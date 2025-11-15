<?php
declare(strict_types=1);

$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoloadPath)) {
    require_once $autoloadPath;
}

$config = require __DIR__ . '/config.php';
$GLOBALS['config'] = $config;
$timezone = $config['app']['timezone'] ?? 'Europe/Rome';
if (is_string($timezone) && $timezone !== '') {
    try {
        new \DateTimeZone($timezone);
        date_default_timezone_set($timezone);
    } catch (\Throwable $exception) {
        date_default_timezone_set('Europe/Rome');
    }
}
$activeConfig = $GLOBALS['db_config'] ?? $config['db'];
if (!isset($activeConfig['timezone']) || !is_string($activeConfig['timezone']) || $activeConfig['timezone'] === '') {
    $activeConfig['timezone'] = $timezone;
}

final class Database
{
    private static ?PDO $pdo = null;
    private static array $settings = [];
    private static ?string $timezone = null;

    public static function configure(array $settings): void
    {
        self::$settings = $settings;
        $tz = $settings['timezone'] ?? null;
        self::$timezone = is_string($tz) && $tz !== '' ? $tz : null;
    }

    public static function getConnection(): PDO
    {
        if (self::$pdo === null) {
            if (self::$settings === []) {
                throw new RuntimeException('Database non configurato.');
            }

            self::$pdo = new PDO(
                self::$settings['dsn'],
                self::$settings['user'],
                self::$settings['pass'],
                self::$settings['options'] ?? []
            );

            if (self::$timezone !== null) {
                try {
                    $tz = new DateTimeZone(self::$timezone);
                    $timezoneName = $tz->getName();
                    $quotedName = self::$pdo->quote($timezoneName);
                    self::$pdo->exec('SET time_zone = ' . $quotedName);
                } catch (\Throwable $exception) {
                    try {
                        $tz = new DateTimeZone(self::$timezone);
                        $now = new DateTimeImmutable('now', $tz);
                        $offsetSeconds = $tz->getOffset($now);
                        $sign = $offsetSeconds >= 0 ? '+' : '-';
                        $offsetSeconds = abs($offsetSeconds);
                        $hours = intdiv($offsetSeconds, 3600);
                        $minutes = intdiv($offsetSeconds % 3600, 60);
                        $formattedOffset = sprintf('%s%02d:%02d', $sign, $hours, $minutes);
                        self::$pdo->exec('SET time_zone = ' . self::$pdo->quote($formattedOffset));
                    } catch (\Throwable $innerException) {
                        // Ignora se non è possibile impostare il fuso orario sul database.
                    }
                }
            }
        }

        return self::$pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }
}

Database::configure($activeConfig);
