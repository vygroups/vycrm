<?php
ob_start();
/**
 * api/click_to_call.php
 * 
 * REST API for Click-to-Call Telephony / Remote Mobile Dialer Bridge.
 * Triggers outgoing call actions on connected user mobile devices or cloud VoIP providers.
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dynamic_modules.php';
require_once __DIR__ . '/../includes/commerce.php';

try {
    $context = commerce_get_tenant_context();
} catch (Throwable $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$conn = $context['conn'];
$prefix = $context['prefix'];
$userId = $context['user_id'];

dm_ensure_tables($conn, $prefix);

$input = commerce_read_input();
$action = $_GET['action'] ?? $input['action'] ?? 'trigger_call';

try {
    // Fetch logged in user's profile and mobile FCM registration
    $uStmt = $conn->prepare("SELECT id, username, fcm_token, fcm_web_token, fcm_device_type, fcm_updated_at FROM {$prefix}users WHERE id = ?");
    $uStmt->execute([$userId]);
    $user = $uStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new RuntimeException('User record not found');
    }

    switch ($action) {

        case 'get_status':
            // Check if user has an active mobile device registered
            $hasMobile = !empty($user['fcm_token']);
            $telephonyProvider = dm_get_system_setting($conn, $prefix, 'telephony_provider', 'mobile_bridge');
            
            commerce_json_response([
                'success' => true,
                'has_mobile_device' => $hasMobile,
                'device_type' => $user['fcm_device_type'] ?? 'android',
                'last_synced' => $user['fcm_updated_at'] ?? null,
                'telephony_provider' => $telephonyProvider,
            ]);

        case 'trigger_call':
            $phoneNumber = trim($input['phone_number'] ?? '');
            $customerName = trim($input['customer_name'] ?? $input['record_name'] ?? 'Contact');
            $recordId = (string)($input['record_id'] ?? '');
            $moduleSlug = trim($input['module_slug'] ?? 'contacts');
            $fieldLabel = trim($input['field_label'] ?? 'Phone');

            if (empty($phoneNumber)) {
                throw new RuntimeException('Phone number is required to initiate call.');
            }

            // Clean phone number format
            $cleanPhone = preg_replace('/[^\d\+\#\*]/', '', $phoneNumber);

            $telephonyProvider = dm_get_system_setting($conn, $prefix, 'telephony_provider', 'mobile_bridge');

            if ($telephonyProvider === 'mobile_bridge') {
                $fcmToken = $user['fcm_token'] ?? '';

                if (empty($fcmToken)) {
                    commerce_json_response([
                        'success' => false,
                        'error_code' => 'NO_MOBILE_DEVICE',
                        'message' => 'No mobile device linked. Please open and sign into the VY CRM Mobile App on your Android/iOS phone to enable Click-to-Call.',
                        'phone_number' => $cleanPhone
                    ]);
                }

                // Construct high-priority FCM payload for Flutter app
                $fcmData = [
                    'type' => 'CLICK_TO_CALL',
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    'phone_number' => $cleanPhone,
                    'customer_name' => $customerName,
                    'record_id' => $recordId,
                    'module_slug' => $moduleSlug,
                    'field_label' => $fieldLabel,
                    'timestamp' => (string)time(),
                ];

                $title = "📞 Outgoing Call Request";
                $body = "Calling {$customerName} ({$cleanPhone})";

                $fcmResult = dm_send_fcm_notification($fcmToken, $title, $body, $fcmData, null, $conn, $prefix);

                if (!$fcmResult['success']) {
                    commerce_json_response([
                        'success' => false,
                        'error_code' => 'FCM_DELIVERY_FAILED',
                        'message' => 'Failed to deliver push command to device: ' . ($fcmResult['error'] ?? 'Unknown push gateway error'),
                        'fcm_response' => $fcmResult,
                        'phone_number' => $cleanPhone,
                        'device_type' => $user['fcm_device_type'] ?? 'mobile'
                    ]);
                }

                commerce_json_response([
                    'success' => true,
                    'mode' => 'mobile_bridge',
                    'message' => "Call command dispatched to your {$user['fcm_device_type']} device.",
                    'phone_number' => $cleanPhone,
                    'customer_name' => $customerName,
                    'device_type' => $user['fcm_device_type'] ?? 'mobile',
                    'fcm_response' => $fcmResult
                ]);
            } else if ($telephonyProvider === 'browser_tel') {
                commerce_json_response([
                    'success' => true,
                    'mode' => 'browser_tel',
                    'message' => 'Opening default telephony client...',
                    'phone_number' => $cleanPhone,
                    'customer_name' => $customerName
                ]);
            } else {
                // Future cloud PBX (Twilio, Exotel, etc.)
                commerce_json_response([
                    'success' => true,
                    'mode' => $telephonyProvider,
                    'message' => "Cloud telephony provider [{$telephonyProvider}] provision initialized.",
                    'phone_number' => $cleanPhone,
                    'customer_name' => $customerName
                ]);
            }
            break;

        case 'test_push':
            $fcmToken = $user['fcm_token'] ?? '';
            if (empty($fcmToken)) {
                commerce_json_response([
                    'success' => false,
                    'error_code' => 'NO_MOBILE_DEVICE',
                    'message' => 'No mobile device linked to your CRM account. Please open the mobile app and tap "Sync Token" in Settings.',
                ]);
            }

            $title = "🔔 VY CRM Push Test";
            $body = "Push notifications are working perfectly on your " . ucfirst($user['fcm_device_type'] ?? 'mobile') . " device!";
            $fcmData = [
                'type' => 'TEST_PUSH',
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                'timestamp' => (string)time(),
                'source' => 'web_settings'
            ];

            $fcmResult = dm_send_fcm_notification($fcmToken, $title, $body, $fcmData, null, $conn, $prefix);

            commerce_json_response([
                'success' => (bool)($fcmResult['success'] ?? false),
                'device_type' => $user['fcm_device_type'] ?? 'mobile',
                'last_synced' => $user['fcm_updated_at'] ?? null,
                'fcm_response' => $fcmResult,
                'message' => ($fcmResult['success'] ?? false) 
                    ? "Test push notification successfully sent to your device!" 
                    : ("Push delivery failed: " . ($fcmResult['error'] ?? 'Unknown gateway error'))
            ]);
            break;
    }

} catch (Throwable $e) {
    http_response_code(400);
    commerce_json_response([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
