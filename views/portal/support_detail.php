<?php
$request = $data['request'] ?? [];
?>
<section class="portal-section">
    <header class="portal-section__header">
        <h2>Richiesta #<?= (int) ($request['id'] ?? 0) ?></h2>
        <a class="portal-button portal-button--ghost" href="index.php?view=support">Torna alle richieste</a>
    </header>
    <div class="portal-grid">
        <article class="portal-card">
            <h3>Stato</h3>
            <p class="portal-card__value"><span class="portal-badge portal-badge--<?= portal_badge_class((string) ($request['status'] ?? 'Open')) ?>"><?= htmlspecialchars((string) ($request['status'] ?? 'Open')) ?></span></p>
            <small>Ultimo aggiornamento: <?= htmlspecialchars(isset($request['updated_at']) ? date('d/m/Y H:i', strtotime((string) $request['updated_at'])) : '-') ?></small>
        </article>
        <article class="portal-card">
            <h3>Tipologia</h3>
            <p class="portal-card__value"><?= htmlspecialchars((string) ($request['type'] ?? '-')) ?></p>
            <small>Preferenza: <?= !empty($request['preferred_slot']) ? htmlspecialchars(date('d/m/Y H:i', strtotime((string) $request['preferred_slot']))) : '—' ?></small>
        </article>
    </div>
</section>

<section class="portal-section">
    <header class="portal-section__header">
        <h3>Messaggio</h3>
    </header>
    <article class="portal-message">
        <h4><?= htmlspecialchars((string) ($request['subject'] ?? '')) ?></h4>
        <p><?= nl2br(htmlspecialchars((string) ($request['message'] ?? ''))) ?></p>
    </article>
</section>

<?php if (!empty($request['resolution_note'])): ?>
<section class="portal-section">
    <header class="portal-section__header">
        <h3>Esito</h3>
    </header>
    <article class="portal-message portal-message--resolved">
        <p><?= nl2br(htmlspecialchars((string) $request['resolution_note'])) ?></p>
    </article>
</section>
<?php endif; ?>
