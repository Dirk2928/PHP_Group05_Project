<?php

require_once __DIR__ . '/../Db/db_config.php';

function brewski_mail_text_ok($value, int $max): bool
{
    return is_string($value)
        && trim($value) !== ''
        && mb_strlen($value, 'UTF-8') <= $max;
}

function brewski_mail_email_ok($value): bool
{
    return brewski_mail_text_ok($value, 254)
        && preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $value) === 1;
}

function brewski_mail_amount_ok($value): bool
{
    if (is_int($value) || is_float($value)) {
        return is_finite((float) $value) && $value >= 0;
    }

    return is_string($value) && preg_match('/^\d+(?:\.\d{1,2})?$/', $value) === 1;
}

function brewski_mail_amount($value): string
{
    return number_format((float) $value, 2, '.', '');
}

function brewski_mail_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Builds the subject, plain-text and HTML parts for an endpoint, or returns null
 * when the payload is missing or invalid.
 */
function brewski_mail_build(string $endpoint, array $payload): ?array
{
    $endpoint = trim($endpoint, '/');

    if ($endpoint === 'send-activation') {
        $email = $payload['email'] ?? null;
        $firstName = $payload['firstName'] ?? null;
        $activationUrl = $payload['activationUrl'] ?? null;

        if (
            !brewski_mail_email_ok($email)
            || !brewski_mail_text_ok($firstName, 100)
            || !brewski_mail_text_ok($activationUrl, 2048)
        ) {
            return null;
        }

        $parts = parse_url($activationUrl);

        if (
            $parts === false
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return null;
        }

        $safeName = brewski_mail_h($firstName);
        $safeUrl = brewski_mail_h($activationUrl);

        return [
            'to' => $email,
            'subject' => 'Activate your Brewski account',
            'text' => "Hello {$firstName},\n\n"
                . "Activate your account using this link (valid for 24 hours):\n"
                . "{$activationUrl}\n\n"
                . 'If you did not create an account, you can ignore this email.',
            'html' => '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;color:#1e110a">'
                . "<h2>Welcome to Brewski, {$safeName}!</h2>"
                . '<p>Click below to activate your account. This link expires in 24 hours.</p>'
                . '<p><a href="' . $safeUrl . '" style="display:inline-block;padding:12px 24px;'
                . 'background:#1e110a;color:#f1e2ca;text-decoration:none;border-radius:6px">'
                . 'Activate my account</a></p>'
                . '<p style="font-size:12px;color:#71492a">'
                . 'If you did not create an account, you can ignore this email.</p>'
                . '</div>',
        ];
    }

    if ($endpoint === 'send-password-reset') {
        $email = $payload['email'] ?? null;
        $firstName = $payload['firstName'] ?? null;
        $resetUrl = $payload['resetUrl'] ?? null;

        if (
            !brewski_mail_email_ok($email)
            || !brewski_mail_text_ok($firstName, 100)
            || !brewski_mail_text_ok($resetUrl, 2048)
        ) {
            return null;
        }

        $safeName = brewski_mail_h($firstName);
        $safeUrl = brewski_mail_h($resetUrl);

        return [
            'to' => $email,
            'subject' => 'Reset your Brewski password',
            'text' => "Hello {$firstName},\n\n"
                . "Reset your password using this link (valid for 1 hour):\n"
                . "{$resetUrl}\n\n"
                . 'If you did not request a password reset, you can ignore this email.',
            'html' => '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;color:#1e110a">'
                . "<h2>Hello, {$safeName}!</h2>"
                . '<p>Use the link below to reset your Brewski password. This link expires in 1 hour.</p>'
                . '<p><a href="' . $safeUrl . '" style="display:inline-block;padding:12px 24px;'
                . 'background:#1e110a;color:#f1e2ca;text-decoration:none;border-radius:6px">'
                . 'Reset my password</a></p>'
                . '<p style="font-size:12px;color:#71492a">'
                . 'If you did not request a password reset, you can ignore this email.</p>'
                . '</div>',
        ];
    }

    if ($endpoint === 'send-order-otp') {
        $email = $payload['email'] ?? null;
        $firstName = $payload['firstName'] ?? null;
        $otp = $payload['otp'] ?? null;

        if (
            !brewski_mail_email_ok($email)
            || !brewski_mail_text_ok($firstName, 100)
            || !is_string($otp)
            || preg_match('/^\d{6}$/', $otp) !== 1
        ) {
            return null;
        }

        $safeName = brewski_mail_h($firstName);

        return [
            'to' => $email,
            'subject' => 'Your Brewski order verification code',
            'text' => "Hello {$firstName},\n\n"
                . "Your Brewski order verification code is {$otp}. It expires in 10 minutes. "
                . "You have up to 5 attempts to enter it.\n\n"
                . 'If you did not try to place an order, you can ignore this email.',
            'html' => '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;color:#1e110a">'
                . '<h2>Verify your Brewski order</h2>'
                . "<p>Hello {$safeName}, enter this code in the order confirmation window to continue:</p>"
                . '<p style="font-size:32px;font-weight:bold;letter-spacing:8px;background:#f1e2ca;'
                . 'padding:14px 20px;display:inline-block">' . $otp . '</p>'
                . '<p>This code expires in 10 minutes. You have up to 5 attempts.</p>'
                . '<p style="font-size:12px;color:#71492a">'
                . 'If you did not try to place an order, you can ignore this email.</p>'
                . '</div>',
        ];
    }

    if ($endpoint === 'send-signup-otp') {
        $email = $payload['email'] ?? null;
        $firstName = $payload['firstName'] ?? null;
        $otp = $payload['otp'] ?? null;

        if (
            !brewski_mail_email_ok($email)
            || !brewski_mail_text_ok($firstName, 100)
            || !is_string($otp)
            || preg_match('/^\d{6}$/', $otp) !== 1
        ) {
            return null;
        }

        $safeName = brewski_mail_h($firstName);

        return [
            'to' => $email,
            'subject' => 'Verify your Brewski account',
            'text' => "Hello {$firstName},\n\n"
                . "Your Brewski verification code is {$otp}. It expires in 10 minutes. "
                . "You have up to 5 attempts.\n\n"
                . 'If you did not create a Brewski account, you can ignore this email.',
            'html' => '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;color:#1e110a">'
                . "<h2>Verify your Brewski account</h2>"
                . "<p>Hello {$safeName}, enter this code to continue creating your account:</p>"
                . '<p style="font-size:32px;font-weight:bold;letter-spacing:8px;background:#f1e2ca;'
                . 'padding:14px 20px;display:inline-block">' . $otp . '</p>'
                . '<p>This code expires in 10 minutes and has up to 5 attempts.</p>'
                . '<p style="font-size:12px;color:#71492a">'
                . 'If you did not create a Brewski account, you can ignore this email.</p>'
                . '</div>',
        ];
    }

    if ($endpoint === 'send-order-receipt') {
        $email = $payload['email'] ?? null;
        $customerName = $payload['customerName'] ?? null;
        $receiptNumber = $payload['receiptNumber'] ?? null;
        $orderDate = $payload['orderDate'] ?? null;
        $address = $payload['address'] ?? null;
        $deliveryNotes = $payload['deliveryNotes'] ?? '';
        $items = $payload['items'] ?? null;
        $subtotal = $payload['subtotal'] ?? null;
        $deliveryFee = $payload['deliveryFee'] ?? null;
        $total = $payload['total'] ?? null;

        if (
            !brewski_mail_email_ok($email)
            || !brewski_mail_text_ok($customerName, 200)
            || !brewski_mail_text_ok($receiptNumber, 40)
            || !brewski_mail_text_ok($orderDate, 40)
            || !is_array($address)
            || !brewski_mail_text_ok($address['addressLine'] ?? null, 255)
            || !brewski_mail_text_ok($address['city'] ?? null, 100)
            || !brewski_mail_text_ok($address['province'] ?? null, 100)
            || !brewski_mail_text_ok($address['postalCode'] ?? null, 20)
            || !is_string($deliveryNotes)
            || mb_strlen($deliveryNotes, 'UTF-8') > 500
            || !is_array($items)
            || count($items) === 0
            || count($items) > 100
            || !brewski_mail_amount_ok($subtotal)
            || !brewski_mail_amount_ok($deliveryFee)
            || !brewski_mail_amount_ok($total)
        ) {
            return null;
        }

        foreach ($items as $item) {
            if (
                !is_array($item)
                || !brewski_mail_text_ok($item['name'] ?? null, 255)
                || !brewski_mail_text_ok($item['size'] ?? null, 100)
                || !is_int($item['quantity'] ?? null)
                || $item['quantity'] < 1
                || $item['quantity'] > 99
                || !brewski_mail_amount_ok($item['unitPrice'] ?? null)
                || !brewski_mail_amount_ok($item['lineTotal'] ?? null)
            ) {
                return null;
            }
        }

        $addressText = implode(', ', [
            $address['addressLine'],
            $address['city'],
            $address['province'],
            $address['postalCode'],
        ]);

        $notes = trim($deliveryNotes);
        $itemLines = [];
        $itemRows = '';

        foreach ($items as $item) {
            $itemLines[] = "{$item['name']} ({$item['size']}) x {$item['quantity']} - PHP "
                . brewski_mail_amount($item['lineTotal']);

            $itemRows .= '<tr>'
                . '<td style="padding:10px;border-bottom:1px solid #e8dac4">'
                . brewski_mail_h($item['name']) . ' (' . brewski_mail_h($item['size']) . ')</td>'
                . '<td style="padding:10px;border-bottom:1px solid #e8dac4;text-align:center">'
                . (int) $item['quantity'] . '</td>'
                . '<td style="padding:10px;border-bottom:1px solid #e8dac4;text-align:right">PHP '
                . brewski_mail_amount($item['lineTotal']) . '</td>'
                . '</tr>';
        }

        $notesText = $notes !== '' ? "\nDelivery notes: {$notes}\n" : '';
        $notesHtml = $notes !== ''
            ? '<p><strong>Delivery notes:</strong><br>'
                . nl2br(brewski_mail_h($notes), false) . '</p>'
            : '';

        $text = "Hello {$customerName},\n\n"
            . "Thank you for your order. Here is your receipt.\n\n"
            . "Receipt: {$receiptNumber}\n"
            . "Order date: {$orderDate}\n"
            . "Delivery address: {$addressText}{$notesText}\n\n"
            . implode("\n", $itemLines) . "\n\n"
            . 'Subtotal: PHP ' . brewski_mail_amount($subtotal) . "\n"
            . 'Delivery fee: PHP ' . brewski_mail_amount($deliveryFee) . "\n"
            . 'Total: PHP ' . brewski_mail_amount($total) . "\n";

        $html = '<div style="font-family:Arial,sans-serif;max-width:640px;margin:auto;color:#1a0f0a">'
            . '<h2>Brewski order receipt</h2>'
            . '<p>Hello ' . brewski_mail_h($customerName) . ', thank you for your order.</p>'
            . '<p><strong>Receipt:</strong> ' . brewski_mail_h($receiptNumber) . '<br>'
            . '<strong>Order date:</strong> ' . brewski_mail_h($orderDate) . '<br>'
            . '<strong>Delivery address:</strong> ' . brewski_mail_h($addressText) . '</p>'
            . $notesHtml
            . '<table style="width:100%;border-collapse:collapse"><thead><tr>'
            . '<th style="padding:10px;text-align:left;border-bottom:2px solid #5c4033">Item</th>'
            . '<th style="padding:10px;border-bottom:2px solid #5c4033">Qty</th>'
            . '<th style="padding:10px;text-align:right;border-bottom:2px solid #5c4033">Amount</th>'
            . '</tr></thead><tbody>' . $itemRows . '</tbody></table>'
            . '<p style="text-align:right">'
            . 'Subtotal: PHP ' . brewski_mail_amount($subtotal) . '<br>'
            . 'Delivery fee: PHP ' . brewski_mail_amount($deliveryFee) . '<br>'
            . '<strong>Total: PHP ' . brewski_mail_amount($total) . '</strong></p>'
            . '</div>';

        return [
            'to' => $email,
            'subject' => 'Your Brewski order receipt - ' . $receiptNumber,
            'text' => $text,
            'html' => $html,
        ];
    }

    return null;
}

