<?php
// dashboard.php - Premium CRM Dashboard
$v = time();
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
    </style>
</head>
<body>
<div id="vyToastContainer"></div>
<div class="app-wrapper">
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleMobileSidebar()" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:90;"></div>
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-head">
            <a href="dashboard.php"><img src="images/logo.png?v=<?= $v ?>" alt="Vy CRM" style="max-height:50px;"></a>
            <div class="sidebar-toggle hidden-mobile" onclick="toggleSidebar()">
                <i class="fa-solid fa-chevron-left" id="toggleIcon"></i>
            </div>
        </div>
        <div class="sidebar-nav">
            <a href="dashboard.php" class="nav-item active"><i class="fa-solid fa-chart-pie"></i><span class="nav-text">Dashboard</span></a>
            <a href="attendance.php" class="nav-item"><i class="fa-regular fa-clock"></i><span class="nav-text">Attendance</span></a>
            <a href="#" class="nav-item"><i class="fa-solid fa-ticket"></i><span class="nav-text">Tickets</span></a>
            <a href="#" class="nav-item"><i class="fa-solid fa-file-invoice"></i><span class="nav-text">Invoices</span></a>
            <a href="#" class="nav-item"><i class="fa-solid fa-boxes-stacked"></i><span class="nav-text">Products</span></a>
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
                <div class="profile-pill">
                    <img src="images/admin.jpg" onerror="this.src='https://ui-avatars.com/api/?name=Admin&background=7b5ef0&color=fff'" alt="Admin">
                    <span class="name">Administrator</span>
                    <i class="fa-solid fa-chevron-down text-muted" style="margin-right:8px;font-size:12px;"></i>
                </div>
            </div>
        </header>
        <div class="content-scroll">
            <h3 class="pipeline-header">PIPELINE STAGE BREAKDOWN</h3>
            <div class="card-grid">
                <div class="crm-card card-hot">
                    <div class="card-title">HOT TICKETS</div>
                    <div class="card-value val-hot">1 <i class="fa-solid fa-fire"></i></div>
                    <div class="card-desc">(75+) High Priority</div>
                </div>
                <div class="crm-card card-warm">
                    <div class="card-title">WARM INVOICES</div>
                    <div class="card-value val-warm">0 <i class="fa-solid fa-file-invoice-dollar"></i></div>
                    <div class="card-desc">(50-74) Pending Review</div>
                </div>
                <div class="crm-card card-cold">
                    <div class="card-title">COLD PRODUCTS</div>
                    <div class="card-value val-cold">0 <i class="fa-solid fa-box"></i></div>
                    <div class="card-desc">(25-49) Default Stock</div>
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
                            <tr><td class="text-bold">SMSKR-BR001</td><td>Sarvesh</td><td><span class="badge badge-warm">Submitted</span></td><td>0</td><td class="text-muted text-bold">UNSCORED</td><td>2026-03-29</td><td><button class="btn-icon"><i class="fa-solid fa-eye"></i></button></td></tr>
                            <tr><td class="text-bold">SMSKR-BR002</td><td>Shiv</td><td><span class="badge badge-hot">Fee Offer Sent</span></td><td>0</td><td class="text-muted text-bold">UNSCORED</td><td>2026-03-29</td><td><button class="btn-icon"><i class="fa-solid fa-eye"></i></button></td></tr>
                            <tr><td class="text-bold">SMSKR-BR003</td><td>Ramya</td><td><span class="badge badge-cold">New Lead</span></td><td>15</td><td class="text-bold" style="color:var(--primary)">PRIMARY</td><td>2026-03-28</td><td><button class="btn-icon"><i class="fa-solid fa-eye"></i></button></td></tr>
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
setInterval(tickTimer, 1000);
tickTimer();
</script>
</body>
</html>