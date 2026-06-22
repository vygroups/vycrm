<?php
// web/cron_campaigns.php
date_default_timezone_set('Asia/Kolkata');
set_time_limit(0);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/dynamic_modules.php';

echo "Cron Campaigns Started at " . date('Y-m-d H:i:s') . "\n";

try {
    $masterConn = Database::getMasterConn();
    $prefix = Database::getMasterPrefix();
    $masterDB = Database::getMasterDBName();

    // Fetch all active company tenants
    $stmt = $masterConn->query("SELECT db_name, slug FROM {$prefix}companies");
    $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Also add the master tenant itself (it's not in the companies table)
    array_unshift($tenants, ['db_name' => $masterDB, 'slug' => 'vycrm']);

    foreach ($tenants as $tenant) {
        $dbName = $tenant['db_name'];
        $slug = $tenant['slug'];

        $isIsolated = ($dbName != $masterDB);
        $tenantPrefix = $isIsolated ? "" : $slug . "_";

        $conn = Database::getTenantConn($dbName);
        if (!$conn) {
            echo "  [SKIPPED] Could not connect to tenant database: $dbName\n";
            continue;
        }

        // Ensure database schema is up-to-date
        try {
            dm_ensure_tables($conn, $tenantPrefix);
        } catch (Throwable $e) {
            echo "  [WARNING] Schema update failed for $dbName: " . $e->getMessage() . "\n";
        }

        try {
            // Find campaigns marked as scheduled where scheduled_at is in the past or present
            $now = date('Y-m-d H:i:s');
            $cStmt = $conn->prepare("
                SELECT c.*, t.subject, t.body
                FROM {$tenantPrefix}campaigns c
                JOIN {$tenantPrefix}campaign_templates t ON t.id = c.template_id
                WHERE c.status = 'scheduled' AND c.scheduled_at <= ?
            ");
            $cStmt->execute([$now]);
            $dueCampaigns = $cStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($dueCampaigns)) {
                continue;
            }

            echo "Processing tenant: $dbName\n";

            foreach ($dueCampaigns as $campaign) {
                $campaignId = (int)$campaign['id'];
                $sendDelay = (int)$campaign['send_delay'];
                $campaignType = $campaign['type'];

                echo "  Campaign #{$campaignId} ('{$campaign['name']}'): starting processing...\n";

                // Mark campaign status as 'sending'
                $conn->prepare("UPDATE {$tenantPrefix}campaigns SET status = 'sending' WHERE id = ?")->execute([$campaignId]);

                // Get pending recipients
                $rStmt = $conn->prepare("SELECT * FROM {$tenantPrefix}campaign_recipients WHERE campaign_id = ? AND status = 'pending' ORDER BY id ASC");
                $rStmt->execute([$campaignId]);
                $recipients = $rStmt->fetchAll(PDO::FETCH_ASSOC);

                echo "    Found " . count($recipients) . " pending recipients.\n";

                foreach ($recipients as $recipient) {
                    // Check if user paused or stopped campaign from UI
                    $statusCheck = $conn->prepare("SELECT status FROM {$tenantPrefix}campaigns WHERE id = ?");
                    $statusCheck->execute([$campaignId]);
                    $currentStatus = $statusCheck->fetchColumn();

                    if ($currentStatus !== 'sending') {
                        echo "    Campaign sending paused or cancelled by user. Aborting loop.\n";
                        break;
                    }

                    // Resolve placeholders
                    $replacements = [
                        '{First Name}' => $recipient['first_name'] ?: '',
                        '{Last Name}' => $recipient['last_name'] ?: '',
                        '{Company Name}' => $recipient['company_name'] ?: '',
                        '{Designation}' => $recipient['designation'] ?: ''
                    ];

                    $subject = str_replace(array_keys($replacements), array_values($replacements), $campaign['subject'] ?? '');
                    $body = str_replace(array_keys($replacements), array_values($replacements), $campaign['body'] ?? '');

                    $sendSuccess = false;
                    $errorMsg = null;

                    if ($campaignType === 'email') {
                        $smtpHost = dm_get_system_setting($conn, $tenantPrefix, 'smtp_host', '');
                        $smtpPort = (int)dm_get_system_setting($conn, $tenantPrefix, 'smtp_port', '587');
                        $smtpUser = dm_get_system_setting($conn, $tenantPrefix, 'smtp_user', '');
                        $smtpPass = dm_get_system_setting($conn, $tenantPrefix, 'smtp_pass', '');
                        $smtpFromEmail = dm_get_system_setting($conn, $tenantPrefix, 'smtp_from_email', '');
                        $smtpFromName = dm_get_system_setting($conn, $tenantPrefix, 'smtp_from_name', '');
                        $smtpEnc = dm_get_system_setting($conn, $tenantPrefix, 'smtp_encryption', 'none');

                        if (!$smtpHost || !$smtpFromEmail) {
                            $errorMsg = 'SMTP settings are not configured. Go to Settings > Business Profile.';
                        } else if (!$recipient['email']) {
                            $errorMsg = 'No email address provided for recipient.';
                        } else {
                            try {
                                $sendSuccess = dm_send_smtp_email(
                                    $smtpHost, $smtpPort, $smtpUser, $smtpPass,
                                    $smtpFromEmail, $smtpFromName,
                                    $recipient['email'], $subject, $body, $smtpEnc
                                );
                            } catch (Throwable $e) {
                                $errorMsg = $e->getMessage();
                            }
                        }
                    } else {
                        $waUrl = dm_get_system_setting($conn, $tenantPrefix, 'whatsapp_api_url', '');
                        $waToken = dm_get_system_setting($conn, $tenantPrefix, 'whatsapp_access_token', '');

                        if (!$waUrl || !$waToken) {
                            $errorMsg = 'WhatsApp credentials not configured. Save them under Modules > Configuration rules.';
                        } else if (!$recipient['phone']) {
                            $errorMsg = 'No phone number provided for recipient.';
                        } else {
                            try {
                                $sendSuccess = dm_send_whatsapp_message($waUrl, $waToken, $recipient['phone'], $body);
                            } catch (Throwable $e) {
                                $errorMsg = $e->getMessage();
                            }
                        }
                    }

                    // Update recipient status
                    $newStatus = $sendSuccess ? 'sent' : 'failed';
                    $upStmt = $conn->prepare("UPDATE {$tenantPrefix}campaign_recipients SET status = ?, sent_at = NOW(), error_message = ? WHERE id = ?");
                    $upStmt->execute([$newStatus, $errorMsg, $recipient['id']]);

                    echo "      Recipient #{$recipient['id']} ({$recipient['email']}/{$recipient['phone']}): {$newStatus}" . ($errorMsg ? " (Error: {$errorMsg})" : "") . "\n";

                    // Respect delay between messages
                    if ($sendDelay > 0) {
                        sleep($sendDelay);
                    }
                }

                // If the campaign wasn't paused/altered, determine and set final campaign status
                $statusCheck = $conn->prepare("SELECT status FROM {$tenantPrefix}campaigns WHERE id = ?");
                $statusCheck->execute([$campaignId]);
                $currentStatus = $statusCheck->fetchColumn();

                if ($currentStatus === 'sending') {
                    $failStmt = $conn->prepare("SELECT COUNT(*) FROM {$tenantPrefix}campaign_recipients WHERE campaign_id = ? AND status = 'failed'");
                    $failStmt->execute([$campaignId]);
                    $failed = (int)$failStmt->fetchColumn();

                    $totStmt = $conn->prepare("SELECT COUNT(*) FROM {$tenantPrefix}campaign_recipients WHERE campaign_id = ?");
                    $totStmt->execute([$campaignId]);
                    $total = (int)$totStmt->fetchColumn();

                    $finalStatus = ($failed === $total && $total > 0) ? 'failed' : 'completed';
                    $conn->prepare("UPDATE {$tenantPrefix}campaigns SET status = ? WHERE id = ?")->execute([$finalStatus, $campaignId]);
                    echo "    Campaign #{$campaignId} completed processing. Status set to: {$finalStatus}\n";
                }
            }
        } catch (Exception $e) {
            echo "  [ERROR] Tenant {$dbName} failed: " . $e->getMessage() . "\n";
        }
    }
} catch (Exception $e) {
    echo "Global Connection Error: " . $e->getMessage() . "\n";
}

echo "Cron Campaigns Completed at " . date('Y-m-d H:i:s') . "\n";
