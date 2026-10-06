<?php

require_once __DIR__ . '/../../login-signup/settings.php';

if (!defined('BREWSKI_CHOICE_FINGERPRINT_KEY')) {
    define('BREWSKI_CHOICE_FINGERPRINT_KEY', 'choice_catalog_fingerprint');
}

function brewski_choice_questions(): array
{
    return [
        'caffeine' => [
            'question' => 'Do you want caffeine?',
            'answers' => [
                'Yes' => ['caffeine'],
                'A little' => ['caffeine', 'decaf'],
                'No' => ['decaf'],
            ],
        ],
        'temperature' => [
            'question' => 'Hot or iced?',
            'answers' => [
                'Hot' => ['temp:hot'],
                'Iced' => ['temp:iced'],
                'Either' => ['temp:hot', 'temp:iced'],
            ],
        ],
        'sweetness' => [
            'question' => 'How sweet do you like it?',
            'answers' => [
                'Sweet' => ['sugar:sweet'],
                'A little' => ['sugar:sweet', 'sugar:light'],
                'Not sweet' => ['sugar:none'],
            ],
        ],
        'base' => [
            'question' => 'Coffee or something else?',
            'answers' => [
                'Coffee' => ['coffee'],
                'Something else' => ['non-coffee'],
                'Either' => ['coffee', 'non-coffee'],
            ],
        ],
        'budget' => [
            'question' => 'How much do you usually spend?',
            'answers' => [
                'Budget-friendly' => ['price:budget'],
                'Mid-range' => ['price:mid'],
                'Treat yourself' => ['price:treat'],
            ],
        ],
    ];
}

function brewski_preference_definitions(): array
{
    return [
        'caffeine' => 'Has caffeine',
        'decaf' => 'No caffeine',
        'coffee' => 'Coffee based',
        'non-coffee' => 'Not coffee based',
        'temp:hot' => 'Served hot',
        'temp:iced' => 'Served iced',
        'sugar:sweet' => 'Sweet',
        'sugar:light' => 'Lightly sweet',
        'sugar:none' => 'Not sweet',
        'price:budget' => 'Budget friendly',
        'price:mid' => 'Mid-range price',
        'price:treat' => 'Premium price',
    ];
}

function brewski_decaf_keywords(): array
{
    return ['decaf', 'caffeine-free', 'caffeine free', 'herbal', 'fruit', 'juice', 'smoothie'];
}

function brewski_non_coffee_keywords(): array
{
    return ['matcha', 'tea', 'choco', 'fruit', 'juice', 'smoothie', 'milk', 'non-coffee'];
}

function brewski_preferences_connection()
{
    static $connection = null;

    if ($connection instanceof mysqli) {
        return $connection;
    }

    $previous_report_mode = mysqli_report(MYSQLI_REPORT_OFF);
    $candidate = new mysqli('localhost', 'root', '', 'brewski_db');
    mysqli_report($previous_report_mode);

    if ($candidate->connect_errno) {
        return null;
    }

    $candidate->set_charset('utf8mb4');
    $connection = $candidate;

    return $connection;
}

function brewski_preferences_attempt(callable $work)
{
    $previous_report_mode = mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        return $work();
    } catch (mysqli_sql_exception $error) {
        error_log('Brewski choice preferences error: ' . $error->getMessage());

        return null;
    } finally {
        mysqli_report($previous_report_mode);
    }
}

