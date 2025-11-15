-- 20251101_add_product_stock_alerts.sql
-- Gestione alert scorte per i prodotti hardware.

CREATE TABLE IF NOT EXISTS product_stock_alerts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  current_stock INT NOT NULL,
  stock_reserved INT NOT NULL DEFAULT 0,
  threshold INT NOT NULL,
  average_daily_sales DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  days_cover DECIMAL(10,2) NULL,
  last_movement DATETIME NULL,
  status ENUM('Open','Resolved') NOT NULL DEFAULT 'Open',
  message TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  resolved_at TIMESTAMP NULL,
  FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @dbname := DATABASE();
SET @have_index := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = 'product_stock_alerts'
      AND INDEX_NAME = 'idx_product_stock_alerts_product_status'
);
SET @ddl := IF(
    @have_index = 0,
    'CREATE INDEX idx_product_stock_alerts_product_status ON product_stock_alerts (product_id, status)',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
