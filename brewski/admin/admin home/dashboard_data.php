<?php

define('SESSION_NO_TOUCH', true);
require_once __DIR__ . '/../../login-signup/session_init.php';
require_once __DIR__ . '/../../Db/db_config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (
    empty($_SESSION['user_id'])
    || strtoupper((string) ($_SESSION['role'] ?? '')) !== 'ADMIN'
) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

session_write_close();

function dashboard_json_error(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

function dashboard_parse_date(string $value): ?DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();

    if (
        !$date
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d') !== $value
    ) {
        return null;
    }

    return $date;
}

function dashboard_fetch_rows(mysqli $connection, string $sql): array
{
    $result = $connection->query($sql);
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();

    return $rows;
}

function dashboard_sales_buckets(
    mysqli $connection,
    string $startDate,
    string $endDate,
    string $interval
): array {
    if ($interval === 'hour') {
        $bucketExpression = 'HOUR(o.order_date)';
    } elseif ($interval === 'week') {
        $bucketExpression = 'DATE_SUB(DATE(o.order_date), INTERVAL WEEKDAY(o.order_date) DAY)';
    } elseif ($interval === 'month') {
        $bucketExpression = "DATE_FORMAT(o.order_date, '%Y-%m-01')";
    } elseif ($interval === 'year') {
        $bucketExpression = "DATE_FORMAT(o.order_date, '%Y-01-01')";
    } else {
        $bucketExpression = 'DATE(o.order_date)';
    }

    $rows = dashboard_fetch_rows(
        $connection,
        "SELECT {$bucketExpression} AS bucket, SUM(o.total_amount) AS amount
         FROM orders o
        LEFT JOIN order_payments op ON op.order_id = o.order_id
        WHERE o.order_status = 'COMPLETED'
          AND (op.payment_status IS NULL OR op.payment_status = 'PAID')
           AND o.order_date >= '{$startDate}'
           AND o.order_date < DATE_ADD('{$endDate}', INTERVAL 1 DAY)
         GROUP BY bucket
         ORDER BY bucket"
    );

    $buckets = [];
    foreach ($rows as $row) {
        $buckets[(string) $row['bucket']] = (float) $row['amount'];
    }

    return $buckets;
}

$today = new DateTimeImmutable('today');
$defaultStart = $today->modify('first day of this month');
$defaultEnd = $today->modify('last day of this month');

function dashboard_request_range(string $key, DateTimeImmutable $defaultStart, DateTimeImmutable $defaultEnd): array
{
    $requestedStart = $_GET[$key . '_from'] ?? $_GET['from'] ?? $defaultStart->format('Y-m-d');
    $requestedEnd = $_GET[$key . '_to'] ?? $_GET['to'] ?? $defaultEnd->format('Y-m-d');
    $start = is_string($requestedStart) ? dashboard_parse_date($requestedStart) : null;
    $end = is_string($requestedEnd) ? dashboard_parse_date($requestedEnd) : null;

    if (!$start || !$end || $end < $start) {
        dashboard_json_error(400, 'Choose a valid date range for ' . $key . '.');
    }

    if ($start->diff($end)->days > 3659) {
        dashboard_json_error(400, 'A date range cannot exceed 10 years.');
    }

    return [$start, $end];
}

[$trendStart, $trendEnd] = dashboard_request_range('trend', $defaultStart, $defaultEnd);
[$sellerStart, $sellerEnd] = dashboard_request_range('seller', $defaultStart, $defaultEnd);
[$peakStart, $peakEnd] = dashboard_request_range('peak', $defaultStart, $defaultEnd);
[$categoryStart, $categoryEnd] = dashboard_request_range('category', $defaultStart, $defaultEnd);
$requestedInterval = $_GET['interval'] ?? 'day';
$interval = is_string($requestedInterval) ? $requestedInterval : '';

if (!in_array($interval, ['hour', 'day', 'week', 'month', 'year'], true)) {
    dashboard_json_error(400, 'Choose a valid sales trend interval.');
}

