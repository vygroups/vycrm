<?php
// auth_check.php - Session & Tenant Enforcement
session_start();

if (!isset($_SESSION['token']) || time() > $_SESSION['expiry']) {
    $tenantSlug = preg_replace('/[^a-z0-9_-]/', '', strtolower($_SESSION['tenant_slug'] ?? ''));
    session_destroy();
    header('Location: ' . ($tenantSlug !== '' ? '/login/' . $tenantSlug : '/index.php'));
    exit;
}

// Ensure database config is loaded
require_once 'config/database.php';

// Set global tenant context for the page
$tenant_slug   = $_SESSION['tenant_slug'];
$tenant_db     = $_SESSION['tenant_db'];
$tenant_prefix = $_SESSION['tenant_prefix'];
$username      = $_SESSION['username'];

// Get Tenant Connection
$tenantDb = Database::getTenantConn($tenant_db);
if (!$tenantDb) {
    die("Error: Could not establish tenant connection.");
}
?>
