<?php
declare(strict_types=1);

use PDO;

require __DIR__ . '/../config/database.php';

$config = $GLOBALS['config']['db'];

$dsn = $config['dsn'];
$dsn = preg_replace('/dbname=[^;]+;?/', '', $dsn);
if ($dsn === null) {
    throw new \RuntimeException('Impossibile elaborare il DSN.');
}
if (!str_contains($dsn, 'charset=')) {
    $dsn .= (str_ends_with($dsn, ';') ? '' : ';') . 'charset=utf8mb4';
}

$options = $config['options'] ?? [];
$options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
    $options[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = true;
}

$pdo = new PDO($dsn, $config['user'], $config['pass'], $options);

$sqlPath = __DIR__ . '/../migrations/create_db.sql';
$sql = file_get_contents($sqlPath);
if ($sql === false) {
    throw new \RuntimeException('Impossibile leggere il file SQL.');
}

$pdo->exec($sql);

echo "Migrazione completata.\n";
