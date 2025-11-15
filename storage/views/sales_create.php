<?php
declare(strict_types=1);

/**
 * @var array<int, array<string, mixed>> $availableIccid
 * @var array{success:bool, errors?:array<int, string>}|null $feedbackCreate
 * @var array{success:bool, errors?:array<int, string>, message?:string}|null $feedbackCancel
 * @var array{success:bool, errors?:array<int, string>, message?:string}|null $feedbackRefund
 * @var array<int, array<string, mixed>> $discountCampaigns
 * @var array<int, array<string, mixed>> $availableOffers
 * @var array<int, array<string, mixed>> $availableProducts
 */
$pageTitle = 'Nuova vendita';
$availableOffers = $availableOffers ?? [];
$availableProducts = $availableProducts ?? [];
$feedbackCreate = $feedbackCreate ?? null;
$feedbackCancel = $feedbackCancel ?? null;
$feedbackRefund = $feedbackRefund ?? null;
$discountCampaigns = $discountCampaigns ?? [];
$taxRate = (float) ($GLOBALS['config']['app']['tax_rate'] ?? 0.0);
$taxNote = $GLOBALS['config']['app']['tax_note'] ?? "Operazione non soggetta a IVA ai sensi dell'art. 74 DPR 633/72";
?>
<section class="page">
    <header class="page__header">
        <h2>Nuova vendita</h2>
        <p>Cassa rapida con scontrino termico 80&nbsp;mm e gestione immediata di annulli e resi.</p>
    </header>

    <div class="sales-layout">
        <div class="sales-layout__main">
            <?php if ($feedbackCreate && !($feedbackCreate['success'] ?? false)): ?>
                <div class="alert alert--error">
                    <?php foreach ($feedbackCreate['errors'] ?? [] as $error): ?>
                        <p><?= htmlspecialchars($error) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" class="form" id="sale-form">
                <input type="hidden" name="action" value="create_sale">
                <div class="form__grid">
                    <div class="form__group">
                        <label for="customer_name">Cliente (opzionale)</label>
                        <input type="text" name="customer_name" id="customer_name" placeholder="Nome cliente">
                    </div>
                    <div class="form__group">
                        <label for="payment_method">Pagamento</label>
                        <select name="payment_method" id="payment_method">
                            <option value="Contanti">Contanti</option>
                            <option value="Carta">Carta</option>
                            <option value="POS">POS</option>
                        </select>
                    </div>
                    <div class="form__group">
                        <label for="discount">Sconto (€)</label>
                        <input type="number" step="0.01" min="0" name="discount" id="discount" value="0">
                    </div>
                    <div class="form__group">
                        <label for="discount_campaign_id">Campagna sconto</label>
                        <select name="discount_campaign_id" id="discount_campaign_id" data-discount-campaign>
                            <option value="">Nessuna</option>
                            <?php foreach ($discountCampaigns as $campaign): ?>
                                <?php
                                    $campaignId = (int) ($campaign['id'] ?? 0);
                                    $type = strtolower((string) ($campaign['type'] ?? 'Fixed')) === 'percent' ? 'percent' : 'fixed';
                                    $value = number_format((float) ($campaign['value'] ?? 0), 2, '.', '');
                                    $labelParts = [
                                        (string) ($campaign['name'] ?? ''),
                                        $type === 'percent'
                                            ? number_format((float) ($campaign['value'] ?? 0), 2, ',', '.') . '%'
                                            : '€ ' . number_format((float) ($campaign['value'] ?? 0), 2, ',', '.')
                                    ];
                                ?>
                                <option value="<?= $campaignId ?>" data-type="<?= $type ?>" data-value="<?= $value ?>">
                                    <?= htmlspecialchars(implode(' • ', array_filter($labelParts))) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="muted" data-discount-campaign-note></small>
                    </div>
                    <div class="form__group">
                        <label>Regime IVA</label>
                        <input type="hidden" name="vat" value="<?= number_format($taxRate, 2, '.', '') ?>">
                        <p class="muted"><?= htmlspecialchars($taxNote) ?></p>
                    </div>
                </div>

                <section class="page__section">
                    <header class="section__header">
                        <h3>Articoli</h3>
                        <button type="button" class="btn btn--secondary" data-action="add-item">Aggiungi riga</button>
                    </header>

                    <div class="form__group">
                        <label for="barcode_input">Scansione barcode ICCID</label>
                        <input type="text" id="barcode_input" placeholder="Scannerizza qui l'ICCID" autocomplete="off" list="iccids_list">
                        <small>Scannerizza il barcode: l'ICCID viene associato alla prima riga libera.</small>
                    </div>

                    <?php if ($availableOffers !== []): ?>
                        <div class="form__group">
                            <label for="quick_offer_select">Offerte rapide</label>
                            <select id="quick_offer_select" data-offer-select>
                                <option value="">Seleziona un'offerta</option>
                                <?php foreach ($availableOffers as $offer): ?>
                                    <?php
                                        $title = (string) ($offer['title'] ?? '');
                                        $price = number_format((float) ($offer['price'] ?? 0), 2, '.', '');
                                        $provider = (string) ($offer['provider_name'] ?? '');
                                        $description = (string) ($offer['description'] ?? '');
                                        $labelPieces = array_filter([
                                            $title,
                                            $provider !== '' ? $provider : null,
                                            '€ ' . number_format((float) ($offer['price'] ?? 0), 2, ',', '.'),
                                        ]);
                                    ?>
                                    <option
                                        value="<?= (int) ($offer['id'] ?? 0) ?>"
                                        data-title="<?= htmlspecialchars($title) ?>"
                                        data-price="<?= htmlspecialchars($price) ?>"
                                        data-provider="<?= htmlspecialchars($provider) ?>"
                                        data-description="<?= htmlspecialchars($description) ?>"
                                    ><?= htmlspecialchars(implode(' • ', $labelPieces)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="muted">La selezione inserisce automaticamente una riga articolo con i dati dell'offerta.</small>
                        </div>
                    <?php endif; ?>

                    <datalist id="iccids_list">
                        <?php foreach ($availableIccid as $sim): ?>
                            <option value="<?= htmlspecialchars((string) $sim['iccid']) ?>" data-id="<?= (int) $sim['id'] ?>" data-provider="<?= htmlspecialchars((string) $sim['provider_name']) ?>">
                                <?= htmlspecialchars((string) $sim['iccid']) ?> (<?= htmlspecialchars((string) $sim['provider_name']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </datalist>

                    <div class="table-wrapper">
                        <table class="table" id="items-table">
                            <thead>
                                <tr>
                                    <th>ICCID</th>
                                    <th>Descrizione</th>
                                    <th>Prezzo (€)</th>
                                    <th>Q.tà</th>
                                    <th>Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="item-row">
                                    <td>
                                        <div class="table-field">
                                            <select name="item_iccid[]" class="table-field__input" data-iccids>
                                                <option value="">--</option>
                                                <?php foreach ($availableIccid as $sim): ?>
                                                    <option value="<?= (int) $sim['id'] ?>">
                                                        <?= htmlspecialchars((string) $sim['iccid']) ?> (<?= htmlspecialchars((string) $sim['provider_name']) ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <input type="hidden" name="item_iccid_code[]" value="">
                                    </td>
                                    <td>
                                        <div class="table-field">
                                            <input type="text" name="item_description[]" placeholder="Descrizione" class="table-field__input">
                                        </div>
                                    </td>
                                    <td>
                                        <div class="table-field">
                                            <input type="number" step="0.01" min="0" name="item_price[]" required class="table-field__input table-field__input--number">
                                        </div>
                                    </td>
                                    <td>
                                        <div class="table-field table-field--quantity">
                                            <input type="number" min="1" value="1" name="item_quantity[]" class="table-field__input table-field__input--number">
                                        </div>
                                    </td>
                                    <td><button type="button" class="btn btn--icon" data-action="remove-item">✖</button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="page__section">
                    <header class="section__header">
                        <h3>Prodotti di catalogo</h3>
                        <button type="button" class="btn btn--secondary" data-action="add-product-item">Aggiungi prodotto</button>
                    </header>
                    <p class="muted">I prezzi sono comprensivi di IVA. La percentuale viene applicata automaticamente alle righe prodotto.</p>
                    <?php if ($availableProducts === []): ?>
                        <p class="muted">Nessun prodotto attivo: aggiungi articoli dal catalogo Prodotti.</p>
                    <?php endif; ?>

                    <div class="form__group">
                        <label for="product_barcode_input">Scansione barcode prodotto</label>
                        <input type="text" id="product_barcode_input" placeholder="Scannerizza qui il barcode prodotto" autocomplete="off" list="products_barcodes">
                        <small>Il barcode identifica il prodotto attivo e lo inserisce nella prima riga disponibile.</small>
                    </div>

                    <datalist id="products_barcodes">
                        <?php foreach ($availableProducts as $product): ?>
                            <?php
                                $barcode = isset($product['barcode']) ? trim((string) $product['barcode']) : '';
                                if ($barcode === '') {
                                    continue;
                                }
                            ?>
                            <option value="<?= htmlspecialchars($barcode) ?>"
                                data-id="<?= (int) ($product['id'] ?? 0) ?>"
                                data-name="<?= htmlspecialchars((string) ($product['name'] ?? '')) ?>"
                                data-price="<?= htmlspecialchars(number_format((float) ($product['price'] ?? 0.0), 2, '.', '')) ?>"
                                data-tax="<?= htmlspecialchars(number_format((float) ($product['tax_rate'] ?? 0.0), 2, '.', '')) ?>"
                                data-sku="<?= htmlspecialchars((string) ($product['sku'] ?? '')) ?>"
                            ><?= htmlspecialchars((string) ($product['name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </datalist>

                    <div class="table-wrapper">
                        <table class="table" id="products-table">
                            <thead>
                                <tr>
                                    <th>Prodotto</th>
                                    <th>Descrizione</th>
                                    <th>Prezzo (€)</th>
                                    <th>Q.tà</th>
                                    <th>IVA</th>
                                    <th>Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="product-row">
                                    <td>
                                        <div class="table-field">
                                            <select name="product_id[]" class="table-field__input" data-products-select>
                                                <option value="">--</option>
                                                <?php foreach ($availableProducts as $product): ?>
                                                    <?php
                                                        $productId = (int) ($product['id'] ?? 0);
                                                        $labelParts = [
                                                            (string) ($product['name'] ?? ''),
                                                        ];
                                                        if (!empty($product['sku'])) {
                                                            $labelParts[] = 'SKU ' . (string) $product['sku'];
                                                        }
                                                    ?>
                                                    <option
                                                        value="<?= $productId ?>"
                                                        data-price="<?= htmlspecialchars(number_format((float) ($product['price'] ?? 0.0), 2, '.', '')) ?>"
                                                        data-tax="<?= htmlspecialchars(number_format((float) ($product['tax_rate'] ?? 0.0), 2, '.', '')) ?>"
                                                        data-barcode="<?= htmlspecialchars((string) ($product['barcode'] ?? '')) ?>"
                                                        data-name="<?= htmlspecialchars((string) ($product['name'] ?? '')) ?>"
                                                        data-sku="<?= htmlspecialchars((string) ($product['sku'] ?? '')) ?>"
                                                    ><?= htmlspecialchars(implode(' • ', array_filter($labelParts))) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <input type="hidden" name="product_tax_rate[]" value="">
                                    </td>
                                    <td>
                                        <div class="table-field">
                                            <input type="text" name="product_description[]" placeholder="Descrizione prodotto" class="table-field__input">
                                        </div>
                                    </td>
                                    <td>
                                        <div class="table-field">
                                            <input type="number" step="0.01" min="0" name="product_price[]" class="table-field__input table-field__input--number">
                                        </div>
                                    </td>
                                    <td>
                                        <div class="table-field table-field--quantity">
                                            <input type="number" min="1" value="1" name="product_quantity[]" class="table-field__input table-field__input--number">
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge badge--muted" data-product-tax-label>IVA n/d</span>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn--icon" data-action="remove-product-item">✖</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <footer class="form__footer">
                    <div class="payment-hints">
                        <span>Stampa: 80&nbsp;mm termico</span>
                        <span>Shortcut: scanner + Invio</span>
                    </div>
                    <button type="submit" class="btn btn--primary">Salva e stampa</button>
                </footer>
            </form>
        </div>

        <aside class="sales-layout__side">
            <section class="cash-card">
                <header class="cash-card__header">
                    <h4>Annulla scontrino</h4>
                    <p>Ritira gli articoli e rimetti le SIM a stock.</p>
                </header>
                <?php if ($feedbackCancel): ?>
                    <div class="alert <?= ($feedbackCancel['success'] ?? false) ? 'alert--success' : 'alert--error' ?>">
                        <?php if (($feedbackCancel['success'] ?? false) && isset($feedbackCancel['message'])): ?>
                            <p><?= htmlspecialchars($feedbackCancel['message']) ?></p>
                        <?php endif; ?>
                        <?php foreach ($feedbackCancel['errors'] ?? [] as $error): ?>
                            <p><?= htmlspecialchars($error) ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <form method="post" class="cash-form">
                    <input type="hidden" name="action" value="cancel_sale">
                    <div class="form__group">
                        <label for="cancel_sale_id">Numero scontrino</label>
                        <input type="number" min="1" name="cancel_sale_id" id="cancel_sale_id" required>
                    </div>
                    <div class="form__group">
                        <label for="cancel_reason">Motivazione (opzionale)</label>
                        <textarea name="cancel_reason" id="cancel_reason" rows="2" placeholder="Merce difettosa, errore operatore..."></textarea>
                    </div>
                    <button type="submit" class="btn btn--secondary">Annulla e ripristina stock</button>
                </form>
            </section>

            <section class="cash-card">
                <header class="cash-card__header">
                    <h4>Reso rapido</h4>
                    <p>Gestisci resi totali o parziali e aggiorna automaticamente inventario e audit.</p>
                </header>
                <?php if ($feedbackRefund): ?>
                    <div class="alert <?= ($feedbackRefund['success'] ?? false) ? 'alert--success' : 'alert--error' ?>">
                        <?php if (($feedbackRefund['success'] ?? false) && isset($feedbackRefund['message'])): ?>
                            <p><?= htmlspecialchars($feedbackRefund['message']) ?></p>
                        <?php endif; ?>
                        <?php foreach ($feedbackRefund['errors'] ?? [] as $error): ?>
                            <p><?= htmlspecialchars($error) ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <form method="post" class="cash-form" data-refund-form>
                    <input type="hidden" name="action" value="refund_sale">
                    <div class="form__group">
                        <label for="refund_sale_id">Numero scontrino</label>
                        <div class="form__inline">
                            <input type="number" min="1" name="refund_sale_id" id="refund_sale_id" required data-refund-sale-id>
                            <button type="button" class="btn btn--primary" data-action="load-sale">Carica dettagli</button>
                        </div>
                    </div>
                    <div class="refund-feedback" data-refund-feedback></div>
                    <div class="refund-items" data-refund-items hidden>
                        <div class="refund-items__summary" data-refund-summary>
                            <p class="refund-items__headline">
                                Scontrino #<span data-refund-sale></span>
                                <span class="badge badge--muted" data-refund-status></span>
                            </p>
                            <p>Totale originario: € <span data-refund-total></span></p>
                            <p>Resi registrati: € <span data-refund-refunded></span> · Crediti emessi: € <span data-refund-credited></span></p>
                            <p class="refund-items__customer" data-refund-customer hidden>
                                Cliente: <span data-refund-customer-name></span>
                            </p>
                        </div>
                        <div class="table-wrapper table-wrapper--embedded">
                            <table class="table table--compact">
                                <thead>
                                    <tr>
                                        <th>Articolo</th>
                                        <th>Q.tà da reso</th>
                                        <th>Tipo</th>
                                        <th>Nota riga</th>
                                    </tr>
                                </thead>
                                <tbody data-refund-items-body></tbody>
                            </table>
                        </div>
                        <small class="refund-items__hint">Imposta la quantità da rimborsare per ogni articolo. Lascia a 0 per escluderlo.</small>
                    </div>
                    <div class="form__group">
                        <label for="refund_note">Note reso</label>
                        <textarea name="refund_note" id="refund_note" rows="2" placeholder="Motivo restituzione"></textarea>
                    </div>
                    <button type="submit" class="btn btn--secondary">Registra reso</button>
                </form>
            </section>
        </aside>
    </div>
</section>

<template id="refund-item-row-template">
    <tr class="refund-item-row">
        <td>
            <input type="hidden" name="refund_item_id[]" data-field="id" value="0">
            <div class="refund-item-row__title" data-field="info"></div>
            <div class="refund-item-row__meta" data-field="meta"></div>
        </td>
        <td>
            <div class="refund-item-row__qty">
                <input type="number" min="0" value="0" name="refund_item_quantity[]" data-field="quantity">
                <small data-field="available"></small>
            </div>
        </td>
        <td>
            <select name="refund_item_type[]" data-field="type">
                <option value="Refund">Rimborso</option>
                <option value="Credit">Nota di credito</option>
            </select>
        </td>
        <td>
            <input type="text" name="refund_item_note[]" placeholder="Motivo riga (opzionale)" data-field="note">
        </td>
    </tr>
</template>
