<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/preferences.php';

header('Content-Type: text/plain; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed';
    exit;
}

$submittedToken = $_POST['csrf_token'] ?? '';
$sessionToken = $_SESSION['csrf_token'] ?? '';

if (empty($sessionToken) || !is_string($submittedToken) || !hash_equals($sessionToken, $submittedToken)) {
    http_response_code(403);
    echo 'Invalid request token';
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    http_response_code(401);
    echo 'Please log in to save your preferences';
    exit;
}

$rawAnswers = $_POST['answers'] ?? [];

if (!is_array($rawAnswers)) {
    http_response_code(400);
    echo 'Invalid answer format';
    exit;
}

$normalizedAnswers = [];

foreach (brewski_choice_questions() as $questionKey => $question) {
    $isMulti = !empty($question['multi']);

    if ($isMulti) {
        $value = $rawAnswers[$questionKey] ?? [];

        if (is_string($value)) {
            $value = [$value];
        }

        if (!is_array($value)) {
            continue;
        }

        $cleaned = [];
        foreach ($value as $item) {
            $item = trim((string) $item);
            if ($item !== '' && isset($question['answers'][$item])) {
                $cleaned[] = $item;
            }
        }

        if (!empty($cleaned)) {
            $normalizedAnswers[$questionKey] = array_values(array_unique($cleaned));
        }
    } else {
        $value = $rawAnswers[$questionKey] ?? null;

        if (is_array($value)) {
            $value = reset($value);
        }

        $value = trim((string) $value);

        if ($value !== '' && isset($question['answers'][$value])) {
            $normalizedAnswers[$questionKey] = $value;
        }
    }
}

if (!brewski_answers_valid($normalizedAnswers)) {
    http_response_code(400);
    echo 'Please answer all questions';
    exit;
}

$preferenceNames = brewski_answers_to_preferences($normalizedAnswers);

if (!brewski_save_user_preferences($userId, $preferenceNames)) {
    http_response_code(500);
    echo 'Could not save your choices. Please try again.';
    exit;
}

echo 'ok';