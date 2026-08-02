<?php
// web/cron_campaigns.php
date_default_timezone_set('Asia/Kolkata');
set_time_limit(0);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/dynamic_modules.php';

$appTz = new DateTimeZone('Asia/Kolkata');
$logFile = __DIR__ . '/cron_campaigns.log';

function cron_log(string $message, string $logFile): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    echo $line;
    @file_put_contents($logFile, $line, FILE_APPEND);
}

$lockFile = __DIR__ . '/cron.lock';
$fp = fopen($lockFile, "w+");
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    cron_log('Cron is already running. Exiting to prevent duplicates.', $logFile);
    exit;
}

cron_log('Cron Campaigns Started', $logFile);

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
            $now = (new DateTimeImmutable('now', $appTz))->format('Y-m-d H:i:s');
            $cStmt = $conn->prepare("
                SELECT c.*, t.subject, t.body
                FROM {$tenantPrefix}campaigns c
                JOIN {$tenantPrefix}campaign_templates t ON t.id = c.template_id
                WHERE (c.status = 'scheduled' AND c.scheduled_at IS NOT NULL AND c.scheduled_at <= ?)
                   OR c.status = 'sending'
            ");
            $cStmt->execute([$now]);
            $dueCampaigns = $cStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($dueCampaigns)) {
                continue;
            }

            cron_log("Processing tenant: {$dbName}", $logFile);

            foreach ($dueCampaigns as $campaign) {
                $campaignId = (int)$campaign['id'];
                $sendDelay = (int)$campaign['send_delay'];
                $campaignType = $campaign['type'];

                cron_log("  Campaign #{$campaignId} ('{$campaign['name']}'): starting processing...", $logFile);

                // Mark campaign status as 'sending'
                $conn->prepare("UPDATE {$tenantPrefix}campaigns SET status = 'sending' WHERE id = ?")->execute([$campaignId]);

                // Get pending recipients (batch of 5 to prevent server timeouts and Hostinger SMTP rate limits)
                $rStmt = $conn->prepare("SELECT * FROM {$tenantPrefix}campaign_recipients WHERE campaign_id = ? AND status = 'pending' ORDER BY id ASC LIMIT 5");
                $rStmt->execute([$campaignId]);
                $recipients = $rStmt->fetchAll(PDO::FETCH_ASSOC);

                cron_log('    Found ' . count($recipients) . ' pending recipients.', $logFile);

                foreach ($recipients as $recipient) {
                    // Check if user paused or stopped campaign from UI
                    $statusCheck = $conn->prepare("SELECT status FROM {$tenantPrefix}campaigns WHERE id = ?");
                    $statusCheck->execute([$campaignId]);
                    $currentStatus = $statusCheck->fetchColumn();

                    if ($currentStatus !== 'sending') {
                        cron_log('    Campaign sending paused or cancelled by user. Aborting loop.', $logFile);
                        break;
                    }

                    // Resolve Personalization placeholders dynamically from configured fields
                    $cfStmt = $conn->query("SELECT field_key, label FROM {$tenantPrefix}campaign_fields");
                    $campaignFields = $cfStmt->fetchAll(PDO::FETCH_ASSOC);
                    $extraData = !empty($recipient['extra_data']) ? json_decode($recipient['extra_data'], true) : [];

                    $replacements = [];
                    foreach ($campaignFields as $cf) {
                        $fKey = $cf['field_key'];
                        $val = isset($extraData[$fKey]) ? $extraData[$fKey] : ($recipient[$fKey] ?? '');
                        $replacements['{' . $cf['label'] . '}'] = $val;
                    }

                    $subject = str_ireplace(array_keys($replacements), array_values($replacements), $campaign['subject'] ?? '');
                    $body = str_ireplace(array_keys($replacements), array_values($replacements), $campaign['body'] ?? '');

                    $sendSuccess = false;
                    $errorMsg = null;

                    if ($campaignType === 'email') {
                        $configData = null;
                        if (!empty($campaign['communication_config_id'])) {
                            $cStmt = $conn->prepare("SELECT config_data FROM {$tenantPrefix}communication_configs WHERE id = ? AND type = 'smtp'");
                            $cStmt->execute([$campaign['communication_config_id']]);
                            $cRow = $cStmt->fetch(PDO::FETCH_ASSOC);
                            if ($cRow && $cRow['config_data']) {
                                $configData = json_decode($cRow['config_data'], true);
                            }
                        }
                        if (!$configData) {
                            $cStmt = $conn->query("SELECT config_data FROM {$tenantPrefix}communication_configs WHERE type = 'smtp' AND is_default = 1 LIMIT 1");
                            $cRow = $cStmt->fetch(PDO::FETCH_ASSOC);
                            if ($cRow && $cRow['config_data']) {
                                $configData = json_decode($cRow['config_data'], true);
                            }
                        }

                        if (!$configData) {
                            $errorMsg = 'SMTP configuration is missing. Add one in Settings > Communications.';
                        } else {
                            $smtpHost = $configData['smtp_host'] ?? '';
                            $smtpPort = (int)($configData['smtp_port'] ?? 0);
                            $smtpUser = $configData['smtp_user'] ?? '';
                            $smtpPass = $configData['smtp_pass'] ?? '';
                            $smtpFromEmail = $configData['smtp_from_email'] ?? '';
                            $smtpFromName = $configData['smtp_from_name'] ?? '';
                            $smtpEnc = $configData['smtp_encryption'] ?? 'none';

                            if (!$smtpHost || !$smtpFromEmail) {
                                $errorMsg = 'SMTP settings are incomplete.';
                            } else {
                                $targetOption = $campaign['target_option'] ?? 'primary';
                                $emailsToSend = [];
                                if ($targetOption === 'primary' || $targetOption === 'both') {
                                    if (!empty($recipient['email'])) $emailsToSend[] = trim($recipient['email']);
                                }
                                if ($targetOption === 'additional' || $targetOption === 'both') {
                                    if (!empty($recipient['email2'])) $emailsToSend[] = trim($recipient['email2']);
                                }

                                if (empty($emailsToSend)) {
                                    $errorMsg = 'No email address found for the selected option.';
                                } else {
                                    $sendSuccess = true;
                                    foreach ($emailsToSend as $toEmail) {
                                        try {
                                            $ok = dm_send_smtp_email(
                                                $smtpHost, $smtpPort, $smtpUser, $smtpPass,
                                                $smtpFromEmail, $smtpFromName,
                                                $toEmail, $subject, $body, $smtpEnc
                                            );
                                            if (!$ok) {
                                                $sendSuccess = false;
                                                $errorMsg = 'Failed to send to ' . $toEmail;
                                            }
                                        } catch (Throwable $e) {
                                            $sendSuccess = false;
                                            $errorMsg = $e->getMessage();
                                        }
                                    }
                                }
                            }
                        }
                    } else {
                        $configData = null;
                        if (!empty($campaign['communication_config_id'])) {
                            $cStmt = $conn->prepare("SELECT config_data FROM {$tenantPrefix}communication_configs WHERE id = ? AND type = 'whatsapp'");
                            $cStmt->execute([$campaign['communication_config_id']]);
                            $cRow = $cStmt->fetch(PDO::FETCH_ASSOC);
                            if ($cRow && $cRow['config_data']) {
                                $configData = json_decode($cRow['config_data'], true);
                            }
                        }
                        if (!$configData) {
                            $cStmt = $conn->query("SELECT config_data FROM {$tenantPrefix}communication_configs WHERE type = 'whatsapp' AND is_default = 1 LIMIT 1");
                            $cRow = $cStmt->fetch(PDO::FETCH_ASSOC);
                            if ($cRow && $cRow['config_data']) {
                                $configData = json_decode($cRow['config_data'], true);
                            }
                        }

                        if (!$configData) {
                            $errorMsg = 'WhatsApp configuration is missing. Add one in Settings > Communications.';
                        } else {
                            $waUrl = $configData['wa_url'] ?? '';
                            $waToken = $configData['wa_token'] ?? '';

                            if (!$waUrl || !$waToken) {
                                $errorMsg = 'WhatsApp credentials are incomplete.';
                            } else {
                                $targetOption = $campaign['target_option'] ?? 'primary';
                                $phonesToSend = [];
                                if ($targetOption === 'primary' || $targetOption === 'both') {
                                    if (!empty($recipient['phone'])) $phonesToSend[] = trim($recipient['phone']);
                                }
                                if ($targetOption === 'additional' || $targetOption === 'both') {
                                    if (!empty($recipient['phone2'])) $phonesToSend[] = trim($recipient['phone2']);
                                }

                                if (empty($phonesToSend)) {
                                    $errorMsg = 'No phone number found for the selected option.';
                                } else {
                                    $sendSuccess = true;
                                    foreach ($phonesToSend as $toPhone) {
                                        try {
                                            $ok = dm_send_whatsapp_message($waUrl, $waToken, $toPhone, $body);
                                            if (!$ok) {
                                                $sendSuccess = false;
                                                $errorMsg = 'Failed to send to ' . $toPhone;
                                            }
                                        } catch (Throwable $e) {
                                            $sendSuccess = false;
                                            $errorMsg = $e->getMessage();
                                        }
                                    }
                                }
                            }
                        }
                    }

                    // Update recipient status
                    $newStatus = $sendSuccess ? 'sent' : 'failed';
                    $upStmt = $conn->prepare("UPDATE {$tenantPrefix}campaign_recipients SET status = ?, sent_at = NOW(), error_message = ? WHERE id = ?");
                    $upStmt->execute([$newStatus, $errorMsg, $recipient['id']]);

                    $sentContacts = [];
                    if ($campaignType === 'email') {
                        $targetOption = $campaign['target_option'] ?? 'primary';
                        if ($targetOption === 'primary' || $targetOption === 'both') {
                            if (!empty($recipient['email'])) $sentContacts[] = trim($recipient['email']);
                        }
                        if ($targetOption === 'additional' || $targetOption === 'both') {
                            if (!empty($recipient['email2'])) $sentContacts[] = trim($recipient['email2']);
                        }
                    } else {
                        $targetOption = $campaign['target_option'] ?? 'primary';
                        if ($targetOption === 'primary' || $targetOption === 'both') {
                            if (!empty($recipient['phone'])) $sentContacts[] = trim($recipient['phone']);
                        }
                        if ($targetOption === 'additional' || $targetOption === 'both') {
                            if (!empty($recipient['phone2'])) $sentContacts[] = trim($recipient['phone2']);
                        }
                    }
                    $contactStr = implode(', ', $sentContacts);

                    $recipientLog = '      Recipient #' . $recipient['id'] . ' (' . $contactStr . '): ' . $newStatus . ($errorMsg ? ' (Error: ' . $errorMsg . ')' : '');
                    cron_log($recipientLog, $logFile);

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
                    // Check if any recipients are still pending
                    $pendStmt = $conn->prepare("SELECT COUNT(*) FROM {$tenantPrefix}campaign_recipients WHERE campaign_id = ? AND status = 'pending'");
                    $pendStmt->execute([$campaignId]);
                    $pending = (int)$pendStmt->fetchColumn();

                    if ($pending === 0) {
                        $failStmt = $conn->prepare("SELECT COUNT(*) FROM {$tenantPrefix}campaign_recipients WHERE campaign_id = ? AND status = 'failed'");
                        $failStmt->execute([$campaignId]);
                        $failed = (int)$failStmt->fetchColumn();

                        $totStmt = $conn->prepare("SELECT COUNT(*) FROM {$tenantPrefix}campaign_recipients WHERE campaign_id = ?");
                        $totStmt->execute([$campaignId]);
                        $total = (int)$totStmt->fetchColumn();

                        $finalStatus = ($failed === $total && $total > 0) ? 'failed' : 'completed';
                        $conn->prepare("UPDATE {$tenantPrefix}campaigns SET status = ? WHERE id = ?")->execute([$finalStatus, $campaignId]);
                        cron_log("    Campaign #{$campaignId} completed processing. Status set to: {$finalStatus}", $logFile);
                    } else {
                        cron_log("    Campaign #{$campaignId} has {$pending} recipients left. Will resume in next cron tick.", $logFile);
                    }
                }
            }
        } catch (Exception $e) {
            cron_log("  [ERROR] Tenant {$dbName} failed: " . $e->getMessage(), $logFile);
        }
    }
} catch (Exception $e) {
    cron_log('Global Connection Error: ' . $e->getMessage(), $logFile);
}

cron_log('Cron Campaigns Completed', $logFile);
