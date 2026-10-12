<?php

require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/../Db/connection.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$error = '';
$attempts = (int) ($_SESSION['totp_reset_attempts'] ?? 0);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_token'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        $error = 'This request could not be verified. Refresh the page and try again.';
    } elseif ($attempts >= 5) {
        $error = 'Too many reset attempts. Sign out and sign in again before retrying.';
    } else {
        $password = $_POST['password'] ?? '';
        $recoveryCode = $_POST['recovery_code'] ?? '';

        if (!is_string($password) || !is_string($recoveryCode) || $password === '') {
            $error = 'Enter your current password and an unused recovery code.';
        } else {
            try {
                $userStatement = $pdo->prepare(
                    'SELECT password, totp_secret_encrypted, totp_enabled_at
                     FROM users
                     WHERE user_id = ?
                     LIMIT 1'
                );
                $userStatement->execute([$userId]);
                $user = $userStatement->fetch();

                if (
                    !$user
                    || empty($user['totp_secret_encrypted'])
                    || empty($user['totp_enabled_at'])
                    || !password_verify($password, (string) $user['password'])
                ) {
                    throw new RuntimeException('The password or recovery code is invalid.');
                }

                $pdo->beginTransaction();
                $recoveryCodeId = brewski_totp_find_recovery_code($pdo, $userId, $recoveryCode);
                if ($recoveryCodeId === null) {
                    throw new RuntimeException('The password or recovery code is invalid.');
                }

                $consumeStatement = $pdo->prepare(
                    'UPDATE totp_recovery_codes
                     SET used_at = NOW()
                     WHERE recovery_code_id = ?
                       AND user_id = ?
                       AND used_at IS NULL'
                );
                $consumeStatement->execute([$recoveryCodeId, $userId]);
                if ($consumeStatement->rowCount() !== 1) {
                    throw new RuntimeException('The password or recovery code is invalid.');
                }

                $resetStatement = $pdo->prepare(
                    'UPDATE users
                     SET totp_secret_encrypted = NULL, totp_enabled_at = NULL
                     WHERE user_id = ?
                       AND totp_secret_encrypted IS NOT NULL
                       AND totp_enabled_at IS NOT NULL'
                );
                $resetStatement->execute([$userId]);
                if ($resetStatement->rowCount() !== 1) {
                    throw new RuntimeException('The authenticator could not be reset.');
                }

                $deleteStatement = $pdo->prepare(
                    'DELETE FROM totp_recovery_codes WHERE user_id = ?'
                );
                $deleteStatement->execute([$userId]);
                $pdo->commit();

                unset($_SESSION['totp_reset_attempts'], $_SESSION['totp_setup_secret']);
                session_regenerate_id(true);
                header('Location: setup-authenticator.php');
                exit;
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Authenticator reset database failure: ' . $exception->getMessage());
                $error = 'Authenticator reset is temporarily unavailable. Please try again later.';
            } catch (RuntimeException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $_SESSION['totp_reset_attempts'] = $attempts + 1;
                $attempts++;
                $error = $attempts >= 5
                    ? 'Too many reset attempts. Sign out and sign in again before retrying.'
                    : $exception->getMessage();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Authenticator reset failed: ' . $exception->getMessage());
                $error = 'Authenticator reset is temporarily unavailable. Please try again later.';
            }
        }
    }
}

$escape = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset authenticator | Brewski</title>
    <style>
        :root { color-scheme: light; font-family: Arial, sans-serif; color: #1a0f0a; background: #faf5ee; }
        body { margin: 0; padding: 2rem 1rem; }
        main { width: min(100%, 30rem); margin: 3rem auto; padding: 2rem; box-sizing: border-box; background: #fff; border: 1px solid #e8dac4; border-radius: 1rem; }
        h1 { margin-top: 0; }
        p { line-height: 1.5; }
        .error { padding: 0.9rem 1rem; border: 1px solid #fecaca; border-radius: 0.65rem; color: #7f1d1d; background: #fef2f2; }
        label { display: block; margin: 1.2rem 0 0.4rem; font-weight: 700; }
        input { width: 100%; box-sizing: border-box; padding: 0.8rem; border: 1px solid #b8a58e; border-radius: 0.6rem; font: inherit; }
        button, a { display: inline-block; margin-top: 1rem; padding: 0.75rem 1rem; border: 1px solid #1a0f0a; border-radius: 0.6rem; background: #1a0f0a; color: #fff; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        a { margin-left: 0.5rem; background: #fff; color: #1a0f0a; }
    </style>
</head>
<body>
<main>
    <h1>Reset authenticator</h1>
    <p>To replace a lost authenticator, confirm your current password and one unused recovery code. This will invalidate every existing recovery code and require authenticator setup again.</p>

    <?php if ($error !== ''): ?>
        <p class="error" role="alert"><?= $escape($error) ?></p>
    <?php endif; ?>

    <form method="post" action="reset-authenticator.php">
        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
        <label for="current-password">Current password</label>
        <input type="password" id="current-password" name="password" autocomplete="current-password" required>
        <label for="recovery-code">Unused recovery code</label>
        <input
            type="text"
            id="recovery-code"
            name="recovery_code"
            autocomplete="off"
            maxlength="30"
            required
        >
        <button type="submit">Verify and reset</button>
        <a href="setup-authenticator.php">Cancel</a>
    </form>
</main>
</body>
</html>
