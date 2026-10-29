<?php

require_once __DIR__ . '/../../login-signup/settings.php';
require_once __DIR__ . '/../../Db/db_config.php';

if (!defined('BREWSKI_CHOICE_FINGERPRINT_KEY')) {
    define('BREWSKI_CHOICE_FINGERPRINT_KEY', 'choice_catalog_fingerprint');
}

function brewski_custom_option_values(string $group, array $fallback): array
{
    static $cache = [];

    if (array_key_exists($group, $cache)) {
        return $cache[$group];
    }

    try {
        $connection = brewski_preferences_connection();
        if (!$connection) {
            return $cache[$group] = $fallback;
        }
        $statement = $connection->prepare(
            'SELECT DISTINCT option_name
             FROM product_customization_options
             WHERE option_group = ?
             ORDER BY option_name'
        );
        $statement->bind_param('s', $group);
        $statement->execute();
        $result = $statement->get_result();
        $names = [];
        while ($row = $result->fetch_assoc()) {
            $names[] = (string) $row['option_name'];
        }
        $statement->close();
        return $cache[$group] = ($names ?: $fallback);
    } catch (Throwable $error) {
        error_log('Preference option lookup error: ' . $error->getMessage());
        return $cache[$group] = $fallback;
    }
}

function brewski_drink_recommendation_questions(): array
{
    static $questions = null;

    if ($questions !== null) {
        return $questions;
    }

    $beanChoices = brewski_custom_option_values('bean', ['Arabica', 'Robusta', 'Blend']);
    $milkChoices = brewski_custom_option_values('milk', ['Dairy milk', 'Oat milk', 'Almond milk', 'Soy milk', 'No milk']);
    $allergenChoices = brewski_custom_option_values('allergen', ['Dairy', 'Nuts', 'Gluten']);
    $flavorChoices = brewski_custom_option_values('flavor', ['Chocolate', 'Nutty', 'Fruity', 'Creamy']);
    $caffeineChoices = brewski_custom_option_values('caffeine', ['Regular', 'Decaf', 'Caffeine-free']);
    $choiceAnswers = static function (array $names, string $prefix): array {
        $answers = [];
        foreach ($names as $name) {
            $answers[$name] = [$prefix . $name];
        }
        $answers['No preference'] = [$prefix . 'any'];
        return $answers;
    };

    return $questions = [
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
            'answers' => $choiceAnswers($beanChoices, 'bean:'),
        ],
        'milk' => [
            'question' => 'Which milk should it use?',
            'answers' => $choiceAnswers($milkChoices, 'milk:'),
        ],
        'flavor' => [
            'question' => 'Which flavor profile sounds best?',
            'answers' => $choiceAnswers($flavorChoices, 'flavor:'),
        ],
        'caffeine' => [
            'question' => 'How much caffeine do you want?',
            'answers' => $choiceAnswers($caffeineChoices, 'caffeine:'),
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
            'answers' => array_merge(
                array_combine(
                    array_map(static fn (string $name): string => 'Avoid ' . $name, $allergenChoices),
                    array_map(static fn (string $name): array => ['avoid:' . strtolower($name)], $allergenChoices)
                ),
                ['None' => []]
            ),
        ],
    ];
}

