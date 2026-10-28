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





    function makeKey(name, size, customization) {
        return [name, size, customization].join('|');
    }


    function normalizeItem(item) {
        if (item && typeof item === 'object' && !item.key) {
            item.key = makeKey(item.name || '', item.size || '', item.customization || '');
        }
        return item;
    }


    function mergeDuplicates(items) {
        var merged = [];
        var byKey = {};
        items.forEach(function (item) {
            if (!item || typeof item !== 'object') return;
            normalizeItem(item);
            var existing = byKey[item.key];
            if (existing) {
                existing.quantity = Math.min(MAX_QTY, (Number(existing.quantity) || 0) + (Number(item.quantity) || 0));
            } else {
                byKey[item.key] = item;
                merged.push(item);
            }
        });
        return merged;
    }

    function load() {
        try {
            var data = JSON.parse(sessionStorage.getItem(STORAGE_KEY));
            return Array.isArray(data) ? mergeDuplicates(data) : [];
        } catch (error) {
            return [];
        }
    }

    function persist(items) {
        try {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(items));
        } catch (error) {
            console.warn('Could not save cart:', error);
        }
    }

    function save(items) {
        items = mergeDuplicates(items);
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
                        var syncError = new Error(result.error || 'Cart synchronization failed.');
                        syncError.status = response.status;
                        throw syncError;
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





    function money(amount) {
        return '₱' + Number(amount).toLocaleString('en-PH');
    }

    function escapeHtml(value) {
        return String(value === undefined || value === null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function renderBadge(total) {
        document.querySelectorAll('[data-cart-count]').forEach(function (element) {
            element.textContent = total;
            element.hidden = total < 1;
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

        var cartBody = document.getElementById('cart-body');
        if (!cartBody) {
            return;
        }

        if (!items.length) {
            cartBody.innerHTML = '<p class="cart-empty">Your cart is empty.</p>';
            return;
        }

        var html =
            '<div class="cart-row cart-row--head">' +
                '<div>Drink</div>' +
                '<div>Customization</div>' +
                '<div>Drinks</div>' +
                '<div style="grid-column: span 2; display: flex; justify-content: space-between; width: 100%;">' +
                    '<span>Price</span>' +
                    '<span></span>' +
                '</div>' +
            '</div>' +
            '<div class="cart-list">';

        items.forEach(function (item, index) {
            var label = item.name + (item.size ? ' (' + item.size + ')' : '');
            var customization = item.customization
                ? 'Per drink: ' + item.customization
                : 'No extras per drink';

            html +=
                '<div class="cart-row" data-index="' + index + '" data-key="' + escapeHtml(item.key) + '">' +
                    '<div class="cart-drink">' +
                        '<img src="' + escapeHtml(item.image) + '" alt="' + escapeHtml(item.name) + '" onerror="brewskiImageFallback(this)">' +
                        '<span>' + escapeHtml(label) + '</span>' +
                    '</div>' +
                    '<div class="cart-custom">' + escapeHtml(customization) + '</div>' +
                    '<div class="cart-qty">' +
                        '<button type="button" class="cart-qty__btn" data-action="decrease" aria-label="Remove one drink"><i data-lucide="minus"></i></button>' +
                        '<span class="cart-qty__value">' + (Number(item.quantity) || 0) + '</span>' +
                        '<button type="button" class="cart-qty__btn" data-action="increase" aria-label="Add one identical drink"><i data-lucide="plus"></i></button>' +
                    '</div>' +
                    '<div style="display: flex; align-items: center; justify-content: flex-end; gap: 16px; grid-column: span 2; width: 100%;">' +
                        '<div class="cart-price" style="margin: 0;">' + money((Number(item.price) || 0) * (Number(item.quantity) || 0)) + '</div>' +
                        '<div style="display: flex; gap: 8px; align-items: center;">' +
                            '<button type="button" class="cart-edit__btn" data-action="edit" aria-label="Edit item" style="background:none; border:none; cursor:pointer; color:#5c4033;"><i data-lucide="pencil"></i></button>' +
                            '<button type="button" class="cart-remove__btn" data-action="remove" aria-label="Remove item" style="background:none; border:none; cursor:pointer; color:#5c4033;"><i data-lucide="trash-2"></i></button>' +
                        '</div>' +
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

        cartBody.innerHTML = html;

        if (window.lucide) {
            window.lucide.createIcons();
        }
    }

    function setOpen(open) {
        var m = document.getElementById('cart-menu');
        var b = document.getElementById('cart-button');
        if (!m || !b) return;
        m.classList.toggle('open', open);
        b.setAttribute('aria-expanded', open ? 'true' : 'false');
    }







    document.addEventListener('click', function (event) {
        var target = event.target.closest ? event.target.closest('#cart-body [data-action]') : null;
        if (!target) return;

        var row = target.closest('[data-key]');
        if (!row) return;

        event.preventDefault();
        event.stopPropagation();

        var items = load();
        var action = target.getAttribute('data-action');
        var rowKey = row.getAttribute('data-key');
        var index = parseInt(row.getAttribute('data-index'), 10);

        if (isNaN(index) || !items[index] || items[index].key !== rowKey) {
            index = -1;
            items.forEach(function (entry, i) {
                if (index === -1 && entry.key === rowKey) index = i;
            });
        }
        if (index === -1) {
            render();
            return;
        }

        var item = items[index];

        if (action === 'edit') {
            window.openEditModal(item.key);
            return;
        }

        if (action === 'remove') {
            items.splice(index, 1);
        } else if (action === 'increase') {
            item.quantity = Math.min(MAX_QTY, (Number(item.quantity) || 0) + 1);
        } else if (action === 'decrease') {
            item.quantity = Math.max(1, (Number(item.quantity) || 0) - 1);
        } else {
            return;
        }

        save(items);
    }, true);





    var editingKey = '';
    var editingItem = null;
    var openingForEdit = false;



    (function injectEditStyles() {
        var style = document.createElement('style');
        style.textContent =
            '#customization-modal.is-editing #btn-confirm-add > span,' +
            '#customization-modal.is-editing #btn-confirm-add > i,' +
            '#customization-modal.is-editing #btn-confirm-add > svg { display: none !important; }' +
            '#customization-modal.is-editing #btn-confirm-add::before { content: "Save Changes"; }' +
            '#customization-modal.is-editing #btn-confirm-add::after { content: "\\2713"; margin-left: 8px; }';
        document.head.appendChild(style);
    })();

    function startEditing(item) {
        editingKey = item.key;
        editingItem = item;
        var modalEl = document.getElementById('customization-modal');
        var keyInput = document.getElementById('modal-edit-key');
        if (modalEl) modalEl.classList.add('is-editing');
        if (keyInput) keyInput.value = item.key;
    }

    function stopEditing() {
        editingKey = '';
        editingItem = null;
        var modalEl = document.getElementById('customization-modal');
        var keyInput = document.getElementById('modal-edit-key');
        if (modalEl) modalEl.classList.remove('is-editing');
        if (keyInput) keyInput.value = '';
    }

    function findProductCard(item) {
        var card = null;
        if (item.productId !== undefined && item.productId !== null && item.productId !== '') {
            card = document.querySelector('.product-card[data-id="' + item.productId + '"]');
        }
        if (!card && item.name) {
            var cards = document.querySelectorAll('.product-card');
            for (var i = 0; i < cards.length; i++) {
                if (cards[i].getAttribute('data-name') === item.name) return cards[i];
            }
        }
        return card;
    }

    function norm(text) {
        return String(text || '').toLowerCase().replace(/\s+/g, ' ').trim();
    }

    function optionMatches(optionText, wanted) {
        var o = norm(optionText);
        if (!o) return false;
        return wanted.some(function (w) {
            return w && (w === o || w.indexOf(o) === 0 || o.indexOf(w) === 0);
        });
    }



    function applySavedOptions(item, tries) {
        if (editingKey !== item.key) return;

        var modalEl = document.getElementById('customization-modal');
        if (!modalEl) return;

        var wanted = String(item.customization || '').split(',').map(norm);
        if (item.size) wanted.push(norm(item.size));
        wanted = wanted.filter(function (w) { return w && w !== 'no extras'; });

        var ready = false;
        ['temperature', 'size', 'sugar'].forEach(function (type) {
            var group = modalEl.querySelector('[data-option-group="' + type + '"]');
            if (!group || group.hasAttribute('hidden')) return;

            var buttons = group.querySelectorAll('.option-btn');
            if (!buttons.length) return;
            ready = true;

            for (var i = 0; i < buttons.length; i++) {
                if (optionMatches(buttons[i].textContent, wanted)) {
                    if (!buttons[i].classList.contains('active')) buttons[i].click();
                    break;
                }
            }
        });

        if (!ready) {
            if (tries < 15) {
                setTimeout(function () { applySavedOptions(item, tries + 1); }, 60);
            }
            return;
        }


        var qtyEl = document.getElementById('drink-quantity-value');
        var current = qtyEl ? (parseInt(qtyEl.textContent, 10) || 1) : 1;
        var target = Math.max(1, Math.min(MAX_QTY, Number(item.quantity) || 1));
        var action = target > current ? 'increase' : 'decrease';
        var stepBtn = modalEl.querySelector('[data-drink-action="' + action + '"]');
        for (var n = Math.abs(target - current); n > 0 && stepBtn; n--) {
            stepBtn.click();
        }
    }



    function closeCustomizationModal() {
        var modalEl = document.getElementById('customization-modal');
        var closeBtn = document.getElementById('modal-close');
        if (closeBtn) closeBtn.click();
        if (modalEl) {
            ['active', 'show', 'open', 'is-open', 'is-active', 'visible'].forEach(function (cls) {
                modalEl.classList.remove(cls);
            });
        }
    }

    window.openEditModal = function (key) {
        var items = load();
        var item = items.find(function (i) { return i.key === key; });
        if (!item) return;

        var productCard = findProductCard(item);

        if (!productCard) {
            alert("Please go to the Menu page to edit this drink's customizations.");
            return;
        }

        setOpen(false);

        var addBtn = productCard.querySelector('.btn-add') || productCard.querySelector('button');

        openingForEdit = true;
        if (addBtn) addBtn.click();
        openingForEdit = false;

        startEditing(item);

        var titleEl = document.getElementById('modal-product-name');
        if (titleEl) titleEl.textContent = 'Edit: ' + item.name;

        applySavedOptions(item, 0);
    };


    document.addEventListener('click', function (e) {
        if (!openingForEdit && e.target.closest && e.target.closest('.btn-add')) {
            stopEditing();
        }
    }, true);


    document.addEventListener('click', function (e) {
        var confirmBtn = e.target.closest ? e.target.closest('#btn-confirm-add') : null;
        if (!confirmBtn) return;

        var keyInput = document.getElementById('modal-edit-key');
        var oldKey = editingKey || (keyInput ? keyInput.value : '');
        if (!oldKey) return;

        e.preventDefault();
        e.stopPropagation();

        var modal = document.getElementById('customization-modal');
        var titleEl = document.getElementById('modal-product-name');
        var base = editingItem || {};
        var productName = base.name || (titleEl ? titleEl.textContent.replace('Edit: ', '') : '');
        if (!productName) return;

        var productCard = findProductCard({ productId: base.productId, name: productName });

        var qty = parseInt(document.getElementById('drink-quantity-value').textContent, 10) || 1;
        var priceText = document.getElementById('modal-total-price').textContent.replace('₱', '').replace(/,/g, '');
        var totalPrice = parseFloat(priceText) || 0;
        var unitPrice = totalPrice / qty;

        var size = '';
        var parts = [];
        modal.querySelectorAll('[data-option-group]').forEach(function (group) {
            if (group.hasAttribute('hidden')) return;
            var type = group.getAttribute('data-option-group');

            group.querySelectorAll('.option-btn.active').forEach(function (activeBtn) {
                var text = activeBtn.textContent.trim();
                if (!text) return;

                if (type === 'size') {
                    size = text.replace(/\s*\(.*\)\s*$/, '');
                    return;
                }
                if (type === 'sugar' && /^\d+%$/.test(text)) {
                    text += ' sugar';
                }
                parts.push(text);
            });
        });

        var customizationString = parts.join(', ');

        var newItem = {
            key: makeKey(productName, size, customizationString),
            productId: base.productId || (productCard ? productCard.getAttribute('data-id') : ''),
            name: productName,
            image: base.image || (productCard ? productCard.getAttribute('data-image') : ''),
            size: size,
            customization: customizationString,
            customizationOptions: {},
            price: unitPrice,
            quantity: qty
        };


        var items = load();
        var position = -1;
        items.forEach(function (entry, i) {
            if (position === -1 && entry.key === oldKey) position = i;
        });
        if (position === -1) {
            items.push(newItem);
        } else {
            items.splice(position, 1, newItem);
        }

        save(items);


        stopEditing();
        if (titleEl) titleEl.textContent = 'Customize your drink';
        closeCustomizationModal();

    }, true);


    document.addEventListener('click', function (e) {
        if (!editingKey) return;
        if (e.target.closest && (e.target.closest('#modal-close') || e.target.id === 'customization-modal')) {
            setTimeout(function () {
                stopEditing();
                var titleEl = document.getElementById('modal-product-name');
                if (titleEl) titleEl.textContent = 'Customize your drink';
            }, 50);
        }
    }, true);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && editingKey) {
            setTimeout(stopEditing, 50);
        }
    });





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
            event.stopPropagation();

            var checkoutLink = event.target.closest('.cart-checkout');
            if (!checkoutLink) return;
            if (!load().length) {
                event.preventDefault();
                return;
            }
        });
    }

    document.querySelectorAll('[data-cart-logout]').forEach(function (link) {
        link.addEventListener('click', function () {
            try {
                sessionStorage.removeItem(STORAGE_KEY);
            } catch (error) {
                console.warn('Could not clear cart from browser storage:', error);
            }
        });
    });

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
        add: function (item) { save(load().concat([item])); },
        count: (typeof count === 'function') ? count : function () {
            return load().reduce(function (sum, i) { return sum + (Number(i.quantity) || 0); }, 0);
        },
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
