<?php
require_once __DIR__ . '/../includes/api_auth.php';

header('Content-Type: application/json');

if (!isset($_FILES['upload']) || $_FILES['upload']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode([
        'error' => [
            'message' => 'Upload failed or no file sent.'
        ]
    ]);
    exit;
}

$file = $_FILES['upload'];
$filename = basename($file['name']);
$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
if (!in_array($ext, $allowed)) {
    echo json_encode([
        'error' => [
            'message' => 'Invalid file extension. Allowed: jpg, jpeg, png, gif, webp'
        ]
    ]);
    exit;
}

// Ensure images directory exists
$uploadDir = __DIR__ . '/../assets/uploads/images/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Generate unique filename
$newFilename = uniqid('img_') . '.' . $ext;
$targetPath = $uploadDir . $newFilename;

if (move_uploaded_file($file['tmp_name'], $targetPath)) {
    // Determine the base URL dynamically based on the current request
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $publicUrl = $protocol . $host . '/assets/uploads/images/' . $newFilename;

    echo json_encode([
        'url' => $publicUrl
    ]);
} else {
    echo json_encode([
        'error' => [
            'message' => 'Failed to move uploaded file.'
        ]
    ]);
}
