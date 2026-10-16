<?php

const BREWSKI_CONSENT_COOKIE = 'brewski_cookie_consent';
const BREWSKI_CONSENT_VERSION = '1';
const BREWSKI_CONSENT_LIFETIME = 31536000;

function brewski_consent_choice(): string
{
    $stored = $_COOKIE[BREWSKI_CONSENT_COOKIE] ?? '';

    if (!is_string($stored) || $stored === '') {
        return '';
    }

    $parts = explode(':', $stored, 2);

    if (count($parts) !== 2 || $parts[1] !== BREWSKI_CONSENT_VERSION) {
        return '';
    }

    return in_array($parts[0], ['accept', 'reject'], true) ? $parts[0] : '';
}

function brewski_consent_required(): bool
{
    return brewski_consent_choice() === '';
}

function brewski_consent_cookie_value(string $choice): string
{
    return $choice . ':' . BREWSKI_CONSENT_VERSION;
}

function brewski_consent_local_path(string $candidate): string
{
    $candidate = trim($candidate);

    if (
        $candidate === ''
        || $candidate[0] !== '/'
        || strpos($candidate, '//') === 0
        || strpos($candidate, '\\') !== false
        || strpos($candidate, "\r") !== false
        || strpos($candidate, "\n") !== false
        || parse_url($candidate, PHP_URL_SCHEME) !== null
        || parse_url($candidate, PHP_URL_HOST) !== null
    ) {
        return '';
    }

    return $candidate;
}

function brewski_consent_request_path(): string
{
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $path = strtok($requestUri, '?');

    return brewski_consent_local_path(is_string($path) ? $path : '');
}
