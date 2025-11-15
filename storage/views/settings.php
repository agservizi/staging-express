<?php
declare(strict_types=1);

/**
 * @var array<int, array<string, mixed>> $providerInsights
 * @var array<int, array<string, mixed>> $stockAlerts
 * @var array<int, array<string, mixed>> $roles
 * @var array<int, array<string, mixed>> $operators
 * @var array<int, array<string, mixed>> $discountCampaigns
 * @var array<int, array<string, mixed>> $auditLogs
 * @var array{page:int, per_page:int, total:int, pages:int, has_prev:bool, has_next:bool} $auditPagination
 * @var callable $buildAuditPageUrl
 * @var array{success:bool, message:string, error?:string}|null $feedback
 * @var bool $isAdmin
 * @var bool $auditOpen
 * @var array<string, mixed>|null $operatorEdit
 * @var array<string, mixed>|null $operatorEditForm
 * @var bool|null $operatorsOpen
 */
$pageTitle = $pageTitle ?? 'Impostazioni';
$roles = $roles ?? [];
$operators = $operators ?? [];
$discountCampaigns = $discountCampaigns ?? [];
$isAdmin = $isAdmin ?? false;
$operatorEdit = $operatorEdit ?? null;
$operatorEditForm = isset($operatorEditForm) && is_array($operatorEditForm) ? $operatorEditForm : null;
$auditLogs = $auditLogs ?? [];
$auditPagination = $auditPagination ?? [
    'page' => 1,
    'per_page' => 10,
    'total' => count($auditLogs),
    'pages' => 1,
    'has_prev' => false,
    'has_next' => false,
];
$buildAuditPageUrl = $buildAuditPageUrl ?? static fn(int $pageNo): string => 'index.php?page=settings&audit_page=' . max(1, $pageNo);
$auditOpen = $auditOpen ?? false;
$currentUserId = isset($currentUser['id']) ? (int) $currentUser['id'] : 0;
$operatorsOpenProp = $operatorsOpen ?? null;
$inventoryOpen = $feedback !== null && isset($feedback['message']) && stripos((string) $feedback['message'], 'soglia') !== false;
$alertsOpen = !empty($stockAlerts);
$operatorsOpen = is_bool($operatorsOpenProp)
    ? $operatorsOpenProp
    : ($isAdmin && $feedback !== null && ($feedback['success'] ?? false) === false && ! $inventoryOpen);
