<?php
// api/login.php - Tenant-Aware Secure Login with 2FA & Status Enforcement
require_once '../config/database.php';
require_once '../includes/api_auth.php';
require_once '../includes/dynamic_modules.php';
session_start();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$action = $_POST['action'] ?? '';
$companySlug = trim($_POST['company'] ?? '');
$userInput = trim($_POST['username'] ?? '');
$passInput = $_POST['password'] ?? '';
$otpInput = trim($_POST['otp'] ?? '');
$userIdInput = (int)($_POST['user_id'] ?? 0);

if (!$companySlug) {
    echo json_encode(['success' => false, 'message' => 'Missing company identifier']);
    exit;
}

function send_2fa_email($user, $company, $tenantConn, $tenantPrefix, $otp) {
    $toEmail = $user['email'] ?? '';
    if (!$toEmail) return false;

    $companyName = $company['name'] ?? 'VY CRM';
    $subject = "Your Two-Step Verification Code - " . $companyName;
    $body = "Hello " . htmlspecialchars($user['first_name'] ?: $user['username']) . ",<br><br>"
          . "Your Two-Step Verification code is: <div style='font-size:28px; font-weight:800; color:#7b5ef0; letter-spacing:6px; margin:16px 0;'>$otp</div>"
          . "This verification code is valid for <b>10 minutes</b>.<br><br>"
          . "If you did not attempt to log in to your account, please secure your credentials immediately.<br><br>"
          . "Regards,<br>" . htmlspecialchars($companyName) . " Team";

    $emailSent = false;
    try {
        $smtpStmt = $tenantConn->prepare("SELECT * FROM {$tenantPrefix}communication_configs WHERE type = 'smtp' ORDER BY is_default DESC, id ASC LIMIT 1");
        $smtpStmt->execute();
        $smtpConfig = $smtpStmt->fetch(PDO::FETCH_ASSOC);

        if ($smtpConfig) {
            $rawConf = $smtpConfig['config_data'] ?? $smtpConfig['config_json'] ?? null;
            $conf = is_array($rawConf) ? $rawConf : json_decode((string)$rawConf, true);
            if ($conf && is_array($conf)) {
                $emailSent = dm_send_smtp_email(
                    $conf['smtp_host'] ?? $conf['host'] ?? '',
                    (int)($conf['smtp_port'] ?? $conf['port'] ?? 587),
                    $conf['smtp_user'] ?? $conf['user'] ?? $conf['username'] ?? '',
                    $conf['smtp_pass'] ?? $conf['pass'] ?? $conf['password'] ?? '',
                    $conf['smtp_from_email'] ?? $conf['from_email'] ?? $conf['smtp_user'] ?? $conf['user'] ?? '',
                    $conf['smtp_from_name'] ?? $conf['from_name'] ?? $companyName,
                    $toEmail,
                    $subject,
                    $body,
                    $conf['smtp_encryption'] ?? $conf['encryption'] ?? 'tls'
                );
            }
        }
    } catch (Throwable $e) {}

    if (!$emailSent) {
        $fromHost = $_SERVER['HTTP_HOST'] ?? 'vycrm.vygroups.com';
        $headers = "MIME-Version: 1.0\r\nContent-type: text/html; charset=utf-8\r\nFrom: no-reply@{$fromHost}\r\n";
        @mail($toEmail, $subject, $body, $headers);
    }
    return true;
}

function mask_user_email($email) {
    $parts = explode('@', $email);
    if (count($parts) < 2) return $email;
    $name = $parts[0];
    $maskedName = strlen($name) <= 2 ? $name . '***' : substr($name, 0, 1) . '***' . substr($name, -1);
    return $maskedName . '@' . $parts[1];
}

function save_user_fcm_token($tenantConn, $tenantPrefix, $userId, $fcmToken, $deviceType = 'android') {
    if (!isset($_POST['fcm_token']) && !isset($_GET['fcm_token'])) {
        return; // Don't clear if parameter wasn't part of login request
    }
    try {
        dm_ensure_tables($tenantConn, $tenantPrefix);
        $deviceType = strtolower(trim($deviceType ?: 'android'));
        if (!in_array($deviceType, ['android', 'ios', 'web'])) {
            $deviceType = 'android';
        }

        if (!empty($fcmToken) && $fcmToken !== 'null' && $fcmToken !== 'clear') {
            if ($deviceType === 'web') {
                $stmt = $tenantConn->prepare("UPDATE {$tenantPrefix}users SET fcm_web_token = ?, fcm_updated_at = NOW() WHERE id = ?");
                $stmt->execute([$fcmToken, $userId]);
            } else {
                $stmt = $tenantConn->prepare("UPDATE {$tenantPrefix}users SET fcm_token = ?, fcm_device_type = ?, fcm_updated_at = NOW() WHERE id = ?");
                $stmt->execute([$fcmToken, $deviceType, $userId]);
            }
        } else {
            if ($deviceType === 'web') {
                $stmt = $tenantConn->prepare("UPDATE {$tenantPrefix}users SET fcm_web_token = NULL, fcm_updated_at = NOW() WHERE id = ?");
                $stmt->execute([$userId]);
            } else {
                $stmt = $tenantConn->prepare("UPDATE {$tenantPrefix}users SET fcm_token = NULL, fcm_updated_at = NOW() WHERE id = ?");
                $stmt->execute([$userId]);
            }
        }
    } catch (Throwable $e) {}
}

