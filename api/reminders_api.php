<?php
/**
 * api/reminders_api.php
 * 
 * REST API for Universal Multi-Channel Module Reminders.
 */

header('Content-Type: application/json');

ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/commerce.php';
require_once __DIR__ . '/../includes/dynamic_modules.php';
require_once __DIR__ . '/../includes/reminder_helper.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $context = commerce_get_tenant_context();
} catch (Throwable $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized: ' . $e->getMessage()]);
    exit;
}

$conn = $context['conn'];
$prefix = $context['prefix'];
$userId = (int)($context['user_id'] ?? ($_SESSION['user_id'] ?? 0));

// Ensure user authentication
if (!$userId) {
    // Check Bearer token for mobile app API requests
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
        $token = trim($matches[1]);
        $uStmt = $conn->prepare("SELECT id, username, is_admin FROM `{$prefix}users` WHERE api_token = ? OR token = ? LIMIT 1");
        $uStmt->execute([$token, $token]);
        if ($u = $uStmt->fetch(PDO::FETCH_ASSOC)) {
            $userId = (int)$u['id'];
        }
    }
}

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access. Please login.']);
    exit;
}

reminder_ensure_tables($conn, $prefix);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: $_POST;
    if (empty($action) && isset($input['action'])) {
        $action = $input['action'];
    }
} else {
    $input = $_GET;
}

