SET @dbname := DATABASE();

CREATE TABLE IF NOT EXISTS products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  sku VARCHAR(100) NULL,
  imei VARCHAR(100) NULL,
  category VARCHAR(100) NULL,
  price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  tax_rate DECIMAL(5,2) NOT NULL DEFAULT 22.00,
  notes TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY idx_products_sku (sku),
  UNIQUE KEY idx_products_imei (imei)
);

SET @column_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sale_items' AND COLUMN_NAME = 'product_id'
);
SET @ddl := IF(
  @column_exists = 0,
  'ALTER TABLE sale_items ADD COLUMN product_id INT NULL AFTER iccid_id',
  'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sale_items' AND COLUMN_NAME = 'product_imei'
);
SET @ddl := IF(
  @column_exists = 0,
  'ALTER TABLE sale_items ADD COLUMN product_imei VARCHAR(100) NULL AFTER product_id',
  'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sale_items' AND COLUMN_NAME = 'tax_rate'
);
SET @ddl := IF(
  @column_exists = 0,
  'ALTER TABLE sale_items ADD COLUMN tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER price',
  'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sale_items' AND COLUMN_NAME = 'tax_amount'
);
SET @ddl := IF(
  @column_exists = 0,
  'ALTER TABLE sale_items ADD COLUMN tax_amount DECIMAL(10,4) NOT NULL DEFAULT 0.0000 AFTER tax_rate',
  'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @constraint_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = @dbname AND TABLE_NAME = 'sale_items' AND CONSTRAINT_NAME = 'fk_sale_items_product'
);
SET @ddl := IF(
  @constraint_exists = 0,
  'ALTER TABLE sale_items ADD CONSTRAINT fk_sale_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL',
  'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sales' AND COLUMN_NAME = 'vat_amount'
);
SET @ddl := IF(
  @column_exists = 0,
  'ALTER TABLE sales ADD COLUMN vat_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER vat',
  'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
