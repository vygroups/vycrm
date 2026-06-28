<?php
/**
 * api/module_import.php
 * 
 * Imports records from CSV or Excel (.xls, .xlsx) upload.
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dynamic_modules.php';
require_once __DIR__ . '/../includes/commerce.php';

try {
    $context = commerce_get_tenant_context();
    $conn = $context['conn'];
    $prefix = $context['prefix'];
    $userId = $context['user_id'];
} catch (Throwable $e) {
    commerce_json_response(['success' => false, 'error' => 'Unauthorized']);
}

$moduleId = (int)($_POST['module_id'] ?? 0);
if (!$moduleId) {
    commerce_json_response(['success' => false, 'error' => 'Module ID required']);
}

$module = dm_fetch_module_full($conn, $prefix, $moduleId);
if (!$module) {
    commerce_json_response(['success' => false, 'error' => 'Module not found']);
}

if (empty($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
    commerce_json_response(['success' => false, 'error' => 'Valid CSV or Excel file upload required']);
}

$fileTmpPath = $_FILES['import_file']['tmp_name'];
$fileName = $_FILES['import_file']['name'];

// Fetch all dynamic fields of the module
$fStmt = $conn->prepare("
    SELECT id, field_key, label, field_type 
    FROM {$prefix}module_fields 
    WHERE module_id = ? 
    ORDER BY sort_order ASC
");
$fStmt->execute([$moduleId]);
$fields = $fStmt->fetchAll(PDO::FETCH_ASSOC);

try {
    $rows = get_rows_from_file($fileTmpPath, $fileName);
} catch (Throwable $e) {
    commerce_json_response(['success' => false, 'error' => 'Failed to parse file: ' . $e->getMessage()]);
}

if (empty($rows)) {
    commerce_json_response(['success' => false, 'error' => 'No records found in the uploaded file.']);
}

// First row is headers
$headers = array_shift($rows);

// Clean UTF-8 BOM if present on the first header
if (substr($headers[0], 0, 3) == "\xEF\xBB\xBF") {
    $headers[0] = substr($headers[0], 3);
}

// Map header names to field IDs and types
$headerMap = [];
$fieldTypes = [];
foreach ($headers as $index => $headerName) {
    $headerName = trim(strtolower($headerName));
    foreach ($fields as $f) {
        if (trim(strtolower($f['label'])) === $headerName) {
            $headerMap[$index] = $f['id'];
            $fieldTypes[$f['id']] = $f['field_type'];
            break;
        }
    }
}

if (empty($headerMap)) {
    commerce_json_response([
        'success' => false, 
        'error' => 'No columns matched the module field labels. Please download the template to check headers.'
    ]);
}

$conn->beginTransaction();
try {
    $recordsImported = 0;
    foreach ($rows as $row) {
        // Check if row is empty
        $isEmpty = true;
        foreach ($row as $cell) {
            if (trim($cell) !== '') {
                $isEmpty = false;
                break;
            }
        }
        if ($isEmpty) continue;

        // Create record
        $conn->prepare("INSERT INTO {$prefix}module_records (module_id, created_by) VALUES (?, ?)")->execute([$moduleId, $userId]);
        $recordId = (int)$conn->lastInsertId();
        
        // Insert values
        $upsertStmt = $conn->prepare("INSERT INTO {$prefix}module_record_values (record_id, field_id, value) VALUES (?, ?, ?)");
        foreach ($row as $index => $val) {
            if (isset($headerMap[$index])) {
                $fieldId = $headerMap[$index];
                $fieldType = $fieldTypes[$fieldId];
                $valClean = trim($val);
                if ($fieldType === 'checkbox') {
                    $valClean = in_array(strtolower($valClean), ['yes', '1', 'true', 'checked', 'on']) ? '1' : '0';
                }
                $upsertStmt->execute([$recordId, $fieldId, $valClean]);
            }
        }
        $recordsImported++;
    }
    $conn->commit();
    commerce_json_response(['success' => true, 'message' => "Successfully imported $recordsImported records!"]);
} catch (Throwable $e) {
    $conn->rollBack();
    commerce_json_response(['success' => false, 'error' => 'Database import error: ' . $e->getMessage()]);
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
    // Disable libxml warnings for broken markup
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
