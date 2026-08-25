<?php
// includes/calls_helper.php - Calls & Cloud Storage Helper Utilities
require_once __DIR__ . '/dynamic_modules.php';

function calls_ensure_tables(PDO $conn, string $p): void
{
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS {$p}calls (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            user_name VARCHAR(150) NULL,
            contact_name VARCHAR(150) NULL,
            customer_id INT NULL,
            caller_number VARCHAR(50) NOT NULL,
            from_number VARCHAR(50) NULL,
            to_number VARCHAR(50) NULL,
            call_type ENUM('incoming', 'outgoing', 'missed', 'rejected', 'blocked', 'unknown') NOT NULL DEFAULT 'incoming',
            call_start_time DATETIME NOT NULL,
            call_end_time DATETIME NULL,
            duration INT NOT NULL DEFAULT 0,
            sim_slot VARCHAR(50) NULL,
            sim_carrier VARCHAR(100) NULL,
            device_model VARCHAR(150) NULL,
            device_id VARCHAR(100) NULL,
            location VARCHAR(150) NULL,
            recording_file_url TEXT NULL,
            recording_storage_type VARCHAR(50) NOT NULL DEFAULT 'local',
            recording_file_id VARCHAR(255) NULL,
            recording_file_size INT NOT NULL DEFAULT 0,
            notes TEXT NULL,
            outcome VARCHAR(100) NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'synced',
            raw_data JSON NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_calls_number (caller_number),
            INDEX idx_calls_user (user_id),
            INDEX idx_calls_type (call_type),
            INDEX idx_calls_start (call_start_time),
            INDEX idx_calls_customer (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Throwable $e) {}

    // Auto-migrate new columns if table existed prior
    try { @$conn->exec("ALTER TABLE {$p}calls ADD COLUMN from_number VARCHAR(50) NULL AFTER caller_number"); } catch (Throwable $e) {}
    try { @$conn->exec("ALTER TABLE {$p}calls ADD COLUMN to_number VARCHAR(50) NULL AFTER from_number"); } catch (Throwable $e) {}
    try { @$conn->exec("ALTER TABLE {$p}calls ADD COLUMN sim_carrier VARCHAR(100) NULL AFTER sim_slot"); } catch (Throwable $e) {}
    try { @$conn->exec("ALTER TABLE {$p}calls ADD COLUMN device_model VARCHAR(150) NULL AFTER sim_carrier"); } catch (Throwable $e) {}
    try { @$conn->exec("ALTER TABLE {$p}calls ADD COLUMN location VARCHAR(150) NULL AFTER device_id"); } catch (Throwable $e) {}

    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS {$p}call_storage_configs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            provider ENUM('google_drive', 's3', 'cloudflare_r2', 'local', 'dropbox') NOT NULL DEFAULT 'local',
            config_name VARCHAR(150) NOT NULL,
            config_data JSON NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Throwable $e) {}

    // Automatically ensure the Dynamic Calls Module is created in the CRM
    calls_ensure_dynamic_module($conn, $p);
}

/**
 * Automatically create the Dynamic "Calls" Module in CRM with all call log fields
 */
