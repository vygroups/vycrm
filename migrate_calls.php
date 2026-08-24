<?php
// migrate_calls.php - Database migration for Calls Module & Storage Configs
require_once __DIR__ . '/config/database.php';

try {
    $masterDb = Database::getMasterConn();
    $masterPrefix = Database::getMasterPrefix();
    
    $dbsToProcess = [
        ['conn' => $masterDb, 'prefix' => $masterPrefix, 'name' => 'master']
    ];
    
    // Fetch all companies to process tenant DBs
    try {
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
    } catch (Exception $e) {
        echo "Could not load companies: " . $e->getMessage() . "\n";
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
            // 1. Calls Table
            $conn->exec("CREATE TABLE IF NOT EXISTS {$prefix}calls (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                user_name VARCHAR(150) NULL,
                contact_name VARCHAR(150) NULL,
                customer_id INT NULL,
                caller_number VARCHAR(50) NOT NULL,
                call_type ENUM('incoming', 'outgoing', 'missed', 'rejected', 'blocked', 'unknown') NOT NULL DEFAULT 'incoming',
                call_start_time DATETIME NOT NULL,
                call_end_time DATETIME NULL,
                duration INT NOT NULL DEFAULT 0,
                sim_slot VARCHAR(50) NULL,
                device_id VARCHAR(100) NULL,
                recording_file_url TEXT NULL,
                recording_storage_type VARCHAR(50) NOT NULL DEFAULT 'local',
                recording_file_id VARCHAR(255) NULL,
                recording_file_size INT NOT NULL DEFAULT 0,
                notes TEXT NULL,
                outcome VARCHAR(100) NULL,
                status VARCHAR(50) NOT NULL DEFAULT 'synced',
                raw_data JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_calls_number (caller_number),
                INDEX idx_calls_user (user_id),
                INDEX idx_calls_type (call_type),
                INDEX idx_calls_start (call_start_time),
                INDEX idx_calls_customer (customer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            // 2. Call Storage Configs Table (Google Drive, S3, R2, Local, etc.)
            $conn->exec("CREATE TABLE IF NOT EXISTS {$prefix}call_storage_configs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                provider ENUM('google_drive', 's3', 'cloudflare_r2', 'local', 'dropbox') NOT NULL DEFAULT 'local',
                config_name VARCHAR(150) NOT NULL,
                config_data JSON NULL,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            require_once __DIR__ . '/includes/calls_helper.php';
            calls_ensure_tables($conn, $prefix);
            $modId = calls_ensure_dynamic_module($conn, $prefix);

            // 3. Ensure system_settings has default calls_enabled and calls_visibility
            try {
                $conn->exec("INSERT IGNORE INTO {$prefix}system_settings (setting_key, setting_value) VALUES 
                    ('calls_enabled', '1'),
                    ('calls_visibility', 'all'),
                    ('calls_allow_bulk_import', '1'),
                    ('calls_default_storage', 'google_drive')");
            } catch (Exception $e) {
                // Ignore if already present
            }

            echo " - Calls schema & Dynamic Module (ID: {$modId}) migrated successfully.\n";
        } catch (PDOException $e) {
            echo " - Error: " . $e->getMessage() . "\n";
        }
    }
    
    echo "Calls migration completed successfully.\n";
    
} catch (Exception $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
