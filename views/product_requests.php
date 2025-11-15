<?php
declare(strict_types=1);

/**
 * @var array<int, array<string, mixed>> $requests
 * @var array{page:int, per_page:int, total:int, pages:int} $pagination
 * @var array{status:?string,type:?string,payment:?string,q:?string,from:?string,to:?string} $filters
 * @var array<string, int> $summary
 * @var array<int, string> $statusOptions
 * @var array<int, string> $typeOptions
 * @var array<int, string> $paymentOptions
 * @var array{success:bool, message?:string, errors?:array<int, string>}|null $feedback
 * @var int $perPage
 */

$pageTitle = 'Ordini store';
$requests = $requests ?? [];
$pagination = $pagination ?? ['page' => 1, 'per_page' => 10, 'total' => count($requests), 'pages' => 1];
$filters = $filters ?? ['status' => null, 'type' => null, 'payment' => null, 'q' => null, 'from' => null, 'to' => null];
$summary = $summary ?? [];
$statusOptions = $statusOptions ?? [];
$typeOptions = $typeOptions ?? [];
$paymentOptions = $paymentOptions ?? [];
$feedback = $feedback ?? null;
$perPage = isset($perPage) ? (int) $perPage : (int) ($pagination['per_page'] ?? 10);

$statusLabels = [
    'Pending' => 'In attesa',
    'InReview' => 'In verifica',
    'Confirmed' => 'Confermate',
    'Completed' => 'Evadite',
    'Cancelled' => 'Annullate',
    'Declined' => 'Rifiutate',
];
$statusMeta = [
    'Pending' => 'Ordini appena ricevuti',
    'InReview' => 'Analisi da parte del team',
    'Confirmed' => 'Da completare o in preparazione',
    'Completed' => 'Consegnati/chiusi',
    'Cancelled' => 'Annullati dal cliente o dall’operatore',
    'Declined' => 'Non approvati',
];
$statusBadges = [
    'Pending' => 'badge badge--warning',
    'InReview' => 'badge badge--info',
    'Confirmed' => 'badge badge--info',
    'Completed' => 'badge badge--success',
    'Cancelled' => 'badge badge--muted',
    'Declined' => 'badge badge--danger',
];
$typeLabels = [
    'Purchase' => 'Acquisto',
    'Reservation' => 'Prenotazione',
    'Deposit' => 'Acconto',
    'Installment' => 'Rateale',
];
$paymentLabels = [
    'BankTransfer' => 'Bonifico',
    'InStore' => 'In negozio',
    'Other' => 'Altro',
];

$formatDate = static function (?string $value, string $pattern = 'd/m/Y H:i'): string {
    if (!is_string($value) || trim($value) === '') {
        return 'n/d';
    }
    $timestamp = strtotime($value);
    return $timestamp !== false ? date($pattern, $timestamp) : 'n/d';
};

$formatEuro = static function (?float $value): string {
    if ($value === null) {
        return 'N/D';
    }
    return number_format((float) $value, 2, ',', '.') . ' €';
};

$buildListUrl = static function (array $extra = []) use ($filters, $perPage): string {
    $params = ['page' => 'product_requests'];
    foreach (['status', 'type', 'payment', 'q', 'from', 'to'] as $key) {
        $value = $filters[$key] ?? null;
        if ($value !== null && $value !== '') {
            $params[$key] = $value;
        }
    }
    if ($perPage !== 10) {
        $params['per_page'] = $perPage;
    }

    return 'index.php?' . http_build_query(array_merge($params, $extra));
};

$currentPage = max(1, (int) ($pagination['page'] ?? 1));
$totalPages = max(1, (int) ($pagination['pages'] ?? 1));
$totalResults = max(0, (int) ($pagination['total'] ?? count($requests)));
$currentListUrl = $buildListUrl(['page_no' => $currentPage]);
$hasActiveFilters = false;
foreach (['status', 'type', 'payment', 'q', 'from', 'to'] as $filterKey) {
    $value = $filters[$filterKey] ?? null;
    if ($value !== null && $value !== '') {
        $hasActiveFilters = true;
        break;
    }
}

