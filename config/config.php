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

$parseHeaderList = static function (?string $raw): array {
    if ($raw === null) {
        return [];
    }

    $trimmed = trim($raw);
    if ($trimmed === '') {
        return [];
    }

    if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            $headers = [];
            foreach ($decoded as $key => $value) {
                if (is_int($key) && is_array($value)) {
                    $name = array_key_first($value);
                    if ($name !== null) {
                        $headers[(string) $name] = trim((string) $value[$name]);
                    }
                    continue;
                }
                if (!is_string($key)) {
                    continue;
                }
                $headers[$key] = trim((string) $value);
            }

            return $headers;
        }
    }

    $segments = preg_split('/[\r\n,;]+/', $trimmed) ?: [];
    $headers = [];
    foreach ($segments as $segment) {
        $candidate = trim($segment);
        if ($candidate === '') {
            continue;
        }

        if (str_contains($candidate, ':')) {
            [$name, $value] = explode(':', $candidate, 2);
        } elseif (preg_match('/^([^=]+)=(.+)$/', $candidate, $matches) === 1) {
            $name = $matches[1];
            $value = $matches[2];
        } else {
            $name = $candidate;
            $value = '';
        }

        $name = trim((string) $name);
        if ($name === '') {
            continue;
        }

        $headers[$name] = trim((string) $value);
    }

    return $headers;
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
$resendFromName = $getEnv('RESEND_FROM_NAME');
$salesFulfilmentEmail = $getEnv('SALES_FULFILMENT_EMAIL');
$customerPortalUrl = $getEnv('CUSTOMER_PORTAL_URL');
$appTimezone = $getEnv('APP_TIMEZONE', 'Europe/Rome');
$notificationWebhookUrl = $getEnv('NOTIFICATIONS_WEBHOOK_URL');
$notificationWebhookHeaders = $parseHeaderList($getEnv('NOTIFICATIONS_WEBHOOK_HEADERS'));
$notificationQueueDsn = $getEnv('NOTIFICATIONS_QUEUE_DSN');
$notificationQueueExchange = $getEnv('NOTIFICATIONS_QUEUE_EXCHANGE', 'coresuite.notifications');
$notificationQueueRoutingKey = $getEnv('NOTIFICATIONS_QUEUE_ROUTING_KEY', 'event');
$notificationQueueName = $getEnv('NOTIFICATIONS_QUEUE_NAME');
$notificationTopbarLimit = (int) ($getEnv('NOTIFICATIONS_TOPBAR_LIMIT', '10'));
$ssoIssuer = $getEnv('SSO_ISSUER', 'coresuite-express');
$ssoSharedSecret = $getEnv('SSO_SHARED_SECRET');
$ssoTokenTtl = (int) ($getEnv('SSO_TOKEN_TTL', '3600'));

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    $dbHost,
    (int) $dbPort,
    $dbName
);

$notificationQueueConfig = null;
if ($notificationQueueDsn !== null && $notificationQueueDsn !== '') {
    $notificationQueueConfig = [
        'dsn' => $notificationQueueDsn,
        'exchange' => $notificationQueueExchange,
        'routing_key' => $notificationQueueRoutingKey,
        'queue' => $notificationQueueName,
    ];
}

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
        'portal_url' => $customerPortalUrl,
    ],
    'alerts' => [
        'email' => $alertEmail,
        'resend_api_key' => $resendApiKey,
        'resend_from' => $resendFrom,
        'resend_from_name' => $resendFromName,
        'sales_fulfilment_email' => $salesFulfilmentEmail,
    ],
    'notifications' => [
        'webhook_url' => $notificationWebhookUrl,
        'webhook_headers' => $notificationWebhookHeaders,
        'queue' => $notificationQueueConfig,
        'topbar_limit' => $notificationTopbarLimit > 0 ? min($notificationTopbarLimit, 30) : 10,
    ],
    'sso' => [
        'enabled' => is_string($ssoSharedSecret) && $ssoSharedSecret !== '',
        'issuer' => $ssoIssuer,
        'shared_secret' => $ssoSharedSecret,
        'token_ttl' => $ssoTokenTtl > 0 ? $ssoTokenTtl : 3600,
        'code_ttl' => 300,
    ],
];

if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
    $configCache['db']['options'][PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
}

return $configCache;
