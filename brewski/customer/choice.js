document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var modal = document.getElementById('choice-modal');

    if (!modal) {
        return;
    }

    var body = modal.querySelector('.modal__body');
    var closeButton = document.getElementById('choice-close');
    var saveButton = document.getElementById('choice-save');
    var status = document.getElementById('choice-status');
    var openButton = document.getElementById('choice-open');
    var questions = Array.prototype.slice.call(
        modal.querySelectorAll('[data-choice-question]')
    );

    function open() {
        modal.classList.add('show');
    }

    function close() {
        modal.classList.remove('show');
    }

    function setStatus(message, modifier) {
        status.textContent = message;
        status.className = 'choice-status' + (modifier ? ' ' + modifier : '');
    }

    function selectedAnswers() {
        var answers = {};

        questions.forEach(function (question) {
            var active = question.querySelector('.choice-answer.active');

            if (active) {
                answers[question.dataset.choiceQuestion] = active.dataset.choiceAnswer;
            }
        });

        return answers;
    }

    if (body) {
        body.addEventListener('click', function (event) {
            var answer = event.target.closest('.choice-answer');

            if (!answer) {
                return;
            }

            Array.prototype.forEach.call(
                answer.parentElement.querySelectorAll('.choice-answer'),
                function (other) {
                    other.classList.remove('active');
                    other.setAttribute('aria-pressed', 'false');
                }
            );

            answer.classList.add('active');
            answer.setAttribute('aria-pressed', 'true');
        });
    }

    if (openButton) {
        openButton.addEventListener('click', open);
    }

    if (closeButton) {
        closeButton.addEventListener('click', close);
    }

    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            close();
        }
    });

    if (saveButton && status) {
        saveButton.addEventListener('click', function () {
            var answers = selectedAnswers();

            if (Object.keys(answers).length < questions.length) {
                setStatus('Please answer all ' + questions.length + ' questions.', 'is-error');
                return;
            }

            setStatus('Saving...', '');
            saveButton.disabled = true;

            var payload = new URLSearchParams();

            Object.keys(answers).forEach(function (questionKey) {
                payload.append('answers[' + questionKey + ']', answers[questionKey]);
            });

            fetch('../choice-save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: payload.toString(),
                credentials: 'same-origin'
            })
                .then(function (response) {
                    return response.text().then(function (text) {
                        return { ok: response.ok, text: text };
                    });
                })
                .then(function (result) {
                    if (result.ok && result.text === 'ok') {
                        setStatus('Saved! Taking you to your menu...', 'is-saved');

                        window.setTimeout(function () {
                            window.location.href = '../customer_menu/customermenu.php';
                        }, 700);

                        return true;
                    }

                    setStatus(
                        result.ok && result.text.indexOf('<') === -1
                            ? result.text
                            : 'Could not save your choices. Please log in again and try.',
                        'is-error'
                    );

                    return false;
                })
                .catch(function () {
                    setStatus('Could not save your choices. Please try again.', 'is-error');

                    return false;
                })
                .then(function (saved) {
                    if (saved !== true) {
                        saveButton.disabled = false;
                    }
                });
        });
    }

    if (modal.dataset.autoOpen === '1') {
        open();
    }
});
