<?php
declare(strict_types=1);

/**
 * @var array<string, int|float|string> $metrics
 * @var array<int, array<string, mixed>> $stockAlerts
 * @var array<int, array<string, mixed>> $providerInsights
 * @var string $selectedPeriod
 * @var array<int, string> $lowStockProviders
 */
$pageTitle = 'Dashboard';
$selectedPeriod = $selectedPeriod ?? ($metrics['period_label'] ?? 'day');
$lowStockProviders = $lowStockProviders ?? [];
$periodLabels = [
    'day' => 'Giorno',
    'month' => 'Mese',
    'year' => 'Anno',
];
$salesTrend = (array) ($metrics['sales_trend']['points'] ?? []);
$salesTrendMeta = (array) ($metrics['sales_trend'] ?? []);
$campaignPerformance = (array) ($metrics['campaign_performance']['items'] ?? []);
$campaignPerformanceMeta = (array) ($metrics['campaign_performance'] ?? []);
$recentEvents = (array) ($metrics['recent_events'] ?? []);
$operatorActivity = (array) ($metrics['operator_activity'] ?? []);
$stockRiskSummary = (array) ($stockRiskSummary ?? []);
$nextSteps = (array) ($nextSteps ?? []);
$formatDateTime = static function (?string $value, string $format = 'd/m/Y H:i'): string {
    if ($value === null || $value === '') {
        return 'n/d';
    }
    try {
        return (new \DateTimeImmutable($value))->format($format);
    } catch (\Throwable $exception) {
        return (string) $value;
    }
};
?>
<section class="page">
    <header class="page__header">
        <h2>Dashboard</h2>
        <p>Panoramica rapida di stock Sim e vendite odierne.</p>
        <?php if (!empty($nextSteps)): ?>
            <div class="page__actions">
                <h3 class="page__actions-title">Azioni consigliate</h3>
                <ul class="action-list action-list--header">
                    <?php foreach ($nextSteps as $step): ?>
                        <?php $severity = htmlspecialchars((string) ($step['severity'] ?? 'info')); ?>
                        <li class="action-list__item action-list__item--<?= $severity ?>">
                            <span><?= htmlspecialchars((string) ($step['label'] ?? '')) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </header>
    <div class="cards" data-draggable-container="dashboard-metrics">
        <article class="card" data-draggable-card="metric-total">
            <h3>Sim totali</h3>
            <p class="card__value"><?= (int) $metrics['iccid_total'] ?></p>
        </article>
        <article class="card" data-draggable-card="metric-available">
            <h3>Sim disponibili</h3>
            <p class="card__value"><?= (int) $metrics['iccid_available'] ?></p>
        </article>
        <article class="card" data-draggable-card="metric-sales">
            <div class="card__header">
                <h3>Vendite</h3>
                <div class="card__filters">
                    <?php foreach ($periodLabels as $key => $label): ?>
                        <a
                            class="pill-switch<?= $selectedPeriod === $key ? ' is-active' : '' ?>"
                            href="index.php?page=dashboard&period=<?= $key ?>"
                        ><?= htmlspecialchars($label) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <p class="card__value"><?= (int) ($metrics['sales_count'] ?? 0) ?></p>
            <p class="card__meta">Periodo selezionato: <?= htmlspecialchars($periodLabels[$selectedPeriod] ?? 'Giorno') ?></p>
        </article>
        <article class="card" data-draggable-card="metric-revenue">
            <div class="card__header">
                <h3>Incasso</h3>
                <div class="card__filters">
                    <?php foreach ($periodLabels as $key => $label): ?>
                        <a
                            class="pill-switch<?= $selectedPeriod === $key ? ' is-active' : '' ?>"
                            href="index.php?page=dashboard&period=<?= $key ?>"
                        ><?= htmlspecialchars($label) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <p class="card__value">€ <?= number_format((float) ($metrics['revenue_sum'] ?? 0.0), 2, ',', '.') ?></p>
            <p class="card__meta">Periodo selezionato: <?= htmlspecialchars($periodLabels[$selectedPeriod] ?? 'Giorno') ?></p>
        </article>
        <article class="card" data-draggable-card="metric-low-stock">
            <h3>Operatori sotto soglia</h3>
            <p class="card__value"><?= (int) ($metrics['low_stock_providers'] ?? 0) ?></p>
            <?php if (!empty($lowStockProviders)): ?>
                <p class="card__meta" style="margin-top:6px;font-size:12px;color:#475569;">
                    <?= htmlspecialchars(implode(', ', $lowStockProviders)) ?>
                </p>
            <?php else: ?>
                <p class="card__meta">Tutti gli operatori sono sopra soglia.</p>
            <?php endif; ?>
        </article>
    </div>

    <div class="dashboard-grid" data-draggable-container="dashboard-panels">
        <section class="dashboard-panel dashboard-panel--wide" data-draggable-card="panel-sales-trend">
            <header class="dashboard-panel__header">
                <h3>Performance ultimi 7 giorni</h3>
                <p class="dashboard-panel__meta">
                    Totale vendite: <?= (int) ($salesTrendMeta['total_count'] ?? 0) ?> · Incasso: € <?= number_format((float) ($salesTrendMeta['total_revenue'] ?? 0.0), 2, ',', '.') ?>
                </p>
            </header>
            <?php if (empty($salesTrend)): ?>
                <p class="muted">Nessuna vendita registrata negli ultimi 7 giorni.</p>
            <?php else: ?>
                <div class="trend-grid">
                    <?php foreach ($salesTrend as $point): ?>
                        <?php
                            $count = (int) ($point['count'] ?? 0);
                            $revenue = (float) ($point['revenue'] ?? 0.0);
                            $countPct = (int) ($point['count_pct'] ?? 0);
                            $revenuePct = (int) ($point['revenue_pct'] ?? 0);
                            $label = (string) ($point['label'] ?? '');
                        ?>
                        <article class="trend-card" title="Vendite <?= $count ?> · Incasso € <?= number_format($revenue, 2, ',', '.') ?>">
                            <span class="trend-card__label"><?= htmlspecialchars($label) ?></span>
                            <span class="trend-card__value"><?= $count ?> vendite</span>
                            <div class="trend-bar">
                                <span class="trend-bar__fill" style="width: <?= $countPct ?>%;"></span>
                            </div>
                            <div class="trend-bar trend-bar--secondary">
                                <span class="trend-bar__fill" style="width: <?= $revenuePct ?>%;"></span>
                            </div>
                            <span class="trend-card__meta">Incasso € <?= number_format($revenue, 2, ',', '.') ?></span>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="dashboard-panel" data-draggable-card="panel-campaigns">
            <header class="dashboard-panel__header">
                <h3>Campagne sconto</h3>
                <p class="dashboard-panel__meta">
                    Attive: <?= (int) ($campaignPerformanceMeta['active_total'] ?? 0) ?> · Sconto erogato: € <?= number_format((float) ($campaignPerformanceMeta['discount_total'] ?? 0.0), 2, ',', '.') ?>
                </p>
            </header>
            <?php if (empty($campaignPerformance)): ?>
                <p class="muted">Nessuna campagna configurata.</p>
            <?php else: ?>
                <ul class="campaign-list">
                    <?php foreach (array_slice($campaignPerformance, 0, 5) as $campaign): ?>
                        <?php
                            $active = !empty($campaign['is_active']);
                            $typeRaw = strtolower((string) ($campaign['type'] ?? 'Fixed'));
                            $typeLabel = $typeRaw === 'percent' ? 'Percentuale' : 'Importo fisso';
                            $value = (float) ($campaign['value'] ?? 0.0);
                            $endsIn = $campaign['ends_in_days'] ?? null;
                            $endsLabel = null;
                            if ($endsIn !== null && is_numeric($endsIn)) {
                                $endsInt = (int) $endsIn;
                                if ($endsInt > 0) {
                                    $endsLabel = 'Scade tra ' . $endsInt . ' giorni';
                                } elseif ($endsInt === 0) {
                                    $endsLabel = 'Scade oggi';
                                } else {
                                    $endsLabel = 'Scaduta da ' . abs($endsInt) . ' giorni';
                                }
                            } elseif (!empty($campaign['ends_at'])) {
                                $endsLabel = 'Scadenza: ' . $formatDateTime((string) $campaign['ends_at'], 'd/m/Y');
                            } else {
                                $endsLabel = 'Validità continua';
                            }
                        ?>
                        <li class="campaign-card <?= $active ? 'is-active' : 'is-inactive' ?>">
                            <div class="campaign-card__header">
                                <span><?= htmlspecialchars((string) ($campaign['name'] ?? '')) ?></span>
                                <span class="badge <?= $active ? 'badge--success' : 'badge--muted' ?>"><?= $active ? 'Attiva' : 'Disattiva' ?></span>
                            </div>
                            <div class="campaign-card__stats">
                                <span><?= $typeLabel ?> · <?= $typeRaw === 'percent' ? number_format($value, 2, ',', '.') . '%' : '€ ' . number_format($value, 2, ',', '.') ?></span>
                                <span>Vendite: <?= (int) ($campaign['sales_count'] ?? 0) ?> (oggi <?= (int) ($campaign['sales_today'] ?? 0) ?>)</span>
                                <span>Incasso: € <?= number_format((float) ($campaign['revenue_total'] ?? 0.0), 2, ',', '.') ?></span>
                                <span>Sconto erogato: € <?= number_format((float) ($campaign['discount_total'] ?? 0.0), 2, ',', '.') ?></span>
                                <span><?= htmlspecialchars($endsLabel) ?></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="dashboard-panel" data-draggable-card="panel-stock">
            <header class="dashboard-panel__header">
                <h3>Intelligence stock</h3>
                <p class="dashboard-panel__meta">Focus sui primi rischi</p>
            </header>
            <?php if (empty($stockRiskSummary)): ?>
                <p class="muted">Nessun rischio rilevante sul magazzino.</p>
            <?php else: ?>
                <ul class="stock-list">
                    <?php foreach ($stockRiskSummary as $risk): ?>
                        <?php
                            $riskLevel = htmlspecialchars((string) ($risk['risk_level'] ?? 'ok'));
                            $coverage = $risk['days_cover'] ?? null;
                        ?>
                        <li class="stock-item stock-item--<?= $riskLevel ?>">
                            <div class="stock-item__header">
                                <strong><?= htmlspecialchars((string) ($risk['provider_name'] ?? '')) ?></strong>
                                <span><?= (int) ($risk['current_stock'] ?? 0) ?> disponibili · soglia <?= (int) ($risk['threshold'] ?? 0) ?></span>
                            </div>
                            <div class="stock-item__meta">
                                <span>Vendite/giorno: <?= number_format((float) ($risk['average_daily_sales'] ?? 0.0), 2, ',', '.') ?></span>
                                <span>Copertura: <?= $coverage !== null ? number_format((float) $coverage, 1, ',', '.') . ' giorni' : 'n/d' ?></span>
                                <?php if (!empty($risk['suggested_reorder'])): ?>
                                    <span>Riordino suggerito: <?= (int) $risk['suggested_reorder'] ?> SIM</span>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="dashboard-panel" data-draggable-card="panel-activity">
            <header class="dashboard-panel__header">
                <h3>Attività operatori</h3>
                <p class="dashboard-panel__meta">Ultime operazioni registrate</p>
            </header>
            <?php if (empty($operatorActivity)): ?>
                <p class="muted">Ancora nessuna operazione recente.</p>
            <?php else: ?>
                <ul class="activity-list">
                    <?php foreach ($operatorActivity as $entry): ?>
                        <?php
                            $statusRaw = strtolower((string) ($entry['status'] ?? ''));
                            $statusLabel = match ($statusRaw) {
                                'completed' => 'Completato',
                                'cancelled' => 'Annullato',
                                'refunded' => 'Reso',
                                default => ucfirst($statusRaw),
                            };
                        ?>
                        <li class="activity-entry <?= $statusRaw !== 'completed' ? 'activity-entry--warning' : '' ?>">
                            <span class="activity-entry__title">Scontrino #<?= (int) ($entry['sale_id'] ?? 0) ?><?= !empty($entry['user']) ? ' · ' . htmlspecialchars((string) $entry['user']) : '' ?></span>
                            <span class="activity-entry__meta"><?= htmlspecialchars($formatDateTime($entry['created_at'] ?? '')) ?> · Pagamento <?= htmlspecialchars((string) ($entry['payment_method'] ?? '')) ?></span>
                            <span class="activity-entry__value">Incasso: € <?= number_format((float) ($entry['total'] ?? 0.0), 2, ',', '.') ?><?php if (!empty($entry['discount'])): ?> · Sconto € <?= number_format((float) $entry['discount'], 2, ',', '.') ?><?php endif; ?></span>
                            <span class="activity-entry__status">Stato: <?= htmlspecialchars($statusLabel ?: '-') ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="dashboard-panel dashboard-panel--wide" data-draggable-card="panel-timeline">
            <header class="dashboard-panel__header">
                <h3>Timeline eventi</h3>
                <p class="dashboard-panel__meta">Audit log e movimentazioni recenti</p>
            </header>
            <?php if (empty($recentEvents)): ?>
                <p class="muted">Nessun evento registrato di recente.</p>
            <?php else: ?>
                <ul class="timeline">
                    <?php foreach ($recentEvents as $event): ?>
                        <?php
                            $eventLabel = (string) ($event['action_label'] ?? $event['action'] ?? 'Aggiornamento');
                            if ($eventLabel === '') {
                                $eventLabel = 'Aggiornamento';
                            }
                        ?>
                        <li class="timeline__item">
                            <span class="timeline__dot" aria-hidden="true"></span>
                            <div class="timeline__body">
                                <span class="timeline__title"><?= htmlspecialchars($eventLabel) ?><?= !empty($event['user']) ? ' · ' . htmlspecialchars((string) $event['user']) : '' ?></span>
                                <?php if (!empty($event['description'])): ?>
                                    <p class="timeline__description"><?= htmlspecialchars((string) $event['description']) ?></p>
                                <?php endif; ?>
                                <span class="timeline__meta"><?= htmlspecialchars($formatDateTime($event['created_at'] ?? '')) ?></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="dashboard-panel dashboard-panel--wide" data-draggable-card="panel-operator-monitor">
            <header class="dashboard-panel__header">
                <h3>Monitoraggio operatori</h3>
                <p class="dashboard-panel__meta">Disponibilità, soglie e suggerimenti di riordino</p>
            </header>
            <div class="table-wrapper table-wrapper--embedded">
                <table class="table table--compact">
                    <thead>
                        <tr>
                            <th>Operatore</th>
                            <th>Disponibili</th>
                            <th>Soglia</th>
                            <th>Media / giorno</th>
                            <th>Copertura stimata</th>
                            <th>Suggerimento</th>
                            <th>Ultimo movimento</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($providerInsights)): ?>
                            <tr><td colspan="7">Nessun operatore configurato.</td></tr>
                        <?php else: ?>
                            <?php foreach ($providerInsights as $insight): ?>
                                <?php $isLow = !empty($insight['below_threshold']); ?>
                                <tr class="<?= $isLow ? 'table-row--warning' : '' ?>">
                                    <td><?= htmlspecialchars((string) $insight['provider_name']) ?></td>
                                    <td><?= (int) $insight['current_stock'] ?></td>
                                    <td><?= (int) $insight['threshold'] ?></td>
                                    <td><?= number_format((float) $insight['average_daily_sales'], 2, ',', '.') ?></td>
                                    <td>
                                        <?php if ($insight['days_cover'] === null): ?>
                                            n/d
                                        <?php else: ?>
                                            <?= number_format((float) $insight['days_cover'], 1, ',', '.') ?> giorni
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int) $insight['suggested_reorder'] > 0): ?>
                                            Riordina almeno <?= (int) $insight['suggested_reorder'] ?> SIM
                                        <?php else: ?>
                                            Nessun riordino urgente
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($insight['last_movement'])): ?>
                                            <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $insight['last_movement']))) ?>
                                        <?php else: ?>
                                            n/d
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <?php if (!empty($stockAlerts)): ?>
        <section class="page__section">
            <h3>Alert stock attivi</h3>
            <div class="alert-list">
                <?php foreach ($stockAlerts as $alert): ?>
                    <article class="alert-card">
                        <header class="alert-card__header">
                            <h4><?= htmlspecialchars((string) $alert['provider_name']) ?></h4>
                            <span class="badge badge--warning">Sotto soglia</span>
                        </header>
                        <p class="alert-card__message"><?= htmlspecialchars((string) $alert['message']) ?></p>
                        <p class="alert-card__meta">Ultimo controllo: <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $alert['updated_at']))) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</section>
