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
                '<div style="grid-column: span 2; display: flex; justify-content: space-between; width: 100%;">' +
                    '<span>Price</span>' +
                    '<span></span>' +
                '</div>' +
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

        body.innerHTML = html;

        if (window.lucide) {
            window.lucide.createIcons();
        }
    }

    function setOpen(open) {
        if (!menu || !button) return;
        menu.classList.toggle('open', open);
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    // --- SYNC PRICE TO OUR CUSTOM FOOTER ---
    // The menu updates #modal-total-price, so we copy it to our footer every 50ms
    setInterval(function() {
        var nativePrice = document.getElementById('modal-total-price');
        var customPrice = document.getElementById('custom-modal-total-price');
        if (nativePrice && customPrice && nativePrice.textContent !== customPrice.textContent) {
            customPrice.textContent = nativePrice.textContent;
        }
    }, 50);

    // --- EDIT INTERCEPTOR ---
    window.openEditModal = function(key) {
        var items = load();
        var item = items.find(function(i) { return i.key === key; });
        if (!item) return;

        setOpen(false); 

        var productCard = document.querySelector('.product-card[data-id="' + item.productId + '"]');
        
        if (productCard) {
            var addBtn = productCard.querySelector('.btn-add');
            if (addBtn) addBtn.click(); 

            document.getElementById('modal-edit-key').value = key;

            // Swap our custom buttons
            document.getElementById('custom-btn-add').style.display = 'none';
            document.getElementById('custom-btn-save').style.display = 'flex';

            var titleEl = document.getElementById('modal-product-name');
            if (titleEl) titleEl.textContent = 'Edit: ' + item.name;
        } else {
            alert("Please go to the Menu page to edit this drink's customizations.");
        }
    };

    // 1. Handle Save Changes (Custom Button)
    document.getElementById('custom-btn-save')?.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();

        var editKeyInput = document.getElementById('modal-edit-key');
        var oldKey = editKeyInput.value;
        var modal = document.getElementById('customization-modal');
        var productName = document.getElementById('modal-product-name').textContent.replace('Edit: ', '');
        var productCard = document.querySelector('.product-card[data-name="' + productName + '"]');
        
        if (!productCard) return;

        var qty = parseInt(document.getElementById('drink-quantity-value').textContent, 10) || 1;
        var priceText = document.getElementById('custom-modal-total-price').textContent.replace('₱', '').replace(/,/g, '');
        var totalPrice = parseFloat(priceText) || 0;
        var unitPrice = totalPrice / qty;

        var selectedOptions = [];
        var optionGroups = modal.querySelectorAll('[data-option-group]');
        optionGroups.forEach(function(group) {
            if (group.hasAttribute('hidden')) return;
            var activeBtn = group.querySelector('.option-btn.active');
            if (activeBtn) {
                selectedOptions.push(activeBtn.textContent.trim());
            }
        });

        var customizationString = selectedOptions.join(', ');
        if (!customizationString) customizationString = 'No extras';

        var newItem = {
            key: makeKey(productName, '', customizationString),
            productId: productCard.dataset.id,
            name: productName,
            image: productCard.dataset.image,
            size: '',
            customization: customizationString,
            customizationOptions: {},
            price: unitPrice,
            quantity: qty
        };

        var items = load();
        items = items.filter(function(i) { return i.key !== oldKey; });
        items.push(newItem);
        
        save(items);
        
        // Close modal and reset
        modal.classList.remove('active');
        editKeyInput.value = '';
        
        // Reset our custom buttons
        document.getElementById('custom-btn-save').style.display = 'none';
        document.getElementById('custom-btn-add').style.display = 'flex';
        document.getElementById('modal-product-name').textContent = 'Customize your drink';

    });

    // 2. Handle Add to Cart (Custom Button)
    document.getElementById('custom-btn-add')?.addEventListener('click', function(e) {
        // If we are NOT editing, we let the menu handle it by clicking the hidden native button
        var editKeyInput = document.getElementById('modal-edit-key');
        if (editKeyInput && editKeyInput.value !== '') return; // If editing, do nothing here

        e.preventDefault();
        var nativeBtn = document.getElementById('btn-confirm-add');
        if (nativeBtn) nativeBtn.click();
    });

    // 3. Reset on close
    document.addEventListener('click', function(e) {
        if (e.target.closest('#modal-close') || e.target.closest('.modal-overlay')) {
            var editKeyInput = document.getElementById('modal-edit-key');
            if (editKeyInput && editKeyInput.value !== '') {
                setTimeout(function() {
                    editKeyInput.value = '';
                    document.getElementById('custom-btn-save').style.display = 'none';
                    document.getElementById('custom-btn-add').style.display = 'flex';
                    document.getElementById('modal-product-name').textContent = 'Customize your drink';
                }, 50);
            }
        }
    }, true);

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

            var target = event.target.closest('[data-action]');
            if (!target) return;

            var row = target.closest('[data-key]');
            if (!row) return;

            var items = load();
            var action = target.dataset.action;

            if (action === 'edit') {
                if (window.openEditModal) window.openEditModal(row.dataset.key);
                return;
            }

            if (action === 'remove') {
                items = items.filter(function (entry) {
                    return entry.key !== row.dataset.key;
                });
            } else {
                items.forEach(function (entry) {
                    if (entry.key !== row.dataset.key) return;

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
            if (!checkoutLink || !syncEnabled || syncEnabled.content !== '1') return;

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

    document.querySelectorAll('[data-cart-logout]').forEach(function (link) {
        link.addEventListener('click', function () {
            try {
                localStorage.removeItem(STORAGE_KEY);
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
        add: function(item) { save([...load(), item]); },
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