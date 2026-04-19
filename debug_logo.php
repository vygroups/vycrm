<?php
require_once 'auth_check.php';
require_once 'config/database.php';
header('Content-Type: application/json');

$tenantDb = $_SESSION['tenant_db'] ?? '';
$tenantPrefix = $_SESSION['tenant_prefix'] ?? '';
$slug = $_SESSION['tenant_slug'] ?? '';

$result = [
    'session' => ['tenant_db' => $tenantDb, 'tenant_prefix' => $tenantPrefix, 'slug' => $slug],
    'master_logo' => null,
    'business_profile' => null,
    'error' => null,
];

try {
    $brandDb = Database::getMasterConn();
    $brandPrefix = Database::getMasterPrefix();
    $stmt = $brandDb->prepare("SELECT name, logo FROM {$brandPrefix}companies WHERE slug = ?");
    $stmt->execute([$slug]);
    $result['master_logo'] = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $result['error_master'] = $e->getMessage();
}

try {
    if ($tenantDb && $tenantPrefix) {
        $conn = Database::getTenantConn($tenantDb);
        $stmt = $conn->query("SELECT id, logo_path FROM {$tenantPrefix}business_profile WHERE id = 1");
        $result['business_profile'] = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $result['error_tenant'] = $e->getMessage();
}

echo json_encode($result, JSON_PRETTY_PRINT);
