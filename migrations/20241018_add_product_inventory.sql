-- 20241018_add_product_inventory.sql
-- Introduce la gestione stock per i prodotti a catalogo.

SET @dbname := DATABASE();

-- Aggiunge la colonna quantità al catalogo prodotti.
SET @stock_column := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'products' AND COLUMN_NAME = 'stock_quantity'
);
SET @ddl := IF(
    @stock_column = 0,
    'ALTER TABLE products ADD COLUMN stock_quantity INT NOT NULL DEFAULT 0 AFTER price',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @reserved_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'products' AND COLUMN_NAME = 'stock_reserved'
);
SET @ddl := IF(
    @reserved_column = 0,
    'ALTER TABLE products ADD COLUMN stock_reserved INT NOT NULL DEFAULT 0 AFTER stock_quantity',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @threshold_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'products' AND COLUMN_NAME = 'reorder_threshold'
);
SET @ddl := IF(
    @threshold_column = 0,
    'ALTER TABLE products ADD COLUMN reorder_threshold INT NOT NULL DEFAULT 0 AFTER stock_reserved',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS product_stock_movements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  quantity_change INT NOT NULL,
  balance_after INT NOT NULL,
  reason VARCHAR(50) NOT NULL,
  reference_type VARCHAR(50) NULL,
  reference_id INT NULL,
  user_id INT NULL,
  note VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
