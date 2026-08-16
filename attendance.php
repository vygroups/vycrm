<?php
// attendance.php - Attendance Module with Global Timer + Toast
require_once 'auth_check.php';
require_once 'config/database.php';
require_once 'includes/brand.php';
require_once 'includes/dynamic_modules.php';

$dbName = $_SESSION['tenant_db'];
$prefix = $_SESSION['tenant_prefix'];
$conn = Database::getTenantConn($dbName);

$rule = dm_get_system_setting($conn, $prefix, 'attendance_visibility', 'all');
$isAdmin = !empty($_SESSION['is_admin']);
$currentUserId = (int) $_SESSION['user_id'];
$allowedUserIds = dm_get_visible_user_ids($conn, $prefix, $currentUserId, isset($_SESSION['role_id']) ? (int) $_SESSION['role_id'] : null, $rule, $isAdmin);

$showMemberFilter = ($rule !== 'owner' && ($allowedUserIds === null || count($allowedUserIds) > 0));
$initialSelectedUserId = (string) $currentUserId;

$users = [];
if ($showMemberFilter) {
    try {
        if ($allowedUserIds !== null) {
            $inClause = implode(',', array_map('intval', $allowedUserIds));
            $userStmt = $conn->query("SELECT u.id, u.username, u.first_name, u.last_name, r.name as role_name FROM {$prefix}users u LEFT JOIN {$prefix}roles r ON r.id = u.role_id WHERE u.id IN ($inClause) ORDER BY u.username ASC");
        } else {
            $userStmt = $conn->query("SELECT u.id, u.username, u.first_name, u.last_name, r.name as role_name FROM {$prefix}users u LEFT JOIN {$prefix}roles r ON r.id = u.role_id ORDER BY u.username ASC");
        }
        $users = $userStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(brand_page_title('Attendance')) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link rel="shortcut icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <script>
        async function apiPunch(action) {
            const res = await fetch('/api/attendance.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=${action}`
            });
            const data = await res.json();
            if (data.success) {
                vyToast(data.message);
                if (typeof fetchStatus === 'function') fetchStatus();
                if (typeof loadAttendanceHistory === 'function') loadAttendanceHistory();
            } else {
                vyToast(data.message, 'error');
            }
        }

        function vyToast(msg, type = 'success') {
            const colors = { success: '#10b981', warning: '#f59e0b', info: '#7b5ef0', error: '#ef4444' };
            const icons = { success: '✅', warning: '☕', info: '💼', error: '👋' };
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

        function openModal(id) {
            const m = document.getElementById(id);
            if (m) m.style.display = 'flex';
        }

        function closeModal(id) {
            const m = document.getElementById(id);
            if (m) m.style.display = 'none';
        }

        function switchTab(evt, id) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
            const targetContent = document.getElementById(id);
            if (targetContent) targetContent.classList.add('active');
            if (evt && evt.currentTarget) evt.currentTarget.classList.add('active');
            if (id === 'leaves' && typeof loadLeaves === 'function') loadLeaves();
            if (id === 'permissions' && typeof loadPermissions === 'function') loadPermissions();
            if (id === 'history' && typeof loadAttendanceHistory === 'function') loadAttendanceHistory();
        }

        function toggleSidebar() {
            const s = document.getElementById('sidebar');
            const i = document.getElementById('toggleIcon');
            if (!s) return;
            s.classList.toggle('sidebar-collapsed');
            if (i) {
                i.classList.toggle('fa-chevron-left', !s.classList.contains('sidebar-collapsed'));
                i.classList.toggle('fa-chevron-right', s.classList.contains('sidebar-collapsed'));
            }
        }

        function toggleMobileSidebar() {
            const s = document.getElementById('sidebar');
            const o = document.getElementById('sidebarOverlay');
            if (!s) return;
            s.classList.toggle('mobile-open');
            if (o) {
                const isOpen = s.classList.contains('mobile-open');
                o.style.display = isOpen ? 'block' : 'none';
                o.style.pointerEvents = isOpen ? 'auto' : 'none';
            }
        }

        function toggleProfileDropdown(e) {
            if (e) e.stopPropagation();
            const p = document.getElementById('profileDropdown');
            if (p) p.classList.toggle('show');
        }

        window.onclick = function (event) {
            if (!event.target.closest('.profile-pill')) {
                const dropdowns = document.getElementsByClassName("profile-dropdown");
                for (let i = 0; i < dropdowns.length; i++) { dropdowns[i].classList.remove('show'); }
            }
        };
    </script>
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
            font-family: inherit;
            width: 100%;
            outline: none;
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

        /* Toast Container */
        #vyToastContainer {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 99999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            pointer-events: none;
        }

        .vy-toast {
            pointer-events: auto;
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

        .attendance-topbar {
            z-index: 200;
        }

        .attendance-topbar .topbar-right,
        .attendance-topbar .profile-pill {
            position: relative;
            z-index: 201;
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
            <header class="topbar attendance-topbar" style="position:relative;">
                <div class="flex items-center">
                    <button class="btn-icon" onclick="toggleMobileSidebar()" style="margin-right:20px;display:none;"
                        id="mobileToggle"><i class="fa-solid fa-bars"></i></button>
                    <div class="breadcrumb">Home / Attendance<span class="current">Attendance Portal</span></div>
                </div>

                <!-- Global Timers -->
                <div
                    style="position:absolute; left:50%; transform:translateX(-50%); display:flex; gap:10px; z-index:100; pointer-events:none;">
                    <div id="globalPunchTimer"
                        style="display:none; align-items: center; gap: 10px; background: linear-gradient(135deg, rgba(16, 185, 129, .08), rgba(16, 185, 129, .04)); border: 1.5px solid rgba(16, 185, 129, .25); border-radius: 50px; padding: 8px 24px; font-size: 15px; font-weight: 700; color: #10b981; letter-spacing: .5px;">
                        <span
                            style="width:9px;height:9px;background:#10b981;border-radius:50%;display:inline-block;animation:vyPulse 1.5s infinite;"></span>
                        <span>Work Session:</span>
                        <span id="punchTimerValue" style="font-size:16px;">00:00:00</span>
                    </div>
                    <div id="globalBreakTimer"
                        style="display:none; align-items: center; gap: 10px; background: linear-gradient(135deg, rgba(245, 158, 11, .08), rgba(245, 158, 11, .04)); border: 1.5px solid rgba(245, 158, 11, .25); border-radius: 50px; padding: 8px 24px; font-size: 15px; font-weight: 700; color: #f59e0b; letter-spacing: .5px;">
                        <span
                            style="width:9px;height:9px;background:#f59e0b;border-radius:50%;display:inline-block;animation:vyPulse 1.5s infinite;"></span>
                        <span>Break Session:</span>
                        <span id="breakTimerValue" style="font-size:16px;">00:00:00</span>
                    </div>
                </div>

                <div class="topbar-right">
                    <button class="btn-icon" style="background:var(--surface);color:var(--text-muted);"><i
                            class="fa-regular fa-bell"></i></button>
                    <?php include 'includes/profile_pill.php'; ?>
                </div>
            </header>

            <div class="content-scroll">
                <div class="action-grid">
                    <button type="button" class="action-btn btn-checkin" id="btnCheckIn" onclick="apiPunch('punch_in')">
                        <i class="fa-solid fa-right-to-bracket"></i><span>PUNCH IN</span>
                    </button>
                    <button type="button" class="action-btn btn-breakin" id="btnBreakIn" onclick="apiPunch('break_in')">
                        <i class="fa-solid fa-mug-hot"></i><span>START BREAK</span>
                    </button>
                    <button type="button" class="action-btn btn-breakout" id="btnBreakOut" onclick="apiPunch('break_out')">
                        <i class="fa-solid fa-briefcase"></i><span>END BREAK</span>
                    </button>
                    <button type="button" class="action-btn btn-checkout" id="btnCheckOut" onclick="apiPunch('punch_out')">
                        <i class="fa-solid fa-right-from-bracket"></i><span>PUNCH OUT</span>
                    </button>
                </div>

                <div class="table-panel" style="padding:20px;">
                    <div class="tabs-header"
                        style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px; border-bottom: 2px solid var(--border); padding-bottom: 10px; margin-bottom: 20px;">
                        <div style="display:flex; gap:10px;">
                            <button class="tab-btn active" onclick="switchTab(event,'history')">ATTENDANCE
                                HISTORY</button>
                            <button class="tab-btn" onclick="switchTab(event,'leaves')">LEAVE REQUESTS</button>
                            <button class="tab-btn" onclick="switchTab(event,'permissions')">PERMISSIONS</button>
                        </div>
                        <div style="display:flex; align-items:center; flex-wrap:wrap; gap:12px;">
                            <div style="display:flex; align-items:center; gap:6px;">
                                <span style="font-size:12px; font-weight:700; color:var(--text-muted); letter-spacing:0.5px;">FROM:</span>
                                <input type="date" id="filterStartDate" class="form-control" onchange="applyFilters()" style="width:130px; padding:4px 8px; font-size:13px; border-radius:8px; border:1.5px solid var(--border); background:#fff; height:32px; box-sizing:border-box;">
                            </div>
                            <div style="display:flex; align-items:center; gap:6px;">
                                <span style="font-size:12px; font-weight:700; color:var(--text-muted); letter-spacing:0.5px;">TO:</span>
                                <input type="date" id="filterEndDate" class="form-control" value="<?= date('Y-m-d') ?>" onchange="applyFilters()" style="width:130px; padding:4px 8px; font-size:13px; border-radius:8px; border:1.5px solid var(--border); background:#fff; height:32px; box-sizing:border-box;">
                                
                                <?php if ($showMemberFilter): ?>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <span
                                        style="font-size:12px; font-weight:700; color:var(--text-muted); letter-spacing:0.5px;">MEMBER:</span>
                                    <select class="form-control" id="memberFilter" autocomplete="off" onchange="filterMember(this.value)"
                                        style="width:180px; padding:4px 10px; font-size:13px; border-radius:8px; border:1.5px solid var(--border); background:#fff; cursor:pointer; height:32px; box-sizing:border-box;">
                                        <option value="all">All Team Members</option>
                                        <?php foreach ($users as $u): 
                                            $fullName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                                            $disp = $fullName !== '' ? $fullName : $u['username'];
                                            if (!empty($u['role_name'])) $disp .= ' (' . $u['role_name'] . ')';
                                            $selected = ((int)$u['id'] === $currentUserId) ? 'selected' : '';
                                        ?>
                                            <option value="<?= $u['id'] ?>" <?= $selected ?>>
                                                <?= htmlspecialchars($disp) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div id="history" class="tab-content active table-responsive">
                        <table class="crm-table">
                            <thead>
                                <tr id="historyHeaderRow">
                                    <th>Date</th>
                                    <th>First Punch In</th>
                                    <th>Last Punch Out</th>
                                    <th>Total Hours</th>
                                    <th>Total Break Hours</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="attendanceHistoryBody">
                                <!-- Dynamic Content -->
                            </tbody>
                        </table>
                    </div>

                    <div id="leaves" class="tab-content table-responsive">
                        <div class="flex justify-between items-center mb-3">
                            <h4 style="color:var(--text-main);" id="leavesTitle">My Leave Applications</h4>
                            <button class="btn-primary" style="width:auto;padding:10px 20px;border-radius:10px;"
                                onclick="openModal('leaveModal')">
                                <i class="fa-solid fa-plus" style="margin-right:8px;"></i> Request Leave
                            </button>
                        </div>
                        <table class="crm-table">
                            <thead>
                                <tr id="leavesHeaderRow">
                                    <th>Leave Type</th>
                                    <th>From Date</th>
                                    <th>To Date</th>
                                    <th>Reason</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="leaveHistoryBody">
                                <!-- Dynamic Content -->
                            </tbody>
                        </table>
                    </div>

                    <div id="permissions" class="tab-content table-responsive">
                        <div class="flex justify-between items-center mb-3">
                            <h4 style="color:var(--text-main);" id="permissionsTitle">My Permission Requests</h4>
                            <button class="btn-primary" style="width:auto;padding:10px 20px;border-radius:10px;"
                                onclick="openModal('permissionModal')">
                                <i class="fa-solid fa-plus" style="margin-right:8px;"></i> Request Permission
                            </button>
                        </div>
                        <table class="crm-table">
                            <thead>
                                <tr id="permissionsHeaderRow">
                                    <th>Date</th>
                                    <th>Time Window</th>
                                    <th>Duration</th>
                                    <th>Reason</th>
                                    <th>Status</th>
                                </tr>
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
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, .5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: #fff;
            padding: 30px;
            border-radius: 20px;
            width: 100%;
            max-width: 450px;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border);
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: var(--text-main);
            font-size: 14px;
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-size: 14px;
            background: #f9f9f9;
        }
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
                <div class="flex justify-end mt-4" style="gap: 15px;">
                    <button type="button" class="btn"
                        style="width:auto;padding:10px 20px;background:#e5e7eb;color:#374151;border:none;border-radius:10px;font-weight:600;cursor:pointer;"
                        onclick="closeModal('leaveModal')">Cancel</button>
                    <button type="submit" class="btn-primary" style="width:auto;padding:10px 20px;">Submit
                        Application</button>
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
                <div class="flex justify-end mt-4" style="gap: 15px;">
                    <button type="button" class="btn"
                        style="width:auto;padding:10px 20px;background:#e5e7eb;color:#374151;border:none;border-radius:10px;font-weight:600;cursor:pointer;"
                        onclick="closeModal('permissionModal')">Cancel</button>
                    <button type="submit" class="btn-primary" style="width:auto;padding:10px 20px;">Submit
                        Request</button>
                </div>
            </form>
        </div>
    </div>

    <div id="attendanceHistoryModal" class="modal">
        <div class="modal-content" style="max-width:500px;">
            <div class="flex justify-between items-center mb-4">
                <h3 style="margin:0;">Detailed History</h3>
                <button onclick="closeModal('attendanceHistoryModal')" style="background:none;border:none;cursor:pointer;font-size:20px;color:var(--text-muted);"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div id="historyModalBody" style="font-size:14px;">
                <!-- dynamic -->
            </div>
        </div>
    </div>

    <script>
        const PUNCH_KEY = 'vycrm_punch_start';
        const BREAK_KEY = 'vycrm_break_start';
        let selectedUserId = <?= is_numeric($initialSelectedUserId) ? (int)$initialSelectedUserId : json_encode($initialSelectedUserId) ?>;

        function escapeHtml(str) {
            if (!str) return '';
            return str.toString()
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        function formatVyDate(dStr) {
            if (!dStr) return '-';
            try {
                const d = new Date(dStr);
                return isNaN(d.getTime()) ? dStr : d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            } catch (_) { return dStr; }
        }

        function formatVyTime(tStr) {
            if (!tStr) return '-';
            try {
                const d = new Date(tStr.includes(' ') ? tStr.replace(' ', 'T') : ('1970-01-01T' + tStr));
                return isNaN(d.getTime()) ? tStr : d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });
            } catch (_) { return tStr; }
        }

        function filterMember(uid) {
            selectedUserId = uid === 'all' ? 'all' : parseInt(uid, 10);

            const activeTabBtn = document.querySelector('.tab-btn.active');
            if (activeTabBtn) {
                const onclickText = activeTabBtn.getAttribute('onclick') || '';
                if (onclickText.includes('history')) loadAttendanceHistory();
                else if (onclickText.includes('leaves')) loadLeaves();
                else if (onclickText.includes('permissions')) loadPermissions();
            } else {
                loadAttendanceHistory();
            }
        }
        function formatElapsed(ms) {
            const s = Math.floor(ms / 1000);
            return [Math.floor(s / 3600), Math.floor((s % 3600) / 60), s % 60].map(n => String(n).padStart(2, '0')).join(':');
        }

        function tickTimer() {
            const start = localStorage.getItem(PUNCH_KEY);
            const breakStart = localStorage.getItem(BREAK_KEY);
            const el = document.getElementById('globalPunchTimer');
            const val = document.getElementById('punchTimerValue');
            const bEl = document.getElementById('globalBreakTimer');
            const bVal = document.getElementById('breakTimerValue');

            if (start && el && val) {
                val.textContent = formatElapsed(Date.now() - parseInt(start, 10));
                el.style.display = 'flex';
            } else if (el) {
                el.style.display = 'none';
            }

            if (breakStart && bEl && bVal) {
                bVal.textContent = formatElapsed(Date.now() - parseInt(breakStart, 10));
                bEl.style.display = 'flex';
            } else if (bEl) {
                bEl.style.display = 'none';
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
                if (!btnIn || !btnOut || !btnBIn || !btnBOut) return;

                if (data.is_punched_in) {
                    if (!data.is_on_break) {
                        localStorage.removeItem(BREAK_KEY);
                        if (!localStorage.getItem(PUNCH_KEY)) {
                            // Sync with server time by calculating elapsed duration
                            if (data.punch_in_ms && data.server_time) {
                                const elapsed = data.server_time - data.punch_in_ms;
                                localStorage.setItem(PUNCH_KEY, (Date.now() - elapsed).toString());
                            } else if (data.punch_in) {
                                localStorage.setItem(PUNCH_KEY, (new Date(data.punch_in)).getTime().toString());
                            }
                        }
                    } else { // On Break
                        localStorage.removeItem(PUNCH_KEY);
                        if (!localStorage.getItem(BREAK_KEY)) {
                            if (data.break_in_ms && data.server_time) {
                                const elapsed = data.server_time - data.break_in_ms;
                                localStorage.setItem(BREAK_KEY, (Date.now() - elapsed).toString());
                            }
                        }
                    }
                } else {
                    localStorage.removeItem(PUNCH_KEY);
                    localStorage.removeItem(BREAK_KEY);
                }
                tickTimer();
            }
        }

        function applyFilters() {
            const activeTabBtn = document.querySelector('.tab-btn.active');
            if (activeTabBtn) {
                const onclickText = activeTabBtn.getAttribute('onclick') || '';
                if (onclickText.includes('history')) loadAttendanceHistory();
                else if (onclickText.includes('leaves')) loadLeaves();
                else if (onclickText.includes('permissions')) loadPermissions();
            } else {
                loadAttendanceHistory();
            }
        }

        async function loadAttendanceHistory() {
            const startDate = document.getElementById('filterStartDate')?.value || '';
            const endDate = document.getElementById('filterEndDate')?.value || '';
            let url = '/api/attendance.php?action=history&user_id=' + selectedUserId;
            if (startDate && endDate) {
                url += `&start_date=${encodeURIComponent(startDate)}&end_date=${encodeURIComponent(endDate)}`;
            }
            const res = await fetch(url);
            const data = await res.json();
            const tbody = document.getElementById('attendanceHistoryBody');
            const headerRow = document.getElementById('historyHeaderRow');

            const isAll = (selectedUserId === 'all');
            if (headerRow) {
                headerRow.innerHTML = isAll
                    ? `<th>User</th><th>Date</th><th>First Punch In</th><th>Last Punch Out</th><th>Total Hours</th><th>Total Break Hours</th><th>Status</th><th>Action</th>`
                    : `<th>Date</th><th>First Punch In</th><th>Last Punch Out</th><th>Total Hours</th><th>Total Break Hours</th><th>Status</th><th>Action</th>`;
            }

            if (data.success && data.data && data.data.length > 0) {
                window.currentAttendanceData = data.data;
                tbody.innerHTML = data.data.map((at, index) => {
                    let statusColor = '#10b981';
                    let statusBg = 'rgba(16,185,129,.1)';
                    if (at.status === 'Break') {
                        statusColor = '#f59e0b';
                        statusBg = 'rgba(245,158,11,.1)';
                    }
                    const statusTag = `<span class="badge" style="background:${statusBg};border:1px solid ${statusColor};color:${statusColor};">${at.status || 'Present'}</span>`;
                    const fn = (at.first_name || '').trim();
                    const ln = (at.last_name || '').trim();
                    const fullName = (fn + ' ' + ln).trim();
                    const dispName = fullName.length > 0 ? fullName : (at.username || 'Unknown');
                    const userCell = isAll ? `<td class="text-bold">${escapeHtml(dispName)}</td>` : '';
                    return `
                    <tr>
                        ${userCell}
                        <td class="${isAll ? '' : 'text-bold'}">${formatVyDate(at.date)}</td>
                        <td>${formatVyTime(at.punch_in)}</td>
                        <td>${formatVyTime(at.punch_out)}</td>
                        <td>${at.total_hours || '-'}</td>
                        <td>${at.total_break_hours || '-'}</td>
                        <td>${statusTag}</td>
                        <td><button class="btn-icon" style="color:var(--primary); padding:5px 10px; background:var(--surface);" onclick='viewAttendanceHistory(${index})'><i class="fa-solid fa-eye"></i></button></td>
                    </tr>`;
                }).join('');
            } else {
                const cols = isAll ? 8 : 7;
                tbody.innerHTML = `<tr><td colspan="${cols}" style="text-align:center;padding:20px;color:var(--text-muted);">No attendance records found.</td></tr>`;
            }
        }

        function viewAttendanceHistory(index) {
            const at = window.currentAttendanceData[index];
            let html = '';
            if (at.username) {
                html += `<p><strong>Team Member:</strong> ${escapeHtml(at.username)}</p>`;
            }
            html += `<p><strong>Date:</strong> ${formatVyDate(at.date)}</p>
                        <p><strong>First Punch In:</strong> ${formatVyTime(at.punch_in)}</p>
                        <p><strong>Last Punch Out:</strong> ${formatVyTime(at.punch_out)}</p>
                        <p><strong>Total Hours:</strong> ${at.total_hours || '-'}</p>
                        <p><strong>Total Break Hours:</strong> ${at.total_break_hours || '-'}</p>
                        <hr style="margin:15px 0; border:0; border-top:1px solid var(--border);">
                        <h4 style="margin-bottom:10px;">Break History</h4>`;

            const breaks = at.break_history ? JSON.parse(at.break_history) : [];
            if (breaks.length > 0) {
                html += `<ul style="list-style:none; padding:0; margin:0;">`;
                breaks.forEach((b, i) => {
                    html += `<li style="padding:8px 0; border-bottom:1px solid #f1f1f1;">
                        <strong>Break ${i + 1}:</strong> ${formatVyTime(b.start)} - ${formatVyTime(b.end)}
                    </li>`;
                });
                html += `</ul>`;
            } else {
                html += `<p style="color:var(--text-muted); margin-top:10px;">No breaks recorded.</p>`;
            }

            document.getElementById('historyModalBody').innerHTML = html;
            openModal('attendanceHistoryModal');
        }

        async function loadLeaves() {
            const res = await fetch('/api/leaves.php?user_id=' + selectedUserId);
            const data = await res.json();
            const tbody = document.getElementById('leaveHistoryBody');
            const headerRow = document.getElementById('leavesHeaderRow');
            const leavesTitle = document.getElementById('leavesTitle');

            const isAll = (selectedUserId === 'all');
            if (leavesTitle) {
                leavesTitle.textContent = isAll ? "Team Leave Applications" : "My Leave Applications";
            }
            if (headerRow) {
                headerRow.innerHTML = isAll
                    ? `<th>User</th><th>Leave Type</th><th>From Date</th><th>To Date</th><th>Reason</th><th>Status</th>`
                    : `<th>Leave Type</th><th>From Date</th><th>To Date</th><th>Reason</th><th>Status</th>`;
            }

            if (data.success) {
                tbody.innerHTML = data.data.map(l => {
                    const userCell = isAll ? `<td class="text-bold">${escapeHtml(l.username || 'Unknown')}</td>` : '';
                    return `
                    <tr>
                        ${userCell}
                        <td class="${isAll ? '' : 'text-bold'}">${l.leave_type}</td>
                        <td>${formatVyDate(l.from_date)}</td>
                        <td>${formatVyDate(l.to_date)}</td>
                        <td>${escapeHtml(l.reason)}</td>
                        <td><span class="badge badge-${l.status === 'pending' ? 'warm' : (l.status === 'approved' ? 'success' : 'hot')}">${l.status}</span></td>
                    </tr>
                `}).join('') || `<tr><td colspan="${isAll ? 6 : 5}" style="text-align:center;padding:20px;color:var(--text-muted);">No leave applications found</td></tr>`;
            }
        }

        async function loadPermissions() {
            const res = await fetch('/api/permissions.php?user_id=' + selectedUserId);
            const data = await res.json();
            const tbody = document.getElementById('permissionHistoryBody');
            const headerRow = document.getElementById('permissionsHeaderRow');
            const permissionsTitle = document.getElementById('permissionsTitle');

            const isAll = (selectedUserId === 'all');
            if (permissionsTitle) {
                permissionsTitle.textContent = isAll ? "Team Permission Requests" : "My Permission Requests";
            }
            if (headerRow) {
                headerRow.innerHTML = isAll
                    ? `<th>User</th><th>Date</th><th>Time Window</th><th>Duration</th><th>Reason</th><th>Status</th>`
                    : `<th>Date</th><th>Time Window</th><th>Duration</th><th>Reason</th><th>Status</th>`;
            }

            if (data.success) {
                tbody.innerHTML = data.data.map(p => {
                    const userCell = isAll ? `<td class="text-bold">${escapeHtml(p.username || 'Unknown')}</td>` : '';
                    return `
                    <tr>
                        ${userCell}
                        <td class="${isAll ? '' : 'text-bold'}">${formatVyDate(p.date)}</td>
                        <td>${escapeHtml(p.time_window)}</td>
                        <td>${escapeHtml(p.duration)}</td>
                        <td>${escapeHtml(p.reason)}</td>
                        <td><span class="badge badge-${p.status === 'pending' ? 'warm' : (p.status === 'approved' ? 'success' : 'hot')}">${p.status}</span></td>
                    </tr>
                `}).join('') || `<tr><td colspan="${isAll ? 6 : 5}" style="text-align:center;padding:20px;color:var(--text-muted);">No permission requests found</td></tr>`;
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            const sidebar = document.getElementById('sidebar');
            const sidebarOverlay = document.getElementById('sidebarOverlay');
            if (sidebarOverlay && (!sidebar || !sidebar.classList.contains('mobile-open'))) {
                sidebarOverlay.style.display = 'none';
                sidebarOverlay.style.pointerEvents = 'none';
            }
            document.querySelectorAll('.modal').forEach(modal => {
                if (!modal.classList.contains('show')) {
                    modal.style.display = 'none';
                }
            });

            const leaveForm = document.getElementById('leaveForm');
            if (leaveForm) {
                leaveForm.onsubmit = async (e) => {
                    e.preventDefault();
                    const res = await fetch('/api/leaves.php', { method: 'POST', body: new FormData(e.target) });
                    const data = await res.json();
                    if (data.success) { vyToast(data.message); closeModal('leaveModal'); loadLeaves(); }
                    else vyToast(data.message, 'error');
                };
            }

            const permissionForm = document.getElementById('permissionForm');
            if (permissionForm) {
                permissionForm.onsubmit = async (e) => {
                    e.preventDefault();
                    const res = await fetch('/api/permissions.php', { method: 'POST', body: new FormData(e.target) });
                    const data = await res.json();
                    if (data.success) { vyToast(data.message); closeModal('permissionModal'); loadPermissions(); }
                    else vyToast(data.message, 'error');
                };
            }

            const mf = document.getElementById('memberFilter');
            if (mf && mf.value) {
                selectedUserId = mf.value === 'all' ? 'all' : parseInt(mf.value, 10);
            }

            setInterval(tickTimer, 1000);
            fetchStatus();
            loadAttendanceHistory();
        });
    </script>
</body>

</html>
