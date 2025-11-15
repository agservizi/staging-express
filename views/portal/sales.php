<?php
$sales = $data['sales'] ?? [];
$pagination = $data['pagination'] ?? ['page' => 1, 'per_page' => 10, 'total' => 0, 'pages' => 1];
$filters = $data['filters'] ?? ['status' => null, 'payment_status' => null, 'per_page' => 10];
$feedbackPayment = $data['feedbackPayment'] ?? null;
$catalog = $data['catalog'] ?? [
    'rows' => [],
    'pagination' => ['page' => 1, 'per_page' => 8, 'total' => 0, 'pages' => 1],
    'filters' => ['category' => null, 'search' => null, 'per_page' => 8],
    'categories' => [],
];
$catalogRows = $catalog['rows'] ?? [];
$catalogPagination = $catalog['pagination'] ?? ['page' => 1, 'per_page' => 8, 'total' => 0, 'pages' => 1];
$catalogFilters = $catalog['filters'] ?? ['category' => null, 'search' => null, 'per_page' => 8];
$catalogCategories = $catalog['categories'] ?? [];
$productOptions = $data['productOptions'] ?? [];
$productRequests = $data['productRequests'] ?? [];
$feedbackProduct = $data['feedbackProduct'] ?? null;
$selectedProduct = (int) ($data['selectedProduct'] ?? 0);
$selectedProductData = null;
$selectedProductPrice = 0.0;
$selectedProductCategory = null;
$selectedProductAvailability = null;
if ($selectedProduct > 0) {
    foreach ($productOptions as $option) {
        if ((int) ($option['id'] ?? 0) === $selectedProduct) {
            $selectedProductData = $option;
            $selectedProductPrice = (float) ($option['price'] ?? 0.0);
            $selectedProductCategory = $option['category'] ?? null;
            $selectedProductAvailability = $option['available_stock'] ?? null;
            break;
        }
    }
}

$formatMoney = static function (float $value): string {
    return '€ ' . number_format($value, 2, ',', '.');
};

$storeStats = [
    'catalog' => max(0, (int) ($catalog['pagination']['total'] ?? count($catalogRows))),
    'orders' => count($productRequests),
    'sales' => count($sales),
];

$ordersValue = 0.0;
$completedOrders = 0;
foreach ($productRequests as $orderRow) {
    $ordersValue += (float) ($orderRow['product_price'] ?? 0.0);
    if (($orderRow['status'] ?? '') === 'Completed') {
        $completedOrders++;
    }
}

$typeLabels = [
    'Purchase' => 'Acquisto',
    'Reservation' => 'Prenotazione',
    'Deposit' => 'Acconto',
    'Installment' => 'Rateizzazione',
];

$statusLabels = [
    'Pending' => 'In lavorazione',
    'InReview' => 'In revisione',
    'Confirmed' => 'Confermato',
    'Completed' => 'Completato',
    'Cancelled' => 'Annullato',
    'Declined' => 'Rifiutato',
];

$applyQueryFilter = static function (array $params): string {
    $filtered = array_filter($params, static fn ($value): bool => $value !== null && $value !== '');
    return 'index.php?' . http_build_query($filtered);
};

$catalogBaseParams = [
    'view' => 'sales',
    'page' => (int) ($pagination['page'] ?? 1),
    'per_page' => $filters['per_page'] ?? null,
    'status' => $filters['status'] ?? null,
    'payment_status' => $filters['payment_status'] ?? null,
    'catalog_page' => (int) ($catalogPagination['page'] ?? 1),
    'catalog_per_page' => $catalogFilters['per_page'] ?? null,
    'catalog_category' => $catalogFilters['category'] ?? null,
    'catalog_search' => $catalogFilters['search'] ?? null,
];

$salesPaginationFilters = $filters;
$salesPaginationFilters['catalog_page'] = $catalogPagination['page'] ?? null;
$salesPaginationFilters['catalog_per_page'] = $catalogFilters['per_page'] ?? null;
$salesPaginationFilters['catalog_category'] = $catalogFilters['category'] ?? null;
$salesPaginationFilters['catalog_search'] = $catalogFilters['search'] ?? null;
if ($selectedProduct > 0) {
    $salesPaginationFilters['selected_product'] = $selectedProduct;
}

$catalogPaginationLink = static function (int $page) use ($catalogBaseParams, $applyQueryFilter): string {
    $params = $catalogBaseParams;
    $params['catalog_page'] = max(1, $page);
    return $applyQueryFilter($params);
};

