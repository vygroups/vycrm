<?php
// attendance_tenant.php - Tenant Attendance Module
require_once 'auth_check.php';
require_once 'config/database.php';
require_once 'includes/brand.php';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $_SESSION["tenant_slug"] ?> - Attendance</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="/assets/css/styles.css?v=<?= $v?>" rel="stylesheet">
    <style>
        .action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .action-btn {
            background: var(--surface);
            padding: 30px 20px;
            border-radius: 20px;
            text-align: center;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-md);
            transition: var(--transition);
            cursor: pointer;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 15px;
            user-select: none;
        }

        .action-btn:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }

        .action-btn:active {
            transform: translateY(-2px);
        }

        .action-btn i {
            font-size: 32px;
        }

        .action-btn span {
            font-size: 16px;
            font-weight: 700;
            letter-spacing: .5px;
        }

        .btn-checkin {
            color: #10b981;
            border-bottom: 4px solid #10b981;
        }

        .btn-breakin {
            color: #f59e0b;
            border-bottom: 4px solid #f59e0b;
        }

        .btn-breakout {
            color: #3b82f6;
            border-bottom: 4px solid #3b82f6;
        }

        .btn-checkout {
            color: #ef4444;
            border-bottom: 4px solid #ef4444;
        }

        .tabs-header {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid var(--border);
            padding-bottom: 10px;
        }

        .tab-btn {
            background: transparent;
            border: none;
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 700;
            color: var(--text-muted);
            cursor: pointer;
            transition: var(--transition);
            position: relative;
        }

        .tab-btn:hover {
            color: var(--primary);
        }

        .tab-btn.active {
            color: var(--primary);
        }

        .tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: -12px;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--primary);
            border-radius: 4px 4px 0 0;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeTab .35s ease;
        }

        @keyframes fadeTab {
            from {
                opacity: 0;
                transform: translateY(8px)
            }

            to {
                opacity: 1;
                transform: translateY(0)
            }
        }

        /* Global Timer in Topbar */
        @keyframes vyPulse {

            0%,
            100% {
                transform: scale(1);
                opacity: 1
            }

            50% {
                transform: scale(1.5);
                opacity: .5
            }
        }

        #globalPunchTimer {
            display: none;
            align-items: center;
            gap: 10px;
            background: linear-gradient(135deg, rgba(123, 94, 240, .08), rgba(123, 94, 240, .04));
            border: 1.5px solid rgba(123, 94, 240, .25);
            border-radius: 50px;
            padding: 8px 24px;
            font-size: 15px;
            font-weight: 700;
            color: #7b5ef0;
            letter-spacing: .5px;
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            pointer-events: none;
            z-index: 100;
        }

        /* Toast Container */
        #vyToastContainer {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 99999;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .vy-toast {
            background: #fff;
            border-radius: 10px;
            padding: 14px 20px;
            min-width: 280px;
            max-width: 340px;
            font-size: 14px;
            font-weight: 600;
            color: #2b3674;
            box-shadow: 0 8px 25px rgba(0, 0, 0, .12);
            display: flex;
            align-items: center;
            gap: 10px;
            opacity: 0;
            transform: translateX(30px);
            transition: all .35s cubic-bezier(.25, .8, .25, 1);
        }

        .vy-toast.show {
            opacity: 1;
            transform: translateX(0);
        }
    </style>
</head>

