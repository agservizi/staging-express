<?php
declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use PDO;
use PDOException;

/**
 * Servizi dedicati al portale clienti: riepiloghi, vendite, pagamenti e richieste di supporto.
 */
final class CustomerPortalService
{
    public function __construct(
        private PDO $pdo,
        private SalesService $salesService,
        private ProductService $productService,
        private ?SystemNotificationService $notificationService = null
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAccountProfile(int $accountId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id,
                    a.email,
                    a.customer_id,
                    a.last_login_at,
                    c.fullname,
                    c.email AS customer_email,
                    c.phone,
                    c.tax_code,
                    c.note,
                    c.created_at
             FROM customer_portal_accounts a
             INNER JOIN customers c ON c.id = a.customer_id
             WHERE a.id = :id'
        );
        $stmt->execute([':id' => $accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDashboard(int $customerId, int $portalAccountId): array
    {
        $summaryStmt = $this->pdo->prepare(
            'SELECT
                COALESCE(SUM(total), 0) AS total_spent,
                COALESCE(SUM(total_paid), 0) AS total_paid,
                COALESCE(SUM(balance_due), 0) AS total_due,
                SUM(CASE WHEN payment_status = "Overdue" THEN 1 ELSE 0 END) AS overdue_sales,
                SUM(CASE WHEN payment_status IN ("Pending", "Partial") THEN 1 ELSE 0 END) AS pending_sales
             FROM sales
             WHERE customer_id = :customer_id'
        );
        $summaryStmt->execute([':customer_id' => $customerId]);
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $nextDueStmt = $this->pdo->prepare(
            'SELECT MIN(due_date) FROM sales
             WHERE customer_id = :customer_id
               AND balance_due > 0
               AND due_date IS NOT NULL'
        );
        $nextDueStmt->execute([':customer_id' => $customerId]);
        $nextDue = $nextDueStmt->fetchColumn();

        $recentSalesStmt = $this->pdo->prepare(
            'SELECT id, created_at, total, payment_status, balance_due
             FROM sales
             WHERE customer_id = :customer_id
             ORDER BY created_at DESC
             LIMIT 5'
        );
        $recentSalesStmt->execute([':customer_id' => $customerId]);
        $recentSales = $recentSalesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $supportCountStmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM customer_support_requests
             WHERE customer_id = :customer_id
               AND portal_account_id = :portal_account_id
               AND status IN ("Open", "InProgress")'
        );
        $supportCountStmt->execute([
            ':customer_id' => $customerId,
            ':portal_account_id' => $portalAccountId,
        ]);
        $openRequests = (int) ($supportCountStmt->fetchColumn() ?: 0);

        return [
            'totals' => [
                'spent' => (float) ($summary['total_spent'] ?? 0.0),
                'paid' => (float) ($summary['total_paid'] ?? 0.0),
                'due' => (float) ($summary['total_due'] ?? 0.0),
                'overdue_sales' => (int) ($summary['overdue_sales'] ?? 0),
                'pending_sales' => (int) ($summary['pending_sales'] ?? 0),
            ],
            'next_due_date' => $nextDue ? (new DateTimeImmutable((string) $nextDue))->format('Y-m-d') : null,
            'recent_sales' => $recentSales,
            'open_support_requests' => $openRequests,
        ];
    }

    /**
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   pagination: array{page:int, per_page:int, total:int, pages:int}
     * }
     */
    public function listSales(int $customerId, int $page, int $perPage, ?string $status = null, ?string $paymentStatus = null): array
    {
        return $this->salesService->listCustomerSales($customerId, $page, $perPage, $status, $paymentStatus);
    }

    /**
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   pagination: array{page:int, per_page:int, total:int, pages:int},
     *   filters: array{category:?string, search:?string, per_page:int},
     *   categories: array<int, string>
     * }
     */
    public function listCatalogProducts(int $page, int $perPage, ?string $category, ?string $search): array
    {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 48));

        $conditions = ['is_active = 1'];
        $params = [];

        if ($category !== null && $category !== '') {
            $conditions[] = 'category = :category';
            $params[':category'] = $category;
        }

        if ($search !== null && $search !== '') {
            $conditions[] = '(name LIKE :term OR sku LIKE :term)';
            $params[':term'] = '%' . $search . '%';
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM products ' . $where);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $pages = max(1, (int) ceil($total / $perPage));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $perPage;

        $dataStmt = $this->pdo->prepare(
            'SELECT id, name, sku, category, price, tax_rate, stock_quantity, stock_reserved, notes
             FROM products
             ' . $where . '
             ORDER BY name ASC
             LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $dataStmt->bindValue($key, $value);
        }
        $dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $dataStmt->execute();
        $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$row) {
            $stock = (int) ($row['stock_quantity'] ?? 0);
            $reserved = (int) ($row['stock_reserved'] ?? 0);
            $row['available_stock'] = max($stock - $reserved, 0);
        }
        unset($row);

        $categories = [];
        $categoriesStmt = $this->pdo->query(
            'SELECT DISTINCT category
             FROM products
             WHERE category IS NOT NULL AND category != ""
             ORDER BY category ASC'
        );
        if ($categoriesStmt !== false) {
            $categories = array_map(static fn ($value): string => (string) $value, $categoriesStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        }

        return [
            'rows' => $rows,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => $pages,
            ],
            'filters' => [
                'category' => $category !== null && $category !== '' ? $category : null,
                'search' => $search !== null && $search !== '' ? $search : null,
                'per_page' => $perPage,
            ],
            'categories' => $categories,
        ];
    }

