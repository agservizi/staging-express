<?php
$productRequests = $data['productRequests'] ?? [];
$filters = $data['filters'] ?? ['status' => null, 'type' => null];
$stats = $data['stats'] ?? [
    'total' => count($productRequests),
    'active' => 0,
    'completed' => 0,
    'value' => 0.0,
    'last_order_at' => null,
    'visible' => count($productRequests),
];

$typeLabels = [
    'Purchase' => 'Acquisto',
    'Reservation' => 'Prenotazione',
    'Deposit' => 'Acconto',
    'Installment' => 'Rateizzazione',
];

$statusLabels = [
    'Pending' => 'In lavorazione',
    'InReview' => 'In revisione',
    'Confirmed' => 'Confermato',
    'Completed' => 'Completato',
    'Cancelled' => 'Annullato',
    'Declined' => 'Rifiutato',
];

$paymentLabels = [
    'BankTransfer' => 'Bonifico',
    'InStore' => 'Pagamento in negozio',
    'Other' => 'Altro',
];

$formatMoney = static function (float $value): string {
    return '€ ' . number_format($value, 2, ',', '.');
};

$formatDateTime = static function (?string $value): string {
    if ($value === null || $value === '') {
        return '—';
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return '—';
    }

    return date('d/m/Y H:i', $timestamp);
};

$formatDate = static function (?string $value): ?string {
    if ($value === null || $value === '') {
        return null;
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }

    return date('d/m/Y', $timestamp);
};

$lastOrderDisplay = '—';
if (isset($stats['last_order_at']) && is_int($stats['last_order_at'])) {
    $lastOrderDisplay = date('d/m/Y H:i', $stats['last_order_at']);
}
?>

<section class="portal-section">
    <header class="portal-section__header">
        <div>
            <h2>Ordini store online</h2>
            <p class="portal-hint">Controlla lo stato delle richieste inviate e tieni traccia delle consegne.</p>
        </div>
        <a class="portal-button portal-button--ghost" href="index.php?view=sales">Torna allo store</a>
    </header>
    <div class="portal-grid">
        <div class="portal-card">
            <span class="portal-hint">Totale richieste</span>
            <span class="portal-card__value"><?= number_format((int) ($stats['total'] ?? 0), 0, ',', '.') ?></span>
            <span class="portal-hint">Richieste visibili: <?= number_format((int) ($stats['visible'] ?? count($productRequests)), 0, ',', '.') ?></span>
        </div>
        <div class="portal-card">
            <span class="portal-hint">In gestione</span>
            <span class="portal-card__value portal-card__value--warning"><?= number_format((int) ($stats['active'] ?? 0), 0, ',', '.') ?></span>
            <span class="portal-hint">Stati inclusi: In lavorazione, In revisione, Confermato</span>
        </div>
        <div class="portal-card">
            <span class="portal-hint">Ordini completati</span>
            <span class="portal-card__value portal-card__value--success"><?= number_format((int) ($stats['completed'] ?? 0), 0, ',', '.') ?></span>
            <span class="portal-hint">Ultimo aggiornamento: <?= htmlspecialchars($lastOrderDisplay) ?></span>
        </div>
        <div class="portal-card">
            <span class="portal-hint">Valore richieste</span>
            <span class="portal-card__value"><?= $formatMoney((float) ($stats['value'] ?? 0.0)) ?></span>
            <span class="portal-hint">Totale stimato dei prodotti richiesti</span>
        </div>
    </div>
</section>

<section class="portal-section">
    <header class="portal-section__header">
        <h2>Dettaglio ordini</h2>
        <form method="get" class="portal-filters" aria-label="Filtri ordini">
            <input type="hidden" name="view" value="orders">
            <label>
                Stato
                <select name="status">
                    <option value="">Tutti</option>
                    <?php foreach ($statusLabels as $value => $label): ?>
                        <option value="<?= $value ?>"<?= ($filters['status'] ?? '') === $value ? ' selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Tipo richiesta
                <select name="type">
                    <option value="">Tutti</option>
                    <?php foreach ($typeLabels as $value => $label): ?>
                        <option value="<?= $value ?>"<?= ($filters['type'] ?? '') === $value ? ' selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="portal-button portal-button--primary">Applica filtri</button>
        </form>
    </header>

    <?php if ($productRequests === []): ?>
        <p class="portal-empty">Nessun ordine trovato per i criteri selezionati.</p>
    <?php else: ?>
        <div class="portal-table-wrapper">
            <table class="portal-table">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Data</th>
                    <th>Prodotto</th>
                    <th>Tipo</th>
                    <th>Pagamento</th>
                    <th>Dettagli</th>
                    <th>Stato</th>
                    <th>Aggiornato</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($productRequests as $request): ?>
                    <?php $statusValue = (string) ($request['status'] ?? 'Pending'); ?>
                    <tr>
                        <td><?= (int) ($request['id'] ?? 0) ?></td>
                        <td><?= $formatDateTime($request['created_at'] ?? null) ?></td>
                        <td>
                            <strong><?= htmlspecialchars((string) ($request['product_name'] ?? 'Prodotto')) ?></strong>
                            <?php if (!empty($request['current_category'])): ?>
                                <div class="portal-hint">Categoria: <?= htmlspecialchars((string) $request['current_category']) ?></div>
                            <?php endif; ?>
                            <div class="portal-hint">Prezzo indicativo: <?= $formatMoney((float) ($request['product_price'] ?? 0.0)) ?></div>
                        </td>
                        <td><?= htmlspecialchars($typeLabels[$request['request_type']] ?? (string) ($request['request_type'] ?? '')) ?></td>
                        <?php $paymentValue = $request['payment_method'] ?? null; ?>
                        <td><?= htmlspecialchars($paymentLabels[$paymentValue] ?? ($paymentValue ?? '—')) ?></td>
                        <td>
                            <?php if (!empty($request['deposit_amount'])): ?>
                                <div>Acconto: <?= $formatMoney((float) $request['deposit_amount']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($request['installments'])): ?>
                                <div>Rate: <?= (int) $request['installments'] ?></div>
                            <?php endif; ?>
                            <?php $pickupDate = $formatDate($request['desired_pickup_date'] ?? null); ?>
                            <?php if ($pickupDate !== null): ?>
                                <div>Ritiro da: <?= htmlspecialchars($pickupDate) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($request['bank_transfer_reference'])): ?>
                                <div>Rif. bonifico: <?= htmlspecialchars((string) $request['bank_transfer_reference']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($request['note'])): ?>
                                <div class="portal-hint">Note: <?= htmlspecialchars((string) $request['note']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="portal-badge portal-badge--<?= portal_badge_class($statusValue) ?>"><?= htmlspecialchars($statusLabels[$statusValue] ?? $statusValue) ?></span></td>
                        <td><?= $formatDateTime($request['updated_at'] ?? null) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