<body>

    <div id="vyToastContainer"></div>

    <div class="app-wrapper">
        <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleMobileSidebar()"
            style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:90;">
        </div>

        <aside class="sidebar" id="sidebar">
            <div class="sidebar-head">
                <a href="dashboard.php"><img src="<?= $companyLogo ?>?v=<?= $v ?>" alt="<?= $companyName ?>"
                        style="max-height:50px;"></a>
                <div class="sidebar-toggle hidden-mobile" onclick="toggleSidebar()">
                    <i class="fa-solid fa-chevron-left" id="toggleIcon"></i>
                </div>
            </div>
            <div class="sidebar-nav">
                <a href="dashboard.php" class="nav-item"><i class="fa-solid fa-chart-pie"></i><span class="nav-text">Dashboard</span></a>
                <a href="attendance.php" class="nav-item active"><i class="fa-regular fa-clock"></i><span class="nav-text">Attendance</span></a>
                <a href="#" class="nav-item"><i class="fa-solid fa-ticket"></i><span class="nav-text">Tickets</span></a>
                <a href="invoices.php" class="nav-item"><i class="fa-solid fa-file-invoice"></i><span class="nav-text">Invoices</span></a>
                <a href="products.php" class="nav-item"><i class="fa-solid fa-boxes-stacked"></i><span class="nav-text">Products</span></a>
            </div>
        </aside>

        <main class="main-content">
            <header class="topbar" style="position:relative;">
                <div class="flex items-center">
                    <button class="btn-icon" onclick="toggleMobileSidebar()" style="margin-right:20px;display:none;"
                        id="mobileToggle"><i class="fa-solid fa-bars"></i></button>
                    <div class="breadcrumb">Home / Attendance<span class="current">Attendance Portal</span></div>
                </div>

                <!-- Global Timer -->
                <div id="globalPunchTimer">
                    <span
                        style="width:9px;height:9px;background:#10b981;border-radius:50%;display:inline-block;animation:vyPulse 1.5s infinite;"></span>
                    <span>Work Session:</span>
                    <span id="punchTimerValue" style="font-size:16px;">00:00:00</span>
                </div>

                <div class="topbar-right">
                    <button class="btn-icon" style="background:var(--surface);color:var(--text-muted);"><i
                            class="fa-regular fa-bell"></i></button>
                    <div class="profile-pill" onclick="toggleProfileDropdown(event)">
                        <img src="/images/admin.jpg"
                            onerror="this.src='https://ui-avatars.com/api/?name=Admin&background=7b5ef0&color=fff'"
                            alt="Admin">
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
                <div class="action-grid">
                    <div class="action-btn btn-checkin" onclick="doPunch('Check-In')">
                        <i class="fa-solid fa-right-to-bracket"></i><span>PUNCH IN</span>
                    </div>
                    <div class="action-btn btn-breakin" onclick="doPunch('Break-In')">
                        <i class="fa-solid fa-mug-hot"></i><span>START BREAK</span>
                    </div>
                    <div class="action-btn btn-breakout" onclick="doPunch('Break-Out')">
                        <i class="fa-solid fa-briefcase"></i><span>END BREAK</span>
                    </div>
                    <div class="action-btn btn-checkout" onclick="doPunch('Check-Out')">
                        <i class="fa-solid fa-right-from-bracket"></i><span>PUNCH OUT</span>
                    </div>
                </div>

                <div class="table-panel" style="padding:20px;">
                    <div class="tabs-header">
                        <button class="tab-btn active" onclick="switchTab(event,'history')">ATTENDANCE HISTORY</button>
                        <button class="tab-btn" onclick="switchTab(event,'leaves')">LEAVE REQUESTS</button>
                        <button class="tab-btn" onclick="switchTab(event,'permissions')">PERMISSIONS</button>
                    </div>

                    <div id="history" class="tab-content active table-responsive">
                        <table class="crm-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>First Punch In</th>
                                    <th>Last Punch Out</th>
                                    <th>Total Hours</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="text-bold">2026-03-29</td>
                                    <td>09:05 AM</td>
                                    <td>-</td>
                                    <td>4 hrs 10 mins</td>
                                    <td><span class="badge"
                                            style="background:rgba(16,185,129,.1);border:1px solid #10b981;color:#10b981;">Present
                                            (Active)</span></td>
                                </tr>
                                <tr>
                                    <td class="text-bold">2026-03-28</td>
                                    <td>08:55 AM</td>
                                    <td>05:02 PM</td>
                                    <td>8 hrs 07 mins</td>
                                    <td><span class="badge"
                                            style="background:rgba(16,185,129,.1);border:1px solid #10b981;color:#10b981;">Present</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-bold">2026-03-27</td>
                                    <td>-</td>
                                    <td>-</td>
                                    <td>0 hrs</td>
                                    <td><span class="badge badge-hot">Absent</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div id="leaves" class="tab-content table-responsive">
                        <div class="flex justify-between items-center mb-3">
                            <h4 style="color:var(--text-main);">My Leave Applications</h4>
                            <button class="btn-icon"
                                style="width:auto;padding:0 20px;font-size:13px;border-radius:20px;"
                                onclick="vyToast('Leave request form coming soon!','info')">
                                <i class="fa-solid fa-plus" style="margin-right:8px;"></i> Request Leave
                            </button>
                        </div>
                        <table class="crm-table">
                            <thead>
                                <tr>
                                    <th>Leave Type</th>
                                    <th>From Date</th>
                                    <th>To Date</th>
                                    <th>Reason</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="text-bold">Casual Leave</td>
                                    <td>2026-04-10</td>
                                    <td>2026-04-12</td>
                                    <td>Family Function</td>
                                    <td><span class="badge badge-warm">Pending Approval</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div id="permissions" class="tab-content table-responsive">
                        <div class="flex justify-between items-center mb-3">
                            <h4 style="color:var(--text-main);">My Permission Requests</h4>
                            <button class="btn-icon"
                                style="width:auto;padding:0 20px;font-size:13px;border-radius:20px;"
                                onclick="vyToast('Permission request form coming soon!','info')">
                                <i class="fa-solid fa-plus" style="margin-right:8px;"></i> Request Permission
                            </button>
                        </div>
                        <table class="crm-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Time Window</th>
                                    <th>Duration</th>
                                    <th>Reason</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="text-bold">2026-03-20</td>
                                    <td>03:00 PM - 05:00 PM</td>
                                    <td>2 Hours</td>
                                    <td>Bank Work</td>
                                    <td><span class="badge"
                                            style="background:rgba(16,185,129,.1);border:1px solid #10b981;color:#10b981;">Approved</span>
                                    </td>
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
            const colors = { success: '#10b981', warning: '#f59e0b', info: '#7b5ef0', error: '#ef4444' };
            const icons = { success: '✅', warning: '☕', info: '💼', error: '👋' };
            const c = document.getElementById('vyToastContainer');
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
            const s = Math.floor(ms / 1000);
            return [Math.floor(s / 3600), Math.floor((s % 3600) / 60), s % 60].map(n => String(n).padStart(2, '0')).join(':');
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

        function doPunch(type) {
            const msgs = {
                'Check-In': ['Punched In! Session started.', 'success'],
                'Break-In': ['Break started! ☕', 'warning'],
                'Break-Out': ['Break ended! 💼', 'info'],
                'Check-Out': ['Punched Out. Good work today! 👋', 'error']
            };
            if (type === 'Check-In' && !localStorage.getItem(PUNCH_KEY)) {
                localStorage.setItem(PUNCH_KEY, Date.now().toString());
            }
            if (type === 'Check-Out') localStorage.removeItem(PUNCH_KEY);
            const [m, t] = msgs[type] || ['Action recorded.', 'success'];
            vyToast(m, t);
            tickTimer();
        }

        function switchTab(evt, id) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
            document.getElementById(id).classList.add('active');
            evt.currentTarget.classList.add('active');
        }

        function toggleSidebar() {
            const s = document.getElementById('sidebar');
            const i = document.getElementById('toggleIcon');
            s.classList.toggle('sidebar-collapsed');
            i.classList.toggle('fa-chevron-left', !s.classList.contains('sidebar-collapsed'));
            i.classList.toggle('fa-chevron-right', s.classList.contains('sidebar-collapsed'));
        }

        function toggleMobileSidebar() {
            const s = document.getElementById('sidebar');
            const o = document.getElementById('sidebarOverlay');
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
