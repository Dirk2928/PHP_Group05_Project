<?php

if (!defined('BREWSKI_PASSWORD_LENGTH_DEFAULT')) {
    define('BREWSKI_PASSWORD_LENGTH_DEFAULT', 12);
}

if (!defined('BREWSKI_PASSWORD_LENGTH_MIN')) {
    define('BREWSKI_PASSWORD_LENGTH_MIN', 8);
}

if (!defined('BREWSKI_PASSWORD_LENGTH_MAX')) {
    define('BREWSKI_PASSWORD_LENGTH_MAX', 64);
}

if (!defined('BREWSKI_SESSION_IDLE_DEFAULT')) {
    define('BREWSKI_SESSION_IDLE_DEFAULT', 1800);
}

if (!defined('BREWSKI_SESSION_IDLE_MIN')) {
    define('BREWSKI_SESSION_IDLE_MIN', 60);
}

if (!defined('BREWSKI_SESSION_IDLE_MAX')) {
    define('BREWSKI_SESSION_IDLE_MAX', 14400);
}

if (!defined('BREWSKI_SESSION_ABSOLUTE_DEFAULT')) {
    define('BREWSKI_SESSION_ABSOLUTE_DEFAULT', 28800);
}

if (!defined('BREWSKI_SESSION_ABSOLUTE_MIN')) {
    define('BREWSKI_SESSION_ABSOLUTE_MIN', 3600);
}

if (!defined('BREWSKI_SESSION_ABSOLUTE_MAX')) {
    define('BREWSKI_SESSION_ABSOLUTE_MAX', 86400);
}

function brewski_settings_connection()
{
    static $connection = null;
    static $resolved = false;

    if ($resolved) {
        return $connection;
    }

    $resolved = true;

    $previous_report_mode = mysqli_report(MYSQLI_REPORT_OFF);

    $candidate = new mysqli('localhost', 'root', '', 'brewski_db');

    mysqli_report($previous_report_mode);

    if ($candidate->connect_error) {
        return $connection;
    }

    $candidate->set_charset('utf8mb4');

    $connection = $candidate;

    return $connection;
}

function brewski_settings_all($refresh = false)
{
    static $settings = null;

    if ($settings !== null && !$refresh) {
        return $settings;
    }

    $settings = [];

    $connection = brewski_settings_connection();

    if (!$connection) {
        return $settings;
    }

    $result = $connection->query('SELECT setting_key, setting_value FROM settings');

    if (!$result) {
        return $settings;
    }

    while ($row = $result->fetch_assoc()) {
        $settings[$row['setting_key']] = (string) $row['setting_value'];
    }

    $result->free();

    return $settings;
}

function brewski_setting($key, $default = '')
{
    $settings = brewski_settings_all();

    if (!isset($settings[$key]) || $settings[$key] === '') {
        return $default;
    }

    return $settings[$key];
}

function brewski_setting_record($key)
{
    $record = [
        'value' => '',
        'updated_at' => ''
    ];

    $connection = brewski_settings_connection();

    if (!$connection) {
        return $record;
    }

    $statement = $connection->prepare(
        'SELECT setting_value, updated_at
         FROM settings
         WHERE setting_key = ?
         LIMIT 1'
    );

    if (!$statement) {
        return $record;
    }

    $statement->bind_param('s', $key);
    $statement->execute();
    $statement->bind_result($value, $updated_at);

    if ($statement->fetch()) {
        $record['value'] = (string) $value;
        $record['updated_at'] = (string) $updated_at;
    }

    $statement->close();

    return $record;
}

function brewski_setting_set($key, $value)
{
    $connection = brewski_settings_connection();

    if (!$connection) {
        return false;
    }

    $statement = $connection->prepare(
        'INSERT INTO settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );

    if (!$statement) {
        return false;
    }

    $statement->bind_param('ss', $key, $value);
    $saved = $statement->execute();
    $statement->close();

    if ($saved) {
        brewski_settings_all(true);
    }

    return (bool) $saved;
}

function brewski_bounded_int($key, $default, $min, $max)
{
    $stored = (int) brewski_setting($key, '');

    if ($stored < $min || $stored > $max) {
        return $default;
    }

    return $stored;
}

function brewski_password_length_bounds()
{
    return [
        'min' => BREWSKI_PASSWORD_LENGTH_MIN,
        'max' => BREWSKI_PASSWORD_LENGTH_MAX,
        'default' => BREWSKI_PASSWORD_LENGTH_DEFAULT
    ];
}

function brewski_min_password_length()
{
    return brewski_bounded_int(
        'min_password_length',
        BREWSKI_PASSWORD_LENGTH_DEFAULT,
        BREWSKI_PASSWORD_LENGTH_MIN,
        BREWSKI_PASSWORD_LENGTH_MAX
    );
}

function brewski_session_timeout_bounds()
{
    return [
        'idle_min' => BREWSKI_SESSION_IDLE_MIN,
        'idle_max' => BREWSKI_SESSION_IDLE_MAX,
        'idle_default' => BREWSKI_SESSION_IDLE_DEFAULT,
        'absolute_min' => BREWSKI_SESSION_ABSOLUTE_MIN,
        'absolute_max' => BREWSKI_SESSION_ABSOLUTE_MAX,
        'absolute_default' => BREWSKI_SESSION_ABSOLUTE_DEFAULT
    ];
}

function brewski_session_idle_timeout()
{
    return brewski_bounded_int(
        'session_idle_timeout',
        BREWSKI_SESSION_IDLE_DEFAULT,
        BREWSKI_SESSION_IDLE_MIN,
        BREWSKI_SESSION_IDLE_MAX
    );
}

function brewski_session_absolute_timeout()
{
    return brewski_bounded_int(
        'session_absolute_timeout',
        BREWSKI_SESSION_ABSOLUTE_DEFAULT,
        BREWSKI_SESSION_ABSOLUTE_MIN,
        BREWSKI_SESSION_ABSOLUTE_MAX
    );
}
