<?php
// api/forgot_password_api.php
require_once '../config/database.php';
require_once '../includes/dynamic_modules.php'; // for dm_send_smtp_email

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$action = $_POST['action'] ?? '';
$companySlug = trim($_POST['company'] ?? '');
$username = trim($_POST['username'] ?? '');

if (!$companySlug || !$username) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

try {
    $masterDb = Database::getMasterConn();
    $prefix = Database::getMasterPrefix();
    
    // Resolve Company
    $stmt = $masterDb->prepare("SELECT * FROM {$prefix}companies WHERE slug = ?");
    $stmt->execute([$companySlug]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$company) {
        echo json_encode(['success' => false, 'message' => 'Invalid company slug']);
        exit;
    }
    
    // Connect to Tenant Environment
    $isIsolated = ($company['db_name'] != Database::getMasterDBName());
    $tenantConn = Database::getTenantConn($company['db_name']);
    $tenantPrefix = $isIsolated ? "" : $companySlug . "_";
    
    if (!$tenantConn) {
        echo json_encode(['success' => false, 'message' => 'Tenant database connection failed']);
        exit;
    }
    
    // Find User
    $stmt = $tenantConn->prepare("SELECT * FROM {$tenantPrefix}users WHERE (username = ? OR email = ?)");
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        // Return a generic success to prevent email enumeration, but we'll show an error for UX for now.
        echo json_encode(['success' => false, 'message' => 'User not found in this company']);
        exit;
    }
    
    if ($action === 'request_otp') {
        // Generate 6-digit OTP
        $otp = sprintf('%06d', mt_rand(100000, 999999));
        $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        
        $updateStmt = $tenantConn->prepare("UPDATE {$tenantPrefix}users SET reset_otp = ?, reset_otp_expires = ? WHERE id = ?");
        $updateStmt->execute([$otp, $expires, $user['id']]);
        
        $toEmail = $user['email'];
        $subject = "Your Password Reset OTP";
        $body = "Hello {$user['first_name']},<br><br>Your OTP to reset your password is: <b>$otp</b><br>This OTP is valid for 15 minutes.<br><br>If you did not request this, please ignore this email.";
        
        // Check for SMTP config
        $smtpStmt = $tenantConn->prepare("SELECT * FROM {$tenantPrefix}communication_configs WHERE type = 'smtp' ORDER BY is_default DESC, id ASC LIMIT 1");
        $smtpStmt->execute();
        $smtpConfig = $smtpStmt->fetch(PDO::FETCH_ASSOC);
        
        $emailSent = false;
        $lastError = '';
        if ($smtpConfig) {
            try {
                $rawConf = $smtpConfig['config_data'] ?? $smtpConfig['config_json'] ?? null;
                $conf = is_array($rawConf) ? $rawConf : json_decode((string)$rawConf, true);
                if ($conf && is_array($conf)) {
                    $emailSent = dm_send_smtp_email(
                        $conf['smtp_host'] ?? $conf['host'] ?? '',
                        (int)($conf['smtp_port'] ?? $conf['port'] ?? 587),
                        $conf['smtp_user'] ?? $conf['user'] ?? $conf['username'] ?? '',
                        $conf['smtp_pass'] ?? $conf['pass'] ?? $conf['password'] ?? '',
                        $conf['smtp_from_email'] ?? $conf['from_email'] ?? $conf['smtp_user'] ?? $conf['user'] ?? '',
                        $conf['smtp_from_name'] ?? $conf['from_name'] ?? $company['name'] ?? 'VY CRM',
                        $toEmail,
                        $subject,
                        $body,
                        $conf['smtp_encryption'] ?? $conf['encryption'] ?? 'tls'
                    );
                }
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
            }
        }
        
        if (!$emailSent) {
            // Fallback to PHP mail()
            $fromHost = $_SERVER['HTTP_HOST'] ?? 'vycrm.vygroups.com';
            $headers = "MIME-Version: 1.0\r\n";
            $headers .= "Content-type: text/html; charset=utf-8\r\n";
            $headers .= "From: no-reply@{$fromHost}\r\n";
            $emailSent = @mail($toEmail, $subject, $body, $headers);
        }
        
        if ($emailSent) {
            echo json_encode(['success' => true, 'message' => 'OTP Sent']);
        } else {
            $failMsg = 'Failed to send OTP email.';
            if ($lastError) {
                if (strpos($lastError, '535') !== false || strpos($lastError, 'BadCredentials') !== false) {
                    $failMsg = 'SMTP Error: Gmail rejected the password. Please create and use a 16-character Google App Password (not your personal password).';
                } else {
                    $failMsg = 'SMTP Error: ' . $lastError;
                }
            }
            echo json_encode(['success' => false, 'message' => $failMsg]);
        }
        exit;
    }
    
    if ($action === 'reset_password') {
        $otpInput = trim($_POST['otp'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';
        
        if (!$otpInput || !$newPassword) {
            echo json_encode(['success' => false, 'message' => 'Missing OTP or new password']);
            exit;
        }
        
        if ($user['reset_otp'] !== $otpInput) {
            echo json_encode(['success' => false, 'message' => 'Invalid OTP']);
            exit;
        }
        
        if (strtotime($user['reset_otp_expires']) < time()) {
            echo json_encode(['success' => false, 'message' => 'OTP has expired. Please request a new one.']);
            exit;
        }
        
        // Update Password
        $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
        $updateStmt = $tenantConn->prepare("UPDATE {$tenantPrefix}users SET password = ?, reset_otp = NULL, reset_otp_expires = NULL WHERE id = ?");
        $updateStmt->execute([$hashed, $user['id']]);
        
        echo json_encode(['success' => true, 'message' => 'Password reset successful']);
        exit;
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
