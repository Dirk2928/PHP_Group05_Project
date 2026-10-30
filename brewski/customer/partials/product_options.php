<?php

require_once __DIR__ . '/catalog.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$productId = filter_var(
    $_GET['product_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($productId === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'A valid product is required.']);
    exit;
}

try {
    $catalog = brewski_load_catalog();
    foreach ($catalog['products'] as $product) {
        if ((int) $product['product_id'] === (int) $productId) {
            echo json_encode(['ok' => true, 'options' => $product['customizations']]);
            exit;
        }
    }

    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'This product is no longer available.']);
} catch (Throwable $error) {
    error_log('Could not load current product options: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Could not load the latest add-on choices. Please try again.']);
}
