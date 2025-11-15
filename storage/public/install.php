<?php


session_start();

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../scripts/install_lib.php';

if (!isset($GLOBALS['config']['db'])) {
    http_response_code(500);
    echo 'Configurazione database non trovata.';
    exit;
}

$dbConfig = $GLOBALS['config']['db'];
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$messages = [];
$errors = [];
$warnings = [];
$graphics = [];

$defaults = [
    'admin_user' => 'admin',
    'admin_name' => 'Administrator',
];

$form = [
    'admin_user' => trim((string) ($_POST['admin_user'] ?? $defaults['admin_user'])),
    'admin_pass' => '',
    'admin_name' => trim((string) ($_POST['admin_name'] ?? $defaults['admin_name'])),
    'force' => isset($_POST['force']),
    'skip_admin' => isset($_POST['skip_admin']),
];
$operation = isset($_POST['operation']) ? (string) $_POST['operation'] : 'install';
if (!in_array($operation, ['install', 'upgrade'], true)) {
    $operation = 'install';
}

if (!isset($_SESSION['install_token'])) {
    $_SESSION['install_token'] = bin2hex(random_bytes(16));
}
$token = $_SESSION['install_token'];

if ($isPost) {
    if (!hash_equals($token, (string) ($_POST['token'] ?? ''))) {
        $errors[] = 'Token di sicurezza non valido. Ricarica la pagina e riprova.';
    } else {
        if ($operation === 'upgrade') {
            $installResult = AppInstaller::runUpgrade($dbConfig);

            $messages = $installResult['messages'];
            $errors = array_merge($errors, $installResult['errors']);
            $graphics = $installResult['graphics'];

            if ($installResult['success']) {
                $_SESSION['install_token'] = bin2hex(random_bytes(16));
                $token = $_SESSION['install_token'];
            }
        } else {
            if (!$form['skip_admin'] && ($_POST['admin_pass'] ?? '') === '') {
                $errors[] = 'Inserisci una password per l’utente admin oppure abilita "Salta creazione admin".';
            }

            if ($errors === []) {
                $installResult = AppInstaller::run($dbConfig, [
                    'force' => $form['force'],
                    'skip_admin' => $form['skip_admin'],
                    'admin_user' => $form['admin_user'] !== '' ? $form['admin_user'] : $defaults['admin_user'],
                    'admin_pass' => $form['skip_admin'] ? null : (string) ($_POST['admin_pass'] ?? ''),
                    'admin_name' => $form['admin_name'] !== '' ? $form['admin_name'] : $defaults['admin_name'],
                ]);

                $messages = $installResult['messages'];
                $errors = array_merge($errors, $installResult['errors']);
                $graphics = $installResult['graphics'];

                if ($installResult['success']) {
                    $_SESSION['install_token'] = bin2hex(random_bytes(16));
                    $token = $_SESSION['install_token'];
                }
            }
        }
    }
} else {
    $graphics = AppInstaller::verifyGraphics();
}

$requirements = [
    'PHP >= 8.1' => PHP_VERSION_ID >= 80100,
    'Estensione PDO abilitata' => extension_loaded('pdo'),
    'Driver PDO MySQL attivo' => extension_loaded('pdo_mysql'),
];

foreach ($requirements as $label => $ok) {
    if (!$ok) {
        $warnings[] = $label . ' non soddisfatto';
    }
}

