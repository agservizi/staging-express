-- 20241018_add_customers.sql
-- Introduce la tabella clienti e collega le vendite ad un eventuale cliente registrato.

CREATE TABLE IF NOT EXISTS customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fullname VARCHAR(150) NOT NULL,
  email VARCHAR(150) NULL,
  phone VARCHAR(50) NULL,
  tax_code VARCHAR(32) NULL,
  note TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY idx_customers_email (email),
  UNIQUE KEY idx_customers_tax_code (tax_code)
);

-- Aggiunge il riferimento opzionale al cliente nella tabella vendite.
SET @dbname := DATABASE();

SET @customer_id_column := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sales' AND COLUMN_NAME = 'customer_id'
);
SET @ddl := IF(
    @customer_id_column = 0,
    'ALTER TABLE sales ADD COLUMN customer_id INT NULL AFTER user_id',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = @dbname
      AND TABLE_NAME = 'sales'
      AND CONSTRAINT_NAME = 'fk_sales_customer'
);
SET @ddl := IF(
    @fk_exists = 0,
    'ALTER TABLE sales ADD CONSTRAINT fk_sales_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @customer_note_column := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sales' AND COLUMN_NAME = 'customer_note'
);
SET @ddl := IF(
    @customer_note_column = 0,
    'ALTER TABLE sales ADD COLUMN customer_note VARCHAR(200) NULL AFTER customer_name',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
