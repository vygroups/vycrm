<?php

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
        $itemPath = $dir . '/' . $item;
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
    $dir = upload_company_asset_dir($slug, $section);
    upload_clean_existing_files($dir, $basename);
    upload_ensure_dir($dir);

    return $dir . $basename . '.' . strtolower($extension);
}
