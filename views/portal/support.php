<?php
$requests = $data['requests'] ?? [];
$pagination = $data['pagination'] ?? ['page' => 1, 'per_page' => 10, 'total' => 0, 'pages' => 1];
$filters = $data['filters'] ?? ['status' => null, 'per_page' => 10];
$feedbackSupport = $data['feedbackSupport'] ?? null;
?>
<section class="portal-section">
    <header class="portal-section__header">
        <h2>Richieste di supporto</h2>
        <form method="get" class="portal-filters">
            <input type="hidden" name="view" value="support">
            <label>
                Stato
                <select name="status">
                    <option value="">Tutti</option>
                    <?php foreach (['Open' => 'Aperte', 'InProgress' => 'In lavorazione', 'Completed' => 'Chiuse', 'Cancelled' => 'Annullate'] as $value => $label): ?>
                        <option value="<?= $value ?>"<?= ($filters['status'] ?? '') === $value ? ' selected' : '' ?>><?= $label ?></option>
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
            <button type="submit" class="portal-button">Filtra</button>
        </form>
    </header>

    <?php if ($requests === []): ?>
        <p class="portal-empty">Non hai ancora inviato richieste di supporto.</p>
    <?php else: ?>
        <div class="portal-table-wrapper">
            <table class="portal-table">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Oggetto</th>
                    <th>Tipo</th>
                    <th>Stato</th>
                    <th>Ultimo aggiornamento</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($requests as $request): ?>
                    <tr>
                        <td>#<?= (int) $request['id'] ?></td>
                        <td><?= htmlspecialchars((string) $request['subject']) ?></td>
                        <td><?= htmlspecialchars((string) $request['type']) ?></td>
                        <td><span class="portal-badge portal-badge--<?= portal_badge_class((string) $request['status']) ?>"><?= htmlspecialchars((string) $request['status']) ?></span></td>
                        <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $request['updated_at']))) ?></td>
                        <td><a class="portal-link" href="index.php?view=support_detail&amp;request_id=<?= (int) $request['id'] ?>">Dettagli</a></td>
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
                    <a class="portal-button portal-button--ghost" href="<?= portal_paginate_link('support', $currentPage - 1, $filters) ?>">Precedente</a>
                <?php endif; ?>
                <?php if ($currentPage < $pagesTotal): ?>
                    <a class="portal-button portal-button--ghost" href="<?= portal_paginate_link('support', $currentPage + 1, $filters) ?>">Successiva</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</section>

<section class="portal-section">
    <header class="portal-section__header">
        <h2>Apri una nuova richiesta</h2>
    </header>
    <?php if ($feedbackSupport !== null): ?>
        <div class="portal-alert portal-alert--<?= ($feedbackSupport['success'] ?? false) ? 'success' : 'error' ?>">
            <p><?= htmlspecialchars($feedbackSupport['message'] ?? 'Operazione completata.') ?></p>
            <?php foreach ($feedbackSupport['errors'] ?? [] as $error): ?>
                <p><?= htmlspecialchars($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <form method="post" class="portal-form">
        <input type="hidden" name="action" value="create_support">
        <div class="portal-form__grid">
            <label>
                Tipo di assistenza
                <select name="type">
                    <option value="Support">Supporto tecnico</option>
                    <option value="Booking">Prenotazione appuntamento</option>
                </select>
            </label>
            <label>
                Slot preferito (facoltativo)
                <input type="datetime-local" name="preferred_slot">
            </label>
        </div>
        <label>
            Oggetto
            <input type="text" name="subject" required placeholder="Es. Richiesta duplicato documento">
        </label>
        <label>
            Messaggio
            <textarea name="message" rows="5" required placeholder="Descrivi in dettaglio la richiesta"></textarea>
        </label>
        <button type="submit" class="portal-button portal-button--primary">Invia richiesta</button>
    </form>
</section>
