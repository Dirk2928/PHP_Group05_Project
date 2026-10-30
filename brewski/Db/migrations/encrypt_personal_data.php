<?php

// Run during a maintenance window, after making a database backup.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db_config.php';

try {
    brewski_personal_data_key();
    $pdo = brewski_db_pdo();
    if (!$pdo) {
        throw new RuntimeException('Database connection unavailable.');
    }
    $GLOBALS['brewski_personal_mode'] = 'encrypted';

    $column = $pdo->query("SHOW COLUMNS FROM users LIKE 'email_lookup'")->fetch();
    if (!$column) {
        $pdo->exec('ALTER TABLE users ADD COLUMN email_lookup BINARY(32) NULL');
    }
    if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'avatar_data'")->fetch()) {
        $pdo->exec('ALTER TABLE users ADD COLUMN avatar_data LONGBLOB NULL');
    }
    if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'avatar_mime_type'")->fetch()) {
        $pdo->exec('ALTER TABLE users ADD COLUMN avatar_mime_type VARCHAR(32) NULL');
    }
    $pdo->exec('ALTER TABLE users MODIFY first_name VARCHAR(512) NOT NULL, MODIFY last_name VARCHAR(512) NOT NULL, MODIFY email VARCHAR(512) NOT NULL');
    $pdo->exec('UPDATE users SET activation_token = NULL, activation_expires = NULL WHERE activation_token IS NOT NULL');

    $lastId = 0;
    $select = $pdo->prepare('SELECT user_id, first_name, last_name, email, avatar_data, password FROM users WHERE user_id > ? ORDER BY user_id LIMIT 10');
    $update = $pdo->prepare('UPDATE users SET first_name = ?, last_name = ?, email = ?, email_lookup = ?, avatar_data = ?, password = ? WHERE user_id = ?');
    do {
        $select->execute([$lastId]);
        $rows = $select->fetchAll();
        foreach ($rows as $row) {
            $lastId = (int) $row['user_id'];
            $plain = brewski_decrypt_user_fields($row, true);
            $email = (string) $plain['email'];
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid email for user #' . $lastId . '.');
            }
            $password = (string) $row['password'];
            if (password_get_info($password)['algoName'] === 'unknown') {
                $password = password_hash($password, PASSWORD_DEFAULT);
            }
            $pdo->beginTransaction();
            $update->execute([
                brewski_personal_encrypt((string) $plain['first_name'], 'first_name'),
                brewski_personal_encrypt((string) $plain['last_name'], 'last_name'),
                brewski_personal_encrypt($email, 'email'),
                brewski_email_lookup($email),
                $plain['avatar_data'] === null ? null : brewski_personal_encrypt((string) $plain['avatar_data'], 'avatar_data'),
                $password,
                $lastId,
            ]);
            $pdo->commit();
        }
    } while (count($rows) === 10);

    $pdo->exec('ALTER TABLE users MODIFY email_lookup BINARY(32) NOT NULL');
    $index = $pdo->query("SHOW INDEX FROM users WHERE Column_name = 'email_lookup' AND Non_unique = 0")->fetch();
    if (!$index) {
        $pdo->exec('ALTER TABLE users ADD UNIQUE INDEX ux_users_email_lookup (email_lookup)');
    }
    $indexes = $pdo->query("SHOW INDEX FROM users WHERE Column_name = 'email' AND Non_unique = 0")->fetchAll();
    foreach ($indexes as $emailIndex) {
        $name = (string) $emailIndex['Key_name'];
        if ($name !== 'PRIMARY') {
            $pdo->exec('ALTER TABLE users DROP INDEX `' . str_replace('`', '``', $name) . '`');
        }
    }

    $tableExists = static function (PDO $database, string $table): bool {
        $query = $database->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $query->execute([$table]);
        return (int) $query->fetchColumn() > 0;
    };

    if ($tableExists($pdo, 'order_payments')) {
        $pdo->exec('ALTER TABLE order_payments MODIFY checkout_url TEXT NOT NULL');
        $selectPayments = $pdo->prepare('SELECT payment_id, checkout_url FROM order_payments WHERE payment_id > ? ORDER BY payment_id LIMIT 100');
        $updatePayment = $pdo->prepare('UPDATE order_payments SET checkout_url = ? WHERE payment_id = ?');
        $lastPaymentId = 0;
        do {
            $selectPayments->execute([$lastPaymentId]);
            $payments = $selectPayments->fetchAll();
            foreach ($payments as $payment) {
                $lastPaymentId = (int) $payment['payment_id'];
                $url = (string) $payment['checkout_url'];
                if ($url !== '' && !str_starts_with($url, 'v1:')) {
                    $updatePayment->execute([brewski_personal_encrypt($url, 'checkout_url'), $lastPaymentId]);
                }
            }
        } while (count($payments) === 100);
    }

    if ($tableExists($pdo, 'account_lockouts')) {
        $lockouts = $pdo->query('SELECT account_lockout_id, username FROM account_lockouts')->fetchAll();
        $updateLockout = $pdo->prepare('UPDATE account_lockouts SET username = ? WHERE account_lockout_id = ?');
        foreach ($lockouts as $lockout) {
            $username = (string) $lockout['username'];
            if (!preg_match('/\A[0-9a-f]{64}\z/', $username)) {
                $updateLockout->execute([brewski_lockout_lookup($username), (int) $lockout['account_lockout_id']]);
            }
        }
    }

    if ($tableExists($pdo, 'system_activity_logs')) {
        $pdo->exec("UPDATE system_activity_logs SET actor_name = CONCAT('User #', COALESCE(actor_user_id, 0)), description = action");
    }

    echo "Personal data migration completed.\n";
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Migration stopped: ' . $error->getMessage() . "\n");
    exit(1);
}
