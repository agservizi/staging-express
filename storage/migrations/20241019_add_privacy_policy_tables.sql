CREATE TABLE IF NOT EXISTS privacy_policies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(20) NOT NULL,
    title VARCHAR(255) NOT NULL,
    content LONGTEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY idx_privacy_policies_version (version),
    KEY idx_privacy_policies_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS privacy_policy_acceptances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    portal_account_id INT NOT NULL,
    policy_id INT NOT NULL,
    accepted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    KEY idx_privacy_policy_acceptances_portal (portal_account_id),
    KEY idx_privacy_policy_acceptances_policy (policy_id),
    CONSTRAINT fk_privacy_policy_acceptances_account FOREIGN KEY (portal_account_id) REFERENCES customer_portal_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_privacy_policy_acceptances_policy FOREIGN KEY (policy_id) REFERENCES privacy_policies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO privacy_policies (version, title, content, is_active)
VALUES (
    '1.0',
    'Informativa privacy area clienti',
    'Questa è un''informativa esemplificativa. Aggiorna il testo con la tua policy completa, includendo finalità, basi giuridiche, tempi di conservazione e diritti degli interessati.',
    1
) ON DUPLICATE KEY UPDATE
    title = VALUES(title),
    content = VALUES(content),
    is_active = VALUES(is_active);
