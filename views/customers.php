<?php
declare(strict_types=1);

/**
 * @var array<int, array<string, mixed>> $customers
 * @var array{page:int, per_page:int, total:int, total_pages:int, has_prev:bool, has_next:bool}|null $pagination
 * @var array<string, mixed>|null $editingCustomer
 * @var callable $buildPageUrl
 */
$pageTitle = 'Clienti';
$customers = $customers ?? [];
$pagination = $pagination ?? [
    'page' => 1,
    'per_page' => 10,
    'total' => count($customers),
    'total_pages' => 1,
    'has_prev' => false,
    'has_next' => false,
];
$editingCustomer = $editingCustomer ?? null;
$searchTerm = $searchTerm ?? '';
$perPage = $perPage ?? (int) ($pagination['per_page'] ?? 10);
$buildPageUrl = isset($buildPageUrl) && is_callable($buildPageUrl)
    ? $buildPageUrl
    : static fn (int $pageNo, string $search, int $perPageValue): string => 'index.php?page=customers';

$editingId = $editingCustomer !== null ? (int) ($editingCustomer['id'] ?? 0) : 0;
$currentPage = (int) ($pagination['page'] ?? 1);
$totalPages = (int) ($pagination['total_pages'] ?? 1);
$totalCustomers = (int) ($pagination['total'] ?? count($customers));

