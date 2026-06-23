<?php
require_once '../auth_check.php';
require_once '../config/database.php';
require_once '../includes/dynamic_modules.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$dbName = $_SESSION['tenant_db'];
$conn = Database::getTenantConn($dbName);
$prefix = $_SESSION['tenant_prefix'];

if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// Auto-heal schema for cc_email if not present
try {
    $conn->exec("ALTER TABLE {$prefix}employee_details ADD COLUMN cc_email VARCHAR(255) DEFAULT NULL AFTER pan_number");
} catch (Throwable $t) {
    // Ignore error if column already exists
}

// Auto-heal schema for system_settings
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS {$prefix}system_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Throwable $t) {
    // Ignore error
}

// Auto-heal schema for salaries (dynamic module support)
try {
    $conn->exec("ALTER TABLE {$prefix}salaries MODIFY user_id INT DEFAULT NULL");
    $conn->exec("ALTER TABLE {$prefix}salaries ADD COLUMN record_id INT DEFAULT NULL AFTER user_id");
    $conn->exec("ALTER TABLE {$prefix}salaries ADD UNIQUE KEY uk_record_month (record_id, salary_month)");
} catch (Throwable $t) {
    // Ignore error
}

try {
    switch ($action) {
        case 'get_config':
            $sourceModuleId = dm_get_system_setting($conn, $prefix, 'payroll_source_module_id', '');
            $emailFieldId = dm_get_system_setting($conn, $prefix, 'payroll_email_field_id', '');
            $filterFieldId = dm_get_system_setting($conn, $prefix, 'payroll_filter_field_id', '');
            $filterValue = dm_get_system_setting($conn, $prefix, 'payroll_filter_value', '');
            $grossFieldId = dm_get_system_setting($conn, $prefix, 'payroll_gross_field_id', '');
            $deductionsFieldId = dm_get_system_setting($conn, $prefix, 'payroll_deductions_field_id', '');
            
            // Get all active dynamic modules
            $stmt = $conn->query("SELECT id, name, slug FROM {$prefix}modules WHERE status = 'active' ORDER BY name ASC");
            $modules = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get fields for the selected module
            $fields = [];
            if ($sourceModuleId) {
                $fields = dm_fetch_module_fields($conn, $prefix, (int)$sourceModuleId);
                // Attach options
                $fieldIds = array_column($fields, 'id');
                if ($fieldIds) {
                    $inClause = implode(',', array_map('intval', $fieldIds));
                    $oStmt = $conn->query("SELECT * FROM {$prefix}module_field_options WHERE field_id IN ($inClause) ORDER BY sort_order ASC");
                    $optionsMap = [];
                    foreach ($oStmt->fetchAll(PDO::FETCH_ASSOC) as $opt) {
                        $optionsMap[(int)$opt['field_id']][] = $opt;
                    }
                    foreach ($fields as &$f) {
                        if (isset($optionsMap[(int)$f['id']])) {
                            $f['options'] = $optionsMap[(int)$f['id']];
                        }
                    }
                    unset($f);
                }
            }

            // Get users for dynamic field filtering
            $stmt = $conn->query("SELECT id, username, first_name, last_name FROM {$prefix}users ORDER BY first_name ASC");
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true, 
                'source_module_id' => $sourceModuleId,
                'email_field_id' => $emailFieldId,
                'filter_field_id' => $filterFieldId,
                'filter_value' => $filterValue,
                'gross_field_id' => $grossFieldId,
                'deductions_field_id' => $deductionsFieldId,
                'modules' => $modules,
                'fields' => $fields,
                'users' => $users
            ]);
            break;

        case 'save_config':
            $sourceModuleId = $_POST['source_module_id'] ?? '';
            $emailFieldId = $_POST['email_field_id'] ?? '';
            $filterFieldId = $_POST['filter_field_id'] ?? '';
            $filterValue = $_POST['filter_value'] ?? '';
            $grossFieldId = $_POST['gross_field_id'] ?? '';
            $deductionsFieldId = $_POST['deductions_field_id'] ?? '';

            if (!$sourceModuleId) throw new Exception("Please select a module.");

            dm_set_system_setting($conn, $prefix, 'payroll_source_module_id', $sourceModuleId);
            dm_set_system_setting($conn, $prefix, 'payroll_email_field_id', $emailFieldId);
            dm_set_system_setting($conn, $prefix, 'payroll_filter_field_id', $filterFieldId);
            dm_set_system_setting($conn, $prefix, 'payroll_filter_value', $filterValue);
            dm_set_system_setting($conn, $prefix, 'payroll_gross_field_id', $grossFieldId);
            dm_set_system_setting($conn, $prefix, 'payroll_deductions_field_id', $deductionsFieldId);

            echo json_encode(['success' => true, 'message' => 'Configuration saved successfully.']);
            break;

        case 'get_module_fields':
            $moduleId = (int)($_GET['module_id'] ?? 0);
            if (!$moduleId) throw new Exception("Module ID required");
            $fields = dm_fetch_module_fields($conn, $prefix, $moduleId);
            // Attach options
            $fieldIds = array_column($fields, 'id');
            if ($fieldIds) {
                $inClause = implode(',', array_map('intval', $fieldIds));
                $oStmt = $conn->query("SELECT * FROM {$prefix}module_field_options WHERE field_id IN ($inClause) ORDER BY sort_order ASC");
                $optionsMap = [];
                foreach ($oStmt->fetchAll(PDO::FETCH_ASSOC) as $opt) {
                    $optionsMap[(int)$opt['field_id']][] = $opt;
                }
                foreach ($fields as &$f) {
                    if (isset($optionsMap[(int)$f['id']])) {
                        $f['options'] = $optionsMap[(int)$f['id']];
                    }
                }
                unset($f);
            }
            echo json_encode(['success' => true, 'fields' => $fields]);
            break;

        case 'get_monthly_payroll':
            $month = $_GET['month'] ?? date('Y-m');
            $sourceModuleId = (int)dm_get_system_setting($conn, $prefix, 'payroll_source_module_id', 0);
            
            if (!$sourceModuleId) {
                echo json_encode(['success' => true, 'records' => [], 'error' => 'No module configured for payroll.']);
                exit;
            }

            $moduleFields = dm_fetch_module_fields($conn, $prefix, $sourceModuleId);
            
            // Fetch dynamic records
            $dynData = dm_fetch_records($conn, $prefix, $sourceModuleId, null, 1000, 0);
            $dynamicRecords = $dynData['records'] ?? [];

            // Get settings for filtering and mapping
            $filterFieldId = dm_get_system_setting($conn, $prefix, 'payroll_filter_field_id', '');
            $filterValue = dm_get_system_setting($conn, $prefix, 'payroll_filter_value', '');
            $grossFieldId = dm_get_system_setting($conn, $prefix, 'payroll_gross_field_id', '');
            $deductionsFieldId = dm_get_system_setting($conn, $prefix, 'payroll_deductions_field_id', '');

            $filterFieldKey = null;
            $grossFieldKey = null;
            $deductionsFieldKey = null;

            foreach ($moduleFields as $f) {
                if ($f['id'] == $filterFieldId) $filterFieldKey = $f['field_key'];
                if ($f['id'] == $grossFieldId) $grossFieldKey = $f['field_key'];
                if ($f['id'] == $deductionsFieldId) $deductionsFieldKey = $f['field_key'];
            }

            // Fetch salaries for this month
            $sStmt = $conn->prepare("SELECT record_id, gross_earnings, total_deductions, net_payable, status, payslip_sent FROM {$prefix}salaries WHERE salary_month = ?");
            $sStmt->execute([$month]);
            $salariesRaw = $sStmt->fetchAll(PDO::FETCH_ASSOC);
            $salaries = [];
            foreach ($salariesRaw as $s) {
                $salaries[$s['record_id']] = $s;
            }

            $combined = [];
            foreach ($dynamicRecords as $rec) {
                // Apply filter
                if ($filterFieldKey && $filterValue !== '') {
                    $recVal = $rec['values'][$filterFieldKey] ?? '';
                    if (strcasecmp((string)$recVal, $filterValue) !== 0) {
                        continue; // Skip this record
                    }
                }

                $rid = $rec['id'];
                $sData = $salaries[$rid] ?? null;
                
                $gross = 0;
                $deductions = 0;
                
                if ($sData) {
                    $gross = $sData['gross_earnings'];
                    $deductions = $sData['total_deductions'];
                } else {
                    // Try to auto-populate from mapped fields
                    if ($grossFieldKey && !empty($rec['values'][$grossFieldKey])) {
                        $gross = (float)$rec['values'][$grossFieldKey];
                    }
                    if ($deductionsFieldKey && !empty($rec['values'][$deductionsFieldKey])) {
                        $deductions = (float)$rec['values'][$deductionsFieldKey];
                    }
                }
                
                $net = $gross - $deductions;
                $status = $sData ? $sData['status'] : 'draft';
                $payslip_sent = $sData ? $sData['payslip_sent'] : 0;

                // We will use the first text field as the "Name" or Title
                $title = "Record #$rid";
                foreach ($moduleFields as $f) {
                    if ($f['field_type'] === 'text') {
                        $key = $f['field_key'];
                        if (!empty($rec['values'][$key])) {
                            $title = $rec['values'][$key];
                            break;
                        }
                    }
                }

                $combined[] = [
                    'record_id' => $rid,
                    'title' => $title,
                    'dynamic_data' => $rec['values'],
                    'gross_earnings' => $gross,
                    'total_deductions' => $deductions,
                    'net_payable' => $net,
                    'status' => $status,
                    'payslip_sent' => $payslip_sent
                ];
            }

            echo json_encode(['success' => true, 'records' => $combined, 'fields' => $moduleFields]);
            break;

        case 'save_salary':
            $recordId = (int)($_POST['record_id'] ?? 0);
            $month = $_POST['month'] ?? '';
            $gross = (float)($_POST['gross_earnings'] ?? 0);
            $deductions = (float)($_POST['total_deductions'] ?? 0);
            $net = (float)($_POST['net_payable'] ?? 0);
            $status = $_POST['status'] ?? 'draft';

            if (!$recordId || !$month) throw new Exception("Record ID and Month required");

            $stmt = $conn->prepare("
                INSERT INTO {$prefix}salaries (record_id, salary_month, gross_earnings, total_deductions, net_payable, status)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                gross_earnings=VALUES(gross_earnings), total_deductions=VALUES(total_deductions), net_payable=VALUES(net_payable), status=VALUES(status)
            ");
            $stmt->execute([$recordId, $month, $gross, $deductions, $net, $status]);

            echo json_encode(['success' => true, 'message' => 'Salary saved.']);
            break;

        case 'process_payslip':
            $recordId = (int)($_POST['record_id'] ?? 0);
            $month = $_POST['month'] ?? '';
            $customCc = $_POST['custom_cc'] ?? '';

            if (!$recordId || !$month) throw new Exception("Record ID and Month required");

            $sourceModuleId = (int)dm_get_system_setting($conn, $prefix, 'payroll_source_module_id', 0);
            $emailFieldId = dm_get_system_setting($conn, $prefix, 'payroll_email_field_id', '');

            if (!$sourceModuleId) throw new Exception("Payroll module not configured.");

            // Mark as paid
            $conn->prepare("UPDATE {$prefix}salaries SET status = 'paid' WHERE record_id = ? AND salary_month = ?")->execute([$recordId, $month]);

            // Get Employee Data
            $recData = dm_fetch_record($conn, $prefix, $recordId);
            if (!$recData) throw new Exception("Employee record not found");
            
            // Fetch Salary Data
            $stmt = $conn->prepare("SELECT gross_earnings, total_deductions, net_payable FROM {$prefix}salaries WHERE record_id = ? AND salary_month = ?");
            $stmt->execute([$recordId, $month]);
            $sal = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sal) throw new Exception("Salary record not found for this month");

            // Find Email Address
            $toEmail = '';
            if ($emailFieldId) {
                // Determine field key
                $fStmt = $conn->prepare("SELECT field_key FROM {$prefix}module_fields WHERE id = ?");
                $fStmt->execute([$emailFieldId]);
                $fKey = $fStmt->fetchColumn();
                if ($fKey && !empty($recData['values'][$fKey])) {
                    $toEmail = $recData['values'][$fKey];
                }
            }

            // Get Email Template
            $tStmt = $conn->query("SELECT subject, body FROM {$prefix}email_templates WHERE module = 'payslip'");
            $template = $tStmt->fetch(PDO::FETCH_ASSOC);
            if (!$template) throw new Exception("Payslip email template not found");

            // Build Replacements
            $replacements = [
                '{{salary_month}}' => date('F Y', strtotime($month . '-01')),
                '{{gross_earnings}}' => number_format($sal['gross_earnings'], 2),
                '{{total_deductions}}' => number_format($sal['total_deductions'], 2),
                '{{net_payable}}' => number_format($sal['net_payable'], 2),
            ];

            // Add all dynamic fields
            foreach ($recData['values'] as $key => $val) {
                $replacements['{{' . $key . '}}'] = is_array($val) ? implode(', ', $val) : (string)$val;
            }

            $subject = str_replace(array_keys($replacements), array_values($replacements), $template['subject']);
            $body = str_replace(array_keys($replacements), array_values($replacements), $template['body']);

            // Send Email
            $smtpHost = dm_get_system_setting($conn, $prefix, 'smtp_host', '');
            $smtpPort = (int)dm_get_system_setting($conn, $prefix, 'smtp_port', '587');
            $smtpUser = dm_get_system_setting($conn, $prefix, 'smtp_user', '');
            $smtpPass = dm_get_system_setting($conn, $prefix, 'smtp_pass', '');
            $smtpFromEmail = dm_get_system_setting($conn, $prefix, 'smtp_from_email', '');
            $smtpFromName = dm_get_system_setting($conn, $prefix, 'smtp_from_name', '');
            $smtpEnc = dm_get_system_setting($conn, $prefix, 'smtp_encryption', 'none');

            if (!$smtpHost || !$smtpFromEmail) {
                throw new Exception("SMTP not configured in Settings.");
            }

            if (!$toEmail) {
                throw new Exception("Employee has no email address configured in their dynamic record.");
            }

            $sent = dm_send_smtp_email(
                $smtpHost, $smtpPort, $smtpUser, $smtpPass,
                $smtpFromEmail, $smtpFromName,
                $toEmail, $subject, $body, $smtpEnc, $customCc
            );

            if ($sent) {
                $conn->prepare("UPDATE {$prefix}salaries SET payslip_sent = 1 WHERE record_id = ? AND salary_month = ?")->execute([$recordId, $month]);
                echo json_encode(['success' => true, 'message' => 'Payslip sent successfully.']);
            } else {
                throw new Exception("Failed to send email via SMTP.");
            }
            break;

        case 'get_template':
            $stmt = $conn->query("SELECT subject, body FROM {$prefix}email_templates WHERE module = 'payslip'");
            $template = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Also fetch available fields for the template helper
            $sourceModuleId = (int)dm_get_system_setting($conn, $prefix, 'payroll_source_module_id', 0);
            $fields = [];
            if ($sourceModuleId) {
                $fields = dm_fetch_module_fields($conn, $prefix, $sourceModuleId);
            }

            echo json_encode(['success' => true, 'template' => $template, 'fields' => $fields]);
            break;

        case 'save_template':
            $subject = $_POST['subject'] ?? '';
            $body = $_POST['body'] ?? '';

            if (!$subject || !$body) throw new Exception("Subject and body required");

            $stmt = $conn->prepare("
                UPDATE {$prefix}email_templates SET subject = ?, body = ? WHERE module = 'payslip'
            ");
            $stmt->execute([$subject, $body]);

            echo json_encode(['success' => true, 'message' => 'Template saved.']);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