function brewski_menu_preference_questions(): array
{
    static $questions = null;

    if ($questions !== null) {
        return $questions;
    }

    $flavorChoices = brewski_custom_option_values('flavor', ['Chocolate', 'Nutty', 'Fruity', 'Creamy']);
    $caffeineChoices = brewski_custom_option_values('caffeine', ['Regular', 'Decaf', 'Caffeine-free']);
    $allergenChoices = brewski_custom_option_values('allergen', ['Dairy', 'Nuts', 'Gluten']);
    $flavorAnswers = [];
    foreach ($flavorChoices as $name) {
        $flavorAnswers[$name] = ['flavor:' . $name];
    }
    $flavorAnswers['No preference'] = ['flavor:any'];
    $caffeineAnswers = [];
    foreach ($caffeineChoices as $name) {
        $caffeineAnswers[$name] = ['caffeine:' . $name];
    }
    $caffeineAnswers['No preference'] = ['caffeine:any'];

    return $questions = [
        'mood' => [
            'question' => 'What kind of drink are you in the mood for?',
            'answers' => [
                'Coffee' => ['coffee'],
                'Non-coffee' => ['non-coffee'],
                'Surprise me' => ['coffee', 'non-coffee'],
            ],
        ],
        'flavor' => [
            'question' => 'Which flavor sounds best right now?',
            'answers' => $flavorAnswers,
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
            'question' => 'How sweet do you feel like having it?',
            'answers' => [
                'Sweet' => ['sugar:sweet'],
                'Mild' => ['sugar:light'],
                'Not sweet' => ['sugar:none'],
                'No preference' => ['sugar:sweet', 'sugar:light', 'sugar:none'],
            ],
        ],
        'caffeine' => [
            'question' => 'How much caffeine do you want?',
            'answers' => $caffeineAnswers,
        ],
        'dietary' => [
            'question' => 'Which allergens should your drink avoid?',
            'multi' => true,
            'answers' => array_merge(
                array_combine(
                    array_map(static fn (string $name): string => 'Avoid ' . $name, $allergenChoices),
                    array_map(static fn (string $name): array => ['avoid:' . strtolower($name)], $allergenChoices)
                ),
                ['None' => []]
            ),
        ],
    ];
}

function brewski_questions_for_mode(string $mode): array
{
    return match ($mode) {
        'first-timer' => brewski_drink_recommendation_questions(),
        'recommendation' => brewski_menu_preference_questions(),
        default => throw new InvalidArgumentException('Unknown drink choice mode.'),
    };
}

