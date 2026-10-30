<?php

require __DIR__ . '/../brewski/Db/personal_data.php';
putenv('BREWSKI_PERSONAL_DATA_KEY=' . bin2hex(random_bytes(32)));

$explicitKey = brewski_personal_data_key();
putenv('BREWSKI_PERSONAL_DATA_KEY');
putenv('BREWSKI_TOTP_ENCRYPTION_KEY=' . bin2hex(random_bytes(32)));
if (strlen(brewski_personal_data_key()) !== 32 || brewski_personal_data_key() === $explicitKey) {
    throw new RuntimeException('Derived personal data key failed.');
}
putenv('BREWSKI_PERSONAL_DATA_KEY=' . bin2hex(random_bytes(32)));

$GLOBALS['brewski_personal_mode'] = 'legacy';
if (brewski_personal_encrypt('Legacy Name', 'first_name') !== 'Legacy Name'
    || brewski_email_lookup_column() !== 'email') {
    throw new RuntimeException('Legacy database compatibility failed.');
}
$GLOBALS['brewski_personal_mode'] = 'encrypted';

$name = 'María Santos';
$encrypted = brewski_personal_encrypt($name, 'first_name');
if ($encrypted === $name || brewski_personal_decrypt($encrypted, 'first_name') !== $name) {
    throw new RuntimeException('Name encryption round trip failed.');
}

try {
    brewski_personal_decrypt($encrypted, 'last_name');
    throw new RuntimeException('Wrong field was accepted.');
} catch (RuntimeException $error) {
    if ($error->getMessage() === 'Wrong field was accepted.') {
        throw $error;
    }
}

$payload = base64_decode(substr($encrypted, 3), true);
$payload[30] = chr(ord($payload[30]) ^ 1);
try {
    brewski_personal_decrypt('v1:' . base64_encode($payload), 'first_name');
    throw new RuntimeException('Tampered ciphertext was accepted.');
} catch (RuntimeException $error) {
    if ($error->getMessage() === 'Tampered ciphertext was accepted.') {
        throw $error;
    }
}

$avatar = random_bytes(1024);
if (brewski_personal_decrypt(brewski_personal_encrypt($avatar, 'avatar_data'), 'avatar_data') !== $avatar) {
    throw new RuntimeException('Avatar encryption round trip failed.');
}

if (brewski_email_lookup(' User@Example.com ') !== brewski_email_lookup('user@example.com')) {
    throw new RuntimeException('Email lookup normalization failed.');
}
if (brewski_lockout_lookup('User@Example.com') === 'User@Example.com') {
    throw new RuntimeException('Lockout email was not protected.');
}

echo "personal data: ok\n";
