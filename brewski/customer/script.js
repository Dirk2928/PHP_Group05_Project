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
    var filterState = { category: 'all' };

    function applyFilters() {
        var visibleCount = 0;

        productCards.forEach(function (card) {
            var matchCategory =
                filterState.category === 'all' ||
                card.dataset.category === filterState.category;

            card.style.display = matchCategory ? '' : 'none';

            if (matchCategory) {
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
    applyFilters();

    /* --- customization modal --------------------------------------------- */

    var modal = document.getElementById('customization-modal');
    var modalProductName = document.getElementById('modal-product-name');
    var modalTotalPrice = document.getElementById('modal-total-price');
    var drinkQuantityValue = document.getElementById('drink-quantity-value');
    var closeModalBtn = document.getElementById('modal-close');
    var confirmAddBtn = document.getElementById('btn-confirm-add');
    var addToCartButtons = document.querySelectorAll('.btn-add');

    if (!modal || !modalProductName || !modalTotalPrice || !drinkQuantityValue || !confirmAddBtn) {
        return;
    }

    var currentProduct = null;
    var MAX_DRINK_QUANTITY = 99;

    function selectedDrinkQuantity() {
        return Math.max(1, Math.min(
            MAX_DRINK_QUANTITY,
            parseInt(drinkQuantityValue.textContent, 10) || 1
        ));
    }

    function activeOption(group) {
        return modal.querySelector(
            '.option-buttons[data-group="' + group + '"] .option-btn.active'
        );
    }

    function activeOptions(group) {
        return Array.prototype.map.call(
            modal.querySelectorAll(
                '.option-buttons[data-group="' + group + '"] .option-btn.active'
            ),
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
        var sizeButton = activeOption('size');

        if (sizeButton) {
            total += parseFloat(sizeButton.dataset.price) || 0;
        }

        modal.querySelectorAll('.addon-option').forEach(function (addonRow) {
            var quantity = parseInt(
                addonRow.querySelector('.addon-quantity__value').textContent,
                10
            ) || 0;
            total += (parseFloat(addonRow.dataset.price) || 0) * quantity;
        });

        return total;
    }

    function updateModalTotal() {
        var quantity = selectedDrinkQuantity();
        modalTotalPrice.textContent = '₱' + (calculateTotal() * quantity).toLocaleString('en-PH');

    }

    function renderProductOptions(options) {
        var groups = {
            temperature: 'temp',
            size: 'size',
            sugar: 'sugar',
            addon: 'addons'
        };

        Object.keys(groups).forEach(function (optionGroup) {
            var container = modal.querySelector(
                '.option-buttons[data-group="' + groups[optionGroup] + '"]'
            );
            var section = modal.querySelector(
                '[data-option-group="' + optionGroup + '"]'
            );
            var choices = options.filter(function (option) {
                return option.group === optionGroup;
            });

            container.textContent = '';
            section.hidden = choices.length === 0;

            choices.forEach(function (choice, index) {
                var price = Number(choice.price) || 0;

                if (optionGroup === 'addon') {
                    var row = document.createElement('div');
                    row.className = 'addon-option';
                    row.dataset.name = choice.name;
                    row.dataset.price = String(price);
                    row.dataset.maxQuantity = String(
                        Math.max(1, parseInt(choice.maxQuantity, 10) || 99)
                    );

                    var description = document.createElement('span');
                    description.className = 'addon-option__description';
                    description.textContent = choice.name + (price > 0
                        ? ' (+₱' + price.toLocaleString('en-PH') + ' each)'
                        : '');

                    var stepper = document.createElement('div');
                    stepper.className = 'quantity-stepper quantity-stepper--addon';

                    var decrease = document.createElement('button');
                    decrease.type = 'button';
                    decrease.className = 'quantity-stepper__button';
                    decrease.dataset.addonAction = 'decrease';
                    decrease.setAttribute('aria-label', 'Remove one ' + choice.name);
                    decrease.textContent = '−';

                    var quantity = document.createElement('output');
                    quantity.className = 'quantity-stepper__value addon-quantity__value';
                    quantity.setAttribute('aria-live', 'polite');
                    quantity.textContent = '0';

                    var increase = document.createElement('button');
                    increase.type = 'button';
                    increase.className = 'quantity-stepper__button';
                    increase.dataset.addonAction = 'increase';
                    increase.setAttribute('aria-label', 'Add one ' + choice.name);
                    increase.textContent = '+';

                    stepper.appendChild(decrease);
                    stepper.appendChild(quantity);
                    stepper.appendChild(increase);
                    row.appendChild(description);
                    row.appendChild(stepper);
                    container.appendChild(row);
                } else {
                    var optionButton = document.createElement('button');
                    optionButton.type = 'button';
                    optionButton.className = 'option-btn';
                    optionButton.dataset.value = choice.name;
                    optionButton.dataset.price = String(price);
                    optionButton.textContent = choice.name + (price > 0
                        ? ' (+₱' + price.toLocaleString('en-PH') + ')'
                        : '');

                    if (index === 0) {
                        optionButton.classList.add('active');
                    }
                    container.appendChild(optionButton);
                }
            });
        });
    }

    function updateAddonQuantity(stepper, action, maxQuantity) {
        var value = stepper.querySelector('.quantity-stepper__value');
        var current = parseInt(value.textContent, 10) || 0;
        var maximum = maxQuantity || 99;
        value.textContent = Math.max(
            0,
            Math.min(maximum, current + (action === 'increase' ? 1 : -1))
        );
    }

    addToCartButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            var card = this.closest('.product-card');
            if (!card) {
                return;
            }

            var options = [];
            try {
                options = JSON.parse(card.dataset.options || '[]');
            } catch (error) {
                console.error('Could not read product customizations:', error);
            }

            currentProduct = {
                id: parseInt(card.dataset.id, 10) || 0,
                name: card.dataset.name,
                image: card.dataset.image || '',
                basePrice: parseFloat(card.dataset.basePrice) || 0,
                options: options
            };

            drinkQuantityValue.textContent = '1';
            renderProductOptions(options);
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

    modal.querySelector('.modal__body').addEventListener('click', function (event) {
        var drinkControl = event.target.closest('[data-drink-action]');
        if (drinkControl) {
            var quantity = selectedDrinkQuantity();
            drinkQuantityValue.textContent = String(Math.max(
                1,
                Math.min(
                    MAX_DRINK_QUANTITY,
                    quantity + (drinkControl.dataset.drinkAction === 'increase' ? 1 : -1)
                )
            ));
            updateModalTotal();
            return;
        }

        var addonControl = event.target.closest('[data-addon-action]');
        if (addonControl && currentProduct) {
            var addonRow = addonControl.closest('.addon-option');
            updateAddonQuantity(
                addonControl.closest('.quantity-stepper'),
                addonControl.dataset.addonAction,
                parseInt(addonRow.dataset.maxQuantity, 10)
            );
            updateModalTotal();
            return;
        }

        var optionButton = event.target.closest('.option-btn');
        if (!optionButton || !currentProduct) {
            return;
        }

        var group = optionButton.parentElement.dataset.group;
        if (group === 'temp' || group === 'size' || group === 'sugar') {
            optionButton.parentElement.querySelectorAll('.option-btn').forEach(function (other) {
                other.classList.remove('active');
            });
            optionButton.classList.add('active');
        }

        updateModalTotal();
    });

    confirmAddBtn.addEventListener('click', function () {
        if (!currentProduct || !window.BrewskiCart) {
            return;
        }

        var parts = [];
        var temperature = activeOptions('temp')[0];
        var sugar = activeOptions('sugar')[0];
        var size = activeOptions('size')[0] || '';
        var addons = [];

        if (temperature) {
            parts.push(temperature);
        }
        if (sugar) {
            parts.push(sugar + ' sugar');
        }

        modal.querySelectorAll('.addon-option').forEach(function (addonRow) {
            var quantity = parseInt(
                addonRow.querySelector('.addon-quantity__value').textContent,
                10
            ) || 0;
            if (quantity > 0) {
                parts.push(addonRow.dataset.name + ' ×' + quantity);
                addons.push({
                    name: addonRow.dataset.name,
                    quantity: quantity
                });
            }
        });

        window.BrewskiCart.add({
            productId: currentProduct.id,
            name: currentProduct.name,
            image: currentProduct.image,
            size: size,
            customization: parts.join(', '),
            customizationOptions: {
                temperature: temperature || '',
                size: size,
                sugar: sugar || '',
                addons: addons
            },
            price: calculateTotal(),
            quantity: selectedDrinkQuantity()
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
