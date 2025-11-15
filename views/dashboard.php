<?php
declare(strict_types=1);

/**
 * @var array<string, mixed> $metrics
 * @var array<int, array<string, mixed>> $stockAlerts
 * @var array<int, array<string, mixed>> $productAlerts
 * @var array<int, array<string, mixed>> $providerInsights
 * @var array<int, array<string, mixed>> $productInsights
 * @var array<int, array<string, mixed>> $stockRiskSummary
 * @var array<int, array<string, mixed>> $productRiskSummary
 * @var array<int, array<string, mixed>> $nextSteps
 * @var array<int, string> $lowStockProviders
 * @var array<int, string> $lowStockProducts
 * @var array<string, mixed> $operationalPulse
 * @var string $selectedPeriod
 * @var array<string, mixed>|null $currentUser
 */
$pageTitle = 'Dashboard';
$selectedPeriod = $selectedPeriod ?? ($metrics['period_label'] ?? 'day');
$periodLabels = [
    'day' => 'Giorno',
    'month' => 'Mese',
    'year' => 'Anno',
];

$analyticsCards = (array) ($metrics['analytics_overview']['cards'] ?? []);
$salesTrend = (array) ($metrics['sales_trend']['points'] ?? []);
$salesTrendMeta = (array) ($metrics['sales_trend'] ?? []);
$customerIntelligence = (array) ($metrics['customer_intelligence'] ?? []);
$customerSummary = (array) ($customerIntelligence['summary'] ?? []);
$forecast = (array) ($metrics['forecast'] ?? []);
$governance = (array) ($metrics['governance'] ?? []);
$operationalPulse = (array) ($operationalPulse ?? []);
$supportSummary = (array) ($operationalPulse['support_summary'] ?? $metrics['support_summary'] ?? []);
$billingPipeline = (array) ($operationalPulse['billing'] ?? $metrics['billing'] ?? []);
$expiringCampaigns = array_slice((array) ($operationalPulse['expiring_campaigns'] ?? []), 0, 4);
$operatorActivity = array_slice((array) ($operationalPulse['operator_activity'] ?? $metrics['operator_activity'] ?? []), 0, 4);
$recentEvents = array_slice((array) ($operationalPulse['recent_events'] ?? $metrics['recent_events'] ?? []), 0, 5);
$stockRiskSummary = array_slice((array) ($stockRiskSummary ?? []), 0, 5);
$productRiskSummary = array_slice((array) ($productRiskSummary ?? []), 0, 5);
$topCustomers = array_slice((array) ($customerIntelligence['top_customers'] ?? []), 0, 5);
$atRiskCustomers = array_slice((array) ($customerIntelligence['at_risk_customers'] ?? []), 0, 5);
$recentCustomers = array_slice((array) ($customerIntelligence['recent_customers'] ?? []), 0, 4);
$lowStockProviders = array_slice((array) ($operationalPulse['low_stock_providers'] ?? $lowStockProviders ?? []), 0, 5);
$lowStockProducts = array_slice((array) ($operationalPulse['low_stock_products'] ?? $lowStockProducts ?? []), 0, 5);
$providerInsights = (array) ($providerInsights ?? []);
$productInsights = (array) ($productInsights ?? []);
$stockAlerts = (array) ($stockAlerts ?? []);
$productAlerts = (array) ($productAlerts ?? []);
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

$formatValue = static function (float|int $value, string $format): string {
    return match ($format) {
        'currency' => '€ ' . number_format((float) $value, 2, ',', '.'),
        'percent' => number_format((float) $value, 1, ',', '.') . '%',
        default => number_format((float) $value, 0, ',', '.'),
    };
};

$formatCardValue = static function (array $card) use ($formatValue): string {
    $format = $card['format'] ?? 'number';
    return $formatValue((float) ($card['value'] ?? 0), $format);
};

