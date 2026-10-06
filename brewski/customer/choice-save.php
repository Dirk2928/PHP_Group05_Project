<?php

require_once __DIR__ . '/partials/bootstrap.php';
require_once __DIR__ . '/partials/preferences.php';

header('Content-Type: text/plain; charset=utf-8');

if (!brewski_is_logged_in() || empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo 'Please log in again to save your choices.';
    exit;
}

$userId = (int) $_SESSION['user_id'];
$answers = $_POST['answers'] ?? [];

if (!is_array($answers) || !brewski_answers_valid($answers)) {
    http_response_code(422);
    echo 'Please answer every question.';
    exit;
}

if (!brewski_save_user_preferences($userId, brewski_answers_to_preferences($answers))) {
    http_response_code(500);
    echo 'Could not save your choices. Please try again.';
    exit;
}

echo 'ok';
