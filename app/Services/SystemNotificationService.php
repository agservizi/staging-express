<?php
declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use PDO;

/**
 * Gestione centralizzata delle notifiche mostrate nel layout e inviate verso canali esterni.
 */
final class SystemNotificationService
{
    private PDO $pdo;
    private ?NotificationDispatcher $dispatcher;
    private ?string $logFile;

    public function __construct(PDO $pdo, ?NotificationDispatcher $dispatcher = null, ?string $logFile = null)
    {
        $this->pdo = $pdo;
        $this->dispatcher = $dispatcher;
        $this->logFile = $logFile;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function push(string $type, string $title, string $body, array $options = []): array
    {
        $normalizedType = $type !== '' ? strtolower($type) : 'system';
        $normalizedLevel = $this->normalizeLevel((string) ($options['level'] ?? 'info'));
        $channel = isset($options['channel']) && (string) $options['channel'] !== ''
            ? strtolower((string) $options['channel'])
            : $normalizedType;
        $source = isset($options['source']) && (string) $options['source'] !== ''
            ? (string) $options['source']
            : 'system';
        $link = isset($options['link']) && (string) $options['link'] !== ''
            ? trim((string) $options['link'])
            : null;
        $recipientId = isset($options['user_id']) ? (int) $options['user_id'] : null;
        $meta = $this->normalizeMeta($options['meta'] ?? []);
        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($metaJson === false) {
            $metaJson = '{}';
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO system_notifications (
                 type, title, body, level, channel, source, link, meta_json, recipient_user_id, is_read, created_at
             ) VALUES (:type, :title, :body, :level, :channel, :source, :link, :meta, :recipient, 0, NOW())'
        );

        $stmt->execute([
            ':type' => $normalizedType,
            ':title' => trim($title),
            ':body' => trim($body),
            ':level' => $normalizedLevel,
            ':channel' => $channel,
            ':source' => $source,
            ':link' => $link,
            ':meta' => $metaJson,
            ':recipient' => $recipientId,
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $createdAt = (new DateTimeImmutable('now'))->format('c');
        $record = [
            'id' => $id,
            'type' => $normalizedType,
            'title' => trim($title),
            'body' => trim($body),
            'level' => $normalizedLevel,
            'channel' => $channel,
            'source' => $source,
            'link' => $link,
            'meta' => $meta,
            'recipient_user_id' => $recipientId,
            'created_at' => $createdAt,
            'is_read' => false,
        ];

        if ($this->dispatcher !== null) {
            $this->dispatcher->dispatch($record);
        }

        $this->log(sprintf('Notifica #%d [%s] registrata', $id, $channel));

        return $record;
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, unread_count:int}
     */
    public function getTopbarFeed(?int $userId, int $limit = 10): array
    {
        $limit = max(1, min($limit, 30));
        $conditions = 'recipient_user_id IS NULL';
        $params = [];
        if ($userId !== null) {
            $conditions = '(recipient_user_id IS NULL OR recipient_user_id = :uid)';
            $params[':uid'] = $userId;
        }

        $sql = 'SELECT id, type, title, body, level, channel, source, link, meta_json, recipient_user_id, is_read, created_at
                FROM system_notifications
                WHERE ' . $conditions . '
                ORDER BY created_at DESC
                LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        if (array_key_exists(':uid', $params)) {
            $stmt->bindValue(':uid', (int) $params[':uid'], PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $items = array_map(fn (array $row): array => $this->hydrateNotificationRow($row), $rows);

        return [
            'items' => $items,
            'unread_count' => $this->countUnread($userId),
        ];
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, unread_count:int, last_id:int}
     */
    public function getStreamPayload(?int $userId, int $afterId, int $limit = 15): array
    {
        $limit = max(1, min($limit, 50));
        $afterId = max(0, $afterId);

        $baseCondition = 'id > :after';
        $params = [
            ':after' => $afterId,
        ];

        if ($userId !== null) {
            $baseCondition .= ' AND (recipient_user_id IS NULL OR recipient_user_id = :uid)';
            $params[':uid'] = $userId;
        } else {
            $baseCondition .= ' AND recipient_user_id IS NULL';
        }

        $sql = 'SELECT id, type, title, body, level, channel, source, link, meta_json, recipient_user_id, is_read, created_at
                FROM system_notifications
                WHERE ' . $baseCondition . '
                ORDER BY id ASC
                LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':after', $params[':after'], PDO::PARAM_INT);
        if (array_key_exists(':uid', $params)) {
            $stmt->bindValue(':uid', (int) $params[':uid'], PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $items = array_map(fn (array $row): array => $this->hydrateNotificationRow($row), $rows);

        $lastId = $afterId;
        if ($items !== []) {
            $lastItem = $items[array_key_last($items)];
            $lastId = (int) ($lastItem['id'] ?? $afterId);
        }

        return [
            'items' => $items,
            'unread_count' => $this->countUnread($userId),
            'last_id' => $lastId,
        ];
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, int>}
     */
    public function getPaginatedFeed(?int $userId, int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(5, min($perPage, 50));
        $offset = ($page - 1) * $perPage;

        $conditions = 'recipient_user_id IS NULL';
        $params = [];
        if ($userId !== null) {
            $conditions = '(recipient_user_id IS NULL OR recipient_user_id = :uid)';
            $params[':uid'] = $userId;
        }

        $sql = 'SELECT id, type, title, body, level, channel, source, link, meta_json, recipient_user_id, is_read, created_at
                FROM system_notifications
                WHERE ' . $conditions . '
                ORDER BY created_at DESC
                LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        if (array_key_exists(':uid', $params)) {
            $stmt->bindValue(':uid', (int) $params[':uid'], PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $items = array_map(fn (array $row): array => $this->hydrateNotificationRow($row), $rows);

        $total = $this->countAll($userId);
        $pages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => $pages > 0 ? $pages : 1,
            ],
        ];
    }

    public function markAllRead(?int $userId = null): int
    {
        if ($userId === null) {
            $stmt = $this->pdo->prepare('UPDATE system_notifications SET is_read = 1, read_at = NOW() WHERE is_read = 0');
            $stmt->execute();
        } else {
            $stmt = $this->pdo->prepare(
                'UPDATE system_notifications
                 SET is_read = 1, read_at = NOW()
                 WHERE is_read = 0 AND (recipient_user_id IS NULL OR recipient_user_id = :uid)'
            );
            $stmt->execute([':uid' => $userId]);
        }

        $count = (int) $stmt->rowCount();
        if ($count > 0) {
            $this->log('Notifiche segnate come lette: ' . $count);
        }

        return $count;
    }

    public function markAsRead(int $notificationId, ?int $userId = null): bool
    {
        $sql = 'UPDATE system_notifications SET is_read = 1, read_at = NOW() WHERE id = :id';
        $params = [':id' => $notificationId];
        if ($userId !== null) {
            $sql .= ' AND (recipient_user_id IS NULL OR recipient_user_id = :uid)';
            $params[':uid'] = $userId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $updated = (int) $stmt->rowCount() > 0;
        if ($updated) {
            $this->log('Notifica #' . $notificationId . ' segnata come letta');
        }

        return $updated;
    }

    private function countUnread(?int $userId): int
    {
        $sql = 'SELECT COUNT(*) FROM system_notifications WHERE is_read = 0';
        $params = [];
        if ($userId !== null) {
            $sql .= ' AND (recipient_user_id IS NULL OR recipient_user_id = :uid)';
            $params[':uid'] = $userId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    private function countAll(?int $userId): int
    {
        $sql = 'SELECT COUNT(*) FROM system_notifications';
        $params = [];
        if ($userId !== null) {
            $sql .= ' WHERE recipient_user_id IS NULL OR recipient_user_id = :uid';
            $params[':uid'] = $userId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateNotificationRow(array $row): array
    {
        $meta = [];
        if (!empty($row['meta_json'])) {
            $decoded = json_decode((string) $row['meta_json'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }

        $isRead = (int) ($row['is_read'] ?? 0) === 1;

        return [
            'id' => (int) $row['id'],
            'type' => (string) ($row['type'] ?? 'system'),
            'title' => (string) ($row['title'] ?? ''),
            'body' => (string) ($row['body'] ?? ''),
            'level' => $this->normalizeLevel((string) ($row['level'] ?? 'info')),
            'channel' => (string) ($row['channel'] ?? ($row['type'] ?? 'system')),
            'source' => (string) ($row['source'] ?? 'system'),
            'link' => isset($row['link']) && $row['link'] !== '' ? (string) $row['link'] : null,
            'meta' => $meta,
            'is_read' => $isRead,
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    /**
     * @param mixed $meta
     * @return array<string, mixed>
     */
    private function normalizeMeta($meta): array
    {
        if (is_array($meta)) {
            return $meta;
        }

        return ['value' => $meta];
    }

    private function normalizeLevel(string $level): string
    {
        $normalized = strtolower(trim($level));
        return match ($normalized) {
            'success', 'ok', 'positive' => 'success',
            'warning', 'warn', 'alert' => 'warning',
            'danger', 'error', 'fail' => 'danger',
            default => 'info',
        };
    }

    private function log(string $message): void
    {
        if ($this->logFile === null) {
            return;
        }

        $line = sprintf('[%s] %s%s', date('c'), $message, PHP_EOL);
        @file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
