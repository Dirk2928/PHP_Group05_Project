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
        $statement = $connection->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?'
        );
        if (!$statement) {
            error_log('Could not inspect users columns: ' . $connection->error);
            return;
        }
        $tableName = 'users';
        $statement->bind_param('ss', $tableName, $column);
        $statement->execute();
        $statement->bind_result($columnCount);
        $statement->fetch();
        $exists = (int) $columnCount > 0;
        $statement->close();
        if (!$exists) {
            $connection->query('ALTER TABLE users ADD COLUMN ' . $column . ' ' . $definition);
        }
    }
}
