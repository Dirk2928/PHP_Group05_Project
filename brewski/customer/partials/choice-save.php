<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/preferences.php';
require_once __DIR__ . '/catalog.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Method not allowed';
    exit;
}

$submittedToken = $_POST['csrf_token'] ?? '';
$sessionToken = $_SESSION['csrf_token'] ?? '';

if (empty($sessionToken) || !is_string($submittedToken) || !hash_equals($sessionToken, $submittedToken)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Invalid request token';
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Please log in to save your preferences';
    exit;
}

if (($_POST['action'] ?? '') === 'skip') {
    $_SESSION['choice_skipped'] = true;
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
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Please answer all questions';
    exit;
}

$preferenceNames = brewski_answers_to_preferences($normalizedAnswers);

if (!brewski_save_user_preferences($userId, $preferenceNames)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Could not save your choices. Please try again.';
    exit;
}

$catalog = brewski_load_catalog();
$preferences = brewski_user_preferences($userId) ?? [];
$ranking = brewski_rank_products($preferences, brewski_preference_links($catalog));
$best = brewski_best_match($ranking);
$bestMatch = null;

if ($best) {
    foreach ($catalog['products'] as $product) {
        if ((int) $product['product_id'] !== (int) $best['product_id']) {
            continue;
        }
        $image = !empty($product['image_mime_type'])
            ? BREWSKI_BASE_URL . '/customer/product_image.php?id=' . (int) $product['product_id']
            : '../../images/' . ($product['image_path'] ?: 'brewskilogo.png');
        $bestMatch = [
            'product_id' => (int) $product['product_id'],
            'name' => $product['product_name'],
            'price' => (float) $product['price'],
            'image' => $image,
            'percent' => (int) $best['percent'],
            'label' => $best['percent'] >= 70 ? 'Your best match' : 'Closest match we found',
            'matched' => array_slice($best['matched'], 0, 3),
        ];
        break;
    }
}

$recommendationConnection = brewski_db_connect();
if ($recommendationConnection) {
    $recommendationConnection->begin_transaction();
    try {
        $deleteRecommendations = $recommendationConnection->prepare(
            'DELETE FROM recommendations WHERE user_id = ?'
        );
        $deleteRecommendations->bind_param('i', $userId);
        $deleteRecommendations->execute();
        $deleteRecommendations->close();
        if ($bestMatch) {
            $reason = implode(', ', $bestMatch['matched']);
            $insertRecommendation = $recommendationConnection->prepare(
                'INSERT INTO recommendations
                 (user_id, product_id, recommendation_score, recommendation_reason)
                 VALUES (?, ?, ?, ?)'
            );
            $productId = $bestMatch['product_id'];
            $score = $bestMatch['percent'];
            $insertRecommendation->bind_param('iids', $userId, $productId, $score, $reason);
            $insertRecommendation->execute();
            $insertRecommendation->close();
        }
        $recommendationConnection->commit();
    } catch (mysqli_sql_exception $error) {
        $recommendationConnection->rollback();
        error_log('Recommendation persistence error: ' . $error->getMessage());
    }
    $recommendationConnection->close();
}

echo json_encode([
    'ok' => true,
    'best_match' => $bestMatch,
    'message' => $bestMatch ? 'Your recommendation is ready.' : 'No safe match was found.',
]);