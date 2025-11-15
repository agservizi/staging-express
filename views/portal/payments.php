<?php
$sales = $data['sales'] ?? [];
$pagination = $data['pagination'] ?? ['page' => 1, 'per_page' => 10, 'total' => 0, 'pages' => 1];
$filters = $data['filters'] ?? ['status' => null, 'payment_status' => null, 'per_page' => 10];
$feedbackPayment = $data['feedbackPayment'] ?? null;

$paymentStatusLabels = [
    'Paid' => 'Pagato',
    'Partial' => 'Parziale',
    'Pending' => 'In attesa',
    'Overdue' => 'Scaduto',
];

$saleStatusLabels = [
    'Completed' => 'Completata',
    'Refunded' => 'Rimborsata',
    'Cancelled' => 'Annullata',
    'Pending' => 'In lavorazione',
    'Confirmed' => 'Confermata',
];

$formatMoney = static function (float $value): string {
    return '€ ' . number_format($value, 2, ',', '.');
};
?>

<section class="portal-section">
    <header class="portal-section__header">
        <div>
            <h2>Vendite registrate</h2>
            <p class="portal-hint">Qui trovi tutte le vendite concluse dall'amministrazione verso il tuo profilo cliente.</p>
        </div>
        <form method="get" class="portal-filters" aria-label="Filtra vendite">
            <input type="hidden" name="view" value="payments">
            <label>
                Stato vendita
                <select name="status">
                    <option value="">Tutti</option>
                    <?php foreach ($saleStatusLabels as $value => $label): ?>
                        <option value="<?= $value ?>"<?= ($filters['status'] ?? '') === $value ? ' selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Stato pagamento
                <select name="payment_status">
                    <option value="">Tutti</option>
                    <?php foreach ($paymentStatusLabels as $value => $label): ?>
                        <option value="<?= $value ?>"<?= ($filters['payment_status'] ?? '') === $value ? ' selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Risultati per pagina
                <select name="per_page">
                    <?php foreach ([10, 20, 30] as $opt): ?>
                        <option value="<?= $opt ?>"<?= (int) ($filters['per_page'] ?? 10) === $opt ? ' selected' : '' ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="portal-button portal-button--primary">Applica filtri</button>
        </form>
    </header>

    <?php if ($sales === []): ?>
        <p class="portal-empty">Non risultano vendite per i criteri selezionati.</p>
    <?php else: ?>
        <div class="portal-table-wrapper">
            <table class="portal-table">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Data</th>
                    <th>Totale</th>
                    <th>Pagato</th>
                    <th>Saldo</th>
                    <th>Pagamento</th>
                    <th>Stato</th>
                    <th>Azioni</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($sales as $sale): ?>
                    <?php $paymentStatus = (string) ($sale['payment_status'] ?? 'Pending'); ?>
                    <tr>
                        <td>#<?= (int) $sale['id'] ?></td>
                        <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $sale['created_at']))) ?></td>
                        <td><?= $formatMoney((float) $sale['total']) ?></td>
                        <td><?= $formatMoney((float) $sale['total_paid']) ?></td>
                        <td><?= $formatMoney((float) $sale['balance_due']) ?></td>
                        <td><span class="portal-badge portal-badge--<?= portal_badge_class($paymentStatus) ?>"><?= htmlspecialchars($paymentStatusLabels[$paymentStatus] ?? $paymentStatus) ?></span></td>
                        <td><?= htmlspecialchars($saleStatusLabels[$sale['status'] ?? ''] ?? (string) ($sale['status'] ?? '—')) ?></td>
                        <td>
                            <div class="portal-actions">
                                <a class="portal-button portal-button--ghost" href="index.php?view=sale_detail&amp;sale_id=<?= (int) $sale['id'] ?>">Dettagli</a>
                                <a class="portal-button portal-button--primary" href="../print_receipt.php?sale_id=<?= (int) $sale['id'] ?>" target="_blank" rel="noopener">Scontrino</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="portal-pagination">
            <?php $currentPage = (int) ($pagination['page'] ?? 1); ?>
            <?php $pagesTotal = (int) ($pagination['pages'] ?? 1); ?>
            <span>Pagina <?= $currentPage ?> di <?= $pagesTotal ?></span>
            <div class="portal-pagination__actions">
                <?php if ($currentPage > 1): ?>
                    <a class="portal-button portal-button--ghost" href="<?= portal_paginate_link('payments', $currentPage - 1, $filters) ?>">Precedente</a>
                <?php endif; ?>
                <?php if ($currentPage < $pagesTotal): ?>
                    <a class="portal-button portal-button--ghost" href="<?= portal_paginate_link('payments', $currentPage + 1, $filters) ?>">Successiva</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</section>

<section class="portal-section">
    <header class="portal-section__header">
        <h2>Segnala un pagamento</h2>
    </header>
    <?php if ($feedbackPayment !== null): ?>
        <div class="portal-alert portal-alert--<?= ($feedbackPayment['success'] ?? false) ? 'success' : 'error' ?>">
            <p><?= htmlspecialchars($feedbackPayment['message'] ?? 'Operazione completata.') ?></p>
            <?php foreach ($feedbackPayment['errors'] ?? [] as $error): ?>
                <p><?= htmlspecialchars($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <form method="post" class="portal-form">
        <input type="hidden" name="action" value="create_payment">
        <div class="portal-form__grid">
            <label>
                Vendita
                <select name="sale_id" required>
                    <option value="">Seleziona</option>
                    <?php foreach ($sales as $sale): ?>
                        <?php if ((float) $sale['balance_due'] <= 0) { continue; } ?>
                        <option value="<?= (int) $sale['id'] ?>" data-balance="<?= number_format((float) $sale['balance_due'], 2, '.', '') ?>">#<?= (int) $sale['id'] ?> · Saldo <?= $formatMoney((float) $sale['balance_due']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Importo
                <input type="number" step="0.01" min="0" name="amount" placeholder="0,00" required>
            </label>
            <label>
                Metodo
                <select name="payment_method">
                    <option value="BankTransfer">Bonifico</option>
                    <option value="Card">Carta</option>
                    <option value="Cash">Contanti</option>
                    <option value="Other">Altro</option>
                </select>
            </label>
        </div>
        <label>
            Nota (facoltativa)
            <textarea name="note" rows="3" placeholder="Inserisci riferimenti utili (es. CRO, banca)"></textarea>
        </label>
        <button type="submit" class="portal-button portal-button--primary">Invia segnalazione</button>
    </form>
</section>
