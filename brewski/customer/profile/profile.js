(function () {
    const STORAGE_KEY = 'brewski_cart';
    const menu = document.getElementById('cart-menu');
    const button = document.getElementById('cart-button');
    const body = document.getElementById('cart-body');

    if (!menu || !button || !body) {
        return;
    }

    function load() {
        try {
            const data = JSON.parse(localStorage.getItem(STORAGE_KEY));
            return Array.isArray(data) ? data : [];
        } catch (error) {
            return [];
        }
    }

    function save(items) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(items));
        } catch (error) {}
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

    function setOpen(open) {
        menu.classList.toggle('open', open);
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function render() {
        const items = load();
        let count = 0;
        let subtotal = 0;

        items.forEach(function (item) {
            count += item.quantity;
            subtotal += item.price * item.quantity;
        });

        document.querySelectorAll('[data-cart-count]').forEach(function (element) {
            element.textContent = count;
        });

        if (!items.length) {
            body.innerHTML = '<p class="cart-empty">Your cart is empty.</p>';
            return;
        }

        let html = '' +
            '<div class="cart-row cart-row--head">' +
            '<div>Drink</div><div>Customization</div><div>Quantity</div><div>Price</div><div></div>' +
            '</div>' +
            '<div class="cart-list">';

        items.forEach(function (item) {
            const label = item.name + (item.size ? ' (' + item.size + ')' : '');

            html += '' +
                '<div class="cart-row" data-key="' + escapeHtml(item.key) + '">' +
                '<div class="cart-drink">' +
                '<img src="' + escapeHtml(item.image) + '" alt="' + escapeHtml(item.name) + '">' +
                '<span>' + escapeHtml(label) + '</span>' +
                '</div>' +
                '<div class="cart-custom">' + escapeHtml(item.customization
                    ? 'Per drink: ' + item.customization
                    : 'No extras per drink') + '</div>' +
                '<div class="cart-qty">' +
                '<button type="button" class="cart-qty__btn" data-action="increase" aria-label="Increase quantity"><i data-lucide="plus"></i></button>' +
                '<span class="cart-qty__value">' + item.quantity + '</span>' +
                '<button type="button" class="cart-qty__btn" data-action="decrease" aria-label="Decrease quantity"><i data-lucide="minus"></i></button>' +
                '</div>' +
                '<div class="cart-price">' + money(item.price * item.quantity) + '</div>' +
                '<div class="cart-remove">' +
                '<button type="button" class="cart-remove__btn" data-action="remove" aria-label="Remove item"><i data-lucide="trash-2"></i></button>' +
                '</div>' +
                '</div>';
        });

        html += '' +
            '</div>' +
            '<div class="cart-summary">' +
            '<div class="cart-summary__line"><span>Subtotal</span><span>' + money(subtotal) + '</span></div>' +
            '<div class="cart-summary__line cart-summary__line--total"><span>Total</span><span>' + money(subtotal) + '</span></div>' +
            '<a href="checkout.html" class="cart-checkout">Proceed to check out</a>' +
            '</div>';

        body.innerHTML = html;

        if (window.lucide) {
            window.lucide.createIcons();
        }
    }

    function selectedValues(group) {
        return Array.prototype.map.call(
            document.querySelectorAll('.option-buttons[data-group="' + group + '"] .option-btn.active'),
            function (option) {
                return option.dataset.value;
            }
        );
    }

    function addFromModal() {
        const nameElement = document.getElementById('modal-product-name');
        const totalElement = document.getElementById('modal-total-price');

        if (!nameElement || !totalElement) {
            return;
        }

        const name = nameElement.textContent.trim();
        const price = parseFloat(totalElement.textContent.replace(/[^0-9.]/g, '')) || 0;

        const card = Array.prototype.find.call(
            document.querySelectorAll('.product-card'),
            function (element) {
                return element.dataset.name === name;
            }
        );

        const image = card ? card.dataset.image || '' : '';
        const size = selectedValues('size')[0] || '';
        const parts = [];

        const temp = selectedValues('temp')[0];
        if (temp) {
            parts.push(temp);
        }

        const sugar = selectedValues('sugar')[0];
        if (sugar) {
            parts.push(sugar + ' sugar');
        }

        selectedValues('addons').forEach(function (addon) {
            parts.push(addon);
        });

        const note = document.getElementById('special-instructions');
        if (note && note.value.trim() !== '') {
            parts.push(note.value.trim());
        }

        const customization = parts.join(', ');
        const key = [name, size, customization].join('|');
        const items = load();
        const existing = items.find(function (item) {
            return item.key === key;
        });

        if (existing) {
            existing.quantity = Math.min(99, existing.quantity + 1);
        } else {
            items.push({
                key: key,
                name: name,
                image: image,
                size: size,
                customization: customization,
                price: price,
                quantity: 1
            });
        }

        save(items);
        render();
        setOpen(true);
    }

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

    document.addEventListener(
        'click',
        function (event) {
            if (event.target.closest('#btn-confirm-add')) {
                addFromModal();
            }
        },
        true
    );

    body.addEventListener('click', function (event) {
        const target = event.target.closest('[data-action]');

        if (!target) {
            return;
        }

        const row = target.closest('[data-key]');

        if (!row) {
            return;
        }

        let items = load();
        const item = items.find(function (entry) {
            return entry.key === row.dataset.key;
        });

        if (!item) {
            return;
        }

        if (target.dataset.action === 'increase') {
            item.quantity = Math.min(99, item.quantity + 1);
        } else if (target.dataset.action === 'decrease') {
            item.quantity = Math.max(1, item.quantity - 1);
        } else if (target.dataset.action === 'remove') {
            items = items.filter(function (entry) {
                return entry.key !== item.key;
            });
        }

        save(items);
        render();
    });

    window.addEventListener('storage', render);

    render();
})();