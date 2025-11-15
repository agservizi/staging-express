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
 * @var array<int, array<string, mixed>> $availableCustomers
 * @var array<int, array<string, mixed>> $availableProviders
 * @var array{success:bool,message:string,warnings?:array<int,string>,errors?:array<int,string>}|null $pdaFeedback
 * @var array<string, mixed>|null $pdaPrefill
 */
$pageTitle = 'Nuova vendita';
$availableOffers = $availableOffers ?? [];
$availableProducts = $availableProducts ?? [];
$availableCustomers = $availableCustomers ?? [];
$availableProviders = $availableProviders ?? [];
$feedbackCreate = $feedbackCreate ?? null;
$feedbackCancel = $feedbackCancel ?? null;
$feedbackRefund = $feedbackRefund ?? null;
$pdaFeedback = $pdaFeedback ?? null;
$pdaPrefill = $pdaPrefill ?? null;
$discountCampaigns = $discountCampaigns ?? [];
$taxRate = (float) ($GLOBALS['config']['app']['tax_rate'] ?? 0.0);
$taxNote = $GLOBALS['config']['app']['tax_note'] ?? "Operazione non soggetta a IVA ai sensi dell'art. 74 DPR 633/72";
$pendingReceiptId = isset($_GET['print']) ? max(0, (int) $_GET['print']) : 0;
$shouldOpenPdaImport = $pdaFeedback !== null || $pdaPrefill !== null;
$prefillCustomer = [
    'id' => isset($pdaPrefill['customer_id']) ? (int) $pdaPrefill['customer_id'] : null,
    'name' => isset($pdaPrefill['customer_name']) ? (string) $pdaPrefill['customer_name'] : '',
    'email' => isset($pdaPrefill['customer_email']) ? (string) $pdaPrefill['customer_email'] : '',
    'phone' => isset($pdaPrefill['customer_phone']) ? (string) $pdaPrefill['customer_phone'] : '',
    'tax_code' => isset($pdaPrefill['customer_tax_code']) ? (string) $pdaPrefill['customer_tax_code'] : '',
    'note' => isset($pdaPrefill['customer_note']) ? (string) $pdaPrefill['customer_note'] : '',
    'provider_id' => isset($pdaPrefill['provider']['id']) ? (int) $pdaPrefill['provider']['id'] : null,
];
?>
<section class="page">
    <header class="page__header">
        <h2>Nuova vendita</h2>
        <p>Cassa rapida con scontrino termico 80&nbsp;mm e gestione immediata di annulli e resi.</p>
    </header>

    <section class="page__section">
        <div class="settings-accordion">
            <article class="settings-accordion__item" data-accordion data-open="<?= $shouldOpenPdaImport ? 'true' : 'false' ?>">
                <button type="button" class="settings-accordion__toggle" data-accordion-toggle aria-expanded="<?= $shouldOpenPdaImport ? 'true' : 'false' ?>">
                    <span class="settings-accordion__title">
                        <span>Importa PDA <span class="muted">(facoltativo)</span></span>
                    </span>
                    <span class="settings-accordion__icon" aria-hidden="true"></span>
                </button>
                <div class="settings-accordion__content" data-accordion-content <?= $shouldOpenPdaImport ? '' : 'hidden' ?>>
                    <p class="muted">Carica la pratica di attivazione per compilare automaticamente cliente e articoli.</p>

                    <?php if ($pdaFeedback !== null): ?>
                        <div class="alert <?= ($pdaFeedback['success'] ?? false) ? 'alert--success' : 'alert--error' ?>">
                            <p><?= htmlspecialchars((string) ($pdaFeedback['message'] ?? 'Operazione completata.')) ?></p>
                            <?php foreach ($pdaFeedback['errors'] ?? [] as $error): ?>
                                <p><?= htmlspecialchars((string) $error) ?></p>
                            <?php endforeach; ?>
                            <?php if (!empty($pdaFeedback['warnings'])): ?>
                                <ul class="muted">
                                    <?php foreach ($pdaFeedback['warnings'] as $warning): ?>
                                        <li><?= htmlspecialchars((string) $warning) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" enctype="multipart/form-data" class="form">
                        <input type="hidden" name="action" value="upload_pda">
                        <div class="form__grid">
                            <div class="form__group">
                                <label for="pda_provider_id">Gestore</label>
                                <select name="pda_provider_id" id="pda_provider_id" required>
                                    <option value="">-- Seleziona gestore --</option>
                                    <?php foreach ($availableProviders as $provider): ?>
                                        <?php $providerId = (int) ($provider['id'] ?? 0); ?>
                                        <option value="<?= $providerId ?>" <?= $prefillCustomer['provider_id'] === $providerId ? 'selected' : '' ?>>
                                            <?= htmlspecialchars((string) ($provider['name'] ?? '')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form__group form__group--file">
                                <label for="pda_file">File PDA</label>
                                <div class="file-upload" data-file-upload>
                                    <input class="file-upload__input" type="file" name="pda_file" id="pda_file" accept=".pdf,.txt,.csv,.json" required>
                                    <button type="button" class="btn btn--secondary file-upload__button" data-file-upload-trigger>Scegli file</button>
                                    <span class="file-upload__name" data-file-upload-name data-placeholder="Nessun file selezionato">Nessun file selezionato</span>
                                </div>
                                <small class="muted">Formati supportati: PDF, TXT, CSV, JSON. Iliad escluso.</small>
                            </div>
                        </div>
                        <button type="submit" class="btn btn--secondary">Importa PDA</button>
                    </form>
                </div>
            </article>
        </div>
    </section>

    <div class="sales-layout">
        <div class="sales-layout__main">
            <?php if ($feedbackCreate && !($feedbackCreate['success'] ?? false)): ?>
                <div class="alert alert--error">
                    <?php foreach ($feedbackCreate['errors'] ?? [] as $error): ?>
                        <p><?= htmlspecialchars($error) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($feedbackCreate && ($feedbackCreate['success'] ?? false)): ?>
                <div class="alert alert--success">
                    <p>Vendita registrata correttamente. L'anteprima di stampa si è aperta automaticamente.</p>
                </div>
            <?php endif; ?>

            <form method="post" class="form" id="sale-form">
                <input type="hidden" name="action" value="create_sale">
                <div class="form__grid">
                    <div class="form__group">
                        <label for="customer_id">Cliente registrato</label>
                        <select name="customer_id" id="customer_id">
                            <option value="">-- Nessun cliente --</option>
                            <?php foreach ($availableCustomers as $customer): ?>
                                <?php
                                    $customerId = (int) ($customer['id'] ?? 0);
                                    $fullName = (string) ($customer['fullname'] ?? '');
                                    $email = (string) ($customer['email'] ?? '');
                                    $phone = (string) ($customer['phone'] ?? '');
                                    $labelParts = array_filter([
                                        $fullName,
                                        $email !== '' ? $email : null,
                                        $phone !== '' ? $phone : null,
                                    ]);
                                ?>
                                <option value="<?= $customerId ?>" <?= $prefillCustomer['id'] === $customerId ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(implode(' • ', $labelParts)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="muted">Seleziona un cliente già in anagrafica, altrimenti compila i campi liberi.</small>
                    </div>
                    <div class="form__group">
                        <label for="customer_name">Cliente libero</label>
                        <input type="text" name="customer_name" id="customer_name" placeholder="Nome cliente" value="<?= htmlspecialchars($prefillCustomer['name']) ?>">
                        <small class="muted">Usato solo se il cliente non è presente in anagrafica.</small>
                    </div>
                    <div class="form__group">
                        <label for="customer_email">Email cliente</label>
                        <input type="email" name="customer_email" id="customer_email" placeholder="nome@cliente.it" value="<?= htmlspecialchars($prefillCustomer['email']) ?>">
                    </div>
                    <div class="form__group">
                        <label for="customer_phone">Telefono cliente</label>
                        <input type="text" name="customer_phone" id="customer_phone" placeholder="+39..." value="<?= htmlspecialchars($prefillCustomer['phone']) ?>">
                    </div>
                    <div class="form__group">
                        <label for="customer_tax_code">Codice fiscale / P.IVA</label>
                        <input type="text" name="customer_tax_code" id="customer_tax_code" placeholder="RSSMRA80A01F205X" value="<?= htmlspecialchars($prefillCustomer['tax_code']) ?>">
                    </div>
                    <div class="form__group">
                        <label for="customer_note">Nota cliente</label>
                        <input type="text" name="customer_note" id="customer_note" placeholder="Nota su documento o contatto" value="<?= htmlspecialchars($prefillCustomer['note']) ?>">
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
                                            <input type="number" step="0.01" min="0" name="item_price[]" class="table-field__input table-field__input--number" data-item-price>
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
                                $stockQuantity = array_key_exists('stock_quantity', $product) ? $product['stock_quantity'] : null;
                                $stockData = $stockQuantity === null ? null : (int) $stockQuantity;
                            ?>
                            <option value="<?= htmlspecialchars($barcode) ?>"
                                data-id="<?= (int) ($product['id'] ?? 0) ?>"
                                data-name="<?= htmlspecialchars((string) ($product['name'] ?? '')) ?>"
                                data-price="<?= htmlspecialchars(number_format((float) ($product['price'] ?? 0.0), 2, '.', '')) ?>"
                                data-tax="<?= htmlspecialchars(number_format((float) ($product['tax_rate'] ?? 0.0), 2, '.', '')) ?>"
                                data-sku="<?= htmlspecialchars((string) ($product['sku'] ?? '')) ?>"
                                <?php if ($stockData !== null): ?> data-stock="<?= $stockData ?>"<?php endif; ?>
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
                                    <th>IVA / Stock</th>
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
                                                        $stockQuantity = array_key_exists('stock_quantity', $product) ? $product['stock_quantity'] : null;
                                                        $stockLabel = $stockQuantity === null ? 'Stock n/d' : 'Stock ' . (int) $stockQuantity;
                                                        $labelParts = [
                                                            (string) ($product['name'] ?? ''),
                                                        ];
                                                        if (!empty($product['sku'])) {
                                                            $labelParts[] = 'SKU ' . (string) $product['sku'];
                                                        }
                                                        $labelParts[] = $stockLabel;
                                                        $stockData = $stockQuantity === null ? null : (int) $stockQuantity;
                                                    ?>
                                                    <option
                                                        value="<?= $productId ?>"
                                                        data-price="<?= htmlspecialchars(number_format((float) ($product['price'] ?? 0.0), 2, '.', '')) ?>"
                                                        data-tax="<?= htmlspecialchars(number_format((float) ($product['tax_rate'] ?? 0.0), 2, '.', '')) ?>"
                                                        data-barcode="<?= htmlspecialchars((string) ($product['barcode'] ?? '')) ?>"
                                                        data-name="<?= htmlspecialchars((string) ($product['name'] ?? '')) ?>"
                                                        data-sku="<?= htmlspecialchars((string) ($product['sku'] ?? '')) ?>"
                                                        <?php if ($stockData !== null): ?> data-stock="<?= $stockData ?>"<?php endif; ?>
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
                                        <div class="table-field__badges">
                                            <span class="badge badge--muted" data-product-tax-label>IVA n/d</span>
                                            <span class="badge badge--muted" data-product-stock-label>Stock n/d</span>
                                        </div>
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

            <?php if ($feedbackCreate && ($feedbackCreate['success'] ?? false)): ?>
                <div class="page__actions">
                    <a class="btn btn--link" href="index.php?page=sales_list">Vai alla lista vendite</a>
                </div>
            <?php endif; ?>
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

    <?php if ($pdaPrefill !== null): ?>
        <script>
            window.PdaPrefill = <?= json_encode($pdaPrefill, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        </script>
    <?php endif; ?>
</section>

<?php if ($pendingReceiptId > 0): ?>
<script>
    (function() {
        const receiptUrl = 'print_receipt.php?sale_id=<?= $pendingReceiptId ?>';
        const triggerModal = () => {
            window.dispatchEvent(new CustomEvent('app:openReceipt', { detail: { url: receiptUrl } }));
            try {
                const cleaned = new URL(window.location.href);
                cleaned.searchParams.delete('print');
                window.history.replaceState({}, document.title, cleaned);
            } catch (error) {
                console.error('Impossibile aggiornare l\'URL', error);
            }
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => {
                window.setTimeout(triggerModal, 40);
            }, { once: true });
        } else {
            window.setTimeout(triggerModal, 0);
        }
    })();
</script>
<?php endif; ?>

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
