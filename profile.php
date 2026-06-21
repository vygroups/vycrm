<?php
require_once 'auth_check.php';
require_once 'includes/commerce.php';
require_once 'includes/brand.php';
require_once 'includes/upload_paths.php';
require_once 'includes/dynamic_modules.php';

$v = time();

$context = commerce_get_tenant_context();
$conn = $context['conn'];
$prefix = $context['prefix'];
commerce_ensure_tables($conn, $prefix);

// Company slug for folder separation
$slug = upload_normalize_company_slug($companySlug ?: 'default');

// Fetch existing profile
$stmt = $conn->query("SELECT * FROM {$prefix}business_profile WHERE id = 1");
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

// If no profile exists, pre-fill from master company data
if (!$profile) {
    $profile = [
        'business_name' => $companyData['name'] ?? '',
        'gstin' => '',
        'phone' => '',
        'email' => '',
        'address' => '',
        'business_type' => '',
        'business_category' => '',
        'logo_path' => $companyData['logo'] ?? '',
        'signature_path' => '',
        'bank_name' => '',
        'account_no' => '',
        'ifsc_code' => '',
        'terms' => '',
        'country' => 'IN',
        'currency_symbol' => '₹'
    ];
} else {
    if (empty($profile['country'])) {
        $profile['country'] = 'IN';
    }
    if (empty($profile['currency_symbol'])) {
        $profile['currency_symbol'] = '₹';
    }
    // If profile exists but logo_path is empty, fallback to master company logo
    if (empty($profile['logo_path']) && !empty($companyData['logo'])) {
        $profile['logo_path'] = $companyData['logo'];
    }
}

// Fetch integration credentials settings
$smtpHost = dm_get_system_setting($conn, $prefix, 'smtp_host', '');
$smtpPort = dm_get_system_setting($conn, $prefix, 'smtp_port', '');
$smtpUser = dm_get_system_setting($conn, $prefix, 'smtp_user', '');
$smtpPass = dm_get_system_setting($conn, $prefix, 'smtp_pass', '');
$smtpFromEmail = dm_get_system_setting($conn, $prefix, 'smtp_from_email', '');
$smtpFromName = dm_get_system_setting($conn, $prefix, 'smtp_from_name', '');
$smtpEnc = dm_get_system_setting($conn, $prefix, 'smtp_encryption', 'none');

