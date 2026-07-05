<?php
// dashboard.php - Premium CRM Dashboard
require_once 'auth_check.php';
require_once 'config/database.php';
require_once 'includes/brand.php';
require_once 'includes/navigation_config.php';
require_once 'includes/commerce.php';

$user_id = $_SESSION['user_id'];
$dbName = $_SESSION['tenant_db'];
$prefix = $_SESSION['tenant_prefix'];
$conn = Database::getTenantConn($dbName);
$dashboardModules = vycrm_module_config();
commerce_ensure_tables($conn, $prefix);
require_once 'includes/dynamic_modules.php';

$billingEnabled = dm_get_system_setting($conn, $prefix, 'billing_enabled', '1') === '1';
$attendanceEnabled = dm_get_system_setting($conn, $prefix, 'attendance_enabled', '1') === '1';

if (!$billingEnabled) unset($dashboardModules['billing']);
if (!$attendanceEnabled) unset($dashboardModules['hr_operations']);

// Fetch dashboard data
$dashboardStats = [
    'billing' => [
        ['label' => 'Customers', 'value' => 0, 'icon' => 'fa-solid fa-users', 'desc' => 'Registered customers'],
        ['label' => 'Sale Invoices', 'value' => 0, 'icon' => 'fa-solid fa-file-invoice-dollar', 'desc' => 'Invoices created'],
        ['label' => 'Products / Service', 'value' => 0, 'icon' => 'fa-solid fa-boxes-stacked', 'desc' => 'Active catalog items'],
    ],
    'hr_operations' => [
        ['label' => "Today's Attendance", 'value' => 0, 'icon' => 'fa-regular fa-calendar-check', 'desc' => 'Punch records for today'],
        ['label' => 'Pending Leaves', 'value' => 0, 'icon' => 'fa-solid fa-plane-departure', 'desc' => 'Leave requests awaiting action'],
        ['label' => 'Pending Permissions', 'value' => 0, 'icon' => 'fa-solid fa-clipboard-check', 'desc' => 'Permission requests awaiting action'],
    ],
];

if (!$billingEnabled) unset($dashboardStats['billing']);
if (!$attendanceEnabled) unset($dashboardStats['hr_operations']);

// Append dynamic modules
$dynModules = dm_fetch_active_modules($conn, $prefix);
foreach ($dynModules as $dm) {
    $dashboardModules['dyn_' . $dm['slug']] = [
        'title' => $dm['name'],
        'section_label' => $dm['name'],
        'icon' => $dm['icon'],
        'description' => $dm['description'],
        'links' => [
            ['label' => 'View ' . $dm['name'], 'href' => 'module_view.php?module=' . urlencode($dm['slug'])]
        ]
    ];
    $dashboardStats['dyn_' . $dm['slug']] = [
        ['label' => 'Total Records', 'value' => 0, 'icon' => 'fa-solid fa-database', 'desc' => 'Total records in ' . $dm['name']]
    ];
}

