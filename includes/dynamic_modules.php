<?php
/**
 * includes/dynamic_modules.php
 * 
 * Dynamic Module System — Schema, helpers, and rendering engine.
 * Provides the EAV backbone for admin-defined modules with blocks, fields,
 * dependent/conditional logic, and editable dropdown options.
 */

require_once __DIR__ . '/../config/database.php';

/* ─────────────────────────── FIELD TYPE REGISTRY ─────────────────────────── */

function dm_field_types(): array
{
    return [
        'text' => ['label' => 'Text Field', 'icon' => 'fa-solid fa-font'],
        'email' => ['label' => 'Email Field', 'icon' => 'fa-solid fa-envelope'],
        'phone' => ['label' => 'Phone Number', 'icon' => 'fa-solid fa-phone'],
        'number' => ['label' => 'Number Field', 'icon' => 'fa-solid fa-hashtag'],
        'currency' => ['label' => 'Currency Field', 'icon' => 'fa-solid fa-indian-rupee-sign'],
        'url' => ['label' => 'URL Field', 'icon' => 'fa-solid fa-link'],
        'textarea' => ['label' => 'Textarea', 'icon' => 'fa-solid fa-align-left'],
        'checkbox' => ['label' => 'Checkbox', 'icon' => 'fa-solid fa-square-check'],
        'dropdown' => ['label' => 'Dropdown', 'icon' => 'fa-solid fa-caret-down'],
        'radio_group' => ['label' => 'Radio Group', 'icon' => 'fa-solid fa-circle-dot'],
        'multi_picker' => ['label' => 'Multi Picker', 'icon' => 'fa-solid fa-list-check'],
        'date' => ['label' => 'Date Picker', 'icon' => 'fa-solid fa-calendar'],
        'datetime' => ['label' => 'Date & Time Picker', 'icon' => 'fa-solid fa-calendar-day'],
        'time' => ['label' => 'Time Picker', 'icon' => 'fa-solid fa-clock'],
        'duration' => ['label' => 'Duration Picker', 'icon' => 'fa-solid fa-stopwatch'],
        'name' => ['label' => 'Name Field', 'icon' => 'fa-solid fa-id-card'],
        'country' => ['label' => 'Country Picker', 'icon' => 'fa-solid fa-globe'],
        'state' => ['label' => 'State Picker', 'icon' => 'fa-solid fa-map-location-dot'],
        'district' => ['label' => 'District Picker', 'icon' => 'fa-solid fa-location-crosshairs'],
        'assigned_to' => ['label' => 'Assigned To', 'icon' => 'fa-solid fa-user-check'],
        'api_call_picker' => ['label' => 'API Call Picker', 'icon' => 'fa-solid fa-plug'],
        'attachment' => ['label' => 'Attachment Picker', 'icon' => 'fa-solid fa-paperclip'],
        'map_picker' => ['label' => 'Map Picker', 'icon' => 'fa-solid fa-map-pin'],
        'address' => ['label' => 'Address Field', 'icon' => 'fa-solid fa-location-dot'],
        'sys_created_by' => ['label' => 'Created By', 'icon' => 'fa-solid fa-user-plus'],
        'sys_created_at' => ['label' => 'Created On', 'icon' => 'fa-solid fa-calendar-plus'],
        'sys_updated_by' => ['label' => 'Updated By', 'icon' => 'fa-solid fa-user-pen'],
        'sys_updated_at' => ['label' => 'Updated On', 'icon' => 'fa-solid fa-calendar-check'],
    ];
}

/* ──────────────────────────── TABLE CREATION ──────────────────────────── */

function dm_ensure_tables(PDO $conn, string $p): void
{
    require_once __DIR__ . '/commerce.php';
    commerce_ensure_tables($conn, $p);
}

/* ──────────────────────────── HELPER FUNCTIONS ──────────────────────────── */
function dm_get_system_setting(PDO $conn, string $p, string $key, $default = null)
{
    $stmt = $conn->prepare("SELECT setting_value FROM {$p}system_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false ? $val : $default;
}

function dm_set_system_setting(PDO $conn, string $p, string $key, $value)
{
    $stmt = $conn->prepare("INSERT INTO {$p}system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    return $stmt->execute([$key, $value, $value]);
}

/**
 * Generate a URL-safe slug from a human-readable name.
 */
function dm_slugify(string $text): string
{
    $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $text), '_'));
    return $slug ?: 'module_' . time();
}

/**
 * Generate a field_key from a label (lowercase, underscored, unique per module).
 */
function dm_field_key(string $label): string
{
    return strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $label), '_'));
}

/**
 * Fetch all active modules for sidebar rendering.
 */
