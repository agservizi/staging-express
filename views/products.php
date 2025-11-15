<?php
declare(strict_types=1);

/**
 * @var array{success:bool, message:string, errors?:array<int, string>}|null $feedback
 * @var array<string, mixed>|null $editingProduct
 */
$pageTitle = 'Catalogo prodotti';
$feedback = $feedback ?? null;
$editingProduct = $editingProduct ?? null;
$isEditing = $editingProduct !== null;
$categoryOptions = ['Smartphone', 'Tablet', 'Accessori'];
$selectedCategory = $isEditing ? (string) ($editingProduct['category'] ?? '') : '';
if ($selectedCategory !== '' && !in_array($selectedCategory, $categoryOptions, true)) {
    $categoryOptions[] = $selectedCategory;
}

if ($isEditing) {
    $pageTitle = 'Modifica prodotto';
}
?>
<section class="page">
    <header class="page__header">
        <h2><?= $isEditing ? 'Modifica prodotto' : 'Catalogo prodotti' ?></h2>
        <p>Gestisci smartphone, tablet e accessori da utilizzare in cassa.</p>
        <p class="muted">
            Consulta l'elenco completo dalla nuova <a href="index.php?page=products_list">Lista prodotti</a>.
        </p>
    </header>

    <section class="page__section">
        <h3><?= $isEditing ? 'Aggiorna i dati del prodotto' : 'Aggiungi prodotto' ?></h3>
        <p class="muted">
            <?= $isEditing
                ? 'Stai modificando un prodotto esistente: aggiorna i campi necessari e salva le modifiche.'
                : 'Compila i campi principali: il prezzo è comprensivo di IVA.'
            ?>
        </p>

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

        <form method="post" class="form" autocomplete="off">
            <input type="hidden" name="action" value="<?= $isEditing ? 'update' : 'create' ?>">
            <?php if ($isEditing): ?>
                <input type="hidden" name="product_id" value="<?= (int) ($editingProduct['id'] ?? 0) ?>">
            <?php endif; ?>
            <div class="form__grid">
                <div class="form__group">
                    <label for="name">Nome prodotto</label>
                    <input type="text" name="name" id="name" required maxlength="150" placeholder="Es. iPhone 15 128GB" value="<?= htmlspecialchars($isEditing ? (string) ($editingProduct['name'] ?? '') : '') ?>">
                </div>
                <div class="form__group">
                    <label for="category">Categoria</label>
                    <select name="category" id="category" required>
                        <option value="" disabled <?= $selectedCategory === '' ? 'selected' : '' ?>>Seleziona una categoria</option>
                        <?php foreach ($categoryOptions as $categoryOption): ?>
                            <option value="<?= htmlspecialchars($categoryOption) ?>" <?= $selectedCategory === $categoryOption ? 'selected' : '' ?>><?= htmlspecialchars($categoryOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form__group">
                    <label for="sku">SKU</label>
                    <input type="text" name="sku" id="sku" maxlength="100" placeholder="Codice interno" value="<?= htmlspecialchars($isEditing ? (string) ($editingProduct['sku'] ?? '') : '') ?>">
                </div>
                <div class="form__group">
                    <label for="imei">IMEI</label>
                    <input type="text" name="imei" id="imei" maxlength="100" placeholder="Numero IMEI del dispositivo" value="<?= htmlspecialchars($isEditing ? (string) ($editingProduct['imei'] ?? '') : '') ?>">
                </div>
                <div class="form__group">
                    <label for="price">Prezzo (€)</label>
                    <input type="number" name="price" id="price" step="0.01" min="0" placeholder="0.00" required value="<?= htmlspecialchars($isEditing ? number_format((float) ($editingProduct['price'] ?? 0), 2, '.', '') : '') ?>">
                </div>
                <div class="form__group">
                    <label for="tax_rate">IVA (%)</label>
                    <input type="number" name="tax_rate" id="tax_rate" step="0.01" min="0" max="100" value="<?= htmlspecialchars($isEditing ? number_format((float) ($editingProduct['tax_rate'] ?? 0), 2, '.', '') : '22.00') ?>" required>
                </div>
                <div class="form__group">
                    <label for="stock_quantity">Stock disponibile</label>
                    <input type="number" name="stock_quantity" id="stock_quantity" min="0" value="<?= htmlspecialchars($isEditing ? (string) ((int) ($editingProduct['stock_quantity'] ?? 0)) : '0') ?>">
                    <small class="muted">Quantità fisica presente in magazzino.</small>
                </div>
                <div class="form__group">
                    <label for="reorder_threshold">Soglia di riordino</label>
                    <input type="number" name="reorder_threshold" id="reorder_threshold" min="0" value="<?= htmlspecialchars($isEditing ? (string) ((int) ($editingProduct['reorder_threshold'] ?? 0)) : '0') ?>">
                    <small class="muted">Avviso interno quando lo stock scende sotto la soglia.</small>
                </div>
                <div class="form__group form__group--checkbox">
                    <label class="form__checkbox">
                        <?php $isActive = $isEditing ? ((int) ($editingProduct['is_active'] ?? 1) === 1) : true; ?>
                        <input type="checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>>
                        <span>Prodotto attivo</span>
                    </label>
                </div>
            </div>
            <div class="form__group">
                <label for="notes">Note (opzionali)</label>
                <textarea name="notes" id="notes" rows="2" placeholder="Informazioni aggiuntive, colori disponibili..."><?= htmlspecialchars($isEditing ? (string) ($editingProduct['notes'] ?? '') : '') ?></textarea>
            </div>
            <div class="form__footer">
                <button type="submit" class="btn btn--primary"><?= $isEditing ? 'Aggiorna prodotto' : 'Salva prodotto' ?></button>
                <?php if ($isEditing): ?>
                    <a class="btn btn--secondary" href="index.php?page=products">Annulla modifica</a>
                <?php endif; ?>
                <a class="btn btn--secondary" href="index.php?page=products_list">Vai alla lista prodotti</a>
            </div>
        </form>
    </section>
</section>
