<?php






define('RECAPTCHA_SITE_KEY',  '6LeAgvItAAAAANJmPUDzCAPi3vbUA3GtbxgZnIxm');
define('RECAPTCHA_SECRET_KEY', '6LeAgvItAAAAAAqVRct761l9Z7YjSw2NoXvfK2DI');

function recaptcha_verify(string $token): bool
{
    if ($token === '') {
        return false;
    }

    $post = [
        'secret'   => RECAPTCHA_SECRET_KEY,
        'response' => $token,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($post),
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    if ($response === false) {
        return false;
    }

    $data = json_decode($response, true);

    return !empty($data['success']);
}