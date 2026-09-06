<?php
// api/update_fcm_token.php - Update or Clear User FCM Push Notification Token
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/api_auth.php';
require_once __DIR__ . '/../includes/dynamic_modules.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $context = api_require_context();
    $conn = $context['conn'];
    $prefix = $context['prefix'];
    $userId = (int)$context['user_id'];

    dm_ensure_tables($conn, $prefix);

    $rawInput = json_decode(file_get_contents('php://input'), true);
    $fcmToken = trim($_POST['fcm_token'] ?? $rawInput['fcm_token'] ?? '');
    $deviceType = strtolower(trim($_POST['device_type'] ?? $rawInput['device_type'] ?? 'android'));
    if (!in_array($deviceType, ['android', 'ios', 'web'])) {
        $deviceType = 'android';
    }

    if (empty($fcmToken) || $fcmToken === 'null' || $fcmToken === 'clear') {
        // Clear token
        if ($deviceType === 'web') {
            $stmt = $conn->prepare("UPDATE {$prefix}users SET fcm_web_token = NULL, fcm_updated_at = NOW() WHERE id = ?");
            $stmt->execute([$userId]);
        } else {
            $stmt = $conn->prepare("UPDATE {$prefix}users SET fcm_token = NULL, fcm_updated_at = NOW() WHERE id = ?");
            $stmt->execute([$userId]);
        }
        echo json_encode([
            'success' => true,
            'message' => 'FCM Token cleared successfully',
            'fcm_token' => null
        ]);
    } else {
        // Save new token
        if ($deviceType === 'web') {
            $stmt = $conn->prepare("UPDATE {$prefix}users SET fcm_web_token = ?, fcm_updated_at = NOW() WHERE id = ?");
            $stmt->execute([$fcmToken, $userId]);
        } else {
            $stmt = $conn->prepare("UPDATE {$prefix}users SET fcm_token = ?, fcm_device_type = ?, fcm_updated_at = NOW() WHERE id = ?");
            $stmt->execute([$fcmToken, $deviceType, $userId]);
        }
        echo json_encode([
            'success' => true,
            'message' => 'FCM Token updated successfully',
            'device_type' => $deviceType,
            'fcm_token' => $fcmToken
        ]);
    }
} catch (Throwable $e) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
