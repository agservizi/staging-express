<?php
declare(strict_types=1);

/**
 * @var array<string, mixed> $report
 * @var array<string, string> $filters
 * @var array<string, mixed> $filterOptions
 * @var string $view
 */
$pageTitle = $pageTitle ?? 'Report vendite';
$report = $report ?? [];
$view = $view ?? ($report['granularity'] ?? 'daily');
$filters = $filters ?? ($report['filters'] ?? []);
$filterOptions = $filterOptions ?? ($report['filter_options'] ?? []);
$filters = array_merge(['date' => '', 'month' => '', 'year' => '', 'payment' => '', 'operator_id' => ''], $filters);
$paymentOptions = array_values(array_filter(
    (array) ($filterOptions['payments'] ?? []),
    static fn ($value): bool => is_string($value) && $value !== ''
));
$operatorOptionsRaw = array_filter(
    (array) ($filterOptions['operators'] ?? []),
    static fn ($row): bool => is_array($row)
);
$operatorOptions = array_values(array_filter(array_map(
    static function (array $operator): array {
        return [
            'id' => (int) ($operator['id'] ?? 0),
            'name' => (string) ($operator['name'] ?? ''),
        ];
    },
    $operatorOptionsRaw
), static fn (array $option): bool => $option['id'] > 0));
$selectedPayment = (string) $filters['payment'];
$selectedOperator = (string) $filters['operator_id'];
$totals = array_merge([
    'sales_count' => 0,
    'gross_revenue' => 0.0,
    'net_revenue' => 0.0,
    'discount_total' => 0.0,
    'refund_total' => 0.0,
    'credit_total' => 0.0,
    'average_ticket' => 0.0,
    'average_ticket_net' => 0.0,
], (array) ($report['totals'] ?? []));
$payments = (array) ($report['payments'] ?? []);
$operators = (array) ($report['operators'] ?? []);
$trend = (array) ($report['trend'] ?? []);
$trendPoints = (array) ($trend['points'] ?? []);
$period = (array) ($report['period'] ?? []);
$referenceForFile = $period['reference'] ?? '';
if ($view === 'daily' && $filters['date'] !== '') {
    $referenceForFile = (string) $filters['date'];
} elseif ($view === 'monthly' && $filters['month'] !== '') {
    $referenceForFile = (string) $filters['month'];
} elseif ($view === 'yearly' && $filters['year'] !== '') {
    $referenceForFile = (string) $filters['year'];
}
if ($referenceForFile === '') {
    $referenceForFile = date('Y-m-d');
}
$referenceSlug = preg_replace('/[^0-9\-]/', '', str_replace(['/', ' '], '-', (string) $referenceForFile));
if ($referenceSlug === '') {
    $referenceSlug = date('Ymd');
}
$exportFilename = sprintf('report-%s-%s.pdf', $view, $referenceSlug);
$viewLabels = [
    'daily' => 'Giornaliero',
    'monthly' => 'Mensile',
    'yearly' => 'Annuale',
];
$viewDescriptions = [
    'daily' => 'Analisi delle vendite giornaliere con focus sulle ultime 24 ore.',
    'monthly' => 'Statistiche aggregate per il mese selezionato.',
    'yearly' => 'Panoramica su base annuale per confrontare l\'andamento.',
];
$formatCurrency = static function (float $value): string {
    return number_format($value, 2, ',', '.');
};
$rangeStart = null;
$rangeEnd = null;
try {
    if (!empty($period['start'])) {
        $rangeStart = new DateTimeImmutable((string) $period['start']);
    }
    if (!empty($period['end'])) {
        $rangeEnd = (new DateTimeImmutable((string) $period['end']))->modify('-1 day');
    }
} catch (\Throwable) {
    $rangeStart = null;
    $rangeEnd = null;
}
$rangeLabel = $period['label'] ?? '';
if ($rangeStart !== null && $rangeEnd !== null) {
    $rangeLabel = sprintf(
        '%s · %s → %s',
        $period['label'] ?? $viewLabels[$view] ?? 'Periodo',
        $rangeStart->format('d/m/Y'),
        $rangeEnd->format('d/m/Y')
    );
}
$maxTrendCount = 0;
$maxTrendRevenue = 0.0;
foreach ($trendPoints as $point) {
    $count = (int) ($point['sale_count'] ?? 0);
    $net = max((float) ($point['net_revenue'] ?? 0.0), 0.0);
    if ($count > $maxTrendCount) {
        $maxTrendCount = $count;
    }
    if ($net > $maxTrendRevenue) {
        $maxTrendRevenue = $net;
    }
}
$trendSummary = sprintf(
    'Totale: %d vendite · Incasso netto € %s',
    (int) ($trend['total_count'] ?? 0),
    $formatCurrency((float) ($trend['total_net'] ?? 0.0))
);
?>
<section class="page">
    <header class="page__header">
        <h2>Report vendite</h2>
        <p><?= htmlspecialchars($viewDescriptions[$view] ?? 'Analisi delle vendite registrate.') ?></p>
    </header>

    <div id="report-export-target">
        <form method="get" class="filters-bar" id="reports-filter-form" data-html2canvas-ignore="true">
            <input type="hidden" name="page" value="reports">
            <div class="filters-bar__row" style="flex-wrap: wrap; gap: 1rem;">
                <div class="form__group">
                    <label for="filter-view">Intervallo</label>
                    <select name="view" id="filter-view" data-report-filter="view">
                        <?php foreach ($viewLabels as $key => $label): ?>
                            <option value="<?= htmlspecialchars($key) ?>" <?= $view === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form__group" data-report-filter="date"<?= $view === 'daily' ? '' : ' hidden' ?>>
                    <label for="filter-date">Giorno</label>
                    <input type="date" name="date" id="filter-date" value="<?= htmlspecialchars((string) $filters['date']) ?>">
                </div>
                <div class="form__group" data-report-filter="month"<?= $view === 'monthly' ? '' : ' hidden' ?>>
                    <label for="filter-month">Mese</label>
                    <input type="month" name="month" id="filter-month" value="<?= htmlspecialchars((string) $filters['month']) ?>">
                </div>
                <div class="form__group" data-report-filter="year"<?= $view === 'yearly' ? '' : ' hidden' ?>>
                    <label for="filter-year">Anno</label>
                    <input type="number" name="year" id="filter-year" min="2000" max="<?= (int) date('Y') + 1 ?>" value="<?= htmlspecialchars((string) $filters['year']) ?>">
                </div>
            </div>
            <div class="filters-bar__row" style="flex-wrap: wrap; gap: 1rem;">
                <div class="form__group">
                    <label for="filter-payment">Metodo di pagamento</label>
                    <select name="payment" id="filter-payment">
                        <option value="">Tutti</option>
                        <?php foreach ($paymentOptions as $method): ?>
                            <option value="<?= htmlspecialchars($method) ?>" <?= $selectedPayment === $method ? 'selected' : '' ?>><?= htmlspecialchars($method) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form__group">
                    <label for="filter-operator">Operatore</label>
                    <select name="operator_id" id="filter-operator">
                        <option value="">Tutti</option>
                        <?php foreach ($operatorOptions as $option): ?>
                            <?php $optionName = $option['name'] !== '' ? $option['name'] : 'Operatore #' . (int) $option['id']; ?>
                            <option value="<?= (int) $option['id'] ?>" <?= $selectedOperator === (string) $option['id'] ? 'selected' : '' ?>><?= htmlspecialchars($optionName) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="filters-bar__actions">
                <button type="submit" class="btn btn--primary">Applica filtri</button>
                <a class="btn btn--secondary" href="index.php?page=reports&amp;view=<?= htmlspecialchars($view) ?>">Reset</a>
                <button type="button" class="btn btn--secondary" id="btn-report-export" data-filename="<?= htmlspecialchars($exportFilename) ?>">Esporta PDF</button>
            </div>
        </form>

        <p class="muted" style="margin-bottom: 1.5rem;">Periodo analizzato: <?= htmlspecialchars($rangeLabel) ?></p>

        <div class="cards" data-draggable-container="reports-metrics">
            <article class="card" data-draggable-card="metric-net">
                <h3>Incasso netto</h3>
                <p class="card__value">€ <?= $formatCurrency((float) $totals['net_revenue']) ?></p>
                <p class="card__meta">Ticket medio netto € <?= $formatCurrency((float) $totals['average_ticket_net']) ?></p>
            </article>
            <article class="card" data-draggable-card="metric-gross">
                <h3>Incasso lordo</h3>
                <p class="card__value">€ <?= $formatCurrency((float) $totals['gross_revenue']) ?></p>
                <p class="card__meta">Ticket medio € <?= $formatCurrency((float) $totals['average_ticket']) ?></p>
            </article>
            <article class="card" data-draggable-card="metric-count">
                <h3>Vendite registrate</h3>
                <p class="card__value"><?= (int) $totals['sales_count'] ?></p>
                <p class="card__meta">Sconti erogati € <?= $formatCurrency((float) $totals['discount_total']) ?></p>
            </article>
            <article class="card" data-draggable-card="metric-refund">
                <h3>Resi e crediti</h3>
                <p class="card__value">€ <?= $formatCurrency((float) $totals['refund_total'] + (float) $totals['credit_total']) ?></p>
                <p class="card__meta">Resi € <?= $formatCurrency((float) $totals['refund_total']) ?> · Crediti € <?= $formatCurrency((float) $totals['credit_total']) ?></p>
            </article>
    </div>

    <div class="dashboard-grid" data-draggable-container="reports-panels">
        <section class="dashboard-panel dashboard-panel--wide" data-draggable-card="panel-trend">
            <header class="dashboard-panel__header">
                <h3>Tendenza <?= htmlspecialchars($viewLabels[$view] ?? 'Periodo') ?></h3>
                <p class="dashboard-panel__meta"><?= htmlspecialchars($trendSummary) ?></p>
            </header>
            <?php if ($trendPoints === []): ?>
                <p class="muted">Non ci sono vendite nel periodo selezionato.</p>
            <?php else: ?>
                <div class="trend-grid">
                    <?php foreach ($trendPoints as $point): ?>
                        <?php
                            $count = (int) ($point['sale_count'] ?? 0);
                            $net = (float) ($point['net_revenue'] ?? 0.0);
                            $countPct = $maxTrendCount > 0 ? (int) round(($count / $maxTrendCount) * 100) : 0;
                            $revenuePct = $maxTrendRevenue > 0 ? (int) round((max($net, 0.0) / $maxTrendRevenue) * 100) : 0;
                            $label = (string) ($point['label'] ?? '');
                        ?>
                        <article class="trend-card" title="Vendite <?= $count ?> · Netto € <?= $formatCurrency($net) ?>">
                            <span class="trend-card__label"><?= htmlspecialchars($label) ?></span>
                            <span class="trend-card__value"><?= $count ?> vendite</span>
                            <div class="trend-bar">
                                <span class="trend-bar__fill" style="width: <?= $countPct ?>%;"></span>
                            </div>
                            <div class="trend-bar trend-bar--secondary">
                                <span class="trend-bar__fill" style="width: <?= $revenuePct ?>%;"></span>
                            </div>
                            <span class="trend-card__meta">Netto € <?= $formatCurrency($net) ?></span>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="dashboard-panel" data-draggable-card="panel-payments">
            <header class="dashboard-panel__header">
                <h3>Metodi di pagamento</h3>
                <p class="dashboard-panel__meta">Distribuzione incassi</p>
            </header>
            <?php if ($payments === []): ?>
                <p class="muted">Nessuna vendita disponibile per il periodo filtrato.</p>
            <?php else: ?>
                <div class="table-wrapper table-wrapper--embedded">
                    <table class="table table--compact">
                        <thead>
                            <tr>
                                <th>Metodo</th>
                                <th>Vendite</th>
                                <th>Incasso lordo</th>
                                <th>Incasso netto</th>
                                <th>Sconti</th>
                                <th>Resi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) $row['method']) ?></td>
                                    <td><?= (int) $row['sales_count'] ?></td>
                                    <td>€ <?= $formatCurrency((float) $row['gross_revenue']) ?></td>
                                    <td>€ <?= $formatCurrency((float) $row['net_revenue']) ?></td>
                                    <td>€ <?= $formatCurrency((float) $row['discount_total']) ?></td>
                                    <td>€ <?= $formatCurrency((float) $row['refund_total'] + (float) $row['credit_total']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="dashboard-panel" data-draggable-card="panel-operators">
            <header class="dashboard-panel__header">
                <h3>Top operatori</h3>
                <p class="dashboard-panel__meta">Netto periodo selezionato</p>
            </header>
            <?php if ($operators === []): ?>
                <p class="muted">Nessuna vendita registrata dagli operatori nel periodo.</p>
            <?php else: ?>
                <ul class="activity-list">
                    <?php foreach ($operators as $operator): ?>
                        <li class="activity-entry">
                            <span class="activity-entry__title"><?= htmlspecialchars((string) $operator['operator_name']) ?></span>
                            <span class="activity-entry__meta">Vendite <?= (int) $operator['sales_count'] ?> · Sconti € <?= $formatCurrency((float) $operator['discount_total']) ?></span>
                            <span class="activity-entry__value">Netto € <?= $formatCurrency((float) $operator['net_revenue']) ?> · Lordo € <?= $formatCurrency((float) $operator['gross_revenue']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>
</section>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
(function () {
    const form = document.getElementById('reports-filter-form');
    if (!form) {
        return;
    }

    const viewSelect = form.querySelector('[data-report-filter="view"]');
    const controls = {
        daily: form.querySelector('[data-report-filter="date"]'),
        monthly: form.querySelector('[data-report-filter="month"]'),
        yearly: form.querySelector('[data-report-filter="year"]'),
    };

    const toggleControls = () => {
    const mode = ((viewSelect && viewSelect.value) ? viewSelect.value : 'daily').toLowerCase();
        Object.entries(controls).forEach(([key, element]) => {
            if (!element) {
                return;
            }
            if (key === mode) {
                element.removeAttribute('hidden');
            } else {
                element.setAttribute('hidden', 'hidden');
            }
        });
    };

    toggleControls();
    if (viewSelect) {
        viewSelect.addEventListener('change', toggleControls);
    }

    const exportBtn = document.getElementById('btn-report-export');
    const exportTarget = document.getElementById('report-export-target');
    if (!exportBtn || !exportTarget) {
        return;
    }

    exportBtn.addEventListener('click', async () => {
        if (typeof window.html2pdf !== 'function') {
            alert('Impossibile generare il PDF: libreria non caricata.');
            return;
        }

        const originalLabel = exportBtn.textContent;
        exportBtn.disabled = true;
        exportBtn.textContent = 'Generazione PDF...';

        try {
            await window.html2pdf()
                .set({
                    filename: exportBtn.dataset.filename || 'report.pdf',
                    image: { type: 'jpeg', quality: 0.98 },
                    html2canvas: { scale: 2, useCORS: true },
                    jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
                })
                .from(exportTarget)
                .save();
        } catch (error) {
            console.error('Report PDF export error', error);
            alert('Si è verificato un errore durante la generazione del PDF.');
        } finally {
            exportBtn.disabled = false;
            if (originalLabel) {
                exportBtn.textContent = originalLabel;
            }
        }
    });
})();
</script>
