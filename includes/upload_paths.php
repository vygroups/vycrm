<?php

$docRoot = $_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__);
// For safety, remove trailing slashes
$docRoot = rtrim($docRoot, '/\\');
define('UPLOAD_BASE_DIR', dirname($docRoot) . '/vy_uploads/');
define('UPLOAD_BASE_URL', '/serve_file.php?path=');

function upload_normalize_company_slug(?string $slug): string
{
    $normalized = preg_replace('/[^a-z0-9-]/', '', strtolower((string) $slug));
    return $normalized !== '' ? $normalized : 'default';
}

function upload_company_asset_dir(string $slug, string $section = 'branding'): string
{
    $normalizedSlug = upload_normalize_company_slug($slug);
    $normalizedSection = trim($section, '/');

    return "assets/uploads/{$normalizedSlug}/{$normalizedSection}/";
}

function upload_ensure_dir(string $dir): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

function upload_clean_existing_files(string $dir, string $basename): void
{
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    $basenameLower = strtolower($basename);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $itemPath = rtrim($dir, '/') . '/' . $item;
        if (is_file($itemPath)) {
            $filename = pathinfo($item, PATHINFO_FILENAME);
            if (strtolower($filename) === $basenameLower) {
                @unlink($itemPath);
            }
        }
    }
}

function upload_company_file_path(string $slug, string $basename, string $extension, string $section = 'branding'): string
{
    $logicalPath = upload_company_asset_dir($slug, $section);
    $physicalDir = UPLOAD_BASE_DIR . $logicalPath;
    
    upload_clean_existing_files($physicalDir, $basename);
    upload_ensure_dir($physicalDir);

    // Return the logical path for database storage
    return $logicalPath . $basename . '.' . strtolower($extension);
}