$whatsappApiUrl = dm_get_system_setting($conn, $prefix, 'whatsapp_api_url', '');
$whatsappToken = dm_get_system_setting($conn, $prefix, 'whatsapp_access_token', '');
$sessionExpiryHours = (int)dm_get_system_setting($conn, $prefix, 'session_expiry_hours', 8);
$recordsPerPage = (int)dm_get_system_setting($conn, $prefix, 'records_per_page', 25);
if ($recordsPerPage <= 0 || $recordsPerPage > 1000) $recordsPerPage = 25;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $gst = $_POST['gstin'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $email = $_POST['email'] ?? '';
    $addr = $_POST['address'] ?? '';
    $bType = $_POST['business_type'] ?? '';
    $bCat = $_POST['business_category'] ?? '';
    $bankName = $_POST['bank_name'] ?? '';
    $accountNo = $_POST['account_no'] ?? '';
    $ifscCode = $_POST['ifsc_code'] ?? '';
    $terms = $_POST['terms'] ?? '';
    $country = $_POST['country'] ?? 'IN';
    $currencySymbol = $_POST['currency_symbol'] ?? '₹';

    // Business name comes from master, use it directly
    $bName = $companyData['name'] ?? ($profile['business_name'] ?? '');

    // Handle logo upload
    $logoPath = $profile['logo_path'] ?? '';
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK && $_FILES['logo']['size'] > 0) {
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        $allowed = ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'];
        if (in_array($ext, $allowed)) {
            $dest = upload_company_file_path($slug, 'logo', $ext, 'branding');
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) {
                $logoPath = $dest;
                try {
                    $brandDb = Database::getMasterConn();
                    $brandPrefix = Database::getMasterPrefix();
                    $brandStmt = $brandDb->prepare("UPDATE {$brandPrefix}companies SET logo = ? WHERE slug = ?");
                    $brandStmt->execute([$logoPath, $slug]);
                } catch (Throwable $e) {
                    // Keep tenant profile save working even if master logo sync fails.
                }
            }
        }
    }

    // Handle signature upload
    $sigPath = $profile['signature_path'] ?? '';
    if (isset($_FILES['signature']) && $_FILES['signature']['error'] === UPLOAD_ERR_OK && $_FILES['signature']['size'] > 0) {
        $ext = strtolower(pathinfo($_FILES['signature']['name'], PATHINFO_EXTENSION));
        $allowed = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
        if (in_array($ext, $allowed)) {
            $dest = upload_company_file_path($slug, 'signature', $ext, 'branding');
            if (move_uploaded_file($_FILES['signature']['tmp_name'], $dest)) {
                $sigPath = $dest;
            }
        }
    }

    $stmt = $conn->prepare("
        REPLACE INTO {$prefix}business_profile 
        (id, business_name, gstin, phone, email, address, business_type, business_category, logo_path, signature_path, bank_name, account_no, ifsc_code, terms, country, currency_symbol) 
        VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$bName, $gst, $phone, $email, $addr, $bType, $bCat, $logoPath, $sigPath, $bankName, $accountNo, $ifscCode, $terms, $country, $currencySymbol]);

    // Save SMTP and WhatsApp credentials settings
    $smtp_host = $_POST['smtp_host'] ?? '';
    $smtp_port = $_POST['smtp_port'] ?? '';
    $smtp_user = $_POST['smtp_user'] ?? '';
    $smtp_pass = $_POST['smtp_pass'] ?? '';
    $smtp_from_email = $_POST['smtp_from_email'] ?? '';
    $smtp_from_name = $_POST['smtp_from_name'] ?? '';
    $smtp_enc = $_POST['smtp_encryption'] ?? 'none';

    $whatsapp_api_url = $_POST['whatsapp_api_url'] ?? '';
    $whatsapp_access_token = $_POST['whatsapp_access_token'] ?? '';

    dm_set_system_setting($conn, $prefix, 'smtp_host', $smtp_host);
    dm_set_system_setting($conn, $prefix, 'smtp_port', $smtp_port);
    dm_set_system_setting($conn, $prefix, 'smtp_user', $smtp_user);
    dm_set_system_setting($conn, $prefix, 'smtp_pass', $smtp_pass);
    dm_set_system_setting($conn, $prefix, 'smtp_from_email', $smtp_from_email);
    dm_set_system_setting($conn, $prefix, 'smtp_from_name', $smtp_from_name);
    dm_set_system_setting($conn, $prefix, 'smtp_encryption', $smtp_enc);

    dm_set_system_setting($conn, $prefix, 'whatsapp_api_url', $whatsapp_api_url);
    dm_set_system_setting($conn, $prefix, 'whatsapp_access_token', $whatsapp_access_token);

    $session_expiry_hours = (int)($_POST['session_expiry_hours'] ?? 8);
    if ($session_expiry_hours <= 0) $session_expiry_hours = 8;
    dm_set_system_setting($conn, $prefix, 'session_expiry_hours', $session_expiry_hours);

    $records_per_page = (int)($_POST['records_per_page'] ?? 25);
    if ($records_per_page <= 0 || $records_per_page > 1000) $records_per_page = 25;
    dm_set_system_setting($conn, $prefix, 'records_per_page', $records_per_page);

    header("Location: profile.php?success=1");
    exit;
}

