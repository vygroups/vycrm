<?php
require_once 'auth_check.php';
require_once 'config/database.php';

$dbName = $_SESSION['tenant_db'];
$conn = Database::getTenantConn($dbName);
$prefix = $_SESSION['tenant_prefix'];

try {
    // 1. Add roles table
    $conn->exec("CREATE TABLE IF NOT EXISTS {$prefix}roles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        parent_id INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (parent_id) REFERENCES {$prefix}roles(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 2. Add role_id to users table (if not exists)
    try {
        $conn->exec("ALTER TABLE {$prefix}users ADD COLUMN IF NOT EXISTS role_id INT DEFAULT NULL");
    } catch (Exception $e) { /* Column might already exist */ }

    // 3. Add foreign key (if not exists)
    try {
        $conn->exec("ALTER TABLE {$prefix}users ADD CONSTRAINT fk_user_role FOREIGN KEY (role_id) REFERENCES {$prefix}roles(id) ON DELETE SET NULL");
    } catch (Exception $e) { /* Constraint might already exist */ }

    // 4. Populate default roles if empty
    $stmt = $conn->query("SELECT COUNT(*) FROM {$prefix}roles");
    if ($stmt->fetchColumn() == 0) {
        $conn->exec("INSERT INTO {$prefix}roles (name) VALUES ('Administrator'), ('Manager'), ('TL'), ('Developer')");
    }

    echo "Migration successful for $dbName!";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage();
}
?>
