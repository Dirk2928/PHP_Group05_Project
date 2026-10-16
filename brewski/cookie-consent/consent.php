<?php

require_once __DIR__ . '/../login-signup/session_init.php';
require_once __DIR__ . '/consent-state.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ' . BREWSKI_BASE_URL . '/cookie-consent/privacy.php');
    exit;
}

$returnTo = brewski_consent_local_path((string) ($_POST['return'] ?? ''));

if ($returnTo === '') {
    $returnTo = BREWSKI_BASE_URL . '/Start-screen/start.php';
}

$submittedToken = $_POST['csrf_token'] ?? '';

if (
    empty($_SESSION['csrf_token'])
    || !is_string($submittedToken)
    || !hash_equals((string) $_SESSION['csrf_token'], $submittedToken)
) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'This request could not be verified. Reload the page and choose again.';
    exit;
}

$choice = $_POST['choice'] ?? '';

if (is_string($choice) && in_array($choice, ['accept', 'reject'], true)) {
    setcookie(BREWSKI_CONSENT_COOKIE, brewski_consent_cookie_value($choice), [
        'expires'  => time() + BREWSKI_CONSENT_LIFETIME,
        'path'     => '/',
        'secure'   => brewski_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

header('Location: ' . $returnTo);
exit;
