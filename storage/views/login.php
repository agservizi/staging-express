<?php
declare(strict_types=1);

/** @var array<int, string> $errors */
$appName = $appName ?? 'Gestionale Telefonia';
$oldInput = $oldInput ?? ['username' => '', 'remember_me' => false];
$oldUsername = htmlspecialchars((string) ($oldInput['username'] ?? ''), ENT_QUOTES, 'UTF-8');
$rememberChecked = !empty($oldInput['remember_me']);
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Accedi - <?= htmlspecialchars($appName) ?></title>
    <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body class="login-body">
    <div class="login-shell">
        <section class="login-hero">
            <div class="login-hero__brand">
                <span class="login-hero__logo">Coresuite <span>Express</span></span>
                <p class="login-hero__subtitle">La console unificata per store telefonici e corner retail.</p>
            </div>
            <ul class="login-hero__features">
                <li>Monitoraggio stock SIM con soglie dinamiche</li>
                <li>Dashboard vendite con filtri smart</li>
                <li>Gestione resi e audit centralizzati</li>
            </ul>
        </section>
        <section class="login-card">
            <header class="login-card__header">
                <h1>Benvenuto</h1>
                <p>Accedi a <?= htmlspecialchars($appName) ?> con le tue credenziali aziendali.</p>
            </header>

            <?php if (!empty($errors)): ?>
                <div class="alert alert--error">
                    <?php foreach ($errors as $error): ?>
                        <p><?= htmlspecialchars($error) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="index.php?page=login" class="login-form">
                <div class="form__group">
                    <label for="username">Nome utente</label>
                    <input type="text" name="username" id="username" value="<?= $oldUsername ?>" required autofocus>
                </div>
                <div class="form__group">
                    <label for="password">Password</label>
                    <input type="password" name="password" id="password" required>
                </div>
                <div class="login-form__options">
                    <label class="checkbox">
                        <input type="checkbox" name="remember_me" value="1" <?= $rememberChecked ? 'checked' : '' ?>>
                        <span>Ricordami su questo dispositivo</span>
                    </label>
                    <span class="login-form__help">Problemi di accesso? Contatta l'amministratore.</span>
                </div>
                <button type="submit" class="btn btn--primary btn--full">Accedi</button>
            </form>
            <p class="login-card__footer">Accesso protetto e tracciato per garantirti compliance e sicurezza.</p>
        </section>
    </div>
</body>
</html>
