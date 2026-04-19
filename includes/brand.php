<?php
/**
 * includes/brand.php - Centralized Company Branding
 * 
 * Include this AFTER auth_check.php and config/database.php to set:
 *   $companyLogo  - Full path to the company logo image
 *   $companyName  - Company display name
 *   $companySlug  - Company slug (for folder paths, etc.)
 *   $companyData  - Full company record from master DB
 * 
 * Logo priority: tenant business_profile → master company logo → default
 * This replaces the repeated try/catch blocks in every page.
 */

$companyLogo = "/images/logo.png";
$companyName = "Vy CRM";
$companySlug = $_SESSION['tenant_slug'] ?? '';
$companyData = null;

try {
    $brandDb = Database::getMasterConn();
    $brandPrefix = Database::getMasterPrefix();
    $brandStmt = $brandDb->prepare("SELECT * FROM {$brandPrefix}companies WHERE slug = ?");
    $brandStmt->execute([$companySlug]);
    $companyData = $brandStmt->fetch(PDO::FETCH_ASSOC);
    if ($companyData && $companyData['logo']) {
        $companyLogo = '/' . $companyData['logo'];
        $companyName = htmlspecialchars($companyData['name']);
    } elseif ($companyData) {
        $companyName = htmlspecialchars($companyData['name']);
    }
} catch (Throwable $e) {
    // Fail silently — defaults will be used
}

// Override with tenant's own logo from business_profile (uploaded via Settings > Business Profile)
// This takes highest priority so the user-uploaded logo is shown everywhere
try {
    $tenantDb = $_SESSION['tenant_db'] ?? '';
    $tenantPrefix = $_SESSION['tenant_prefix'] ?? '';
    if ($tenantDb && $tenantPrefix) {
        $bpConn = Database::getTenantConn($tenantDb);
        $bpStmt = $bpConn->query("SELECT logo_path FROM {$tenantPrefix}business_profile WHERE id = 1");
        $bpRow = $bpStmt->fetch(PDO::FETCH_ASSOC);
        if ($bpRow && !empty($bpRow['logo_path'])) {
            $companyLogo = '/' . ltrim($bpRow['logo_path'], '/');
        }
    }
} catch (Throwable $e) {
    // Fail silently — keep whatever logo was already resolved
}

$v = time();
