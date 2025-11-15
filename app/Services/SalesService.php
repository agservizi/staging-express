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
     *     customer_id?:int|null,
     *     customer_name?:string|null,
     *     customer_note?:string|null,
     *     items:array<int, array{
     *         type?:string,
     *         price:float|int,
     *         quantity?:int,
     *         description?:string|null,
     *         iccid_id?:int|null,
     *         iccid_code?:string|null,
     *         product_id?:int|null,
     *         tax_rate?:float|null
     *     }>,
     *     payment_method?:string,
     *     discount?:float,
     *     discount_campaign_id?:int|null,
     *     vat?:float,
     *     total_paid?:float,
     *     balance_due?:float,
     *     payment_status?:string,
     *     due_date?:string|null
     * } $data
     */
    public function createSale(array $data): int
    {
        $campaignId = null;
        $this->pdo->beginTransaction();
        try {
            $items = $data['items'];
            $subtotal = 0.0;
            $productIds = [];
            $productUsage = [];

            foreach ($items as $item) {
                $price = (float) ($item['price'] ?? 0);
                $quantity = (int) ($item['quantity'] ?? 1);
                if ($quantity <= 0) {
                    $quantity = 1;
                }
                $subtotal += $price * $quantity;

                if (($item['type'] ?? '') === 'product') {
                    $productId = (int) ($item['product_id'] ?? 0);
                    if ($productId > 0) {
                        $productIds[] = $productId;
                        $productUsage[$productId] = ($productUsage[$productId] ?? 0) + $quantity;
                    }
                }
            }

            $customerId = null;
            $customerName = null;
            $customerNote = null;

            if (array_key_exists('customer_id', $data) && $data['customer_id'] !== null) {
                $candidate = (int) $data['customer_id'];
                if ($candidate > 0) {
                    $stmtCustomer = $this->pdo->prepare('SELECT id, fullname FROM customers WHERE id = :id');
                    $stmtCustomer->execute([':id' => $candidate]);
                    $customerRow = $stmtCustomer->fetch(PDO::FETCH_ASSOC);
                    if ($customerRow === false) {
                        throw new \RuntimeException('Cliente selezionato non trovato.');
                    }
                    $customerId = (int) $customerRow['id'];
                    $customerName = (string) ($customerRow['fullname'] ?? '');
                }
            }

            if ($customerName === null || $customerName === '') {
                $customerName = isset($data['customer_name']) ? trim((string) $data['customer_name']) : '';
            }
            if ($customerName === '') {
                $customerName = null;
            }

            if (isset($data['customer_note'])) {
                $candidateNote = trim((string) $data['customer_note']);
                $customerNote = $candidateNote !== '' ? $candidateNote : null;
            }

            $discount = (float) ($data['discount'] ?? 0.0);
            if ($discount < 0) {
                $discount = 0.0;
            }
            if ($discount > $subtotal) {
                $discount = $subtotal;
            }
            $subtotal = round($subtotal, 2);
            $discount = round($discount, 2);
            $total = round(max($subtotal - $discount, 0.0), 2);
            $configuredVat = (float) ($GLOBALS['config']['app']['tax_rate'] ?? 0.0);
            $requestedVatRate = isset($data['vat']) ? (float) $data['vat'] : $configuredVat;
            if ($requestedVatRate < 0) {
                $requestedVatRate = 0.0;
            }

            if (array_key_exists('discount_campaign_id', $data) && $data['discount_campaign_id'] !== null) {
                $candidate = (int) $data['discount_campaign_id'];
                if ($candidate > 0) {
                    $campaignId = $candidate;
                }
            }

            $discountRatio = 0.0;
            if ($subtotal > 0) {
                $discountRatio = min($discount / $subtotal, 1.0);
            }

            $totalPaid = null;
            if (array_key_exists('total_paid', $data)) {
                $totalPaid = max((float) $data['total_paid'], 0.0);
            }
            if ($totalPaid === null) {
                $totalPaid = $total;
            }
            if ($total > 0) {
                $totalPaid = min($totalPaid, $total);
            }
            $totalPaid = round($totalPaid, 2);

            $balanceDue = null;
            if (array_key_exists('balance_due', $data)) {
                $balanceDue = max((float) $data['balance_due'], 0.0);
            }
            if ($balanceDue === null) {
                $balanceDue = max($total - $totalPaid, 0.0);
            }
            if ($total > 0 && $balanceDue > $total) {
                $balanceDue = $total;
            }
            if ($balanceDue < 0.01) {
                $balanceDue = 0.0;
            }
            $balanceDue = round($balanceDue, 2);

            $dueDate = null;
            if (array_key_exists('due_date', $data) && $data['due_date'] !== null && $data['due_date'] !== '') {
                $candidateDue = (string) $data['due_date'];
                $dueDateObject = date_create($candidateDue);
                if ($dueDateObject === false) {
                    throw new \RuntimeException('Data scadenza pagamento non valida.');
                }
                $dueDate = $dueDateObject->format('Y-m-d');
            }

            $validPaymentStatuses = ['Paid', 'Partial', 'Pending', 'Overdue'];
            $paymentStatus = null;
            if (array_key_exists('payment_status', $data)) {
                $candidateStatus = (string) $data['payment_status'];
                if (in_array($candidateStatus, $validPaymentStatuses, true)) {
                    $paymentStatus = $candidateStatus;
                }
            }

            if ($paymentStatus === null) {
                $paymentStatus = 'Paid';
                if ($balanceDue > 0.0) {
                    $paymentStatus = $totalPaid > 0.0 ? 'Partial' : 'Pending';
                    if ($dueDate !== null) {
                        $today = new \DateTimeImmutable('today');
                        $dueDateObject = new \DateTimeImmutable($dueDate);
                        if ($dueDateObject < $today) {
                            $paymentStatus = 'Overdue';
                        }
                    }
                }
            }

            $productMap = [];
            $uniqueProductIds = array_values(array_unique(array_filter($productIds, static fn (int $id): bool => $id > 0)));
            if ($uniqueProductIds !== []) {
                $placeholders = implode(',', array_fill(0, count($uniqueProductIds), '?'));
                $stmtProducts = $this->pdo->prepare(
                    'SELECT id, name, tax_rate, stock_quantity, vat_code
                     FROM products
                     WHERE id IN (' . $placeholders . ')
                     FOR UPDATE'
                );
                $stmtProducts->execute($uniqueProductIds);
                while ($row = $stmtProducts->fetch(PDO::FETCH_ASSOC)) {
                    $productMap[(int) $row['id']] = [
                        'name' => (string) ($row['name'] ?? ''),
                        'tax_rate' => (float) ($row['tax_rate'] ?? 0.0),
                        'stock_quantity' => (int) ($row['stock_quantity'] ?? 0),
                        'vat_code' => isset($row['vat_code']) && $row['vat_code'] !== '' ? (string) $row['vat_code'] : null,
                    ];
                }
            }

            if ($productUsage !== []) {
                foreach ($productUsage as $productId => $qtyRequested) {
                    if (!isset($productMap[$productId])) {
                        throw new \RuntimeException('Prodotto non trovato o disattivato (ID ' . $productId . ').');
                    }
                    $availableStock = (int) ($productMap[$productId]['stock_quantity'] ?? 0);
                    if ($availableStock < $qtyRequested) {
                        $productName = (string) ($productMap[$productId]['name'] ?? ('ID ' . $productId));
                        throw new \RuntimeException(
                            'Stock insufficiente per "' . $productName . '". Disponibili: ' . $availableStock . ', richiesti: ' . $qtyRequested . '.'
                        );
                    }
                }
            }

            $itemTaxDetails = [];
            $vatAmountAccumulator = 0.0;
            foreach ($items as $index => $item) {
                $type = (string) ($item['type'] ?? 'service');
                $price = (float) ($item['price'] ?? 0.0);
                $quantity = (int) ($item['quantity'] ?? 1);
                if ($quantity <= 0) {
                    $quantity = 1;
                }
                $lineTotal = $price * $quantity;
                $lineAfterDiscount = $lineTotal;
                if ($discountRatio > 0) {
                    $lineAfterDiscount = max($lineTotal - ($lineTotal * $discountRatio), 0.0);
                }

                $taxRate = 0.0;
                $taxAmount = 0.0;
                $fallbackDescription = null;

                if ($type === 'product') {
                    $productId = (int) ($item['product_id'] ?? 0);
                    if ($productId <= 0 || !isset($productMap[$productId])) {
                        throw new \RuntimeException('Prodotto non trovato o disattivato (ID ' . $productId . ').');
                    }
                    $taxRate = (float) $productMap[$productId]['tax_rate'];
                    $fallbackDescription = $productMap[$productId]['name'] ?? null;
                    if ($taxRate > 0 && $lineAfterDiscount > 0) {
                        $lineTaxable = $lineAfterDiscount / (1 + ($taxRate / 100));
                        $taxAmount = $lineAfterDiscount - $lineTaxable;
                    }
                }

                $taxAmount = round($taxAmount, 4);
                if ($taxAmount < 0) {
                    $taxAmount = 0.0;
                }

                $vatAmountAccumulator += $taxAmount;
                $itemTaxDetails[$index] = [
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmount,
                    'fallback_description' => $fallbackDescription,
                    'vat_code' => $productMap[$productId]['vat_code'] ?? null,
                ];
            }

            $effectiveRates = [];
            foreach ($itemTaxDetails as $detail) {
                $rate = (float) ($detail['tax_rate'] ?? 0.0);
                if ($rate > 0) {
                    $effectiveRates[] = round($rate, 2);
                }
            }

            $saleVatRate = $requestedVatRate;
            if ($effectiveRates !== []) {
                $uniqueRates = array_values(array_unique($effectiveRates));
                if (count($uniqueRates) === 1) {
                    $saleVatRate = $uniqueRates[0];
                }
            }

            $saleVatAmount = round($vatAmountAccumulator, 2);

            $stmtSale = $this->pdo->prepare(
                'INSERT INTO sales (
                    user_id,
                    customer_id,
                    customer_name,
                    customer_note,
                    total,
                    total_paid,
                    balance_due,
                    payment_status,
                    due_date,
                    vat,
                    vat_amount,
                    discount,
                    discount_campaign_id,
                    payment_method,
                    status,
                    refunded_amount,
                    credited_amount
                )
                VALUES (
                    :u,
                    :customer_id,
                    :customer_name,
                    :customer_note,
                    :t,
                    :total_paid,
                    :balance_due,
                    :payment_status,
                    :due_date,
                    :v,
                    :vat_amount,
                    :d,
                    :campaign,
                    :p,
                    "Completed",
                    0,
                    0
                )'
            );
            $stmtSale->execute([
                ':u' => $data['user_id'],
                ':customer_id' => $customerId,
                ':customer_name' => $customerName,
                ':customer_note' => $customerNote,
                ':t' => $total,
                ':total_paid' => $totalPaid,
                ':balance_due' => $balanceDue,
                ':payment_status' => $paymentStatus,
                ':due_date' => $dueDate,
                ':v' => $saleVatRate,
                ':vat_amount' => $saleVatAmount,
                ':d' => $discount,
                ':campaign' => $campaignId,
                ':p' => $data['payment_method'] ?? 'Contanti',
            ]);

            $saleId = (int) $this->pdo->lastInsertId();

            $stmtItem = $this->pdo->prepare(
                'INSERT INTO sale_items (sale_id, iccid_id, product_id, description, quantity, price, tax_rate, tax_amount, vat_code)
                 VALUES (:s, :iccid, :product, :desc, :qty, :price, :tax_rate, :tax_amount, :vat_code)'
            );
            $stmtUpdateICCID = $this->pdo->prepare(
                "UPDATE iccid_stock
                 SET status = 'Sold', updated_at = NOW()
                 WHERE id = :id AND status != 'Sold'"
            );
            $stmtFetchICCID = $this->pdo->prepare(
                'SELECT iccid FROM iccid_stock WHERE id = :id'
            );

            foreach ($items as $index => $item) {
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

                $detail = $itemTaxDetails[$index] ?? ['tax_rate' => 0.0, 'tax_amount' => 0.0, 'fallback_description' => null, 'vat_code' => null];
                $description = $item['description'] ?? null;
                if (($description === null || $description === '') && !empty($detail['fallback_description'])) {
                    $description = $detail['fallback_description'];
                }

                $quantity = (int) ($item['quantity'] ?? 1);
                if ($quantity <= 0) {
                    $quantity = 1;
                }

                $productId = null;
                if (($item['type'] ?? '') === 'product') {
                    $candidate = (int) ($item['product_id'] ?? 0);
                    if ($candidate > 0) {
                        $productId = $candidate;
                    }
                }

                $stmtItem->execute([
                    ':s' => $saleId,
                    ':iccid' => $iccidId,
                    ':product' => $productId,
                    ':desc' => $description,
                    ':qty' => $quantity,
                    ':price' => (float) $item['price'],
                    ':tax_rate' => (float) ($detail['tax_rate'] ?? 0.0),
                    ':tax_amount' => (float) ($detail['tax_amount'] ?? 0.0),
                    ':vat_code' => isset($detail['vat_code']) && $detail['vat_code'] !== '' ? (string) $detail['vat_code'] : null,
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

            if ($productUsage !== []) {
                $stmtUpdateProductStock = $this->pdo->prepare(
                    'UPDATE products SET stock_quantity = :stock WHERE id = :id'
                );
                $stmtInsertProductMovement = $this->pdo->prepare(
                    'INSERT INTO product_stock_movements (product_id, quantity_change, balance_after, reason, reference_type, reference_id, user_id, note)
                     VALUES (:product_id, :quantity_change, :balance_after, :reason, :reference_type, :reference_id, :user_id, :note)'
                );

                foreach ($productUsage as $productId => $qtySold) {
                    $currentStock = (int) ($productMap[$productId]['stock_quantity'] ?? 0);
                    $newStock = $currentStock - $qtySold;
                    $stmtUpdateProductStock->execute([
                        ':stock' => $newStock,
                        ':id' => $productId,
                    ]);
                    $productMap[$productId]['stock_quantity'] = $newStock;

                    $stmtInsertProductMovement->execute([
                        ':product_id' => $productId,
                        ':quantity_change' => -$qtySold,
                        ':balance_after' => $newStock,
                        ':reason' => 'Sale',
                        ':reference_type' => 'sale',
                        ':reference_id' => $saleId,
                        ':user_id' => $data['user_id'],
                        ':note' => $customerNote ?? $customerName,
                    ]);
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

            $stmtItems = $this->pdo->prepare('SELECT iccid_id, product_id, quantity FROM sale_items WHERE sale_id = :id');
            $stmtItems->execute([':id' => $saleId]);
            $iccids = [];
            $productRestock = [];
            while ($row = $stmtItems->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($row['iccid_id'])) {
                    $iccids[] = (int) $row['iccid_id'];
                }
                if (!empty($row['product_id'])) {
                    $productId = (int) $row['product_id'];
                    $qty = (int) ($row['quantity'] ?? 0);
                    if ($productId > 0 && $qty > 0) {
                        $productRestock[$productId] = ($productRestock[$productId] ?? 0) + $qty;
                    }
                }
            }

            if ($iccids !== []) {
                $stmtRestore = $this->pdo->prepare(
                    "UPDATE iccid_stock SET status = 'InStock', updated_at = NOW() WHERE id = :id"
                );
                foreach ($iccids as $iccidId) {
                    $stmtRestore->execute([':id' => $iccidId]);
                }
            }

            if ($productRestock !== []) {
                $stmtFetchProduct = $this->pdo->prepare('SELECT stock_quantity FROM products WHERE id = :id FOR UPDATE');
                $stmtUpdateProduct = $this->pdo->prepare('UPDATE products SET stock_quantity = :stock WHERE id = :id');
                $stmtInsertMovement = $this->pdo->prepare(
                    'INSERT INTO product_stock_movements (product_id, quantity_change, balance_after, reason, reference_type, reference_id, user_id, note)
                     VALUES (:product_id, :quantity_change, :balance_after, :reason, :reference_type, :reference_id, :user_id, :note)'
                );

                foreach ($productRestock as $productId => $qty) {
                    $stmtFetchProduct->execute([':id' => $productId]);
                    $currentStock = (int) ($stmtFetchProduct->fetchColumn() ?: 0);
                    $newStock = $currentStock + $qty;
                    $stmtUpdateProduct->execute([
                        ':stock' => $newStock,
                        ':id' => $productId,
                    ]);

                    $stmtInsertMovement->execute([
                        ':product_id' => $productId,
                        ':quantity_change' => $qty,
                        ':balance_after' => $newStock,
                        ':reason' => 'Cancel',
                        ':reference_type' => 'sale_cancel',
                        ':reference_id' => $saleId,
                        ':user_id' => $userId,
                        ':note' => $reason !== null && $reason !== '' ? $reason : null,
                    ]);
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
                'SELECT id, iccid_id, product_id, quantity, price, refunded_quantity
                 FROM sale_items
                 WHERE sale_id = :sale_id
                 FOR UPDATE'
            );
            $stmtItems->execute([':sale_id' => $saleId]);
            $saleItems = [];
            while ($row = $stmtItems->fetch(PDO::FETCH_ASSOC)) {
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
            $productReturns = [];

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
                    if (!empty($saleItem['product_id'])) {
                        $productId = (int) $saleItem['product_id'];
                        if ($productId > 0) {
                            $productReturns[$productId] = ($productReturns[$productId] ?? 0) + $quantity;
                        }
                    }
                } else {
                    $totalCreditAmount += $amount;
                }

                $saleItems[$itemId]['refunded_quantity'] += $quantity;
                $processedRows++;
            }

            if ($processedRows === 0) {
                throw new \RuntimeException('Seleziona almeno un articolo con quantità valida.');
            }

            if ($productReturns !== []) {
                $stmtFetchProduct = $this->pdo->prepare('SELECT stock_quantity FROM products WHERE id = :id FOR UPDATE');
                $stmtUpdateProduct = $this->pdo->prepare('UPDATE products SET stock_quantity = :stock WHERE id = :id');
                $stmtInsertMovement = $this->pdo->prepare(
                    'INSERT INTO product_stock_movements (product_id, quantity_change, balance_after, reason, reference_type, reference_id, user_id, note)
                     VALUES (:product_id, :quantity_change, :balance_after, :reason, :reference_type, :reference_id, :user_id, :note)'
                );

                foreach ($productReturns as $productId => $qtyReturn) {
                    $stmtFetchProduct->execute([':id' => $productId]);
                    $currentStock = (int) ($stmtFetchProduct->fetchColumn() ?: 0);
                    $newStock = $currentStock + $qtyReturn;
                    $stmtUpdateProduct->execute([
                        ':stock' => $newStock,
                        ':id' => $productId,
                    ]);

                    $stmtInsertMovement->execute([
                        ':product_id' => $productId,
                        ':quantity_change' => $qtyReturn,
                        ':balance_after' => $newStock,
                        ':reason' => 'Refund',
                        ':reference_type' => 'sale_refund',
                        ':reference_id' => $saleId,
                        ':user_id' => $userId,
                        ':note' => $generalNote !== null && trim($generalNote) !== '' ? trim($generalNote) : null,
                    ]);
                }
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
    public function getSaleWithItems(int $saleId, ?int $customerId = null): ?array
    {
        $sql = 'SELECT s.*, u.fullname, u.username,
                       c.fullname AS customer_fullname,
                       c.email AS customer_email,
                       c.phone AS customer_phone,
                       c.tax_code AS customer_tax_code
                FROM sales s
                LEFT JOIN users u ON u.id = s.user_id
                LEFT JOIN customers c ON c.id = s.customer_id
                WHERE s.id = :id';

        $params = [':id' => $saleId];
        if ($customerId !== null) {
            $sql .= ' AND s.customer_id = :customer_id';
            $params[':customer_id'] = $customerId;
        }

        $stmtSale = $this->pdo->prepare($sql);
        $stmtSale->execute($params);
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
                    $conditions[] = '(
                        s.customer_name LIKE :term OR
                        s.customer_note LIKE :term OR
                        u.fullname LIKE :term OR
                        u.username LIKE :term OR
                        c.fullname LIKE :term OR
                        c.email LIKE :term
                    )';
                    $params[':term'] = '%' . $q . '%';
                }
            }
        }

        $where = $conditions !== [] ? ('WHERE ' . implode(' AND ', $conditions)) : '';

        $countSql = 'SELECT COUNT(*)
                     FROM sales s
                     LEFT JOIN users u ON u.id = s.user_id
                     LEFT JOIN customers c ON c.id = s.customer_id
                     ' . $where;
        $stmtCount = $this->pdo->prepare($countSql);
        foreach ($params as $key => $value) {
            $stmtCount->bindValue($key, $value);
        }
        $stmtCount->execute();
        $total = (int) $stmtCount->fetchColumn();

        $pages = (int) max((int) ceil($total / $perPage), 1);
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

    $dataSql = 'SELECT s.*, u.fullname, u.username, c.fullname AS customer_fullname, c.email AS customer_email
            FROM sales s
            LEFT JOIN users u ON u.id = s.user_id
            LEFT JOIN customers c ON c.id = s.customer_id
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

    /**
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   pagination: array{page:int, per_page:int, total:int, pages:int}
     * }
     */
    public function listCustomerSales(
        int $customerId,
        int $page,
        int $perPage,
        ?string $status = null,
        ?string $paymentStatus = null
    ): array {
        $customerId = max(1, $customerId);
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 30));

        $conditions = ['s.customer_id = :customer_id'];
        $params = [':customer_id' => $customerId];

        if ($status !== null && in_array($status, ['Completed', 'Cancelled', 'Refunded'], true)) {
            $conditions[] = 's.status = :status';
            $params[':status'] = $status;
        }

        if ($paymentStatus !== null && in_array($paymentStatus, ['Paid', 'Partial', 'Pending', 'Overdue'], true)) {
            $conditions[] = 's.payment_status = :payment_status';
            $params[':payment_status'] = $paymentStatus;
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        $countSql = 'SELECT COUNT(*) FROM sales s ' . $where;
        $stmtCount = $this->pdo->prepare($countSql);
        foreach ($params as $key => $value) {
            $stmtCount->bindValue($key, $value);
        }
        $stmtCount->execute();
        $total = (int) ($stmtCount->fetchColumn() ?: 0);

        $pages = (int) max(1, ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $dataSql = 'SELECT s.id, s.total, s.total_paid, s.balance_due, s.payment_status, s.status,
                           s.created_at, s.due_date, s.discount, s.refunded_amount, s.payment_method
                    FROM sales s
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
        $rows = $stmtData->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
