<?php
// google_oauth_callback.php - Google Drive OAuth Callback Handler
require_once 'auth_check.php';
require_once 'config/database.php';
require_once 'includes/commerce.php';
require_once 'includes/calls_helper.php';

$context = commerce_get_tenant_context();
$conn = $context['conn'];
$prefix = $context['prefix'];
$userId = $context['user_id'] ?? null;

calls_ensure_tables($conn, $prefix);

$error = $_GET['error'] ?? null;
if ($error) {
    header('Location: call_settings.php?error=' . urlencode('Google authorization cancelled or failed: ' . $error));
    exit;
}

$code = $_GET['code'] ?? null;
if (!$code) {
    header('Location: call_settings.php?error=' . urlencode('No authorization code provided by Google.'));
    exit;
}

try {
    $currentStorage = calls_get_storage_config($conn, $prefix, $userId);
    $cfgData = $currentStorage['config_data'] ?? [];

    [$clientId, $clientSecret] = calls_get_effective_google_credentials($cfgData);

    if (!$clientId || !$clientSecret) {
        $clientId = trim($_SESSION['pending_gd_client_id'] ?? $clientId);
        $clientSecret = trim($_SESSION['pending_gd_client_secret'] ?? $clientSecret);
    }

    if (!$clientId || !$clientSecret) {
        throw new RuntimeException('Google OAuth credentials are not configured in Super Admin. Please contact platform administrator.');
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $redirectUri = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/google_oauth_callback.php';

    $tokenData = calls_exchange_google_code($code, $clientId, $clientSecret, $redirectUri);

    // Merge into storage config
    $mergedConfigData = array_merge($cfgData, [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'access_token' => $tokenData['access_token'],
        'refresh_token' => !empty($tokenData['refresh_token']) ? $tokenData['refresh_token'] : ($cfgData['refresh_token'] ?? ''),
        'token_expires_at' => $tokenData['token_expires_at'],
        'account_email' => $tokenData['account_email'],
        'account_name' => $tokenData['account_name'],
        'account_picture' => $tokenData['account_picture'],
        'connected_at' => date('Y-m-d H:i:s')
    ]);

    // Check if target folder already exists, if not, try to find or create "Call Recordings" folder
    if (empty($mergedConfigData['folder_id'])) {
        try {
            $folders = calls_list_google_folders($tokenData['access_token'], 'root');
            $existingFolder = null;
            foreach ($folders as $f) {
                if (strtolower(trim($f['name'])) === 'call recordings') {
                    $existingFolder = $f;
                    break;
                }
            }
            if ($existingFolder) {
                $mergedConfigData['folder_id'] = $existingFolder['id'];
                $mergedConfigData['folder_name'] = $existingFolder['name'];
            } else {
                $created = calls_create_google_folder($tokenData['access_token'], 'Call Recordings', 'root');
                if (!empty($created['id'])) {
                    $mergedConfigData['folder_id'] = $created['id'];
                    $mergedConfigData['folder_name'] = $created['name'];
                }
            }
        } catch (Exception $e) {
            // Folder creation can also be done manually in folder navigator
        }
    }

    calls_save_storage_config($conn, $prefix, [
        'id' => $currentStorage['id'] ?? 0,
        'provider' => 'google_drive',
        'config_name' => 'Google Drive Storage (' . ($tokenData['account_email'] ?: 'Connected') . ')',
        'config_data' => $mergedConfigData,
        'is_default' => 1,
        'is_active' => 1
    ], $userId);

    unset($_SESSION['pending_gd_client_id'], $_SESSION['pending_gd_client_secret']);

    header('Location: call_settings.php?connected=1');
    exit;
} catch (Throwable $e) {
    header('Location: call_settings.php?error=' . urlencode($e->getMessage()));
    exit;
}