    /**
     * @return array<int, array{id:int, name:string, category:?string, price:float, stock_quantity:int, tax_rate:float}>
     */
    public function listCatalogProductOptions(): array
    {
        $products = $this->productService->listActive();
        $options = [];
        foreach ($products as $product) {
            $options[] = [
                'id' => (int) ($product['id'] ?? 0),
                'name' => (string) ($product['name'] ?? ''),
                'category' => isset($product['category']) && $product['category'] !== '' ? (string) $product['category'] : null,
                'price' => (float) ($product['price'] ?? 0.0),
                'stock_quantity' => (int) ($product['stock_quantity'] ?? 0),
                'tax_rate' => (float) ($product['tax_rate'] ?? 0.0),
            ];
        }

        return $options;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSaleDetail(int $customerId, int $saleId): ?array
    {
        $sale = $this->salesService->getSaleWithItems($saleId, $customerId);
        if ($sale === null) {
            return null;
        }

        $paymentsStmt = $this->pdo->prepare(
            'SELECT id, amount, payment_method, status, provider_reference, note, created_at, portal_account_id
             FROM customer_payments
             WHERE sale_id = :sale_id
             ORDER BY created_at DESC'
        );
        $paymentsStmt->execute([':sale_id' => $saleId]);
        $sale['payments'] = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return $sale;
    }

    /**
     * @return array{success:bool, message:string, errors?:array<int, string>}
     */
    public function createProductRequest(int $customerId, int $portalAccountId, array $payload): array
    {
        $customerId = max(1, $customerId);
        $portalAccountId = max(1, $portalAccountId);

        $productId = isset($payload['product_id']) ? (int) $payload['product_id'] : 0;
        if ($productId <= 0) {
            return [
                'success' => false,
                'message' => 'Seleziona un prodotto.',
                'errors' => ['Scegli il prodotto che desideri acquistare o prenotare.'],
            ];
        }

        $product = $this->productService->findById($productId);
        if ($product === null || (int) ($product['is_active'] ?? 0) !== 1) {
            return [
                'success' => false,
                'message' => 'Prodotto non disponibile.',
                'errors' => ['Il prodotto scelto non è più disponibile a catalogo.'],
            ];
        }

        $typeRaw = isset($payload['request_type']) ? (string) $payload['request_type'] : 'Purchase';
        $normalizedType = strtoupper($typeRaw);
        $typeMap = [
            'PURCHASE' => 'Purchase',
            'RESERVATION' => 'Reservation',
            'DEPOSIT' => 'Deposit',
            'INSTALLMENT' => 'Installment',
        ];
        $requestType = $typeMap[$normalizedType] ?? 'Purchase';

        $depositAmount = null;
        if (isset($payload['deposit_amount']) && $payload['deposit_amount'] !== '') {
            $depositAmount = (float) $payload['deposit_amount'];
            if ($depositAmount < 0) {
                return [
                    'success' => false,
                    'message' => 'Importo acconto non valido.',
                    'errors' => ['L\'acconto deve essere positivo.'],
                ];
            }
        }

        $price = (float) ($product['price'] ?? 0.0);
        if ($requestType === 'Deposit' && ($depositAmount === null || $depositAmount <= 0.0)) {
            return [
                'success' => false,
                'message' => 'Indica l\'importo dell\'acconto.',
                'errors' => ['Per confermare un acconto inserisci l\'importo desiderato.'],
            ];
        }

        if ($depositAmount !== null && $price > 0 && $depositAmount > $price) {
            return [
                'success' => false,
                'message' => 'Acconto troppo alto.',
                'errors' => ['L\'importo inserito non può superare il prezzo del prodotto.'],
            ];
        }

        $installments = null;
        if ($requestType === 'Installment') {
            $installments = isset($payload['installments']) ? (int) $payload['installments'] : 6;
            if ($installments <= 0) {
                $installments = 6;
            }
            $installments = min($installments, 24);
        }

        $paymentMethodRaw = isset($payload['payment_method']) ? (string) $payload['payment_method'] : 'BankTransfer';
        $paymentAllowed = ['BankTransfer', 'InStore', 'Other'];
        $paymentMethod = in_array($paymentMethodRaw, $paymentAllowed, true) ? $paymentMethodRaw : 'BankTransfer';

        $note = isset($payload['note']) ? trim((string) $payload['note']) : '';
        if ($note !== '' && function_exists('mb_strlen') && mb_strlen($note) > 1000) {
            $note = mb_substr($note, 0, 1000);
        } elseif ($note !== '' && strlen($note) > 1000) {
            $note = substr($note, 0, 1000);
        }

        $desiredPickup = null;
        if (isset($payload['desired_pickup_date']) && $payload['desired_pickup_date'] !== '') {
            $pickupCandidate = date_create((string) $payload['desired_pickup_date']);
            if ($pickupCandidate === false) {
                return [
                    'success' => false,
                    'message' => 'Data ritiro non valida.',
                    'errors' => ['Inserisci una data di ritiro valida (formato YYYY-MM-DD).'],
                ];
            }
            $desiredPickup = $pickupCandidate->format('Y-m-d');
        }

        $bankReference = isset($payload['bank_reference']) ? trim((string) $payload['bank_reference']) : null;
        if ($bankReference === '') {
            $bankReference = null;
        }

        $duplicateStmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM customer_product_requests
             WHERE customer_id = :customer AND portal_account_id = :account AND product_id = :product
               AND status IN ("Pending","InReview")'
        );
        $duplicateStmt->execute([
            ':customer' => $customerId,
            ':account' => $portalAccountId,
            ':product' => $productId,
        ]);
        $hasPending = (int) ($duplicateStmt->fetchColumn() ?: 0) > 0;
        if ($hasPending) {
            return [
                'success' => false,
                'message' => 'Richiesta già registrata.',
                'errors' => ['Hai già una richiesta aperta per questo prodotto. Riceverai aggiornamenti a breve.'],
            ];
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO customer_product_requests (
                    customer_id,
                    portal_account_id,
                    product_id,
                    product_name,
                    product_price,
                    request_type,
                    status,
                    deposit_amount,
                    installments,
                    payment_method,
                    desired_pickup_date,
                    bank_transfer_reference,
                    note,
                    created_at,
                    updated_at
                ) VALUES (
                    :customer,
                    :account,
                    :product,
                    :product_name,
                    :product_price,
                    :type,
                    "Pending",
                    :deposit,
                    :installments,
                    :payment_method,
                    :pickup,
                    :bank_reference,
                    :note,
                    NOW(),
                    NOW()
                )'
            );
            $stmt->execute([
                ':customer' => $customerId,
                ':account' => $portalAccountId,
                ':product' => $productId,
                ':product_name' => (string) ($product['name'] ?? ''),
                ':product_price' => $price,
                ':type' => $requestType,
                ':deposit' => $depositAmount,
                ':installments' => $installments,
                ':payment_method' => $paymentMethod,
                ':pickup' => $desiredPickup,
                ':bank_reference' => $bankReference,
                ':note' => $note !== '' ? $note : null,
            ]);

            $requestId = (int) $this->pdo->lastInsertId();
            [$customerName, $portalEmail] = $this->resolvePortalIdentity($portalAccountId, $customerId);
            $requestLabels = [
                'Purchase' => 'di acquisto',
                'Reservation' => 'di prenotazione',
                'Deposit' => 'con acconto',
                'Installment' => 'con pagamento rateale',
            ];
            $methodLabels = [
                'BankTransfer' => 'Bonifico',
                'InStore' => 'Pagamento in negozio',
                'Other' => 'Metodo non specificato',
            ];
            $requestLabel = $requestLabels[$requestType] ?? strtolower($requestType);
            $methodLabel = $methodLabels[$paymentMethod] ?? $paymentMethod;
            $productName = (string) ($product['name'] ?? ('Prodotto #' . $productId));
            $bodyParts = [
                sprintf('%s ha inviato una richiesta %s per "%s".', $customerName, $requestLabel, $productName),
                'Metodo indicato: ' . $methodLabel . '.',
            ];
            if ($depositAmount !== null) {
                $bodyParts[] = 'Acconto indicato: € ' . number_format($depositAmount, 2, ',', '.') . '.';
            }
            if ($desiredPickup !== null) {
                $pickupDate = DateTimeImmutable::createFromFormat('Y-m-d', $desiredPickup) ?: null;
                $bodyParts[] = 'Ritiro richiesto: ' . ($pickupDate !== null ? $pickupDate->format('d/m/Y') : $desiredPickup) . '.';
            }
            if ($note !== '') {
                $bodyParts[] = 'Il cliente ha inserito una nota.';
            }
            $meta = [
                'request_id' => $requestId,
                'customer_id' => $customerId,
                'portal_account_id' => $portalAccountId,
                'product_id' => $productId,
                'product_name' => $productName,
                'request_type' => $requestType,
                'deposit_amount' => $depositAmount,
                'installments' => $installments,
                'payment_method' => $paymentMethod,
                'desired_pickup_date' => $desiredPickup,
                'bank_reference' => $bankReference,
                'note' => $note,
                'portal_email' => $portalEmail,
                'customer_name' => $customerName,
            ];
            $this->notifyPortalEvent(
                'portal_product_request',
                'Nuova richiesta prodotto dal portale',
                implode(' ', $bodyParts),
                $meta,
                'info',
                'index.php?page=product_requests'
            );
        } catch (PDOException $exception) {
            return [
                'success' => false,
                'message' => 'Impossibile registrare la richiesta.',
                'errors' => ['Database: ' . $exception->getMessage()],
            ];
        }

