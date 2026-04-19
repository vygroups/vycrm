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

try {
    $dashboardStats['billing'][0]['value'] = (int) $conn->query("SELECT COUNT(*) FROM {$prefix}customers")->fetchColumn();
    $dashboardStats['billing'][1]['value'] = (int) $conn->query("SELECT COUNT(*) FROM {$prefix}invoices")->fetchColumn();
    $dashboardStats['billing'][2]['value'] = (int) $conn->query("SELECT COUNT(*) FROM {$prefix}products WHERE status = 'active'")->fetchColumn();

    $today = date('Y-m-d');
    $attendanceStmt = $conn->prepare("SELECT COUNT(*) FROM {$prefix}attendance WHERE date = ?");
    $attendanceStmt->execute([$today]);
    $dashboardStats['hr_operations'][0]['value'] = (int) $attendanceStmt->fetchColumn();
    $dashboardStats['hr_operations'][1]['value'] = (int) $conn->query("SELECT COUNT(*) FROM {$prefix}leaves WHERE status = 'pending'")->fetchColumn();
    $dashboardStats['hr_operations'][2]['value'] = (int) $conn->query("SELECT COUNT(*) FROM {$prefix}permissions WHERE status = 'pending'")->fetchColumn();
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
                <div class="profile-pill" onclick="toggleProfileDropdown(event)">
                    <img src="/images/admin.jpg" onerror="this.src='https://ui-avatars.com/api/?name=Admin&background=7b5ef0&color=fff'" alt="Admin">
                    <span class="name"><?= htmlspecialchars($_SESSION["username"]) ?></span>
                    <i class="fa-solid fa-chevron-down text-muted" style="margin-right:8px;font-size:12px;"></i>
                    
                    <!-- Profile Dropdown -->
                    <div class="profile-dropdown" id="profileDropdown">
                        <a href="/profile.php" class="dropdown-item"><i class="fa-regular fa-user"></i> My Profile</a>
                        <div class="dropdown-divider"></div>
                        <a href="/users.php" class="dropdown-item"><i class="fa-solid fa-users"></i> User Management</a>
                        <a href="/roles.php" class="dropdown-item"><i class="fa-solid fa-wand-magic-sparkles"></i> Studio (Roles)</a>
                        <div class="dropdown-divider"></div>
                        <a href="/logout.php" class="dropdown-item" style="color: var(--hot);"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
                    </div>
                </div>
            </div>
        </header>
        <div class="content-scroll">
            <h3 class="pipeline-header">DASHBOARD MODULES</h3>
            <div class="module-selector" id="moduleSelector">
                <?php foreach ($dashboardModules as $moduleKey => $module): ?>
                <button type="button" data-module="<?= htmlspecialchars($moduleKey) ?>">
                    <i class="<?= htmlspecialchars($module['icon']) ?>" style="margin-right:8px;"></i><?= htmlspecialchars($module['title']) ?>
                </button>
                <?php endforeach; ?>
            </div>
            <div class="card-grid stats-panel" id="statsPanel">
                <?php foreach ($dashboardStats as $moduleKey => $stats): ?>
                    <?php foreach ($stats as $index => $stat): ?>
                    <div class="crm-card module-stat-card" data-module="<?= htmlspecialchars($moduleKey) ?>" <?= $moduleKey !== 'billing' ? 'style="display:none;"' : '' ?>>
                        <div class="card-title"><?= htmlspecialchars($stat['label']) ?></div>
                        <div class="card-value"><?= (int) $stat['value'] ?> <i class="<?= htmlspecialchars($stat['icon']) ?>"></i></div>
                        <div class="card-desc"><?= htmlspecialchars($stat['desc']) ?></div>
                    </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
            <h3 class="pipeline-header">CONFIGURABLE MODULES</h3>
            <div class="module-shortcuts">
                <?php foreach ($dashboardModules as $moduleKey => $module): ?>
                <div class="module-shortcut module-shortcut-card" data-module="<?= htmlspecialchars($moduleKey) ?>" <?= $moduleKey !== 'billing' ? 'style="display:none;"' : '' ?>>
                    <i class="<?= htmlspecialchars($module['icon']) ?>"></i>
                    <h4><?= htmlspecialchars($module['title']) ?></h4>
                    <p><?= htmlspecialchars($module['description']) ?></p>
                    <div class="module-links">
                        <?php foreach ($module['links'] as $link): ?>
                        <a href="<?= htmlspecialchars($link['href']) ?>"><?= htmlspecialchars($link['label']) ?></a>
                        <?php endforeach; ?>
                    </div>
                    <a href="<?= htmlspecialchars($module['links'][0]['href']) ?>">Open <?= htmlspecialchars($module['title']) ?> <i class="fa-solid fa-arrow-right"></i></a>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="table-panel" id="dashboardInfoPanel">
                <div class="table-header">
                    <div class="table-title" id="dashboardInfoTitle">MODULE QUICK GUIDE</div>
                    <div class="table-actions">
                        <span class="filter-btn active" id="dashboardInfoTag">Billing & Transactions</span>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="crm-table">
                        <thead><tr><th>Area</th><th>Purpose</th><th>Primary Action</th></tr></thead>
                        <tbody id="dashboardGuideBody">
                            <tr data-module="billing">
                                <td class="text-bold">Customers</td>
                                <td>Manage customer master data used during billing.</td>
                                <td><a href="customers.php">Open Customers</a></td>
                            </tr>
                            <tr data-module="billing">
                                <td class="text-bold">Invoices</td>
                                <td>Create and manage sale invoices and totals.</td>
                                <td><a href="invoices.php">Open Invoices</a></td>
                            </tr>
                            <tr data-module="billing">
                                <td class="text-bold">Products / Service</td>
                                <td>Keep sellable items and pricing ready for transactions.</td>
                                <td><a href="products.php">Open Products</a></td>
                            </tr>
                            <tr data-module="hr_operations" style="display:none;">
                                <td class="text-bold">Attendance</td>
                                <td>Track punch-in, punch-out, and employee work sessions.</td>
                                <td><a href="attendance.php">Open Attendance</a></td>
                            </tr>
                            <tr data-module="hr_operations" style="display:none;">
                                <td class="text-bold">Attendance Report</td>
                                <td>Review attendance summaries and date-based reporting.</td>
                                <td><a href="attendance_report.php">Open Report</a></td>
                            </tr>
                            <tr data-module="hr_operations" style="display:none;">
                                <td class="text-bold">Approvals</td>
                                <td>Approve leave and permission requests from employees.</td>
                                <td><a href="manage_requests.php">Open Approvals</a></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
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
    const safeKey = dashboardModules[moduleKey] ? moduleKey : 'billing';
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

setActiveDashboardModule(localStorage.getItem(DASHBOARD_MODULE_KEY) || 'billing');

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
</script>
</body>
</html>
