document.addEventListener('DOMContentLoaded', function () {
    var ordersBoard = document.getElementById('ordersBoard');
    var ordersEmpty = document.getElementById('ordersEmpty');
    var orderSearch = document.getElementById('orderSearch');
    var statusFilter = document.getElementById('orderStatusFilter');
    var orderCount = document.getElementById('orderCount');
    var feedback = document.getElementById('orderFeedback');
    var productList = document.getElementById('productAvailabilityList');
    var productFeedback = document.getElementById('productFeedback');
    var productCategoryFilter = document.getElementById('productCategoryFilter');
    var productSearch = document.getElementById('productSearch');
    var productsEmpty = document.getElementById('productsEmpty');
    var productsPageInfo = document.getElementById('productsPageInfo');
    var productsPagination = document.getElementById('productsPagination');

    if (!ordersBoard || !ordersEmpty || !productList) {
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

    var orderStatuses = [
        { key: 'PENDING', label: 'Pending', icon: 'clock-3' },
        { key: 'CONFIRMED', label: 'Confirmed', icon: 'badge-check' },
        { key: 'PREPARING', label: 'Preparing', icon: 'coffee' },
        { key: 'READY', label: 'Ready', icon: 'package-check' },
        { key: 'COMPLETED', label: 'Completed', icon: 'circle-check' },
        { key: 'CANCELLED', label: 'Cancelled', icon: 'circle-x' }
    ];

    var advanceLabels = {
        CONFIRMED: 'Confirm order',
        PREPARING: 'Start preparing',
        READY: 'Mark ready',
        COMPLETED: 'Mark completed',
        CANCELLED: 'Cancel order'
    };

    var orders = [];
    var products = [];
    var orderLanePages = {};
    var orderLanePageSize = 4;
    var productsPage = 1;
    var productsPageSize = 6;
    var autoRefreshInterval = null;
    var isRefreshing = false;

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

    function requestJson(body) {
        return fetch(endpoint, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP error! status: ' + response.status);
            }
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
        var activeElement = document.getElementById('activeOrderCount');
        var readyElement = document.getElementById('readyOrderCount');
        var completedElement = document.getElementById('completedOrderCount');

        if (activeElement) {
            activeElement.textContent = orders.filter(function (order) {
                return order.status !== 'COMPLETED' && order.status !== 'CANCELLED';
            }).length;
        }

        if (readyElement) {
            readyElement.textContent = orders.filter(function (order) {
                return order.status === 'READY';
            }).length;
        }

        if (completedElement) {
            completedElement.textContent = orders.filter(function (order) {
                return order.status === 'COMPLETED';
            }).length;
        }
    }

    function actionCell(order) {
        var nextSteps = transitions[order.status] || [];

        if (!nextSteps.length) {
            return order.status === 'COMPLETED'
                ? '<span class="order-action-done"><i data-lucide="circle-check" aria-hidden="true"></i>Order complete</span>'
                : '<span class="order-action-done"><i data-lucide="circle-x" aria-hidden="true"></i>Order cancelled</span>';
        }

        var advance = nextSteps.filter(function (status) {
            return status !== 'CANCELLED';
        })[0];

        var html = '';

        if (advance) {
            var advanceLabel = advance === 'COMPLETED' && order.payment_provider === 'in_store'
                ? 'Payment collected & complete'
                : (advanceLabels[advance] || advance);
            html += '<button type="button" class="btn btn-primary order-advance" data-order-id="' +
                order.order_id + '" data-status="' + escapeHtml(advance) + '">' +
                '<i data-lucide="' + (advance === 'COMPLETED' ? 'circle-check' : 'arrow-right') + '" aria-hidden="true"></i>' +
                escapeHtml(advanceLabel) + '</button>';
        } else {
            html += '<span class="order-action-waiting">In progress</span>';
        }

        if (nextSteps.indexOf('CANCELLED') !== -1) {
            html += ' <button type="button" class="btn btn-secondary order-cancel" data-order-id="' +
                order.order_id + '" data-status="CANCELLED"><i data-lucide="x" aria-hidden="true"></i>Cancel</button>';
        }

        return html;
    }

    function pageButton(label, disabled, active, handler) {
        var item = document.createElement('button');
        item.type = 'button';
        item.className = 'page-link' + (active ? ' active' : '');
        item.textContent = label;
        item.disabled = disabled;
        item.addEventListener('click', handler);
        return item;
    }

    function renderOrders() {
        var query = orderSearch.value.trim().toLowerCase();
        var status = statusFilter.value;
        var visibleOrders = orders.filter(function (order) {
            var haystack = (
                order.order_id + ' ' +
                (order.customer || '') + ' ' +
                (order.order_notes || '') + ' ' +
                (order.items || []).map(function (item) { return item.name || ''; }).join(' ')
            ).toLowerCase();

            return (!query || haystack.indexOf(query) !== -1) &&
                (status === 'ALL' || order.status === status.toUpperCase());
        });

        var lanes = orderStatuses.filter(function (orderStatus) {
            if (status !== 'ALL' && orderStatus.key !== status.toUpperCase()) {
                return false;
            }

            return !query || visibleOrders.some(function (order) {
                return order.status === orderStatus.key;
            });
        }).map(function (orderStatus) {
            var laneOrders = visibleOrders.filter(function (order) {
                return order.status === orderStatus.key;
            });
            var pageCount = Math.max(1, Math.ceil(laneOrders.length / orderLanePageSize));
            var page = Math.min(orderLanePages[orderStatus.key] || 1, pageCount);
            orderLanePages[orderStatus.key] = page;
            var start = (page - 1) * orderLanePageSize;
            var pageOrders = laneOrders.slice(start, start + orderLanePageSize);

            var cards = pageOrders.map(function (order) {
                var items = order.items.map(function (item) {
                    return '<li>' + escapeHtml(item.quantity + ' × ' + item.name) +
                        (item.customization ? '<small>Per drink: ' + escapeHtml(item.customization) + '</small>' : '') +
                        '</li>';
                }).join('');

                if (!items) {
                    items = '<li>No items</li>';
                }

                return '<article class="order-card">' +
                    '<div class="order-card-heading">' +
                        '<span class="order-id"><i data-lucide="receipt-text" aria-hidden="true"></i>#' + escapeHtml(order.order_id) + '</span>' +
                        '<span class="order-status order-status--' + String(order.status).toLowerCase() + '">' +
                            escapeHtml(orderStatus.label) + '</span>' +
                    '</div>' +
                    '<div class="order-customer"><span class="customer-avatar">' +
                        escapeHtml(initialsOf(order.customer || 'Guest')) +
                        '</span><span><strong><i data-lucide="user-round" aria-hidden="true"></i>' + escapeHtml(order.customer || 'Guest') + '</strong>' +
                        '<small><i data-lucide="calendar-clock" aria-hidden="true"></i>' + escapeHtml(formatPlaced(order.created_at)) + '</small></span></div>' +
                    '<div class="order-items-label"><i data-lucide="package" aria-hidden="true"></i>Items</div>' +
                    '<ul class="order-items">' + items + '</ul>' +
                    (order.order_notes
                        ? '<p class="order-pickup-note"><i data-lucide="message-square-text" aria-hidden="true"></i><strong>Order notes:</strong> ' +
                            escapeHtml(order.order_notes) + '</p>'
                        : '') +
                    (order.payment_provider === 'in_store'
                        ? '<p class="order-pickup-note"><i data-lucide="store" aria-hidden="true"></i><strong>Store pickup · Pay cash at counter</strong></p>'
                        : '') +
                    (order.payment_provider === 'paymongo'
                        ? '<p class="order-pickup-note"><i data-lucide="shield-check" aria-hidden="true"></i><strong>PayMongo · ' +
                            (String(order.payment_status || '').toUpperCase() === 'PAID' ? 'Paid' : 'Payment pending') + '</strong></p>'
                        : '') +
                    '<div class="order-card-footer">' +
                        '<strong class="order-total"><i data-lucide="banknote" aria-hidden="true"></i>₱' + Number(order.total_amount).toFixed(2) + '</strong>' +
                        '<div class="order-card-actions">' + actionCell(order) + '</div>' +
                    '</div>' +
                '</article>';
            }).join('');

            return '<section class="order-lane order-lane--' + orderStatus.key.toLowerCase() +
                '" aria-label="' + escapeHtml(orderStatus.label) + ' orders">' +
                '<div class="order-lane-heading">' +
                    '<h2><i data-lucide="' + orderStatus.icon + '" aria-hidden="true"></i>' + escapeHtml(orderStatus.label) + '</h2>' +
                    '<span aria-label="' + laneOrders.length + ' orders">' + laneOrders.length + '</span>' +
                '</div>' +
                '<div class="order-lane-cards">' + (cards || '<p class="order-lane-empty">No orders</p>') + '</div>' +
                (laneOrders.length > orderLanePageSize
                    ? '<div class="order-lane-pagination">' +
                        '<span>' + (start + 1) + '–' + Math.min(start + orderLanePageSize, laneOrders.length) +
                            ' of ' + laneOrders.length + '</span>' +
                        '<div class="order-lane-pagination-controls">' +
                            '<button type="button" class="page-link" data-lane-status="' + orderStatus.key +
                                '" data-lane-page="' + (page - 1) + '"' + (page === 1 ? ' disabled' : '') +
                                ' aria-label="Previous ' + escapeHtml(orderStatus.label) + ' orders">‹</button>' +
                            '<span>Page ' + page + ' of ' + pageCount + '</span>' +
                            '<button type="button" class="page-link" data-lane-status="' + orderStatus.key +
                                '" data-lane-page="' + (page + 1) + '"' + (page === pageCount ? ' disabled' : '') +
                                ' aria-label="Next ' + escapeHtml(orderStatus.label) + ' orders">›</button>' +
                        '</div>' +
                    '</div>'
                    : '') +
                '</section>';
        }).join('');

        ordersBoard.innerHTML = lanes;
        if (window.lucide) {
            window.lucide.createIcons();
        }
        ordersBoard.classList.toggle('hidden', visibleOrders.length === 0);

        ordersEmpty.classList.toggle('hidden', visibleOrders.length > 0);
        orderCount.textContent = visibleOrders.length + (visibleOrders.length === 1 ? ' order' : ' orders');

        updateOrderSummary();
    }

    function renderProducts() {
        var availableCount = products.filter(function (product) {
            return product.available;
        }).length;
        var selectedCategory = productCategoryFilter.value;
        var query = productSearch.value.trim().toLowerCase();
        var visibleProducts = products.filter(function (product) {
            var matchesCategory = selectedCategory === 'ALL' || product.category === selectedCategory;
            var matchesSearch = !query ||
                (product.name + ' ' + product.category).toLowerCase().indexOf(query) !== -1;

            return matchesCategory && matchesSearch;
        });
        var pageCount = Math.max(1, Math.ceil(visibleProducts.length / productsPageSize));
        if (productsPage > pageCount) productsPage = pageCount;

        var start = (productsPage - 1) * productsPageSize;
        var pageProducts = visibleProducts.slice(start, start + productsPageSize);

        var availableElement = document.getElementById('availableProductCount');
        var totalElement = document.getElementById('totalProductCount');
        var listCountElement = document.getElementById('productListCount');

        if (availableElement) availableElement.textContent = availableCount;
        if (totalElement) totalElement.textContent = products.length;
        if (listCountElement) {
            listCountElement.textContent = visibleProducts.length + (visibleProducts.length === 1 ? ' item' : ' items');
        }

        productList.innerHTML = pageProducts.map(function (product) {
            var state = product.available ? 'Available' : 'Unavailable';

            return '<article class="availability-product">' +
                '<div class="availability-product-photo">' +
                    '<img class="availability-product-image" src="' + escapeHtml(product.image_url) +
                        '" alt="' + escapeHtml(product.name) + '" loading="lazy">' +
                    '<span class="availability-product-icon hidden" aria-hidden="true">' +
                    escapeHtml(String(product.name).charAt(0)) +
                    '</span>' +
                '</div>' +
                '<div class="availability-product-details"><strong>' + escapeHtml(product.name) +
                    '</strong><span>' + escapeHtml(product.category) + '</span></div>' +
                '<span class="availability-product-price">₱' + Number(product.price).toFixed(2) + '</span>' +
                '<span class="availability-state' + (product.available ? ' is-available' : '') + '"><i data-lucide="' +
                    (product.available ? 'circle-check' : 'circle-minus') + '" aria-hidden="true"></i>' + state + '</span>' +
                '<button type="button" class="availability-toggle' + (product.available ? ' is-on' : '') +
                    '" role="switch" aria-checked="' + String(product.available) +
                    '" aria-label="' + (product.available ? 'Disable ' : 'Enable ') +
                    escapeHtml(product.name) + ' for orders" data-product-id="' + product.id + '">' +
                    '<span></span></button>' +
                '</article>';
        }).join('');

        if (window.lucide) {
            window.lucide.createIcons();
        }

        if (productsEmpty) {
            productsEmpty.classList.toggle('hidden', visibleProducts.length > 0);
        }
        if (productsEmpty) {
            productsEmpty.querySelector('.empty-title').textContent = query
                ? 'No matching products'
                : 'No products in this category';
        }

        if (productsPageInfo) {
            productsPageInfo.textContent = visibleProducts.length === 0
                ? 'No products'
                : 'Showing ' + (start + 1) + ' to ' + Math.min(start + productsPageSize, visibleProducts.length) +
                  ' of ' + visibleProducts.length + ' products';
        }

        renderProductsPagination(pageCount);
    }

    function renderProductsPagination(pageCount) {
        if (!productsPagination) {
            return;
        }

        productsPagination.innerHTML = '';

        productsPagination.appendChild(pageButton('Previous', productsPage === 1, false, function () {
            if (productsPage > 1) {
                productsPage--;
                renderProducts();
            }
        }));

        for (var page = 1; page <= pageCount; page++) {
            (function (target) {
                productsPagination.appendChild(pageButton(String(target), false, target === productsPage, function () {
                    productsPage = target;
                    renderProducts();
                }));
            })(page);
        }

        productsPagination.appendChild(pageButton('Next', productsPage === pageCount, false, function () {
            if (productsPage < pageCount) {
                productsPage++;
                renderProducts();
            }
        }));
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
        if (isRefreshing) {
            return;
        }

        isRefreshing = true;

        fetch(endpoint, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP error! status: ' + response.status);
                }
                return response.json();
            })
            .then(function (payload) {
                if (!payload.ok) {
                    feedback.textContent = payload.error || 'We could not load the orders.';
                    return;
                }

                orders = (payload.orders || []).map(function (order) {
                    order.status = String(order.status || '').trim().toUpperCase();
                    order.payment_provider = String(order.payment_provider || '').trim().toLowerCase();
                    order.payment_status = String(order.payment_status || '').trim().toUpperCase();
                    return order;
                });
                products = payload.products || [];

                renderCategoryOptions();
                renderOrders();
                renderProducts();


                if (feedback.textContent && feedback.textContent.indexOf('could not') !== -1) {
                    feedback.textContent = '';
                }
            })
            .catch(function (error) {
                console.error('Error loading orders:', error);
                feedback.textContent = 'We could not reach the server. Please reload the page.';
            })
            .finally(function () {
                isRefreshing = false;
            });
    }

    function startAutoRefresh() {
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
        }

        autoRefreshInterval = setInterval(function () {
            if (document.hidden) {
                return;
            }

            var busy = ordersBoard.querySelector('button[disabled]') ||
                       productList.querySelector('button[disabled]');
            if (busy) {
                return;
            }

            load();
        }, 10000);
    }

    function stopAutoRefresh() {
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
            autoRefreshInterval = null;
        }
    }

    ordersBoard.addEventListener('click', function (event) {
        var pageButton = event.target.closest('button[data-lane-status][data-lane-page]');
        if (pageButton) {
            orderLanePages[pageButton.dataset.laneStatus] = Number(pageButton.dataset.lanePage);
            renderOrders();
            return;
        }

        var button = event.target.closest('button[data-order-id]');
        if (!button) {
            return;
        }

        if (button.dataset.status === 'COMPLETED' && button.textContent.includes('Payment collected')
            && !window.confirm('Confirm you have collected cash for this pickup order?')) {
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

            setTimeout(function () {
                if (feedback.textContent === payload.message) {
                    feedback.textContent = '';
                }
            }, 3000);

            load();
        }).catch(function (error) {
            console.error('Error updating order:', error);
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

            setTimeout(function () {
                if (productFeedback.textContent === 'Product availability updated.') {
                    productFeedback.textContent = '';
                }
            }, 3000);

            renderProducts();
        }).catch(function (error) {
            console.error('Error toggling product:', error);
            productFeedback.textContent = 'We could not reach the server. Please try again.';
            button.disabled = false;
        });
    });

    orderSearch.addEventListener('input', function () {
        orderLanePages = {};
        renderOrders();
    });

    statusFilter.addEventListener('change', function () {
        orderLanePages = {};
        renderOrders();
    });

    productCategoryFilter.addEventListener('change', function () {
        productsPage = 1;
        renderProducts();
    });

    productSearch.addEventListener('input', function () {
        productsPage = 1;
        renderProducts();
    });

    productList.addEventListener('error', function (event) {
        if (event.target.matches('.availability-product-image')) {
            event.target.hidden = true;
            event.target.nextElementSibling.classList.remove('hidden');
        }
    }, true);

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            stopAutoRefresh();
        } else {
            load();
            startAutoRefresh();
        }
    });

    load();

    startAutoRefresh();
});
