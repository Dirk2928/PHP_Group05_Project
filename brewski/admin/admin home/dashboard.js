document.addEventListener('DOMContentLoaded', function () {
    var svgNamespace = 'http://www.w3.org/2000/svg';
    var dashboard = document.getElementById('homeView');
    var salesChart = document.getElementById('salesChart');
    var dateFrom = document.getElementById('dateFrom');
    var dateTo = document.getElementById('dateTo');
    var intervalSelect = document.getElementById('trendInterval');
    var compareToggle = document.getElementById('compareToggle');
    var comparePeriod = document.getElementById('comparePeriod');
    var message = document.getElementById('dashboardMessage');
    var dashboardData = null;
    var activeRequest = null;

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
        var hasComparison = data.comparison !== '';
        var previousValues = points.map(function (point) {
            return Number(point.previous || 0);
        });
        var total = values.reduce(function (sum, amount) {
            return sum + amount;
        }, 0);
        setText('trendTotal', money(total));

        var previousLegend = document.querySelector('.legend-previous');
        var previousLegendLabel = previousLegend && previousLegend.nextSibling;
        if (previousLegend) {
            previousLegend.style.display = hasComparison ? '' : 'none';
            if (previousLegendLabel && previousLegendLabel.nodeType === Node.TEXT_NODE) {
                previousLegendLabel.textContent = hasComparison
                    ? (data.comparison === 'week' ? ' Previous week' : ' Previous month')
                    : '';
            }
        }

        var width = 800;
        var height = 240;
        var left = 58;
        var right = 12;
        var top = 14;
        var bottom = 32;
        var chartWidth = width - left - right;
        var chartHeight = height - top - bottom;
        var allValues = hasComparison ? values.concat(previousValues) : values;
        var maximum = Math.max.apply(null, allValues.concat([0]));
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
        var previousPoints = previousValues.map(function (value, index) {
            return {
                x: chartPoints[index].x,
                y: top + drawHeight - (value / roundedMaximum) * drawHeight,
                value: value,
                label: points[index].label
            };
        });

        salesChart.replaceChildren();
        salesChart.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
        salesChart.setAttribute(
            'aria-label',
            'Completed sales from ' + data.range.from + ' to ' + data.range.to
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
            if (hasComparison) {
                drawLine(previousPoints, 'chart-previous-line');
            }
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

            var labelStep = Math.max(1, Math.ceil(points.length / 8));
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
        var hourLabels = ['8a', '10a', '12p', '2p', '4p', '6p', '8p', '10p'];
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

    function renderOrders(orders) {
        var tableBody = document.getElementById('ordersTable');
        tableBody.replaceChildren();
        if (orders.length === 0) {
            var emptyRow = document.createElement('tr');
            var emptyCell = document.createElement('td');
            emptyCell.colSpan = 5;
            emptyCell.textContent = 'There are no open orders.';
            emptyCell.className = 'dashboard-empty';
            emptyRow.appendChild(emptyCell);
            tableBody.appendChild(emptyRow);
            return;
        }

        orders.forEach(function (order) {
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
    }

    function renderStock(items, count) {
        var container = document.getElementById('lowStockList');
        container.replaceChildren();
        setText('lowStockCount', count + ' items');
        if (items.length === 0) {
            showEmpty(container, 'No products are at or below the low-stock threshold.');
            return;
        }

        items.forEach(function (item) {
            var row = document.createElement('div');
            row.className = 'stock-row';

            var name = document.createElement('span');
            name.className = 'stock-name';
            name.textContent = item.name;

            var quantity = document.createElement('span');
            quantity.className = 'stock-quantity';
            quantity.textContent = item.quantity + ' left';

            var track = document.createElement('div');
            track.className = 'stock-track';
            var fill = document.createElement('div');
            fill.className = 'stock-fill';
            fill.style.width = Math.min(100, (item.quantity / 10) * 100) + '%';
            track.appendChild(fill);

            row.append(name, quantity, track);
            container.appendChild(row);
        });
    }

    function render(data) {
        renderOverview(data.overview);
        renderTrend(data);
        renderHeatmap(data);
        renderCategories(data.categories);
        renderBestSellers(data.bestSellers);
        renderAlerts(data.alerts);
        renderOrders(data.orders);
        renderStock(data.lowStock, data.lowStockCount);
    }

    function clearDashboard() {
        ['todaySales', 'salesChange', 'todayOrders', 'ordersChange', 'averageOrderValue', 'openOrders']
            .forEach(function (id) {
                setText(id, 'Unavailable');
            });
        setText('trendTotal', 'Unavailable');
        setText('peakHours', 'Unavailable');
        setText('lowStockCount', 'Unavailable');
        setText('alertCount', '0');
        ['categoryBreakdown', 'bestSellers', 'alertsList', 'lowStockList'].forEach(function (id) {
            showEmpty(document.getElementById(id), 'Dashboard data is unavailable.');
        });
        document.getElementById('ordersTable').replaceChildren();
        salesChart.replaceChildren();
    }

    async function loadDashboard() {
        if (activeRequest) {
            activeRequest.abort();
        }
        activeRequest = new AbortController();

        var parameters = new URLSearchParams({
            from: dateFrom.value,
            to: dateTo.value,
            interval: intervalSelect.value
        });
        if (compareToggle.checked) {
            parameters.set('compare', comparePeriod.value);
        }

        message.classList.add('hidden');
        dashboard.setAttribute('aria-busy', 'true');
        try {
            var response = await fetch('dashboard_data.php?' + parameters.toString(), {
                headers: { Accept: 'application/json' },
                signal: activeRequest.signal
            });
            var result = await response.json();
            if (!response.ok) {
                throw new Error(result.error || 'Dashboard data could not be loaded.');
            }
            dashboardData = result;
            render(result);
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }
            dashboardData = null;
            clearDashboard();
            message.textContent = error.message || 'Dashboard data could not be loaded.';
            message.classList.remove('hidden');
        } finally {
            dashboard.removeAttribute('aria-busy');
        }
    }

    function csvEscape(value) {
        return '"' + String(value).replace(/"/g, '""') + '"';
    }

    function exportCsv() {
        if (!dashboardData) {
            return;
        }

        var overview = dashboardData.overview;
        var rows = [
            ['Section', 'Item', 'Detail', 'Value', 'Timestamp'],
            ['Summary', "Today's sales", '', overview.todaySales.toFixed(2), dashboardData.range.to],
            ['Summary', 'Number of orders', '', overview.todayOrders, dashboardData.range.to],
            ['Summary', 'Average order value', '', overview.averageOrderValue.toFixed(2), dashboardData.range.to],
            ['Summary', 'Open orders', JSON.stringify(overview.statusCounts), overview.openOrders, ''],
            ['Sales trend', 'Selected range total', dashboardData.range.from + ' to ' + dashboardData.range.to,
                dashboardData.trend.reduce(function (sum, point) { return sum + Number(point.amount); }, 0).toFixed(2), '']
        ];

        dashboardData.orders.forEach(function (order) {
            rows.push([
                'Open order',
                '#' + order.order_id,
                order.items,
                order.total_amount.toFixed(2),
                order.time
            ]);
        });
        dashboardData.bestSellers.forEach(function (item) {
            rows.push(['Best seller', item.name, item.units + ' units', item.revenue.toFixed(2), '']);
        });
        dashboardData.lowStock.forEach(function (item) {
            rows.push(['Low stock', item.name, item.quantity + ' remaining', '', '']);
        });
        dashboardData.alerts.forEach(function (alert) {
            rows.push(['Alert', alert.title, alert.type, '', alert.time]);
        });

        var csv = rows.map(function (row) {
            return row.map(csvEscape).join(',');
        }).join('\r\n');
        var blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8' });
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = 'brewski-dashboard-' + dashboardData.range.from + '.csv';
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(function () {
            URL.revokeObjectURL(url);
        }, 1000);
    }

    var today = new Date();
    var firstDate = new Date(today);
    firstDate.setDate(firstDate.getDate() - 6);
    dateFrom.value = localDateString(firstDate);
    dateTo.value = localDateString(today);
    dateTo.min = dateFrom.value;

    dateFrom.addEventListener('change', function () {
        dateTo.min = dateFrom.value;
        if (dateTo.value < dateFrom.value) {
            dateTo.value = dateFrom.value;
        }
        loadDashboard();
    });
    dateTo.addEventListener('change', function () {
        if (dateTo.value < dateFrom.value) {
            dateFrom.value = dateTo.value;
        }
        dateFrom.max = dateTo.value;
        loadDashboard();
    });
    intervalSelect.addEventListener('change', loadDashboard);
    compareToggle.addEventListener('change', function () {
        comparePeriod.disabled = !compareToggle.checked;
        loadDashboard();
    });
    comparePeriod.addEventListener('change', loadDashboard);
    document.getElementById('exportCsv').addEventListener('click', exportCsv);
    document.getElementById('exportPdf').addEventListener('click', function () {
        window.print();
    });

    loadDashboard();
});
