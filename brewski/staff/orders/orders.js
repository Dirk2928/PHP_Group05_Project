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

    var orders = [
        {
            id: 'BK-1048',
            customer: 'Alana Santos',
            initials: 'AS',
            phone: '+63 917 555 0124',
            items: ['1 × Iced Caramel Latte', '1 × Blueberry Muffin'],
            total: 245,
            placed: 'Today, 10:42 AM',
            status: 'DELIVERED'
        },
        {
            id: 'BK-1047',
            customer: 'Miguel Reyes',
            initials: 'MR',
            phone: '+63 918 402 7751',
            items: ['2 × Spanish Latte (Large)'],
            total: 380,
            placed: 'Today, 10:36 AM',
            status: 'PREPARING'
        },
        {
            id: 'BK-1046',
            customer: 'Sofia Cruz',
            initials: 'SC',
            phone: '+63 905 223 4180',
            items: ['1 × Matcha Latte', '1 × Butter Croissant'],
            total: 290,
            placed: 'Today, 10:21 AM',
            status: 'READY'
        },
        {
            id: 'BK-1045',
            customer: 'Daniel Garcia',
            initials: 'DG',
            phone: '+63 917 604 2298',
            items: ['2 × Americano', '1 × Chocolate Cookie'],
            total: 310,
            placed: 'Today, 9:58 AM',
            status: 'COMPLETED'
        },
        {
            id: 'BK-1044',
            customer: 'Bea Mendoza',
            initials: 'BM',
            phone: '+63 927 100 4821',
            items: ['1 × Mocha Frappe'],
            total: 185,
            placed: 'Today, 9:40 AM',
            status: 'DELIVERED'
        }
    ];

    var products = [
        { id: 1, name: 'Iced Caramel Latte', category: 'Coffee', price: 160, available: true },
        { id: 2, name: 'Spanish Latte', category: 'Coffee', price: 190, available: true },
        { id: 3, name: 'Matcha Latte', category: 'Non-coffee', price: 170, available: true },
        { id: 4, name: 'Mocha Frappe', category: 'Blended drinks', price: 185, available: false },
        { id: 5, name: 'Americano', category: 'Coffee', price: 120, available: true },
        { id: 6, name: 'Blueberry Muffin', category: 'Pastries', price: 85, available: true }
    ];

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

    function updateOrderSummary() {
        document.getElementById('activeOrderCount').textContent = orders.filter(function (order) {
            return order.status !== 'COMPLETED';
        }).length;
        document.getElementById('readyOrderCount').textContent = orders.filter(function (order) {
            return order.status === 'READY';
        }).length;
        document.getElementById('completedOrderCount').textContent = orders.filter(function (order) {
            return order.status === 'COMPLETED';
        }).length;
    }

    function renderOrders() {
        var query = orderSearch.value.trim().toLowerCase();
        var status = statusFilter.value;
        var visibleOrders = orders.filter(function (order) {
            var matchesQuery = (order.id + ' ' + order.customer).toLowerCase().includes(query);
            return matchesQuery && (status === 'ALL' || order.status === status);
        });

        ordersBody.innerHTML = visibleOrders.map(function (order) {
            var action = '';
            if (order.status === 'DELIVERED') {
                action = '<button type="button" class="btn btn-primary order-complete" data-order-id="' +
                    escapeHtml(order.id) + '">Mark completed</button>';
            } else if (order.status === 'READY') {
                action = '<button type="button" class="btn btn-secondary order-deliver" data-order-id="' +
                    escapeHtml(order.id) + '">Mark delivered</button>';
            } else if (order.status === 'COMPLETED') {
                action = '<span class="order-action-done">Order complete</span>';
            } else {
                action = '<span class="order-action-waiting">In progress</span>';
            }

            var items = order.items.map(function (item) {
                return '<li>' + escapeHtml(item) + '</li>';
            }).join('');

            return '<tr>' +
                '<td><span class="order-id">#' + escapeHtml(order.id) + '</span></td>' +
                '<td><div class="order-customer"><span class="customer-avatar">' + escapeHtml(order.initials) +
                    '</span><span><strong>' + escapeHtml(order.customer) + '</strong><small>' +
                    escapeHtml(order.phone) + '</small></span></div></td>' +
                '<td><ul class="order-items">' + items + '</ul></td>' +
                '<td class="order-total">₱' + Number(order.total).toFixed(2) + '</td>' +
                '<td class="order-placed">' + escapeHtml(order.placed) + '</td>' +
                '<td><span class="order-status order-status--' + order.status.toLowerCase() + '">' +
                    escapeHtml(order.status) + '</span></td>' +
                '<td>' + action + '</td>' +
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
                    escapeHtml(product.name.charAt(0)) +
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

    Array.from(new Set(products.map(function (product) {
        return product.category;
    }))).sort().forEach(function (category) {
        var option = document.createElement('option');
        option.value = category;
        option.textContent = category;
        productCategoryFilter.appendChild(option);
    });

    ordersBody.addEventListener('click', function (event) {
        var button = event.target.closest('button[data-order-id]');
        if (!button) {
            return;
        }

        var order = orders.find(function (candidate) {
            return candidate.id === button.dataset.orderId;
        });
        if (!order) {
            return;
        }

        if (button.classList.contains('order-complete') && order.status === 'DELIVERED') {
            order.status = 'COMPLETED';
            feedback.textContent = 'Order #' + order.id + ' marked as completed.';
        } else if (button.classList.contains('order-deliver') && order.status === 'READY') {
            order.status = 'DELIVERED';
            feedback.textContent = 'Order #' + order.id + ' marked as delivered.';
        } else {
            return;
        }

        renderOrders();
    });

    productList.addEventListener('click', function (event) {
        var button = event.target.closest('button[data-product-id]');
        if (!button) {
            return;
        }

        var product = products.find(function (candidate) {
            return candidate.id === Number(button.dataset.productId);
        });
        if (!product) {
            return;
        }

        product.available = !product.available;
        productFeedback.textContent = product.name + ' is now ' +
            (product.available ? 'available' : 'unavailable') + ' for orders.';
        renderProducts();
    });

    orderSearch.addEventListener('input', renderOrders);
    statusFilter.addEventListener('change', renderOrders);
    productCategoryFilter.addEventListener('change', renderProducts);

    renderOrders();
    renderProducts();
});
