<?php

require_once __DIR__ . '/personal_data.php';

function brewski_env(string $key, string $default = '', bool $trim = true): string
{
    $value = getenv($key);

    if ($value === false) {
        return $default;
    }

    if ($trim) {
        $value = trim($value);
    }

    if ($value === '') {
        return $default;
    }

    return $value;
}

function brewski_env_flag(string $key, bool $default = false): bool
{
    $value = strtolower(brewski_env($key, ''));

    if ($value === '') {
        return $default;
    }

    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function brewski_db_settings(): array
{
    $ssl_ca = brewski_env('DB_SSL_CA', '');
    $ssl_requested = brewski_env_flag('DB_SSL') || $ssl_ca !== '';
    $host = brewski_env('DB_HOST', '');

    if ($host === '') {
        if (brewski_env('RENDER', '') !== '' || brewski_env('RENDER_SERVICE_NAME', '') !== '') {
            error_log(
                'Brewski: DB_HOST is not set on this host. Set DB_HOST, DB_PORT, DB_NAME, '
                . 'DB_USER and DB_PASS on the service, or the app will try localhost.'
            );
        }

        $host = 'localhost';
    }

    if ($ssl_ca !== '' && !is_readable($ssl_ca)) {
        error_log(
            'Brewski: DB_SSL_CA is not a readable file. Continuing with TLS but without '
            . 'certificate verification. Set DB_SSL_CA to the path of a CA certificate.'
        );

        $ssl_ca = '';
    }

    return [
        'host'   => $host,
        'port'   => (int) brewski_env('DB_PORT', '3306'),
        'name'   => brewski_env('DB_NAME', 'brewski_db'),
        'user'   => brewski_env('DB_USER', 'root'),
        'pass'   => brewski_env('DB_PASS', '', false),
        'ssl'    => $ssl_requested,
        'ssl_ca' => $ssl_ca,
    ];
}

function brewski_db_tls_connect(array $settings): ?mysqli
{
    $connection = mysqli_init();

    if (!$connection) {
        return null;
    }

    $flags = MYSQLI_CLIENT_SSL;

    if ($settings['ssl_ca'] !== '') {
        $connection->ssl_set(null, null, $settings['ssl_ca'], null, null);
    } else {
        $flags |= MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT;
    }

    $connected = $connection->real_connect(
        $settings['host'],
        $settings['user'],
        $settings['pass'],
        $settings['name'],
        $settings['port'],
        null,
        $flags
    );

    if (!$connected) {
        return null;
    }

    return $connection;
}

function brewski_db_connect(): ?mysqli
{
    $settings = brewski_db_settings();
    $driver = new mysqli_driver();
    $previous_report_mode = $driver->report_mode;
    mysqli_report(MYSQLI_REPORT_OFF);
    $connection = null;

    try {
        if ($settings['ssl']) {
            $candidate = brewski_db_tls_connect($settings);
        } else {
            $candidate = new mysqli(
                $settings['host'],
                $settings['user'],
                $settings['pass'],
                $settings['name'],
                $settings['port']
            );
        }

        if ($candidate instanceof mysqli && !$candidate->connect_errno) {
            $candidate->set_charset('utf8mb4');
            brewski_personal_configure($candidate);
            $connection = $candidate;
        }
    } catch (Throwable $error) {
        $connection = null;
    } finally {
        mysqli_report($previous_report_mode);
    }

    return $connection;
}

function brewski_db_connection(): mysqli
{
    $connection = brewski_db_connect();

    if (!$connection) {
        throw new mysqli_sql_exception('Unable to connect to the database.');
    }

    return $connection;
}

function brewski_db_pdo(): ?PDO
{
    $settings = brewski_db_settings();

    $dsn = 'mysql:host=' . $settings['host']
        . ';port=' . $settings['port']
        . ';dbname=' . $settings['name']
        . ';charset=utf8mb4';

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    if ($settings['ssl']) {
        if ($settings['ssl_ca'] !== '') {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $settings['ssl_ca'];
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        } else {
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }
    }

    try {
        $database = new PDO($dsn, $settings['user'], $settings['pass'], $options);
        brewski_personal_configure($database);
        return $database;
    } catch (PDOException $error) {
        return null;
    }
}

function brewski_email_service_url(string $endpoint): string
{
    $base = brewski_env('EMAIL_SERVICE_URL', 'http://127.0.0.1:3000');

    if (!preg_match('#^https?://#i', $base)) {
        $base = 'https://' . $base;
    }

    return rtrim($base, '/') . '/' . ltrim($endpoint, '/');
}

function brewski_app_public_url(string $path = ''): ?string
{
    $configuredBase = brewski_env('BREWSKI_PUBLIC_URL', '');
    $renderBase = brewski_env('RENDER_EXTERNAL_URL', '');

    if ($renderBase === '') {
        $renderHostname = brewski_env('RENDER_EXTERNAL_HOSTNAME', '');
        $renderBase = $renderHostname === '' ? '' : 'https://' . $renderHostname;
    }

    $base = $configuredBase !== '' ? $configuredBase : $renderBase;

    if ($base !== '') {
        $parts = parse_url($base);

        if (
            $parts === false
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            error_log('Brewski: configured public URL is invalid.');
            return null;
        }

        if ($configuredBase === '') {
            $base = rtrim($base, '/') . (defined('BREWSKI_BASE_URL') ? BREWSKI_BASE_URL : '');
        }
    } else {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');

        if (
            preg_match(
                '/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*(?::[0-9]{1,5})?\z/i',
                $host
            ) !== 1
            || (parse_url('http://' . $host, PHP_URL_PORT) !== null
                && (int) parse_url('http://' . $host, PHP_URL_PORT) > 65535)
        ) {
            error_log('Brewski: request host is invalid; cannot build a public URL.');
            return null;
        }

        $scheme = (
            !empty($_SERVER['HTTPS'])
            && strtolower((string) $_SERVER['HTTPS']) !== 'off'
        ) ? 'https' : 'http';

        $forwardedProto = strtolower(trim(explode(
            ',',
            (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')
        )[0]));

        if (in_array($forwardedProto, ['http', 'https'], true)) {
            $scheme = $forwardedProto;
        }

        $base = $scheme . '://' . $host
            . (defined('BREWSKI_BASE_URL') ? BREWSKI_BASE_URL : '');
    }

    return rtrim($base, '/') . '/' . ltrim($path, '/');
}
