CREATE DATABASE IF NOT EXISTS brewski_db;

USE brewski_db;

CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('CUSTOMER', 'STAFF', 'ADMIN') NOT NULL DEFAULT 'CUSTOMER',
    is_first_timer TINYINT(1) NOT NULL DEFAULT 0,
    assessment_completed_at DATETIME NULL,
    job_title VARCHAR(50) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    is_locked TINYINT(1) NOT NULL DEFAULT 0,
    activation_token VARCHAR(255) NULL,
    activation_expires DATETIME NULL,
    email_activation_token CHAR(64) NULL,
    email_activation_expires DATETIME NULL,
    password_reset_token CHAR(64) NULL,
    password_reset_expires DATETIME NULL,
    totp_secret_encrypted TEXT NULL,
    totp_enabled_at DATETIME NULL,
    failed_login_attempts TINYINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_email_activation_token (email_activation_token)
);

CREATE TABLE IF NOT EXISTS account_lockouts (
    account_lockout_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL UNIQUE,
    failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    locked_at DATETIME NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_account_lockouts_locked_at (locked_at)
);

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

CREATE TABLE IF NOT EXISTS otp_codes (
    otp_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts TINYINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_otp_codes_user_id (user_id),
    CONSTRAINT fk_otp_codes_user
        FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);

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

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(64) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO settings (setting_key, setting_value)
VALUES
    ('min_password_length', '12'),
    ('password_min_lowercase', '1'),
    ('password_min_uppercase', '1'),
    ('password_min_digits', '1'),
    ('password_min_special', '1'),
    ('session_idle_timeout', '1800'),
    ('session_absolute_timeout', '28800'),
    ('choice_catalog_fingerprint', ''),
    ('delivery_enabled', '1'),
    ('max_login_attempts', '3');

CREATE TABLE IF NOT EXISTS categories (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT
);

