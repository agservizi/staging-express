<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

final class StockMonitorService
{
    private const DEFAULT_LOOKBACK_DAYS = 30;

    public function __construct(
        private PDO $pdo,
        private ?string $alertEmail = null,
        private ?string $logFile = null,
        private ?string $resendApiKey = null,
        private ?string $resendFrom = null,
        private ?SystemNotificationService $notificationService = null
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getProviderInsights(): array
    {
        $insights = $this->computeProviderInsights();
        return array_values($insights);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getOpenAlerts(): array
    {
        $stmt = $this->pdo->query(
            'SELECT sa.*, p.name AS provider_name
             FROM stock_alerts sa
             JOIN providers p ON p.id = sa.provider_id
             WHERE sa.status = "Open"
             ORDER BY sa.created_at ASC'
        );

            $rows = $stmt !== false ? $stmt->fetchAll() : [];
            foreach ($rows as &$row) {
                if (isset($row['message'])) {
                    $row['message'] = (string) $row['message'];
                }
            }

            return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getProductInsights(): array
    {
        try {
            $insights = $this->computeProductInsights();
        } catch (\PDOException $exception) {
            if ($this->isSchemaNotReady($exception)) {
                return [];
            }
            throw $exception;
        }
        return array_values($insights);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getOpenProductAlerts(): array
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT psa.*, pr.name AS product_name
                 FROM product_stock_alerts psa
                 JOIN products pr ON pr.id = psa.product_id
                 WHERE psa.status = "Open"
                 ORDER BY psa.created_at ASC'
            );
        } catch (\PDOException $exception) {
            if ($this->isSchemaNotReady($exception)) {
                return [];
            }
            throw $exception;
        }

        $rows = $stmt !== false ? $stmt->fetchAll() : [];
        foreach ($rows as &$row) {
            if (isset($row['message'])) {
                $row['message'] = (string) $row['message'];
            }
        }

        return $rows;
    }

    public function updateThreshold(int $providerId, int $threshold): array
    {
        if ($providerId <= 0) {
            return [
                'success' => false,
                'message' => 'Operatore non valido.',
            ];
        }
        if ($threshold < 0) {
            return [
                'success' => false,
                'message' => 'La soglia deve essere positiva.',
            ];
        }

        $stmt = $this->pdo->prepare('UPDATE providers SET reorder_threshold = :t WHERE id = :id');
        $stmt->execute([
            ':t' => $threshold,
            ':id' => $providerId,
        ]);

        return [
            'success' => true,
            'message' => 'Soglia aggiornata correttamente.',
        ];
    }

    /**
     * Eseguito da cron/CLI: controlla le soglie e registra alert.
     *
     * @return array<string, array<string, int>>
     */
    public function checkThresholds(): array
    {
        $providerStats = $this->checkProviderThresholds();
        $productStats = $this->checkProductThresholds();

        return [
            'providers' => $providerStats,
            'products' => $productStats,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function computeProviderInsights(): array
    {
        $providers = $this->fetchProviders();
        if ($providers === []) {
            return [];
        }

        $availableByProvider = $this->fetchAvailableStockCounts();
        $lastMovementByProvider = $this->fetchLastMovement();
        $salesByProvider = $this->fetchSalesCounts(self::DEFAULT_LOOKBACK_DAYS);
    $openAlerts = $this->fetchOpenAlertsIndexed();

        $insights = [];
        foreach ($providers as $provider) {
            $providerId = (int) $provider['id'];
            $threshold = (int) $provider['reorder_threshold'];
            $current = (int) ($availableByProvider[$providerId] ?? 0);
            $soldCount = (int) ($salesByProvider[$providerId] ?? 0);
            $averageDaily = self::DEFAULT_LOOKBACK_DAYS > 0
                ? round($soldCount / self::DEFAULT_LOOKBACK_DAYS, 2)
                : 0.0;
            $daysCover = $averageDaily > 0.0
                ? round($current / $averageDaily, 2)
                : null;

            $insights[$providerId] = [
                'provider_id' => $providerId,
                'provider_name' => (string) $provider['name'],
                'threshold' => $threshold,
                'current_stock' => $current,
                'average_daily_sales' => $averageDaily,
                'days_cover' => $daysCover,
                'last_movement' => $lastMovementByProvider[$providerId] ?? null,
                'below_threshold' => $current < $threshold,
                'open_alert' => isset($openAlerts[$providerId]),
                'suggested_reorder' => $this->suggestReorderQuantity($current, $averageDaily, $threshold),
            ];
        }

        return $insights;
    }

    /**
     * @return array{checked:int, created:int, updated:int, resolved:int}
     */
    private function checkProviderThresholds(): array
    {
        $insights = $this->computeProviderInsights();
        if ($insights === []) {
            return ['checked' => 0, 'created' => 0, 'updated' => 0, 'resolved' => 0];
        }

        $openAlerts = $this->fetchOpenAlertsIndexed();

        $created = 0;
        $updated = 0;
        $resolved = 0;

        foreach ($insights as $providerId => $info) {
            $belowThreshold = (bool) ($info['below_threshold'] ?? false);
            if ($belowThreshold) {
                $message = $this->buildAlertMessage($info);
                if (isset($openAlerts[$providerId])) {
                    $this->updateAlert((int) $openAlerts[$providerId]['id'], $info, $message);
                    $updated++;
                } else {
                    $this->createAlert($providerId, $info, $message);
                    $created++;
                }
            } else {
                if (isset($openAlerts[$providerId])) {
                    $this->resolveAlert((int) $openAlerts[$providerId]['id']);
                    $resolved++;
                }
            }
        }

        return [
            'checked' => count($insights),
            'created' => $created,
            'updated' => $updated,
            'resolved' => $resolved,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function computeProductInsights(): array
    {
        $products = $this->fetchProductCatalog();
        if ($products === []) {
            return [];
        }

        $lastMovementByProduct = $this->fetchProductLastMovement();
        $salesByProduct = $this->fetchProductSalesCounts(self::DEFAULT_LOOKBACK_DAYS);
        $openAlerts = $this->fetchOpenProductAlertsIndexed();

        $insights = [];
        foreach ($products as $product) {
            $productId = (int) $product['id'];
            $stockQuantity = (int) ($product['stock_quantity'] ?? 0);
            $stockReserved = (int) ($product['stock_reserved'] ?? 0);
            $threshold = (int) ($product['reorder_threshold'] ?? 0);
            $available = max(0, $stockQuantity - $stockReserved);
            $soldQty = (int) ($salesByProduct[$productId] ?? 0);
            $averageDaily = self::DEFAULT_LOOKBACK_DAYS > 0
                ? round($soldQty / self::DEFAULT_LOOKBACK_DAYS, 2)
                : 0.0;
            $daysCover = $averageDaily > 0.0
                ? round($available / max($averageDaily, 0.0001), 2)
                : null;

            $insights[$productId] = [
                'product_id' => $productId,
                'product_name' => (string) $product['name'],
                'current_stock' => $available,
                'stock_quantity' => $stockQuantity,
                'stock_reserved' => $stockReserved,
                'threshold' => $threshold,
                'average_daily_sales' => $averageDaily,
                'days_cover' => $daysCover,
                'last_movement' => $lastMovementByProduct[$productId] ?? null,
                'below_threshold' => $available < $threshold,
                'open_alert' => isset($openAlerts[$productId]),
                'suggested_reorder' => $this->suggestReorderQuantity($available, $averageDaily, $threshold),
            ];
        }

        return $insights;
    }

    /**
     * @return array{checked:int, created:int, updated:int, resolved:int}
     */
    private function checkProductThresholds(): array
    {
        try {
            $insights = $this->computeProductInsights();
        } catch (\PDOException $exception) {
            if ($this->isSchemaNotReady($exception)) {
                return ['checked' => 0, 'created' => 0, 'updated' => 0, 'resolved' => 0];
            }
            throw $exception;
        }
        if ($insights === []) {
            return ['checked' => 0, 'created' => 0, 'updated' => 0, 'resolved' => 0];
        }

        try {
            $openAlerts = $this->fetchOpenProductAlertsIndexed();
        } catch (\PDOException $exception) {
            if ($this->isSchemaNotReady($exception)) {
                return ['checked' => 0, 'created' => 0, 'updated' => 0, 'resolved' => 0];
            }
            throw $exception;
        }

        $created = 0;
        $updated = 0;
        $resolved = 0;

        foreach ($insights as $productId => $info) {
            $belowThreshold = (bool) ($info['below_threshold'] ?? false);
            if ($belowThreshold) {
                $message = $this->buildProductAlertMessage($info);
                if (isset($openAlerts[$productId])) {
                    $this->updateProductAlert((int) $openAlerts[$productId]['id'], $info, $message);
                    $updated++;
                } else {
                    $this->createProductAlert($productId, $info, $message);
                    $created++;
                }
            } else {
                if (isset($openAlerts[$productId])) {
                    $this->resolveProductAlert((int) $openAlerts[$productId]['id']);
                    $resolved++;
                }
            }
        }

        return [
            'checked' => count($insights),
            'created' => $created,
            'updated' => $updated,
            'resolved' => $resolved,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchProviders(): array
    {
        $stmt = $this->pdo->query('SELECT id, name, reorder_threshold FROM providers ORDER BY name');
        return $stmt !== false ? $stmt->fetchAll() : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchProductCatalog(): array
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT id, name, stock_quantity, stock_reserved, reorder_threshold
                 FROM products
                 WHERE is_active = 1
                 ORDER BY name ASC'
            );
        } catch (\PDOException $exception) {
            if ($this->isSchemaNotReady($exception)) {
                return [];
            }
            throw $exception;
        }

        return $stmt !== false ? $stmt->fetchAll() : [];
    }

    /**
     * @return array<int, int>
     */
    private function fetchAvailableStockCounts(): array
    {
        $stmt = $this->pdo->query(
            "SELECT provider_id, COUNT(*) AS total
             FROM iccid_stock
             WHERE status = 'InStock'
             GROUP BY provider_id"
        );
        $results = $stmt !== false ? $stmt->fetchAll() : [];

        $counts = [];
        foreach ($results as $row) {
            $counts[(int) $row['provider_id']] = (int) $row['total'];
        }
        return $counts;
    }

    /**
     * @return array<int, string>
     */
    private function fetchLastMovement(): array
    {
        $stmt = $this->pdo->query(
            'SELECT provider_id, MAX(updated_at) AS last_movement
             FROM iccid_stock
             GROUP BY provider_id'
        );
        $rows = $stmt !== false ? $stmt->fetchAll() : [];

        $map = [];
        foreach ($rows as $row) {
            if (!empty($row['last_movement'])) {
                $map[(int) $row['provider_id']] = (string) $row['last_movement'];
            }
        }
        return $map;
    }

    /**
     * @return array<int, int>
     */
    private function fetchSalesCounts(int $lookbackDays): array
    {
                $fromDate = (new \DateTimeImmutable('-' . $lookbackDays . ' days'))->format('Y-m-d 00:00:00');

                $stmt = $this->pdo->prepare(
                        "SELECT ic.provider_id, COUNT(*) AS sold
                         FROM sale_items si
                         JOIN sales s ON s.id = si.sale_id
                         JOIN iccid_stock ic ON ic.id = si.iccid_id
                         WHERE s.status = 'Completed'
                             AND s.created_at >= :from_date
                         GROUP BY ic.provider_id"
                );
                $stmt->execute([':from_date' => $fromDate]);
        $rows = $stmt->fetchAll();

        $sales = [];
        foreach ($rows as $row) {
            $sales[(int) $row['provider_id']] = (int) $row['sold'];
        }
        return $sales;
    }

    /**
     * @return array<int, int>
     */
    private function fetchProductSalesCounts(int $lookbackDays): array
    {
        $fromDate = (new \DateTimeImmutable('-' . $lookbackDays . ' days'))->format('Y-m-d 00:00:00');

        try {
            $stmt = $this->pdo->prepare(
                'SELECT si.product_id, SUM(si.quantity) AS sold
                 FROM sale_items si
                 JOIN sales s ON s.id = si.sale_id
                 WHERE si.product_id IS NOT NULL
                   AND s.status = "Completed"
                   AND s.created_at >= :from_date
                 GROUP BY si.product_id'
            );
            $stmt->execute([':from_date' => $fromDate]);
            $rows = $stmt->fetchAll();
        } catch (\PDOException $exception) {
            if ($this->isSchemaNotReady($exception)) {
                return [];
            }
            throw $exception;
        }

        $sales = [];
        foreach ($rows as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            if ($productId > 0) {
                $sales[$productId] = (int) ($row['sold'] ?? 0);
            }
        }

        return $sales;
    }

    /**
     * @return array<int, string>
     */
    private function fetchProductLastMovement(): array
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT product_id, MAX(created_at) AS last_movement
                 FROM product_stock_movements
                 GROUP BY product_id'
            );
        } catch (\PDOException $exception) {
            if ($this->isSchemaNotReady($exception)) {
                return [];
            }
            throw $exception;
        }
        $rows = $stmt !== false ? $stmt->fetchAll() : [];

        $map = [];
        foreach ($rows as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            if ($productId > 0 && !empty($row['last_movement'])) {
                $map[$productId] = (string) $row['last_movement'];
            }
        }

        return $map;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchOpenAlertsIndexed(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM stock_alerts WHERE status = "Open"');
        $rows = $stmt !== false ? $stmt->fetchAll() : [];
        $indexed = [];
        foreach ($rows as $row) {
            $row['message'] = isset($row['message']) ? (string) $row['message'] : '';
            $indexed[(int) $row['provider_id']] = $row;
        }
        return $indexed;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchOpenProductAlertsIndexed(): array
    {
        try {
            $stmt = $this->pdo->query('SELECT * FROM product_stock_alerts WHERE status = "Open"');
        } catch (\PDOException $exception) {
            if ($this->isSchemaNotReady($exception)) {
                return [];
            }
            throw $exception;
        }
        $rows = $stmt !== false ? $stmt->fetchAll() : [];
        $indexed = [];
        foreach ($rows as $row) {
            $row['message'] = isset($row['message']) ? (string) $row['message'] : '';
            $indexed[(int) $row['product_id']] = $row;
        }

        return $indexed;
    }

    /**
     * @param array<string, mixed> $info
     */
    private function createAlert(int $providerId, array $info, string $message): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO stock_alerts (
                 provider_id, current_stock, threshold, average_daily_sales,
                 days_cover, last_movement, message
             ) VALUES (:provider_id, :current_stock, :threshold, :average_daily_sales,
                 :days_cover, :last_movement, :message)'
        );
        $stmt->execute([
            ':provider_id' => $providerId,
            ':current_stock' => (int) ($info['current_stock'] ?? 0),
            ':threshold' => (int) ($info['threshold'] ?? 0),
            ':average_daily_sales' => (float) ($info['average_daily_sales'] ?? 0.0),
            ':days_cover' => $info['days_cover'] ?? null,
            ':last_movement' => $info['last_movement'] ?? null,
            ':message' => $message,
        ]);

        $this->notify(
            $info['provider_name'] ?? ('Provider #' . $providerId),
            $message,
            [
                'provider_id' => $providerId,
                'current_stock' => (int) ($info['current_stock'] ?? 0),
                'threshold' => (int) ($info['threshold'] ?? 0),
                'average_daily_sales' => (float) ($info['average_daily_sales'] ?? 0.0),
                'days_cover' => $info['days_cover'] ?? null,
            ],
            'stock'
        );
    }

    /**
     * @param array<string, mixed> $info
     */
    private function updateAlert(int $alertId, array $info, string $message): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE stock_alerts
             SET current_stock = :current_stock,
                 threshold = :threshold,
                 average_daily_sales = :average_daily_sales,
                 days_cover = :days_cover,
                 last_movement = :last_movement,
                 message = :message
             WHERE id = :id'
        );
        $stmt->execute([
            ':current_stock' => (int) ($info['current_stock'] ?? 0),
            ':threshold' => (int) ($info['threshold'] ?? 0),
            ':average_daily_sales' => (float) ($info['average_daily_sales'] ?? 0.0),
            ':days_cover' => $info['days_cover'] ?? null,
            ':last_movement' => $info['last_movement'] ?? null,
            ':message' => $message,
            ':id' => $alertId,
        ]);
    }

