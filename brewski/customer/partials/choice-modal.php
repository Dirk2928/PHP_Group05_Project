<?php

$choiceQuestions = brewski_choice_questions();
$choiceValues = $choiceValues ?? [];
$choiceAutoOpen = $choiceAutoOpen ?? false;
$choiceMode = $choiceMode ?? 'recommendation';
$isFirstTimer = $choiceMode === 'first-timer';

?>

<div
    class="modal-overlay choice-modal"
    id="choice-modal"
    data-auto-open="<?= $choiceAutoOpen ? '1' : '0' ?>"
    data-choice-mode="<?= e($choiceMode) ?>"
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
                    <?= $isFirstTimer ? 'Set up your drink preferences' : 'Get drink recommendations' ?>
                </h2>

                <p class="modal__subtitle">
                    <?= $isFirstTimer
                        ? 'Answer a few questions to organize your menu by compatibility.'
                        : 'Answer a few questions to rank drinks that fit your taste.' ?>
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

            <?php foreach ($choiceQuestions as $questionKey => $question): ?>

                <?php
                    $isMulti = !empty($question['multi']);
                    $chosen = $choiceValues[$questionKey] ?? null;
                    $chosenList = is_array($chosen) ? $chosen : ($chosen !== null ? [$chosen] : []);
                ?>

                <section
                    class="choice-question"
                    data-choice-question="<?= e($questionKey) ?>"
                    data-choice-multi="<?= $isMulti ? '1' : '0' ?>"
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

            <?php endforeach; ?>

            <p
                class="choice-status"
                id="choice-status"
                role="status"
                aria-live="polite"
            ></p>

        </div>

        <div class="modal__footer">

            <button type="button" class="modal__close" id="choice-skip">Skip for now</button>

            <button
                type="button"
                class="btn-confirm"
                id="choice-save"
            >

                <span><?= $isFirstTimer ? 'Organize my menu' : 'Recommend my drinks' ?></span>

                <i data-lucide="arrow-right"></i>

            </button>

        </div>

    </div>

</div>