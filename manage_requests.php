<?php
// manage_requests.php - Admin Approval Center for Leaves and Permissions
require_once 'auth_check.php';
require_once 'config/database.php';
require_once 'includes/brand.php';

$dbName = $_SESSION['tenant_db'];
$prefix = $_SESSION['tenant_prefix'];
$conn = Database::getTenantConn($dbName);

// Fetch child roles for hierarchy filtering
$childRoleIds = [];
$isAdmin = !empty($_SESSION['is_admin']);
$myRoleId = $_SESSION['role_id'] ?? null;

if (!$isAdmin && $myRoleId) {
    try {
        $stmt = $conn->prepare("SELECT child_role_id FROM {$prefix}role_hierarchy WHERE parent_role_id = ?");
        $stmt->execute([$myRoleId]);
        $childRoleIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {}
}

// Fetch All Pending Requests
try {
    $whereClause = "WHERE l.status = 'pending'";
    if (!$isAdmin) {
        if (!empty($childRoleIds)) {
            $whereClause .= " AND u.role_id IN (" . implode(',', array_map('intval', $childRoleIds)) . ")";
        } else {
            $whereClause .= " AND 1=0"; // Show nothing if no child roles
        }
    }

    // Leaves
    $stmtLeaves = $conn->prepare("SELECT l.*, u.username, u.first_name, u.last_name FROM {$prefix}leaves l JOIN {$prefix}users u ON l.user_id = u.id $whereClause ORDER BY l.created_at DESC");
    $stmtLeaves->execute();
    $pendingLeaves = $stmtLeaves->fetchAll(PDO::FETCH_ASSOC);

    // Permissions (using similar logic for p)
    $whereClauseP = str_replace('l.', 'p.', $whereClause);
    $stmtPerms = $conn->prepare("SELECT p.*, u.username, u.first_name, u.last_name FROM {$prefix}permissions p JOIN {$prefix}users u ON p.user_id = u.id $whereClauseP ORDER BY p.created_at DESC");
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
    <title><?= htmlspecialchars(brand_page_title('Requests Approval')) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link rel="shortcut icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
</head>
<body>
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>

    <main class="main-content">
        <header class="topbar">
            <div class="breadcrumb">Admin / <span class="current">Pending Approvals</span></div>
        </header>

        <div class="content-scroll">
            <div class="table-panel" style="margin-bottom: 40px;">
                <div class="table-header" style="cursor:pointer;" onclick="togglePanel(this)">
                    <div class="table-title">Pending Leave Applications</div>
                    <div class="table-actions">
                        <i class="fa-solid fa-chevron-up transition-transform" style="transition: transform 0.3s ease;"></i>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="crm-table">
                        <thead><tr><th>User</th><th>Leave Type</th><th>From</th><th>To</th><th>Reason</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($pendingLeaves as $l): ?>
                            <tr id="leave-<?= $l['id'] ?>">
                                <td class="text-bold"><?php 
                                    $fullName = trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? ''));
                                    echo htmlspecialchars($fullName ?: $l['username']);
                                ?></td>
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
                <div class="table-header" style="cursor:pointer;" onclick="togglePanel(this)">
                    <div class="table-title">Pending Permission Requests</div>
                    <div class="table-actions">
                        <i class="fa-solid fa-chevron-up transition-transform" style="transition: transform 0.3s ease;"></i>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="crm-table">
                        <thead><tr><th>User</th><th>Date</th><th>Window</th><th>Duration</th><th>Reason</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($pendingPerms as $p): ?>
                            <tr id="perm-<?= $p['id'] ?>">
                                <td class="text-bold"><?php 
                                    $fullName = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
                                    echo htmlspecialchars($fullName ?: $p['username']);
                                ?></td>
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
function togglePanel(headerElement) {
    const panel = headerElement.nextElementSibling;
    const icon = headerElement.querySelector('i');
    
    if (panel.style.display === 'none') {
        panel.style.display = '';
        icon.style.transform = 'rotate(0deg)';
    } else {
        panel.style.display = 'none';
        icon.style.transform = 'rotate(180deg)';
    }
}

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