$campaignsOpen = $feedback !== null && isset($feedback['message']) && strpos((string) $feedback['message'], 'Campagna') !== false;
$auditCurrentPage = max(1, (int) ($auditPagination['page'] ?? 1));
$totalAuditPages = max(1, (int) ($auditPagination['pages'] ?? 1));
$totalAuditEvents = max(0, (int) ($auditPagination['total'] ?? count($auditLogs)));
$hasAuditPrev = (bool) ($auditPagination['has_prev'] ?? ($auditCurrentPage > 1));
$hasAuditNext = (bool) ($auditPagination['has_next'] ?? ($auditCurrentPage < $totalAuditPages));
?>
<section class="page page--settings">
    <header class="page__header">
        <h2>Impostazioni</h2>
        <p>Configura il gestionale per categorie: magazzino, alert e anagrafiche operatori.</p>
    </header>

    <?php if ($feedback !== null): ?>
        <section class="page__section">
            <div class="alert <?= ($feedback['success'] ?? false) ? 'alert--success' : 'alert--error' ?>">
                <p><?= htmlspecialchars($feedback['message']) ?></p>
                <?php if (!empty($feedback['error'])): ?>
                    <p class="muted">Dettaglio: <?= htmlspecialchars((string) $feedback['error']) ?></p>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <div class="settings-accordion" data-accordion-group>
        <article class="settings-accordion__item" data-accordion data-open="<?= $inventoryOpen ? 'true' : 'false' ?>">
            <button type="button" class="settings-accordion__toggle" data-accordion-toggle aria-expanded="<?= $inventoryOpen ? 'true' : 'false' ?>">
                <span class="settings-accordion__title">Magazzino e soglie</span>
                <span class="settings-accordion__icon" aria-hidden="true"></span>
            </button>
            <div class="settings-accordion__content" data-accordion-content <?= $inventoryOpen ? '' : 'hidden' ?>>
                <p class="muted">Definisci il livello minimo di SIM disponibili per ciascun operatore. Al raggiungimento della soglia viene generato un alert visibile in dashboard e via email.</p>
                <div class="table-wrapper table-wrapper--embedded">
                    <table class="table table--compact">
                        <thead>
                            <tr>
                                <th>Operatore</th>
                                <th>Soglia minima</th>
                                <th>Disponibili</th>
                                <th>Media vendite / giorno</th>
                                <th>Copertura stimata</th>
                                <th>Suggerimento riordino</th>
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
                                        <td>
                                            <form method="post" class="inline-form">
                                                <input type="hidden" name="action" value="update_threshold">
                                                <input type="hidden" name="provider_id" value="<?= (int) $insight['provider_id'] ?>">
                                                <div class="table-field table-field--compact">
                                                    <input type="number" min="0" name="reorder_threshold" value="<?= (int) $insight['threshold'] ?>" class="table-field__input table-field__input--number">
                                                </div>
                                                <button type="submit" class="btn btn--secondary btn--small">Salva</button>
                                            </form>
                                        </td>
                                        <td><?= (int) $insight['current_stock'] ?></td>
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
            </div>
        </article>

        <article class="settings-accordion__item" data-accordion data-open="<?= $alertsOpen ? 'true' : 'false' ?>">
            <button type="button" class="settings-accordion__toggle" data-accordion-toggle aria-expanded="<?= $alertsOpen ? 'true' : 'false' ?>">
                <span class="settings-accordion__title">Alert in corso</span>
                <span class="settings-accordion__icon" aria-hidden="true"></span>
            </button>
            <div class="settings-accordion__content" data-accordion-content <?= $alertsOpen ? '' : 'hidden' ?>>
                <?php if (empty($stockAlerts)): ?>
                    <p class="muted">Nessun alert attivo al momento.</p>
                <?php else: ?>
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
                <?php endif; ?>
            </div>
        </article>

        <article class="settings-accordion__item" data-accordion data-open="<?= $campaignsOpen ? 'true' : 'false' ?>">
            <button type="button" class="settings-accordion__toggle" data-accordion-toggle aria-expanded="<?= $campaignsOpen ? 'true' : 'false' ?>">
                <span class="settings-accordion__title">Campagne sconto</span>
                <span class="settings-accordion__icon" aria-hidden="true"></span>
            </button>
            <div class="settings-accordion__content" data-accordion-content <?= $campaignsOpen ? '' : 'hidden' ?>>
                <?php if (!$isAdmin): ?>
                    <p class="muted">Solo gli amministratori possono gestire le campagne sconto.</p>
                <?php else: ?>
                    <div class="settings-operators">
                        <section class="settings-operators__panel">
                            <h4>Crea campagna</h4>
                            <form method="post" class="form settings-form">
                                <input type="hidden" name="action" value="create_discount_campaign">
                                <div class="settings-form__grid">
                                    <div class="settings-form__field">
                                        <label for="campaign_name">Nome</label>
                                        <input type="text" id="campaign_name" name="campaign_name" required>
                                    </div>
                                    <div class="settings-form__field">
                                        <label for="campaign_type">Tipo sconto</label>
                                        <select id="campaign_type" name="campaign_type" required>
                                            <option value="fixed">Importo fisso</option>
                                            <option value="percent">Percentuale</option>
                                        </select>
                                    </div>
                                    <div class="settings-form__field">
                                        <label for="campaign_value">Valore</label>
                                        <input type="number" id="campaign_value" name="campaign_value" min="0" step="0.01" required>
                                    </div>
                                    <div class="settings-form__field">
                                        <label for="campaign_starts_at">Valido dal</label>
                                        <input type="date" id="campaign_starts_at" name="campaign_starts_at">
                                    </div>
                                    <div class="settings-form__field">
                                        <label for="campaign_ends_at">Valido fino al</label>
                                        <input type="date" id="campaign_ends_at" name="campaign_ends_at">
                                    </div>
                                    <div class="settings-form__field">
                                        <label for="campaign_description">Descrizione (opzionale)</label>
                                        <div class="table-field">
                                            <textarea id="campaign_description" name="campaign_description" rows="2" class="table-field__input" placeholder="Note per gli operatori..."></textarea>
                                        </div>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn--primary">Salva campagna</button>
                            </form>
                        </section>

                        <section class="settings-operators__panel">
                            <h4>Campagne attive e archivio</h4>
                            <?php if (empty($discountCampaigns)): ?>
                                <p class="muted">Nessuna campagna configurata.</p>
                            <?php else: ?>
                                <div class="table-wrapper table-wrapper--embedded">
                                    <table class="table table--compact">
                                        <thead>
                                            <tr>
                                                <th>Nome</th>
                                                <th>Tipo</th>
                                                <th>Valore</th>
                                                <th>Validità</th>
                                                <th>Stato</th>
                                                <th>Azioni</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($discountCampaigns as $campaign): ?>
                                                <?php
                                                    $isActive = (int) ($campaign['is_active'] ?? 0) === 1;
                                                    $type = (string) ($campaign['type'] ?? 'Fixed');
                                                    $value = (float) ($campaign['value'] ?? 0);
                                                    $starts = !empty($campaign['starts_at']) ? date('d/m/Y', strtotime((string) $campaign['starts_at'])) : null;
                                                    $ends = !empty($campaign['ends_at']) ? date('d/m/Y', strtotime((string) $campaign['ends_at'])) : null;
                                                ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= htmlspecialchars((string) $campaign['name']) ?></strong>
                                                        <?php if (!empty($campaign['description'])): ?>
                                                            <div class="muted"><?= htmlspecialchars((string) $campaign['description']) ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= $type === 'Percent' ? 'Percentuale' : 'Importo fisso' ?></td>
                                                    <td>
                                                        <?php if ($type === 'Percent'): ?>
                                                            <?= number_format($value, 2, ',', '.') ?>%
                                                        <?php else: ?>
                                                            € <?= number_format($value, 2, ',', '.') ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($starts === null && $ends === null): ?>
                                                            Sempre
                                                        <?php else: ?>
                                                            <?= $starts ?? 'n/d' ?> → <?= $ends ?? 'n/d' ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= $isActive ? 'Attiva' : 'Disattivata' ?></td>
                                                    <td>
                                                        <form method="post" class="inline-form">
                                                            <input type="hidden" name="action" value="toggle_discount_campaign">
                                                            <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
                                                            <input type="hidden" name="target_status" value="<?= $isActive ? '0' : '1' ?>">
                                                            <button type="submit" class="btn btn--secondary btn--small">
                                                                <?= $isActive ? 'Disattiva' : 'Attiva' ?>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </section>
                    </div>
                <?php endif; ?>
            </div>
        </article>

        <article class="settings-accordion__item" data-accordion data-open="<?= ($operatorsOpen ? 'true' : 'false') ?>">
            <button type="button" class="settings-accordion__toggle" data-accordion-toggle aria-expanded="<?= $operatorsOpen ? 'true' : 'false' ?>">
                <span class="settings-accordion__title">Gestione operatori</span>
                <span class="settings-accordion__icon" aria-hidden="true"></span>
            </button>
            <div class="settings-accordion__content" data-accordion-content <?= $operatorsOpen ? '' : 'hidden' ?>>
                <?php if (!$isAdmin): ?>
                    <p class="muted">Solo gli amministratori possono creare o modificare operatori.</p>
                <?php else: ?>
                    <div class="settings-operators">
                        <section class="settings-operators__panel">
                            <h4>Crea un nuovo operatore</h4>
                            <form method="post" class="form settings-form">
                                <input type="hidden" name="action" value="create_operator">
                                <div class="settings-form__grid">
                                    <div class="settings-form__field">
                                        <label for="operator_fullname">Nome completo</label>
                                        <input type="text" id="operator_fullname" name="operator_fullname" required>
                                    </div>
                                    <div class="settings-form__field">
                                        <label for="operator_username">Nome utente</label>
                                        <input type="text" id="operator_username" name="operator_username" minlength="3" required>
                                    </div>
                                    <div class="settings-form__field">
                                        <label for="operator_role">Ruolo</label>
                                        <select id="operator_role" name="operator_role" required>
                                            <option value="">Seleziona...</option>
                                            <?php foreach ($roles as $role): ?>
                                                <option value="<?= (int) $role['id'] ?>"><?= htmlspecialchars((string) $role['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="settings-form__field">
                                        <label for="operator_password">Password</label>
                                        <input type="password" id="operator_password" name="operator_password" minlength="8" required>
                                    </div>
                                    <div class="settings-form__field">
                                        <label for="operator_password_confirmation">Conferma password</label>
                                        <input type="password" id="operator_password_confirmation" name="operator_password_confirmation" minlength="8" required>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn--primary">Crea operatore</button>
                            </form>
                        </section>

                        <section class="settings-operators__panel">
                            <h4>Operatori attivi</h4>
                            <?php if (empty($operators)): ?>
                                <p class="muted">Nessun operatore registrato oltre all'amministratore.</p>
                            <?php else: ?>
                                <div class="table-wrapper table-wrapper--embedded">
                                    <table class="table table--compact">
                                        <thead>
                                            <tr>
                                                <th>Nome</th>
                                                <th>Nome utente</th>
                                                <th>Ruolo</th>
                                                <th>Creato il</th>
                                                <th>Aggiornato il</th>
                                                <th class="table__col--actions">Azioni</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($operators as $operator): ?>
                                                <?php
                                                    $operatorId = (int) ($operator['id'] ?? 0);
                                                    $createdAt = !empty($operator['created_at'])
                                                        ? date('d/m/Y H:i', strtotime((string) $operator['created_at']))
                                                        : 'n/d';
                                                    $updatedAt = !empty($operator['updated_at'])
                                                        ? date('d/m/Y H:i', strtotime((string) $operator['updated_at']))
                                                        : $createdAt;
                                                    $isSelf = $currentUserId === $operatorId;
                                                ?>
                                                <tr>
                                                    <td><?= htmlspecialchars((string) ($operator['fullname'] ?? '')) ?></td>
                                                    <td><?= htmlspecialchars((string) $operator['username']) ?></td>
                                                    <td><?= htmlspecialchars((string) $operator['role_name']) ?></td>
                                                    <td><?= htmlspecialchars($createdAt) ?></td>
                                                    <td><?= htmlspecialchars($updatedAt) ?></td>
                                                    <td class="table__col--actions">
                                                        <div class="table-actions">
                                                            <a class="btn btn--secondary btn--small" href="index.php?page=settings&amp;operators_open=1&amp;edit_operator=<?= $operatorId ?>">Modifica</a>
                                                            <?php if (!$isSelf): ?>
                                                                <form method="post" onsubmit="return confirm('Confermi l\'eliminazione di questo operatore?');">
                                                                    <input type="hidden" name="action" value="delete_operator">
                                                                    <input type="hidden" name="operator_id" value="<?= $operatorId ?>">
                                                                    <button type="submit" class="btn btn--danger btn--small">Elimina</button>
                                                                </form>
                                                            <?php else: ?>
                                                                <span class="badge badge--info">Attivo</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                            <?php if ($operatorEdit !== null): ?>
                                <?php
                                    $editFullname = trim((string) ($operatorEditForm['fullname'] ?? ($operatorEdit['fullname'] ?? '')));
                                    $editUsername = trim((string) ($operatorEditForm['username'] ?? ($operatorEdit['username'] ?? '')));
                                    $editRoleId = (int) ($operatorEditForm['role_id'] ?? ($operatorEdit['role_id'] ?? 0));
                                    $editUpdatedAt = !empty($operatorEdit['updated_at'])
                                        ? date('d/m/Y H:i', strtotime((string) $operatorEdit['updated_at']))
                                        : null;
                                ?>
                                <section class="settings-operators__panel">
                                    <h5>Modifica operatore</h5>
                                    <p class="muted">Aggiorna i dati di <?= htmlspecialchars($editFullname !== '' ? $editFullname : (string) ($operatorEdit['username'] ?? 'operatore')) ?>.<?= $editUpdatedAt !== null ? ' Ultima modifica il ' . htmlspecialchars($editUpdatedAt) . '.' : '' ?></p>
                                    <form method="post" class="form settings-form">
                                        <input type="hidden" name="action" value="update_operator">
                                        <input type="hidden" name="operator_id" value="<?= (int) $operatorEdit['id'] ?>">
                                        <div class="settings-form__grid">
                                            <div class="settings-form__field">
                                                <label for="operator_edit_fullname">Nome completo</label>
                                                <input type="text" id="operator_edit_fullname" name="operator_edit_fullname" value="<?= htmlspecialchars($editFullname) ?>" required>
                                            </div>
                                            <div class="settings-form__field">
                                                <label for="operator_edit_username">Nome utente</label>
                                                <input type="text" id="operator_edit_username" name="operator_edit_username" value="<?= htmlspecialchars($editUsername) ?>" minlength="3" required>
                                            </div>
                                            <div class="settings-form__field">
                                                <label for="operator_edit_role">Ruolo</label>
                                                <select id="operator_edit_role" name="operator_edit_role" required>
                                                    <option value="">Seleziona...</option>
                                                    <?php foreach ($roles as $role): ?>
                                                        <?php $roleId = (int) ($role['id'] ?? 0); ?>
                                                        <option value="<?= $roleId ?>" <?= $roleId === $editRoleId ? 'selected' : '' ?>><?= htmlspecialchars((string) $role['name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="settings-form__field">
                                                <label for="operator_edit_password">Nuova password <span class="muted">(opzionale)</span></label>
                                                <input type="password" id="operator_edit_password" name="operator_edit_password" minlength="8" placeholder="Lascia vuoto per non cambiare">
                                            </div>
                                            <div class="settings-form__field">
                                                <label for="operator_edit_password_confirmation">Conferma password</label>
                                                <input type="password" id="operator_edit_password_confirmation" name="operator_edit_password_confirmation" minlength="8" placeholder="Ripeti la password">
                                            </div>
                                        </div>
                                        <div class="table-actions-inline">
                                            <button type="submit" class="btn btn--primary">Salva modifiche</button>
                                            <a class="btn btn--secondary" href="index.php?page=settings&amp;operators_open=1">Annulla</a>
                                        </div>
                                        <p class="muted">Le password vengono aggiornate solo se inserite e confermate correttamente.</p>
                                    </form>
                                </section>
                            <?php endif; ?>
                        </section>
                    </div>
                <?php endif; ?>
            </div>
        </article>

        <article class="settings-accordion__item" data-accordion data-open="<?= $auditOpen ? 'true' : 'false' ?>">
            <button type="button" class="settings-accordion__toggle" data-accordion-toggle aria-expanded="<?= $auditOpen ? 'true' : 'false' ?>">
                <span class="settings-accordion__title">Registro attività</span>
                <span class="settings-accordion__icon" aria-hidden="true"></span>
            </button>
            <div class="settings-accordion__content" data-accordion-content <?= $auditOpen ? '' : 'hidden' ?>>
                <?php if (empty($auditLogs)): ?>
                    <p class="muted">Nessuna attività registrata negli audit log.</p>
                <?php else: ?>
                    <ul class="activity-list">
                        <?php foreach ($auditLogs as $event): ?>
                            <?php
                                $metaParts = [];
                                if (!empty($event['user'])) {
                                    $metaParts[] = 'Operatore: ' . htmlspecialchars((string) $event['user']);
                                }
                                if (!empty($event['created_at_display'])) {
                                    $metaParts[] = 'Registrato il ' . htmlspecialchars((string) $event['created_at_display']);
                                }
                                $metaText = implode(' • ', $metaParts);
                            ?>
                            <li class="activity-entry">
                                <span class="activity-entry__title"><?= htmlspecialchars((string) $event['action_label']) ?></span>
                                <?php if ($metaText !== ''): ?>
                                    <span class="activity-entry__meta"><?= $metaText ?></span>
                                <?php endif; ?>
                                <?php if (($event['description'] ?? '') !== ''): ?>
                                    <p class="activity-entry__value"><?= nl2br(htmlspecialchars((string) $event['description']), false) ?></p>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($totalAuditPages > 1): ?>
                        <nav class="pagination">
                            <a class="pagination__link <?= $hasAuditPrev ? '' : 'is-disabled' ?>" href="<?= $hasAuditPrev ? htmlspecialchars($buildAuditPageUrl(1)) : '#' ?>" aria-label="Prima pagina">«</a>
                            <a class="pagination__link <?= $hasAuditPrev ? '' : 'is-disabled' ?>" href="<?= $hasAuditPrev ? htmlspecialchars($buildAuditPageUrl($auditCurrentPage - 1)) : '#' ?>" aria-label="Pagina precedente">‹</a>
                            <span class="pagination__info">Pagina <?= $auditCurrentPage ?> di <?= $totalAuditPages ?> (<?= $totalAuditEvents ?> eventi)</span>
                            <a class="pagination__link <?= $hasAuditNext ? '' : 'is-disabled' ?>" href="<?= $hasAuditNext ? htmlspecialchars($buildAuditPageUrl($auditCurrentPage + 1)) : '#' ?>" aria-label="Pagina successiva">›</a>
                            <a class="pagination__link <?= $hasAuditNext ? '' : 'is-disabled' ?>" href="<?= $hasAuditNext ? htmlspecialchars($buildAuditPageUrl($totalAuditPages)) : '#' ?>" aria-label="Ultima pagina">»</a>
                        </nav>
                    <?php else: ?>
                        <p class="muted">Totale <?= $totalAuditEvents ?> eventi nei log.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </article>
    </div>
</section>
