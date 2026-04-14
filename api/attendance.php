<?php
session_start();
// api/attendance.php - Reusable Attendance API for Web & Android
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

$action = $_GET['action'] ?? $_POST['action'] ?? 'status';
$today = date('Y-m-d');
$now = date('Y-m-d H:i:s');

try {
    switch ($action) {
        case 'status':
            // Get today's active attendance
            $stmt = $conn->prepare("SELECT * FROM {$prefix}attendance WHERE user_id = ? AND date = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$user_id, $today]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($record) {
                $is_punched_in = ($record['punch_in'] && !$record['punch_out']);
                echo json_encode([
                    'success' => true,
                    'is_punched_in' => $is_punched_in,
                    'type' => $record['type'] ?? 'shift',
                    'punch_in' => $record['punch_in'],
                    'server_time' => time() * 1000 // ms for JS
                ]);
            } else {
                echo json_encode(['success' => true, 'is_punched_in' => false]);
            }
            break;

        case 'punch_in':
            // Check if already punched in
            $stmt = $conn->prepare("SELECT id FROM {$prefix}attendance WHERE user_id = ? AND date = ? AND punch_out IS NULL LIMIT 1");
            $stmt->execute([$user_id, $today]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Already punched in']);
                exit;
            }

            $stmt = $conn->prepare("INSERT INTO {$prefix}attendance (user_id, date, punch_in, status, type) VALUES (?, ?, ?, 'Present', 'shift')");
            $stmt->execute([$user_id, $today, $now]);
            echo json_encode(['success' => true, 'message' => 'Punched in successfully', 'punch_in' => $now]);
            break;

        case 'punch_out':
            $stmt = $conn->prepare("UPDATE {$prefix}attendance SET punch_out = ? WHERE user_id = ? AND date = ? AND punch_out IS NULL ORDER BY id DESC LIMIT 1");
            $stmt->execute([$now, $user_id, $today]);
            
            // Calculate total hours
            $stmt = $conn->prepare("SELECT punch_in, punch_out FROM {$prefix}attendance WHERE user_id = ? AND date = ? AND punch_out IS NOT NULL ORDER BY id DESC LIMIT 1");
            $stmt->execute([$user_id, $today]);
            $row = $stmt->fetch();
            if ($row) {
                $start = new DateTime($row['punch_in']);
                $end = new DateTime($row['punch_out']);
                $interval = $start->diff($end);
                $total_hours = $interval->format('%h hrs %i mins');
                
                $stmt = $conn->prepare("UPDATE {$prefix}attendance SET total_hours = ? WHERE user_id = ? AND date = ? ORDER BY id DESC LIMIT 1");
                $stmt->execute([$total_hours, $user_id, $today]);
            }

            echo json_encode(['success' => true, 'message' => 'Punched out successfully', 'total_hours' => $total_hours ?? '']);
            break;

        case 'break_in':
            // End the current shift record and start a break record
            $conn->prepare("UPDATE {$prefix}attendance SET punch_out = ? WHERE user_id = ? AND date = ? AND punch_out IS NULL AND type='shift'")->execute([$now, $user_id, $today]);
            $conn->prepare("INSERT INTO {$prefix}attendance (user_id, date, punch_in, status, type) VALUES (?, ?, ?, 'Break', 'break')")->execute([$user_id, $today, $now]);
            echo json_encode(['success' => true, 'message' => 'Break started']);
            break;

        case 'break_out':
            $conn->prepare("UPDATE {$prefix}attendance SET punch_out = ? WHERE user_id = ? AND date = ? AND punch_out IS NULL AND type='break'")->execute([$now, $user_id, $today]);
            $conn->prepare("INSERT INTO {$prefix}attendance (user_id, date, punch_in, status, type) VALUES (?, ?, ?, 'Present', 'shift')")->execute([$user_id, $today, $now]);
            echo json_encode(['success' => true, 'message' => 'Break ended, shift resumed']);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
