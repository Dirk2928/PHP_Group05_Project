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
    var currentMode = modal.dataset.choiceMode === 'first-timer'
        ? 'first-timer'
        : 'recommendation';
    var resultPanel = null;

    var copy = {
        'first-timer': {
            title: 'Set up your drink preferences',
            subtitle: 'Answer a few questions to sort your menu by compatibility.',
            button: 'Sort my menu',
            saved: 'Preferences saved! Ranking every drink for you...'
        },
        recommendation: {
            title: 'Get a drink recommendation',
            subtitle: 'Answer a few quick questions and we will pick one drink for you.',
            button: 'Recommend a drink',
            saved: 'Finding the best drink for your mood...'
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

    function clearResult() {
        if (resultPanel && resultPanel.parentNode) {
            resultPanel.parentNode.removeChild(resultPanel);
        }
        resultPanel = null;
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
        currentMode = mode === 'first-timer' ? 'first-timer' : 'recommendation';

        Object.keys(panels).forEach(function (key) {
            panels[key].hidden = key !== currentMode;
        });

        title.textContent = copy[currentMode].title;
        subtitle.textContent = copy[currentMode].subtitle;
        saveLabel.textContent = copy[currentMode].button;

        if (skipButton) {
            skipButton.hidden = currentMode !== 'first-timer';
        }
    }

    function open(mode) {
        setMode(mode || currentMode);
        if (currentMode === 'recommendation') {
            resetRecommendationAnswers();
        }
        clearResult();
        setStatus('', '');
        if (status) {
            status.hidden = false;
        }
        if (saveButton) {
            saveButton.hidden = false;
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
            open(triggerMode(openButton, 'recommendation'));
        });
    }
    if (noticeButton) {
        noticeButton.addEventListener('click', function () {
            open(triggerMode(noticeButton, 'first-timer'));
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

    function productLink(productId, label, className) {
        var link = document.createElement('a');
        link.className = className;
        link.textContent = label;
        link.href = '../customer_menu/customermenu.php?product_id='
            + encodeURIComponent(String(parseInt(productId, 10)));

        return link;
    }

    function hidePanelsForResult() {
        Object.keys(panels).forEach(function (key) {
            panels[key].hidden = true;
        });
        if (status) {
            status.hidden = true;
        }
        if (saveButton) {
            saveButton.hidden = true;
        }
        if (skipButton) {
            skipButton.hidden = true;
        }
    }

    function showRanking(ranking, message) {
        hidePanelsForResult();
        clearResult();
        resultPanel = document.createElement('section');
        resultPanel.className = 'choice-result';

        var heading = document.createElement('h3');
        heading.textContent = 'Your drink ranking';
        resultPanel.appendChild(heading);

        if (!ranking || ranking.length === 0) {
            var empty = document.createElement('p');
            empty.textContent = message;
            resultPanel.appendChild(empty);
            body.appendChild(resultPanel);

            return;
        }

        var intro = document.createElement('p');
        intro.textContent = 'Sorted from greatest to least match with your preferences.';
        resultPanel.appendChild(intro);

        var list = document.createElement('ol');
        list.className = 'choice-ranking';

        ranking.forEach(function (drink) {
            var item = document.createElement('li');
            item.className = 'choice-ranking__item';

            var image = document.createElement('img');
            image.src = drink.image;
            image.alt = drink.name;
            item.appendChild(image);

            var info = document.createElement('div');
            info.className = 'choice-ranking__info';

            var name = document.createElement('strong');
            name.textContent = '#' + drink.rank + ' ' + drink.name;
            info.appendChild(name);

            var match = document.createElement('span');
            match.textContent = drink.percent + '% match';
            info.appendChild(match);

            if (drink.matched && drink.matched.length) {
                var reasons = document.createElement('small');
                reasons.textContent = 'Why: ' + drink.matched.join(', ');
                info.appendChild(reasons);
            }

            item.appendChild(info);
            item.appendChild(
                productLink(drink.product_id, 'View', 'btn-link')
            );
            list.appendChild(item);
        });

        resultPanel.appendChild(list);

        var home = document.createElement('a');
        home.className = 'btn-confirm choice-result__home';
        home.textContent = 'Back to my menu';
        home.href = modal.dataset.homeUrl || '../customer_home/customerhome.php';
        resultPanel.appendChild(home);
        body.appendChild(resultPanel);
    }

    function showRecommendation(bestMatch, message) {
        hidePanelsForResult();
        clearResult();
        resultPanel = document.createElement('section');
        resultPanel.className = 'choice-result';

        var heading = document.createElement('h3');
        heading.textContent = bestMatch ? bestMatch.label : 'No recommendation yet';
        resultPanel.appendChild(heading);

        if (bestMatch) {
            var image = document.createElement('img');
            image.src = bestMatch.image;
            image.alt = bestMatch.name;
            resultPanel.appendChild(image);
        }

        var text = document.createElement('p');
        text.textContent = bestMatch
            ? bestMatch.name + ' matches ' + bestMatch.percent + '% of your answers.'
            : message;
        resultPanel.appendChild(text);

        if (bestMatch) {
            var price = document.createElement('p');
            price.textContent = 'Price: ₱' + Number(bestMatch.price).toFixed(2);
            resultPanel.appendChild(price);

            var reasons = document.createElement('p');
            reasons.textContent = bestMatch.matched.length
                ? 'Why: ' + bestMatch.matched.join(', ')
                : 'This is the closest match in the current menu.';
            resultPanel.appendChild(reasons);
            resultPanel.appendChild(
                productLink(bestMatch.product_id, 'See it on the menu', 'btn-confirm')
            );
        } else {
            var retake = document.createElement('button');
            retake.type = 'button';
            retake.className = 'btn-confirm';
            retake.textContent = 'Try again';
            retake.addEventListener('click', function () {
                setMode('recommendation');
                resetRecommendationAnswers();
                clearResult();
                setStatus('', '');
                if (status) {
                    status.hidden = false;
                }
                if (saveButton) {
                    saveButton.hidden = false;
                }
            });
            resultPanel.appendChild(retake);
        }

        body.appendChild(resultPanel);
    }

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
                        setStatus(copy[submittedMode].saved, 'is-saved');

                        if (submittedMode === 'first-timer') {
                            showRanking(result.data.ranking, result.data.message);
                        } else {
                            showRecommendation(result.data.best_match, result.data.message);
                        }
                        saveButton.disabled = false;

                        return true;
                    }

                    setStatus(
                        result.ok && result.text.indexOf('<') === -1
                            ? result.text
                            : 'Could not complete the request. Please log in again and try.',
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
        open('first-timer');
    }
});