$productSelectLink = static function (int $productId) use ($catalogBaseParams, $applyQueryFilter): string {
    $params = $catalogBaseParams;
    $params['selected_product'] = $productId;
    return $applyQueryFilter($params) . '#request-product';
};
?>

<section class="portal-section store-hero" id="store-hero">
    <div class="store-hero__content">
        <h2>Store online clienti</h2>
        <p>Ordina dispositivi, modem e accessori direttamente dall'area riservata. Confermeremo la disponibilità e ti avviseremo appena il prodotto sarà pronto al ritiro.</p>
        <div class="store-hero__actions">
            <a class="portal-button portal-button--primary" href="#store-catalog">Sfoglia il catalogo</a>
            <a class="portal-button portal-button--ghost" href="index.php?view=orders">Vai ai tuoi ordini</a>
        </div>
    </div>
    <ul class="store-hero__stats">
        <li>
            <span class="store-hero__label">Prodotti attivi</span>
            <strong class="store-hero__value"><?= number_format($storeStats['catalog'], 0, ',', '.') ?></strong>
        </li>
        <li>
            <span class="store-hero__label">Ordini inviati</span>
            <strong class="store-hero__value"><?= number_format($storeStats['orders'], 0, ',', '.') ?></strong>
        </li>
        <li>
            <span class="store-hero__label">Ordini completati</span>
            <strong class="store-hero__value"><?= number_format($completedOrders, 0, ',', '.') ?></strong>
        </li>
        <li>
            <span class="store-hero__label">Valore richiesto</span>
            <strong class="store-hero__value"><?= $ordersValue > 0 ? $formatMoney($ordersValue) : '€ 0,00' ?></strong>
        </li>
    </ul>
</section>

<div class="store-layout" id="store-catalog">

