document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var modal = document.getElementById('choice-modal');

    if (!modal) {
        return;
    }

    var body = modal.querySelector('.modal__body');
    var closeButton = document.getElementById('choice-close');
    var saveButton = document.getElementById('choice-save');
    var saveLabel = document.getElementById('choice-save-label');
    var status = document.getElementById('choice-status');
    var title = document.getElementById('choice-modal-title');
    var subtitle = document.getElementById('choice-modal-subtitle');
    var openButton = document.getElementById('choice-open');
    var skipButton = document.getElementById('choice-skip');
    var noticeButton = document.getElementById('choice-open-notice');
    var panels = {};
    Array.prototype.forEach.call(
        modal.querySelectorAll('[data-choice-panel]'),
        function (panel) {
            panels[panel.dataset.choicePanel] = panel;
        }
    );
    var currentMode = modal.dataset.choiceMode === 'recommendation'
        ? 'recommendation'
        : 'first-timer';

    var copy = {
        'first-timer': {
            title: 'Get a drink recommendation',
            subtitle: 'Answer a few quick questions and we will pick one drink for you.',
            button: 'Recommend a drink'
        },
        recommendation: {
            title: 'Set up your drink preferences',
            subtitle: 'Answer a few questions to sort your menu by compatibility.',
            button: 'Find my best matches'
        }
    };

    function activePanel() {
        return panels[currentMode] || null;
    }

    function questions() {
        var panel = activePanel();

        return panel
            ? Array.prototype.slice.call(panel.querySelectorAll('[data-choice-question]'))
            : [];
    }

    function resetRecommendationAnswers() {
        var panel = panels.recommendation;

        if (!panel) {
            return;
        }

        Array.prototype.forEach.call(
            panel.querySelectorAll('.choice-answer.active'),
            function (answer) {
                answer.classList.remove('active');
                answer.setAttribute('aria-pressed', 'false');
            }
        );
    }

    function setStatus(message, modifier) {
        if (!status) {
            return;
        }
        status.textContent = message;
        status.className = 'choice-status' + (modifier ? ' ' + modifier : '');
    }

    function setMode(mode) {
        currentMode = mode === 'recommendation' ? 'recommendation' : 'first-timer';

        Object.keys(panels).forEach(function (key) {
            panels[key].hidden = key !== currentMode;
        });

        title.textContent = copy[currentMode].title;
        subtitle.textContent = copy[currentMode].subtitle;
        saveLabel.textContent = copy[currentMode].button;

        if (skipButton) {
            skipButton.hidden = currentMode !== 'recommendation';
        }
    }

    function open(mode) {
        setMode(mode || currentMode);
        if (currentMode === 'first-timer') {
            resetRecommendationAnswers();
        }
        setStatus('', '');
        if (status) {
            status.hidden = false;
        }
        if (saveButton) {
            saveButton.hidden = false;
            saveButton.disabled = false;
        }
        modal.classList.add('show');
    }

    function close() {
        modal.classList.remove('show');
    }

    function selectedAnswers() {
        var answers = {};

        questions().forEach(function (question) {
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

    function triggerMode(element, fallback) {
        var mode = element && element.dataset ? element.dataset.choiceMode : '';

        return mode === 'first-timer' || mode === 'recommendation' ? mode : fallback;
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
        openButton.addEventListener('click', function () {
            open(triggerMode(openButton, 'first-timer'));
        });
    }
    if (noticeButton) {
        noticeButton.addEventListener('click', function () {
            open(triggerMode(noticeButton, 'recommendation'));
        });
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
            payload.append('mode', currentMode);
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
            var answered = selectedAnswers();
            var total = questions().length;

            if (Object.keys(answered).length < total) {
                setStatus('Please answer all ' + total + ' questions.', 'is-error');
                return;
            }

            setStatus('Saving...', '');
            saveButton.disabled = true;

            var payload = new URLSearchParams();
            var csrfMeta = document.querySelector('meta[name="csrf-token"]');

            payload.append('csrf_token', csrfMeta ? csrfMeta.getAttribute('content') : '');
            payload.append('mode', currentMode);

            Object.keys(answered).forEach(function (questionKey) {
                var value = answered[questionKey];
                if (Array.isArray(value)) {
                    value.forEach(function (item) {
                        payload.append('answers[' + questionKey + '][]', item);
                    });
                } else {
                    payload.append('answers[' + questionKey + ']', value);
                }
            });

            var submittedMode = currentMode;

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
                        if (submittedMode === 'first-timer') {
                            var match = result.data.best_match;

                            if (match && match.product_id) {
                                window.location.href = '../customer_menu/customermenu.php?product_id='
                                    + encodeURIComponent(String(parseInt(match.product_id, 10)));

                                return true;
                            }

                            setStatus('No recommendation is available right now.', 'is-error');
                            saveButton.disabled = false;

                            return false;
                        }

                        window.location.href = '../customer_home/customerhome.php';

                        return true;
                    }

                    setStatus(
                        result.data && result.data.message
                            ? result.data.message
                            : (result.ok && result.text.indexOf('<') === -1
                                ? result.text
                                : 'Could not complete the request. Please try again.'),
                        'is-error'
                    );
                    saveButton.disabled = false;

                    return false;
                })
                .catch(function () {
                    setStatus('Could not complete the request. Please try again.', 'is-error');
                    saveButton.disabled = false;
                });
        });
    }

    setMode(currentMode);

    if (modal.dataset.autoOpen === '1') {
        open('recommendation');
    }
});
