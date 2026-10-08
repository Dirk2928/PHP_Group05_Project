document.addEventListener('DOMContentLoaded', function () {
    var svgNamespace = 'http://www.w3.org/2000/svg';
    var salesChart = document.getElementById('salesChart');
    var trendTotal = document.getElementById('trendTotal');
    var dateFrom = document.getElementById('dateFrom');
    var dateTo = document.getElementById('dateTo');
    var intervalSelect = document.getElementById('trendInterval');
    var compareToggle = document.getElementById('compareToggle');
    var comparePeriod = document.getElementById('comparePeriod');
    var alertsList = document.getElementById('alertsList');
    var alertCount = document.getElementById('alertCount');

    var bestSellerData = [
        { name: 'Brown Sugar Latte', units: 42, revenue: 8820 },
        { name: 'Classic Milk Tea', units: 36, revenue: 6480 },
        { name: 'Spanish Latte', units: 31, revenue: 7130 },
        { name: 'Matcha Cream', units: 24, revenue: 5760 },
        { name: 'Cold Brew', units: 19, revenue: 3800 }
    ];

    var categoryData = [
        { name: 'Coffee', amount: 9840 },
        { name: 'Milk tea', amount: 6120 },
        { name: 'Matcha', amount: 3680 },
        { name: 'Other', amount: 2780 }
    ];

    var alertData = [
        { type: 'warning', title: 'Oat milk is running low (4 left)', time: 'Today, 11:48 AM' },
        { type: 'critical', title: 'Refunds are up 18% this afternoon', time: 'Today, 11:36 AM' },
        { type: 'critical', title: 'Payment failed for order #1048', time: 'Today, 11:29 AM' },
        { type: 'info', title: 'Receipt printer is offline', time: 'Today, 11:12 AM' }
    ];

    var orderData = [
        { number: '#1052', time: '11:42 AM', items: '2× Brown Sugar Latte, 1× Croffle', total: 570, status: 'PREPARING' },
        { number: '#1051', time: '11:38 AM', items: '1× Classic Milk Tea, 1× Matcha Cream', total: 380, status: 'READY' },
        { number: '#1050', time: '11:34 AM', items: '2× Spanish Latte', total: 460, status: 'CONFIRMED' },
        { number: '#1049', time: '11:27 AM', items: '1× Cold Brew, 2× Cookies', total: 310, status: 'PENDING' },
        { number: '#1048', time: '11:21 AM', items: '1× Brown Sugar Latte', total: 210, status: 'PENDING' }
    ];

    var stockData = [
        { name: 'Oat milk', quantity: 4, maximum: 30 },
        { name: 'Matcha powder', quantity: 6, maximum: 30 },
        { name: 'Tapioca pearls', quantity: 8, maximum: 30 },
        { name: 'Vanilla syrup', quantity: 9, maximum: 30 }
    ];

    var dailySales = [1240, 1680, 1430, 2180, 1920, 2760, 2340, 3180, 2890, 3520, 3010, 3860, 3420, 4280];

    function money(amount) {
        return '₱' + Number(amount).toLocaleString('en-PH', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        });
    }

    function localDateString(date) {
        var year = date.getFullYear();
        var month = String(date.getMonth() + 1).padStart(2, '0');
        var day = String(date.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    }

    function parseLocalDate(value) {
        var parts = value.split('-').map(Number);
        return new Date(parts[0], parts[1] - 1, parts[2]);
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

    function getTrendLabels(interval, startDate, endDate) {
        if (interval === 'hour') {
            return Array.from({ length: 12 }, function (_, index) {
                var hour = index + 8;
                return {
                    label: (hour > 12 ? hour - 12 : hour) + (hour >= 12 ? ' PM' : ' AM'),
                    title: 'Sales at ' + hour + ':00'
                };
            });
        }

        var dateSpan = Math.max(1, Math.round((endDate - startDate) / 86400000) + 1);
        var bucketCount = interval === 'week'
            ? Math.min(12, Math.ceil(dateSpan / 7))
            : Math.min(14, dateSpan);

        return Array.from({ length: bucketCount }, function (_, index) {
            var offset = bucketCount === 1
                ? 0
                : Math.round((index / (bucketCount - 1)) * (dateSpan - 1));
            var date = new Date(startDate);
            date.setDate(date.getDate() + offset);
            return {
                label: date.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' }),
                title: date.toLocaleDateString('en-PH', { dateStyle: 'long' })
            };
        });
    }

    function renderTrend() {
        if (!dateFrom.value || !dateTo.value) {
            return;
        }

        var startDate = parseLocalDate(dateFrom.value);
        var endDate = parseLocalDate(dateTo.value);
        var interval = intervalSelect.value;
        var labels = getTrendLabels(interval, startDate, endDate);
        var intervalScale = interval === 'hour' ? 0.38 : (interval === 'week' ? 2.1 : 1);
        var values = labels.map(function (_, index) {
            return Math.round(dailySales[index % dailySales.length] * intervalScale);
        });
        var previousValues = values.map(function (value, index) {
            var comparisonScale = comparePeriod.value === 'month' ? 0.68 : 0.82;
            return Math.round(value * (comparisonScale + ((index % 4) * 0.045)));
        });

        var width = 800;
        var height = 240;
        var left = 58;
        var right = 12;
        var top = 14;
        var bottom = 32;
        var chartWidth = width - left - right;
        var chartHeight = height - top - bottom;
        var maximum = Math.max.apply(null, compareToggle.checked ? values.concat(previousValues) : values);
        var roundedMaximum = Math.ceil(maximum / 1000) * 1000 || 1000;
        var drawHeight = chartHeight - 8;
        var points = values.map(function (value, index) {
            return {
                x: left + (labels.length === 1 ? chartWidth / 2 : (index / (labels.length - 1)) * chartWidth),
                y: top + drawHeight - (value / roundedMaximum) * drawHeight,
                value: value,
                title: labels[index].title
            };
        });
        var previousPoints = previousValues.map(function (value, index) {
            return {
                x: points[index].x,
                y: top + drawHeight - (value / roundedMaximum) * drawHeight,
                value: value,
                title: labels[index].title
            };
        });

        salesChart.replaceChildren();
        salesChart.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
        var intervalDescription = interval === 'day' ? 'daily' : (interval === 'week' ? 'weekly' : 'hourly');
        salesChart.setAttribute('aria-label', 'Sales trend ' + intervalDescription + ' from ' + dateFrom.value + ' to ' + dateTo.value);

        var defs = addSvgElement('defs', {});
        var gradient = addSvgElement('linearGradient', { id: 'salesFill', x1: '0', x2: '0', y1: '0', y2: '1' });
        gradient.appendChild(addSvgElement('stop', { offset: '0%', 'stop-color': '#9b7656', 'stop-opacity': '0.22' }));
        gradient.appendChild(addSvgElement('stop', { offset: '100%', 'stop-color': '#9b7656', 'stop-opacity': '0' }));
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
            }, tickValue === 0 ? '₱0' : '₱' + (tickValue / 1000) + 'k'));
        }

        var pointsPath = points.map(function (point, index) {
            return (index === 0 ? 'M' : 'L') + point.x + ' ' + point.y;
        }).join(' ');

        if (compareToggle.checked) {
            var previousPath = previousPoints.map(function (point, index) {
                return (index === 0 ? 'M' : 'L') + point.x + ' ' + point.y;
            }).join(' ');
            salesChart.appendChild(addSvgElement('path', { d: previousPath, class: 'chart-previous-line' }));
        }

        salesChart.appendChild(addSvgElement('path', {
            d: pointsPath + ' L' + points[points.length - 1].x + ' ' + (top + chartHeight) +
                ' L' + points[0].x + ' ' + (top + chartHeight) + ' Z',
            class: 'chart-area'
        }));
        salesChart.appendChild(addSvgElement('path', { d: pointsPath, class: 'chart-line' }));

        points.forEach(function (point, index) {
            var circle = addSvgElement('circle', {
                cx: point.x,
                cy: point.y,
                r: 4,
                class: 'chart-point'
            });
            circle.appendChild(addSvgElement('title', {}, point.title + ': ' + money(point.value)));
            salesChart.appendChild(circle);

            var shouldShowLabel = labels.length <= 8 || index % Math.ceil(labels.length / 7) === 0 || index === labels.length - 1;
            if (shouldShowLabel) {
                salesChart.appendChild(addSvgElement('text', {
                    x: point.x,
                    y: height - 8,
                    'text-anchor': 'middle',
                    class: 'chart-axis-label'
                }, labels[index].label));
            }
        });

        trendTotal.textContent = money(values.reduce(function (total, value) {
            return total + value;
        }, 0));

        var previousLegend = document.querySelector('.legend-previous');
        var previousLegendLabel = previousLegend && previousLegend.nextSibling;
        if (previousLegend) {
            previousLegend.style.display = compareToggle.checked ? '' : 'none';
            if (previousLegendLabel && previousLegendLabel.nodeType === Node.TEXT_NODE) {
                previousLegendLabel.textContent = compareToggle.checked ? ' Previous period' : '';
            }
        }
    }

    function renderHeatmap() {
        var heatmap = document.getElementById('peakHeatmap');
        var weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        var hourLabels = ['8a', '10a', '12p', '2p', '4p', '6p', '8p', '10p'];
        heatmap.replaceChildren();
        heatmap.appendChild(addHeatmapLabel(''));
        hourLabels.forEach(function (label) {
            heatmap.appendChild(addHeatmapLabel(label));
        });

        weekdays.forEach(function (weekday, dayIndex) {
            heatmap.appendChild(addHeatmapLabel(weekday));
            hourLabels.forEach(function (hour, hourIndex) {
                var intensity = ((dayIndex * 3 + hourIndex * 5 + dayIndex * hourIndex) % 11) / 10;
                var cell = document.createElement('span');
                cell.className = 'heatmap-cell';
                cell.style.backgroundColor = intensity > 0.72
                    ? '#8a6140'
                    : (intensity > 0.48 ? '#cda982' : (intensity > 0.25 ? '#e8d8c4' : '#f3eee7'));
                cell.setAttribute('aria-hidden', 'true');
                cell.title = weekday + ' ' + hour + ': ' + Math.round(4 + intensity * 30) + ' orders';
                heatmap.appendChild(cell);
            });
        });
    }

    function addHeatmapLabel(text) {
        var label = document.createElement('span');
        label.className = 'heatmap-label';
        label.textContent = text;
        label.setAttribute('aria-hidden', 'true');
        return label;
    }

    function renderCategories() {
        var container = document.getElementById('categoryBreakdown');
        var maximum = Math.max.apply(null, categoryData.map(function (category) {
            return category.amount;
        }));
        container.replaceChildren();

        categoryData.forEach(function (category) {
            var row = document.createElement('div');
            row.className = 'category-row';

            var name = document.createElement('span');
            name.className = 'category-name';
            name.textContent = category.name;

            var track = document.createElement('div');
            track.className = 'category-track';
            var fill = document.createElement('div');
            fill.className = 'category-fill';
            fill.style.width = ((category.amount / maximum) * 100) + '%';
            track.appendChild(fill);

            var value = document.createElement('span');
            value.className = 'category-value';
            value.textContent = money(category.amount);

            row.append(name, track, value);
            container.appendChild(row);
        });
    }

    function renderBestSellers() {
        var container = document.getElementById('bestSellers');
        var maximumUnits = Math.max.apply(null, bestSellerData.map(function (item) {
            return item.units;
        }));
        container.replaceChildren();

        bestSellerData.forEach(function (item, index) {
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
            fill.style.width = ((item.units / maximumUnits) * 100) + '%';
            track.appendChild(fill);

            row.append(rank, detail, revenue, track);
            container.appendChild(row);
        });
    }

    function renderAlerts() {
        alertsList.replaceChildren();
        alertData.forEach(function (alert, index) {
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

            var dismiss = document.createElement('button');
            dismiss.className = 'alert-dismiss';
            dismiss.type = 'button';
            dismiss.textContent = 'Dismiss';
            dismiss.setAttribute('aria-label', 'Dismiss alert: ' + alert.title);
            dismiss.addEventListener('click', function () {
                alertData.splice(index, 1);
                renderAlerts();
            });

            row.append(dot, copy, dismiss);
            alertsList.appendChild(row);
        });
        alertCount.textContent = String(alertData.length);
    }

    function renderOrders() {
        var tableBody = document.getElementById('ordersTable');
        tableBody.replaceChildren();

        orderData.forEach(function (order) {
            var row = document.createElement('tr');
            var number = document.createElement('td');
            number.className = 'order-number';
            number.textContent = order.number;

            var time = document.createElement('td');
            time.textContent = order.time;

            var items = document.createElement('td');
            items.className = 'order-items';
            items.textContent = order.items;

            var total = document.createElement('td');
            total.textContent = money(order.total);

            var statusCell = document.createElement('td');
            var status = document.createElement('span');
            status.className = 'order-status';
            status.dataset.status = order.status;
            status.textContent = order.status.charAt(0) + order.status.slice(1).toLowerCase();
            statusCell.appendChild(status);

            row.append(number, time, items, total, statusCell);
            tableBody.appendChild(row);
        });
    }

    function renderStock() {
        var container = document.getElementById('lowStockList');
        container.replaceChildren();

        stockData.forEach(function (item) {
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
            fill.style.width = ((item.quantity / item.maximum) * 100) + '%';
            track.appendChild(fill);

            row.append(name, quantity, track);
            container.appendChild(row);
        });
    }

    function csvEscape(value) {
        return '"' + String(value).replace(/"/g, '""') + '"';
    }

    function exportCsv() {
        var rows = [
            ['Section', 'Item', 'Detail', 'Value', 'Timestamp'],
            ['Summary', "Today's sales", '', '18420.00', localDateString(new Date())],
            ['Summary', 'Number of orders', '', '86', localDateString(new Date())],
            ['Summary', 'Average order value', '', '214.19', localDateString(new Date())],
            ['Summary', 'Active / pending orders', '3 pending, 9 in progress', '12', localDateString(new Date())]
        ];

        orderData.forEach(function (order) {
            rows.push(['Live order', order.number, order.items, order.total.toFixed(2), order.time]);
        });
        bestSellerData.forEach(function (item) {
            rows.push(['Best seller', item.name, item.units + ' units', item.revenue.toFixed(2), '']);
        });
        stockData.forEach(function (item) {
            rows.push(['Low stock', item.name, item.quantity + ' remaining', '', '']);
        });
        alertData.forEach(function (alert) {
            rows.push(['Alert', alert.title, alert.type, '', alert.time]);
        });

        var csv = rows.map(function (row) {
            return row.map(csvEscape).join(',');
        }).join('\r\n');
        var blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8' });
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = 'brewski-dashboard-' + localDateString(new Date()) + '.csv';
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
        renderTrend();
    });
    dateTo.addEventListener('change', function () {
        if (dateTo.value < dateFrom.value) {
            dateFrom.value = dateTo.value;
        }
        dateFrom.max = dateTo.value;
        renderTrend();
    });
    intervalSelect.addEventListener('change', renderTrend);
    compareToggle.addEventListener('change', function () {
        comparePeriod.disabled = !compareToggle.checked;
        renderTrend();
    });
    comparePeriod.addEventListener('change', renderTrend);
    document.getElementById('exportCsv').addEventListener('click', exportCsv);
    document.getElementById('exportPdf').addEventListener('click', function () {
        window.print();
    });

    renderTrend();
    renderHeatmap();
    renderCategories();
    renderBestSellers();
    renderAlerts();
    renderOrders();
    renderStock();
});
