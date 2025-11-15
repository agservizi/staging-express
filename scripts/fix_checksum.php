<?php
declare(strict_types=1);

use PDO;

require __DIR__ . '/../config/database.php';

$filename = $argv[1] ?? '';
if ($filename === '') {
    fwrite(STDERR, "Usage: php scripts/fix_checksum.php <migration_filename>\n");
    exit(1);
}

$pdo = Database::getConnection();

$stmt = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE filename = :filename');
$stmt->execute([':filename' => $filename]);
$current = $stmt->fetchColumn();

if ($current === false) {
    fwrite(STDERR, "Migration not found.\n");
    exit(1);
}

$newChecksum = strtolower((string) $current);
if ((string) $current === $newChecksum) {
    fwrite(STDOUT, "Checksum already normalized.\n");
    exit(0);
}

$update = $pdo->prepare('UPDATE schema_migrations SET checksum = :checksum WHERE filename = :filename');
$update->execute([
    ':checksum' => $newChecksum,
    ':filename' => $filename,
]);

echo "Updated checksum for {$filename} to {$newChecksum}.\n";
