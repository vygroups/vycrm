<?php
/**
 * api/campaigns_api.php
 * 
 * REST API for Campaigns and Templates.
 * Actions: list_templates, get_template, save_template, delete_template,
 *          list_campaigns, get_campaign, save_campaign, delete_campaign,
 *          import_recipients, send_next_recipient, retry_failed
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

// Ensure campaign tables are present
dm_ensure_tables($conn, $prefix);

$input = commerce_read_input();
$action = $_GET['action'] ?? $input['action'] ?? '';

try {
    switch ($action) {

        /* ════════════════════ CAMPAIGN FIELD MAPPINGS ════════════════════ */

        case 'save_field_mapping':
            $moduleId = (int)($input['module_id'] ?? 0);
            if (!$moduleId) throw new RuntimeException('Module ID required');
            $mapping = $input['mapping'] ?? null;
            if (!$mapping || !is_array($mapping)) throw new RuntimeException('Mapping required');
            
            $key = "campaign_mapping_" . $moduleId;
            $value = json_encode($mapping);
            dm_set_system_setting($conn, $prefix, $key, $value);
            commerce_json_response(['success' => true]);

        case 'get_field_mapping':
            $moduleId = (int)($_GET['module_id'] ?? $input['module_id'] ?? 0);
            if (!$moduleId) throw new RuntimeException('Module ID required');
            
            $key = "campaign_mapping_" . $moduleId;
            $value = dm_get_system_setting($conn, $prefix, $key, null);
            $mapping = $value ? json_decode($value, true) : null;
            commerce_json_response(['success' => true, 'mapping' => $mapping]);

        case 'delete_field_mapping':
            $moduleId = (int)($input['module_id'] ?? 0);
            if (!$moduleId) throw new RuntimeException('Module ID required');
            $key = "campaign_mapping_" . $moduleId;
            $stmt = $conn->prepare("DELETE FROM {$prefix}system_settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            commerce_json_response(['success' => true]);

        /* ════════════════════ CAMPAIGN TEMPLATES CRUD ════════════════════ */

        case 'list_templates':
            $stmt = $conn->query("SELECT id, name, type, subject, created_at FROM {$prefix}campaign_templates ORDER BY created_at DESC");
            $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            commerce_json_response(['success' => true, 'templates' => $templates]);

        case 'get_template':
            $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
            if (!$id) throw new RuntimeException('Template ID required');
            $stmt = $conn->prepare("SELECT * FROM {$prefix}campaign_templates WHERE id = ?");
            $stmt->execute([$id]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$template) throw new RuntimeException('Template not found');
            commerce_json_response(['success' => true, 'template' => $template]);

        case 'save_template':
            $id = (int)($input['id'] ?? 0);
            $name = trim($input['name'] ?? '');
            $type = trim($input['type'] ?? 'email');
            $subject = trim($input['subject'] ?? '');
            $body = trim($input['body'] ?? '');

            if (!$name) throw new RuntimeException('Template name required');
            if (!$body) throw new RuntimeException('Message body required');

            if ($id > 0) {
                $stmt = $conn->prepare("UPDATE {$prefix}campaign_templates SET name = ?, type = ?, subject = ?, body = ? WHERE id = ?");
                $stmt->execute([$name, $type, $subject, $body, $id]);
                $savedId = $id;
            } else {
                $stmt = $conn->prepare("INSERT INTO {$prefix}campaign_templates (name, type, subject, body) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $type, $subject, $body]);
                $savedId = (int)$conn->lastInsertId();
            }
            commerce_json_response(['success' => true, 'id' => $savedId]);

        case 'delete_template':
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new RuntimeException('Template ID required');
            $conn->prepare("DELETE FROM {$prefix}campaign_templates WHERE id = ?")->execute([$id]);
            commerce_json_response(['success' => true]);


        /* ════════════════════ CAMPAIGNS CRUD ════════════════════ */

        case 'list_campaigns':
            $stmt = $conn->query("
                SELECT c.*, t.name as template_name,
                       (SELECT COUNT(*) FROM {$prefix}campaign_recipients WHERE campaign_id = c.id) AS total_recipients,
                       (SELECT COUNT(*) FROM {$prefix}campaign_recipients WHERE campaign_id = c.id AND status = 'sent') AS sent_count,
                       (SELECT COUNT(*) FROM {$prefix}campaign_recipients WHERE campaign_id = c.id AND status = 'failed') AS failed_count
                FROM {$prefix}campaigns c
                LEFT JOIN {$prefix}campaign_templates t ON t.id = c.template_id
                ORDER BY c.created_at DESC
            ");
            $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            commerce_json_response(['success' => true, 'campaigns' => $campaigns]);

        case 'get_campaign':
            $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
            if (!$id) throw new RuntimeException('Campaign ID required');
            
            // Fetch campaign metadata
            $stmt = $conn->prepare("
                SELECT c.*, t.name as template_name, t.subject as template_subject, t.body as template_body
                FROM {$prefix}campaigns c
                LEFT JOIN {$prefix}campaign_templates t ON t.id = c.template_id
                WHERE c.id = ?
            ");
            $stmt->execute([$id]);
            $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$campaign) throw new RuntimeException('Campaign not found');

            // Fetch recipients
            $rStmt = $conn->prepare("SELECT * FROM {$prefix}campaign_recipients WHERE campaign_id = ? ORDER BY id ASC");
            $rStmt->execute([$id]);
            $recipients = $rStmt->fetchAll(PDO::FETCH_ASSOC);

            commerce_json_response(['success' => true, 'campaign' => $campaign, 'recipients' => $recipients]);

        case 'save_campaign':
            $id = (int)($input['id'] ?? 0);
            $name = trim($input['name'] ?? '');
            $type = trim($input['type'] ?? 'email');
            $templateId = (int)($input['template_id'] ?? 0);
            $sendMode = trim($input['send_mode'] ?? 'immediate');
            $sendDelay = (int)($input['send_delay'] ?? 0);

            if (!$name) throw new RuntimeException('Campaign name required');
            if (!$templateId) throw new RuntimeException('Template selection required');

            $scheduledAt = null;
            $status = 'draft';
            if ($sendMode === 'schedule') {
                $rawScheduledAt = trim($input['scheduled_at'] ?? '');
                if (!$rawScheduledAt) {
                    throw new RuntimeException('Schedule date and time required for scheduled campaigns');
                }
                $scheduledAt = date('Y-m-d H:i:s', strtotime($rawScheduledAt));
                $status = 'scheduled';
            }

            $commConfigId = !empty($input['communication_config_id']) ? (int)$input['communication_config_id'] : null;

            if ($id > 0) {
                $existingStmt = $conn->prepare("SELECT status FROM {$prefix}campaigns WHERE id = ?");
                $existingStmt->execute([$id]);
                $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    $existingStatus = $existing['status'];
                    if ($existingStatus !== 'draft' && $existingStatus !== 'scheduled') {
                        throw new RuntimeException('Cannot edit a campaign that has already started or completed.');
                    }
                }
                $stmt = $conn->prepare("UPDATE {$prefix}campaigns SET name = ?, type = ?, template_id = ?, scheduled_at = ?, send_delay = ?, status = ?, communication_config_id = ? WHERE id = ?");
                $stmt->execute([$name, $type, $templateId, $scheduledAt, $sendDelay, $status, $commConfigId, $id]);
                $savedId = $id;
            } else {
                $stmt = $conn->prepare("INSERT INTO {$prefix}campaigns (name, type, template_id, scheduled_at, send_delay, status, created_by, communication_config_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $type, $templateId, $scheduledAt, $sendDelay, $status, $userId, $commConfigId]);
                $savedId = (int)$conn->lastInsertId();
            }
            commerce_json_response(['success' => true, 'id' => $savedId]);

        case 'delete_campaign':
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new RuntimeException('Campaign ID required');
            $conn->prepare("DELETE FROM {$prefix}campaigns WHERE id = ?")->execute([$id]);
            commerce_json_response(['success' => true]);


        /* ════════════════════ CAMPAIGN RECIPIENTS IMPORT ════════════════════ */

        case 'import_recipients':
            $campaignId = (int)($input['campaign_id'] ?? 0);
            $recipients = $input['recipients'] ?? [];

            if (!$campaignId) throw new RuntimeException('Campaign ID is required');
            if (!is_array($recipients) || empty($recipients)) throw new RuntimeException('No contacts to import');

            // Delete existing recipients for this campaign
            $conn->prepare("DELETE FROM {$prefix}campaign_recipients WHERE campaign_id = ?")->execute([$campaignId]);

            // Bulk insert recipients
            $stmt = $conn->prepare("
                INSERT INTO {$prefix}campaign_recipients 
                (campaign_id, first_name, last_name, email, phone, company_name, designation, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
            ");

            $conn->beginTransaction();
            foreach ($recipients as $r) {
                $fName = trim($r['first_name'] ?? $r['First Name'] ?? '');
                $lName = trim($r['last_name'] ?? $r['Last Name'] ?? '');
                $email = trim($r['email'] ?? $r['Email'] ?? '');
                $phone = trim($r['phone'] ?? $r['Phone'] ?? $r['Mobile'] ?? '');
                $company = trim($r['company_name'] ?? $r['Company Name'] ?? $r['Company'] ?? '');
                $designation = trim($r['designation'] ?? $r['Designation'] ?? '');

                if (!$email && !$phone) continue; // Skip entries without contacts

                $stmt->execute([$campaignId, $fName, $lName, $email, $phone, $company, $designation]);
            }
            $conn->commit();

            // Set campaign status back to draft, UNLESS it is already scheduled
            $conn->prepare("UPDATE {$prefix}campaigns SET status = IF(status = 'scheduled', 'scheduled', 'draft') WHERE id = ?")->execute([$campaignId]);

            commerce_json_response(['success' => true, 'count' => count($recipients)]);


        /* ════════════════════ PROGRESSIVE SENDING ENGINE ════════════════════ */

        case 'send_next_recipient':
            $campaignId = (int)($input['campaign_id'] ?? 0);
            if (!$campaignId) throw new RuntimeException('Campaign ID required');

            // Fetch campaign and template configuration
            $cStmt = $conn->prepare("
                SELECT c.*, t.subject, t.body
                FROM {$prefix}campaigns c
                JOIN {$prefix}campaign_templates t ON t.id = c.template_id
                WHERE c.id = ?
            ");
            $cStmt->execute([$campaignId]);
            $campaign = $cStmt->fetch(PDO::FETCH_ASSOC);
            if (!$campaign) throw new RuntimeException('Campaign not found');

            // Update campaign status to 'sending' if it's currently 'draft' or 'scheduled'
            if ($campaign['status'] === 'draft' || $campaign['status'] === 'scheduled' || $campaign['status'] === 'failed' || $campaign['status'] === 'completed') {
                $conn->prepare("UPDATE {$prefix}campaigns SET status = 'sending' WHERE id = ?")->execute([$campaignId]);
            }

            // Find next pending recipient
            $rStmt = $conn->prepare("SELECT * FROM {$prefix}campaign_recipients WHERE campaign_id = ? AND status = 'pending' ORDER BY id ASC LIMIT 1");
            $rStmt->execute([$campaignId]);
            $recipient = $rStmt->fetch(PDO::FETCH_ASSOC);

            if (!$recipient) {
                // Determine overall status
                $failStmt = $conn->prepare("SELECT COUNT(*) FROM {$prefix}campaign_recipients WHERE campaign_id = ? AND status = 'failed'");
                $failStmt->execute([$campaignId]);
                $failed = (int)$failStmt->fetchColumn();

                $totStmt = $conn->prepare("SELECT COUNT(*) FROM {$prefix}campaign_recipients WHERE campaign_id = ?");
                $totStmt->execute([$campaignId]);
                $total = (int)$totStmt->fetchColumn();

                $finalStatus = ($failed === $total && $total > 0) ? 'failed' : 'completed';
                $conn->prepare("UPDATE {$prefix}campaigns SET status = ? WHERE id = ?")->execute([$finalStatus, $campaignId]);

                commerce_json_response(['success' => true, 'finished' => true, 'campaign_status' => $finalStatus]);
            }

            // Resolve Personalization placeholders
            $replacements = [
                '{First Name}' => $recipient['first_name'] ?: '',
                '{Last Name}' => $recipient['last_name'] ?: '',
                '{Company Name}' => $recipient['company_name'] ?: '',
                '{Designation}' => $recipient['designation'] ?: ''
            ];

            $subject = str_ireplace(array_keys($replacements), array_values($replacements), $campaign['subject'] ?? '');
            $body = str_ireplace(array_keys($replacements), array_values($replacements), $campaign['body'] ?? '');

            $sendSuccess = false;
            $errorMsg = null;

            if ($campaign['type'] === 'email') {
                $configData = null;
                if (!empty($campaign['communication_config_id'])) {
                    $cStmt = $conn->prepare("SELECT config_data FROM {$prefix}communication_configs WHERE id = ? AND type = 'smtp'");
                    $cStmt->execute([$campaign['communication_config_id']]);
                    $cRow = $cStmt->fetch(PDO::FETCH_ASSOC);
                    if ($cRow && $cRow['config_data']) {
                        $configData = json_decode($cRow['config_data'], true);
                    }
                }
                if (!$configData) {
                    $cStmt = $conn->query("SELECT config_data FROM {$prefix}communication_configs WHERE type = 'smtp' AND is_default = 1 LIMIT 1");
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
                }
            } else {
                $configData = null;
                if (!empty($campaign['communication_config_id'])) {
                    $cStmt = $conn->prepare("SELECT config_data FROM {$prefix}communication_configs WHERE id = ? AND type = 'whatsapp'");
                    $cStmt->execute([$campaign['communication_config_id']]);
                    $cRow = $cStmt->fetch(PDO::FETCH_ASSOC);
                    if ($cRow && $cRow['config_data']) {
                        $configData = json_decode($cRow['config_data'], true);
                    }
                }
                if (!$configData) {
                    $cStmt = $conn->query("SELECT config_data FROM {$prefix}communication_configs WHERE type = 'whatsapp' AND is_default = 1 LIMIT 1");
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
            }

            // Update recipient status
            $newStatus = $sendSuccess ? 'sent' : 'failed';
            $upStmt = $conn->prepare("UPDATE {$prefix}campaign_recipients SET status = ?, sent_at = NOW(), error_message = ? WHERE id = ?");
            $upStmt->execute([$newStatus, $errorMsg, $recipient['id']]);

            commerce_json_response([
                'success' => true,
                'finished' => false,
                'recipient' => [
                    'id' => $recipient['id'],
                    'name' => trim($recipient['first_name'] . ' ' . $recipient['last_name']),
                    'contact' => $campaign['type'] === 'email' ? $recipient['email'] : $recipient['phone'],
                    'status' => $newStatus,
                    'error' => $errorMsg
                ]
            ]);

        case 'retry_failed':
            $campaignId = (int)($input['campaign_id'] ?? 0);
            if (!$campaignId) throw new RuntimeException('Campaign ID required');

            // Reset failed recipients of this campaign back to pending
            $conn->prepare("UPDATE {$prefix}campaign_recipients SET status = 'pending', error_message = NULL WHERE campaign_id = ? AND status = 'failed'")->execute([$campaignId]);
            // Reset campaign status to draft
            $conn->prepare("UPDATE {$prefix}campaigns SET status = 'draft' WHERE id = ?")->execute([$campaignId]);

            commerce_json_response(['success' => true]);

        default:
            throw new RuntimeException("Unknown action: $action");
    }
} catch (Throwable $e) {
    commerce_json_response(['success' => false, 'error' => $e->getMessage()], 400);
}
