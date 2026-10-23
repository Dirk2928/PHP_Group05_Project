<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/preferences.php';
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/../../Db/connection.php';
require_once __DIR__ . '/../../Db/activity_log.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Method not allowed';
    exit;
}

$mode = ($_POST['mode'] ?? 'recommendation') === 'first-timer'
    ? 'first-timer'
    : 'recommendation';

if ($mode === 'recommendation') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (empty($sessionToken) || !is_string($submittedToken) || !hash_equals($sessionToken, $submittedToken)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Invalid request token';
        exit;
    }
}

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($mode === 'recommendation' && $userId <= 0) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Please log in to use drink features';
    exit;
}

$questions = $mode === 'recommendation'
    ? brewski_choice_questions()
    : brewski_recommendation_questions();

if (($_POST['action'] ?? '') === 'skip') {
    if ($mode === 'recommendation') {
        $_SESSION['choice_skipped'] = true;
    }
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'skipped';
    exit;
}

$rawAnswers = $_POST['answers'] ?? [];

if (!is_array($rawAnswers)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Invalid answer format';
    exit;
}

$normalizedAnswers = [];

foreach ($questions as $questionKey => $question) {
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

if (!brewski_answers_valid($normalizedAnswers, $questions)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Please answer all questions';
    exit;
}

$catalog = brewski_load_catalog();
$productIndex = [];
foreach ($catalog['products'] as $product) {
    $productIndex[(int) $product['product_id']] = $product;
}
$productImage = static function (array $product): string {
    return !empty($product['image_mime_type'])
        ? BREWSKI_BASE_URL . '/customer/product_image.php?id=' . (int) $product['product_id']
        : '../../images/' . ($product['image_path'] ?: 'brewskilogo.png');
};

if ($mode === 'first-timer') {
    try {
        $preferences = brewski_answers_to_preferences($normalizedAnswers, $questions);
        $ranking = brewski_rank_products($preferences, brewski_preference_links($catalog));
        $best = brewski_best_match($ranking);
    } catch (Throwable $error) {
        error_log('Brewski recommendation error: ' . $error->getMessage());
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'message' => 'Could not build a recommendation right now. Please try again.',
        ]);
        exit;
    }
    $bestMatch = null;

    if ($best && isset($productIndex[(int) $best['product_id']])) {
        $product = $productIndex[(int) $best['product_id']];
        $bestMatch = [
            'product_id' => (int) $product['product_id'],
            'name' => $product['product_name'],
            'price' => (float) $product['price'],
            'image' => $productImage($product),
            'percent' => (int) $best['percent'],
            'label' => 'Your drink recommendation',
            'matched' => array_slice($best['matched'], 0, 3),
        ];
    }

    echo json_encode([
        'ok' => true,
        'mode' => $mode,
        'best_match' => $bestMatch,
        'message' => $bestMatch ? 'Your drink recommendation is ready.' : 'No recommendation is available right now.',
    ], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$preferenceNames = brewski_answers_to_preferences($normalizedAnswers, $questions);

if (!brewski_save_user_preferences($userId, $preferenceNames)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Could not save your choices. Please try again.';
    exit;
}

brewski_record_activity(
    $pdo,
    'preferences',
    'Updated drink preferences',
    'Updated personalized drink preferences.'
);

unset($_SESSION['choice_skipped']);

echo json_encode([
    'ok' => true,
    'mode' => $mode,
    'message' => 'Your preferences were saved.',
]);
