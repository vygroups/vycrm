<?php
require_once 'auth_check.php';
require_once 'config/database.php';

// Fetch all users for this tenant
try {
    $dbName = $_SESSION['tenant_db'];
    $conn = Database::getTenantConn($dbName);
    $prefix = $_SESSION['tenant_prefix'];
    
    // Fetch Roles
    $stmtRoles = $conn->query("SELECT * FROM {$prefix}roles ORDER BY name ASC");
    $allRoles = $stmtRoles->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all users with their role names
    $stmt = $conn->query("
        SELECT u.*, r.name as role_name 
        FROM {$prefix}users u 
        LEFT JOIN {$prefix}roles r ON u.role_id = r.id 
        ORDER BY u.created_at DESC
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("Error fetching data: " . $e->getMessage());
}

$v = time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - <?= htmlspecialchars($_SESSION['tenant_slug']) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <style>
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal-content { background: white; padding: 30px; border-radius: 20px; width: 100%; max-width: 450px; box-shadow: var(--shadow-lg); }
        .role-badge { padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; text-transform: uppercase; background: rgba(123, 94, 240, 0.1); color: var(--primary); border: 1px solid rgba(123, 94, 240, 0.2); }
    </style>
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
            <a href="#" class="nav-item"><i class="fa-solid fa-ticket"></i><span class="nav-text">Tickets</span></a>
            <a href="#" class="nav-item"><i class="fa-solid fa-file-invoice"></i><span class="nav-text">Invoices</span></a>
            <a href="#" class="nav-item"><i class="fa-solid fa-boxes-stacked"></i><span class="nav-text">Products</span></a>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div class="breadcrumb">Settings / <span class="current">User Management</span></div>
            <button class="btn-primary" style="width:auto; padding: 10px 20px;" onclick="openModal()">
                <i class="fa-solid fa-plus" style="margin-right:8px;"></i> Add New User
            </button>
        </header>

        <div class="content-scroll">
            <div class="table-panel">
                <div class="table-header"><div class="table-title">System Users</div></div>
                <div class="table-responsive">
                    <table class="crm-table">
                        <thead>
                            <tr><th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>Created At</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                            <tr>
                                <td>#<?= $u['id'] ?></td>
                                <td class="text-bold"><?= htmlspecialchars($u['username']) ?></td>
                                <td><?= htmlspecialchars($u['email']) ?></td>
                                <td>
                                    <span class="role-badge">
                                        <?= htmlspecialchars($u['role_name'] ?? 'No Role') ?>
                                    </span>
                                </td>
                                <td><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
                                <td><button class="btn-icon" title="Edit"><i class="fa-solid fa-pen-to-square"></i></button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>

<div class="modal" id="userModal">
    <div class="modal-content">
        <div class="flex justify-between items-center mb-4">
            <h3 style="margin:0;">Add Tenant User</h3>
            <button class="btn-icon" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="addUserForm">
            <div class="form-group">
                <label class="form-label">Username</label>
                <input type="text" class="form-control" name="username" required placeholder="e.g. jdoe">
            </div>
            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" class="form-control" name="email" required placeholder="e.g. jane@company.com">
            </div>
            <div class="form-group">
                <label class="form-label">Initial Password</label>
                <input type="password" class="form-control" name="password" required placeholder="••••••••">
            </div>
            <div class="form-group">
                <label class="form-label">Assign Role</label>
                <select class="form-control" name="role_id">
                    <option value="">-- Select Role --</option>
                    <?php foreach ($allRoles as $role): ?>
                        <option value="<?= $role['id'] ?>"><?= htmlspecialchars($role['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p style="font-size:11px; color:var(--text-muted); margin-top:5px;">Manage roles in <a href="roles.php">Roles Configuration</a></p>
            </div>
            <button type="submit" class="btn-primary" style="margin-top:20px;">CREATE USER ACCOUNT</button>
        </form>
    </div>
</div>

<script>
function openModal() { document.getElementById('userModal').style.display = 'flex'; }
function closeModal() { document.getElementById('userModal').style.display = 'none'; }

document.getElementById('addUserForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    fetch('/api/create_user.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if(data.success) {
            location.reload();
        } else {
            alert(data.message);
        }
    });
});
</script>
</body>
</html>