$searchQueryParams = static function (array $extra = []) use ($searchTerm, $currentPage, $perPage): string {
    $params = array_merge([
        'page' => 'customers',
    ], $extra);

    if ($searchTerm !== '') {
        $params['search'] = $searchTerm;
    }
    if ($currentPage > 1) {
        $params['page_no'] = $currentPage;
    }
    if ($perPage !== 10) {
        $params['per_page'] = $perPage;
    }

    return 'index.php?' . http_build_query($params);
};
?>
<section class="page">
    <header class="page__header">
        <h2>Anagrafica clienti</h2>
        <p class="muted">Gestisci i contatti registrati, aggiorna i dati e tieni traccia delle note operative associate alle vendite.</p>
    </header>
    <section class="page__section">
        <form method="get" class="filters-bar">
            <input type="hidden" name="page" value="customers">
            <div class="filters-bar__row">
                <div class="form__group">
                    <label for="customer_search">Ricerca rapida</label>
                    <input type="text" name="search" id="customer_search" value="<?= htmlspecialchars($searchTerm) ?>" placeholder="Nome, email, telefono o codice fiscale">
                </div>
                <div class="form__group">
                    <label for="customer_per_page">Risultati per pagina</label>
                    <select name="per_page" id="customer_per_page">
                        <?php foreach ([10, 20, 30, 50] as $option): ?>
                            <option value="<?= $option ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= $option ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="filters-bar__actions">
                <button type="submit" class="btn btn--primary">Applica filtri</button>
                <?php if ($searchTerm !== '' || $perPage !== 10): ?>
                    <a class="btn btn--secondary" href="index.php?page=customers">Reset filtri</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <div class="customers-layout">
        <div class="customers-layout__form">
            <form method="post" class="form">
                <input type="hidden" name="action" value="<?= $editingCustomer ? 'update_customer' : 'create_customer' ?>">
                <?php if ($editingCustomer): ?>
                    <input type="hidden" name="customer_id" value="<?= $editingId ?>">
                <?php endif; ?>
                <input type="hidden" name="search_term" value="<?= htmlspecialchars($searchTerm) ?>">
                <input type="hidden" name="page_no" value="<?= $currentPage ?>">
                <input type="hidden" name="per_page" value="<?= $perPage ?>">

                <h3><?= $editingCustomer ? 'Modifica cliente' : 'Nuovo cliente' ?></h3>
                <p class="muted">I dati salvati saranno disponibili direttamente in cassa al momento della vendita.</p>

                <div class="form__grid">
                    <div class="form__group">
                        <label for="customer_fullname">Nome completo *</label>
                        <input type="text" name="fullname" id="customer_fullname" value="<?= htmlspecialchars($editingCustomer['fullname'] ?? '') ?>" required>
                    </div>
                    <div class="form__group">
                        <label for="customer_email">Email</label>
                        <input type="email" name="email" id="customer_email" value="<?= htmlspecialchars($editingCustomer['email'] ?? '') ?>" placeholder="nome@azienda.it">
                    </div>
                    <div class="form__group">
                        <label for="customer_phone">Telefono</label>
                        <input type="text" name="phone" id="customer_phone" value="<?= htmlspecialchars($editingCustomer['phone'] ?? '') ?>" placeholder="+39...">
                    </div>
                    <div class="form__group">
                        <label for="customer_tax_code">Codice fiscale / P. IVA</label>
                        <input type="text" name="tax_code" id="customer_tax_code" value="<?= htmlspecialchars($editingCustomer['tax_code'] ?? '') ?>" placeholder="CF o PI">
                    </div>
                </div>
                <div class="form__group">
                    <label for="customer_note">Note operative</label>
                    <textarea name="note" id="customer_note" rows="3" placeholder="Preferenze, promemoria, condizioni particolari."><?= htmlspecialchars($editingCustomer['note'] ?? '') ?></textarea>
                </div>

                <div class="form__footer">
                    <div class="payment-hints">
                        <?php if ($editingCustomer): ?>
                            <span>Ultimo aggiornamento: <?= !empty($editingCustomer['updated_at']) ? htmlspecialchars(date('d/m/Y H:i', strtotime((string) $editingCustomer['updated_at']))) : 'n/d' ?></span>
                        <?php else: ?>
                            <span>I campi contrassegnati da * sono obbligatori.</span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php if ($editingCustomer): ?>
                            <a class="btn btn--secondary" href="<?= htmlspecialchars($searchQueryParams()) ?>">Annulla</a>
                        <?php endif; ?>
                        <button type="submit" class="btn btn--primary"><?= $editingCustomer ? 'Aggiorna cliente' : 'Salva cliente' ?></button>
                    </div>
                </div>
            </form>
        </div>

        <div class="customers-layout__list">
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Contatti</th>
                            <th>Codice fiscale</th>
                            <th>Note</th>
                            <th>Creato</th>
                            <th class="table__col--actions">Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($customers === []): ?>
                            <tr>
                                <td colspan="6">Nessun cliente registrato<?= $searchTerm !== '' ? ' per la ricerca corrente.' : '.' ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($customers as $customer): ?>
                                <?php
                                    $rowId = (int) ($customer['id'] ?? 0);
                                    $contactParts = [];
                                    if (!empty($customer['email'])) {
                                        $contactParts[] = $customer['email'];
                                    }
                                    if (!empty($customer['phone'])) {
                                        $contactParts[] = $customer['phone'];
                                    }
                                    $noteSnippet = '';
                                    if (!empty($customer['note'])) {
                                        $noteSnippet = (string) $customer['note'];
                                        if (function_exists('mb_strlen') && mb_strlen($noteSnippet) > 60) {
                                            $noteSnippet = mb_substr($noteSnippet, 0, 57) . '…';
                                        } elseif (strlen($noteSnippet) > 60) {
                                            $noteSnippet = substr($noteSnippet, 0, 57) . '…';
                                        }
                                    }
                                    $editLinkParams = [
                                        'page' => 'customers',
                                        'edit' => $rowId,
                                    ];
                                    if ($searchTerm !== '') {
                                        $editLinkParams['search'] = $searchTerm;
                                    }
                                    if ($currentPage > 1) {
                                        $editLinkParams['page_no'] = $currentPage;
                                    }
                                    if ($perPage !== 10) {
                                        $editLinkParams['per_page'] = $perPage;
                                    }
                                    $portalEmail = (string) ($customer['email'] ?? '');
                                    $canResendCredentials = $portalEmail !== '' && filter_var($portalEmail, FILTER_VALIDATE_EMAIL);
                                    $resendTooltip = $canResendCredentials
                                        ? 'Reinvia credenziali area clienti'
                                        : 'Aggiungi un indirizzo email valido per inviare le credenziali';
                                    $inviteTooltip = $canResendCredentials
                                        ? 'Invia invito di attivazione area clienti'
                                        : 'Aggiungi un indirizzo email valido per inviare l\'invito';
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) ($customer['fullname'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars(implode(' · ', $contactParts)) ?: 'n/d' ?></td>
                                    <td><?= htmlspecialchars((string) ($customer['tax_code'] ?? '')) ?: 'n/d' ?></td>
                                    <td><?= htmlspecialchars($noteSnippet ?: '—') ?></td>
                                    <td>
                                        <?php if (!empty($customer['created_at'])): ?>
                                            <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $customer['created_at']))) ?>
                                        <?php else: ?>
                                            n/d
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="micro-actions" role="group" aria-label="Azioni cliente">
                                            <a class="micro-actions__btn micro-actions__btn--edit" href="<?= htmlspecialchars('index.php?' . http_build_query($editLinkParams)) ?>" data-tooltip="Modifica" aria-label="Modifica cliente">
                                                <svg class="micro-actions__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                    <path d="M4 17.25V20h2.75L17.81 8.94l-2.75-2.75L4 17.25z"></path>
                                                    <path d="M20.71 5.04a1 1 0 0 0 0-1.41l-1.34-1.34a1 1 0 0 0-1.41 0L16.38 3.87l2.75 2.75 1.58-1.58z"></path>
                                                </svg>
                                            </a>
                                            <form method="post"<?php if ($canResendCredentials): ?> onsubmit="return confirm('Inviare l\'invito di attivazione al cliente selezionato?');"<?php endif; ?>>
                                                <input type="hidden" name="action" value="send_portal_invitation">
                                                <input type="hidden" name="customer_id" value="<?= $rowId ?>">
                                                <input type="hidden" name="search_term" value="<?= htmlspecialchars($searchTerm) ?>">
                                                <input type="hidden" name="page_no" value="<?= $currentPage ?>">
                                                <input type="hidden" name="per_page" value="<?= $perPage ?>">
                                                <button type="submit" class="micro-actions__btn micro-actions__btn--invite" data-tooltip="<?= htmlspecialchars($inviteTooltip) ?>" aria-label="<?= htmlspecialchars($inviteTooltip) ?>"<?= $canResendCredentials ? '' : ' disabled' ?>>
                                                    <svg class="micro-actions__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                        <path d="M20 4h-3.17l-1.41-1.41A2 2 0 0 0 14.17 2H9.83a2 2 0 0 0-1.41.59L7 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 14H4V6h4.05l1.41-1.41.09-.09h4.9l.09.09L15.95 6H20zm-8-9a3 3 0 1 0 3 3 3 3 0 0 0-3-3zm0 8a6 6 0 0 0-5.33-3H6a1 1 0 0 0 0 2h.67A4 4 0 0 1 10 18h4a4 4 0 0 1 3.33-2H18a1 1 0 0 0 0-2h-.67A6 6 0 0 0 12 17z"></path>
                                                    </svg>
                                                </button>
                                            </form>
                                            <form method="post"<?php if ($canResendCredentials): ?> onsubmit="return confirm('Rigenerare e inviare nuove credenziali al cliente?');"<?php endif; ?>>
                                                <input type="hidden" name="action" value="resend_portal_credentials">
                                                <input type="hidden" name="customer_id" value="<?= $rowId ?>">
                                                <input type="hidden" name="search_term" value="<?= htmlspecialchars($searchTerm) ?>">
                                                <input type="hidden" name="page_no" value="<?= $currentPage ?>">
                                                <input type="hidden" name="per_page" value="<?= $perPage ?>">
                                                <button type="submit" class="micro-actions__btn micro-actions__btn--email" data-tooltip="<?= htmlspecialchars($resendTooltip) ?>" aria-label="<?= htmlspecialchars($resendTooltip) ?>"<?= $canResendCredentials ? '' : ' disabled' ?>>
                                                    <svg class="micro-actions__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                        <path d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 2v.01L12 13 4 6.01V6h16zM4 18V8.24l7.4 6.17a1 1 0 0 0 1.2 0L20 8.24V18H4z"></path>
                                                    </svg>
                                                </button>
                                            </form>
                                            <form method="post" onsubmit="return confirm('Eliminare definitivamente il cliente selezionato?');">
                                                <input type="hidden" name="action" value="delete_customer">
                                                <input type="hidden" name="customer_id" value="<?= $rowId ?>">
                                                <input type="hidden" name="search_term" value="<?= htmlspecialchars($searchTerm) ?>">
                                                <input type="hidden" name="page_no" value="<?= $currentPage ?>">
                                                <input type="hidden" name="per_page" value="<?= $perPage ?>">
                                                <button type="submit" class="micro-actions__btn micro-actions__btn--delete" data-tooltip="Elimina" aria-label="Elimina cliente">
                                                    <svg class="micro-actions__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                        <path d="M9 3a1 1 0 0 0-.94.66L7.62 5H5a1 1 0 0 0 0 2h14a1 1 0 1 0 0-2h-2.62l-.44-1.34A1 1 0 0 0 15 3H9zM6 8v10a3 3 0 0 0 3 3h6a3 3 0 0 0 3-3V8H6zm5 3a1 1 0 0 1 2 0v6a1 1 0 1 1-2 0v-6z"></path>
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

            <?php if ($totalPages > 1): ?>
                <nav class="pagination">
                    <a class="pagination__link <?= ($pagination['has_prev'] ?? false) ? '' : 'is-disabled' ?>" href="<?= ($pagination['has_prev'] ?? false) ? htmlspecialchars($buildPageUrl(1, $searchTerm, $perPage)) : '#' ?>" aria-label="Prima pagina">«</a>
                    <a class="pagination__link <?= ($pagination['has_prev'] ?? false) ? '' : 'is-disabled' ?>" href="<?= ($pagination['has_prev'] ?? false) ? htmlspecialchars($buildPageUrl($currentPage - 1, $searchTerm, $perPage)) : '#' ?>" aria-label="Pagina precedente">‹</a>
                    <span class="pagination__info">Pagina <?= $currentPage ?> di <?= $totalPages ?> (<?= $totalCustomers ?> clienti)</span>
                    <a class="pagination__link <?= ($pagination['has_next'] ?? false) ? '' : 'is-disabled' ?>" href="<?= ($pagination['has_next'] ?? false) ? htmlspecialchars($buildPageUrl($currentPage + 1, $searchTerm, $perPage)) : '#' ?>" aria-label="Pagina successiva">›</a>
                    <a class="pagination__link <?= ($pagination['has_next'] ?? false) ? '' : 'is-disabled' ?>" href="<?= ($pagination['has_next'] ?? false) ? htmlspecialchars($buildPageUrl($totalPages, $searchTerm, $perPage)) : '#' ?>" aria-label="Ultima pagina">»</a>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</section>
