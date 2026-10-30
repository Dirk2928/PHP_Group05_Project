<?php

require_once __DIR__ . '/../../Db/connection.php';
require_once __DIR__ . '/../partials/order_support.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function customer_paymongo_webhook_respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    customer_paymongo_webhook_respond(405, ['ok' => false]);
}

$webhookSecret = trim((string) getenv('BREWSKI_PAYMONGO_WEBHOOK_SECRET'));
$signature = (string) ($_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '');
$rawBody = file_get_contents('php://input', false, null, 0, 1048577);
if ($webhookSecret === '' || !is_string($rawBody) || $rawBody === '') {
    error_log('PayMongo webhook is missing its secret or request body.');
    customer_paymongo_webhook_respond(503, ['ok' => false]);
}
if (strlen($rawBody) > 1048576) {
    customer_paymongo_webhook_respond(413, ['ok' => false]);
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    customer_paymongo_webhook_respond(400, ['ok' => false]);
}

$eventData = $payload['data'] ?? [];
$eventAttributes = is_array($eventData) ? ($eventData['attributes'] ?? []) : [];
if (!is_array($eventAttributes)) {
    $eventAttributes = [];
}
$liveMode = (bool) (
    $eventAttributes['livemode']
    ?? $eventData['livemode']
    ?? $payload['livemode']
    ?? false
);
if (!customer_paymongo_signature_is_valid($rawBody, $signature, $webhookSecret, $liveMode)) {
    error_log('Rejected PayMongo webhook with an invalid signature.');
    customer_paymongo_webhook_respond(401, ['ok' => false]);
}

$eventType = (string) (
    $eventAttributes['type']
    ?? $eventData['type']
    ?? ''
);
if ($eventType !== 'checkout_session.payment.paid') {
    customer_paymongo_webhook_respond(200, ['ok' => true]);
}

$session = $eventAttributes['data'] ?? $eventData['data'] ?? [];
if (!is_array($session)) {
    customer_paymongo_webhook_respond(400, ['ok' => false]);
}

try {
    $completedPayment = customer_complete_paymongo_payment($pdo, $session);
    if ($completedPayment === null) {
        error_log('PayMongo payment webhook did not match a pending Brewski order.');
        customer_paymongo_webhook_respond(422, ['ok' => false]);
    }
} catch (Throwable $error) {
    error_log('Could not process PayMongo payment webhook: ' . $error->getMessage());
    customer_paymongo_webhook_respond(500, ['ok' => false]);
}

customer_paymongo_webhook_respond(200, ['ok' => true]);
