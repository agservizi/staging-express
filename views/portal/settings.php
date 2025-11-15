<?php
$feedbackPassword = $data['feedbackPassword'] ?? null;
?>
<section class="portal-section">
    <header class="portal-section__header">
        <h2>Impostazioni account</h2>
    </header>
    <?php if ($feedbackPassword !== null): ?>
        <div class="portal-alert portal-alert--<?= ($feedbackPassword['success'] ?? false) ? 'success' : 'error' ?>">
            <p><?= htmlspecialchars($feedbackPassword['message'] ?? 'Operazione completata.') ?></p>
            <?php foreach ($feedbackPassword['errors'] ?? [] as $error): ?>
                <p><?= htmlspecialchars($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <form method="post" class="portal-form portal-form--narrow">
        <input type="hidden" name="action" value="update_password">
        <label>
            Password attuale
            <input type="password" name="current_password" required autocomplete="current-password">
        </label>
        <label>
            Nuova password
            <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
        </label>
        <button type="submit" class="portal-button portal-button--primary">Aggiorna password</button>
    </form>
    <p class="portal-hint">Suggerimento: usa almeno 8 caratteri, includendo lettere e numeri.</p>
</section>
