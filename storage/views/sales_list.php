<?php
declare(strict_types=1);

/**
 * @var array<int, array<string, mixed>> $sales
 * @var array{page:int, per_page:int, total:int, pages:int} $pagination
 * @var array{q?:string|null,status?:string|null,from?:string|null,to?:string|null,payment?:string|null} $filters
 */

$pageTitle = 'Storico vendite';
$filters = $filters ?? [];
$pagination = $pagination ?? ['page' => 1, 'per_page' => 7, 'total' => 0, 'pages' => 1];
?>
<section
    class="page"
    data-live-refresh="sales_list"
    data-refresh-url="index.php?page=sales_list&amp;action=refresh"
    data-refresh-interval="25000"
    data-refresh-page="<?= (int) $pagination['page'] ?>"
    data-refresh-per-page="<?= (int) $pagination['per_page'] ?>"
>
    <header class="page__header">
        <h2>Storico vendite</h2>
        <p>Consulta le vendite acquisite, filtra per periodo, stato o metodo di pagamento e ristampa gli scontrini.</p>
    </header>

    <div data-live-slot="feedback"></div>

    <p class="muted" data-live-slot="status">Ultimo aggiornamento: <span data-live-slot="timestamp">--:--</span></p>

    <form method="get" class="filters-bar" data-live-form>
        <input type="hidden" name="page" value="sales_list">
        <input type="hidden" name="page_no" value="<?= (int) $pagination['page'] ?>">
        <input type="hidden" name="per_page" value="<?= (int) $pagination['per_page'] ?>">
        <div class="filters-bar__row">
            <div class="form__group">
                <label for="q">Ricerca libera</label>
                <input type="text" name="q" id="q" placeholder="Cliente, ID, operatore" value="<?= htmlspecialchars((string) ($filters['q'] ?? '')) ?>">
            </div>
            <div class="form__group">
                <label for="status">Stato</label>
                <?php $status = $filters['status'] ?? ''; ?>
                <select name="status" id="status">
                    <option value="">Tutti</option>
                    <option value="Completed" <?= $status === 'Completed' ? 'selected' : '' ?>>Completati</option>
                    <option value="Cancelled" <?= $status === 'Cancelled' ? 'selected' : '' ?>>Annullati</option>
                    <option value="Refunded" <?= $status === 'Refunded' ? 'selected' : '' ?>>Resi</option>
                </select>
            </div>
            <div class="form__group">
                <label for="payment">Pagamento</label>
                <?php $payment = $filters['payment'] ?? ''; ?>
                <select name="payment" id="payment">
                    <option value="">Tutti</option>
                    <option value="Contanti" <?= $payment === 'Contanti' ? 'selected' : '' ?>>Contanti</option>
                    <option value="Carta" <?= $payment === 'Carta' ? 'selected' : '' ?>>Carta</option>
                    <option value="POS" <?= $payment === 'POS' ? 'selected' : '' ?>>POS</option>
                </select>
            </div>
            <div class="form__group">
                <label for="from">Dal</label>
                <input type="date" name="from" id="from" value="<?= htmlspecialchars((string) ($filters['from'] ?? '')) ?>">
            </div>
            <div class="form__group">
                <label for="to">Al</label>
                <input type="date" name="to" id="to" value="<?= htmlspecialchars((string) ($filters['to'] ?? '')) ?>">
            </div>
        </div>
        <div class="filters-bar__actions">
            <button type="submit" class="btn btn--primary">Filtra</button>
            <a class="btn btn--secondary" href="index.php?page=sales_list">Azzera filtri</a>
        </div>
    </form>

    <section class="page__section">
        <div class="table-wrapper">
            <table class="table" data-live-slot="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Data</th>
                        <th>Cliente</th>
                        <th>Operatore</th>
                        <th>Pagamento</th>
                        <th>Totale</th>
                        <th>Stato</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody data-live-slot="rows">
                    <?php if ($sales === []): ?>
                        <tr>
                            <td colspan="8">Nessuna vendita trovata.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($sales as $sale): ?>
                        <?php
                            $customer = $sale['customer_name'] !== null && $sale['customer_name'] !== ''
                                ? $sale['customer_name']
                                : 'Cliente non specificato';
                            $operator = $sale['fullname'] !== null && $sale['fullname'] !== ''
                                ? $sale['fullname']
                                : $sale['username'];
                            $statusLabel = match ($sale['status']) {
                                'Cancelled' => ['label' => 'Annullato', 'class' => 'badge--muted'],
                                'Refunded' => ['label' => 'Reso', 'class' => 'badge--warning'],
                                default => ['label' => 'Completato', 'class' => 'badge--success'],
                            };
                            $created = (new DateTimeImmutable($sale['created_at']))->format('d/m/Y H:i');
                        ?>
                        <tr>
                            <td>#<?= (int) $sale['id'] ?></td>
                            <td><?= htmlspecialchars($created) ?></td>
                            <td><?= htmlspecialchars((string) $customer) ?></td>
                            <td><?= htmlspecialchars((string) $operator) ?></td>
                            <td><?= htmlspecialchars((string) $sale['payment_method']) ?></td>
                            <td>€ <?= number_format((float) $sale['total'], 2, ',', '.') ?></td>
                            <td>
                                <span class="badge <?= $statusLabel['class'] ?>">
                                    <?= $statusLabel['label'] ?>
                                </span>
                            </td>
                            <td class="table-actions-inline">
                                <a class="btn btn--secondary" href="print_receipt.php?sale_id=<?= (int) $sale['id'] ?>" target="_blank">Stampa</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($pagination['pages'] > 1): ?>
        <nav class="pagination" data-live-slot="pagination">
            <?php $current = $pagination['page']; ?>
            <a class="pagination__link <?= $current === 1 ? 'is-disabled' : '' ?>" href="<?= $current === 1 ? '#' : buildPageUrl($filters, 1) ?>">«</a>
            <a class="pagination__link <?= $current === 1 ? 'is-disabled' : '' ?>" href="<?= $current === 1 ? '#' : buildPageUrl($filters, $current - 1) ?>">‹</a>
            <span class="pagination__info">Pagina <?= $current ?> di <?= $pagination['pages'] ?> (<?= $pagination['total'] ?> risultati)</span>
            <a class="pagination__link <?= $current === $pagination['pages'] ? 'is-disabled' : '' ?>" href="<?= $current === $pagination['pages'] ? '#' : buildPageUrl($filters, $current + 1) ?>">›</a>
            <a class="pagination__link <?= $current === $pagination['pages'] ? 'is-disabled' : '' ?>" href="<?= $current === $pagination['pages'] ? '#' : buildPageUrl($filters, $pagination['pages']) ?>">»</a>
        </nav>
    <?php else: ?>
        <nav class="pagination" data-live-slot="pagination" hidden></nav>
    <?php endif; ?>
</section>
<?php
/**
 * @param array<string, mixed> $filters
 */
function buildPageUrl(array $filters, int $page): string
{
    $query = array_filter([
        'page' => 'sales_list',
        'page_no' => $page,
        'q' => $filters['q'] ?? null,
        'status' => $filters['status'] ?? null,
        'payment' => $filters['payment'] ?? null,
        'from' => $filters['from'] ?? null,
        'to' => $filters['to'] ?? null,
    ], static fn ($value) => $value !== null && $value !== '');

    return 'index.php?' . http_build_query($query);
}
?>
