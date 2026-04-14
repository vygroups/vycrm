<?php
// dashboard.php - Premium CRM Dashboard
require_once 'auth_check.php';
require_once 'config/database.php';

$v = time();
$companyLogo = "/images/logo.png";
$companyName = "Vy CRM";

$user_id = $_SESSION['user_id'];
$dbName = $_SESSION['tenant_db'];
$prefix = $_SESSION['tenant_prefix'];
$conn = Database::getTenantConn($dbName);

if (isset($_SESSION['tenant_slug'])) {
    try {
        $dbMaster = Database::getMasterConn();
        $masterPrefix = Database::getMasterPrefix();
        $stmt = $dbMaster->prepare("SELECT * FROM {$masterPrefix}companies WHERE slug = ?");
        $stmt->execute([$_SESSION['tenant_slug']]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($company && $company['logo']) {
            $companyLogo = '/' . $company['logo'];
            $companyName = htmlspecialchars($company['name']);
        }
    } catch (Exception $e) {}
}

// Fetch Live Data
try {
    // 1. All Assigned Enquiries
    $stmt = $conn->prepare("SELECT * FROM {$prefix}enquiries WHERE assigned_to = ? OR assigned_to IS NULL ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
    $enquiries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Counts
    $hotCount = 0;
    $warmCount = 0;
    $coldCount = 0;
    foreach ($enquiries as $enq) {
        if ($enq['status'] == 'Hot' || $enq['score'] >= 75) $hotCount++;
        elseif ($enq['status'] == 'Warm' || ($enq['score'] >= 50 && $enq['score'] < 75)) $warmCount++;
        elseif ($enq['status'] == 'Cold' || ($enq['score'] >= 25 && $enq['score'] < 50)) $coldCount++;
    }
} catch (Exception $e) {
    $enquiries = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vy CRM - Dashboard</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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
            <h3 class="pipeline-header">PIPELINE STAGE BREAKDOWN</h3>
            <div class="card-grid">
                <div class="crm-card card-hot">
                    <div class="card-title">HOT TICKETS</div>
                    <div class="card-value val-hot"><?= $hotCount ?> <i class="fa-solid fa-fire"></i></div>
                    <div class="card-desc">High Priority / Hot Leads</div>
                </div>
                <div class="crm-card card-warm">
                    <div class="card-title">WARM LEADS</div>
                    <div class="card-value val-warm"><?= $warmCount ?> <i class="fa-solid fa-leaf"></i></div>
                    <div class="card-desc">Active Opportunities</div>
                </div>
                <div class="crm-card card-cold">
                    <div class="card-title">TOTAL ASSIGNED</div>
                    <div class="card-value val-cold"><?= count($enquiries) ?> <i class="fa-solid fa-ticket"></i></div>
                    <div class="card-desc">Total Open Enquiries</div>
                </div>
            </div>
            <div class="module-shortcuts">
                <div class="module-shortcut">
                    <i class="fa-solid fa-file-invoice"></i>
                    <h4>Invoice Module</h4>
                    <p>Create customer invoices with dedicated tables, line items, and reusable APIs for web and mobile.</p>
                    <a href="invoices.php">Open Invoices <i class="fa-solid fa-arrow-right"></i></a>
                </div>
                <div class="module-shortcut">
                    <i class="fa-solid fa-boxes-stacked"></i>
                    <h4>Product Module</h4>
                    <p>Manage the product catalog separately so invoice rows always use consistent pricing and tax values.</p>
                    <a href="products.php">Open Products <i class="fa-solid fa-arrow-right"></i></a>
                </div>
            </div>
            <div class="table-panel">
                <div class="table-header">
                    <div class="table-title">MY ASSIGNED ENQUIRIES</div>
                    <div class="table-actions">
                        <button class="filter-btn active">All Enquiries</button>
                        <button class="filter-btn">Today Schedule</button>
                        <input type="date" class="form-control" style="width:150px;padding:8px 15px;border-radius:8px;">
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="crm-table">
                        <thead><tr><th>Enquiry No</th><th>Student Name</th><th>Status</th><th>Score</th><th>Bucket</th><th>Date</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($enquiries as $enq): ?>
                            <tr>
                                <td class="text-bold"><?= htmlspecialchars($enq['enquiry_no']) ?></td>
                                <td><?= htmlspecialchars($enq['student_name']) ?></td>
                                <td>
                                    <?php 
                                        $cls = 'badge-cold';
                                        if($enq['status'] == 'Hot') $cls = 'badge-hot';
                                        if($enq['status'] == 'Warm') $cls = 'badge-warm';
                                    ?>
                                    <span class="badge <?= $cls ?>"><?= htmlspecialchars($enq['status']) ?></span>
                                </td>
                                <td><?= $enq['score'] ?></td>
                                <td class="text-muted text-bold"><?= htmlspecialchars($enq['bucket']) ?></td>
                                <td><?= date('Y-m-d', strtotime($enq['created_at'])) ?></td>
                                <td><button class="btn-icon"><i class="fa-solid fa-eye"></i></button></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($enquiries)): ?>
                                <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-muted);">No enquiries assigned to you yet.</td></tr>
                            <?php endif; ?>
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
