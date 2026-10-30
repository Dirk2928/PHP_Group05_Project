<?php

require_once __DIR__ . '/../login-signup/session_init.php';
require_once __DIR__ . '/../login-signup/email-service-client.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function report_response(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['success' => $status === 200, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    report_response(405, 'This request is not supported.');
}

$token = $_POST['csrf_token'] ?? null;
if (!is_string($token) || empty($_SESSION['problem_report_csrf'])
    || !hash_equals((string) $_SESSION['problem_report_csrf'], $token)) {
    report_response(403, 'Please refresh the page and try again.');
}

$rawEmail = $_POST['email'] ?? null;
$rawMessage = $_POST['message'] ?? null;
if (!is_string($rawEmail) || !is_string($rawMessage)) {
    report_response(422, 'Enter a valid email and a message between 10 and 3000 characters.');
}
$email = trim($rawEmail);
$message = trim($rawMessage);
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254
    || mb_strlen($message, 'UTF-8') < 10 || mb_strlen($message, 'UTF-8') > 3000) {
    report_response(422, 'Enter a valid email and a message between 10 and 3000 characters.');
}

$lastSent = (int) ($_SESSION['problem_report_sent_at'] ?? 0);
if ($lastSent > time() - 60) {
    report_response(429, 'Please wait a minute before sending another report.');
}

$recipient = brewski_env('BREWSKI_REPORT_RECIPIENT', '');
if ($recipient === '') {
    $recipient = brewski_env('MAILJET_SENDER', '');
}
if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    error_log('Problem report recipient is not configured.');
    report_response(503, 'Reports are temporarily unavailable. Please try again later.');
}

$safeEmail = brewski_mail_h($email);
$safeMessage = nl2br(brewski_mail_h($message), false);
$mail = [
    'to' => $recipient,
    'replyTo' => $email,
    'subject' => 'Brewski problem report',
    'text' => "A visitor reported a problem.\n\nReply to: {$email}\n\nMessage:\n{$message}",
    'html' => '<div style="font-family:Arial,sans-serif;max-width:640px;margin:auto;color:#1e110a">'
        . '<h2>Brewski problem report</h2><p><strong>Reply to:</strong> '
        . $safeEmail . '</p><p><strong>Message:</strong></p><p>' . $safeMessage . '</p></div>',
];

if (!brewski_mailjet_send($mail)) {
    report_response(503, 'Unable to send your report right now. Please try again later.');
}

$_SESSION['problem_report_sent_at'] = time();
report_response(200, 'Thanks. Your report has been sent.');