<section class="portal-section store-catalog" id="catalogo-prodotti">
    <header class="portal-section__header">
        <h2>Catalogo prodotti</h2>
        <form method="get" class="portal-filters">
            <input type="hidden" name="view" value="sales">
            <input type="hidden" name="page" value="<?= (int) ($pagination['page'] ?? 1) ?>">
            <input type="hidden" name="status" value="<?= htmlspecialchars((string) ($filters['status'] ?? '')) ?>">
            <input type="hidden" name="payment_status" value="<?= htmlspecialchars((string) ($filters['payment_status'] ?? '')) ?>">
            <input type="hidden" name="per_page" value="<?= (int) ($filters['per_page'] ?? 10) ?>">
            <label>
                Categoria
                <select name="catalog_category">
                    <option value="">Tutte</option>
                    <?php foreach ($catalogCategories as $category): ?>
                        <option value="<?= htmlspecialchars($category) ?>"<?= ($catalogFilters['category'] ?? null) === $category ? ' selected' : '' ?>><?= htmlspecialchars($category) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Cerca
                <input type="search" name="catalog_search" placeholder="Nome o SKU" value="<?= htmlspecialchars((string) ($catalogFilters['search'] ?? '')) ?>">
            </label>
            <label>
                Prodotti per pagina
                <select name="catalog_per_page">
                    <?php foreach ([6, 8, 12, 16] as $opt): ?>
                        <option value="<?= $opt ?>"<?= (int) ($catalogFilters['per_page'] ?? 8) === $opt ? ' selected' : '' ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="portal-button portal-button--primary">Aggiorna</button>
        </form>
    </header>

    <?php if ($catalogRows === []): ?>
        <p class="portal-empty">Nessun prodotto disponibile per i filtri selezionati.</p>
    <?php else: ?>
        <div class="portal-grid">
            <?php foreach ($catalogRows as $product): ?>
                <?php $available = max(0, (int) ($product['available_stock'] ?? 0)); ?>
                <article class="portal-card">
                    <header>
                        <h3><?= htmlspecialchars((string) $product['name']) ?></h3>
                        <?php if (!empty($product['category'])): ?>
                            <p class="portal-hint">Categoria: <?= htmlspecialchars((string) $product['category']) ?></p>
                        <?php endif; ?>
                    </header>
                    <p class="portal-card__value">€ <?= number_format((float) ($product['price'] ?? 0.0), 2, ',', '.') ?></p>
                    <p class="portal-hint">Disponibilità: <?= $available > 0 ? $available . ' pezzi' : 'Su richiesta' ?></p>
                    <?php if (!empty($product['notes'])): ?>
                        <p class="portal-hint">Note: <?= htmlspecialchars((string) $product['notes']) ?></p>
                    <?php endif; ?>
                    <div class="portal-actions">
                        <a class="portal-button portal-button--primary" href="<?= htmlspecialchars($productSelectLink((int) $product['id'])) ?>">Richiedi</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?php $catalogCurrentPage = (int) ($catalogPagination['page'] ?? 1); ?>
        <?php $catalogPages = (int) ($catalogPagination['pages'] ?? 1); ?>
        <?php if ($catalogPages > 1): ?>
            <div class="portal-pagination">
                <span>Pagina catalogo <?= $catalogCurrentPage ?> di <?= $catalogPages ?></span>
                <div class="portal-pagination__actions">
                    <?php if ($catalogCurrentPage > 1): ?>
                        <a class="portal-button portal-button--ghost" href="<?= htmlspecialchars($catalogPaginationLink($catalogCurrentPage - 1)) ?>">Precedente</a>
                    <?php endif; ?>
                    <?php if ($catalogCurrentPage < $catalogPages): ?>
                        <a class="portal-button portal-button--ghost" href="<?= htmlspecialchars($catalogPaginationLink($catalogCurrentPage + 1)) ?>">Successiva</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="portal-section store-request" id="request-product">
    <header class="portal-section__header">
        <div>
            <h2>Completa l'ordine</h2>
            <p class="store-request__subtitle">Ti ricontattiamo per confermare disponibilità e tempi di ritiro in agenzia.</p>
        </div>
    </header>

    <?php if ($selectedProductData !== null): ?>
        <?php $suggestedDeposit = round($selectedProductPrice * 0.3, 2); ?>
        <aside class="store-order-summary">
            <div class="store-order-summary__header">
                <div>
                    <h3><?= htmlspecialchars((string) ($selectedProductData['name'] ?? 'Prodotto')) ?></h3>
                    <?php if (!empty($selectedProductCategory)): ?>
                        <span class="store-order-summary__category">Categoria · <?= htmlspecialchars((string) $selectedProductCategory) ?></span>
                    <?php endif; ?>
                </div>
                <span class="store-order-summary__price"><?= $formatMoney($selectedProductPrice) ?></span>
            </div>
            <ul class="store-order-summary__meta">
                <li>
                    <strong>Disponibilità</strong>
                    <span><?= ($selectedProductAvailability ?? 0) > 0 ? number_format((float) $selectedProductAvailability, 0, ',', '.') . ' pezzi' : 'Su richiesta' ?></span>
                </li>
                <?php if (!empty($selectedProductData['notes'])): ?>
                    <li>
                        <strong>Note</strong>
                        <span><?= htmlspecialchars((string) $selectedProductData['notes']) ?></span>
                    </li>
                <?php endif; ?>
                <li>
                    <strong>Pagamento</strong>
                    <span>Bonifico, in negozio o rateizzazione dedicata</span>
                </li>
            </ul>
            <?php if ($selectedProductPrice > 0): ?>
                <p class="store-order-summary__hint">Suggerimento acconto (30%): <strong><?= $formatMoney($suggestedDeposit) ?></strong></p>
            <?php endif; ?>
        </aside>
    <?php else: ?>
        <aside class="store-order-summary store-order-summary--empty">
            <h3>Seleziona un prodotto dal catalogo</h3>
            <p>Scegli un articolo per compilare automaticamente i dettagli dell'ordine.</p>
            <a class="portal-button portal-button--ghost" href="#store-catalog">Apri catalogo</a>
        </aside>
    <?php endif; ?>

    <ul class="store-steps">
        <li>
            <strong>1. Invia la richiesta</strong>
            <span>Indica quantità, acconto e preferenze di pagamento.</span>
        </li>
        <li>
            <strong>2. Conferma disponibilità</strong>
            <span>Ti avvisiamo tramite e-mail o telefono appena l'ordine è pronto.</span>
        </li>
        <li>
            <strong>3. Ritira e paga</strong>
            <span>Completa l'acquisto in agenzia oppure finalizza il bonifico concordato.</span>
        </li>
    </ul>

    <p class="portal-hint">Prenota il dispositivo, lascia un acconto o richiedi il pagamento in 6 rate tramite bonifico. Riceverai conferma quando sarà pronto al ritiro in agenzia.</p>
    <?php if ($feedbackProduct !== null): ?>
        <div class="portal-alert portal-alert--<?= ($feedbackProduct['success'] ?? false) ? 'success' : 'error' ?>">
            <p><?= htmlspecialchars($feedbackProduct['message'] ?? 'Richiesta elaborata.') ?></p>
            <?php foreach ($feedbackProduct['errors'] ?? [] as $error): ?>
                <p><?= htmlspecialchars($error) ?></p>
            <?php endforeach; ?>
            <?php if (($feedbackProduct['success'] ?? false) && (($feedbackProduct['need_transfer_info'] ?? false) || ($feedbackProduct['payment_method'] ?? '') === 'BankTransfer')): ?>
                <p class="portal-hint">Per il bonifico utilizza i dati:</p>
                <p class="portal-hint"><strong>Beneficiario:</strong> AG SERVIZI VIA PLINIO 72 DI CAVALIERE CARMINE</p>
                <p class="portal-hint"><strong>IBAN:</strong> IT63 D032 6822 3000 5254 9691 300</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <form method="post" class="portal-form">
        <input type="hidden" name="action" value="create_product_request">
        <div class="portal-form__grid">
            <label>
                Prodotto
                <select name="product_id" required>
                    <option value="">Seleziona</option>
                    <?php foreach ($productOptions as $option): ?>
                        <?php $optionId = (int) ($option['id'] ?? 0); ?>
                        <option value="<?= $optionId ?>" data-price="<?= number_format((float) ($option['price'] ?? 0.0), 2, '.', '') ?>"<?= $selectedProduct === $optionId ? ' selected' : '' ?>>
                            <?= htmlspecialchars((string) $option['name']) ?><?= !empty($option['category']) ? ' · ' . htmlspecialchars((string) $option['category']) : '' ?>
                            (<?= number_format((float) ($option['price'] ?? 0.0), 2, ',', '.') ?> €)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Operazione
                <select name="request_type" id="product-request-type">
                    <?php foreach ($typeLabels as $value => $label): ?>
                        <option value="<?= $value ?>"<?= $value === 'Purchase' ? ' selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label data-field="deposit">
                Acconto
                <input type="number" step="0.01" min="0" name="deposit_amount" placeholder="0,00">
            </label>
            <label data-field="installments">
                Numero rate (max 24)
                <input type="number" min="2" max="24" name="installments" value="6">
            </label>
            <label>
                Modalità pagamento
                <select name="payment_method">
                    <option value="BankTransfer">Bonifico</option>
                    <option value="InStore">In negozio</option>
                    <option value="Other">Altro</option>
                </select>
            </label>
            <label>
                Data ritiro preferita
                <input type="date" name="desired_pickup_date">
            </label>
            <label>
                Riferimento bonifico (CRO, causale)
                <input type="text" name="bank_reference" maxlength="100">
            </label>
        </div>
        <label class="portal-form__full">
            Note aggiuntive
            <textarea name="note" rows="3" placeholder="Aggiungi indicazioni utili per la prenotazione o la consegna."></textarea>
        </label>
        <button type="submit" class="portal-button portal-button--primary">Invia richiesta</button>
    </form>
