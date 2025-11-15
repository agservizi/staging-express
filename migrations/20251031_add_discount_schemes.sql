-- 20251031_add_discount_schemes.sql
-- Introduce la tabella legacy per gestire le scontistiche statiche utilizzate da DiscountService.

CREATE TABLE IF NOT EXISTS discount_schemes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    type ENUM('Amount','Percent') NOT NULL DEFAULT 'Amount',
    value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY idx_discount_schemes_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
