<?php

function brewski_product_image_fallback(): string
{
    static $uri = null;

    if ($uri === null) {
        $uri = 'data:image/svg+xml;utf8,' . rawurlencode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"'
            . ' stroke="#a2886a" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M4 8h14v8a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4V8z"></path>'
            . '<path d="M18 10h1a3 3 0 0 1 0 6h-1"></path>'
            . '<path d="M2 22h20"></path>'
            . '<path d="M10 2v3"></path>'
            . '<path d="M14 2v3"></path>'
            . '</svg>'
        );
    }

    return $uri;
}

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