try {
    switch ($action) {
        case 'list':
            $moduleId = (int)($input['module_id'] ?? 0);
            $recordId = (int)($input['record_id'] ?? 0);

            if (!$moduleId || !$recordId) {
                echo json_encode(['success' => false, 'error' => 'module_id and record_id are required.']);
                exit;
            }

            $reminders = reminder_fetch_for_record($conn, $prefix, $moduleId, $recordId);
            echo json_encode(['success' => true, 'reminders' => $reminders]);
            break;

        case 'get':
            $id = (int)($input['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'Reminder ID required.']);
                exit;
            }
            $reminder = reminder_get_by_id($conn, $prefix, $id);
            if (!$reminder) {
                echo json_encode(['success' => false, 'error' => 'Reminder not found.']);
                exit;
            }
            echo json_encode(['success' => true, 'reminder' => $reminder]);
            break;

        case 'get_record_reminder':
            $moduleId = (int)($input['module_id'] ?? 0);
            $recordId = (int)($input['record_id'] ?? 0);
            if (!$moduleId || !$recordId) {
                echo json_encode(['success' => false, 'error' => 'module_id and record_id are required.']);
                exit;
            }
            $reminder = reminder_get_active_for_record($conn, $prefix, $moduleId, $recordId);
            echo json_encode(['success' => true, 'reminder' => $reminder, 'has_reminder' => ($reminder !== null)]);
            break;

        case 'list_all':
            $filtered = reminder_fetch_all_filtered($conn, $prefix, $userId, $input);
            echo json_encode(array_merge(['success' => true], $filtered));
            break;

        case 'upcoming':
            $limit = (int)($input['limit'] ?? 15);
            $reminders = reminder_fetch_user_upcoming($conn, $prefix, $userId, $limit);
            echo json_encode(['success' => true, 'reminders' => $reminders]);
            break;

        case 'create':
            $input['created_by'] = $userId;
            $res = reminder_create($conn, $prefix, $input);
            echo json_encode($res);
            break;

        case 'update':
            $res = reminder_update($conn, $prefix, $input);
            echo json_encode($res);
            break;

        case 'mark_status':
            $id = (int)($input['id'] ?? 0);
            $status = trim($input['status'] ?? 'sent');
            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'Reminder ID required.']);
                exit;
            }
            $updated = reminder_update_status($conn, $prefix, $id, $status);
            echo json_encode(['success' => $updated, 'message' => $updated ? 'Status updated.' : 'Failed to update status.']);
            break;

        case 'delete':
            $id = (int)($input['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'Reminder ID required.']);
                exit;
            }

            $deleted = reminder_delete($conn, $prefix, $id);
            echo json_encode(['success' => $deleted, 'message' => $deleted ? 'Reminder deleted.' : 'Failed to delete reminder.']);
            break;

        case 'trigger_now':
            $id = (int)($input['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'Reminder ID required.']);
                exit;
            }

            $stmt = $conn->prepare("SELECT * FROM `{$prefix}module_reminders` WHERE id = ?");
            $stmt->execute([$id]);
            $reminder = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$reminder) {
                echo json_encode(['success' => false, 'error' => 'Reminder not found.']);
                exit;
            }

            $dispatchRes = reminder_dispatch_single($conn, $prefix, $reminder);
            echo json_encode($dispatchRes);
            break;

        case 'google_calendar_status':
            $gcalCfg = reminder_get_google_calendar_config($conn, $prefix, $userId);
            $isConnected = !empty($gcalCfg) && (!empty($gcalCfg['access_token']) || !empty($gcalCfg['refresh_token']));
            echo json_encode([
                'success' => true,
                'connected' => $isConnected,
                'config' => $isConnected ? [
                    'account_email' => $gcalCfg['account_email'] ?? '',
                    'account_name' => $gcalCfg['account_name'] ?? '',
                    'account_picture' => $gcalCfg['account_picture'] ?? '',
                    'sync_enabled' => (bool)($gcalCfg['sync_enabled'] ?? 1),
                    'connected_at' => !empty($gcalCfg['connected_at']) ? date('d M, Y h:i A', strtotime($gcalCfg['connected_at'])) : ''
                ] : null
            ]);
            break;

        case 'google_calendar_get_auth_url':
            $clientId = (string)dm_get_global_setting('google_drive_client_id', '');
            if (!$clientId) {
                echo json_encode(['success' => false, 'error' => 'Google OAuth is not configured in Super Admin yet. Please configure Google Client ID & Secret in Super Admin.']);
                exit;
            }
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $redirectUri = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/google_oauth_callback.php';
            $_SESSION['google_oauth_source'] = 'reminders';
            $authUrl = reminder_get_google_calendar_auth_url($clientId, $redirectUri, 'reminders');
            echo json_encode(['success' => true, 'auth_url' => $authUrl]);
            break;

        case 'google_calendar_toggle_sync':
            $enabled = !empty($input['enabled']) ? 1 : 0;
            $conn->prepare("UPDATE `{$prefix}user_calendar_configs` SET sync_enabled = ? WHERE user_id = ? AND provider = 'google'")->execute([$enabled, $userId]);
            echo json_encode(['success' => true, 'sync_enabled' => (bool)$enabled]);
            break;

        case 'google_calendar_disconnect':
            $res = reminder_disconnect_google_calendar($conn, $prefix, $userId);
            echo json_encode(['success' => $res, 'message' => $res ? 'Google Calendar disconnected.' : 'Failed to disconnect.']);
            break;

        case 'google_calendar_sync_all':
            $upcoming = reminder_fetch_user_upcoming($conn, $prefix, $userId, 50);
            $syncedCount = 0;
            $errors = [];
            foreach ($upcoming as $uRem) {
                $err = null;
                $eventId = reminder_sync_to_google_calendar($conn, $prefix, $uRem, $userId, $err);
                if ($eventId) {
                    $syncedCount++;
                } else if ($err) {
                    $errors[] = $err;
                }
            }
            $uniqueErrors = array_values(array_unique(array_filter($errors)));

            if (!empty($uniqueErrors) && $syncedCount === 0) {
                echo json_encode([
                    'success' => false,
                    'synced_count' => 0,
                    'errors' => $uniqueErrors,
                    'error' => implode(' | ', $uniqueErrors),
                    'message' => 'Google Calendar sync failed: ' . implode(' | ', $uniqueErrors)
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'synced_count' => $syncedCount,
                    'errors' => $uniqueErrors,
                    'message' => $syncedCount > 0 
                        ? "Successfully synced {$syncedCount} upcoming reminder" . ($syncedCount === 1 ? '' : 's') . " with Google Calendar."
                        : "No upcoming reminders needed syncing."
                ]);
            }
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action: ' . htmlspecialchars($action)]);
            break;
    }
} catch (Throwable $e) {
    error_log("[Reminders API Error] " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
