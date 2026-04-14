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
            // Admin/Manager check should be here
            $leave_id = $_POST['id'];
            $status = $_POST['status']; // 'approved' or 'rejected'

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
