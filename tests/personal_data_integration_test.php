<?php

// Run only against a disposable database; see the integration test commands in the task notes.
if (getenv('BREWSKI_INTEGRATION_TEST') !== '1'
    || !in_array(getenv('DB_NAME'), ['brewski_legacy_test', 'brewski_legacy_auth_test'], true)
    || getenv('DB_PORT') !== '3308') {
    fwrite(STDERR, "Disposable database settings required.\n");
    exit(2);
}

require __DIR__ . '/../brewski/Db/db_config.php';

$database = brewski_db_pdo();
if (!$database) {
    throw new RuntimeException('Test database unavailable.');
}
$email = 'encryption.integration@example.test';
$phase = $argv[1] ?? '';

if ($phase === 'before') {
    if (brewski_personal_data_active() || brewski_email_lookup_column() !== 'email') {
        throw new RuntimeException('Legacy schema was not detected.');
    }
    $existing = $database->prepare('SELECT user_id FROM users WHERE email = ?');
    $existing->execute([$email]);
    $userId = (int) $existing->fetchColumn();
    if (!$userId) {
        $insert = $database->prepare(
            "INSERT INTO users (first_name, last_name, email, password, role, is_active)
             VALUES (?, ?, ?, ?, 'CUSTOMER', 1)"
        );
        $insert->execute(['Test', 'Account', $email, password_hash('TestPassword!123', PASSWORD_DEFAULT)]);
        $userId = (int) $database->lastInsertId();
    }
    brewski_update_user_identity($database, $userId, 'Legacy', 'Account', $email);
    $row = $database->query('SELECT first_name, email FROM users WHERE user_id = ' . $userId)->fetch();
    if ($row['first_name'] !== 'Legacy' || $row['email'] !== $email) {
        throw new RuntimeException('Legacy update failed.');
    }
    require __DIR__ . '/../brewski/login-signup/auth_core.php';
    $connection = brewski_db_connect();
    $signup = brewski_prepare_customer_signup_insert($connection);
    if (!$signup) {
        throw new RuntimeException('Legacy signup statement failed to prepare.');
    }
    $signup->close();
    $valid = brewski_authenticate($connection, $email, 'TestPassword!123', 'customer');
    if ($valid['status'] !== BREWSKI_AUTH_ENROLL) {
        throw new RuntimeException('Legacy account login failed.');
    }
    $connection->close();
    echo "legacy mode: ok\n";
} elseif ($phase === 'after') {
    if (!brewski_personal_data_active() || brewski_email_lookup_column() !== 'email_lookup') {
        throw new RuntimeException('Encrypted schema was not detected.');
    }
    $select = $database->prepare('SELECT user_id, first_name, last_name, email, email_lookup FROM users WHERE email_lookup = ?');
    $select->execute([brewski_email_lookup($email)]);
    $row = $select->fetch();
    if (!$row || !str_starts_with($row['email'], 'v1:')) {
        throw new RuntimeException('Email migration failed.');
    }
    $plain = brewski_decrypt_user_fields($row);
    if (!in_array($plain['first_name'], ['Legacy', 'Encrypted'], true) || $plain['email'] !== $email) {
        throw new RuntimeException('Encrypted read failed.');
    }
    brewski_update_user_identity($database, (int) $row['user_id'], 'Encrypted', 'Account', $email);
    $select->execute([brewski_email_lookup($email)]);
    $updated = brewski_decrypt_user_fields($select->fetch());
    if ($updated['first_name'] !== 'Encrypted') {
        throw new RuntimeException('Encrypted update failed.');
    }
    require __DIR__ . '/../brewski/login-signup/auth_core.php';
    $connection = brewski_db_connect();
    $signup = brewski_prepare_customer_signup_insert($connection);
    if (!$signup) {
        throw new RuntimeException('Encrypted signup statement failed to prepare.');
    }
    $signup->close();
    $invalid = brewski_authenticate($connection, 'absent.integration@example.test', 'wrong', 'customer');
    if ($invalid['status'] !== BREWSKI_AUTH_INVALID) {
        throw new RuntimeException('Encrypted email login lookup failed.');
    }
    $valid = brewski_authenticate($connection, $email, 'TestPassword!123', 'customer');
    if ($valid['status'] !== BREWSKI_AUTH_ENROLL) {
        throw new RuntimeException('Encrypted account login failed.');
    }
    $connection->close();
    echo "encrypted mode: ok\n";
} else {
    fwrite(STDERR, "Expected before or after.\n");
    exit(2);
}
