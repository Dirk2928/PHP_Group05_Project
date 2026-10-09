<?php

function brewski_ensure_product_image_columns(mysqli $connection): void
{
    $columns = [
        'image_data' => 'LONGBLOB NULL',
        'image_mime_type' => 'VARCHAR(32) NULL',
    ];

    foreach ($columns as $column => $definition) {
        $result = $connection->query(
            "SHOW COLUMNS FROM products LIKE '" . $connection->real_escape_string($column) . "'"
        );
        $exists = $result->num_rows > 0;
        $result->free();

        if (!$exists) {
            $connection->query(
                'ALTER TABLE products ADD COLUMN ' . $column . ' ' . $definition
            );
        }
    }
}
