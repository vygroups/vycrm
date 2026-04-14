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
