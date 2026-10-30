<?php

require_once __DIR__ . '/../Db/db_config.php';

$userId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$userId || $userId < 1) {
    http_response_code(404);
    exit;
}

$connection = brewski_db_connection();
$stmt = $connection->prepare(
    'SELECT avatar_data, avatar_mime_type FROM users WHERE user_id = ? LIMIT 1'
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$stmt->bind_result($data, $mime);
$stmt->fetch();
$stmt->close();
$connection->close();

if (!$data || !$mime) {
    http_response_code(404);
    exit;
}

$safeTypes = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mime, $safeTypes, true)) {
    http_response_code(415);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($data));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=86400');

echo $data;