function brewski_preferences_ensure_tables(mysqli $connection): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $connection->query(
        "CREATE TABLE IF NOT EXISTS preferences (
            preference_id INT AUTO_INCREMENT PRIMARY KEY,
            preference_name VARCHAR(100) NOT NULL UNIQUE,
            description TEXT
        )"
    );
    $connection->query(
        "CREATE TABLE IF NOT EXISTS product_preferences (
            product_id INT NOT NULL,
            preference_id INT NOT NULL,
            preference_value BOOLEAN NOT NULL DEFAULT TRUE,
            PRIMARY KEY (product_id, preference_id),
            CONSTRAINT fk_product_preferences_product
                FOREIGN KEY (product_id)
                REFERENCES products(product_id)
                ON UPDATE CASCADE
                ON DELETE CASCADE,
            CONSTRAINT fk_product_preferences_preference
                FOREIGN KEY (preference_id)
                REFERENCES preferences(preference_id)
                ON UPDATE CASCADE
                ON DELETE CASCADE
        )"
    );
    $connection->query(
        "CREATE TABLE IF NOT EXISTS user_preferences (
            user_preference_id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            preference_id INT NOT NULL,
            preference_value BOOLEAN NOT NULL DEFAULT TRUE,
            CONSTRAINT fk_user_preferences_user
                FOREIGN KEY (user_id)
                REFERENCES users(user_id)
                ON UPDATE CASCADE
                ON DELETE CASCADE,
            CONSTRAINT fk_user_preferences_preference
                FOREIGN KEY (preference_id)
                REFERENCES preferences(preference_id)
                ON UPDATE CASCADE
                ON DELETE CASCADE,
            CONSTRAINT unique_user_preference
                UNIQUE (user_id, preference_id)
        )"
    );

    $done = true;
}

function brewski_preference_definitions_write(mysqli $connection): void
{
    brewski_preferences_ensure_tables($connection);

    $upsert = $connection->prepare(
        'INSERT INTO preferences (preference_name, description)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE description = VALUES(description)'
    );

    foreach (brewski_preference_definitions() as $key => $label) {
        $upsert->bind_param('ss', $key, $label);
        $upsert->execute();
    }

    $upsert->close();
}

function brewski_catalog_fingerprint(array $catalog): string
{
    $parts = [];

    foreach ($catalog['products'] as $product) {
        $parts[] = $product['product_id'] . ':' . $product['product_name']
            . ':' . $product['category_name'] . ':' . $product['price'];

        foreach ($product['customizations'] as $customization) {
            $parts[] = $product['product_id'] . ':' . $customization['group']
                . ':' . $customization['name'];
        }
    }

    sort($parts);

    return md5(implode('|', $parts));
}

function brewski_matches_any(array $values, array $needles): bool
{
    foreach ($values as $value) {
        foreach ($needles as $needle) {
            if (mb_strpos($value, $needle) !== false) {
                return true;
            }
        }
    }

    return false;
}

function brewski_sugar_percent(string $name): ?int
{
    if (preg_match('/(\d{1,3})\s*%/', $name, $matches) === 1) {
        return min(100, (int) $matches[1]);
    }

    $levels = [
        'none' => 0,
        'no sugar' => 0,
        'unsweet' => 0,
        'zero' => 0,
        'half' => 50,
        'less' => 50,
        'light' => 50,
        'extra' => 100,
        'sweet' => 100,
        'regular' => 100,
        'normal' => 100,
    ];

    foreach ($levels as $keyword => $percent) {
        if (mb_strpos($name, $keyword) !== false) {
            return $percent;
        }
    }

    return null;
}

function brewski_price_thresholds(array $catalog): array
{
    $prices = [];

    foreach ($catalog['products'] as $product) {
        $prices[] = (float) $product['price'];
    }

    if (!$prices) {
        return [0.0, 0.0];
    }

    $lowest = min($prices);
    $step = (max($prices) - $lowest) / 3;

    return [$lowest + $step, $lowest + ($step * 2)];
}

function brewski_price_preference(float $price, array $thresholds): string
{
    if ($price <= $thresholds[0]) {
        return 'price:budget';
    }

    if ($price <= $thresholds[1]) {
        return 'price:mid';
    }

    return 'price:treat';
}

