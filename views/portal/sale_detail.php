<?php
/** @var array<string, mixed> $data */
$sale = $data['sale'] ?? [];
$feedbackPayment = $data['feedbackPayment'] ?? null;
$items = $sale['items'] ?? [];
$payments = $sale['payments'] ?? [];
?>
<section class="portal-section">
    <header class="portal-section__header">
        <h2>Scontrino #<?= (int) ($sale['id'] ?? 0) ?></h2>
        <div class="portal-actions">
            <a class="portal-button portal-button--ghost" href="index.php?view=sales">Torna all'elenco</a>
            <a class="portal-button portal-button--primary" href="../print_receipt.php?sale_id=<?= (int) $sale['id'] ?>" target="_blank" rel="noopener">Scarica ricevuta</a>
        </div>
    </header>
    <div class="portal-grid">
        <article class="portal-card">
            <h3>Totale documento</h3>
            <p class="portal-card__value">€ <?= number_format((float) ($sale['total'] ?? 0.0), 2, ',', '.') ?></p>
            <small>Creato il <?= htmlspecialchars(isset($sale['created_at']) ? date('d/m/Y H:i', strtotime((string) $sale['created_at'])) : '-') ?></small>
        </article>
        <article class="portal-card">
            <h3>Pagato</h3>
            <p class="portal-card__value portal-card__value--success">€ <?= number_format((float) ($sale['total_paid'] ?? 0.0), 2, ',', '.') ?></p>
            <small>Ultimo metodo: <?= htmlspecialchars((string) ($sale['payment_method'] ?? 'n/d')) ?></small>
        </article>
        <article class="portal-card">
            <h3>Saldo residuo</h3>
            <p class="portal-card__value portal-card__value--warning">€ <?= number_format((float) ($sale['balance_due'] ?? 0.0), 2, ',', '.') ?></p>
            <small>Stato pagamento: <span class="portal-badge portal-badge--<?= portal_badge_class((string) ($sale['payment_status'] ?? 'Pending')) ?>"><?= htmlspecialchars((string) ($sale['payment_status'] ?? 'Pending')) ?></span></small>
        </article>
        <article class="portal-card">
            <h3>Stato vendita</h3>
            <p class="portal-card__value"><?= htmlspecialchars((string) ($sale['status'] ?? 'n/d')) ?></p>
            <small>Scadenza: <?= !empty($sale['due_date']) ? htmlspecialchars(date('d/m/Y', strtotime((string) $sale['due_date']))) : '—' ?></small>
        </article>
    </div>
</section>

<section class="portal-section">
    <header class="portal-section__header">
        <h3>Dettaglio articoli</h3>
    </header>
    <?php if ($items === []): ?>
        <p class="portal-empty">Nessun articolo registrato.</p>
    <?php else: ?>
        <div class="portal-table-wrapper">
            <table class="portal-table">
                <thead>
                <tr>
                    <th>Descrizione</th>
                    <th>Quantità</th>
                    <th>Prezzo</th>
                    <th>Imposte</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($item['description'] ?? $item['iccid'] ?? 'Articolo')) ?></td>
                        <td><?= (int) ($item['quantity'] ?? 1) ?></td>
                        <td>€ <?= number_format((float) ($item['price'] ?? 0.0), 2, ',', '.') ?></td>
                        <td><?= isset($item['tax_rate']) ? (float) $item['tax_rate'] . '%' : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="portal-section">
    <header class="portal-section__header">
        <h3>Pagamenti registrati</h3>
    </header>
    <?php if ($payments === []): ?>
        <p class="portal-empty">Non sono presenti pagamenti associati a questo scontrino.</p>
    <?php else: ?>
        <div class="portal-table-wrapper">
            <table class="portal-table">
                <thead>
                <tr>
                    <th>Data</th>
                    <th>Importo</th>
                    <th>Metodo</th>
                    <th>Stato</th>
                    <th>Riferimento</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($payments as $payment): ?>
                    <tr>
                        <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $payment['created_at']))) ?></td>
                        <td>€ <?= number_format((float) $payment['amount'], 2, ',', '.') ?></td>
                        <td><?= htmlspecialchars((string) $payment['payment_method']) ?></td>
                        <td><span class="portal-badge portal-badge--<?= portal_badge_class((string) $payment['status']) ?>"><?= htmlspecialchars((string) $payment['status']) ?></span></td>
                        <td><?= htmlspecialchars((string) ($payment['provider_reference'] ?? '—')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="portal-section">
    <header class="portal-section__header">
        <h3>Segnala un pagamento</h3>
    </header>
    <?php if ($feedbackPayment !== null): ?>
        <div class="portal-alert portal-alert--<?= ($feedbackPayment['success'] ?? false) ? 'success' : 'error' ?>">
            <p><?= htmlspecialchars($feedbackPayment['message'] ?? 'Operazione completata.') ?></p>
            <?php foreach ($feedbackPayment['errors'] ?? [] as $error): ?>
                <p><?= htmlspecialchars($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <form method="post" class="portal-form portal-form--inline">
        <input type="hidden" name="action" value="create_payment">
        <input type="hidden" name="sale_id" value="<?= (int) $sale['id'] ?>">
        <label>
            Importo
            <input type="number" step="0.01" min="0" name="amount" value="<?= number_format((float) ($sale['balance_due'] ?? 0.0), 2, '.', '') ?>" required>
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
        <label class="portal-form__full">
            Nota (facoltativa)
            <textarea name="note" rows="3" placeholder="Puoi inserire riferimenti o note per lo staff"></textarea>
        </label>
        <button type="submit" class="portal-button portal-button--primary">Invia segnalazione</button>
    </form>
</section>
