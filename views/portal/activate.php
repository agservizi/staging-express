<?php
$token = $token ?? '';
$feedbackActivation = $feedbackActivation ?? null;
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Attiva il tuo accesso</title>
    <link rel="stylesheet" href="../assets/css/portal.css?v=1">
</head>
<body class="portal-auth">
<div class="portal-auth__card">
    <header class="portal-auth__header">
        <h1>Attiva il tuo account</h1>
        <p>Imposta la password per completare l'accesso all'area clienti.</p>
    </header>
    <?php if ($feedbackActivation !== null): ?>
        <div class="portal-alert portal-alert--<?= ($feedbackActivation['success'] ?? false) ? 'success' : 'error' ?>">
            <p><?= htmlspecialchars($feedbackActivation['message'] ?? '') ?></p>
            <?php foreach ($feedbackActivation['errors'] ?? [] as $error): ?>
                <p><?= htmlspecialchars($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <form method="post" class="portal-form portal-form--narrow">
        <input type="hidden" name="action" value="complete_invitation">
        <label>
            Codice invito
            <input type="text" name="token" value="<?= htmlspecialchars($token) ?>" required>
        </label>
        <label>
            Nuova password
            <input type="password" name="password" minlength="8" required>
        </label>
        <button type="submit" class="portal-button portal-button--primary">Attiva account</button>
    </form>
    <footer class="portal-auth__footer">
        <p>Account già attivo? <a href="index.php?view=login">Torna al login</a></p>
    </footer>
</div>
</body>
</html>