        return [
            'success' => true,
            'message' => 'Richiesta ricevuta correttamente. Ti ricontatteremo appena il dispositivo sarà disponibile.',
            'payment_method' => $paymentMethod,
            'need_transfer_info' => $paymentMethod === 'BankTransfer' || $requestType === 'Deposit' || $requestType === 'Installment',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listProductRequests(int $customerId, int $portalAccountId): array
    {
        $customerId = max(1, $customerId);
        $portalAccountId = max(1, $portalAccountId);

        $stmt = $this->pdo->prepare(
            'SELECT r.id,
                    r.product_id,
                    r.product_name,
                    r.product_price,
                    r.request_type,
                    r.status,
                    r.deposit_amount,
                    r.installments,
                    r.payment_method,
                    r.desired_pickup_date,
                    r.bank_transfer_reference,
                    r.note,
                    r.created_at,
                    r.updated_at,
                    p.category AS current_category
             FROM customer_product_requests r
             LEFT JOIN products p ON p.id = r.product_id
             WHERE r.customer_id = :customer AND r.portal_account_id = :account
             ORDER BY r.created_at DESC'
        );
        $stmt->execute([
            ':customer' => $customerId,
            ':account' => $portalAccountId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   pagination: array{page:int, per_page:int, total:int, pages:int}
     * }
     */
    public function listPayments(int $portalAccountId, int $page, int $perPage, ?string $status = null): array
    {
        $portalAccountId = max(1, $portalAccountId);
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 30));

        $conditions = ['portal_account_id = :account_id'];
        $params = [':account_id' => $portalAccountId];

        if ($status !== null && in_array($status, ['Pending', 'Succeeded', 'Failed'], true)) {
            $conditions[] = 'status = :status';
            $params[':status'] = $status;
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM customer_payments ' . $where);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $pages = (int) max(1, ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $dataStmt = $this->pdo->prepare(
            'SELECT id, sale_id, amount, payment_method, status, provider_reference, note, created_at
             FROM customer_payments
             ' . $where . '
             ORDER BY created_at DESC
             LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $dataStmt->bindValue($key, $value);
        }
        $dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $dataStmt->execute();
        $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
     * @param array{sale_id?:int, amount?:float|int, payment_method?:string, note?:string|null} $payload
     * @return array{success:bool, message:string, errors?:array<int, string>}
     */
    public function createPaymentRequest(int $portalAccountId, int $customerId, array $payload): array
    {
        $saleId = isset($payload['sale_id']) ? (int) $payload['sale_id'] : 0;
        $amount = isset($payload['amount']) ? (float) $payload['amount'] : 0.0;
        $method = isset($payload['payment_method']) ? trim((string) $payload['payment_method']) : 'BankTransfer';
        $note = isset($payload['note']) ? trim((string) $payload['note']) : null;

        if ($saleId <= 0) {
            return [
                'success' => false,
                'message' => 'Nessuna vendita selezionata.',
                'errors' => ['Seleziona la vendita da saldare.'],
            ];
        }

        if ($amount <= 0) {
            return [
                'success' => false,
                'message' => 'Importo non valido.',
                'errors' => ['L\'importo deve essere maggiore di zero.'],
            ];
        }

        $sale = $this->salesService->getSaleWithItems($saleId, $customerId);
        if ($sale === null) {
            return [
                'success' => false,
                'message' => 'Vendita non trovata.',
                'errors' => ['La vendita selezionata non è associata al tuo profilo.'],
            ];
        }

        $balanceDue = isset($sale['balance_due']) ? (float) $sale['balance_due'] : 0.0;
        if ($balanceDue <= 0.0) {
            return [
                'success' => false,
                'message' => 'Nessun saldo residuo.',
                'errors' => ['Questa vendita risulta già saldata.'],
            ];
        }

        if ($amount > $balanceDue) {
            return [
                'success' => false,
                'message' => 'Importo troppo alto.',
                'errors' => ['Puoi registrare al massimo € ' . number_format($balanceDue, 2, ',', '.') . '.'],
            ];
        }

        $allowedMethods = ['Card', 'BankTransfer', 'Cash', 'Other'];
        if (!in_array($method, $allowedMethods, true)) {
            $method = 'Other';
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO customer_payments (sale_id, portal_account_id, amount, payment_method, status, note, created_at)
                 VALUES (:sale_id, :account_id, :amount, :method, "Pending", :note, NOW())'
            );
            $stmt->execute([
                ':sale_id' => $saleId,
                ':account_id' => $portalAccountId,
                ':amount' => $amount,
                ':method' => $method,
                ':note' => $note !== null && $note !== '' ? $note : null,
            ]);

            $paymentId = (int) $this->pdo->lastInsertId();
            [$customerName, $portalEmail] = $this->resolvePortalIdentity($portalAccountId, $customerId);
            $methodLabels = [
                'Card' => 'Carta',
                'BankTransfer' => 'Bonifico',
                'Cash' => 'Contanti',
                'Other' => 'Altro',
            ];
            $methodLabel = $methodLabels[$method] ?? $method;
            $formattedAmount = number_format($amount, 2, ',', '.');
            $remaining = max($balanceDue - $amount, 0.0);
            $body = sprintf(
                '%s ha segnalato un pagamento di € %s per la vendita #%d. Metodo: %s. Residuo stimato: € %s.',
                $customerName,
                $formattedAmount,
                $saleId,
                $methodLabel,
                number_format($remaining, 2, ',', '.')
            );
            if ($note !== null && $note !== '') {
                $body .= ' Nota cliente presente.';
            }
            $meta = [
                'payment_id' => $paymentId,
                'sale_id' => $saleId,
                'amount' => $amount,
                'payment_method' => $method,
                'note' => $note,
                'portal_email' => $portalEmail,
                'customer_id' => $customerId,
                'portal_account_id' => $portalAccountId,
                'customer_name' => $customerName,
                'balance_due_before' => $balanceDue,
                'balance_due_after' => $remaining,
            ];
            $this->notifyPortalEvent(
                'portal_payment_signal',
                'Pagamento segnalato dal portale',
                $body,
                $meta,
                'warning',
                'index.php?page=sales_list'
            );
        } catch (PDOException $exception) {
            return [
                'success' => false,
                'message' => 'Impossibile registrare il pagamento.',
                'errors' => ['Database: ' . $exception->getMessage()],
            ];
        }

        return [
            'success' => true,
            'message' => 'Pagamento segnalato correttamente. Ti avviseremo quando verrà contabilizzato.',
            'payment_method' => $method,
            'need_transfer_info' => $method === 'BankTransfer',
        ];
    }

    /**
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   pagination: array{page:int, per_page:int, total:int, pages:int}
     * }
     */
    public function listSupportRequests(int $customerId, int $portalAccountId, int $page, int $perPage, ?string $status = null): array
    {
        $customerId = max(1, $customerId);
        $portalAccountId = max(1, $portalAccountId);
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 30));

