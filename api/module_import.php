<?php
/**
 * api/module_import.php
 * 
 * Imports records from CSV or Excel (.xls, .xlsx) upload.
 */

@set_time_limit(600);
@ini_set('memory_limit', '512M');

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

$fields = [];
$pickerDisplayField = [];
foreach ($module['blocks'] as $block) {
    foreach ($block['fields'] as $f) {
        $fields[] = $f;
        if ($f['field_type'] === 'api_call_picker' && !empty($f['config']['linked_module_id'])) {
            $linkedModId = (int)$f['config']['linked_module_id'];
            $displayFId = (int)($f['config']['display_field_id'] ?? 0);
            if (!$displayFId) {
                $dfStmt = $conn->prepare("SELECT id FROM {$prefix}module_fields WHERE module_id = ? ORDER BY sort_order ASC, id ASC LIMIT 1");
                $dfStmt->execute([$linkedModId]);
                $displayFId = (int)$dfStmt->fetchColumn();
            }
            $pickerDisplayField[$f['id']] = [
                'linked_module_id' => $linkedModId,
                'display_field_id' => $displayFId,
            ];
        }
    }
}

if (empty($fields)) {
    commerce_json_response(['success' => false, 'error' => 'No fields defined for this module']);
}

$pickerResolveCache = [];

try {
    $rows = get_rows_from_file($fileTmpPath, $fileName);
} catch (Throwable $e) {
    commerce_json_response(['success' => false, 'error' => 'File parse error: ' . $e->getMessage()]);
}

if (empty($rows)) {
    commerce_json_response(['success' => false, 'error' => 'File is empty or contains no readable rows']);
}

// Extract headers (first row)
$headers = array_shift($rows);
if (empty($headers)) {
    commerce_json_response(['success' => false, 'error' => 'File headers missing']);
}

// Clean UTF-8 BOM if present on the first header
if (substr($headers[0], 0, 3) == "\xEF\xBB\xBF") {
    $headers[0] = substr($headers[0], 3);
}

// Map header names to field IDs and types
$headerMap = [];
$fieldTypes = [];
foreach ($headers as $index => $headerName) {
    $normalizedHeader = normalize_import_header_name($headerName);
    if ($normalizedHeader === '') continue;

    $matchedKey = null;
    // Pass 1: Exact label match
    foreach ($fields as $f) {
        $normalizedLabel = normalize_import_header_name($f['label'] ?? '');
        if ($normalizedHeader === $normalizedLabel) {
            $headerMap[$index] = $f['id'];
            $fieldTypes[$f['id']] = $f['field_type'];
            $matchedKey = $f['id'];
            break;
        }
    }
    // Pass 2: Field key match
    if (!$matchedKey) {
        foreach ($fields as $f) {
            $normalizedKey = normalize_import_header_name($f['field_key'] ?? '');
            if ($normalizedHeader === $normalizedKey) {
                $headerMap[$index] = $f['id'];
                $fieldTypes[$f['id']] = $f['field_type'];
                break;
            }
        }
    }
}

if (empty($headerMap)) {
    commerce_json_response([
        'success' => false,
        'error' => 'No columns matched the module field labels or field keys. Please download the template to check headers.'
    ]);
}


// Fetch unique field IDs for this module
$uniqueFieldsStmt = $conn->prepare("SELECT id FROM {$prefix}module_fields WHERE module_id = ? AND is_unique = 1");
$uniqueFieldsStmt->execute([$moduleId]);
$uniqueFieldIds = $uniqueFieldsStmt->fetchAll(PDO::FETCH_COLUMN);

$importedUniqueCache = []; // Cache to track unique values within the current import batch

