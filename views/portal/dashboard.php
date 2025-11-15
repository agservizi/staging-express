<?php
$totals = $data['totals'] ?? ['spent' => 0.0, 'paid' => 0.0, 'due' => 0.0, 'overdue_sales' => 0, 'pending_sales' => 0];
$recentSales = $data['recent_sales'] ?? [];
$nextDue = $data['next_due_date'] ?? null;
$openSupport = (int) ($data['open_support_requests'] ?? 0);
?>
<section class="portal-section">
    <div class="portal-grid">
        <article class="portal-card">
            <h2>Totale acquisti</h2>
            <p class="portal-card__value">€ <?= number_format((float) $totals['spent'], 2, ',', '.') ?></p>
            <small>Totale complessivo degli acquisti registrati</small>
        </article>
        <article class="portal-card">
            <h2>Pagato</h2>
            <p class="portal-card__value portal-card__value--success">€ <?= number_format((float) $totals['paid'], 2, ',', '.') ?></p>
            <small>Somma dei pagamenti già contabilizzati</small>
        </article>
        <article class="portal-card">
            <h2>Saldo residuo</h2>
            <p class="portal-card__value portal-card__value--warning">€ <?= number_format((float) $totals['due'], 2, ',', '.') ?></p>
            <?php if ($nextDue !== null): ?>
                <small>Prossima scadenza: <?= htmlspecialchars((string) $nextDue) ?></small>
            <?php else: ?>
                <small>Nessuna scadenza imminente</small>
            <?php endif; ?>
        </article>
        <article class="portal-card">
            <h2>Supporto aperto</h2>
            <p class="portal-card__value"><?= $openSupport ?></p>
            <small>Richieste di supporto in gestione</small>
        </article>
    </div>
</section>

<section class="portal-section">
    <header class="portal-section__header">
        <h2>Ultimi acquisti</h2>
        <a class="portal-link" href="index.php?view=sales">Vedi tutte le vendite</a>
    </header>
    <?php if ($recentSales === []): ?>
        <p class="portal-empty">Non ci sono vendite registrate.</p>
    <?php else: ?>
        <div class="portal-table-wrapper">
            <table class="portal-table">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Data</th>
                    <th>Totale</th>
                    <th>Saldo</th>
                    <th>Stato pagamento</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($recentSales as $sale): ?>
                    <tr>
                        <td><?= (int) $sale['id'] ?></td>
                        <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $sale['created_at']))) ?></td>
                        <td>€ <?= number_format((float) $sale['total'], 2, ',', '.') ?></td>
                        <td>€ <?= number_format((float) $sale['balance_due'], 2, ',', '.') ?></td>
                        <td><span class="portal-badge portal-badge--<?= portal_badge_class((string) $sale['payment_status']) ?>"><?= htmlspecialchars((string) $sale['payment_status']) ?></span></td>
                        <td><a class="portal-link" href="index.php?view=sale_detail&amp;sale_id=<?= (int) $sale['id'] ?>">Dettagli</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="portal-section">
    <header class="portal-section__header">
        <h2>Situazione saldi</h2>
    </header>
    <div class="portal-indicators">
        <div class="portal-indicator">
            <span class="portal-indicator__label">Vendite da saldare</span>
            <span class="portal-indicator__value"><?= (int) ($totals['pending_sales'] ?? 0) ?></span>
        </div>
        <div class="portal-indicator">
            <span class="portal-indicator__label">Vendite scadute</span>
            <span class="portal-indicator__value portal-indicator__value--alert"><?= (int) ($totals['overdue_sales'] ?? 0) ?></span>
        </div>
    </div>
</section>
