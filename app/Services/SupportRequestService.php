<?php
declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use PDO;
use PDOException;

final class SupportRequestService
{
    private const STATUSES = ['Open', 'InProgress', 'Completed', 'Cancelled'];
    private const TYPES = ['Support', 'Booking'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   pagination: array{page:int, per_page:int, total:int, pages:int},
     *   filters: array{status:?string,type:?string,q:?string,from:?string,to:?string}
     * }
     */
    public function listRequests(array $filters, int $page, int $perPage): array
    {
        $status = isset($filters['status']) && in_array($filters['status'], self::STATUSES, true)
            ? (string) $filters['status']
            : null;
        $type = isset($filters['type']) && in_array($filters['type'], self::TYPES, true)
            ? (string) $filters['type']
            : null;
        $search = isset($filters['q']) ? trim((string) $filters['q']) : '';
        $from = isset($filters['from']) ? $this->normalizeDate((string) $filters['from']) : null;
        $to = isset($filters['to']) ? $this->normalizeDate((string) $filters['to']) : null;

        $page = max(1, $page);
        $perPage = max(1, min($perPage, 50));

        $conditions = [];
        $params = [];

        if ($status !== null) {
            $conditions[] = 'r.status = :status';
            $params[':status'] = $status;
        }
        if ($type !== null) {
            $conditions[] = 'r.type = :type';
            $params[':type'] = $type;
        }
        if ($search !== '') {
            $conditions[] = '(
                r.subject LIKE :search OR
                r.message LIKE :search OR
                c.fullname LIKE :search OR
                c.email LIKE :search OR
                cp.email LIKE :search
            )';
            $params[':search'] = '%' . $search . '%';
        }
        if ($from !== null) {
            $conditions[] = 'DATE(r.created_at) >= :from';
            $params[':from'] = $from;
        }
        if ($to !== null) {
            $conditions[] = 'DATE(r.created_at) <= :to';
            $params[':to'] = $to;
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        $countSql = 'SELECT COUNT(*) FROM customer_support_requests r ' . $where;
        $countStmt = $this->pdo->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $pages = (int) max(1, ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $sql = 'SELECT
                    r.id,
                    r.subject,
                    r.type,
                    r.status,
                    r.preferred_slot,
                    r.created_at,
                    r.updated_at,
                    r.customer_id,
                    c.fullname AS customer_name,
                    c.email AS customer_email,
                    c.phone AS customer_phone,
                    cp.email AS portal_email
                FROM customer_support_requests r
                LEFT JOIN customers c ON c.id = r.customer_id
                LEFT JOIN customer_portal_accounts cp ON cp.id = r.portal_account_id
                ' . $where . '
                ORDER BY r.created_at DESC
                LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'rows' => $rows,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => $pages,
            ],
            'filters' => [
                'status' => $status,
                'type' => $type,
                'q' => $search !== '' ? $search : null,
                'from' => $from,
                'to' => $to,
            ],
        ];
    }

    public function getRequest(int $requestId): ?array
    {
        if ($requestId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT
                r.id,
                r.customer_id,
                r.portal_account_id,
                r.type,
                r.subject,
                r.message,
                r.status,
                r.preferred_slot,
                r.resolution_note,
                r.created_at,
                r.updated_at,
                c.fullname AS customer_name,
                c.email AS customer_email,
                c.phone AS customer_phone,
                cp.email AS portal_email
             FROM customer_support_requests r
             LEFT JOIN customers c ON c.id = r.customer_id
             LEFT JOIN customer_portal_accounts cp ON cp.id = r.portal_account_id
             WHERE r.id = :id'
        );
        $stmt->execute([':id' => $requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * @param array<string, mixed> $operator
     * @return array{success:bool,message?:string,errors?:array<int,string>}
     */
    public function updateRequest(int $requestId, string $status, ?string $note, bool $appendNote, array $operator): array
    {
        $status = trim($status);
        if (!in_array($status, self::STATUSES, true)) {
            return [
                'success' => false,
                'errors' => ['Seleziona uno stato valido.'],
            ];
        }

        $note = $note !== null ? trim($note) : null;

        try {
            $this->pdo->beginTransaction();
            $currentStmt = $this->pdo->prepare('SELECT resolution_note FROM customer_support_requests WHERE id = :id FOR UPDATE');
            $currentStmt->execute([':id' => $requestId]);
            $current = $currentStmt->fetch(PDO::FETCH_ASSOC);
            if ($current === false) {
                $this->pdo->rollBack();
                return [
                    'success' => false,
                    'errors' => ['Richiesta non trovata.'],
                ];
            }

            $existingNote = $current['resolution_note'] ?? null;
            if (is_string($existingNote)) {
                $existingNote = trim($existingNote);
                if ($existingNote === '') {
                    $existingNote = null;
                }
            } else {
                $existingNote = null;
            }

            $nextNote = $existingNote;
            if ($note !== null && $appendNote) {
                $nextNote = $this->appendNote($existingNote, $note, $operator);
            } elseif ($note !== null && !$appendNote) {
                $nextNote = $note;
            }

            if (is_string($nextNote)) {
                $nextNote = trim($nextNote);
                if ($nextNote === '') {
                    $nextNote = null;
                }
            }

            $update = $this->pdo->prepare(
                'UPDATE customer_support_requests
                 SET status = :status,
                     resolution_note = :note,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $update->bindValue(':status', $status);
            if ($nextNote === null) {
                $update->bindValue(':note', null, PDO::PARAM_NULL);
            } else {
                $update->bindValue(':note', $nextNote, PDO::PARAM_STR);
            }
            $update->bindValue(':id', $requestId, PDO::PARAM_INT);
            $update->execute();

            $this->pdo->commit();
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            return [
                'success' => false,
                'errors' => ['Impossibile aggiornare la richiesta.', $exception->getMessage()],
            ];
        }

        return [
            'success' => true,
            'message' => 'Richiesta aggiornata correttamente.',
        ];
    }

    /**
     * @return array<string, int>
     */
    public function getStatusSummary(): array
    {
        $summary = array_fill_keys(self::STATUSES, 0);
        $stmt = $this->pdo->query('SELECT status, COUNT(*) AS total FROM customer_support_requests GROUP BY status');
        if ($stmt !== false) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $status = $row['status'] ?? null;
                if (is_string($status) && isset($summary[$status])) {
                    $summary[$status] = (int) ($row['total'] ?? 0);
                }
            }
        }
        $summary['total'] = array_sum($summary);

        return $summary;
    }

    /**
     * @return array<int, string>
     */
    public function getAllowedStatuses(): array
    {
        return self::STATUSES;
    }

    /**
     * @return array<int, string>
     */
    public function getAllowedTypes(): array
    {
        return self::TYPES;
    }

    private function appendNote(?string $existing, string $note, array $operator): string
    {
        $author = $this->resolveOperatorName($operator);
        $timestamp = (new DateTimeImmutable('now'))->format('d/m/Y H:i');
        $entry = '[' . $timestamp . ' · ' . $author . "]\n" . $note;

        if ($existing !== null && trim($existing) !== '') {
            return trim($existing) . "\n\n" . $entry;
        }

        return $entry;
    }

    private function resolveOperatorName(array $operator): string
    {
        $nameCandidates = [
            isset($operator['fullname']) ? trim((string) $operator['fullname']) : '',
            isset($operator['username']) ? trim((string) $operator['username']) : '',
        ];

        foreach ($nameCandidates as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return 'Operatore';
    }

    private function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $date = date_create($value);
        return $date !== false ? $date->format('Y-m-d') : null;
    }
}
