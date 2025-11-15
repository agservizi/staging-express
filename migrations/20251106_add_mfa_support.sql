ALTER TABLE users
    ADD COLUMN mfa_secret VARCHAR(128) NULL,
    ADD COLUMN mfa_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN mfa_enabled_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS user_mfa_recovery_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    used_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_mfa_codes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_mfa_codes_user (user_id)
);
