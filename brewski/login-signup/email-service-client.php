<?php

require_once __DIR__ . '/../Db/db_config.php';

function email_service_send(string $endpoint, array $payload): bool
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
