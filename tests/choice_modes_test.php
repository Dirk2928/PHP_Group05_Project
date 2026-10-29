<?php

require_once __DIR__ . '/../brewski/customer/partials/preferences.php';

function expect_choice_test(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$drinkQuestions = brewski_questions_for_mode('first-timer');
$preferenceQuestions = brewski_questions_for_mode('recommendation');

expect_choice_test(count($drinkQuestions) === 8, 'Drink recommendation must have eight questions.');
expect_choice_test(count($preferenceQuestions) === 6, 'Menu preferences must have six questions.');

$nonCoffeeQuestions = brewski_drink_recommendation_questions_for_base('Non-coffee');
$surpriseQuestions = brewski_drink_recommendation_questions_for_base('Surprise me');
expect_choice_test(count($nonCoffeeQuestions) === 7, 'Non-coffee recommendation must have seven questions.');
expect_choice_test(isset($nonCoffeeQuestions['style']) && !isset($nonCoffeeQuestions['bean']), 'Non-coffee must replace the bean question.');
expect_choice_test(!isset($nonCoffeeQuestions['caffeine']), 'Non-coffee must omit the caffeine question.');
expect_choice_test($nonCoffeeQuestions['milk']['question'] === 'Milk preference, if any?', 'Non-coffee milk question did not adapt.');
expect_choice_test(array_keys($surpriseQuestions) === ['base'], 'Surprise me must need only the first answer.');

$drinkAnswers = [
    'base' => 'Coffee',
    'bean' => 'Arabica',
    'milk' => 'Dairy milk',
    'flavor' => 'Chocolate',
    'caffeine' => 'Regular',
    'temperature' => 'Iced',
    'sweetness' => 'Mild',
    'dietary' => ['None'],
];
$preferenceAnswers = [
    'mood' => 'Coffee',
    'flavor' => 'Chocolate',
    'temperature' => 'Iced',
    'sweetness' => 'Mild',
    'caffeine' => 'Regular',
    'dietary' => ['None'],
];

expect_choice_test(brewski_answers_valid($drinkAnswers, $drinkQuestions), 'Eight-question answers were rejected.');
expect_choice_test(brewski_answers_valid($preferenceAnswers, $preferenceQuestions), 'Six-question answers were rejected.');
expect_choice_test(!brewski_answers_valid($drinkAnswers, $preferenceQuestions), 'Drink answers passed as preferences.');
expect_choice_test(!brewski_answers_valid($preferenceAnswers, $drinkQuestions), 'Preference answers passed as a drink recommendation.');

$nonCoffeeAnswers = $drinkAnswers;
$nonCoffeeAnswers['base'] = 'Non-coffee';
unset($nonCoffeeAnswers['bean']);
$nonCoffeeAnswers['style'] = 'Milk tea';
unset($nonCoffeeAnswers['caffeine']);
expect_choice_test(brewski_answers_valid($nonCoffeeAnswers, $nonCoffeeQuestions), 'Non-coffee answers were rejected.');
expect_choice_test(!brewski_answers_valid($nonCoffeeAnswers, $drinkQuestions), 'Non-coffee answers passed the coffee branch.');
expect_choice_test(brewski_answers_valid(['base' => 'Surprise me'], $surpriseQuestions), 'Surprise me requires extra answers.');

$catalog = ['products' => [
    [
        'product_id' => 1, 'category_name' => 'Coffee', 'product_name' => 'Americano',
        'description' => '', 'price' => 165, 'customizations' => [],
    ],
    [
        'product_id' => 2, 'category_name' => 'Milk Tea', 'product_name' => 'Classic Pearl',
        'description' => '', 'price' => 180, 'customizations' => [
            ['group' => 'milk', 'name' => 'Dairy milk'],
            ['group' => 'flavor', 'name' => 'Chocolate'],
            ['group' => 'caffeine', 'name' => 'Regular'],
            ['group' => 'temperature', 'name' => 'Iced'],
            ['group' => 'sugar', 'name' => 'Mild'],
        ],
    ],
    [
        'product_id' => 3, 'category_name' => 'Juice', 'product_name' => 'Mango',
        'description' => '', 'price' => 150, 'customizations' => [],
    ],
    [
        'product_id' => 4, 'category_name' => 'Pastry', 'product_name' => 'Cookie',
        'description' => '', 'price' => 140, 'customizations' => [],
    ],
]];
$links = brewski_preference_links($catalog);
expect_choice_test(in_array('caffeine:Caffeine-free', $links[3], true), 'Juice should be treated as caffeine-free.');
expect_choice_test(in_array('milk:No milk', $links[3], true), 'Juice should match the no-milk choice.');
$ranked = brewski_rank_products(
    brewski_answers_to_preferences($nonCoffeeAnswers, $nonCoffeeQuestions),
    $links
);
expect_choice_test(brewski_best_match($ranked)['product_id'] === 2, 'Non-coffee style did not select the matching drink.');
for ($attempt = 0; $attempt < 20; $attempt++) {
    $randomDrink = brewski_random_drink_product($catalog, $links);
    expect_choice_test(in_array($randomDrink['product_id'], [1, 2, 3], true), 'Surprise me selected a food item.');
}

$stored = brewski_answers_to_preferences($preferenceAnswers, $preferenceQuestions);
$restored = brewski_answers_from_preferences($stored);
expect_choice_test($restored === $preferenceAnswers, 'Saved preferences do not restore to the six-question form.');

echo "Drink recommendation and menu preference contracts passed.\n";