        $conditions = [
            'customer_id = :customer_id',
            'portal_account_id = :portal_account_id',
        ];
        $params = [
            ':customer_id' => $customerId,
            ':portal_account_id' => $portalAccountId,
        ];

        if ($status !== null && in_array($status, ['Open', 'InProgress', 'Completed', 'Cancelled'], true)) {
            $conditions[] = 'status = :status';
            $params[':status'] = $status;
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM customer_support_requests ' . $where);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $pages = (int) max(1, ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $dataStmt = $this->pdo->prepare(
            'SELECT id, type, subject, status, preferred_slot, created_at, updated_at
             FROM customer_support_requests
             ' . $where . '
             ORDER BY created_at DESC
             LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $dataStmt->bindValue($key, $value);
        }
        $dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $dataStmt->execute();
        $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
     * @param array{type?:string, subject?:string, message?:string, preferred_slot?:string|null} $payload
     * @return array{success:bool, message:string, errors?:array<int, string>}
     */
    public function createSupportRequest(int $customerId, int $portalAccountId, array $payload): array
    {
        $type = isset($payload['type']) ? (string) $payload['type'] : 'Support';
        $subject = isset($payload['subject']) ? trim((string) $payload['subject']) : '';
        $message = isset($payload['message']) ? trim((string) $payload['message']) : '';
        $preferredSlot = isset($payload['preferred_slot']) ? trim((string) $payload['preferred_slot']) : null;

        if (!in_array($type, ['Support', 'Booking'], true)) {
            $type = 'Support';
        }
        if ($subject === '') {
            return [
                'success' => false,
                'message' => 'Oggetto obbligatorio.',
                'errors' => ['Inserisci un oggetto per la richiesta.'],
            ];
        }
        if ($message === '') {
            return [
                'success' => false,
                'message' => 'Messaggio obbligatorio.',
                'errors' => ['Descrivi la richiesta.'],
            ];
        }

        $slotValue = null;
        if ($preferredSlot !== null && $preferredSlot !== '') {
            $slot = date_create($preferredSlot);
            if ($slot !== false) {
                $slotValue = $slot->format('Y-m-d H:i:s');
            }
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO customer_support_requests (customer_id, portal_account_id, type, subject, message, preferred_slot, status, created_at, updated_at)
                 VALUES (:customer_id, :account_id, :type, :subject, :message, :slot, "Open", NOW(), NOW())'
            );
            $stmt->execute([
                ':customer_id' => $customerId,
                ':account_id' => $portalAccountId,
                ':type' => $type,
                ':subject' => $subject,
                ':message' => $message,
                ':slot' => $slotValue,
            ]);

            $supportRequestId = (int) $this->pdo->lastInsertId();
            [$customerName, $portalEmail] = $this->resolvePortalIdentity($portalAccountId, $customerId);
            $typeLabels = [
                'Support' => 'di supporto',
                'Booking' => 'di prenotazione',
            ];
            $typeLabel = $typeLabels[$type] ?? strtolower($type);
            $bodyParts = [
                sprintf('%s ha inviato una richiesta %s: "%s".', $customerName, $typeLabel, $subject),
            ];
            if ($slotValue !== null) {
                $slot = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $slotValue) ?: null;
                $bodyParts[] = 'Fascia preferita: ' . ($slot !== null ? $slot->format('d/m/Y H:i') : $slotValue) . '.';
            }
            $meta = [
                'support_request_id' => $supportRequestId,
                'customer_id' => $customerId,
                'portal_account_id' => $portalAccountId,
                'type' => $type,
                'subject' => $subject,
                'message' => $message,
                'preferred_slot' => $slotValue,
                'portal_email' => $portalEmail,
                'customer_name' => $customerName,
            ];
            $this->notifyPortalEvent(
                'portal_support_request',
                'Nuova richiesta di supporto dal portale',
                implode(' ', $bodyParts),
                $meta,
                'info',
                'index.php?page=support_requests'
            );
        } catch (PDOException $exception) {
            return [
                'success' => false,
                'message' => 'Impossibile inviare la richiesta.',
                'errors' => ['Database: ' . $exception->getMessage()],
            ];
        }

        return [
            'success' => true,
            'message' => 'Richiesta inviata. Ti contatteremo al più presto.',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSupportRequest(int $customerId, int $portalAccountId, int $requestId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, type, subject, message, preferred_slot, status, resolution_note, created_at, updated_at
             FROM customer_support_requests
             WHERE id = :id AND customer_id = :customer_id AND portal_account_id = :account_id'
        );
        $stmt->execute([
            ':id' => $requestId,
            ':customer_id' => $customerId,
            ':account_id' => $portalAccountId,
        ]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        return $request !== false ? $request : null;
    }

    /**
     * @return array{0:string,1:?string}
     */
    private function resolvePortalIdentity(int $portalAccountId, int $customerId): array
    {
        $profile = $this->getAccountProfile($portalAccountId);
        $customerName = 'Cliente #' . $customerId;
        $portalEmail = null;

        if (is_array($profile)) {
            $nameCandidate = $profile['fullname'] ?? null;
            if (is_string($nameCandidate) && $nameCandidate !== '') {
                $customerName = $nameCandidate;
            }

            $emailCandidate = $profile['email'] ?? ($profile['customer_email'] ?? null);
            if (is_string($emailCandidate) && $emailCandidate !== '') {
                $portalEmail = $emailCandidate;
            }
        }

        return [$customerName, $portalEmail];
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function notifyPortalEvent(string $type, string $title, string $body, array $meta, string $level, ?string $link = null): void
    {
        if ($this->notificationService === null) {
            return;
        }

        $options = [
            'level' => $level,
            'channel' => 'customer_portal',
            'source' => 'customer_portal',
            'meta' => $meta,
        ];

        if ($link !== null) {
            $options['link'] = $link;
        }

        $this->notificationService->push($type, $title, $body, $options);
    }
}
