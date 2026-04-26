<?php
session_start();
// api/permissions.php - Short-term permission request API
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$dbName = $_SESSION['tenant_db'];
$prefix = $_SESSION['tenant_prefix'];
$conn = Database::getTenantConn($dbName);

if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    if ($method == 'POST') {
        if ($action == 'apply') {
            $date = $_POST['date'];
            $time_window = $_POST['time_window'];
            $duration = $_POST['duration'];
            $reason = $_POST['reason'];

            $stmt = $conn->prepare("INSERT INTO {$prefix}permissions (user_id, date, time_window, duration, reason, status) VALUES (?, ?, ?, ?, ?, 'pending')");
            $stmt->execute([$user_id, $date, $time_window, $duration, $reason]);
            
            echo json_encode(['success' => true, 'message' => 'Permission applied successfully']);
        } elseif ($action == 'update_status') {
            $perm_id = $_POST['id'];
            $status = $_POST['status'];

            // Admin/Manager check
            $stmt = $conn->prepare("SELECT user_id FROM {$prefix}permissions WHERE id = ?");
            $stmt->execute([$perm_id]);
            $req_user_id = $stmt->fetchColumn();

            if ($req_user_id == $user_id) {
                echo json_encode(['success' => false, 'message' => 'You cannot approve your own request']);
                exit;
            }

            $stmt = $conn->prepare("SELECT role_id FROM {$prefix}users WHERE id = ?");
            $stmt->execute([$user_id]);
            $my_role_id = $stmt->fetchColumn();

            $stmt = $conn->prepare("SELECT role_id FROM {$prefix}users WHERE id = ?");
            $stmt->execute([$req_user_id]);
            $req_role_id = $stmt->fetchColumn();

            $is_admin = false;
            if (!$my_role_id) {
                $is_admin = true;
            } else {
                $stmt = $conn->prepare("SELECT name FROM {$prefix}roles WHERE id = ?");
                $stmt->execute([$my_role_id]);
                $role_name = strtolower($stmt->fetchColumn() ?: '');
                if (strpos($role_name, 'admin') !== false || strpos($role_name, 'manager') !== false) {
                    $is_admin = true;
                }
            }

            if (!$is_admin && $my_role_id && $req_role_id) {
                $stmt = $conn->prepare("SELECT id FROM {$prefix}role_hierarchy WHERE parent_role_id = ? AND child_role_id = ?");
                $stmt->execute([$my_role_id, $req_role_id]);
                if (!$stmt->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'You do not have permission to approve this request']);
                    exit;
                }
            }

            $stmt = $conn->prepare("UPDATE {$prefix}permissions SET status = ? WHERE id = ?");
            $stmt->execute([$status, $perm_id]);
            
            echo json_encode(['success' => true, 'message' => 'Permission status updated']);
        }
    } else {
        // GET
        $stmt = $conn->prepare("SELECT * FROM {$prefix}permissions WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->execute([$user_id]);
        $perms = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $perms]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
