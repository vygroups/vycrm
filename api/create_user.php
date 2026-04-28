<?php
require_once '../auth_check.php';
require_once '../config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$username = $_POST['username'] ?? '';
$first_name = $_POST['first_name'] ?? '';
$last_name = $_POST['last_name'] ?? '';
$email = $_POST['email'] ?? '';
$password = $_POST['password'] ?? '';
$roleId = $_POST['role_id'] ?? null;
if ($roleId === '') $roleId = null;
$is_admin = isset($_POST['is_admin']) && $_POST['is_admin'] === '1' ? 1 : 0;

if (!$username || !$email || !$password) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

try {
    $dbName = $_SESSION['tenant_db'];
    $conn = Database::getTenantConn($dbName);
    $prefix = $_SESSION['tenant_prefix'];
    
    // Check if user already exists
    $stmt = $conn->prepare("SELECT id FROM {$prefix}users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Username or Email already exists']);
        exit;
    }
    
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    $stmt = $conn->prepare("INSERT INTO {$prefix}users (username, first_name, last_name, email, password, role_id, is_admin) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$username, $first_name, $last_name, $email, $hashedPassword, $roleId, $is_admin]);
    
    echo json_encode(['success' => true, 'message' => 'User created successfully']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
