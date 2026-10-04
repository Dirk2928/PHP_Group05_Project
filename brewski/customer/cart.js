/*
 * cart.js — the one cart implementation, shared by every customer page.
 *
 * There used to be two, writing to different localStorage keys with different
 * item shapes (brewskiCart in script.js, brewski_cart in the menu profile
 * script), so a drink added on Home never appeared in the Menu cart. This file
 * merges them onto brewski_cart and the richer item shape.
 *
 * Item: { key, name, image, size, customization, price, quantity }
 *
 * Public API — window.BrewskiCart:
 *     add(item)    add one unit, merging into a matching line
 *     load()       current items
 *     save(items)  replace items and re-render
 *     count()      total quantity
 *     render()     redraw badge + dropdown
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'brewski_cart';
    var MAX_QTY = 99;

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
    }

    function makeKey(name, size, customization) {
        return [name, size, customization].join('|');
    }

    function add(item) {
        var items = load();
        var key = makeKey(item.name, item.size || '', item.customization || '');
        var existing = null;

        items.forEach(function (entry) {
            if (entry.key === key) {
                existing = entry;
            }
        });

        if (existing) {
            existing.quantity = Math.min(MAX_QTY, existing.quantity + 1);
        } else {
            items.push({
                key: key,
                name: item.name,
                image: item.image || '',
                size: item.size || '',
                customization: item.customization || '',
                price: Number(item.price) || 0,
                quantity: 1
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
                '<div>Quantity</div>' +
                '<div>Price</div>' +
                '<div></div>' +
            '</div>' +
            '<div class="cart-list">';

        items.forEach(function (item) {
            var label = item.name + (item.size ? ' (' + item.size + ')' : '');

            html +=
                '<div class="cart-row" data-key="' + escapeHtml(item.key) + '">' +
                    '<div class="cart-drink">' +
                        '<img src="' + escapeHtml(item.image) + '" alt="' + escapeHtml(item.name) + '">' +
                        '<span>' + escapeHtml(label) + '</span>' +
                    '</div>' +
                    '<div class="cart-custom">' + escapeHtml(item.customization || 'None') + '</div>' +
                    '<div class="cart-qty">' +
                        '<button type="button" class="cart-qty__btn" data-action="decrease" aria-label="Decrease quantity"><i data-lucide="minus"></i></button>' +
                        '<span class="cart-qty__value">' + (Number(item.quantity) || 0) + '</span>' +
                        '<button type="button" class="cart-qty__btn" data-action="increase" aria-label="Increase quantity"><i data-lucide="plus"></i></button>' +
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

                // TODO: checkout page not built yet.
                '<a href="../customer_checkout/checkout.php" class="cart-checkout">Proceed to check out</a>' +
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
    }

    // Keep several tabs, and the back/forward cache, in step.
    window.addEventListener('storage', render);
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
})();
