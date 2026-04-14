try {
    $dbName = $_SESSION['tenant_db'];
    $conn = Database::getTenantConn($dbName);
    $prefix = $_SESSION['tenant_prefix'];
    
    // Fetch Enquiries
    $stmt = $conn->query("SELECT * FROM {$prefix}enquiries ORDER BY created_at DESC LIMIT 10");
    $enquiries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Stats
    $totalHot = $conn->query("SELECT COUNT(*) FROM {$prefix}enquiries WHERE status = 'Hot'")->fetchColumn();
    $totalWarm = $conn->query("SELECT COUNT(*) FROM {$prefix}enquiries WHERE status = 'Warm'")->fetchColumn();
    $totalCold = $conn->query("SELECT COUNT(*) FROM {$prefix}enquiries WHERE status = 'Cold'")->fetchColumn();

    // Company Logo
    $companyLogo = "/images/logo.png";
    $companyName = "Vy CRM";
    if (isset($_SESSION['tenant_slug'])) {
        $dbMaster = Database::getMasterConn();
        $prefixMaster = Database::getMasterPrefix();
        $stmt = $dbMaster->prepare("SELECT * FROM {$prefixMaster}companies WHERE slug = ?");
        $stmt->execute([$_SESSION['tenant_slug']]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($company && $company['logo']) {
            $companyLogo = '/' . $company['logo'];
            $companyName = htmlspecialchars($company['name']);
        }
    }
} catch (Exception $e) {}

$v = time();
?>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $_SESSION["tenant_slug"] ?> - Dashboard</title>
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
    </style>
</head>
<body>
<div id="vyToastContainer"></div>
<div class="app-wrapper">
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleMobileSidebar()" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:90;"></div>
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-head">
            <a href="dashboard.php"><img src="<?= $companyLogo ?>?v=<?= $v ?>" alt="<?= $companyName ?>" style="max-height:50px;"></a>
            <div class="sidebar-toggle hidden-mobile" onclick="toggleSidebar()">
                <i class="fa-solid fa-chevron-left" id="toggleIcon"></i>
            </div>
        </div>
        <div class="sidebar-nav">
            <a href="dashboard.php" class="nav-item active"><i class="fa-solid fa-chart-pie"></i><span class="nav-text">Dashboard</span></a>
            <a href="attendance.php" class="nav-item"><i class="fa-regular fa-clock"></i><span class="nav-text">Attendance</span></a>
            <a href="#" class="nav-item"><i class="fa-solid fa-ticket"></i><span class="nav-text">Tickets</span></a>
            <a href="invoices.php" class="nav-item"><i class="fa-solid fa-file-invoice"></i><span class="nav-text">Invoices</span></a>
            <a href="products.php" class="nav-item"><i class="fa-solid fa-boxes-stacked"></i><span class="nav-text">Products</span></a>
        </div>
    </aside>
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
                    <div class="card-title">HOT ENQUIRIES</div>
                    <div class="card-value val-hot"><?= (int)$totalHot ?> <i class="fa-solid fa-fire"></i></div>
                    <div class="card-desc">High Priority Leads</div>
                </div>
                <div class="crm-card card-warm">
                    <div class="card-title">WARM ENQUIRIES</div>
                    <div class="card-value val-warm"><?= (int)$totalWarm ?> <i class="fa-solid fa-file-invoice-dollar"></i></div>
                    <div class="card-desc">Pending Follow-up</div>
                </div>
                <div class="crm-card card-cold">
                    <div class="card-title">COLD ENQUIRIES</div>
                    <div class="card-value val-cold"><?= (int)$totalCold ?> <i class="fa-solid fa-box"></i></div>
                    <div class="card-desc">Default Leads</div>
                </div>
            </div>
            <div class="table-panel">
                <div class="table-header">
                    <div class="table-title">ALL ASSIGNED ENQUIRIES</div>
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
                            <?php if (empty($enquiries)): ?>
                                <tr><td colspan="7" class="text-center text-muted">No enquiries found. Add your first lead!</td></tr>
                            <?php else: ?>
                                <?php foreach ($enquiries as $enq): ?>
                                <tr>
                                    <td class="text-bold"><?= htmlspecialchars($enq['enquiry_no']) ?></td>
                                    <td><?= htmlspecialchars($enq['student_name']) ?></td>
                                    <td><span class="badge badge-<?= strtolower($enq['status']) ?>"><?= htmlspecialchars($enq['status']) ?></span></td>
                                    <td><?= (int)$enq['score'] ?></td>
                                    <td class="text-muted text-bold"><?= htmlspecialchars($enq['bucket']) ?></td>
                                    <td><?= date('Y-m-d', strtotime($enq['created_at'])) ?></td>
                                    <td><button class="btn-icon"><i class="fa-solid fa-eye"></i></button></td>
                                </tr>
                                <?php endforeach; ?>
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
function toggleProfileDropdown(e) {
    e.stopPropagation();
    document.getElementById('profileDropdown').classList.toggle('show');
}

window.onclick = function(event) {
    if (!event.target.closest('.profile-pill')) {
        const dropdowns = document.getElementsByClassName("profile-dropdown");
        for (let i = 0; i < dropdowns.length; i++) {
            dropdowns[i].classList.remove('show');
        }
    }
}

setInterval(tickTimer, 1000);
tickTimer();
</script>
</body>
</html>
