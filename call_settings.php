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

$isGoogleConnected = !empty($cfgData['access_token']) || !empty($cfgData['refresh_token']);
$googleEmail = $cfgData['account_email'] ?? '';
$googleName = $cfgData['account_name'] ?? '';
$googlePicture = $cfgData['account_picture'] ?? '';
$currentFolderId = $cfgData['folder_id'] ?? '';
$currentFolderName = $cfgData['folder_name'] ?? ($currentFolderId ? 'Selected Folder' : 'Not Selected');

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$redirectUri = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/google_oauth_callback.php';

$globalClientId = (string)dm_get_global_setting('google_drive_client_id', '');
$hasGlobalGoogleConfig = !empty($globalClientId);
$allowBulkImport = (bool)dm_get_system_setting($conn, $prefix, 'calls_allow_bulk_import', '1');
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
        .google-connect-box {
            border: 1.5px solid rgba(66, 133, 244, 0.2);
            background: linear-gradient(135deg, rgba(66, 133, 244, 0.04) 0%, rgba(99, 102, 241, 0.04) 100%);
            border-radius: 16px;
            padding: 22px;
            margin-bottom: 20px;
        }
        .btn-google {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            background: #ffffff;
            color: #374151;
            border: 1.5px solid #d1d5db;
            border-radius: 12px;
            padding: 12px 24px;
            font-size: 14.5px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
            transition: all 0.2s;
            text-decoration: none;
        }
        .btn-google:hover {
            background: #f9fafb;
            border-color: #9ca3af;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .folder-picker-modal {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }
        .folder-picker-modal.show {
            display: flex;
        }
        .folder-dialog {
            background: var(--surface, #fff);
            border: 1.5px solid var(--border);
            border-radius: 20px;
            width: 90%;
            max-width: 580px;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
        }
        .folder-list-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            cursor: pointer;
            transition: background 0.15s;
        }
        .folder-list-item:hover {
            background: var(--surface-muted, #f8fafc);
        }
        .sys-toggle-pill {
            width: 38px;
            height: 20px;
            background: #cbd5e1;
            border-radius: 20px;
            position: relative;
            transition: background 0.2s;
            display: inline-block;
        }
        .sys-toggle-pill::after {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            background: #fff;
            border-radius: 50%;
            top: 2px;
            left: 2px;
            transition: transform 0.2s;
        }
        .sys-toggle-pill.active {
            background: #10b981;
        }
        .sys-toggle-pill.active::after {
            transform: translateX(18px);
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
                    <p style="font-size: 13.5px; color: var(--text-muted); margin: 0;">Connect your cloud storage so mobile call recordings automatically upload and remain available indefinitely.</p>
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
                                <span>AWS S3</span>
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

                        <!-- Global Sync Guardrail -->
                        <div class="storage-nav" style="margin-top: 16px; border-color: rgba(99, 102, 241, 0.2); background: rgba(99, 102, 241, 0.03);">
                            <div style="padding: 14px 16px;">
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
                                    Enable for bulk device history imports. Turn OFF to restrict to new real-time syncs only.
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Config Forms -->
                    <div>
                        <!-- 1. Google Drive Form (1-Click Sign-in + Visual Folder Browser) -->
                        <div class="storage-card" id="pane_google_drive" style="<?= $provider === 'google_drive' ? '' : 'display:none;' ?>">
                            <div class="storage-title">
                                <i class="fa-brands fa-google-drive" style="color: #4285f4;"></i> Google Drive Storage
                            </div>
                            <div class="storage-desc">
                                Automatically upload voice recordings to your Google Drive. Includes permanent offline refresh so you never have to log in repeatedly.
                            </div>

                                <!-- CONNECTED STATE CONTAINER -->
                                <div class="google-connect-box" id="gd_connected_box" style="<?= $isGoogleConnected ? 'border-color: rgba(16, 185, 129, 0.3); background: rgba(16, 185, 129, 0.04);' : 'display:none;' ?>">
                                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px; margin-bottom: 16px;">
                                        <div style="display: flex; align-items: center; gap: 12px;">
                                            <?php if ($googlePicture): ?>
                                                <img src="<?= htmlspecialchars($googlePicture) ?>" style="width: 44px; height: 44px; border-radius: 50%; border: 2px solid #10b981;">
                                            <?php else: ?>
                                                <div style="width: 44px; height: 44px; border-radius: 50%; background: #10b981; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                                                    <i class="fa-brands fa-google"></i>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <div style="font-size: 14.5px; font-weight: 800; color: var(--text);">
                                                    <?= htmlspecialchars($googleName ?: 'Connected Google Account') ?>
                                                    <span style="font-size: 11px; background: #10b981; color:#fff; padding: 2px 8px; border-radius: 6px; margin-left: 6px;">CONNECTED</span>
                                                </div>
                                                <div style="font-size: 12.5px; color: var(--text-muted);">
                                                    <?= htmlspecialchars($googleEmail) ?>
                                                </div>
                                            </div>
                                        </div>

                                        <button type="button" class="btn-secondary" onclick="disconnectGoogleDrive()" style="color: #ef4444; border-color: rgba(239, 68, 68, 0.3); padding: 8px 14px; font-size: 12.5px;">
                                            <i class="fa-solid fa-link-slash"></i> Disconnect Account
                                        </button>
                                    </div>

                                    <div style="display: flex; align-items: center; gap: 8px; font-size: 12px; color: #059669; font-weight: 600; margin-bottom: 18px;">
                                        <i class="fa-solid fa-circle-check"></i> Permanent Offline Access Active — Tokens auto-refresh in the background without logout.
                                    </div>

                                    <div style="background: var(--surface, #fff); border: 1.5px solid var(--border); border-radius: 12px; padding: 16px;">
                                        <label style="font-size: 12.5px; font-weight: 700; color: var(--text); display: block; margin-bottom: 8px;">
                                            Target Upload Folder in Google Drive:
                                        </label>
                                        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <i class="fa-solid fa-folder-open" style="color: #f59e0b; font-size: 22px;"></i>
                                                <div>
                                                    <strong style="font-size: 14px; color: var(--text);" id="displayFolderName"><?= htmlspecialchars($currentFolderName) ?></strong>
                                                    <div style="font-size: 11px; color: var(--text-muted);" id="displayFolderId">Folder ID: <?= htmlspecialchars($currentFolderId ?: 'None (Root Drive)') ?></div>
                                                </div>
                                            </div>
                                            <div style="display: flex; gap: 8px;">
                                                <button type="button" class="btn-primary" onclick="openFolderNavigator()" style="padding: 8px 16px; border-radius: 10px; font-size: 13px;">
                                                    <i class="fa-solid fa-folder-tree"></i> Browse & Select Folder
                                                </button>
                                                <?php if (!$currentFolderId): ?>
                                                    <button type="button" class="btn-secondary" onclick="autoCreateCallRecordingsFolder()" style="padding: 8px 14px; border-radius: 10px; font-size: 13px;">
                                                        <i class="fa-solid fa-plus"></i> Auto-Create "Call Recordings"
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- DISCONNECTED / 1-CLICK SIGN IN STATE CONTAINER -->
                                <div class="google-connect-box" id="gd_disconnected_box" style="<?= $isGoogleConnected ? 'display:none;' : '' ?>">
                                    <div style="text-align: center; padding: 16px 8px;">
                                        <img src="https://upload.wikimedia.org/wikipedia/commons/1/12/Google_Drive_icon_%282020%29.svg" style="width: 56px; height: 56px; margin-bottom: 12px;">
                                        <h4 style="font-size: 17px; font-weight: 800; color: var(--text); margin: 0 0 6px;">Connect your Google Drive</h4>
                                        <p style="font-size: 13px; color: var(--text-muted); max-width: 440px; margin: 0 auto 20px;">
                                            1-Click sign-in to securely authorize VY-AI CRM to upload voice call recordings directly to your chosen Google Drive folder.
                                        </p>
                                        
                                        <button type="button" class="btn-google" onclick="startGoogleSignIn()">
                                            <img src="https://www.gstatic.com/firebasejs/ui/2.0.0/images/auth/google.svg" style="width: 20px; height: 20px;">
                                            <span>Sign in with Google Drive</span>
                                        </button>
                                    </div>
                                </div>

                            <!-- Google Cloud OAuth App Status / Override -->
                            <details style="border: 1px solid var(--border); border-radius: 12px; padding: 12px 16px; background: var(--surface-muted, #f8fafc);">
                                <summary style="font-size: 13px; font-weight: 700; color: var(--text); cursor: pointer; display: flex; align-items: center; justify-content: space-between;">
                                    <span>
                                        <i class="fa-solid fa-sliders" style="color: var(--primary); margin-right: 6px;"></i> 
                                        <?= $hasGlobalGoogleConfig ? 'Custom Google OAuth App Override (Optional)' : 'Google Cloud OAuth App Settings (Client ID & Secret)' ?>
                                    </span>
                                    <span style="font-size: 11px; color: var(--text-muted);"><?= $hasGlobalGoogleConfig ? 'Managed by Super Admin' : 'Click to expand' ?></span>
                                </summary>
                                <div style="margin-top: 16px;">
                                    <?php if ($hasGlobalGoogleConfig): ?>
                                        <div style="font-size: 12px; color: #059669; background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.2); border-radius: 8px; padding: 10px 14px; margin-bottom: 14px; line-height: 1.5;">
                                            <i class="fa-solid fa-circle-check"></i> <strong>Central Super Admin OAuth is Active.</strong> Regular users can click "Sign in with Google Drive" directly without entering credentials below.
                                        </div>
                                    <?php endif; ?>
                                    <div class="form-group">
                                        <label>Google Cloud OAuth Client ID (Optional Override)</label>
                                        <input type="text" id="gd_client_id" class="form-control-custom" placeholder="Leave empty to use Super Admin default" value="<?= htmlspecialchars($cfgData['client_id'] ?? '') ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Google Cloud OAuth Client Secret (Optional Override)</label>
                                        <input type="password" id="gd_client_secret" class="form-control-custom" placeholder="Leave empty to use Super Admin default" value="<?= htmlspecialchars($cfgData['client_secret'] ?? '') ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Authorized Redirect URI in Google Cloud Console:</label>
                                        <div style="display: flex; gap: 8px;">
                                            <input type="text" class="form-control-custom" value="<?= htmlspecialchars($redirectUri) ?>" readonly id="redirectUriInput" style="background: rgba(0,0,0,0.03);">
                                            <button type="button" class="btn-secondary" onclick="copyRedirectUri()" style="padding: 8px 14px; white-space: nowrap;">
                                                <i class="fa-solid fa-copy"></i> Copy URI
                                            </button>
                                        </div>
                                    </div>
                                    <div style="display: flex; gap: 10px; margin-top: 14px; flex-wrap: wrap;">
                                        <button type="button" class="btn-primary" onclick="saveGoogleCredentialsOnly()" style="padding: 9px 18px; font-size: 13px; border-radius: 8px;">
                                            <i class="fa-solid fa-floppy-disk"></i> Save Client Override
                                        </button>
                                        <button type="button" class="btn-secondary" onclick="clearGoogleCredentials()" style="padding: 9px 16px; font-size: 13px; border-radius: 8px; color: #ef4444; border-color: rgba(239, 68, 68, 0.4);">
                                            <i class="fa-solid fa-trash-can"></i> Clear & Use Super Admin Default
                                        </button>
                                    </div>
                                </div>
                            </details>
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

    <!-- Interactive Google Drive Folder Picker Modal -->
    <div class="folder-picker-modal" id="googleFolderModal">
        <div class="folder-dialog">
            <div style="padding: 18px 22px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between;">
                <div style="font-size: 16px; font-weight: 800; color: var(--text); display: flex; align-items: center; gap: 8px;">
                    <i class="fa-brands fa-google-drive" style="color: #4285f4;"></i>
                    <span>Select Google Drive Folder</span>
                </div>
                <button type="button" class="btn-icon" onclick="closeFolderNavigator()" style="border:none; background:transparent; font-size:18px; cursor:pointer; color:var(--text-muted);">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <!-- Breadcrumbs & New Folder bar -->
            <div style="padding: 12px 20px; background: var(--surface-muted, #f8fafc); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                <div id="folderBreadcrumbs" style="font-size: 13px; font-weight: 600; color: var(--primary); display: flex; align-items: center; gap: 6px;">
                    <span onclick="navigateToFolder('root', 'My Drive')" style="cursor:pointer;"><i class="fa-solid fa-house"></i> My Drive</span>
                </div>
                <div style="display: flex; gap: 6px;">
                    <button type="button" class="btn-secondary" onclick="toggleNewFolderInput()" style="padding: 6px 12px; font-size: 12px; border-radius: 8px;">
                        <i class="fa-solid fa-folder-plus"></i> New Folder
                    </button>
                </div>
            </div>

            <!-- Inline New Folder Creator -->
            <div id="newFolderContainer" style="display:none; padding: 10px 20px; background: #fff; border-bottom: 1px solid var(--border);">
                <div style="display: flex; gap: 8px;">
                    <input type="text" id="newFolderNameInput" class="form-control-custom" placeholder="Folder Name (e.g. Call Recordings)" style="height: 36px; padding: 4px 12px; font-size: 13px;">
                    <button type="button" class="btn-primary" onclick="submitCreateFolder()" style="padding: 6px 14px; font-size: 12.5px; border-radius: 8px; white-space: nowrap;">
                        Create & Select
                    </button>
                </div>
            </div>

            <!-- Folder List Body -->
            <div id="folderListContent" style="flex: 1; overflow-y: auto; max-height: 380px; min-height: 200px;">
                <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                    <i class="fa-solid fa-spinner fa-spin" style="font-size: 24px; color: var(--primary); margin-bottom: 10px;"></i>
                    <div>Loading Google Drive Folders...</div>
                </div>
            </div>

            <!-- Footer -->
            <div style="padding: 14px 20px; border-top: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; background: var(--surface);">
                <div style="font-size: 12px; color: var(--text-muted);">
                    Current: <strong id="currentSelectedFolderName" style="color: var(--text);">My Drive</strong>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button type="button" class="btn-secondary" onclick="closeFolderNavigator()" style="padding: 8px 16px; border-radius: 10px; font-size: 13px;">Cancel</button>
                    <button type="button" class="btn-primary" onclick="selectCurrentNavFolder()" style="padding: 8px 18px; border-radius: 10px; font-size: 13px;">
                        <i class="fa-solid fa-check"></i> Choose This Folder
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Initialize picker state before optional UI helpers (such as Toast). This
        // keeps the close/cancel controls functional even if an external script
        // fails to load while returning from Google OAuth.
        let folderHistory = [{ id: 'root', name: 'My Drive' }];
        let currentFolderNavId = 'root';
        let currentFolderNavName = 'My Drive';
        let folderListRequestId = 0;

        function encodeFolderActionValue(value) {
            return encodeURIComponent(String(value)).replace(/'/g, '%27');
        }

        // Check URL parameters for status
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('connected') === '1') {
            if (window.Toast && typeof Toast.show === 'function') {
                Toast.show('Google Drive connected successfully with permanent offline access!', 'success');
            }
            window.history.replaceState({}, document.title, window.location.pathname);
        } else if (urlParams.get('error')) {
            if (window.Toast && typeof Toast.show === 'function') {
                Toast.show(decodeURIComponent(urlParams.get('error')), 'error');
            }
            window.history.replaceState({}, document.title, window.location.pathname);
        }

        function switchTab(p) {
            document.querySelectorAll('.storage-nav-item').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.storage-card').forEach(el => el.style.display = 'none');
            
            const card = document.getElementById('pane_' + p);
            if (card) card.style.display = 'block';

            const navs = document.querySelectorAll('.storage-nav-item');
            navs.forEach(nav => {
                if (nav.innerText.toLowerCase().includes(p.replace('_', ' '))) {
                    nav.classList.add('active');
                }
            });
        }

        function copyRedirectUri() {
            const input = document.getElementById('redirectUriInput');
            input.select();
            navigator.clipboard.writeText(input.value);
            Toast.show('Redirect URI copied to clipboard!', 'success');
        }

        async function saveGoogleCredentialsOnly() {
            const clientId = document.getElementById('gd_client_id').value.trim();
            const clientSecret = document.getElementById('gd_client_secret').value.trim();

            try {
                const res = await fetch('/api/calls_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'save_google_credentials',
                        client_id: clientId,
                        client_secret: clientSecret
                    })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Failed to save credentials');
                Toast.show(data.message || 'Google OAuth Client credentials saved successfully!', 'success');
                if (!clientId && !clientSecret) {
                    setTimeout(() => location.reload(), 600);
                }
            } catch (err) {
                Toast.show(err.message, 'error');
            }
        }

        async function clearGoogleCredentials() {
            if (!confirm('Are you sure you want to remove and clear your Google Client ID and Secret?')) return;
            document.getElementById('gd_client_id').value = '';
            document.getElementById('gd_client_secret').value = '';

            try {
                const res = await fetch('/api/calls_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'save_google_credentials',
                        client_id: '',
                        client_secret: ''
                    })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Failed to clear credentials');
                Toast.show('Google Client credentials removed successfully!', 'success');
                setTimeout(() => location.reload(), 600);
            } catch (err) {
                Toast.show(err.message, 'error');
            }
        }

        async function startGoogleSignIn() {
            const clientId = document.getElementById('gd_client_id')?.value.trim() || '';
            const clientSecret = document.getElementById('gd_client_secret')?.value.trim() || '';

            try {
                const res = await fetch('/api/calls_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'google_drive_get_auth_url',
                        client_id: clientId,
                        client_secret: clientSecret
                    })
                });
                const data = await res.json();
                if (!data.success || !data.auth_url) {
                    if (data.error && data.error.includes('Super Admin')) {
                        const details = document.querySelector('details');
                        if (details) details.open = true;
                    }
                    throw new Error(data.error || 'Failed to initialize Google Sign-in');
                }
                window.location.href = data.auth_url;
            } catch (err) {
                Toast.show(err.message, 'error');
            }
        }

        async function disconnectGoogleDrive() {
            if (!confirm('Are you sure you want to disconnect your Google Drive account?')) return;

            const btn = document.querySelector('button[onclick="disconnectGoogleDrive()"]');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Disconnecting...';
            }

            try {
                const res = await fetch('/api/calls_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'google_drive_disconnect' })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Failed to disconnect');

                // 1. Instant live DOM switch (0ms delay)
                const connectedBox = document.getElementById('gd_connected_box');
                const disconnectedBox = document.getElementById('gd_disconnected_box');
                if (connectedBox) connectedBox.style.display = 'none';
                if (disconnectedBox) disconnectedBox.style.display = 'block';

                Toast.show('Google Drive disconnected successfully', 'success');
            } catch (err) {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-link-slash"></i> Disconnect Account';
                }
                Toast.show(err.message, 'error');
            }
        }

        function openFolderNavigator() {
            document.getElementById('googleFolderModal').classList.add('show');
            folderHistory = [{ id: 'root', name: 'My Drive' }];
            navigateToFolder('root', 'My Drive');
        }

        function closeFolderNavigator() {
            // Invalidate any in-flight response so it cannot redraw a closed picker.
            folderListRequestId++;
            document.getElementById('googleFolderModal').classList.remove('show');
            document.getElementById('newFolderContainer').style.display = 'none';
        }

        function updateBreadcrumbs() {
            const container = document.getElementById('folderBreadcrumbs');
            container.innerHTML = folderHistory.map((f, idx) => {
                if (idx === folderHistory.length - 1) {
                    return `<span style="color:var(--text); font-weight:800;">${escapeHtml(f.name)}</span>`;
                }
                return `<span onclick="jumpToHistory(${idx})" style="cursor:pointer; color:var(--primary);">${escapeHtml(f.name)}</span> <span style="color:var(--text-muted);">&gt;</span>`;
            }).join(' ');

            document.getElementById('currentSelectedFolderName').textContent = currentFolderNavName;
        }

        function jumpToHistory(idx) {
            folderHistory = folderHistory.slice(0, idx + 1);
            const target = folderHistory[idx];
            navigateToFolder(target.id, target.name, false);
        }

        async function navigateToFolder(folderId, folderName, pushHistory = true) {
            const requestId = ++folderListRequestId;
            const modal = document.getElementById('googleFolderModal');
            currentFolderNavId = folderId;
            currentFolderNavName = folderName;
            if (pushHistory && (folderHistory.length === 0 || folderHistory[folderHistory.length - 1].id !== folderId)) {
                folderHistory.push({ id: folderId, name: folderName });
            }
            updateBreadcrumbs();

            const content = document.getElementById('folderListContent');
            content.innerHTML = `
                <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                    <i class="fa-solid fa-spinner fa-spin" style="font-size: 24px; color: var(--primary); margin-bottom: 10px;"></i>
                    <div>Loading folders inside "${escapeHtml(folderName)}"...</div>
                </div>
            `;

            // Do not depend on AbortController: older mobile browsers can leave the
            // loading state on screen forever when that API is unavailable.
            let timeoutId;
            const requestTimeout = new Promise((resolve, reject) => {
                timeoutId = setTimeout(() => reject(new Error('Google Drive took too long to respond. Please try again.')), 20000);
            });

            try {
                const res = await Promise.race([
                    fetch(`/api/calls_api.php?action=google_drive_list_folders&parent_id=${encodeURIComponent(folderId)}`, {
                        cache: 'no-store'
                    }),
                    requestTimeout
                ]);
                clearTimeout(timeoutId);
                if (requestId !== folderListRequestId || !modal.classList.contains('show')) return;
                const rawText = await res.text();
                let data;
                try {
                    data = JSON.parse(rawText);
                } catch (pe) {
                    throw new Error('Server response error: ' + rawText.replace(/<[^>]*>?/gm, ' ').trim().substring(0, 250));
                }
                if (!data.success) throw new Error(data.error || 'Failed to list folders');

                const folders = data.folders || [];
                if (folders.length === 0) {
                    content.innerHTML = `
                        <div style="text-align: center; padding: 36px 20px; color: var(--text-muted);">
                            <i class="fa-regular fa-folder-open" style="font-size: 36px; color: #cbd5e1; margin-bottom: 12px;"></i>
                            <div style="font-size: 14px; font-weight: 600; color: var(--text); margin-bottom: 4px;">No subfolders in "${escapeHtml(folderName)}"</div>
                            <div style="font-size: 12px; margin-bottom: 16px;">You can create a folder here or choose this directory.</div>
                            <div style="display: flex; gap: 8px; justify-content: center; flex-wrap: wrap;">
                                <button type="button" class="btn-secondary" onclick="toggleNewFolderInput()" style="padding: 7px 14px; font-size: 12px;">
                                    <i class="fa-solid fa-plus"></i> Create Folder
                                </button>
                                <button type="button" class="btn-primary" onclick="setTargetFolder(decodeURIComponent('${encodeFolderActionValue(folderId)}'), decodeURIComponent('${encodeFolderActionValue(folderName)}'))" style="padding: 7px 16px; font-size: 12px;">
                                    <i class="fa-solid fa-check"></i> Choose "${escapeHtml(folderName)}"
                                </button>
                            </div>
                        </div>
                    `;
                    return;
                }

                content.innerHTML = folders.map(f => {
                    const actionId = encodeFolderActionValue(f.id);
                    const actionName = encodeFolderActionValue(f.name);
                    return `
                    <div class="folder-list-item">
                        <div style="display:flex; align-items:center; gap:12px; cursor:pointer; flex:1;" onclick="navigateToFolder(decodeURIComponent('${actionId}'), decodeURIComponent('${actionName}'))">
                            <i class="fa-solid fa-folder" style="color: #f59e0b; font-size: 20px;"></i>
                            <div>
                                <strong style="font-size: 13.5px; color: var(--text);">${escapeHtml(f.name)}</strong>
                            </div>
                        </div>
                        <div style="display:flex; gap:6px;">
                            <button type="button" class="btn-secondary" onclick="navigateToFolder(decodeURIComponent('${actionId}'), decodeURIComponent('${actionName}'))" style="padding: 6px 10px; font-size: 12px;" title="Open Folder">
                                <i class="fa-solid fa-folder-open"></i> Open
                            </button>
                            <button type="button" class="btn-primary" onclick="setTargetFolder(decodeURIComponent('${actionId}'), decodeURIComponent('${actionName}'))" style="padding: 6px 12px; font-size: 12px;">
                                <i class="fa-solid fa-check"></i> Select
                            </button>
                        </div>
                    </div>
                `;
                }).join('');
            } catch (err) {
                clearTimeout(timeoutId);
                if (requestId !== folderListRequestId || !modal.classList.contains('show')) return;
                content.innerHTML = `
                    <div style="padding: 30px 20px; text-align: center;">
                        <i class="fa-solid fa-triangle-exclamation" style="font-size: 32px; color: #ef4444; margin-bottom: 12px;"></i>
                        <div style="font-size: 14px; font-weight: 700; color: #ef4444; margin-bottom: 8px;">${escapeHtml(err.message)}</div>
                        <div style="font-size: 12px; color: var(--text-muted); max-width: 440px; margin: 0 auto 16px;">
                            Make sure <strong>Google Drive API</strong> is enabled in your Google Cloud Console for project "vycrm".
                        </div>
                        <div style="display: flex; gap: 8px; justify-content: center; flex-wrap: wrap;">
                            <a href="https://console.cloud.google.com/apis/library/drive.googleapis.com" target="_blank" class="btn-secondary" style="padding: 6px 14px; font-size: 12px; text-decoration: none;">
                                <i class="fa-solid fa-arrow-up-right-from-square"></i> Enable Drive API in Google Cloud
                            </a>
                            <button type="button" class="btn-secondary" onclick="navigateToFolder(decodeURIComponent('${encodeFolderActionValue(folderId)}'), decodeURIComponent('${encodeFolderActionValue(folderName)}'))" style="padding: 6px 14px; font-size: 12px;">
                                <i class="fa-solid fa-rotate-right"></i> Retry
                            </button>
                            <button type="button" class="btn-primary" onclick="setTargetFolder('root', 'My Drive (Root)')" style="padding: 6px 14px; font-size: 12px;">
                                <i class="fa-solid fa-check"></i> Use Root Drive
                            </button>
                        </div>
                    </div>
                `;
            }
            finally {
                clearTimeout(timeoutId);
            }
        }

        function toggleNewFolderInput() {
            const container = document.getElementById('newFolderContainer');
            container.style.display = container.style.display === 'none' ? 'block' : 'none';
            if (container.style.display === 'block') {
                document.getElementById('newFolderNameInput').focus();
            }
        }

        async function submitCreateFolder() {
            const folderName = document.getElementById('newFolderNameInput').value.trim();
            if (!folderName) {
                Toast.show('Please enter a folder name', 'error');
                return;
            }

            try {
                const res = await fetch('/api/calls_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'google_drive_create_folder',
                        folder_name: folderName,
                        parent_id: currentFolderNavId
                    })
                });
                const data = await res.json();
                if (!data.success || !data.folder) throw new Error(data.error || 'Failed to create folder');

                Toast.show(`Folder "${folderName}" created and selected!`, 'success');
                await setTargetFolder(data.folder.id, data.folder.name);
            } catch (err) {
                Toast.show(err.message, 'error');
            }
        }

        async function autoCreateCallRecordingsFolder() {
            try {
                const res = await fetch('/api/calls_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'google_drive_create_folder',
                        folder_name: 'Call Recordings',
                        parent_id: 'root'
                    })
                });
                const data = await res.json();
                if (!data.success || !data.folder) throw new Error(data.error || 'Failed to create Call Recordings folder');

                await setTargetFolder(data.folder.id, data.folder.name);
            } catch (err) {
                Toast.show(err.message, 'error');
            }
        }

        function selectCurrentNavFolder() {
            setTargetFolder(currentFolderNavId, currentFolderNavName);
        }

        async function setTargetFolder(folderId, folderName) {
            try {
                const res = await fetch('/api/calls_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'google_drive_set_folder',
                        folder_id: folderId,
                        folder_name: folderName
                    })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Failed to set target folder');

                Toast.show(`Target folder set to "${folderName}"`, 'success');
                closeFolderNavigator();
                document.getElementById('displayFolderName').textContent = folderName;
                document.getElementById('displayFolderId').textContent = 'Folder ID: ' + (folderId === 'root' ? 'Root Drive' : folderId);
            } catch (err) {
                Toast.show(err.message, 'error');
            }
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        async function saveStorageConfig(e, provider) {
            e.preventDefault();
            let configData = {};
            let configName = '';

            if (provider === 's3') {
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
