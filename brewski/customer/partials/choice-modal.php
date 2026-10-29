<?php

$drinkQuestions = brewski_questions_for_mode('first-timer');
$nonCoffeeQuestions = brewski_drink_recommendation_questions_for_base('Non-coffee');
$drinkDisplayQuestions = [];
foreach ($drinkQuestions as $questionKey => $question) {
    if ($questionKey === 'caffeine') {
        $question['branch'] = 'coffee';
    }
    $drinkDisplayQuestions[$questionKey] = $question;
    if ($questionKey === 'bean') {
        $drinkDisplayQuestions['style'] = $nonCoffeeQuestions['style'];
    }
}
$preferenceQuestions = brewski_questions_for_mode('recommendation');
$choiceValues = $choiceValues ?? [];
$choiceAutoOpen = $choiceAutoOpen ?? false;
$choiceMode = $choiceMode ?? 'first-timer';
$initialMode = $choiceMode === 'recommendation' ? 'recommendation' : 'first-timer';
$renderQuestions = static function (array $questionSet, array $values, bool $progressive = false, array $alternates = []): void {
    foreach ($questionSet as $questionKey => $question) {
        $isMulti = !empty($question['multi']);
        $chosen = $values[$questionKey] ?? null;
        $chosenList = is_array($chosen) ? $chosen : ($chosen !== null ? [$chosen] : []);
        ?>
        <section
            class="choice-question"
            data-choice-question="<?= e($questionKey) ?>"
            data-choice-multi="<?= $isMulti ? '1' : '0' ?>"
            <?php if (isset($question['branch'])): ?>data-choice-branch="<?= e($question['branch']) ?>"<?php endif; ?>
            <?php if (isset($alternates[$questionKey]) && $alternates[$questionKey]['question'] !== $question['question']): ?>
                data-choice-default-title="<?= e($question['question']) ?>"
                data-choice-non-coffee-title="<?= e($alternates[$questionKey]['question']) ?>"
            <?php endif; ?>
            <?= $progressive && $questionKey !== 'base' ? 'hidden' : '' ?>
        >
            <h3><?= e($question['question']) ?></h3>
            <div class="choice-answers">
                <?php foreach ($question['answers'] as $label => $preferences): ?>
                    <?php $isActive = in_array($label, $chosenList, true); ?>
                    <button
                        type="button"
                        class="choice-answer<?= $isActive ? ' active' : '' ?>"
                        data-choice-answer="<?= e($label) ?>"
                        aria-pressed="<?= $isActive ? 'true' : 'false' ?>"
                    >
                        <?= e($label) ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
    }
};

?>

<div
    class="modal-overlay choice-modal"
    id="choice-modal"
    data-auto-open="<?= $choiceAutoOpen ? '1' : '0' ?>"
    data-choice-mode="<?= e($initialMode) ?>"
    data-home-url="../customer_home/customerhome.php"
>

    <div
        class="modal choice-modal__panel"
        role="dialog"
        aria-modal="true"
        aria-labelledby="choice-modal-title"
    >

        <div class="modal__header">

            <div>

                <h2 id="choice-modal-title">
                    <?= $initialMode === 'recommendation' ? 'Set up your drink preferences' : 'Get a drink recommendation' ?>
                </h2>

                <p class="modal__subtitle" id="choice-modal-subtitle">
                    <?= $initialMode === 'recommendation'
                        ? 'Answer a few questions to sort your menu by compatibility.'
                        : 'Answer a few quick questions and we will pick one drink for you.' ?>
                </p>

            </div>

            <button
                type="button"
                class="modal__close"
                id="choice-close"
                aria-label="Close"
            >

                <i data-lucide="x"></i>

            </button>

        </div>

        <div class="modal__body">

            <div class="choice-panel" data-choice-panel="first-timer"<?= $initialMode === 'first-timer' ? '' : ' hidden' ?>>
                <?php $renderQuestions($drinkDisplayQuestions, [], true, $nonCoffeeQuestions); ?>
                <p class="choice-surprise-hint" id="choice-surprise-hint" hidden>We will pick a random available drink for you.</p>
            </div>

            <div class="choice-panel" data-choice-panel="recommendation"<?= $initialMode === 'recommendation' ? '' : ' hidden' ?>>
                <?php $renderQuestions($preferenceQuestions, $choiceValues); ?>
            </div>

            <p
                class="choice-status"
                id="choice-status"
                role="status"
                aria-live="polite"
            ></p>

        </div>

        <div class="modal__footer">

            <button type="button" class="modal__close" id="choice-skip"<?= $initialMode === 'recommendation' ? '' : ' hidden' ?>>Skip for now</button>

            <button
                type="button"
                class="btn-confirm"
                id="choice-save"
            >

                <span id="choice-save-label"><?= $initialMode === 'recommendation' ? 'Find my best matches' : 'Recommend a drink' ?></span>

                <i data-lucide="arrow-right"></i>

            </button>

        </div>

    </div>

</div>
