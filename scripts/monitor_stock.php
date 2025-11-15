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

$providerStats = $result['providers'] ?? ['checked' => 0, 'created' => 0, 'updated' => 0, 'resolved' => 0];
$productStats = $result['products'] ?? ['checked' => 0, 'created' => 0, 'updated' => 0, 'resolved' => 0];

echo 'Operatori analizzati: ' . $providerStats['checked'] . PHP_EOL;
echo 'Alert operatori creati: ' . $providerStats['created'] . PHP_EOL;
echo 'Alert operatori aggiornati: ' . $providerStats['updated'] . PHP_EOL;
echo 'Alert operatori risolti: ' . $providerStats['resolved'] . PHP_EOL;
echo str_repeat('-', 32) . PHP_EOL;
echo 'Prodotti analizzati: ' . $productStats['checked'] . PHP_EOL;
echo 'Alert prodotti creati: ' . $productStats['created'] . PHP_EOL;
echo 'Alert prodotti aggiornati: ' . $productStats['updated'] . PHP_EOL;
echo 'Alert prodotti risolti: ' . $productStats['resolved'] . PHP_EOL;