$resolveDelta = static function (?array $delta, string $format) use ($formatValue): ?array {
    if (!is_array($delta)) {
        return null;
    }

    $direction = (string) ($delta['direction'] ?? 'flat');
    $class = match ($direction) {
        'up' => 'delta-badge--up',
        'down' => 'delta-badge--down',
        default => 'delta-badge--flat',
    };

    $percentValue = $delta['percent'] ?? null;
    if ($percentValue !== null) {
        $percentValue = (float) $percentValue;
        $prefix = $percentValue > 0 ? '+' : '';
        $label = $prefix . number_format($percentValue, 1, ',', '.') . '%';
    } else {
        $absolute = (float) ($delta['absolute'] ?? 0.0);
        if (abs($absolute) < 0.01) {
            $label = 'stabile';
            $class = 'delta-badge--flat';
        } else {
            $sign = $absolute > 0 ? '+' : '-';
            $magnitude = abs($absolute);
            if ($format === 'currency') {
                $label = $sign . number_format($magnitude, 2, ',', '.') . '€';
            } elseif ($format === 'percent') {
                $label = $sign . number_format($magnitude, 1, ',', '.') . '%';
            } else {
                $label = $sign . number_format($magnitude, 0, ',', '.');
            }
        }
    }

    $title = 'Periodo precedente: ' . $formatValue((float) ($delta['previous'] ?? 0.0), $format);

    return [
        'class' => $class,
        'label' => $label,
        'caption' => 'vs periodo precedente',
        'title' => $title,
    ];
};

$openSupport = (int) ($supportSummary['open_total'] ?? 0);
$supportBreaches = (int) ($supportSummary['breaches']['open_over_48h'] ?? 0);

$pendingPayments = [
    'count' => (int) ($billingPipeline['pending_payments']['count'] ?? 0),
    'amount' => (float) ($billingPipeline['pending_payments']['amount'] ?? 0.0),
];
$dueNextPayments = [
    'count' => (int) ($billingPipeline['due_next_7_days']['count'] ?? 0),
    'amount' => (float) ($billingPipeline['due_next_7_days']['amount'] ?? 0.0),
];
$overdueInvoices = [
    'count' => (int) ($billingPipeline['overdue_invoices']['count'] ?? 0),
    'amount' => (float) ($billingPipeline['overdue_invoices']['amount'] ?? 0.0),
];

$forecastConfidenceLabel = match ($forecast['confidence'] ?? 'media') {
    'alta' => 'Confidenza alta',
    'bassa' => 'Confidenza bassa',
    default => 'Confidenza media',
};
$forecastTrendLabel = match ($forecast['trend_direction'] ?? 'flat') {
    'up' => 'Trend in crescita',
    'down' => 'Trend in calo',
    default => 'Trend stabile',
};

$alertCount = count($stockAlerts);
$productAlertCount = count($productAlerts);
$expiringCount = count($expiringCampaigns);
$lowStockCount = count($lowStockProviders);
$lowStockProductCount = count($lowStockProducts);

$currentUser = $currentUser ?? null;
$welcomeTitle = null;
$welcomeSubtitle = '';
if (is_array($currentUser)) {
    $nameCandidates = [
        trim((string) ($currentUser['fullname'] ?? '')),
        trim(trim((string) ($currentUser['first_name'] ?? '')) . ' ' . trim((string) ($currentUser['last_name'] ?? ''))),
        trim((string) ($currentUser['username'] ?? '')),
    ];
    $displayName = null;
    foreach ($nameCandidates as $candidate) {
        if ($candidate !== '') {
            $displayName = $candidate;
            break;
        }
    }
    if ($displayName === null || $displayName === '') {
        $displayName = 'Operatore';
    }

    try {
        $currentHour = (int) (new \DateTimeImmutable('now'))->format('G');
    } catch (\Throwable) {
        $currentHour = 12;
    }

    if ($currentHour >= 5 && $currentHour < 12) {
        $greeting = 'Buongiorno';
    } elseif ($currentHour >= 12 && $currentHour < 18) {
        $greeting = 'Buon pomeriggio';
    } elseif ($currentHour >= 18 && $currentHour < 23) {
        $greeting = 'Buonasera';
    } else {
        $greeting = 'Bentornato';
    }

    $welcomeTitle = sprintf('%s, %s!', $greeting, $displayName);
    $welcomeSubtitle = 'Qui trovi le metriche più rilevanti aggiornate in tempo reale.';
}

