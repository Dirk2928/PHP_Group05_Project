document.addEventListener('DOMContentLoaded', function () {
    var ordersBody = document.getElementById('ordersBody');
    var ordersEmpty = document.getElementById('ordersEmpty');
    var orderSearch = document.getElementById('orderSearch');
    var statusFilter = document.getElementById('orderStatusFilter');
    var orderCount = document.getElementById('orderCount');
    var feedback = document.getElementById('orderFeedback');
    var productList = document.getElementById('productAvailabilityList');
    var productFeedback = document.getElementById('productFeedback');
    var productCategoryFilter = document.getElementById('productCategoryFilter');

    if (!ordersBody || !ordersEmpty || !productList) {
        return;
    }

    var endpoint = window.BREWSKI_ORDERS_API || 'orders_api.php';
    var csrfToken = window.BREWSKI_CSRF || '';

    var transitions = {
        PENDING: ['CONFIRMED', 'CANCELLED'],
        CONFIRMED: ['PREPARING', 'CANCELLED'],
        PREPARING: ['READY', 'CANCELLED'],
        READY: ['COMPLETED', 'CANCELLED'],
        COMPLETED: [],
        CANCELLED: []
    };

    var advanceLabels = {
        CONFIRMED: 'Confirm order',
        PREPARING: 'Start preparing',
        READY: 'Mark ready',
        COMPLETED: 'Mark completed',
        CANCELLED: 'Cancel order'
    };

    var orders = [];
    var products = [];

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (character) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            }[character];
        });
    }

    function initialsOf(name) {
        var parts = String(name).trim().split(/\s+/);
        var first = parts[0] ? parts[0].charAt(0) : '?';
        var last = parts.length > 1 ? parts[parts.length - 1].charAt(0) : '';

        return (first + last).toUpperCase();
    }

    function parseDate(value) {
        if (!value) {
            return null;
        }

        var date = new Date(String(value).replace(' ', 'T'));

        return isNaN(date.getTime()) ? null : date;
    }

    function formatPlaced(value) {
        var date = parseDate(value);

        if (!date) {
            return value || '';

        }

        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) +
            ', ' + date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    }

    function isToday(value) {
        var date = parseDate(value);
        var now = new Date();

        if (!date) {
            return false;
        }

        return date.getFullYear() === now.getFullYear() &&
            date.getMonth() === now.getMonth() &&
            date.getDate() === now.getDate();
    }

    function requestJson(body) {
        return fetch(endpoint, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            return response.json();
        });
    }

    function post(action, fields) {
        var body = new FormData();
        body.append('csrf_token', csrfToken);
        body.append('action', action);

        Object.keys(fields).forEach(function (key) {
            body.append(key, fields[key]);
        });

        return requestJson(body);
    }

    function updateOrderSummary() {
        document.getElementById('activeOrderCount').textContent = orders.filter(function (order) {
            return order.status !== 'COMPLETED' && order.status !== 'CANCELLED';
        }).length;

        document.getElementById('readyOrderCount').textContent = orders.filter(function (order) {
            return order.status === 'READY';
        }).length;

        document.getElementById('completedOrderCount').textContent = orders.filter(function (order) {
            return order.status === 'COMPLETED' && isToday(order.created_at);
        }).length;
    }

    function actionCell(order) {
        var nextSteps = transitions[order.status] || [];

        if (!nextSteps.length) {
            return order.status === 'COMPLETED'
                ? '<span class="order-action-done">Order complete</span>'
                : '<span class="order-action-done">Order cancelled</span>';
        }

        var advance = nextSteps.filter(function (status) {
            return status !== 'CANCELLED';
        })[0];

        var html = '';

        if (advance) {
            html += '<button type="button" class="btn btn-primary order-advance" data-order-id="' +
                order.order_id + '" data-status="' + escapeHtml(advance) + '">' +
                escapeHtml(advanceLabels[advance] || advance) + '</button>';
        } else {
            html += '<span class="order-action-waiting">In progress</span>';
        }

        if (nextSteps.indexOf('CANCELLED') !== -1) {
            html += ' <button type="button" class="btn btn-secondary order-cancel" data-order-id="' +
                order.order_id + '" data-status="CANCELLED">Cancel</button>';
        }

        return html;
    }

    function renderOrders() {
        var query = orderSearch.value.trim().toLowerCase();
        var status = statusFilter.value;
        var visibleOrders = orders.filter(function (order) {
            var haystack = (order.order_id + ' ' + order.customer).toLowerCase();

            return (!query || haystack.indexOf(query) !== -1) &&
                (status === 'ALL' || order.status === status);
        });

        ordersBody.innerHTML = visibleOrders.map(function (order) {
            var items = order.items.map(function (item) {
                return '<li>' + escapeHtml(item.quantity + ' × ' + item.name) + '</li>';
            }).join('');

            if (!items) {
                items = '<li>No items</li>';
            }

            return '<tr>' +
                '<td><span class="order-id">#' + escapeHtml(order.order_id) + '</span></td>' +
                '<td><div class="order-customer"><span class="customer-avatar">' +
                    escapeHtml(initialsOf(order.customer || 'Guest')) +
                    '</span><span><strong>' + escapeHtml(order.customer || 'Guest') + '</strong></span></div></td>' +
                '<td><ul class="order-items">' + items + '</ul></td>' +
                '<td class="order-total">₱' + Number(order.total_amount).toFixed(2) + '</td>' +
                '<td class="order-placed">' + escapeHtml(formatPlaced(order.created_at)) + '</td>' +
                '<td><span class="order-status order-status--' + String(order.status).toLowerCase() + '">' +
                    escapeHtml(order.status) + '</span></td>' +
                '<td>' + actionCell(order) + '</td>' +
                '</tr>';
        }).join('');

        ordersEmpty.classList.toggle('hidden', visibleOrders.length > 0);
        orderCount.textContent = visibleOrders.length + (visibleOrders.length === 1 ? ' order' : ' orders');
        updateOrderSummary();
    }

    function renderProducts() {
        var availableCount = products.filter(function (product) {
            return product.available;
        }).length;
        var selectedCategory = productCategoryFilter.value;
        var visibleProducts = products.filter(function (product) {
            return selectedCategory === 'ALL' || product.category === selectedCategory;
        });

        document.getElementById('availableProductCount').textContent = availableCount;
        document.getElementById('totalProductCount').textContent = products.length;
        document.getElementById('productListCount').textContent =
            visibleProducts.length + (visibleProducts.length === 1 ? ' item' : ' items');

        productList.innerHTML = visibleProducts.map(function (product) {
            var state = product.available ? 'Available' : 'Unavailable';

            return '<article class="availability-product">' +
                '<div class="availability-product-icon" aria-hidden="true">' +
                    escapeHtml(String(product.name).charAt(0)) +
                '</div>' +
                '<div class="availability-product-details"><strong>' + escapeHtml(product.name) +
                    '</strong><span>' + escapeHtml(product.category) + '</span></div>' +
                '<span class="availability-product-price">₱' + Number(product.price).toFixed(2) + '</span>' +
                '<span class="availability-state' + (product.available ? ' is-available' : '') + '">' +
                    state + '</span>' +
                '<button type="button" class="availability-toggle' + (product.available ? ' is-on' : '') +
                    '" role="switch" aria-checked="' + String(product.available) +
                    '" aria-label="' + (product.available ? 'Disable ' : 'Enable ') +
                    escapeHtml(product.name) + ' for orders" data-product-id="' + product.id + '">' +
                    '<span></span></button>' +
                '</article>';
        }).join('');
    }

    function renderCategoryOptions() {
        var selected = productCategoryFilter.value;
        var categories = [];

        products.forEach(function (product) {
            if (categories.indexOf(product.category) === -1) {
                categories.push(product.category);
            }
        });

        categories.sort();

        productCategoryFilter.innerHTML = '<option value="ALL">All categories</option>';

        categories.forEach(function (category) {
            var option = document.createElement('option');
            option.value = category;
            option.textContent = category;
            productCategoryFilter.appendChild(option);
        });

        productCategoryFilter.value = categories.indexOf(selected) === -1 ? 'ALL' : selected;
    }

    function load() {
        fetch(endpoint, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (payload) {
                if (!payload.ok) {
                    feedback.textContent = payload.error || 'We could not load the orders.';
                    return;
                }

                orders = payload.orders || [];
                products = payload.products || [];

                renderCategoryOptions();
                renderOrders();
                renderProducts();
            })
            .catch(function () {
                feedback.textContent = 'We could not reach the server. Please reload the page.';
            });
    }

    ordersBody.addEventListener('click', function (event) {
        var button = event.target.closest('button[data-order-id]');
        if (!button) {
            return;
        }

        button.disabled = true;
        feedback.textContent = '';

        post('update_status', {
            order_id: button.dataset.orderId,
            status: button.dataset.status
        }).then(function (payload) {
            if (!payload.ok) {
                feedback.textContent = payload.error || 'We could not update that order.';
                button.disabled = false;
                return;
            }

            feedback.textContent = payload.message || 'Order updated.';
            load();
        }).catch(function () {
            feedback.textContent = 'We could not reach the server. Please try again.';
            button.disabled = false;
        });
    });

    productList.addEventListener('click', function (event) {
        var button = event.target.closest('button[data-product-id]');
        if (!button) {
            return;
        }

        button.disabled = true;
        productFeedback.textContent = '';

        post('toggle_product', {
            product_id: button.dataset.productId
        }).then(function (payload) {
            if (!payload.ok) {
                productFeedback.textContent = payload.error || 'We could not update that product.';
                button.disabled = false;
                return;
            }

            products = products.map(function (product) {
                if (product.id === payload.product_id) {
                    product.available = payload.available;
                }

                return product;
            });

            productFeedback.textContent = 'Product availability updated.';
            renderProducts();
        }).catch(function () {
            productFeedback.textContent = 'We could not reach the server. Please try again.';
            button.disabled = false;
        });
    });

    orderSearch.addEventListener('input', renderOrders);
    statusFilter.addEventListener('change', renderOrders);
    productCategoryFilter.addEventListener('change', renderProducts);

    load();
});
