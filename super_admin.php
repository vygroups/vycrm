<?php
// super_admin.php - Multi-Tenant Platform Master Administration (White & Blue Modern Theme)
require_once 'config/database.php';
require_once 'includes/upload_paths.php';
require_once 'includes/dynamic_modules.php';

$db = Database::getMasterConn();
$prefix = Database::getMasterPrefix();
$message = "";
$messageType = "info";

// Ensure global settings table exists
$db->exec("CREATE TABLE IF NOT EXISTS `{$prefix}global_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) UNIQUE NOT NULL,
    `setting_value` TEXT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // 1. ADD / ONBOARD COMPANY
    if ($_POST['action'] === 'add_company') {
        $name = trim($_POST['name'] ?? '');
        $slug = upload_normalize_company_slug($_POST['slug'] ?? '');
        $new_db = trim($_POST['db_name'] ?? '');

        try {
            if (!$name || !$slug || !$new_db) {
                throw new Exception("Company Name, URL Slug, and Database Name are required.");
            }

            // Check duplicate slug
            $stmt = $db->prepare("SELECT id FROM {$prefix}companies WHERE slug = ?");
            $stmt->execute([$slug]);
            if ($stmt->fetch()) {
                throw new Exception("Company slug '/{$slug}' already exists. Please choose another slug.");
            }

            // Store branding assets
            $logo_path = null;
            if (isset($_FILES['logo']) && $_FILES['logo']['error'] === 0) {
                $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
                if ($ext !== '') {
                    $logicalDest = upload_company_file_path($slug, 'logo', $ext, 'branding');
                    $physicalDest = UPLOAD_BASE_DIR . $logicalDest;
                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $physicalDest)) {
                        $logo_path = $logicalDest;
                    }
                }
            }

            // CONNECT TENANT DB
            $tenantConn = Database::getTenantConn($new_db);
            if ($tenantConn) {
                if (file_exists('base_schema.sql')) {
                    $sql = file_get_contents('base_schema.sql');
                    $tenantConn->exec($sql);
                }

                // Create default admin
                $pass = password_hash('admin@123', PASSWORD_DEFAULT);
                $check = $tenantConn->prepare("SELECT id FROM users WHERE username='admin'");
                $check->execute();

                if (!$check->fetch()) {
                    $connRoles = $tenantConn->prepare("INSERT INTO roles (name) VALUES ('Administrator')");
                    $connRoles->execute();
                    $roleId = $tenantConn->lastInsertId();

                    $stmt = $tenantConn->prepare("
                        INSERT INTO users (username, password, email, role_id, is_admin)
                        VALUES ('admin', ?, 'admin@company.com', ?, 1)
                    ");
                    $stmt->execute([$pass, $roleId]);
                }

                // Create Default Calls Module
                dm_ensure_tables($tenantConn, '');
                $connModules = $tenantConn->prepare("INSERT INTO modules (name, slug, icon, description, sort_order) VALUES ('Calls Module', 'calls_module', 'fa-solid fa-phone-volume', 'Manage inbound and outbound calls', 1)");
                $connModules->execute();
                $moduleId = $tenantConn->lastInsertId();

                $connBlocks = $tenantConn->prepare("INSERT INTO module_blocks (module_id, name, sort_order) VALUES (?, 'Call Log Details', 0)");
                $connBlocks->execute([$moduleId]);
                $blockId = $tenantConn->lastInsertId();

                $fields = [
                    ['Assignee', 'assigned_to', 0, 0],
                    ['Caller Name', 'text', 1, 1],
                    ['From Number', 'phone', 2, 1],
                    ['To Number', 'phone', 3, 1],
                    ['Call Start', 'datetime', 4, 1],
                    ['Call End', 'datetime', 5, 1],
                    ['Duration', 'text', 6, 0],
                    ['Call Type', 'dropdown', 7, 1],
                    ['Notes or Comments', 'textarea', 8, 0],
                ];

                $stmtField = $tenantConn->prepare("INSERT INTO module_fields (block_id, module_id, field_key, label, field_type, is_required, is_list_visible, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                foreach ($fields as $f) {
                    $fieldKey = strtolower(str_replace(' ', '_', $f[0]));
                    $stmtField->execute([$blockId, $moduleId, $fieldKey, $f[0], $f[1], $f[3], 1, $f[2]]);
                    if ($f[1] === 'dropdown') {
                        $fieldId = $tenantConn->lastInsertId();
                        $stmtOpt = $tenantConn->prepare("INSERT INTO module_field_options (field_id, label, value, sort_order) VALUES (?, ?, ?, ?)");
                        $stmtOpt->execute([$fieldId, 'Incoming', 'Incoming', 0]);
                        $stmtOpt->execute([$fieldId, 'Outgoing', 'Outgoing', 1]);
                        $stmtOpt->execute([$fieldId, 'Missed', 'Missed', 2]);
                        $stmtOpt->execute([$fieldId, 'Rejected', 'Rejected', 3]);
                    }
                }

                // Insert system fields
                $sysFields = [
                    ['Created By', 'sys_created_by', 'created_by_sys', 1, 9],
                    ['Created On', 'sys_created_at', 'created_at_sys', 1, 10],
                    ['Updated By', 'sys_updated_by', 'updated_by_sys', 0, 11],
                    ['Updated On', 'sys_updated_at', 'updated_at_sys', 0, 12],
                ];
                $sfStmt = $tenantConn->prepare("INSERT INTO module_fields (block_id, module_id, label, field_type, field_key, is_list_visible, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
                foreach ($sysFields as $f) {
                    $sfStmt->execute([$blockId, $moduleId, $f[0], $f[1], $f[2], $f[3], $f[4]]);
                }

                // Save in master DB
                $stmt = $db->prepare("
                    INSERT INTO {$prefix}companies (name, slug, db_name, logo)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$name, $slug, $new_db, $logo_path]);

                $message = "Company '{$name}' onboarded and infrastructure provisioned successfully!";
                $messageType = "success";
            } else {
                throw new Exception("Cannot connect to Database '{$new_db}'. Make sure the database exists in MySQL and credentials match.");
            }
        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = "error";
        }
    }

    // 2. DELETE COMPANY
    if ($_POST['action'] === 'delete_company') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmt = $db->prepare("DELETE FROM {$prefix}companies WHERE id = ?");
            $stmt->execute([$id]);
            $message = "Company removed from platform directory.";
            $messageType = "success";
        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = "error";
        }
    }

    // 3. SAVE GLOBAL GOOGLE OAUTH
    if ($_POST['action'] === 'save_global_google_oauth') {
        $clientId = trim($_POST['google_drive_client_id'] ?? '');
        $clientSecret = trim($_POST['google_drive_client_secret'] ?? '');

        dm_set_global_setting('google_drive_client_id', $clientId);
        dm_set_global_setting('google_drive_client_secret', $clientSecret);

        $message = "Global Google Cloud OAuth App credentials saved! All tenant CRM accounts can now connect in 1 click.";
        $messageType = "success";
    }

    // 4. CLEAR GLOBAL GOOGLE OAUTH
    if ($_POST['action'] === 'clear_global_google_oauth') {
        dm_set_global_setting('google_drive_client_id', '');
        dm_set_global_setting('google_drive_client_secret', '');

        $message = "Global Google Cloud OAuth credentials cleared.";
        $messageType = "info";
    }
}

