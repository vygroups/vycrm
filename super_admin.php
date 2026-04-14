<?php
require_once 'config/database.php';

$db = Database::getMasterConn();
$prefix = Database::getMasterPrefix();
$message = "";

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] == 'add_company') {

        $name = $_POST['name'];
        $slug = preg_replace('/[^a-z0-9-]/', '', strtolower($_POST['slug']));
        $new_db = trim($_POST['db_name']);

        try {

            // Check duplicate slug
            $stmt = $db->prepare("SELECT id FROM {$prefix}companies WHERE slug = ?");
            $stmt->execute([$slug]);

            if ($stmt->fetch()) {
                $message = "❌ Error: Company slug already exists.";
            }
            else {

                // 🔥 LOGO UPLOAD
                $logo_path = null;

                if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {

                    $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
                    $filename = $slug . "_logo." . $ext;

                    $upload_dir = "assets/uploads/logos/";

                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }

                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_dir . $filename)) {
                        $logo_path = $upload_dir . $filename;
                    }
                }

                // CONNECT TENANT DB
                $tenantConn = Database::getTenantConn($new_db);

                if ($tenantConn) {

                    // Run schema
                    $sql = file_get_contents('base_schema.sql');
                    $tenantConn->exec($sql);

                    // Create admin
                    $pass = password_hash('admin@123', PASSWORD_DEFAULT);

                    $check = $tenantConn->prepare("SELECT id FROM users WHERE username='admin'");
                    $check->execute();

                    if (!$check->fetch()) {
                        // 1. Create a default "Administrator" role
                        $connRoles = $tenantConn->prepare("INSERT INTO roles (name) VALUES ('Administrator')");
                        $connRoles->execute();
                        $roleId = $tenantConn->lastInsertId();

                        // 2. Insert the admin user with the role_id
                        $stmt = $tenantConn->prepare("
                            INSERT INTO users (username, password, email, role_id)
                            VALUES ('admin', ?, 'admin@company.com', ?)
                        ");
                        $stmt->execute([$pass, $roleId]);
                    }

                    // Save in master DB (WITH LOGO)
                    $stmt = $db->prepare("
                        INSERT INTO {$prefix}companies (name, slug, db_name, logo)
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([$name, $slug, $new_db, $logo_path]);

                    $message = "🚀 Company '$name' onboarded successfully!";
                }
                else {
                    $message = "❌ Cannot connect. Make sure:
                    1. DB exists
                    2. DB user = DB name
                    3. Password = Tn02aps2391*";
                }
            }

        }
        catch (Exception $e) {
            $message = "❌ FATAL: " . $e->getMessage();
        }
    }
}

// Fetch Companies
$companies = $db->query("SELECT * FROM {$prefix}companies ORDER BY created_at DESC")->fetchAll();
$v = time();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vy CRM - Super Admin</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="/assets/css/styles.css?v=<?= $v?>" rel="stylesheet">
    <style>
        .admin-nav {
            background: #1a1c23;
            color: white;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            padding: 40px;
            border-radius: 20px;
            width: 100%;
            max-width: 500px;
            box-shadow: var(--shadow-xl);
        }

        .logo-img {
            width: 40px;
            height: 40px;
            border-radius: 6px;
            object-fit: cover;
        }
    </style>
</head>

<body class="bg-light">

    <div class="admin-nav">
        <h2 style="margin:0; font-size: 20px;">
            <i class="fa-solid fa-gears" style="margin-right:10px;"></i> VY CRM SUPER ADMIN
        </h2>
        <div>Logged in as: <strong>Platform Owner</strong></div>
    </div>

    <main class="container py-5" style="max-width:1200px; margin: 0 auto; padding: 40px 20px;">

        <?php if ($message): ?>
        <div class="table-panel mb-4"
            style="background:#f0f7ff; border-left: 5px solid var(--primary); padding: 20px; font-weight: 600;">
            <?= $message?>
        </div>
        <?php
endif; ?>

        <div class="flex justify-between items-center mb-4">
            <h3 class="pipeline-header" style="margin:0;">PLATFORM OVERVIEW</h3>
            <button class="btn-primary" style="width:auto; padding: 12px 30px;"
                onclick="document.getElementById('addModal').style.display='flex'">
                <i class="fa-solid fa-plus" style="margin-right:8px;"></i> ONBOARD NEW COMPANY
            </button>
        </div>

        <div class="stats-grid">
            <div class="crm-card">
                <div class="card-title">TOTAL COMPANIES</div>
                <div class="card-value">
                    <?= count($companies)?>
                </div>
                <div class="card-desc">Active Tenants</div>
            </div>
            <div class="crm-card">
                <div class="card-title">MASTER DB STATUS</div>
                <div class="card-value text-success" style="font-size: 24px;">
                    HEALTHY <i class="fa-solid fa-circle-check"></i>
                </div>
                <div class="card-desc">Uptime 99.9%</div>
            </div>
        </div>

        <div class="table-panel">
            <div class="table-header">
                <div class="table-title">REGISTERED TENANTS</div>
            </div>

            <div class="table-responsive">
                <table class="crm-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Logo</th>
                            <th>Company Name</th>
                            <th>Slug</th>
                            <th>Database</th>
                            <th>Provisioned Date</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($companies as $c): ?>
                        <tr>
                            <td>#
                                <?= $c['id']?>
                            </td>

                            <td>
                                <?php if ($c['logo']): ?>
                                <img src="/<?= $c['logo']?>" class="logo-img">
                                <?php
    endif; ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($c['name'])?>
                            </td>
                            <td><code>/login/<?= $c['slug']?></code></td>
                            <td><code><?= $c['db_name']?></code></td>
                            <td>
                                <?= date('Y-m-d', strtotime($c['created_at']))?>
                            </td>
                        </tr>
                        <?php
endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- Modal -->
    <div class="modal" id="addModal">
        <div class="modal-content">

            <div class="flex justify-between items-center mb-4">
                <h3 style="margin:0;">Onboard New Tenant</h3>
                <button class="btn-icon" onclick="document.getElementById('addModal').style.display='none'">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form action="" method="POST" enctype="multipart/form-data">

                <input type="hidden" name="action" value="add_company">

                <div class="form-group">
                    <label class="form-label">Company Legal Name</label>
                    <input type="text" class="form-control" name="name" required placeholder="e.g. Prisoft Inc.">
                </div>

                <div class="form-group">
                    <label class="form-label">URL Slug</label>
                    <input type="text" class="form-control" name="slug" required placeholder="e.g. prisoft"
                        oninput="this.value=this.value.toLowerCase().replace(/[^a-z0-9-]/g,'')">
                </div>

                <div class="form-group">
                    <label class="form-label">Database Name</label>
                    <input type="text" class="form-control" name="db_name" required placeholder="e.g. vycrm_prisoft">
                </div>

                <div class="form-group">
                    <label class="form-label">Company Logo</label>
                    <input type="file" class="form-control" name="logo" accept="image/*">
                </div>

                <button type="submit" class="btn-primary w-full" style="margin-top:20px;">
                    PROVISION INFRASTRUCTURE
                </button>

            </form>
        </div>
    </div>

</body>

</html>