$conn->beginTransaction();
try {
    $recordsImported = 0;
    $recordsUpdated = 0;
    
    $upsertStmt = $conn->prepare("
        INSERT INTO {$prefix}module_record_values (record_id, field_id, value) 
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE value = VALUES(value)
    ");

    foreach ($rows as $row) {
        // Check if row is empty
        $isEmpty = true;
        foreach ($row as $cell) {
            if (trim((string)$cell) !== '') {
                $isEmpty = false;
                break;
            }
        }
        if ($isEmpty) continue;

        // Clean values for all mapped fields in this row first
        $rowFieldValues = [];
        foreach ($row as $index => $val) {
            if (isset($headerMap[$index])) {
                $fieldId = $headerMap[$index];
                $fieldType = $fieldTypes[$fieldId];
                $valClean = trim((string)$val);

                if ($fieldType === 'checkbox') {
                    $valClean = in_array(strtolower($valClean), ['yes', '1', 'true', 'checked', 'on']) ? '1' : '0';
                } elseif (in_array($fieldType, ['date', 'datetime', 'time'])) {
                    $valClean = normalize_import_date_value($valClean, $fieldType);
                } elseif ($fieldType === 'api_call_picker' && $valClean !== '' && !ctype_digit($valClean)) {
                    // Non-numeric: try to resolve the text name to a real record ID
                    $pInfo = $pickerDisplayField[$fieldId] ?? null;
                    if ($pInfo && $pInfo['display_field_id']) {
                        $cacheKey = $pInfo['linked_module_id'] . '|' . strtolower($valClean);
                        if (!array_key_exists($cacheKey, $pickerResolveCache)) {
                            $rStmt = $conn->prepare("
                                SELECT mrv.record_id
                                FROM {$prefix}module_record_values mrv
                                JOIN {$prefix}module_records mr ON mr.id = mrv.record_id
                                WHERE mrv.field_id = ? AND LOWER(mrv.value) = LOWER(?) AND mr.module_id = ?
                                LIMIT 1
                            ");
                            $rStmt->execute([$pInfo['display_field_id'], $valClean, $pInfo['linked_module_id']]);
                            $resolvedId = $rStmt->fetchColumn();
                            $pickerResolveCache[$cacheKey] = $resolvedId ? (string)$resolvedId : '';
                        }
                        $valClean = $pickerResolveCache[$cacheKey];
                    } else {
                        $valClean = '';
                    }
                }

                $rowFieldValues[$fieldId] = $valClean;
            }
        }

        // Check if a record matching any unique field already exists in DB or current import batch
        $existingRecordId = null;
        if (!empty($uniqueFieldIds)) {
            foreach ($uniqueFieldIds as $ufid) {
                if (isset($rowFieldValues[$ufid]) && $rowFieldValues[$ufid] !== '') {
                    $checkVal = strtolower(trim($rowFieldValues[$ufid]));
                    
                    // Check batch cache
                    if (isset($importedUniqueCache[$ufid][$checkVal])) {
                        $existingRecordId = $importedUniqueCache[$ufid][$checkVal];
                        break;
                    }

                    // Check database
                    $findStmt = $conn->prepare("
                        SELECT mrv.record_id 
                        FROM {$prefix}module_record_values mrv
                        JOIN {$prefix}module_records mr ON mr.id = mrv.record_id
                        WHERE mr.module_id = ? AND mrv.field_id = ? AND LOWER(TRIM(mrv.value)) = ?
                        LIMIT 1
                    ");
                    $findStmt->execute([$moduleId, $ufid, $checkVal]);
                    $foundId = $findStmt->fetchColumn();
                    if ($foundId) {
                        $existingRecordId = (int)$foundId;
                        break;
                    }
                }
            }
        }

        if ($existingRecordId) {
            $recordId = $existingRecordId;
            $conn->prepare("UPDATE {$prefix}module_records SET updated_at = NOW(), updated_by = ? WHERE id = ?")->execute([$userId, $recordId]);
            $recordsUpdated++;
        } else {
            $conn->prepare("INSERT INTO {$prefix}module_records (module_id, created_by) VALUES (?, ?)")->execute([$moduleId, $userId]);
            $recordId = (int)$conn->lastInsertId();
            $recordsImported++;
        }

        // Cache unique values for this record
        if (!empty($uniqueFieldIds)) {
            foreach ($uniqueFieldIds as $ufid) {
                if (isset($rowFieldValues[$ufid]) && $rowFieldValues[$ufid] !== '') {
                    $checkVal = strtolower(trim($rowFieldValues[$ufid]));
                    $importedUniqueCache[$ufid][$checkVal] = $recordId;
                }
            }
        }

        // Execute value upserts
        foreach ($rowFieldValues as $fieldId => $valClean) {
            $upsertStmt->execute([$recordId, $fieldId, $valClean]);
        }
    }
    $conn->commit();
    $totalProcessed = $recordsImported + $recordsUpdated;
    $msg = "Successfully imported $totalProcessed records";
    if ($recordsUpdated > 0) {
        $msg .= " ($recordsImported new records created, $recordsUpdated duplicate records updated)";
    }
    $msg .= "!";
    commerce_json_response(['success' => true, 'message' => $msg]);
} catch (Throwable $e) {
    $conn->rollBack();
    commerce_json_response(['success' => false, 'error' => 'Database import error: ' . $e->getMessage()]);
}


/**
 * Helper to normalize date/time values from Excel (including Excel serial numbers like 46204 or strings like 22-Aug-24).
 */
function normalize_import_date_value(string $val, string $fieldType = 'date'): string {
    $val = trim($val);
    if ($val === '') return '';

    // Case 1: Excel Serial Date Number (numeric, e.g. 45526, 46204)
    if (is_numeric($val) && (float)$val > 1000 && (float)$val < 2958465) {
        $excelSerial = (float)$val;
        $unixTimestamp = round(($excelSerial - 25569) * 86400);
        if ($fieldType === 'datetime') {
            return date('Y-m-d H:i:s', $unixTimestamp);
        } elseif ($fieldType === 'time') {
            return date('H:i:s', $unixTimestamp);
        } else {
            return date('Y-m-d', $unixTimestamp);
        }
    }

    // Case 2: String date (e.g. 22-Aug-24, 22-Aug-2024, 22/08/2024, 22.08.2024, 2024-08-22)
    $cleanDate = $val;
    if (preg_match('/^(\d{1,2})[\/\.-](\d{1,2})[\/\.-](\d{2,4})$/', $cleanDate, $m)) {
        $d1 = (int)$m[1];
        $d2 = (int)$m[2];
        $yr = (int)$m[3];
        if ($yr < 100) $yr += ($yr > 50 ? 1900 : 2000);
        
        if ($d1 > 12 && $d2 <= 12) {
            // d1 is Day, d2 is Month
            $cleanDate = sprintf('%04d-%02d-%02d', $yr, $d2, $d1);
        } elseif ($d2 > 12 && $d1 <= 12) {
            // d1 is Month, d2 is Day
            $cleanDate = sprintf('%04d-%02d-%02d', $yr, $d1, $d2);
        }
    }

    $ts = strtotime($cleanDate);
    if ($ts !== false && $ts > 0) {
        if ($fieldType === 'datetime') {
            return date('Y-m-d H:i:s', $ts);
        } elseif ($fieldType === 'time') {
            return date('H:i:s', $ts);
        } else {
            return date('Y-m-d', $ts);
        }
    }

    return $val;
}

function normalize_import_header_name(string $value): string {
    $value = trim($value);
    $value = strtolower($value);
    // Remove all non-alphanumeric characters and whitespaces to match spaces-insensitively
    $value = preg_replace('/[^a-z0-9]/', '', $value);
    return $value;
}

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

      // Read relationships for hyperlinks
      $hyperlinks = [];
      $relsXml = $zip->getFromName('xl/worksheets/_rels/sheet1.xml.rels');
      if ($relsXml) {
          $rels = [];
          $dom = new DOMDocument();
          @$dom->loadXML($relsXml);
          foreach ($dom->getElementsByTagName('Relationship') as $rel) {
              $id = $rel->getAttribute('Id');
              $target = $rel->getAttribute('Target');
              $rels[$id] = $target;
          }

          // Parse hyperlinks block in sheet1.xml
          $domSheet = new DOMDocument();
          @$domSheet->loadXML($sheetXml);
          foreach ($domSheet->getElementsByTagName('hyperlink') as $hl) {
              $ref = $hl->getAttribute('ref');
              $rId = $hl->getAttribute('r:id');
              if (!$rId) {
                  foreach ($hl->attributes as $attr) {
                      if ($attr->localName === 'id') {
                          $rId = $attr->nodeValue;
                          break;
                      }
                  }
              }
              if ($ref && $rId && isset($rels[$rId])) {
                  $hyperlinks[$ref] = $rels[$rId];
              }
          }
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
                  if (isset($hyperlinks[$ref]) && preg_match('/^(https?|mailto):/i', trim($hyperlinks[$ref]))) {
                      $val = trim($hyperlinks[$ref]);
                      if (stripos($val, 'mailto:') === 0) {
                          $val = substr($val, 7);
                      }
                  } elseif (isset($cNode->v)) {
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
