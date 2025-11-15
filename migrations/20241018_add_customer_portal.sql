-- 20241018_add_customer_portal.sql
-- Estende la tabella vendite con stato pagamenti e introduce il portale clienti.

ALTER TABLE sales
  ADD COLUMN IF NOT EXISTS total_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total,
  ADD COLUMN IF NOT EXISTS balance_due DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_paid,
  ADD COLUMN IF NOT EXISTS payment_status ENUM('Paid','Partial','Pending','Overdue') NOT NULL DEFAULT 'Paid' AFTER balance_due,
  ADD COLUMN IF NOT EXISTS due_date DATE NULL AFTER payment_status;

CREATE TABLE IF NOT EXISTS customer_portal_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  email VARCHAR(150) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  invite_token VARCHAR(64) NULL,
  invite_sent_at DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_portal_email (email),
  UNIQUE KEY uq_portal_customer (customer_id),
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS customer_portal_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  portal_account_id INT NOT NULL,
  session_token CHAR(64) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  user_agent VARCHAR(255) NULL,
  ip_address VARCHAR(45) NULL,
  UNIQUE KEY uq_portal_session (session_token),
  FOREIGN KEY (portal_account_id) REFERENCES customer_portal_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS customer_support_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  portal_account_id INT NOT NULL,
  type ENUM('Support','Booking') NOT NULL DEFAULT 'Support',
  subject VARCHAR(150) NOT NULL,
  message TEXT NOT NULL,
  preferred_slot DATETIME NULL,
  status ENUM('Open','InProgress','Completed','Cancelled') NOT NULL DEFAULT 'Open',
  resolution_note TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  FOREIGN KEY (portal_account_id) REFERENCES customer_portal_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS customer_payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sale_id INT NOT NULL,
  portal_account_id INT NULL,
  amount DECIMAL(10,2) NOT NULL,
  payment_method ENUM('Card','BankTransfer','Cash','Other') NOT NULL DEFAULT 'Card',
  status ENUM('Pending','Succeeded','Failed') NOT NULL DEFAULT 'Succeeded',
  provider_reference VARCHAR(100) NULL,
  note VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
  FOREIGN KEY (portal_account_id) REFERENCES customer_portal_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;