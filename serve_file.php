<?php
require_once __DIR__ . '/includes/upload_paths.php';

$path = $_GET['path'] ?? '';
// Strip any query string appended by cache busters (e.g., ?v=123)
$path = explode('?', $path)[0];

if (empty($path)) {
    http_response_code(400);
    exit('Path parameter is required.');
}

// Basic path traversal protection
if (strpos($path, '..') !== false || strpos($path, "\0") !== false) {
    http_response_code(400);
    exit('Invalid path.');
}

// Ensure the path is relative and starts without a slash
$path = ltrim($path, '/');

// Build absolute path
$absolutePath = UPLOAD_BASE_DIR . $path;

// Fallback logic for logos if not found directly
if (!file_exists($absolutePath) && strpos($path, 'assets/uploads/logos/') === 0) {
    // If we're looking for a logo and it doesn't exist, it might be in UPLOAD_BASE_DIR
    // Actually, fallback is handled in the frontend scripts mostly. But we can check here too.
}

if (!file_exists($absolutePath) || !is_file($absolutePath)) {
    // Fallback to checking public_html in case they haven't migrated files yet
    $fallbackPath = __DIR__ . '/' . $path;
    if (file_exists($fallbackPath) && is_file($fallbackPath)) {
        $absolutePath = $fallbackPath;
    } else {
        http_response_code(404);
        exit('File not found.');
    }
}

// Prevent serving sensitive files (only allow specific extensions)
$ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
$allowed_extensions = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'svg' => 'image/svg+xml',
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
];

if (!array_key_exists($ext, $allowed_extensions)) {
    http_response_code(403);
    exit('File type not allowed.');
}

$mime_type = $allowed_extensions[$ext];

// Set headers for caching and output
header('Content-Type: ' . $mime_type);
header('Content-Length: ' . filesize($absolutePath));
header('Cache-Control: public, max-age=86400'); // Cache for 1 day
header('Pragma: cache');
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT');

// Prevent output buffering issues with large files
while (ob_get_level()) {
    ob_end_clean();
}

readfile($absolutePath);
exit;
