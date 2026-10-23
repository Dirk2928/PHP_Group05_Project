<?php

/**
 * Record an action without interrupting the action being logged.
 */
function brewski_record_activity(
    PDO|mysqli $database,
    string $category,
    string $action,
    string $description,
    ?string $targetType = null,
    ?int $targetId = null
): void {
    $role = strtoupper(trim((string) ($_SESSION['role'] ?? '')));
    $actorId = (int) ($_SESSION['user_id'] ?? 0);

    if ($actorId <= 0) {
        return;
    }

    $actorName = trim(
        (string) ($_SESSION['first_name'] ?? '') . ' ' . (string) ($_SESSION['last_name'] ?? '')
    );
    if ($actorName === '') {
        $actorName = 'User #' . $actorId;
    }

    try {
        // 1. CREATE TABLE IF NOT EXISTS (This fixes the missing table on Render)
        if ($database instanceof PDO) {
            $database->exec("
                CREATE TABLE IF NOT EXISTS system_activity_logs (
                    activity_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    actor_user_id INT NOT NULL,
                    actor_name VARCHAR(201) NOT NULL,
                    actor_role VARCHAR(20) NOT NULL,
                    category VARCHAR(40) NOT NULL,
                    action VARCHAR(80) NOT NULL,
                    description VARCHAR(500) NOT NULL,
                    target_type VARCHAR(60) NULL,
                    target_id INT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_activity_actor (actor_user_id),
                    INDEX idx_activity_created (created_at)
                )
            ");
        } else {
            $database->query("
                CREATE TABLE IF NOT EXISTS system_activity_logs (
                    activity_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    actor_user_id INT NOT NULL,
                    actor_name VARCHAR(201) NOT NULL,
                    actor_role VARCHAR(20) NOT NULL,
                    category VARCHAR(40) NOT NULL,
                    action VARCHAR(80) NOT NULL,
                    description VARCHAR(500) NOT NULL,
                    target_type VARCHAR(60) NULL,
                    target_id INT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_activity_actor (actor_user_id),
                    INDEX idx_activity_created (created_at)
                )
            ");
        }

        // 2. INSERT THE LOG
        $values = [
            $actorId,
            mb_substr($actorName, 0, 201),
            $role,
            mb_substr($category, 0, 40),
            mb_substr($action, 0, 80),
            mb_substr($description, 0, 500),
            $targetType !== null ? mb_substr($targetType, 0, 60) : null,
            $targetId,
        ];

        $sql = 'INSERT INTO system_activity_logs
            (actor_user_id, actor_name, actor_role, category, action, description, target_type, target_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)';

        if ($database instanceof PDO) {
            $database->prepare($sql)->execute($values);
        } else {
            $statement = $database->prepare($sql);
            [$actorIdValue, $actorNameValue, $roleValue, $categoryValue, $actionValue,
                $descriptionValue, $targetTypeValue, $targetIdValue] = $values;
            $statement->bind_param(
                'issssssi',
                $actorIdValue,
                $actorNameValue,
                $roleValue,
                $categoryValue,
                $actionValue,
                $descriptionValue,
                $targetTypeValue,
                $targetIdValue
            );
            $statement->execute();
            $statement->close();
        }
    } catch (Throwable $error) {
        // Log the error to the server logs instead of dying silently
        error_log('Could not record system activity: ' . $error->getMessage());
    }
}