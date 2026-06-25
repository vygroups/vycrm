<?php
// auth_check.php - Session & Tenant Enforcement
session_start();

if (!isset($_SESSION['token']) || time() > $_SESSION['expiry']) {
    $tenantSlug = preg_replace('/[^a-z0-9_-]/', '', strtolower($_SESSION['tenant_slug'] ?? $_COOKIE['vy_company_slug'] ?? ''));
    session_destroy();
    header('Location: ' . ($tenantSlug !== '' ? '/' . $tenantSlug : '/index.php'));
    exit;
}

// Ensure database config is loaded
require_once 'config/database.php';

// Set global tenant context for the page
$tenant_slug = $_SESSION['tenant_slug'];
$tenant_db = $_SESSION['tenant_db'];
$tenant_prefix = $_SESSION['tenant_prefix'];
$username = $_SESSION['username'];

// Get Tenant Connection
$tenantDb = Database::getTenantConn($tenant_db);
if (!$tenantDb) {
    die("Error: Could not establish tenant connection. DB: " . htmlspecialchars($tenant_db));
}

try {
    $stmt = $tenantDb->prepare("SELECT time_format, date_format, profile_picture, first_name, last_name, is_admin, role_id FROM {$tenant_prefix}users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_prefs = $stmt->fetch(PDO::FETCH_ASSOC);

    $_SESSION['time_format'] = $user_prefs['time_format'] ?? '12h';
    $_SESSION['date_format'] = $user_prefs['date_format'] ?? 'd M, Y';
    $_SESSION['profile_picture'] = $user_prefs['profile_picture'] ?? '';
    $_SESSION['first_name'] = $user_prefs['first_name'] ?? '';
    $_SESSION['last_name'] = $user_prefs['last_name'] ?? '';
    $_SESSION['is_admin'] = (int)($user_prefs['is_admin'] ?? 0);
    $_SESSION['role_id'] = $user_prefs['role_id'] ?? null;
} catch (Exception $e) {
    // If columns don't exist yet, ignore
}
?>