function dm_fetch_active_modules(PDO $conn, string $p): array
{
    $stmt = $conn->query("
        SELECT id, name, slug, icon, description
        FROM {$p}modules
        WHERE status = 'active'
        ORDER BY sort_order ASC, name ASC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetch all modules (including inactive) for admin management.
 */
function dm_fetch_all_modules(PDO $conn, string $p): array
{
    $stmt = $conn->query("
        SELECT m.*, 
               (SELECT COUNT(*) FROM {$p}module_blocks WHERE module_id = m.id) AS block_count,
               (SELECT COUNT(*) FROM {$p}module_fields WHERE module_id = m.id) AS field_count,
               (SELECT COUNT(*) FROM {$p}module_records WHERE module_id = m.id) AS record_count
        FROM {$p}modules m
        ORDER BY m.sort_order ASC, m.name ASC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetch a single module with its blocks and fields (fully hydrated).
 */
function dm_fetch_module_full(PDO $conn, string $p, int $moduleId): ?array
{
    $mStmt = $conn->prepare("SELECT * FROM {$p}modules WHERE id = ?");
    $mStmt->execute([$moduleId]);
    $module = $mStmt->fetch(PDO::FETCH_ASSOC);
    if (!$module)
        return null;

    // Blocks
    $bStmt = $conn->prepare("SELECT * FROM {$p}module_blocks WHERE module_id = ? ORDER BY sort_order ASC");
    $bStmt->execute([$moduleId]);
    $blocks = $bStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fields grouped by block
    $fStmt = $conn->prepare("SELECT * FROM {$p}module_fields WHERE module_id = ? ORDER BY sort_order ASC");
    $fStmt->execute([$moduleId]);
    $allFields = $fStmt->fetchAll(PDO::FETCH_ASSOC);

    // Options for dropdown / multi_picker fields
    $fieldIds = array_column($allFields, 'id');
    $options = [];
    if ($fieldIds) {
        $inClause = implode(',', array_map('intval', $fieldIds));
        $oStmt = $conn->query("SELECT * FROM {$p}module_field_options WHERE field_id IN ($inClause) ORDER BY sort_order ASC");
        foreach ($oStmt->fetchAll(PDO::FETCH_ASSOC) as $opt) {
            $options[(int) $opt['field_id']][] = $opt;
        }
    }

    // Rules
    $rules = [];
    if ($fieldIds) {
        $inClause = implode(',', array_map('intval', $fieldIds));
        $rStmt = $conn->query("SELECT * FROM {$p}module_field_rules WHERE field_id IN ($inClause)");
        foreach ($rStmt->fetchAll(PDO::FETCH_ASSOC) as $rule) {
            $rules[(int) $rule['field_id']][] = $rule;
        }
    }

    // Attach options & rules to fields, group by block
    $blockFields = [];
    foreach ($allFields as &$f) {
        $fid = (int) $f['id'];
        $f['options'] = $options[$fid] ?? [];
        $f['rules'] = $rules[$fid] ?? [];
        if ($f['config']) {
            $f['config'] = json_decode($f['config'], true);
        }
        $blockFields[(int) $f['block_id']][] = $f;
    }
    unset($f);

    foreach ($blocks as &$b) {
        $b['fields'] = $blockFields[(int) $b['id']] ?? [];
    }
    unset($b);

    $module['blocks'] = $blocks;
    return $module;
}

/**
 * Fetch records for a module with values pivoted.
 */
function dm_fetch_records(PDO $conn, string $p, int $moduleId, ?string $search = null, int $limit = 50, int $offset = 0, ?array $filterRules = null): array
{
    // Get list-visible fields
    $fStmt = $conn->prepare("
        SELECT id, field_key, label, field_type 
        FROM {$p}module_fields 
        WHERE module_id = ? AND is_list_visible = 1 
        ORDER BY sort_order ASC
    ");
    $fStmt->execute([$moduleId]);
    $fields = $fStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get record IDs with Visibility Filtering
    $mStmt = $conn->prepare("SELECT * FROM {$p}modules WHERE id = ?");
    $mStmt->execute([$moduleId]);
    $module = $mStmt->fetch(PDO::FETCH_ASSOC);
    if (!$module) return ['fields' => [], 'records' => [], 'total' => 0];
    $rule = $module['visibility_rule'] ?: 'all';

    $baseSql = " FROM {$p}module_records r WHERE r.module_id = ?";
    $params = [$moduleId];

    // Visibility Logic
    $currentUserId = (int) ($_SESSION['user_id'] ?? 0);
    $currentUserRole = (int) ($_SESSION['role_id'] ?? 0);
    $isAdmin = !empty($_SESSION['is_admin']);

    if (!$isAdmin && $rule !== 'all') {
        $vConditions = [];

        if ($rule === 'owner') {
            $vConditions[] = "r.created_by = ?";
            $params[] = $currentUserId;
        } elseif ($rule === 'role_down' || $rule === 'role_equal_down') {
            $childRoles = [];
            if ($currentUserRole) {
                $crStmt = $conn->prepare("SELECT child_role_id FROM {$p}role_hierarchy WHERE parent_role_id = ?");
                $crStmt->execute([$currentUserRole]);
                $childRoles = $crStmt->fetchAll(PDO::FETCH_COLUMN);
            }

            $roleFilter = [$currentUserRole];
            if (!empty($childRoles))
                $roleFilter = array_merge($roleFilter, $childRoles);

            if ($rule === 'role_down') {
                $vConditions[] = "(r.created_by = ? OR r.created_by IN (SELECT id FROM {$p}users WHERE role_id IN (" . implode(',', array_map('intval', $childRoles ?: [0])) . ")))";
                $params[] = $currentUserId;
            } else {
                $vConditions[] = "r.created_by IN (SELECT id FROM {$p}users WHERE role_id IN (" . implode(',', array_map('intval', $roleFilter)) . "))";
            }
        } elseif ($rule === 'role_up') {
            $parentRoles = [];
            if ($currentUserRole) {
                $prStmt = $conn->prepare("SELECT parent_role_id FROM {$p}role_hierarchy WHERE child_role_id = ?");
                $prStmt->execute([$currentUserRole]);
                $parentRoles = $prStmt->fetchAll(PDO::FETCH_COLUMN);
            }
            $vConditions[] = "(r.created_by = ? OR r.created_by IN (SELECT id FROM {$p}users WHERE role_id IN (" . implode(',', array_map('intval', $parentRoles ?: [0])) . ")))";
            $params[] = $currentUserId;
        }

        // Add "Assigned To Me" exception
        $atStmt = $conn->prepare("SELECT id FROM {$p}module_fields WHERE module_id = ? AND field_type = 'assigned_to' LIMIT 1");
        $atStmt->execute([$moduleId]);
        $atFid = $atStmt->fetchColumn();
        if ($atFid) {
            $vConditions[] = "r.id IN (SELECT record_id FROM {$p}module_record_values WHERE field_id = ? AND value = ?)";
            $params[] = (int) $atFid;
            $params[] = (string) $currentUserId;
        }

        if (!empty($vConditions)) {
            $baseSql .= " AND (" . implode(' OR ', $vConditions) . ")";
        }
    }

    if ($search) {
        $baseSql .= " AND r.id IN (
            SELECT DISTINCT rv.record_id 
            FROM {$p}module_record_values rv 
            WHERE rv.value LIKE ?
        )";
        $params[] = '%' . $search . '%';
    }

    // Dynamic Saved Filters
    if ($filterRules && is_array($filterRules)) {
        // Fetch all fields for system type mapping
        $fStmt = $conn->prepare("SELECT id, field_type FROM {$p}module_fields WHERE module_id = ?");
        $fStmt->execute([$moduleId]);
        $moduleFields = $fStmt->fetchAll(PDO::FETCH_ASSOC);
        $sysFieldTypes = [];
        foreach ($moduleFields as $f) {
            $sysFieldTypes[(int)$f['id']] = $f['field_type'];
        }

        foreach ($filterRules as $rule_item) {
            $fid = $rule_item['field_id'] ?? '';
            $op = $rule_item['operator'] ?? '=';
            $val = $rule_item['value'] ?? '';

            // Validate operator
            $allowedOps = ['=', '!=', '>', '<', '>=', '<=', 'LIKE', 'NOT LIKE'];
            if (!in_array($op, $allowedOps)) {
                $op = '=';
            }

            // System fields mapping
            $sysFields = [
                'created_by' => 'r.created_by',
                'created_at' => 'r.created_at',
                'updated_by' => 'r.updated_by',
                'updated_at' => 'r.updated_at',
                'id' => 'r.id'
            ];

            $mappedCol = null;
            if (array_key_exists($fid, $sysFields)) {
                $mappedCol = $sysFields[$fid];
            } else {
                $numericFid = (int)$fid;
                if ($numericFid > 0 && isset($sysFieldTypes[$numericFid])) {
                    $type = $sysFieldTypes[$numericFid];
                    if ($type === 'sys_created_by') $mappedCol = 'r.created_by';
                    elseif ($type === 'sys_created_at') $mappedCol = 'r.created_at';
                    elseif ($type === 'sys_updated_by') $mappedCol = 'r.updated_by';
                    elseif ($type === 'sys_updated_at') $mappedCol = 'r.updated_at';
                }
            }

            if ($mappedCol) {
                if ($op === 'LIKE') {
                    $baseSql .= " AND $mappedCol LIKE ?";
                    $params[] = '%' . $val . '%';
                } elseif ($op === 'NOT LIKE') {
                    $baseSql .= " AND $mappedCol NOT LIKE ?";
                    $params[] = '%' . $val . '%';
                } else {
                    $baseSql .= " AND $mappedCol $op ?";
                    $params[] = $val;
                }
            } else {
                // Custom field
                $fieldId = (int)$fid;
                if ($fieldId > 0) {
                    if ($op === 'LIKE') {
                        $baseSql .= " AND r.id IN (SELECT record_id FROM {$p}module_record_values WHERE field_id = ? AND value LIKE ?)";
                        $params[] = $fieldId;
                        $params[] = '%' . $val . '%';
                    } elseif ($op === 'NOT LIKE') {
                        $baseSql .= " AND r.id NOT IN (SELECT record_id FROM {$p}module_record_values WHERE field_id = ? AND value LIKE ?)";
                        $params[] = $fieldId;
                        $params[] = '%' . $val . '%';
                    } elseif ($op === '!=') {
                        $baseSql .= " AND r.id NOT IN (SELECT record_id FROM {$p}module_record_values WHERE field_id = ? AND value = ?)";
                        $params[] = $fieldId;
                        $params[] = $val;
                    } else {
                        $baseSql .= " AND r.id IN (SELECT record_id FROM {$p}module_record_values WHERE field_id = ? AND value $op ?)";
                        $params[] = $fieldId;
                        $params[] = $val;
                    }
                }
            }
        }
    }

    // Count total matching records
    $cStmt = $conn->prepare("SELECT COUNT(*)" . $baseSql);
    $cStmt->execute($params);
    $total = (int) $cStmt->fetchColumn();

    // Query records
    $sql = "SELECT r.id, r.created_at, r.created_by, r.updated_at, r.updated_by" . $baseSql . " ORDER BY r.created_at DESC LIMIT $limit OFFSET $offset";
    $rStmt = $conn->prepare($sql);
    $rStmt->execute($params);
    $records = $rStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all fields for this module to find system fields
    $allFStmt = $conn->prepare("SELECT id, field_type FROM {$p}module_fields WHERE module_id = ?");
    $allFStmt->execute([$moduleId]);
    $sysFieldMap = [];
    foreach ($allFStmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
        if (strpos($f['field_type'], 'sys_') === 0)
            $sysFieldMap[$f['field_type']] = (int) $f['id'];
    }

    // Fetch users for mapping IDs to names
    $usersList = dm_fetch_users($conn, $p);
    $userMap = [];
    foreach ($usersList as $u) {
        $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
        $userMap[$u['id']] = $name ?: $u['username'];
    }

    // Fetch values for these records
    $recordIds = array_column($records, 'id');
    $valueMap = [];
    if ($recordIds) {
        $inClause = implode(',', array_map('intval', $recordIds));
        $vStmt = $conn->query("SELECT record_id, field_id, value FROM {$p}module_record_values WHERE record_id IN ($inClause)");
        foreach ($vStmt->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $valueMap[(int) $v['record_id']][(int) $v['field_id']] = $v['value'];
        }
    }

    foreach ($records as &$rec) {
        $rec['values'] = $valueMap[(int) $rec['id']] ?? [];

        // Inject system values
        if (isset($sysFieldMap['sys_created_by']))
            $rec['values'][$sysFieldMap['sys_created_by']] = $userMap[$rec['created_by']] ?? "User #" . $rec['created_by'];
        if (isset($sysFieldMap['sys_created_at']))
            $rec['values'][$sysFieldMap['sys_created_at']] = $rec['created_at'];
        if (isset($sysFieldMap['sys_updated_by']))
            $rec['values'][$sysFieldMap['sys_updated_by']] = $userMap[$rec['updated_by']] ?? ($rec['updated_by'] ? "User #" . $rec['updated_by'] : "");
        if (isset($sysFieldMap['sys_updated_at']))
            $rec['values'][$sysFieldMap['sys_updated_at']] = $rec['updated_at'];
            
        $rec['can_edit'] = dm_can_edit_record($conn, $p, $module, $rec['created_by'], $currentUserId, $currentUserRole, $isAdmin);
        $rec['can_delete'] = dm_can_delete_record($conn, $p, $module, $rec['created_by'], $currentUserId, $currentUserRole, $isAdmin);
    }
    unset($rec);

    return ['fields' => $fields, 'records' => $records, 'total' => $total];
}

/**
 * Fetch a single record with all its values.
 */
function dm_fetch_record(PDO $conn, string $p, int $recordId): ?array
{
    $rStmt = $conn->prepare("SELECT * FROM {$p}module_records WHERE id = ?");
    $rStmt->execute([$recordId]);
    $record = $rStmt->fetch(PDO::FETCH_ASSOC);
    if (!$record)
        return null;

    $vStmt = $conn->prepare("SELECT field_id, value FROM {$p}module_record_values WHERE record_id = ?");
    $vStmt->execute([$recordId]);
    $record['values'] = [];
    foreach ($vStmt->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $record['values'][(int) $v['field_id']] = $v['value'];
    }

    // Populate system fields for single record
    $sfStmt = $conn->prepare("SELECT id, field_type FROM {$p}module_fields WHERE module_id = ? AND field_type LIKE 'sys_%'");
    $sfStmt->execute([$record['module_id']]);
    foreach ($sfStmt->fetchAll(PDO::FETCH_ASSOC) as $sf) {
        $fid = (int) $sf['id'];
        switch ($sf['field_type']) {
            case 'sys_created_at':
                $record['values'][$fid] = $record['created_at'];
                break;
            case 'sys_updated_at':
                $record['values'][$fid] = $record['updated_at'];
                break;
            case 'sys_created_by':
            case 'sys_updated_by':
                $uid = $sf['field_type'] === 'sys_created_by' ? $record['created_by'] : $record['updated_by'];
                if ($uid) {
                    $uStmt = $conn->prepare("SELECT username, first_name, last_name FROM {$p}users WHERE id = ?");
                    $uStmt->execute([$uid]);
                    $u = $uStmt->fetch(PDO::FETCH_ASSOC);
                    if ($u) {
                        $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                        $record['values'][$fid] = $name ?: $u['username'];
                    } else {
                        $record['values'][$fid] = "User #$uid";
                    }
                }
                break;
        }
    }

    return $record;
}

/**
 * Fetch users list for "Assigned To" field type.
 */
function dm_fetch_users(PDO $conn, string $p): array
{
    try {
        $stmt = $conn->query("SELECT id, username, first_name, last_name FROM {$p}users ORDER BY username ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/* ──────────────────────── COUNTRIES & STATES DATA ────────────────────────── */

function dm_get_countries(): array
{
    return [
        'IN' => 'India',
        'US' => 'United States',
        'GB' => 'United Kingdom',
        'AE' => 'United Arab Emirates',
        'SA' => 'Saudi Arabia',
        'AU' => 'Australia',
        'CA' => 'Canada',
        'SG' => 'Singapore',
        'MY' => 'Malaysia',
        'DE' => 'Germany',
        'FR' => 'France',
        'JP' => 'Japan',
        'CN' => 'China',
        'KR' => 'South Korea',
        'BR' => 'Brazil',
        'ZA' => 'South Africa',
        'NZ' => 'New Zealand',
        'QA' => 'Qatar',
        'KW' => 'Kuwait',
        'BH' => 'Bahrain',
        'OM' => 'Oman',
        'NP' => 'Nepal',
        'LK' => 'Sri Lanka',
        'BD' => 'Bangladesh',
    ];
}

function dm_get_states(): array
{
    return [
        'IN' => [
            'AN' => 'Andaman and Nicobar Islands',
            'AP' => 'Andhra Pradesh',
            'AR' => 'Arunachal Pradesh',
            'AS' => 'Assam',
            'BR' => 'Bihar',
            'CH' => 'Chandigarh',
            'CT' => 'Chhattisgarh',
            'DL' => 'Delhi',
            'GA' => 'Goa',
            'GJ' => 'Gujarat',
            'HR' => 'Haryana',
            'HP' => 'Himachal Pradesh',
            'JK' => 'Jammu and Kashmir',
            'JH' => 'Jharkhand',
            'KA' => 'Karnataka',
            'KL' => 'Kerala',
            'MP' => 'Madhya Pradesh',
            'MH' => 'Maharashtra',
            'MN' => 'Manipur',
            'ML' => 'Meghalaya',
            'MZ' => 'Mizoram',
            'NL' => 'Nagaland',
            'OR' => 'Odisha',
            'PB' => 'Punjab',
            'RJ' => 'Rajasthan',
            'SK' => 'Sikkim',
            'TN' => 'Tamil Nadu',
            'TG' => 'Telangana',
            'TR' => 'Tripura',
            'UP' => 'Uttar Pradesh',
            'UK' => 'Uttarakhand',
            'WB' => 'West Bengal',
        ],
        'US' => [
            'AL' => 'Alabama',
            'AK' => 'Alaska',
            'AZ' => 'Arizona',
            'AR' => 'Arkansas',
            'CA' => 'California',
            'CO' => 'Colorado',
            'CT' => 'Connecticut',
            'FL' => 'Florida',
            'GA' => 'Georgia',
            'IL' => 'Illinois',
            'NY' => 'New York',
            'TX' => 'Texas',
            'WA' => 'Washington',
        ],
        'GB' => ['ENG' => 'England', 'SCT' => 'Scotland', 'WLS' => 'Wales', 'NIR' => 'Northern Ireland'],
        'AE' => [
            'AUH' => 'Abu Dhabi',
            'DXB' => 'Dubai',
            'SHJ' => 'Sharjah',
            'AJM' => 'Ajman',
            'UMQ' => 'Umm Al-Quwain',
            'RAK' => 'Ras Al Khaimah',
            'FUJ' => 'Fujairah',
        ],
    ];
}

function dm_get_districts(): array
{
    // A sample mapping of State Code -> Array of Districts.
    // For production, this should ideally be populated from a database.
    return [
        'TN' => [
            'CHE' => 'Chennai',
            'CBE' => 'Coimbatore',
            'MDU' => 'Madurai',
            'TRI' => 'Trichy',
            'SA' => 'Salem',
            'ER' => 'Erode',
            'TIR' => 'Tirunelveli',
            'KAN' => 'Kanyakumari',
            'VEL' => 'Vellore',
            'THO' => 'Thoothukudi',
            'DGL' => 'Dindigul',
            'TAN' => 'Thanjavur'
        ],
        'KA' => [
            'BLR' => 'Bangalore',
            'MYS' => 'Mysore',
            'MAN' => 'Mangalore',
            'HUB' => 'Hubli',
            'BEL' => 'Belgaum',
            'GUL' => 'Gulbarga',
            'DAV' => 'Davanagere',
            'BEL' => 'Bellary'
        ],
        'MH' => [
            'MUM' => 'Mumbai',
            'PUN' => 'Pune',
            'NAG' => 'Nagpur',
            'THA' => 'Thane',
            'NAS' => 'Nashik',
            'AUR' => 'Aurangabad',
            'SOL' => 'Solapur',
            'KOL' => 'Kolhapur'
        ],
        'DL' => [
            'NDL' => 'New Delhi',
            'CDL' => 'Central Delhi',
            'EDL' => 'East Delhi',
            'WDL' => 'West Delhi',
            'SDL' => 'South Delhi',
            'NDL' => 'North Delhi'
        ],
        'NY' => [
            'NYC' => 'New York City',
            'ALB' => 'Albany',
            'BUF' => 'Buffalo',
            'ROC' => 'Rochester'
        ],
        'CA' => [
            'LA' => 'Los Angeles',
            'SF' => 'San Francisco',
            'SD' => 'San Diego',
            'SJ' => 'San Jose'
        ]
    ];
}

/* ──────────────────────── MAPBOX CONFIGURATION ────────────────────────── */

function dm_get_mapbox_config(): array
{
    return [
        'access_token' => 'pk.eyJ1IjoiZnRwYWRtaW4iLCJhIjoiY21sZXQ1enJpMWtyODNmcXVzanNxZWlsOSJ9.LD6BV4V5Pz6Bc2O4FI2yJw',
        'bearer_token' => 'Bearer 97fa6WEt6nfzAlJfBuZwwmPPusYX1AEk',
        'api_key' => '97fa6WEt6nfzAlJfBuZwwmPPusYX1AEk',
    ];
}

/**
 * Get the list of user IDs visible to a specific user based on a visibility rule.
 * Returns null if all users should be visible (e.g. admin or rule 'all').
 * Otherwise, returns an array of allowed user IDs (e.g. [1, 2, 3]).
 */
function dm_get_visible_user_ids(PDO $conn, string $p, int $userId, ?int $roleId, string $rule, bool $isAdmin = false): ?array
{
    if ($isAdmin || $rule === 'all') {
        return null;
    }

    $allowedUserIds = [$userId];

    if ($rule === 'owner') {
        return $allowedUserIds;
    }

    if ($rule === 'role_down' || $rule === 'role_equal_down') {
        $childRoles = [];
        if ($roleId) {
            $crStmt = $conn->prepare("SELECT child_role_id FROM {$p}role_hierarchy WHERE parent_role_id = ?");
            $crStmt->execute([$roleId]);
            $childRoles = $crStmt->fetchAll(PDO::FETCH_COLUMN);
        }

        $roleFilter = [];
        if ($rule === 'role_equal_down') {
            if ($roleId) {
                $roleFilter[] = $roleId;
            }
        }
        if (!empty($childRoles)) {
            $roleFilter = array_merge($roleFilter, $childRoles);
        }

        if (!empty($roleFilter)) {
            $inClause = implode(',', array_map('intval', $roleFilter));
            $uStmt = $conn->query("SELECT id FROM {$p}users WHERE role_id IN ($inClause)");
            $allowedUserIds = array_merge($allowedUserIds, $uStmt->fetchAll(PDO::FETCH_COLUMN));
        }
    } elseif ($rule === 'role_up') {
        $parentRoles = [];
        if ($roleId) {
            $prStmt = $conn->prepare("SELECT parent_role_id FROM {$p}role_hierarchy WHERE child_role_id = ?");
            $prStmt->execute([$roleId]);
            $parentRoles = $prStmt->fetchAll(PDO::FETCH_COLUMN);
        }

        if (!empty($parentRoles)) {
            $inClause = implode(',', array_map('intval', $parentRoles));
            $uStmt = $conn->query("SELECT id FROM {$p}users WHERE role_id IN ($inClause)");
            $allowedUserIds = array_merge($allowedUserIds, $uStmt->fetchAll(PDO::FETCH_COLUMN));
        }
        if (!in_array($userId, $allowedUserIds)) {
            $allowedUserIds[] = $userId;
        }
    }

}

/**
 * Fetch all fields for a dynamic module.
 */
function dm_fetch_module_fields(PDO $conn, string $p, int $moduleId): array
{
    $stmt = $conn->prepare("SELECT * FROM {$p}module_fields WHERE module_id = ? ORDER BY sort_order ASC");
    $stmt->execute([$moduleId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Triggers workflow automation rules for a module record.
 */
function dm_trigger_workflows(PDO $conn, string $p, int $moduleId, int $recordId, array $oldValues, array $newValues): void
{
    // Fetch active workflows for this module
    $wStmt = $conn->prepare("SELECT * FROM {$p}module_workflows WHERE module_id = ? AND status = 'active'");
    $wStmt->execute([$moduleId]);
    $workflows = $wStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($workflows)) {
        return;
    }

    // Fetch all field details of this module to construct label mapping
    $fields = dm_fetch_module_fields($conn, $p, $moduleId);
    $fieldMap = []; // field_id => field details
    foreach ($fields as $f) {
        $fieldMap[(int)$f['id']] = $f;
    }

    // Fetch all current record values to replace template variables
    $recStmt = $conn->prepare("SELECT created_at, created_by, updated_at, updated_by FROM {$p}module_records WHERE id = ?");
    $recStmt->execute([$recordId]);
    $recordRow = $recStmt->fetch(PDO::FETCH_ASSOC);
    if (!$recordRow) return;

    $allCurrentValues = [];
    $valStmt = $conn->prepare("SELECT field_id, value FROM {$p}module_record_values WHERE record_id = ?");
    $valStmt->execute([$recordId]);
    foreach ($valStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $allCurrentValues[(int)$row['field_id']] = $row['value'];
    }

    // Inject system fields into $allCurrentValues and potentially $newValues/$oldValues
    foreach ($fields as $f) {
        $fid = (int)$f['id'];
        $fType = $f['field_type'];
        if ($fType === 'sys_created_by') {
            $allCurrentValues[$fid] = $recordRow['created_by'];
            if (empty($oldValues)) {
                $newValues[$fid] = $recordRow['created_by'];
            } else {
                $oldValues[$fid] = $recordRow['created_by'];
                $newValues[$fid] = $recordRow['created_by'];
            }
        } elseif ($fType === 'sys_created_at') {
            $allCurrentValues[$fid] = $recordRow['created_at'];
            if (empty($oldValues)) {
                $newValues[$fid] = $recordRow['created_at'];
            } else {
                $oldValues[$fid] = $recordRow['created_at'];
                $newValues[$fid] = $recordRow['created_at'];
            }
        } elseif ($fType === 'sys_updated_by') {
            $allCurrentValues[$fid] = $recordRow['updated_by'];
            if (!empty($oldValues)) {
                $newValues[$fid] = $recordRow['updated_by'];
            }
        } elseif ($fType === 'sys_updated_at') {
            $allCurrentValues[$fid] = $recordRow['updated_at'];
            if (!empty($oldValues)) {
                $newValues[$fid] = $recordRow['updated_at'];
            }
        }
    }

    // Prepare system user lists for placeholder resolution
    $usersStmt = $conn->query("SELECT id, username, first_name, last_name, email FROM {$p}users");
    $userMap = [];
    foreach ($usersStmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $fullName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
        $userMap[$u['id']] = [
            'name' => $fullName ?: $u['username'],
            'email' => $u['email'],
            'phone' => ''
        ];
    }

    foreach ($workflows as $w) {
        $triggerEvent = $w['trigger_event'] ?? 'create_or_edit';
        $condType = $w['condition_type'] ?? 'field_value';
        $triggerFieldId = $w['trigger_field_id'] ? (int)$w['trigger_field_id'] : null;
        $triggerValue = $w['trigger_value'] !== null ? trim($w['trigger_value']) : '';

        // 1. Check trigger event matches
        $isCreate = empty($oldValues);
        if ($isCreate) {
            if ($triggerEvent === 'edit') {
                continue; // Trigger event is edit, but this is a creation
            }
        } else {
            if ($triggerEvent === 'create') {
                continue; // Trigger event is create, but this is an update
            }
        }

        // 2. Check condition type
        $isTriggered = false;
        if ($condType === 'always') {
            $isTriggered = true;
        } elseif ($condType === 'field_value' && $triggerFieldId !== null) {
            $newValue = isset($newValues[$triggerFieldId]) ? trim((string)$newValues[$triggerFieldId]) : null;
            $oldValue = isset($oldValues[$triggerFieldId]) ? trim((string)$oldValues[$triggerFieldId]) : null;

            if ($newValue === null && isset($allCurrentValues[$triggerFieldId])) {
                $newValue = trim((string)$allCurrentValues[$triggerFieldId]);
            }

            if ($isCreate) {
                if ($newValue !== null && $newValue === $triggerValue) {
                    $isTriggered = true;
                }
            } else {
                if ($newValue !== null && $newValue === $triggerValue && $oldValue !== $newValue) {
                    $isTriggered = true;
                }
            }
        } elseif ($condType === 'field_changed' && $triggerFieldId !== null) {
            $newValue = isset($newValues[$triggerFieldId]) ? trim((string)$newValues[$triggerFieldId]) : null;
            $oldValue = isset($oldValues[$triggerFieldId]) ? trim((string)$oldValues[$triggerFieldId]) : null;

            if ($isCreate) {
                if ($newValue !== null && $newValue !== '') {
                    $isTriggered = true;
                }
            } else {
                if ($newValue !== null && $oldValue !== $newValue) {
                    $isTriggered = true;
                }
            }
        }

        if (!$isTriggered) {
            continue;
        }

        // 1. Resolve Recipient
        $recipient = '';
        if ($w['recipient_field_id']) {
            $recFieldId = (int)$w['recipient_field_id'];
            $recipientValue = $allCurrentValues[$recFieldId] ?? '';
            
            // If the recipient field is a user type (e.g. assigned_to or created_by)
            $fType = $fieldMap[$recFieldId]['field_type'] ?? '';
            if (($fType === 'user' || $fType === 'assigned_to' || $fType === 'sys_created_by' || $fType === 'sys_updated_by') && $recipientValue) {
                $uid = (int)$recipientValue;
                if ($w['action_type'] === 'email') {
                    $recipient = $userMap[$uid]['email'] ?? '';
                } elseif ($w['action_type'] === 'whatsapp') {
                    $recipient = $userMap[$uid]['phone'] ?? '';
                } else {
                    $recipient = $userMap[$uid]['name'] ?? "User #$uid";
                }
            } else {
                $recipient = $recipientValue;
            }
        }
        
        if (empty($recipient) && $w['recipient_custom']) {
            $recipient = $w['recipient_custom'];
        }

        if (empty($recipient)) {
            // Record a failure log
            $logStmt = $conn->prepare("
                INSERT INTO {$p}workflow_logs (workflow_id, record_id, recipient, action_type, subject, body, status, error_message)
                VALUES (?, ?, ?, ?, ?, ?, 'failed', 'Recipient is empty or could not be resolved')
            ");
            $logStmt->execute([$w['id'], $recordId, '', $w['action_type'], $w['template_subject'], $w['template_body']]);
            continue;
        }

        // 2. Resolve Subject & Body template placeholders (e.g. {First Name} -> Anand)
        $subject = $w['template_subject'] ?? '';
        $body = $w['template_body'];

        // Replace custom field placeholders
        foreach ($fields as $f) {
            $val = $allCurrentValues[(int)$f['id']] ?? '';
            
            // Format duration/checkbox/user fields nicely in templates
            if ($f['field_type'] === 'checkbox') {
                $val = $val ? 'Yes' : 'No';
            } elseif ($f['field_type'] === 'user' || $f['field_type'] === 'assigned_to' || $f['field_type'] === 'sys_created_by' || $f['field_type'] === 'sys_updated_by') {
                $val = $userMap[(int)$val]['name'] ?? "User #$val";
            }
            
            $placeholder = '{' . $f['label'] . '}';
            $subject = str_ireplace($placeholder, $val, $subject);
            $body = str_ireplace($placeholder, $val, $body);
        }

        // Replace system placeholder values
        $createdByName = $userMap[(int)$recordRow['created_by']]['name'] ?? '';
        $updatedByName = $userMap[(int)$recordRow['updated_by']]['name'] ?? '';
        
        $sysPlaceholders = [
            '{Created By}' => $createdByName,
            '{Created On}' => $recordRow['created_at'],
            '{Updated By}' => $updatedByName,
            '{Updated On}' => $recordRow['updated_at'],
            '{Record ID}' => $recordId
        ];
        foreach ($sysPlaceholders as $pl => $val) {
            $subject = str_ireplace($pl, $val, $subject);
            $body = str_ireplace($pl, $val, $body);
        }

        // 3. Dispatch Action
        $status = 'sent';
        $errorMsg = null;

        if ($w['action_type'] === 'email') {
            $smtpHost = dm_get_system_setting($conn, $p, 'smtp_host', '');
            $smtpPort = (int)dm_get_system_setting($conn, $p, 'smtp_port', 0);
            $smtpUser = dm_get_system_setting($conn, $p, 'smtp_user', '');
            $smtpPass = dm_get_system_setting($conn, $p, 'smtp_pass', '');
            $smtpFromEmail = dm_get_system_setting($conn, $p, 'smtp_from_email', '');
            $smtpFromName = dm_get_system_setting($conn, $p, 'smtp_from_name', '');
            $smtpEnc = dm_get_system_setting($conn, $p, 'smtp_encryption', 'none');

            if ($smtpHost && $smtpPort) {
                // Route email via configured SMTP settings
                try {
                    dm_send_smtp_email($smtpHost, $smtpPort, $smtpUser, $smtpPass, $smtpFromEmail, $smtpFromName, $recipient, $subject, $body, $smtpEnc);
                } catch (Throwable $e) {
                    $status = 'failed';
                    $errorMsg = 'SMTP Error: ' . $e->getMessage();
                }
            } else {
                // Fall back to standard php mail() function
                $headers = "MIME-Version: 1.0\r\n";
                $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                $headers .= "From: VY-AI CRM Automation <no-reply@vygroups.com>\r\n";
                
                $mailSent = @mail($recipient, $subject, nl2br($body), $headers);
                if (!$mailSent) {
                    $status = 'failed';
                    $errorMsg = 'PHP mail() dispatch returned false';
                }
            }
        } elseif ($w['action_type'] === 'whatsapp') {
            $whatsappApiUrl = dm_get_system_setting($conn, $p, 'whatsapp_api_url', '');
            $whatsappToken = dm_get_system_setting($conn, $p, 'whatsapp_access_token', '');

            if ($whatsappApiUrl && $whatsappToken) {
                // Dispatch via WhatsApp Gateway API URL
                try {
                    dm_send_whatsapp_message($whatsappApiUrl, $whatsappToken, $recipient, $body);
                } catch (Throwable $e) {
                    $status = 'failed';
                    $errorMsg = 'WhatsApp API Error: ' . $e->getMessage();
                }
            } else {
                // Simulated dispatch fallback
                $status = 'sent';
                $errorMsg = 'WhatsApp simulated dispatch: message stored in database logs';
            }
        } elseif ($w['action_type'] === 'push') {
            // Push Notification simulated dispatch
            $status = 'sent';
            $errorMsg = 'Push notification simulated delivery: notification stored in database logs';
        }

        // 4. Log execution
        $logStmt = $conn->prepare("
            INSERT INTO {$p}workflow_logs (workflow_id, record_id, recipient, action_type, subject, body, status, error_message)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $logStmt->execute([
            $w['id'],
            $recordId,
            $recipient,
            $w['action_type'],
            $subject,
            $body,
            $status,
            $errorMsg
        ]);
    }
}

/**
 * Sends a transactional email securely via raw SMTP sockets.
 */
function dm_send_smtp_email(string $host, int $port, string $user, string $pass, string $fromEmail, string $fromName, string $to, string $subject, string $body, string $encryption = 'none', string $ccEmail = '', array $attachments = [], string $bccEmail = ''): bool
{
    $timeout = 15;
    $socketHost = ($encryption === 'ssl') ? 'ssl://' . $host : $host;
    $socket = @fsockopen($socketHost, $port, $errno, $errstr, $timeout);
    if (!$socket) {
        throw new Exception("Could not connect to SMTP server $host:$port: $errstr ($errno)");
    }

    $read = function($socket) {
        $response = '';
        while (($str = fgets($socket, 515)) !== false) {
            $response .= $str;
            if (substr($str, 3, 1) === ' ') {
                break;
            }
        }
        return $response;
    };

    $send = function($socket, $cmd) use ($read) {
        fwrite($socket, $cmd . "\r\n");
        return $read($socket);
    };

    $read($socket); // banner

    // EHLO
    $send($socket, "EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));

    if ($encryption === 'tls') {
        $res = $send($socket, "STARTTLS");
        if (strpos($res, '220') === false) {
            throw new Exception("STARTTLS failed: " . $res);
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new Exception("Failed to enable TLS encryption");
        }
        // Resend EHLO after TLS
        $send($socket, "EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    }

    // AUTH LOGIN
    if ($user && $pass) {
        $res = $send($socket, "AUTH LOGIN");
        if (strpos($res, '334') === false) {
            throw new Exception("AUTH LOGIN not supported by server: " . $res);
        }
        $res = $send($socket, base64_encode($user));
        if (strpos($res, '334') === false) {
            throw new Exception("Username rejected: " . $res);
        }
        $res = $send($socket, base64_encode($pass));
        if (strpos($res, '235') === false) {
            throw new Exception("Password rejected: " . $res);
        }
    }

    // MAIL FROM
    $res = $send($socket, "MAIL FROM:<" . $fromEmail . ">");
    if (strpos($res, '250') === false) {
        throw new Exception("MAIL FROM failed: " . $res);
    }

    // RCPT TO
    $res = $send($socket, "RCPT TO:<" . $to . ">");
    if (strpos($res, '250') === false && strpos($res, '251') === false) {
        throw new Exception("RCPT TO failed: " . $res);
    }

    if ($ccEmail) {
        $res = $send($socket, "RCPT TO:<" . $ccEmail . ">");
        if (strpos($res, '250') === false && strpos($res, '251') === false) {
            throw new Exception("RCPT TO (CC) failed: " . $res);
        }
    }

    if ($bccEmail) {
        // If there are multiple BCC emails comma separated, loop them
        $bccArr = array_map('trim', explode(',', $bccEmail));
        foreach ($bccArr as $bccItem) {
            if ($bccItem) {
                $res = $send($socket, "RCPT TO:<" . $bccItem . ">");
                if (strpos($res, '250') === false && strpos($res, '251') === false) {
                    throw new Exception("RCPT TO (BCC) failed: " . $res);
                }
            }
        }
    }

    // DATA
    $res = $send($socket, "DATA");
    if (strpos($res, '354') === false) {
        throw new Exception("DATA command rejected: " . $res);
    }

    // Headers & Message
    $boundary = md5(time() . uniqid());
    
    $headers = [
        "MIME-Version: 1.0",
        "From: " . ($fromName ? '"' . $fromName . '" <' . $fromEmail . '>' : $fromEmail),
        "To: " . $to,
        "Subject: " . $subject,
        "Date: " . date('r'),
        "Message-ID: <" . time() . '.' . uniqid() . '@' . $host . ">"
    ];

    if ($ccEmail) {
        $headers[] = "Cc: " . $ccEmail;
    }
    
    // SMTP standard usually hides BCC from headers, but some systems prefer omitting it entirely
    // Usually we don't add "Bcc: " header in the message payload, so the recipients don't see each other.
    // However, if we do add it, SMTP servers often strip it before delivery.
    // It's safer not to include Bcc in the headers sent inside the DATA command.

    if (empty($attachments)) {
        $headers[] = "Content-Type: text/html; charset=UTF-8";
        $message = implode("\r\n", $headers) . "\r\n\r\n" . nl2br($body) . "\r\n.";
    } else {
        $headers[] = "Content-Type: multipart/mixed; boundary=\"$boundary\"";
        
        $message = implode("\r\n", $headers) . "\r\n\r\n";
        $message .= "--$boundary\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $message .= nl2br($body) . "\r\n\r\n";
        
        foreach ($attachments as $att) {
            $message .= "--$boundary\r\n";
            $message .= "Content-Type: " . ($att['mime'] ?? 'application/octet-stream') . "; name=\"" . $att['name'] . "\"\r\n";
            $message .= "Content-Transfer-Encoding: base64\r\n";
            $message .= "Content-Disposition: attachment; filename=\"" . $att['name'] . "\"\r\n\r\n";
            $message .= chunk_split(base64_encode($att['content'])) . "\r\n";
        }
        $message .= "--$boundary--\r\n.";
    }
    $res = $send($socket, $message);

    // QUIT
    $send($socket, "QUIT");
    fclose($socket);

    if (strpos($res, '250') === false) {
        throw new Exception("Failed to send message body: " . $res);
    }
    return true;
}

/**
 * Sends a WhatsApp text message using curl to Meta Cloud API or standard webhook gateway.
 */
function dm_send_whatsapp_message(string $apiUrl, string $token, string $to, string $body): bool
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    
    $headers = [
        "Authorization: Bearer " . $token,
        "Content-Type: application/json"
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    $payload = json_encode([
        "messaging_product" => "whatsapp",
        "recipient_type" => "individual",
        "to" => $to,
        "type" => "text",
        "text" => [
            "body" => $body
        ]
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    
    if ($err) {
        throw new Exception("Curl error: " . $err);
    }
    if ($httpCode >= 400) {
        throw new Exception("WhatsApp API returned HTTP $httpCode: $response");
    }
    return true;
}

function dm_can_edit_record($conn, $p, $module, $recordOwnerId, $userId, $userRoleId, $isAdmin = false) {
    if ($isAdmin) return true;
    $rule = $module['edit_rule'] ?? 'all';
    if ($rule === 'all') return true;
    if ($rule === 'specific_roles') {
        $rolesStr = $module['edit_roles'] ?? '';
        $roles = array_filter(array_map('trim', explode(',', $rolesStr)));
        return in_array((string)$userRoleId, $roles, true);
    }
    $allowedIds = dm_get_visible_user_ids($conn, $p, $userId, $userRoleId, $rule);
    return in_array((int)$recordOwnerId, $allowedIds, true);
}

function dm_can_delete_record($conn, $p, $module, $recordOwnerId, $userId, $userRoleId, $isAdmin = false) {
    if ($isAdmin) return true;
    $rule = $module['delete_rule'] ?? 'all';
    if ($rule === 'all') return true;
    if ($rule === 'specific_roles') {
        $rolesStr = $module['delete_roles'] ?? '';
        $roles = array_filter(array_map('trim', explode(',', $rolesStr)));
        return in_array((string)$userRoleId, $roles, true);
    }
    $allowedIds = dm_get_visible_user_ids($conn, $p, $userId, $userRoleId, $rule);
    return in_array((int)$recordOwnerId, $allowedIds, true);
}
