<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
define('ACCESS_CHECK', true);

require_once 'config.php';
require_once 'jdf.php';

function convertToJalali($gregorianDateTime, $format = 'Y/m/d H:i:s') {
    if (!$gregorianDateTime || $gregorianDateTime == '0000-00-00 00:00:00') return '';
    try {
        $timestamp = strtotime($gregorianDateTime);
        if ($timestamp === false) return $gregorianDateTime;
        return jdate($format, $timestamp, '', 'Asia/Tehran', 'fa');
    } catch (Exception $e) {
        return $gregorianDateTime;
    }
}

function trendBadge($change, $unit = '') {
    if ($change > 0) return '<span style="color:green; font-size:0.8em;">▲ +' . number_format($change) . $unit . '</span>';
    elseif ($change < 0) return '<span style="color:red; font-size:0.8em;">▼ ' . number_format($change) . $unit . '</span>';
    return '<span style="color:gray; font-size:0.8em;">─ 0</span>';
}

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

// آمار اصلی
$total_today = $conn->query("SELECT COUNT(id) AS total FROM visitors WHERE entry_date = '$today'")->fetch_assoc()['total'];
$present_cars = $conn->query("SELECT COUNT(id) AS total FROM visitors WHERE exit_time IS NULL")->fetch_assoc()['total'];
$exited_today = $conn->query("SELECT COUNT(id) AS total FROM visitors WHERE exit_time IS NOT NULL AND DATE(exit_time) = '$today'")->fetch_assoc()['total'];

$income_result = $conn->query("SELECT SUM(paid_amount) AS total FROM visitors WHERE payment_method != 'پرداخت نشده' AND entry_date = '$today'");
$total_income = $income_result->fetch_assoc()['total'] ?? 0;

$unpaid_count = $conn->query("SELECT COUNT(id) AS total FROM visitors WHERE payment_method = 'پرداخت نشده' AND entry_date = '$today'")->fetch_assoc()['total'];

$total_yesterday = $conn->query("SELECT COUNT(id) AS total FROM visitors WHERE entry_date = '$yesterday'")->fetch_assoc()['total'] ?? 0;
$income_yesterday = $conn->query("SELECT SUM(paid_amount) AS total FROM visitors WHERE payment_method != 'پرداخت نشده' AND entry_date = '$yesterday'")->fetch_assoc()['total'] ?? 0;

$visitorChange = $total_today - $total_yesterday;
$incomeChange  = $total_income - $income_yesterday;

// میانگین توقف
$avgDurationQuery = $conn->query("SELECT AVG(TIMESTAMPDIFF(MINUTE, entry_time, exit_time)) as avg_min FROM visitors WHERE entry_date = '$today' AND exit_time IS NOT NULL");
$avgMin = $avgDurationQuery->fetch_assoc()['avg_min'] ?? 0;
$avgHours = round($avgMin / 60, 1);

// مشتریان بازگشتی
$repeatCustomers = $conn->query("SELECT COUNT(*) as cnt FROM (SELECT plate, COUNT(*) as visits FROM visitors WHERE entry_date >= DATE_FORMAT('$today', '%Y-%m-01') AND plate != '' GROUP BY plate HAVING visits > 1) as repeats")->fetch_assoc()['cnt'] ?? 0;

// توزیع سرویس‌ها
$services = ['پارک توسط راننده' => 0, 'سالن 2E' => 0, 'سالن 2W' => 0, 'سالن 1M' => 0];
$service_stats = $conn->query("SELECT service_type, COUNT(id) AS count FROM visitors WHERE entry_date = '$today' GROUP BY service_type");
while ($row = $service_stats->fetch_assoc()) {
    if (isset($services[$row['service_type']])) $services[$row['service_type']] = $row['count'];
}

// روش‌های پرداخت
$payMethods = ['پرداخت نشده' => 0, 'نقد' => 0, 'دستگاه pos' => 0, 'کارت به کارت' => 0];
$payStats = $conn->query("SELECT payment_method, COUNT(id) as cnt FROM visitors WHERE entry_date = '$today' GROUP BY payment_method");
while ($row = $payStats->fetch_assoc()) {
    $key = trim($row['payment_method']);
    if (isset($payMethods[$key])) $payMethods[$key] = (int)$row['cnt'];
}

// درآمد ۳۰ روز
$last30Income = []; $last30Dates = [];
$income30 = $conn->query("SELECT entry_date, SUM(paid_amount) as total FROM visitors WHERE entry_date >= DATE_SUB('$today', INTERVAL 30 DAY) AND payment_method != 'پرداخت نشده' GROUP BY entry_date ORDER BY entry_date ASC");
while ($row = $income30->fetch_assoc()) {
    $last30Dates[] = $row['entry_date'];
    $last30Income[] = (int)$row['total'];
}
$last30DatesJalali = array_map(fn($gDate) => convertToJalali($gDate, 'Y/m/d'), $last30Dates);

