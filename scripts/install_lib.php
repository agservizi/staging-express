<?php
declare(strict_types=1);

use PDO;

if (!class_exists('AppInstaller')) {
    final class AppInstaller
    {
        /**
         * @param array<string, mixed> $dbConfig
         * @param array<string, mixed> $options
         * @return array{success:bool,messages:array<int,string>,errors:array<int,string>,graphics:array<int,string>}
         */
        public static function run(array $dbConfig, array $options): array
        {
            $messages = [];
            $errors = [];
            $graphics = [];

            try {
                if (!isset($dbConfig['dsn'], $dbConfig['user'], $dbConfig['pass'])) {
                    throw new \RuntimeException('Configurazione database incompleta.');
                }

                $dsnParts = self::parseDsn((string) $dbConfig['dsn']);
                $dbName = $dsnParts['dbname'] ?? null;
                if ($dbName === null || $dbName === '') {
                    throw new \RuntimeException('Il DSN deve contenere il parametro dbname.');
                }

                $optionsPdo = $dbConfig['options'] ?? [];
                $optionsPdo[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
                if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
                    $optionsPdo[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = true;
                }

                $serverDsn = self::ensureCharset(self::buildServerDsn((string) $dbConfig['dsn']));
                $pdoServer = new PDO($serverDsn, (string) $dbConfig['user'], (string) $dbConfig['pass'], $optionsPdo);
                $messages[] = 'Connessione al server MySQL riuscita.';

                if (!empty($options['force'])) {
                    $pdoServer->exec('DROP DATABASE IF EXISTS `' . $dbName . '`');
                    $messages[] = "Database '$dbName' eliminato (--force).";
                }

                $pdoServer->exec('CREATE DATABASE IF NOT EXISTS `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
                $messages[] = "Database '$dbName' pronto.";

                $pdo = new PDO(self::ensureCharset((string) $dbConfig['dsn']), (string) $dbConfig['user'], (string) $dbConfig['pass'], $optionsPdo);
                $messages[] = 'Connessione al database riuscita.';

                $schemaPath = realpath(__DIR__ . '/../migrations/create_db.sql');
                if ($schemaPath === false || !is_file($schemaPath)) {
                    throw new \RuntimeException('File migrations/create_db.sql non trovato.');
                }
                $createdCount = self::applySqlFile($pdo, $schemaPath, $messages);
                $messages[] = $createdCount > 0
                    ? 'Schema importato correttamente.'
                    : 'Schema già allineato; nessuna tabella da creare.';

                if (empty($options['skip_admin'])) {
                    $adminUser = (string) ($options['admin_user'] ?? 'admin');
                    $adminPass = $options['admin_pass'] ?? null;
                    if (!is_string($adminPass) || $adminPass === '') {
                        throw new \RuntimeException('Password admin mancante. Specifica --admin-pass o abilita skip_admin.');
                    }
                    $adminName = (string) ($options['admin_name'] ?? 'Administrator');
                    $messages[] = self::createAdminUser($pdo, $adminUser, $adminPass, $adminName);
                }

                self::enforceDirectories([
                    __DIR__ . '/../storage',
                    __DIR__ . '/../storage/uploads',
                    __DIR__ . '/../logs',
                ]);
                $messages[] = 'Cartelle storage/logs verificate.';

                self::prepareMigrationsTable($pdo);
                $messages[] = 'Tabella schema_migrations pronta.';

                $appliedDuringInstall = self::applyPendingMigrations($pdo, $messages);
                if ($appliedDuringInstall > 0) {
                    $messages[] = "Migrazioni aggiuntive applicate: $appliedDuringInstall";
                }

                $graphics = self::verifyGraphics();
            } catch (\Throwable $exception) {
                $errors[] = $exception->getMessage();
            }

            return [
                'success' => $errors === [],
                'messages' => $messages,
                'errors' => $errors,
                'graphics' => $graphics,
            ];
        }

        /**
         * @param array<string, mixed> $dbConfig
         * @return array{success:bool,messages:array<int,string>,errors:array<int,string>,graphics:array<int,string>}
         */
        public static function runUpgrade(array $dbConfig): array
        {
            $messages = [];
            $errors = [];
            $graphics = [];

            try {
                if (!isset($dbConfig['dsn'], $dbConfig['user'], $dbConfig['pass'])) {
                    throw new \RuntimeException('Configurazione database incompleta.');
                }

                $optionsPdo = $dbConfig['options'] ?? [];
                $optionsPdo[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
                if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
                    $optionsPdo[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = true;
                }

                $pdo = new PDO(self::ensureCharset((string) $dbConfig['dsn']), (string) $dbConfig['user'], (string) $dbConfig['pass'], $optionsPdo);
                $messages[] = 'Connessione al database riuscita.';

                self::prepareMigrationsTable($pdo);
                $messages[] = 'Tabella schema_migrations pronta.';

                $applied = self::applyPendingMigrations($pdo, $messages);
                if ($applied === 0) {
                    $messages[] = 'Database già aggiornato; nessuna nuova migrazione applicata.';
                }

                $graphics = self::verifyGraphics();
            } catch (\Throwable $exception) {
                $errors[] = $exception->getMessage();
            }

            return [
                'success' => $errors === [],
                'messages' => $messages,
                'errors' => $errors,
                'graphics' => $graphics,
            ];
        }

        /**
         * @return array<int, string>
         */
        public static function verifyGraphics(): array
        {
            $checks = [
                'public/assets/css/styles.css' => 'Stili principali',
                'public/assets/js/app.js' => 'Script interattivi',
                'views/layout.php' => 'Template layout',
                'views/dashboard.php' => 'Dashboard view',
                'views/iccid_list.php' => 'Lista ICCID',
                'views/sales_create.php' => 'Modulo vendita',
            ];

            $report = ['Verifica grafica e viste:'];
            foreach ($checks as $relative => $label) {
                $fullPath = realpath(__DIR__ . '/../' . $relative);
                $status = ($fullPath !== false && is_file($fullPath)) ? 'OK' : 'MANCANTE';
                $report[] = sprintf(' - %-20s : %s', $label, $status);
            }

            return $report;
        }

        /**
         * @return array<string, string>
         */
        private static function parseDsn(string $dsn): array
        {
            $result = [];
            if (!str_contains($dsn, ':')) {
                return $result;
            }
            [, $rest] = explode(':', $dsn, 2);
            $segments = array_filter(explode(';', $rest), static fn(string $seg): bool => $seg !== '');
            foreach ($segments as $segment) {
                [$key, $value] = array_map('trim', explode('=', $segment, 2));
                $result[strtolower($key)] = $value;
            }
            return $result;
        }

        private static function buildServerDsn(string $dsn): string
        {
            if (!str_contains($dsn, ':')) {
                return $dsn;
            }
            [$driver, $rest] = explode(':', $dsn, 2);
            $segments = array_filter(explode(';', $rest), static fn(string $seg): bool => $seg !== '' && !str_starts_with($seg, 'dbname='));
            return $driver . ':' . implode(';', $segments);
        }

        private static function ensureCharset(string $dsn): string
        {
            return str_contains($dsn, 'charset=') ? $dsn : ($dsn . (str_ends_with($dsn, ';') ? '' : ';') . 'charset=utf8mb4');
        }

        private static function createAdminUser(PDO $pdo, string $username, string $password, string $fullName): string
        {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :username');
            $stmt->execute([':username' => $username]);
            if ((int) $stmt->fetchColumn() > 0) {
                return "Utente admin '$username' già presente.";
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $roleId = self::retrieveAdminRoleId($pdo);

            $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, role_id, fullname) VALUES (:u, :p, :r, :f)');
            $stmt->execute([
                ':u' => $username,
                ':p' => $hash,
                ':r' => $roleId,
                ':f' => $fullName,
            ]);

            return "Utente admin '$username' creato.";
        }

        private static function retrieveAdminRoleId(PDO $pdo): int
        {
            $stmt = $pdo->prepare('SELECT id FROM roles WHERE name = :name LIMIT 1');
            $stmt->execute([':name' => 'admin']);
            $roleId = $stmt->fetchColumn();
            if ($roleId === false) {
                throw new \RuntimeException('Ruolo admin non trovato dopo l\'import dello schema.');
            }
            return (int) $roleId;
        }

        /**
         * @param array<int, string> $paths
         */
        private static function enforceDirectories(array $paths): void
        {
            foreach ($paths as $path) {
                if (!is_dir($path)) {
                    mkdir($path, 0775, true);
                }
            }
        }

        private static function prepareMigrationsTable(PDO $pdo): void
        {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS schema_migrations (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    filename VARCHAR(255) NOT NULL UNIQUE,
                    checksum CHAR(64) NOT NULL,
                    executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
            );
        }

        /**
         * @return array<int, string>
         */
        private static function listUpgradeMigrations(): array
        {
            $directory = realpath(__DIR__ . '/../migrations');
            if ($directory === false) {
                return [];
            }

            $files = glob($directory . '/*.sql');
            if ($files === false) {
                return [];
            }

            $filtered = array_filter($files, static function (string $file): bool {
                return basename($file) !== 'create_db.sql';
            });

            natcasesort($filtered);

            return array_values($filtered);
        }

        private static function applySqlFile(PDO $pdo, string $filePath, array &$messages): int
        {
            if (!is_file($filePath)) {
                throw new \RuntimeException('File SQL mancante: ' . $filePath);
            }

            $sql = file_get_contents($filePath);
            if ($sql === false) {
                throw new \RuntimeException('Impossibile leggere il file SQL: ' . $filePath);
            }

            $statements = self::splitSqlStatements($sql);
            $executed = 0;
            foreach ($statements as $statement) {
                $trimmed = trim($statement);
                if ($trimmed === '') {
                    continue;
                }
                $pdo->exec($trimmed);
                $executed++;
            }

            $messages[] = sprintf('Applicato file SQL %s (%d statement).', basename($filePath), $executed);

            return $executed;
        }

        private static function applyPendingMigrations(PDO $pdo, array &$messages): int
        {
            $migrations = self::listUpgradeMigrations();
            if ($migrations === []) {
                return 0;
            }

            $executed = [];
            $stmt = $pdo->query('SELECT filename, checksum FROM schema_migrations');
            if ($stmt !== false) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (!empty($row['filename'])) {
                        $executed[$row['filename']] = (string) ($row['checksum'] ?? '');
                    }
                }
            }

            $applyCount = 0;
            foreach ($migrations as $filePath) {
                $basename = basename($filePath);
                $checksum = self::computeFileChecksum($filePath);

                if (isset($executed[$basename])) {
                    if ($executed[$basename] !== $checksum) {
                        throw new \RuntimeException('La migrazione ' . $basename . ' risulta già registrata con checksum differente.');
                    }
                    continue;
                }

                self::applySqlFile($pdo, $filePath, $messages);

                $insert = $pdo->prepare(
                    'INSERT INTO schema_migrations (filename, checksum) VALUES (:filename, :checksum)'
                );
                $insert->execute([
                    ':filename' => $basename,
                    ':checksum' => $checksum,
                ]);

                $messages[] = 'Registrata migrazione ' . $basename . '.';
                $applyCount++;
            }

            return $applyCount;
        }

        private static function splitSqlStatements(string $sql): array
        {
            $lines = preg_split('/;\s*(?:\r?\n|$)/', $sql);
            return $lines === false ? [] : $lines;
        }

        private static function computeFileChecksum(string $filePath): string
        {
            $contents = file_get_contents($filePath);
            if ($contents === false) {
                throw new \RuntimeException('Impossibile calcolare checksum per ' . $filePath);
            }

            return hash('sha256', $contents);
        }
    }
}