function brewski_preference_links(array $catalog): array
{
    $decafKeywords = brewski_decaf_keywords();
    $nonCoffeeKeywords = brewski_non_coffee_keywords();
    $thresholds = brewski_price_thresholds($catalog);
    $links = [];

    foreach ($catalog['products'] as $product) {
        $productId = (int) $product['product_id'];
        $haystack = mb_strtolower(
            $product['category_name'] . ' ' . $product['product_name']
        );
        $keys = [];

        $keys[] = brewski_matches_any([$haystack], $decafKeywords) ? 'decaf' : 'caffeine';
        $keys[] = brewski_matches_any([$haystack], $nonCoffeeKeywords)
            ? 'non-coffee'
            : 'coffee';
        $keys[] = brewski_price_preference((float) $product['price'], $thresholds);

        $temperatures = [];
        $sugarLevels = [];

        foreach ($product['customizations'] as $customization) {
            $name = mb_strtolower($customization['name']);

            if ($customization['group'] === 'temp') {
                $temperatures[] = $name;
            }

            if ($customization['group'] === 'sugar') {
                $percent = brewski_sugar_percent($name);

                if ($percent !== null) {
                    $sugarLevels[] = $percent;
                }
            }
        }

        if (brewski_matches_any($temperatures, ['iced', 'cold'])) {
            $keys[] = 'temp:iced';
        }

        if (brewski_matches_any($temperatures, ['hot', 'warm'])) {
            $keys[] = 'temp:hot';
        }

        if ($sugarLevels) {
            $highest = max($sugarLevels);

            if ($highest >= 75) {
                $keys[] = 'sugar:sweet';
            } elseif ($highest >= 25) {
                $keys[] = 'sugar:light';
            } else {
                $keys[] = 'sugar:none';
            }
        }

        $links[$productId] = $keys;
    }

    return $links;
}

function brewski_preference_links_write(mysqli $connection, array $catalog): void
{
    $ids = [];
    $result = $connection->query('SELECT preference_id, preference_name FROM preferences');

    while ($row = $result->fetch_assoc()) {
        $ids[$row['preference_name']] = (int) $row['preference_id'];
    }

    $connection->query('DELETE FROM product_preferences');

    $insert = $connection->prepare(
        'INSERT IGNORE INTO product_preferences (product_id, preference_id, preference_value)
         VALUES (?, ?, 1)'
    );

    foreach (brewski_preference_links($catalog) as $productId => $keys) {
        foreach ($keys as $key) {
            if (!isset($ids[$key])) {
                continue;
            }

            $insert->bind_param('ii', $productId, $ids[$key]);
            $insert->execute();
        }
    }

    $insert->close();
}

function brewski_stored_fingerprint(): string
{
    $stored = brewski_preferences_attempt(function () {
        return brewski_setting(BREWSKI_CHOICE_FINGERPRINT_KEY);
    });

    if (is_string($stored) && $stored !== '') {
        return $stored;
    }

    return (string) ($_SESSION['brewski_choice_fingerprint'] ?? '');
}

function brewski_store_fingerprint(string $fingerprint): void
{
    $_SESSION['brewski_choice_fingerprint'] = $fingerprint;

    brewski_preferences_attempt(function () use ($fingerprint) {
        brewski_setting_set(BREWSKI_CHOICE_FINGERPRINT_KEY, $fingerprint);
    });
}

function brewski_preference_sync(array $catalog): void
{
    if (empty($catalog['products'])) {
        return;
    }

    $fingerprint = brewski_catalog_fingerprint($catalog);

    if (brewski_stored_fingerprint() === $fingerprint) {
        return;
    }

    $written = brewski_preferences_attempt(function () use ($catalog) {
        $connection = brewski_preferences_connection();

        if (!$connection) {
            return false;
        }

        brewski_preference_definitions_write($connection);
        $connection->begin_transaction();

        try {
            brewski_preference_links_write($connection, $catalog);
            $connection->commit();
        } catch (mysqli_sql_exception $error) {
            $connection->rollback();

            throw $error;
        }

        return true;
    });

    if ($written === true) {
        brewski_store_fingerprint($fingerprint);
    }
}

function brewski_user_preferences(int $userId): ?array
{
    if ($userId <= 0) {
        return [];
    }

    $names = brewski_preferences_attempt(function () use ($userId) {
        $connection = brewski_preferences_connection();

        if (!$connection) {
            return null;
        }

        brewski_preferences_ensure_tables($connection);

        $statement = $connection->prepare(
            'SELECT preferences.preference_name
             FROM user_preferences
             INNER JOIN preferences
                ON preferences.preference_id = user_preferences.preference_id
             WHERE user_preferences.user_id = ?
               AND user_preferences.preference_value = 1
             ORDER BY preferences.preference_name'
        );

        $statement->bind_param('i', $userId);
        $statement->execute();

        $result = $statement->get_result();
        $names = [];

        while ($row = $result->fetch_assoc()) {
            $names[] = $row['preference_name'];
        }

        $statement->close();

        return $names;
    });

    return is_array($names) ? $names : null;
}

