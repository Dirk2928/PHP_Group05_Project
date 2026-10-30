<?php

/** Application-level protection for user data stored in MySQL. */
function brewski_personal_data_key(): string
{
    $hex = getenv('BREWSKI_PERSONAL_DATA_KEY');
    if (!is_string($hex) || !preg_match('/\A[0-9a-fA-F]{64}\z/', $hex)) {
        throw new RuntimeException('BREWSKI_PERSONAL_DATA_KEY must contain 64 hexadecimal characters.');
    }
    return hex2bin($hex);
}

function brewski_personal_encrypt(string $value, string $field): string
{
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
    $indexKey = hash_hkdf('sha256', brewski_personal_data_key(), 32, 'brewski:email-lookup:v1');
    return hash_hmac('sha256', mb_strtolower(trim($email), 'UTF-8'), $indexKey, true);
}

function brewski_lockout_lookup(string $email): string
{
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
