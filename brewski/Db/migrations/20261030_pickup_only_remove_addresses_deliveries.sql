-- Remove address and delivery storage and keep optional pickup notes on orders.
-- The address foreign key may have a generated name in existing deployments.
SET @address_fk = (
    SELECT CONSTRAINT_NAME
      FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'orders'
       AND COLUMN_NAME = 'address_id'
       AND REFERENCED_TABLE_NAME = 'addresses'
     LIMIT 1
);
SET @drop_address_fk_sql = IF(
    @address_fk IS NULL,
    'SELECT 1',
    CONCAT('ALTER TABLE orders DROP FOREIGN KEY `', REPLACE(@address_fk, '`', '``'), '`')
);
PREPARE drop_address_fk_stmt FROM @drop_address_fk_sql;
EXECUTE drop_address_fk_stmt;
DEALLOCATE PREPARE drop_address_fk_stmt;

SET @address_column_exists = (
    SELECT COUNT(*)
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'orders'
       AND COLUMN_NAME = 'address_id'
);
SET @drop_address_column_sql = IF(
    @address_column_exists > 0,
    'ALTER TABLE orders DROP COLUMN address_id',
    'SELECT 1'
);
PREPARE drop_address_column_stmt FROM @drop_address_column_sql;
EXECUTE drop_address_column_stmt;
DEALLOCATE PREPARE drop_address_column_stmt;

SET @order_notes_exists = (
    SELECT COUNT(*)
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'orders'
       AND COLUMN_NAME = 'order_notes'
);
SET @add_order_notes_sql = IF(
    @order_notes_exists = 0,
    'ALTER TABLE orders ADD COLUMN order_notes TEXT NULL',
    'SELECT 1'
);
PREPARE add_order_notes_stmt FROM @add_order_notes_sql;
EXECUTE add_order_notes_stmt;
DEALLOCATE PREPARE add_order_notes_stmt;

DROP TABLE IF EXISTS deliveries;
DROP TABLE IF EXISTS addresses;
DELETE FROM settings WHERE setting_key = 'delivery_enabled';
