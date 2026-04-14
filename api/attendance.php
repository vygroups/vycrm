<?php
session_start();
// api/attendance.php - Reusable Attendance API for Web & Android
require_once '../config/database.php';

date_default_timezone_set('Asia/Kolkata');
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
            $stmt = $conn->prepare("SELECT * FROM {$prefix}attendance WHERE user_id = ? AND date = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$user_id, $today]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($record) {
                $is_punched_in = ($record['punch_in'] && !$record['punch_out']);
                $punch_in_ms = $record['punch_in'] ? (strtotime($record['punch_in']) * 1000) : null;
                echo json_encode([
                    'success' => true,
                    'is_punched_in' => $is_punched_in,
                    'type' => $record['type'] ?? 'shift',
                    'punch_in' => $record['punch_in'],
                    'punch_in_ms' => $punch_in_ms,
                    'server_time' => time() * 1000
                ]);
            } else {
                echo json_encode(['success' => true, 'is_punched_in' => false]);
            }
            break;

        case 'punch_in':
            // Check if ANY regular shift already exists for today
            $stmt = $conn->prepare("SELECT id FROM {$prefix}attendance WHERE user_id = ? AND date = ? AND type = 'shift' LIMIT 1");
            $stmt->execute([$user_id, $today]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'You have already punched in for today. Each day only one punch is allowed.']);
                exit;
            }

            $stmt = $conn->prepare("INSERT INTO {$prefix}attendance (user_id, date, punch_in, status, type) VALUES (?, ?, ?, 'Present', 'shift')");
            $stmt->execute([$user_id, $today, $now]);
            echo json_encode(['success' => true, 'message' => 'Punched in successfully', 'punch_in' => $now, 'punch_in_ms' => strtotime($now) * 1000]);
            break;

        case 'punch_out':
            $stmt = $conn->prepare("UPDATE {$prefix}attendance SET punch_out = ? WHERE user_id = ? AND date = ? AND punch_out IS NULL ORDER BY id DESC LIMIT 1");
            $stmt->execute([$now, $user_id, $today]);
            
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

        case 'history':
            $stmt = $conn->prepare("SELECT * FROM {$prefix}attendance WHERE user_id = ? AND date = ? ORDER BY punch_in ASC");
            $stmt->execute([$user_id, $today]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'report':
            $startDate = $_GET['start_date'] ?? date('Y-m-01');
            $endDate = $_GET['end_date'] ?? date('Y-m-d');
            $targetUser = (int) ($_GET['user_id'] ?? $user_id);
            
            $stmt = $conn->prepare("
                SELECT a.*, u.username 
                FROM {$prefix}attendance a
                JOIN users u ON u.id = a.user_id
                WHERE a.date BETWEEN ? AND ? " . ($_GET['user_id'] ? "AND a.user_id = ?" : "") . "
                ORDER BY a.date DESC, a.punch_in ASC
            ");
            
            $params = [$startDate, $endDate];
            if ($_GET['user_id']) $params[] = $targetUser;
            
            $stmt->execute($params);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'break_in':
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
