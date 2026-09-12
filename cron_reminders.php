<?php
/**
 * cron_reminders.php
 * 
 * Background Dispatcher Cron Engine for Dynamic Module Reminders.
 * Dispatches due WhatsApp, Push Notification (FCM), and Email reminders across all tenant databases.
 * 
 * Usage:
 *   CLI:  php cron_reminders.php
 *   HTTP: https://your-crm-domain.com/cron_reminders.php
 */

date_default_timezone_set('Asia/Kolkata');
set_time_limit(300);
ini_set('memory_limit', '256M');

$isCli = (php_sapi_name() === 'cli' || defined('STDIN'));

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/commerce.php';
require_once __DIR__ . '/includes/dynamic_modules.php';
require_once __DIR__ . '/includes/reminder_helper.php';

$logFile = __DIR__ . '/cron_reminders.log';

function reminder_cron_log(string $message, string $logFile): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    echo $line;
    @file_put_contents($logFile, $line, FILE_APPEND);
}

$lockFile = __DIR__ . '/cron_reminders.lock';
$fp = fopen($lockFile, "w+");
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    reminder_cron_log('Cron Reminders is already running. Exiting to prevent duplicates.', $logFile);
    exit;
}

reminder_cron_log('=== Cron Reminders Started ===', $logFile);

$startTime = microtime(true);
$resultsSummary = [
    'timestamp' => date('Y-m-d H:i:s'),
    'total_checked' => 0,
    'total_dispatched' => 0,
    'total_failed' => 0,
    'details' => []
];

try {
    $masterConn = Database::getMasterConn();
    $prefix = Database::getMasterPrefix();

    // Fetch all active company tenants
    $stmt = $masterConn->query("SELECT db_name, slug FROM {$prefix}companies");
    $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($tenants as $tenant) {
        $dbName = $tenant['db_name'];
        $slug = $tenant['slug'];

        $isIsolated = true;
        $tenantPrefix = $isIsolated ? "" : $slug . "_";

        $conn = Database::getTenantConn($dbName);
        if (!$conn) {
            reminder_cron_log("  [SKIPPED] Could not connect to tenant database: $dbName", $logFile);
            continue;
        }

        try {
            $conn->exec("SET time_zone = '+05:30'");
        } catch (Throwable $e) {}

        reminder_ensure_tables($conn, $tenantPrefix);

        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');

        // Fetch due pending reminders (remind_at <= $now in IST)
        $dueStmt = $conn->prepare("
            SELECT r.* 
            FROM `{$tenantPrefix}module_reminders` r
            LEFT JOIN `{$tenantPrefix}modules` m ON m.id = r.module_id
            WHERE r.status = 'pending' 
              AND r.remind_at <= ?
              AND (r.module_id IS NULL OR r.module_id = 0 OR m.enable_reminders IS NULL OR m.enable_reminders = 1)
            ORDER BY r.remind_at ASC
            LIMIT 50
        ");
        $dueStmt->execute([$now]);
        $dueReminders = $dueStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($dueReminders)) {
            reminder_cron_log("  [TENANT {$dbName}] Found " . count($dueReminders) . " due reminders (Target <= {$now}).", $logFile);
        }

        $resultsSummary['total_checked'] += count($dueReminders);

        foreach ($dueReminders as $reminder) {
            $remId = (int)$reminder['id'];
            
            // Atomic lock status to 'sent' immediately to prevent any duplicate execution from subsequent or concurrent cron jobs
            $claimStmt = $conn->prepare("UPDATE `{$tenantPrefix}module_reminders` SET status = 'sent', sent_at = ? WHERE id = ? AND status = 'pending'");
            $claimStmt->execute([$now, $remId]);
            if ($claimStmt->rowCount() === 0) {
                reminder_cron_log("    [SKIPPED] Reminder #{$remId} already processed by another process.", $logFile);
                continue;
            }

            // Dispatch across selected channels exactly once
            $res = reminder_dispatch_single($conn, $tenantPrefix, $reminder);
            if ($res['success']) {
                $resultsSummary['total_dispatched']++;
                reminder_cron_log("    [SUCCESS] Reminder #{$remId} ('{$reminder['title']}') sent via: {$reminder['channels']}", $logFile);
            } else {
                $resultsSummary['total_failed']++;
                reminder_cron_log("    [PARTIAL/FAILED] Reminder #{$remId} ('{$reminder['title']}'). Errors: " . implode('; ', $res['errors']), $logFile);
            }

            $resultsSummary['details'][] = [
                'tenant' => $dbName,
                'reminder_id' => $remId,
                'title' => $reminder['title'],
                'channels' => $reminder['channels'],
                'result' => $res['status'],
                'channel_status' => $res['channel_status'],
                'errors' => $res['errors']
            ];
        }
    }

} catch (Throwable $e) {
    $resultsSummary['error'] = $e->getMessage();
    reminder_cron_log('Global Connection Error: ' . $e->getMessage(), $logFile);
}

$duration = round(microtime(true) - $startTime, 4);
$resultsSummary['duration_seconds'] = $duration;

reminder_cron_log("=== Cron Reminders Completed in {$duration}s (Checked: {$resultsSummary['total_checked']}, Dispatched: {$resultsSummary['total_dispatched']}, Failed: {$resultsSummary['total_failed']}) ===", $logFile);

if (!$isCli) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'summary' => $resultsSummary], JSON_PRETTY_PRINT);
}
