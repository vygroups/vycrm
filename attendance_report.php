<?php
// attendance_report.php - Attendance Reporting Page
require_once 'auth_check.php';
require_once 'config/database.php';

$v = time();
$db = Database::getMasterConn();
$prefix = Database::getMasterPrefix();

$context = ['user_id' => $_SESSION['user_id'], 'db_name' => $_SESSION['tenant_db'], 'prefix' => $_SESSION['tenant_prefix']];
$conn = Database::getTenantConn($context['db_name']);
$tPrefix = $context['prefix'];

// Fetch users for the filter (Admin only)
$users = [];
try {
    $userStmt = $conn->query("SELECT id, username FROM users ORDER BY username ASC");
    $users = $userStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$userId = $_GET['user_id'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vy CRM - Attendance Report</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <style>
        .report-filters { background: var(--surface); padding: 20px; border-radius: 20px; box-shadow: var(--shadow-sm); margin-bottom: 24px; display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; }
        .filter-group { display: flex; flex-direction: column; gap: 5px; }
        .filter-label { font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; }
        .report-table-panel { background: var(--surface); border-radius: 22px; box-shadow: var(--shadow-md); padding: 24px; }
        @media print { .sidebar, .topbar, .report-filters, .btn-primary { display: none !important; } .main-content { margin-left: 0 !important; padding: 0 !important; } .report-table-panel { box-shadow: none; border: 1px solid #eee; } }
    </style>
</head>
<body>
    <div class="app-wrapper">
        <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleMobileSidebar()" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:90;"></div>
        <?php include 'includes/sidebar.php'; ?>
        <main class="main-content">
            <header class="topbar">
                <div class="flex items-center">
                    <button class="btn-icon" onclick="toggleMobileSidebar()" style="margin-right:20px;display:none;" id="mobileToggle"><i class="fa-solid fa-bars"></i></button>
                    <div class="breadcrumb">Attendance / <span class="current">Report</span></div>
                </div>
                <div class="topbar-right">
                    <button class="btn-primary" onclick="window.print()" style="width:auto; padding: 10px 20px;"><i class="fa-solid fa-print"></i> Print Report</button>
                </div>
            </header>
            <div class="content-scroll">
                <section class="report-filters">
                    <form action="" method="GET" class="flex" style="gap:15px; width:100%; flex-wrap:wrap;">
                        <div class="filter-group">
                            <label class="filter-label">Start Date</label>
                            <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($startDate) ?>">
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">End Date</label>
                            <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($endDate) ?>">
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Team Member</label>
                            <select name="user_id" class="form-control" style="min-width:180px;">
                                <option value="">All Members</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?= $u['id'] ?>" <?= $userId == $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['username']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn-primary" style="width:auto; height:42px; margin-top:21px;">Filter</button>
                    </form>
                </section>

                <div class="report-table-panel">
                    <div class="table-header">
                        <div class="table-title">Attendance Records: <?= date('d M', strtotime($startDate)) ?> - <?= date('d M', strtotime($endDate)) ?></div>
                    </div>
                    <div class="table-responsive">
                        <table class="crm-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Date</th>
                                    <th>Punch In</th>
                                    <th>Punch Out</th>
                                    <th>Total Hours</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="reportTableBody">
                                <!-- Loaded via JS for consistency -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        async function fetchReport() {
            const params = new URLSearchParams(window.location.search);
            const res = await fetch(`/api/attendance.php?action=report&${params.toString()}`);
            const data = await res.json();
            const tbody = document.getElementById('reportTableBody');
            
            if (data.success && data.data && data.data.length > 0) {
                tbody.innerHTML = data.data.map(at => `
                    <tr>
                        <td class="text-bold">${at.username}</td>
                        <td>${at.date}</td>
                        <td>${at.punch_in ? new Date(at.punch_in).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) : '-'}</td>
                        <td>${at.punch_out ? new Date(at.punch_out).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) : '-'}</td>
                        <td>${at.total_hours || '-'}</td>
                        <td><span class="badge ${at.type === 'break' ? 'badge-warm' : 'badge-success'}">${at.type === 'shift' ? 'Work' : 'Break'}</span></td>
                    </tr>
                `).join('');
            } else {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:40px;color:var(--text-muted);">No records found for the selected criteria.</td></tr>';
            }
        }

        function toggleMobileSidebar() {
            const s = document.getElementById('sidebar');
            const o = document.getElementById('sidebarOverlay');
            s.classList.toggle('mobile-open');
            o.style.display = s.classList.contains('mobile-open') ? 'block' : 'none';
        }

        fetchReport();
    </script>
</body>
</html>