function calls_ensure_dynamic_module(PDO $conn, string $p): int
{
    try {
        // Check if calls module already exists
        $stmt = $conn->prepare("SELECT id, status FROM {$p}modules WHERE slug = 'calls' LIMIT 1");
        $stmt->execute();
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $moduleId = (int)$existing['id'];
            if ($existing['status'] !== 'active') {
                $conn->prepare("UPDATE {$p}modules SET status = 'active' WHERE id = ?")->execute([$moduleId]);
            }
            return $moduleId;
        }

        // 1. Create Module Entry
        $modStmt = $conn->prepare("INSERT INTO {$p}modules (
            name, slug, icon, description, status, sort_order,
            visibility_rule, edit_rule, delete_rule,
            enable_create, enable_quickcreate, enable_import, enable_export, enable_multidelete
        ) VALUES (
            'Calls', 'calls', 'fa-solid fa-phone-volume',
            'Mobile call logs, recording files, SIM details, duration, caller identification, and customer follow-up notes.',
            'active', 1, 'all', 'all', 'all', 1, 1, 1, 1, 1
        )");
        $modStmt->execute();
        $moduleId = (int)$conn->lastInsertId();

        // 2. Create Blocks
        // Block 1: Call Information
        $b1Stmt = $conn->prepare("INSERT INTO {$p}module_blocks (module_id, name, sort_order) VALUES (?, 'Call Information', 1)");
        $b1Stmt->execute([$moduleId]);
        $block1Id = (int)$conn->lastInsertId();

        // Block 2: Number & Device / SIM Details
        $b2Stmt = $conn->prepare("INSERT INTO {$p}module_blocks (module_id, name, sort_order) VALUES (?, 'Number & Device / SIM Details', 2)");
        $b2Stmt->execute([$moduleId]);
        $block2Id = (int)$conn->lastInsertId();

        // Block 3: Voice Recording & Cloud Storage
        $b3Stmt = $conn->prepare("INSERT INTO {$p}module_blocks (module_id, name, sort_order) VALUES (?, 'Voice Recording & Cloud Storage', 3)");
        $b3Stmt->execute([$moduleId]);
        $block3Id = (int)$conn->lastInsertId();

        // Block 4: Outcome & Follow-Up Remarks
        $b4Stmt = $conn->prepare("INSERT INTO {$p}module_blocks (module_id, name, sort_order) VALUES (?, 'Outcome & Follow-Up Remarks', 4)");
        $b4Stmt->execute([$moduleId]);
        $block4Id = (int)$conn->lastInsertId();

        // 3. Helper to insert fields
        $fieldStmt = $conn->prepare("INSERT INTO {$p}module_fields (
            block_id, module_id, field_key, label, field_type, placeholder, default_value,
            is_required, is_unique, is_searchable, is_list_visible, is_quick_create, is_mobile_list_visible, sort_order
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $optStmt = $conn->prepare("INSERT INTO {$p}module_field_options (field_id, label, value, sort_order) VALUES (?, ?, ?, ?)");

        // Fields for Block 1 (Call Information)
        $fieldStmt->execute([$block1Id, $moduleId, 'caller_number', 'Caller / Phone Number', 'phone', '+91 98765 43210', '', 1, 0, 1, 1, 1, 1, 1]);
        $fieldStmt->execute([$block1Id, $moduleId, 'contact_name', 'Contact / Customer Name', 'text', 'e.g. John Doe', '', 0, 0, 1, 1, 1, 1, 2]);

        $fieldStmt->execute([$block1Id, $moduleId, 'call_type', 'Call Type', 'dropdown', '', 'incoming', 1, 0, 1, 1, 1, 1, 3]);
        $callTypeFieldId = (int)$conn->lastInsertId();
        $callTypes = [
            ['Incoming Call', 'incoming'],
            ['Outgoing Call', 'outgoing'],
            ['Missed Call', 'missed'],
            ['Rejected Call', 'rejected'],
            ['Blocked Call', 'blocked'],
        ];
        foreach ($callTypes as $idx => $ct) {
            $optStmt->execute([$callTypeFieldId, $ct[0], $ct[1], $idx + 1]);
        }

        $fieldStmt->execute([$block1Id, $moduleId, 'call_start_time', 'Call Start Time', 'datetime', '', 'now', 1, 0, 1, 1, 1, 1, 4]);
        $fieldStmt->execute([$block1Id, $moduleId, 'call_end_time', 'Call End Time', 'datetime', '', '', 0, 0, 1, 1, 1, 0, 5]);
        $fieldStmt->execute([$block1Id, $moduleId, 'duration_formatted', 'Call Duration (MM:SS)', 'text', 'e.g. 03:45', '', 0, 0, 1, 1, 0, 1, 6]);

        // Fields for Block 2 (Number & Device / SIM Details)
        $fieldStmt->execute([$block2Id, $moduleId, 'from_number', 'From Number (Caller)', 'phone', 'e.g. Caller Number', '', 0, 0, 1, 1, 0, 0, 7]);
        $fieldStmt->execute([$block2Id, $moduleId, 'to_number', 'To Number (Receiver)', 'phone', 'e.g. Receiver Number', '', 0, 0, 1, 1, 0, 0, 8]);

        $fieldStmt->execute([$block2Id, $moduleId, 'sim_slot', 'SIM Slot', 'dropdown', '', 'SIM 1', 0, 0, 1, 1, 0, 1, 9]);
        $simSlotFieldId = (int)$conn->lastInsertId();
        $simSlots = [['SIM 1', 'SIM 1'], ['SIM 2', 'SIM 2'], ['eSIM', 'eSIM']];
        foreach ($simSlots as $idx => $ss) {
            $optStmt->execute([$simSlotFieldId, $ss[0], $ss[1], $idx + 1]);
        }

        $fieldStmt->execute([$block2Id, $moduleId, 'sim_carrier', 'SIM Carrier / Operator', 'text', 'e.g. Airtel, Jio, Vi', '', 0, 0, 1, 1, 0, 0, 10]);
        $fieldStmt->execute([$block2Id, $moduleId, 'device_model', 'Device Model & OS', 'text', 'e.g. Samsung Galaxy / Android', '', 0, 0, 0, 0, 0, 0, 11]);
        $fieldStmt->execute([$block2Id, $moduleId, 'call_location', 'Location / Region', 'text', 'e.g. Mumbai, India', '', 0, 0, 1, 0, 0, 0, 12]);

        // Fields for Block 3 (Voice Recording & Cloud Storage)
        $fieldStmt->execute([$block3Id, $moduleId, 'recording_file_url', 'Call Recording Link', 'url', 'https://drive.google.com/...', '', 0, 0, 0, 1, 0, 1, 13]);

        $fieldStmt->execute([$block3Id, $moduleId, 'recording_storage_provider', 'Cloud Storage Provider', 'dropdown', '', 'google_drive', 0, 0, 1, 1, 0, 0, 14]);
        $storageFieldId = (int)$conn->lastInsertId();
        $storageProviders = [
            ['Google Drive', 'google_drive'],
            ['AWS S3 Cloud', 's3'],
            ['Cloudflare R2', 'cloudflare_r2'],
            ['Local CRM Server', 'local']
        ];
        foreach ($storageProviders as $idx => $sp) {
            $optStmt->execute([$storageFieldId, $sp[0], $sp[1], $idx + 1]);
        }

        // Fields for Block 4 (Outcome & Follow-Up Remarks)
        $fieldStmt->execute([$block4Id, $moduleId, 'call_outcome', 'Call Outcome / Lead Status', 'dropdown', '', '', 0, 0, 1, 1, 1, 1, 15]);
        $outcomeFieldId = (int)$conn->lastInsertId();
        $outcomes = [
            ['Interested / Hot Lead', 'Interested'],
            ['Callback Requested', 'Callback Requested'],
            ['Requirement Gathered', 'Requirement Gathered'],
            ['Deal Closed', 'Deal Closed'],
            ['Follow-up Needed', 'Follow-up Needed'],
            ['Not Interested', 'Not Interested'],
            ['Wrong Number', 'Wrong Number'],
            ['No Answer / Busy', 'No Answer'],
        ];
        foreach ($outcomes as $idx => $oc) {
            $optStmt->execute([$outcomeFieldId, $oc[0], $oc[1], $idx + 1]);
        }

        $fieldStmt->execute([$block4Id, $moduleId, 'call_notes', 'Call Notes / Conversation Remarks', 'textarea', 'Enter discussion points and follow-up notes...', '', 0, 0, 1, 0, 1, 0, 16]);
        $fieldStmt->execute([$block4Id, $moduleId, 'assigned_agent', 'Assigned Agent / Rep', 'text', 'e.g. Agent Name', '', 0, 0, 1, 1, 0, 1, 17]);

        try {
            $conn->exec("UPDATE {$p}module_fields SET is_list_visible = 1, is_searchable = 1 WHERE module_id = {$moduleId} AND field_key = 'call_end_time'");
        } catch (Throwable $e) {}

        return $moduleId;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Automatically sync a call record into the dynamic Calls module
 */
function calls_sync_to_dynamic_module_record(PDO $conn, string $p, array $callData, ?int $userId): void
{
    try {
        $moduleId = calls_ensure_dynamic_module($conn, $p);
        if (!$moduleId) return;

        // Fetch field map and types for calls module
        $fStmt = $conn->prepare("SELECT field_key, id, field_type FROM {$p}module_fields WHERE module_id = ?");
        $fStmt->execute([$moduleId]);
        $rawFields = $fStmt->fetchAll(PDO::FETCH_ASSOC);
        $fields = [];
        $fieldTypes = [];
        foreach ($rawFields as $rf) {
            $fields[$rf['field_key']] = (int)$rf['id'];
            $fieldTypes[$rf['field_key']] = $rf['field_type'];
        }
        if (empty($fields)) return;

        $callerNumber = $callData['caller_number'] ?? '';
        $startTime = $callData['call_start_time'] ?? date('Y-m-d H:i:s');
        $effectiveUserId = !empty($callData['user_id']) ? (int)$callData['user_id'] : ($userId ?: 1);

        // Check if dynamic record exists (by start time + caller number in record_values)
        $numFieldId = $fields['caller_number'] ?? 0;
        $startFieldId = $fields['call_start_time'] ?? 0;
        
        $recId = null;
        if ($numFieldId && $startFieldId) {
            $checkStmt = $conn->prepare("SELECT v1.record_id 
                FROM {$p}module_record_values v1
                JOIN {$p}module_record_values v2 ON v1.record_id = v2.record_id
                WHERE v1.field_id = ? AND v1.value = ?
                  AND v2.field_id = ? AND v2.value LIKE ?
                LIMIT 1");
            $checkStmt->execute([$numFieldId, $callerNumber, $startFieldId, substr($startTime, 0, 16) . '%']);
            $recId = $checkStmt->fetchColumn();
        }

        if (!$recId) {
            $rStmt = $conn->prepare("INSERT INTO {$p}module_records (module_id, created_by, created_at) VALUES (?, ?, ?)");
            $rStmt->execute([$moduleId, $effectiveUserId, $startTime]);
            $recId = (int)$conn->lastInsertId();
        }

        $agentUid = (string)$effectiveUserId;
        $agentUname = !empty($callData['user_name']) ? (string)$callData['user_name'] : '';
        $agentFieldKey = isset($fields['assigned_agent']) ? 'assigned_agent' : (isset($fields['assigned_to']) ? 'assigned_to' : null);
        $agentFieldType = $agentFieldKey ? ($fieldTypes[$agentFieldKey] ?? 'text') : 'text';
        $resolvedAgentVal = ($agentFieldType === 'assigned_to' || $agentFieldType === 'user') ? $agentUid : ($agentUname ?: $agentUid);

        // Map values
        $valMap = [
            'caller_number' => $callerNumber,
            'contact_name' => $callData['contact_name'] ?? '',
            'call_type' => $callData['call_type'] ?? 'incoming',
            'call_start_time' => $startTime,
            'call_end_time' => $callData['call_end_time'] ?? '',
            'duration_formatted' => calls_format_duration((int)($callData['duration'] ?? 0)),
            'from_number' => (!empty($callData['from_number']) && $callData['from_number'] !== 'My Phone' && !str_starts_with($callData['from_number'], 'SIM ')) ? $callData['from_number'] : (($callData['call_type'] ?? '') === 'incoming' ? $callerNumber : ''),
            'to_number' => (!empty($callData['to_number']) && $callData['to_number'] !== 'My Phone' && !str_starts_with($callData['to_number'], 'SIM ')) ? $callData['to_number'] : (($callData['call_type'] ?? '') === 'outgoing' ? $callerNumber : ''),
            'sim_slot' => $callData['sim_slot'] ?? 'SIM 1',
            'sim_carrier' => $callData['sim_carrier'] ?? '',
            'device_model' => $callData['device_model'] ?? '',
            'call_location' => $callData['location'] ?? '',
            'recording_file_url' => $callData['recording_file_url'] ?? '',
            'recording_storage_provider' => $callData['recording_storage_type'] ?? 'google_drive',
            'call_outcome' => $callData['outcome'] ?? '',
            'call_notes' => $callData['notes'] ?? '',
            'assigned_agent' => $resolvedAgentVal,
            'assigned_to' => $resolvedAgentVal,
        ];

        $insValStmt = $conn->prepare("INSERT INTO {$p}module_record_values (record_id, field_id, value) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE value = VALUES(value)");

        foreach ($valMap as $key => $val) {
            if (isset($fields[$key]) && $val !== '' && $val !== null) {
                $insValStmt->execute([$recId, $fields[$key], (string)$val]);
            }
        }
    } catch (Throwable $e) {}
}

/**
 * Format duration in seconds to MM:SS or HH:MM:SS
 */
function calls_format_duration(int $seconds): string
{
    if ($seconds <= 0) return '00:00';
    $hours = floor($seconds / 3600);
    $mins = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;

    if ($hours > 0) {
        return sprintf('%02d:%02d:%02d', $hours, $mins, $secs);
    }
    return sprintf('%02d:%02d', $mins, $secs);
}

/**
 * Fetch storage config for a specific user or the company default
 */
function calls_get_storage_config(PDO $conn, string $p, ?int $userId = null): ?array
{
    calls_ensure_tables($conn, $p);

    if ($userId !== null && $userId > 0) {
        $stmt = $conn->prepare("SELECT * FROM {$p}call_storage_configs WHERE user_id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$userId]);
        $cfg = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cfg) {
            $cfg['config_data'] = json_decode($cfg['config_data'] ?? '{}', true) ?: [];
            return $cfg;
        }
    }

    // Default company config
    $stmt = $conn->prepare("SELECT * FROM {$p}call_storage_configs WHERE (user_id IS NULL OR user_id = 0) AND is_active = 1 ORDER BY is_default DESC, id DESC LIMIT 1");
    $stmt->execute();
    $cfg = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($cfg) {
        $cfg['config_data'] = json_decode($cfg['config_data'] ?? '{}', true) ?: [];
        return $cfg;
    }

    // Fallback default: Google Drive / Local
    return [
        'id' => 0,
        'user_id' => null,
        'provider' => 'google_drive',
        'config_name' => 'Google Drive Cloud Storage',
        'config_data' => [
            'folder' => 'uploads/recordings/'
        ],
        'is_default' => 1,
        'is_active' => 1
    ];
}

/**
 * Save / update storage config
 */
function calls_save_storage_config(PDO $conn, string $p, array $data, ?int $userId = null): int
{
    calls_ensure_tables($conn, $p);
    $provider = trim($data['provider'] ?? 'google_drive');
    $configName = trim($data['config_name'] ?? ucfirst($provider) . ' Storage');
    $configData = is_array($data['config_data'] ?? null) ? json_encode($data['config_data']) : ($data['config_data'] ?? '{}');
    $isDefault = (int)($data['is_default'] ?? 1);
    $isActive = (int)($data['is_active'] ?? 1);
    $id = (int)($data['id'] ?? 0);

    if ($isDefault) {
        if ($userId) {
            $conn->prepare("UPDATE {$p}call_storage_configs SET is_default = 0 WHERE user_id = ?")->execute([$userId]);
        } else {
            $conn->prepare("UPDATE {$p}call_storage_configs SET is_default = 0 WHERE (user_id IS NULL OR user_id = 0)")->execute();
        }
    }

    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE {$p}call_storage_configs SET provider = ?, config_name = ?, config_data = ?, is_default = ?, is_active = ? WHERE id = ?");
        $stmt->execute([$provider, $configName, $configData, $isDefault, $isActive, $id]);
        return $id;
    } else {
        $stmt = $conn->prepare("INSERT INTO {$p}call_storage_configs (user_id, provider, config_name, config_data, is_default, is_active) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId ?: null, $provider, $configName, $configData, $isDefault, $isActive]);
        return (int)$conn->lastInsertId();
    }
}

/**
 * Upload recording file to configured storage provider
 */
function calls_process_recording_upload(PDO $conn, string $p, array $uploadedFile, array $callMeta, ?int $userId = null): array
{
    $config = calls_get_storage_config($conn, $p, $userId);
    $provider = $config['provider'] ?? 'google_drive';
    $cfgData = $config['config_data'] ?? [];

    $tmpPath = $uploadedFile['tmp_name'];
    $origName = $uploadedFile['name'] ?? 'recording.mp3';
    $fileSize = (int)($uploadedFile['size'] ?? filesize($tmpPath));
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION)) ?: 'mp3';

    $callerNumber = preg_replace('/[^0-9+]/', '', $callMeta['caller_number'] ?? 'unknown');
    $callTime = !empty($callMeta['call_start_time']) ? strtotime($callMeta['call_start_time']) : time();
    if ($callTime <= 0) $callTime = time();

    // Format: call_{callerNumber}_{YYYYMMDD_HHMMSS}.{ext}
    $timestamp = date('Ymd_His', $callTime);
    $safeFileName = "call_{$callerNumber}_{$timestamp}.{$ext}";

    switch ($provider) {
        case 'google_drive':
            return calls_upload_to_google_drive($conn, $p, $tmpPath, $safeFileName, $config, $origName, $callMeta);

        case 's3':
        case 'cloudflare_r2':
            return calls_upload_to_s3_compatible($tmpPath, $safeFileName, $cfgData, $provider, $callMeta);

        case 'local':
        default:
            return calls_upload_to_local_storage($tmpPath, $safeFileName, $cfgData, $callMeta);
    }
}

/**
 * Upload to Local CRM Web Storage
 */
function calls_upload_to_local_storage(string $tmpPath, string $filename, array $cfg, array $callMeta = []): array
{
    $callTime = !empty($callMeta['call_start_time']) ? strtotime($callMeta['call_start_time']) : time();
    if ($callTime <= 0) $callTime = time();

    $year = date('Y', $callTime);
    $month = date('m', $callTime);
    $day = date('d', $callTime);

    $subDir = 'uploads/recordings/' . $year . '/' . $month . '/' . $day . '/';
    $targetDir = dirname(__DIR__) . '/' . $subDir;

    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0755, true);
    }

    $destPath = $targetDir . $filename;
    if (move_uploaded_file($tmpPath, $destPath) || copy($tmpPath, $destPath)) {
        @chmod($destPath, 0644);
        return [
            'success' => true,
            'storage_type' => 'local',
            'file_url' => $subDir . $filename,
            'file_id' => $filename,
            'file_size' => filesize($destPath)
        ];
    }

    throw new RuntimeException('Failed to save recording file locally');
}

