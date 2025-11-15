<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

final class OffersService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listOffers(?string $status = null): array
    {
        if ($status !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT operator_offers.*, providers.name AS provider_name
                 FROM operator_offers
                 LEFT JOIN providers ON providers.id = operator_offers.provider_id
                 WHERE operator_offers.status = :status
                 ORDER BY operator_offers.updated_at DESC'
            );
            $stmt->execute([':status' => $status]);
        } else {
            $stmt = $this->pdo->query(
                'SELECT operator_offers.*, providers.name AS provider_name
                 FROM operator_offers
                 LEFT JOIN providers ON providers.id = operator_offers.provider_id
                 ORDER BY operator_offers.updated_at DESC'
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
    public function paginateOffers(int $page, int $perPage, ?string $status = null): array
    {
        $page = max($page, 1);
        $perPage = max($perPage, 1);

        $conditions = [];
        $params = [];

        if ($status !== null) {
            $conditions[] = 'operator_offers.status = :status';
            $params[':status'] = $status;
        }

        $where = $conditions === [] ? '' : ('WHERE ' . implode(' AND ', $conditions));

        $countSql = 'SELECT COUNT(*) FROM operator_offers ' . $where;
        $stmtCount = $this->pdo->prepare($countSql);
        foreach ($params as $key => $value) {
            $stmtCount->bindValue($key, $value);
        }
        $stmtCount->execute();
        $total = (int) $stmtCount->fetchColumn();

        $pages = (int) max((int) ceil($total / $perPage), 1);
        $currentPage = max(1, min($page, $pages));
        $offset = ($currentPage - 1) * $perPage;

        $dataSql = 'SELECT operator_offers.*, providers.name AS provider_name
                    FROM operator_offers
                    LEFT JOIN providers ON providers.id = operator_offers.provider_id
                    ' . $where . '
                    ORDER BY operator_offers.updated_at DESC
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
    public function listActiveOffers(): array
    {
                $stmt = $this->pdo->query(
                        'SELECT operator_offers.*, providers.name AS provider_name
                         FROM operator_offers
                         LEFT JOIN providers ON providers.id = operator_offers.provider_id
                         WHERE operator_offers.status = "Active"
                             AND (operator_offers.valid_from IS NULL OR operator_offers.valid_from <= CURRENT_DATE())
                             AND (operator_offers.valid_to IS NULL OR operator_offers.valid_to >= CURRENT_DATE())
                         ORDER BY providers.name, operator_offers.title'
                );

                return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getOffer(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT operator_offers.*, providers.name AS provider_name
             FROM operator_offers
             LEFT JOIN providers ON providers.id = operator_offers.provider_id
             WHERE operator_offers.id = :id'
        );
        $stmt->execute([':id' => $id]);
        $offer = $stmt->fetch();

        return $offer === false ? null : $offer;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{success:bool, errors?:array<int, string>, offer_id?:int}
     */
    public function createOffer(array $data): array
    {
        $validation = $this->validate($data);
        if ($validation['errors'] !== []) {
            return ['success' => false, 'errors' => $validation['errors']];
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO operator_offers (provider_id, title, description, price, status, valid_from, valid_to)
             VALUES (:provider_id, :title, :description, :price, :status, :valid_from, :valid_to)'
        );
        $stmt->execute([
            ':provider_id' => $validation['provider_id'],
            ':title' => $validation['title'],
            ':description' => $validation['description'],
            ':price' => $validation['price'],
            ':status' => $validation['status'],
            ':valid_from' => $validation['valid_from'],
            ':valid_to' => $validation['valid_to'],
        ]);

        return [
            'success' => true,
            'offer_id' => (int) $this->pdo->lastInsertId(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{success:bool, errors?:array<int, string>}
     */
    public function updateOffer(int $id, array $data): array
    {
        $existing = $this->getOffer($id);
        if ($existing === null) {
            return ['success' => false, 'errors' => ['Offerta non trovata.']];
        }

        $validation = $this->validate($data);
        if ($validation['errors'] !== []) {
            return ['success' => false, 'errors' => $validation['errors']];
        }

        $stmt = $this->pdo->prepare(
            'UPDATE operator_offers
             SET provider_id = :provider_id,
                 title = :title,
                 description = :description,
                 price = :price,
                 status = :status,
                 valid_from = :valid_from,
                 valid_to = :valid_to
             WHERE id = :id'
        );
        $stmt->execute([
            ':provider_id' => $validation['provider_id'],
            ':title' => $validation['title'],
            ':description' => $validation['description'],
            ':price' => $validation['price'],
            ':status' => $validation['status'],
            ':valid_from' => $validation['valid_from'],
            ':valid_to' => $validation['valid_to'],
            ':id' => $id,
        ]);

        return ['success' => true];
    }

    public function updateStatus(int $id, string $status): void
    {
        $status = $status === 'Active' ? 'Active' : 'Inactive';
        $stmt = $this->pdo->prepare('UPDATE operator_offers SET status = :status WHERE id = :id');
        $stmt->execute([':status' => $status, ':id' => $id]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listProviders(): array
    {
        $stmt = $this->pdo->query('SELECT id, name FROM providers ORDER BY name');
        return $stmt->fetchAll();
    }

    /**
     * @param array<string, mixed> $data
     * @return array{
     *   errors: array<int, string>,
     *   provider_id: int|null,
     *   title: string,
     *   description: string|null,
     *   price: float,
     *   status: string,
     *   valid_from: string|null,
     *   valid_to: string|null
     * }
     */
    private function validate(array $data): array
    {
        $errors = [];
        $providerId = isset($data['provider_id']) && $data['provider_id'] !== ''
            ? (int) $data['provider_id']
            : null;
        if ($providerId !== null && $providerId <= 0) {
            $providerId = null;
        } elseif ($providerId !== null) {
            $stmtProvider = $this->pdo->prepare('SELECT id FROM providers WHERE id = :id');
            $stmtProvider->execute([':id' => $providerId]);
            if ($stmtProvider->fetchColumn() === false) {
                $errors[] = 'Operatore selezionato non valido.';
                $providerId = null;
            }
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $errors[] = 'Il nome offerta è obbligatorio.';
        }

        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            $description = null;
        }

        $price = (float) ($data['price'] ?? 0.0);
        if ($price < 0) {
            $errors[] = 'Il prezzo deve essere maggiore o uguale a zero.';
        }

        $status = (string) ($data['status'] ?? 'Active');
        if (!in_array($status, ['Active', 'Inactive'], true)) {
            $status = 'Active';
        }

        $validFrom = $this->normaliseDate($data['valid_from'] ?? null, 'La data inizio validità non è valida.', $errors);
        $validTo = $this->normaliseDate($data['valid_to'] ?? null, 'La data fine validità non è valida.', $errors);

        if ($validFrom !== null && $validTo !== null && $validFrom > $validTo) {
            $errors[] = 'La data di fine validità deve essere successiva alla data di inizio.';
        }

        return [
            'errors' => $errors,
            'provider_id' => $providerId,
            'title' => $title,
            'description' => $description,
            'price' => $price,
            'status' => $status,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
        ];
    }

    /**
     * @param mixed $value
     * @param array<int, string> $errors
     * @return string|null
     */
    private function normaliseDate(mixed $value, string $errorMessage, array &$errors): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = date_create((string) $value);
        if (!$date) {
            $errors[] = $errorMessage;
            return null;
        }

        return $date->format('Y-m-d');
    }
}
