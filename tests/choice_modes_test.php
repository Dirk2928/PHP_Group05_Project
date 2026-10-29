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

$stored = brewski_answers_to_preferences($preferenceAnswers, $preferenceQuestions);
$restored = brewski_answers_from_preferences($stored);
expect_choice_test($restored === $preferenceAnswers, 'Saved preferences do not restore to the six-question form.');

echo "Drink recommendation and menu preference contracts passed.\n";
