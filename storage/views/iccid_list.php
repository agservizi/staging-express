<?php
declare(strict_types=1);

/** @var array<int, array<string, mixed>> $stock */
$pageTitle = 'ICCID';
/** @var array{page:int, per_page:int, total:int, pages:int}|null $pagination */
$pagination = $pagination ?? ['page' => 1, 'per_page' => 7, 'total' => count($stock), 'pages' => 1];
$buildPageUrl = static function (int $pageNo): string {
    return 'index.php?' . http_build_query([
        'page' => 'iccid_list',
        'page_no' => $pageNo,
    ]);
};
?>
<section
    class="page"
    data-live-refresh="iccid_list"
    data-refresh-url="index.php?page=iccid_list&amp;action=refresh"
    data-refresh-interval="20000"
    data-refresh-page="<?= (int) $pagination['page'] ?>"
    data-refresh-per-page="<?= (int) $pagination['per_page'] ?>"
>
    <header class="page__header">
        <h2>Magazzino ICCID</h2>
        <p>Elenco completo delle SIM con stato corrente.</p>
    </header>

    <div data-live-slot="feedback"></div>

    <p class="muted" data-live-slot="status">Ultimo aggiornamento: <span data-live-slot="timestamp">--:--</span></p>

    <div class="table-wrapper">
        <table class="table" data-live-slot="table">
            <thead>
                <tr>
                    <th>ICCID</th>
                    <th>Operatore</th>
                    <th>Stato</th>
                    <th>Ultimo aggiornamento</th>
                    <th>Note</th>
                </tr>
            </thead>
            <tbody data-live-slot="rows">
                <?php if ($stock === []): ?>
                    <tr><td colspan="5">Nessun record disponibile.</td></tr>
                <?php else: ?>
                    <?php foreach ($stock as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $row['iccid']) ?></td>
                            <td><?= htmlspecialchars((string) $row['provider_name']) ?></td>
                            <td><?= htmlspecialchars((string) $row['status']) ?></td>
                            <td><?= htmlspecialchars((string) $row['updated_at']) ?></td>
                            <td><?= htmlspecialchars((string) ($row['notes'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pagination['pages'] > 1): ?>
        <nav class="pagination" data-live-slot="pagination">
            <?php $current = (int) $pagination['page']; ?>
            <a class="pagination__link <?= $current === 1 ? 'is-disabled' : '' ?>" href="<?= $current === 1 ? '#' : $buildPageUrl(1) ?>">«</a>
            <a class="pagination__link <?= $current === 1 ? 'is-disabled' : '' ?>" href="<?= $current === 1 ? '#' : $buildPageUrl($current - 1) ?>">‹</a>
            <span class="pagination__info">Pagina <?= $current ?> di <?= (int) $pagination['pages'] ?> (<?= (int) $pagination['total'] ?> risultati)</span>
            <a class="pagination__link <?= $current === (int) $pagination['pages'] ? 'is-disabled' : '' ?>" href="<?= $current === (int) $pagination['pages'] ? '#' : $buildPageUrl($current + 1) ?>">›</a>
            <a class="pagination__link <?= $current === (int) $pagination['pages'] ? 'is-disabled' : '' ?>" href="<?= $current === (int) $pagination['pages'] ? '#' : $buildPageUrl((int) $pagination['pages']) ?>">»</a>
        </nav>
    <?php else: ?>
        <nav class="pagination" data-live-slot="pagination" hidden></nav>
    <?php endif; ?>
</section>