function calls_get_effective_google_credentials(?array $cfgData = null): array
{
    $clientId = trim($cfgData['client_id'] ?? '');
    $clientSecret = trim($cfgData['client_secret'] ?? '');

    if (!$clientId || !$clientSecret) {
        $globalId = (string)dm_get_global_setting('google_drive_client_id', '');
        $globalSecret = (string)dm_get_global_setting('google_drive_client_secret', '');
        if ($globalId && $globalSecret) {
            $clientId = $clientId ?: $globalId;
            $clientSecret = $clientSecret ?: $globalSecret;
        }
    }

    return [$clientId, $clientSecret];
}

/**
 * Recursively find or create a folder path in Google Drive (e.g. YYYY / MM / DD)
 * Returns the final leaf folder ID
 */
function calls_get_or_create_google_drive_folder_path(string $rootFolderId, array $pathSegments, string $accessToken): string
{
    $currentParentId = $rootFolderId ?: 'root';

    foreach ($pathSegments as $segment) {
        $segment = trim((string)$segment);
        if ($segment === '') continue;

        // 1. Check if folder already exists under $currentParentId
        $q = "mimeType = 'application/vnd.google-apps.folder' and name = '" . addslashes($segment) . "' and '{$currentParentId}' in parents and trashed = false";
        $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query([
            'q' => $q,
            'spaces' => 'drive',
            'fields' => 'files(id, name)',
            'pageSize' => 1
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($res, true);
        if ($httpCode >= 200 && $httpCode < 300 && !empty($json['files'][0]['id'])) {
            $currentParentId = $json['files'][0]['id'];
            continue;
        }

        // 2. Not found -> create folder under $currentParentId
        $createUrl = 'https://www.googleapis.com/drive/v3/files?fields=id,name';
        $createData = [
            'name' => $segment,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [$currentParentId]
        ];

        $ch = curl_init($createUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($createData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $createRes = curl_exec($ch);
        $createCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $createJson = json_decode($createRes, true);
        if ($createCode >= 200 && $createCode < 300 && !empty($createJson['id'])) {
            $currentParentId = $createJson['id'];
        } else {
            error_log("[VY-CRM Google Drive] Subfolder creation failed for '{$segment}': " . $createRes);
            break;
        }
    }

    return $currentParentId;
}

/**
 * Upload to Google Drive via Access Token / Refresh Token into Year/Month/Date hierarchy
 */
function calls_upload_to_google_drive(PDO $conn, string $p, string $tmpPath, string $filename, array $fullConfig, string $origName = '', array $callMeta = []): array
{
    $cfg = $fullConfig['config_data'] ?? [];
    $folderId = trim($cfg['folder_id'] ?? '');
    $accessToken = trim($cfg['access_token'] ?? '');
    $refreshToken = trim($cfg['refresh_token'] ?? '');
    [$clientId, $clientSecret] = calls_get_effective_google_credentials($cfg);
    $configId = (int)($fullConfig['id'] ?? 0);

    // Refresh token if needed
    if ((!$accessToken || (!empty($cfg['token_expires_at']) && time() > (int)$cfg['token_expires_at'])) && $refreshToken && $clientId && $clientSecret) {
        $newToken = calls_refresh_google_drive_token($refreshToken, $clientId, $clientSecret);
        if ($newToken) {
            $accessToken = $newToken;
            $cfg['access_token'] = $newToken;
            $cfg['token_expires_at'] = time() + 3500;
            // Persist back to DB
            if ($configId > 0) {
                try {
                    $conn->prepare("UPDATE {$p}call_storage_configs SET config_data = ? WHERE id = ?")->execute([json_encode($cfg), $configId]);
                } catch (Exception $e) {}
            }
        }
    }

    if (!$accessToken) {
        error_log("[VY-CRM Google Drive] Access token missing or refresh failed. Config ID: {$configId}. Falling back to local storage.");
        return calls_upload_to_local_storage($tmpPath, $filename, [], $callMeta);
    }

    // Auto-organize into year/month/date folder hierarchy
    $callTime = !empty($callMeta['call_start_time']) ? strtotime($callMeta['call_start_time']) : time();
    if ($callTime <= 0) $callTime = time();

    $year = date('Y', $callTime);
    $month = date('m', $callTime);
    $day = date('d', $callTime);

    $targetFolderId = calls_get_or_create_google_drive_folder_path($folderId, [$year, $month, $day], $accessToken);

    $fileData = file_get_contents($tmpPath);

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION)) ?: 'mp3';
    $mimeMap = [
        'm4a' => 'audio/mp4',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'aac' => 'audio/aac',
        'ogg' => 'audio/ogg',
        'opus' => 'audio/opus',
        '3gp' => 'audio/3gpp',
        'amr' => 'audio/amr'
    ];
    $mimeType = $mimeMap[$ext] ?? (mime_content_type($tmpPath) ?: 'audio/mpeg');
    if (stripos($mimeType, 'video/') === 0) {
        $mimeType = $mimeMap[$ext] ?? 'audio/mp4';
    }

    $metadata = [
        'name' => $filename,
        'mimeType' => $mimeType,
        'description' => 'VY-AI CRM Call Recording: ' . $origName
    ];
    if ($targetFolderId) {
        $metadata['parents'] = [$targetFolderId];
    }

    $boundary = '-------314159265358979323846';
    $delimiter = "\r\n--" . $boundary . "\r\n";
    $closeDelim = "\r\n--" . $boundary . "--";

    $multipartBody = $delimiter .
        "Content-Type: application/json; charset=UTF-8\r\n\r\n" .
        json_encode($metadata) .
        $delimiter .
        "Content-Type: " . $mimeType . "\r\n" .
        "Content-Transfer-Encoding: base64\r\n\r\n" .
        base64_encode($fileData) .
        $closeDelim;

    $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name,webViewLink,webContentLink');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $multipartBody);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: multipart/related; boundary=' . $boundary,
        'Content-Length: ' . strlen($multipartBody)
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300 && !empty($json['id'])) {
        $fileId = $json['id'];
        $webViewLink = "https://drive.google.com/file/d/" . $fileId . "/view";
        return [
            'success' => true,
            'storage_type' => 'google_drive',
            'file_url' => $webViewLink,
            'file_id' => $fileId,
            'file_size' => strlen($fileData),
            'web_view_link' => $webViewLink
        ];
    }

    error_log("[VY-CRM Google Drive] Upload failed with HTTP {$httpCode}: {$response}. Falling back to local storage.");
    return calls_upload_to_local_storage($tmpPath, $filename, [], $callMeta);
}

/**
 * Refresh Google Drive OAuth Access Token
 */
function calls_refresh_google_drive_token(string $refreshToken, string $clientId, string $clientSecret): ?string
{
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'refresh_token' => $refreshToken,
        'grant_type' => 'refresh_token'
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $res = curl_exec($ch);
    curl_close($ch);

    $json = json_decode($res, true);
    return $json['access_token'] ?? null;
}

/**
 * Make Google Drive File accessible for inline audio streaming
 */
function calls_set_google_drive_public(string $fileId, string $accessToken): void
{
    $ch = curl_init("https://www.googleapis.com/drive/v3/files/{$fileId}/permissions");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'role' => 'reader',
        'type' => 'anyone'
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * Upload to S3 / Cloudflare R2 / MinIO compatible storage
 */
function calls_upload_to_s3_compatible(string $tmpPath, string $filename, array $cfg, string $provider = 's3'): array
{
    $bucket = trim($cfg['bucket'] ?? '');
    $endpoint = trim($cfg['endpoint'] ?? '');
    $region = trim($cfg['region'] ?? 'us-east-1');
    $accessKey = trim($cfg['access_key'] ?? '');
    $secretKey = trim($cfg['secret_key'] ?? '');
    $cdnUrl = trim($cfg['cdn_url'] ?? '');

    if (!$bucket || !$accessKey || !$secretKey) {
        return calls_upload_to_local_storage($tmpPath, $filename, []);
    }

    $fileData = file_get_contents($tmpPath);
    $mimeType = mime_content_type($tmpPath) ?: 'audio/mpeg';
    $objectKey = "recordings/" . date('Y/m/') . $filename;

    if ($provider === 'cloudflare_r2' && !$endpoint && !empty($cfg['account_id'])) {
        $endpoint = "https://{$cfg['account_id']}.r2.cloudflarestorage.com";
    } elseif (!$endpoint) {
        $endpoint = "https://{$bucket}.s3.{$region}.amazonaws.com";
    }

    $url = rtrim($endpoint, '/') . '/' . ($provider === 'cloudflare_r2' ? "{$bucket}/" : '') . $objectKey;
    
    $host = parse_url($url, PHP_URL_HOST);
    $path = parse_url($url, PHP_URL_PATH);
    $date = gmdate('Ymd\THis\Z');
    $shortDate = gmdate('Ymd');
    $service = 's3';

    $payloadHash = hash('sha256', $fileData);
    $canonicalHeaders = "host:{$host}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$date}\n";
    $signedHeaders = "host;x-amz-content-sha256;x-amz-date";
    $canonicalRequest = "PUT\n{$path}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";

    $credentialScope = "{$shortDate}/{$region}/{$service}/aws4_request";
    $stringToSign = "AWS4-HMAC-SHA256\n{$date}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

    $kSecret = 'AWS4' . $secretKey;
    $kDate = hash_hmac('sha256', $shortDate, $kSecret, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $authorization = "AWS4-HMAC-SHA256 Credential={$accessKey}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fileData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Host: ' . $host,
        'Content-Type: ' . $mimeType,
        'x-amz-date: ' . $date,
        'x-amz-content-sha256: ' . $payloadHash,
        'Authorization: ' . $authorization
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        $finalUrl = $cdnUrl ? (rtrim($cdnUrl, '/') . '/' . $objectKey) : $url;
        return [
            'success' => true,
            'storage_type' => $provider,
            'file_url' => $finalUrl,
            'file_id' => $objectKey,
            'file_size' => strlen($fileData)
        ];
    }

    return calls_upload_to_local_storage($tmpPath, $filename, []);
}

/**
 * Get valid Google Drive access token (auto-refreshing if expired)
 */
function calls_get_valid_google_access_token(PDO $conn, string $p, ?int $userId = null): string
{
    $config = calls_get_storage_config($conn, $p, $userId);
    $cfg = $config['config_data'] ?? [];
    $accessToken = trim($cfg['access_token'] ?? '');
    $refreshToken = trim($cfg['refresh_token'] ?? '');
    [$clientId, $clientSecret] = calls_get_effective_google_credentials($cfg);
    $expiresAt = (int)($cfg['token_expires_at'] ?? 0);
    $configId = (int)($config['id'] ?? 0);

    // Refresh token if needed
    if ((!$accessToken || ($expiresAt && time() > ($expiresAt - 120))) && $refreshToken && $clientId && $clientSecret) {
        $newToken = calls_refresh_google_drive_token($refreshToken, $clientId, $clientSecret);
        if ($newToken) {
            $accessToken = $newToken;
            $cfg['access_token'] = $newToken;
            $cfg['token_expires_at'] = time() + 3500;
            if ($configId > 0) {
                try {
                    $conn->prepare("UPDATE {$p}call_storage_configs SET config_data = ? WHERE id = ?")->execute([json_encode($cfg), $configId]);
                } catch (Exception $e) {}
            }
        }
    }

    if (!$accessToken) {
        throw new RuntimeException('Google Drive is not connected or token has expired. Please sign in with Google in Call Settings.');
    }

    return $accessToken;
}

/**
 * Generate Google OAuth 2.0 Auth URL with offline access
 */
function calls_get_google_auth_url(string $clientId, string $redirectUri, string $state): string
{
    $params = [
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/userinfo.profile',
        'access_type' => 'offline',
        'prompt' => 'consent',
        'include_granted_scopes' => 'true',
        'state' => $state
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

/**
 * Exchange OAuth authorization code for permanent refresh token + access token
 */
function calls_exchange_google_code(string $code, string $clientId, string $clientSecret, string $redirectUri): array
{
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'code' => $code,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code'
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($res, true);
    if ($httpCode < 200 || $httpCode >= 300 || empty($json['access_token'])) {
        throw new RuntimeException($json['error_description'] ?? ($json['error'] ?? 'Failed to exchange Google OAuth code.'));
    }

    $accessToken = $json['access_token'];
    $refreshToken = $json['refresh_token'] ?? null;
    $expiresIn = (int)($json['expires_in'] ?? 3600);

    // Fetch user info (email & profile)
    $userInfo = [];
    $uCh = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
    curl_setopt($uCh, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($uCh, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
    curl_setopt($uCh, CURLOPT_TIMEOUT, 10);
    $uRes = curl_exec($uCh);
    curl_close($uCh);
    if ($uRes) {
        $userInfo = json_decode($uRes, true) ?: [];
    }

    return [
        'access_token' => $accessToken,
        'refresh_token' => $refreshToken,
        'token_expires_at' => time() + $expiresIn - 60,
        'account_email' => $userInfo['email'] ?? '',
        'account_name' => $userInfo['name'] ?? '',
        'account_picture' => $userInfo['picture'] ?? ''
    ];
}

/**
 * List folders in Google Drive under a parent directory
 */
function calls_list_google_folders(string $accessToken, string $parentId = 'root'): array
{
    $parentId = trim($parentId) ?: 'root';
    $q = "mimeType = 'application/vnd.google-apps.folder' and trashed = false and '" . str_replace("'", "\\'", $parentId) . "' in parents";
    $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query([
        'q' => $q,
        'spaces' => 'drive',
        'corpora' => 'user',
        'fields' => 'files(id, name, modifiedTime, parents)',
        'orderBy' => 'name asc',
        'pageSize' => 100,
        'supportsAllDrives' => 'true',
        'includeItemsFromAllDrives' => 'true'
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    $res = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($res === false || !empty($curlErr)) {
        throw new RuntimeException("Google Drive Connection Error: " . ($curlErr ?: 'Request timed out'));
    }

    $json = json_decode($res, true);
    if ($httpCode >= 200 && $httpCode < 300 && isset($json['files'])) {
        return $json['files'];
    }

    if (!empty($json['error']['message'])) {
        throw new RuntimeException("Google Drive: " . $json['error']['message']);
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException("Google Drive responded with HTTP {$httpCode}: " . ($res ?: 'Empty response'));
    }

    return [];
}

/**
 * Create a new folder in Google Drive
 */
function calls_create_google_folder(string $accessToken, string $folderName, string $parentId = 'root'): array
{
    $metadata = [
        'name' => $folderName,
        'mimeType' => 'application/vnd.google-apps.folder',
        'parents' => [$parentId]
    ];

    $ch = curl_init('https://www.googleapis.com/drive/v3/files?fields=id,name&supportsAllDrives=true');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($metadata));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($res, true);
    if ($httpCode >= 200 && $httpCode < 300 && !empty($json['id'])) {
        return [
            'success' => true,
            'id' => $json['id'],
            'name' => $json['name'] ?? $folderName
        ];
    }

    throw new RuntimeException($json['error']['message'] ?? 'Failed to create folder in Google Drive');
}
