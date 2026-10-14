<?php

require_once __DIR__ . '/db_config.php';

if (!isset($pdo)) {
    $pdo = brewski_db_pdo();

    if (!$pdo) {
        http_response_code(500);
        exit('Database connection failed.');
    }
}