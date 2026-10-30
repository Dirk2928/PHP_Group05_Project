<?php

/** Application-level protection for user data stored in MySQL. */
function brewski_personal_configure(PDO|mysqli $database): void
{
    if (PHP_SAPI !== 'cli') {
        if ($database instanceof PDO) {
            $maintenance = $database->query(
                "SELECT setting_value FROM settings WHERE setting_key = 'personal_data_migration' LIMIT 1"
            )->fetchColumn();
        } else {
            $maintenanceResult = $database->query(
                "SELECT setting_value FROM settings WHERE setting_key = 'personal_data_migration' LIMIT 1"
            );
            $maintenance = $maintenanceResult ? $maintenanceResult->fetch_column() : false;
        }
        if ($maintenance === '1') {
            $GLOBALS['brewski_personal_mode'] = 'maintenance';
            return;
        }
    }
    if ($database instanceof PDO) {
        $column = $database->query("SHOW COLUMNS FROM users LIKE 'email_lookup'")->fetch(PDO::FETCH_ASSOC);
    } else {
        $result = $database->query("SHOW COLUMNS FROM users LIKE 'email_lookup'");
        $column = $result ? $result->fetch_assoc() : false;
    }
    $migrated = is_array($column) && strtoupper((string) ($column['Null'] ?? '')) === 'NO';
    $hasKey = brewski_personal_key_available();
    $GLOBALS['brewski_personal_mode'] = $migrated
        ? ($hasKey ? 'encrypted' : 'missing_key')
        : 'legacy';
}

function brewski_personal_data_active(): bool
{
    $mode = $GLOBALS['brewski_personal_mode'] ?? null;
    if ($mode === null) {
        $mode = brewski_personal_key_available()
            ? 'encrypted' : 'legacy';
    }
    if ($mode === 'maintenance') {
        throw new RuntimeException('Personal data migration is in progress. Please try again shortly.');
    }
    if ($mode === 'missing_key') {
        throw new RuntimeException('Personal data encryption key is missing for a migrated database.');
    }
    return $mode === 'encrypted';
}

function brewski_email_lookup_column(): string
{
    return brewski_personal_data_active() ? 'email_lookup' : 'email';
}

function brewski_personal_key_available(): bool
{
    $personal = getenv('BREWSKI_PERSONAL_DATA_KEY');
    if (is_string($personal) && $personal !== '') {
        return (bool) preg_match('/\A[0-9a-fA-F]{64}\z/', $personal);
    }
    $totp = getenv('BREWSKI_TOTP_ENCRYPTION_KEY');
    return is_string($totp) && (bool) preg_match('/\A[0-9a-fA-F]{64}\z/', $totp);
}

function brewski_personal_data_key(): string
{
    $personal = getenv('BREWSKI_PERSONAL_DATA_KEY');
    if (is_string($personal) && $personal !== '') {
        if (!preg_match('/\A[0-9a-fA-F]{64}\z/', $personal)) {
            throw new RuntimeException('BREWSKI_PERSONAL_DATA_KEY must contain 64 hexadecimal characters.');
        }
        return hex2bin($personal);
    }
    $totp = getenv('BREWSKI_TOTP_ENCRYPTION_KEY');
    if (!is_string($totp) || !preg_match('/\A[0-9a-fA-F]{64}\z/', $totp)) {
        throw new RuntimeException('Configure BREWSKI_PERSONAL_DATA_KEY or BREWSKI_TOTP_ENCRYPTION_KEY.');
    }
    return hash_hkdf('sha256', hex2bin($totp), 32, 'brewski:personal-data-master:v1');
}

function brewski_personal_encrypt(string $value, string $field): string
{
    if (!brewski_personal_data_active()) {
        return $value;
    }
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $value,
        'aes-256-gcm',
        brewski_personal_data_key(),
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        'brewski:personal:v1:' . $field
    );
    if ($ciphertext === false || strlen($tag) !== 16) {
        throw new RuntimeException('Could not encrypt personal data.');
    }
    return 'v1:' . base64_encode($nonce . $tag . $ciphertext);
}

