<?php
require_once '../auth_check.php';
require_once '../config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$username = $_POST['username'] ?? '';
$email = $_POST['email'] ?? '';
$password = $_POST['password'] ?? '';
$roleId = $_POST['role_id'] ?? null;
if ($roleId === '') $roleId = null;

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
    
    $stmt = $conn->prepare("INSERT INTO {$prefix}users (username, email, password, role_id) VALUES (?, ?, ?, ?)");
    $stmt->execute([$username, $email, $hashedPassword, $roleId]);
    
    echo json_encode(['success' => true, 'message' => 'User created successfully']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