</section>

</div>

<section class="portal-section">
    <header class="portal-section__header">
        <h2>Storico vendite</h2>
        <form method="get" class="portal-filters">
            <input type="hidden" name="view" value="sales">
            <label>
                Stato vendita
                <select name="status">
                    <option value="">Tutti</option>
                    <?php foreach (['Completed' => 'Completate', 'Refunded' => 'Rese', 'Cancelled' => 'Annullate'] as $value => $label): ?>
                        <option value="<?= $value ?>"<?= ($filters['status'] ?? '') === $value ? ' selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Stato pagamento
                <select name="payment_status">
                    <option value="">Tutti</option>
                    <?php foreach (['Paid' => 'Pagato', 'Partial' => 'Parziale', 'Pending' => 'In attesa', 'Overdue' => 'Scaduto'] as $value => $label): ?>
                        <option value="<?= $value ?>"<?= ($filters['payment_status'] ?? '') === $value ? ' selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Risultati per pagina
                <select name="per_page">
                    <?php foreach ([10, 20, 30] as $opt): ?>
                        <option value="<?= $opt ?>"<?= (int) ($filters['per_page'] ?? 10) === $opt ? ' selected' : '' ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="portal-button portal-button--primary">Filtra</button>
        </form>
    </header>

    <?php if ($sales === []): ?>
        <p class="portal-empty">Nessuna vendita disponibile.</p>
    <?php else: ?>
        <div class="portal-table-wrapper">
            <table class="portal-table">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Data</th>
                    <th>Totale</th>
                    <th>Pagato</th>
                    <th>Saldo</th>
                    <th>Pagamento</th>
                    <th>Stato</th>
                    <th>Scadenza</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($sales as $sale): ?>
                    <tr>
                        <td><?= (int) $sale['id'] ?></td>
                        <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $sale['created_at']))) ?></td>
                        <td>€ <?= number_format((float) $sale['total'], 2, ',', '.') ?></td>
                        <td>€ <?= number_format((float) $sale['total_paid'], 2, ',', '.') ?></td>
                        <td>€ <?= number_format((float) $sale['balance_due'], 2, ',', '.') ?></td>
                        <td><span class="portal-badge portal-badge--<?= portal_badge_class((string) $sale['payment_status']) ?>"><?= htmlspecialchars((string) $sale['payment_status']) ?></span></td>
                        <td><?= htmlspecialchars((string) $sale['status']) ?></td>
                        <td><?= !empty($sale['due_date']) ? htmlspecialchars(date('d/m/Y', strtotime((string) $sale['due_date']))) : '—' ?></td>
                        <td><a class="portal-link" href="index.php?view=sale_detail&amp;sale_id=<?= (int) $sale['id'] ?>">Dettagli</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="portal-pagination">
            <?php $currentPage = (int) ($pagination['page'] ?? 1); ?>
            <?php $pagesTotal = (int) ($pagination['pages'] ?? 1); ?>
            <span>Pagina <?= $currentPage ?> di <?= $pagesTotal ?></span>
            <div class="portal-pagination__actions">
                <?php if ($currentPage > 1): ?>
                    <a class="portal-button portal-button--ghost" href="<?= portal_paginate_link('sales', $currentPage - 1, $salesPaginationFilters) ?>">Precedente</a>
                <?php endif; ?>
                <?php if ($currentPage < $pagesTotal): ?>
                    <a class="portal-button portal-button--ghost" href="<?= portal_paginate_link('sales', $currentPage + 1, $salesPaginationFilters) ?>">Successiva</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</section>

