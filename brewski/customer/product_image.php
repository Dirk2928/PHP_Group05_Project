<?php

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$productId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$productId || $productId < 1) {
    http_response_code(404);
    exit;
}

try {
    $connection = new mysqli('localhost', 'root', '', 'brewski_db');
    $connection->set_charset('utf8mb4');
    $statement = $connection->prepare(
        'SELECT image_data, image_mime_type FROM products
         WHERE product_id = ? AND image_data IS NOT NULL'
    );
    $statement->bind_param('i', $productId);
    $statement->execute();
    $statement->bind_result($imageData, $mimeType);

    if (!$statement->fetch()
        || !in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        $statement->close();
        $connection->close();
        http_response_code(404);
        exit;
    }

    $statement->close();
    $connection->close();

    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . strlen($imageData));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=86400');
    echo $imageData;
} catch (mysqli_sql_exception $error) {
    error_log('Product image retrieval error: ' . $error->getMessage());
    http_response_code(500);
}
