<?php
declare(strict_types=1);

/**
 * @var array<string, mixed> $request
 * @var array<int, string> $statusOptions
 * @var array{success:bool, message?:string, errors?:array<int, string>}|null $feedback
 * @var string $backUrl
 * @var string $backEncoded
 */

$request = $request ?? [];
$statusOptions = $statusOptions ?? [];
$feedback = $feedback ?? null;
$backUrl = isset($backUrl) && is_string($backUrl) && $backUrl !== '' ? $backUrl : 'index.php?page=support_requests';
$backEncoded = isset($backEncoded) && is_string($backEncoded) ? $backEncoded : '';

$requestId = (int) ($request['id'] ?? 0);
$pageTitle = 'Richiesta assistenza #' . $requestId;

$statusLabels = [
    'Open' => 'Da gestire',
    'InProgress' => 'In lavorazione',
    'Completed' => 'Completata',
    'Cancelled' => 'Annullata',
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

$formatSlot = static function (?string $value): ?string {
    if (!is_string($value) || trim($value) === '') {
        return null;
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return trim($value);
    }
    return date('d/m/Y H:i', $timestamp);
};

$currentStatus = (string) ($request['status'] ?? 'Open');
$statusBadgeClass = $statusBadges[$currentStatus] ?? 'badge badge--muted';
$subject = trim((string) ($request['subject'] ?? ''));
if ($subject === '') {
    $subject = 'Richiesta senza oggetto';
}
$type = (string) ($request['type'] ?? 'Support');
$preferredSlot = $formatSlot($request['preferred_slot'] ?? null);
$message = trim((string) ($request['message'] ?? ''));
$resolutionNote = trim((string) ($request['resolution_note'] ?? ''));
$customerName = trim((string) ($request['customer_name'] ?? '')); 
$customerEmail = trim((string) ($request['customer_email'] ?? ''));
$customerPhone = trim((string) ($request['customer_phone'] ?? ''));
$portalEmail = trim((string) ($request['portal_email'] ?? ''));
$createdAt = $formatDate($request['created_at'] ?? null);
$updatedAt = $formatDate($request['updated_at'] ?? ($request['created_at'] ?? null));
?>
<section class="page">
    <header class="page__header">
        <h2>Richiesta #<?= $requestId ?></h2>
        <p class="muted">Inviata il <?= htmlspecialchars((string) $createdAt) ?> · Stato attuale: <?= htmlspecialchars($statusLabels[$currentStatus] ?? $currentStatus) ?></p>
    </header>

    <?php if ($feedback !== null): ?>
        <div class="alert <?= $feedback['success'] ? 'alert--success' : 'alert--error' ?>">
            <p><?= htmlspecialchars($feedback['message'] ?? ($feedback['success'] ? 'Aggiornamento completato.' : 'Impossibile aggiornare la richiesta.')) ?></p>
            <?php if (!$feedback['success']): ?>
                <?php foreach ($feedback['errors'] ?? [] as $error): ?>
                    <p class="muted"><?= htmlspecialchars($error) ?></p>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="support-detail">
        <div class="support-detail__main">
            <article class="card">
                <div class="card__header">
                    <h3><?= htmlspecialchars($subject) ?></h3>
                    <span class="<?= htmlspecialchars($statusBadgeClass) ?>"><?= htmlspecialchars($statusLabels[$currentStatus] ?? $currentStatus) ?></span>
                </div>
                <div class="support-detail__meta">
                    <div>
                        <span>Tipologia</span>
                        <strong><?= htmlspecialchars($typeLabels[$type] ?? $type) ?></strong>
                    </div>
                    <div>
                        <span>Aperta il</span>
                        <strong><?= htmlspecialchars((string) $createdAt) ?></strong>
                    </div>
                    <div>
                        <span>Ultimo aggiornamento</span>
                        <strong><?= htmlspecialchars((string) $updatedAt) ?></strong>
                    </div>
                    <?php if ($preferredSlot !== null): ?>
                        <div>
                            <span>Slot richiesto</span>
                            <strong><?= htmlspecialchars($preferredSlot) ?></strong>
                        </div>
                    <?php endif; ?>
                </div>
                <h4>Messaggio inviato</h4>
                <div class="support-message">
                    <?= nl2br(htmlspecialchars($message !== '' ? $message : 'Il cliente non ha inserito un messaggio aggiuntivo.')) ?>
                </div>
            </article>

            <?php if ($resolutionNote !== ''): ?>
                <article class="card">
                    <div class="card__header">
                        <h3>Storico note</h3>
                    </div>
                    <div class="support-note"><?= nl2br(htmlspecialchars($resolutionNote)) ?></div>
                </article>
            <?php endif; ?>

            <article class="card">
                <form method="post" class="form">
                    <h3>Aggiorna stato e note</h3>
                    <div class="form__grid">
                        <div class="form__group">
                            <label for="support_status">Stato richiesta</label>
                            <select name="status" id="support_status" required>
                                <?php foreach ($statusOptions as $option): ?>
                                    <?php $value = (string) $option; ?>
                                    <option value="<?= htmlspecialchars($value) ?>" <?= $currentStatus === $value ? 'selected' : '' ?>><?= htmlspecialchars($statusLabels[$value] ?? $value) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form__group">
                            <label class="form__checkbox">
                                <input type="checkbox" name="append_note" value="1" checked>
                                <span>Aggiungi la nota allo storico esistente</span>
                            </label>
                        </div>
                    </div>
                    <div class="form__group">
                        <label for="resolution_note">Nota operativa</label>
                        <textarea name="resolution_note" id="resolution_note" rows="4" placeholder="Scrivi un aggiornamento per il cliente o per il team interno."></textarea>
                        <small class="muted">Lascia vuoto per aggiornare solo lo stato.</small>
                    </div>
                    <div class="form__footer">
                        <div class="payment-hints">
                            <span>Ultimo aggiornamento: <?= htmlspecialchars((string) $updatedAt) ?></span>
                        </div>
                        <div>
                            <a class="btn btn--secondary" href="<?= htmlspecialchars($backUrl) ?>">Torna alla lista</a>
                            <button type="submit" class="btn btn--primary">Salva aggiornamento</button>
                        </div>
                    </div>
                    <input type="hidden" name="action" value="update_request">
                    <input type="hidden" name="request_id" value="<?= $requestId ?>">
                    <input type="hidden" name="back" value="<?= htmlspecialchars($backEncoded) ?>">
                </form>
            </article>
        </div>

        <aside class="support-detail__sidebar">
            <article class="card">
                <div class="card__header">
                    <h3>Dettagli cliente</h3>
                </div>
                <ul class="support-detail__info-list">
                    <li>
                        <span>Cliente</span>
                        <strong><?= htmlspecialchars($customerName !== '' ? $customerName : ($portalEmail !== '' ? $portalEmail : 'Cliente area clienti')) ?></strong>
                    </li>
                    <?php if ($customerEmail !== ''): ?>
                        <li>
                            <span>Email CRM</span>
                            <strong><?= htmlspecialchars($customerEmail) ?></strong>
                        </li>
                    <?php endif; ?>
                    <?php if ($portalEmail !== ''): ?>
                        <li>
                            <span>Email portale</span>
                            <strong><?= htmlspecialchars($portalEmail) ?></strong>
                        </li>
                    <?php endif; ?>
                    <?php if ($customerPhone !== ''): ?>
                        <li>
                            <span>Telefono</span>
                            <strong><?= htmlspecialchars($customerPhone) ?></strong>
                        </li>
                    <?php endif; ?>
                    <?php if ($preferredSlot !== null): ?>
                        <li>
                            <span>Disponibilità indicata</span>
                            <strong><?= htmlspecialchars($preferredSlot) ?></strong>
                        </li>
                    <?php endif; ?>
                </ul>
            </article>
        </aside>
    </div>
</section>