function brewski_preference_definitions(): array
{
    $definitions = [
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
        'flavor:Chocolate' => 'Chocolate flavor',
        'flavor:Nutty' => 'Nutty flavor',
        'flavor:Fruity' => 'Fruity flavor',
        'flavor:Creamy' => 'Creamy flavor',
        'flavor:any' => 'Any flavor',
        'caffeine:any' => 'Any caffeine level',
        'caffeine:Regular' => 'Regular caffeine',
        'caffeine:Decaf' => 'Decaffeinated',
        'caffeine:Caffeine-free' => 'Caffeine-free',
        'temp:hot' => 'Served hot',
        'temp:iced' => 'Served iced',
        'sugar:sweet' => 'Sweet',
        'sugar:light' => 'Lightly sweet',
        'sugar:none' => 'Not sweet',
        'avoid:dairy' => 'Avoid dairy',
        'avoid:nuts' => 'Avoid nuts',
        'avoid:gluten' => 'Avoid gluten',
    ];
    $questions = brewski_drink_recommendation_questions();

    foreach (['bean', 'milk', 'flavor', 'caffeine', 'dietary'] as $group) {
        foreach ($questions[$group]['answers'] as $answers) {
            foreach ($answers as $answer) {
                if (!str_ends_with($answer, ':any')) {
                    $definitions[$answer] = ucfirst(str_replace(':', ' ', $answer));
                }
            }
        }
    }
    return $definitions;
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
    $driver = new mysqli_driver();
    $previous_report_mode = $driver->report_mode;
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

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

    if ($done || !empty($_SESSION['brewski_pref_tables_ready'])) {
        return;
    }

    $columns = [
        'is_first_timer' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'assessment_completed_at' => 'DATETIME NULL',
    ];
    foreach ($columns as $column => $definition) {
        $columnCheck = $connection->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?'
        );
        if (!$columnCheck) {
            throw new mysqli_sql_exception(
                'Could not inspect users columns: ' . $connection->error
            );
        }
        $tableName = 'users';
        $columnCheck->bind_param('ss', $tableName, $column);
        $columnCheck->execute();
        $columnCheck->bind_result($columnCount);
        $columnCheck->fetch();
        $exists = (int) $columnCount > 0;
        $columnCheck->close();
        if (!$exists) {
            $connection->query('ALTER TABLE users ADD COLUMN ' . $column . ' ' . $definition);
        }
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
    $_SESSION['brewski_pref_tables_ready'] = true;
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
            . ':' . $product['category_name'] . ':' . $product['price']
            . ':' . ($product['description'] ?? '');

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
        'not sweet' => 0,
        'none' => 0,
        'no sugar' => 0,
        'unsweet' => 0,
        'zero' => 0,
        'mild' => 50,
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
    $links = [];

    foreach ($catalog['products'] as $product) {
        $productId = (int) $product['product_id'];
        $categoryName = mb_strtolower((string) $product['category_name']);
        $text = mb_strtolower(
            $product['product_name'] . ' ' . ($product['description'] ?? '')
        );
        $haystack = $categoryName . ' ' . $text;
        $keys = [];

        if (brewski_matches_any([$categoryName], ['pastry', 'pastries', 'food', 'snack', 'dessert'])) {
            $links[$productId] = [];
            continue;
        }

        $isCoffee = $categoryName === 'coffee'
            || brewski_matches_any(
                [$text],
                ['coffee', 'espresso', 'macchiato', 'americano', 'mocha', 'cappuccino']
            );
        $keys[] = $isCoffee ? 'coffee' : 'non-coffee';

        $temperatures = [];
        $sugarLevels = [];
        $hasFlavorOptions = false;
        $hasCaffeineOptions = false;

        foreach ($product['customizations'] as $customization) {
            $name = mb_strtolower($customization['name']);
            $group = strtolower((string) $customization['group']);

            if ($group === 'bean' || $group === 'milk') {
                $keys[] = $group . ':' . $customization['name'];
            }
            if ($group === 'flavor' || $group === 'caffeine') {
                $keys[] = $group . ':' . $customization['name'];
                if ($group === 'flavor') {
                    $hasFlavorOptions = true;
                } else {
                    $hasCaffeineOptions = true;
                }
            }

            if ($group === 'allergen' && $name !== '') {
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

        if (!$hasFlavorOptions) {
            if (brewski_matches_any([$haystack], ['chocolate', 'cocoa', 'mocha'])) {
                $keys[] = 'flavor:Chocolate';
            }
            if (brewski_matches_any([$haystack], ['nutty', 'hazelnut', 'almond'])) {
                $keys[] = 'flavor:Nutty';
            }
            if (brewski_matches_any([$haystack], ['fruit', 'berry', 'mango', 'juice'])) {
                $keys[] = 'flavor:Fruity';
            }
            if (brewski_matches_any([$haystack], ['cream', 'creamy', 'vanilla', 'latte'])) {
                $keys[] = 'flavor:Creamy';
            }
        }

        if (!$hasCaffeineOptions) {
            $isCaffeinated = $isCoffee
                || brewski_matches_any([$text], ['matcha', 'tea']);
            $keys[] = $isCaffeinated ? 'caffeine:Regular' : 'caffeine:Decaf';
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

const BREWSKI_RANK_WEIGHTS = [
    'temperature' => 20,
    'caffeine' => 20,
    'sweetness' => 15,
    'flavor' => 20,
    'bean' => 5,
    'milk' => 10,
    'base' => 10,
];

function brewski_rank_products(array $userPreferences, array $productTags): array
{
    $beanChoices = brewski_custom_option_values('bean', ['Arabica', 'Robusta', 'Blend']);
    $milkChoices = brewski_custom_option_values('milk', ['Dairy milk', 'Oat milk', 'Almond milk', 'Soy milk', 'No milk']);
    $flavorChoices = brewski_custom_option_values('flavor', ['Chocolate', 'Nutty', 'Fruity', 'Creamy']);
    $caffeineChoices = brewski_custom_option_values('caffeine', ['Regular', 'Decaf', 'Caffeine-free']);
    $groupTags = static function (string $prefix, array $names): array {
        return array_map(
            static fn (string $name): string => $prefix . ':' . $name,
            $names
        );
    };
    $groups = [
        'temperature' => ['temp:hot', 'temp:iced'],
        'caffeine' => $groupTags('caffeine', $caffeineChoices),
        'sweetness' => ['sugar:sweet', 'sugar:light', 'sugar:none'],
        'flavor' => $groupTags('flavor', $flavorChoices),
        'bean' => $groupTags('bean', $beanChoices),
        'milk' => $groupTags('milk', $milkChoices),
        'base' => ['coffee', 'non-coffee'],
    ];
    $ranked = [];
    $baseWanted = array_values(array_intersect($userPreferences, ['coffee', 'non-coffee']));
    $avoided = array_values(array_filter(
        $userPreferences,
        static fn (string $preference): bool => str_starts_with($preference, 'avoid:')
    ));
    foreach ($productTags as $productId => $tags) {
        $tags = array_values(array_unique($tags));

        if (!$tags) {
            $ranked[(int) $productId] = [
                'product_id' => (int) $productId,
                'percent' => 0,
                'status' => 'available',
                'matched' => [],
                'excluded_for' => [],
            ];
            continue;
        }

        $excludedFor = [];
        $hasAllergenData = (bool) array_filter(
            $tags,
            static fn (string $tag): bool => str_starts_with($tag, 'avoid:')
        );
        foreach ($avoided as $avoid) {
            if (!$hasAllergenData) {
                $excludedFor[] = 'allergen info not listed';
            } elseif (in_array($avoid, $tags, true)) {
                $excludedFor[] = substr($avoid, 6);
            }
        }
        $earned = 0;
        $possible = 0;
        $matched = [];
        foreach ($groups as $group => $options) {
            $wanted = array_values(array_intersect($userPreferences, $options));
            if (!$wanted || (
                in_array($group, ['temperature', 'sweetness', 'base'], true)
                && count($wanted) === count($options)
            )) {
                continue;
            }
            $possible += BREWSKI_RANK_WEIGHTS[$group];
            $productMatches = array_values(array_intersect($wanted, $tags));
            if ($productMatches) {
                $earned += BREWSKI_RANK_WEIGHTS[$group];
                foreach ($productMatches as $match) {
                    $matched[] = ucfirst(str_replace(['temp:', 'sugar:', 'flavor:', 'caffeine:', 'bean:', 'milk:'], '', $match));
                }
            }
        }
        $percent = $excludedFor ? 0 : ($possible > 0 ? (int) round(100 * $earned / $possible) : 0);
        if ($percent > 0 && $baseWanted && !array_intersect($baseWanted, $tags)) {
            $percent = 0;
        }
        $ranked[(int) $productId] = [
            'product_id' => (int) $productId,
            'percent' => $percent,
            'status' => $excludedFor ? 'excluded' : ($percent >= 50 ? 'recommended' : 'available'),
            'matched' => array_values(array_unique($matched)),
            'excluded_for' => array_values(array_unique($excludedFor)),
        ];
    }
    return $ranked;
}

function brewski_best_match(array $ranked, int $minimum = 50): ?array
{
    $best = null;
    foreach ($ranked as $candidate) {
        if ($candidate['status'] === 'excluded' || $candidate['percent'] < $minimum) {
            continue;
        }
        if ($best === null
            || $candidate['percent'] > $best['percent']
            || ($candidate['percent'] === $best['percent']
                && count($candidate['matched']) > count($best['matched']))) {
            $best = $candidate;
        }
    }
    return $best;
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

        $assessment = $connection->prepare(
            'UPDATE users
             SET assessment_completed_at = NOW(),
                 is_first_timer = 0
             WHERE user_id = ?'
        );
        $assessment->bind_param('i', $userId);
        $assessment->execute();
        $assessment->close();

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

function brewski_answers_valid(array $answers, ?array $questions = null): bool
{
    $questions = $questions ?? brewski_drink_recommendation_questions();

    foreach ($questions as $questionKey => $question) {
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

function brewski_answers_to_preferences(array $answers, ?array $questions = null): array
{
    $questions = $questions ?? brewski_drink_recommendation_questions();
    $preferences = [];

    foreach ($questions as $questionKey => $question) {
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
                    $preferences[$preference] = true;
                }
            }
        } else {
            if (!is_string($value) || !isset($question['answers'][$value])) {
                continue;
            }
            foreach ($question['answers'][$value] as $preference) {
                $preferences[$preference] = true;
            }
        }
    }

    return array_keys($preferences);
}

function brewski_answers_from_preferences(array $stored): array
{
    $selected = [];

    foreach (brewski_menu_preference_questions() as $questionKey => $question) {
        $isMulti = !empty($question['multi']);
        $universe = [];

        foreach ($question['answers'] as $preferences) {
            foreach ($preferences as $preference) {
                $universe[$preference] = true;
            }
        }

        $reached = array_values(array_intersect($stored, array_keys($universe)));

        if (!$reached) {
            if ($isMulti && isset($question['answers']['None'])) {
                $selected[$questionKey] = ['None'];
            }
            continue;
        }

        sort($reached);

        if ($isMulti) {
            $matchedLabels = [];
            foreach ($question['answers'] as $label => $preferences) {
                if (empty($preferences)) {
                    continue;
                }
                if (!array_diff($preferences, $reached)) {
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
