<?php
require_once 'auth_check.php';
require_once 'config/database.php';
require_once 'includes/brand.php';
require_once 'includes/dynamic_modules.php';

// Fetch all users for this tenant
try {
    $dbName = $_SESSION['tenant_db'];
    $conn = Database::getTenantConn($dbName);
    $prefix = $_SESSION['tenant_prefix'];
    dm_ensure_tables($conn, $prefix);
    
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
    <title><?= htmlspecialchars(brand_page_title('User Management')) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link rel="shortcut icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <style>
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal-content { background: white; padding: 30px; border-radius: 20px; width: 100%; max-width: 500px; box-shadow: var(--shadow-lg); max-height: 90vh; overflow-y: auto; }
        .role-badge { padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; text-transform: uppercase; background: rgba(123, 94, 240, 0.1); color: var(--primary); border: 1px solid rgba(123, 94, 240, 0.2); }
        .status-pill {
            display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; cursor: pointer; transition: all 0.2s ease; border: none;
        }
        .status-pill.active { background: #dcfce7; color: #15803d; }
        .status-pill.active:hover { background: #bbf7d0; }
        .status-pill.inactive { background: #fee2e2; color: #b91c1c; }
        .status-pill.inactive:hover { background: #fecaca; }
        
        .tfa-pill {
            display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; cursor: pointer; transition: all 0.2s ease; border: 1px solid transparent;
        }
        .tfa-pill.enabled { background: #f3e8ff; color: #7e22ce; border-color: rgba(126,34,206,0.2); }
        .tfa-pill.enabled:hover { background: #e9d5ff; }
        .tfa-pill.disabled { background: #f1f5f9; color: #64748b; border-color: #cbd5e1; }
        .tfa-pill.disabled:hover { background: #e2e8f0; }

        #vyToastContainer { position:fixed; top:20px; right:20px; z-index:99999; display:flex; flex-direction:column; gap:10px; }
        .vy-toast { background:#fff; border-radius:10px; padding:14px 20px; min-width:280px; max-width:340px; font-size:14px; font-weight:600; color:#2b3674; box-shadow:0 8px 25px rgba(0,0,0,.12); display:flex; align-items:center; gap:10px; opacity:0; transform:translateX(30px); transition:all .35s cubic-bezier(.25,.8,.25,1); }
        .vy-toast.show { opacity:1; transform:translateX(0); }
    </style>
</head>
<body>
<div id="vyToastContainer"></div>
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>

    <main class="main-content">
        <header class="topbar">
            <div class="breadcrumb">Settings / <span class="current">User Management</span></div>
            <button class="btn-primary" style="width:auto; padding: 10px 20px;" onclick="openModal()">
                <i class="fa-solid fa-plus" style="margin-right:8px;"></i> Add New User
            </button>
        </header>

        <div class="content-scroll">
            <div class="table-panel">
                <div class="table-header">
                    <div class="table-title"><i class="fa-solid fa-users-gear" style="color:var(--primary); margin-right:8px;"></i> System Users</div>
                </div>
                <div class="table-responsive">
                    <table class="crm-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Two-Step Auth</th>
                                <th>Admin</th>
                                <th>Created At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): 
                                $userStatus = strtolower($u['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
                                $has2FA = !empty($u['two_factor_enabled']) && (int)$u['two_factor_enabled'] === 1;
                            ?>
                            <tr id="user-row-<?= $u['id'] ?>">
                                <td>#<?= $u['id'] ?></td>
                                <td class="text-bold"><?= htmlspecialchars(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))) ?: '-' ?></td>
                                <td><?= htmlspecialchars($u['username']) ?></td>
                                <td><?= htmlspecialchars($u['email']) ?></td>
                                <td>
                                    <span class="role-badge">
                                        <?= htmlspecialchars($u['role_name'] ?? 'No Role') ?>
                                    </span>
                                </td>
                                <td>
                                    <button type="button" class="status-pill <?= $userStatus ?>" 
                                            onclick="toggleUserStatus(<?= $u['id'] ?>, '<?= $userStatus ?>', this)" 
                                            title="Click to toggle Active/Inactive">
                                        <i class="fa-solid <?= $userStatus === 'active' ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i>
                                        <span><?= ucfirst($userStatus) ?></span>
                                    </button>
                                </td>
                                <td>
                                    <button type="button" class="tfa-pill <?= $has2FA ? 'enabled' : 'disabled' ?>" 
                                            onclick="toggleUser2FA(<?= $u['id'] ?>, <?= $has2FA ? 1 : 0 ?>, this)" 
                                            title="Click to toggle Two-Step Verification">
                                        <i class="fa-solid <?= $has2FA ? 'fa-shield-halved' : 'fa-shield' ?>"></i>
                                        <span><?= $has2FA ? 'Enabled' : 'Disabled' ?></span>
                                    </button>
                                </td>
                                <td>
                                    <?php if (!empty($u['is_admin'])): ?>
                                        <span class="badge" style="background:rgba(255,0,0,0.1);color:red;padding:4px 8px;border-radius:4px;font-size:11px;font-weight:700;">Yes</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#eee;color:#666;padding:4px 8px;border-radius:4px;font-size:11px;font-weight:600;">No</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
                                <td>
                                    <button class="btn-icon" title="Edit User" onclick='editUser(<?= json_encode($u) ?>)'>
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </button>
                                </td>
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
        <div class="flex justify-between items-center mb-4" style="display:flex; justify-content:space-between; align-items:center; border-bottom: 1px solid var(--border); padding-bottom: 12px;">
            <h3 id="modalTitle" style="margin:0; font-size:17px; font-weight:700;">Add Tenant User</h3>
            <button class="btn-icon" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="addUserForm">
            <input type="hidden" name="id" id="user_id" value="">
            <div style="display: flex; gap: 15px;">
                <div class="form-group" style="flex: 1;">
                    <label class="form-label">First Name</label>
                    <input type="text" class="form-control" name="first_name" required placeholder="e.g. John">
                </div>
                <div class="form-group" style="flex: 1;">
                    <label class="form-label">Last Name</label>
                    <input type="text" class="form-control" name="last_name" required placeholder="e.g. Doe">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Username</label>
                <input type="text" class="form-control" name="username" required placeholder="e.g. jdoe">
            </div>
            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" class="form-control" name="email" required placeholder="e.g. jane@company.com">
            </div>
            <div class="form-group">
                <label class="form-label" id="pwdLabel">Initial Password</label>
                <input type="password" class="form-control" name="password" id="user_password" placeholder="••••••••">
            </div>
            <div style="display: flex; gap: 15px;">
                <div class="form-group" style="flex: 1;">
                    <label class="form-label">Assign Role</label>
                    <select class="form-control" name="role_id">
                        <option value="">-- Select Role --</option>
                        <?php foreach ($allRoles as $role): ?>
                            <option value="<?= $role['id'] ?>"><?= htmlspecialchars($role['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="flex: 1;">
                    <label class="form-label">Account Status</label>
                    <select class="form-control" name="status" id="user_status">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="form-group" style="background:#f8fafc; padding:12px 14px; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:15px;">
                <label style="display:flex; align-items:center; gap:10px; cursor:pointer; margin:0;">
                    <input type="checkbox" name="two_factor_enabled" id="two_factor_enabled" value="1" style="width:18px;height:18px;accent-color:#7b5ef0;cursor:pointer;">
                    <div>
                        <div style="font-weight:700; font-size:13px; color:#1e1b4b;"><i class="fa-solid fa-shield-halved" style="color:#7b5ef0; margin-right:4px;"></i> Two-Step Verification (OTP)</div>
                        <div style="font-size:11px; color:#64748b;">Requires an email OTP verification code upon every login.</div>
                    </div>
                </label>
            </div>
            <div class="form-group" style="display:flex; align-items:center; gap:10px; margin-bottom:20px;">
                <input type="checkbox" name="is_admin" id="is_admin" value="1" style="width:18px;height:18px;accent-color:var(--primary);cursor:pointer;">
                <label for="is_admin" class="form-label" style="margin:0;cursor:pointer;">Admin Permission <small class="text-muted">(Grants access to settings & configuration)</small></label>
            </div>
            <button type="submit" class="btn-primary" id="saveUserBtn" style="width:100%; padding:12px;">SAVE USER ACCOUNT</button>
        </form>
    </div>
</div>

<script>
function vyToast(msg, type = 'success') {
    const colors = { success:'#10b981', error:'#ef4444', info:'#7b5ef0' };
    const icons = { success:'✅', error:'❌', info:'ℹ️' };
    const c = document.getElementById('vyToastContainer');
    const t = document.createElement('div');
    t.className = 'vy-toast';
    t.style.borderLeft = '4px solid ' + (colors[type] || colors.success);
    t.innerHTML = `<span>${icons[type] || '✅'}</span><span>${msg}</span>`;
    c.appendChild(t);
    requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('show')));
    setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 400); }, 3500);
}

function openModal() { 
    document.getElementById('userModal').style.display = 'flex'; 
    document.getElementById('modalTitle').innerText = 'Add Tenant User';
    document.getElementById('addUserForm').reset();
    document.getElementById('user_id').value = '';
    document.getElementById('user_password').required = true;
    document.getElementById('user_status').value = 'active';
    document.getElementById('two_factor_enabled').checked = false;
    document.getElementById('pwdLabel').innerText = 'Initial Password';
}

function closeModal() { 
    document.getElementById('userModal').style.display = 'none'; 
}

function editUser(user) {
    openModal();
    document.getElementById('modalTitle').innerText = 'Edit Tenant User';
    document.getElementById('user_id').value = user.id;
    document.querySelector('input[name="first_name"]').value = user.first_name || '';
    document.querySelector('input[name="last_name"]').value = user.last_name || '';
    document.querySelector('input[name="username"]').value = user.username || '';
    document.querySelector('input[name="email"]').value = user.email || '';
    document.querySelector('select[name="role_id"]').value = user.role_id || '';
    document.getElementById('user_status').value = (user.status && user.status.toLowerCase() === 'inactive') ? 'inactive' : 'active';
    document.getElementById('two_factor_enabled').checked = parseInt(user.two_factor_enabled, 10) === 1;
    document.getElementById('is_admin').checked = parseInt(user.is_admin, 10) === 1;
    
    document.getElementById('user_password').required = false;
    document.getElementById('pwdLabel').innerText = 'Password (leave blank to keep current)';
}

async function toggleUserStatus(userId, currentStatus, btn) {
    const newStatus = currentStatus === 'active' ? 'inactive' : 'active';
    btn.disabled = true;
    try {
        const formData = new FormData();
        formData.append('action', 'toggle_status');
        formData.append('id', userId);
        formData.append('status', newStatus);

        const res = await fetch('/api/save_user.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            btn.className = 'status-pill ' + newStatus;
            btn.innerHTML = `<i class="fa-solid ${newStatus === 'active' ? 'fa-circle-check' : 'fa-circle-xmark'}"></i> <span>${newStatus.charAt(0).toUpperCase() + newStatus.slice(1)}</span>`;
            btn.setAttribute('onclick', `toggleUserStatus(${userId}, '${newStatus}', this)`);
            vyToast(`User marked as ${newStatus.toUpperCase()}`, 'success');
        } else {
            vyToast(data.message || 'Failed to update status', 'error');
        }
    } catch (err) {
        vyToast('Network error occurred', 'error');
    } finally {
        btn.disabled = false;
    }
}

async function toggleUser2FA(userId, current2FA, btn) {
    const new2FA = current2FA === 1 ? 0 : 1;
    btn.disabled = true;
    try {
        const formData = new FormData();
        formData.append('action', 'toggle_2fa');
        formData.append('id', userId);
        formData.append('two_factor_enabled', new2FA);

        const res = await fetch('/api/save_user.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            const isEnabled = new2FA === 1;
            btn.className = 'tfa-pill ' + (isEnabled ? 'enabled' : 'disabled');
            btn.innerHTML = `<i class="fa-solid ${isEnabled ? 'fa-shield-halved' : 'fa-shield'}"></i> <span>${isEnabled ? 'Enabled' : 'Disabled'}</span>`;
            btn.setAttribute('onclick', `toggleUser2FA(${userId}, ${new2FA}, this)`);
            vyToast(`Two-Step Verification ${isEnabled ? 'ENABLED' : 'DISABLED'}`, 'info');
        } else {
            vyToast(data.message || 'Failed to update 2FA', 'error');
        }
    } catch (err) {
        vyToast('Network error occurred', 'error');
    } finally {
        btn.disabled = false;
    }
}

document.getElementById('addUserForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('saveUserBtn');
    btn.disabled = true;
    btn.innerText = 'SAVING...';
    const formData = new FormData(this);
    fetch('/api/save_user.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            vyToast(data.message || 'User saved successfully', 'success');
            setTimeout(() => location.reload(), 800);
        } else {
            vyToast(data.message, 'error');
            btn.disabled = false;
            btn.innerText = 'SAVE USER ACCOUNT';
        }
    })
    .catch(err => {
        vyToast('Network error occurred', 'error');
        btn.disabled = false;
        btn.innerText = 'SAVE USER ACCOUNT';
    });
});
</script>
</body>
</html>

