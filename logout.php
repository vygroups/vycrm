<?php
session_start();
$tenantSlug = preg_replace('/[^a-z0-9_-]/', '', strtolower($_SESSION['tenant_slug'] ?? ''));
session_destroy();
header('Location: ' . ($tenantSlug !== '' ? '/login/' . $tenantSlug : '/index.php'));
exit;
?>