// Fetch dashboard widgets
$widgets = [];
try {
    $wStmt = $conn->query("SELECT * FROM {$prefix}dashboard_widgets ORDER BY sort_order ASC, id ASC");
    $widgets = $wStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

try {
    if ($billingEnabled) {
        $dashboardStats['billing'][0]['value'] = (int) $conn->query("SELECT COUNT(*) FROM {$prefix}customers")->fetchColumn();
        $dashboardStats['billing'][1]['value'] = (int) $conn->query("SELECT COUNT(*) FROM {$prefix}invoices")->fetchColumn();
        $dashboardStats['billing'][2]['value'] = (int) $conn->query("SELECT COUNT(*) FROM {$prefix}products WHERE status = 'active'")->fetchColumn();
    }

    if ($attendanceEnabled) {
        $today = date('Y-m-d');
        $attendanceStmt = $conn->prepare("SELECT COUNT(*) FROM {$prefix}attendance WHERE date = ?");
        $attendanceStmt->execute([$today]);
        $dashboardStats['hr_operations'][0]['value'] = (int) $attendanceStmt->fetchColumn();
        $dashboardStats['hr_operations'][1]['value'] = (int) $conn->query("SELECT COUNT(*) FROM {$prefix}leaves WHERE status = 'pending'")->fetchColumn();
        $dashboardStats['hr_operations'][2]['value'] = (int) $conn->query("SELECT COUNT(*) FROM {$prefix}permissions WHERE status = 'pending'")->fetchColumn();
    }

    foreach ($dynModules as $dm) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM {$prefix}module_records WHERE module_id = ?");
        $stmt->execute([$dm['id']]);
        $total = (int) $stmt->fetchColumn();
        
        $todayStmt = $conn->prepare("SELECT COUNT(*) FROM {$prefix}module_records WHERE module_id = ? AND DATE(created_at) = CURDATE()");
        $todayStmt->execute([$dm['id']]);
        $today = (int) $todayStmt->fetchColumn();

        $weekStmt = $conn->prepare("SELECT COUNT(*) FROM {$prefix}module_records WHERE module_id = ? AND YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)");
        $weekStmt->execute([$dm['id']]);
        $week = (int) $weekStmt->fetchColumn();

        $monthStmt = $conn->prepare("SELECT COUNT(*) FROM {$prefix}module_records WHERE module_id = ? AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
        $monthStmt->execute([$dm['id']]);
        $month = (int) $monthStmt->fetchColumn();

        $trendStmt = $conn->prepare("
            SELECT DATE(created_at) as d, COUNT(*) as c 
            FROM {$prefix}module_records 
            WHERE module_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
            GROUP BY DATE(created_at)
        ");
        $trendStmt->execute([$dm['id']]);
        $trendRows = $trendStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $trendData = [];
        $trendLabels = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $displayDate = date('D d', strtotime("-$i days"));
            $trendData[$date] = 0;
            $trendLabels[$date] = $displayDate;
        }
        foreach ($trendRows as $r) {
            if (isset($trendData[$r['d']])) {
                $trendData[$r['d']] = (int) $r['c'];
            }
        }

        // Fetch custom saved filters
        $filtersStmt = $conn->prepare("SELECT id, name, filter_rules FROM {$prefix}module_saved_filters WHERE user_id = ? AND module_id = ? ORDER BY name ASC");
        $filtersStmt->execute([$user_id, $dm['id']]);
        $savedFiltersList = $filtersStmt->fetchAll(PDO::FETCH_ASSOC);

        $moduleFilters = [];
        foreach ($savedFiltersList as $filterRow) {
            $filterRules = json_decode($filterRow['filter_rules'], true);
            $filterCount = 0;
            try {
                $res = dm_fetch_records($conn, $prefix, $dm['id'], null, 1, 0, $filterRules);
                $filterCount = $res['total'];
            } catch (Exception $ex) {}
            
            $moduleFilters[] = [
                'id' => $filterRow['id'],
                'name' => $filterRow['name'],
                'count' => $filterCount
            ];
        }

        $dashboardStats['dyn_' . $dm['slug']] = [
            ['label' => 'Total Records', 'value' => $total, 'icon' => 'fa-solid fa-database', 'desc' => 'Total records in ' . $dm['name']],
            ['label' => 'Added Today', 'value' => $today, 'icon' => 'fa-solid fa-calendar-day', 'desc' => 'Records created today']
        ];
        $dashboardModules['dyn_' . $dm['slug']]['chart_data'] = [
            'today' => $today,
            'week' => $week,
            'month' => $month,
            'trend_labels' => array_values($trendLabels),
            'trend_data' => array_values($trendData)
        ];
        $dashboardModules['dyn_' . $dm['slug']]['filters'] = $moduleFilters;
        $dashboardModules['dyn_' . $dm['slug']]['module_id'] = $dm['id'];
    }
} catch (Exception $e) {
    // Keep default zero-state cards on dashboard.
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(brand_page_title('Dashboard')) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link rel="shortcut icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <style>
        @keyframes vyPulse { 0%,100%{transform:scale(1);opacity:1} 50%{transform:scale(1.5);opacity:.5} }
        #globalPunchTimer {
            display:none; align-items:center; gap:10px;
            background:linear-gradient(135deg,rgba(123,94,240,.08),rgba(123,94,240,.04));
            border:1.5px solid rgba(123,94,240,.25); border-radius:50px;
            padding:8px 24px; font-size:15px; font-weight:700; color:#7b5ef0;
            letter-spacing:.5px; position:absolute; left:50%; transform:translateX(-50%);
            pointer-events:none; z-index: 1000;
        }
        #vyToastContainer { position:fixed; top:20px; right:20px; z-index:99999; display:flex; flex-direction:column; gap:10px; }
        .vy-toast {
            background:#fff; border-radius:10px; padding:14px 20px;
            min-width:280px; max-width:340px; font-size:14px; font-weight:600;
            color:#2b3674; box-shadow:0 8px 25px rgba(0,0,0,.12);
            display:flex; align-items:center; gap:10px;
            opacity:0; transform:translateX(30px);
            transition:all .35s cubic-bezier(.25,.8,.25,1);
        }
        .vy-toast.show { opacity:1; transform:translateX(0); }
        .module-shortcuts {
            display:grid;
            grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));
            gap:20px;
            margin:0 0 40px;
        }
        .module-selector {
            display:flex;
            flex-wrap:wrap;
            gap:12px;
            margin:0 0 24px;
        }
        .module-selector button {
            border:1px solid rgba(123,94,240,.14);
            background:#fff;
            color:var(--text-main);
            border-radius:999px;
            padding:12px 18px;
            font-weight:700;
            cursor:pointer;
            transition:all .2s ease;
        }
        .module-selector button.active {
            background:var(--primary);
            color:#fff;
            border-color:var(--primary);
            box-shadow:var(--shadow-md);
        }
        .module-shortcut {
            background:var(--surface);
            border-radius:20px;
            padding:24px;
            box-shadow:var(--shadow-md);
            border:1px solid rgba(123,94,240,.08);
        }
        .module-shortcut i {
            font-size:24px;
            color:var(--primary);
            margin-bottom:16px;
        }
        .module-shortcut h4 {
            font-size:18px;
            margin-bottom:10px;
        }
        .module-shortcut p {
            color:var(--text-muted);
            line-height:1.5;
            margin-bottom:18px;
        }
        .module-shortcut a {
            display:inline-flex;
            align-items:center;
            gap:8px;
            color:var(--primary);
            font-weight:700;
        }
        .module-links {
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            margin-bottom:18px;
        }
        .module-links a {
            padding:8px 12px;
            border-radius:999px;
            background:#f6f3ff;
            border:1px solid rgba(123,94,240,.12);
            font-size:13px;
            font-weight:600;
        }
        .stats-panel {
            margin-bottom:28px;
        }
        .dyn-chart-card {
            grid-column: 1 / -1; 
            display: flex; 
            flex-direction: column; 
            gap: 20px;
        }
        .dyn-stats-header {
            display: flex; 
            gap: 20px; 
            flex-wrap: wrap;
        }
        .dyn-stat-pill {
            flex: 1;
            min-width: 150px;
            background: #f8fafc;
            border-radius: 12px;
            padding: 16px;
            border: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .dyn-stat-pill span {
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .dyn-stat-pill strong {
            color: var(--text);
            font-size: 24px;
        }
        .dyn-chart-container {
            width: 100%;
            height: 300px;
            position: relative;
            background: #ffffff;
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }
        .dyn-charts-wrapper {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        @media (max-width: 768px) {
            .dyn-charts-wrapper {
                grid-template-columns: 1fr;
            }
        }
        .scrollable-cards-container {
            display: flex;
            overflow-x: auto;
            gap: 20px;
            padding: 10px 5px 20px 5px;
            scroll-behavior: smooth;
            -webkit-overflow-scrolling: touch;
            width: 100%;
        }
        .scrollable-cards-container::-webkit-scrollbar {
            height: 8px;
        }
        .scrollable-cards-container::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.02);
            border-radius: 10px;
        }
        .scrollable-cards-container::-webkit-scrollbar-thumb {
            background: rgba(123, 94, 240, 0.2);
            border-radius: 10px;
        }
        .scrollable-cards-container::-webkit-scrollbar-thumb:hover {
            background: rgba(123, 94, 240, 0.4);
        }
        .scrollable-card {
            flex: 0 0 280px;
            height: 160px;
            background: var(--surface);
            border-radius: 20px;
            padding: 20px;
            box-shadow: var(--shadow-sm);
            border: 1px solid rgba(123, 94, 240, 0.08);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            text-decoration: none;
            transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s;
            cursor: pointer;
            box-sizing: border-box;
        }
        .scrollable-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary);
        }
        .scrollable-card .card-header-flex {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
        }
        .scrollable-card .card-title-text {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .scrollable-card .card-val-text {
            font-size: 32px;
            font-weight: 800;
            color: var(--text-main);
            line-height: 1.1;
            margin-top: 8px;
        }
        .scrollable-card .card-icon-box {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        .scrollable-card .card-footer-action {
            font-size: 12px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: auto;
        }
        .dyn-details-grid {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 24px;
            margin-top: 24px;
            width: 100%;
        }
        @media (max-width: 1024px) {
            .dyn-details-grid {
                grid-template-columns: 1fr;
            }
        }
        .premium-metric-card {
            background: linear-gradient(135deg, #ffffff, #fcfbff);
            border-radius: 24px;
            padding: 24px;
            box-shadow: 0 10px 30px -15px rgba(123, 94, 240, 0.15);
            border: 1px solid rgba(123, 94, 240, 0.08);
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .metric-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--text);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .metric-row {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .metric-row-label-flex {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
        }
        .metric-progress-bg {
            height: 8px;
            background: #f1f0f7;
            border-radius: 999px;
            overflow: hidden;
            width: 100%;
        }
        .metric-progress-bar {
            height: 100%;
            border-radius: 999px;
            transition: width 0.8s cubic-bezier(0.25, 0.8, 0.25, 1);
        }
        .premium-chart-card {
            background: #ffffff;
            border-radius: 24px;
            padding: 24px;
            box-shadow: 0 10px 30px -15px rgba(123, 94, 240, 0.15);
            border: 1px solid rgba(123, 94, 240, 0.08);
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        .chart-header-flex {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .chart-canvas-container {
            width: 100%;
            height: 280px;
            position: relative;
        }
    </style>
</head>
<body>
<div id="vyToastContainer"></div>
<div class="app-wrapper">
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleMobileSidebar()" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:90;"></div>
    <?php include 'includes/sidebar.php'; ?>
    <main class="main-content">
        <header class="topbar" style="position:relative;">
            <div class="flex items-center">
                <button class="btn-icon" onclick="toggleMobileSidebar()" style="margin-right:20px;display:none;" id="mobileToggle"><i class="fa-solid fa-bars"></i></button>
                <div class="breadcrumb">Home / Dashboard<span class="current">Dashboard</span></div>
            </div>
            <!-- Global Timer -->
            <div id="globalPunchTimer">
                <span style="width:9px;height:9px;background:#10b981;border-radius:50%;display:inline-block;animation:vyPulse 1.5s infinite;"></span>
                <span>Work Session:</span>
                <span id="punchTimerValue" style="font-size:16px;">00:00:00</span>
            </div>
            <div class="topbar-right">
                <button class="btn-icon" style="background:var(--surface);color:var(--text-muted);"><i class="fa-regular fa-bell"></i></button>
                <?php include 'includes/profile_pill.php'; ?>
            </div>
        </header>
        <div class="content-scroll">
            <?php if (!empty($widgets)): ?>
            <!-- Dynamic Insights Section -->
            <div id="dynamicInsightsSection" style="margin-bottom: 15px;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 20px;">
                    <?php foreach ($widgets as $w): 
                        $wRules = json_decode($w['rules'], true);
                        $wCount = 0;
                        try {
                            $res = dm_fetch_records($conn, $prefix, (int)$w['module_id'], null, 0, 0, $wRules);
                            $wCount = $res['total'];
                        } catch (Exception $ex) {}
                        
                        $cardColor = htmlspecialchars($w['color'] ?: 'var(--primary)');
                        $cardIcon = htmlspecialchars($w['icon'] ?: 'fa-solid fa-bell');
                        
                        // Link to module view with filter rules
                        $viewUrl = 'module_view.php?module=' . (int)$w['module_id'] . '&filter_rules=' . urlencode($w['rules']);
                    ?>
                        <a href="<?= $viewUrl ?>" class="crm-card" style="display: block; text-decoration: none; padding: 20px; border-left: 5px solid <?= $cardColor ?>; position: relative; overflow: hidden; transition: transform 0.2s, box-shadow 0.2s; box-shadow: var(--shadow-sm);" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='var(--shadow-md)';" onmouseout="this.style.transform='none'; this.style.boxShadow='var(--shadow-sm)';">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                <div style="flex: 1; min-width: 0; padding-right: 15px;">
                                    <div style="font-size: 13px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($w['title']) ?>">
                                        <?= htmlspecialchars($w['title']) ?>
                                    </div>
                                    <div style="font-size: 28px; font-weight: 800; color: var(--text-main); line-height: 1;">
                                        <?= number_format($wCount) ?>
                                    </div>
                                </div>
                                <div style="width: 42px; height: 42px; border-radius: 12px; background: <?= $cardColor ?>12; color: <?= $cardColor ?>; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0;">
                                    <i class="<?= $cardIcon ?>"></i>
                                </div>
                            </div>
                            <div style="font-size: 12px; color: var(--primary); font-weight: 700; margin-top: 15px; display: flex; align-items: center; gap: 4px;">
                                View Records <i class="fa-solid fa-arrow-right-long" style="font-size:10px;"></i>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <h3 class="pipeline-header">DASHBOARD MODULES</h3>
            <div class="module-selector" id="moduleSelector">
                <?php foreach ($dashboardModules as $moduleKey => $module): ?>
                <button type="button" data-module="<?= htmlspecialchars($moduleKey) ?>">
                    <i class="<?= htmlspecialchars($module['icon']) ?>" style="margin-right:8px;"></i><?= htmlspecialchars($module['title']) ?>
                </button>
                <?php endforeach; ?>
            </div>
            <div class="card-grid stats-panel" id="statsPanel">
                <?php 
                $firstModuleKey = array_key_first($dashboardModules);
                foreach ($dashboardStats as $moduleKey => $stats): ?>
                    <?php if (strpos($moduleKey, 'dyn_') === 0): 
                        $mId = $dashboardModules[$moduleKey]['module_id'];
                        $filters = $dashboardModules[$moduleKey]['filters'] ?? [];
                        $chartData = $dashboardModules[$moduleKey]['chart_data'] ?? ['today'=>0, 'week'=>0, 'month'=>0];
                        $colorsList = ['#7b5ef0', '#10b981', '#3b82f6', '#ec4899', '#f59e0b', '#8b5cf6', '#06b6d4', '#14b8a6'];
                        
                        $totalVal = (int)$stats[0]['value'];
                        $todayVal = (int)$stats[1]['value'];
                        $weekVal = (int)$chartData['week'];
                        $monthVal = (int)$chartData['month'];
                        
                        $todayPercent = $totalVal > 0 ? min(100, round(($todayVal / $totalVal) * 100)) : 0;
                        $weekPercent = $totalVal > 0 ? min(100, round(($weekVal / $totalVal) * 100)) : 0;
                        $monthPercent = $totalVal > 0 ? min(100, round(($monthVal / $totalVal) * 100)) : 0;
                    ?>
                        <div class="module-stat-card" data-module="<?= htmlspecialchars($moduleKey) ?>" <?= $moduleKey !== $firstModuleKey ? 'style="display:none; width:100%;"' : 'style="width:100%;"' ?>>
                            <!-- Top: Scrollable filters cards -->
                            <div class="scrollable-cards-container">
                                <!-- Card 1: Total Records -->
                                <a href="module_view.php?module=<?= $mId ?>" class="scrollable-card" style="border-left: 5px solid <?= $colorsList[0] ?>;">
                                    <div class="card-header-flex">
                                        <div style="flex:1; min-width:0;">
                                            <div class="card-title-text" title="Total Records">Total Records</div>
                                            <div class="card-val-text"><?= number_format($totalVal) ?></div>
                                        </div>
                                        <div class="card-icon-box" style="background: <?= $colorsList[0] ?>12; color: <?= $colorsList[0] ?>;">
                                            <i class="fa-solid fa-database"></i>
                                        </div>
                                    </div>
                                    <div class="card-footer-action" style="color: <?= $colorsList[0] ?>;">
                                        View All Records <i class="fa-solid fa-arrow-right-long"></i>
                                    </div>
                                </a>

                                <!-- Card 2: Added Today -->
                                <?php 
                                $todayFilterRules = json_encode([['field_id' => 'created_at', 'operator' => 'today', 'value' => '']]);
                                ?>
                                <a href="module_view.php?module=<?= $mId ?>&filter_rules=<?= urlencode($todayFilterRules) ?>" class="scrollable-card" style="border-left: 5px solid <?= $colorsList[1] ?>;">
                                    <div class="card-header-flex">
                                        <div style="flex:1; min-width:0;">
                                            <div class="card-title-text" title="Added Today">Added Today</div>
                                            <div class="card-val-text"><?= number_format($todayVal) ?></div>
                                        </div>
                                        <div class="card-icon-box" style="background: <?= $colorsList[1] ?>12; color: <?= $colorsList[1] ?>;">
                                            <i class="fa-solid fa-calendar-day"></i>
                                        </div>
                                    </div>
                                    <div class="card-footer-action" style="color: <?= $colorsList[1] ?>;">
                                        View Today's Records <i class="fa-solid fa-arrow-right-long"></i>
                                    </div>
                                </a>

                                <!-- Custom Saved Filters -->
                                <?php foreach ($filters as $index => $filter): 
                                    $color = $colorsList[($index + 2) % count($colorsList)];
                                ?>
                                    <a href="module_view.php?module=<?= $mId ?>&filter_id=<?= $filter['id'] ?>" class="scrollable-card" style="border-left: 5px solid <?= $color ?>;">
                                        <div class="card-header-flex">
                                            <div style="flex:1; min-width:0;">
                                                <div class="card-title-text" title="<?= htmlspecialchars($filter['name']) ?>"><?= htmlspecialchars($filter['name']) ?></div>
                                                <div class="card-val-text"><?= number_format((int)$filter['count']) ?></div>
                                            </div>
                                            <div class="card-icon-box" style="background: <?= $color ?>12; color: <?= $color ?>;">
                                                <i class="fa-solid fa-filter"></i>
                                            </div>
                                        </div>
                                        <div class="card-footer-action" style="color: <?= $color ?>;">
                                            Apply Filter <i class="fa-solid fa-arrow-right-long"></i>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>

                            <!-- Bottom: Metrics & Charts Grid -->
                            <div class="dyn-details-grid">
                                <!-- Left Column: Activity Breakdown Summary -->
                                <div class="premium-metric-card">
                                    <h4 class="metric-title">
                                        <i class="fa-solid fa-chart-simple" style="color: #7b5ef0;"></i> Activity Overview
                                    </h4>
                                    
                                    <div class="metric-row">
                                        <div class="metric-row-label-flex">
                                            <span>Added Today</span>
                                            <span style="color: var(--text-main); font-weight:700;"><?= $todayVal ?> records (<?= $todayPercent ?>%)</span>
                                        </div>
                                        <div class="metric-progress-bg">
                                            <div class="metric-progress-bar" style="width: <?= $todayPercent ?>%; background: #10b981;"></div>
                                        </div>
                                    </div>

                                    <div class="metric-row">
                                        <div class="metric-row-label-flex">
                                            <span>Added This Week</span>
                                            <span style="color: var(--text-main); font-weight:700;"><?= $weekVal ?> records (<?= $weekPercent ?>%)</span>
                                        </div>
                                        <div class="metric-progress-bg">
                                            <div class="metric-progress-bar" style="width: <?= $weekPercent ?>%; background: #3b82f6;"></div>
                                        </div>
                                    </div>

                                    <div class="metric-row">
                                        <div class="metric-row-label-flex">
                                            <span>Added This Month</span>
                                            <span style="color: var(--text-main); font-weight:700;"><?= $monthVal ?> records (<?= $monthPercent ?>%)</span>
                                        </div>
                                        <div class="metric-progress-bg">
                                            <div class="metric-progress-bar" style="width: <?= $monthPercent ?>%; background: #ec4899;"></div>
                                        </div>
                                    </div>

                                    <div style="background: rgba(123, 94, 240, 0.04); border-radius: 16px; padding: 15px; display: flex; align-items: center; gap: 12px; margin-top: auto; border: 1px dashed rgba(123,94,240,0.15);">
                                        <div style="font-size: 24px; color: #7b5ef0;"><i class="fa-regular fa-lightbulb"></i></div>
                                        <div style="font-size: 12px; line-height: 1.4; color: var(--text-muted);">
                                            <strong>Pro Tip:</strong> Click any saved filter card above to immediately view segmented records in list view.
                                        </div>
                                    </div>
                                </div>

                                <!-- Right Column: Modern 7-Day Trend Chart -->
                                <div class="premium-chart-card">
                                    <div class="chart-header-flex">
                                        <h4 class="metric-title" style="font-size: 15px;">
                                            <i class="fa-solid fa-circle-trend" style="color: #7b5ef0;"></i> 7-Day Record Trend
                                        </h4>
                                        <span style="font-size: 12px; font-weight:700; color: #7b5ef0; background: rgba(123,94,240,0.08); padding: 4px 10px; border-radius: 8px;">Activity analytics</span>
                                    </div>
                                    <div class="chart-canvas-container">
                                        <canvas id="chart_trend_<?= htmlspecialchars($moduleKey) ?>"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($stats as $index => $stat): ?>
                        <div class="crm-card module-stat-card" data-module="<?= htmlspecialchars($moduleKey) ?>" <?= $moduleKey !== $firstModuleKey ? 'style="display:none;"' : '' ?>>
                            <div class="card-title"><?= htmlspecialchars($stat['label']) ?></div>
                            <div class="card-value"><?= (int) $stat['value'] ?> <i class="<?= htmlspecialchars($stat['icon']) ?>"></i></div>
                            <div class="card-desc"><?= htmlspecialchars($stat['desc']) ?></div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

        </div>
    </main>
</div>
<script>
function vyToast(msg, type = 'success') {
    const colors = { success:'#10b981', warning:'#f59e0b', info:'#7b5ef0', error:'#ef4444' };
    const icons  = { success:'✅', warning:'☕', info:'💼', error:'👋' };
    const c = document.getElementById('vyToastContainer');
    if (!c) return;
    const t = document.createElement('div');
    t.className = 'vy-toast';
    t.style.borderLeft = '4px solid ' + (colors[type] || colors.success);
    t.innerHTML = `<span style="font-size:18px;">${icons[type] || '✅'}</span><span>${msg}</span>`;
    c.appendChild(t);
    requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('show')));
    setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 400); }, 3500);
}

const DASHBOARD_MODULE_KEY = 'vycrm_dashboard_module_' + <?= json_encode((string) $_SESSION['user_id']) ?>;
const dashboardModules = <?= json_encode($dashboardModules, JSON_UNESCAPED_SLASHES) ?>;

function setActiveDashboardModule(moduleKey) {
    const defaultKey = Object.keys(dashboardModules)[0] || '';
    const safeKey = dashboardModules[moduleKey] ? moduleKey : defaultKey;
    if (!safeKey) return;
    
    document.querySelectorAll('#moduleSelector button').forEach((button) => {
        button.classList.toggle('active', button.dataset.module === safeKey);
    });
    document.querySelectorAll('.module-stat-card, .module-shortcut-card, #dashboardGuideBody tr').forEach((node) => {
        node.style.display = node.dataset.module === safeKey ? '' : 'none';
    });
    const title = document.getElementById('dashboardInfoTitle');
    const tag = document.getElementById('dashboardInfoTag');
    if (title) title.textContent = dashboardModules[safeKey].title + ' Quick Guide';
    if (tag) tag.textContent = dashboardModules[safeKey].title;
    localStorage.setItem(DASHBOARD_MODULE_KEY, safeKey);
}

document.querySelectorAll('#moduleSelector button').forEach((button) => {
    button.addEventListener('click', () => setActiveDashboardModule(button.dataset.module));
});

const defaultModule = Object.keys(dashboardModules)[0] || '';
setActiveDashboardModule(localStorage.getItem(DASHBOARD_MODULE_KEY) || defaultModule);

// Attendance Timer Logic
const PUNCH_KEY = 'vycrm_punch_start';
function formatElapsed(ms) {
    const s = Math.floor(ms/1000);
    return [Math.floor(s/3600), Math.floor((s%3600)/60), s%60].map(n => String(n).padStart(2,'0')).join(':');
}
function tickTimer() {
    const start = localStorage.getItem(PUNCH_KEY);
    const el = document.getElementById('globalPunchTimer');
    const val = document.getElementById('punchTimerValue');
    if (!el || !val) return;
    if (start) {
        val.textContent = formatElapsed(Date.now() - parseInt(start, 10));
        el.style.display = 'flex';
    } else {
        el.style.display = 'none';
    }
}

// Server-side check for active session timer
async function syncTimer() {
    const res = await fetch('/api/attendance.php?action=status');
    const data = await res.json();
    if (data.success && data.is_punched_in && data.type === 'shift') {
        const startTime = new Date(data.punch_in).getTime();
        localStorage.setItem(PUNCH_KEY, startTime);
    } else {
        localStorage.removeItem(PUNCH_KEY);
    }
    tickTimer();
}

function toggleSidebar() {
    const s = document.getElementById('sidebar');
    const i = document.getElementById('toggleIcon');
    if(!s || !i) return;
    s.classList.toggle('sidebar-collapsed');
    i.classList.toggle('fa-chevron-left', !s.classList.contains('sidebar-collapsed'));
    i.classList.toggle('fa-chevron-right', s.classList.contains('sidebar-collapsed'));
}
function toggleMobileSidebar() {
    const s = document.getElementById('sidebar');
    const o = document.getElementById('sidebarOverlay');
    if(!s || !o) return;
    s.classList.toggle('mobile-open');
    o.style.display = s.classList.contains('mobile-open') ? 'block' : 'none';
}
setInterval(tickTimer, 1000);
syncTimer();

function toggleProfileDropdown(e) { e.stopPropagation(); document.getElementById('profileDropdown').classList.toggle('show'); }
window.onclick = function(event) {
    if (!event.target.closest('.profile-pill')) {
        const dropdowns = document.getElementsByClassName("profile-dropdown");
        for (let i = 0; i < dropdowns.length; i++) { dropdowns[i].classList.remove('show'); }
    }
}

// Initialize Dynamic Module Charts
document.addEventListener('DOMContentLoaded', function() {
    const themeColor = '#7b5ef0';
    for (const [key, module] of Object.entries(dashboardModules)) {
        if (key.startsWith('dyn_') && module.chart_data) {
            const ctxTrend = document.getElementById('chart_trend_' + key);
            if (ctxTrend) {
                const gradient = ctxTrend.getContext('2d').createLinearGradient(0, 0, 0, 250);
                gradient.addColorStop(0, 'rgba(123, 94, 240, 0.35)');
                gradient.addColorStop(1, 'rgba(123, 94, 240, 0.00)');
                
                new Chart(ctxTrend, {
                    type: 'line',
                    data: {
                        labels: module.chart_data.trend_labels,
                        datasets: [{
                            label: 'Records Created',
                            data: module.chart_data.trend_data,
                            borderColor: themeColor,
                            backgroundColor: gradient,
                            borderWidth: 3,
                            fill: true,
                            tension: 0.35,
                            pointBackgroundColor: '#ffffff',
                            pointBorderColor: themeColor,
                            pointBorderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 6,
                            pointHoverBackgroundColor: themeColor,
                            pointHoverBorderColor: '#ffffff',
                            pointHoverBorderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: '#1e293b',
                                titleColor: '#ffffff',
                                bodyColor: '#ffffff',
                                padding: 12,
                                cornerRadius: 10,
                                displayColors: false
                            }
                        },
                        scales: {
                            y: { 
                                beginAtZero: true, 
                                ticks: { 
                                    precision: 0,
                                    color: '#94a3b8',
                                    font: { size: 11, weight: '600' }
                                },
                                grid: {
                                    color: 'rgba(148, 163, 184, 0.1)',
                                    drawBorder: false
                                }
                            },
                            x: { 
                                ticks: {
                                    color: '#94a3b8',
                                    font: { size: 11, weight: '600' }
                                },
                                grid: { 
                                    display: false,
                                    drawBorder: false
                                } 
                            }
                        }
                    }
                });
            }
        }
    }
});
</script>
</body>
</html>
