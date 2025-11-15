-- 20251018_add_customer_product_requests.sql
-- Richieste prodotti dal portale clienti.

CREATE TABLE IF NOT EXISTS customer_product_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  portal_account_id INT NOT NULL,
  product_id INT NOT NULL,
  product_name VARCHAR(150) NOT NULL,
  product_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  request_type ENUM('Purchase','Reservation','Deposit','Installment') NOT NULL DEFAULT 'Purchase',
  status ENUM('Pending','InReview','Confirmed','Completed','Cancelled','Declined') NOT NULL DEFAULT 'Pending',
  deposit_amount DECIMAL(10,2) NULL,
  installments INT NULL,
  payment_method ENUM('BankTransfer','InStore','Other') NOT NULL DEFAULT 'BankTransfer',
  desired_pickup_date DATE NULL,
  bank_transfer_reference VARCHAR(120) NULL,
  note TEXT NULL,
  handling_note TEXT NULL,
  handled_by INT NULL,
  handled_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  FOREIGN KEY (portal_account_id) REFERENCES customer_portal_accounts(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_customer_product_requests_customer (customer_id),
  INDEX idx_customer_product_requests_portal (portal_account_id),
  INDEX idx_customer_product_requests_status (status),
  INDEX idx_customer_product_requests_handled (handled_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
