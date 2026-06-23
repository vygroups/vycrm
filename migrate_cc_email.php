<?php
// migrate_cc_email.php
require_once 'config/database.php';

try {
    $masterConn = Database::getMasterConn();
    $prefix = Database::getMasterPrefix();
    $masterDB = Database::getMasterDBName();
    
    // Get all companies
    $stmt = $masterConn->query("SELECT db_name, slug FROM {$prefix}companies");
    $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($tenants as $tenant) {
        $dbName = $tenant['db_name'];
        $slug = $tenant['slug'];
        
        // Determine prefix based on isolation logic
        $isIsolated = ($dbName != $masterDB);
        $tenantPrefix = $isIsolated ? "" : $slug . "_";
        
        echo "Migrating tenant DB: $dbName (Prefix: '$tenantPrefix')\n";
        
        $conn = Database::getTenantConn($dbName);
        if (!$conn) {
            echo "  [SKIPPED] Could not connect to $dbName\n";
            continue;
        }

        try {
            $conn->exec("ALTER TABLE {$tenantPrefix}employee_details ADD COLUMN cc_email VARCHAR(255) DEFAULT NULL AFTER pan_number");
            echo "  [SUCCESS] Added cc_email\n";
        } catch (Exception $e) {
            echo "  [INFO] " . $e->getMessage() . "\n";
        }
    }
    echo "\nMigration completed.\n";
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>
