<?php

function brewski_ensure_order_customization_columns(PDO $connection): void
{
    static $done = false;
    if ($done) {
        return;
    }
    foreach (['cart_items', 'order_items'] as $table) {
        $column = $connection->query("SHOW COLUMNS FROM {$table} LIKE 'customization_summary'");
        if (!$column->fetch()) {
            $connection->exec("ALTER TABLE {$table} ADD COLUMN customization_summary TEXT NULL");
        }
    }
    $done = true;
}
