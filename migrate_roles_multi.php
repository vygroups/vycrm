<?php
require_once 'auth_check.php';
require_once 'config/database.php';

$dbName = $_SESSION['tenant_db'];
$conn = Database::getTenantConn($dbName);
$prefix = $_SESSION['tenant_prefix'];

try {
    // 1. Create role_hierarchy table
    $conn->exec("CREATE TABLE IF NOT EXISTS {$prefix}role_hierarchy (
        id INT AUTO_INCREMENT PRIMARY KEY,
        parent_role_id INT NOT NULL,
        child_role_id INT NOT NULL,
        FOREIGN KEY (parent_role_id) REFERENCES {$prefix}roles(id) ON DELETE CASCADE,
        FOREIGN KEY (child_role_id) REFERENCES {$prefix}roles(id) ON DELETE CASCADE,
        UNIQUE KEY unique_link (parent_role_id, child_role_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 2. Migrate existing parent_id data (if column exists)
    try {
        $stmt = $conn->query("SELECT id, parent_id FROM {$prefix}roles WHERE parent_id IS NOT NULL");
        $existing = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($existing as $row) {
            $insert = $conn->prepare("INSERT IGNORE INTO {$prefix}role_hierarchy (parent_role_id, child_role_id) VALUES (?, ?)");
            $insert->execute([$row['parent_id'], $row['id']]);
        }
    } catch (Exception $e) { /* Column might be gone already */ }

    // 3. Remove parent_id column from roles table
    try {
        $conn->exec("ALTER TABLE {$prefix}roles DROP COLUMN parent_id");
    } catch (Exception $e) { /* Column might be gone already */ }

    echo "Migration successful for many-to-many roles!";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage();
}
?>
