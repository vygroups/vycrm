<?php
// manage_requests.php - Admin Approval Center for Leaves and Permissions
require_once 'auth_check.php';
require_once 'config/database.php';

$v = time();
$dbName = $_SESSION['tenant_db'];
$prefix = $_SESSION['tenant_prefix'];
$conn = Database::getTenantConn($dbName);

// Fetch All Pending Requests
try {
    // Leaves
    $stmtLeaves = $conn->prepare("SELECT l.*, u.username FROM {$prefix}leaves l JOIN {$prefix}users u ON l.user_id = u.id WHERE l.status = 'pending' ORDER BY l.created_at DESC");
    $stmtLeaves->execute();
    $pendingLeaves = $stmtLeaves->fetchAll(PDO::FETCH_ASSOC);

    // Permissions
    $stmtPerms = $conn->prepare("SELECT p.*, u.username FROM {$prefix}permissions p JOIN {$prefix}users u ON p.user_id = u.id WHERE p.status = 'pending' ORDER BY p.created_at DESC");
    $stmtPerms->execute();
    $pendingPerms = $stmtPerms->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Requests Approval - Vy CRM</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
</head>
<body>
<div class="app-wrapper">
    <aside class="sidebar">
        <div class="sidebar-head">
            <a href="dashboard.php"><img src="/images/logo.png?v=<?= $v ?>" alt="Vy CRM" style="max-height:50px;"></a>
        </div>
        <div class="sidebar-nav">
            <a href="dashboard.php" class="nav-item"><i class="fa-solid fa-chart-pie"></i><span class="nav-text">Dashboard</span></a>
            <a href="attendance.php" class="nav-item"><i class="fa-regular fa-clock"></i><span class="nav-text">Attendance</span></a>
            <a href="manage_requests.php" class="nav-item active"><i class="fa-solid fa-clipboard-check"></i><span class="nav-text">Approvals</span></a>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div class="breadcrumb">Admin / <span class="current">Pending Approvals</span></div>
        </header>

        <div class="content-scroll">
            <div class="table-panel mb-5">
                <div class="table-header"><div class="table-title">Pending Leave Applications</div></div>
                <div class="table-responsive">
                    <table class="crm-table">
                        <thead><tr><th>User</th><th>Leave Type</th><th>From</th><th>To</th><th>Reason</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($pendingLeaves as $l): ?>
                            <tr id="leave-<?= $l['id'] ?>">
                                <td class="text-bold"><?= htmlspecialchars($l['username']) ?></td>
                                <td><?= htmlspecialchars($l['leave_type']) ?></td>
                                <td><?= $l['from_date'] ?></td>
                                <td><?= $l['to_date'] ?></td>
                                <td><?= htmlspecialchars($l['reason']) ?></td>
                                <td>
                                    <button class="btn-primary" style="background:#10b981;padding:5px 12px;width:auto;margin-right:5px;" onclick="updateReq('leave', <?= $l['id'] ?>, 'approved')">Approve</button>
                                    <button class="btn-primary" style="background:#ef4444;padding:5px 12px;width:auto;" onclick="updateReq('leave', <?= $l['id'] ?>, 'rejected')">Reject</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($pendingLeaves)): ?>
                                <tr><td colspan="6" style="text-align:center;padding:20px;">No pending leaves.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="table-panel">
                <div class="table-header"><div class="table-title">Pending Permission Requests</div></div>
                <div class="table-responsive">
                    <table class="crm-table">
                        <thead><tr><th>User</th><th>Date</th><th>Window</th><th>Duration</th><th>Reason</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($pendingPerms as $p): ?>
                            <tr id="perm-<?= $p['id'] ?>">
                                <td class="text-bold"><?= htmlspecialchars($p['username']) ?></td>
                                <td><?= $p['date'] ?></td>
                                <td><?= htmlspecialchars($p['time_window']) ?></td>
                                <td><?= htmlspecialchars($p['duration']) ?></td>
                                <td><?= htmlspecialchars($p['reason']) ?></td>
                                <td>
                                    <button class="btn-primary" style="background:#10b981;padding:5px 12px;width:auto;margin-right:5px;" onclick="updateReq('perm', <?= $p['id'] ?>, 'approved')">Approve</button>
                                    <button class="btn-primary" style="background:#ef4444;padding:5px 12px;width:auto;" onclick="updateReq('perm', <?= $p['id'] ?>, 'rejected')">Reject</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($pendingPerms)): ?>
                                <tr><td colspan="6" style="text-align:center;padding:20px;">No pending permissions.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
async function updateReq(type, id, status) {
    const endpoint = type === 'leave' ? '/api/leaves.php' : '/api/permissions.php';
    const res = await fetch(endpoint, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=update_status&id=${id}&status=${status}`
    });
    const data = await res.json();
    if (data.success) {
        document.getElementById(`${type}-${id}`).remove();
        alert('Status updated successfully');
    } else {
        alert('Error: ' + data.message);
    }
}
</script>
</body>
</html>