function brewski_save_user_preferences(int $userId, array $names): bool
{
    if ($userId <= 0) {
        return false;
    }

    $saved = brewski_preferences_attempt(function () use ($userId, $names) {
        $connection = brewski_preferences_connection();

        if (!$connection) {
            return false;
        }

        brewski_preference_definitions_write($connection);

        $connection->begin_transaction();

        $delete = $connection->prepare('DELETE FROM user_preferences WHERE user_id = ?');
        $delete->bind_param('i', $userId);
        $delete->execute();
        $delete->close();

        if ($names) {
            $insert = $connection->prepare(
                'INSERT IGNORE INTO user_preferences (user_id, preference_id, preference_value)
                 SELECT ?, preference_id, 1 FROM preferences WHERE preference_name = ?'
            );

            foreach ($names as $name) {
                $insert->bind_param('is', $userId, $name);
                $insert->execute();
            }

            $insert->close();
        }

        $connection->commit();

        return true;
    });

    return $saved === true;
}

function brewski_user_choice_scores(int $userId, array $products): array
{
    if ($userId <= 0 || !$products) {
        return [];
    }

    $scores = brewski_preferences_attempt(function () use ($userId) {
        $connection = brewski_preferences_connection();

        if (!$connection) {
            return null;
        }

        brewski_preferences_ensure_tables($connection);

        $statement = $connection->prepare(
            'SELECT product_preferences.product_id, COUNT(*) AS score
             FROM user_preferences
             INNER JOIN product_preferences
                ON product_preferences.preference_id = user_preferences.preference_id
               AND product_preferences.preference_value = 1
             WHERE user_preferences.user_id = ?
               AND user_preferences.preference_value = 1
             GROUP BY product_preferences.product_id'
        );

        $statement->bind_param('i', $userId);
        $statement->execute();

        $result = $statement->get_result();
        $scores = [];

        while ($row = $result->fetch_assoc()) {
            $scores[(int) $row['product_id']] = (int) $row['score'];
        }

        $statement->close();

        return $scores;
    });

    return is_array($scores) ? $scores : [];
}

function brewski_answers_valid(array $answers): bool
{
    foreach (brewski_choice_questions() as $questionKey => $question) {
        if (!isset($answers[$questionKey]) || !is_string($answers[$questionKey])) {
            return false;
        }

        if (!isset($question['answers'][$answers[$questionKey]])) {
            return false;
        }
    }

    return true;
}

function brewski_answers_to_preferences(array $answers): array
{
    $preferences = [];

    foreach (brewski_choice_questions() as $questionKey => $question) {
        if (!isset($answers[$questionKey]) || !is_string($answers[$questionKey])) {
            continue;
        }

        $answer = $answers[$questionKey];

        if (!isset($question['answers'][$answer])) {
            continue;
        }

        foreach ($question['answers'][$answer] as $preference) {
            $preferences[$preference] = true;
        }
    }

    return array_keys($preferences);
}

function brewski_answers_from_preferences(array $stored): array
{
    $selected = [];

    foreach (brewski_choice_questions() as $questionKey => $question) {
        $universe = [];

        foreach ($question['answers'] as $preferences) {
            foreach ($preferences as $preference) {
                $universe[$preference] = true;
            }
        }

        $reached = array_values(array_intersect($stored, array_keys($universe)));

        if (!$reached) {
            continue;
        }

        sort($reached);

        foreach ($question['answers'] as $label => $preferences) {
            $candidate = $preferences;
            sort($candidate);

            if ($candidate === $reached) {
                $selected[$questionKey] = $label;
                break;
            }
        }
    }

    return $selected;
}
