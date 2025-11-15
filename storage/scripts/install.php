<?php
declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/install_lib.php';

if (!isset($GLOBALS['config']['db'])) {
    fwrite(STDERR, "Configurazione database non trovata.\n");
    exit(1);
}

$dbConfig = $GLOBALS['config']['db'];
$cliOptions = getopt('', [
    'admin-user::',
    'admin-pass::',
    'admin-name::',
    'skip-admin',
    'force',
    'verify-only',
    'upgrade',
    'upgrade-db',
]);

if (array_key_exists('verify-only', $cliOptions)) {
    $report = AppInstaller::verifyGraphics();
    foreach ($report as $line) {
        echo $line . "\n";
    }
    echo "Verifica completata (modalità only).\n";
    exit(0);
}

if (array_key_exists('upgrade', $cliOptions) || array_key_exists('upgrade-db', $cliOptions)) {
    $result = AppInstaller::runUpgrade($dbConfig);

    foreach ($result['messages'] as $message) {
        echo $message . "\n";
    }
    foreach ($result['graphics'] as $line) {
        echo $line . "\n";
    }

    if (!$result['success']) {
        foreach ($result['errors'] as $error) {
            fwrite(STDERR, $error . "\n");
        }
        exit(1);
    }

    echo "Aggiornamento completato con successo.\n";
    exit(0);
}

$options = [
    'force' => array_key_exists('force', $cliOptions),
    'skip_admin' => array_key_exists('skip-admin', $cliOptions),
    'admin_user' => $cliOptions['admin-user'] ?? 'admin',
    'admin_pass' => $cliOptions['admin-pass'] ?? null,
    'admin_name' => $cliOptions['admin-name'] ?? 'Administrator',
];

if (!$options['skip_admin'] && ($options['admin_pass'] === null || $options['admin_pass'] === '')) {
    fwrite(STDERR, "Specifica --admin-pass=<password> oppure usa --skip-admin.\n");
    exit(1);
}

$result = AppInstaller::run($dbConfig, $options);

foreach ($result['messages'] as $message) {
    echo $message . "\n";
}
foreach ($result['graphics'] as $line) {
    echo $line . "\n";
}

if (!$result['success']) {
    foreach ($result['errors'] as $error) {
        fwrite(STDERR, $error . "\n");
    }
    exit(1);
}

echo "Installazione completata con successo.\n";
exit(0);