?>
<section class="page">
    <header class="page__header">
        <div class="page__header-top">
            <h2>Dashboard</h2>
            <p>Comando centralizzato su vendite, clientela, campagne e compliance.</p>
        </div>
        <div class="dashboard-header-grid">
            <div class="dashboard-welcome" role="status">
                <div class="dashboard-welcome__icon" aria-hidden="true">
                    <?= htmlspecialchars($welcomeTitle !== null ? '👋' : '📊') ?>
                </div>
                <div class="dashboard-welcome__content">
                    <p class="dashboard-welcome__title">
                        <?= htmlspecialchars($welcomeTitle ?? 'Dashboard operativa') ?>
                    </p>
                    <?php if (($welcomeSubtitle !== '') || $welcomeTitle === null): ?>
                        <p class="dashboard-welcome__subtitle">
                            <?= htmlspecialchars($welcomeSubtitle !== '' ? $welcomeSubtitle : 'Seleziona il periodo per personalizzare le analisi.') ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="dashboard-period-card">
                <div class="dashboard-period-card__header">
                    <span class="dashboard-period-card__title">Periodo di analisi</span>
                    <span class="dashboard-period-card__subtitle">Scegli l'intervallo temporale per tutte le metriche</span>
                </div>
                <div class="dashboard-period-card__actions">
                    <?php foreach ($periodLabels as $key => $label): ?>
                        <a class="btn btn--ghost<?= $selectedPeriod === $key ? ' is-active' : '' ?>" href="index.php?page=dashboard&period=<?= $key ?>">
                            <?= htmlspecialchars($label) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php if ($nextSteps !== []): ?>
            <div class="page__actions">
                <h3 class="page__actions-title">Azioni consigliate</h3>
                <ul class="action-list action-list--header">
                    <?php foreach ($nextSteps as $step): ?>
                        <?php
                            $severity = htmlspecialchars((string) ($step['severity'] ?? 'info'));
                            $label = htmlspecialchars((string) ($step['label'] ?? ''));
                            $motivation = isset($step['motivation']) && $step['motivation'] !== null
                                ? htmlspecialchars((string) $step['motivation'])
                                : null;
                        ?>
                        <li class="action-list__item action-list__item--<?= $severity ?>">
                            <div class="action-list__content">
                                <span class="action-list__label"><?= $label ?></span>
                                <?php if ($motivation !== null && $motivation !== ''): ?>
                                    <span class="action-list__motivation"><?= $motivation ?></span>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </header>

    <div class="cards" data-draggable-container="dashboard-metrics">
        <?php foreach ($analyticsCards as $card): ?>
            <?php
                $format = (string) ($card['format'] ?? 'number');
                $delta = $resolveDelta($card['delta'] ?? null, $format);
                $cardId = (string) ($card['id'] ?? uniqid('metric', false));
                $cardSlug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $cardId), '-'));
                if ($cardSlug === '') {
                    $cardSlug = 'metric';
                }
            ?>
            <article class="card" data-draggable-card="metric-<?= htmlspecialchars($cardSlug) ?>">
                <h3><?= htmlspecialchars((string) ($card['label'] ?? '')) ?></h3>
                <p class="card__value"><?= $formatCardValue($card) ?></p>
                <?php if ($delta !== null): ?>
                    <div class="card__delta">
                        <span class="delta-badge <?= htmlspecialchars($delta['class']) ?>" title="<?= htmlspecialchars($delta['title']) ?>">
                            <?= htmlspecialchars($delta['label']) ?>
                        </span>
                        <span><?= htmlspecialchars($delta['caption']) ?></span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($card['meta'])): ?>
                    <p class="card__meta"><?= htmlspecialchars((string) $card['meta']) ?></p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="dashboard-grid" data-draggable-container="dashboard-panels">
        <section class="dashboard-panel dashboard-panel--wide" data-draggable-card="panel-operational">
            <header class="dashboard-panel__header">
                <h3>Pulse operativo</h3>
                <p class="dashboard-panel__meta">Stato corrente di ticket, campagne e rischio pagamenti.</p>
            </header>
            <div class="status-chips">
                <span class="status-chip status-chip--warning">Alert stock: <?= $alertCount ?></span>
                <span class="status-chip status-chip--warning">Alert hardware: <?= $productAlertCount ?></span>
                <span class="status-chip status-chip--info">Ticket aperti: <?= $openSupport ?></span>
                <span class="status-chip status-chip--danger">Pagamenti scoperti: <?= $overdueInvoices['count'] ?></span>
                <span class="status-chip status-chip--neutral">Campagne in scadenza: <?= $expiringCount ?></span>
            </div>
            <div class="insight-grid">
                <div class="insight-highlight">
                    <span>Richieste fuori SLA</span>
                    <strong><?= $supportBreaches ?></strong>
                    <small>Monitorare i casi sopra le 48 ore</small>
                </div>
                <div class="insight-highlight">
                    <span>Pagamenti in attesa</span>
                    <strong><?= $pendingPayments['count'] ?></strong>
                    <small><?= $formatValue($pendingPayments['amount'], 'currency') ?></small>
                </div>
                <div class="insight-highlight">
                    <span>Rate in arrivo (7gg)</span>
                    <strong><?= $dueNextPayments['count'] ?></strong>
                    <small><?= $formatValue($dueNextPayments['amount'], 'currency') ?></small>
                </div>
                <div class="insight-highlight">
                    <span>Operatori critici</span>
                    <strong><?= $lowStockCount ?></strong>
                    <small><?= htmlspecialchars(implode(', ', $lowStockProviders)) ?: 'Nessuno' ?></small>
                </div>
                <div class="insight-highlight">
                    <span>Prodotti critici</span>
                    <strong><?= $lowStockProductCount ?></strong>
                    <small><?= htmlspecialchars(implode(', ', $lowStockProducts)) ?: 'Nessuno' ?></small>
                </div>
            </div>
            <div class="insight-split">
                <div>
                    <h4 class="insight-title">Campagne in uscita</h4>
                    <?php if ($expiringCampaigns === []): ?>
                        <p class="muted">Nessuna campagna in scadenza entro 7 giorni.</p>
                    <?php else: ?>
                        <ul class="insight-list">
                            <?php foreach ($expiringCampaigns as $campaign): ?>
                                <li class="insight-list__item">
                                    <span class="insight-list__label"><?= htmlspecialchars((string) ($campaign['name'] ?? '')) ?></span>
                                    <span class="insight-list__value"><?= (int) ($campaign['days'] ?? 0) ?> gg</span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <div>
                    <h4 class="insight-title">Ultime attività operatori</h4>
                    <?php if ($operatorActivity === []): ?>
                        <p class="muted">Nessuna attività registrata.</p>
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
                                    <span class="activity-entry__meta"><?= htmlspecialchars($formatDateTime($entry['created_at'] ?? '')) ?> · <?= htmlspecialchars((string) ($entry['payment_method'] ?? '')) ?></span>
                                    <span class="activity-entry__value">Incasso <?= $formatValue((float) ($entry['total'] ?? 0.0), 'currency') ?></span>
                                    <span class="activity-entry__status">Stato: <?= htmlspecialchars($statusLabel ?: '-') ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </section>

    <section class="dashboard-panel" data-draggable-card="panel-customers">
            <header class="dashboard-panel__header">
                <h3>Clienti al centro</h3>
                <p class="dashboard-panel__meta">Top clienti, account a rischio e nuovi ingressi.</p>
            </header>
            <div class="table-wrapper table-wrapper--embedded">
                <table class="table table--compact">
                    <thead>
                        <tr>
                            <th>Cliente</th>
                            <th>Ordini</th>
                            <th>Valore</th>
                            <th>Ultimo acquisto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($topCustomers === []): ?>
                            <tr><td colspan="4">Nessun dato disponibile.</td></tr>
                        <?php else: ?>
                            <?php foreach ($topCustomers as $customer): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) ($customer['customer_name'] ?? 'Cliente')) ?></td>
                                    <td><?= (int) ($customer['orders'] ?? 0) ?></td>
                                    <td><?= $formatValue((float) ($customer['revenue'] ?? 0.0), 'currency') ?></td>
                                    <td><?= htmlspecialchars($formatDateTime($customer['last_purchase'] ?? '', 'd/m/Y')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="insight-split">
                <div>
                    <h4 class="insight-title">Clienti a rischio churn</h4>
                    <?php if ($atRiskCustomers === []): ?>
                        <p class="muted">Nessun cliente con inattività prolungata.</p>
                    <?php else: ?>
                        <ul class="insight-list">
                            <?php foreach ($atRiskCustomers as $customer): ?>
                                <li class="insight-list__item">
                                    <span class="insight-list__label"><?= htmlspecialchars((string) ($customer['customer_name'] ?? '')) ?></span>
                                    <span class="insight-list__value">Ultimo acquisto <?= htmlspecialchars($formatDateTime($customer['last_purchase'] ?? '', 'd/m/Y')) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <div>
                    <h4 class="insight-title">Nuovi clienti (30 giorni)</h4>
                    <?php if ($recentCustomers === []): ?>
                        <p class="muted">Ancora nessun nuovo cliente.</p>
                    <?php else: ?>
                        <ul class="insight-list">
                            <?php foreach ($recentCustomers as $customer): ?>
                                <li class="insight-list__item">
                                    <span class="insight-list__label"><?= htmlspecialchars((string) ($customer['fullname'] ?? '')) ?></span>
                                    <span class="insight-list__value"><?= htmlspecialchars($formatDateTime($customer['created_at'] ?? '', 'd/m/Y')) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </section>

    <section class="dashboard-panel dashboard-panel--wide" data-draggable-card="panel-forecast">
            <header class="dashboard-panel__header">
                <h3>Trend e forecast vendite</h3>
                <p class="dashboard-panel__meta">Previsione <?= (int) ($forecast['horizon_days'] ?? 7) ?> giorni · <?= htmlspecialchars($forecastConfidenceLabel) ?> · <?= htmlspecialchars($forecastTrendLabel) ?></p>
            </header>
            <div class="insight-grid">
                <div class="insight-highlight">
                    <span>Vendite previste</span>
                    <strong><?= (int) ($forecast['expected_sales'] ?? 0) ?></strong>
                    <small>Media giornaliera <?= number_format((float) ($forecast['avg_daily_sales'] ?? 0.0), 1, ',', '.') ?></small>
                </div>
                <div class="insight-highlight">
                    <span>Fatturato previsto</span>
                    <strong><?= $formatValue((float) ($forecast['expected_revenue'] ?? 0.0), 'currency') ?></strong>
                    <small>Media giornaliera <?= $formatValue((float) ($forecast['avg_daily_revenue'] ?? 0.0), 'currency') ?></small>
                </div>
                <div class="insight-highlight">
                    <span>Vendite periodo precedente</span>
                    <strong><?= (int) ($salesTrendMeta['total_count'] ?? 0) ?></strong>
                    <small>Intervallo monitorato <?= (int) ($forecast['lookback_days'] ?? 28) ?> giorni</small>
                </div>
                <div class="insight-highlight">
                    <span>Ticket medio corrente</span>
                    <strong><?= $formatValue((float) ($metrics['average_ticket'] ?? 0.0), 'currency') ?></strong>
                    <small>Periodo: <?= htmlspecialchars($periodLabels[$selectedPeriod] ?? 'Giorno') ?></small>
                </div>
            </div>
            <?php if ($salesTrend === []): ?>
                <p class="muted">Nessun dato disponibile per la serie storica selezionata.</p>
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
                        <article class="trend-card" title="Vendite <?= $count ?> · Incasso <?= $formatValue($revenue, 'currency') ?>">
                            <span class="trend-card__label"><?= htmlspecialchars($label) ?></span>
                            <span class="trend-card__value"><?= $count ?> vendite</span>
                            <div class="trend-bar">
                                <span class="trend-bar__fill" style="width: <?= $countPct ?>%;"></span>
                            </div>
                            <div class="trend-bar trend-bar--secondary">
                                <span class="trend-bar__fill" style="width: <?= $revenuePct ?>%;"></span>
                            </div>
                            <span class="trend-card__meta">Incasso <?= $formatValue($revenue, 'currency') ?></span>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

    <section class="dashboard-panel" data-draggable-card="panel-governance">
            <header class="dashboard-panel__header">
                <h3>Governance & compliance</h3>
                <p class="dashboard-panel__meta">Policy privacy, adozione portale e audit trail.</p>
            </header>
            <ul class="insight-list">
                <li class="insight-list__item">
                    <span class="insight-list__label">Policy attive</span>
                    <span class="insight-list__value"><?= (int) ($governance['active_policies'] ?? 0) ?><?php if (!empty($governance['latest_version'])): ?> · v<?= htmlspecialchars((string) $governance['latest_version']) ?><?php endif; ?></span>
                </li>
                <li class="insight-list__item">
                    <span class="insight-list__label">Accettazioni portal</span>
                    <?php
                        $rate = $governance['acceptance_rate'] ?? null;
                        $rateLabel = $rate !== null ? number_format((float) $rate, 1, ',', '.') . '%' : 'n/d';
                    ?>
                    <span class="insight-list__value"><?= $rateLabel ?> · Mancano <?= (int) ($governance['pending_acceptances'] ?? 0) ?></span>
                </li>
                <li class="insight-list__item">
                    <span class="insight-list__label">Ultimo aggiornamento policy</span>
                    <span class="insight-list__value"><?= htmlspecialchars($formatDateTime($governance['last_policy_update'] ?? '', 'd/m/Y')) ?></span>
                </li>
                <li class="insight-list__item">
                    <span class="insight-list__label">Audit ultimi 30 giorni</span>
                    <span class="insight-list__value"><?= (int) ($governance['audit_events_last_30'] ?? 0) ?></span>
                </li>
            </ul>
            <h4 class="insight-title">Audit log recente</h4>
            <?php if ($recentEvents === []): ?>
                <p class="muted">Nessuna attività da mostrare.</p>
            <?php else: ?>
                <ul class="timeline">
                    <?php foreach ($recentEvents as $event): ?>
                        <?php
                            $eventLabel = (string) ($event['action_label'] ?? $event['action'] ?? 'Evento');
                            if ($eventLabel === '') {
                                $eventLabel = 'Evento';
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

    <section class="dashboard-panel dashboard-panel--wide" data-draggable-card="panel-stock">
        <header class="dashboard-panel__header">
            <h3>Stock & fornitori</h3>
            <p class="dashboard-panel__meta">Copertura, soglie e suggerimenti di riordino.</p>
        </header>
        <div class="insight-split">
            <div>
                <h4 class="insight-title">Operatori critici</h4>
                <?php if ($stockRiskSummary === []): ?>
                    <p class="muted">Nessun rischio di magazzino rilevato.</p>
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
                                    <span>Vendite/giorno <?= number_format((float) ($risk['average_daily_sales'] ?? 0.0), 2, ',', '.') ?></span>
                                    <span>Copertura <?= $coverage !== null ? number_format((float) $coverage, 1, ',', '.') . ' gg' : 'n/d' ?></span>
                                    <?php if (!empty($risk['suggested_reorder'])): ?>
                                        <span>Riordino consigliato <?= (int) $risk['suggested_reorder'] ?> SIM</span>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <div>
                <h4 class="insight-title">Hardware critico</h4>
                <?php if ($productRiskSummary === []): ?>
                    <p class="muted">Nessun prodotto sotto soglia.</p>
                <?php else: ?>
                    <ul class="stock-list">
                        <?php foreach ($productRiskSummary as $risk): ?>
                            <?php
                                $riskLevel = htmlspecialchars((string) ($risk['risk_level'] ?? 'ok'));
                                $coverage = $risk['days_cover'] ?? null;
                            ?>
                            <li class="stock-item stock-item--<?= $riskLevel ?>">
                                <div class="stock-item__header">
                                    <strong><?= htmlspecialchars((string) ($risk['product_name'] ?? '')) ?></strong>
                                    <span><?= (int) ($risk['current_stock'] ?? 0) ?> disponibili · riservati <?= (int) ($risk['stock_reserved'] ?? 0) ?> · soglia <?= (int) ($risk['threshold'] ?? 0) ?></span>
                                </div>
                                <div class="stock-item__meta">
                                    <span>Vendite/giorno <?= number_format((float) ($risk['average_daily_sales'] ?? 0.0), 2, ',', '.') ?></span>
                                    <span>Copertura <?= $coverage !== null ? number_format((float) $coverage, 1, ',', '.') . ' gg' : 'n/d' ?></span>
                                    <?php if (!empty($risk['suggested_reorder'])): ?>
                                        <span>Riordino consigliato <?= (int) $risk['suggested_reorder'] ?> pezzi</span>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
        <div class="table-wrapper table-wrapper--embedded" style="margin-top:16px;">
            <table class="table table--compact">
                <thead>
                    <tr>
                        <th>Operatore</th>
                        <th>Disponibili</th>
                        <th>Soglia</th>
                        <th>Media/die</th>
                        <th>Copertura</th>
                        <th>Suggerimento</th>
                        <th>Ultimo movimento</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($providerInsights === []): ?>
                        <tr><td colspan="7">Nessun operatore configurato.</td></tr>
                    <?php else: ?>
                        <?php foreach ($providerInsights as $insight): ?>
                            <?php $isLow = !empty($insight['below_threshold']); ?>
                            <tr class="<?= $isLow ? 'table-row--warning' : '' ?>">
                                <td><?= htmlspecialchars((string) ($insight['provider_name'] ?? '')) ?></td>
                                <td><?= (int) ($insight['current_stock'] ?? 0) ?></td>
                                <td><?= (int) ($insight['threshold'] ?? 0) ?></td>
                                <td><?= number_format((float) ($insight['average_daily_sales'] ?? 0.0), 2, ',', '.') ?></td>
                                <td>
                                    <?php if (!isset($insight['days_cover']) || $insight['days_cover'] === null): ?>
                                        n/d
                                    <?php else: ?>
                                        <?= number_format((float) $insight['days_cover'], 1, ',', '.') ?> gg
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((int) ($insight['suggested_reorder'] ?? 0) > 0): ?>
                                        Riordina <?= (int) $insight['suggested_reorder'] ?> SIM
                                    <?php else: ?>
                                        Nessuna azione urgente
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($insight['last_movement'])): ?>
                                        <?= htmlspecialchars($formatDateTime((string) $insight['last_movement'])) ?>
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
        <div class="table-wrapper table-wrapper--embedded" style="margin-top:16px;">
            <table class="table table--compact">
                <thead>
                    <tr>
                        <th>Prodotto</th>
                        <th>Disponibili</th>
                        <th>Riservati</th>
                        <th>Soglia</th>
                        <th>Media/die</th>
                        <th>Copertura</th>
                        <th>Suggerimento</th>
                        <th>Ultimo movimento</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($productInsights === []): ?>
                        <tr><td colspan="8">Nessun prodotto attivo.</td></tr>
                    <?php else: ?>
                        <?php foreach ($productInsights as $insight): ?>
                            <?php $isLowProduct = !empty($insight['below_threshold']); ?>
                            <tr class="<?= $isLowProduct ? 'table-row--warning' : '' ?>">
                                <td><?= htmlspecialchars((string) ($insight['product_name'] ?? '')) ?></td>
                                <td><?= (int) ($insight['current_stock'] ?? 0) ?></td>
                                <td><?= (int) ($insight['stock_reserved'] ?? 0) ?></td>
                                <td><?= (int) ($insight['threshold'] ?? 0) ?></td>
                                <td><?= number_format((float) ($insight['average_daily_sales'] ?? 0.0), 2, ',', '.') ?></td>
                                <td>
                                    <?php if (!isset($insight['days_cover']) || $insight['days_cover'] === null): ?>
                                        n/d
                                    <?php else: ?>
                                        <?= number_format((float) $insight['days_cover'], 1, ',', '.') ?> gg
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((int) ($insight['suggested_reorder'] ?? 0) > 0): ?>
                                        Riordina <?= (int) $insight['suggested_reorder'] ?> pezzi
                                    <?php else: ?>
                                        Nessuna azione urgente
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($insight['last_movement'])): ?>
                                        <?= htmlspecialchars($formatDateTime((string) $insight['last_movement'])) ?>
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

