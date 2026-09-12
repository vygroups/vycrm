<?php
/**
 * includes/reminder_helper.php
 * 
 * Universal Multi-Channel Reminder System for Dynamic Modules.
 * Supports WhatsApp, Push Notification (FCM), and Email (SMTP) reminders.
 */

require_once __DIR__ . '/dynamic_modules.php';
require_once __DIR__ . '/brand.php';

date_default_timezone_set('Asia/Kolkata');

/* ──────────────────────────── TABLE SCHEMA ──────────────────────────── */

function reminder_ensure_tables(PDO $conn, string $p): void
{
    // 1. Ensure columns exist on {$p}modules
    try {
        @$conn->exec("ALTER TABLE {$p}modules ADD COLUMN enable_reminders TINYINT(1) DEFAULT 1");
    } catch (Throwable $e) {}
    try {
        @$conn->exec("ALTER TABLE {$p}modules ADD COLUMN reminder_channels VARCHAR(255) DEFAULT 'whatsapp,push,email'");
    } catch (Throwable $e) {}
    try {
        @$conn->exec("ALTER TABLE {$p}modules ADD COLUMN reminder_default_lead_time INT DEFAULT 15");
    } catch (Throwable $e) {}
    try {
        @$conn->exec("ALTER TABLE {$p}modules ADD COLUMN reminder_quick_notes TEXT DEFAULT NULL");
    } catch (Throwable $e) {}
    try {
        @$conn->exec("ALTER TABLE {$p}modules ADD COLUMN reminder_timing_presets TEXT DEFAULT NULL");
    } catch (Throwable $e) {}

    // 2. Create {$p}module_reminders table
    $sql = "CREATE TABLE IF NOT EXISTS `{$p}module_reminders` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `module_id` INT NOT NULL,
        `record_id` INT NOT NULL,
        `title` VARCHAR(255) NOT NULL,
        `description` TEXT NULL,
        `remind_at` DATETIME NOT NULL,
        `channels` VARCHAR(100) NOT NULL DEFAULT 'push',
        `recipient_type` ENUM('user', 'custom', 'contact') DEFAULT 'user',
        `recipient_user_id` INT NULL,
        `recipient_phone` VARCHAR(50) NULL,
        `recipient_email` VARCHAR(255) NULL,
        `status` ENUM('pending', 'processing', 'sent', 'failed', 'cancelled') DEFAULT 'pending',
        `channel_status` JSON NULL,
        `sent_at` DATETIME NULL,
        `error_log` TEXT NULL,
        `created_by` INT NOT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_remind_status_time` (`status`, `remind_at`),
        INDEX `idx_remind_record` (`module_id`, `record_id`),
        INDEX `idx_remind_user` (`recipient_user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    try {
        $conn->exec($sql);
    } catch (Throwable $e) {
        error_log("[Reminder Schema Error] " . $e->getMessage());
    }

    // 3. User Calendar Configs table (for Google Calendar & Cloud sync)
    $calSql = "CREATE TABLE IF NOT EXISTS `{$p}user_calendar_configs` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NOT NULL,
        `provider` VARCHAR(50) NOT NULL DEFAULT 'google',
        `account_email` VARCHAR(255) NULL,
        `account_name` VARCHAR(255) NULL,
        `account_picture` TEXT NULL,
        `access_token` TEXT NULL,
        `refresh_token` TEXT NULL,
        `token_expires_at` INT NULL,
        `sync_enabled` TINYINT(1) DEFAULT 1,
        `calendar_id` VARCHAR(255) DEFAULT 'primary',
        `connected_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_user_provider` (`user_id`, `provider`),
        INDEX `idx_user_cal` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    try {
        $conn->exec($calSql);
    } catch (Throwable $e) {}

    try {
        @$conn->exec("ALTER TABLE `{$p}module_reminders` MODIFY COLUMN `status` ENUM('pending', 'processing', 'sent', 'failed', 'cancelled') DEFAULT 'pending'");
    } catch (Throwable $e) {}
    try {
        @$conn->exec("ALTER TABLE `{$p}module_reminders` MODIFY COLUMN `module_id` INT NULL DEFAULT NULL");
    } catch (Throwable $e) {}
    try {
        @$conn->exec("ALTER TABLE `{$p}module_reminders` MODIFY COLUMN `record_id` INT NULL DEFAULT NULL");
    } catch (Throwable $e) {}
    try {
        @$conn->exec("ALTER TABLE `{$p}module_reminders` ADD COLUMN `google_event_id` VARCHAR(255) NULL DEFAULT NULL");
    } catch (Throwable $e) {}
    try {
        @$conn->exec("ALTER TABLE `{$p}module_reminders` ADD COLUMN `end_at` DATETIME NULL DEFAULT NULL");
    } catch (Throwable $e) {}
}

/* ──────────────────────────── CRUD OPERATIONS ──────────────────────────── */