/** Sends one prepared email through Mailjet's Send API (HTTPS). */
function brewski_mailjet_send(array $mail): bool
{
    $apiKey = brewski_env('MAILJET_API_KEY', '');
    $secretKey = brewski_env('MAILJET_SECRET_KEY', '');
    $sender = brewski_env('MAILJET_SENDER', '');
    $senderName = brewski_env('MAILJET_SENDER_NAME', 'Brewski');

    if ($apiKey === '' || $secretKey === '' || $sender === '') {
        error_log('Mailjet is not configured: set MAILJET_API_KEY, MAILJET_SECRET_KEY and MAILJET_SENDER.');
        return false;
    }

    $json = json_encode([
        'Messages' => [[
            'From' => ['Email' => $sender, 'Name' => $senderName],
            'To' => [['Email' => $mail['to']]],
            'Subject' => $mail['subject'],
            'TextPart' => $mail['text'],
            'HTMLPart' => $mail['html'],
        ]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        error_log('Mailjet request could not be encoded.');
        return false;
    }

    $ch = curl_init('https://api.mailjet.com/v3.1/send');

    if ($ch === false) {
        error_log('Could not initialize the Mailjet request.');
        return false;
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_USERPWD => $apiKey . ':' . $secretKey,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false) {
        error_log('Mailjet request failed: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }

    curl_close($ch);
    $result = json_decode($response, true);
    $status = is_array($result) ? ($result['Messages'][0]['Status'] ?? null) : null;

    if ($httpCode !== 200 || $status !== 'success') {
        $detail = '';

        if (is_array($result)) {
            $errors = $result['Messages'][0]['Errors'] ?? [];
            $messages = array_map(
                static fn($e) => is_array($e) ? (string) ($e['ErrorMessage'] ?? '') : '',
                is_array($errors) ? $errors : []
            );
            $detail = trim(implode('; ', array_filter($messages)));

            if ($detail === '') {
                $detail = (string) ($result['ErrorMessage'] ?? '');
            }
        }

        if ($detail === '') {
            $detail = substr((string) $response, 0, 300);
        }

        error_log('Mailjet returned HTTP ' . $httpCode . ': ' . $detail);
        return false;
    }

    return true;
}

/** Original behaviour: post to the separate Node email service. */
function brewski_email_service_http(string $endpoint, array $payload): bool
{
    $url = brewski_email_service_url($endpoint);
    $json = json_encode($payload);

    if ($json === false) {
        error_log('Email service request could not be encoded.');
        return false;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        error_log('Could not initialize the email service request.');
        return false;
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 45
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false) {
        error_log('Email service request failed: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }

    curl_close($ch);
    $result = json_decode($response, true);

    if ($httpCode !== 200 || !is_array($result) || ($result['success'] ?? false) !== true) {
        error_log('Email service returned HTTP ' . $httpCode . ': ' . $response);
        return false;
    }

    return true;
}

function email_service_send(string $endpoint, array $payload): bool
{
    $useMailjet = brewski_env('MAILJET_API_KEY', '') !== '';
    $consoleMode = strtolower(brewski_env('MAIL_MODE', '')) === 'console';

    if (!$useMailjet && !$consoleMode) {
        return brewski_email_service_http($endpoint, $payload);
    }

    $mail = brewski_mail_build($endpoint, $payload);

    if ($mail === null) {
        error_log('Email request "' . $endpoint . '" has missing or invalid information.');
        return false;
    }

    if ($consoleMode) {
        error_log(
            "DEV MODE: EMAIL NOT SENT\nTo: " . $mail['to'] . "\nSubject: " . $mail['subject']
            . "\n\n" . $mail['text']
        );
        return true;
    }

    return brewski_mailjet_send($mail);
}