// ورودی ساعتی
$hourlyEntries = array_fill(0, 24, 0);
$hourly = $conn->query("SELECT HOUR(entry_time) as h, COUNT(*) as cnt FROM visitors WHERE entry_date = '$today' GROUP BY h");
while ($row = $hourly->fetch_assoc()) $hourlyEntries[(int)$row['h']] = (int)$row['cnt'];

// =================== اصلاح نمودار هفتگی (۷ روز گذشته شامل امروز) ===================
// ساخت ۷ تاریخ متوالی از ۶ روز قبل تا امروز
$weekDatesGregorian = [];
$weekCounts = array_fill(0, 7, 0);
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days", strtotime($today)));
    $weekDatesGregorian[] = $date;
}

// کوئری با استفاده از IN برای دریافت آمار دقیق بر اساس تاریخ واقعی
$placeholders = implode(',', array_fill(0, 7, '?'));
$stmt = $conn->prepare("SELECT entry_date, COUNT(*) as cnt FROM visitors WHERE entry_date IN ($placeholders) GROUP BY entry_date");
$stmt->bind_param(str_repeat('s', 7), ...$weekDatesGregorian);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $index = array_search($row['entry_date'], $weekDatesGregorian);
    if ($index !== false) {
        $weekCounts[$index] = (int)$row['cnt'];
    }
}
$stmt->close();

// تبدیل تاریخ‌ها به شمسی برای نمایش در نمودار
$weekDatesJalali = array_map(fn($gDate) => convertToJalali($gDate, 'Y/m/d'), $weekDatesGregorian);
// ===================================================================

$conn->close();