$totalSummary = (int) ($summary['total'] ?? $totalResults);
?>
<section class="page">
    <header class="page__header">
        <h2>Ordini store online</h2>
        <p class="muted">Monitora gli ordini provenienti dallo store del portale clienti, coordina le conferme e tieni traccia delle note operative per il team.</p>
    </header>

    <?php if ($feedback !== null): ?>
        <div class="alert <?= $feedback['success'] ? 'alert--success' : 'alert--error' ?>">
            <p><?= htmlspecialchars($feedback['message'] ?? ($feedback['success'] ? 'Operazione completata.' : 'Si è verificato un problema.')) ?></p>
            <?php if (!$feedback['success']): ?>
                <?php foreach ($feedback['errors'] ?? [] as $error): ?>
                    <p class="muted"><?= htmlspecialchars($error) ?></p>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <section class="page__section">
        <div class="cards">
            <article class="card">
                <div class="card__header">
                    <h3>Ordini totali</h3>
                </div>
                <div class="card__value"><?= $totalSummary ?></div>
                <p class="card__meta">Ordini registrati nel tempo</p>
            </article>
            <?php foreach ($statusLabels as $statusKey => $label): ?>
                <?php $count = (int) ($summary[$statusKey] ?? 0); ?>
                <article class="card">
                    <div class="card__header">
                        <h3><?= htmlspecialchars($label) ?></h3>
                        <span class="<?= htmlspecialchars($statusBadges[$statusKey] ?? 'badge badge--muted') ?>"><?= htmlspecialchars($label) ?></span>
                    </div>
                    <div class="card__value"><?= $count ?></div>
                    <p class="card__meta"><?= htmlspecialchars($statusMeta[$statusKey]) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <form method="get" class="filters-bar">
        <input type="hidden" name="page" value="product_requests">
        <div class="filters-bar__row">
            <div class="form__group">
                <label for="product_requests_q">Cerca ordine</label>
                <input type="text" name="q" id="product_requests_q" value="<?= htmlspecialchars((string) ($filters['q'] ?? '')) ?>" placeholder="Prodotto, cliente, stato">
            </div>
            <div class="form__group">
                <label for="product_requests_status">Stato</label>
                <?php $statusFilter = (string) ($filters['status'] ?? ''); ?>
                <select name="status" id="product_requests_status">
                    <option value="">Tutti</option>
                    <?php foreach ($statusOptions as $option): ?>
                        <?php $value = (string) $option; ?>
                        <option value="<?= htmlspecialchars($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= htmlspecialchars($statusLabels[$value] ?? $value) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form__group">
                <label for="product_requests_type">Tipologia</label>
                <?php $typeFilter = (string) ($filters['type'] ?? ''); ?>
                <select name="type" id="product_requests_type">
                    <option value="">Tutte</option>
                    <?php foreach ($typeOptions as $option): ?>
                        <?php $value = (string) $option; ?>
                        <option value="<?= htmlspecialchars($value) ?>" <?= $typeFilter === $value ? 'selected' : '' ?>><?= htmlspecialchars($typeLabels[$value] ?? $value) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form__group">
                <label for="product_requests_payment">Pagamento</label>
                <?php $paymentFilter = (string) ($filters['payment'] ?? ''); ?>
                <select name="payment" id="product_requests_payment">
                    <option value="">Tutti</option>
                    <?php foreach ($paymentOptions as $option): ?>
                        <?php $value = (string) $option; ?>
                        <option value="<?= htmlspecialchars($value) ?>" <?= $paymentFilter === $value ? 'selected' : '' ?>><?= htmlspecialchars($paymentLabels[$value] ?? $value) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form__group">
                <label for="product_requests_from">Dal</label>
                <input type="date" name="from" id="product_requests_from" value="<?= htmlspecialchars((string) ($filters['from'] ?? '')) ?>">
            </div>
            <div class="form__group">
                <label for="product_requests_to">Al</label>
                <input type="date" name="to" id="product_requests_to" value="<?= htmlspecialchars((string) ($filters['to'] ?? '')) ?>">
            </div>
            <div class="form__group">
                <label for="product_requests_per_page">Risultati per pagina</label>
                <select name="per_page" id="product_requests_per_page">
                    <?php foreach ([10, 20, 30, 50] as $option): ?>
                        <option value="<?= $option ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= $option ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="filters-bar__actions">
            <button type="submit" class="btn btn--primary">Applica filtri</button>
            <?php if ($hasActiveFilters || $perPage !== 10): ?>
                <a class="btn btn--secondary" href="index.php?page=product_requests">Azzera filtri</a>
            <?php endif; ?>
        </div>
    </form>

    <section class="page__section">
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Prodotto</th>
                        <th>Cliente</th>
                        <th>Tipologia</th>
                        <th>Pagamento</th>
                        <th>Stato</th>
                        <th>Aggiornata</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($requests === []): ?>
                        <tr>
                            <td colspan="7">Nessun ordine trovato<?= $hasActiveFilters ? ' per i filtri attivi.' : '.' ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($requests as $row): ?>
                            <?php
                                $requestId = (int) ($row['id'] ?? 0);
                                $productName = trim((string) ($row['product_name'] ?? 'Prodotto non indicato'));
                                $productPrice = isset($row['product_price']) ? (float) $row['product_price'] : null;
                                $type = (string) ($row['request_type'] ?? 'Purchase');
                                $status = (string) ($row['status'] ?? 'Pending');
                                $payment = (string) ($row['payment_method'] ?? 'BankTransfer');
                                $customerName = trim((string) ($row['customer_name'] ?? ''));
                                $portalEmail = trim((string) ($row['portal_email'] ?? ''));
                                $customerEmail = trim((string) ($row['customer_email'] ?? ''));
                                $customerPhone = trim((string) ($row['customer_phone'] ?? ''));
                                $desiredPickup = $row['desired_pickup_date'] ?? null;
                                $depositAmount = isset($row['deposit_amount']) ? (float) $row['deposit_amount'] : null;
                                $installments = isset($row['installments']) ? (int) $row['installments'] : null;
                                $bankReference = trim((string) ($row['bank_transfer_reference'] ?? ''));
                                $createdAt = $formatDate($row['created_at'] ?? null);
                                $updatedAt = $formatDate($row['updated_at'] ?? ($row['created_at'] ?? null));
                                $metaParts = ['#' . $requestId, 'Inviato ' . $createdAt];
                                if ($desiredPickup) {
                                    $metaParts[] = 'Ritiro ' . $formatDate((string) $desiredPickup, 'd/m/Y');
                                }
                                if ($depositAmount !== null && $depositAmount > 0) {
                                    $metaParts[] = 'Acconto ' . $formatEuro($depositAmount);
                                }
                                if ($installments !== null && $installments > 0) {
                                    $metaParts[] = $installments . ' rate';
                                }
                                if ($bankReference !== '') {
                                    $metaParts[] = 'Rif. bonifico ' . $bankReference;
                                }
                                $contactBits = [];
                                if ($customerEmail !== '') {
                                    $contactBits[] = $customerEmail;
                                }
                                if ($portalEmail !== '' && $portalEmail !== $customerEmail) {
                                    $contactBits[] = $portalEmail;
                                }
                                if ($customerPhone !== '') {
                                    $contactBits[] = $customerPhone;
                                }
                                $customerDisplay = $customerName !== '' ? $customerName : ($portalEmail !== '' ? $portalEmail : 'Cliente portale');
                                $detailUrl = 'index.php?page=product_request&request_id=' . $requestId;
                                if ($requestId > 0 && $currentListUrl !== 'index.php?page=product_requests') {
                                    $detailUrl .= '&back=' . rawurlencode($currentListUrl);
                                }
                                $badgeClass = $statusBadges[$status] ?? 'badge badge--muted';
                            ?>
                            <tr>
                                <td>
                                    <div class="support-list__subject">
                                        <strong><?= htmlspecialchars($productName) ?></strong>
                                        <span class="support-list__meta"><?= htmlspecialchars(implode(' · ', $metaParts)) ?></span>
                                        <span class="support-list__meta">Valore: <?= htmlspecialchars($formatEuro($productPrice)) ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="support-list__customer">
                                        <strong><?= htmlspecialchars($customerDisplay) ?></strong>
                                        <?php if ($contactBits !== []): ?>
                                            <span class="support-list__meta"><?= htmlspecialchars(implode(' · ', $contactBits)) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge--muted"><?= htmlspecialchars($typeLabels[$type] ?? $type) ?></span>
                                </td>
                                <td>
                                    <span class="badge badge--outline"><?= htmlspecialchars($paymentLabels[$payment] ?? $payment) ?></span>
                                </td>
                                <td>
                                    <span class="<?= htmlspecialchars($badgeClass) ?>"><?= htmlspecialchars($statusLabels[$status] ?? $status) ?></span>
                                </td>
                                <td><?= htmlspecialchars($updatedAt) ?></td>
                                <td>
                                    <a class="btn btn--secondary btn--small" href="<?= htmlspecialchars($detailUrl) ?>">Apri ordine</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="pagination">
                <a class="pagination__link <?= $currentPage === 1 ? 'is-disabled' : '' ?>" href="<?= $currentPage === 1 ? '#' : htmlspecialchars($buildListUrl(['page_no' => 1])) ?>" aria-label="Prima pagina">«</a>
                <a class="pagination__link <?= $currentPage === 1 ? 'is-disabled' : '' ?>" href="<?= $currentPage === 1 ? '#' : htmlspecialchars($buildListUrl(['page_no' => $currentPage - 1])) ?>" aria-label="Pagina precedente">‹</a>
                <span class="pagination__info">Pagina <?= $currentPage ?> di <?= $totalPages ?> (<?= $totalResults ?> ordini)</span>
                <a class="pagination__link <?= $currentPage === $totalPages ? 'is-disabled' : '' ?>" href="<?= $currentPage === $totalPages ? '#' : htmlspecialchars($buildListUrl(['page_no' => $currentPage + 1])) ?>" aria-label="Pagina successiva">›</a>
                <a class="pagination__link <?= $currentPage === $totalPages ? 'is-disabled' : '' ?>" href="<?= $currentPage === $totalPages ? '#' : htmlspecialchars($buildListUrl(['page_no' => $totalPages])) ?>" aria-label="Ultima pagina">»</a>
            </nav>
        <?php endif; ?>
    </section>
</section>
