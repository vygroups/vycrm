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
            $conn->exec("CREATE TABLE IF NOT EXISTS {$prefix}communication_configs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                type ENUM('smtp', 'whatsapp') NOT NULL,
                config_data JSON,
                is_default TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            
            // Add communication_config_id to campaigns
            try {
                $conn->exec("ALTER TABLE {$prefix}campaigns ADD COLUMN communication_config_id INT NULL AFTER template_id");
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate column name') === false) { throw $e; }
            }

            // Add communication_config_id to module_workflows
            try {
                $conn->exec("ALTER TABLE {$prefix}module_workflows ADD COLUMN communication_config_id INT NULL AFTER template_body");
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate column name') === false) { throw $e; }
            }
            
            echo " - Migrated successfully.\n";
        } catch (PDOException $e) {
            echo " - Error: " . $e->getMessage() . "\n";
        }
    }
    
    echo "Migration completed successfully.\n";
    
} catch (Exception $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
