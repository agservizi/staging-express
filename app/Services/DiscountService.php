<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

final class DiscountService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, name, type, value, description, is_active, created_at
             FROM discount_schemes
             ORDER BY is_active DESC, name ASC'
        );

        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listActive(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, name, type, value, description
             FROM discount_schemes
             WHERE is_active = 1
             ORDER BY name ASC'
        );

        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, type, value, description, is_active
             FROM discount_schemes
             WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $discount = $stmt->fetch();

        return $discount === false ? null : $discount;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool, message?:string, errors?:array<int, string>}
     */
    public function create(array $input): array
    {
        $name = isset($input['discount_name']) ? trim((string) $input['discount_name']) : '';
        $type = isset($input['discount_type']) ? (string) $input['discount_type'] : 'Amount';
        $value = isset($input['discount_value']) ? (float) $input['discount_value'] : 0.0;
        $description = isset($input['discount_description']) ? trim((string) $input['discount_description']) : null;

        $errors = [];
        if ($name === '') {
            $errors[] = 'Il nome della scontistica è obbligatorio.';
        }
        if (!in_array($type, ['Amount', 'Percent'], true)) {
            $errors[] = 'Tipo di sconto non valido.';
        }
        if ($value <= 0) {
            $errors[] = 'Il valore dello sconto deve essere maggiore di zero.';
        } elseif ($type === 'Percent' && $value > 100) {
            $errors[] = 'Le scontistiche percentuali non possono superare il 100%.';
        }

        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        $stmtDuplicate = $this->pdo->prepare('SELECT COUNT(*) FROM discount_schemes WHERE name = :name');
        $stmtDuplicate->execute([':name' => $name]);
        if ((int) $stmtDuplicate->fetchColumn() > 0) {
            return [
                'success' => false,
                'errors' => ['Esiste già una scontistica con questo nome.']
            ];
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO discount_schemes (name, type, value, description, is_active)
             VALUES (:name, :type, :value, :description, 1)'
        );
        $stmt->execute([
            ':name' => $name,
            ':type' => $type,
            ':value' => $value,
            ':description' => $description !== '' ? $description : null,
        ]);

        return [
            'success' => true,
            'message' => 'Scontistica creata con successo.'
        ];
    }

    /**
     * @return array{success:bool, message?:string, errors?:array<int, string>}
     */
    public function setStatus(int $id, bool $active): array
    {
        $existing = $this->find($id);
        if ($existing === null) {
            return ['success' => false, 'errors' => ['Scontistica non trovata.']];
        }

        if ((int) $existing['is_active'] === ($active ? 1 : 0)) {
            return [
                'success' => true,
                'message' => $active ? 'Scontistica già attiva.' : 'Scontistica già disattivata.'
            ];
        }

        $stmt = $this->pdo->prepare('UPDATE discount_schemes SET is_active = :active WHERE id = :id');
        $stmt->execute([
            ':active' => $active ? 1 : 0,
            ':id' => $id,
        ]);

        return [
            'success' => true,
            'message' => $active ? 'Scontistica attivata.' : 'Scontistica disattivata.'
        ];
    }
}