if (preg_match('/host=([^;]+)/i', (string) $dbConfig['dsn'], $match)) {
    $dsnHost = $match[1];
} else {
    $dsnHost = 'n/d';
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

?><!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Installazione Gestionale</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        body{background:#f8fafc;}
        .install-page{max-width:760px;margin:48px auto;display:flex;flex-direction:column;gap:24px;}
        .install-card{background:#fff;border-radius:12px;padding:28px;box-shadow:0 12px 32px rgba(15,23,42,.12);}
        .install-card h1,.install-card h2{margin-bottom:12px;}
        .list{border-radius:10px;border:1px solid #e2e8f0;padding:16px;background:#f8fafc;font-size:14px;}
        .list ul{list-style:disc;padding-left:22px;}
        .list li{margin-bottom:6px;}
        .danger{color:#b91c1c;}
        .success{color:#166534;}
        .warning{color:#b45309;}
        .muted{color:#64748b;font-size:13px;}
        .checkbox{display:flex;align-items:center;gap:8px;font-weight:500;color:#1f2937;}
    </style>
</head>
<body>
<div class="install-page">
    <section class="install-card">
        <h1>Installazione Gestionale Telefonia</h1>
    <p class="muted">Esegui la procedura una sola volta. Al termine elimina o rinomina <code>public/install.php</code> per evitare accessi indesiderati.</p>
    <p class="muted">Puoi usare il pulsante "Aggiorna database" per applicare nuove migrazioni senza ricreare il database.</p>
        <form method="post" class="form" autocomplete="off">
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <div class="form__group">
                <label for="admin_user">Username admin</label>
                <input type="text" id="admin_user" name="admin_user" value="<?= h($form['admin_user']) ?>" required>
            </div>
            <div class="form__group">
                <label for="admin_pass">Password admin</label>
                <input type="password" id="admin_pass" name="admin_pass" value="">
                <small>Necessaria salvo selezione di "Salta creazione admin".</small>
            </div>
            <div class="form__group">
                <label for="admin_name">Nome completo operatore</label>
                <input type="text" id="admin_name" name="admin_name" value="<?= h($form['admin_name']) ?>">
            </div>
            <div class="form__group">
                <label class="checkbox">
                    <input type="checkbox" name="force" <?= $form['force'] ? 'checked' : '' ?>> Ricrea database (DROP + CREATE)
                </label>
                <label class="checkbox">
                    <input type="checkbox" name="skip_admin" <?= $form['skip_admin'] ? 'checked' : '' ?>> Salta creazione admin
                </label>
            </div>
            <div class="form__actions" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
                <button type="submit" name="operation" value="install" class="btn btn--primary">Esegui installazione</button>
                <button type="submit" name="operation" value="upgrade" class="btn btn--secondary">Aggiorna database</button>
            </div>
        </form>
    </section>

    <?php if ($messages !== []): ?>
    <section class="install-card">
        <h2>Log installazione</h2>
        <div class="list success">
            <ul>
                <?php foreach ($messages as $message): ?>
                    <li><?= h($message) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
    <section class="install-card">
        <h2>Errori</h2>
        <div class="list danger">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= h($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($warnings !== []): ?>
    <section class="install-card">
        <h2>Avvisi</h2>
        <div class="list warning">
            <ul>
                <?php foreach ($warnings as $warning): ?>
                    <li><?= h($warning) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($graphics !== []): ?>
    <section class="install-card">
        <h2>Verifica grafica</h2>
        <div class="list">
            <ul>
                <?php foreach ($graphics as $line): ?>
                    <li><?= h($line) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>
    <?php endif; ?>

    <section class="install-card">
        <h2>Configurazione corrente</h2>
        <div class="list">
            <ul>
                <li>DSN: <code><?= h((string) $dbConfig['dsn']) ?></code></li>
                <li>Utente DB: <code><?= h((string) $dbConfig['user']) ?></code></li>
                <li>Host: <code><?= h($dsnHost) ?></code></li>
            </ul>
        </div>
    </section>

    <section class="install-card">
        <h2>Checklist rapida</h2>
        <div class="list">
            <ul>
                <li><?= $requirements['PHP >= 8.1'] ? '✅' : '⚠️' ?> PHP 8.1 o superiore</li>
                <li><?= $requirements['Estensione PDO abilitata'] ? '✅' : '⚠️' ?> Estensione PDO</li>
                <li><?= $requirements['Driver PDO MySQL attivo'] ? '✅' : '⚠️' ?> Driver PDO MySQL</li>
            </ul>
        </div>
    </section>
</div>
</body>
</html>
