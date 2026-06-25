<?php
require_once __DIR__ . '/config/database.php';

try {
    $masterDb = Database::getMasterConn();
    $masterPrefix = Database::getMasterPrefix();
    
    // Process master db first if isolated schemas aren't strictly isolated
    $dbsToProcess = [
        ['conn' => $masterDb, 'prefix' => $masterPrefix, 'name' => 'master']
    ];
    
    // Fetch all companies to process tenant DBs
    $stmt = $masterDb->query("SELECT slug, db_name FROM {$masterPrefix}companies");
    $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($companies as $c) {
        try {
            $tConn = Database::getTenantConn($c['db_name']);
            if ($tConn) {
                $isIsolated = ($c['db_name'] != Database::getMasterDBName());
                $tPrefix = $isIsolated ? "" : $c['slug'] . "_";
                $dbsToProcess[] = ['conn' => $tConn, 'prefix' => $tPrefix, 'name' => $c['slug']];
            }
        } catch (Exception $e) {
            echo "Skipping tenant {$c['slug']}: " . $e->getMessage() . "\n";
        }
    }
    
    // Track processed db+prefix combinations to avoid duplicates in shared DB
    $processed = [];
    
    foreach ($dbsToProcess as $dbInfo) {
        $conn = $dbInfo['conn'];
        $prefix = $dbInfo['prefix'];
        $key = spl_object_hash($conn) . '_' . $prefix;
        
        if (in_array($key, $processed)) {
            continue;
        }
        $processed[] = $key;
        
        echo "Updating schema for: {$dbInfo['name']} (Prefix: '{$prefix}')\n";
        
        try {
            // Check if edit_rule exists
            $stmt = $conn->query("SHOW COLUMNS FROM {$prefix}modules LIKE 'edit_rule'");
            if ($stmt->rowCount() == 0) {
                $conn->exec("ALTER TABLE {$prefix}modules ADD COLUMN edit_rule VARCHAR(50) DEFAULT 'all'");
                $conn->exec("ALTER TABLE {$prefix}modules ADD COLUMN edit_roles TEXT NULL");
                $conn->exec("ALTER TABLE {$prefix}modules ADD COLUMN delete_rule VARCHAR(50) DEFAULT 'all'");
                $conn->exec("ALTER TABLE {$prefix}modules ADD COLUMN delete_roles TEXT NULL");
                echo " - Added granular permission columns to {$prefix}modules\n";
                
                // Update visibility_rule ENUM to support specific_roles
                // Current ENUM is: ENUM('all','owner','role_down','role_equal_down','role_up')
                // Wait, do we need specific_roles for visibility too? The user didn't ask for it, but let's stick to edit/delete first.
            } else {
                echo " - Columns already exist in {$prefix}modules\n";
            }
        } catch (PDOException $e) {
            echo " - Error updating {$prefix}modules: " . $e->getMessage() . "\n";
        }
    }
    
    echo "Migration completed successfully.\n";
    
} catch (Exception $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
