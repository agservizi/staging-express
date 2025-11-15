<?php
declare(strict_types=1);

/**
 * @var array<int, array<string, mixed>> $providers
 * @var array<int, array<string, mixed>> $stock
 * @var array{success:bool, message:string, error?:string}|null $feedback
 * @var array{page:int, per_page:int, total:int, pages:int}|null $pagination
 */
$pageTitle = 'Magazzino SIM';
$pagination = $pagination ?? ['page' => 1, 'per_page' => 7, 'total' => count($stock), 'pages' => 1];
$buildStockPageUrl = static function (int $pageNo): string {
    return 'index.php?' . http_build_query([
        'page' => 'sim_stock',
        'page_no' => $pageNo,
    ]);
};
?>
<section
    class="page"
    data-live-refresh="sim_stock"
    data-refresh-url="index.php?page=sim_stock&amp;action=refresh"
    data-refresh-interval="15000"
    data-refresh-page="<?= (int) $pagination['page'] ?>"
    data-refresh-per-page="<?= (int) $pagination['per_page'] ?>"
>
    <header class="page__header">
        <h2>Magazzino SIM</h2>
        <p>Gestisci le SIM a magazzino, aggiungi nuove schede e verifica disponibilità per la vendita.</p>
    </header>

    <section class="page__section">
        <h3>Aggiungi SIM</h3>
        <p class="muted">Inserisci manualmente ICCID (19-20 cifre) e seleziona l'operatore.</p>

        <div data-live-slot="feedback">
            <?php if ($feedback !== null): ?>
                <div class="alert <?= $feedback['success'] ? 'alert--success' : 'alert--error' ?>">
                    <p><?= htmlspecialchars($feedback['message']) ?></p>
                    <?php if (!empty($feedback['error'])): ?>
                        <p class="muted">Dettaglio: <?= htmlspecialchars($feedback['error']) ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <form method="post" class="form" autocomplete="off" data-live-form>
            <input type="hidden" name="action" value="add_sim">
            <input type="hidden" name="page_no" value="<?= (int) $pagination['page'] ?>">
            <input type="hidden" name="per_page" value="<?= (int) $pagination['per_page'] ?>">
            <div class="form__grid">
                <div class="form__group">
                    <label for="iccid">ICCID</label>
                    <input type="text" name="iccid" id="iccid" pattern="[0-9]{19,20}" minlength="19" maxlength="20" placeholder="Esempio: 8931..." required>
                </div>
                <div class="form__group">
                    <label for="provider_id">Operatore</label>
                    <select name="provider_id" id="provider_id" required>
                        <option value="">Seleziona</option>
                        <?php foreach ($providers as $provider): ?>
                            <option value="<?= (int) $provider['id'] ?>"><?= htmlspecialchars((string) $provider['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form__group">
                    <label for="notes">Note</label>
                    <input type="text" name="notes" id="notes" placeholder="Note facoltative">
                </div>
            </div>
            <div class="form__footer">
                <button type="submit" class="btn btn--primary">Salva in magazzino</button>
            </div>
        </form>
    </section>

    <section class="page__section">
        <h3>SIM a magazzino</h3>
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
                        <tr><td colspan="5">Nessuna SIM presente.</td></tr>
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
                <a class="pagination__link <?= $current === 1 ? 'is-disabled' : '' ?>" href="<?= $current === 1 ? '#' : $buildStockPageUrl(1) ?>">«</a>
                <a class="pagination__link <?= $current === 1 ? 'is-disabled' : '' ?>" href="<?= $current === 1 ? '#' : $buildStockPageUrl($current - 1) ?>">‹</a>
                <span class="pagination__info">Pagina <?= $current ?> di <?= (int) $pagination['pages'] ?> (<?= (int) $pagination['total'] ?> risultati)</span>
                <a class="pagination__link <?= $current === (int) $pagination['pages'] ? 'is-disabled' : '' ?>" href="<?= $current === (int) $pagination['pages'] ? '#' : $buildStockPageUrl($current + 1) ?>">›</a>
                <a class="pagination__link <?= $current === (int) $pagination['pages'] ? 'is-disabled' : '' ?>" href="<?= $current === (int) $pagination['pages'] ? '#' : $buildStockPageUrl((int) $pagination['pages']) ?>">»</a>
            </nav>
        <?php else: ?>
            <nav class="pagination" data-live-slot="pagination" hidden></nav>
        <?php endif; ?>
    </section>
</section>
