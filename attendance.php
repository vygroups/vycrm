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
$approverUsers = [];

// Always fetch approver users (Equal & Upper Roles + Admins)
try {
    $currentRoleId = isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : null;
    $upperRoleIds = dm_get_visible_user_ids($conn, $prefix, $currentUserId, $currentRoleId, 'role_up', $isAdmin);
    
    if ($isAdmin || $upperRoleIds === null) {
        $appStmt = $conn->query("SELECT u.id, u.username, u.first_name, u.last_name, r.name as role_name FROM {$prefix}users u LEFT JOIN {$prefix}roles r ON r.id = u.role_id ORDER BY u.username ASC");
        $approverUsers = $appStmt->fetchAll(PDO::FETCH_ASSOC);
    } else if (!empty($upperRoleIds)) {
        $appInClause = implode(',', array_map('intval', $upperRoleIds));
        $appStmt = $conn->query("SELECT u.id, u.username, u.first_name, u.last_name, r.name as role_name FROM {$prefix}users u LEFT JOIN {$prefix}roles r ON r.id = u.role_id WHERE u.id IN ($appInClause) OR u.is_admin = 1 ORDER BY u.username ASC");
        $approverUsers = $appStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $appStmt = $conn->query("SELECT u.id, u.username, u.first_name, u.last_name, r.name as role_name FROM {$prefix}users u LEFT JOIN {$prefix}roles r ON r.id = u.role_id WHERE u.is_admin = 1 OR u.id = $currentUserId ORDER BY u.username ASC");
        $approverUsers = $appStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($approverUsers) || (count($approverUsers) === 1 && (int)$approverUsers[0]['id'] === $currentUserId)) {
        $appStmt = $conn->query("SELECT u.id, u.username, u.first_name, u.last_name, r.name as role_name FROM {$prefix}users u LEFT JOIN {$prefix}roles r ON r.id = u.role_id ORDER BY u.username ASC");
        $approverUsers = $appStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
}

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
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.default.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
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
            if (m) {
                m.style.display = 'flex';
                m.querySelectorAll('.tom-multi-select').forEach(el => {
                    if (el.tomselect) el.tomselect.sync();
                });
            }
        }

        function closeModal(id) {
            const m = document.getElementById(id);
            if (m) m.style.display = 'none';
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
        .ts-wrapper { width: 100% !important; margin-bottom: 0 !important; }
        .ts-control {
            border-radius: 12px !important;
            border: 1px solid var(--border) !important;
            padding: 6px 12px !important;
            font-size: 14px !important;
            min-height: 44px !important;
            background: #ffffff !important;
            display: flex !important;
            flex-wrap: wrap !important;
            align-items: center !important;
            gap: 4px !important;
            box-shadow: none !important;
        }
        .ts-control.focus {
            border-color: var(--primary) !important;
            box-shadow: 0 0 0 2px rgba(123,94,240,0.2) !important;
        }
        .ts-dropdown {
            border-radius: 12px !important;
            border: 1px solid var(--border) !important;
            box-shadow: var(--shadow-lg) !important;
            font-size: 14px !important;
            padding: 6px !important;
            z-index: 10500 !important;
            background: #ffffff !important;
        }
        .ts-dropdown .option {
            padding: 8px 12px !important;
            border-radius: 8px !important;
            cursor: pointer !important;
        }
        .ts-dropdown .option.active, .ts-dropdown .option:hover {
            background: rgba(123,94,240,0.08) !important;
            color: var(--primary) !important;
        }
        .ts-control .item {
            background: rgba(123,94,240,0.12) !important;
            color: var(--primary) !important;
            border-radius: 8px !important;
            padding: 4px 10px !important;
            font-size: 13px !important;
            font-weight: 600 !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 4px !important;
            border: 1px solid rgba(123,94,240,0.2) !important;
            margin: 2px 4px 2px 0 !important;
        }
        .ts-control .item .remove {
            border-left: 1px solid rgba(123,94,240,0.3) !important;
            margin-left: 6px !important;
            padding-left: 6px !important;
            color: var(--primary) !important;
            text-decoration: none !important;
            font-weight: 700 !important;
            font-size: 13px !important;
            opacity: 0.8 !important;
        }
        .ts-control .item .remove:hover {
            opacity: 1 !important;
            color: #ef4444 !important;
        }

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
                                <input type="date" id="filterStartDate" class="form-control" value="<?= date('Y-m-01') ?>" onchange="applyFilters()" style="width:130px; padding:4px 8px; font-size:13px; border-radius:8px; border:1.5px solid var(--border); background:#fff; height:32px; box-sizing:border-box;">
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
                    <label class="form-label">TO (Approvers) <span style="color:#ef4444;">*</span></label>
                    <select multiple class="form-control tom-multi-select" name="to_user_ids[]" placeholder="Search and select TO approvers..." required style="width:100%;">
                        <?php foreach ($approverUsers as $u):
                            if ((int)$u['id'] === $currentUserId) continue;
                            $fullName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                            $disp = $fullName !== '' ? $fullName : $u['username'];
                            if (!empty($u['role_name'])) $disp .= ' (' . $u['role_name'] . ')';
                        ?>
                            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($disp) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">CC (Notify / Secondary Approval - Optional)</label>
                    <select multiple class="form-control tom-multi-select" name="cc_user_ids[]" placeholder="Search and select CC members..." style="width:100%;">
                        <?php foreach ($approverUsers as $u):
                            if ((int)$u['id'] === $currentUserId) continue;
                            $fullName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                            $disp = $fullName !== '' ? $fullName : $u['username'];
                            if (!empty($u['role_name'])) $disp .= ' (' . $u['role_name'] . ')';
                        ?>
                            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($disp) ?></option>
                        <?php endforeach; ?>
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
                    <label class="form-label">TO (Approvers) <span style="color:#ef4444;">*</span></label>
                    <select multiple class="form-control tom-multi-select" name="to_user_ids[]" placeholder="Search and select TO approvers..." required style="width:100%;">
                        <?php foreach ($approverUsers as $u):
                            if ((int)$u['id'] === $currentUserId) continue;
                            $fullName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                            $disp = $fullName !== '' ? $fullName : $u['username'];
                            if (!empty($u['role_name'])) $disp .= ' (' . $u['role_name'] . ')';
                        ?>
                            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($disp) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">CC (Notify / Secondary Approval - Optional)</label>
                    <select multiple class="form-control tom-multi-select" name="cc_user_ids[]" placeholder="Search and select CC members..." style="width:100%;">
                        <?php foreach ($approverUsers as $u):
                            if ((int)$u['id'] === $currentUserId) continue;
                            $fullName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                            $disp = $fullName !== '' ? $fullName : $u['username'];
                            if (!empty($u['role_name'])) $disp .= ' (' . $u['role_name'] . ')';
                        ?>
                            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($disp) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
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

        function switchTab(evt, tabName) {
            let i, tabcontent, tablinks;
            tabcontent = document.getElementsByClassName("tab-content");
            for (i = 0; i < tabcontent.length; i++) {
                tabcontent[i].style.display = "none";
                tabcontent[i].classList.remove("active");
            }
            tablinks = document.getElementsByClassName("tab-btn");
            for (i = 0; i < tablinks.length; i++) {
                tablinks[i].classList.remove("active");
            }
            const targetTab = document.getElementById(tabName);
            if (targetTab) {
                targetTab.style.display = "block";
                targetTab.classList.add("active");
            }
            if (evt && evt.currentTarget) {
                evt.currentTarget.classList.add("active");
            }

            if (tabName === 'leaves' || tabName === 'permissions') {
                const mf = document.getElementById('memberFilter');
                if (mf) {
                    mf.value = 'all';
                    selectedUserId = 'all';
                }
            }

            if (tabName === 'history') loadAttendanceHistory();
            else if (tabName === 'leaves') loadLeaves();
            else if (tabName === 'permissions') loadPermissions();
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

        function getStatusBadge(status) {
            const s = (status || 'pending').toLowerCase();
            if (s === 'approved') {
                return `<span class="badge" style="background:rgba(16,185,129,.1);border:1px solid #10b981;color:#10b981;font-weight:700;">APPROVED</span>`;
            } else if (s === 'partially_approved') {
                return `<span class="badge" style="background:rgba(59,130,246,.1);border:1px solid #3b82f6;color:#3b82f6;font-weight:700;">PARTIALLY APPROVED</span>`;
            } else if (s === 'rejected') {
                return `<span class="badge" style="background:rgba(239,68,68,.1);border:1px solid #ef4444;font-weight:700;color:#ef4444;">REJECTED</span>`;
            }
            return `<span class="badge" style="background:rgba(245,158,11,.1);border:1px solid #f59e0b;font-weight:700;color:#f59e0b;">PENDING</span>`;
        }

        async function updateItemStatus(apiPath, id, status) {
            const formData = new FormData();
            formData.append('action', 'update_status');
            formData.append('id', id);
            formData.append('status', status);

            const res = await fetch(apiPath, { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                vyToast(data.message);
                if (apiPath.includes('leaves')) loadLeaves();
                else loadPermissions();
            } else {
                vyToast(data.message, 'error');
            }
        }

        async function loadLeaves() {
            const tbody = document.getElementById('leaveHistoryBody');
            const headerRow = document.getElementById('leavesHeaderRow');
            const leavesTitle = document.getElementById('leavesTitle');
            const startDate = document.getElementById('filterStartDate')?.value || '';
            const endDate = document.getElementById('filterEndDate')?.value || '';

            if (tbody) {
                tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:30px;color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin" style="font-size:24px;color:var(--primary);margin-bottom:8px;"></i><br>Loading leave applications...</td></tr>`;
            }

            let url = '/api/leaves.php?user_id=' + selectedUserId;
            if (startDate && endDate) {
                url += `&start_date=${encodeURIComponent(startDate)}&end_date=${encodeURIComponent(endDate)}`;
            }

            const res = await fetch(url);
            const data = await res.json();

            const isAll = (selectedUserId === 'all');
            if (leavesTitle) {
                leavesTitle.textContent = isAll ? "Team Leave Applications" : "My Leave Applications";
            }
            if (headerRow) {
                headerRow.innerHTML = `<th>Applicant</th><th>Leave Details</th><th>TO / CC Approvers</th><th>Status & Audit</th><th>Action</th>`;
            }

            if (data.success && data.data) {
                const currentUserId = <?= $currentUserId ?>;
                tbody.innerHTML = data.data.map(l => {
                    const fn = (l.first_name || '').trim();
                    const ln = (l.last_name || '').trim();
                    const fullName = (fn + ' ' + ln).trim();
                    const applicantName = fullName.length > 0 ? fullName : (l.username || 'User #' + l.user_id);
                    
                    const toName = l.to_display_name || 'Not assigned';
                    const ccNames = (l.cc_user_names && l.cc_user_names.length > 0) ? l.cc_user_names.join(', ') : 'None';
                    
                    let auditText = '';
                    if (l.approved_by_name) {
                        auditText = `<div style="font-size:11px; color:var(--text-muted); margin-top:4px;"><i class="fa-solid fa-user-check"></i> By ${escapeHtml(l.approved_by_name)}</div>`;
                    }

                    let actionBtns = '-';
                    const canDecision = (parseInt(l.user_id, 10) !== currentUserId) && (l.status === 'pending' || l.status === 'partially_approved');
                    if (canDecision) {
                        actionBtns = `
                            <div style="display:flex; gap:6px;">
                                <button class="btn" style="background:#10b981; color:#fff; border:none; padding:4px 10px; border-radius:6px; font-size:12px; cursor:pointer;" onclick="updateItemStatus('/api/leaves.php', ${l.id}, 'approved')"><i class="fa-solid fa-check"></i></button>
                                <button class="btn" style="background:#ef4444; color:#fff; border:none; padding:4px 10px; border-radius:6px; font-size:12px; cursor:pointer;" onclick="updateItemStatus('/api/leaves.php', ${l.id}, 'rejected')"><i class="fa-solid fa-xmark"></i></button>
                            </div>
                        `;
                    }

                    return `
                    <tr>
                        <td class="text-bold">${escapeHtml(applicantName)}</td>
                        <td>
                            <strong style="color:var(--primary);">${escapeHtml(l.leave_type)}</strong><br>
                            <span style="font-size:12px; color:var(--text-muted);">${formatVyDate(l.from_date)} to ${formatVyDate(l.to_date)}</span><br>
                            <span style="font-size:12px; font-style:italic;">"${escapeHtml(l.reason)}"</span>
                        </td>
                        <td>
                            <div style="font-size:12px;"><strong>TO:</strong> ${escapeHtml(toName)}</div>
                            <div style="font-size:11px; color:var(--text-muted);"><strong>CC:</strong> ${escapeHtml(ccNames)}</div>
                        </td>
                        <td>
                            ${getStatusBadge(l.status)}
                            ${auditText}
                        </td>
                        <td>${actionBtns}</td>
                    </tr>
                `}).join('') || `<tr><td colspan="5" style="text-align:center;padding:20px;color:var(--text-muted);">No leave applications found</td></tr>`;
            } else {
                tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:20px;color:var(--text-muted);">No leave applications found</td></tr>`;
            }
        }

        async function loadPermissions() {
            const tbody = document.getElementById('permissionHistoryBody');
            const headerRow = document.getElementById('permissionsHeaderRow');
            const permissionsTitle = document.getElementById('permissionsTitle');
            const startDate = document.getElementById('filterStartDate')?.value || '';
            const endDate = document.getElementById('filterEndDate')?.value || '';

            if (tbody) {
                tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:30px;color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin" style="font-size:24px;color:var(--primary);margin-bottom:8px;"></i><br>Loading permission requests...</td></tr>`;
            }

            let url = '/api/permissions.php?user_id=' + selectedUserId;
            if (startDate && endDate) {
                url += `&start_date=${encodeURIComponent(startDate)}&end_date=${encodeURIComponent(endDate)}`;
            }

            const res = await fetch(url);
            const data = await res.json();

            const isAll = (selectedUserId === 'all');
            if (permissionsTitle) {
                permissionsTitle.textContent = isAll ? "Team Permission Requests" : "My Permission Requests";
            }
            if (headerRow) {
                headerRow.innerHTML = `<th>Applicant</th><th>Permission Details</th><th>TO / CC Approvers</th><th>Status & Audit</th><th>Action</th>`;
            }

            if (data.success && data.data) {
                const currentUserId = <?= $currentUserId ?>;
                tbody.innerHTML = data.data.map(p => {
                    const fn = (p.first_name || '').trim();
                    const ln = (p.last_name || '').trim();
                    const fullName = (fn + ' ' + ln).trim();
                    const applicantName = fullName.length > 0 ? fullName : (p.username || 'User #' + p.user_id);
                    
                    const toName = p.to_display_name || 'Not assigned';
                    const ccNames = (p.cc_user_names && p.cc_user_names.length > 0) ? p.cc_user_names.join(', ') : 'None';
                    
                    let auditText = '';
                    if (p.approved_by_name) {
                        auditText = `<div style="font-size:11px; color:var(--text-muted); margin-top:4px;"><i class="fa-solid fa-user-check"></i> By ${escapeHtml(p.approved_by_name)}</div>`;
                    }

                    let actionBtns = '-';
                    const canDecision = (parseInt(p.user_id, 10) !== currentUserId) && (p.status === 'pending' || p.status === 'partially_approved');
                    if (canDecision) {
                        actionBtns = `
                            <div style="display:flex; gap:6px;">
                                <button class="btn" style="background:#10b981; color:#fff; border:none; padding:4px 10px; border-radius:6px; font-size:12px; cursor:pointer;" onclick="updateItemStatus('/api/permissions.php', ${p.id}, 'approved')"><i class="fa-solid fa-check"></i></button>
                                <button class="btn" style="background:#ef4444; color:#fff; border:none; padding:4px 10px; border-radius:6px; font-size:12px; cursor:pointer;" onclick="updateItemStatus('/api/permissions.php', ${p.id}, 'rejected')"><i class="fa-solid fa-xmark"></i></button>
                            </div>
                        `;
                    }

                    return `
                    <tr>
                        <td class="text-bold">${escapeHtml(applicantName)}</td>
                        <td>
                            <strong style="color:var(--primary);">${formatVyDate(p.date)} (${escapeHtml(p.duration)})</strong><br>
                            <span style="font-size:12px; color:var(--text-muted);">${escapeHtml(p.time_window)}</span><br>
                            <span style="font-size:12px; font-style:italic;">"${escapeHtml(p.reason)}"</span>
                        </td>
                        <td>
                            <div style="font-size:12px;"><strong>TO:</strong> ${escapeHtml(toName)}</div>
                            <div style="font-size:11px; color:var(--text-muted);"><strong>CC:</strong> ${escapeHtml(ccNames)}</div>
                        </td>
                        <td>
                            ${getStatusBadge(p.status)}
                            ${auditText}
                        </td>
                        <td>${actionBtns}</td>
                    </tr>
                `}).join('') || `<tr><td colspan="5" style="text-align:center;padding:20px;color:var(--text-muted);">No permission requests found</td></tr>`;
            } else {
                tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:20px;color:var(--text-muted);">No permission requests found</td></tr>`;
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
                    if (data.success) { 
                        vyToast(data.message); 
                        closeModal('leaveModal'); 
                        leaveForm.reset();
                        leaveForm.querySelectorAll('.tom-multi-select').forEach(el => {
                            if (el.tomselect) el.tomselect.clear();
                        });
                        loadLeaves(); 
                    }
                    else vyToast(data.message, 'error');
                };
            }

            const permissionForm = document.getElementById('permissionForm');
            if (permissionForm) {
                permissionForm.onsubmit = async (e) => {
                    e.preventDefault();
                    const res = await fetch('/api/permissions.php', { method: 'POST', body: new FormData(e.target) });
                    const data = await res.json();
                    if (data.success) { 
                        vyToast(data.message); 
                        closeModal('permissionModal'); 
                        permissionForm.reset();
                        permissionForm.querySelectorAll('.tom-multi-select').forEach(el => {
                            if (el.tomselect) el.tomselect.clear();
                        });
                        loadPermissions(); 
                    }
                    else vyToast(data.message, 'error');
                };
            }

            const mf = document.getElementById('memberFilter');
            if (mf && mf.value) {
                selectedUserId = mf.value === 'all' ? 'all' : parseInt(mf.value, 10);
            }

            document.querySelectorAll('.tom-multi-select').forEach(el => {
                if (typeof TomSelect !== 'undefined') {
                    new TomSelect(el, {
                        dropdownParent: 'body',
                        plugins: ['remove_button'],
                        create: false,
                        maxItems: null,
                        sortField: { field: 'text', direction: 'asc' }
                    });
                }
            });

            setInterval(tickTimer, 1000);
            fetchStatus();
            loadAttendanceHistory();
        });
    </script>
</body>

</html>
