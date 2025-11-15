<?php
declare(strict_types=1);

/**
 * Carica variabili da .env in $_ENV e restituisce la configurazione applicativa.
 */

static $configCache = null;

if ($configCache !== null) {
    return $configCache;
}

$envFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
$envValues = [];
if (is_file($envFile)) {
    $contents = file_get_contents($envFile);
    if ($contents !== false) {
        $parsed = parse_ini_string($contents, false, INI_SCANNER_RAW);
        if (is_array($parsed)) {
            $envValues = $parsed;
        }
    }
}

$getEnv = static function (string $key, ?string $default = null) use ($envValues): ?string {
    if (array_key_exists($key, $envValues)) {
        $value = $envValues[$key];
    } else {
        $value = getenv($key) !== false ? getenv($key) : null;
    }

    if ($value === null) {
        return $default;
    }

    $value = trim((string) $value);
    if ($value === '') {
        return $default;
    }

    if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
        (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
        $value = substr($value, 1, -1);
    }


    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    return $value;
};

$dbHost = $getEnv('DB_HOST');
$dbPort = $getEnv('DB_PORT');
$dbName = $getEnv('DB_NAME');
$dbUser = $getEnv('DB_USER');
$dbPass = $getEnv('DB_PASS', '');

if ($dbHost === null) {
    throw new \RuntimeException('DB_HOST non impostato nel file .env');
}
if ($dbPort === null) {
    throw new \RuntimeException('DB_PORT non impostato nel file .env');
}
if ($dbName === null) {
    throw new \RuntimeException('DB_NAME non impostato nel file .env');
}
if ($dbUser === null) {
    throw new \RuntimeException('DB_USER non impostato nel file .env');
}

$appEnv = $getEnv('APP_ENV', 'development');
$appName = $getEnv('APP_NAME', 'Coresuite');
$taxRate = (float) ($getEnv('APP_TAX_RATE', '0.0'));
$taxNote = $getEnv('APP_TAX_NOTE', "Operazione non soggetta a IVA ai sensi dell'art. 74 DPR 633/72");
$alertEmail = $getEnv('ALERT_EMAIL');
$resendApiKey = $getEnv('RESEND_API_KEY');
$resendFrom = $getEnv('RESEND_FROM', 'alerts@coresuite.test');
$appTimezone = $getEnv('APP_TIMEZONE', 'Europe/Rome');

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    $dbHost,
    (int) $dbPort,
    $dbName
);

$configCache = [
    'env' => $appEnv,
    'db' => [
        'dsn' => $dsn,
        'user' => $dbUser,
        'pass' => $dbPass,
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
        'timezone' => $appTimezone,
    ],
    'app' => [
        'name' => $appName,
        'tax_rate' => $taxRate,
    'tax_note' => $taxNote,
        'timezone' => $appTimezone,
    ],
    'alerts' => [
        'email' => $alertEmail,
        'resend_api_key' => $resendApiKey,
        'resend_from' => $resendFrom,
    ],
];

return $configCache;
