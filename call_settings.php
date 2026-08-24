<?php
// call_settings.php - Call Recording Storage & Mobile Sync Configuration
require_once 'auth_check.php';
require_once 'config/database.php';
require_once 'includes/commerce.php';
require_once 'includes/brand.php';
require_once 'includes/calls_helper.php';

$context = commerce_get_tenant_context();
$conn = $context['conn'];
$prefix = $context['prefix'];
$userId = $context['user_id'] ?? null;

calls_ensure_tables($conn, $prefix);

$currentStorage = calls_get_storage_config($conn, $prefix, $userId);
$provider = $currentStorage['provider'] ?? 'google_drive';
$cfgData = $currentStorage['config_data'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(brand_page_title('Call Recording & Storage Settings')) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link rel="shortcut icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <script src="/assets/js/toast.js?v=<?= $v ?>"></script>
    <style>
        .settings-grid {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 24px;
        }
        .storage-nav {
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: 18px;
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .storage-nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-radius: 12px;
            color: var(--text);
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .storage-nav-item:hover {
            background: var(--surface-muted, #f1f5f9);
        }
        .storage-nav-item.active {
            background: rgba(99, 102, 241, 0.12);
            color: #6366f1;
        }
        .storage-card {
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: 20px;
            padding: 26px;
            margin-bottom: 20px;
        }
        .storage-title {
            font-size: 18px;
            font-weight: 800;
            color: var(--text);
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .storage-desc {
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.5;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 6px;
            color: var(--text);
        }
        .form-control-custom {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            background: var(--surface);
            color: var(--text);
            font-size: 13.5px;
            box-sizing: border-box;
            outline: none;
            transition: border-color 0.2s;
        }
        .form-control-custom:focus {
            border-color: var(--primary);
        }
        .provider-badge-selected {
            background: #10b981;
            color: #fff;
            padding: 2px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            margin-left: auto;
        }
        @media (max-width: 900px) {
            .settings-grid {
                grid-template-columns: 1fr;
            }
        }
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
                    <div class="breadcrumb">Home / Communications / <a href="calls.php">Calls</a> / <span class="current">Storage & Sync Settings</span></div>
                </div>
                <div class="topbar-right">
                    <a href="calls.php" class="btn-secondary" style="padding: 10px 16px; border-radius: 12px; font-weight: 600; font-size: 13px;">
                        <i class="fa-solid fa-arrow-left"></i> Back to Calls
                    </a>
                    <?php include 'includes/profile_pill.php'; ?>
                </div>
            </header>

            <div class="content-scroll" style="padding: 24px;">
                <div style="margin-bottom: 24px;">
                    <h3 style="font-size: 22px; font-weight: 800; color: var(--text); margin: 0 0 4px;">Call Recordings Storage Provider</h3>
                    <p style="font-size: 13.5px; color: var(--text-muted); margin: 0;">Select and configure where incoming and outgoing call recording audio files will be stored.</p>
                </div>

                <div class="settings-grid">
                    <!-- Provider Nav -->
                    <div>
                        <div class="storage-nav">
                            <div class="storage-nav-item <?= $provider === 'google_drive' ? 'active' : '' ?>" onclick="switchTab('google_drive')">
                                <i class="fa-brands fa-google-drive" style="color: #4285f4; font-size: 16px;"></i>
                                <span>Google Drive</span>
                                <?php if ($provider === 'google_drive'): ?><span class="provider-badge-selected">ACTIVE</span><?php endif; ?>
                            </div>
                            <div class="storage-nav-item <?= $provider === 's3' ? 'active' : '' ?>" onclick="switchTab('s3')">
                                <i class="fa-solid fa-cloud" style="color: #f59e0b; font-size: 16px;"></i>
                                <span>AWS S3 Storage</span>
                                <?php if ($provider === 's3'): ?><span class="provider-badge-selected">ACTIVE</span><?php endif; ?>
                            </div>
                            <div class="storage-nav-item <?= $provider === 'cloudflare_r2' ? 'active' : '' ?>" onclick="switchTab('cloudflare_r2')">
                                <i class="fa-solid fa-bolt" style="color: #f97316; font-size: 16px;"></i>
                                <span>Cloudflare R2</span>
                                <?php if ($provider === 'cloudflare_r2'): ?><span class="provider-badge-selected">ACTIVE</span><?php endif; ?>
                            </div>
                            <div class="storage-nav-item <?= $provider === 'local' ? 'active' : '' ?>" onclick="switchTab('local')">
                                <i class="fa-solid fa-server" style="color: #10b981; font-size: 16px;"></i>
                                <span>Local CRM Server</span>
                                <?php if ($provider === 'local'): ?><span class="provider-badge-selected">ACTIVE</span><?php endif; ?>
                            </div>
                        </div>

                        <!-- Mobile Sync Architecture & Admin Controls -->
                        <?php
                        $allowBulkImport = dm_get_system_setting($conn, $prefix, 'calls_allow_bulk_import', '1') === '1';
                        ?>
                        <div style="background: var(--surface); border: 1.5px solid var(--border); border-radius: 18px; padding: 20px; margin-top: 18px; box-shadow: var(--shadow-sm);">
                            <div style="font-size: 14px; font-weight: 800; color: #4f46e5; margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                                <i class="fa-solid fa-mobile-screen-button"></i> Mobile Sync Architecture
                            </div>
                            
                            <!-- Default Standard Flow: Auto-Sync After Each Call -->
                            <div style="background: rgba(16, 185, 129, 0.08); border-left: 3px solid #10b981; border-radius: 10px; padding: 12px; margin-bottom: 14px;">
                                <div style="font-size: 13px; font-weight: 800; color: #065f46; margin-bottom: 4px; display: flex; align-items: center; gap: 6px;">
                                    <i class="fa-solid fa-circle-check"></i> Standard Mode: Auto-Sync After Each Call
                                </div>
                                <div style="font-size: 11.5px; color: #047857; line-height: 1.45;">
                                    Immediately after each call disconnects on mobile, the app automatically fetches call duration, caller number, contact info, and uploads the voice recording to CRM & cloud storage in real-time.
                                </div>
                            </div>

                            <!-- Onboarding / Bulk Import Admin Toggle -->
                            <div style="background: var(--surface-muted, #f8fafc); border: 1px solid var(--border); border-radius: 12px; padding: 14px;">
                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 6px;">
                                    <div style="font-size: 12.5px; font-weight: 700; color: var(--text);">
                                        <i class="fa-solid fa-shield-halved" style="color: #6366f1;"></i> Allow Bulk / History Import
                                    </div>
                                    <label style="display: flex; align-items: center; cursor: pointer;">
                                        <input type="checkbox" id="adminBulkImportToggle" <?= $allowBulkImport ? 'checked' : '' ?> onchange="toggleAdminBulkImport(this.checked)" style="display:none;">
                                        <span class="sys-toggle-pill <?= $allowBulkImport ? 'active' : '' ?>"></span>
                                    </label>
                                </div>
                                <div style="font-size: 11px; color: var(--text-muted); line-height: 1.4;">
                                    Enable only for <strong>new users</strong> who need to import past device call history into CRM. Turn OFF to prevent unwanted bulk dumps.
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Config Forms -->
                    <div>
                        <!-- 1. Google Drive Form -->
                        <div class="storage-card" id="pane_google_drive" style="<?= $provider === 'google_drive' ? '' : 'display:none;' ?>">
                            <div class="storage-title">
                                <i class="fa-brands fa-google-drive" style="color: #4285f4;"></i> Google Drive Storage
                            </div>
                            <div class="storage-desc">
                                Automatically upload voice recordings to your Google Drive folder. Team members can listen to recordings directly from CRM web.
                            </div>

                            <form onsubmit="saveStorageConfig(event, 'google_drive')">
                                <div class="form-group">
                                    <label>Google Cloud OAuth Client ID</label>
                                    <input type="text" id="gd_client_id" class="form-control-custom" placeholder="e.g. 123456789-abc.apps.googleusercontent.com" value="<?= htmlspecialchars($cfgData['client_id'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label>Google Cloud OAuth Client Secret</label>
                                    <input type="password" id="gd_client_secret" class="form-control-custom" placeholder="••••••••••••••••" value="<?= htmlspecialchars($cfgData['client_secret'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label>Target Google Drive Folder ID</label>
                                    <input type="text" id="gd_folder_id" class="form-control-custom" placeholder="e.g. 1B2M3N4O5P6Q7R8S9T0U (from Google Drive folder URL)" value="<?= htmlspecialchars($cfgData['folder_id'] ?? '') ?>">
                                    <small style="color: var(--text-muted); font-size: 11.5px; margin-top: 4px; display: block;">Open the folder in Google Drive and copy the ID from the end of the URL.</small>
                                </div>
                                <div class="form-group">
                                    <label>Refresh Token / Authorization Token</label>
                                    <textarea id="gd_refresh_token" class="form-control-custom" rows="2" placeholder="Google Drive Refresh Token"><?= htmlspecialchars($cfgData['refresh_token'] ?? '') ?></textarea>
                                </div>
                                <div style="display: flex; gap: 12px; margin-top: 24px;">
                                    <button type="submit" class="btn-primary" style="padding: 10px 22px; border-radius: 10px;">
                                        <i class="fa-solid fa-floppy-disk"></i> Save & Set as Active Storage
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- 2. AWS S3 Form -->
                        <div class="storage-card" id="pane_s3" style="<?= $provider === 's3' ? '' : 'display:none;' ?>">
                            <div class="storage-title">
                                <i class="fa-solid fa-cloud" style="color: #f59e0b;"></i> Amazon Web Services (AWS S3)
                            </div>
                            <div class="storage-desc">
                                Enterprise-grade object storage. Stores recordings securely in an AWS S3 bucket with instant streaming.
                            </div>

                            <form onsubmit="saveStorageConfig(event, 's3')">
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                                    <div class="form-group">
                                        <label>S3 Bucket Name</label>
                                        <input type="text" id="s3_bucket" class="form-control-custom" placeholder="e.g. my-crm-recordings" value="<?= htmlspecialchars($cfgData['bucket'] ?? '') ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>AWS Region</label>
                                        <input type="text" id="s3_region" class="form-control-custom" placeholder="e.g. ap-south-1" value="<?= htmlspecialchars($cfgData['region'] ?? 'ap-south-1') ?>">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>AWS Access Key ID</label>
                                    <input type="text" id="s3_access_key" class="form-control-custom" placeholder="AKIAIOSFODNN7EXAMPLE" value="<?= htmlspecialchars($cfgData['access_key'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label>AWS Secret Access Key</label>
                                    <input type="password" id="s3_secret_key" class="form-control-custom" placeholder="••••••••••••••••" value="<?= htmlspecialchars($cfgData['secret_key'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label>Custom CDN / CloudFront Domain (Optional)</label>
                                    <input type="text" id="s3_cdn_url" class="form-control-custom" placeholder="https://recordings.mycompany.com" value="<?= htmlspecialchars($cfgData['cdn_url'] ?? '') ?>">
                                </div>
                                <div style="display: flex; gap: 12px; margin-top: 24px;">
                                    <button type="submit" class="btn-primary" style="padding: 10px 22px; border-radius: 10px;">
                                        <i class="fa-solid fa-floppy-disk"></i> Save & Set as Active Storage
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- 3. Cloudflare R2 Form -->
                        <div class="storage-card" id="pane_cloudflare_r2" style="<?= $provider === 'cloudflare_r2' ? '' : 'display:none;' ?>">
                            <div class="storage-title">
                                <i class="fa-solid fa-bolt" style="color: #f97316;"></i> Cloudflare R2 Storage (Zero Egress Fees)
                            </div>
                            <div class="storage-desc">
                                High performance S3-compatible cloud storage with no egress bandwidth fees.
                            </div>

                            <form onsubmit="saveStorageConfig(event, 'cloudflare_r2')">
                                <div class="form-group">
                                    <label>Cloudflare Account ID</label>
                                    <input type="text" id="r2_account_id" class="form-control-custom" placeholder="e.g. 1a2b3c4d5e6f7a8b9c0d" value="<?= htmlspecialchars($cfgData['account_id'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label>R2 Bucket Name</label>
                                    <input type="text" id="r2_bucket" class="form-control-custom" placeholder="e.g. crm-voice-recordings" value="<?= htmlspecialchars($cfgData['bucket'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label>R2 Access Key ID</label>
                                    <input type="text" id="r2_access_key" class="form-control-custom" placeholder="R2 Access Key" value="<?= htmlspecialchars($cfgData['access_key'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label>R2 Secret Access Key</label>
                                    <input type="password" id="r2_secret_key" class="form-control-custom" placeholder="••••••••••••••••" value="<?= htmlspecialchars($cfgData['secret_key'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label>R2 Public Bucket URL / Custom Domain</label>
                                    <input type="text" id="r2_cdn_url" class="form-control-custom" placeholder="https://pub-xxxx.r2.dev or https://media.yourdomain.com" value="<?= htmlspecialchars($cfgData['cdn_url'] ?? '') ?>">
                                </div>
                                <div style="display: flex; gap: 12px; margin-top: 24px;">
                                    <button type="submit" class="btn-primary" style="padding: 10px 22px; border-radius: 10px;">
                                        <i class="fa-solid fa-floppy-disk"></i> Save & Set as Active Storage
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- 4. Local CRM Storage Form -->
                        <div class="storage-card" id="pane_local" style="<?= $provider === 'local' ? '' : 'display:none;' ?>">
                            <div class="storage-title">
                                <i class="fa-solid fa-server" style="color: #10b981;"></i> Local CRM Server Storage
                            </div>
                            <div class="storage-desc">
                                Saves recordings directly in your CRM web server's <code>uploads/recordings/</code> directory.
                            </div>

                            <form onsubmit="saveStorageConfig(event, 'local')">
                                <div class="form-group">
                                    <label>Storage Subdirectory</label>
                                    <input type="text" id="local_folder" class="form-control-custom" value="<?= htmlspecialchars($cfgData['folder'] ?? 'uploads/recordings/') ?>">
                                </div>
                                <div style="display: flex; gap: 12px; margin-top: 24px;">
                                    <button type="submit" class="btn-primary" style="padding: 10px 22px; border-radius: 10px;">
                                        <i class="fa-solid fa-floppy-disk"></i> Set as Active Storage
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        function switchTab(p) {
            document.querySelectorAll('.storage-nav-item').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.storage-card').forEach(el => el.style.display = 'none');
            
            const card = document.getElementById('pane_' + p);
            if (card) card.style.display = 'block';

            // Find matching nav
            const navs = document.querySelectorAll('.storage-nav-item');
            navs.forEach(nav => {
                if (nav.innerText.toLowerCase().includes(p.replace('_', ' '))) {
                    nav.classList.add('active');
                }
            });
        }

        async function saveStorageConfig(e, provider) {
            e.preventDefault();
            let configData = {};
            let configName = '';

            if (provider === 'google_drive') {
                configName = 'Google Drive Storage';
                configData = {
                    client_id: document.getElementById('gd_client_id').value.trim(),
                    client_secret: document.getElementById('gd_client_secret').value.trim(),
                    folder_id: document.getElementById('gd_folder_id').value.trim(),
                    refresh_token: document.getElementById('gd_refresh_token').value.trim()
                };
            } else if (provider === 's3') {
                configName = 'AWS S3 Storage';
                configData = {
                    bucket: document.getElementById('s3_bucket').value.trim(),
                    region: document.getElementById('s3_region').value.trim(),
                    access_key: document.getElementById('s3_access_key').value.trim(),
                    secret_key: document.getElementById('s3_secret_key').value.trim(),
                    cdn_url: document.getElementById('s3_cdn_url').value.trim()
                };
            } else if (provider === 'cloudflare_r2') {
                configName = 'Cloudflare R2 Storage';
                configData = {
                    account_id: document.getElementById('r2_account_id').value.trim(),
                    bucket: document.getElementById('r2_bucket').value.trim(),
                    access_key: document.getElementById('r2_access_key').value.trim(),
                    secret_key: document.getElementById('r2_secret_key').value.trim(),
                    cdn_url: document.getElementById('r2_cdn_url').value.trim()
                };
            } else if (provider === 'local') {
                configName = 'Local CRM Server Storage';
                configData = {
                    folder: document.getElementById('local_folder').value.trim() || 'uploads/recordings/'
                };
            }

            try {
                const res = await fetch('/api/calls_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'save_storage_config',
                        provider: provider,
                        config_name: configName,
                        config_data: configData,
                        is_default: 1,
                        is_active: 1
                    })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Failed to save configuration');
                Toast.show(`Storage configuration for ${configName} saved successfully!`, 'success');
                setTimeout(() => location.reload(), 1000);
            } catch (err) {
                Toast.show(err.message, 'error');
            }
        }

        async function toggleAdminBulkImport(enabled) {
            const pill = document.querySelector('#adminBulkImportToggle').parentElement.querySelector('.sys-toggle-pill');
            if (pill) pill.classList.toggle('active', enabled);

            try {
                const res = await fetch('/api/calls_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'save_admin_settings',
                        allow_bulk_import: enabled ? 1 : 0
                    })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Failed to update setting');
                Toast.show(enabled ? 'Bulk past call import enabled for new users' : 'Bulk import disabled. Real-time auto-sync is active.', 'success');
            } catch (err) {
                if (pill) pill.classList.toggle('active', !enabled);
                document.getElementById('adminBulkImportToggle').checked = !enabled;
                Toast.show(err.message, 'error');
            }
        }
    </script>
</body>
</html>