<?php if ($stockAlerts !== [] || $productAlerts !== []): ?>
    <section class="page__section">
        <h3>Alert operativi</h3>
        <?php if ($stockAlerts !== []): ?>
            <h4 class="insight-title">Operatori</h4>
            <div class="alert-list">
                <?php foreach ($stockAlerts as $alert) { ?>
                    <article class="alert-card">
                        <header class="alert-card__header">
                            <h4><?= htmlspecialchars((string) ($alert['provider_name'] ?? '')) ?></h4>
                            <span class="badge badge--warning">Sotto soglia</span>
                        </header>
                        <p class="alert-card__message"><?= htmlspecialchars((string) ($alert['message'] ?? '')) ?></p>
                        <p class="alert-card__meta">Ultimo controllo <?= htmlspecialchars($formatDateTime($alert['updated_at'] ?? '')) ?></p>
                    </article>
                <?php } ?>
            </div>
        <?php endif; ?>
        <?php if ($productAlerts !== []): ?>
            <h4 class="insight-title">Hardware</h4>
            <div class="alert-list">
                <?php foreach ($productAlerts as $alert) { ?>
                    <article class="alert-card">
                        <header class="alert-card__header">
                            <h4><?= htmlspecialchars((string) ($alert['product_name'] ?? '')) ?></h4>
                            <span class="badge badge--warning">Sotto soglia</span>
                        </header>
                        <p class="alert-card__message"><?= htmlspecialchars((string) ($alert['message'] ?? '')) ?></p>
                        <p class="alert-card__meta">Ultimo controllo <?= htmlspecialchars($formatDateTime($alert['updated_at'] ?? '')) ?></p>
                    </article>
                <?php } ?>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
</section>