<section class="portal-section">
    <header class="portal-section__header">
        <h2>Segnala un pagamento</h2>
    </header>
    <?php if ($feedbackPayment !== null): ?>
        <div class="portal-alert portal-alert--<?= ($feedbackPayment['success'] ?? false) ? 'success' : 'error' ?>">
            <p><?= htmlspecialchars($feedbackPayment['message'] ?? 'Operazione completata.') ?></p>
            <?php foreach ($feedbackPayment['errors'] ?? [] as $error): ?>
                <p><?= htmlspecialchars($error) ?></p>
            <?php endforeach; ?>
            <?php if (($feedbackPayment['success'] ?? false) && (($feedbackPayment['payment_method'] ?? '') === 'BankTransfer' || ($feedbackPayment['need_transfer_info'] ?? false))): ?>
                <p class="portal-hint">Per completare il bonifico utilizza:</p>
                <p class="portal-hint"><strong>Beneficiario:</strong> AG SERVIZI VIA PLINIO 72 DI CAVALIERE CARMINE</p>
                <p class="portal-hint"><strong>IBAN:</strong> IT63 D032 6822 3000 5254 9691 300</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <form method="post" class="portal-form">
        <input type="hidden" name="action" value="create_payment">
        <div class="portal-form__grid">
            <label>
                Vendita
                <select name="sale_id" required>
                    <option value="">Seleziona</option>
                    <?php foreach ($sales as $sale): ?>
                        <?php if ((float) $sale['balance_due'] <= 0) { continue; } ?>
                        <option value="<?= (int) $sale['id'] ?>" data-balance="<?= number_format((float) $sale['balance_due'], 2, '.', '') ?>">#<?= (int) $sale['id'] ?> · Saldo € <?= number_format((float) $sale['balance_due'], 2, ',', '.') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Importo
                <input type="number" step="0.01" min="0" name="amount" placeholder="0,00" required>
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
        </div>
        <label>
            Nota (facoltativa)
            <textarea name="note" rows="3" placeholder="Inserisci riferimenti utili (es. CRO, banca)"></textarea>
        </label>
        <button type="submit" class="portal-button portal-button--primary">Invia segnalazione</button>
    </form>
</section>
