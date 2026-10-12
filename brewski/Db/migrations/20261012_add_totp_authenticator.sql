USE brewski_db;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS totp_secret_encrypted TEXT NULL,
    ADD COLUMN IF NOT EXISTS totp_enabled_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS totp_recovery_codes (
    recovery_code_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_totp_recovery_codes_user_id (user_id),
    CONSTRAINT fk_totp_recovery_codes_user
        FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);
