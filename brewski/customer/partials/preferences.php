<?php

require_once __DIR__ . '/../../login-signup/settings.php';
require_once __DIR__ . '/../../Db/db_config.php';

if (!defined('BREWSKI_CHOICE_FINGERPRINT_KEY')) {
    define('BREWSKI_CHOICE_FINGERPRINT_KEY', 'choice_catalog_fingerprint');
}

function brewski_choice_questions(): array
{
    return [
        'base' => [
            'question' => 'What kind of drink do you want?',
            'answers' => [
                'Coffee' => ['coffee'],
                'Non-coffee' => ['non-coffee'],
                'Surprise me' => ['coffee', 'non-coffee'],
            ],
        ],
        'bean' => [
            'question' => 'Which coffee bean do you want?',
            'answers' => [
                'Arabica' => ['bean:Arabica'],
                'Robusta' => ['bean:Robusta'],
                'Blend' => ['bean:Blend'],
                'No preference' => ['bean:any'],
            ],
        ],
        'milk' => [
            'question' => 'Which milk should it use?',
            'answers' => [
                'Dairy milk' => ['milk:Dairy milk'],
                'Oat milk' => ['milk:Oat milk'],
                'Almond milk' => ['milk:Almond milk'],
                'Soy milk' => ['milk:Soy milk'],
                'No milk' => ['milk:No milk'],
                'No preference' => ['milk:any'],
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
            'question' => 'How sweet?',
            'answers' => [
                'Sweet' => ['sugar:sweet'],
                'Mild' => ['sugar:light'],
                'Not sweet' => ['sugar:none'],
                'No preference' => ['sugar:sweet', 'sugar:light', 'sugar:none'],
            ],
        ],
        'dietary' => [
            'question' => 'Which allergens should your drink avoid?',
            'multi' => true,
            'answers' => [
                'Avoid dairy' => ['avoid:dairy'],
                'Avoid nuts' => ['avoid:nuts'],
                'Avoid gluten' => ['avoid:gluten'],
                'None' => [],
            ],
        ],
    ];
}

function brewski_preference_definitions(): array
{
    return [
        'coffee' => 'Coffee based',
        'non-coffee' => 'Not coffee based',
        'bean:Arabica' => 'Arabica coffee bean',
        'bean:Robusta' => 'Robusta coffee bean',
        'bean:Blend' => 'Blended coffee beans',
        'bean:any' => 'Any coffee bean',
        'milk:Dairy milk' => 'Dairy milk',
        'milk:Oat milk' => 'Oat milk',
        'milk:Almond milk' => 'Almond milk',
        'milk:Soy milk' => 'Soy milk',
        'milk:No milk' => 'No milk',
        'milk:any' => 'Any milk',
        'temp:hot' => 'Served hot',
        'temp:iced' => 'Served iced',
        'sugar:sweet' => 'Sweet',
        'sugar:light' => 'Lightly sweet',
        'sugar:none' => 'Not sweet',
        'avoid:dairy' => 'Avoid dairy',
        'avoid:nuts' => 'Avoid nuts',
        'avoid:gluten' => 'Avoid gluten',
    ];
}

function brewski_decaf_keywords(): array
{
    return ['decaf', 'caffeine-free', 'caffeine free', 'herbal', 'fruit', 'juice', 'smoothie'];
}

function brewski_non_coffee_keywords(): array
{
    return ['matcha', 'tea', 'choco', 'chocolate', 'fruit', 'juice', 'smoothie', 'milk', 'non-coffee', 'lemonade', 'soda'];
}

function brewski_preferences_connection()
{
    static $connection = null;

    if ($connection instanceof mysqli) {
        return $connection;
    }

    $connection = brewski_db_connect();

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
    $nonCoffeeKeywords = brewski_non_coffee_keywords();
    $links = [];

    foreach ($catalog['products'] as $product) {
        $productId = (int) $product['product_id'];
        $haystack = mb_strtolower(
            $product['category_name'] . ' ' . $product['product_name'] . ' ' . ($product['description'] ?? '')
        );
        $keys = [];

        $isNonCoffee = brewski_matches_any([$haystack], $nonCoffeeKeywords);
        $keys[] = $isNonCoffee ? 'non-coffee' : 'coffee';

        $temperatures = [];
        $sugarLevels = [];

        foreach ($product['customizations'] as $customization) {
            $name = mb_strtolower($customization['name']);
            $group = strtolower((string) $customization['group']);

            if ($group === 'bean' || $group === 'milk') {
                $keys[] = $group . ':' . $customization['name'];
            }

            if ($group === 'allergen' && in_array($name, ['dairy', 'nuts', 'gluten'], true)) {
                $keys[] = 'avoid:' . $name;
            }

            if ($group === 'temp' || $group === 'temperature') {
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

        $links[$productId] = array_values(array_unique($keys));
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

    $result = brewski_preferences_attempt(function () use ($userId) {
        $connection = brewski_preferences_connection();

        if (!$connection) {
            return null;
        }

        brewski_preferences_ensure_tables($connection);

        $userPrefsStmt = $connection->prepare(
            'SELECT preferences.preference_name
             FROM user_preferences
             INNER JOIN preferences ON preferences.preference_id = user_preferences.preference_id
             WHERE user_preferences.user_id = ?
               AND user_preferences.preference_value = 1'
        );
        $userPrefsStmt->bind_param('i', $userId);
        $userPrefsStmt->execute();
        $userPrefsResult = $userPrefsStmt->get_result();

        $userPrefs = [];
        $dietaryExclusions = [];
        while ($row = $userPrefsResult->fetch_assoc()) {
            $name = $row['preference_name'];
            $userPrefs[] = $name;
            if (str_starts_with($name, 'avoid:')) {
                $dietaryExclusions[] = $name;
            }
        }
        $userPrefsStmt->close();

        if (empty($userPrefs)) {
            return [];
        }

        $scoreStmt = $connection->prepare(
            "SELECT product_preferences.product_id, COUNT(*) AS score
             FROM product_preferences
             INNER JOIN user_preferences
                ON product_preferences.preference_id = user_preferences.preference_id
               AND product_preferences.preference_value = 1
             WHERE user_preferences.user_id = ?
               AND user_preferences.preference_value = 1
             GROUP BY product_preferences.product_id"
        );
        $scoreStmt->bind_param('i', $userId);
        $scoreStmt->execute();
        $scoreResult = $scoreStmt->get_result();

        $scores = [];
        while ($row = $scoreResult->fetch_assoc()) {
            $scores[(int) $row['product_id']] = (int) $row['score'];
        }
        $scoreStmt->close();

        if (!empty($dietaryExclusions)) {
            $exclusionPlaceholders = implode(',', array_fill(0, count($dietaryExclusions), '?'));
            $exclusionTypes = str_repeat('s', count($dietaryExclusions));

            $excludeStmt = $connection->prepare(
                "SELECT DISTINCT pp.product_id
                 FROM product_preferences pp
                 INNER JOIN preferences p ON p.preference_id = pp.preference_id
                 WHERE p.preference_name IN ($exclusionPlaceholders)
                   AND pp.preference_value = 1"
            );
            $excludeStmt->bind_param($exclusionTypes, ...$dietaryExclusions);
            $excludeStmt->execute();
            $excludeResult = $excludeStmt->get_result();

            $excludedProducts = [];
            while ($row = $excludeResult->fetch_assoc()) {
                $excludedProducts[(int) $row['product_id']] = true;
            }
            $excludeStmt->close();

            foreach ($excludedProducts as $pid => $_) {
                unset($scores[$pid]);
            }
        }

        return $scores;
    });

    return is_array($result) ? $result : [];
}

function brewski_answers_valid(array $answers): bool
{
    foreach (brewski_choice_questions() as $questionKey => $question) {
        if (!isset($answers[$questionKey])) {
            return false;
        }

        $isMulti = !empty($question['multi']);
        $value = $answers[$questionKey];

        if ($isMulti) {
            if (!is_array($value) || empty($value)) {
                return false;
            }
            foreach ($value as $item) {
                if (!is_string($item) || !isset($question['answers'][$item])) {
                    return false;
                }
            }
        } else {
            if (!is_string($value) || !isset($question['answers'][$value])) {
                return false;
            }
        }
    }

    return true;
}

function brewski_answers_to_preferences(array $answers): array
{
    $preferences = [];

    foreach (brewski_choice_questions() as $questionKey => $question) {
        if (!isset($answers[$questionKey])) {
            continue;
        }

        $isMulti = !empty($question['multi']);
        $value = $answers[$questionKey];

        if ($isMulti) {
            if (!is_array($value)) {
                continue;
            }
            foreach ($value as $label) {
                if (!isset($question['answers'][$label])) {
                    continue;
                }
                foreach ($question['answers'][$label] as $preference) {
                    if (str_ends_with($preference, ':any')) {
                        continue;
                    }
                    $preferences[$preference] = true;
                }
            }
        } else {
            if (!is_string($value) || !isset($question['answers'][$value])) {
                continue;
            }
            foreach ($question['answers'][$value] as $preference) {
                if (str_ends_with($preference, ':any')) {
                    continue;
                }
                $preferences[$preference] = true;
            }
        }
    }

    return array_keys($preferences);
}

function brewski_answers_from_preferences(array $stored): array
{
    $selected = [];

    foreach (brewski_choice_questions() as $questionKey => $question) {
        $isMulti = !empty($question['multi']);
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

        if ($isMulti) {
            $matchedLabels = [];
            foreach ($question['answers'] as $label => $preferences) {
                if (empty($preferences)) {
                    continue;
                }
                $candidate = $preferences;
                sort($candidate);
                if ($candidate === $reached) {
                    $matchedLabels[] = $label;
                }
            }
            if (!empty($matchedLabels)) {
                $selected[$questionKey] = $matchedLabels;
            }
        } else {
            foreach ($question['answers'] as $label => $preferences) {
                $candidate = $preferences;
                sort($candidate);

                if ($candidate === $reached) {
                    $selected[$questionKey] = $label;
                    break;
                }
            }
        }
    }

    return $selected;
}