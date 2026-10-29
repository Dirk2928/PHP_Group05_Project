-- Legacy migration: allow pickup orders without an address when the address
-- column exists. Skip cleanly on fresh or fully migrated pickup-only schemas.
SET @orders_table_exists = (
    SELECT COUNT(*)
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'orders'
);
SET @address_column_exists = (
    SELECT COUNT(*)
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'orders'
       AND COLUMN_NAME = 'address_id'
);
SET @allow_pickup_sql = IF(
    @orders_table_exists > 0 AND @address_column_exists > 0,
    'ALTER TABLE orders MODIFY COLUMN address_id INT NULL',
    'SELECT 1'
);
PREPARE allow_pickup_stmt FROM @allow_pickup_sql;
EXECUTE allow_pickup_stmt;
DEALLOCATE PREPARE allow_pickup_stmt;
