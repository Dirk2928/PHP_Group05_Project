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
    ?int $targetId = null,
    ?int $overrideActorId = null,
    ?string $overrideRole = null,
    ?string $overrideName = null
): void {
    $role = $overrideRole ?? strtoupper(trim((string) ($_SESSION['role'] ?? '')));
    $actorId = $overrideActorId ?? (int) ($_SESSION['user_id'] ?? 0);

    // Allow ADMIN, STAFF, and CUSTOMER
    if ($actorId <= 0 || !in_array($role, ['ADMIN', 'STAFF', 'CUSTOMER'], true)) {
        return;
    }

    $actorName = $overrideName ?? trim(
        (string) ($_SESSION['first_name'] ?? '') . ' ' . (string) ($_SESSION['last_name'] ?? '')
    );
    if ($actorName === '') {
        $actorName = 'User #' . $actorId;
    }

    try {
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
        error_log('Could not record system activity: ' . $error->getMessage());
    }
}