// Resolve display paths for logo and signature with prioritized extension fallbacks
$displayLogo = '';
$rawLogoPath = !empty($profile['logo_path']) ? ltrim($profile['logo_path'], '/') : '';
if ($rawLogoPath && (file_exists(__DIR__ . '/' . $rawLogoPath) || file_exists($_SERVER['DOCUMENT_ROOT'] . '/' . $rawLogoPath))) {
    $displayLogo = '/' . $rawLogoPath;
} elseif (!empty($slug)) {
    $ext = $rawLogoPath ? strtolower(pathinfo($rawLogoPath, PATHINFO_EXTENSION)) : '';
    $fallbackExts = ($ext === 'png') ? ['png', 'jpeg', 'jpg'] : ['jpeg', 'jpg', 'png'];
    foreach ($fallbackExts as $fe) {
        $fb = "assets/uploads/logos/{$slug}_logo.{$fe}";
        if (file_exists(__DIR__ . '/' . $fb) || file_exists($_SERVER['DOCUMENT_ROOT'] . '/' . $fb)) {
            $displayLogo = '/' . $fb;
            break;
        }
    }
}
if (empty($displayLogo)) {
    $displayLogo = '/images/logo.png';
}
$displaySig = '';
if (!empty($profile['signature_path'])) {
    $displaySig = '/' . ltrim($profile['signature_path'], '/');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(brand_page_title('Business Settings')) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link rel="shortcut icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <style>
        .profile-container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 30px;
        }

        .settings-card {
            background: var(--surface);
            border-radius: 24px;
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            border: 1px solid var(--border);
            margin-bottom: 28px;
        }

        .card-header {
            padding: 24px 30px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-title i {
            color: var(--primary);
        }

        .card-body {
            padding: 32px 40px;
        }

        .grid-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
        }

        .section-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 8px;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border-radius: 12px;
            border: 1.5px solid var(--border);
            background: #fff;
            font-size: 14px;
            transition: all 0.2s;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(123, 94, 240, 0.1);
            outline: none;
        }

        .form-control.readonly {
            background: #f3f4f6;
            color: #6b7280;
            cursor: not-allowed;
        }

        .upload-area {
            border: 2px dashed var(--border);
            border-radius: 18px;
            padding: 24px;
            text-align: center;
            background: #fcfcfd;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }

        .upload-area:hover {
            border-color: var(--primary);
            background: #f8f7ff;
        }

        .upload-area input[type=file] {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }

        .logo-preview {
            max-height: 80px;
            max-width: 200px;
            border-radius: 12px;
            margin-bottom: 10px;
        }

        .sig-preview {
            max-height: 60px;
            max-width: 160px;
            border-radius: 8px;
            margin-bottom: 10px;
        }

        .success-alert {
            background: rgba(123, 94, 240, .1);
            color: #5b3cc4;
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-weight: 600;
            display: none;
            border-left: 4px solid var(--primary);
        }

        .readonly-hint {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 4px;
        }

        @media (max-width: 900px) {
            .grid-layout {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="app-wrapper">
        <?php include 'includes/sidebar.php'; ?>
        <main class="main-content">
            <header class="topbar">
                <div class="breadcrumb">Settings / <span class="current">Business Profile</span></div>
                <div class="topbar-right">
                    <button form="profileForm" class="btn-primary" style="width: auto; padding: 12px 24px;"><i
                            class="fa-solid fa-check"></i> SAVE CHANGES</button>
                </div>
            </header>
            <div class="content-scroll">
                <div class="profile-container">
                    <?php if (isset($_GET['success'])): ?>
                        <div class="success-alert" style="display: block;"><i class="fa-solid fa-circle-check"></i> Settings
                            updated successfully!</div>
                    <?php endif; ?>

                    <form id="profileForm" method="POST" enctype="multipart/form-data">
                        <!-- Business Details Card -->
                        <div class="settings-card">
                            <div class="card-header">
                                <div class="card-title"><i class="fa-solid fa-building"></i> Business Identity</div>
                            </div>
                            <div class="card-body">
                                <div class="grid-layout">
                                    <div>
                                        <div class="form-group">
                                            <label class="form-label">Business Name</label>
                                            <input type="text" class="form-control readonly"
                                                value="<?= htmlspecialchars($profile['business_name'] ?? ($companyData['name'] ?? '')) ?>"
                                                readonly>
                                            <div class="readonly-hint"><i class="fa-solid fa-lock"></i> Managed by Super
                                                Admin</div>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">GSTIN</label>
                                            <input type="text" name="gstin" class="form-control"
                                                placeholder="22AAAAA0000A1Z5"
                                                value="<?= htmlspecialchars($profile['gstin'] ?? '') ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Business Phone</label>
                                            <input type="text" name="phone" class="form-control"
                                                placeholder="+91 00000 00000"
                                                value="<?= htmlspecialchars($profile['phone'] ?? '') ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Business Email</label>
                                            <input type="email" name="email" class="form-control"
                                                placeholder="contact@business.com"
                                                value="<?= htmlspecialchars($profile['email'] ?? '') ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Business Address</label>
                                            <textarea name="address" class="form-control" rows="3"
                                                placeholder="Full registered address..."><?= htmlspecialchars($profile['address'] ?? '') ?></textarea>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Country</label>
                                            <select name="country" class="form-control">
                                                <option value="">Select Country...</option>
                                                <?php foreach (dm_get_countries() as $code => $cname): ?>
                                                    <option value="<?= $code ?>" <?= ($profile['country'] ?? 'IN') == $code ? 'selected' : '' ?>><?= htmlspecialchars($cname) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Currency Symbol</label>
                                            <input type="text" name="currency_symbol" class="form-control"
                                                placeholder="e.g. ₹, $, £"
                                                value="<?= htmlspecialchars($profile['currency_symbol'] ?? '₹') ?>">
                                        </div>
                                    </div>
                                    <div>
                                        <div class="form-group">
                                            <label class="form-label">Company Logo</label>
                                            <div class="upload-area" id="logoUploadArea">
                                                <?php if ($displayLogo): ?>
                                                    <img src="<?= htmlspecialchars($displayLogo) ?>?v=<?= $v ?>"
                                                        class="logo-preview" id="logoPreview" alt="Logo"><br>
                                                    <span class="text-muted text-sm">Click to change logo</span>
                                                <?php else: ?>
                                                    <i class="fa-solid fa-image"
                                                        style="font-size:32px;color:var(--text-muted);margin-bottom:10px;display:block;"></i>
                                                    <img src="" class="logo-preview" id="logoPreview" alt=""
                                                        style="display:none;"><br>
                                                    <span class="text-muted" id="logoHint">Click to upload logo
                                                        (PNG/JPG)</span>
                                                <?php endif; ?>
                                                <input type="file" name="logo" accept="image/*"
                                                    onchange="previewFile(this, 'logoPreview', 'logoHint')">
                                            </div>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Business Type</label>
                                            <select name="business_type" class="form-control">
                                                <option value="">Select Type</option>
                                                <?php foreach (['Retail', 'Wholesale', 'Service', 'Manufacturing', 'Hospital', 'Pharmacy'] as $t): ?>
                                                    <option value="<?= $t ?>" <?= ($profile['business_type'] ?? '') == $t ? 'selected' : '' ?>><?= $t ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Business Category</label>
                                            <select name="business_category" class="form-control">
                                                <option value="">Select Category</option>
                                                <?php foreach (['Electronics', 'Pharmacy', 'FMCG', 'Automobile', 'Textile', 'Agriculture', 'IT Services', 'Others'] as $c): ?>
                                                    <option value="<?= $c ?>" <?= ($profile['business_category'] ?? '') == $c ? 'selected' : '' ?>><?= $c ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Bank & Signature Card -->
                        <div class="settings-card">
                            <div class="card-header">
                                <div class="card-title"><i class="fa-solid fa-landmark"></i> Bank Details & Signature
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="grid-layout">
                                    <div>
                                        <div class="section-title"><i class="fa-solid fa-building-columns"></i> Bank
                                            Information</div>
                                        <div class="form-group">
                                            <label class="form-label">Bank Name</label>
                                            <input type="text" name="bank_name" class="form-control"
                                                placeholder="e.g. State Bank of India"
                                                value="<?= htmlspecialchars($profile['bank_name'] ?? '') ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Account Number</label>
                                            <input type="text" name="account_no" class="form-control"
                                                placeholder="e.g. 120028422420"
                                                value="<?= htmlspecialchars($profile['account_no'] ?? '') ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">IFSC Code</label>
                                            <input type="text" name="ifsc_code" class="form-control"
                                                placeholder="e.g. SBIN0007440"
                                                value="<?= htmlspecialchars($profile['ifsc_code'] ?? '') ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Default Terms & Conditions</label>
                                            <textarea name="terms" class="form-control" rows="3"
                                                placeholder="e.g. Thanks for doing business with us!"><?= htmlspecialchars($profile['terms'] ?? '') ?></textarea>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="section-title"><i class="fa-solid fa-signature"></i> Authorized
                                            Signature</div>
                                        <div class="form-group">
                                            <div class="upload-area">
                                                <?php if ($displaySig): ?>
                                                    <img src="<?= htmlspecialchars($displaySig) ?>?v=<?= $v ?>"
                                                        class="sig-preview" id="sigPreview" alt="Signature"><br>
                                                    <span class="text-muted text-sm">Click to change signature</span>
                                                <?php else: ?>
                                                    <i class="fa-solid fa-signature"
                                                        style="font-size:32px;color:var(--text-muted);margin-bottom:12px;display:block;"></i>
                                                    <img src="" class="sig-preview" id="sigPreview" alt=""
                                                        style="display:none;"><br>
                                                    <span class="text-muted" id="sigHint">Click to upload signature
                                                        (PNG/JPG)</span>
                                                <?php endif; ?>
                                                <input type="file" name="signature" accept="image/*"
                                                    onchange="previewFile(this, 'sigPreview', 'sigHint')">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Integration Gateways -->
                        <div class="settings-card">
                            <div class="card-header">
                                <div class="card-title"><i class="fa-solid fa-gears"></i> Integration Gateways</div>
                            </div>
                            <div class="card-body">
                                <div class="grid-layout">
                                    <div>
                                        <div class="section-title"><i class="fa-solid fa-envelope"></i> SMTP Email
                                            Server Settings</div>
                                        <div class="form-group">
                                            <label class="form-label">SMTP Host</label>
                                            <input type="text" name="smtp_host" class="form-control"
                                                placeholder="e.g. smtp.gmail.com"
                                                value="<?= htmlspecialchars($smtpHost) ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">SMTP Port</label>
                                            <input type="number" name="smtp_port" class="form-control"
                                                placeholder="e.g. 456 (or 587)"
                                                value="<?= htmlspecialchars($smtpPort) ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">SMTP Username</label>
                                            <input type="text" name="smtp_user" class="form-control"
                                                placeholder="e.g. you@gmail.com"
                                                value="<?= htmlspecialchars($smtpUser) ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">SMTP Password</label>
                                            <input type="password" name="smtp_pass" class="form-control"
                                                placeholder="SMTP Password" value="<?= htmlspecialchars($smtpPass) ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">From Email Address</label>
                                            <input type="email" name="smtp_from_email" class="form-control"
                                                placeholder="e.g. alerts@yourcompany.com"
                                                value="<?= htmlspecialchars($smtpFromEmail) ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">From Name</label>
                                            <input type="text" name="smtp_from_name" class="form-control"
                                                placeholder="e.g. Alert System"
                                                value="<?= htmlspecialchars($smtpFromName) ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Encryption Type(SSL (if using Port 465) or TLS (if
                                                using Port 587))</label>
                                            <select name="smtp_encryption" class="form-control">
                                                <option value="none" <?= $smtpEnc === 'none' ? 'selected' : '' ?>>None
                                                </option>
                                                <option value="ssl" <?= $smtpEnc === 'ssl' ? 'selected' : '' ?>>SSL
                                                </option>
                                                <option value="tls" <?= $smtpEnc === 'tls' ? 'selected' : '' ?>>TLS /
                                                    STARTTLS</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="section-title"><i class="fa-brands fa-whatsapp"></i> WhatsApp Cloud
                                            API Settings</div>
                                        <div class="form-group">
                                            <label class="form-label">API Endpoint URL</label>
                                            <input type="url" name="whatsapp_api_url" class="form-control"
                                                placeholder="https://graph.facebook.com/v17.0/YOUR_PHONE_NUMBER_ID/messages"
                                                value="<?= htmlspecialchars($whatsappApiUrl) ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">System Access Token (Bearer)</label>
                                            <textarea name="whatsapp_access_token" class="form-control" rows="5"
                                                placeholder="EAABwz... (Permanent Access Token)"><?= htmlspecialchars($whatsappToken) ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Security & Session Settings -->
                        <div class="settings-card">
                            <div class="card-header">
                                <div class="card-title"><i class="fa-solid fa-shield-halved"></i> Security & Session Settings</div>
                            </div>
                            <div class="card-body">
                                <div class="form-group" style="max-width: 400px;">
                                    <label class="form-label">Auto Logout Timeout (Hours)</label>
                                    <input type="number" name="session_expiry_hours" class="form-control" min="1" max="720" value="<?= htmlspecialchars($sessionExpiryHours) ?>">
                                    <span style="font-size:11px; color:var(--text-muted); display:block; margin-top:4px;">Configure how many hours a user remains logged in before automatically logging out.</span>
                                </div>
                            </div>
                        </div>

                        <!-- Pagination & View Settings -->
                        <div class="settings-card">
                            <div class="card-header">
                                <div class="card-title"><i class="fa-solid fa-list-ol"></i> Pagination & View Settings</div>
                            </div>
                            <div class="card-body">
                                <div class="form-group" style="max-width: 400px;">
                                    <label class="form-label">Default Records Per Page</label>
                                    <div style="display: flex; gap: 10px; align-items: center;">
                                        <select id="records_per_page_select" class="form-control" onchange="toggleCustomRecordsInput(this.value)" style="flex: 1.5;">
                                            <option value="10" <?= $recordsPerPage === 10 ? 'selected' : '' ?>>10 records per page</option>
                                            <option value="25" <?= $recordsPerPage === 25 ? 'selected' : '' ?>>25 records per page</option>
                                            <option value="50" <?= $recordsPerPage === 50 ? 'selected' : '' ?>>50 records per page</option>
                                            <option value="100" <?= $recordsPerPage === 100 ? 'selected' : '' ?>>100 records per page</option>
                                            <option value="200" <?= $recordsPerPage === 200 ? 'selected' : '' ?>>200 records per page</option>
                                            <option value="300" <?= $recordsPerPage === 300 ? 'selected' : '' ?>>300 records per page</option>
                                            <option value="500" <?= $recordsPerPage === 500 ? 'selected' : '' ?>>500 records per page</option>
                                            <option value="custom" <?= !in_array($recordsPerPage, [10, 25, 50, 100, 200, 300, 500]) ? 'selected' : '' ?>>Custom number...</option>
                                        </select>
                                        <input type="number" id="records_per_page_custom" name="records_per_page" class="form-control" min="1" max="1000" value="<?= htmlspecialchars($recordsPerPage) ?>" style="display: <?= !in_array($recordsPerPage, [10, 25, 50, 100, 200, 300, 500]) ? 'block' : 'none' ?>; flex: 1; height: 50px;" placeholder="Digits">
                                    </div>
                                    <span style="font-size:11px; color:var(--text-muted); display:block; margin-top:8px;">Select a default option or choose "Custom number..." to enter manually.</span>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
    <script>
        function toggleCustomRecordsInput(val) {
            const customInput = document.getElementById('records_per_page_custom');
            if (val === 'custom') {
                customInput.style.display = 'block';
                customInput.focus();
            } else {
                customInput.style.display = 'none';
                customInput.value = val;
            }
        }
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('sidebar-collapsed');
        }
        function previewFile(input, previewId, hintId) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function (e) {
                    const img = document.getElementById(previewId);
                    img.src = e.target.result;
                    img.style.display = 'inline-block';
                    const hint = document.getElementById(hintId);
                    if (hint) hint.textContent = 'New file selected — click Save';
                };
                reader.readAsDataURL(input.files[0]);
            }
        }
    </script>
</body>

</html>