<?php
declare(strict_types=1);

/**
 * @var array<int, array<string, mixed>> $requests
 * @var array{page:int, per_page:int, total:int, pages:int} $pagination
 * @var array{status:?string,type:?string,q:?string,from:?string,to:?string} $filters
 * @var array<string, int> $summary
 * @var array<int, string> $statusOptions
 * @var array<int, string> $typeOptions
 * @var array{success:bool, message?:string, errors?:array<int, string>}|null $feedback
 * @var int $perPage
 */

$pageTitle = 'Richieste assistenza';
$requests = $requests ?? [];
$pagination = $pagination ?? ['page' => 1, 'per_page' => 10, 'total' => count($requests), 'pages' => 1];
$filters = $filters ?? ['status' => null, 'type' => null, 'q' => null, 'from' => null, 'to' => null];
$summary = $summary ?? [];
$statusOptions = $statusOptions ?? [];
$typeOptions = $typeOptions ?? [];
$feedback = $feedback ?? null;
$perPage = isset($perPage) ? (int) $perPage : (int) ($pagination['per_page'] ?? 10);

$statusLabels = [
    'Open' => 'Da gestire',
    'InProgress' => 'In lavorazione',
    'Completed' => 'Completate',
    'Cancelled' => 'Annullate',
];
$statusMeta = [
    'Open' => 'Richieste in coda',
    'InProgress' => 'Ticket assegnati',
    'Completed' => 'Ticket risolti',
    'Cancelled' => 'Ticket chiusi senza intervento',
];
$statusBadges = [
    'Open' => 'badge badge--warning',
    'InProgress' => 'badge badge--info',
    'Completed' => 'badge badge--success',
    'Cancelled' => 'badge badge--danger',
];
$typeLabels = [
    'Support' => 'Supporto',
    'Booking' => 'Appuntamento',
];

$formatDate = static function (?string $value): string {
    if (!is_string($value) || trim($value) === '') {
        return 'n/d';
    }
    $timestamp = strtotime($value);
    return $timestamp !== false ? date('d/m/Y H:i', $timestamp) : 'n/d';
};

$formatSlot = static function (?string $value) use ($formatDate): ?string {
    if (!is_string($value) || trim($value) === '') {
        return null;
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return trim($value);
    }
    return date('d/m/Y H:i', $timestamp);
};

