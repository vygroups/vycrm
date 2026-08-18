<?php
session_start();
// api/permissions.php - Short-term permission request API with multiple TO / CC & status workflow
require_once '../config/database.php';

header('Content-Type: application/json');

require_once '../includes/api_auth.php';
require_once '../includes/dynamic_modules.php';

try {
    $context = api_require_context();
    $user_id = (int)$context['user_id'];
    $dbName = $context['db_name'];
    $prefix = $context['prefix'];
    $conn = $context['conn'];
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized: ' . $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// Support raw JSON body for API / Mobile clients
$rawBody = file_get_contents('php://input');
if (!empty($rawBody)) {
    $decodedBody = json_decode($rawBody, true);
    if (is_array($decodedBody)) {
        $_POST = array_merge($decodedBody, $_POST);
    }
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Ensure columns exist helper
function ensure_permission_workflow_cols($conn, $prefix) {
    $cols = [
        "to_user_id INT DEFAULT NULL",
        "to_user_ids JSON DEFAULT NULL",
        "cc_user_ids JSON DEFAULT NULL",
        "from_time VARCHAR(20) DEFAULT NULL",
        "to_time VARCHAR(20) DEFAULT NULL",
        "to_status VARCHAR(50) DEFAULT 'pending'",
        "cc_status VARCHAR(50) DEFAULT 'pending'",
        "approved_by INT DEFAULT NULL",
        "approved_by_name VARCHAR(150) DEFAULT NULL",
        "updated_at DATETIME DEFAULT NULL"
    ];
    foreach ($cols as $cDef) {
        try {
            $conn->exec("ALTER TABLE {$prefix}permissions ADD COLUMN {$cDef}");
        } catch (Throwable $t) {}
    }
    try {
        $conn->exec("ALTER TABLE {$prefix}permissions MODIFY COLUMN status VARCHAR(50) DEFAULT 'pending'");
    } catch (Throwable $t) {}
}
ensure_permission_workflow_cols($conn, $prefix);

try {
    if ($method == 'POST') {
        if ($action == 'apply') {
            $date = trim($_POST['date'] ?? $_POST['from_date'] ?? '');
            $from_time = trim($_POST['from_time'] ?? '');
            $to_time = trim($_POST['to_time'] ?? '');
            $time_window = trim($_POST['time_window'] ?? $_POST['leave_type'] ?? '');
            $duration = trim($_POST['duration'] ?? '');
            $reason = trim($_POST['reason'] ?? '');

            if (empty($date)) {
                echo json_encode(['success' => false, 'message' => 'Date is required.']);
                exit;
            }

            // Calculate formatted time window and duration if from_time and to_time are given
            if (!empty($from_time) && !empty($to_time)) {
                $from_ts = strtotime($from_time);
                $to_ts = strtotime($to_time);
                if ($from_ts && $to_ts) {
                    $formattedFrom = date("h:i A", $from_ts);
                    $formattedTo = date("h:i A", $to_ts);
                    if (empty($time_window) || $time_window === 'Short Permission (1-2 hrs)') {
                        $time_window = "{$formattedFrom} - {$formattedTo}";
                    }
                    if (empty($duration) || $duration === '1 Hour') {
                        $diffMins = round(($to_ts - $from_ts) / 60);
                        if ($diffMins < 0) $diffMins += 24 * 60;
                        $hrs = floor($diffMins / 60);
                        $mins = $diffMins % 60;
                        if ($hrs > 0 && $mins > 0) {
                            $duration = "{$hrs} hr {$mins} mins";
                        } elseif ($hrs > 0) {
                            $duration = $hrs == 1 ? "1 Hour" : "{$hrs} Hours";
                        } else {
                            $duration = "{$mins} Mins";
                        }
                    }
                }
            }
            if (empty($duration)) $duration = '1 Hour';
            if (empty($time_window)) $time_window = 'Permission';

            // Extract TO user IDs
            $to_user_ids_raw = $_POST['to_user_ids'] ?? $_POST['to_user_id'] ?? [];
            $to_array = [];
            if (is_array($to_user_ids_raw)) {
                foreach ($to_user_ids_raw as $tid) {
                    $tidInt = (int)$tid;
                    if ($tidInt > 0 && $tidInt !== $user_id) $to_array[] = $tidInt;
                }
            } else if (is_numeric($to_user_ids_raw)) {
                $tInt = (int)$to_user_ids_raw;
                if ($tInt > 0 && $tInt !== $user_id) $to_array[] = $tInt;
            } else if (is_string($to_user_ids_raw) && !empty($to_user_ids_raw)) {
                $decoded = json_decode($to_user_ids_raw, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $tid) {
                        $tidInt = (int)$tid;
                        if ($tidInt > 0 && $tidInt !== $user_id) $to_array[] = $tidInt;
                    }
                }
            }
            $to_array = array_values(array_unique($to_array));

            if (empty($to_array)) {
                echo json_encode(['success' => false, 'message' => 'At least one TO person selection is mandatory to apply for permission.']);
                exit;
            }
            $to_json = json_encode($to_array);

            // Extract CC user IDs
            $cc_user_ids_raw = $_POST['cc_user_ids'] ?? [];
            $cc_array = [];
            if (is_array($cc_user_ids_raw)) {
                foreach ($cc_user_ids_raw as $cid) {
                    $cidInt = (int)$cid;
                    if ($cidInt > 0 && !in_array($cidInt, $to_array) && $cidInt !== $user_id) {
                        $cc_array[] = $cidInt;
                    }
                }
            } else if (is_numeric($cc_user_ids_raw)) {
                $cInt = (int)$cc_user_ids_raw;
                if ($cInt > 0 && !in_array($cInt, $to_array) && $cInt !== $user_id) {
                    $cc_array[] = $cInt;
                }
            } else if (is_string($cc_user_ids_raw) && !empty($cc_user_ids_raw)) {
                $decoded = json_decode($cc_user_ids_raw, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $cid) {
                        $cidInt = (int)$cid;
                        if ($cidInt > 0 && !in_array($cidInt, $to_array) && $cidInt !== $user_id) {
                            $cc_array[] = $cidInt;
                        }
                    }
                }
            }
            $cc_json = json_encode(array_values(array_unique($cc_array)));

            $primaryToId = $to_array[0];
            $stmt = $conn->prepare("INSERT INTO {$prefix}permissions (user_id, to_user_id, to_user_ids, cc_user_ids, date, from_time, to_time, time_window, duration, reason, status, to_status, cc_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', 'pending')");
            $stmt->execute([$user_id, $primaryToId, $to_json, $cc_json, $date, $from_time, $to_time, $time_window, $duration, $reason]);

            echo json_encode(['success' => true, 'message' => 'Permission request submitted successfully.']);
        } elseif ($action == 'update_status') {
            $perm_id = (int)($_POST['id'] ?? 0);
            $new_status = strtolower(trim($_POST['status'] ?? 'approved')); // 'approved' or 'rejected'

            $stmt = $conn->prepare("SELECT * FROM {$prefix}permissions WHERE id = ?");
            $stmt->execute([$perm_id]);
            $perm = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$perm) {
                echo json_encode(['success' => false, 'message' => 'Permission request not found.']);
                exit;
            }

            if ((int)$perm['user_id'] === $user_id) {
                echo json_encode(['success' => false, 'message' => 'You cannot approve or reject your own request.']);
                exit;
            }

            // Fetch acting user details
            $uStmt = $conn->prepare("SELECT u.id, u.first_name, u.last_name, u.username, u.is_admin FROM {$prefix}users u WHERE u.id = ?");
            $uStmt->execute([$user_id]);
            $actor = $uStmt->fetch(PDO::FETCH_ASSOC);
            $actorName = trim(($actor['first_name'] ?? '') . ' ' . ($actor['last_name'] ?? ''));
            if (empty($actorName)) $actorName = $actor['username'] ?? 'Admin';
            $isAdmin = (int)($actor['is_admin'] ?? 0) === 1;

            $to_user_ids = json_decode($perm['to_user_ids'] ?: '[]', true);
            if (empty($to_user_ids) && !empty($perm['to_user_id'])) {
                $to_user_ids = [(int)$perm['to_user_id']];
            }
            if (!is_array($to_user_ids)) $to_user_ids = [];

            $cc_user_ids = json_decode($perm['cc_user_ids'] ?: '[]', true);
            if (!is_array($cc_user_ids)) $cc_user_ids = [];

            $is_to_person = in_array($user_id, array_map('intval', $to_user_ids));
            $is_cc_person = in_array($user_id, array_map('intval', $cc_user_ids));

            if (!$isAdmin && !$is_to_person && !$is_cc_person) {
                echo json_encode(['success' => false, 'message' => 'You do not have permission to approve or reject this request.']);
                exit;
            }

            $current_to_status = $perm['to_status'] ?? 'pending';
            $current_cc_status = $perm['cc_status'] ?? 'pending';

            if ($isAdmin || $is_to_person) {
                $current_to_status = $new_status;
            }
            if ($isAdmin || $is_cc_person) {
                $current_cc_status = $new_status;
            }

            // Compute overall status
            $final_status = 'pending';
            if ($current_to_status === 'rejected') {
                $final_status = 'rejected';
            } elseif ($current_to_status === 'approved') {
                if (!empty($cc_user_ids)) {
                    if ($current_cc_status === 'approved') {
                        $final_status = 'approved';
                    } else {
                        $final_status = 'partially_approved';
                    }
                } else {
                    $final_status = 'approved';
                }
            }

            $now = date('Y-m-d H:i:s');
            $upStmt = $conn->prepare("UPDATE {$prefix}permissions SET status = ?, to_status = ?, cc_status = ?, approved_by = ?, approved_by_name = ?, updated_at = ? WHERE id = ?");
            $upStmt->execute([$final_status, $current_to_status, $current_cc_status, $user_id, $actorName, $now, $perm_id]);

            $statusText = str_replace('_', ' ', $final_status);
            echo json_encode([
                'success' => true,
                'message' => "Permission status updated to {$statusText}.",
                'status' => $final_status,
                'approved_by_name' => $actorName
            ]);
        }
    } else {
        // GET permissions
        $targetUser = isset($_GET['user_id']) ? $_GET['user_id'] : $user_id;

        $rule = dm_get_system_setting($conn, $prefix, 'attendance_visibility', 'all');
        
        $userQuery = $conn->prepare("SELECT role_id, is_admin FROM {$prefix}users WHERE id = ?");
        $userQuery->execute([$user_id]);
        $userData = $userQuery->fetch(PDO::FETCH_ASSOC);
        $my_role_id = $userData ? $userData['role_id'] : null;
        $is_admin = $userData ? (int)$userData['is_admin'] : 0;

        $allowedUserIds = dm_get_visible_user_ids($conn, $prefix, $user_id, $my_role_id ? (int)$my_role_id : null, $rule, $is_admin);

        $sqlSelect = "SELECT p.*, 
                             u.username, u.first_name, u.last_name
                      FROM {$prefix}permissions p 
                      JOIN {$prefix}users u ON p.user_id = u.id";

        $startDate = trim($_GET['start_date'] ?? '');
        $endDate = trim($_GET['end_date'] ?? '');

        $whereClause = [];
        $params = [];

        if ($targetUser === 'all') {
            if ($allowedUserIds !== null) {
                if (empty($allowedUserIds)) {
                    echo json_encode(['success' => true, 'data' => []]);
                    exit;
                }
                $inClause = implode(',', array_map('intval', $allowedUserIds));
                $whereClause[] = "p.user_id IN ($inClause)";
            }
        } else {
            $targetUser = (int)$targetUser;
            if ($targetUser !== $user_id) {
                if ($allowedUserIds !== null && !in_array($targetUser, $allowedUserIds)) {
                    echo json_encode(['success' => true, 'data' => []]);
                    exit;
                }
            }
            $whereClause[] = "p.user_id = ?";
            $params[] = $targetUser;
        }

        if (!empty($startDate)) {
            if (!empty($endDate) && $endDate > date('Y-m-d')) {
                $whereClause[] = "((p.date >= ? AND p.date <= ?) OR p.created_at >= ?)";
                $params[] = $startDate;
                $params[] = $endDate;
                $params[] = $startDate . ' 00:00:00';
            } else {
                $whereClause[] = "(p.date >= ? OR p.created_at >= ?)";
                $params[] = $startDate;
                $params[] = $startDate . ' 00:00:00';
            }
        }

        $sql = $sqlSelect;
        if (!empty($whereClause)) {
            $sql .= " WHERE " . implode(' AND ', $whereClause);
        }
        $sql .= " ORDER BY p.created_at DESC";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        $perms = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Pre-fetch all user display names once for fast & safe mapping
        $uStmt = $conn->query("SELECT id, username, first_name, last_name FROM {$prefix}users");
        $uMap = [];
        if ($uStmt) {
            while ($ur = $uStmt->fetch(PDO::FETCH_ASSOC)) {
                $fn = trim(($ur['first_name'] ?? '') . ' ' . ($ur['last_name'] ?? ''));
                $uMap[(int)$ur['id']] = $fn !== '' ? $fn : ($ur['username'] ?? 'User #' . $ur['id']);
            }
        }

        // Enhance TO & CC user names for each permission
        foreach ($perms as &$p) {
            $to_ids = is_array($p['to_user_ids'] ?? null) ? $p['to_user_ids'] : json_decode($p['to_user_ids'] ?: '[]', true);
            if (empty($to_ids) && !empty($p['to_user_id'])) {
                $to_ids = [(int)$p['to_user_id']];
            }
            if (!is_array($to_ids)) $to_ids = [];

            $to_names = [];
            foreach ($to_ids as $tid) {
                $tidInt = (int)$tid;
                if ($tidInt > 0 && isset($uMap[$tidInt])) {
                    $to_names[] = $uMap[$tidInt];
                }
            }
            $p['to_user_names'] = $to_names;
            $p['to_display_name'] = !empty($to_names) ? implode(', ', $to_names) : 'Not specified';

            $cc_ids = is_array($p['cc_user_ids'] ?? null) ? $p['cc_user_ids'] : json_decode($p['cc_user_ids'] ?: '[]', true);
            if (!is_array($cc_ids)) $cc_ids = [];

            $cc_names = [];
            foreach ($cc_ids as $cid) {
                $cidInt = (int)$cid;
                if ($cidInt > 0 && isset($uMap[$cidInt])) {
                    $cc_names[] = $uMap[$cidInt];
                }
            }
            $p['cc_user_names'] = $cc_names;
            $p['leave_type'] = !empty($p['time_window']) ? $p['time_window'] : 'Permission';
            $p['from_date'] = $p['date'] ?? '';
            $p['to_date'] = $p['date'] ?? '';
        }
        unset($p);

        echo json_encode(['success' => true, 'data' => $perms]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
