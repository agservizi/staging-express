<?php
declare(strict_types=1);

require __DIR__ . '/../config/database.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/../app/';
    if (str_starts_with($class, $prefix)) {
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $path = $baseDir . $relative . '.php';
        if (file_exists($path)) {
            require $path;
        }
    }
});

use App\Services\StockMonitorService;

$pdo = Database::getConnection();
$config = $GLOBALS['config'] ?? [];
$alertsConfig = $config['alerts'] ?? [];
$alertEmail = $alertsConfig['email'] ?? null;
$resendApiKey = $alertsConfig['resend_api_key'] ?? null;
$resendFrom = $alertsConfig['resend_from'] ?? null;
$logPath = __DIR__ . '/../storage/logs/stock_alerts.log';

$monitor = new StockMonitorService($pdo, $alertEmail, $logPath, $resendApiKey, $resendFrom);
$result = $monitor->checkThresholds();

echo 'Operatori analizzati: ' . $result['checked'] . PHP_EOL;
echo 'Alert creati: ' . $result['created'] . PHP_EOL;
echo 'Alert aggiornati: ' . $result['updated'] . PHP_EOL;
echo 'Alert risolti: ' . $result['resolved'] . PHP_EOL;
