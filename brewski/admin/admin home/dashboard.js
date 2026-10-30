document.addEventListener('DOMContentLoaded', function () {
    var svgNamespace = 'http://www.w3.org/2000/svg';
    var dashboard = document.getElementById('homeView');
    var salesChart = document.getElementById('salesChart');
    var intervalSelect = document.getElementById('trendInterval');
    var message = document.getElementById('dashboardMessage');
    var activeRequest = null;
    var pendingRangeKeys = new Set();
    var rangeControls = [
        { key: 'trend', unit: 'trendRangeUnit', from: 'trendRangeFrom', to: 'trendRangeTo' },
        { key: 'seller', unit: 'sellerRangeUnit', from: 'sellerRangeFrom', to: 'sellerRangeTo' },
        { key: 'peak', unit: 'peakRangeUnit', from: 'peakRangeFrom', to: 'peakRangeTo' },
        { key: 'category', unit: 'categoryRangeUnit', from: 'categoryRangeFrom', to: 'categoryRangeTo' }
    ];

    function money(amount, fractionDigits) {
        return '₱' + Number(amount || 0).toLocaleString('en-PH', {
            minimumFractionDigits: fractionDigits || 0,
            maximumFractionDigits: fractionDigits || 0
        });
    }

    function localDateString(date) {
        var year = date.getFullYear();
        var month = String(date.getMonth() + 1).padStart(2, '0');
        var day = String(date.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    }

    function expandedRange(control) {
        var fromValue = document.getElementById(control.from).value;
        var toValue = document.getElementById(control.to).value;
        var isYear = document.getElementById(control.unit).value === 'year';

        if (isYear) {
            return { from: fromValue + '-01-01', to: toValue + '-12-31' };
        }

        var toParts = toValue.split('-');
        var lastDay = new Date(Number(toParts[0]), Number(toParts[1]), 0).getDate();
        return {
            from: fromValue + '-01',
            to: toValue + '-' + String(lastDay).padStart(2, '0')
        };
    }

    function setupRangeControl(control, currentMonth) {
        var unit = document.getElementById(control.unit);
        var from = document.getElementById(control.from);
        var to = document.getElementById(control.to);
        from.value = currentMonth;
        to.value = currentMonth;

        unit.addEventListener('change', function () {
            var useYear = unit.value === 'year';
            [from, to].forEach(function (input) {
                var currentValue = input.value;
                input.type = useYear ? 'number' : 'month';
                input.min = useYear ? '2000' : '';
                input.max = useYear ? String(new Date().getFullYear()) : '';
                input.step = useYear ? '1' : '';
                input.value = useYear ? currentValue.slice(0, 4) : currentValue.slice(0, 4) + '-01';
            });
            if (to.value < from.value) {
                to.value = from.value;
            }
            loadDashboard(control.key);
        });

        [from, to].forEach(function (input) {
            input.addEventListener('change', function () {
                if (to.value < from.value) {
                    if (input === from) {
                        to.value = from.value;
                    } else {
                        from.value = to.value;
                    }
                }
                loadDashboard(control.key);
            });
        });
    }

    function setText(id, value) {
        document.getElementById(id).textContent = value;
    }

    function showEmpty(container, text) {
        var empty = document.createElement('p');
        empty.className = 'dashboard-empty';
        empty.textContent = text;
        container.replaceChildren(empty);
    }

    function addSvgElement(name, attributes, text) {
        var element = document.createElementNS(svgNamespace, name);
        Object.keys(attributes).forEach(function (key) {
            element.setAttribute(key, attributes[key]);
        });
        if (text !== undefined) {
            element.textContent = text;
        }
        return element;
    }

    function changeLabel(value, current) {
        if (value === null) {
            return current > 0 ? 'New' : '0.0%';
        }

        var sign = value > 0 ? '+' : '';
        return sign + value.toFixed(1) + '%';
    }

    function renderOverview(overview) {
        setText('todaySales', money(overview.todaySales, 2));
        setText('salesChange', changeLabel(overview.salesChange, overview.todaySales));
        setText('todayOrders', String(overview.todayOrders));
        setText('ordersChange', changeLabel(overview.ordersChange, overview.todayOrders));
        setText('averageOrderValue', money(overview.averageOrderValue, 2));
        setText('openOrders', String(overview.openOrders));

        [
            ['salesChange', overview.salesChange],
            ['ordersChange', overview.ordersChange]
        ].forEach(function (entry) {
            var element = document.getElementById(entry[0]);
            element.classList.toggle('positive', entry[1] !== null && entry[1] > 0);
            element.classList.toggle('negative', entry[1] !== null && entry[1] < 0);
        });

        var statusCounts = overview.statusCounts;
        setText(
            'openOrdersNote',
            statusCounts.PENDING + ' pending · '
                + (statusCounts.CONFIRMED + statusCounts.PREPARING + statusCounts.READY)
                + ' confirmed or in progress'
        );
    }

    function renderTrend(data) {
        var points = data.trend;
        var values = points.map(function (point) {
            return Number(point.amount);
        });
        var total = values.reduce(function (sum, amount) {
            return sum + amount;
        }, 0);
        setText('trendTotal', money(total));

        var width = 800;
        var height = 240;
        var left = 58;
        var right = 12;
        var top = 14;
        var bottom = 32;
        var chartWidth = width - left - right;
        var chartHeight = height - top - bottom;
        var maximum = Math.max.apply(null, values.concat([0]));
        var roundedMaximum = Math.ceil(maximum / 1000) * 1000 || 1000;
        var drawHeight = chartHeight - 8;
        var chartPoints = values.map(function (value, index) {
            return {
                x: left + (points.length === 1 ? chartWidth / 2 : (index / (points.length - 1)) * chartWidth),
                y: top + drawHeight - (value / roundedMaximum) * drawHeight,
                value: value,
                label: points[index].label
            };
        });
        salesChart.replaceChildren();
        salesChart.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
        salesChart.setAttribute(
            'aria-label',
            'Completed sales from ' + data.ranges.trend.from + ' to ' + data.ranges.trend.to
        );

        var defs = addSvgElement('defs', {});
        var gradient = addSvgElement('linearGradient', {
            id: 'salesFill',
            x1: '0',
            x2: '0',
            y1: '0',
            y2: '1'
        });
        gradient.appendChild(addSvgElement('stop', {
            offset: '0%',
            'stop-color': '#9b7656',
            'stop-opacity': '0.22'
        }));
        gradient.appendChild(addSvgElement('stop', {
            offset: '100%',
            'stop-color': '#9b7656',
            'stop-opacity': '0'
        }));
        defs.appendChild(gradient);
        salesChart.appendChild(defs);

        for (var tick = 0; tick <= 4; tick += 1) {
            var y = top + (chartHeight / 4) * tick;
            var tickValue = Math.round(roundedMaximum * (1 - tick / 4));
            salesChart.appendChild(addSvgElement('line', {
                x1: left,
                x2: width - right,
                y1: y,
                y2: y,
                class: 'chart-gridline'
            }));
            salesChart.appendChild(addSvgElement('text', {
                x: left - 9,
                y: y + 3,
                'text-anchor': 'end',
                class: 'chart-axis-label'
            }, money(tickValue)));
        }

        function drawLine(linePoints, className) {
            if (linePoints.length === 0) {
                return;
            }

            var path = linePoints.map(function (point, index) {
                return (index === 0 ? 'M' : 'L') + point.x + ' ' + point.y;
            }).join(' ');
            salesChart.appendChild(addSvgElement('path', {
                d: path,
                class: className
            }));
        }

        if (chartPoints.length > 0) {
            var areaPath = 'M' + chartPoints[0].x + ' ' + (top + drawHeight) + ' '
                + chartPoints.map(function (point) {
                    return 'L' + point.x + ' ' + point.y;
                }).join(' ')
                + ' L' + chartPoints[chartPoints.length - 1].x + ' ' + (top + drawHeight) + ' Z';
            salesChart.appendChild(addSvgElement('path', {
                d: areaPath,
                class: 'chart-area'
            }));
            drawLine(chartPoints, 'chart-line');

            chartPoints.forEach(function (point) {
                var circle = addSvgElement('circle', {
                    cx: point.x,
                    cy: point.y,
                    r: 3.5,
                    class: 'chart-point'
                });
                circle.appendChild(addSvgElement('title', {}, point.label + ': ' + money(point.value)));
                salesChart.appendChild(circle);
            });

            var labelStep = Math.max(1, Math.ceil((points.length - 1) / 7));
            points.forEach(function (point, index) {
                if (index % labelStep !== 0 && index !== points.length - 1) {
                    return;
                }
                var pointX = chartPoints[index].x;
                salesChart.appendChild(addSvgElement('text', {
                    x: pointX,
                    y: height - 8,
                    'text-anchor': index === 0
                        ? 'start'
                        : (index === points.length - 1 ? 'end' : 'middle'),
                    class: 'chart-axis-label'
                }, point.label));
            });
        }
    }

    function renderHeatmap(data) {
        var container = document.getElementById('peakHeatmap');
        var weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        var hourLabels = ['12a', '2a', '4a', '6a', '8a', '10a', '12p', '2p', '4p', '6p', '8p', '10p'];
        var maximum = Math.max.apply(null, data.heatmap.reduce(function (all, day) {
            return all.concat(day);
        }, [0]));
        var scaleMaximum = maximum || 1;

        container.replaceChildren();
        container.appendChild(addHeatmapLabel(''));
        hourLabels.forEach(function (label) {
            container.appendChild(addHeatmapLabel(label));
        });

        data.heatmap.forEach(function (day, dayIndex) {
            container.appendChild(addHeatmapLabel(weekdays[dayIndex]));
            day.forEach(function (count, hourIndex) {
                var intensity = count / scaleMaximum;
                var cell = document.createElement('span');
                cell.className = 'heatmap-cell';
                cell.style.backgroundColor = intensity > 0.72
                    ? '#8a6140'
                    : (intensity > 0.48 ? '#cda982' : (intensity > 0.25 ? '#e8d8c4' : '#f3eee7'));
                cell.setAttribute('aria-hidden', 'true');
                cell.title = weekdays[dayIndex] + ' ' + hourLabels[hourIndex] + ': '
                    + count + ' orders';
                container.appendChild(cell);
            });
        });

        setText('peakHours', maximum > 0 ? data.peakHours : 'No orders yet');
    }

    function addHeatmapLabel(text) {
        var label = document.createElement('span');
        label.className = 'heatmap-label';
        label.textContent = text;
        label.setAttribute('aria-hidden', 'true');
        return label;
    }

    function renderCategories(categories) {
        var container = document.getElementById('categoryBreakdown');
        container.replaceChildren();
        if (categories.length === 0) {
            showEmpty(container, 'No completed sales in this date range.');
            return;
        }

        var maximum = Math.max.apply(null, categories.map(function (category) {
            return Number(category.amount);
        }));
        categories.forEach(function (category) {
            var row = document.createElement('div');
            row.className = 'category-row';

            var name = document.createElement('span');
            name.className = 'category-name';
            name.textContent = category.name;

            var track = document.createElement('div');
            track.className = 'category-track';
            var fill = document.createElement('div');
            fill.className = 'category-fill';
            fill.style.width = maximum > 0 ? ((category.amount / maximum) * 100) + '%' : '0%';
            track.appendChild(fill);

            var value = document.createElement('span');
            value.className = 'category-value';
            value.textContent = money(category.amount);

            row.append(name, track, value);
            container.appendChild(row);
        });
    }

    function renderBestSellers(sellers) {
        var container = document.getElementById('bestSellers');
        container.replaceChildren();
        if (sellers.length === 0) {
            showEmpty(container, 'No products sold in this date range.');
            return;
        }

        var maximumUnits = Math.max.apply(null, sellers.map(function (item) {
            return Number(item.units);
        }));
        sellers.forEach(function (item, index) {
            var row = document.createElement('div');
            row.className = 'seller-row';

            var rank = document.createElement('span');
            rank.className = 'seller-rank';
            rank.textContent = String(index + 1);

            var detail = document.createElement('div');
            detail.className = 'seller-detail';
            var name = document.createElement('span');
            name.className = 'seller-name';
            name.textContent = item.name;
            var units = document.createElement('span');
            units.className = 'seller-units';
            units.textContent = item.units + ' units sold';
            detail.append(name, units);

            var revenue = document.createElement('span');
            revenue.className = 'seller-revenue';
            revenue.textContent = money(item.revenue);

            var track = document.createElement('div');
            track.className = 'seller-track';
            var fill = document.createElement('div');
            fill.className = 'seller-fill';
            fill.style.width = maximumUnits > 0 ? ((item.units / maximumUnits) * 100) + '%' : '0%';
            track.appendChild(fill);

            row.append(rank, detail, revenue, track);
            container.appendChild(row);
        });
    }

    function renderAlerts(alerts) {
        var container = document.getElementById('alertsList');
        container.replaceChildren();
        setText('alertCount', String(alerts.length));

        if (alerts.length === 0) {
            showEmpty(container, 'No inventory or order alerts.');
            return;
        }

        alerts.forEach(function (alert) {
            var row = document.createElement('article');
            row.className = 'alert-item';
            row.dataset.type = alert.type;

            var dot = document.createElement('span');
            dot.className = 'alert-dot';
            dot.setAttribute('aria-hidden', 'true');

            var copy = document.createElement('div');
            copy.className = 'alert-copy';
            var title = document.createElement('p');
            title.className = 'alert-title';
            title.textContent = alert.title;
            var time = document.createElement('p');
            time.className = 'alert-time';
            time.textContent = alert.time;
            copy.append(title, time);

            row.append(dot, copy);
            container.appendChild(row);
        });
    }

    var openOrdersPage = 1;
    var openOrdersPageSize = 5;

    function renderOrdersPagination(orders, pageCount) {
        var pageInfo = document.getElementById('ordersPageInfo');
        var pagination = document.getElementById('ordersPagination');

        if (!pageInfo || !pagination) {
            return;
        }

        var start = (openOrdersPage - 1) * openOrdersPageSize;
        pageInfo.textContent = orders.length === 0
            ? 'No open orders'
            : 'Showing ' + (start + 1) + ' to ' + Math.min(start + openOrdersPageSize, orders.length) +
              ' of ' + orders.length + ' orders';

        pagination.replaceChildren();

        function addButton(label, disabled, active, onClick) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'page-link' + (label === 'Previous' || label === 'Next' ? ' pagination-control' : '') + (active ? ' active' : '');
            button.textContent = label;
            button.disabled = disabled;
            if (active) {
                button.setAttribute('aria-current', 'page');
            }
            button.addEventListener('click', onClick);
            pagination.appendChild(button);
        }

        addButton('Previous', openOrdersPage === 1 || orders.length === 0, false, function () {
            openOrdersPage--;
            renderOrders(orders);
        });

        for (var page = 1; page <= pageCount; page++) {
            (function (target) {
                addButton(String(target), false, target === openOrdersPage, function () {
                    openOrdersPage = target;
                    renderOrders(orders);
                });
            })(page);
        }

        addButton('Next', openOrdersPage === pageCount || orders.length === 0, false, function () {
            openOrdersPage++;
            renderOrders(orders);
        });
    }

    function renderOrders(orders) {
        var tableBody = document.getElementById('ordersTable');
        var pageCount = Math.max(1, Math.ceil(orders.length / openOrdersPageSize));

        if (openOrdersPage > pageCount) {
            openOrdersPage = pageCount;
        }

        var start = (openOrdersPage - 1) * openOrdersPageSize;
        var visibleOrders = orders.slice(start, start + openOrdersPageSize);

        tableBody.replaceChildren();
        if (orders.length === 0) {
            var emptyRow = document.createElement('tr');
            var emptyCell = document.createElement('td');
            emptyCell.colSpan = 6;
            emptyCell.textContent = 'There are no open orders.';
            emptyCell.className = 'dashboard-empty';
            emptyRow.appendChild(emptyCell);
            tableBody.appendChild(emptyRow);
            renderOrdersPagination(orders, pageCount);
            return;
        }

        visibleOrders.forEach(function (order) {
            var row = document.createElement('tr');
            var number = document.createElement('td');
            number.className = 'order-number';
            number.textContent = '#' + order.order_id;

            var time = document.createElement('td');
            time.textContent = order.time;

            var items = document.createElement('td');
            items.className = 'order-items';
            items.textContent = order.items;


            var total = document.createElement('td');
            total.textContent = money(order.total_amount, 2);

            var statusCell = document.createElement('td');
            var status = document.createElement('span');
            status.className = 'order-status';
            status.dataset.status = order.order_status;
            status.textContent = order.order_status.charAt(0)
                + order.order_status.slice(1).toLowerCase();
            statusCell.appendChild(status);

            row.append(number, time, items, total, statusCell);
            tableBody.appendChild(row);
        });

        renderOrdersPagination(orders, pageCount);
    }

    function render(data, changedRangeKeys) {
        var updateAll = !changedRangeKeys;
        var shouldRenderRange = function (key) {
            return updateAll || changedRangeKeys.includes(key);
        };

        if (updateAll) {
            renderOverview(data.overview);
            renderAlerts(data.alerts);
            renderOrders(data.orders);
        }
        if (shouldRenderRange('trend')) {
            renderTrend(data);
        }
        if (shouldRenderRange('peak')) {
            renderHeatmap(data);
        }
        if (shouldRenderRange('category')) {
            renderCategories(data.categories);
        }
        if (shouldRenderRange('seller')) {
            renderBestSellers(data.bestSellers);
        }
    }

    function clearDashboard() {
        ['todaySales', 'salesChange', 'todayOrders', 'ordersChange', 'averageOrderValue', 'openOrders']
            .forEach(function (id) {
                setText(id, 'Unavailable');
            });
        setText('trendTotal', 'Unavailable');
        setText('peakHours', 'Unavailable');
        setText('alertCount', '0');
        ['categoryBreakdown', 'bestSellers', 'alertsList'].forEach(function (id) {
            showEmpty(document.getElementById(id), 'Dashboard data is unavailable.');
        });
        document.getElementById('ordersTable').replaceChildren();
        salesChart.replaceChildren();
    }

    async function loadDashboard(changedRangeKey) {
        if (changedRangeKey) {
            pendingRangeKeys.add(changedRangeKey);
        }
        if (activeRequest) {
            activeRequest.abort();
        }
        var requestController = new AbortController();
        activeRequest = requestController;
        var rangeKeysToRender = pendingRangeKeys.size > 0
            ? Array.from(pendingRangeKeys)
            : null;

        var parameters = new URLSearchParams({
            interval: intervalSelect.value
        });
        rangeControls.forEach(function (control) {
            var range = expandedRange(control);
            parameters.set(control.key + '_from', range.from);
            parameters.set(control.key + '_to', range.to);
        });
        message.classList.add('hidden');
        dashboard.setAttribute('aria-busy', 'true');
        try {
            var response = await fetch('dashboard_data.php?' + parameters.toString(), {
                headers: { Accept: 'application/json' },
                signal: requestController.signal
            });
            var result = await response.json();
            if (!response.ok) {
                throw new Error(result.error || 'Dashboard data could not be loaded.');
            }
            render(result, rangeKeysToRender);
            if (rangeKeysToRender) {
                pendingRangeKeys.clear();
            }
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }
            if (!rangeKeysToRender) {
                clearDashboard();
            } else {
                rangeKeysToRender.forEach(function (key) {
                    if (key === 'trend') {
                        setText('trendTotal', 'Unavailable');
                        salesChart.replaceChildren();
                    } else if (key === 'peak') {
                        setText('peakHours', 'Unavailable');
                        document.getElementById('peakHeatmap').replaceChildren();
                    } else if (key === 'category') {
                        showEmpty(document.getElementById('categoryBreakdown'), 'Sales breakdown is unavailable.');
                    } else if (key === 'seller') {
                        showEmpty(document.getElementById('bestSellers'), 'Best sellers are unavailable.');
                    }
                });
            }
            message.textContent = error.message || 'Dashboard data could not be loaded.';
            message.classList.remove('hidden');
        } finally {
            if (activeRequest === requestController) {
                dashboard.removeAttribute('aria-busy');
            }
        }
    }

    var today = new Date();
    var currentMonth = localDateString(today).slice(0, 7);
    rangeControls.forEach(function (control) {
        setupRangeControl(control, currentMonth);
    });
    intervalSelect.addEventListener('change', function () {
        loadDashboard('trend');
    });
    document.getElementById('printReport').addEventListener('click', function () {
        window.print();
    });

    loadDashboard();
    window.setInterval(function () {
        if (!document.hidden) {
            loadDashboard();
        }
    }, 10000);
});
