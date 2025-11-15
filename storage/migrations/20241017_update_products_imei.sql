SET @dbname := DATABASE();

SET @index_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'products' AND INDEX_NAME = 'idx_products_barcode'
);
SET @ddl := IF(
  @index_exists > 0,
  'ALTER TABLE products DROP INDEX idx_products_barcode',
  'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'products' AND COLUMN_NAME = 'barcode'
);
SET @ddl := IF(
  @column_exists > 0,
  'ALTER TABLE products CHANGE COLUMN barcode imei VARCHAR(100) NULL',
  'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'products' AND COLUMN_NAME = 'imei'
);
SET @ddl := IF(
  @column_exists = 0,
  'ALTER TABLE products ADD COLUMN imei VARCHAR(100) NULL AFTER sku',
  'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'products' AND INDEX_NAME = 'idx_products_imei'
);
SET @ddl := IF(
  @index_exists = 0,
  'ALTER TABLE products ADD UNIQUE KEY idx_products_imei (imei)',
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
