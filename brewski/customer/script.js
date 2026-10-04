/*
 * script.js — page behaviour shared by the customer pages.
 *
 * Covers: icon rendering, the profile dropdown, the menu filters, and the
 * customization modal. Everything cart-related lives in cart.js, which must be
 * loaded first — adding to the cart goes through window.BrewskiCart.
 *
 * Each feature is guarded on the elements it needs, so a page can include this
 * without having filters or a product grid.
 */
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    if (window.lucide) {
        window.lucide.createIcons();
    }


    /* --- profile dropdown ------------------------------------------------ */

    var profileMenu = document.querySelector('.profile-menu');
    var profileButton = document.getElementById('profile-button');

    if (profileMenu && profileButton) {

        profileButton.addEventListener('click', function (event) {
            event.stopPropagation();

            var isOpen = profileMenu.classList.toggle('open');
            profileButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });

        document.addEventListener('click', function (event) {
            if (!profileMenu.contains(event.target)) {
                profileMenu.classList.remove('open');
                profileButton.setAttribute('aria-expanded', 'false');
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                profileMenu.classList.remove('open');
                profileButton.setAttribute('aria-expanded', 'false');
            }
        });
    }


    /* --- menu filters (menu page only) ----------------------------------- */

    var productCards = document.querySelectorAll('.product-card');
    var noResults = document.getElementById('no-results');
    var filterState = { category: 'all', temp: 'all' };

    function applyFilters() {
        var visibleCount = 0;

        productCards.forEach(function (card) {
            var matchCategory =
                filterState.category === 'all' ||
                card.dataset.category === filterState.category;

            var matchTemp =
                filterState.temp === 'all' ||
                card.dataset.temp === filterState.temp;

            var show = matchCategory && matchTemp;

            card.style.display = show ? '' : 'none';

            if (show) {
                visibleCount++;
            }
        });

        if (noResults) {
            noResults.style.display = visibleCount === 0 ? 'block' : 'none';
        }
    }

    function setupFilterGroup(containerId, stateKey) {
        var container = document.getElementById(containerId);

        if (!container) {
            return;
        }

        container.querySelectorAll('.filter-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                container.querySelectorAll('.filter-btn').forEach(function (other) {
                    other.classList.remove('active');
                });

                btn.classList.add('active');

                filterState[stateKey] = btn.dataset.filter;
                applyFilters();
            });
        });
    }

    setupFilterGroup('category-filters', 'category');
    setupFilterGroup('temp-filters', 'temp');


    /* --- customization modal --------------------------------------------- */

    var modal = document.getElementById('customization-modal');
    var modalProductName = document.getElementById('modal-product-name');
    var modalTotalPrice = document.getElementById('modal-total-price');
    var closeModalBtn = document.getElementById('modal-close');
    var confirmAddBtn = document.getElementById('btn-confirm-add');
    var specialInstructionsInput = document.getElementById('special-instructions');
    var optionButtons = document.querySelectorAll('.option-btn');
    var addToCartButtons = document.querySelectorAll('.btn-add');

    if (!modal || !modalProductName || !modalTotalPrice || !confirmAddBtn) {
        return;
    }

    var currentProduct = null;

    function activeOption(group) {
        return document.querySelector('.option-buttons[data-group="' + group + '"] .option-btn.active');
    }

    function activeOptions(group) {
        return Array.prototype.map.call(
            document.querySelectorAll('.option-buttons[data-group="' + group + '"] .option-btn.active'),
            function (option) {
                return option.dataset.value;
            }
        );
    }

    function calculateTotal() {
        if (!currentProduct) {
            return 0;
        }

        var total = currentProduct.basePrice;
        var sizeBtn = activeOption('size');

        if (sizeBtn) {
            total += parseFloat(sizeBtn.dataset.price) || 0;
        }

        currentProduct.addons.forEach(function (addon) {
            total += addon.price;
        });

        return total;
    }

    function updateModalTotal() {
        modalTotalPrice.textContent = '₱' + calculateTotal();
    }

    function resetModalSelections(defaultTemp) {
        ['temp', 'size', 'sugar', 'addons'].forEach(function (group) {
            document.querySelectorAll('.option-buttons[data-group="' + group + '"] .option-btn').forEach(function (btn) {
                btn.classList.remove('active');
            });
        });

        var defaults = {
            temp: defaultTemp || 'Hot',
            size: 'Regular',
            sugar: '100%'
        };

        Object.keys(defaults).forEach(function (group) {
            var target = document.querySelector(
                '.option-buttons[data-group="' + group + '"] [data-value="' + defaults[group] + '"]'
            );

            if (target) {
                target.classList.add('active');
            }
        });

        if (specialInstructionsInput) {
            specialInstructionsInput.value = '';
        }
    }

    addToCartButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            var card = this.closest('.product-card');

            if (!card) {
                return;
            }

            var defaultTemp = card.dataset.temp === 'iced' ? 'Iced' : 'Hot';

            currentProduct = {
                name: card.dataset.name,
                image: card.dataset.image || '',
                basePrice: parseFloat(card.dataset.basePrice) || 0,
                temp: defaultTemp,
                size: 'Regular',
                sugar: '100%',
                addons: [],
                instructions: ''
            };

            resetModalSelections(defaultTemp);

            modalProductName.textContent = 'Customize: ' + currentProduct.name;

            updateModalTotal();
            modal.classList.add('show');
        });
    });

    if (closeModalBtn) {
        closeModalBtn.addEventListener('click', function () {
            modal.classList.remove('show');
        });
    }

    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            modal.classList.remove('show');
        }
    });

    optionButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            if (!currentProduct) {
                return;
            }

            var group = this.parentElement.dataset.group;
            var value = this.dataset.value;
            var price = parseFloat(this.dataset.price) || 0;

            if (group === 'addons') {
                this.classList.toggle('active');

                if (this.classList.contains('active')) {
                    currentProduct.addons.push({ name: value, price: price });
                } else {
                    currentProduct.addons = currentProduct.addons.filter(function (addon) {
                        return addon.name !== value;
                    });
                }
            } else if (group === 'temp' || group === 'size' || group === 'sugar') {
                this.parentElement.querySelectorAll('.option-btn').forEach(function (other) {
                    other.classList.remove('active');
                });

                this.classList.add('active');

                if (group === 'temp') {
                    currentProduct.temp = value;
                } else if (group === 'size') {
                    currentProduct.size = value;
                } else {
                    currentProduct.sugar = value;
                }
            }

            updateModalTotal();
        });
    });

    confirmAddBtn.addEventListener('click', function () {
        if (!currentProduct || !window.BrewskiCart) {
            return;
        }

        var parts = [];
        var temp = activeOptions('temp')[0];
        var sugar = activeOptions('sugar')[0];
        var size = activeOptions('size')[0] || '';
        var note = specialInstructionsInput ? specialInstructionsInput.value.trim() : '';

        if (temp) {
            parts.push(temp);
        }

        if (sugar) {
            parts.push(sugar + ' sugar');
        }

        activeOptions('addons').forEach(function (addon) {
            parts.push(addon);
        });

        if (note !== '') {
            parts.push(note);
        }

        window.BrewskiCart.add({
            name: currentProduct.name,
            image: currentProduct.image,
            size: size,
            customization: parts.join(', '),
            price: calculateTotal()
        });

        var originalHTML = confirmAddBtn.innerHTML;

        confirmAddBtn.innerHTML = '<i data-lucide="check"></i> Added!';
        confirmAddBtn.style.backgroundColor = 'var(--mocha)';

        if (window.lucide) {
            window.lucide.createIcons();
        }

        setTimeout(function () {
            confirmAddBtn.innerHTML = originalHTML;
            confirmAddBtn.style.backgroundColor = '';
            modal.classList.remove('show');

            if (window.lucide) {
                window.lucide.createIcons();
            }
        }, 1000);
    });
});
