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
    var skipButton = document.getElementById('choice-skip');
    var noticeButton = document.getElementById('choice-open-notice');
    var questions = Array.prototype.slice.call(
        modal.querySelectorAll('[data-choice-question]')
    );
    var isFirstTimer = modal.dataset.choiceMode === 'first-timer';

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
            var key = question.dataset.choiceQuestion;
            var isMulti = question.dataset.choiceMulti === '1';

            if (isMulti) {
                var activeButtons = question.querySelectorAll('.choice-answer.active');
                var selected = [];
                activeButtons.forEach(function (btn) {
                    selected.push(btn.dataset.choiceAnswer);
                });
                if (selected.length > 0) {
                    answers[key] = selected;
                }
            } else {
                var active = question.querySelector('.choice-answer.active');
                if (active) {
                    answers[key] = active.dataset.choiceAnswer;
                }
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

            var questionSection = answer.closest('[data-choice-question]');
            var isMulti = questionSection && questionSection.dataset.choiceMulti === '1';

            if (isMulti) {
                var wasActive = answer.classList.contains('active');
                answer.classList.toggle('active');
                answer.setAttribute('aria-pressed', wasActive ? 'false' : 'true');

                var noneButton = questionSection.querySelector('[data-choice-answer="None"]');
                if (noneButton && answer !== noneButton) {
                    noneButton.classList.remove('active');
                    noneButton.setAttribute('aria-pressed', 'false');
                }
                if (noneButton && answer === noneButton && !wasActive) {
                    questionSection.querySelectorAll('.choice-answer').forEach(function (btn) {
                        if (btn !== noneButton) {
                            btn.classList.remove('active');
                            btn.setAttribute('aria-pressed', 'false');
                        }
                    });
                }
            } else {
                Array.prototype.forEach.call(
                    answer.parentElement.querySelectorAll('.choice-answer'),
                    function (other) {
                        other.classList.remove('active');
                        other.setAttribute('aria-pressed', 'false');
                    }
                );
                answer.classList.add('active');
                answer.setAttribute('aria-pressed', 'true');
            }
        });
    }

    if (openButton) {
        openButton.addEventListener('click', open);
    }
    if (noticeButton) {
        noticeButton.addEventListener('click', open);
    }

    if (closeButton) {
        closeButton.addEventListener('click', close);
    }

    if (skipButton) {
        skipButton.addEventListener('click', function () {
            var csrfMeta = document.querySelector('meta[name="csrf-token"]');
            var payload = new URLSearchParams();
            payload.append('csrf_token', csrfMeta ? csrfMeta.getAttribute('content') : '');
            payload.append('action', 'skip');
            fetch('../partials/choice-save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: payload.toString(),
                credentials: 'same-origin'
            }).then(function () {
                close();
            });
        });
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
            var csrfMeta = document.querySelector('meta[name="csrf-token"]');

            payload.append('csrf_token', csrfMeta ? csrfMeta.getAttribute('content') : '');

            Object.keys(answers).forEach(function (questionKey) {
                var value = answers[questionKey];
                if (Array.isArray(value)) {
                    value.forEach(function (item) {
                        payload.append('answers[' + questionKey + '][]', item);
                    });
                } else {
                    payload.append('answers[' + questionKey + ']', value);
                }
            });

            fetch('../partials/choice-save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: payload.toString(),
                credentials: 'same-origin'
            })
                .then(function (response) {
                    return response.text().then(function (text) {
                        var data = null;
                        try {
                            data = JSON.parse(text);
                        } catch (error) {
                            data = null;
                        }
                        return { ok: response.ok, text: text, data: data };
                    });
                })
                .then(function (result) {
                    if (result.ok && result.data && result.data.ok) {
                        setStatus(
                            isFirstTimer
                                ? 'Preferences saved! Organizing your menu...'
                                : 'Preferences saved! Ranking your recommendations...',
                            'is-saved'
                        );

                        showResult(result.data.best_match, result.data.message);

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

                function showResult(bestMatch, message) {
                    while (body.firstChild) {
                        body.removeChild(body.firstChild);
                    }
                    var panel = document.createElement('section');
                    panel.className = 'choice-result';
                    var title = document.createElement('h3');
                    title.textContent = bestMatch ? bestMatch.label : 'No recommendation yet';
                    panel.appendChild(title);
                    if (bestMatch) {
                        var image = document.createElement('img');
                        image.src = bestMatch.image;
                        image.alt = bestMatch.name;
                        panel.appendChild(image);
                    }
                    var text = document.createElement('p');
                    text.textContent = bestMatch
                        ? bestMatch.name + ' matches ' + bestMatch.percent + '% of your preferences.'
                        : message;
                    panel.appendChild(text);
                    if (bestMatch) {
                        var price = document.createElement('p');
                        price.textContent = 'Price: ₱' + Number(bestMatch.price).toFixed(2);
                        panel.appendChild(price);
                        var reasons = document.createElement('p');
                        reasons.textContent = bestMatch.matched.length
                            ? 'Why: ' + bestMatch.matched.join(', ')
                            : 'This is the closest safe match in the current menu.';
                        panel.appendChild(reasons);
                        var menuLink = document.createElement('a');
                        menuLink.className = 'btn-confirm';
                        menuLink.textContent = 'See it on the menu';
                        menuLink.href = '../customer_menu/customermenu.php?product_id='
                            + encodeURIComponent(String(parseInt(bestMatch.product_id, 10)));
                        panel.appendChild(menuLink);
                    } else {
                        var retake = document.createElement('button');
                        retake.type = 'button';
                        retake.className = 'btn-confirm';
                        retake.textContent = 'Retake';
                        retake.addEventListener('click', function () {
                            window.location.reload();
                        });
                        panel.appendChild(retake);
                    }
                    body.appendChild(panel);
                }
        });
    }

    if (modal.dataset.autoOpen === '1') {
        open();
    }
});