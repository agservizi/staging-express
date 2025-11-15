<?php
declare(strict_types=1);

use PDO;

require __DIR__ . '/../config/database.php';

$filename = $argv[1] ?? '';
if ($filename === '') {
    fwrite(STDERR, "Usage: php scripts/show_checksum.php <migration_filename>\n");
    exit(1);
}

$pdo = Database::getConnection();
$stmt = $pdo->prepare('SELECT checksum, executed_at FROM schema_migrations WHERE filename = :filename');
$stmt->execute([':filename' => $filename]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row === false) {
    fwrite(STDOUT, "Migration not found in schema_migrations.\n");
    exit(0);
}

printf("filename: %s\nchecksum: %s\nexecuted_at: %s\n", $filename, $row['checksum'], $row['executed_at']);
