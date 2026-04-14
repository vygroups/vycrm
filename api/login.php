<?php
// api/login.php - Tenant-Aware Secure Login
require_once '../config/database.php';
require_once '../includes/api_auth.php';
session_start();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$userInput = $_POST['username'] ?? '';
$passInput = $_POST['password'] ?? '';
$companySlug = $_POST['company'] ?? '';

if (!$userInput || !$passInput || !$companySlug) {
    echo json_encode(['success' => false, 'message' => 'Missing credentials or company slug']);
    exit;
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
    
    // 3. Verify User
    $stmt = $tenantConn->prepare("SELECT * FROM {$tenantPrefix}users WHERE (username = ? OR email = ?)");
    $stmt->execute([$userInput, $userInput]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && password_verify($passInput, $user['password'])) {
        // 4. Create Session / Token
        $token = bin2hex(random_bytes(32));
        $_SESSION['token'] = $token;
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['tenant_slug'] = $companySlug;
        $_SESSION['tenant_db'] = $company['db_name'];
        $_SESSION['tenant_prefix'] = $tenantPrefix;
        $_SESSION['expiry'] = time() + (8 * 3600); // 8 Hours
        $apiToken = api_issue_token($user, $companySlug, $company['db_name'], $tenantPrefix);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Login successful',
            'redirect' => '../dashboard.php',
            'api_token' => $apiToken,
            'token_type' => 'Bearer'
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid username or password']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Internal Server Error: ' . $e->getMessage()]);
}
?>