    private function resolveAlert(int $alertId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE stock_alerts
             SET status = 'Resolved', resolved_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute([':id' => $alertId]);
    }

    /**
     * @param array<string, mixed> $info
     */
    private function createProductAlert(int $productId, array $info, string $message): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO product_stock_alerts (
                     product_id, current_stock, stock_reserved, threshold, average_daily_sales,
                     days_cover, last_movement, message
                 ) VALUES (:product_id, :current_stock, :stock_reserved, :threshold, :average_daily_sales,
                     :days_cover, :last_movement, :message)'
            );
            $stmt->execute([
                ':product_id' => $productId,
                ':current_stock' => (int) ($info['current_stock'] ?? 0),
                ':stock_reserved' => (int) ($info['stock_reserved'] ?? 0),
                ':threshold' => (int) ($info['threshold'] ?? 0),
                ':average_daily_sales' => (float) ($info['average_daily_sales'] ?? 0.0),
                ':days_cover' => $info['days_cover'] ?? null,
                ':last_movement' => $info['last_movement'] ?? null,
                ':message' => $message,
            ]);
        } catch (\PDOException $exception) {
            if ($this->isSchemaNotReady($exception)) {
                return;
            }
            throw $exception;
        }

        $productName = (string) ($info['product_name'] ?? ('Prodotto #' . $productId));
        $this->notify(
            'Prodotto ' . $productName,
            $message,
            [
                'product_id' => $productId,
                'available' => (int) ($info['current_stock'] ?? 0),
                'threshold' => (int) ($info['threshold'] ?? 0),
                'average_daily_sales' => (float) ($info['average_daily_sales'] ?? 0.0),
                'days_cover' => $info['days_cover'] ?? null,
            ],
            'product_stock'
        );
    }

    /**
     * @param array<string, mixed> $info
     */
    private function updateProductAlert(int $alertId, array $info, string $message): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE product_stock_alerts
                 SET current_stock = :current_stock,
                     stock_reserved = :stock_reserved,
                     threshold = :threshold,
                     average_daily_sales = :average_daily_sales,
                     days_cover = :days_cover,
                     last_movement = :last_movement,
                     message = :message
                 WHERE id = :id'
            );
            $stmt->execute([
                ':current_stock' => (int) ($info['current_stock'] ?? 0),
                ':stock_reserved' => (int) ($info['stock_reserved'] ?? 0),
                ':threshold' => (int) ($info['threshold'] ?? 0),
                ':average_daily_sales' => (float) ($info['average_daily_sales'] ?? 0.0),
                ':days_cover' => $info['days_cover'] ?? null,
                ':last_movement' => $info['last_movement'] ?? null,
                ':message' => $message,
                ':id' => $alertId,
            ]);
        } catch (\PDOException $exception) {
            if ($this->isSchemaNotReady($exception)) {
                return;
            }
            throw $exception;
        }
    }

    private function resolveProductAlert(int $alertId): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE product_stock_alerts
                 SET status = 'Resolved', resolved_at = NOW()
                 WHERE id = :id"
            );
            $stmt->execute([':id' => $alertId]);
        } catch (\PDOException $exception) {
            if ($this->isSchemaNotReady($exception)) {
                return;
            }
            throw $exception;
        }
    }

    private function isSchemaNotReady(\PDOException $exception): bool
    {
        $errorCode = (int) $exception->getCode();
        $errorInfo = $exception->errorInfo ?? null;
        if (is_array($errorInfo) && isset($errorInfo[1])) {
            $errorCode = (int) $errorInfo[1];
        }

        return in_array($errorCode, [1054, 1091, 1146], true);
    }

    /**
     * @param array<string, mixed> $info
     */
    private function buildAlertMessage(array $info): string
    {
        $provider = (string) ($info['provider_name'] ?? 'Operatore sconosciuto');
        $current = (int) ($info['current_stock'] ?? 0);
        $threshold = (int) ($info['threshold'] ?? 0);
        $average = (float) ($info['average_daily_sales'] ?? 0.0);
        $cover = $info['days_cover'] !== null ? (float) $info['days_cover'] : null;

        $message = sprintf(
            'Stock %s sotto soglia: %d disponibili su soglia %d. Media vendite %s/giorno.',
            $provider,
            $current,
            $threshold,
            number_format($average, 2, ',', '.')
        );

        if ($cover !== null && $cover > 0) {
            $message .= ' Copertura stimata: circa ' . number_format($cover, 1, ',', '.') . ' giorni.';
        }

        $suggested = $this->suggestReorderQuantity($current, $average, $threshold);
        if ($suggested > 0) {
            $message .= ' Suggerito riordino minimo: ' . $suggested . ' SIM.';
        }

        return $message;
    }

    /**
     * @param array<string, mixed> $info
     */
    private function buildProductAlertMessage(array $info): string
    {
        $productName = (string) ($info['product_name'] ?? 'Prodotto sconosciuto');
        $available = (int) ($info['current_stock'] ?? 0);
        $stockQuantity = (int) ($info['stock_quantity'] ?? $available);
        $stockReserved = (int) ($info['stock_reserved'] ?? 0);
        $threshold = (int) ($info['threshold'] ?? 0);
        $average = (float) ($info['average_daily_sales'] ?? 0.0);
        $cover = $info['days_cover'] !== null ? (float) $info['days_cover'] : null;

        $message = sprintf(
            'Stock prodotto %s sotto soglia: %d disponibili (totale %d, riservati %d) su soglia %d. Media vendite %s/giorno.',
            $productName,
            $available,
            $stockQuantity,
            $stockReserved,
            $threshold,
            number_format($average, 2, ',', '.')
        );

        if ($cover !== null && $cover > 0) {
            $message .= ' Copertura stimata: circa ' . number_format($cover, 1, ',', '.') . ' giorni.';
        }

        $suggested = $this->suggestReorderQuantity($available, $average, $threshold);
        if ($suggested > 0) {
            $message .= ' Suggerito riordino minimo: ' . $suggested . ' pezzi.';
        }

        return $message;
    }

    private function suggestReorderQuantity(int $current, float $averageDaily, int $threshold): int
    {
        $targetDays = 14;
        $targetByDemand = $averageDaily > 0 ? (int) ceil($averageDaily * $targetDays) : $threshold;
        $target = max($threshold, $targetByDemand);
        $suggested = $target - $current;
        return $suggested > 0 ? $suggested : 0;
    }

    private function notify(string $label, string $message, array $context = [], string $channel = 'stock'): void
    {
        $subject = '[Coresuite] Alert stock ' . $label;

        $delivery = 'none';
        if ($this->alertEmail !== null) {
            if ($this->resendApiKey !== null && $this->sendResendEmail($subject, $message)) {
                $delivery = 'resend';
            } elseif (@mail($this->alertEmail, $subject, $message)) {
                $delivery = 'mail';
            }
        }

        if ($this->logFile !== null) {
            $suffix = $delivery === 'none' ? ' [notifica non inviata]' : ' [notifica ' . $delivery . ']';
            $this->appendLog($subject . ' - ' . $message . $suffix);
        }

        if ($this->notificationService !== null) {
            $meta = array_merge($context, [
                'label' => $label,
                'delivery' => $delivery,
            ]);

            $link = $channel === 'product_stock'
                ? 'index.php?page=products_list'
                : 'index.php?page=sim_stock';

            $this->notificationService->push(
                'stock_alert',
                $subject,
                $message,
                [
                    'level' => $delivery === 'none' ? 'warning' : 'success',
                    'channel' => $channel,
                    'source' => 'stock_monitor_service',
                    'link' => $link,
                    'meta' => $meta,
                ]
            );
        }
    }

    private function sendResendEmail(string $subject, string $message): bool
    {
        if ($this->alertEmail === null || $this->resendApiKey === null) {
            return false;
        }

        if (!function_exists('curl_init')) {
            return false;
        }

        $from = $this->resendFrom ?: 'alerts@coresuite.test';

        $payload = json_encode([
            'from' => $from,
            'to' => [$this->alertEmail],
            'subject' => $subject,
            'text' => $message,
        ]);
        if ($payload === false) {
            return false;
        }

        $ch = curl_init('https://api.resend.com/emails');
        if ($ch === false) {
            return false;
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->resendApiKey,
        ]);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false) {
            curl_close($ch);
            return false;
        }
        curl_close($ch);

        return $status >= 200 && $status < 300;
    }

    private function appendLog(string $line): void
    {
        $path = $this->logFile;
        if ($path === null) {
            return;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $entry = '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL;
        file_put_contents($path, $entry, FILE_APPEND);
    }
}
