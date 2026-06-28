<?php
/**
 * api/module_export.php
 * 
 * Generates CSV or Excel (.xls HTML table) download, or a CSV Import Template.
 */

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dynamic_modules.php';
require_once __DIR__ . '/../includes/commerce.php';

try {
    $context = commerce_get_tenant_context();
    $conn = $context['conn'];
    $prefix = $context['prefix'];
    $userId = $context['user_id'];
} catch (Throwable $e) {
    http_response_code(401);
    die("Unauthorized access.");
}

$moduleId = (int)($_GET['module_id'] ?? 0);
if (!$moduleId) {
    http_response_code(400);
    die("Module ID required.");
}

$module = dm_fetch_module_full($conn, $prefix, $moduleId);
if (!$module) {
    http_response_code(404);
    die("Module not found.");
}

// Check if downloading a template
$isTemplate = !empty($_GET['template']);

// Fetch fields visible in list (or all fields excluding system fields for template)
if ($isTemplate) {
    $fStmt = $conn->prepare("
        SELECT id, field_key, label, field_type 
        FROM {$prefix}module_fields 
        WHERE module_id = ? AND field_type NOT LIKE 'sys_%'
        ORDER BY sort_order ASC
    ");
} else {
    $fStmt = $conn->prepare("
        SELECT id, field_key, label, field_type 
        FROM {$prefix}module_fields 
        WHERE module_id = ? AND is_list_visible = 1 
        ORDER BY sort_order ASC
    ");
}
$fStmt->execute([$moduleId]);
$fields = $fStmt->fetchAll(PDO::FETCH_ASSOC);

if ($isTemplate) {
    // Generate empty CSV template containing just the headers
    $filename = dm_slugify($module['name']) . '_import_template';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    
    $out = fopen('php://output', 'w');
    // Add UTF-8 BOM
    fwrite($out, "\xEF\xBB\xBF");
    
    $headers = [];
    foreach ($fields as $f) {
        $headers[] = $f['label'];
    }
    
    fputcsv($out, $headers);
    fclose($out);
    exit;
}

// Otherwise, fetch records to export
$search = $_GET['search'] ?? null;
$sortBy = $_GET['sort_by'] ?? null;
$sortOrder = $_GET['sort_order'] ?? 'DESC';

$filterRules = null;
$filterRulesInput = $_GET['filter_rules'] ?? null;
if ($filterRulesInput) {
    $filterRules = json_decode($filterRulesInput, true);
} else {
    $filterId = (int)($_GET['filter_id'] ?? 0);
    if ($filterId) {
        $fStmt = $conn->prepare("SELECT filter_rules FROM {$prefix}module_saved_filters WHERE id = ? AND user_id = ?");
        $fStmt->execute([$filterId, $userId]);
        $rulesJson = $fStmt->fetchColumn();
        if ($rulesJson) {
            $filterRules = json_decode($rulesJson, true);
        }
    }
}

// Fetch all records without paging (very high limit)
$data = dm_fetch_records($conn, $prefix, $moduleId, $search, 100000, 0, $filterRules, $sortBy, $sortOrder);
$records = $data['records'];

// Prepare users list for mapping
$usersStmt = $conn->query("SELECT id, username, first_name, last_name FROM {$prefix}users");
$usersList = [];
foreach ($usersStmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
    $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
    $usersList[$u['id']] = $name ?: $u['username'];
}

$format = $_GET['format'] ?? 'csv';
$filename = dm_slugify($module['name']) . '_export_' . date('Ymd_His');

if ($format === 'excel') {
    // Export as XLS via HTML table
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"" . $filename . ".xls\"");
    
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta http-equiv="content-type" content="text/html; charset=UTF-8"></head>';
    echo '<body>';
    echo '<table border="1">';
    echo '<thead><tr>';
    foreach ($fields as $f) {
        echo '<th>' . htmlspecialchars($f['label']) . '</th>';
    }
    echo '</tr></thead>';
    echo '<tbody>';
    foreach ($records as $rec) {
        echo '<tr>';
        foreach ($fields as $f) {
            $val = $rec['values'][(int)$f['id']] ?? '';
            // Format check
            if ($f['field_type'] === 'checkbox') {
                $val = $val ? 'Yes' : 'No';
            } elseif ($f['field_type'] === 'multi_picker') {
                $decoded = json_decode($val, true);
                $val = is_array($decoded) ? implode(', ', $decoded) : $val;
            } elseif ($f['field_type'] === 'duration' && $val !== '') {
                $seconds = (int)$val;
                if ($seconds < 60) $val = $seconds . ' sec';
                elseif ($seconds < 3600) $val = floor($seconds / 60) . ' min ' . ($seconds % 60) . ' sec';
                else $val = floor($seconds / 3600) . ' hr ' . floor(($seconds % 3600) / 60) . ' min';
            } elseif ($f['field_type'] === 'sys_created_by' || $f['field_type'] === 'sys_updated_by') {
                $val = $usersList[(int)$val] ?? $val;
            }
            echo '<td>' . htmlspecialchars((string)$val) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
    echo '</body>';
    echo '</html>';
    exit;
} else {
    // Export as CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    
    $out = fopen('php://output', 'w');
    // Add UTF-8 BOM
    fwrite($out, "\xEF\xBB\xBF");
    
    $headers = [];
    foreach ($fields as $f) {
        $headers[] = $f['label'];
    }
    fputcsv($out, $headers);
    
    foreach ($records as $rec) {
        $row = [];
        foreach ($fields as $f) {
            $val = $rec['values'][(int)$f['id']] ?? '';
            if ($f['field_type'] === 'checkbox') {
                $val = $val ? 'Yes' : 'No';
            } elseif ($f['field_type'] === 'multi_picker') {
                $decoded = json_decode($val, true);
                $val = is_array($decoded) ? implode(', ', $decoded) : $val;
            } elseif ($f['field_type'] === 'duration' && $val !== '') {
                $seconds = (int)$val;
                if ($seconds < 60) $val = $seconds . ' sec';
                elseif ($seconds < 3600) $val = floor($seconds / 60) . ' min ' . ($seconds % 60) . ' sec';
                else $val = floor($seconds / 3600) . ' hr ' . floor(($seconds % 3600) / 60) . ' min';
            } elseif ($f['field_type'] === 'sys_created_by' || $f['field_type'] === 'sys_updated_by') {
                $val = $usersList[(int)$val] ?? $val;
            }
            $row[] = $val;
        }
        fputcsv($out, $row);
    }
    
    fclose($out);
    exit;
}
