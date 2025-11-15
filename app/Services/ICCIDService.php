<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Validator;
use PDO;
use PDOException;

final class ICCIDService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listStock(?string $status = null): array
    {
        if ($status !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT iccid_stock.*, providers.name AS provider_name FROM iccid_stock
                 JOIN providers ON providers.id = iccid_stock.provider_id
                 WHERE status = :status ORDER BY iccid_stock.created_at DESC'
            );
            $stmt->execute([':status' => $status]);
        } else {
            $stmt = $this->pdo->query(
                'SELECT iccid_stock.*, providers.name AS provider_name FROM iccid_stock
                 JOIN providers ON providers.id = iccid_stock.provider_id
                 ORDER BY iccid_stock.created_at DESC'
            );
        }

        return $stmt->fetchAll();
    }

    /**
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   pagination: array{page:int, per_page:int, total:int, pages:int}
     * }
     */
    public function paginateStock(int $page, int $perPage, ?string $status = null): array
    {
        $page = max($page, 1);
        $perPage = max($perPage, 1);

        $conditions = [];
        $params = [];

        if ($status !== null) {
            $conditions[] = 'status = :status';
            $params[':status'] = $status;
        }

        $where = $conditions === [] ? '' : ('WHERE ' . implode(' AND ', $conditions));

        $countSql = 'SELECT COUNT(*) FROM iccid_stock ' . $where;
        $stmtCount = $this->pdo->prepare($countSql);
        foreach ($params as $key => $value) {
            $stmtCount->bindValue($key, $value);
        }
        $stmtCount->execute();
        $total = (int) $stmtCount->fetchColumn();

        $pages = (int) max((int) ceil($total / $perPage), 1);
        $currentPage = max(1, min($page, $pages));
        $offset = ($currentPage - 1) * $perPage;

        $dataSql = 'SELECT iccid_stock.*, providers.name AS provider_name
                    FROM iccid_stock
                    JOIN providers ON providers.id = iccid_stock.provider_id
                    ' . $where . '
                    ORDER BY iccid_stock.created_at DESC
                    LIMIT :limit OFFSET :offset';

        $stmtData = $this->pdo->prepare($dataSql);
        foreach ($params as $key => $value) {
            $stmtData->bindValue($key, $value);
        }
        $stmtData->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmtData->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmtData->execute();
        $rows = $stmtData->fetchAll();

        return [
            'rows' => $rows,
            'pagination' => [
                'page' => $currentPage,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => $pages,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listProviders(): array
    {
        $stmt = $this->pdo->query('SELECT id, name, reorder_threshold FROM providers ORDER BY name');
        return $stmt->fetchAll();
    }

    /**
     * @return array{inserted:int, errors:array<int, string>}
     */
    public function importFromCsv(string $tmpFile, int $providerId): array
    {
        $errors = [];
        $inserted = 0;

        $fh = fopen($tmpFile, 'r');
        if ($fh === false) {
            throw new \RuntimeException('Impossibile aprire il file CSV.');
        }

        $this->pdo->beginTransaction();
        try {
            $stmtInsert = $this->pdo->prepare(
                "INSERT INTO iccid_stock (iccid, provider_id, status, notes) VALUES (:iccid, :provider, 'InStock', :notes)"
            );

            while (($row = fgetcsv($fh, 1000, ',')) !== false) {
                $iccid = trim($row[0] ?? '');
                $notes = $row[1] ?? null;

                if ($iccid === '' || !Validator::isValidICCID($iccid)) {
                    $errors[] = "ICCID non valido: $iccid";
                    continue;
                }

                try {
                    $stmtInsert->execute([
                        ':iccid' => $iccid,
                        ':provider' => $providerId,
                        ':notes' => $notes,
                    ]);
                    $inserted++;
                } catch (PDOException $exception) {
                    $errors[] = "Errore inserimento $iccid: " . $exception->getMessage();
                }
            }

            $this->pdo->commit();
        } catch (
            \Throwable $exception
        ) {
            $this->pdo->rollBack();
            fclose($fh);
            throw $exception;
        }

        fclose($fh);

        return [
            'inserted' => $inserted,
            'errors' => $errors,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAvailable(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT iccid_stock.*, providers.name AS provider_name
             FROM iccid_stock
             JOIN providers ON providers.id = iccid_stock.provider_id
             WHERE iccid_stock.status = 'InStock'
             ORDER BY iccid_stock.created_at ASC"
        );
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * @return array{success:bool, message:string, error?:string}
     */
    public function addSim(string $iccid, int $providerId, ?string $notes = null): array
    {
        $iccid = trim($iccid);
        if ($iccid === '' || !Validator::isValidICCID($iccid)) {
            return [
                'success' => false,
                'message' => 'ICCID non valido. Inserire 19-20 cifre.',
                'error' => 'ICCID non valido',
            ];
        }

        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO iccid_stock (iccid, provider_id, status, notes) VALUES (:iccid, :provider, 'InStock', :notes)"
            );
            $stmt->execute([
                ':iccid' => $iccid,
                ':provider' => $providerId,
                ':notes' => $notes,
            ]);
        } catch (PDOException $exception) {
            return [
                'success' => false,
                'message' => 'Errore durante il salvataggio dell\'ICCID.',
                'error' => $exception->getMessage(),
            ];
        }

        return [
            'success' => true,
            'message' => 'SIM aggiunta correttamente al magazzino.',
        ];
    }
}
