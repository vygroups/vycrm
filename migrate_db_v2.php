<?php
// migrate_db.php
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
        
        // Determine prefix based on isolation logic from login API
        $isIsolated = ($dbName != $masterDB);
        $tenantPrefix = $isIsolated ? "" : $slug . "_";
        
        echo "Migrating tenant DB: $dbName (Prefix: '$tenantPrefix')\n";
        
        $conn = Database::getTenantConn($dbName);
        if (!$conn) {
            echo "  [SKIPPED] Could not connect to $dbName\n";
            continue;
        }

        // 1. Add assigned_to to enquiries
        try {
            // Using IF NOT EXISTS via subquery or just catching error
            $conn->exec("ALTER TABLE {$tenantPrefix}enquiries ADD COLUMN assigned_to INT DEFAULT NULL AFTER student_name");
            $conn->exec("ALTER TABLE {$tenantPrefix}enquiries ADD FOREIGN KEY (assigned_to) REFERENCES {$tenantPrefix}users(id) ON DELETE SET NULL");
            echo "  [SUCCESS] Added assigned_to to enquiries\n";
        } catch (Exception $e) {
            echo "  [INFO] enquiries.assigned_to process: " . $e->getMessage() . "\n";
        }

        // 2. Add type to attendance
        try {
            $conn->exec("ALTER TABLE {$tenantPrefix}attendance ADD COLUMN type ENUM('shift', 'break') DEFAULT 'shift' AFTER user_id");
            echo "  [SUCCESS] Added type to attendance\n";
        } catch (Exception $e) {
            echo "  [INFO] attendance.type process: " . $e->getMessage() . "\n";
        }
    }
    echo "\nMigration process completed.\n";
} catch (Exception $e) {
    die("Migration Global Error: " . $e->getMessage());
}
?>
