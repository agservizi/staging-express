-- 20241014_add_discount_campaigns.sql
-- Introduce discount campaigns and link them to sales records.

SET @dbname := DATABASE();

CREATE TABLE IF NOT EXISTS discount_campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(255) NULL,
    type ENUM('Fixed','Percent') NOT NULL DEFAULT 'Fixed',
    value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @column_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sales' AND COLUMN_NAME = 'discount'
);
SET @ddl := IF(
    @column_exists = 0,
    'ALTER TABLE sales ADD COLUMN discount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER vat',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sales' AND COLUMN_NAME = 'discount_campaign_id'
);
SET @ddl := IF(
    @column_exists = 0,
    'ALTER TABLE sales ADD COLUMN discount_campaign_id INT NULL AFTER discount',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @constraint_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = @dbname AND TABLE_NAME = 'sales' AND CONSTRAINT_NAME = 'fk_sales_discount_campaign'
);
SET @ddl := IF(
    @constraint_exists = 0,
    'ALTER TABLE sales ADD CONSTRAINT fk_sales_discount_campaign FOREIGN KEY (discount_campaign_id) REFERENCES discount_campaigns(id) ON DELETE SET NULL',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