$trendStartDate = $trendStart->format('Y-m-d');
$trendEndDate = $trendEnd->format('Y-m-d');
$sellerStartDate = $sellerStart->format('Y-m-d');
$sellerEndDate = $sellerEnd->format('Y-m-d');
$peakStartDate = $peakStart->format('Y-m-d');
$peakEndDate = $peakEnd->format('Y-m-d');
$categoryStartDate = $categoryStart->format('Y-m-d');
$categoryEndDate = $categoryEnd->format('Y-m-d');
$todayDate = $today->format('Y-m-d');
$yesterdayDate = $today->modify('-1 day')->format('Y-m-d');

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $connection = brewski_db_connection();

    $dailySummary = dashboard_fetch_rows(
        $connection,
        "SELECT COUNT(*) AS order_count,
                COALESCE(SUM(CASE WHEN order_status = 'COMPLETED' THEN total_amount ELSE 0 END), 0) AS sales,
                COUNT(CASE WHEN order_status = 'COMPLETED' THEN 1 END) AS completed_count
         FROM orders o
         LEFT JOIN order_payments op ON op.order_id = o.order_id
         WHERE o.order_date >= '{$todayDate}'
           AND o.order_date < DATE_ADD('{$todayDate}', INTERVAL 1 DAY)
           AND (op.payment_status IS NULL OR op.payment_status = 'PAID')"
    )[0];
    $yesterdaySummary = dashboard_fetch_rows(
        $connection,
        "SELECT COUNT(*) AS order_count,
                COALESCE(SUM(CASE WHEN order_status = 'COMPLETED' THEN total_amount ELSE 0 END), 0) AS sales
         FROM orders o
         LEFT JOIN order_payments op ON op.order_id = o.order_id
         WHERE o.order_date >= '{$yesterdayDate}'
           AND o.order_date < DATE_ADD('{$yesterdayDate}', INTERVAL 1 DAY)
           AND (op.payment_status IS NULL OR op.payment_status = 'PAID')"
    )[0];
    $statusRows = dashboard_fetch_rows(
        $connection,
        "SELECT o.order_status, COUNT(*) AS order_count
         FROM orders o
         LEFT JOIN order_payments op ON op.order_id = o.order_id
         WHERE o.order_status IN ('PENDING', 'CONFIRMED', 'PREPARING', 'READY')
           AND (op.payment_status IS NULL OR op.payment_status = 'PAID')
         GROUP BY o.order_status"
    );

    $statusCounts = [
        'PENDING' => 0,
        'CONFIRMED' => 0,
        'PREPARING' => 0,
        'READY' => 0,
    ];
    foreach ($statusRows as $row) {
        $statusCounts[$row['order_status']] = (int) $row['order_count'];
    }

    $makeChange = static function (float $current, float $previous): ?float {
        if ($previous === 0.0) {
            return $current === 0.0 ? 0.0 : null;
        }

        return (($current - $previous) / $previous) * 100;
    };

    $trendBuckets = dashboard_sales_buckets($connection, $trendStartDate, $trendEndDate, $interval);
    $trendLabels = [];
    if ($interval === 'hour') {
        for ($hour = 0; $hour < 24; $hour++) {
            $trendLabels[] = [
                'key' => (string) $hour,
                'label' => date('g A', mktime($hour, 0)),
            ];
        }
    } else {
        $step = match ($interval) {
            'week' => '+1 week',
            'month' => '+1 month',
            'year' => '+1 year',
            default => '+1 day',
        };
        $cursor = match ($interval) {
            'week' => $trendStart->modify('monday this week'),
            'month' => $trendStart->modify('first day of this month'),
            'year' => $trendStart->setDate((int) $trendStart->format('Y'), 1, 1),
            default => $trendStart,
        };
        $lastBucket = match ($interval) {
            'week' => $trendEnd->modify('monday this week'),
            'month' => $trendEnd->modify('first day of this month'),
            'year' => $trendEnd->setDate((int) $trendEnd->format('Y'), 1, 1),
            default => $trendEnd,
        };

        while ($cursor <= $lastBucket) {
            $key = $cursor->format('Y-m-d');
            $trendLabels[] = [
                'key' => $key,
                'label' => match ($interval) {
                    'month' => $cursor->format('M Y'),
                    'year' => $cursor->format('Y'),
                    default => $cursor->format('M j'),
                },
            ];
            $cursor = $cursor->modify($step);
        }
    }

    $trend = [];
    foreach ($trendLabels as $label) {
        $trend[] = [
            'label' => $label['label'],
            'amount' => $trendBuckets[$label['key']] ?? 0,
        ];
    }

    $categorySales = dashboard_fetch_rows(
        $connection,
        "SELECT c.category_name AS name, SUM(oi.subtotal) AS amount
         FROM orders o
         INNER JOIN order_items oi ON oi.order_id = o.order_id
         INNER JOIN products p ON p.product_id = oi.product_id
         INNER JOIN categories c ON c.category_id = p.category_id
         LEFT JOIN order_payments op ON op.order_id = o.order_id
         WHERE o.order_status = 'COMPLETED'
           AND (op.payment_status IS NULL OR op.payment_status = 'PAID')
           AND o.order_date >= '{$categoryStartDate}'
           AND o.order_date < DATE_ADD('{$categoryEndDate}', INTERVAL 1 DAY)
         GROUP BY c.category_id, c.category_name
         ORDER BY amount DESC"
    );
    foreach ($categorySales as &$category) {
        $category['amount'] = (float) $category['amount'];
    }
    unset($category);

    $bestSellers = dashboard_fetch_rows(
        $connection,
        "SELECT p.product_name AS name,
                SUM(oi.quantity) AS units,
                SUM(oi.subtotal) AS revenue
         FROM orders o
         INNER JOIN order_items oi ON oi.order_id = o.order_id
         INNER JOIN products p ON p.product_id = oi.product_id
         LEFT JOIN order_payments op ON op.order_id = o.order_id
         WHERE o.order_status = 'COMPLETED'
           AND (op.payment_status IS NULL OR op.payment_status = 'PAID')
           AND o.order_date >= '{$sellerStartDate}'
           AND o.order_date < DATE_ADD('{$sellerEndDate}', INTERVAL 1 DAY)
         GROUP BY p.product_id, p.product_name
         ORDER BY units DESC, revenue DESC
         LIMIT 5"
    );
    foreach ($bestSellers as &$seller) {
        $seller['units'] = (int) $seller['units'];
        $seller['revenue'] = (float) $seller['revenue'];
    }
    unset($seller);

    $heatmap = array_fill(0, 7, array_fill(0, 8, 0));
    $heatmapRows = dashboard_fetch_rows(
        $connection,
        "SELECT WEEKDAY(o.order_date) AS weekday,
                FLOOR(HOUR(o.order_date) / 2) - 4 AS hour_slot,
                COUNT(DISTINCT o.order_id) AS order_count
         FROM orders o
         LEFT JOIN order_payments op ON op.order_id = o.order_id
         WHERE o.order_status <> 'CANCELLED'
           AND (op.payment_status IS NULL OR op.payment_status = 'PAID')
           AND o.order_date >= '{$peakStartDate}'
           AND o.order_date < DATE_ADD('{$peakEndDate}', INTERVAL 1 DAY)
           AND HOUR(o.order_date) >= 8
         GROUP BY weekday, hour_slot"
    );
    foreach ($heatmapRows as $row) {
        $weekday = (int) $row['weekday'];
        $hourSlot = (int) $row['hour_slot'];
        if ($weekday >= 0 && $weekday < 7 && $hourSlot >= 0 && $hourSlot < 8) {
            $heatmap[$weekday][$hourSlot] = (int) $row['order_count'];
        }
    }

    $hourTotals = array_fill(0, 8, 0);
    foreach ($heatmap as $day) {
        foreach ($day as $hourSlot => $count) {
            $hourTotals[$hourSlot] += $count;
        }
    }
    $maximumHourCount = max($hourTotals);
    if ($maximumHourCount > 0) {
        $peakSlot = array_search($maximumHourCount, $hourTotals, true);
        $peakHour = 8 + ((int) $peakSlot * 2);
        $peakHours = date('g A', mktime($peakHour, 0))
            . ' – '
            . date('g A', mktime($peakHour + 2, 0));
    } else {
        $peakHours = '';
    }

    $orders = dashboard_fetch_rows(
        $connection,
        "SELECT o.order_id,
                o.order_date,
                o.total_amount,
                o.order_status,
                COALESCE(GROUP_CONCAT(
                    CONCAT(oi.quantity, 'x ', p.product_name)
                    ORDER BY oi.order_item_id SEPARATOR ', '
                ), 'No items') AS items
         FROM orders o
         LEFT JOIN order_payments op ON op.order_id = o.order_id
         LEFT JOIN order_items oi ON oi.order_id = o.order_id
         LEFT JOIN products p ON p.product_id = oi.product_id
         WHERE o.order_status IN ('PENDING', 'CONFIRMED', 'PREPARING', 'READY')
           AND (op.payment_status IS NULL OR op.payment_status = 'PAID')
         GROUP BY o.order_id, o.order_date, o.total_amount, o.order_status
         ORDER BY o.order_date DESC
         LIMIT 5"
    );
    foreach ($orders as &$order) {
        $order['order_id'] = (int) $order['order_id'];
        $order['total_amount'] = (float) $order['total_amount'];
        $order['time'] = (new DateTimeImmutable($order['order_date']))->format('M j, g:i A');
    }
    unset($order);

    $alerts = [];
    $unavailableProducts = dashboard_fetch_rows(
        $connection,
        "SELECT product_name AS name
         FROM products
         WHERE availability = 0
         ORDER BY product_name ASC"
    );
    foreach ($unavailableProducts as $item) {
        $alerts[] = [
            'type' => 'critical',
            'title' => $item['name'] . ' — unavailable for orders',
            'time' => 'Product availability',
        ];
    }

    $pendingCount = $statusCounts['PENDING'];
    if ($pendingCount > 0) {
        $alerts[] = [
            'type' => 'info',
            'title' => $pendingCount . ' order' . ($pendingCount === 1 ? '' : 's') . ' awaiting confirmation',
            'time' => 'Order queue',
        ];
    }

    echo json_encode([
        'overview' => [
            'todaySales' => (float) $dailySummary['sales'],
            'salesChange' => $makeChange(
                (float) $dailySummary['sales'],
                (float) $yesterdaySummary['sales']
            ),
            'todayOrders' => (int) $dailySummary['order_count'],
            'ordersChange' => $makeChange(
                (float) $dailySummary['order_count'],
                (float) $yesterdaySummary['order_count']
            ),
            'averageOrderValue' => (int) $dailySummary['completed_count'] > 0
                ? (float) $dailySummary['sales'] / (int) $dailySummary['completed_count']
                : 0,
            'openOrders' => array_sum($statusCounts),
            'statusCounts' => $statusCounts,
        ],
        'trend' => $trend,
        'categories' => $categorySales,
        'bestSellers' => $bestSellers,
        'heatmap' => $heatmap,
        'peakHours' => $peakHours,
        'orders' => $orders,
        'alerts' => $alerts,
        'ranges' => [
            'trend' => ['from' => $trendStartDate, 'to' => $trendEndDate],
            'seller' => ['from' => $sellerStartDate, 'to' => $sellerEndDate],
            'peak' => ['from' => $peakStartDate, 'to' => $peakEndDate],
            'category' => ['from' => $categoryStartDate, 'to' => $categoryEndDate],
        ],
    ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

    $connection->close();
} catch (Throwable $error) {
    error_log('Dashboard data query failed: ' . $error->getMessage());
    dashboard_json_error(500, 'Dashboard data could not be loaded. Please try again.');
}
