<?php
session_start();
// api/leaves.php - Leave request and management API
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
            $leave_type = $_POST['leave_type'];
            $from_date = $_POST['from_date'];
            $to_date = $_POST['to_date'];
            $reason = $_POST['reason'];

            $stmt = $conn->prepare("INSERT INTO {$prefix}leaves (user_id, leave_type, from_date, to_date, reason, status) VALUES (?, ?, ?, ?, ?, 'pending')");
            $stmt->execute([$user_id, $leave_type, $from_date, $to_date, $reason]);

            echo json_encode(['success' => true, 'message' => 'Leave applied successfully']);
        } elseif ($action == 'update_status') {
            $leave_id = $_POST['id'];
            $status = $_POST['status']; // 'approved' or 'rejected'

            // Admin/Manager check
            $stmt = $conn->prepare("SELECT user_id FROM {$prefix}leaves WHERE id = ?");
            $stmt->execute([$leave_id]);
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

            $stmt = $conn->prepare("UPDATE {$prefix}leaves SET status = ? WHERE id = ?");
            $stmt->execute([$status, $leave_id]);

            echo json_encode(['success' => true, 'message' => 'Leave status updated']);
        }
    } else {
        // GET leaves for the user
        $stmt = $conn->prepare("SELECT * FROM {$prefix}leaves WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->execute([$user_id]);
        $leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $leaves]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>