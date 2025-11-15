<?php
declare(strict_types=1);

/**
 * @var array<int, array<string, mixed>> $notifications
 * @var array{page:int, per_page:int, total:int, pages:int}|null $pagination
 * @var int|null $focusNotificationId
 */

$pageTitle = $pageTitle ?? 'Notifiche';
$notifications = $notifications ?? [];
$pagination = $pagination ?? ['page' => 1, 'per_page' => 20, 'total' => count($notifications), 'pages' => 1];
$focusNotificationId = isset($focusNotificationId) ? (int) $focusNotificationId : null;

$channelLabels = [
    'sales' => 'Vendite',
    'stock' => 'Scorte SIM',
    'product_stock' => 'Magazzino prodotti',
    'system' => 'Sistema',
];

$levelBadges = [
    'info' => 'badge badge--info',
    'success' => 'badge badge--success',
    'warning' => 'badge badge--warning',
    'danger' => 'badge badge--danger',
];

$formatTimestamp = static function (?string $timestamp): string {
    if ($timestamp === null || $timestamp === '') {
        return 'N/D';
    }
    $time = strtotime($timestamp);
    if ($time === false) {
        return htmlspecialchars($timestamp, ENT_QUOTES);
    }
    return date('d/m/Y H:i', $time);
};

$currentPage = max(1, (int) ($pagination['page'] ?? 1));
$totalPages = max(1, (int) ($pagination['pages'] ?? 1));
$totalResults = max(0, (int) ($pagination['total'] ?? count($notifications)));
$perPage = max(5, min(50, (int) ($pagination['per_page'] ?? 20)));

$buildPageUrl = static function (int $pageNo) use ($perPage): string {
    $params = [
        'page' => 'notifications',
        'page_no' => $pageNo,
    ];
    if ($perPage !== 20) {
        $params['per_page'] = $perPage;
    }

    return 'index.php?' . http_build_query($params);
};

?>
<section class="page">
    <header class="page__header">
        <h2>Centro notifiche</h2>
        <p class="muted">Consulta lo storico completo delle notifiche di sistema e operative. Le notifiche contrassegnate come lette restano disponibili per riferimento.</p>
    </header>

    <section class="page__section">
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Titolo</th>
                        <th>Dettagli</th>
                        <th>Canale</th>
                        <th>Livello</th>
                        <th>Stato</th>
                        <th>Creato il</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($notifications === []): ?>
                        <tr>
                            <td colspan="6">Nessuna notifica disponibile.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($notifications as $notification): ?>
                            <?php
                                $id = (int) ($notification['id'] ?? 0);
                                $title = (string) ($notification['title'] ?? '');
                                $body = (string) ($notification['body'] ?? '');
                                if ($title === '' && $body !== '') {
                                    $title = $body;
                                }
                                $channel = strtolower((string) ($notification['channel'] ?? ($notification['type'] ?? 'system')));
                                $channelLabel = $channelLabels[$channel] ?? ucfirst($channel);
                                $level = strtolower((string) ($notification['level'] ?? 'info'));
                                $isUnread = empty($notification['is_read']);
                                $link = isset($notification['link']) && $notification['link'] !== '' ? (string) $notification['link'] : null;
                                $rowId = 'notification-item-' . $id;
                                $isFocused = $focusNotificationId !== null && $focusNotificationId === $id;
                            ?>
                            <tr id="<?= htmlspecialchars($rowId) ?>" class="notification-row <?= $isUnread ? 'is-unread' : 'is-read' ?> <?= $isFocused ? 'highlight' : '' ?>">
                                <td>
                                    <?php if ($link !== null): ?>
                                        <a href="<?= htmlspecialchars($link) ?>" class="link--primary"><?= htmlspecialchars($title !== '' ? $title : 'Notifica #' . $id) ?></a>
                                    <?php else: ?>
                                        <?= htmlspecialchars($title !== '' ? $title : 'Notifica #' . $id) ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($body) ?></td>
                                <td><?= htmlspecialchars($channelLabel) ?></td>
                                <td>
                                    <span class="<?= htmlspecialchars($levelBadges[$level] ?? $levelBadges['info']) ?>"><?= htmlspecialchars(ucfirst($level)) ?></span>
                                </td>
                                <td>
                                    <?php if ($isUnread): ?>
                                        <span class="badge badge--warning">Non letta</span>
                                    <?php else: ?>
                                        <span class="badge badge--muted">Letta</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $formatTimestamp((string) ($notification['created_at'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="pagination">
                <a class="pagination__link <?= $currentPage === 1 ? 'is-disabled' : '' ?>" href="<?= $currentPage === 1 ? '#' : $buildPageUrl(1) ?>">«</a>
                <a class="pagination__link <?= $currentPage === 1 ? 'is-disabled' : '' ?>" href="<?= $currentPage === 1 ? '#' : $buildPageUrl($currentPage - 1) ?>">‹</a>
                <span class="pagination__info">Pagina <?= $currentPage ?> di <?= $totalPages ?> (<?= $totalResults ?> notifiche)</span>
                <a class="pagination__link <?= $currentPage === $totalPages ? 'is-disabled' : '' ?>" href="<?= $currentPage === $totalPages ? '#' : $buildPageUrl($currentPage + 1) ?>">›</a>
                <a class="pagination__link <?= $currentPage === $totalPages ? 'is-disabled' : '' ?>" href="<?= $currentPage === $totalPages ? '#' : $buildPageUrl($totalPages) ?>">»</a>
            </nav>
        <?php endif; ?>
    </section>
</section>

<?php if ($focusNotificationId !== null && $focusNotificationId > 0): ?>
<script>
  document.addEventListener('DOMContentLoaded', () => {
    const target = document.getElementById('notification-item-<?= (int) $focusNotificationId ?>');
    if (!target) {
      return;
    }
    target.classList.add('highlight');
    target.scrollIntoView({ block: 'center', behavior: 'smooth' });
    window.setTimeout(() => {
      target.classList.remove('highlight');
    }, 4000);
  });
</script>
<?php endif; ?>
