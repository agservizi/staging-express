<?php
declare(strict_types=1);

/**
 * @var array<string, mixed> $request
 * @var array<int, string> $statusOptions
 * @var array<int, string> $typeOptions
 * @var array<int, string> $paymentOptions
 * @var array{success:bool, message?:string, errors?:array<int, string>}|null $feedback
 * @var string $backUrl
 * @var string $backEncoded
 */

$request = $request ?? [];
$statusOptions = $statusOptions ?? [];
$typeOptions = $typeOptions ?? [];
$paymentOptions = $paymentOptions ?? [];
$feedback = $feedback ?? null;
$backUrl = isset($backUrl) && is_string($backUrl) && $backUrl !== '' ? $backUrl : 'index.php?page=product_requests';
$backEncoded = isset($backEncoded) && is_string($backEncoded) ? $backEncoded : '';

$statusLabels = [
	'Pending' => 'In attesa',
	'InReview' => 'In verifica',
	'Confirmed' => 'Confermata',
	'Completed' => 'Evadita',
	'Cancelled' => 'Annullata',
	'Declined' => 'Rifiutata',
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

$requestId = (int) ($request['id'] ?? 0);
$pageTitle = 'Ordine store #' . $requestId;
$productName = trim((string) ($request['product_name'] ?? 'Prodotto non indicato'));
$productPrice = isset($request['product_price']) ? (float) $request['product_price'] : null;
$requestType = (string) ($request['request_type'] ?? 'Purchase');
$status = (string) ($request['status'] ?? 'Pending');
$paymentMethod = (string) ($request['payment_method'] ?? 'BankTransfer');
$depositAmount = isset($request['deposit_amount']) ? (float) $request['deposit_amount'] : null;
$installments = isset($request['installments']) ? (int) $request['installments'] : null;
$desiredPickup = $request['desired_pickup_date'] ?? null;
$bankReference = trim((string) ($request['bank_transfer_reference'] ?? ''));
$note = trim((string) ($request['note'] ?? ''));
$handlingNote = trim((string) ($request['handling_note'] ?? ''));
$handledAt = $formatDate($request['handled_at'] ?? null);
$handledBy = trim((string) ($request['handled_by_name'] ?? $request['handled_by_username'] ?? ''));
$createdAt = $formatDate($request['created_at'] ?? null);
$updatedAt = $formatDate($request['updated_at'] ?? ($request['created_at'] ?? null));

$customerName = trim((string) ($request['customer_name'] ?? ''));
$customerEmail = trim((string) ($request['customer_email'] ?? ''));
$customerPhone = trim((string) ($request['customer_phone'] ?? ''));
$portalEmail = trim((string) ($request['portal_email'] ?? ''));
$productCategory = trim((string) ($request['product_category'] ?? ''));
$productStock = isset($request['stock_quantity']) ? (int) $request['stock_quantity'] : null;
$productReserved = isset($request['stock_reserved']) ? (int) $request['stock_reserved'] : null;

$currentStatusBadge = $statusBadges[$status] ?? 'badge badge--muted';
$desiredPickupHuman = $desiredPickup ? $formatDate((string) $desiredPickup, 'd/m/Y') : null;
$hasHandlingHistory = $handlingNote !== '';
$handledByDisplay = $handledBy !== '' ? $handledBy : 'Operatore';
?>
<section class="page">
	<header class="page__header">
		<h2>Ordine #<?= $requestId ?></h2>
		<p class="muted">Ricevuto il <?= htmlspecialchars($createdAt) ?> · Stato attuale: <?= htmlspecialchars($statusLabels[$status] ?? $status) ?></p>
	</header>

	<?php if ($feedback !== null): ?>
		<div class="alert <?= $feedback['success'] ? 'alert--success' : 'alert--error' ?>">
			<p><?= htmlspecialchars($feedback['message'] ?? ($feedback['success'] ? 'Aggiornamento completato.' : 'Impossibile aggiornare l\'ordine.')) ?></p>
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
					<h3><?= htmlspecialchars($productName) ?></h3>
					<span class="<?= htmlspecialchars($currentStatusBadge) ?>"><?= htmlspecialchars($statusLabels[$status] ?? $status) ?></span>
				</div>
				<div class="support-detail__meta">
					<div>
						<span>Tipologia</span>
						<strong><?= htmlspecialchars($typeLabels[$requestType] ?? $requestType) ?></strong>
					</div>
					<div>
						<span>Valore ordine</span>
						<strong><?= htmlspecialchars($formatEuro($productPrice)) ?></strong>
					</div>
					<div>
						<span>Metodo pagamento</span>
						<strong><?= htmlspecialchars($paymentLabels[$paymentMethod] ?? $paymentMethod) ?></strong>
					</div>
					<?php if ($desiredPickupHuman !== null): ?>
						<div>
							<span>Data ritiro desiderata</span>
							<strong><?= htmlspecialchars($desiredPickupHuman) ?></strong>
						</div>
					<?php endif; ?>
					<?php if ($depositAmount !== null && $depositAmount > 0): ?>
						<div>
							<span>Acconto indicato</span>
							<strong><?= htmlspecialchars($formatEuro($depositAmount)) ?></strong>
						</div>
					<?php endif; ?>
					<?php if ($installments !== null && $installments > 0): ?>
						<div>
							<span>Rate previste</span>
							<strong><?= $installments ?></strong>
						</div>
					<?php endif; ?>
					<div>
						<span>Ultimo aggiornamento</span>
						<strong><?= htmlspecialchars($updatedAt) ?></strong>
					</div>
					<?php if ($handledAt !== 'n/d'): ?>
						<div>
							<span>Gestita da</span>
							<strong><?= htmlspecialchars($handledByDisplay) ?> · <?= htmlspecialchars($handledAt) ?></strong>
						</div>
					<?php endif; ?>
				</div>

				<?php if ($bankReference !== ''): ?>
					<div class="support-message">
						<h4>Riferimento bonifico</h4>
						<p><?= htmlspecialchars($bankReference) ?></p>
					</div>
				<?php endif; ?>

				<h4>Note del cliente</h4>
				<div class="support-message">
					<?= nl2br(htmlspecialchars($note !== '' ? $note : 'Il cliente non ha lasciato note aggiuntive.')) ?>
				</div>
			</article>

			<?php if ($hasHandlingHistory): ?>
				<article class="card">
					<div class="card__header">
						<h3>Storico gestione</h3>
					</div>
					<div class="support-note"><?= nl2br(htmlspecialchars($handlingNote)) ?></div>
				</article>
			<?php endif; ?>

			<article class="card">
				<form method="post" class="form">
					<h3>Aggiorna stato e note</h3>
					<div class="form__grid">
						<div class="form__group">
							<label for="product_request_status">Stato ordine</label>
							<select name="status" id="product_request_status" required>
								<?php foreach ($statusOptions as $option): ?>
									<?php $value = (string) $option; ?>
									<option value="<?= htmlspecialchars($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= htmlspecialchars($statusLabels[$value] ?? $value) ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="form__group">
							<label for="product_request_payment_method">Metodo pagamento</label>
							<select name="payment_method" id="product_request_payment_method">
								<option value="">Lascia invariato</option>
								<?php foreach ($paymentOptions as $option): ?>
									<?php $value = (string) $option; ?>
									<option value="<?= htmlspecialchars($value) ?>" <?= $paymentMethod === $value ? 'selected' : '' ?>><?= htmlspecialchars($paymentLabels[$value] ?? $value) ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
					<div class="form__group">
						<label for="product_request_bank_reference">Riferimento bonifico</label>
						<input type="text" name="bank_transfer_reference" id="product_request_bank_reference" value="<?= htmlspecialchars($bankReference) ?>" maxlength="120" placeholder="Inserisci o aggiorna il riferimento del bonifico">
						<small class="muted">Lascia vuoto per cancellare il riferimento salvato.</small>
					</div>
					<div class="form__group">
						<label class="form__checkbox">
							<input type="checkbox" name="append_note" value="1" checked>
							<span>Aggiungi la nota allo storico esistente</span>
						</label>
					</div>
					<div class="form__group">
						<label for="product_request_handling_note">Nota operativa</label>
						<textarea name="handling_note" id="product_request_handling_note" rows="4" placeholder="Annota esito, prossimi passi o informazioni per il team."></textarea>
						<small class="muted">Lascia vuoto per aggiornare solo lo stato.</small>
					</div>
					<div class="form__footer">
						<div class="payment-hints">
							<span>Ultimo aggiornamento: <?= htmlspecialchars($updatedAt) ?></span>
						</div>
						<div>
							<a class="btn btn--secondary" href="<?= htmlspecialchars($backUrl) ?>">Torna agli ordini</a>
							<button type="submit" class="btn btn--primary">Salva aggiornamento</button>
						</div>
					</div>
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
						<strong><?= htmlspecialchars($customerName !== '' ? $customerName : ($portalEmail !== '' ? $portalEmail : 'Cliente portale')) ?></strong>
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
				</ul>
			</article>

			<article class="card">
				<div class="card__header">
					<h3>Dettagli prodotto</h3>
				</div>
				<ul class="support-detail__info-list">
					<li>
						<span>Prodotto</span>
						<strong><?= htmlspecialchars($productName) ?></strong>
					</li>
					<li>
						<span>Valore</span>
						<strong><?= htmlspecialchars($formatEuro($productPrice)) ?></strong>
					</li>
					<?php if ($productCategory !== ''): ?>
						<li>
							<span>Categoria</span>
							<strong><?= htmlspecialchars($productCategory) ?></strong>
						</li>
					<?php endif; ?>
					<?php if ($productStock !== null): ?>
						<li>
							<span>Stock disponibile</span>
							<strong><?= $productStock ?></strong>
						</li>
					<?php endif; ?>
					<?php if ($productReserved !== null): ?>
						<li>
							<span>Stock riservato</span>
							<strong><?= $productReserved ?></strong>
						</li>
					<?php endif; ?>
				</ul>
			</article>
		</aside>
	</div>
</section>