function reminder_create(PDO $conn, string $p, array $data): array
{
    reminder_ensure_tables($conn, $p);

    $moduleId = !empty($data['module_id']) ? (int)$data['module_id'] : null;
    $recordId = !empty($data['record_id']) ? (int)$data['record_id'] : null;
    $title = trim($data['title'] ?? '');
    $description = trim($data['description'] ?? '');
    $remindAt = trim($data['remind_at'] ?? '');
    $endAt = trim($data['end_at'] ?? '');
    $channels = is_array($data['channels'] ?? null) 
        ? implode(',', array_filter($data['channels'])) 
        : trim($data['channels'] ?? 'push');
    $recipientType = in_array($data['recipient_type'] ?? '', ['user', 'custom', 'contact']) 
        ? $data['recipient_type'] 
        : 'user';
    $recipientUserId = !empty($data['recipient_user_id']) ? (int)$data['recipient_user_id'] : null;
    $recipientPhone = trim($data['recipient_phone'] ?? '');
    $recipientEmail = trim($data['recipient_email'] ?? '');
    $createdBy = (int)($data['created_by'] ?? ($_SESSION['user_id'] ?? 1));

    if (empty($title)) {
        return ['success' => false, 'error' => 'Reminder title / note is required.'];
    }
    if (empty($remindAt)) {
        return ['success' => false, 'error' => 'Reminder date & time is required.'];
    }
    if (empty($channels)) {
        return ['success' => false, 'error' => 'Please select at least one reminder channel (WhatsApp, Push, or Email).'];
    }

    // Default recipient to creator if none specified
    if ($recipientType === 'user' && empty($recipientUserId)) {
        $recipientUserId = $createdBy;
    }

    // Normalize remindAt to Y-m-d H:i:s
    $ts = strtotime($remindAt);
    if (!$ts) {
        return ['success' => false, 'error' => 'Invalid date/time format for reminder.'];
    }
    $remindAtSql = date('Y-m-d H:i:s', $ts);

    // End time is optional. If provided, validate; otherwise default to start + 30m
    $endAtSql = null;
    if (!empty($endAt)) {
        $endTs = strtotime($endAt);
        if ($endTs && $endTs > $ts) {
            $endAtSql = date('Y-m-d H:i:s', $endTs);
        }
    }
    if (!$endAtSql) {
        $endAtSql = date('Y-m-d H:i:s', $ts + 1800); // 30 minutes default duration
    }

    $stmt = $conn->prepare("
        INSERT INTO `{$p}module_reminders` 
        (`module_id`, `record_id`, `title`, `description`, `remind_at`, `end_at`, `channels`, `recipient_type`, `recipient_user_id`, `recipient_phone`, `recipient_email`, `status`, `created_by`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
    ");
    $res = $stmt->execute([
        $moduleId,
        $recordId,
        $title,
        $description,
        $remindAtSql,
        $endAtSql,
        $channels,
        $recipientType,
        $recipientUserId,
        $recipientPhone,
        $recipientEmail,
        $createdBy
    ]);

    if ($res) {
        $id = (int)$conn->lastInsertId();
        $googleEventId = null;
        $googleError = null;

        // Automatically sync with Google Calendar if user has Google Calendar connected
        try {
            $createdReminder = reminder_get_by_id($conn, $p, $id);
            if ($createdReminder) {
                if ($createdBy > 0) {
                    $googleEventId = reminder_sync_to_google_calendar($conn, $p, $createdReminder, $createdBy, $googleError);
                }
                if ($recipientUserId && $recipientUserId !== $createdBy) {
                    $dummyErr = null;
                    reminder_sync_to_google_calendar($conn, $p, $createdReminder, $recipientUserId, $dummyErr);
                }
            }
        } catch (Throwable $e) {
            $googleError = $e->getMessage();
            error_log("[Reminder GCal Auto-sync Error on Create] " . $e->getMessage());
        }

        return [
            'success' => true, 
            'id' => $id, 
            'google_event_id' => $googleEventId,
            'google_error' => $googleError,
            'message' => 'Reminder scheduled successfully.'
        ];
    }

    return ['success' => false, 'error' => 'Failed to schedule reminder in database.'];
}

function reminder_get_pending_record_ids(PDO $conn, string $p, int $moduleId): array
{
    reminder_ensure_tables($conn, $p);
    try {
        $stmt = $conn->prepare("SELECT DISTINCT record_id FROM `{$p}module_reminders` WHERE module_id = ? AND status = 'pending'");
        $stmt->execute([$moduleId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        return [];
    }
}

function reminder_fetch_for_record(PDO $conn, string $p, int $moduleId, int $recordId): array
{
    reminder_ensure_tables($conn, $p);

    $stmt = $conn->prepare("
        SELECT r.*, 
               u.username as recipient_username, 
               u.first_name as recipient_first_name, 
               u.last_name as recipient_last_name,
               u.email as recipient_user_email,
               cu.username as creator_username,
               cu.first_name as creator_first_name,
               cu.last_name as creator_last_name
        FROM `{$p}module_reminders` r
        LEFT JOIN `{$p}users` u ON u.id = r.recipient_user_id
        LEFT JOIN `{$p}users` cu ON cu.id = r.created_by
        WHERE r.module_id = ? AND r.record_id = ?
        ORDER BY r.remind_at DESC, r.id DESC
    ");
    $stmt->execute([$moduleId, $recordId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_map('reminder_format_row', $rows);
}

function reminder_fetch_user_upcoming(PDO $conn, string $p, int $userId, int $limit = 20): array
{
    reminder_ensure_tables($conn, $p);

    $stmt = $conn->prepare("
        SELECT r.*, m.name as module_name, m.slug as module_slug, m.icon as module_icon
        FROM `{$p}module_reminders` r
        LEFT JOIN `{$p}modules` m ON m.id = r.module_id
        WHERE (r.recipient_user_id = ? OR r.created_by = ?)
          AND r.status = 'pending'
          AND r.remind_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ORDER BY r.remind_at ASC
        LIMIT ?
    ");
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $userId, PDO::PARAM_INT);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_map('reminder_format_row', $rows);
}

function reminder_format_row(array $r): array
{
    $r['id'] = (int)$r['id'];
    $r['module_id'] = !empty($r['module_id']) ? (int)$r['module_id'] : null;
    $r['record_id'] = !empty($r['record_id']) ? (int)$r['record_id'] : null;
    $r['module_name'] = !empty($r['module_name']) ? $r['module_name'] : 'General';
    $r['module_slug'] = !empty($r['module_slug']) ? $r['module_slug'] : 'general';
    $r['module_icon'] = !empty($r['module_icon']) ? $r['module_icon'] : 'fa-solid fa-bell';
    $r['channels_list'] = array_filter(array_map('trim', explode(',', $r['channels'] ?? '')));
    $r['channel_status'] = !empty($r['channel_status']) ? json_decode($r['channel_status'], true) : [];
    
    $recipientName = 'System';
    if ($r['recipient_type'] === 'user') {
        $fullName = trim(($r['recipient_first_name'] ?? '') . ' ' . ($r['recipient_last_name'] ?? ''));
        $recipientName = $fullName ?: ($r['recipient_username'] ?? 'User #' . $r['recipient_user_id']);
    } elseif ($r['recipient_type'] === 'contact') {
        $recipientName = 'Contact (' . ($r['recipient_phone'] ?: $r['recipient_email'] ?: 'Record') . ')';
    } elseif (!empty($r['recipient_phone']) || !empty($r['recipient_email'])) {
        $recipientName = trim(($r['recipient_phone'] ?? '') . ' ' . ($r['recipient_email'] ?? ''));
    }
    $r['recipient_display_name'] = $recipientName;

    $cFullName = trim(($r['creator_first_name'] ?? '') . ' ' . ($r['creator_last_name'] ?? ''));
    $r['creator_display_name'] = $cFullName ?: ($r['creator_username'] ?? ($r['created_by'] ? 'User #' . $r['created_by'] : 'System'));

    return $r;
}

function reminder_update_status(PDO $conn, string $p, int $id, string $status, ?array $channelStatus = null, ?string $errorLog = null): bool
{
    $sets = ["status = ?"];
    $params = [$status];

    if ($status === 'sent') {
        $sets[] = "sent_at = NOW()";
    }
    if ($channelStatus !== null) {
        $sets[] = "channel_status = ?";
        $params[] = json_encode($channelStatus);
    }
    if ($errorLog !== null) {
        $sets[] = "error_log = ?";
        $params[] = $errorLog;
    }

    $params[] = $id;
    $sql = "UPDATE `{$p}module_reminders` SET " . implode(', ', $sets) . " WHERE id = ?";
    return $conn->prepare($sql)->execute($params);
}

function reminder_get_by_id(PDO $conn, string $p, int $id): ?array
{
    reminder_ensure_tables($conn, $p);
    $stmt = $conn->prepare("
        SELECT r.*, m.name as module_name, m.slug as module_slug, m.icon as module_icon,
               u.username as recipient_username, u.first_name as recipient_first_name, u.last_name as recipient_last_name,
               cu.username as creator_username
        FROM `{$p}module_reminders` r
        LEFT JOIN `{$p}modules` m ON m.id = r.module_id
        LEFT JOIN `{$p}users` u ON u.id = r.recipient_user_id
        LEFT JOIN `{$p}users` cu ON cu.id = r.created_by
        WHERE r.id = ?
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? reminder_format_row($row) : null;
}

function reminder_get_active_for_record(PDO $conn, string $p, int $moduleId, int $recordId): ?array
{
    reminder_ensure_tables($conn, $p);
    $stmt = $conn->prepare("
        SELECT r.*, m.name as module_name, m.slug as module_slug, m.icon as module_icon,
               m.reminder_channels, m.reminder_quick_notes, m.reminder_timing_presets
        FROM `{$p}module_reminders` r
        LEFT JOIN `{$p}modules` m ON m.id = r.module_id
        WHERE r.module_id = ? AND r.record_id = ? AND r.status = 'pending'
        ORDER BY r.remind_at ASC, r.id DESC
        LIMIT 1
    ");
    $stmt->execute([$moduleId, $recordId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? reminder_format_row($row) : null;
}

function reminder_update(PDO $conn, string $p, array $data): array
{
    reminder_ensure_tables($conn, $p);
    $id = (int)($data['id'] ?? 0);
    if (!$id) {
        return ['success' => false, 'error' => 'Reminder ID is required.'];
    }

    $moduleId = !empty($data['module_id']) ? (int)$data['module_id'] : null;
    $recordId = !empty($data['record_id']) ? (int)$data['record_id'] : null;
    $title = trim($data['title'] ?? '');
    $description = trim($data['description'] ?? '');
    $remindAt = trim($data['remind_at'] ?? '');
    $endAt = trim($data['end_at'] ?? '');
    $channels = is_array($data['channels'] ?? null) 
        ? implode(',', array_filter($data['channels'])) 
        : trim($data['channels'] ?? 'push');
    $recipientType = in_array($data['recipient_type'] ?? '', ['user', 'custom', 'contact']) 
        ? $data['recipient_type'] 
        : 'user';
    $recipientUserId = !empty($data['recipient_user_id']) ? (int)$data['recipient_user_id'] : null;
    $recipientPhone = trim($data['recipient_phone'] ?? '');
    $recipientEmail = trim($data['recipient_email'] ?? '');
    $status = in_array($data['status'] ?? '', ['pending', 'sent', 'failed', 'cancelled']) ? $data['status'] : 'pending';

    if (empty($title)) {
        return ['success' => false, 'error' => 'Reminder title / note is required.'];
    }
    if (empty($remindAt)) {
        return ['success' => false, 'error' => 'Reminder date & time is required.'];
    }
    if (empty($channels)) {
        return ['success' => false, 'error' => 'Please select at least one channel.'];
    }

    $ts = strtotime($remindAt);
    if (!$ts) {
        return ['success' => false, 'error' => 'Invalid date/time format for reminder.'];
    }
    $remindAtSql = date('Y-m-d H:i:s', $ts);

    // End time is optional
    $endAtSql = null;
    if (!empty($endAt)) {
        $endTs = strtotime($endAt);
        if ($endTs && $endTs > $ts) {
            $endAtSql = date('Y-m-d H:i:s', $endTs);
        }
    }
    if (!$endAtSql) {
        $endAtSql = date('Y-m-d H:i:s', $ts + 1800);
    }

    $stmt = $conn->prepare("
        UPDATE `{$p}module_reminders`
        SET module_id = ?, record_id = ?, title = ?, description = ?, remind_at = ?, end_at = ?, channels = ?, recipient_type = ?,
            recipient_user_id = ?, recipient_phone = ?, recipient_email = ?, status = ?
        WHERE id = ?
    ");
    $res = $stmt->execute([
        $moduleId,
        $recordId,
        $title,
        $description,
        $remindAtSql,
        $endAtSql,
        $channels,
        $recipientType,
        $recipientUserId,
        $recipientPhone,
        $recipientEmail,
        $status,
        $id
    ]);

    if ($res) {
        $googleEventId = null;
        $googleError = null;

        // Automatically update event in Google Calendar if connected
        try {
            $updatedReminder = reminder_get_by_id($conn, $p, $id);
            if ($updatedReminder) {
                $creatorId = (int)($updatedReminder['created_by'] ?? 0);
                if ($creatorId > 0) {
                    $googleEventId = reminder_sync_to_google_calendar($conn, $p, $updatedReminder, $creatorId, $googleError);
                }
                if ($recipientUserId && $recipientUserId !== $creatorId) {
                    $dummyErr = null;
                    reminder_sync_to_google_calendar($conn, $p, $updatedReminder, $recipientUserId, $dummyErr);
                }
            }
        } catch (Throwable $e) {
            $googleError = $e->getMessage();
            error_log("[Reminder GCal Auto-sync Error on Update] " . $e->getMessage());
        }

        return [
            'success' => true, 
            'id' => $id, 
            'google_event_id' => $googleEventId,
            'google_error' => $googleError,
            'message' => 'Reminder updated successfully.'
        ];
    }

    return ['success' => false, 'error' => 'Failed to update reminder.'];
}

function reminder_fetch_all_filtered(PDO $conn, string $p, int $userId, array $params = []): array
{
    reminder_ensure_tables($conn, $p);

    $tab = $params['tab'] ?? 'upcoming';
    $moduleId = (int)($params['module_id'] ?? 0);
    $search = trim($params['search'] ?? '');
    $limit = max(1, min(200, (int)($params['limit'] ?? 50)));
    $offset = max(0, (int)($params['offset'] ?? 0));

    $where = ["1=1"];
    $args = [];

    $now = date('Y-m-d H:i:s');
    $todayStart = date('Y-m-d 00:00:00');
    $todayEnd = date('Y-m-d 23:59:59');

    if ($tab === 'today') {
        $where[] = "r.remind_at >= ? AND r.remind_at <= ?";
        $args[] = $todayStart;
        $args[] = $todayEnd;
    } elseif ($tab === 'upcoming') {
        $where[] = "r.status = 'pending' AND r.remind_at >= ?";
        $args[] = $now;
    } elseif ($tab === 'overdue') {
        $where[] = "r.status = 'pending' AND r.remind_at < ?";
        $args[] = $now;
    } elseif ($tab === 'past' || $tab === 'completed') {
        $where[] = "(r.status IN ('sent', 'cancelled') OR (r.status = 'pending' AND r.remind_at < ?))";
        $args[] = $todayStart;
    }

    if ($moduleId > 0) {
        $where[] = "r.module_id = ?";
        $args[] = $moduleId;
    } elseif ($moduleId === -1) {
        $where[] = "(r.module_id IS NULL OR r.module_id = 0)";
    }

    if (!empty($search)) {
        $where[] = "(r.title LIKE ? OR r.description LIKE ? OR m.name LIKE ?)";
        $searchTerm = '%' . $search . '%';
        $args[] = $searchTerm;
        $args[] = $searchTerm;
        $args[] = $searchTerm;
    }

    $whereSql = implode(' AND ', $where);

    $statsSql = "
        SELECT 
            COUNT(CASE WHEN r.status = 'pending' AND r.remind_at >= '{$now}' THEN 1 END) as count_upcoming,
            COUNT(CASE WHEN r.remind_at >= '{$todayStart}' AND r.remind_at <= '{$todayEnd}' THEN 1 END) as count_today,
            COUNT(CASE WHEN r.status = 'pending' AND r.remind_at < '{$now}' THEN 1 END) as count_overdue,
            COUNT(CASE WHEN r.status = 'sent' THEN 1 END) as count_sent,
            COUNT(*) as count_all
        FROM `{$p}module_reminders` r
        LEFT JOIN `{$p}modules` m ON m.id = r.module_id
    ";
    $stats = $conn->query($statsSql)->fetch(PDO::FETCH_ASSOC) ?: [
        'count_upcoming' => 0,
        'count_today' => 0,
        'count_overdue' => 0,
        'count_sent' => 0,
        'count_all' => 0
    ];

    $orderSql = ($tab === 'past' || $tab === 'completed') ? "r.remind_at DESC" : "r.remind_at ASC";
    $sql = "
        SELECT r.*, m.name as module_name, m.slug as module_slug, m.icon as module_icon,
               u.username as recipient_username, u.first_name as recipient_first_name, u.last_name as recipient_last_name,
               cu.username as creator_username
        FROM `{$p}module_reminders` r
        LEFT JOIN `{$p}modules` m ON m.id = r.module_id
        LEFT JOIN `{$p}users` u ON u.id = r.recipient_user_id
        LEFT JOIN `{$p}users` cu ON cu.id = r.created_by
        WHERE {$whereSql}
        ORDER BY {$orderSql}
        LIMIT {$limit} OFFSET {$offset}
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'reminders' => array_map('reminder_format_row', $rows),
        'stats' => $stats,
        'total' => count($rows)
    ];
}

function reminder_delete(PDO $conn, string $p, int $id): bool
{
    try {
        $rem = reminder_get_by_id($conn, $p, $id);
        if ($rem && !empty($rem['google_event_id'])) {
            $creatorId = (int)($rem['created_by'] ?? 0);
            if ($creatorId > 0) {
                reminder_delete_from_google_calendar($conn, $p, $rem['google_event_id'], $creatorId);
            }
        }
    } catch (Throwable $e) {}

    $stmt = $conn->prepare("DELETE FROM `{$p}module_reminders` WHERE id = ?");
    return $stmt->execute([$id]);
}

/* ──────────────────────────── MULTI-CHANNEL DISPATCHER ──────────────────────────── */

/**
 * Dispatches a single reminder across all its configured channels.
 */
function reminder_dispatch_single(PDO $conn, string $p, array $reminder): array
{
    $reminderId = (int)$reminder['id'];
    $moduleId = !empty($reminder['module_id']) ? (int)$reminder['module_id'] : 0;
    $recordId = !empty($reminder['record_id']) ? (int)$reminder['record_id'] : 0;
    $title = $reminder['title'];
    $description = $reminder['description'] ?? '';
    $channels = array_filter(array_map('trim', explode(',', $reminder['channels'] ?? '')));

    // Fetch module details
    $module = ['name' => 'General Reminder', 'slug' => 'general', 'icon' => 'fa-solid fa-bell'];
    if ($moduleId > 0) {
        $modStmt = $conn->prepare("SELECT name, slug, icon FROM `{$p}modules` WHERE id = ?");
        $modStmt->execute([$moduleId]);
        $fetchedMod = $modStmt->fetch(PDO::FETCH_ASSOC);
        if ($fetchedMod) {
            $module = $fetchedMod;
        }
    }

    // Resolve Recipient contact details
    $targetUser = null;
    $targetPhone = $reminder['recipient_phone'] ?? '';
    $targetEmail = $reminder['recipient_email'] ?? '';
    $targetFcmToken = null;
    $recipientName = 'Team Member';

    if (!empty($reminder['recipient_user_id'])) {
        $uStmt = $conn->prepare("SELECT id, username, first_name, last_name, email, fcm_token, fcm_web_token FROM `{$p}users` WHERE id = ?");
        $uStmt->execute([(int)$reminder['recipient_user_id']]);
        $targetUser = $uStmt->fetch(PDO::FETCH_ASSOC);

        if ($targetUser) {
            $fullName = trim(($targetUser['first_name'] ?? '') . ' ' . ($targetUser['last_name'] ?? ''));
            $recipientName = $fullName ?: $targetUser['username'];
            if (empty($targetEmail)) $targetEmail = $targetUser['email'] ?? '';
            $targetFcmToken = !empty($targetUser['fcm_token']) ? $targetUser['fcm_token'] : ($targetUser['fcm_web_token'] ?? null);
        }
    }

    $results = [];
    $errors = [];
    $overallSuccess = true;

    // Build base URL for CRM record link
    $baseUrl = 'https://vycrm.vygroups.com/';
    try {
        $siteUrl = dm_get_system_setting($conn, $p, 'site_url', '');
        if (!empty($siteUrl)) {
            $baseUrl = rtrim($siteUrl, '/') . '/';
        }
    } catch (Throwable $e) {}

    $recordUrl = ($moduleId > 0 && $recordId > 0) 
        ? "{$baseUrl}module_record.php?module={$moduleId}&record={$recordId}&view=1"
        : "{$baseUrl}reminders.php";

    // 1. PUSH NOTIFICATION (FCM HTTP v1)
    if (in_array('push', $channels)) {
        $fcmTokens = [];
        if (!empty($targetUser['fcm_token'])) $fcmTokens[] = trim($targetUser['fcm_token']);
        if (!empty($targetUser['fcm_web_token'])) $fcmTokens[] = trim($targetUser['fcm_web_token']);

        // If recipient user has no token, fallback to reminder creator's tokens
        if (empty($fcmTokens) && !empty($reminder['created_by'])) {
            try {
                $cStmt = $conn->prepare("SELECT fcm_token, fcm_web_token FROM `{$p}users` WHERE id = ?");
                $cStmt->execute([(int)$reminder['created_by']]);
                if ($cu = $cStmt->fetch(PDO::FETCH_ASSOC)) {
                    if (!empty($cu['fcm_token'])) $fcmTokens[] = trim($cu['fcm_token']);
                    if (!empty($cu['fcm_web_token'])) $fcmTokens[] = trim($cu['fcm_web_token']);
                }
            } catch (Throwable $e) {}
        }

        // Deduplicate tokens so each device/browser only receives exactly one alert
        $fcmTokens = array_values(array_unique(array_filter($fcmTokens)));

        if (!empty($fcmTokens)) {
            $pushTitle = "⏰ Reminder: " . $title;
            $pushBody = (!empty($description) ? $description . " • " : "") . "Module: " . $module['name'];
            $pushData = [
                'type' => 'MODULE_REMINDER',
                'reminder_id' => (string)$reminderId,
                'module_id' => (string)$moduleId,
                'module_slug' => (string)$module['slug'],
                'module_name' => (string)$module['name'],
                'record_id' => (string)$recordId,
                'title' => (string)$title,
                'description' => (string)$description,
                'url' => (string)$recordUrl
            ];

            $anySent = false;
            foreach ($fcmTokens as $tok) {
                $fcmRes = dm_send_fcm_notification($tok, $pushTitle, $pushBody, $pushData, null, $conn, $p);
                if (!empty($fcmRes['success'])) {
                    $anySent = true;
                } else {
                    $errors[] = "Push error: " . ($fcmRes['error'] ?? 'FCM dispatch failed');
                }
            }

            if ($anySent) {
                $results['push'] = 'sent';
            } else {
                $results['push'] = 'failed';
            }
        } else {
            $results['push'] = 'skipped';
            $errors[] = "Push Skipped: User has no active FCM token. (Log in via Mobile App or enable Web Push in browser to receive notifications).";
        }
    }

    // 2. WHATSAPP DISPATCH
    if (in_array('whatsapp', $channels)) {
        if (!empty($targetPhone)) {
            // Clean phone number
            $cleanPhone = preg_replace('/[^0-9]/', '', $targetPhone);
            if (strlen($cleanPhone) === 10) {
                $cleanPhone = '91' . $cleanPhone; // default India code if 10 digits
            }

            // Fetch tenant WhatsApp configuration
            $commStmt = $conn->query("SELECT * FROM `{$p}communication_configs` WHERE type = 'whatsapp' AND status = 'active' ORDER BY is_default DESC, id DESC LIMIT 1");
            $waConfig = $commStmt ? $commStmt->fetch(PDO::FETCH_ASSOC) : null;

            if ($waConfig && !empty($waConfig['api_url']) && !empty($waConfig['api_key'])) {
                $waMsg = "⏰ *CRM Reminder Alert*\n\n"
                       . "*Title:* " . $title . "\n"
                       . (!empty($description) ? "*Notes:* " . $description . "\n" : "")
                       . "*Module:* " . $module['name'] . "\n"
                       . "*Time:* " . date('d M Y, h:i A', strtotime($reminder['remind_at'])) . "\n\n"
                       . "🔗 *View Record:* " . $recordUrl;

                $waRes = dm_send_whatsapp_message($waConfig['api_url'], $waConfig['api_key'], $cleanPhone, $waMsg);
                if ($waRes) {
                    $results['whatsapp'] = 'sent';
                } else {
                    $results['whatsapp'] = 'failed';
                    $errors[] = "WhatsApp Error: Gateway request failed for $cleanPhone";
                    $overallSuccess = false;
                }
            } else {
                $results['whatsapp'] = 'skipped';
                $errors[] = "WhatsApp Skipped: No active WhatsApp gateway configured in Communication Settings.";
            }
        } else {
            $results['whatsapp'] = 'skipped';
            $errors[] = "WhatsApp Skipped: Recipient phone number is empty.";
        }
    }

    // 3. EMAIL DISPATCH (SMTP)
    if (in_array('email', $channels)) {
        if (!empty($targetEmail) && filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
            // Fetch SMTP config
            $smtpStmt = $conn->query("SELECT * FROM `{$p}communication_configs` WHERE type = 'smtp' AND status = 'active' ORDER BY is_default DESC, id DESC LIMIT 1");
            $smtpConfig = $smtpStmt ? $smtpStmt->fetch(PDO::FETCH_ASSOC) : null;

            if ($smtpConfig && !empty($smtpConfig['smtp_host']) && !empty($smtpConfig['smtp_user'])) {
                $emailSubject = "⏰ Reminder: " . $title . " [" . $module['name'] . "]";
                $emailHtml = reminder_render_email_template([
                    'title' => $title,
                    'description' => $description,
                    'module_name' => $module['name'],
                    'remind_at' => date('d M Y, h:i A', strtotime($reminder['remind_at'])),
                    'recipient_name' => $recipientName,
                    'record_url' => $recordUrl,
                    'brand_name' => brand_name() ?: 'VY CRM'
                ]);

                $mailRes = dm_send_smtp_email(
                    $smtpConfig['smtp_host'],
                    (int)$smtpConfig['smtp_port'],
                    $smtpConfig['smtp_user'],
                    $smtpConfig['smtp_pass'],
                    $smtpConfig['from_email'] ?: $smtpConfig['smtp_user'],
                    $smtpConfig['from_name'] ?: brand_name(),
                    $targetEmail,
                    $emailSubject,
                    $emailHtml,
                    $smtpConfig['encryption'] ?? 'tls'
                );

                if ($mailRes) {
                    $results['email'] = 'sent';
                } else {
                    $results['email'] = 'failed';
                    $errors[] = "Email Error: SMTP dispatch failed for $targetEmail";
                    $overallSuccess = false;
                }
            } else {
                $results['email'] = 'skipped';
                $errors[] = "Email Skipped: No active SMTP account configured in Communication Settings.";
            }
        } else {
            $results['email'] = 'skipped';
            $errors[] = "Email Skipped: Recipient email is empty or invalid.";
        }
    }

    $finalStatus = $overallSuccess ? 'sent' : (empty($results) ? 'failed' : 'sent');
    $errorString = !empty($errors) ? implode(" | ", $errors) : null;

    reminder_update_status($conn, $p, $reminderId, $finalStatus, $results, $errorString);

    return [
        'success' => $overallSuccess,
        'status' => $finalStatus,
        'channel_status' => $results,
        'errors' => $errors
    ];
}

/**
 * Render responsive, high-aesthetic HTML Email Template for Reminders.
 */
function reminder_render_email_template(array $data): string
{
    $title = htmlspecialchars($data['title']);
    $description = !empty($data['description']) ? nl2br(htmlspecialchars($data['description'])) : '';
    $moduleName = htmlspecialchars($data['module_name']);
    $remindAt = htmlspecialchars($data['remind_at']);
    $recipientName = htmlspecialchars($data['recipient_name']);
    $recordUrl = htmlspecialchars($data['record_url']);
    $brandName = htmlspecialchars($data['brand_name']);

    $notesRow = '';
    if (!empty($description)) {
        $notesRow = '<tr>
            <td style="font-size: 13px; color: #64748b; vertical-align: top; padding-top: 4px;">Notes:</td>
            <td style="font-size: 14px; color: #334155; line-height: 1.5; padding-top: 4px;">' . $description . '</td>
        </tr>';
    }

    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . $title . '</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; color: #1e293b;">
    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f8fafc; padding: 40px 15px;">
        <tr>
            <td align="center">
                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 580px; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05); border: 1px solid #e2e8f0;">
                    <!-- Header -->
                    <tr>
                        <td style="padding: 30px 36px 20px; background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); text-align: left;">
                            <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td>
                                        <div style="display: inline-block; background: rgba(255, 255, 255, 0.2); padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; color: #ffffff; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px;">
                                            ⏰ Scheduled Reminder
                                        </div>
                                        <h1 style="margin: 0; font-size: 22px; font-weight: 700; color: #ffffff; line-height: 1.3;">
                                            ' . $title . '
                                        </h1>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td style="padding: 32px 36px 24px;">
                            <p style="margin: 0 0 20px; font-size: 15px; line-height: 1.6; color: #475569;">
                                Hello <strong>' . $recipientName . '</strong>, this is an automated reminder regarding a record in <strong>' . $moduleName . '</strong>.
                            </p>
                            
                            <!-- Detail Card -->
                            <div style="background-color: #f1f5f9; border-radius: 12px; padding: 20px; margin-bottom: 24px; border: 1px solid #e2e8f0;">
                                <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                    <tr>
                                        <td style="padding-bottom: 10px; font-size: 13px; color: #64748b; width: 100px;">Module:</td>
                                        <td style="padding-bottom: 10px; font-size: 14px; font-weight: 600; color: #0f172a;">' . $moduleName . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding-bottom: 10px; font-size: 13px; color: #64748b;">Scheduled For:</td>
                                        <td style="padding-bottom: 10px; font-size: 14px; font-weight: 600; color: #0f172a;">' . $remindAt . '</td>
                                    </tr>
                                    ' . $notesRow . '
                                </table>
                            </div>

                            <!-- Action Button -->
                            <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td align="center" style="padding: 10px 0 20px;">
                                        <a href="' . $recordUrl . '" target="_blank" style="display: inline-block; background-color: #6366f1; color: #ffffff; font-size: 15px; font-weight: 600; text-decoration: none; padding: 12px 28px; border-radius: 10px; box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);">
                                            Open Record in CRM →
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 0; font-size: 12px; color: #94a3b8; text-align: center;">
                                If the button above does not work, copy and paste this link into your browser:<br>
                                <a href="' . $recordUrl . '" style="color: #6366f1; word-break: break-all;">' . $recordUrl . '</a>
                            </p>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 20px 36px; background-color: #f8fafc; border-top: 1px solid #e2e8f0; text-align: center;">
                            <p style="margin: 0; font-size: 12px; color: #94a3b8;">
                                Sent by <strong>' . $brandName . '</strong> Automated Notification Service.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';

    return $html;
}

/* ──────────────────────────── GOOGLE CALENDAR CLOUD SYNC ──────────────────────────── */

function reminder_get_google_calendar_config(PDO $conn, string $p, int $userId): ?array
{
    reminder_ensure_tables($conn, $p);
    try {
        $stmt = $conn->prepare("SELECT * FROM `{$p}user_calendar_configs` WHERE user_id = ? AND provider = 'google' LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function reminder_save_google_calendar_config(PDO $conn, string $p, int $userId, array $data): bool
{
    reminder_ensure_tables($conn, $p);
    try {
        $stmt = $conn->prepare("
            INSERT INTO `{$p}user_calendar_configs`
                (user_id, provider, account_email, account_name, account_picture, access_token, refresh_token, token_expires_at, sync_enabled, calendar_id, connected_at)
            VALUES
                (?, 'google', ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                account_email = VALUES(account_email),
                account_name = VALUES(account_name),
                account_picture = VALUES(account_picture),
                access_token = VALUES(access_token),
                refresh_token = IF(VALUES(refresh_token) IS NOT NULL AND VALUES(refresh_token) != '', VALUES(refresh_token), refresh_token),
                token_expires_at = VALUES(token_expires_at),
                sync_enabled = VALUES(sync_enabled),
                calendar_id = VALUES(calendar_id),
                updated_at = NOW()
        ");
        return $stmt->execute([
            $userId,
            $data['account_email'] ?? null,
            $data['account_name'] ?? null,
            $data['account_picture'] ?? null,
            $data['access_token'] ?? null,
            $data['refresh_token'] ?? null,
            $data['token_expires_at'] ?? (time() + 3600),
            isset($data['sync_enabled']) ? (int)$data['sync_enabled'] : 1,
            $data['calendar_id'] ?? 'primary'
        ]);
    } catch (Throwable $e) {
        error_log("[Reminder GCal Config Save Error] " . $e->getMessage());
        return false;
    }
}

function reminder_disconnect_google_calendar(PDO $conn, string $p, int $userId): bool
{
    reminder_ensure_tables($conn, $p);
    try {
        $stmt = $conn->prepare("DELETE FROM `{$p}user_calendar_configs` WHERE user_id = ? AND provider = 'google'");
        return $stmt->execute([$userId]);
    } catch (Throwable $e) {
        return false;
    }
}

function reminder_get_google_calendar_auth_url(string $clientId, string $redirectUri, string $state = 'reminders'): string
{
    $params = [
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/userinfo.profile',
        'access_type' => 'offline',
        'prompt' => 'consent',
        'include_granted_scopes' => 'true',
        'state' => $state
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

function reminder_get_valid_google_calendar_token(PDO $conn, string $p, int $userId): ?string
{
    $cfg = reminder_get_google_calendar_config($conn, $p, $userId);
    if (!$cfg || empty($cfg['sync_enabled'])) {
        return null;
    }

    $accessToken = $cfg['access_token'] ?? null;
    $refreshToken = $cfg['refresh_token'] ?? null;
    $expiresAt = (int)($cfg['token_expires_at'] ?? 0);

    // If token expires in less than 300 seconds, refresh it
    if ((!$accessToken || time() >= ($expiresAt - 300)) && $refreshToken) {
        $clientId = (string)dm_get_global_setting('google_drive_client_id', '');
        $clientSecret = (string)dm_get_global_setting('google_drive_client_secret', '');

        if ($clientId && $clientSecret) {
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
            if (!empty($json['access_token'])) {
                $accessToken = $json['access_token'];
                $newExpiresAt = time() + (int)($json['expires_in'] ?? 3600);
                try {
                    $uStmt = $conn->prepare("UPDATE `{$p}user_calendar_configs` SET access_token = ?, token_expires_at = ? WHERE user_id = ? AND provider = 'google'");
                    $uStmt->execute([$accessToken, $newExpiresAt, $userId]);
                } catch (Throwable $e) {}
            }
        }
    }

    return $accessToken;
}

function reminder_sync_to_google_calendar(PDO $conn, string $p, array $reminder, int $userId, ?string &$errorOut = null): ?string
{
    $accessToken = reminder_get_valid_google_calendar_token($conn, $p, $userId);
    if (!$accessToken) {
        $errorOut = 'Google Calendar is not connected or token has expired. Please reconnect in Google Calendar settings.';
        return null;
    }

    $title = !empty($reminder['title']) ? $reminder['title'] : 'CRM Follow-up Reminder';
    $desc = (!empty($reminder['description']) ? $reminder['description'] . "\n\n" : '') . 
            "CRM Module: " . ($reminder['module_name'] ?? 'General') . 
            (!empty($reminder['record_id']) ? " | Record #" . $reminder['record_id'] : '') . 
            "\nCreated via VY-AI CRM";

    try {
        $tz = new DateTimeZone('Asia/Kolkata');
        $startDt = new DateTime($reminder['remind_at'], $tz);
        $startTimeStr = $startDt->format('Y-m-d\TH:i:sP');

        if (!empty($reminder['end_at'])) {
            $endDt = new DateTime($reminder['end_at'], $tz);
            if ($endDt <= $startDt) {
                $endDt = clone $startDt;
                $endDt->modify('+30 minutes');
            }
        } else {
            $endDt = clone $startDt;
            $endDt->modify('+30 minutes');
        }
        $endTimeStr = $endDt->format('Y-m-d\TH:i:sP');
    } catch (Throwable $e) {
        $errorOut = 'Invalid reminder start or end time: ' . $e->getMessage();
        return null;
    }

    $eventPayload = [
        'summary' => $title,
        'description' => $desc,
        'start' => [
            'dateTime' => $startTimeStr,
            'timeZone' => 'Asia/Kolkata'
        ],
        'end' => [
            'dateTime' => $endTimeStr,
            'timeZone' => 'Asia/Kolkata'
        ],
        'reminders' => [
            'useDefault' => false,
            'overrides' => [
                ['method' => 'popup', 'minutes' => 15],
                ['method' => 'popup', 'minutes' => 0]
            ]
        ]
    ];

    $existingEventId = $reminder['google_event_id'] ?? null;
    $url = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';
    $method = 'POST';

    if ($existingEventId) {
        $url .= '/' . urlencode($existingEventId);
        $method = 'PUT';
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($eventPayload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($res, true);
    if ($httpCode >= 200 && $httpCode < 300 && !empty($json['id'])) {
        $googleEventId = $json['id'];
        if (!empty($reminder['id'])) {
            try {
                $conn->prepare("UPDATE `{$p}module_reminders` SET google_event_id = ? WHERE id = ?")->execute([$googleEventId, (int)$reminder['id']]);
            } catch (Throwable $e) {}
        }
        return $googleEventId;
    }

    // If PUT failed (e.g. event deleted on Google), fallback to POST as new event
    if ($method === 'PUT' && ($httpCode === 404 || $httpCode === 410)) {
        $ch2 = curl_init('https://www.googleapis.com/calendar/v3/calendars/primary/events');
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_POST, true);
        curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode($eventPayload));
        curl_setopt($ch2, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch2, CURLOPT_TIMEOUT, 15);
        $res2 = curl_exec($ch2);
        $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);
        $json2 = json_decode($res2, true);
        if ($httpCode2 >= 200 && $httpCode2 < 300 && !empty($json2['id'])) {
            $googleEventId = $json2['id'];
            if (!empty($reminder['id'])) {
                try {
                    $conn->prepare("UPDATE `{$p}module_reminders` SET google_event_id = ? WHERE id = ?")->execute([$googleEventId, (int)$reminder['id']]);
                } catch (Throwable $e) {}
            }
            return $googleEventId;
        }
    }

    $errMsg = $json['error']['message'] ?? ($json['error_description'] ?? "HTTP {$httpCode}");
    $errorOut = "Google Calendar API Error: {$errMsg}";
    error_log("[Google Calendar Sync Error] HTTP {$httpCode}: {$res}");

    return null;
}

function reminder_delete_from_google_calendar(PDO $conn, string $p, string $googleEventId, int $userId): bool
{
    $accessToken = reminder_get_valid_google_calendar_token($conn, $p, $userId);
    if (!$accessToken || !$googleEventId) return false;

    $url = 'https://www.googleapis.com/calendar/v3/calendars/primary/events/' . urlencode($googleEventId);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($httpCode >= 200 && $httpCode < 300) || $httpCode === 404 || $httpCode === 410;
}
