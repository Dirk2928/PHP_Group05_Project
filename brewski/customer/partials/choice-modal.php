<?php

$choiceQuestions = brewski_choice_questions();
$choiceValues = $choiceValues ?? [];
$choiceAutoOpen = $choiceAutoOpen ?? false;

?>

<div
    class="modal-overlay choice-modal"
    id="choice-modal"
    data-auto-open="<?= $choiceAutoOpen ? '1' : '0' ?>"
>

    <div
        class="modal choice-modal__panel"
        role="dialog"
        aria-modal="true"
        aria-labelledby="choice-modal-title"
    >

        <div class="modal__header">

            <div>

                <h2 id="choice-modal-title">Let's find your drink</h2>

                <p class="modal__subtitle"><?= count($choiceQuestions) ?> quick questions</p>

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

                <?php $chosen = $choiceValues[$questionKey] ?? null; ?>

                <section
                    class="choice-question"
                    data-choice-question="<?= e($questionKey) ?>"
                >

                    <h3><?= e($question['question']) ?></h3>

                    <div class="choice-answers">

                        <?php foreach ($question['answers'] as $label => $preferences): ?>

                            <button
                                type="button"
                                class="choice-answer<?= $chosen === $label ? ' active' : '' ?>"
                                data-choice-answer="<?= e($label) ?>"
                                aria-pressed="<?= $chosen === $label ? 'true' : 'false' ?>"
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

            <button
                type="button"
                class="btn-confirm"
                id="choice-save"
            >

                <span>Show my drinks</span>

                <i data-lucide="arrow-right"></i>

            </button>

        </div>

    </div>

</div>
