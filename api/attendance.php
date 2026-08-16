<?php
session_start();
// api/attendance.php - Reusable Attendance API for Web & Android
require_once '../config/database.php';

date_default_timezone_set('Asia/Kolkata');
header('Content-Type: application/json');

require_once '../includes/api_auth.php';
require_once '../includes/dynamic_modules.php';

try {
    $context = api_require_context();
    $user_id = $context['user_id'];
    $dbName = $context['db_name'];
    $prefix = $context['prefix'];
    $conn = $context['conn'];
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized: ' . $e->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'status';
$today = date('Y-m-d');
$now = date('Y-m-d H:i:s');

try {
    // Auto-migrate new columns if they don't exist
    try {
        $conn->exec("ALTER TABLE {$prefix}attendance ADD COLUMN break_history TEXT DEFAULT '[]'");
        $conn->exec("ALTER TABLE {$prefix}attendance ADD COLUMN total_break_hours VARCHAR(50) DEFAULT NULL");
    } catch (PDOException $e) {
        // Columns already exist
    }

    switch ($action) {
        case 'status':
            $stmt = $conn->prepare("SELECT * FROM {$prefix}attendance WHERE user_id = ? AND date = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$user_id, $today]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($record) {
                $is_punched_in = ($record['punch_in'] && !$record['punch_out']);
                $punch_in_ms = $record['punch_in'] ? (strtotime($record['punch_in']) * 1000) : null;
                $is_on_break = ($record['status'] === 'Break');

                $break_in_ms = null;
                if ($is_on_break) {
                    $history = json_decode($record['break_history'] ?: '[]', true);
                    if (count($history) > 0) {
                        $last = end($history);
                        if (!empty($last['start']) && empty($last['end'])) {
                            $break_in_ms = strtotime($last['start']) * 1000;
                        }
                    }
                }

                // Check user visibility scope for Team View picker
                $userQuery = $conn->prepare("SELECT role_id, is_admin FROM {$prefix}users WHERE id = ?");
                $userQuery->execute([$user_id]);
                $userData = $userQuery->fetch(PDO::FETCH_ASSOC);
                $my_role_id = $userData ? $userData['role_id'] : null;
                $isAdmin = $userData ? (int)$userData['is_admin'] : 0;
                
                $rule = dm_get_system_setting($conn, $prefix, 'attendance_visibility', 'all');
                $allowedUserIds = dm_get_visible_user_ids($conn, $prefix, (int)$user_id, $my_role_id ? (int)$my_role_id : null, $rule, (bool)$isAdmin);
                
                $visibleUsers = [];
                if ($isAdmin || $rule === 'all') {
                    $uStmt = $conn->query("SELECT u.id, u.username, u.first_name, u.last_name, r.name as role_name FROM {$prefix}users u LEFT JOIN {$prefix}roles r ON r.id = u.role_id ORDER BY u.username ASC");
                    $visibleUsers = $uStmt->fetchAll(PDO::FETCH_ASSOC);
                } else if ($allowedUserIds !== null && !empty($allowedUserIds)) {
                    $inClause = implode(',', array_map('intval', $allowedUserIds));
                    $uStmt = $conn->query("SELECT u.id, u.username, u.first_name, u.last_name, r.name as role_name FROM {$prefix}users u LEFT JOIN {$prefix}roles r ON r.id = u.role_id WHERE u.id IN ($inClause) ORDER BY u.username ASC");
                    $visibleUsers = $uStmt->fetchAll(PDO::FETCH_ASSOC);
                }

                echo json_encode([
                    'success' => true,
                    'is_punched_in' => $is_punched_in,
                    'is_on_break' => $is_on_break,
                    'type' => $record['type'] ?? 'shift',
                    'punch_in' => $record['punch_in'],
                    'punch_out' => $record['punch_out'],
                    'total_hours' => $record['total_hours'],
                    'total_break_hours' => $record['total_break_hours'],
                    'break_history' => json_decode($record['break_history'] ?: '[]', true),
                    'punch_in_ms' => $punch_in_ms,
                    'break_in_ms' => $break_in_ms,
                    'visible_users' => $visibleUsers,
                    'can_view_team' => count($visibleUsers) > 1,
                    'server_time' => time() * 1000
                ]);
            } else {
                $userQuery = $conn->prepare("SELECT role_id, is_admin FROM {$prefix}users WHERE id = ?");
                $userQuery->execute([$user_id]);
                $userData = $userQuery->fetch(PDO::FETCH_ASSOC);
                $my_role_id = $userData ? $userData['role_id'] : null;
                $isAdmin = $userData ? (int)$userData['is_admin'] : 0;
                
                $rule = dm_get_system_setting($conn, $prefix, 'attendance_visibility', 'all');
                $allowedUserIds = dm_get_visible_user_ids($conn, $prefix, (int)$user_id, $my_role_id ? (int)$my_role_id : null, $rule, (bool)$isAdmin);
                
                $visibleUsers = [];
                if ($isAdmin || $rule === 'all') {
                    $uStmt = $conn->query("SELECT u.id, u.username, u.first_name, u.last_name, r.name as role_name FROM {$prefix}users u LEFT JOIN {$prefix}roles r ON r.id = u.role_id ORDER BY u.username ASC");
                    $visibleUsers = $uStmt->fetchAll(PDO::FETCH_ASSOC);
                } else if ($allowedUserIds !== null && !empty($allowedUserIds)) {
                    $inClause = implode(',', array_map('intval', $allowedUserIds));
                    $uStmt = $conn->query("SELECT u.id, u.username, u.first_name, u.last_name, r.name as role_name FROM {$prefix}users u LEFT JOIN {$prefix}roles r ON r.id = u.role_id WHERE u.id IN ($inClause) ORDER BY u.username ASC");
                    $visibleUsers = $uStmt->fetchAll(PDO::FETCH_ASSOC);
                }

                echo json_encode([
                    'success' => true, 
                    'is_punched_in' => false, 
                    'is_on_break' => false,
                    'visible_users' => $visibleUsers,
                    'can_view_team' => count($visibleUsers) > 1
                ]);
            }
            break;

        case 'punch_in':
            // Check if ANY regular shift already exists for today
            $stmt = $conn->prepare("SELECT id FROM {$prefix}attendance WHERE user_id = ? AND date = ? LIMIT 1");
            $stmt->execute([$user_id, $today]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'You have already punched in for today. Each day only 1 punch in is allowed.']);
                exit;
            }

            $stmt = $conn->prepare("INSERT INTO {$prefix}attendance (user_id, date, punch_in, status, type, break_history) VALUES (?, ?, ?, 'Present', 'shift', '[]')");
            $stmt->execute([$user_id, $today, $now]);
            echo json_encode(['success' => true, 'message' => 'Punched in successfully', 'punch_in' => $now, 'punch_in_ms' => strtotime($now) * 1000]);
            break;

        case 'punch_out':
            $stmt = $conn->prepare("SELECT * FROM {$prefix}attendance WHERE user_id = ? AND date = ? AND punch_out IS NULL ORDER BY id DESC LIMIT 1");
            $stmt->execute([$user_id, $today]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$record) {
                 echo json_encode(['success' => false, 'message' => 'Already punched out or not punched in']);
                 exit;
            }
            
            $history = json_decode($record['break_history'] ?: '[]', true);
            $total_break_seconds = 0;
            
            if ($record['status'] === 'Break' && count($history) > 0) {
                $last_idx = count($history) - 1;
                if (!isset($history[$last_idx]['end']) || !$history[$last_idx]['end']) {
                    $history[$last_idx]['end'] = $now;
                }
            }
            
            foreach ($history as $b) {
                if (!empty($b['start']) && !empty($b['end'])) {
                    $total_break_seconds += strtotime($b['end']) - strtotime($b['start']);
                }
            }
            
            $history_json = json_encode($history);
            $bh = floor($total_break_seconds / 3600);
            $bm = floor(($total_break_seconds % 3600) / 60);
            $total_break_hours_str = ($bh > 0 || $bm > 0) ? ($bh . ' hrs ' . $bm . ' mins') : '';
            
            $start = new DateTime($record['punch_in']);
            $end = new DateTime($now);
            $interval = $start->diff($end);
            $total_hours = $interval->format('%h hrs %i mins');
            
            $stmt = $conn->prepare("UPDATE {$prefix}attendance SET punch_out = ?, status = 'Present', total_hours = ?, break_history = ?, total_break_hours = ? WHERE id = ?");
            $stmt->execute([$now, $total_hours, $history_json, $total_break_hours_str, $record['id']]);

            echo json_encode(['success' => true, 'message' => 'Punched out successfully', 'total_hours' => $total_hours]);
            break;

        case 'history':
            $targetUser = isset($_GET['user_id']) ? $_GET['user_id'] : $user_id;
            $startDate = $_GET['start_date'] ?? null;
            $endDate = $_GET['end_date'] ?? null;

            // Fetch logged-in user details from db
            $userQuery = $conn->prepare("SELECT role_id, is_admin FROM {$prefix}users WHERE id = ?");
            $userQuery->execute([$user_id]);
            $userData = $userQuery->fetch(PDO::FETCH_ASSOC);
            $my_role_id = $userData ? $userData['role_id'] : null;
            $isAdmin = $userData ? (int)$userData['is_admin'] : 0;

            $rule = dm_get_system_setting($conn, $prefix, 'attendance_visibility', 'all');
            $allowedUserIds = dm_get_visible_user_ids($conn, $prefix, (int)$user_id, $my_role_id ? (int)$my_role_id : null, $rule, $isAdmin);

            $dateClause = "";
            $dateParams = [];
            if (!empty($startDate) && !empty($endDate)) {
                $dateClause = " AND a.date BETWEEN ? AND ?";
                $dateParams = [$startDate, $endDate];
            }

            if ($targetUser === 'all') {
                if ($allowedUserIds !== null) {
                    if (empty($allowedUserIds)) {
                        echo json_encode(['success' => true, 'data' => []]);
                        exit;
                    }
                    $inClause = implode(',', array_map('intval', $allowedUserIds));
                    $stmt = $conn->prepare("SELECT a.*, u.username, u.first_name, u.last_name FROM {$prefix}attendance a JOIN {$prefix}users u ON a.user_id = u.id WHERE a.user_id IN ($inClause) {$dateClause} ORDER BY a.date DESC LIMIT 100");
                    $stmt->execute($dateParams);
                } else {
                    $stmt = $conn->prepare("SELECT a.*, u.username, u.first_name, u.last_name FROM {$prefix}attendance a JOIN {$prefix}users u ON a.user_id = u.id WHERE 1=1 {$dateClause} ORDER BY a.date DESC LIMIT 100");
                    $stmt->execute($dateParams);
                }
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            } else {
                $targetUser = (int)$targetUser;
                if ($targetUser !== (int)$user_id) {
                    if ($allowedUserIds !== null && !in_array($targetUser, $allowedUserIds)) {
                        echo json_encode(['success' => true, 'data' => []]);
                        exit;
                    }
                }
                
                $params = array_merge([$targetUser], $dateParams);
                $stmt = $conn->prepare("SELECT a.*, u.username, u.first_name, u.last_name FROM {$prefix}attendance a JOIN {$prefix}users u ON a.user_id = u.id WHERE a.user_id = ? {$dateClause} ORDER BY a.date DESC LIMIT 100");
                $stmt->execute($params);
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            }
            break;

        case 'report':
            $startDate = $_GET['start_date'] ?? date('Y-m-01');
            $endDate = $_GET['end_date'] ?? date('Y-m-d');
            
            $rule = dm_get_system_setting($conn, $prefix, 'attendance_visibility', 'all');
            $isAdmin = !empty($_SESSION['is_admin']);
            $allowedUserIds = dm_get_visible_user_ids($conn, $prefix, (int)$user_id, isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : null, $rule, $isAdmin);

            $whereClauses = ["a.date BETWEEN ? AND ?"];
            $params = [$startDate, $endDate];

            if ($allowedUserIds !== null) {
                if (isset($_GET['user_id']) && $_GET['user_id'] !== '') {
                    $requestedUid = (int)$_GET['user_id'];
                    if (in_array($requestedUid, $allowedUserIds)) {
                        $whereClauses[] = "a.user_id = ?";
                        $params[] = $requestedUid;
                    } else {
                        echo json_encode(['success' => true, 'data' => []]);
                        exit;
                    }
                } else {
                    $whereClauses[] = "a.user_id IN (" . implode(',', array_map('intval', $allowedUserIds ?: [0])) . ")";
                }
            } else {
                if (isset($_GET['user_id']) && $_GET['user_id'] !== '') {
                    $whereClauses[] = "a.user_id = ?";
                    $params[] = (int)$_GET['user_id'];
                }
            }

            $stmt = $conn->prepare("
                SELECT a.*, u.username 
                FROM {$prefix}attendance a
                JOIN {$prefix}users u ON u.id = a.user_id
                WHERE " . implode(' AND ', $whereClauses) . "
                ORDER BY a.date DESC, a.punch_in ASC
            ");
            
            $stmt->execute($params);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'break_in':
            $stmt = $conn->prepare("SELECT * FROM {$prefix}attendance WHERE user_id = ? AND date = ? AND punch_out IS NULL ORDER BY id DESC LIMIT 1");
            $stmt->execute([$user_id, $today]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$record) {
                echo json_encode(['success' => false, 'message' => 'Not punched in']);
                exit;
            }
            if ($record['status'] === 'Break') {
                echo json_encode(['success' => false, 'message' => 'Already on break']);
                exit;
            }

            $history = json_decode($record['break_history'] ?: '[]', true);
            $history[] = ['start' => $now, 'end' => null];
            $history_json = json_encode($history);

            $stmt = $conn->prepare("UPDATE {$prefix}attendance SET status = 'Break', break_history = ? WHERE id = ?");
            $stmt->execute([$history_json, $record['id']]);
            echo json_encode(['success' => true, 'message' => 'Break started']);
            break;

        case 'break_out':
            $stmt = $conn->prepare("SELECT * FROM {$prefix}attendance WHERE user_id = ? AND date = ? AND punch_out IS NULL ORDER BY id DESC LIMIT 1");
            $stmt->execute([$user_id, $today]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$record || $record['status'] !== 'Break') {
                echo json_encode(['success' => false, 'message' => 'Not on break']);
                exit;
            }

            $history = json_decode($record['break_history'] ?: '[]', true);
            $total_break_seconds = 0;
            if (count($history) > 0) {
                $last_idx = count($history) - 1;
                $history[$last_idx]['end'] = $now;
            }

            // Calculate total break hours
            foreach ($history as $b) {
                if (!empty($b['start']) && !empty($b['end'])) {
                    $total_break_seconds += strtotime($b['end']) - strtotime($b['start']);
                }
            }
            $history_json = json_encode($history);
            
            $bh = floor($total_break_seconds / 3600);
            $bm = floor(($total_break_seconds % 3600) / 60);
            $total_break_hours_str = ($bh > 0 || $bm > 0) ? ($bh . ' hrs ' . $bm . ' mins') : '';

            $stmt = $conn->prepare("UPDATE {$prefix}attendance SET status = 'Present', break_history = ?, total_break_hours = ? WHERE id = ?");
            $stmt->execute([$history_json, $total_break_hours_str, $record['id']]);
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
