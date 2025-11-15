<?php
declare(strict_types=1);

namespace App\Services;
use PDO;
use PDOException;

final class ProductService
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
            'SELECT id, name, sku, imei, category, price, stock_quantity, stock_reserved, reorder_threshold, tax_rate, vat_code, notes, is_active, created_at, updated_at
             FROM products
             ORDER BY created_at DESC'
        );
        return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, pagination: array<string, int|bool>}
     */
    public function listPaginated(int $page, int $perPage = 7): array
    {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 50));

        $total = (int) ($this->pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() ?: 0);
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        $stmt = $this->pdo->prepare(
            'SELECT id, name, sku, imei, category, price, stock_quantity, stock_reserved, reorder_threshold, tax_rate, vat_code, notes, is_active, created_at, updated_at
             FROM products
             ORDER BY created_at DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'rows' => $rows,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_prev' => $page > 1,
                'has_next' => $page < $totalPages,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listActive(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, sku, imei, category, price, stock_quantity, tax_rate, vat_code
             FROM products
             WHERE is_active = 1
             ORDER BY name ASC'
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForFiscalSettings(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, name, sku, tax_rate, vat_code, is_active
             FROM products
             ORDER BY is_active DESC, name ASC'
        );

        return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    public function findById(int $id): ?array
    {
                $stmt = $this->pdo->prepare(
                    'SELECT id, name, sku, imei, category, price, stock_quantity, stock_reserved, reorder_threshold, tax_rate, vat_code, is_active
             FROM products
             WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool, message:string, errors?:array<int, string>}
     */
    public function create(array $input, ?int $userId = null): array
    {
        $name = isset($input['name']) ? trim((string) $input['name']) : '';
        $sku = isset($input['sku']) ? trim((string) $input['sku']) : '';
    $imei = isset($input['imei']) ? trim((string) $input['imei']) : '';
        $category = isset($input['category']) ? trim((string) $input['category']) : '';
        $notes = isset($input['notes']) ? trim((string) $input['notes']) : null;
        $price = isset($input['price']) ? (float) $input['price'] : 0.0;
        $taxRate = isset($input['tax_rate']) ? (float) $input['tax_rate'] : 22.0;
        $vatCode = array_key_exists('vat_code', $input) ? trim((string) $input['vat_code']) : null;
        $isActive = isset($input['is_active']) ? ((int) $input['is_active'] === 1 ? 1 : 0) : 1;
        $stockQuantity = isset($input['stock_quantity']) ? (int) $input['stock_quantity'] : 0;
        $reorderThreshold = isset($input['reorder_threshold']) ? (int) $input['reorder_threshold'] : 0;

        if ($vatCode !== null) {
            $vatCode = $vatCode !== '' ? strtoupper($vatCode) : null;
        }

        $errors = [];
        if ($name === '') {
            $errors[] = 'Inserisci il nome del prodotto.';
        }
        if ($price < 0) {
            $errors[] = 'Il prezzo non può essere negativo.';
        }
        if ($taxRate < 0 || $taxRate > 100) {
            $errors[] = 'L\'aliquota IVA deve essere compresa tra 0 e 100.';
        }
        if ($stockQuantity < 0) {
            $errors[] = 'La quantità iniziale non può essere negativa.';
        }
        if ($reorderThreshold < 0) {
            $errors[] = 'La soglia di riordino deve essere positiva o zero.';
        }
        if ($vatCode !== null) {
            $length = function_exists('mb_strlen') ? mb_strlen($vatCode) : strlen($vatCode);
            if ($length > 32) {
                $errors[] = 'Il codice IVA può contenere al massimo 32 caratteri.';
            }
        }

        if ($errors !== []) {
            return [
                'success' => false,
                'message' => 'Verifica i dati inseriti.',
                'errors' => $errors,
            ];
        }

        if ($sku !== '') {
            $stmtSku = $this->pdo->prepare('SELECT id FROM products WHERE sku = :sku LIMIT 1');
            $stmtSku->execute([':sku' => $sku]);
            if ($stmtSku->fetch()) {
                return [
                    'success' => false,
                    'message' => 'SKU già utilizzato da un altro prodotto.',
                    'errors' => ['Lo SKU inserito è già presente a catalogo.'],
                ];
            }
        }

        if ($imei !== '') {
            $stmtImei = $this->pdo->prepare('SELECT id FROM products WHERE imei = :imei LIMIT 1');
            $stmtImei->execute([':imei' => $imei]);
            if ($stmtImei->fetch()) {
                return [
                    'success' => false,
                    'message' => 'IMEI già utilizzato da un altro prodotto.',
                    'errors' => ['L\'IMEI inserito è già presente a catalogo.'],
                ];
            }
        }

    $productId = null;

    try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare(
                'INSERT INTO products (name, sku, imei, category, price, stock_quantity, stock_reserved, reorder_threshold, tax_rate, vat_code, notes, is_active)
                 VALUES (:name, :sku, :imei, :category, :price, :stock_quantity, 0, :reorder_threshold, :tax_rate, :vat_code, :notes, :is_active)'
            );
            $stmt->execute([
                ':name' => $name,
                ':sku' => $sku !== '' ? $sku : null,
                ':imei' => $imei !== '' ? $imei : null,
                ':category' => $category !== '' ? $category : null,
                ':price' => $price,
                ':stock_quantity' => $stockQuantity,
                ':reorder_threshold' => $reorderThreshold,
                ':tax_rate' => $taxRate,
                ':vat_code' => $vatCode,
                ':notes' => $notes !== null && $notes !== '' ? $notes : null,
                ':is_active' => $isActive,
            ]);

            $productId = (int) $this->pdo->lastInsertId();
            if ($productId > 0 && $stockQuantity > 0) {
                $this->insertStockMovement(
                    $productId,
                    $stockQuantity,
                    $stockQuantity,
                    'Initial',
                    $userId,
                    'product_create',
                    $productId,
                    'Stock iniziale catalogo'
                );
            }

            $this->pdo->commit();
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return [
                'success' => false,
                'message' => 'Errore durante il salvataggio del prodotto.',
                'errors' => ['Database: ' . $e->getMessage()],
            ];
        }

        return [
            'success' => true,
            'message' => 'Prodotto aggiunto a catalogo.',
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool, message:string, errors?:array<int, string>}
     */
    public function update(int $id, array $input, ?int $userId = null): array
    {
        $existing = $this->findById($id);
        if ($existing === null) {
            return [
                'success' => false,
                'message' => 'Prodotto non trovato.',
                'errors' => ['Il prodotto selezionato non è più disponibile.'],
            ];
        }

        $name = isset($input['name']) ? trim((string) $input['name']) : '';
        $sku = isset($input['sku']) ? trim((string) $input['sku']) : '';
        $imei = isset($input['imei']) ? trim((string) $input['imei']) : '';
        $category = isset($input['category']) ? trim((string) $input['category']) : '';
        $notes = isset($input['notes']) ? trim((string) $input['notes']) : null;
        $price = isset($input['price']) ? (float) $input['price'] : 0.0;
        $taxRate = isset($input['tax_rate']) ? (float) $input['tax_rate'] : 22.0;
        $isActive = isset($input['is_active']) ? ((int) $input['is_active'] === 1 ? 1 : 0) : 0;
        $stockQuantity = isset($input['stock_quantity']) ? (int) $input['stock_quantity'] : (int) ($existing['stock_quantity'] ?? 0);
        $reorderThreshold = isset($input['reorder_threshold']) ? (int) $input['reorder_threshold'] : (int) ($existing['reorder_threshold'] ?? 0);
        $vatCode = array_key_exists('vat_code', $input)
            ? trim((string) $input['vat_code'])
            : (isset($existing['vat_code']) ? (string) $existing['vat_code'] : null);

        if ($vatCode !== null) {
            $vatCode = $vatCode !== '' ? strtoupper($vatCode) : null;
        }

        $errors = [];
        if ($name === '') {
            $errors[] = 'Inserisci il nome del prodotto.';
        }
        if ($price < 0) {
            $errors[] = 'Il prezzo non può essere negativo.';
        }
        if ($taxRate < 0 || $taxRate > 100) {
            $errors[] = 'L\'aliquota IVA deve essere compresa tra 0 e 100.';
        }
        if ($stockQuantity < 0) {
            $errors[] = 'La quantità in stock non può essere negativa.';
        }
        if ($reorderThreshold < 0) {
            $errors[] = 'La soglia di riordino deve essere positiva o zero.';
        }
        if ($vatCode !== null) {
            $length = function_exists('mb_strlen') ? mb_strlen($vatCode) : strlen($vatCode);
            if ($length > 32) {
                $errors[] = 'Il codice IVA può contenere al massimo 32 caratteri.';
            }
        }

        if ($errors !== []) {
            return [
                'success' => false,
                'message' => 'Verifica i dati inseriti.',
                'errors' => $errors,
            ];
        }

        if ($sku !== '') {
            $stmtSku = $this->pdo->prepare('SELECT id FROM products WHERE sku = :sku AND id != :id LIMIT 1');
            $stmtSku->execute([':sku' => $sku, ':id' => $id]);
            if ($stmtSku->fetch()) {
                return [
                    'success' => false,
                    'message' => 'SKU già utilizzato da un altro prodotto.',
                    'errors' => ['Lo SKU inserito è già presente a catalogo.'],
                ];
            }
        }

        if ($imei !== '') {
            $stmtImei = $this->pdo->prepare('SELECT id FROM products WHERE imei = :imei AND id != :id LIMIT 1');
            $stmtImei->execute([':imei' => $imei, ':id' => $id]);
            if ($stmtImei->fetch()) {
                return [
                    'success' => false,
                    'message' => 'IMEI già utilizzato da un altro prodotto.',
                    'errors' => ['L\'IMEI inserito è già presente a catalogo.'],
                ];
            }
        }

        $previousStock = (int) ($existing['stock_quantity'] ?? 0);
        $stockDelta = $stockQuantity - $previousStock;

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare(
                'UPDATE products
                 SET name = :name,
                     sku = :sku,
                     imei = :imei,
                     category = :category,
                     price = :price,
                     stock_quantity = :stock_quantity,
                     tax_rate = :tax_rate,
                         vat_code = :vat_code,
                     reorder_threshold = :reorder_threshold,
                     notes = :notes,
                     is_active = :is_active
                 WHERE id = :id'
            );
            $stmt->execute([
                ':name' => $name,
                ':sku' => $sku !== '' ? $sku : null,
                ':imei' => $imei !== '' ? $imei : null,
                ':category' => $category !== '' ? $category : null,
                ':price' => $price,
                ':stock_quantity' => $stockQuantity,
                ':tax_rate' => $taxRate,
                ':vat_code' => $vatCode,
                ':reorder_threshold' => $reorderThreshold,
                ':notes' => $notes !== null && $notes !== '' ? $notes : null,
                ':is_active' => $isActive,
                ':id' => $id,
            ]);

            if ($stockDelta !== 0) {
                $this->insertStockMovement(
                    $id,
                    $stockDelta,
                    $stockQuantity,
                    'Adjustment',
                    $userId,
                    'product_update',
                    $id,
                    'Allineamento manuale stock'
                );
            }

            $this->pdo->commit();
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return [
                'success' => false,
                'message' => 'Errore durante l\'aggiornamento del prodotto.',
                'errors' => ['Database: ' . $e->getMessage()],
            ];
        }

        return [
            'success' => true,
            'message' => 'Prodotto aggiornato correttamente.',
        ];
    }

    /**
     * @return array{success:bool, message:string, errors?:array<int, string>}
     */
    public function updateTaxSettings(int $productId, float $taxRate, ?string $vatCode): array
    {
        $productId = max(1, $productId);
        $existing = $this->findById($productId);
        if ($existing === null) {
            return [
                'success' => false,
                'message' => 'Prodotto non trovato.',
                'errors' => ['Seleziona un prodotto valido.'],
            ];
        }

        if ($taxRate < 0 || $taxRate > 100) {
            return [
                'success' => false,
                'message' => 'Aliquota non valida.',
                'errors' => ['L\'aliquota IVA deve essere compresa tra 0 e 100.'],
            ];
        }

        $normalizedCode = $vatCode !== null ? trim($vatCode) : null;
        if ($normalizedCode === '') {
            $normalizedCode = null;
        }
        if ($normalizedCode !== null) {
            $normalizedCode = strtoupper($normalizedCode);
            $length = function_exists('mb_strlen') ? mb_strlen($normalizedCode) : strlen($normalizedCode);
            if ($length > 32) {
                return [
                    'success' => false,
                    'message' => 'Codice IVA troppo lungo.',
                    'errors' => ['Il codice IVA può contenere al massimo 32 caratteri.'],
                ];
            }
        }

        $stmt = $this->pdo->prepare(
            'UPDATE products
             SET tax_rate = :tax_rate,
                 vat_code = :vat_code
             WHERE id = :id'
        );
        $stmt->execute([
            ':tax_rate' => $taxRate,
            ':vat_code' => $normalizedCode,
            ':id' => $productId,
        ]);

        $updated = $stmt->rowCount() > 0;

        return [
            'success' => true,
            'message' => $updated ? 'Impostazioni fiscali aggiornate.' : 'Nessuna modifica necessaria.',
        ];
    }

    /**
     * @return array{success:bool, message:string, errors?:array<int, string>}
     */
    public function delete(int $id): array
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM products WHERE id = :id');
            $stmt->execute([':id' => $id]);
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Errore durante l\'eliminazione del prodotto.',
                'errors' => ['Database: ' . $e->getMessage()],
            ];
        }

        if ($stmt->rowCount() === 0) {
            return [
                'success' => false,
                'message' => 'Prodotto non trovato o già rimosso.',
                'errors' => ['Nessun prodotto corrispondente all\'ID indicato.'],
            ];
        }

        return [
            'success' => true,
            'message' => 'Prodotto eliminato dal catalogo.',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getStockMovements(int $productId, int $limit = 20): array
    {
        $productId = max(1, $productId);
        $limit = max(1, min($limit, 200));

        $stmt = $this->pdo->prepare(
            'SELECT id, product_id, quantity_change, balance_after, reason, reference_type, reference_id, user_id, note, created_at
             FROM product_stock_movements
             WHERE product_id = :product_id
             ORDER BY created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array{success:bool, message:string, errors?:array<int, string>}
     */
    public function restock(int $id): array
    {
        try {
            $stmt = $this->pdo->prepare('UPDATE products SET is_active = 1 WHERE id = :id');
            $stmt->execute([':id' => $id]);
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Errore durante il ripristino a catalogo.',
                'errors' => ['Database: ' . $e->getMessage()],
            ];
        }

        if ($stmt->rowCount() === 0) {
            return [
                'success' => false,
                'message' => 'Prodotto non trovato.',
                'errors' => ['Verifica che il prodotto esista ancora a catalogo.'],
            ];
        }

        return [
            'success' => true,
            'message' => 'Prodotto riattivato a catalogo.',
        ];
    }

    private function insertStockMovement(
        int $productId,
        int $quantityChange,
        int $balanceAfter,
        string $reason,
        ?int $userId,
        ?string $referenceType,
        ?int $referenceId,
        ?string $note
    ): void {
        if ($quantityChange === 0) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO product_stock_movements (product_id, quantity_change, balance_after, reason, reference_type, reference_id, user_id, note)
             VALUES (:product_id, :quantity_change, :balance_after, :reason, :reference_type, :reference_id, :user_id, :note)'
        );
        $stmt->execute([
            ':product_id' => $productId,
            ':quantity_change' => $quantityChange,
            ':balance_after' => $balanceAfter,
            ':reason' => $reason,
            ':reference_type' => $referenceType,
            ':reference_id' => $referenceId,
            ':user_id' => $userId,
            ':note' => $note !== null && $note !== '' ? $note : null,
        ]);
    }
}
