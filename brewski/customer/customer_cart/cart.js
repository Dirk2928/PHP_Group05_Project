(function () {
    'use strict';

    var STORAGE_KEY = 'brewski_cart';
    var MAX_QTY = 99;
    var sessionUrl = document.querySelector('meta[name="cart-session-url"]');
    var checkoutUrl = document.querySelector('meta[name="checkout-url"]');
    var csrfToken = document.querySelector('meta[name="csrf-token"]');
    var syncEnabled = document.querySelector('meta[name="cart-sync-enabled"]');
    var pendingSync = Promise.resolve();

    var menu = document.getElementById('cart-menu');
    var button = document.getElementById('cart-button');
    var body = document.getElementById('cart-body');

    function load() {
        try {
            var data = JSON.parse(localStorage.getItem(STORAGE_KEY));
            return Array.isArray(data) ? data : [];
        } catch (error) {
            return [];
        }
    }

    function persist(items) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(items));
        } catch (error) {
            console.warn('Could not save cart:', error);
        }
    }

    function save(items) {
        persist(items);
        render();
        syncWithDatabase(items).catch(function (error) {
            console.error('Could not sync cart with checkout:', error);
        });
    }

    function syncWithDatabase(items) {
        if (!sessionUrl || !csrfToken || !syncEnabled || syncEnabled.content !== '1') {
            return Promise.resolve();
        }

        pendingSync = pendingSync.catch(function () {}).then(function () {
            var controller = new AbortController();
            var timeout = window.setTimeout(function () {
                controller.abort();
            }, 5000);

            return fetch(sessionUrl.content, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken.content
                },
                body: JSON.stringify({ items: items }),
                signal: controller.signal
            }).then(function (response) {
                return response.json().then(function (result) {
                    if (!response.ok || !result.ok) {
                        throw new Error(result.error || 'Cart synchronization failed.');
                    }
                });
            }).catch(function (error) {
                if (error.name === 'AbortError') {
                    throw new Error('Cart synchronization timed out.');
                }
                throw error;
            }).finally(function () {
                window.clearTimeout(timeout);
            });
        });

        return pendingSync;
    }

    function makeKey(name, size, customization) {
        return [name, size, customization].join('|');
    }

    function add(item) {
        var items = load();
        var key = makeKey(item.name, item.size || '', item.customization || '');
        var quantity = Math.max(1, Math.min(MAX_QTY, parseInt(item.quantity, 10) || 1));
        var existing = null;

        items.forEach(function (entry) {
            if (entry.key === key) {
                existing = entry;
            }
        });

        if (existing) {
            existing.quantity = Math.min(MAX_QTY, (Number(existing.quantity) || 0) + quantity);
            existing.productId = Number(item.productId) || existing.productId || 0;
            existing.customizationOptions = item.customizationOptions || existing.customizationOptions || {};
            existing.price = Number(item.price) || existing.price || 0;
        } else {
            items.push({
                key: key,
                productId: Number(item.productId) || 0,
                name: item.name,
                image: item.image || '',
                size: item.size || '',
                customization: item.customization || '',
                customizationOptions: item.customizationOptions || {},
                price: Number(item.price) || 0,
                quantity: quantity
            });
        }
        save(items);
    }

    function count() {
        return load().reduce(function (sum, item) {
            return sum + (Number(item.quantity) || 0);
        }, 0);
    }

    function money(amount) {
        return '₱' + Number(amount).toLocaleString('en-PH');
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function renderBadge(total) {
        document.querySelectorAll('[data-cart-count]').forEach(function (element) {
            element.textContent = total;
        });
    }

    function render() {
        var items = load();
        var total = 0;
        var subtotal = 0;

        items.forEach(function (item) {
            total += Number(item.quantity) || 0;
            subtotal += (Number(item.price) || 0) * (Number(item.quantity) || 0);
        });

        renderBadge(total);

        if (!body) {
            return;
        }

        if (!items.length) {
            body.innerHTML = '<p class="cart-empty">Your cart is empty.</p>';
            return;
        }

        var html =
            '<div class="cart-row cart-row--head">' +
                '<div>Drink</div>' +
                '<div>Customization</div>' +
                '<div>Drinks</div>' +
                '<div>Price</div>' +
                '<div></div>' +
            '</div>' +
            '<div class="cart-list">';

        items.forEach(function (item) {
            var label = item.name + (item.size ? ' (' + item.size + ')' : '');
            var customization = item.customization
                ? 'Per drink: ' + item.customization
                : 'No extras per drink';

            html +=
                '<div class="cart-row" data-key="' + escapeHtml(item.key) + '">' +
                    '<div class="cart-drink">' +
                        '<img src="' + escapeHtml(item.image) + '" alt="' + escapeHtml(item.name) + '">' +
                        '<span>' + escapeHtml(label) + '</span>' +
                    '</div>' +
                    '<div class="cart-custom">' + escapeHtml(customization) + '</div>' +
                    '<div class="cart-qty">' +
                        '<button type="button" class="cart-qty__btn" data-action="decrease" aria-label="Remove one drink"><i data-lucide="minus"></i></button>' +
                        '<span class="cart-qty__value">' + (Number(item.quantity) || 0) + '</span>' +
                        '<button type="button" class="cart-qty__btn" data-action="increase" aria-label="Add one identical drink"><i data-lucide="plus"></i></button>' +
                    '</div>' +
                    '<div class="cart-price">' + money((Number(item.price) || 0) * (Number(item.quantity) || 0)) + '</div>' +
                    '<div class="cart-remove">' +
                        '<button type="button" class="cart-remove__btn" data-action="remove" aria-label="Remove item"><i data-lucide="trash-2"></i></button>' +
                    '</div>' +
                '</div>';
        });

        html +=
            '</div>' +
            '<div class="cart-summary">' +
                '<div class="cart-summary__line"><span>Subtotal</span><span>' + money(subtotal) + '</span></div>' +
                '<div class="cart-summary__line cart-summary__line--total"><span>Total</span><span>' + money(subtotal) + '</span></div>' +

                '<a href="' + escapeHtml(checkoutUrl ? checkoutUrl.content : '../checkout/checkout.php') + '" class="cart-checkout">Proceed to checkout</a>' +
            '</div>';

        body.innerHTML = html;

        if (window.lucide) {
            window.lucide.createIcons();
        }
    }

    function setOpen(open) {
        if (!menu || !button) {
            return;
        }

        menu.classList.toggle('open', open);
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    if (menu && button && body) {

        button.addEventListener('click', function (event) {
            event.stopPropagation();
            setOpen(!menu.classList.contains('open'));
        });

        document.addEventListener('click', function (event) {
            if (!menu.contains(event.target)) {
                setOpen(false);
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        });

        body.addEventListener('click', function (event) {
            var target = event.target.closest('[data-action]');

            if (!target) {
                return;
            }

            var row = target.closest('[data-key]');

            if (!row) {
                return;
            }

            var items = load();
            var action = target.dataset.action;

            if (action === 'remove') {
                items = items.filter(function (entry) {
                    return entry.key !== row.dataset.key;
                });
            } else {
                items.forEach(function (entry) {
                    if (entry.key !== row.dataset.key) {
                        return;
                    }

                    if (action === 'increase') {
                        entry.quantity = Math.min(MAX_QTY, (Number(entry.quantity) || 0) + 1);
                    } else if (action === 'decrease') {
                        entry.quantity = Math.max(1, (Number(entry.quantity) || 0) - 1);
                    }
                });
            }

            save(items);
        });

        body.addEventListener('click', function (event) {
            var checkoutLink = event.target.closest('.cart-checkout');
            if (!checkoutLink || !syncEnabled || syncEnabled.content !== '1') {
                return;
            }

            event.preventDefault();
            checkoutLink.setAttribute('aria-disabled', 'true');
            checkoutLink.textContent = 'Saving your order...';
            syncWithDatabase(load()).then(function () {
                var checkoutDestination = new URL(checkoutLink.href);
                checkoutDestination.searchParams.set('cart_synced', '1');
                window.location.assign(checkoutDestination.href);
            }).catch(function (error) {
                console.error('Could not sync cart with checkout:', error);
                window.alert(error.message);
                checkoutLink.removeAttribute('aria-disabled');
                checkoutLink.textContent = 'Proceed to checkout';
            });
        });
    }

    window.addEventListener('storage', function () {
        render();
        syncWithDatabase(load()).catch(function (error) {
            console.error('Could not sync cart with checkout:', error);
        });
    });
    window.addEventListener('pageshow', render);

    window.BrewskiCart = {
        STORAGE_KEY: STORAGE_KEY,
        load: load,
        save: save,
        add: add,
        count: count,
        render: render
    };

    render();
    var isCheckoutPage = checkoutUrl
        && new URL(checkoutUrl.content, window.location.href).pathname === window.location.pathname;
    if (!isCheckoutPage) {
        syncWithDatabase(load()).catch(function (error) {
            console.error('Could not sync cart with checkout:', error);
        });
    }
})();
