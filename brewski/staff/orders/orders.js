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
    var ordersPageInfo = document.getElementById('ordersPageInfo');
    var ordersPagination = document.getElementById('ordersPagination');

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
    var ordersPage = 1;
    var ordersPageSize = 5;
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
                return order.status === 'COMPLETED' && isToday(order.created_at);
            }).length;
        }
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

    function pageButton(label, disabled, active, handler) {
        var item = document.createElement('button');
        item.type = 'button';
        item.className = 'page-link' + (active ? ' active' : '');
        item.textContent = label;
        item.disabled = disabled;
        item.addEventListener('click', handler);
        return item;
    }

    function renderOrdersPagination(pageCount) {
        if (!ordersPagination) {
            return;
        }

        ordersPagination.innerHTML = '';

        ordersPagination.appendChild(pageButton('Previous', ordersPage === 1, false, function () {
            if (ordersPage > 1) {
                ordersPage--;
                renderOrders();
            }
        }));

        for (var page = 1; page <= pageCount; page++) {
            (function (target) {
                ordersPagination.appendChild(pageButton(String(target), false, target === ordersPage, function () {
                    ordersPage = target;
                    renderOrders();
                }));
            })(page);
        }

        ordersPagination.appendChild(pageButton('Next', ordersPage === pageCount, false, function () {
            if (ordersPage < pageCount) {
                ordersPage++;
                renderOrders();
            }
        }));
    }

    function renderOrders() {
        var query = orderSearch.value.trim().toLowerCase();
        var status = statusFilter.value;
        var visibleOrders = orders.filter(function (order) {
            var haystack = (order.order_id + ' ' + order.customer + ' ' + (order.delivery_notes || '')).toLowerCase();

            return (!query || haystack.indexOf(query) !== -1) &&
                (status === 'ALL' || order.status === status);
        });

        var pageCount = Math.max(1, Math.ceil(visibleOrders.length / ordersPageSize));
        if (ordersPage > pageCount) ordersPage = pageCount;

        var start = (ordersPage - 1) * ordersPageSize;
        var pageOrders = visibleOrders.slice(start, start + ordersPageSize);

        ordersBody.innerHTML = pageOrders.map(function (order) {
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
                '<td><ul class="order-items">' + items + '</ul>' +
                    (order.delivery_notes
                        ? '<p class="order-delivery-notes"><strong>Delivery notes:</strong> ' +
                            escapeHtml(order.delivery_notes) + '</p>'
                        : '') +
                    '</td>' +
                '<td class="order-total">₱' + Number(order.total_amount).toFixed(2) + '</td>' +
                '<td class="order-placed">' + escapeHtml(formatPlaced(order.created_at)) + '</td>' +
                '<td><span class="order-status order-status--' + String(order.status).toLowerCase() + '">' +
                    escapeHtml(order.status) + '</span></td>' +
                '<td>' + actionCell(order) + '</td>' +
                '</tr>';
        }).join('');

        ordersEmpty.classList.toggle('hidden', visibleOrders.length > 0);
        orderCount.textContent = visibleOrders.length + (visibleOrders.length === 1 ? ' order' : ' orders');

        if (ordersPageInfo) {
            ordersPageInfo.textContent = visibleOrders.length === 0
                ? 'No results'
                : 'Showing ' + (start + 1) + ' to ' + Math.min(start + ordersPageSize, visibleOrders.length) +
                  ' of ' + visibleOrders.length + ' orders';
        }

        renderOrdersPagination(pageCount);
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

        var availableElement = document.getElementById('availableProductCount');
        var totalElement = document.getElementById('totalProductCount');
        var listCountElement = document.getElementById('productListCount');

        if (availableElement) availableElement.textContent = availableCount;
        if (totalElement) totalElement.textContent = products.length;
        if (listCountElement) {
            listCountElement.textContent = visibleProducts.length + (visibleProducts.length === 1 ? ' item' : ' items');
        }

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

                orders = payload.orders || [];
                products = payload.products || [];

                renderCategoryOptions();
                renderOrders();
                renderProducts();
                
                // Clear any previous error messages on successful load
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

            var busy = ordersBody.querySelector('button[disabled]') || 
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
        ordersPage = 1;
        renderOrders();
    });
    
    statusFilter.addEventListener('change', function () {
        ordersPage = 1;
        renderOrders();
    });
    
    productCategoryFilter.addEventListener('change', renderProducts);

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