<?php

function brewski_ensure_staff_columns(PDO $pdo): void
{
    $columns = [
        'job_title' => 'VARCHAR(50) NULL',
    ];

    foreach ($columns as $column => $definition) {
        $statement = $pdo->query(
            'SHOW COLUMNS FROM users LIKE ' . $pdo->quote($column)
        );

        if (!$statement->fetch()) {
            $pdo->exec('ALTER TABLE users ADD COLUMN ' . $column . ' ' . $definition);
        }

    }
}

function brewski_ensure_preference_columns(mysqli $connection): void
{
    $columns = [
        'is_first_timer' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'assessment_completed_at' => 'DATETIME NULL',
    ];

    foreach ($columns as $column => $definition) {
        $statement = $connection->prepare('SHOW COLUMNS FROM users LIKE ?');
        $statement->bind_param('s', $column);
        $statement->execute();
        $statement->store_result();
        $exists = $statement->num_rows > 0;
        $statement->close();
        if (!$exists) {
            $connection->query('ALTER TABLE users ADD COLUMN ' . $column . ' ' . $definition);
        }
    }
}
