CREATE DATABASE IF NOT EXISTS brewski_db;

USE brewski_db;

CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('CUSTOMER', 'STAFF', 'ADMIN') NOT NULL DEFAULT 'CUSTOMER',
    job_title VARCHAR(50) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    activation_token VARCHAR(255) NULL,
    activation_expires DATETIME NULL,
    email_activation_token CHAR(64) NULL,
    email_activation_expires DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_email_activation_token (email_activation_token)
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
    ('delivery_fee', '50'),
    ('free_delivery_threshold', '500'),
    ('delivery_enabled', '1');

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
    address_id INT NOT NULL,
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

CREATE TABLE IF NOT EXISTS order_items (
    order_item_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    size_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,

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
    option_group VARCHAR(20) NOT NULL,
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

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS first_name VARCHAR(100) NOT NULL,
    ADD COLUMN IF NOT EXISTS last_name VARCHAR(100) NOT NULL,
    ADD COLUMN IF NOT EXISTS email VARCHAR(255) NOT NULL,
    ADD COLUMN IF NOT EXISTS password VARCHAR(255) NOT NULL,
    ADD COLUMN IF NOT EXISTS role ENUM('CUSTOMER', 'STAFF', 'ADMIN') NOT NULL DEFAULT 'CUSTOMER',
    ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS activation_token VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS activation_expires DATETIME NULL,
    ADD COLUMN IF NOT EXISTS email_activation_token CHAR(64) NULL,
    ADD COLUMN IF NOT EXISTS email_activation_expires DATETIME NULL,
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE users
    MODIFY COLUMN password VARCHAR(255) NOT NULL;

ALTER TABLE users
    MODIFY COLUMN role ENUM('CUSTOMER', 'STAFF', 'ADMIN') NOT NULL DEFAULT 'CUSTOMER';

ALTER TABLE users
    ADD INDEX IF NOT EXISTS idx_users_email_activation_token (email_activation_token);

ALTER TABLE otp_codes
    ADD COLUMN IF NOT EXISTS user_id INT NOT NULL,
    ADD COLUMN IF NOT EXISTS code_hash VARCHAR(255) NOT NULL,
    ADD COLUMN IF NOT EXISTS expires_at DATETIME NOT NULL,
    ADD COLUMN IF NOT EXISTS attempts TINYINT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE otp_codes
    ADD INDEX IF NOT EXISTS idx_otp_codes_user_id (user_id);

ALTER TABLE settings
    ADD COLUMN IF NOT EXISTS setting_value VARCHAR(255) NOT NULL,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE categories
    ADD COLUMN IF NOT EXISTS category_name VARCHAR(100) NOT NULL,
    ADD COLUMN IF NOT EXISTS description TEXT;

ALTER TABLE products
    ADD COLUMN IF NOT EXISTS category_id INT NOT NULL,
    ADD COLUMN IF NOT EXISTS product_name VARCHAR(150) NOT NULL,
    ADD COLUMN IF NOT EXISTS description TEXT,
    ADD COLUMN IF NOT EXISTS price DECIMAL(10,2) NOT NULL,
    ADD COLUMN IF NOT EXISTS stock INT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS image_path VARCHAR(255),
    ADD COLUMN IF NOT EXISTS image_data LONGBLOB NULL,
    ADD COLUMN IF NOT EXISTS image_mime_type VARCHAR(32) NULL,
    ADD COLUMN IF NOT EXISTS availability BOOLEAN NOT NULL DEFAULT TRUE,
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE sizes
    ADD COLUMN IF NOT EXISTS size_name VARCHAR(50) NOT NULL,
    ADD COLUMN IF NOT EXISTS additional_price DECIMAL(10,2) NOT NULL DEFAULT 0.00;

ALTER TABLE product_sizes
    ADD COLUMN IF NOT EXISTS product_id INT NOT NULL,
    ADD COLUMN IF NOT EXISTS size_id INT NOT NULL,
    ADD COLUMN IF NOT EXISTS price DECIMAL(10,2) NOT NULL;

ALTER TABLE addresses
    ADD COLUMN IF NOT EXISTS user_id INT NOT NULL,
    ADD COLUMN IF NOT EXISTS address_line VARCHAR(255) NOT NULL,
    ADD COLUMN IF NOT EXISTS city VARCHAR(100) NOT NULL,
    ADD COLUMN IF NOT EXISTS province VARCHAR(100) NOT NULL,
    ADD COLUMN IF NOT EXISTS postal_code VARCHAR(20) NOT NULL;

ALTER TABLE carts
    ADD COLUMN IF NOT EXISTS user_id INT NOT NULL,
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE cart_items
    ADD COLUMN IF NOT EXISTS cart_id INT NOT NULL,
    ADD COLUMN IF NOT EXISTS product_id INT NOT NULL,
    ADD COLUMN IF NOT EXISTS size_id INT NOT NULL,
    ADD COLUMN IF NOT EXISTS quantity INT NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS unit_price DECIMAL(10,2) NOT NULL;

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS user_id INT NOT NULL,
    ADD COLUMN IF NOT EXISTS address_id INT NOT NULL,
    ADD COLUMN IF NOT EXISTS order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN IF NOT EXISTS total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS order_status ENUM(
        'PENDING',
        'CONFIRMED',
        'PREPARING',
        'READY',
        'COMPLETED',
        'CANCELLED'
    ) NOT NULL DEFAULT 'PENDING',
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE orders
    MODIFY COLUMN order_status ENUM(
        'PENDING',
        'CONFIRMED',
        'PREPARING',
        'READY',
        'COMPLETED',
        'CANCELLED'
    ) NOT NULL DEFAULT 'PENDING';

ALTER TABLE order_items
    ADD COLUMN IF NOT EXISTS order_id INT NOT NULL,
    ADD COLUMN IF NOT EXISTS product_id INT NOT NULL,
    ADD COLUMN IF NOT EXISTS size_id INT NOT NULL,
    ADD COLUMN IF NOT EXISTS quantity INT NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS unit_price DECIMAL(10,2) NOT NULL,
    ADD COLUMN IF NOT EXISTS subtotal DECIMAL(10,2) NOT NULL;

ALTER TABLE preferences
    ADD COLUMN IF NOT EXISTS preference_name VARCHAR(100) NOT NULL,
    ADD COLUMN IF NOT EXISTS description TEXT;

ALTER TABLE product_preferences
    ADD COLUMN IF NOT EXISTS preference_value BOOLEAN NOT NULL DEFAULT TRUE;

ALTER TABLE product_customization_options
    ADD COLUMN IF NOT EXISTS product_id INT NOT NULL,
    ADD COLUMN IF NOT EXISTS option_group VARCHAR(20) NOT NULL,
    ADD COLUMN IF NOT EXISTS option_name VARCHAR(100) NOT NULL,
    ADD COLUMN IF NOT EXISTS additional_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS max_quantity SMALLINT UNSIGNED NOT NULL DEFAULT 99;

ALTER TABLE catalog_addons
    ADD COLUMN IF NOT EXISTS addon_name VARCHAR(100) NOT NULL,
    ADD COLUMN IF NOT EXISTS additional_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS max_quantity SMALLINT UNSIGNED NOT NULL DEFAULT 99;

ALTER TABLE product_addons
    ADD COLUMN IF NOT EXISTS additional_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS max_quantity SMALLINT UNSIGNED NOT NULL DEFAULT 99;

ALTER TABLE user_preferences
    ADD COLUMN IF NOT EXISTS user_id INT NOT NULL,
    ADD COLUMN IF NOT EXISTS preference_id INT NOT NULL,
    ADD COLUMN IF NOT EXISTS preference_value BOOLEAN NOT NULL DEFAULT TRUE;

ALTER TABLE recommendations
    ADD COLUMN IF NOT EXISTS user_id INT NOT NULL,
    ADD COLUMN IF NOT EXISTS product_id INT NOT NULL,
    ADD COLUMN IF NOT EXISTS recommendation_score DECIMAL(5,2) NOT NULL,
    ADD COLUMN IF NOT EXISTS recommendation_reason TEXT,
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

INSERT IGNORE INTO settings (setting_key, setting_value)
VALUES
    ('password_min_lowercase', '1'),
    ('password_min_uppercase', '1'),
    ('password_min_digits', '1'),
    ('password_min_special', '1');