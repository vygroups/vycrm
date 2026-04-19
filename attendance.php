<?php
// attendance.php - Attendance Module with Global Timer + Toast
require_once 'auth_check.php';
require_once 'config/database.php';
require_once 'includes/brand.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vy CRM - Attendance</title>
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

        <?php include 'includes/sidebar.php'; ?>

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
                    <div class="action-btn btn-checkin" id="btnCheckIn" onclick="apiPunch('punch_in')">
                        <i class="fa-solid fa-right-to-bracket"></i><span>PUNCH IN</span>
                    </div>
                    <div class="action-btn btn-breakin" id="btnBreakIn" onclick="apiPunch('break_in')">
                        <i class="fa-solid fa-mug-hot"></i><span>START BREAK</span>
                    </div>
                    <div class="action-btn btn-breakout" id="btnBreakOut" onclick="apiPunch('break_out')">
                        <i class="fa-solid fa-briefcase"></i><span>END BREAK</span>
                    </div>
                    <div class="action-btn btn-checkout" id="btnCheckOut" onclick="apiPunch('punch_out')">
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
                                <tr><th>Date</th><th>First Punch In</th><th>Last Punch Out</th><th>Total Hours</th><th>Status</th></tr>
                            </thead>
                            <tbody id="attendanceHistoryBody">
                                <!-- Dynamic Content -->
                            </tbody>
                        </table>
                    </div>

                    <div id="leaves" class="tab-content table-responsive">
                        <div class="flex justify-between items-center mb-3">
                            <h4 style="color:var(--text-main);">My Leave Applications</h4>
                            <button class="btn-primary" style="width:auto;padding:10px 20px;border-radius:10px;" onclick="openModal('leaveModal')">
                                <i class="fa-solid fa-plus" style="margin-right:8px;"></i> Request Leave
                            </button>
                        </div>
                        <table class="crm-table">
                            <thead>
                                <tr><th>Leave Type</th><th>From Date</th><th>To Date</th><th>Reason</th><th>Status</th></tr>
                            </thead>
                            <tbody id="leaveHistoryBody">
                                <!-- Dynamic Content -->
                            </tbody>
                        </table>
                    </div>

                    <div id="permissions" class="tab-content table-responsive">
                        <div class="flex justify-between items-center mb-3">
                            <h4 style="color:var(--text-main);">My Permission Requests</h4>
                            <button class="btn-primary" style="width:auto;padding:10px 20px;border-radius:10px;" onclick="openModal('permissionModal')">
                                <i class="fa-solid fa-plus" style="margin-right:8px;"></i> Request Permission
                            </button>
                        </div>
                        <table class="crm-table">
                            <thead>
                                <tr><th>Date</th><th>Time Window</th><th>Duration</th><th>Reason</th><th>Status</th></tr>
                            </thead>
                            <tbody id="permissionHistoryBody">
                                <!-- Dynamic Content -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Modals -->
    <style>
        .modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,.5); z-index:1000; align-items:center; justify-content:center; }
        .modal-content { background:#fff; padding:30px; border-radius:20px; width:100%; max-width:450px; box-shadow:var(--shadow-lg); border: 1px solid var(--border); }
        .form-group { margin-bottom:15px; }
        .form-label { display:block; margin-bottom:5px; font-weight:600; color:var(--text-main); font-size:14px; }
        .form-control { width:100%; padding:12px; border:1px solid var(--border); border-radius:10px; font-size:14px; background: #f9f9f9; }
    </style>

    <div id="leaveModal" class="modal">
        <div class="modal-content">
            <h3 class="mb-4">Request Leave</h3>
            <form id="leaveForm">
                <input type="hidden" name="action" value="apply">
                <div class="form-group">
                    <label class="form-label">Leave Type</label>
                    <select class="form-control" name="leave_type" required>
                        <option value="Casual Leave">Casual Leave</option>
                        <option value="Sick Leave">Sick Leave</option>
                        <option value="Earned Leave">Earned Leave</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">From Date</label>
                    <input type="date" class="form-control" name="from_date" required>
                </div>
                <div class="form-group">
                    <label class="form-label">To Date</label>
                    <input type="date" class="form-control" name="to_date" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Reason</label>
                    <textarea class="form-control" name="reason" rows="3" required></textarea>
                </div>
                <div class="flex gap-2 justify-end mt-4">
                    <button type="button" class="btn-icon" style="width:auto;padding:10px 20px;background:var(--border);" onclick="closeModal('leaveModal')">Cancel</button>
                    <button type="submit" class="btn-primary" style="width:auto;padding:10px 20px;">Submit Application</button>
                </div>
            </form>
        </div>
    </div>

    <div id="permissionModal" class="modal">
        <div class="modal-content">
            <h3 class="mb-4">Request Permission</h3>
            <form id="permissionForm">
                <input type="hidden" name="action" value="apply">
                <div class="form-group">
                    <label class="form-label">Date</label>
                    <input type="date" class="form-control" name="date" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Time Window (e.g., 2 PM - 4 PM)</label>
                    <input type="text" class="form-control" name="time_window" placeholder="2:00 PM - 3:00 PM" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Duration</label>
                    <input type="text" class="form-control" name="duration" placeholder="1 Hour" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Reason</label>
                    <textarea class="form-control" name="reason" rows="3" required></textarea>
                </div>
                <div class="flex gap-2 justify-end mt-4">
                    <button type="button" class="btn-icon" style="width:auto;padding:10px 20px;background:var(--border);" onclick="closeModal('permissionModal')">Cancel</button>
                    <button type="submit" class="btn-primary" style="width:auto;padding:10px 20px;">Submit Request</button>
                </div>
            </form>
        </div>
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

        // --- DYNAMIC DATA FETCHING ---
        async function fetchStatus() {
            const res = await fetch('/api/attendance.php?action=status');
            const data = await res.json();
            if (data.success) {
                const btnIn = document.getElementById('btnCheckIn');
                const btnOut = document.getElementById('btnCheckOut');
                const btnBIn = document.getElementById('btnBreakIn');
                const btnBOut = document.getElementById('btnBreakOut');
                
                if (data.is_punched_in) {
                    if (data.type === 'shift') {
                        btnIn.style.opacity = '0.5'; btnIn.style.pointerEvents = 'none';
                        btnOut.style.opacity = '1'; btnOut.style.pointerEvents = 'auto';
                        btnBIn.style.opacity = '1'; btnBIn.style.pointerEvents = 'auto';
                        btnBOut.style.opacity = '0.5'; btnBOut.style.pointerEvents = 'none';
                        if (!localStorage.getItem(PUNCH_KEY)) {
                            // Sync with server time by calculating elapsed duration
                            // This solves the 5.5h offset issue once and for all
                            if (data.punch_in_ms && data.server_time) {
                                const elapsed = data.server_time - data.punch_in_ms;
                                localStorage.setItem(PUNCH_KEY, (Date.now() - elapsed).toString());
                            } else if (data.punch_in) {
                                localStorage.setItem(PUNCH_KEY, (new Date(data.punch_in)).getTime().toString());
                            }
                        }
                    } else if (data.type === 'break') {
                        btnIn.style.opacity = '0.5'; btnIn.style.pointerEvents = 'none';
                        btnOut.style.opacity = '0.5'; btnOut.style.pointerEvents = 'none';
                        btnBIn.style.opacity = '0.5'; btnBIn.style.pointerEvents = 'none';
                        btnBOut.style.opacity = '1'; btnBOut.style.pointerEvents = 'auto';
                        localStorage.removeItem(PUNCH_KEY);
                    }
                } else {
                    btnIn.style.opacity = '1'; btnIn.style.pointerEvents = 'auto';
                    btnOut.style.opacity = '0.5'; btnOut.style.pointerEvents = 'none';
                    btnBIn.style.opacity = '0.5'; btnBIn.style.pointerEvents = 'none';
                    btnBOut.style.opacity = '0.5'; btnBOut.style.pointerEvents = 'none';
                    localStorage.removeItem(PUNCH_KEY);
                }
                tickTimer();
            }
        }

        async function apiPunch(action) {
            const res = await fetch('/api/attendance.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=${action}`
            });
            const data = await res.json();
            if (data.success) {
                vyToast(data.message);
                fetchStatus();
                loadAttendanceHistory();
            } else {
                vyToast(data.message, 'error');
            }
        }

        async function loadAttendanceHistory() {
            const res = await fetch('/api/attendance.php?action=history'); 
            const data = await res.json();
            const tbody = document.getElementById('attendanceHistoryBody');
            if (data.success && data.data && data.data.length > 0) {
                tbody.innerHTML = data.data.map(at => {
                    const statusTag = at.type === 'break' 
                        ? '<span class="badge" style="background:rgba(245,158,11,.1);border:1px solid #f59e0b;color:#f59e0b;">Break</span>'
                        : '<span class="badge" style="background:rgba(16,185,129,.1);border:1px solid #10b981;color:#10b981;">Present</span>';
                    return `
                    <tr>
                        <td class="text-bold">${at.date}</td>
                        <td>${at.punch_in ? new Date(at.punch_in).toLocaleTimeString() : '-'}</td>
                        <td>${at.punch_out ? new Date(at.punch_out).toLocaleTimeString() : '-'}</td>
                        <td>${at.total_hours || '-'}</td>
                        <td>${at.type === 'shift' ? statusTag : statusTag}</td>
                    </tr>`;
                }).join('');
            } else {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;color:var(--text-muted);">No records found for today.</td></tr>';
            }
        }

        async function loadLeaves() {
            const res = await fetch('/api/leaves.php');
            const data = await res.json();
            if (data.success) {
                const tbody = document.getElementById('leaveHistoryBody');
                tbody.innerHTML = data.data.map(l => `
                    <tr>
                        <td class="text-bold">${l.leave_type}</td>
                        <td>${l.from_date}</td>
                        <td>${l.to_date}</td>
                        <td>${l.reason}</td>
                        <td><span class="badge badge-${l.status === 'pending' ? 'warm' : (l.status === 'approved' ? 'success' : 'hot')}">${l.status}</span></td>
                    </tr>
                `).join('') || '<tr><td colspan="5" style="text-align:center;">No leave applications found</td></tr>';
            }
        }

        async function loadPermissions() {
            const res = await fetch('/api/permissions.php');
            const data = await res.json();
            if (data.success) {
                const tbody = document.getElementById('permissionHistoryBody');
                tbody.innerHTML = data.data.map(p => `
                    <tr>
                        <td class="text-bold">${p.date}</td>
                        <td>${p.time_window}</td>
                        <td>${p.duration}</td>
                        <td>${p.reason}</td>
                        <td><span class="badge badge-${p.status === 'pending' ? 'warm' : (p.status === 'approved' ? 'success' : 'hot')}">${p.status}</span></td>
                    </tr>
                `).join('') || '<tr><td colspan="5" style="text-align:center;">No permission requests found</td></tr>';
            }
        }

        // Modals
        function openModal(id) { document.getElementById(id).style.display = 'flex'; }
        function closeModal(id) { document.getElementById(id).style.display = 'none'; }

        document.getElementById('leaveForm').onsubmit = async (e) => {
            e.preventDefault();
            const res = await fetch('/api/leaves.php', { method: 'POST', body: new FormData(e.target) });
            const data = await res.json();
            if (data.success) { vyToast(data.message); closeModal('leaveModal'); loadLeaves(); }
            else vyToast(data.message, 'error');
        };

        document.getElementById('permissionForm').onsubmit = async (e) => {
            e.preventDefault();
            const res = await fetch('/api/permissions.php', { method: 'POST', body: new FormData(e.target) });
            const data = await res.json();
            if (data.success) { vyToast(data.message); closeModal('permissionModal'); loadPermissions(); }
            else vyToast(data.message, 'error');
        };

        function switchTab(evt, id) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
            document.getElementById(id).classList.add('active');
            evt.currentTarget.classList.add('active');
            if (id === 'leaves') loadLeaves();
            if (id === 'permissions') loadPermissions();
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

        function toggleProfileDropdown(e) { e.stopPropagation(); document.getElementById('profileDropdown').classList.toggle('show'); }

        window.onclick = function(event) {
            if (!event.target.closest('.profile-pill')) {
                const dropdowns = document.getElementsByClassName("profile-dropdown");
                for (let i = 0; i < dropdowns.length; i++) { dropdowns[i].classList.remove('show'); }
            }
        }

        setInterval(tickTimer, 1000);
        fetchStatus();
        loadAttendanceHistory();
    </script>
</body>
</html>
pt>
</body>

</html>
