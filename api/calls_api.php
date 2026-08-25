<?php
ob_start();
session_start();
// api/calls_api.php - Calls & Voice Recordings Management REST API
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/commerce.php';
require_once __DIR__ . '/../includes/calls_helper.php';

header('Content-Type: application/json');

try {
    $context = commerce_get_tenant_context();
} catch (Throwable $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized: ' . $e->getMessage()]);
    exit;
}

$conn = $context['conn'];
$prefix = $context['prefix'];
$userId = $context['user_id'] ?? null;
$username = $context['username'] ?? 'User';

calls_ensure_tables($conn, $prefix);

$input = commerce_read_input();
$action = $_GET['action'] ?? $input['action'] ?? '';

try {
    switch ($action) {
        // 1. MODULE STATUS & SETTINGS FOR CURRENT USER / TENANT
        case 'status':
            $enabled = dm_get_system_setting($conn, $prefix, 'calls_enabled', '1') === '1';
            $visibility = dm_get_system_setting($conn, $prefix, 'calls_visibility', 'all');
            $allowBulkImport = dm_get_system_setting($conn, $prefix, 'calls_allow_bulk_import', '1') === '1';
            $storageConfig = calls_get_storage_config($conn, $prefix, $userId);

            // Sanitize sensitive config keys for frontend
            $safeStorage = [
                'provider' => $storageConfig['provider'] ?? 'local',
                'config_name' => $storageConfig['config_name'] ?? 'Local Storage',
                'is_active' => (bool)($storageConfig['is_active'] ?? true),
                'has_google_drive' => !empty($storageConfig['config_data']['access_token']) || !empty($storageConfig['config_data']['refresh_token']),
                'google_drive_folder' => $storageConfig['config_data']['folder_id'] ?? '',
                'has_s3' => !empty($storageConfig['config_data']['bucket'])
            ];

            commerce_json_response([
                'success' => true,
                'enabled' => $enabled,
                'visibility' => $visibility,
                'allow_bulk_import' => $allowBulkImport,
                'storage' => $safeStorage,
                'user' => [
                    'id' => $userId,
                    'username' => $username
                ]
            ]);
            break;

        // 2. LIST CALL RECORDS (Paginated & Filtered)
        case 'list':
            $page = max(1, (int)($_GET['page'] ?? $input['page'] ?? 1));
            $limit = min(100, max(1, (int)($_GET['limit'] ?? $input['limit'] ?? 25)));
            $offset = ($page - 1) * $limit;

            $where = ["1=1"];
            $params = [];

            // Filter: Call Type
            $type = trim($_GET['type'] ?? $input['type'] ?? '');
            if ($type && in_array($type, ['incoming', 'outgoing', 'missed', 'rejected', 'blocked'])) {
                $where[] = "c.call_type = ?";
                $params[] = $type;
            }

            // Filter: User / Agent
            $filterUserId = (int)($_GET['user_id'] ?? $input['user_id'] ?? 0);
            if ($filterUserId > 0) {
                $where[] = "c.user_id = ?";
                $params[] = $filterUserId;
            }

            // Filter: Has Recording
            if (isset($_GET['has_recording']) && $_GET['has_recording'] !== '') {
                if ($_GET['has_recording'] == '1') {
                    $where[] = "(c.recording_file_url IS NOT NULL AND c.recording_file_url != '')";
                } else if ($_GET['has_recording'] == '0') {
                    $where[] = "(c.recording_file_url IS NULL OR c.recording_file_url = '')";
                }
            }

            // Filter: Date Range
            $dateFrom = trim($_GET['date_from'] ?? $input['date_from'] ?? '');
            $dateTo = trim($_GET['date_to'] ?? $input['date_to'] ?? '');
            if ($dateFrom) {
                $where[] = "c.call_start_time >= ?";
                $params[] = $dateFrom . ' 00:00:00';
            }
            if ($dateTo) {
                $where[] = "c.call_start_time <= ?";
                $params[] = $dateTo . ' 23:59:59';
            }

            // Filter: Search query
            $q = trim($_GET['q'] ?? $input['q'] ?? '');
            if ($q) {
                $where[] = "(c.caller_number LIKE ? OR c.contact_name LIKE ? OR c.notes LIKE ?)";
                $params[] = "%{$q}%";
                $params[] = "%{$q}%";
                $params[] = "%{$q}%";
            }

            $whereSql = implode(" AND ", $where);

            // Total count
            $countStmt = $conn->prepare("SELECT COUNT(*) FROM {$prefix}calls c WHERE {$whereSql}");
            $countStmt->execute($params);
            $totalCount = (int)$countStmt->fetchColumn();

            // Rows query
            $sql = "SELECT c.*, 
                           cust.name as matched_customer_name, 
                           cust.email as matched_customer_email
                    FROM {$prefix}calls c
                    LEFT JOIN {$prefix}customers cust ON c.customer_id = cust.id
                    WHERE {$whereSql}
                    ORDER BY c.call_start_time DESC
                    LIMIT {$limit} OFFSET {$offset}";

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $calls = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Format rows
            foreach ($calls as &$call) {
                $call['duration_formatted'] = calls_format_duration((int)$call['duration']);
                $call['raw_data'] = json_decode($call['raw_data'] ?? '{}', true) ?: [];
                if (!empty($call['matched_customer_name']) && empty($call['contact_name'])) {
                    $call['contact_name'] = $call['matched_customer_name'];
                }
            }

            commerce_json_response([
                'success' => true,
                'calls' => $calls,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $totalCount,
                    'total_pages' => ceil($totalCount / $limit)
                ]
            ]);
            break;

        // 3. STATS & ANALYTICS
        case 'stats':
            $dateFrom = trim($_GET['date_from'] ?? $input['date_from'] ?? date('Y-m-d', strtotime('-30 days')));
            $dateTo = trim($_GET['date_to'] ?? $input['date_to'] ?? date('Y-m-d'));

            $stmt = $conn->prepare("SELECT 
                COUNT(*) as total_calls,
                SUM(CASE WHEN call_type = 'incoming' THEN 1 ELSE 0 END) as incoming_calls,
                SUM(CASE WHEN call_type = 'outgoing' THEN 1 ELSE 0 END) as outgoing_calls,
                SUM(CASE WHEN call_type = 'missed' THEN 1 ELSE 0 END) as missed_calls,
                SUM(CASE WHEN call_type = 'rejected' THEN 1 ELSE 0 END) as rejected_calls,
                SUM(duration) as total_duration_seconds,
                SUM(CASE WHEN recording_file_url IS NOT NULL AND recording_file_url != '' THEN 1 ELSE 0 END) as recorded_calls
                FROM {$prefix}calls 
                WHERE call_start_time >= ? AND call_start_time <= ?");
            $stmt->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            $stats['total_calls'] = (int)($stats['total_calls'] ?? 0);
            $stats['incoming_calls'] = (int)($stats['incoming_calls'] ?? 0);
            $stats['outgoing_calls'] = (int)($stats['outgoing_calls'] ?? 0);
            $stats['missed_calls'] = (int)($stats['missed_calls'] ?? 0);
            $stats['rejected_calls'] = (int)($stats['rejected_calls'] ?? 0);
            $stats['total_duration_seconds'] = (int)($stats['total_duration_seconds'] ?? 0);
            $stats['total_duration_formatted'] = calls_format_duration($stats['total_duration_seconds']);
            $stats['recorded_calls'] = (int)($stats['recorded_calls'] ?? 0);

            commerce_json_response(['success' => true, 'stats' => $stats]);
            break;

        // 4. BATCH SYNC CALL LOGS FROM MOBILE APP
        case 'sync_calls':
            $callsData = $input['calls'] ?? [];
            if (is_string($callsData)) {
                $callsData = json_decode($callsData, true) ?? [];
            }

            if (!is_array($callsData) || empty($callsData)) {
                throw new RuntimeException('No call records provided in request');
            }

            $syncedCount = 0;
            $updatedCount = 0;
            $skippedCount = 0;
            $syncedIds = [];

            // Prepare lookup queries for customer auto-matching
            $customerStmt = $conn->prepare("SELECT id, name FROM {$prefix}customers WHERE phone = ? OR phone LIKE ? LIMIT 1");
            $findExistingStmt = $conn->prepare("SELECT id, recording_file_url FROM {$prefix}calls WHERE caller_number = ? AND call_start_time = ? AND (user_id = ? OR user_id IS NULL) LIMIT 1");
            
            $insertStmt = $conn->prepare("INSERT INTO {$prefix}calls (
                user_id, user_name, contact_name, customer_id, caller_number,
                from_number, to_number, call_type, call_start_time, call_end_time,
                duration, sim_slot, sim_carrier, device_model, device_id,
                location, recording_file_url, recording_storage_type, notes,
                outcome, status, raw_data
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $updateStmt = $conn->prepare("UPDATE {$prefix}calls SET
                contact_name = COALESCE(NULLIF(?, ''), contact_name),
                duration = COALESCE(NULLIF(?, 0), duration),
                call_end_time = COALESCE(?, call_end_time),
                from_number = COALESCE(NULLIF(?, ''), from_number),
                to_number = COALESCE(NULLIF(?, ''), to_number),
                sim_slot = COALESCE(NULLIF(?, ''), sim_slot),
                sim_carrier = COALESCE(NULLIF(?, ''), sim_carrier),
                device_model = COALESCE(NULLIF(?, ''), device_model),
                location = COALESCE(NULLIF(?, ''), location),
                notes = COALESCE(NULLIF(?, ''), notes),
                outcome = COALESCE(NULLIF(?, ''), outcome),
                raw_data = COALESCE(?, raw_data)
                WHERE id = ?");

            foreach ($callsData as $item) {
                $callerNumber = trim($item['caller_number'] ?? $item['number'] ?? '');
                if (!$callerNumber) {
                    $skippedCount++;
                    continue;
                }

                // Normalize phone number format (strip spaces, dashes, parentheses)
                $cleanPhone = preg_replace('/[^\d+]/', '', $callerNumber);
                $startTime = date('Y-m-d H:i:s', strtotime($item['call_start_time'] ?? $item['timestamp'] ?? 'now'));
                $duration = (int)($item['duration'] ?? 0);
                
                $endTime = !empty($item['call_end_time']) 
                    ? date('Y-m-d H:i:s', strtotime($item['call_end_time']))
                    : date('Y-m-d H:i:s', strtotime($startTime) + $duration);

                $callType = strtolower(trim($item['call_type'] ?? $item['type'] ?? 'incoming'));
                if (!in_array($callType, ['incoming', 'outgoing', 'missed', 'rejected', 'blocked'])) {
                    $callType = 'incoming';
                }

                $contactName = trim($item['contact_name'] ?? $item['name'] ?? '');
                $simSlot = trim($item['sim_slot'] ?? $item['sim'] ?? 'SIM 1');
                $simCarrier = trim($item['sim_carrier'] ?? '');
                $simIdentifier = !empty($simCarrier) ? "{$simSlot} ({$simCarrier})" : $simSlot;

                $fromNumber = trim($item['from_number'] ?? '');
                if ($fromNumber === 'My Phone' || str_starts_with($fromNumber, 'SIM ')) {
                    $fromNumber = ($callType === 'incoming') ? $cleanPhone : '';
                }

                $toNumber = trim($item['to_number'] ?? '');
                if ($toNumber === 'My Phone' || str_starts_with($toNumber, 'SIM ')) {
                    $toNumber = ($callType === 'outgoing') ? $cleanPhone : '';
                }
                $deviceModel = trim($item['device_model'] ?? '');
                $deviceId = trim($item['device_id'] ?? '');
                $location = trim($item['location'] ?? '');
                $notes = trim($item['notes'] ?? '');
                $outcome = trim($item['outcome'] ?? '');
                $recordingUrl = trim($item['recording_file_url'] ?? $item['recording_url'] ?? '');
                $storageType = trim($item['recording_storage_type'] ?? 'local');
                $rawData = json_encode($item['raw_data'] ?? $item);

                // Auto-match customer in CRM if contact name is empty
                $customerId = !empty($item['customer_id']) ? (int)$item['customer_id'] : null;
                if (!$customerId) {
                    $phoneSuffix = substr($cleanPhone, -10);
                    $customerStmt->execute([$cleanPhone, "%{$phoneSuffix}"]);
                    $cust = $customerStmt->fetch(PDO::FETCH_ASSOC);
                    if ($cust) {
                        $customerId = (int)$cust['id'];
                        if (empty($contactName)) {
                            $contactName = $cust['name'];
                        }
                    }
                }

                // Check for existing record to avoid duplicate entries
                $findExistingStmt->execute([$cleanPhone, $startTime, $userId]);
                $resolvedCallUserId = !empty($item['user_id']) ? (int)$item['user_id'] : ($userId ?: 1);
                $resolvedCallUserName = !empty($item['user_name']) ? trim($item['user_name']) : $username;

                $callRecordSyncData = [
                    'caller_number' => $cleanPhone,
                    'contact_name' => $contactName,
                    'call_type' => $callType,
                    'call_start_time' => $startTime,
                    'call_end_time' => $endTime,
                    'duration' => $duration,
                    'from_number' => $fromNumber,
                    'to_number' => $toNumber,
                    'sim_slot' => $simSlot,
                    'sim_carrier' => $simCarrier,
                    'device_model' => $deviceModel,
                    'location' => $location,
                    'recording_file_url' => $recordingUrl,
                    'recording_storage_type' => $storageType,
                    'notes' => $notes,
                    'outcome' => $outcome,
                    'user_id' => $resolvedCallUserId,
                    'user_name' => $resolvedCallUserName,
                ];

                if ($existing) {
                    $callId = (int)$existing['id'];
                    $updateStmt->execute([
                        $contactName,
                        $duration,
                        $endTime,
                        $fromNumber,
                        $toNumber,
                        $simSlot,
                        $simCarrier,
                        $deviceModel,
                        $location,
                        $notes,
                        $outcome,
                        $rawData,
                        $callId
                    ]);
                    $updatedCount++;
                    $syncedIds[] = $callId;
                } else {
                    $insertStmt->execute([
                        $userId,
                        $username,
                        $contactName ?: null,
                        $customerId,
                        $cleanPhone,
                        $fromNumber ?: null,
                        $toNumber ?: null,
                        $callType,
                        $startTime,
                        $endTime,
                        $duration,
                        $simSlot ?: null,
                        $simCarrier ?: null,
                        $deviceModel ?: null,
                        $deviceId ?: null,
                        $location ?: null,
                        $recordingUrl ?: null,
                        $storageType,
                        $notes ?: null,
                        $outcome ?: null,
                        'synced',
                        $rawData
                    ]);
                    $callId = (int)$conn->lastInsertId();
                    $syncedCount++;
                    $syncedIds[] = $callId;
                }

                // Sync to dynamic module record as well
                calls_sync_to_dynamic_module_record($conn, $prefix, $callRecordSyncData, $userId);
            }

            commerce_json_response([
                'success' => true,
                'message' => "Successfully synced {$syncedCount} new calls and updated {$updatedCount} calls.",
                'synced_count' => $syncedCount,
                'updated_count' => $updatedCount,
                'skipped_count' => $skippedCount,
                'synced_ids' => $syncedIds
            ]);
            break;

        // 5. UPLOAD CALL RECORDING AUDIO FILE
        case 'upload_recording':
            if (empty($_FILES['recording_file']) && empty($_FILES['file']) && empty($_FILES['audio']) && empty($_FILES['recording'])) {
                throw new RuntimeException('No recording audio file provided in upload');
            }

            $uploadedFile = $_FILES['recording_file'] ?? $_FILES['file'] ?? $_FILES['audio'] ?? $_FILES['recording'];
            if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('File upload error code: ' . $uploadedFile['error']);
            }

            $callId = (int)($_POST['call_id'] ?? $input['call_id'] ?? 0);
            $callerNumber = trim($_POST['caller_number'] ?? $input['caller_number'] ?? '');
            $callStartTime = trim($_POST['call_start_time'] ?? $input['call_start_time'] ?? '');

            $callMeta = [
                'caller_number' => $callerNumber,
                'call_start_time' => $callStartTime,
                'user_id' => $userId
            ];

            // If call_id not provided, match by caller_number and/or call_start_time
            if ($callId <= 0 && (!empty($callerNumber) || !empty($callStartTime))) {
                $cleanNum = preg_replace('/[^\d+]/', '', $callerNumber);
                $cParams = [];
                $cWhere = ["1=1"];
                if ($cleanNum) {
                    $cWhere[] = "(caller_number = ? OR caller_number LIKE ?)";
                    $cParams[] = $cleanNum;
                    $cParams[] = "%{$cleanNum}%";
                }
                if ($callStartTime) {
                    $cWhere[] = "call_start_time LIKE ?";
                    $cParams[] = substr(date('Y-m-d H:i:s', strtotime($callStartTime)), 0, 16) . '%';
                }
                $cWhereSql = implode(' AND ', $cWhere);
                $findCallStmt = $conn->prepare("SELECT id, caller_number, call_start_time FROM {$prefix}calls WHERE {$cWhereSql} ORDER BY call_start_time DESC LIMIT 1");
                $findCallStmt->execute($cParams);
                $cFound = $findCallStmt->fetch(PDO::FETCH_ASSOC);
                if ($cFound) {
                    $callId = (int)$cFound['id'];
                    $callMeta['caller_number'] = $cFound['caller_number'];
                    $callMeta['call_start_time'] = $cFound['call_start_time'];
                }
            }

            // If call_id provided, fetch its metadata
            if ($callId > 0) {
                $stmt = $conn->prepare("SELECT * FROM {$prefix}calls WHERE id = ?");
                $stmt->execute([$callId]);
                $cRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($cRow) {
                    $callMeta['caller_number'] = $cRow['caller_number'];
                    $callMeta['call_start_time'] = $cRow['call_start_time'];
                }
            }

            // Process upload with configured storage provider (Google Drive / S3 / Local)
            $uploadResult = calls_process_recording_upload($conn, $prefix, $uploadedFile, $callMeta, $userId);

            if (!empty($uploadResult['file_url'])) {
                if ($callId > 0) {
                    $stmt = $conn->prepare("UPDATE {$prefix}calls SET 
                        recording_file_url = ?, 
                        recording_storage_type = ?, 
                        recording_file_id = ?, 
                        recording_file_size = ? 
                        WHERE id = ?");
                    $stmt->execute([
                        $uploadResult['file_url'],
                        $uploadResult['storage_type'] ?? 'local',
                        $uploadResult['file_id'] ?? null,
                        $uploadResult['file_size'] ?? 0,
                        $callId
                    ]);

                    // Sync updated recording URL to the dynamic module record
                    $cRowStmt = $conn->prepare("SELECT * FROM {$prefix}calls WHERE id = ?");
                    $cRowStmt->execute([$callId]);
                    $fullCallRow = $cRowStmt->fetch(PDO::FETCH_ASSOC);
                    if ($fullCallRow) {
                        calls_sync_to_dynamic_module_record($conn, $prefix, $fullCallRow, $userId);
                    }
                } else if (!empty($callerNumber)) {
                    // Update dynamic module record directly even if calls table record wasn't found
                    $directSyncData = [
                        'caller_number' => $callerNumber,
                        'call_start_time' => $callStartTime ?: date('Y-m-d H:i:s'),
                        'recording_file_url' => $uploadResult['file_url'],
                        'recording_storage_type' => $uploadResult['storage_type'] ?? 'google_drive',
                    ];
                    calls_sync_to_dynamic_module_record($conn, $prefix, $directSyncData, $userId);
                }

                commerce_json_response([
                    'success' => true,
                    'message' => 'Recording uploaded successfully',
                    'file_url' => $uploadResult['file_url'],
                    'storage_type' => $uploadResult['storage_type'] ?? 'local',
                    'file_id' => $uploadResult['file_id'] ?? '',
                    'web_view_link' => $uploadResult['web_view_link'] ?? $uploadResult['file_url']
                ]);
            } else {
                throw new RuntimeException('Failed to upload recording file');
            }
            break;

        // 6. CREATE / MANUALLY LOG A CALL
        case 'create_call':
            $callerNumber = trim($input['caller_number'] ?? '');
            if (!$callerNumber) throw new RuntimeException('Caller number is required');

            $cleanPhone = preg_replace('/[^\d+]/', '', $callerNumber);
            $contactName = trim($input['contact_name'] ?? '');
            $callType = strtolower(trim($input['call_type'] ?? 'outgoing'));
            if (!in_array($callType, ['incoming', 'outgoing', 'missed', 'rejected', 'blocked'])) {
                $callType = 'outgoing';
            }

            $startTime = !empty($input['call_start_time']) 
                ? date('Y-m-d H:i:s', strtotime($input['call_start_time'])) 
                : date('Y-m-d H:i:s');
            
            $duration = (int)($input['duration'] ?? 0);
            $endTime = !empty($input['call_end_time']) 
                ? date('Y-m-d H:i:s', strtotime($input['call_end_time'])) 
                : date('Y-m-d H:i:s', strtotime($startTime) + $duration);

            $simSlot = trim($input['sim_slot'] ?? 'SIM 1');
            $notes = trim($input['notes'] ?? '');
            $outcome = trim($input['outcome'] ?? '');
            $customerId = !empty($input['customer_id']) ? (int)$input['customer_id'] : null;

            // Auto-match customer if not provided
            if (!$customerId) {
                $phoneSuffix = substr($cleanPhone, -10);
                $custStmt = $conn->prepare("SELECT id, name FROM {$prefix}customers WHERE phone = ? OR phone LIKE ? LIMIT 1");
                $custStmt->execute([$cleanPhone, "%{$phoneSuffix}"]);
                $cMatch = $custStmt->fetch(PDO::FETCH_ASSOC);
                if ($cMatch) {
                    $customerId = (int)$cMatch['id'];
                    if (empty($contactName)) $contactName = $cMatch['name'];
                }
            }

            $fromNumber = $callType === 'outgoing' ? 'My Phone' : $cleanPhone;
            $toNumber = $callType === 'outgoing' ? $cleanPhone : 'My Phone';

            $stmt = $conn->prepare("INSERT INTO {$prefix}calls (
                user_id, user_name, contact_name, customer_id, caller_number,
                from_number, to_number, call_type, call_start_time, call_end_time,
                duration, sim_slot, notes, outcome, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $stmt->execute([
                $userId,
                $username,
                $contactName ?: null,
                $customerId,
                $cleanPhone,
                $fromNumber,
                $toNumber,
                $callType,
                $startTime,
                $endTime,
                $duration,
                $simSlot,
                $notes ?: null,
                $outcome ?: null,
                'manual'
            ]);

            $newCallId = (int)$conn->lastInsertId();

            calls_sync_to_dynamic_module_record($conn, $prefix, [
                'caller_number' => $cleanPhone,
                'contact_name' => $contactName,
                'call_type' => $callType,
                'call_start_time' => $startTime,
                'call_end_time' => $endTime,
                'duration' => $duration,
                'from_number' => $fromNumber,
                'to_number' => $toNumber,
                'sim_slot' => $simSlot,
                'notes' => $notes,
                'outcome' => $outcome,
                'user_name' => $username,
            ], $userId);

            commerce_json_response([
                'success' => true,
                'message' => 'Call logged successfully',
                'id' => $newCallId
            ]);
            break;

        // 7. CONVERT CALL TO LEAD / CUSTOMER
        case 'convert_to_customer':
        case 'convert_to_lead':
            $callId = (int)($input['call_id'] ?? $input['id'] ?? 0);
            $name = trim($input['name'] ?? '');
            $phone = trim($input['phone'] ?? '');
            $email = trim($input['email'] ?? '');
            $address = trim($input['billing_address'] ?? $input['address'] ?? '');
            $notes = trim($input['notes'] ?? '');

            if (!$name) throw new RuntimeException('Customer / Lead name is required');
            if (!$phone) throw new RuntimeException('Phone number is required');

            $cleanPhone = preg_replace('/[^\d+]/', '', $phone);
            $phoneSuffix = substr($cleanPhone, -10);

            // Check if customer already exists with this phone
            $cStmt = $conn->prepare("SELECT id, name FROM {$prefix}customers WHERE phone = ? OR phone LIKE ? LIMIT 1");
            $cStmt->execute([$cleanPhone, "%{$phoneSuffix}"]);
            $existingCust = $cStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingCust) {
                $customerId = (int)$existingCust['id'];
                // Update customer name/email if blank
                $conn->prepare("UPDATE {$prefix}customers SET 
                    email = COALESCE(NULLIF(?, ''), email),
                    billing_address = COALESCE(NULLIF(?, ''), billing_address)
                    WHERE id = ?")->execute([$email, $address, $customerId]);
            } else {
                // Generate unique customer code
                $code = 'CUST-' . strtoupper(substr(uniqid(), -6));
                $insCust = $conn->prepare("INSERT INTO {$prefix}customers (
                    customer_code, name, phone, email, billing_address, created_by
                ) VALUES (?, ?, ?, ?, ?, ?)");
                $insCust->execute([$code, $name, $cleanPhone, $email ?: null, $address ?: null, $userId]);
                $customerId = (int)$conn->lastInsertId();
            }

            // Link customer to this call record
            if ($callId > 0) {
                $conn->prepare("UPDATE {$prefix}calls SET customer_id = ?, contact_name = ?, notes = COALESCE(NULLIF(?, ''), notes) WHERE id = ?")
                     ->execute([$customerId, $name, $notes, $callId]);
            }

            commerce_json_response([
                'success' => true,
                'message' => 'Successfully converted to CRM Customer / Lead!',
                'customer_id' => $customerId,
                'customer_name' => $name
            ]);
            break;

        // 8. UPDATE CALL RECORD (Notes, Outcome, Link Customer)
        case 'update_call':
            $callId = (int)($input['id'] ?? $input['call_id'] ?? $_POST['id'] ?? 0);
            if (!$callId) throw new RuntimeException('Call ID is required');

            $notes = $input['notes'] ?? null;
            $outcome = $input['outcome'] ?? null;
            $contactName = $input['contact_name'] ?? null;
            $customerId = isset($input['customer_id']) ? (int)$input['customer_id'] : null;

            $fields = [];
            $params = [];

            if ($notes !== null) { $fields[] = "notes = ?"; $params[] = trim($notes); }
            if ($outcome !== null) { $fields[] = "outcome = ?"; $params[] = trim($outcome); }
            if ($contactName !== null) { $fields[] = "contact_name = ?"; $params[] = trim($contactName); }
            if ($customerId !== null) { $fields[] = "customer_id = ?"; $params[] = $customerId ?: null; }

            if (empty($fields)) {
                throw new RuntimeException('No fields to update');
            }

            $params[] = $callId;
            $sql = "UPDATE {$prefix}calls SET " . implode(", ", $fields) . " WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);

            commerce_json_response(['success' => true, 'message' => 'Call updated successfully']);
            break;

        // 9. DELETE CALL RECORD
        case 'delete_call':
            $callId = (int)($input['id'] ?? $input['call_id'] ?? $_GET['id'] ?? 0);
            if (!$callId) throw new RuntimeException('Call ID is required');

            $stmt = $conn->prepare("DELETE FROM {$prefix}calls WHERE id = ?");
            $stmt->execute([$callId]);

            commerce_json_response(['success' => true, 'message' => 'Call record deleted']);
            break;

        // 8. STORAGE CONFIGURATIONS
        case 'get_storage_config':
            $forUser = (int)($_GET['for_user'] ?? $userId);
            $cfg = calls_get_storage_config($conn, $prefix, $forUser);
            commerce_json_response(['success' => true, 'config' => $cfg]);
            break;

        case 'save_storage_config':
            $configId = calls_save_storage_config($conn, $prefix, $input, $userId);
            commerce_json_response(['success' => true, 'message' => 'Storage configuration saved', 'id' => $configId]);
            break;

        case 'save_admin_settings':
            if (isset($input['allow_bulk_import'])) {
                $val = $input['allow_bulk_import'] ? '1' : '0';
                dm_set_system_setting($conn, $prefix, 'calls_allow_bulk_import', $val);
            }
            if (isset($input['calls_enabled'])) {
                $val = $input['calls_enabled'] ? '1' : '0';
                dm_set_system_setting($conn, $prefix, 'calls_enabled', $val);
            }
            commerce_json_response(['success' => true, 'message' => 'Admin sync settings saved successfully']);
            break;

        default:
            throw new RuntimeException("Unknown action: {$action}");
    }
} catch (Throwable $e) {
    commerce_json_response(['success' => false, 'error' => $e->getMessage()], 400);
}
