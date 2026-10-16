<?php

require_once __DIR__ . '/session_init.php';

if (!empty($_SESSION['user_id'])) {
    require_once __DIR__ . '/../Db/connection.php';
    require_once __DIR__ . '/../Db/activity_log.php';

    brewski_record_activity($pdo, 'authentication', 'Signed out', 'Signed out of the system.');

    try {
        $pdo->beginTransaction();
        $deleteCartItems = $pdo->prepare(
            'DELETE FROM cart_items
             WHERE cart_id IN (SELECT cart_id FROM carts WHERE user_id = ?)'
        );
        $deleteCartItems->execute([(int) $_SESSION['user_id']]);

        $deleteCarts = $pdo->prepare('DELETE FROM carts WHERE user_id = ?');
        $deleteCarts->execute([(int) $_SESSION['user_id']]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Could not clear cart during logout: ' . $error->getMessage());
    }
}

brewski_end_session();
