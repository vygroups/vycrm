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

function upload_company_file_path(string $slug, string $basename, string $extension, string $section = 'branding'): string
{
    $dir = upload_company_asset_dir($slug, $section);
    upload_ensure_dir($dir);

    return $dir . $basename . '.' . strtolower($extension);
}