// Fetch Master Companies
$companies = $db->query("SELECT * FROM {$prefix}companies ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Global Settings
$globalClientId = (string)dm_get_global_setting('google_drive_client_id', '');
$globalClientSecret = (string)dm_get_global_setting('google_drive_client_secret', '');

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$redirectUri = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/google_oauth_callback.php';

$v = time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Platform Control — VY-AI CRM</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <script src="/assets/js/toast.js?v=<?= $v ?>"></script>
    <style>
        :root {
            --sa-bg: #f8fafc;
            --sa-surface: #ffffff;
            --sa-surface-muted: #f1f5f9;
            --sa-border: #e2e8f0;
            --sa-border-focus: #3b82f6;
            --sa-text: #0f172a;
            --sa-text-muted: #64748b;
            --sa-blue-primary: #2563eb;
            --sa-blue-hover: #1d4ed8;
            --sa-blue-light: #eff6ff;
            --sa-blue-border: #bfdbfe;
            --sa-success: #10b981;
            --sa-success-light: #ecfdf5;
            --sa-danger: #ef4444;
            --sa-danger-light: #fef2f2;
            --sa-card-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.05), 0 2px 6px -1px rgba(0, 0, 0, 0.03);
        }

        html, body {
            background-color: var(--sa-bg);
            color: var(--sa-text);
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            padding: 0;
            min-height: 100vh;
            overflow-y: auto !important;
        }

        .sa-header {
            background: #ffffff;
            border-bottom: 1px solid var(--sa-border);
            padding: 16px 36px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            flex-wrap: wrap;
            gap: 16px;
        }

        .sa-brand {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .sa-brand-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 20px;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
        }

        .sa-badge {
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 800;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .sa-nav-tabs {
            display: flex;
            gap: 6px;
            background: #f1f5f9;
            padding: 5px;
            border-radius: 12px;
            border: 1px solid var(--sa-border);
        }

        .sa-tab-btn {
            background: transparent;
            border: none;
            color: var(--sa-text-muted);
            padding: 9px 20px;
            border-radius: 9px;
            font-size: 13.5px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }

        .sa-tab-btn:hover {
            color: var(--sa-text);
            background: rgba(255, 255, 255, 0.6);
        }

        .sa-tab-btn.active {
            background: #2563eb;
            color: #ffffff;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.3);
        }

        .sa-container {
            max-width: 1240px;
            margin: 0 auto;
            padding: 32px 24px 60px;
        }

        .sa-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 20px;
            margin-bottom: 28px;
        }

        .sa-stat-card {
            background: #ffffff;
            border: 1px solid var(--sa-border);
            border-radius: 16px;
            padding: 22px;
            box-shadow: var(--sa-card-shadow);
            position: relative;
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .sa-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.07);
        }

        .sa-stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: #2563eb;
        }

        .sa-stat-label {
            font-size: 12.5px;
            color: var(--sa-text-muted);
            font-weight: 700;
            letter-spacing: 0.4px;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .sa-stat-val {
            font-size: 32px;
            font-weight: 800;
            color: var(--sa-text);
            margin: 8px 0 4px;
        }

        .sa-panel {
            background: #ffffff;
            border: 1px solid var(--sa-border);
            border-radius: 18px;
            padding: 28px;
            margin-bottom: 28px;
            box-shadow: var(--sa-card-shadow);
        }

        .sa-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .sa-panel-title {
            font-size: 19px;
            font-weight: 800;
            color: var(--sa-text);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sa-table {
            width: 100%;
            border-collapse: collapse;
        }

        .sa-table th {
            background: #f8fafc;
            color: #475569;
            font-size: 12px;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.5px;
            padding: 14px 18px;
            border-bottom: 1.5px solid var(--sa-border);
            text-align: left;
        }

        .sa-table td {
            padding: 15px 18px;
            border-bottom: 1px solid var(--sa-border);
            font-size: 13.5px;
            vertical-align: middle;
            color: var(--sa-text);
        }

        .sa-table tr:hover td {
            background: #f8fafc;
        }

        .sa-input {
            width: 100%;
            background: #ffffff;
            border: 1.5px solid var(--sa-border);
            border-radius: 10px;
            padding: 11px 16px;
            color: var(--sa-text);
            font-size: 13.5px;
            box-sizing: border-box;
            outline: none;
            transition: all 0.2s ease;
        }

        .sa-input:focus {
            border-color: var(--sa-border-focus);
            box-shadow: 0 0 0 3.5px rgba(59, 130, 246, 0.15);
        }

        .sa-btn-primary {
            background: #2563eb;
            color: #ffffff;
            border: none;
            padding: 11px 22px;
            border-radius: 10px;
            font-size: 13.5px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 6px rgba(37, 99, 235, 0.2);
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .sa-btn-primary:hover {
            background: #1d4ed8;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.35);
            transform: translateY(-1px);
        }

        .sa-btn-secondary {
            background: #ffffff;
            color: #334155;
            border: 1.5px solid var(--sa-border);
            padding: 10px 18px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .sa-btn-secondary:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }

        .sa-alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sa-alert-success {
            background: var(--sa-success-light);
            border: 1px solid #a7f3d0;
            color: #065f46;
        }

        .sa-alert-error {
            background: var(--sa-danger-light);
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        .sa-modal {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 20px;
        }

        .sa-modal.show {
            display: flex;
        }

        .sa-modal-content {
            background: #ffffff;
            border-radius: 20px;
            width: 100%;
            max-width: 520px;
            max-height: 90vh;
            overflow-y: auto;
            padding: 30px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.18);
        }

        .company-logo-circle {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            font-weight: 800;
            color: #2563eb;
            overflow: hidden;
        }

        .code-pill {
            background: #f1f5f9;
            color: #1e293b;
            padding: 4px 8px;
            border-radius: 6px;
            font-family: monospace;
            font-size: 12.5px;
            border: 1px solid #e2e8f0;
        }
    </style>
</head>
<body>

    <!-- Header -->
    <header class="sa-header">
        <div class="sa-brand">
            <div class="sa-brand-icon">
                <i class="fa-solid fa-layer-group"></i>
            </div>
            <div>
                <div style="font-size: 17px; font-weight: 800; color: #0f172a; letter-spacing: -0.3px;">VY-AI CRM Platform</div>
                <div style="font-size: 12px; color: var(--sa-text-muted);">Master Administration Control Hub</div>
            </div>
            <span class="sa-badge">Super Admin</span>
        </div>

        <!-- Navigation Tabs -->
        <div class="sa-nav-tabs">
            <button type="button" class="sa-tab-btn active" onclick="switchSaTab('tab_tenants')">
                <i class="fa-solid fa-building"></i> Tenants & Provisioning
            </button>
            <button type="button" class="sa-tab-btn" onclick="switchSaTab('tab_integrations')">
                <i class="fa-solid fa-cloud"></i> Global Google OAuth
            </button>
            <button type="button" class="sa-tab-btn" onclick="switchSaTab('tab_system')">
                <i class="fa-solid fa-server"></i> Master DB & Health
            </button>
        </div>

        <div style="display: flex; align-items: center; gap: 12px;">
            <div style="font-size: 12.5px; color: #059669; font-weight: 700; display: flex; align-items: center; gap: 6px; background: #ecfdf5; padding: 6px 12px; border-radius: 20px; border: 1px solid #a7f3d0;">
                <i class="fa-solid fa-circle" style="font-size: 8px;"></i> Master DB Online
            </div>
        </div>
    </header>

    <main class="sa-container">

        <!-- Alert Notification -->
        <?php if ($message): ?>
            <div class="sa-alert sa-alert-<?= $messageType === 'error' ? 'error' : 'success' ?>">
                <i class="fa-solid <?= $messageType === 'error' ? 'fa-triangle-exclamation' : 'fa-circle-check' ?>" style="font-size: 18px;"></i>
                <div><?= htmlspecialchars($message) ?></div>
            </div>
        <?php endif; ?>

        <!-- Stats Overview -->
        <div class="sa-stats-grid">
            <div class="sa-stat-card">
                <div class="sa-stat-label">
                    <span>Active Tenants</span>
                    <i class="fa-solid fa-building" style="color: #2563eb; font-size: 16px;"></i>
                </div>
                <div class="sa-stat-val"><?= count($companies) ?></div>
                <div style="font-size: 12.5px; color: #059669; font-weight: 600;"><i class="fa-solid fa-circle-check"></i> Isolated Databases Active</div>
            </div>

            <div class="sa-stat-card">
                <div class="sa-stat-label">
                    <span>Google Drive OAuth</span>
                    <i class="fa-brands fa-google-drive" style="color: #2563eb; font-size: 16px;"></i>
                </div>
                <div class="sa-stat-val" style="font-size: 22px; margin-top: 14px;">
                    <?php if ($globalClientId && $globalClientSecret): ?>
                        <span style="color: #059669;"><i class="fa-solid fa-circle-check"></i> Configured</span>
                    <?php else: ?>
                        <span style="color: #d97706; font-size: 18px;"><i class="fa-solid fa-triangle-exclamation"></i> Not Configured</span>
                    <?php endif; ?>
                </div>
                <div style="font-size: 12.5px; color: var(--sa-text-muted);">Platform-wide 1-click Sign In</div>
            </div>

            <div class="sa-stat-card">
                <div class="sa-stat-label">
                    <span>System Health</span>
                    <i class="fa-solid fa-heart-pulse" style="color: #ec4899; font-size: 16px;"></i>
                </div>
                <div class="sa-stat-val" style="color: #059669; font-size: 24px; margin-top: 14px;">
                    100% HEALTHY
                </div>
                <div style="font-size: 12.5px; color: var(--sa-text-muted);">PHP <?= phpversion() ?> · MySQL Live</div>
            </div>
        </div>

        <!-- TAB 1: TENANTS & PROVISIONING -->
        <div id="pane_tab_tenants">
            <div class="sa-panel">
                <div class="sa-panel-header">
                    <div>
                        <div class="sa-panel-title">
                            <i class="fa-solid fa-city" style="color: #2563eb;"></i> Registered Companies & Tenants
                        </div>
                        <div style="font-size: 13px; color: var(--sa-text-muted); margin-top: 4px;">
                            Manage multi-tenant instances, isolated databases, and company workspaces.
                        </div>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <input type="text" id="tenantSearchInput" class="sa-input" placeholder="Search company or slug..." oninput="filterTenants(this.value)" style="width: 240px; padding: 9px 14px; font-size: 13px;">
                        <button type="button" class="sa-btn-primary" onclick="openAddTenantModal()">
                            <i class="fa-solid fa-plus"></i> Onboard New Company
                        </button>
                    </div>
                </div>

                <div style="overflow-x: auto;">
                    <table class="sa-table" id="tenantsTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Logo</th>
                                <th>Company Name</th>
                                <th>Tenant URL</th>
                                <th>Database Name</th>
                                <th>Provisioned Date</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($companies)): ?>
                                <tr>
                                    <td colspan="7" style="text-align:center; padding: 40px; color: var(--sa-text-muted);">
                                        No companies registered yet. Click "Onboard New Company" to provision a tenant.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($companies as $c): ?>
                                    <tr class="tenant-row">
                                        <td style="color: var(--sa-text-muted); font-weight: 700;">#<?= $c['id'] ?></td>
                                        <td>
                                            <?php if (!empty($c['logo'])): ?>
                                                <img src="<?= UPLOAD_BASE_URL . urlencode(ltrim($c['logo'], '/')) ?>" style="width: 36px; height: 36px; border-radius: 8px; object-fit: cover; border: 1px solid var(--sa-border);">
                                            <?php else: ?>
                                                <div class="company-logo-circle">
                                                    <?= strtoupper(substr($c['name'] ?? 'C', 0, 1)) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong style="font-size: 14.5px; color: var(--sa-text);"><?= htmlspecialchars($c['name']) ?></strong>
                                        </td>
                                        <td>
                                            <a href="/<?= htmlspecialchars($c['slug']) ?>" target="_blank" style="color: #2563eb; text-decoration: none; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
                                                <span class="code-pill">/<?= htmlspecialchars($c['slug']) ?></span>
                                                <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 11px;"></i>
                                            </a>
                                        </td>
                                        <td>
                                            <span class="code-pill" style="color: #047857; font-weight: 600;">
                                                <?= htmlspecialchars($c['db_name']) ?>
                                            </span>
                                        </td>
                                        <td style="color: var(--sa-text-muted); font-size: 13px;">
                                            <?= !empty($c['created_at']) ? date('M d, Y', strtotime($c['created_at'])) : '—' ?>
                                        </td>
                                        <td style="text-align: right;">
                                            <div style="display: flex; gap: 8px; justify-content: flex-end;">
                                                <a href="/<?= htmlspecialchars($c['slug']) ?>" target="_blank" class="sa-btn-secondary" style="padding: 6px 12px; font-size: 12px;" title="Open Tenant Workspace">
                                                    <i class="fa-solid fa-arrow-right-to-bracket"></i> Launch
                                                </a>
                                                <form action="" method="POST" onsubmit="return confirm('Are you sure you want to remove <?= htmlspecialchars(addslashes($c['name'])) ?> from the platform directory? (Database will not be deleted)')" style="display:inline;">
                                                    <input type="hidden" name="action" value="delete_company">
                                                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                                    <button type="submit" class="sa-btn-secondary" style="padding: 6px 12px; font-size: 12px; color: #ef4444; border-color: rgba(239, 68, 68, 0.3);" title="Delete Company Entry">
                                                        <i class="fa-solid fa-trash-can"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB 2: GLOBAL GOOGLE OAUTH CONFIGURATION -->
        <div id="pane_tab_integrations" style="display:none;">
            <div class="sa-panel">
                <div class="sa-panel-header">
                    <div>
                        <div class="sa-panel-title">
                            <i class="fa-brands fa-google-drive" style="color: #2563eb;"></i> Global Google Cloud OAuth App
                        </div>
                        <div style="font-size: 13.5px; color: var(--sa-text-muted); margin-top: 4px;">
                            Configure Google OAuth credentials once here. All client companies and users will automatically enjoy 1-click Google Drive sign-in without creating their own Google Cloud projects.
                        </div>
                    </div>
                </div>

                <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 14px; padding: 20px; margin-bottom: 24px;">
                    <div style="font-weight: 800; font-size: 14px; color: #1e40af; margin-bottom: 6px;">
                        <i class="fa-solid fa-circle-info"></i> How Centralized Google OAuth Works:
                    </div>
                    <div style="font-size: 13px; color: #334155; line-height: 1.6;">
                        1. You create one Web OAuth Client in your Google Cloud Console (`console.cloud.google.com`).<br>
                        2. Save the Client ID and Secret below.<br>
                        3. When any client visits their CRM Call Storage settings, they simply click <strong>"Sign in with Google Drive"</strong> and choose their own Gmail account. All files upload securely to that client's respective Google Drive folder.
                    </div>
                </div>

                <form action="" method="POST">
                    <input type="hidden" name="action" value="save_global_google_oauth">

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 8px; color: var(--sa-text);">
                            Google Cloud OAuth Client ID:
                        </label>
                        <input type="text" name="google_drive_client_id" class="sa-input" placeholder="e.g. 368902196126-xxxx.apps.googleusercontent.com" value="<?= htmlspecialchars($globalClientId) ?>" required>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 8px; color: var(--sa-text);">
                            Google Cloud OAuth Client Secret:
                        </label>
                        <input type="password" name="google_drive_client_secret" class="sa-input" placeholder="••••••••••••••••••••••••" value="<?= htmlspecialchars($globalClientSecret) ?>" required>
                    </div>

                    <div style="margin-bottom: 24px;">
                        <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 8px; color: var(--sa-text);">
                            Authorized Redirect URI in Google Cloud Console:
                        </label>
                        <div style="display: flex; gap: 10px;">
                            <input type="text" class="sa-input" value="<?= htmlspecialchars($redirectUri) ?>" readonly id="saRedirectUriInput" style="background: #f8fafc; font-weight: 600; color: #2563eb;">
                            <button type="button" class="sa-btn-secondary" onclick="copySaRedirectUri()" style="white-space: nowrap;">
                                <i class="fa-solid fa-copy"></i> Copy URI
                            </button>
                        </div>
                        <div style="font-size: 12px; color: var(--sa-text-muted); margin-top: 6px;">
                            Add this exact URL under <strong>Google Cloud Console &rarr; Clients &rarr; Authorized redirect URIs</strong>.
                        </div>
                    </div>

                    <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                        <button type="submit" class="sa-btn-primary" style="padding: 12px 26px;">
                            <i class="fa-solid fa-floppy-disk"></i> Save Global Google OAuth Settings
                        </button>
                        <?php if ($globalClientId): ?>
                            <button type="button" class="sa-btn-secondary" onclick="document.getElementById('clearGoogleForm').submit();" style="color: #ef4444; border-color: rgba(239,68,68,0.3);">
                                <i class="fa-solid fa-trash-can"></i> Remove Credentials
                            </button>
                        <?php endif; ?>
                    </div>
                </form>

                <form id="clearGoogleForm" action="" method="POST" style="display:none;" onsubmit="return confirm('Are you sure you want to remove the global Google OAuth settings?')">
                    <input type="hidden" name="action" value="clear_global_google_oauth">
                </form>
            </div>
        </div>

        <!-- TAB 3: SYSTEM & DATABASE TOOLS -->
        <div id="pane_tab_system" style="display:none;">
            <div class="sa-panel">
                <div class="sa-panel-header">
                    <div>
                        <div class="sa-panel-title">
                            <i class="fa-solid fa-server" style="color: #2563eb;"></i> Master Database & Server Diagnostics
                        </div>
                        <div style="font-size: 13px; color: var(--sa-text-muted); margin-top: 4px;">
                            Master database connectivity and environment diagnostics.
                        </div>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 18px;">
                    <div style="background: #f8fafc; border: 1.5px solid var(--sa-border); border-radius: 14px; padding: 20px;">
                        <div style="font-size: 12px; color: var(--sa-text-muted); text-transform: uppercase; font-weight: 700;">PHP Runtime</div>
                        <div style="font-size: 17px; font-weight: 800; color: var(--sa-text); margin-top: 4px;"><?= phpversion() ?></div>
                    </div>

                    <div style="background: #f8fafc; border: 1.5px solid var(--sa-border); border-radius: 14px; padding: 20px;">
                        <div style="font-size: 12px; color: var(--sa-text-muted); text-transform: uppercase; font-weight: 700;">Master Database Host</div>
                        <div style="font-size: 17px; font-weight: 800; color: var(--sa-text); margin-top: 4px;">localhost (MySQL)</div>
                    </div>

                    <div style="background: #f8fafc; border: 1.5px solid var(--sa-border); border-radius: 14px; padding: 20px;">
                        <div style="font-size: 12px; color: var(--sa-text-muted); text-transform: uppercase; font-weight: 700;">Tenant Isolation Mode</div>
                        <div style="font-size: 17px; font-weight: 800; color: #059669; margin-top: 4px;">Database-Per-Tenant</div>
                    </div>

                    <div style="background: #f8fafc; border: 1.5px solid var(--sa-border); border-radius: 14px; padding: 20px;">
                        <div style="font-size: 12px; color: var(--sa-text-muted); text-transform: uppercase; font-weight: 700;">Upload Base Directory</div>
                        <div style="font-size: 13.5px; font-weight: 700; color: #2563eb; margin-top: 4px;"><?= htmlspecialchars(UPLOAD_BASE_DIR) ?></div>
                    </div>
                </div>
            </div>
        </div>

    </main>

    <!-- Modal: Onboard New Company -->
    <div class="sa-modal" id="addTenantModal">
        <div class="sa-modal-content">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 22px;">
                <div style="font-size: 18px; font-weight: 800; color: var(--sa-text); display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-circle-plus" style="color: #2563eb;"></i>
                    <span>Onboard New Company</span>
                </div>
                <button type="button" onclick="closeAddTenantModal()" style="background:transparent; border:none; color:var(--sa-text-muted); font-size:20px; cursor:pointer;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form action="" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_company">

                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: var(--sa-text);">Company Legal Name</label>
                    <input type="text" class="sa-input" name="name" required placeholder="e.g. Acorn Solutions Pvt Ltd">
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: var(--sa-text);">Tenant URL Slug</label>
                    <input type="text" class="sa-input" name="slug" required placeholder="e.g. acorn" oninput="this.value=this.value.toLowerCase().replace(/[^a-z0-9-]/g,'')">
                    <div style="font-size: 11.5px; color: var(--sa-text-muted); margin-top: 4px;">Tenant workspace URL: <code>vycrm.vygroups.com/your-slug</code></div>
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: var(--sa-text);">Database Name</label>
                    <input type="text" class="sa-input" name="db_name" required placeholder="e.g. u495954467_acorn">
                    <div style="font-size: 11.5px; color: var(--sa-text-muted); margin-top: 4px;">Must exist in MySQL with master credentials.</div>
                </div>

                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: var(--sa-text);">Company Logo (Optional)</label>
                    <input type="file" class="sa-input" name="logo" accept="image/*" style="padding: 8px;">
                </div>

                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="sa-btn-secondary" onclick="closeAddTenantModal()">Cancel</button>
                    <button type="submit" class="sa-btn-primary">
                        <i class="fa-solid fa-rocket"></i> Provision Infrastructure
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function switchSaTab(tabId) {
            document.querySelectorAll('.sa-tab-btn').forEach(b => b.classList.remove('active'));
            document.getElementById('pane_tab_tenants').style.display = 'none';
            document.getElementById('pane_tab_integrations').style.display = 'none';
            document.getElementById('pane_tab_system').style.display = 'none';

            const activePane = document.getElementById('pane_' + tabId);
            if (activePane) activePane.style.display = 'block';

            const btns = document.querySelectorAll('.sa-tab-btn');
            btns.forEach(b => {
                if (b.getAttribute('onclick').includes(tabId)) {
                    b.classList.add('active');
                }
            });
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function openAddTenantModal() {
            document.getElementById('addTenantModal').classList.add('show');
        }

        function closeAddTenantModal() {
            document.getElementById('addTenantModal').classList.remove('show');
        }

        function copySaRedirectUri() {
            const input = document.getElementById('saRedirectUriInput');
            input.select();
            navigator.clipboard.writeText(input.value);
            if (window.Toast) {
                Toast.show('Authorized Redirect URI copied to clipboard!', 'success');
            } else {
                alert('Redirect URI copied to clipboard!');
            }
        }

        function filterTenants(q) {
            const query = q.toLowerCase().trim();
            const rows = document.querySelectorAll('.tenant-row');
            rows.forEach(r => {
                const text = r.innerText.toLowerCase();
                r.style.display = text.includes(query) ? '' : 'none';
            });
        }
    </script>
</body>
</html>
