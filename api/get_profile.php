    <?php
// api/get_profile.php - Get User and Company Details
require_once '../config/database.php';
require_once '../includes/api_auth.php';
require_once '../includes/dynamic_modules.php';
session_start();

header('Content-Type: application/json');

try {
    $context = api_require_context();
    $conn = $context['conn'];
    $prefix = $context['prefix'];
    $userId = $context['user_id'];
    $companySlug = $context['tenant_slug'];

    // 1. Fetch user details from tenant database
    $stmt = $conn->prepare("SELECT id, username, first_name, last_name, is_admin, profile_picture FROM {$prefix}users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }

    // 2. Fetch company details from master database
    $masterDb = Database::getMasterConn();
    $masterPrefix = Database::getMasterPrefix();
    $stmt = $masterDb->prepare("SELECT name, logo FROM {$masterPrefix}companies WHERE slug = ?");
    $stmt->execute([$companySlug]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'first_name' => $user['first_name'] ?? '',
        'last_name' => $user['last_name'] ?? '',
        'is_admin' => (int)($user['is_admin'] ?? 0),
        'profile_picture' => !empty($user['profile_picture']) 
            ? '/serve_file.php?path=' . urlencode(ltrim($user['profile_picture'], '/'))
            : 'https://ui-avatars.com/api/?name=' . urlencode(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: $user['username']) . '&background=0A4D3E&color=FFFFFF&bold=true&format=png',
        'company_logo' => (!empty($company) && !empty($company['logo'])) 
            ? '/serve_file.php?path=' . urlencode(ltrim($company['logo'], '/'))
            : '/images/logo.png',
        'company_name' => (!empty($company) && !empty($company['name'])) ? $company['name'] : 'VY AI CRM',
    ]);

} catch (Exception $e) {
    http_response_code($e->getMessage() === 'Unauthorized' ? 401 : 500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
