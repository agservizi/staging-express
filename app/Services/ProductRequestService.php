<?php
declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use PDO;
use PDOException;

final class ProductRequestService
{
    private const STATUSES = ['Pending', 'InReview', 'Confirmed', 'Completed', 'Cancelled', 'Declined'];
    private const TYPES = ['Purchase', 'Reservation', 'Deposit', 'Installment'];
    private const PAYMENT_METHODS = ['BankTransfer', 'InStore', 'Other'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   pagination: array{page:int, per_page:int, total:int, pages:int},
     *   filters: array{status:?string,type:?string,payment:?string,q:?string,from:?string,to:?string}
     * }
     */
    public function listRequests(array $filters, int $page, int $perPage): array
    {
        $status = isset($filters['status']) && in_array($filters['status'], self::STATUSES, true)
            ? (string) $filters['status']
            : null;
        $type = isset($filters['type']) && in_array($filters['type'], self::TYPES, true)
            ? (string) $filters['type']
            : null;
        $payment = isset($filters['payment']) && in_array($filters['payment'], self::PAYMENT_METHODS, true)
            ? (string) $filters['payment']
            : null;
        $search = isset($filters['q']) ? trim((string) $filters['q']) : '';
        $from = isset($filters['from']) ? $this->normalizeDate((string) $filters['from']) : null;
        $to = isset($filters['to']) ? $this->normalizeDate((string) $filters['to']) : null;

        $page = max(1, $page);
        $perPage = max(1, min($perPage, 50));

        $conditions = [];
        $params = [];

        if ($status !== null) {
            $conditions[] = 'r.status = :status';
            $params[':status'] = $status;
        }
        if ($type !== null) {
            $conditions[] = 'r.request_type = :type';
            $params[':type'] = $type;
        }
        if ($payment !== null) {
            $conditions[] = 'r.payment_method = :payment';
            $params[':payment'] = $payment;
        }
        if ($search !== '') {
            $conditions[] = '(
                r.product_name LIKE :search OR
                c.fullname LIKE :search OR
                c.email LIKE :search OR
                cp.email LIKE :search
            )';
            $params[':search'] = '%' . $search . '%';
        }
        if ($from !== null) {
            $conditions[] = 'DATE(r.created_at) >= :from';
            $params[':from'] = $from;
        }
        if ($to !== null) {
            $conditions[] = 'DATE(r.created_at) <= :to';
            $params[':to'] = $to;
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM customer_product_requests r ' . $where);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $pages = (int) max(1, ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $sql = 'SELECT
                    r.id,
                    r.customer_id,
                    r.portal_account_id,
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
                    r.handling_note,
                    r.handled_at,
                    r.created_at,
                    r.updated_at,
                    c.fullname AS customer_name,
                    c.email AS customer_email,
                    c.phone AS customer_phone,
                    cp.email AS portal_email,
                    u.fullname AS handled_by_name,
                    u.username AS handled_by_username
                FROM customer_product_requests r
                LEFT JOIN customers c ON c.id = r.customer_id
                LEFT JOIN customer_portal_accounts cp ON cp.id = r.portal_account_id
                LEFT JOIN users u ON u.id = r.handled_by
                ' . $where . '
                ORDER BY r.created_at DESC
                LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
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
                'pages' => $pages,
            ],
            'filters' => [
                'status' => $status,
                'type' => $type,
                'payment' => $payment,
                'q' => $search !== '' ? $search : null,
                'from' => $from,
                'to' => $to,
            ],
        ];
    }

    public function getRequest(int $requestId): ?array
    {
        if ($requestId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT
                r.id,
                r.customer_id,
                r.portal_account_id,
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
                r.handling_note,
                r.handled_by,
                r.handled_at,
                r.created_at,
                r.updated_at,
                c.fullname AS customer_name,
                c.email AS customer_email,
                c.phone AS customer_phone,
                cp.email AS portal_email,
                u.fullname AS handled_by_name,
                u.username AS handled_by_username,
                p.category AS product_category,
                p.stock_quantity,
                p.stock_reserved
             FROM customer_product_requests r
             LEFT JOIN customers c ON c.id = r.customer_id
             LEFT JOIN customer_portal_accounts cp ON cp.id = r.portal_account_id
             LEFT JOIN users u ON u.id = r.handled_by
             LEFT JOIN products p ON p.id = r.product_id
             WHERE r.id = :id'
        );
        $stmt->execute([':id' => $requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $operator
     * @return array{success:bool,message?:string,errors?:array<int,string>}
     */
    public function updateRequest(int $requestId, array $input, array $operator): array
    {
        $status = isset($input['status']) ? (string) $input['status'] : '';
        if (!in_array($status, self::STATUSES, true)) {
            return [
                'success' => false,
                'message' => 'Aggiornamento non valido.',
                'errors' => ['Seleziona uno stato valido.'],
            ];
        }

        $note = isset($input['handling_note']) ? trim((string) $input['handling_note']) : '';
        $append = isset($input['append_note']) && (string) $input['append_note'] === '1';
        $paymentMethod = isset($input['payment_method']) ? (string) $input['payment_method'] : '';
        if ($paymentMethod !== '' && !in_array($paymentMethod, self::PAYMENT_METHODS, true)) {
            return [
                'success' => false,
                'message' => 'Aggiornamento non valido.',
                'errors' => ['Seleziona un metodo di pagamento ammesso.'],
            ];
        }
        $bankReference = isset($input['bank_transfer_reference']) ? trim((string) $input['bank_transfer_reference']) : null;
        if ($bankReference !== null && $bankReference === '') {
            $bankReference = null;
        }
        if ($bankReference !== null && strlen($bankReference) > 120) {
            return [
                'success' => false,
                'message' => 'Riferimento bonifico troppo lungo.',
                'errors' => ['Il riferimento bonifico può contenere al massimo 120 caratteri.'],
            ];
        }

        try {
            $this->pdo->beginTransaction();
            $currentStmt = $this->pdo->prepare(
                'SELECT handling_note FROM customer_product_requests WHERE id = :id FOR UPDATE'
            );
            $currentStmt->execute([':id' => $requestId]);
            $current = $currentStmt->fetch(PDO::FETCH_ASSOC);
            if ($current === false) {
                $this->pdo->rollBack();
                return [
                    'success' => false,
                    'message' => 'Richiesta non trovata.',
                    'errors' => ['La richiesta selezionata non è più disponibile.'],
                ];
            }

            $existingNote = $current['handling_note'] ?? null;
            $nextNote = $existingNote;
            if ($note !== '') {
                $nextNote = $append
                    ? $this->appendNote($existingNote, $note, $operator)
                    : $note;
            }
            if ($nextNote !== null) {
                $nextNote = trim((string) $nextNote);
                if ($nextNote === '') {
                    $nextNote = null;
                }
            }

            $updateSql = 'UPDATE customer_product_requests
                SET status = :status,
                    handling_note = :handling_note,
                    updated_at = NOW(),
                    handled_at = NOW(),
                    handled_by = :handled_by';
            if ($paymentMethod !== '') {
                $updateSql .= ', payment_method = :payment_method';
            }
            if ($bankReference !== null || array_key_exists('bank_transfer_reference', $input)) {
                $updateSql .= ', bank_transfer_reference = :bank_reference';
            }
            $updateSql .= ' WHERE id = :id';

            $update = $this->pdo->prepare($updateSql);
            $update->bindValue(':status', $status);
            if ($nextNote === null) {
                $update->bindValue(':handling_note', null, PDO::PARAM_NULL);
            } else {
                $update->bindValue(':handling_note', $nextNote, PDO::PARAM_STR);
            }
            $handledBy = isset($operator['id']) ? (int) $operator['id'] : null;
            if ($handledBy === null || $handledBy <= 0) {
                $update->bindValue(':handled_by', null, PDO::PARAM_NULL);
            } else {
                $update->bindValue(':handled_by', $handledBy, PDO::PARAM_INT);
            }
            if ($paymentMethod !== '') {
                $update->bindValue(':payment_method', $paymentMethod, PDO::PARAM_STR);
            }
            if ($bankReference !== null || array_key_exists('bank_transfer_reference', $input)) {
                if ($bankReference === null) {
                    $update->bindValue(':bank_reference', null, PDO::PARAM_NULL);
                } else {
                    $update->bindValue(':bank_reference', $bankReference, PDO::PARAM_STR);
                }
            }
            $update->bindValue(':id', $requestId, PDO::PARAM_INT);
            $update->execute();

            $this->pdo->commit();
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            return [
                'success' => false,
                'message' => 'Impossibile aggiornare la richiesta.',
                'errors' => ['Database: ' . $exception->getMessage()],
            ];
        }

        return [
            'success' => true,
            'message' => 'Richiesta aggiornata correttamente.',
        ];
    }

    /**
     * @return array<string, int>
     */
    public function getStatusSummary(): array
    {
        $summary = array_fill_keys(self::STATUSES, 0);
        $stmt = $this->pdo->query('SELECT status, COUNT(*) AS total FROM customer_product_requests GROUP BY status');
        if ($stmt !== false) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $status = $row['status'] ?? null;
                if (is_string($status) && isset($summary[$status])) {
                    $summary[$status] = (int) ($row['total'] ?? 0);
                }
            }
        }
        $summary['total'] = array_sum($summary);

        return $summary;
    }

    /**
     * @return array<int, string>
     */
    public function getAllowedStatuses(): array
    {
        return self::STATUSES;
    }

    /**
     * @return array<int, string>
     */
    public function getAllowedTypes(): array
    {
        return self::TYPES;
    }

    /**
     * @return array<int, string>
     */
    public function getAllowedPaymentMethods(): array
    {
        return self::PAYMENT_METHODS;
    }

    private function appendNote(?string $existing, string $note, array $operator): string
    {
        $author = $this->resolveOperatorName($operator);
        $timestamp = (new DateTimeImmutable('now'))->format('d/m/Y H:i');
        $entry = '[' . $timestamp . ' · ' . $author . "]\n" . $note;

        if ($existing !== null && trim($existing) !== '') {
            return trim($existing) . "\n\n" . $entry;
        }

        return $entry;
    }

    private function resolveOperatorName(array $operator): string
    {
        $candidates = [
            isset($operator['fullname']) ? trim((string) $operator['fullname']) : '',
            isset($operator['username']) ? trim((string) $operator['username']) : '',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return 'Operatore';
    }

    private function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $date = date_create($value);
        return $date !== false ? $date->format('Y-m-d') : null;
    }
}
