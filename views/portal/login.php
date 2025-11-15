<?php
$feedbackLogin = $feedbackLogin ?? null;
$prefillEmail = $prefillEmail ?? '';
$prefillPassword = $prefillPassword ?? '';
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Area Clienti · Accesso</title>
    <link rel="stylesheet" href="../assets/css/portal.css?v=1">
</head>
<body class="portal-auth">
<div class="portal-auth__card">
    <header class="portal-auth__header">
        <h1>Coresuite Express</h1>
        <p>Accedi all'area clienti per consultare acquisti e supporto.</p>
    </header>
    <?php if ($feedbackLogin !== null): ?>
        <div class="portal-alert portal-alert--error">
            <?php foreach ($feedbackLogin['errors'] ?? [] as $error): ?>
                <p><?= htmlspecialchars($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <form method="post" class="portal-form portal-form--narrow">
        <input type="hidden" name="action" value="login">
        <label>
            Email
            <input type="email" name="email" autocomplete="email" value="<?= htmlspecialchars($prefillEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
        </label>
        <label>
            Password
            <input type="password" name="password" autocomplete="current-password" value="<?= htmlspecialchars($prefillPassword, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
        </label>
        <label class="portal-checkbox">
            <input type="checkbox" name="remember" value="1"> Ricordami per 14 giorni
        </label>
        <button type="submit" class="portal-button portal-button--primary">Accedi</button>
    </form>
    <footer class="portal-auth__footer">
        <p>Hai ricevuto un invito? <a href="index.php?view=activate">Attiva il tuo account</a></p>
        <p><a href="index.php?view=privacy">Informativa privacy</a></p>
    </footer>
</div>
</body>
</html>
