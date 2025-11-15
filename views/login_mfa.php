<?php
declare(strict_types=1);

/** @var array<int, string> $errors */
/** @var array{username:string, expires_in:int}|null $pending */
$appName = $appName ?? 'Gestionale Telefonia';
$pending = $pending ?? null;
$username = $pending['username'] ?? 'utente';
$expiresIn = isset($pending['expires_in']) ? max(0, (int) $pending['expires_in']) : 0;
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verifica MFA - <?= htmlspecialchars($appName) ?></title>
    <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body class="login-body">
    <div class="login-shell">
    <section class="login-hero">
            <div class="login-hero__decor"></div>
            <div class="login-hero__brand">
                <span class="login-hero__badge">Accesso protetto</span>
                <h1 class="login-hero__logo">Coresuite <span>Express</span></h1>
                <p class="login-hero__subtitle">Conferma il secondo fattore per completare l'accesso.</p>
            </div>
            <ul class="login-hero__features">
                <li>Codice temporaneo valido 30 secondi</li>
                <li>Generato da Google Authenticator o app compatibile</li>
                <li>Recupero possibile tramite codici di backup</li>
            </ul>
        </section>
        <section class="login-card">
            <header class="login-card__header">
                <h1>Autenticazione a due fattori</h1>
                <p>Inserisci il codice a 6 cifre generato per <strong><?= htmlspecialchars($username) ?></strong>.</p>
            </header>

            <?php if (!empty($errors)): ?>
                <div class="alert alert--error">
                    <?php foreach ($errors as $error): ?>
                        <p><?= htmlspecialchars($error) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="index.php?page=login_mfa" class="login-form">
                <input type="hidden" name="action" value="verify">
                <div class="form__group">
                    <label for="mfa_code">Codice di verifica</label>
                    <input type="text" name="mfa_code" id="mfa_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="10" autocomplete="one-time-code" required autofocus>
                    <p class="form__help">Hai circa <?= (int) max(1, ceil($expiresIn / 30)) ?> tentativi prima di dover reinserire le credenziali.</p>
                </div>
                <button type="submit" class="btn btn--primary btn--full">Conferma accesso</button>
                <button type="submit" name="action" value="cancel" class="btn btn--secondary btn--full">Annulla e torna al login</button>
            </form>
            <p class="login-card__footer">Per problemi con il codice contatta l'amministratore o utilizza un codice di recupero.</p>
        </section>
    </div>
</body>
</html>
