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

        // 3. Add cc_email to employee_details
        try {
            $conn->exec("ALTER TABLE {$tenantPrefix}employee_details ADD COLUMN cc_email VARCHAR(255) DEFAULT NULL AFTER pan_number");
            echo "  [SUCCESS] Added cc_email to employee_details\n";
        } catch (Exception $e) {
            echo "  [INFO] employee_details.cc_email process: " . $e->getMessage() . "\n";
        }

        // 4. Update salaries for dynamic records
        try {
            $conn->exec("ALTER TABLE {$tenantPrefix}salaries MODIFY user_id INT DEFAULT NULL");
            $conn->exec("ALTER TABLE {$tenantPrefix}salaries ADD COLUMN record_id INT DEFAULT NULL AFTER user_id");
            $conn->exec("ALTER TABLE {$tenantPrefix}salaries ADD UNIQUE KEY uk_record_month (record_id, salary_month)");
            echo "  [SUCCESS] Added record_id to salaries\n";
        } catch (Exception $e) {
            echo "  [INFO] salaries.record_id process: " . $e->getMessage() . "\n";
        }

        // 3. Ensure default system fields in Calls Module
        try {
            $mStmt = $conn->prepare("SELECT id FROM {$tenantPrefix}modules WHERE slug = 'calls_module' LIMIT 1");
            $mStmt->execute();
            $moduleId = $mStmt->fetchColumn();
            if ($moduleId) {
                $bStmt = $conn->prepare("SELECT id FROM {$tenantPrefix}module_blocks WHERE module_id = ? ORDER BY sort_order LIMIT 1");
                $bStmt->execute([$moduleId]);
                $blockId = $bStmt->fetchColumn();
                if ($blockId) {
                    $sysFields = [
                        ['Created By', 'sys_created_by', 'created_by_sys', 1, 9],
                        ['Created On', 'sys_created_at', 'created_at_sys', 1, 10],
                        ['Updated By', 'sys_updated_by', 'updated_by_sys', 0, 11],
                        ['Updated On', 'sys_updated_at', 'updated_at_sys', 0, 12],
                    ];
                    
                    $chkField = $conn->prepare("SELECT id FROM {$tenantPrefix}module_fields WHERE module_id = ? AND field_key = ?");
                    $insField = $conn->prepare("INSERT INTO {$tenantPrefix}module_fields (block_id, module_id, label, field_type, field_key, is_list_visible, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    
                    foreach ($sysFields as $f) {
                        $chkField->execute([$moduleId, $f[2]]);
                        if (!$chkField->fetchColumn()) {
                            $insField->execute([$blockId, $moduleId, $f[0], $f[1], $f[2], $f[3], $f[4]]);
                            echo "  [SUCCESS] Added system field '{$f[0]}' to Calls Module (ID: $moduleId)\n";
                        }
                    }
                }
            }
        } catch (Exception $e) {
            echo "  [INFO] Calls Module system fields process: " . $e->getMessage() . "\n";
        }
    }
    echo "\nMigration process completed.\n";
} catch (Exception $e) {
    die("Migration Global Error: " . $e->getMessage());
}
?>
