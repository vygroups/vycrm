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

            // Fetch all fields for this module so the client can map fields regardless of is_list_visible
            $fieldsStmt = $conn->prepare("SELECT id, field_key, label FROM {$prefix}module_fields WHERE module_id = ?");
            $fieldsStmt->execute([$moduleId]);
            $allFields = $fieldsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            commerce_json_response([
                'success' => true, 
                'mapping' => $mapping, 
                'all_fields' => $allFields
            ]);

        case 'delete_field_mapping':
            $moduleId = (int)($input['module_id'] ?? 0);
            if (!$moduleId) throw new RuntimeException('Module ID required');
            $key = "campaign_mapping_" . $moduleId;
            $stmt = $conn->prepare("DELETE FROM {$prefix}system_settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            commerce_json_response(['success' => true]);

        /* ════════════════════ CAMPAIGN CUSTOM FIELDS ════════════════════ */

        case 'list_campaign_fields':
            $stmt = $conn->query("SELECT * FROM {$prefix}campaign_fields ORDER BY sort_order ASC, id ASC");
            $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($fields as &$f) {
                $f['options'] = $f['options'] ? json_decode($f['options'], true) : [];
                $f['is_required'] = (int)$f['is_required'];
                $f['sort_order'] = (int)$f['sort_order'];
            }
            unset($f);
            commerce_json_response(['success' => true, 'fields' => $fields]);

        case 'save_campaign_field':
            $fId = (int)($input['id'] ?? 0);
            $label = trim($input['label'] ?? '');
            $fieldType = trim($input['field_type'] ?? 'text');
            $placeholder = trim($input['placeholder'] ?? '');
            $options = $input['options'] ?? [];
            $isRequired = (int)($input['is_required'] ?? 0);
            $sortOrder = (int)($input['sort_order'] ?? 0);

            if (!$label) throw new RuntimeException('Field label is required');

            $allowedTypes = ['text','email','phone','number','textarea','select','date','url'];
            if (!in_array($fieldType, $allowedTypes)) $fieldType = 'text';

            $optionsJson = !empty($options) ? json_encode($options) : null;

            if ($fId > 0) {
                $stmt = $conn->prepare("UPDATE {$prefix}campaign_fields SET label=?, field_type=?, placeholder=?, options=?, is_required=?, sort_order=? WHERE id=?");
                $stmt->execute([$label, $fieldType, $placeholder, $optionsJson, $isRequired, $sortOrder, $fId]);
                $savedId = $fId;
            } else {
                // Generate field_key from label
                $fieldKey = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $label));
                $fieldKey = trim($fieldKey, '_');
                // Check uniqueness, append number if needed
                $baseKey = $fieldKey;
                $counter = 1;
                while (true) {
                    $ck = $conn->prepare("SELECT id FROM {$prefix}campaign_fields WHERE field_key = ?");
                    $ck->execute([$fieldKey]);
                    if (!$ck->fetch()) break;
                    $fieldKey = $baseKey . '_' . $counter++;
                }
                // Auto sort_order
                $maxStmt = $conn->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM {$prefix}campaign_fields");
                $sortOrder = (int)$maxStmt->fetchColumn();
                $stmt = $conn->prepare("INSERT INTO {$prefix}campaign_fields (field_key,label,field_type,placeholder,options,is_required,sort_order) VALUES (?,?,?,?,?,?,?)");
                $stmt->execute([$fieldKey, $label, $fieldType, $placeholder, $optionsJson, $isRequired, $sortOrder]);
                $savedId = (int)$conn->lastInsertId();
            }
            $row = $conn->prepare("SELECT * FROM {$prefix}campaign_fields WHERE id=?");
            $row->execute([$savedId]);
            $saved = $row->fetch(PDO::FETCH_ASSOC);
            $saved['options'] = $saved['options'] ? json_decode($saved['options'], true) : [];
            commerce_json_response(['success' => true, 'field' => $saved]);

        case 'delete_campaign_field':
            $fId = (int)($input['id'] ?? 0);
            if (!$fId) throw new RuntimeException('Field ID required');
            $conn->prepare("DELETE FROM {$prefix}campaign_fields WHERE id=?")->execute([$fId]);
            commerce_json_response(['success' => true]);

        case 'reorder_campaign_fields':
            $order = $input['order'] ?? []; // [{id: 1, sort_order: 0}, ...]
            if (is_array($order)) {
                $upd = $conn->prepare("UPDATE {$prefix}campaign_fields SET sort_order=? WHERE id=?");
                foreach ($order as $item) {
                    $upd->execute([(int)($item['sort_order'] ?? 0), (int)($item['id'] ?? 0)]);
                }
            }
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
            // Decode extra_data JSON
            foreach ($recipients as &$r) {
                $r['extra_data'] = !empty($r['extra_data']) ? json_decode($r['extra_data'], true) : [];
            }
            unset($r);

            // Also return custom field definitions
            $cfStmt = $conn->query("SELECT * FROM {$prefix}campaign_fields ORDER BY sort_order ASC, id ASC");
            $customFields = $cfStmt->fetchAll(PDO::FETCH_ASSOC);

            commerce_json_response(['success' => true, 'campaign' => $campaign, 'recipients' => $recipients, 'custom_fields' => $customFields]);

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

                $appTz = new DateTimeZone('Asia/Kolkata');
                $scheduleDate = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $rawScheduledAt, $appTz);
                if (!$scheduleDate) {
                    $scheduleDate = new DateTimeImmutable($rawScheduledAt, $appTz);
                }
                if (!$scheduleDate) {
                    throw new RuntimeException('Invalid schedule date and time format');
                }

                $scheduledAt = $scheduleDate->format('Y-m-d H:i:s');
                $status = 'scheduled';
            }

            $commConfigId = !empty($input['communication_config_id']) ? (int)$input['communication_config_id'] : null;
            $targetOption = trim($input['target_option'] ?? 'primary');

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
                $stmt = $conn->prepare("UPDATE {$prefix}campaigns SET name = ?, type = ?, template_id = ?, scheduled_at = ?, send_delay = ?, status = ?, communication_config_id = ?, target_option = ? WHERE id = ?");
                $stmt->execute([$name, $type, $templateId, $scheduledAt, $sendDelay, $status, $commConfigId, $targetOption, $id]);
                $savedId = $id;
            } else {
                $stmt = $conn->prepare("INSERT INTO {$prefix}campaigns (name, type, template_id, scheduled_at, send_delay, status, created_by, communication_config_id, target_option) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $type, $templateId, $scheduledAt, $sendDelay, $status, $userId, $commConfigId, $targetOption]);
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

            // Load existing recipients for this campaign to prevent inserting exact duplicates
            $existingStmt = $conn->prepare("SELECT email, phone FROM {$prefix}campaign_recipients WHERE campaign_id = ?");
            $existingStmt->execute([$campaignId]);
            $existingRecipients = $existingStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $existingEmails = [];
            $existingPhones = [];
            foreach ($existingRecipients as $er) {
                $em = trim(strtolower($er['email'] ?? ''));
                $ph = trim($er['phone'] ?? '');
                if ($em) $existingEmails[$em] = true;
                if ($ph) $existingPhones[$ph] = true;
            }

            // Fetch all campaign field definitions
            $cfStmt = $conn->query("SELECT field_key, field_type FROM {$prefix}campaign_fields ORDER BY sort_order ASC");
            $campaignFieldDefs = $cfStmt->fetchAll(PDO::FETCH_ASSOC);
            $allFieldKeys = array_column($campaignFieldDefs, 'field_key');

            // Detect which field_keys map to fixed DB columns (by key name convention)
            $fixedColMap = [
                'first_name'    => 'first_name',
                'last_name'     => 'last_name',
                'email'         => 'email',
                'phone'         => 'phone',
                'mobile'        => 'phone',
                'company_name'  => 'company_name',
                'company'       => 'company_name',
                'designation'   => 'designation',
            ];

            // Bulk insert recipients — store ALL values in extra_data, also populate fixed columns
            $stmt = $conn->prepare("
                INSERT INTO {$prefix}campaign_recipients 
                (campaign_id, first_name, last_name, email, email2, phone, phone2, company_name, designation, extra_data, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");

            $conn->beginTransaction();
            $importedCount = 0;
            foreach ($recipients as $r) {
                // Build extra_data from ALL campaign field values
                $extraData = [];
                foreach ($allFieldKeys as $fKey) {
                    if (isset($r[$fKey]) && $r[$fKey] !== '') {
                        $extraData[$fKey] = $r[$fKey];
                    }
                }

                // Extract fixed column values from extra_data (by field_key convention)
                $fName = '';
                $lName = '';
                $email = '';
                $email2 = '';
                $phone = '';
                $phone2 = '';
                $company = '';
                $designation = '';
                foreach ($r as $k => $v) {
                    $k2 = strtolower(trim($k));
                    switch ($k2) {
                        case 'first_name': $fName = trim($v); break;
                        case 'last_name': $lName = trim($v); break;
                        case 'email': $email = trim($v); break;
                        case 'email2': case 'additional_email': $email2 = trim($v); break;
                        case 'phone': case 'mobile': $phone = trim($v); break;
                        case 'phone2': case 'additional_phone': $phone2 = trim($v); break;
                        case 'company_name': case 'company': $company = trim($v); break;
                        case 'designation': $designation = trim($v); break;
                    }
                }

                $extraDataJson = !empty($extraData) ? json_encode($extraData) : null;

                // Skip if this contact already exists in the campaign by email or phone
                if ($email && isset($existingEmails[strtolower($email)])) continue;
                if ($phone && isset($existingPhones[$phone])) continue;

                // Add to temporary tracker to prevent duplicate entries inside the same upload batch
                if ($email) $existingEmails[strtolower($email)] = true;
                if ($phone) $existingPhones[$phone] = true;

                $stmt->execute([$campaignId, $fName, $lName, $email, $email2, $phone, $phone2, $company, $designation, $extraDataJson]);
                $importedCount++;
            }
            $conn->commit();

            // Set campaign status back to draft, UNLESS it is already scheduled
            $conn->prepare("UPDATE {$prefix}campaigns SET status = IF(status = 'scheduled', 'scheduled', 'draft') WHERE id = ?")->execute([$campaignId]);

            commerce_json_response(['success' => true, 'count' => $importedCount]);


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

            // Resolve Personalization placeholders dynamically from configured fields
            $cfStmt = $conn->query("SELECT field_key, label FROM {$prefix}campaign_fields");
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
            $upStmt = $conn->prepare("UPDATE {$prefix}campaign_recipients SET status = ?, sent_at = NOW(), error_message = ? WHERE id = ?");
            $upStmt->execute([$newStatus, $errorMsg, $recipient['id']]);

            $sentContacts = [];
            if ($campaign['type'] === 'email') {
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

            commerce_json_response([
                'success' => true,
                'finished' => false,
                'recipient' => [
                    'id' => $recipient['id'],
                    'name' => trim($recipient['first_name'] . ' ' . $recipient['last_name']),
                    'contact' => $contactStr,
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

        case 'parse_contacts_file':
            if (empty($_FILES['contacts_file']) || $_FILES['contacts_file']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Valid CSV or Excel file upload required');
            }
            
            $fileTmpPath = $_FILES['contacts_file']['tmp_name'];
            $fileName = $_FILES['contacts_file']['name'];
            
            // Load rows
            $rows = get_rows_from_file($fileTmpPath, $fileName);
            if (empty($rows)) {
                throw new RuntimeException('Empty or invalid file');
            }
            
            $headers = array_shift($rows);
            // Clean BOM
            if (substr($headers[0], 0, 3) == "\xEF\xBB\xBF") {
                $headers[0] = substr($headers[0], 3);
            }
            
            // Get campaign fields definitions
            $cfStmt = $conn->query("SELECT field_key, label FROM {$prefix}campaign_fields");
            $fields = $cfStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $headerMap = [];
            foreach ($headers as $index => $headerName) {
                $headerName = trim(strtolower($headerName));
                foreach ($fields as $f) {
                    if (trim(strtolower($f['label'])) === $headerName || trim(strtolower($f['field_key'])) === $headerName) {
                        $headerMap[$index] = $f['field_key'];
                        break;
                    }
                }
            }
            
            if (empty($headerMap)) {
                throw new RuntimeException('No columns matched the campaign fields. Please check your headers.');
            }
            
            $contacts = [];
            foreach ($rows as $row) {
                $isEmpty = true;
                foreach ($row as $cell) {
                    if (trim($cell) !== '') {
                        $isEmpty = false;
                        break;
                    }
                }
                if ($isEmpty) continue;
                
                $contact = [];
                // Initialize all fields to empty string
                foreach ($fields as $f) {
                    $contact[$f['field_key']] = '';
                }
                
                foreach ($row as $index => $val) {
                    if (isset($headerMap[$index])) {
                        $fKey = $headerMap[$index];
                        $contact[$fKey] = trim($val);
                    }
                }
                $contacts[] = $contact;
            }
            
            commerce_json_response(['success' => true, 'contacts' => $contacts]);

        /* ════════════════════ EMAIL SIGNATURES ════════════════════ */

        case 'list_signatures':
            $stmt = $conn->query("SELECT id, name, content, is_default, created_at FROM {$prefix}email_signatures ORDER BY is_default DESC, created_at DESC");
            $sigs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($sigs as &$s) { $s['is_default'] = (int)$s['is_default']; }
            unset($s);
            commerce_json_response(['success' => true, 'signatures' => $sigs]);

        case 'get_signature':
            $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
            if (!$id) throw new RuntimeException('Signature ID required');
            $stmt = $conn->prepare("SELECT * FROM {$prefix}email_signatures WHERE id = ?");
            $stmt->execute([$id]);
            $sig = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sig) throw new RuntimeException('Signature not found');
            commerce_json_response(['success' => true, 'signature' => $sig]);

        case 'save_signature':
            $id = (int)($input['id'] ?? 0);
            $name = trim($input['name'] ?? '');
            $content = trim($input['content'] ?? '');
            $isDefault = (int)($input['is_default'] ?? 0);
            if (!$name) throw new RuntimeException('Signature name is required');
            if (!$content) throw new RuntimeException('Signature content is required');

            if ($id > 0) {
                $stmt = $conn->prepare("UPDATE {$prefix}email_signatures SET name=?, content=?, is_default=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([$name, $content, $isDefault, $id]);
                $savedId = $id;
            } else {
                $stmt = $conn->prepare("INSERT INTO {$prefix}email_signatures (name, content, is_default, created_by) VALUES (?,?,?,?)");
                $stmt->execute([$name, $content, $isDefault, $userId]);
                $savedId = (int)$conn->lastInsertId();
            }
            // If this is now default, un-default all others
            if ($isDefault) {
                $conn->prepare("UPDATE {$prefix}email_signatures SET is_default=0 WHERE id != ?")->execute([$savedId]);
            }
            commerce_json_response(['success' => true, 'id' => $savedId]);

        case 'delete_signature':
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new RuntimeException('Signature ID required');
            $conn->prepare("DELETE FROM {$prefix}email_signatures WHERE id=?")->execute([$id]);
            commerce_json_response(['success' => true]);

        case 'set_default_signature':
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new RuntimeException('Signature ID required');
            $conn->prepare("UPDATE {$prefix}email_signatures SET is_default=0")->execute();
            $conn->prepare("UPDATE {$prefix}email_signatures SET is_default=1 WHERE id=?")->execute([$id]);
            commerce_json_response(['success' => true]);

        default:
            throw new RuntimeException("Unknown action: $action");

    }
} catch (Throwable $e) {
    commerce_json_response(['success' => false, 'error' => $e->getMessage()], 400);
}

/**
 * Helper to dynamically load rows based on file format.
 */
function get_rows_from_file($filePath, $originalName) {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    
    if ($ext === 'xlsx') {
        return parse_xlsx($filePath);
    }
    
    // Check if it's HTML-based XLS
    $contentStart = file_get_contents($filePath, false, null, 0, 1000);
    if (strpos($contentStart, '<html') !== false || strpos($contentStart, '<table') !== false) {
        return parse_html_xls($filePath);
    }
    
    // Otherwise, assume CSV
    $rows = [];
    if (($handle = fopen($filePath, "r")) !== FALSE) {
        while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $rows[] = $row;
        }
        fclose($handle);
    }
    return $rows;
}

/**
 * Parses native Excel (.xlsx) files without composer dependencies using SimpleXML.
 */
function parse_xlsx($filePath) {
    if (!class_exists('ZipArchive')) {
        throw new Exception("ZipArchive PHP extension is missing. Please save files as CSV or HTML XLS.");
    }
    
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== TRUE) {
        throw new Exception("Unable to open XLSX container.");
    }
    
    // 1. Read shared strings table
    $sharedStrings = [];
    $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedStringsXml) {
        $xml = simplexml_load_string($sharedStringsXml);
        if ($xml && $xml->si) {
            foreach ($xml->si as $si) {
                if (isset($si->t)) {
                    $sharedStrings[] = (string)$si->t;
                } elseif (isset($si->r)) {
                    $str = '';
                    foreach ($si->r as $r) {
                        $str .= (string)$r->t;
                    }
                    $sharedStrings[] = $str;
                } else {
                    $sharedStrings[] = '';
                }
            }
        }
    }
    
    // 2. Read sheet1.xml
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if (!$sheetXml) {
        $zip->close();
        throw new Exception("Missing worksheet data sheet1.xml in XLSX.");
    }
    
    $xml = simplexml_load_string($sheetXml);
    $rows = [];
    
    if ($xml && $xml->sheetData && $xml->sheetData->row) {
        foreach ($xml->sheetData->row as $rNode) {
            $row = [];
            foreach ($rNode->c as $cNode) {
                $ref = (string)$cNode['r']; // e.g. A1, B1
                preg_match('/^[A-Z]+/', $ref, $matches);
                $colLetters = $matches[0] ?? '';
                
                // Convert column letters to 0-based index
                $colIndex = 0;
                $len = strlen($colLetters);
                for ($i = 0; $i < $len; $i++) {
                    $colIndex = $colIndex * 26 + (ord($colLetters[$i]) - 64);
                }
                $colIndex--;
                
                $val = '';
                if (isset($cNode->v)) {
                    $val = (string)$cNode->v;
                    $type = (string)$cNode['t']; // 's' for sharedString references
                    if ($type === 's' && isset($sharedStrings[(int)$val])) {
                        $val = $sharedStrings[(int)$val];
                    }
                }
                $row[$colIndex] = $val;
            }
            
            // Fill empty intermediate cells
            if (!empty($row)) {
                $maxIndex = max(array_keys($row));
                for ($i = 0; $i <= $maxIndex; $i++) {
                    if (!isset($row[$i])) {
                        $row[$i] = '';
                    }
                }
                ksort($row);
            }
            $rows[] = $row;
        }
    }
    
    $zip->close();
    return $rows;
}

/**
 * Parses exported HTML-based XLS table files.
 */
function parse_html_xls($filePath) {
    $html = file_get_contents($filePath);
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    
    $rows = [];
    $trElements = $doc->getElementsByTagName('tr');
    foreach ($trElements as $tr) {
        $row = [];
        $tdElements = $tr->getElementsByTagName('td');
        if ($tdElements->length === 0) {
            $tdElements = $tr->getElementsByTagName('th');
        }
        foreach ($tdElements as $td) {
            $row[] = trim($td->nodeValue);
        }
        if (!empty($row)) {
            $rows[] = $row;
        }
    }
    return $rows;
}
