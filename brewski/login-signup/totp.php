<?php

function brewski_totp_generate_secret(): string
{
    return brewski_totp_base32_encode(random_bytes(20));
}

function brewski_totp_generate_recovery_codes(int $count = 10): array
{
    if ($count < 1 || $count > 20) {
        throw new InvalidArgumentException('The recovery-code count must be between 1 and 20.');
    }

    $codes = [];
    for ($index = 0; $index < $count; $index++) {
        $rawCode = strtoupper(bin2hex(random_bytes(12)));
        $codes[] = implode('-', str_split($rawCode, 6));
    }

    return $codes;
}

function brewski_totp_normalize_recovery_code(string $code): ?string
{
    $normalized = strtoupper((string) preg_replace('/[\s-]+/', '', trim($code)));
    return preg_match('/^[A-F0-9]{24}$/', $normalized) ? $normalized : null;
}

function brewski_totp_find_recovery_code(PDO $pdo, int $userId, string $code): ?int
{
    $normalizedCode = brewski_totp_normalize_recovery_code($code);
    if ($normalizedCode === null || $userId <= 0) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT recovery_code_id, code_hash
         FROM totp_recovery_codes
         WHERE user_id = ? AND used_at IS NULL
         ORDER BY recovery_code_id ASC'
    );
    $statement->execute([$userId]);

    foreach ($statement->fetchAll() as $recoveryCode) {
        if (password_verify($normalizedCode, (string) $recoveryCode['code_hash'])) {
            return (int) $recoveryCode['recovery_code_id'];
        }
    }

    return null;
}

function brewski_totp_provisioning_uri(string $secret, string $accountName, string $issuer = 'Brewski'): string
{
    if (trim($accountName) === '' || trim($issuer) === '') {
        throw new InvalidArgumentException('An account name and issuer are required.');
    }

    brewski_totp_base32_decode($secret);

    $label = rawurlencode($issuer) . ':' . rawurlencode($accountName);
    $query = http_build_query([
        'secret' => $secret,
        'issuer' => $issuer,
        'algorithm' => 'SHA1',
        'digits' => 6,
        'period' => 30,
    ], '', '&', PHP_QUERY_RFC3986);

    return 'otpauth://totp/' . $label . '?' . $query;
}

function brewski_totp_verify_code(
    string $secret,
    string $code,
    ?int $timestamp = null,
    int $window = 1
): bool {
    if (!preg_match('/^\d{6}$/', $code) || $window < 0 || $window > 10) {
        return false;
    }

    try {
        $key = brewski_totp_base32_decode($secret);
    } catch (InvalidArgumentException $error) {
        return false;
    }

    $timestamp = $timestamp ?? time();
    if ($timestamp < 0) {
        return false;
    }

    $currentCounter = intdiv($timestamp, 30);

    for ($offset = -$window; $offset <= $window; $offset++) {
        $counter = $currentCounter + $offset;
        if ($counter < 0) {
            continue;
        }

        $high = intdiv($counter, 0x100000000);
        $low = $counter % 0x100000000;
        $digest = hash_hmac('sha1', pack('N2', $high, $low), $key, true);
        $position = ord($digest[strlen($digest) - 1]) & 0x0f;
        $binaryCode = (
            ((ord($digest[$position]) & 0x7f) << 24)
            | ((ord($digest[$position + 1]) & 0xff) << 16)
            | ((ord($digest[$position + 2]) & 0xff) << 8)
            | (ord($digest[$position + 3]) & 0xff)
        ) % 1000000;

        if (hash_equals(str_pad((string) $binaryCode, 6, '0', STR_PAD_LEFT), $code)) {
            return true;
        }
    }

    return false;
}