$buildListUrl = static function (array $extra = []) use ($filters, $perPage): string {
    $params = ['page' => 'support_requests'];
    foreach (['status', 'type', 'q', 'from', 'to'] as $key) {
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
foreach (['status', 'type', 'q', 'from', 'to'] as $filterKey) {
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
        <h2>Supporto clienti</h2>
        <p class="muted">Monitora le richieste provenienti dal portale clienti, assegna lo stato di lavorazione e mantieni traccia delle note operative.</p>
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
                    <h3>Richieste totali</h3>
                </div>
                <div class="card__value"><?= $totalSummary ?></div>
                <p class="card__meta">Ticket registrati nel sistema</p>
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
        <input type="hidden" name="page" value="support_requests">
        <div class="filters-bar__row">
            <div class="form__group">
                <label for="support_q">Ricerca</label>
                <input type="text" name="q" id="support_q" value="<?= htmlspecialchars((string) ($filters['q'] ?? '')) ?>" placeholder="Oggetto, messaggio, cliente">
            </div>
            <div class="form__group">
                <label for="support_status">Stato</label>
                <?php $statusFilter = (string) ($filters['status'] ?? ''); ?>
                <select name="status" id="support_status">
                    <option value="">Tutti</option>
                    <?php foreach ($statusOptions as $option): ?>
                        <?php $value = (string) $option; ?>
                        <option value="<?= htmlspecialchars($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= htmlspecialchars($statusLabels[$value] ?? $value) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form__group">
                <label for="support_type">Tipologia</label>
                <?php $typeFilter = (string) ($filters['type'] ?? ''); ?>
                <select name="type" id="support_type">
                    <option value="">Tutte</option>
                    <?php foreach ($typeOptions as $option): ?>
                        <?php $value = (string) $option; ?>
                        <option value="<?= htmlspecialchars($value) ?>" <?= $typeFilter === $value ? 'selected' : '' ?>><?= htmlspecialchars($typeLabels[$value] ?? $value) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form__group">
                <label for="support_from">Dal</label>
                <input type="date" name="from" id="support_from" value="<?= htmlspecialchars((string) ($filters['from'] ?? '')) ?>">
            </div>
            <div class="form__group">
                <label for="support_to">Al</label>
                <input type="date" name="to" id="support_to" value="<?= htmlspecialchars((string) ($filters['to'] ?? '')) ?>">
            </div>
            <div class="form__group">
                <label for="support_per_page">Risultati per pagina</label>
                <select name="per_page" id="support_per_page">
                    <?php foreach ([10, 20, 30, 50] as $option): ?>
                        <option value="<?= $option ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= $option ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="filters-bar__actions">
            <button type="submit" class="btn btn--primary">Applica filtri</button>
            <?php if ($hasActiveFilters || $perPage !== 10): ?>
                <a class="btn btn--secondary" href="index.php?page=support_requests">Azzera filtri</a>
            <?php endif; ?>
        </div>
    </form>

    <section class="page__section">
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Richiesta</th>
                        <th>Cliente</th>
                        <th>Tipologia</th>
                        <th>Stato</th>
                        <th>Aggiornata</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($requests === []): ?>
                        <tr>
                            <td colspan="6">Nessuna richiesta trovata<?= $hasActiveFilters ? ' per i filtri attivi.' : '.' ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($requests as $row): ?>
                            <?php
                                $requestId = (int) ($row['id'] ?? 0);
                                $subject = trim((string) ($row['subject'] ?? ''));
                                if ($subject === '') {
                                    $subject = 'Richiesta senza oggetto';
                                }
                                $type = (string) ($row['type'] ?? '');
                                $status = (string) ($row['status'] ?? 'Open');
                                $preferredSlot = $formatSlot($row['preferred_slot'] ?? null);
                                $createdMeta = $formatDate($row['created_at'] ?? null);
                                $updatedMeta = $formatDate($row['updated_at'] ?? null);
                                $metaParts = ['#' . $requestId, 'Aperta ' . $createdMeta];
                                if ($preferredSlot !== null) {
                                    $metaParts[] = 'Slot richiesto ' . $preferredSlot;
                                }
                                $primaryContact = trim((string) ($row['customer_name'] ?? ''));
                                $portalEmail = trim((string) ($row['portal_email'] ?? ''));
                                if ($primaryContact === '') {
                                    $primaryContact = $portalEmail !== '' ? $portalEmail : 'Cliente area clienti';
                                }
                                $contactDetails = [];
                                $customerEmail = trim((string) ($row['customer_email'] ?? ''));
                                if ($customerEmail !== '') {
                                    $contactDetails[] = $customerEmail;
                                }
                                if ($portalEmail !== '' && $portalEmail !== $customerEmail) {
                                    $contactDetails[] = $portalEmail;
                                }
                                $customerPhone = trim((string) ($row['customer_phone'] ?? ''));
                                if ($customerPhone !== '') {
                                    $contactDetails[] = $customerPhone;
                                }
                                $contactDetails = array_values(array_unique($contactDetails));
                                $detailUrl = 'index.php?page=support_request&request_id=' . $requestId;
                                if ($requestId > 0 && $currentListUrl !== 'index.php?page=support_requests') {
                                    $detailUrl .= '&back=' . rawurlencode($currentListUrl);
                                }
                            ?>
                            <tr>
                                <td>
                                    <div class="support-list__subject">
                                        <strong><?= htmlspecialchars($subject) ?></strong>
                                        <span class="support-list__meta"><?= htmlspecialchars(implode(' · ', $metaParts)) ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="support-list__customer">
                                        <strong><?= htmlspecialchars($primaryContact) ?></strong>
                                        <?php if ($contactDetails !== []): ?>
                                            <span class="support-list__meta"><?= htmlspecialchars(implode(' · ', $contactDetails)) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge--muted"><?= htmlspecialchars($typeLabels[$type] ?? $type) ?></span>
                                </td>
                                <td>
                                    <?php $badgeClass = $statusBadges[$status] ?? 'badge badge--muted'; ?>
                                    <span class="<?= htmlspecialchars($badgeClass) ?>"><?= htmlspecialchars($statusLabels[$status] ?? $status) ?></span>
                                </td>
                                <td><?= htmlspecialchars($updatedMeta) ?></td>
                                <td>
                                    <div class="support-list__actions">
                                        <a class="btn btn--secondary btn--small" href="<?= htmlspecialchars($detailUrl) ?>">Apri dettagli</a>
                                    </div>
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
                <span class="pagination__info">Pagina <?= $currentPage ?> di <?= $totalPages ?> (<?= $totalResults ?> richieste)</span>
                <a class="pagination__link <?= $currentPage === $totalPages ? 'is-disabled' : '' ?>" href="<?= $currentPage === $totalPages ? '#' : htmlspecialchars($buildListUrl(['page_no' => $currentPage + 1])) ?>" aria-label="Pagina successiva">›</a>
                <a class="pagination__link <?= $currentPage === $totalPages ? 'is-disabled' : '' ?>" href="<?= $currentPage === $totalPages ? '#' : htmlspecialchars($buildListUrl(['page_no' => $totalPages])) ?>" aria-label="Ultima pagina">»</a>
            </nav>
        <?php endif; ?>
    </section>
</section>
