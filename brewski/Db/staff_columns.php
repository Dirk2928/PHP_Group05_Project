<?php

function brewski_ensure_staff_columns(PDO $pdo): void
{
    $columns = [
        'job_title' => 'VARCHAR(50) NULL',
    ];

    foreach ($columns as $column => $definition) {
        $statement = $pdo->prepare('SHOW COLUMNS FROM users LIKE ?');
        $statement->execute([$column]);

        if (!$statement->fetch()) {
            $pdo->exec('ALTER TABLE users ADD COLUMN ' . $column . ' ' . $definition);
        }
    }
}
