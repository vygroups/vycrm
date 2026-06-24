<?php
session_start();
require_once __DIR__ . '/../includes/api_auth.php';
require_once __DIR__ . '/../includes/upload_paths.php';

header('Content-Type: application/json');

try {
    $ctx = api_require_context();
} catch (Exception $e) {
    echo json_encode([
        'uploaded' => 0,
        'error' => [
            'message' => 'Unauthorized: ' . $e->getMessage()
        ]
    ]);
    exit;
}

if (!isset($_FILES['upload']) || $_FILES['upload']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode([
        'uploaded' => 0,
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
        'uploaded' => 0,
        'error' => [
            'message' => 'Invalid file extension. Allowed: jpg, jpeg, png, gif, webp'
        ]
    ]);
    exit;
}

$tenantSlug = $ctx['tenant_slug'];
if (!$tenantSlug) {
    $tenantSlug = 'default';
}

$section = 'ck_editor_images';
$baseDir = __DIR__ . '/../';
$relDir = upload_company_asset_dir($tenantSlug, $section);
$uploadDir = $baseDir . $relDir;

upload_ensure_dir($uploadDir);

$newFilename = uniqid('img_') . '.' . $ext;
$targetPath = $uploadDir . $newFilename;

if (move_uploaded_file($file['tmp_name'], $targetPath)) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $publicUrl = $protocol . $host . '/' . $relDir . $newFilename;

    echo json_encode([
        'uploaded' => 1,
        'fileName' => $newFilename,
        'url' => $publicUrl
    ]);
} else {
    echo json_encode([
        'uploaded' => 0,
        'error' => [
            'message' => 'Failed to move uploaded file.'
        ]
    ]);
}