try {
    $masterDb = Database::getMasterConn();
    $prefix = Database::getMasterPrefix();
    
    // 1. Resolve Company
    $stmt = $masterDb->prepare("SELECT * FROM {$prefix}companies WHERE slug = ?");
    $stmt->execute([$companySlug]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$company) {
        echo json_encode(['success' => false, 'message' => 'Invalid company identifier']);
        exit;
    }
    
    // 2. Connect to Tenant Environment
    $isIsolated = ($company['db_name'] != Database::getMasterDBName());
    $tenantConn = Database::getTenantConn($company['db_name']);
    $tenantPrefix = $isIsolated ? "" : $companySlug . "_";
    
    if (!$tenantConn) {
        echo json_encode(['success' => false, 'message' => 'Tenant database connection failed']);
        exit;
    }
    dm_ensure_tables($tenantConn, $tenantPrefix);

    // ACTION: Resend 2FA OTP
    if ($action === 'resend_2fa') {
        if (!$userIdInput) {
            echo json_encode(['success' => false, 'message' => 'Missing user ID']);
            exit;
        }
        $stmt = $tenantConn->prepare("SELECT * FROM {$tenantPrefix}users WHERE id = ?");
        $stmt->execute([$userIdInput]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found']);
            exit;
        }
        if (isset($user['status']) && strtolower($user['status']) === 'inactive') {
            echo json_encode(['success' => false, 'message' => 'Your account is deactivated. Please contact your administrator.']);
            exit;
        }

        $otp = sprintf('%06d', mt_rand(100000, 999999));
        $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        $upStmt = $tenantConn->prepare("UPDATE {$tenantPrefix}users SET two_factor_otp = ?, two_factor_expires = ? WHERE id = ?");
        $upStmt->execute([$otp, $expires, $user['id']]);

        send_2fa_email($user, $company, $tenantConn, $tenantPrefix, $otp);
        echo json_encode(['success' => true, 'message' => 'A new verification code has been sent to your email.']);
        exit;
    }

    // ACTION: Verify 2FA OTP
    if ($action === 'verify_2fa') {
        if (!$userIdInput || !$otpInput) {
            echo json_encode(['success' => false, 'message' => 'Missing user ID or OTP code']);
            exit;
        }
        $stmt = $tenantConn->prepare("SELECT u.*, r.name as role_name FROM {$tenantPrefix}users u LEFT JOIN {$tenantPrefix}roles r ON r.id = u.role_id WHERE u.id = ?");
        $stmt->execute([$userIdInput]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found']);
            exit;
        }
        if (isset($user['status']) && strtolower($user['status']) === 'inactive') {
            echo json_encode(['success' => false, 'message' => 'Your account is deactivated. Please contact your administrator.']);
            exit;
        }

        if (empty($user['two_factor_otp']) || $user['two_factor_otp'] !== $otpInput) {
            echo json_encode(['success' => false, 'message' => 'Invalid verification code. Please check and try again.']);
            exit;
        }
        if (!empty($user['two_factor_expires']) && strtotime($user['two_factor_expires']) < time()) {
            echo json_encode(['success' => false, 'message' => 'Verification code has expired. Please click Resend Code.']);
            exit;
        }

        // Clear OTP upon successful validation
        $clrStmt = $tenantConn->prepare("UPDATE {$tenantPrefix}users SET two_factor_otp = NULL, two_factor_expires = NULL WHERE id = ?");
        $clrStmt->execute([$user['id']]);

        // Establish full session
        $token = bin2hex(random_bytes(32));
        $_SESSION['token'] = $token;
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['first_name'] = $user['first_name'] ?? '';
        $_SESSION['last_name'] = $user['last_name'] ?? '';
        $_SESSION['is_admin'] = (int)($user['is_admin'] ?? 0);
        $_SESSION['tenant_slug'] = $companySlug;
        $_SESSION['tenant_db'] = $company['db_name'];
        $_SESSION['tenant_prefix'] = $tenantPrefix;
        setcookie('vy_company_slug', $companySlug, time() + 315360000, '/');
        $expiryHours = (int)dm_get_system_setting($tenantConn, $tenantPrefix, 'session_expiry_hours', 8);
        if ($expiryHours <= 0) $expiryHours = 8;
        $_SESSION['expiry'] = time() + ($expiryHours * 3600);
        $apiToken = api_issue_token($user, $companySlug, $company['db_name'], $tenantPrefix);
        save_user_fcm_token($tenantConn, $tenantPrefix, $user['id'], $_POST['fcm_token'] ?? null, $_POST['device_type'] ?? 'android');

        $roleName = !empty($user['role_name']) ? $user['role_name'] : (!empty($user['is_admin']) ? 'Administrator' : 'Team Member');

        echo json_encode([
            'success' => true,
            'message' => 'Two-Step Verification successful',
            'redirect' => '/dashboard.php',
            'api_token' => $apiToken,
            'token_type' => 'Bearer',
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'first_name' => $user['first_name'] ?? '',
            'last_name' => $user['last_name'] ?? '',
            'is_admin' => (int)($user['is_admin'] ?? 0),
            'role_name' => $roleName,
            'profile_picture' => !empty($user['profile_picture']) 
                ? '/serve_file.php?path=' . urlencode(ltrim($user['profile_picture'], '/'))
                : 'https://ui-avatars.com/api/?name=' . urlencode(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: $user['username']) . '&background=0A4D3E&color=FFFFFF&bold=true&format=png',
            'company_logo' => !empty($company['logo']) 
                ? '/serve_file.php?path=' . urlencode(ltrim($company['logo'], '/'))
                : '/images/logo.png',
            'company_name' => $company['name'] ?? 'VY AI CRM',
        ]);
        exit;
    }
    
    // STANDARD LOGIN: Validate Username & Password
    if (!$userInput || !$passInput) {
        echo json_encode(['success' => false, 'message' => 'Missing credentials']);
        exit;
    }

    $stmt = $tenantConn->prepare("SELECT u.*, r.name as role_name FROM {$tenantPrefix}users u LEFT JOIN {$tenantPrefix}roles r ON r.id = u.role_id WHERE (u.username = ? OR u.email = ?)");
    $stmt->execute([$userInput, $userInput]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && password_verify($passInput, $user['password'])) {
        // Enforce Inactive Account Check
        if (isset($user['status']) && strtolower($user['status']) === 'inactive') {
            echo json_encode(['success' => false, 'message' => 'Your account is deactivated. Please contact your administrator.']);
            exit;
        }

        // Check if Two-Step Verification is enabled
        if (!empty($user['two_factor_enabled']) && (int)$user['two_factor_enabled'] === 1) {
            $otp = sprintf('%06d', mt_rand(100000, 999999));
            $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
            
            $upStmt = $tenantConn->prepare("UPDATE {$tenantPrefix}users SET two_factor_otp = ?, two_factor_expires = ? WHERE id = ?");
            $upStmt->execute([$otp, $expires, $user['id']]);

            send_2fa_email($user, $company, $tenantConn, $tenantPrefix, $otp);
            $maskedEmail = mask_user_email($user['email']);

            echo json_encode([
                'success' => true,
                'requires_2fa' => true,
                'user_id' => (int)$user['id'],
                'company' => $companySlug,
                'email' => $maskedEmail,
                'message' => 'Two-Step Verification required. An OTP has been sent to ' . $maskedEmail
            ]);
            exit;
        }

        // Normal Login Session Creation
        $token = bin2hex(random_bytes(32));
        $_SESSION['token'] = $token;
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['first_name'] = $user['first_name'] ?? '';
        $_SESSION['last_name'] = $user['last_name'] ?? '';
        $_SESSION['is_admin'] = (int)($user['is_admin'] ?? 0);
        $_SESSION['tenant_slug'] = $companySlug;
        $_SESSION['tenant_db'] = $company['db_name'];
        $_SESSION['tenant_prefix'] = $tenantPrefix;
        setcookie('vy_company_slug', $companySlug, time() + 315360000, '/');
        $expiryHours = (int)dm_get_system_setting($tenantConn, $tenantPrefix, 'session_expiry_hours', 8);
        if ($expiryHours <= 0) $expiryHours = 8;
        $_SESSION['expiry'] = time() + ($expiryHours * 3600);
        $apiToken = api_issue_token($user, $companySlug, $company['db_name'], $tenantPrefix);
        save_user_fcm_token($tenantConn, $tenantPrefix, $user['id'], $_POST['fcm_token'] ?? null, $_POST['device_type'] ?? 'android');

        $roleName = !empty($user['role_name']) ? $user['role_name'] : (!empty($user['is_admin']) ? 'Administrator' : 'Team Member');

        echo json_encode([
            'success' => true,
            'message' => 'Login successful',
            'redirect' => '/dashboard.php',
            'api_token' => $apiToken,
            'token_type' => 'Bearer',
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'first_name' => $user['first_name'] ?? '',
            'last_name' => $user['last_name'] ?? '',
            'is_admin' => (int)($user['is_admin'] ?? 0),
            'role_name' => $roleName,
            'profile_picture' => !empty($user['profile_picture']) 
                ? '/serve_file.php?path=' . urlencode(ltrim($user['profile_picture'], '/'))
                : 'https://ui-avatars.com/api/?name=' . urlencode(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: $user['username']) . '&background=0A4D3E&color=FFFFFF&bold=true&format=png',
            'company_logo' => !empty($company['logo']) 
                ? '/serve_file.php?path=' . urlencode(ltrim($company['logo'], '/'))
                : '/images/logo.png',
            'company_name' => $company['name'] ?? 'VY AI CRM',
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid username or password']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Internal Server Error: ' . $e->getMessage()]);
}

