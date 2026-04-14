<?php
require_once '../auth_check.php';
require_once '../config/database.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$dbName = $_SESSION['tenant_db'];
$conn = Database::getTenantConn($dbName);
$prefix = $_SESSION['tenant_prefix'];

try {
    if ($action == 'get_roles') {
        // Fetch All Roles
        $stmt = $conn->query("SELECT * FROM {$prefix}roles ORDER BY id ASC");
        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch Hierarchy / Links
        $stmtLinks = $conn->query("SELECT * FROM {$prefix}role_hierarchy");
        $links = $stmtLinks->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'roles' => $roles, 'links' => $links]);
    } 
    elseif ($action == 'add_role') {
        $name = $_POST['name'] ?? 'New Role';
        $pid = $_POST['parent_id'] ?? null;
        if ($pid === '') $pid = null;

        $stmt = $conn->prepare("INSERT INTO {$prefix}roles (name) VALUES (?)");
        $stmt->execute([$name]);
        $newId = $conn->lastInsertId();

        if ($pid) {
            $stmtLink = $conn->prepare("INSERT INTO {$prefix}role_hierarchy (parent_role_id, child_role_id) VALUES (?, ?)");
            $stmtLink->execute([$pid, $newId]);
        }
        echo json_encode(['success' => true]);
    } 
    elseif ($action == 'link_parent') {
        $childId = $_POST['child_id'] ?? 0;
        $parentId = $_POST['parent_id'] ?? 0;
        $stmt = $conn->prepare("INSERT IGNORE INTO {$prefix}role_hierarchy (parent_role_id, child_role_id) VALUES (?, ?)");
        $stmt->execute([$parentId, $childId]);
        echo json_encode(['success' => true]);
    }
    elseif ($action == 'unlink_parent') {
        $childId = $_POST['child_id'] ?? 0;
        $parentId = $_POST['parent_id'] ?? 0;
        $stmt = $conn->prepare("DELETE FROM {$prefix}role_hierarchy WHERE parent_role_id = ? AND child_role_id = ?");
        $stmt->execute([$parentId, $childId]);
        echo json_encode(['success' => true]);
    }
    elseif ($action == 'rename_role') {
        $id = $_POST['id'] ?? 0;
        $name = $_POST['name'] ?? '';
        $stmt = $conn->prepare("UPDATE {$prefix}roles SET name = ? WHERE id = ?");
        $stmt->execute([$name, $id]);
        echo json_encode(['success' => true]);
    }
    elseif ($action == 'delete_role') {
        $id = $_POST['id'] ?? 0;
        $conn->prepare("DELETE FROM {$prefix}roles WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
