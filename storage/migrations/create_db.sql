-- create_db.sql
-- Roles
CREATE TABLE products (
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
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (role_id) REFERENCES roles(id)
);

-- Providers
CREATE TABLE providers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  reorder_threshold INT NOT NULL DEFAULT 10,
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO providers (name, reorder_threshold) VALUES
('Iliad', 25),
('Fastweb Mobile', 20),
('Sky Mobile', 15),
('Tiscali Mobile', 15),
('Windtre', 25),
('Digi Mobile', 20);

CREATE TABLE iccid_stock (
  id INT AUTO_INCREMENT PRIMARY KEY,
  iccid VARCHAR(32) NOT NULL UNIQUE,
  provider_id INT NOT NULL,
  status ENUM('InStock','Reserved','Sold') NOT NULL DEFAULT 'InStock',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  notes TEXT,
  row_version TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (provider_id) REFERENCES providers(id)
);

-- Products catalog
CREATE TABLE products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  vat_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  sku VARCHAR(100) NULL,
  barcode VARCHAR(100) NULL,
  category VARCHAR(100) NULL,
  price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  tax_rate DECIMAL(5,2) NOT NULL DEFAULT 22.00,
  notes TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  product_id INT NULL,
  UNIQUE KEY idx_products_sku (sku),
  UNIQUE KEY idx_products_barcode (barcode)
);
  tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  tax_amount DECIMAL(10,4) NOT NULL DEFAULT 0.0000,

-- Stock alerts per provider
  FOREIGN KEY (iccid_id) REFERENCES iccid_stock(id),
  FOREIGN KEY (product_id) REFERENCES products(id)
  id INT AUTO_INCREMENT PRIMARY KEY,
  provider_id INT NOT NULL,
  current_stock INT NOT NULL,
  threshold INT NOT NULL,
  average_daily_sales DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  days_cover DECIMAL(10,2) NULL,
  last_movement DATETIME NULL,
  status ENUM('Open','Resolved') NOT NULL DEFAULT 'Open',
  message TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  resolved_at TIMESTAMP NULL,
  FOREIGN KEY (provider_id) REFERENCES providers(id)
);

-- Discount campaigns
CREATE TABLE discount_campaigns (
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
);

-- Sales
CREATE TABLE sale_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sale_id INT NOT NULL,
  iccid_id INT NULL,
  product_id INT NULL,
  product_imei VARCHAR(100) NULL,
  description VARCHAR(255),
  quantity INT DEFAULT 1,
  price DECIMAL(10,2) NOT NULL,
  tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  tax_amount DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
  refunded_quantity INT NOT NULL DEFAULT 0,
  FOREIGN KEY (sale_id) REFERENCES sales(id),
  FOREIGN KEY (iccid_id) REFERENCES iccid_stock(id),
  FOREIGN KEY (product_id) REFERENCES products(id)
);
  credited_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (discount_campaign_id) REFERENCES discount_campaigns(id) ON DELETE SET NULL
);

-- Sale items (link ICCID to sale or generic product)
CREATE TABLE sale_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sale_id INT NOT NULL,
  iccid_id INT NULL,
  description VARCHAR(255),
  quantity INT DEFAULT 1,
  price DECIMAL(10,2) NOT NULL,
  refunded_quantity INT NOT NULL DEFAULT 0,
  FOREIGN KEY (sale_id) REFERENCES sales(id),
  FOREIGN KEY (iccid_id) REFERENCES iccid_stock(id)
);

-- Audit log
CREATE TABLE audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(100) NOT NULL,
  description TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Operator offers (listini e canvass)
CREATE TABLE operator_offers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  provider_id INT NULL,
  title VARCHAR(150) NOT NULL,
  description TEXT,
  price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  valid_from DATE NULL,
  valid_to DATE NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (provider_id) REFERENCES providers(id)
);

INSERT INTO operator_offers (provider_id, title, description, price, status)
VALUES
  (NULL, 'Attivazione SIM standard', 'Attivazione generica con contributo una tantum.', 9.90, 'Active'),
  (1, 'Iliad Voce Plus', 'Pacchetto voce illimitata + 100 SMS.', 7.99, 'Active'),
  (5, 'WindTre Fibra Promo', 'Promo convergente mobile + fibra per 12 mesi.', 24.90, 'Inactive');

-- Sale item refunds (storico resi parziali)
CREATE TABLE sale_item_refunds (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sale_item_id INT NOT NULL,
  user_id INT NOT NULL,
  quantity INT NOT NULL,
  refund_type ENUM('Refund','Credit') NOT NULL,
  note TEXT,
  amount DECIMAL(10,2) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sale_item_id) REFERENCES sale_items(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Remember-me tokens
CREATE TABLE user_remember_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_id (user_id),
  CONSTRAINT fk_user_remember_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
