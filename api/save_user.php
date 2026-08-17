<?php
require_once '../auth_check.php';
require_once '../config/database.php';
require_once '../includes/dynamic_modules.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

try {
    $dbName = $_SESSION['tenant_db'];
    $conn = Database::getTenantConn($dbName);
    $prefix = $_SESSION['tenant_prefix'];
    dm_ensure_tables($conn, $prefix);

    $action = $_POST['action'] ?? '';

    // Quick toggle status
    if ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        $status = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
            exit;
        }
        $stmt = $conn->prepare("UPDATE {$prefix}users SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
        echo json_encode(['success' => true, 'message' => 'User marked as ' . ucfirst($status), 'status' => $status]);
        exit;
    }

    // Quick toggle 2FA
    if ($action === 'toggle_2fa') {
        $id = (int)($_POST['id'] ?? 0);
        $enabled = (int)($_POST['two_factor_enabled'] ?? 0) === 1 ? 1 : 0;
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
            exit;
        }
        $stmt = $conn->prepare("UPDATE {$prefix}users SET two_factor_enabled = ? WHERE id = ?");
        $stmt->execute([$enabled, $id]);
        echo json_encode(['success' => true, 'message' => 'Two-Step Verification ' . ($enabled ? 'Enabled' : 'Disabled'), 'two_factor_enabled' => $enabled]);
        exit;
    }

    $username = trim($_POST['username'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $roleId = $_POST['role_id'] ?? null;
    if ($roleId === '') $roleId = null;
    $is_admin = isset($_POST['is_admin']) && $_POST['is_admin'] === '1' ? 1 : 0;
    $status = (isset($_POST['status']) && $_POST['status'] === 'inactive') ? 'inactive' : 'active';
    $two_factor_enabled = (isset($_POST['two_factor_enabled']) && ($_POST['two_factor_enabled'] === '1' || $_POST['two_factor_enabled'] === 'on')) ? 1 : 0;

    $id = (int)($_POST['id'] ?? 0);

    if (!$username || !$email) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }
    if (!$id && !$password) {
        echo json_encode(['success' => false, 'message' => 'Password is required for new users']);
        exit;
    }

    // Check if user already exists
    $stmt = $conn->prepare("SELECT id FROM {$prefix}users WHERE (username = ? OR email = ?) AND id != ?");
    $stmt->execute([$username, $email, $id]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Username or Email already exists']);
        exit;
    }

    if ($id) {
        if ($password) {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE {$prefix}users SET username = ?, first_name = ?, last_name = ?, email = ?, password = ?, role_id = ?, is_admin = ?, status = ?, two_factor_enabled = ? WHERE id = ?");
            $stmt->execute([$username, $first_name, $last_name, $email, $hashedPassword, $roleId, $is_admin, $status, $two_factor_enabled, $id]);
        } else {
            $stmt = $conn->prepare("UPDATE {$prefix}users SET username = ?, first_name = ?, last_name = ?, email = ?, role_id = ?, is_admin = ?, status = ?, two_factor_enabled = ? WHERE id = ?");
            $stmt->execute([$username, $first_name, $last_name, $email, $roleId, $is_admin, $status, $two_factor_enabled, $id]);
        }
        echo json_encode(['success' => true, 'message' => 'User updated successfully']);
    } else {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO {$prefix}users (username, first_name, last_name, email, password, role_id, is_admin, status, two_factor_enabled) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$username, $first_name, $last_name, $email, $hashedPassword, $roleId, $is_admin, $status, $two_factor_enabled]);
        echo json_encode(['success' => true, 'message' => 'User created successfully']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

