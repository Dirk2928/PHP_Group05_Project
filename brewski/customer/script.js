window.brewskiImageFallback = function (image) {
    image.onerror = null;
    image.src = 'data:image/svg+xml;utf8,' + encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a2886a" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' +
        '<path d="M4 8h14v8a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4V8z"></path>' +
        '<path d="M18 10h1a3 3 0 0 1 0 6h-1"></path>' +
        '<path d="M2 22h20"></path>' +
        '<path d="M10 2v3"></path>' +
        '<path d="M14 2v3"></path>' +
        '</svg>'
    );
    image.classList.add('is-placeholder');
};

document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var sessionTimeout = document.querySelector('meta[name="session-timeout-seconds"]');
    var logoutUrl = document.querySelector('meta[name="logout-url"]');
    var timeoutSeconds = sessionTimeout ? Number(sessionTimeout.content) : 0;

    if (timeoutSeconds > 0 && logoutUrl && logoutUrl.content) {
        window.setTimeout(function () {
            window.location.assign(logoutUrl.content);
        }, timeoutSeconds * 1000);
    }

    if (window.lucide) {
        window.lucide.createIcons();
    }

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

    var productCards = document.querySelectorAll('.product-card');
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (productCards.length && 'IntersectionObserver' in window && !reduceMotion) {
        document.documentElement.classList.add('has-scroll-reveal');

        var revealObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-revealed');
                } else {
                    entry.target.classList.remove('is-revealed');
                }
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -6% 0px' });

        productCards.forEach(function (card) {
            revealObserver.observe(card);
        });
    }

    var noResults = document.getElementById('no-results');
    var menuPageInfo = document.getElementById('menuPageInfo');
    var menuPagination = document.getElementById('menuPagination');
    var filterState = { category: 'all' };
    var menuPageSize = 5;
    var menuPage = 1;

    function matchingCards() {
        return Array.prototype.filter.call(productCards, function (card) {
            var matchesCategory = filterState.category === 'all' ||
                card.dataset.category === filterState.category;
            return matchesCategory;
        });
    }

    function menuPageButton(label, disabled, active, handler) {
        var item = document.createElement('button');
        item.type = 'button';
        item.className = 'menu-page' + (active ? ' active' : '');
        item.textContent = label;
        item.disabled = disabled;
        item.addEventListener('click', handler);
        return item;
    }

    function renderMenuPagination(pageCount) {
        if (!menuPagination) {
            return;
        }

        menuPagination.innerHTML = '';

        menuPagination.appendChild(menuPageButton('Previous', menuPage === 1, false, function () {
            menuPage--;
            applyFilters();
        }));

        var firstPage = Math.max(1, menuPage - 1);
        var lastPage = Math.min(pageCount, firstPage + 2);
        firstPage = Math.max(1, lastPage - 2);

        for (var page = firstPage; page <= lastPage; page++) {
            (function (target) {
                menuPagination.appendChild(menuPageButton(String(target), false, target === menuPage, function () {
                    menuPage = target;
                    applyFilters();
                }));
            })(page);
        }

        menuPagination.appendChild(menuPageButton('Next', menuPage === pageCount, false, function () {
            menuPage++;
            applyFilters();
        }));
    }

    function applyFilters() {
        var matches = matchingCards();
        var shown = matches;

        if (menuPagination) {
            var pageCount = Math.max(1, Math.ceil(matches.length / menuPageSize));
            menuPage = Math.min(menuPage, pageCount);
            var start = (menuPage - 1) * menuPageSize;
            var end = Math.min(start + menuPageSize, matches.length);

            shown = matches.slice(start, end);

            if (menuPageInfo) {
                menuPageInfo.textContent = matches.length === 0
                    ? 'No results'
                    : 'Showing ' + (start + 1) + '–' + end + ' of ' + matches.length + ' drinks';
            }

            renderMenuPagination(pageCount);
        }

        productCards.forEach(function (card) {
            card.style.display = 'none';
        });

        shown.forEach(function (card) {
            card.style.display = '';
        });

        if (noResults) {
            noResults.style.display = matches.length === 0 ? 'block' : 'none';
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
                menuPage = 1;
                applyFilters();
            });
        });
    }

    setupFilterGroup('category-filters', 'category');

    applyFilters();

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

    var requestedProduct = parseInt(
        new URLSearchParams(window.location.search).get('product_id'),
        10
    );

    if (requestedProduct > 0) {
        var requestedCard = Array.prototype.find.call(productCards, function (card) {
            return parseInt(card.dataset.id, 10) === requestedProduct;
        });

        if (requestedCard) {
            var categoryFilter = document.querySelector(
                '#category-filters .filter-btn[data-filter="' + requestedCard.dataset.category + '"]'
            );

            if (categoryFilter) {
                categoryFilter.click();
            }

            var requestedIndex = matchingCards().indexOf(requestedCard);

            if (requestedIndex >= 0) {
                menuPage = Math.floor(requestedIndex / menuPageSize) + 1;
                applyFilters();
            }

            Array.prototype.forEach.call(
                requestedCard.querySelectorAll('.product-card__match'),
                function (badge) {
                    badge.remove();
                }
            );

            var recommendedBadge = document.createElement('span');
            recommendedBadge.className = 'product-card__match';
            recommendedBadge.textContent = 'Recommended drink';
            requestedCard.insertBefore(recommendedBadge, requestedCard.firstChild);

            requestedCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    if (!menuPagination) {
        document.querySelectorAll('.category .product-grid').forEach(function (grid) {
            var cards = Array.prototype.slice.call(grid.querySelectorAll('.product-card'));
            var shown = 5;

            if (cards.length <= shown) {
                return;
            }

            var more = document.createElement('button');
            more.type = 'button';
            more.className = 'see-more';
            more.textContent = 'See more';
            grid.parentNode.insertBefore(more, grid.nextSibling);

            var render = function () {
                cards.forEach(function (card, index) {
                    card.style.display = index < shown ? '' : 'none';
                });
                more.hidden = shown >= cards.length;
            };

            more.addEventListener('click', function () {
                shown += 5;
                render();
            });

            render();
        });
    }
});