function brewski_totp_encrypt_secret(string $secret): string
{
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('Enable the PHP OpenSSL extension to encrypt authenticator secrets.');
    }

    brewski_totp_base32_decode($secret);

    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $secret,
        'aes-256-gcm',
        brewski_totp_encryption_key(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        'brewski:totp:v1'
    );

    if ($ciphertext === false || strlen($tag) !== 16) {
        throw new RuntimeException('Could not encrypt the authenticator secret.');
    }

    return 'v1:' . base64_encode($iv . $tag . $ciphertext);
}

function brewski_totp_decrypt_secret(string $encryptedSecret): string
{
    if (!function_exists('openssl_decrypt')) {
        throw new RuntimeException('Enable the PHP OpenSSL extension to decrypt authenticator secrets.');
    }

    if (!str_starts_with($encryptedSecret, 'v1:')) {
        throw new InvalidArgumentException('The encrypted authenticator secret has an unsupported format.');
    }

    $payload = base64_decode(substr($encryptedSecret, 3), true);
    if ($payload === false || strlen($payload) <= 28) {
        throw new InvalidArgumentException('The encrypted authenticator secret is malformed.');
    }

    $iv = substr($payload, 0, 12);
    $tag = substr($payload, 12, 16);
    $ciphertext = substr($payload, 28);
    $secret = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        brewski_totp_encryption_key(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        'brewski:totp:v1'
    );

    if ($secret === false) {
        throw new RuntimeException('Could not decrypt the authenticator secret. Check the encryption key.');
    }

    brewski_totp_base32_decode($secret);
    return $secret;
}

function brewski_totp_encryption_key(): string
{
    $configuredKey = getenv('BREWSKI_TOTP_ENCRYPTION_KEY');

    if (!is_string($configuredKey) || !preg_match('/^[a-fA-F0-9]{64}$/', $configuredKey)) {
        throw new RuntimeException(
            'BREWSKI_TOTP_ENCRYPTION_KEY must be configured as 64 hexadecimal characters.'
        );
    }

    $key = hex2bin($configuredKey);
    if ($key === false) {
        throw new RuntimeException('Could not decode BREWSKI_TOTP_ENCRYPTION_KEY.');
    }

    return $key;
}

function brewski_totp_base32_encode(string $value): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $encoded = '';
    $buffer = 0;
    $bitsInBuffer = 0;

    for ($index = 0, $length = strlen($value); $index < $length; $index++) {
        $buffer = ($buffer << 8) | ord($value[$index]);
        $bitsInBuffer += 8;

        while ($bitsInBuffer >= 5) {
            $bitsInBuffer -= 5;
            $encoded .= $alphabet[($buffer >> $bitsInBuffer) & 31];
        }

        $buffer &= (1 << $bitsInBuffer) - 1;
    }

    if ($bitsInBuffer > 0) {
        $encoded .= $alphabet[($buffer << (5 - $bitsInBuffer)) & 31];
    }

    return $encoded;
}

function brewski_totp_base32_decode(string $value): string
{
    $value = strtoupper($value);
    if (!preg_match('/^[A-Z2-7]+={0,6}$/', $value)) {
        throw new InvalidArgumentException('The authenticator secret is not valid Base32.');
    }

    $value = rtrim($value, '=');
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $decoded = '';
    $buffer = 0;
    $bitsInBuffer = 0;

    for ($index = 0, $length = strlen($value); $index < $length; $index++) {
        $characterValue = strpos($alphabet, $value[$index]);
        if ($characterValue === false) {
            throw new InvalidArgumentException('The authenticator secret is not valid Base32.');
        }

        $buffer = ($buffer << 5) | $characterValue;
        $bitsInBuffer += 5;

        if ($bitsInBuffer >= 8) {
            $bitsInBuffer -= 8;
            $decoded .= chr(($buffer >> $bitsInBuffer) & 0xff);
        }

        $buffer &= (1 << $bitsInBuffer) - 1;
    }

    if ($decoded === '' || ($bitsInBuffer > 0 && $buffer !== 0)) {
        throw new InvalidArgumentException('The authenticator secret is not valid Base32.');
    }

    return $decoded;
}
