-- 20251102_add_system_notifications.sql
-- Introduce la tabella delle notifiche di sistema per il pannello principale.

CREATE TABLE IF NOT EXISTS system_notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(50) NOT NULL,
  title VARCHAR(150) NOT NULL,
  body TEXT NOT NULL,
  level ENUM('info','success','warning','danger') NOT NULL DEFAULT 'info',
  channel VARCHAR(50) NOT NULL DEFAULT 'system',
  source VARCHAR(100) NULL,
  link VARCHAR(255) NULL,
  meta_json TEXT NULL,
  recipient_user_id INT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  read_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_notifications_channel (channel),
  INDEX idx_notifications_created (created_at),
  INDEX idx_notifications_unread (is_read, created_at),
  INDEX idx_notifications_recipient (recipient_user_id, is_read),
  CONSTRAINT fk_notifications_user FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
