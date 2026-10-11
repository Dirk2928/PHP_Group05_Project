<?php

function customer_has_deliveries_table(PDO $pdo): bool
{
    static $exists = null;

    if ($exists === null) {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
               FROM information_schema.tables
              WHERE table_schema = DATABASE()
                AND table_name = ?'
        );
        $statement->execute(['deliveries']);
        $exists = (int) $statement->fetchColumn() > 0;
    }

    return $exists;
}
