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
function dm_fetch_records(PDO $conn, string $p, int $moduleId, ?string $search = null, int $limit = 50, int $offset = 0): array
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
    $mStmt = $conn->prepare("SELECT visibility_rule FROM {$p}modules WHERE id = ?");
    $mStmt->execute([$moduleId]);
    $rule = $mStmt->fetchColumn() ?: 'all';

    $sql = "SELECT r.id, r.created_at, r.created_by, r.updated_at, r.updated_by FROM {$p}module_records r WHERE r.module_id = ?";
    $params = [$moduleId];

    // ... (Visibility Logic remains same) ...
    // [I'll keep the middle part as is in the replacement, just showing the change in SQL]

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
            $vConditions[] = "r.created_by IN (SELECT id FROM {$p}users WHERE role_id IN (" . implode(',', array_map('intval', $parentRoles ?: [0])) . "))";
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
            $sql .= " AND (" . implode(' OR ', $vConditions) . ")";
        }
    }

    if ($search) {
        $sql .= " AND r.id IN (
            SELECT DISTINCT rv.record_id 
            FROM {$p}module_record_values rv 
            WHERE rv.value LIKE ?
        )";
        $params[] = '%' . $search . '%';
    }

    $sql .= " ORDER BY r.created_at DESC LIMIT $limit OFFSET $offset";
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
    }
    unset($rec);

    // Count total
    $cStmt = $conn->prepare("SELECT COUNT(*) FROM {$p}module_records WHERE module_id = ?");
    $cStmt->execute([$moduleId]);
    $total = (int) $cStmt->fetchColumn();

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
    }

    return array_unique(array_map('intval', $allowedUserIds));
}
