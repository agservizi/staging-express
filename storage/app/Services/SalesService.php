<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

final class SalesService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array{
     *     user_id:int,
     *     customer_name?:string|null,
     *     items:array<int, array<string, mixed>>,
     *     payment_method?:string,
     *     discount?:float,
     *     discount_campaign_id?:int|null,
     *     vat?:float
     * } $data
     */
    public function createSale(array $data): int
    {
        $campaignId = null;
        $this->pdo->beginTransaction();
        try {
            $subtotal = 0.0;
            foreach ($data['items'] as $item) {
                $price = (float) ($item['price'] ?? 0);
                $quantity = (int) ($item['quantity'] ?? 1);
                $subtotal += $price * $quantity;
            }

            $discount = (float) ($data['discount'] ?? 0.0);
            if ($discount < 0) {
                $discount = 0.0;
            }
            if ($discount > $subtotal) {
                $discount = $subtotal;
            }
            $total = max($subtotal - $discount, 0.0);
            $configuredVat = (float) ($GLOBALS['config']['app']['tax_rate'] ?? 0.0);
            $vatRate = isset($data['vat']) ? (float) $data['vat'] : $configuredVat;
            if ($vatRate < 0) {
                $vatRate = 0.0;
            }

            if (array_key_exists('discount_campaign_id', $data) && $data['discount_campaign_id'] !== null) {
                $candidate = (int) $data['discount_campaign_id'];
                if ($candidate > 0) {
                    $campaignId = $candidate;
                }
            }

            $stmtSale = $this->pdo->prepare(
                'INSERT INTO sales (user_id, customer_name, total, vat, discount, discount_campaign_id, payment_method, status, refunded_amount, credited_amount)
                 VALUES (:u, :c, :t, :v, :d, :campaign, :p, "Completed", 0, 0)'
            );
            $stmtSale->execute([
                ':u' => $data['user_id'],
                ':c' => $data['customer_name'] ?? null,
                ':t' => $total,
                ':v' => $vatRate,
                ':d' => $discount,
                ':campaign' => $campaignId,
                ':p' => $data['payment_method'] ?? 'Contanti',
            ]);

            $saleId = (int) $this->pdo->lastInsertId();

            $stmtItem = $this->pdo->prepare(
                'INSERT INTO sale_items (sale_id, iccid_id, description, quantity, price)
                 VALUES (:s, :iccid, :desc, :qty, :price)'
            );
            $stmtUpdateICCID = $this->pdo->prepare(
                "UPDATE iccid_stock
                 SET status = 'Sold', updated_at = NOW()
                 WHERE id = :id AND status != 'Sold'"
            );
            $stmtFetchICCID = $this->pdo->prepare(
                'SELECT iccid FROM iccid_stock WHERE id = :id'
            );

            foreach ($data['items'] as $item) {
                $iccidId = $item['iccid_id'] ?? null;
                if ($iccidId !== null) {
                    $stmtFetchICCID->execute([':id' => $iccidId]);
                    $iccidRow = $stmtFetchICCID->fetch();
                    if (!$iccidRow) {
                        throw new \RuntimeException('ICCID non trovato (ID ' . $iccidId . ').');
                    }
                    $submittedCode = isset($item['iccid_code']) ? trim((string) $item['iccid_code']) : '';
                    if ($submittedCode !== '' && $submittedCode !== (string) $iccidRow['iccid']) {
                        throw new \RuntimeException('Il codice ICCID non coincide con il magazzino.');
                    }
                }

                $stmtItem->execute([
                    ':s' => $saleId,
                    ':iccid' => $iccidId,
                    ':desc' => $item['description'] ?? null,
                    ':qty' => $item['quantity'] ?? 1,
                    ':price' => $item['price'],
                ]);

                if ($iccidId !== null) {
                    $stmtUpdateICCID->execute([':id' => $iccidId]);
                    if ($stmtUpdateICCID->rowCount() === 0) {
                        throw new \RuntimeException(
                            'ICCID ID ' . $iccidId . ' non disponibile o già venduto.'
                        );
                    }
                }
            }

            $stmtAudit = $this->pdo->prepare(
                "INSERT INTO audit_log (user_id, action, description)
                 VALUES (:u, 'sale_create', :desc)"
            );
            $stmtAudit->execute([
                ':u' => $data['user_id'],
                ':desc' => 'Vendita #' . $saleId . ', totale ' . number_format($total, 2),
            ]);

            $this->pdo->commit();
            return $saleId;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function cancelSale(int $saleId, int $userId, ?string $reason = null): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmtSale = $this->pdo->prepare('SELECT status FROM sales WHERE id = :id FOR UPDATE');
            $stmtSale->execute([':id' => $saleId]);
            $sale = $stmtSale->fetch();
            if (!$sale) {
                throw new \RuntimeException('Vendita non trovata.');
            }
            if ($sale['status'] !== 'Completed') {
                throw new \RuntimeException('Impossibile annullare: stato attuale ' . $sale['status']);
            }

            $stmtItems = $this->pdo->prepare('SELECT iccid_id FROM sale_items WHERE sale_id = :id AND iccid_id IS NOT NULL');
            $stmtItems->execute([':id' => $saleId]);
            $iccids = $stmtItems->fetchAll(PDO::FETCH_COLUMN);
            if ($iccids) {
                $stmtRestore = $this->pdo->prepare(
                    "UPDATE iccid_stock SET status = 'InStock', updated_at = NOW() WHERE id = :id"
                );
                foreach ($iccids as $iccidId) {
                    $stmtRestore->execute([':id' => $iccidId]);
                }
            }

            $stmtUpdateSale = $this->pdo->prepare(
                'UPDATE sales
                 SET status = "Cancelled", cancelled_at = NOW(), cancellation_note = :note
                 WHERE id = :id'
            );
            $stmtUpdateSale->execute([
                ':note' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
                ':id' => $saleId,
            ]);

            $stmtAudit = $this->pdo->prepare(
                "INSERT INTO audit_log (user_id, action, description)
                 VALUES (:u, 'sale_cancel', :desc)"
            );
            $stmtAudit->execute([
                ':u' => $userId,
                ':desc' => 'Annullato scontrino #' . $saleId,
            ]);

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * @param array<int, array{sale_item_id:int, quantity:int, type:string, note?:string|null}>|null $items
     */
    public function refundSale(int $saleId, int $userId, ?array $items = null, ?string $generalNote = null): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmtSale = $this->pdo->prepare(
                'SELECT status, total, refunded_amount, credited_amount, refund_note
                 FROM sales WHERE id = :id FOR UPDATE'
            );
            $stmtSale->execute([':id' => $saleId]);
            $sale = $stmtSale->fetch();
            if (!$sale) {
                throw new \RuntimeException('Vendita non trovata.');
            }
            if ($sale['status'] === 'Cancelled') {
                throw new \RuntimeException('Impossibile registrare reso: la vendita è annullata.');
            }

            $stmtItems = $this->pdo->prepare(
                'SELECT id, iccid_id, quantity, price, refunded_quantity
                 FROM sale_items
                 WHERE sale_id = :sale_id
                 FOR UPDATE'
            );
            $stmtItems->execute([':sale_id' => $saleId]);
            $saleItems = [];
            while ($row = $stmtItems->fetch()) {
                $saleItems[(int) $row['id']] = $row;
            }

            if ($saleItems === []) {
                throw new \RuntimeException('Nessun articolo associato alla vendita.');
            }

            if ($items === null || $items === []) {
                $items = [];
                foreach ($saleItems as $saleItem) {
                    $remaining = (int) $saleItem['quantity'] - (int) $saleItem['refunded_quantity'];
                    if ($remaining <= 0) {
                        continue;
                    }

                    $items[] = [
                        'sale_item_id' => (int) $saleItem['id'],
                        'quantity' => $remaining,
                        'type' => 'Refund',
                    ];
                }
            }

            if ($items === []) {
                throw new \RuntimeException('Nessun articolo selezionato per il reso.');
            }

            $stmtInsertRefund = $this->pdo->prepare(
                'INSERT INTO sale_item_refunds (sale_item_id, user_id, quantity, refund_type, note, amount)
                 VALUES (:item, :user, :qty, :type, :note, :amount)'
            );
            $stmtUpdateItem = $this->pdo->prepare(
                'UPDATE sale_items SET refunded_quantity = refunded_quantity + :qty WHERE id = :id'
            );
            $stmtRestoreIccid = $this->pdo->prepare(
                "UPDATE iccid_stock SET status = 'InStock', updated_at = NOW() WHERE id = :id"
            );

            $totalRefundAmount = 0.0;
            $totalCreditAmount = 0.0;
            $processedRows = 0;

            foreach ($items as $item) {
                $itemId = (int) ($item['sale_item_id'] ?? 0);
                $quantity = (int) ($item['quantity'] ?? 0);
                if ($itemId <= 0 || $quantity <= 0) {
                    continue;
                }

                if (!isset($saleItems[$itemId])) {
                    throw new \RuntimeException('Articolo non appartiene a questo scontrino (ID ' . $itemId . ').');
                }

                $saleItem = $saleItems[$itemId];
                $available = (int) $saleItem['quantity'] - (int) $saleItem['refunded_quantity'];
                if ($available <= 0) {
                    throw new \RuntimeException('Articolo già completamente reso (ID riga ' . $itemId . ').');
                }
                if ($quantity > $available) {
                    throw new \RuntimeException('Quantità non disponibile per il reso (ID riga ' . $itemId . ').');
                }

                $type = strtoupper((string) ($item['type'] ?? 'Refund')) === 'CREDIT' ? 'Credit' : 'Refund';
                $amount = (float) $saleItem['price'] * $quantity;
                $note = isset($item['note']) ? trim((string) $item['note']) : null;

                $stmtInsertRefund->execute([
                    ':item' => $itemId,
                    ':user' => $userId,
                    ':qty' => $quantity,
                    ':type' => $type,
                    ':note' => $note !== '' ? $note : null,
                    ':amount' => $amount,
                ]);

                $stmtUpdateItem->execute([
                    ':qty' => $quantity,
                    ':id' => $itemId,
                ]);

                if ($saleItem['iccid_id'] !== null) {
                    $stmtRestoreIccid->execute([':id' => $saleItem['iccid_id']]);
                }

                if ($type === 'Refund') {
                    $totalRefundAmount += $amount;
                } else {
                    $totalCreditAmount += $amount;
                }

                $saleItems[$itemId]['refunded_quantity'] += $quantity;
                $processedRows++;
            }

            if ($processedRows === 0) {
                throw new \RuntimeException('Seleziona almeno un articolo con quantità valida.');
            }

            $stmtTotals = $this->pdo->prepare(
                'SELECT SUM(quantity) AS total_qty, SUM(refunded_quantity) AS refunded_qty
                 FROM sale_items WHERE sale_id = :sale_id'
            );
            $stmtTotals->execute([':sale_id' => $saleId]);
            $totals = $stmtTotals->fetch() ?: ['total_qty' => 0, 'refunded_qty' => 0];

            $status = 'Completed';
            if ((int) $totals['total_qty'] > 0 && (int) $totals['refunded_qty'] >= (int) $totals['total_qty']) {
                $status = 'Refunded';
            } elseif ($sale['status'] === 'Cancelled') {
                $status = 'Cancelled';
            }

            $newRefundedAmount = (float) $sale['refunded_amount'] + $totalRefundAmount;
            $newCreditedAmount = (float) $sale['credited_amount'] + $totalCreditAmount;
            $noteToStore = $generalNote !== null ? trim($generalNote) : ($sale['refund_note'] ?? null);
            if ($noteToStore !== null && $noteToStore === '') {
                $noteToStore = null;
            }

            $stmtUpdateSale = $this->pdo->prepare(
                'UPDATE sales
                 SET refunded_amount = :refunded_amount,
                     credited_amount = :credited_amount,
                     status = :status,
                     refunded_at = NOW(),
                     refund_note = :note
                 WHERE id = :id'
            );
            $stmtUpdateSale->execute([
                ':refunded_amount' => $newRefundedAmount,
                ':credited_amount' => $newCreditedAmount,
                ':status' => $status,
                ':note' => $noteToStore,
                ':id' => $saleId,
            ]);

            $stmtAudit = $this->pdo->prepare(
                "INSERT INTO audit_log (user_id, action, description)
                 VALUES (:u, 'sale_refund', :desc)"
            );
            $stmtAudit->execute([
                ':u' => $userId,
                ':desc' => 'Reso scontrino #' . $saleId . ': rimborso € ' . number_format($totalRefundAmount, 2, ',', '.') . ' / credito € ' . number_format($totalCreditAmount, 2, ',', '.'),
            ]);

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSaleWithItems(int $saleId): ?array
    {
        $stmtSale = $this->pdo->prepare(
            'SELECT s.*, u.fullname, u.username
             FROM sales s
             LEFT JOIN users u ON u.id = s.user_id
             WHERE s.id = :id'
        );
        $stmtSale->execute([':id' => $saleId]);
        $sale = $stmtSale->fetch();

        if (!$sale) {
            return null;
        }

        $stmtItems = $this->pdo->prepare(
            'SELECT si.*, ic.iccid
             FROM sale_items si
             LEFT JOIN iccid_stock ic ON ic.id = si.iccid_id
             WHERE si.sale_id = :id'
        );
        $stmtItems->execute([':id' => $saleId]);
        $items = $stmtItems->fetchAll();

        $sale['items'] = $items;

        return $sale;
    }

    /**
     * @param array{
     *   q?:string|null,
     *   status?:string|null,
     *   from?:string|null,
     *   to?:string|null,
     *   payment?:string|null
     * } $filters
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   pagination: array{page:int, per_page:int, total:int, pages:int}
     * }
     */
    public function searchSales(array $filters, int $page, int $perPage): array
    {
        $conditions = [];
        $params = [];

        if (!empty($filters['status']) && in_array($filters['status'], ['Completed', 'Cancelled', 'Refunded'], true)) {
            $conditions[] = 's.status = :status';
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['payment']) && in_array($filters['payment'], ['Contanti', 'Carta', 'POS'], true)) {
            $conditions[] = 's.payment_method = :payment';
            $params[':payment'] = $filters['payment'];
        }

        if (!empty($filters['from'])) {
            $from = date_create($filters['from']);
            if ($from) {
                $conditions[] = 'DATE(s.created_at) >= :from_date';
                $params[':from_date'] = $from->format('Y-m-d');
            }
        }

        if (!empty($filters['to'])) {
            $to = date_create($filters['to']);
            if ($to) {
                $conditions[] = 'DATE(s.created_at) <= :to_date';
                $params[':to_date'] = $to->format('Y-m-d');
            }
        }

        if (!empty($filters['q'])) {
            $q = trim((string) $filters['q']);
            if ($q !== '') {
                if (ctype_digit($q)) {
                    $conditions[] = 's.id = :sale_id';
                    $params[':sale_id'] = (int) $q;
                } else {
                    $conditions[] = '(s.customer_name LIKE :term OR u.fullname LIKE :term OR u.username LIKE :term)';
                    $params[':term'] = '%' . $q . '%';
                }
            }
        }

        $where = $conditions !== [] ? ('WHERE ' . implode(' AND ', $conditions)) : '';

        $countSql = 'SELECT COUNT(*) FROM sales s LEFT JOIN users u ON u.id = s.user_id ' . $where;
        $stmtCount = $this->pdo->prepare($countSql);
        foreach ($params as $key => $value) {
            $stmtCount->bindValue($key, $value);
        }
        $stmtCount->execute();
        $total = (int) $stmtCount->fetchColumn();

        $pages = (int) max((int) ceil($total / $perPage), 1);
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $dataSql = 'SELECT s.*, u.fullname, u.username
                    FROM sales s
                    LEFT JOIN users u ON u.id = s.user_id
                    ' . $where . '
                    ORDER BY s.created_at DESC
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
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => $pages,
            ],
        ];
    }
}
