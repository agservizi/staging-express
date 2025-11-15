<?php
declare(strict_types=1);

/**
 * @var array{success:bool, message:string, errors?:array<int, string>}|null $feedback
 * @var array<int, array<string, mixed>> $products
 * @var array{page:int, per_page:int, total:int, total_pages:int, has_prev:bool, has_next:bool}|null $pagination
 */
$pageTitle = 'Lista prodotti';
$products = $products ?? [];
$pagination = $pagination ?? [
    'page' => 1,
    'per_page' => 7,
    'total' => count($products),
    'total_pages' => 1,
    'has_prev' => false,
    'has_next' => false,
];
$feedback = $feedback ?? null;
$buildProductsPageUrl = static function (int $pageNo) use ($pagination): string {
    $pageNo = max(1, $pageNo);
    return 'index.php?' . http_build_query([
        'page' => 'products_list',
        'page_no' => $pageNo,
    ]);
};
?>
<section class="page">
    <header class="page__header">
        <h2>Lista prodotti</h2>
        <p class="muted">Visualizza il catalogo già registrato e verifica la disponibilità in cassa.</p>
        <p>
            <a class="btn btn--secondary" href="index.php?page=products">Aggiungi un nuovo prodotto</a>
        </p>
    </header>

    <section class="page__section">
        <?php if ($feedback !== null): ?>
            <div class="alert <?= $feedback['success'] ? 'alert--success' : 'alert--error' ?>">
                <p><?= htmlspecialchars($feedback['message']) ?></p>
                <?php if (!$feedback['success']): ?>
                    <?php foreach ($feedback['errors'] ?? [] as $error): ?>
                        <p class="muted"><?= htmlspecialchars($error) ?></p>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Categoria</th>
                        <th>SKU</th>
                        <th>IMEI</th>
                        <th>Prezzo</th>
                        <th>IVA</th>
                        <th>Stock</th>
                        <th>Soglia</th>
                        <th>Stato</th>
                        <th>Creato</th>
                        <th class="table__col--actions">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($products === []): ?>
                        <tr><td colspan="11">Nessun prodotto registrato.</td></tr>
                    <?php else: ?>
                        <?php foreach ($products as $product): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) ($product['name'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string) ($product['category'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string) ($product['sku'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string) ($product['imei'] ?? '')) ?></td>
                                <td>€ <?= number_format((float) ($product['price'] ?? 0), 2, ',', '.') ?></td>
                                <td><?= number_format((float) ($product['tax_rate'] ?? 0), 2, ',', '.') ?>%</td>
                                <td><?= (int) ($product['stock_quantity'] ?? 0) ?></td>
                                <td><?= (int) ($product['reorder_threshold'] ?? 0) ?></td>
                                <td>
                                    <?php $active = (int) ($product['is_active'] ?? 1) === 1; ?>
                                    <span class="badge <?= $active ? 'badge--success' : 'badge--muted' ?>">
                                        <?= $active ? 'Attivo' : 'Disattivo' ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($product['created_at'])): ?>
                                        <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $product['created_at']))) ?>
                                    <?php else: ?>
                                        n/d
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="micro-actions" role="group" aria-label="Azioni prodotto">
                                        <a href="index.php?page=products&amp;edit=<?= (int) ($product['id'] ?? 0) ?>" class="micro-actions__btn micro-actions__btn--edit" data-tooltip="Modifica" aria-label="Modifica prodotto">
                                            <svg class="micro-actions__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                <path d="M4 17.25V20h2.75L17.81 8.94l-2.75-2.75L4 17.25z"></path>
                                                <path d="M20.71 5.04a1 1 0 0 0 0-1.41l-1.34-1.34a1 1 0 0 0-1.41 0l-1.58 1.58 2.75 2.75 1.58-1.58z"></path>
                                            </svg>
                                        </a>
                                        <form method="post" class="micro-actions__form" onsubmit="return confirm('Eliminare definitivamente il prodotto selezionato?');">
                                            <input type="hidden" name="action" value="delete_product">
                                            <input type="hidden" name="product_id" value="<?= (int) ($product['id'] ?? 0) ?>">
                                            <button type="submit" class="micro-actions__btn micro-actions__btn--delete" data-tooltip="Elimina" aria-label="Elimina prodotto">
                                                <svg class="micro-actions__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                    <path d="M9 3a1 1 0 0 0-.94.66L7.62 5H5a1 1 0 0 0 0 2h14a1 1 0 1 0 0-2h-2.62l-.44-1.34A1 1 0 0 0 15 3H9zM6 8v10a3 3 0 0 0 3 3h6a3 3 0 0 0 3-3V8H6zm5 3a1 1 0 0 1 2 0v6a1 1 0 1 1-2 0v-6z"></path>
                                                </svg>
                                            </button>
                                        </form>
                                        <form method="post" class="micro-actions__form">
                                            <input type="hidden" name="action" value="restock_product">
                                            <input type="hidden" name="product_id" value="<?= (int) ($product['id'] ?? 0) ?>">
                                            <button type="submit" class="micro-actions__btn micro-actions__btn--restock" data-tooltip="Restock" aria-label="Ripristina stock">
                                                <svg class="micro-actions__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                    <path d="M12 5a7 7 0 0 1 6.32 4h-1.9a5 5 0 1 0 0 6h1.9A7 7 0 1 1 12 5zm0 14a7 7 0 0 1-6.32-4h1.9a5 5 0 1 0 0-6h-1.9A7 7 0 1 1 12 19z"></path>
                                                </svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (($pagination['total_pages'] ?? 1) > 1): ?>
            <?php $current = (int) $pagination['page']; ?>
            <nav class="pagination">
                <a class="pagination__link <?= ($pagination['has_prev'] ?? false) ? '' : 'is-disabled' ?>" href="<?= ($pagination['has_prev'] ?? false) ? $buildProductsPageUrl(1) : '#' ?>" aria-label="Prima pagina">«</a>
                <a class="pagination__link <?= ($pagination['has_prev'] ?? false) ? '' : 'is-disabled' ?>" href="<?= ($pagination['has_prev'] ?? false) ? $buildProductsPageUrl($current - 1) : '#' ?>" aria-label="Pagina precedente">‹</a>
                <span class="pagination__info">Pagina <?= $current ?> di <?= (int) ($pagination['total_pages'] ?? 1) ?> (<?= (int) ($pagination['total'] ?? count($products)) ?> prodotti)</span>
                <a class="pagination__link <?= ($pagination['has_next'] ?? false) ? '' : 'is-disabled' ?>" href="<?= ($pagination['has_next'] ?? false) ? $buildProductsPageUrl($current + 1) : '#' ?>" aria-label="Pagina successiva">›</a>
                <a class="pagination__link <?= ($pagination['has_next'] ?? false) ? '' : 'is-disabled' ?>" href="<?= ($pagination['has_next'] ?? false) ? $buildProductsPageUrl((int) ($pagination['total_pages'] ?? 1)) : '#' ?>" aria-label="Ultima pagina">»</a>
            </nav>
        <?php else: ?>
            <nav class="pagination" hidden></nav>
        <?php endif; ?>
    </section>
</section>