function brewski_personal_decrypt(?string $value, string $field, bool $allowLegacy = false): ?string
{
    if ($value === null) {
        return null;
    }
    if (!brewski_personal_data_active()) {
        return $value;
    }
    if (!str_starts_with($value, 'v1:')) {
        if ($allowLegacy) {
            return $value;
        }
        throw new RuntimeException('Unencrypted personal data found. Run the personal data migration.');
    }
    $payload = base64_decode(substr($value, 3), true);
    if ($payload === false || strlen($payload) < 28) {
        throw new RuntimeException('Encrypted personal data is malformed.');
    }
    $plain = openssl_decrypt(
        substr($payload, 28),
        'aes-256-gcm',
        brewski_personal_data_key(),
        OPENSSL_RAW_DATA,
        substr($payload, 0, 12),
        substr($payload, 12, 16),
        'brewski:personal:v1:' . $field
    );
    if ($plain === false) {
        throw new RuntimeException('Could not decrypt personal data. Check the encryption key.');
    }
    return $plain;
}

function brewski_email_lookup(string $email): string
{
    if (!brewski_personal_data_active()) {
        return $email;
    }
    $indexKey = hash_hkdf('sha256', brewski_personal_data_key(), 32, 'brewski:email-lookup:v1');
    return hash_hmac('sha256', mb_strtolower(trim($email), 'UTF-8'), $indexKey, true);
}

function brewski_lockout_lookup(string $email): string
{
    if (!brewski_personal_data_active()) {
        return $email;
    }
    $indexKey = hash_hkdf('sha256', brewski_personal_data_key(), 32, 'brewski:lockout-lookup:v1');
    return hash_hmac('sha256', mb_strtolower(trim($email), 'UTF-8'), $indexKey);
}

function brewski_decrypt_user_fields(array $row, bool $allowLegacy = false): array
{
    foreach (['first_name', 'last_name', 'email', 'avatar_data'] as $field) {
        if (array_key_exists($field, $row)) {
            $row[$field] = brewski_personal_decrypt($row[$field], $field, $allowLegacy);
        }
    }
    return $row;
}

function brewski_encrypted_user_values(string $firstName, string $lastName, string $email): array
{
    return [
        brewski_personal_encrypt($firstName, 'first_name'),
        brewski_personal_encrypt($lastName, 'last_name'),
        brewski_personal_encrypt($email, 'email'),
        brewski_email_lookup($email),
    ];
}

function brewski_update_user_identity(PDO $database, int $userId, string $firstName, string $lastName, string $email): void
{
    $values = brewski_encrypted_user_values($firstName, $lastName, $email);
    if (brewski_personal_data_active()) {
        $statement = $database->prepare(
            'UPDATE users SET first_name = ?, last_name = ?, email = ?, email_lookup = ? WHERE user_id = ?'
        );
        $statement->execute([...$values, $userId]);
    } else {
        $statement = $database->prepare(
            'UPDATE users SET first_name = ?, last_name = ?, email = ? WHERE user_id = ?'
        );
        $statement->execute([$values[0], $values[1], $values[2], $userId]);
    }
}

function brewski_prepare_customer_signup_insert(mysqli $connection): mysqli_stmt|false
{
    $lookupColumn = brewski_personal_data_active() ? ', email_lookup' : '';
    $lookupValue = $lookupColumn === '' ? '' : ', ?';
    return $connection->prepare(
        "INSERT INTO users
            (first_name, last_name, email{$lookupColumn}, password, role, is_active,
             is_first_timer, email_activation_token, email_activation_expires)
         VALUES (?, ?, ?{$lookupValue}, ?, 'CUSTOMER', 0, 1, ?, ?)"
    );
}