CREATE TABLE IF NOT EXISTS products (
    product_id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    product_name VARCHAR(150) NOT NULL,
    description TEXT,
    price DECIMAL(10,2) NOT NULL,
    stock INT NOT NULL DEFAULT 0,
    image_path VARCHAR(255),
    image_data LONGBLOB NULL,
    image_mime_type VARCHAR(32) NULL,
    availability BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_products_category
        FOREIGN KEY (category_id)
        REFERENCES categories(category_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS sizes (
    size_id INT AUTO_INCREMENT PRIMARY KEY,
    size_name VARCHAR(50) NOT NULL UNIQUE,
    additional_price DECIMAL(10,2) NOT NULL DEFAULT 0.00
);

CREATE TABLE IF NOT EXISTS product_sizes (
    product_size_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    size_id INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,

    CONSTRAINT fk_product_sizes_product
        FOREIGN KEY (product_id)
        REFERENCES products(product_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_product_sizes_size
        FOREIGN KEY (size_id)
        REFERENCES sizes(size_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT unique_product_size
        UNIQUE (product_id, size_id)
);

CREATE TABLE IF NOT EXISTS addresses (
    address_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    address_line VARCHAR(255) NOT NULL,
    city VARCHAR(100) NOT NULL,
    province VARCHAR(100) NOT NULL,
    postal_code VARCHAR(20) NOT NULL,

    CONSTRAINT fk_addresses_user
        FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS carts (
    cart_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_carts_user
        FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS cart_items (
    cart_item_id INT AUTO_INCREMENT PRIMARY KEY,
    cart_id INT NOT NULL,
    product_id INT NOT NULL,
    size_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL,
    customization_summary TEXT NULL,

    CONSTRAINT fk_cart_items_cart
        FOREIGN KEY (cart_id)
        REFERENCES carts(cart_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_cart_items_product
        FOREIGN KEY (product_id)
        REFERENCES products(product_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_cart_items_size
        FOREIGN KEY (size_id)
        REFERENCES sizes(size_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS orders (
    order_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    address_id INT NULL,
    order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,

    order_status ENUM(
        'PENDING',
        'CONFIRMED',
        'PREPARING',
        'READY',
        'COMPLETED',
        'CANCELLED'
    ) NOT NULL DEFAULT 'PENDING',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_orders_user
        FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_orders_address
        FOREIGN KEY (address_id)
        REFERENCES addresses(address_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS order_payments (
    payment_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    provider VARCHAR(24) NOT NULL DEFAULT 'paymongo',
    provider_session_id VARCHAR(100) NOT NULL,
    checkout_url VARCHAR(2048) NOT NULL,
    payment_status ENUM('PENDING', 'PAID', 'FAILED') NOT NULL DEFAULT 'PENDING',
    payment_method VARCHAR(40) NULL,
    receipt_email_sent BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_payment_per_order (order_id),
    UNIQUE KEY unique_provider_session (provider_session_id),

    CONSTRAINT fk_order_payments_order
        FOREIGN KEY (order_id)
        REFERENCES orders(order_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS order_items (
    order_item_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    size_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    customization_summary TEXT NULL,

    CONSTRAINT fk_order_items_order
        FOREIGN KEY (order_id)
        REFERENCES orders(order_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_order_items_product
        FOREIGN KEY (product_id)
        REFERENCES products(product_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_order_items_size
        FOREIGN KEY (size_id)
        REFERENCES sizes(size_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS deliveries (
    delivery_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    staff_id INT NULL,
    delivery_status ENUM(
        'UNASSIGNED',
        'ASSIGNED',
        'PICKED_UP',
        'IN_TRANSIT',
        'DELIVERED',
        'FAILED',
        'CANCELLED'
    ) NOT NULL DEFAULT 'UNASSIGNED',
    delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    notes TEXT NULL,
    assigned_at DATETIME NULL,
    picked_up_at DATETIME NULL,
    delivered_at DATETIME NULL,
    failed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_delivery_per_order (order_id),
    KEY idx_deliveries_status (delivery_status),
    KEY idx_deliveries_staff (staff_id),

    CONSTRAINT fk_deliveries_order
        FOREIGN KEY (order_id)
        REFERENCES orders(order_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_deliveries_staff
        FOREIGN KEY (staff_id)
        REFERENCES users(user_id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS preferences (
    preference_id INT AUTO_INCREMENT PRIMARY KEY,
    preference_name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT
);

CREATE TABLE IF NOT EXISTS product_preferences (
    product_id INT NOT NULL,
    preference_id INT NOT NULL,
    preference_value BOOLEAN NOT NULL DEFAULT TRUE,

    PRIMARY KEY (product_id, preference_id),

    CONSTRAINT fk_product_preferences_product
        FOREIGN KEY (product_id)
        REFERENCES products(product_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_product_preferences_preference
        FOREIGN KEY (preference_id)
        REFERENCES preferences(preference_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS product_customization_options (
    product_customization_option_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    option_group VARCHAR(30) NOT NULL,
    option_name VARCHAR(100) NOT NULL,
    additional_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    max_quantity SMALLINT UNSIGNED NOT NULL DEFAULT 99,
    CONSTRAINT fk_product_customization_options_product
        FOREIGN KEY (product_id)
        REFERENCES products(product_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT unique_product_customization_option
        UNIQUE (product_id, option_group, option_name)
);



CREATE TABLE IF NOT EXISTS preference_choice_groups (
    choice_group_id INT AUTO_INCREMENT PRIMARY KEY,
    group_name VARCHAR(50) NOT NULL UNIQUE,
    description VARCHAR(255) NULL
);

CREATE TABLE IF NOT EXISTS preference_choices (
    choice_id INT AUTO_INCREMENT PRIMARY KEY,
    choice_group_id INT NOT NULL,
    choice_name VARCHAR(100) NOT NULL,
    UNIQUE (choice_group_id, choice_name),
    CONSTRAINT fk_preference_choices_group
        FOREIGN KEY (choice_group_id)
        REFERENCES preference_choice_groups(choice_group_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);

INSERT IGNORE INTO preference_choice_groups (group_name, description)
VALUES
    ('bean', 'Coffee bean type'),
    ('milk', 'Milk type'),
    ('allergen', 'Allergens present in the product'),
    ('flavor', 'Flavor profile'),
    ('caffeine', 'Caffeine content');

INSERT IGNORE INTO preference_choices (choice_group_id, choice_name)
SELECT choice_group_id, choice_name
FROM preference_choice_groups
JOIN (
    SELECT 'bean' AS group_name, 'Arabica' AS choice_name
    UNION ALL SELECT 'bean', 'Robusta'
    UNION ALL SELECT 'bean', 'Blend'
    UNION ALL SELECT 'milk', 'Dairy milk'
    UNION ALL SELECT 'milk', 'Oat milk'
    UNION ALL SELECT 'milk', 'Almond milk'
    UNION ALL SELECT 'milk', 'Soy milk'
    UNION ALL SELECT 'milk', 'No milk'
    UNION ALL SELECT 'allergen', 'Dairy'
    UNION ALL SELECT 'allergen', 'Nuts'
    UNION ALL SELECT 'allergen', 'Gluten'
    UNION ALL SELECT 'flavor', 'Chocolate'
    UNION ALL SELECT 'flavor', 'Nutty'
    UNION ALL SELECT 'flavor', 'Fruity'
    UNION ALL SELECT 'flavor', 'Creamy'
    UNION ALL SELECT 'caffeine', 'Regular'
    UNION ALL SELECT 'caffeine', 'Decaf'
    UNION ALL SELECT 'caffeine', 'Caffeine-free'
) choices USING (group_name);

CREATE TABLE IF NOT EXISTS catalog_addons (
    addon_id INT AUTO_INCREMENT PRIMARY KEY,
    addon_name VARCHAR(100) NOT NULL UNIQUE,
    additional_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    max_quantity SMALLINT UNSIGNED NOT NULL DEFAULT 99
);

CREATE TABLE IF NOT EXISTS product_addons (
    product_id INT NOT NULL,
    addon_id INT NOT NULL,
    additional_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    max_quantity SMALLINT UNSIGNED NOT NULL DEFAULT 99,
    PRIMARY KEY (product_id, addon_id),
    CONSTRAINT fk_product_addons_product
        FOREIGN KEY (product_id)
        REFERENCES products(product_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_product_addons_addon
        FOREIGN KEY (addon_id)
        REFERENCES catalog_addons(addon_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS user_preferences (
    user_preference_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    preference_id INT NOT NULL,
    preference_value BOOLEAN NOT NULL DEFAULT TRUE,

    CONSTRAINT fk_user_preferences_user
        FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_user_preferences_preference
        FOREIGN KEY (preference_id)
        REFERENCES preferences(preference_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT unique_user_preference
        UNIQUE (user_id, preference_id)
);

CREATE TABLE IF NOT EXISTS recommendations (
    recommendation_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    recommendation_score DECIMAL(5,2) NOT NULL,
    recommendation_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_recommendations_user
        FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_recommendations_product
        FOREIGN KEY (product_id)
        REFERENCES products(product_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);
