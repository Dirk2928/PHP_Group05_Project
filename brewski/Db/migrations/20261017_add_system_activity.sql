CREATE TABLE IF NOT EXISTS system_activity_logs (
    activity_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_user_id INT NULL,
    actor_name VARCHAR(201) NOT NULL,
    actor_role ENUM('ADMIN', 'STAFF', 'CUSTOMER') NOT NULL,
    category VARCHAR(40) NOT NULL,
    action VARCHAR(80) NOT NULL,
    description VARCHAR(500) NOT NULL,
    target_type VARCHAR(60) NULL,
    target_id BIGINT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_system_activity_created (created_at),
    INDEX idx_system_activity_role_created (actor_role, created_at),
    INDEX idx_system_activity_category_created (category, created_at),
    INDEX idx_system_activity_actor_created (actor_user_id, created_at)
);