$pageTitle = 'داشبورد مدیریتی پارکینگ VIP بیمارستان نیکان';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=1200">
    <title>داشبورد مدیریتی - پارکینگ VIP بیمارستان نیکان</title>
    <link rel="stylesheet" href="css/notyf.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="font/css/all.css">
    <style>
        /* بهبود کارت‌ها برای نمایش کامل محتوا */
        .dashboard-cards {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 30px;
        }
        .card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            flex: 1 1 220px;
            min-width: 220px;
            transition: all 0.2s;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .card i {
            margin-bottom: 15px;
            color: #2c3e50;
        }
        .card h3 {
            font-size: 1rem;
            margin: 10px 0;
            color: #555;
        }
        .card .value {
            font-size: 1.8rem;
            font-weight: bold;
            color: #2c3e50;
            margin-top: 5px;
        }
        .card .value small {
            font-size: 0.8rem;
            font-weight: normal;
        }
        .service-list {
            list-style: none;
            padding: 0;
            margin: 10px 0 0;
            text-align: right;
            width: 100%;
        }
        .service-list li {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            border-bottom: 1px solid #eee;
        }
        .dashboard-charts {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-top: 30px;
        }
        .chart-box {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.15);
            height: auto;
            min-height: 350px;
            position: relative;
            overflow: auto;
        }
        .chart-box h3 {
            margin-bottom: 15px;
            color: #2c3e50;
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
        }
        .full-width {
            grid-column: span 2;
        }
        canvas {
            max-height: 300px;
            width: 100% !important;
        }
        @media (max-width: 900px) {
            .dashboard-charts {
                grid-template-columns: 1fr;
            }
            .full-width {
                grid-column: span 1;
            }
            .card {
                min-width: 180px;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <?php include 'header.php'; ?>

    <main>
        <div class="dashboard-cards">
            <div class="card"><i class="fas fa-users fa-4x"></i><h3>مراجعین امروز</h3><div class="value"><?= $total_today ?> <small><?= trendBadge($visitorChange, ' نفر') ?></small></div></div>
            <div class="card"><i class="fas fa-car fa-4x"></i><h3>خودروهای حاضر</h3><div class="value"><?= $present_cars ?></div></div>
            <div class="card"><i class="fas fa-sign-out-alt fa-4x"></i><h3>خودروهای خارج شده</h3><div class="value"><?= $exited_today ?></div></div>
            <div class="card"><i class="fas fa-coins fa-4x"></i><h3>درآمد امروز (تومان)</h3><div class="value"><?= number_format($total_income) ?> <small><?= trendBadge($incomeChange, ' تومان') ?></small></div></div>
            <div class="card"><i class="fas fa-exclamation-triangle fa-4x"></i><h3>پرداخت نشده‌ها</h3><div class="value"><?= $unpaid_count ?></div></div>
            <div class="card"><i class="fas fa-sync-alt fa-4x"></i><h3>مشتریان بازگشتی (این ماه)</h3><div class="value"><?= $repeatCustomers ?> پلاک</div></div>
            <div class="card"><i class="fas fa-hourglass-half fa-4x"></i><h3>میانگین توقف (امروز)</h3><div class="value"><?= $avgHours ?> ساعت</div></div>
            <div class="card"><i class="fas fa-list fa-4x"></i><h3>توزیع سرویس‌ها</h3><ul class="service-list"><?php foreach ($services as $service => $count): ?><li><?= htmlspecialchars($service) ?>: <span><?= $count ?></span></li><?php endforeach; ?></ul></div>
        </div>

        <div class="dashboard-charts">
            <div class="chart-box"><h3><i class="fas fa-chart-pie"></i> توزیع سرویس‌ها (امروز)</h3><canvas id="serviceChart"></canvas></div>
            <div class="chart-box"><h3><i class="fas fa-credit-card"></i> روش‌های پرداخت (امروز)</h3><canvas id="paymentChart"></canvas></div>
            <div class="chart-box"><h3><i class="fas fa-chart-line"></i> روند درآمد ۳۰ روز گذشته</h3><canvas id="incomeChart"></canvas></div>
            <div class="chart-box"><h3><i class="fas fa-chart-bar"></i> تعداد ورودی‌ها (ساعتی)</h3><canvas id="hourlyChart"></canvas></div>
            <div class="chart-box full-width"><h3><i class="fas fa-calendar-week"></i> مقایسه ورودی‌ها در ۷ روز گذشته (شامل امروز)</h3><canvas id="weekChart"></canvas></div>
        </div>
    </main>

    <?php include 'footer.php'; ?>
</div>

<script>
    const serviceData = <?= json_encode($services) ?>;
    const paymentData = <?= json_encode($payMethods) ?>;
    const incomeDates = <?= json_encode($last30DatesJalali) ?>;
    const incomeValues = <?= json_encode($last30Income) ?>;
    const hourlyData = <?= json_encode($hourlyEntries) ?>;
    // داده‌های اصلاحی نمودار هفتگی
    const weekData = <?= json_encode($weekCounts) ?>;
    const weekLabels = <?= json_encode($weekDatesJalali) ?>;
</script>
<script src="js/chart.umd.min.js"></script>
<script src="js/utils.js"></script>
<script src="js/menu.js"></script>
<script src="js/clock.js"></script>
<script src="js/script.js?v=21011405"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    new Chart(document.getElementById('serviceChart').getContext('2d'), {
        type: 'pie',
        data: { labels: Object.keys(serviceData), datasets: [{ data: Object.values(serviceData), backgroundColor: ['#FF6384','#36A2EB','#FFCE56','#4BC0C0'] }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
    });
    new Chart(document.getElementById('paymentChart').getContext('2d'), {
        type: 'pie',
        data: { labels: Object.keys(paymentData), datasets: [{ data: Object.values(paymentData), backgroundColor: ['#e74c3c','#2ecc71','#3498db','#f1c40f'] }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
    });
    new Chart(document.getElementById('incomeChart').getContext('2d'), {
        type: 'line',
        data: { labels: incomeDates, datasets: [{ label: 'درآمد (تومان)', data: incomeValues, borderColor: '#28a745', backgroundColor: 'rgba(40,167,69,0.1)', borderWidth: 2, fill: true, tension: 0.3 }] },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { tooltip: { callbacks: { label: ctx => ctx.raw.toLocaleString('fa-IR') + ' تومان' } } },
            scales: { y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString('fa-IR') } } }
        }
    });
    const hourlyLabels = Array.from({length: 24}, (_, i) => i.toString().padStart(2,'0') + ':00');
    new Chart(document.getElementById('hourlyChart').getContext('2d'), {
        type: 'bar',
        data: { labels: hourlyLabels, datasets: [{ label: 'تعداد خودرو', data: hourlyData, backgroundColor: '#007bff' }] },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, title: { display: true, text: 'تعداد' } } } }
    });
    // نمودار هفتگی با داده‌های اصلاحی (۷ روز شامل امروز و تاریخ واقعی)
    new Chart(document.getElementById('weekChart').getContext('2d'), {
        type: 'bar',
        data: { labels: weekLabels, datasets: [{ label: 'تعداد ورودی', data: weekData, backgroundColor: '#5f27cd' }] },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true, title: { display: true, text: 'تعداد خودرو' } },
                x: { ticks: { autoSkip: false, rotation: 30, maxRotation: 45, font: { size: 11 } } }
            },
            plugins: { tooltip: { callbacks: { title: (ctx) => 'تاریخ: ' + ctx[0].label, label: ctx => 'تعداد: ' + ctx.raw } } }
        }
    });
});
</script>
</body>
</html>