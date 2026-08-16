<?php
ob_start();
/**
 * api/modules.php
 * 
 * REST API for the Dynamic Module System.
 * Actions: list, get, create, update, delete, create_block, update_block, delete_block,
 *          create_field, update_field, delete_field, reorder_fields,
 *          save_field_options, save_field_rules,
 *          list_records, get_record, save_record, delete_record,
 *          get_states, get_users, add_dropdown_option
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

dm_ensure_tables($conn, $prefix);

$input = commerce_read_input();
$action = $_GET['action'] ?? $input['action'] ?? '';

$userRole = null;
$isAdmin = false;
try {
    $uStmt = $conn->prepare("SELECT role_id, is_admin FROM {$prefix}users WHERE id = ?");
    $uStmt->execute([$userId]);
    if ($u = $uStmt->fetch(PDO::FETCH_ASSOC)) {
        $userRole = $u['role_id'];
        $isAdmin = (bool)$u['is_admin'];
    }
} catch (Exception $e) {}

// Token-based requests (mobile app) never populate $_SESSION, but record
// visibility (dm_fetch_records) and the upload tenant path below still read
// it directly. Mirror the resolved token context into $_SESSION for this
// request so those session-era helpers see the same identity as $userId/
// $userRole/$isAdmin above instead of silently falling back to 0/false.
if (($context['auth_mode'] ?? '') === 'token') {
    $_SESSION['user_id'] = $userId;
    $_SESSION['role_id'] = $userRole;
    $_SESSION['is_admin'] = $isAdmin ? 1 : 0;
    $_SESSION['tenant_slug'] = $context['tenant_slug'] ?? '';
}

try {
    switch ($action) {

        /* ════════════════════ MODULE CRUD ════════════════════ */

        case 'list':
            $modules = dm_fetch_all_modules($conn, $prefix);
            commerce_json_response(['success' => true, 'modules' => $modules]);

        case 'list_active':
            $modules = dm_fetch_active_modules($conn, $prefix);
            commerce_json_response(['success' => true, 'modules' => $modules]);

        case 'list_active_with_stats':
            $modules = dm_fetch_active_modules($conn, $prefix);
            $modulesWithStats = [];
            foreach ($modules as $m) {
                // Total Count with Role Visibility Filtering
                $totalRes = dm_fetch_records($conn, $prefix, (int)$m['id'], null, 1, 0);
                $total = $totalRes['total'];

                // Today Count with Role Visibility Filtering
                $todayRules = [
                    'condition' => 'AND',
                    'rules' => [
                        [
                            'field' => 'created_at',
                            'operator' => 'between',
                            'value' => date('Y-m-d 00:00:00') . '|' . date('Y-m-d 23:59:59')
                        ]
                    ]
                ];
                $todayRes = dm_fetch_records($conn, $prefix, (int)$m['id'], null, 1, 0, $todayRules);
                $today = $todayRes['total'];

                // Saved Filters
                $filtersStmt = $conn->prepare("SELECT id, name, filter_rules, is_default FROM {$prefix}module_saved_filters WHERE user_id = ? AND module_id = ? ORDER BY name ASC");
                $filtersStmt->execute([$userId, $m['id']]);
                $savedFiltersList = $filtersStmt->fetchAll(PDO::FETCH_ASSOC);

                $moduleFilters = [];
                foreach ($savedFiltersList as $filterRow) {
                    $filterRules = json_decode($filterRow['filter_rules'], true);
                    $filterCount = 0;
                    try {
                        $res = dm_fetch_records($conn, $prefix, $m['id'], null, 1, 0, $filterRules);
                        $filterCount = $res['total'];
                    } catch (Exception $ex) {}
                    
                    $moduleFilters[] = [
                        'id' => (int)$filterRow['id'],
                        'name' => $filterRow['name'],
                        'filter_rules' => $filterRules,
                        'is_default' => (int)$filterRow['is_default'],
                        'count' => $filterCount
                    ];
                }

                $modulesWithStats[] = [
                    'id' => (int)$m['id'],
                    'name' => $m['name'],
                    'slug' => $m['slug'],
                    'icon' => $m['icon'],
                    'description' => $m['description'],
                    'total_records' => $total,
                    'today_records' => $today,
                    'filters' => $moduleFilters
                ];
            }

            // Fetch Dashboard Widgets (Common Settings Filters)
            $widgets = [];
            try {
                $wStmt = $conn->query("SELECT id, module_id, title, rules, color, icon FROM {$prefix}dashboard_widgets ORDER BY sort_order ASC, id ASC");
                $widgetsList = $wStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($widgetsList as $w) {
                    $wRules = json_decode($w['rules'], true);
                    $wCount = 0;
                    try {
                        $res = dm_fetch_records($conn, $prefix, (int)$w['module_id'], null, 1, 0, $wRules);
                        $wCount = $res['total'];
                    } catch (Exception $ex) {}
                    
                    $widgets[] = [
                        'id' => (int)$w['id'],
                        'module_id' => (int)$w['module_id'],
                        'title' => $w['title'],
                        'rules' => $w['rules'],
                        'color' => $w['color'] ?: '#6366F1',
                        'icon' => $w['icon'] ?: 'fa-solid fa-bell',
                        'count' => $wCount
                    ];
                }
            } catch (Exception $e) {}

            // Fetch System Settings
            $attendanceEnabled = dm_get_system_setting($conn, $prefix, 'attendance_enabled', '1') === '1';

            commerce_json_response([
                'success' => true, 
                'modules' => $modulesWithStats,
                'common_filters' => $widgets,
                'attendance_enabled' => $attendanceEnabled
            ]);

        case 'get':
            $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
            if (!$id) throw new RuntimeException('Module ID required');
            $module = dm_fetch_module_full($conn, $prefix, $id);
            if (!$module) throw new RuntimeException('Module not found');
            commerce_json_response(['success' => true, 'module' => $module]);

        case 'create':
            $name = trim($input['name'] ?? '');
            if (!$name) throw new RuntimeException('Module name required');
            $slug = dm_slugify($name);
            $icon = trim($input['icon'] ?? 'fa-solid fa-cube');
            $desc = trim($input['description'] ?? '');

            // Check slug uniqueness
            $chk = $conn->prepare("SELECT id FROM {$prefix}modules WHERE slug = ?");
            $chk->execute([$slug]);
            if ($chk->fetch()) {
                $slug .= '_' . time();
            }

            $maxSort = (int)$conn->query("SELECT COALESCE(MAX(sort_order),0) FROM {$prefix}modules")->fetchColumn();

            $stmt = $conn->prepare("
                INSERT INTO {$prefix}modules (name, slug, icon, description, sort_order, visibility_rule) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$name, $slug, $icon, $desc, $maxSort + 1, $input['visibility_rule'] ?? 'all']);
            $moduleId = (int)$conn->lastInsertId();

            // Auto-create a default block
            $bStmt = $conn->prepare("INSERT INTO {$prefix}module_blocks (module_id, name, sort_order) VALUES (?, 'General Information', 0)");
            $bStmt->execute([$moduleId]);
            $blockId = (int)$conn->lastInsertId();

            // Insert default system fields
            $sysFields = [
                ['Created By', 'sys_created_by', 'created_by_sys', 1, 0],
                ['Created On', 'sys_created_at', 'created_at_sys', 1, 1],
                ['Updated By', 'sys_updated_by', 'updated_by_sys', 0, 2],
                ['Updated On', 'sys_updated_at', 'updated_at_sys', 0, 3],
            ];
            $sfStmt = $conn->prepare("INSERT INTO {$prefix}module_fields (block_id, module_id, label, field_type, field_key, is_list_visible, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($sysFields as $f) {
                $sfStmt->execute([$blockId, $moduleId, $f[0], $f[1], $f[2], $f[3], $f[4]]);
            }

            commerce_json_response(['success' => true, 'id' => $moduleId, 'slug' => $slug]);

        case 'update':
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new RuntimeException('Module ID required');
            $sets = [];
            $params = [];
            foreach (['name', 'icon', 'description', 'status', 'visibility_rule', 'visibility_roles', 'edit_rule', 'edit_roles', 'delete_rule', 'delete_roles', 'enable_import', 'enable_export', 'enable_multidelete', 'enable_create', 'enable_quickcreate'] as $col) {
                if (isset($input[$col])) {
                    $sets[] = "$col = ?";
                    $params[] = $input[$col];
                }
            }
            if (isset($input['sort_order'])) {
                $sets[] = "sort_order = ?";
                $params[] = (int)$input['sort_order'];
            }
            if (!$sets) throw new RuntimeException('Nothing to update');
            $params[] = $id;
            $conn->prepare("UPDATE {$prefix}modules SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
            commerce_json_response(['success' => true]);

        case 'update_system_setting':
            $key = $input['key'] ?? '';
            $value = $input['value'] ?? '';
            if (!$key) throw new RuntimeException('Setting key required');
            dm_set_system_setting($conn, $prefix, $key, $value);
            commerce_json_response(['success' => true]);

        case 'toggle_module_status':
            $id = (int)($input['id'] ?? 0);
            $status = ($input['status'] ?? 'active') === 'active' ? 'active' : 'inactive';
            if (!$id) throw new RuntimeException('Module ID required');
            $conn->prepare("UPDATE {$prefix}modules SET status = ? WHERE id = ?")->execute([$status, $id]);
            commerce_json_response(['success' => true, 'status' => $status]);

        case 'delete':
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new RuntimeException('Module ID required');

            $conn->prepare("DELETE FROM {$prefix}modules WHERE id = ?")->execute([$id]);
            commerce_json_response(['success' => true]);

        /* ════════════════════ BLOCK CRUD ════════════════════ */

        case 'create_block':
            $moduleId = (int)($input['module_id'] ?? 0);
            $name = trim($input['name'] ?? '');
            if (!$moduleId || !$name) throw new RuntimeException('Module ID and block name required');
            $maxSort = (int)$conn->prepare("SELECT COALESCE(MAX(sort_order),0) FROM {$prefix}module_blocks WHERE module_id = ?")->execute([$moduleId]) ? 0 : 0;
            $sStmt = $conn->prepare("SELECT COALESCE(MAX(sort_order),0) FROM {$prefix}module_blocks WHERE module_id = ?");
            $sStmt->execute([$moduleId]);
            $maxSort = (int)$sStmt->fetchColumn();

            $stmt = $conn->prepare("INSERT INTO {$prefix}module_blocks (module_id, name, sort_order) VALUES (?, ?, ?)");
            $stmt->execute([$moduleId, $name, $maxSort + 1]);
            commerce_json_response(['success' => true, 'id' => (int)$conn->lastInsertId()]);

        case 'update_block':
            $id = (int)($input['id'] ?? 0);
            $name = trim($input['name'] ?? '');
            if (!$id || !$name) throw new RuntimeException('Block ID and name required');
            $conn->prepare("UPDATE {$prefix}module_blocks SET name = ? WHERE id = ?")->execute([$name, $id]);
            commerce_json_response(['success' => true]);

        case 'delete_block':
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new RuntimeException('Block ID required');
            $conn->prepare("DELETE FROM {$prefix}module_blocks WHERE id = ?")->execute([$id]);
            commerce_json_response(['success' => true]);

        /* ════════════════════ FIELD CRUD ════════════════════ */

        case 'create_field':
            $blockId = (int)($input['block_id'] ?? 0);
            $moduleId = (int)($input['module_id'] ?? 0);
            $label = trim($input['label'] ?? '');
            $fieldType = trim($input['field_type'] ?? 'text');
            if (!$blockId || !$moduleId || !$label) throw new RuntimeException('Block ID, module ID, and label required');

            $fieldKey = dm_field_key($label);
            // Ensure uniqueness
            $chk = $conn->prepare("SELECT id FROM {$prefix}module_fields WHERE module_id = ? AND field_key = ?");
            $chk->execute([$moduleId, $fieldKey]);
            if ($chk->fetch()) {
                $fieldKey .= '_' . time();
            }

            $sStmt = $conn->prepare("SELECT COALESCE(MAX(sort_order),0) FROM {$prefix}module_fields WHERE block_id = ?");
            $sStmt->execute([$blockId]);
            $maxSort = (int)$sStmt->fetchColumn();

            $config = null;
            if (isset($input['config']) && is_array($input['config'])) {
                $config = json_encode($input['config']);
            }

            $stmt = $conn->prepare("
                INSERT INTO {$prefix}module_fields 
                (block_id, module_id, field_key, label, field_type, placeholder, default_value, 
                 is_required, is_unique, is_searchable, is_list_visible, is_mobile_list_visible, is_quick_create, sort_order, config) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $blockId, $moduleId, $fieldKey, $label, $fieldType,
                $input['placeholder'] ?? null,
                $input['default_value'] ?? null,
                (int)($input['is_required'] ?? 0),
                (int)($input['is_unique'] ?? 0),
                (int)($input['is_searchable'] ?? 0),
                (int)($input['is_list_visible'] ?? 1),
                (int)($input['is_mobile_list_visible'] ?? 0),
                (int)($input['is_quick_create'] ?? 0),
                $maxSort + 1,
                $config,
            ]);
            $fieldId = (int)$conn->lastInsertId();

            // Save options if provided
            if (!empty($input['options']) && is_array($input['options'])) {
                $oStmt = $conn->prepare("INSERT INTO {$prefix}module_field_options (field_id, label, value, sort_order) VALUES (?, ?, ?, ?)");
                foreach ($input['options'] as $i => $opt) {
                    $oStmt->execute([$fieldId, $opt['label'] ?? $opt, $opt['value'] ?? $opt, $i]);
                }
            }

            commerce_json_response(['success' => true, 'id' => $fieldId, 'field_key' => $fieldKey]);

        case 'update_field':
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new RuntimeException('Field ID required');
            $sets = [];
            $params = [];
            foreach (['label', 'field_type', 'placeholder', 'default_value'] as $col) {
                if (isset($input[$col])) {
                    $sets[] = "$col = ?";
                    $params[] = $input[$col];
                }
            }
            foreach (['is_required', 'is_unique', 'is_searchable', 'is_list_visible', 'is_mobile_list_visible', 'is_quick_create', 'sort_order'] as $col) {
                if (isset($input[$col])) {
                    $sets[] = "$col = ?";
                    $params[] = (int)$input[$col];
                }
            }
            if (isset($input['config'])) {
                $sets[] = "config = ?";
                $params[] = is_array($input['config']) ? json_encode($input['config']) : $input['config'];
            }
            if (!$sets) throw new RuntimeException('Nothing to update');
            $params[] = $id;
            $conn->prepare("UPDATE {$prefix}module_fields SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);

            // Update options if provided
            if (isset($input['options']) && is_array($input['options'])) {
                $conn->prepare("DELETE FROM {$prefix}module_field_options WHERE field_id = ?")->execute([$id]);
                $oStmt = $conn->prepare("INSERT INTO {$prefix}module_field_options (field_id, label, value, sort_order) VALUES (?, ?, ?, ?)");
                foreach ($input['options'] as $i => $opt) {
                    $optLabel = is_array($opt) ? ($opt['label'] ?? '') : $opt;
                    $optValue = is_array($opt) ? ($opt['value'] ?? $optLabel) : $opt;
                    $oStmt->execute([$id, $optLabel, $optValue, $i]);
                }
            }

            commerce_json_response(['success' => true]);

        case 'delete_field':
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new RuntimeException('Field ID required');
            $conn->prepare("DELETE FROM {$prefix}module_fields WHERE id = ?")->execute([$id]);
            commerce_json_response(['success' => true]);

        case 'reorder_fields':
            $orders = $input['orders'] ?? [];
            if (!is_array($orders)) throw new RuntimeException('Invalid orders');
            $stmt = $conn->prepare("UPDATE {$prefix}module_fields SET sort_order = ?, block_id = COALESCE(?, block_id) WHERE id = ?");
            foreach ($orders as $o) {
                $blockId = isset($o['block_id']) ? (int)$o['block_id'] : null;
                $stmt->execute([(int)$o['sort_order'], $blockId, (int)$o['id']]);
            }
            commerce_json_response(['success' => true]);

        /* ════════════════ FIELD RULES (dependency / conditional) ════════════════ */

        case 'save_field_rules':
            $fieldId = (int)($input['field_id'] ?? 0);
            if (!$fieldId) throw new RuntimeException('Field ID required');
            $rules = $input['rules'] ?? [];

            $conn->prepare("DELETE FROM {$prefix}module_field_rules WHERE field_id = ?")->execute([$fieldId]);
            if (is_array($rules)) {
                $stmt = $conn->prepare("
                    INSERT INTO {$prefix}module_field_rules (field_id, rule_type, source_field_id, operator, value, action, config)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($rules as $r) {
                    $configObj = isset($r['config']) ? (array)$r['config'] : [];
                    if (isset($r['action_value']) && !isset($configObj['action_value'])) {
                        $configObj['action_value'] = $r['action_value'];
                    }
                    $stmt->execute([
                        $fieldId,
                        $r['rule_type'] ?? 'conditional',
                        (int)($r['source_field_id'] ?? 0),
                        $r['operator'] ?? 'equals',
                        $r['value'] ?? null,
                        $r['action'] ?? 'show',
                        !empty($configObj) ? json_encode($configObj) : null,
                    ]);
                }
            }
            commerce_json_response(['success' => true]);

        /* ════════════════ DYNAMIC DROPDOWN: ADD OPTION ON-THE-FLY ════════════════ */

        case 'add_dropdown_option':
            $fieldId = (int)($input['field_id'] ?? 0);
            $label = trim($input['label'] ?? '');
            if (!$fieldId || !$label) throw new RuntimeException('Field ID and label required');
            $value = trim($input['value'] ?? $label);

            $sStmt = $conn->prepare("SELECT COALESCE(MAX(sort_order),0) FROM {$prefix}module_field_options WHERE field_id = ?");
            $sStmt->execute([$fieldId]);
            $maxSort = (int)$sStmt->fetchColumn();

            $stmt = $conn->prepare("INSERT INTO {$prefix}module_field_options (field_id, label, value, sort_order) VALUES (?, ?, ?, ?)");
            $stmt->execute([$fieldId, $label, $value, $maxSort + 1]);
            commerce_json_response(['success' => true, 'id' => (int)$conn->lastInsertId(), 'label' => $label, 'value' => $value]);

        case 'get_quick_create_fields':
            $mid = (int)($input['module_id'] ?? $_GET['module_id'] ?? 0);
            if (!$mid) throw new RuntimeException('Module ID required');
            
            // Check if quickcreate is enabled for this module
            $mStmt = $conn->prepare("SELECT enable_quickcreate FROM {$prefix}modules WHERE id = ?");
            $mStmt->execute([$mid]);
            $eqc = $mStmt->fetchColumn();
            if ($eqc !== false && (int)$eqc === 0) {
                commerce_json_response(['success' => true, 'fields' => []]);
            }
            
            $stmt = $conn->prepare("SELECT * FROM {$prefix}module_fields WHERE module_id = ? AND is_quick_create = 1 ORDER BY sort_order ASC");
            $stmt->execute([$mid]);
            $qcFields = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Hydrate options
            foreach ($qcFields as &$f) {
                $fid = (int)$f['id'];
                $f['options'] = [];
                if (in_array($f['field_type'], ['dropdown', 'multi_picker', 'radio_group'])) {
                    $oStmt = $conn->prepare("SELECT label, value FROM {$prefix}module_field_options WHERE field_id = ? ORDER BY sort_order ASC");
                    $oStmt->execute([$fid]);
                    $f['options'] = $oStmt->fetchAll(PDO::FETCH_ASSOC);
                }
                if ($f['config']) {
                    $f['config'] = json_decode($f['config'], true);
                }
            }
            unset($f);
            
            commerce_json_response(['success' => true, 'fields' => $qcFields]);

        /* ════════════════════ RECORD CRUD ════════════════════ */

        case 'list_records':
            $moduleId = (int)($input['module_id'] ?? $_GET['module_id'] ?? 0);
            if (!$moduleId) throw new RuntimeException('Module ID required');
            $search = $input['search'] ?? $_GET['search'] ?? null;
            $limit = (int)($input['limit'] ?? $_GET['limit'] ?? 50);
            $offset = (int)($input['offset'] ?? $_GET['offset'] ?? 0);
            $sortBy = $input['sort_by'] ?? $_GET['sort_by'] ?? null;
            $sortOrder = $input['sort_order'] ?? $_GET['sort_order'] ?? 'DESC';
            
            $filterRules = null;
            $filterRulesInput = $input['filter_rules'] ?? $_GET['filter_rules'] ?? null;
            if ($filterRulesInput) {
                $filterRules = is_string($filterRulesInput) ? json_decode($filterRulesInput, true) : $filterRulesInput;
            } else {
                $filterId = (int)($input['filter_id'] ?? $_GET['filter_id'] ?? 0);
                if ($filterId) {
                    $fStmt = $conn->prepare("SELECT filter_rules FROM {$prefix}module_saved_filters WHERE id = ? AND user_id = ?");
                    $fStmt->execute([$filterId, $userId]);
                    $rulesJson = $fStmt->fetchColumn();
                    if ($rulesJson) {
                        $filterRules = json_decode($rulesJson, true);
                    }
                }
            }

            $data = dm_fetch_records($conn, $prefix, $moduleId, $search, $limit, $offset, $filterRules, $sortBy, $sortOrder);
            commerce_json_response(['success' => true, 'data' => $data]);

        case 'get_record':
            $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
            if (!$id) throw new RuntimeException('Record ID required');
            $record = dm_fetch_record($conn, $prefix, $id);
            if (!$record) throw new RuntimeException('Record not found');
            commerce_json_response(['success' => true, 'record' => $record]);

        case 'save_record':
            $moduleId = (int)($input['module_id'] ?? $_POST['module_id'] ?? 0);
            $recordId = (int)($input['record_id'] ?? $_POST['record_id'] ?? 0);
            $valuesJson = $input['values'] ?? $_POST['values'] ?? '{}';
            $values = is_string($valuesJson) ? json_decode($valuesJson, true) : $valuesJson;

            if (!$moduleId) throw new RuntimeException('Module ID required');

            $isNewRecord = false;
            $conn->beginTransaction();
            try {
                // Validate unique fields
                $uniqStmt = $conn->prepare("SELECT id, label FROM {$prefix}module_fields WHERE module_id = ? AND is_unique = 1");
                $uniqStmt->execute([$moduleId]);
                $uniqueFields = $uniqStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($uniqueFields as $uf) {
                    $fid = (int)$uf['id'];
                    $flabel = $uf['label'];
                    
                    if (isset($values[$fid]) && trim((string)$values[$fid]) !== '') {
                        $newVal = trim((string)$values[$fid]);
                        
                        if ($recordId) {
                            $chkStmt = $conn->prepare("
                                SELECT COUNT(*) 
                                FROM {$prefix}module_record_values val
                                JOIN {$prefix}module_records rec ON rec.id = val.record_id
                                WHERE rec.module_id = ? 
                                  AND val.field_id = ? 
                                  AND val.value = ? 
                                  AND rec.id != ?
                            ");
                            $chkStmt->execute([$moduleId, $fid, $newVal, $recordId]);
                        } else {
                            $chkStmt = $conn->prepare("
                                SELECT COUNT(*) 
                                FROM {$prefix}module_record_values val
                                JOIN {$prefix}module_records rec ON rec.id = val.record_id
                                WHERE rec.module_id = ? 
                                  AND val.field_id = ? 
                                  AND val.value = ?
                            ");
                            $chkStmt->execute([$moduleId, $fid, $newVal]);
                        }
                        
                        $dupCount = (int)$chkStmt->fetchColumn();
                        if ($dupCount > 0) {
                            throw new RuntimeException("The value '$newVal' for unique field '$flabel' already exists in another record.");
                        }
                    }
                }

                if ($recordId) {
                    $recStmt = $conn->prepare("SELECT created_by FROM {$prefix}module_records WHERE id = ?");
                    $recStmt->execute([$recordId]);
                    $recordOwnerId = $recStmt->fetchColumn();
                    $moduleInfo = dm_fetch_module_full($conn, $prefix, $moduleId);
                    if (!dm_can_edit_record($conn, $prefix, $moduleInfo, $recordOwnerId, $userId, $userRole, $isAdmin)) {
                        throw new RuntimeException('You do not have permission to edit this record.');
                    }
                    // Update existing
                    $conn->prepare("UPDATE {$prefix}module_records SET updated_at = NOW(), updated_by = ? WHERE id = ?")->execute([$userId, $recordId]);
                } else {
                    // Create new
                    $convModId = !empty($input['converted_from_module_id']) ? (int)$input['converted_from_module_id'] : (!empty($_POST['converted_from_module_id']) ? (int)$_POST['converted_from_module_id'] : null);
                    $convRecId = !empty($input['converted_from_record_id']) ? (int)$input['converted_from_record_id'] : (!empty($_POST['converted_from_record_id']) ? (int)$_POST['converted_from_record_id'] : null);
                    $conn->prepare("INSERT INTO {$prefix}module_records (module_id, created_by, converted_from_module_id, converted_from_record_id) VALUES (?, ?, ?, ?)")->execute([$moduleId, $userId, $convModId, $convRecId]);
                    $recordId = (int)$conn->lastInsertId();
                    $isNewRecord = true;
                }

                // Handle file uploads
                if (!empty($_FILES['attachments'])) {
                    $mStmt = $conn->prepare("SELECT slug FROM {$prefix}modules WHERE id = ?");
                    $mStmt->execute([$moduleId]);
                    $moduleSlug = $mStmt->fetchColumn() ?: 'unknown_module';
                    
                    $tenantSlug = $_SESSION['tenant_slug'] ?? 'default_tenant';
                    
                    // Use UPLOAD_BASE_DIR defined in upload_paths.php
                    require_once __DIR__ . '/../includes/upload_paths.php';
                    $logicalDir = "uploads/{$tenantSlug}/{$moduleSlug}/";
                    $uploadDir = UPLOAD_BASE_DIR . $logicalDir;
                    
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }

                    foreach ($_FILES['attachments']['tmp_name'] as $fieldId => $tmpName) {
                        if ($_FILES['attachments']['error'][$fieldId] === UPLOAD_ERR_OK) {
                            $name = basename($_FILES['attachments']['name'][$fieldId]);
                            $name = preg_replace('/[^a-zA-Z0-9.\-_]/', '', $name);
                            $name = time() . '_' . $name;
                            $dest = $uploadDir . $name;
                            if (move_uploaded_file($tmpName, $dest)) {
                                $values[$fieldId] = $logicalDir . $name;
                            }
                        }
                    }
                }


                // Fetch old values for comparison (only for existing records)
                $oldValues = [];
                if ($recordId > 0) {
                    $oldStmt = $conn->prepare("SELECT field_id, value FROM {$prefix}module_record_values WHERE record_id = ?");
                    $oldStmt->execute([$recordId]);
                    $oldValues = $oldStmt->fetchAll(PDO::FETCH_KEY_PAIR);
                }

                // Upsert values
                $upsertStmt = $conn->prepare("
                    INSERT INTO {$prefix}module_record_values (record_id, field_id, value)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE value = VALUES(value)
                ");

                // Prepare history insert statement
                $historyStmt = $conn->prepare("
                    INSERT INTO {$prefix}module_record_history (record_id, field_id, old_value, new_value, changed_by)
                    VALUES (?, ?, ?, ?, ?)
                ");

                foreach ($values as $fieldId => $newValue) {
                    if (is_array($newValue)) {
                        $newValue = json_encode($newValue);
                    }
                    
                    // Audit change if it is an update
                    if ($recordId > 0 && !$isNewRecord) {
                        $oldVal = isset($oldValues[$fieldId]) ? $oldValues[$fieldId] : '';
                        $normOld = trim((string)$oldVal);
                        $normNew = trim((string)$newValue);
                        
                        if ($normOld !== $normNew) {
                            $historyStmt->execute([$recordId, (int)$fieldId, $normOld, $normNew, $userId]);
                        }
                    }

                    $upsertStmt->execute([$recordId, (int)$fieldId, $newValue]);
                }

                $conn->commit();
                
                // Trigger automation workflows
                try {
                    dm_trigger_workflows($conn, $prefix, $moduleId, $recordId, $oldValues, $values);
                } catch (Throwable $wfEx) {
                    error_log("Workflow automation failed for record $recordId: " . $wfEx->getMessage());
                }

                // Sync date/fields to linked parent module (e.g. Clients -> Companies)
                try {
                    dm_sync_linked_parent_records($conn, $prefix, $moduleId, $recordId, $values);
                } catch (Throwable $syncEx) {
                    error_log("Cross-module date sync failed for record $recordId: " . $syncEx->getMessage());
                }

                commerce_json_response(['success' => true, 'record_id' => $recordId]);
            } catch (Throwable $e) {
                $conn->rollBack();
                throw $e;
            }

        case 'delete_record':
            $ids = isset($input['ids']) ? (array)$input['ids'] : [];
            if (empty($ids) && isset($input['id'])) {
                $ids = [(int)$input['id']];
            }
            if (empty($ids) && !empty($input['all_pages']) && !empty($input['module_id'])) {
                $mId = (int)$input['module_id'];
                $sSearch = $input['search'] ?? null;
                $fRules = $input['filter_rules'] ?? null;
                $fId = (int)($input['filter_id'] ?? 0);
                if (!$fRules && $fId) {
                    $sfStmt = $conn->prepare("SELECT filter_rules FROM {$prefix}module_saved_filters WHERE id = ? AND user_id = ?");
                    $sfStmt->execute([$fId, $userId]);
                    $rulesJson = $sfStmt->fetchColumn();
                    if ($rulesJson) $fRules = json_decode($rulesJson, true);
                }
                $allRecs = dm_fetch_records($conn, $prefix, $mId, $sSearch, 100000, 0, $fRules);
                $ids = array_column($allRecs['records'], 'id');
            }
            if (empty($ids)) throw new RuntimeException('Record ID(s) required');

            $conn->beginTransaction();
            try {
                foreach ($ids as $id) {
                    $id = (int)$id;
                    $recStmt = $conn->prepare("SELECT module_id, created_by FROM {$prefix}module_records WHERE id = ?");
                    $recStmt->execute([$id]);
                    $recInfo = $recStmt->fetch(PDO::FETCH_ASSOC);
                    if ($recInfo) {
                        $moduleInfo = dm_fetch_module_full($conn, $prefix, $recInfo['module_id']);
                        if (!dm_can_delete_record($conn, $prefix, $moduleInfo, $recInfo['created_by'], $userId, $userRole, $isAdmin)) {
                            throw new RuntimeException("You do not have permission to delete record #$id.");
                        }
                    }
                    $conn->prepare("DELETE FROM {$prefix}module_records WHERE id = ?")->execute([$id]);
                }
                $conn->commit();
                commerce_json_response(['success' => true]);
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                throw $e;
            }

        case 'bulk_edit_records':
            $moduleId = (int)($input['module_id'] ?? $_POST['module_id'] ?? 0);
            $fieldId = (int)($input['field_id'] ?? $_POST['field_id'] ?? 0);
            $operation = trim($input['operation'] ?? $_POST['operation'] ?? 'set_value');
            $value = trim((string)($input['value'] ?? $_POST['value'] ?? ''));
            $recordIds = isset($input['record_ids']) ? (array)$input['record_ids'] : [];
            $allSelected = !empty($input['all_selected']) || !empty($_POST['all_selected']);
            $search = trim($input['search'] ?? $_POST['search'] ?? '');

            if (!$moduleId) throw new RuntimeException('Module ID required');
            if (!$fieldId) throw new RuntimeException('Field ID required');

            // Resolve target record IDs if all_selected is true
            if ($allSelected) {
                $fRules = $input['filter_rules'] ?? null;
                $fId = (int)($input['filter_id'] ?? 0);
                if (!$fRules && $fId) {
                    $sfStmt = $conn->prepare("SELECT filter_rules FROM {$prefix}module_saved_filters WHERE id = ? AND user_id = ?");
                    $sfStmt->execute([$fId, $userId]);
                    $rulesJson = $sfStmt->fetchColumn();
                    if ($rulesJson) $fRules = json_decode($rulesJson, true);
                }
                $allRecs = dm_fetch_records($conn, $prefix, $moduleId, $search, 100000, 0, $fRules);
                $recordIds = array_column($allRecs['records'] ?? [], 'id');
            }

            if (empty($recordIds)) {
                throw new RuntimeException('No records selected for bulk edit.');
            }

            $recordIds = array_values(array_unique(array_map('intval', $recordIds)));
            $chunks = array_chunk($recordIds, 500);

            $conn->beginTransaction();
            try {
                $affectedCount = 0;
                
                foreach ($chunks as $chunk) {
                    $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                    
                    if ($operation === 'clear_value') {
                        $delParams = array_merge([(int)$fieldId], $chunk);
                        $delStmt = $conn->prepare("DELETE FROM {$prefix}module_record_values WHERE field_id = ? AND record_id IN ({$placeholders})");
                        $delStmt->execute($delParams);
                        $affectedCount += count($chunk);
                    } else if ($operation === 'remove_specific_text') {
                        if ($value === '') {
                            throw new RuntimeException('Value to remove cannot be empty.');
                        }
                        $updParams = array_merge([$value, (int)$fieldId], $chunk);
                        $updStmt = $conn->prepare("UPDATE {$prefix}module_record_values SET value = REPLACE(value, ?, '') WHERE field_id = ? AND record_id IN ({$placeholders})");
                        $updStmt->execute($updParams);
                        
                        $cleanParams = array_merge([(int)$fieldId], $chunk);
                        $conn->prepare("DELETE FROM {$prefix}module_record_values WHERE field_id = ? AND record_id IN ({$placeholders}) AND TRIM(value) = ''")->execute($cleanParams);
                        $affectedCount += count($chunk);
                    } else {
                        // set_value
                        $upsertStmt = $conn->prepare("
                            INSERT INTO {$prefix}module_record_values (record_id, field_id, value)
                            VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE value = VALUES(value)
                        ");
                        foreach ($chunk as $rId) {
                            $upsertStmt->execute([(int)$rId, (int)$fieldId, $value]);
                        }
                        $affectedCount += count($chunk);
                    }

                    // Touch updated_at for chunk records
                    $conn->prepare("UPDATE {$prefix}module_records SET updated_at = NOW(), updated_by = ? WHERE id IN ({$placeholders})")->execute(array_merge([$userId], $chunk));
                }

                $conn->commit();
                commerce_json_response(['success' => true, 'updated_count' => $affectedCount]);
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                throw $e;
            }
            break;

        case 'duplicate_record':
            $id = (int)($input['id'] ?? 0);
            $moduleId = (int)($input['module_id'] ?? 0);
            if (!$id || !$moduleId) throw new RuntimeException('Record ID and Module ID required');

            $conn->beginTransaction();
            try {
                // Fetch original record
                $stmt = $conn->prepare("SELECT * FROM {$prefix}module_records WHERE id = ?");
                $stmt->execute([$id]);
                $orig = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$orig) throw new RuntimeException('Original record not found');

                // Insert new record
                $conn->prepare("INSERT INTO {$prefix}module_records (module_id, created_by) VALUES (?, ?)")->execute([$moduleId, $userId]);
                $newId = (int)$conn->lastInsertId();

                // Fetch original values
                $valStmt = $conn->prepare("SELECT field_id, value FROM {$prefix}module_record_values WHERE record_id = ?");
                $valStmt->execute([$id]);
                $values = $valStmt->fetchAll(PDO::FETCH_ASSOC);

                // Insert new values
                if (!empty($values)) {
                    $upsertStmt = $conn->prepare("INSERT INTO {$prefix}module_record_values (record_id, field_id, value) VALUES (?, ?, ?)");
                    foreach ($values as $val) {
                        $upsertStmt->execute([$newId, $val['field_id'], $val['value']]);
                    }
                }

                $conn->commit();
                commerce_json_response(['success' => true, 'new_record_id' => $newId]);
            } catch (Throwable $e) {
                $conn->rollBack();
                throw $e;
            }

        /* ════════════════ WORKFLOW AUTOMATION ACTIONS ════════════════ */
        case 'list_workflows':
            $wfModuleId = (int)($input['module_id'] ?? $_GET['module_id'] ?? 0);
            if (!$wfModuleId) throw new RuntimeException('Module ID is required');
            
            $stmt = $conn->prepare("
                SELECT w.*, f.label as trigger_field_label, rf.label as recipient_field_label 
                FROM {$prefix}module_workflows w
                LEFT JOIN {$prefix}module_fields f ON f.id = w.trigger_field_id
                LEFT JOIN {$prefix}module_fields rf ON rf.id = w.recipient_field_id
                WHERE w.module_id = ?
                ORDER BY w.created_at DESC
            ");
            $stmt->execute([$wfModuleId]);
            $workflows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            commerce_json_response(['success' => true, 'workflows' => $workflows]);

        case 'save_workflow':
            $wfId = (int)($input['id'] ?? 0);
            $wfModuleId = (int)($input['module_id'] ?? 0);
            $wfName = trim($input['name'] ?? '');
            
            $triggerEvent = $input['trigger_event'] ?? 'create_or_edit';
            if (!in_array($triggerEvent, ['create', 'edit', 'create_or_edit'])) {
                $triggerEvent = 'create_or_edit';
            }
            
            $conditionType = $input['condition_type'] ?? 'field_value';
            if (!in_array($conditionType, ['always', 'field_value', 'field_changed'])) {
                $conditionType = 'field_value';
            }
            
            $triggerFieldId = ($conditionType !== 'always' && !empty($input['trigger_field_id'])) ? (int)$input['trigger_field_id'] : null;
            $triggerValue = ($conditionType === 'field_value' && isset($input['trigger_value'])) ? trim((string)$input['trigger_value']) : null;
            
            $actionType = $input['action_type'] ?? 'email';
            $recipientFieldId = !empty($input['recipient_field_id']) ? (int)$input['recipient_field_id'] : null;
            $recipientCustom = !empty($input['recipient_custom']) ? trim($input['recipient_custom']) : null;
            $templateSubject = !empty($input['template_subject']) ? trim($input['template_subject']) : null;
            $templateBody = !empty($input['template_body']) ? trim($input['template_body']) : null;
            $commConfigId = !empty($input['communication_config_id']) ? (int)$input['communication_config_id'] : null;
            $wfStatus = $input['status'] ?? 'active';

            if (!$wfModuleId) throw new RuntimeException('Module ID is required');
            if (empty($wfName)) throw new RuntimeException('Rule name is required');
            if (empty($wfStatus)) $wfStatus = 'active';

            if ($wfId > 0) {
                $stmt = $conn->prepare("
                    UPDATE {$prefix}module_workflows 
                    SET name = ?, trigger_event = ?, condition_type = ?, 
                        trigger_field_id = ?, trigger_value = ?, action_type = ?, 
                        recipient_field_id = ?, recipient_custom = ?, template_subject = ?, 
                        template_body = ?, communication_config_id = ?, status = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $wfName, $triggerEvent, $conditionType,
                    $triggerFieldId, $triggerValue, $actionType,
                    $recipientFieldId, $recipientCustom, $templateSubject,
                    $templateBody, $commConfigId, $wfStatus, $wfId
                ]);
                $savedId = $wfId;
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO {$prefix}module_workflows 
                    (module_id, name, trigger_event, condition_type, 
                     trigger_field_id, trigger_value, action_type, 
                     recipient_field_id, recipient_custom, template_subject, template_body, communication_config_id, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $wfModuleId, $wfName, $triggerEvent, $conditionType,
                    $triggerFieldId, $triggerValue, $actionType,
                    $recipientFieldId, $recipientCustom, $templateSubject, $templateBody, $commConfigId, $wfStatus
                ]);
                $savedId = $conn->lastInsertId();
            }
            commerce_json_response(['success' => true, 'id' => (int)$savedId]);

        case 'delete_workflow':
            $wfId = (int)($input['id'] ?? 0);
            if (!$wfId) throw new RuntimeException('Workflow ID required');
            $stmt = $conn->prepare("DELETE FROM {$prefix}module_workflows WHERE id = ?");
            $stmt->execute([$wfId]);
            commerce_json_response(['success' => true]);

        case 'list_conversion_rules':
            $srcModuleId = (int)($input['source_module_id'] ?? $_GET['source_module_id'] ?? 0);
            if (!$srcModuleId) throw new RuntimeException('Source module ID required');
            $stmt = $conn->prepare("
                SELECT c.*, m.name as target_module_name 
                FROM {$prefix}module_conversion_rules c
                JOIN {$prefix}modules m ON m.id = c.target_module_id
                WHERE c.source_module_id = ?
                ORDER BY c.created_at DESC
            ");
            $stmt->execute([$srcModuleId]);
            $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
            commerce_json_response(['success' => true, 'rules' => $rules]);

        case 'save_conversion_rule':
            $id = (int)($input['id'] ?? 0);
            $srcModuleId = (int)($input['source_module_id'] ?? 0);
            $targetModuleId = (int)($input['target_module_id'] ?? 0);
            $buttonLabel = trim($input['button_label'] ?? '');
            $mappings = $input['field_mappings'] ?? [];
            $status = $input['status'] ?? 'active';

            if (!$srcModuleId) throw new RuntimeException('Source module ID required');
            if (!$targetModuleId) throw new RuntimeException('Target module ID required');
            if (empty($buttonLabel)) throw new RuntimeException('Button label is required');

            $mappingsJson = json_encode($mappings);

            if ($id > 0) {
                $stmt = $conn->prepare("
                    UPDATE {$prefix}module_conversion_rules 
                    SET target_module_id = ?, button_label = ?, field_mappings = ?, status = ?
                    WHERE id = ? AND source_module_id = ?
                ");
                $stmt->execute([$targetModuleId, $buttonLabel, $mappingsJson, $status, $id, $srcModuleId]);
                $savedId = $id;
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO {$prefix}module_conversion_rules 
                    (source_module_id, target_module_id, button_label, field_mappings, status)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$srcModuleId, $targetModuleId, $buttonLabel, $mappingsJson, $status]);
                $savedId = $conn->lastInsertId();
            }
            commerce_json_response(['success' => true, 'id' => (int)$savedId]);

        case 'delete_conversion_rule':
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new RuntimeException('Rule ID required');
            $stmt = $conn->prepare("DELETE FROM {$prefix}module_conversion_rules WHERE id = ?");
            $stmt->execute([$id]);
            commerce_json_response(['success' => true]);

        case 'list_workflow_logs':
            $wfModuleId = (int)($input['module_id'] ?? $_GET['module_id'] ?? 0);
            if (!$wfModuleId) throw new RuntimeException('Module ID is required');

            $stmt = $conn->prepare("
                SELECT l.*, w.name as workflow_name
                FROM {$prefix}workflow_logs l
                JOIN {$prefix}module_workflows w ON w.id = l.workflow_id
                WHERE w.module_id = ?
                ORDER BY l.sent_at DESC
                LIMIT 100
            ");
            $stmt->execute([$wfModuleId]);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            commerce_json_response(['success' => true, 'logs' => $logs]);

        case 'save_integration_settings':
            $smtpSettings = [
                'smtp_host' => trim($input['smtp_host'] ?? ''),
                'smtp_port' => trim($input['smtp_port'] ?? ''),
                'smtp_user' => trim($input['smtp_user'] ?? ''),
                'smtp_pass' => trim($input['smtp_pass'] ?? ''),
                'smtp_from_email' => trim($input['smtp_from_email'] ?? ''),
                'smtp_from_name' => trim($input['smtp_from_name'] ?? ''),
                'smtp_encryption' => trim($input['smtp_encryption'] ?? 'none'),
                'whatsapp_api_url' => trim($input['whatsapp_api_url'] ?? ''),
                'whatsapp_access_token' => trim($input['whatsapp_access_token'] ?? ''),
            ];
            foreach ($smtpSettings as $key => $val) {
                dm_set_system_setting($conn, $prefix, $key, $val);
            }
            commerce_json_response(['success' => true]);

        /* ════════════════════ UTILITY ════════════════════ */

        case 'get_states':
            $country = $input['country'] ?? $_GET['country'] ?? '';
            $states = dm_get_states();
            commerce_json_response(['success' => true, 'states' => $states[$country] ?? []]);

        case 'get_users':
            $users = dm_fetch_users($conn, $prefix);
            commerce_json_response(['success' => true, 'users' => $users]);

        case 'get_field_types':
            commerce_json_response(['success' => true, 'types' => dm_field_types()]);

        case 'get_record_history':
            $recId = (int)($input['record_id'] ?? $_GET['record_id'] ?? 0);
            $fieldId = (int)($input['field_id'] ?? $_GET['field_id'] ?? 0);
            if (!$recId) throw new RuntimeException('Record ID required');

            $sql = "
                SELECT h.*, u.username, u.first_name, u.last_name, f.label as field_label, f.field_type, f.config as field_config
                FROM {$prefix}module_record_history h
                LEFT JOIN users u ON u.id = h.changed_by
                LEFT JOIN {$prefix}module_fields f ON f.id = h.field_id
                JOIN {$prefix}module_records r ON r.id = h.record_id
                WHERE h.record_id = ?
                  AND ABS(TIMESTAMPDIFF(SECOND, h.changed_at, r.created_at)) > 2
            ";
            $params = [$recId];
            if ($fieldId) {
                $sql .= " AND h.field_id = ?";
                $params[] = $fieldId;
            }
            $sql .= " ORDER BY h.changed_at DESC";

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Resolve api_call_picker values (record IDs -> display names)
            $resolveCache = [];
            foreach ($history as &$row) {
                $displayName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                $row['user_display'] = $displayName ?: ($row['username'] ?? 'System/Unknown');
                $row['date_display'] = date('Y-m-d H:i:s', strtotime($row['changed_at']));

                // For api_call_picker fields, resolve IDs to display names
                if (($row['field_type'] ?? '') === 'api_call_picker') {
                    $fConfig = json_decode($row['field_config'] ?: '{}', true);
                    $linkedModId = (int)($fConfig['linked_module_id'] ?? 0);
                    if ($linkedModId) {
                        foreach (['old_value', 'new_value'] as $valKey) {
                            $recIdVal = trim((string)($row[$valKey] ?? ''));
                            if ($recIdVal !== '' && is_numeric($recIdVal)) {
                                $cacheKey = $linkedModId . '_' . $recIdVal;
                                if (!isset($resolveCache[$cacheKey])) {
                                    // Find the title field of the linked module
                                    $dfStmt = $conn->prepare("SELECT id, field_type, config FROM {$prefix}module_fields WHERE module_id = ? ORDER BY sort_order ASC");
                                    $dfStmt->execute([$linkedModId]);
                                    $allLinkedFields = $dfStmt->fetchAll(PDO::FETCH_ASSOC);
                                    $displayFieldId = null;
                                    $fallbackFieldId = null;
                                    foreach ($allLinkedFields as $lf) {
                                        $lfConf = json_decode($lf['config'] ?: '{}', true);
                                        if (!empty($lfConf['is_title'])) { $displayFieldId = $lf['id']; break; }
                                        if (!$fallbackFieldId && in_array($lf['field_type'], ['text','name','email'])) { $fallbackFieldId = $lf['id']; }
                                    }
                                    if (!$displayFieldId) $displayFieldId = $fallbackFieldId;
                                    if ($displayFieldId) {
                                        $rvStmt = $conn->prepare("SELECT value FROM {$prefix}module_record_values WHERE record_id = ? AND field_id = ?");
                                        $rvStmt->execute([(int)$recIdVal, $displayFieldId]);
                                        $resolvedName = $rvStmt->fetchColumn();
                                        $resolveCache[$cacheKey] = $resolvedName ? trim($resolvedName) : "(Record #$recIdVal)";
                                    } else {
                                        $resolveCache[$cacheKey] = "Record #$recIdVal";
                                    }
                                }
                                $row[$valKey] = $resolveCache[$cacheKey];
                            }
                        }
                    }
                }
            }
            unset($row);

            commerce_json_response(['success' => true, 'history' => $history]);

        /* ════════════════ SAVED FILTERS ACTIONS ════════════════ */

        case 'save_filter':
            $filterModuleId = (int)($input['module_id'] ?? 0);
            $filterName = trim($input['name'] ?? '');
            $rules = $input['filter_rules'] ?? []; // Array/object of filter rules
            $isDefault = !empty($input['is_default']) ? 1 : 0;
            $filterId = (int)($input['id'] ?? 0);

            if (!$filterModuleId) throw new RuntimeException('Module ID is required');
            if (empty($filterName)) throw new RuntimeException('Filter name is required');

            $rulesJson = json_encode($rules);

            if ($isDefault) {
                // Remove default flag from all other filters of this user and module
                $stmt = $conn->prepare("UPDATE {$prefix}module_saved_filters SET is_default = 0 WHERE user_id = ? AND module_id = ?");
                $stmt->execute([$userId, $filterModuleId]);
            }

            if ($filterId > 0) {
                // Update existing
                $stmt = $conn->prepare("
                    UPDATE {$prefix}module_saved_filters 
                    SET name = ?, filter_rules = ?, is_default = ? 
                    WHERE id = ? AND user_id = ?
                ");
                $stmt->execute([$filterName, $rulesJson, $isDefault, $filterId, $userId]);
                $savedId = $filterId;
            } else {
                // Insert new
                $stmt = $conn->prepare("
                    INSERT INTO {$prefix}module_saved_filters (user_id, module_id, name, filter_rules, is_default)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$userId, $filterModuleId, $filterName, $rulesJson, $isDefault]);
                $savedId = $conn->lastInsertId();
            }

            commerce_json_response(['success' => true, 'id' => (int)$savedId, 'message' => 'Filter saved successfully']);

        case 'list_filters':
            $filterModuleId = (int)($input['module_id'] ?? $_GET['module_id'] ?? 0);
            if (!$filterModuleId) throw new RuntimeException('Module ID is required');

            $stmt = $conn->prepare("
                SELECT id, name, filter_rules, is_default 
                FROM {$prefix}module_saved_filters 
                WHERE user_id = ? AND module_id = ? 
                ORDER BY name ASC
            ");
            $stmt->execute([$userId, $filterModuleId]);
            $filters = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Decode filter rules JSON for response
            foreach ($filters as &$f) {
                $f['filter_rules'] = json_decode($f['filter_rules'], true) ?: [];
                $f['is_default'] = (int)$f['is_default'];
            }
            unset($f);

            commerce_json_response(['success' => true, 'filters' => $filters]);

        case 'delete_filter':
            $filterId = (int)($input['id'] ?? 0);
            if (!$filterId) throw new RuntimeException('Filter ID is required');

            $stmt = $conn->prepare("DELETE FROM {$prefix}module_saved_filters WHERE id = ? AND user_id = ?");
            $stmt->execute([$filterId, $userId]);

            commerce_json_response(['success' => true, 'message' => 'Filter deleted successfully']);

        /* ════════════════ LOOKUP: Records of another module (for API Call Picker) ════════════════ */

        case 'lookup_records':
            $targetModuleId = (int)($input['target_module_id'] ?? $_GET['target_module_id'] ?? 0);
            $search = $input['search'] ?? $_GET['search'] ?? '';
            $page = max(1, (int)($input['page'] ?? $_GET['page'] ?? 1));
            $limit = 10;
            $offset = ($page - 1) * $limit;

            if (!$targetModuleId) throw new RuntimeException('Target module ID required');

            // Find first field marked as is_title in config, else fallback to text/name/email field
            $dfStmt = $conn->prepare("
                SELECT id, label, field_type, config FROM {$prefix}module_fields 
                WHERE module_id = ?
                ORDER BY sort_order ASC
            ");
            $dfStmt->execute([$targetModuleId]);
            $allFields = $dfStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $displayField = null;
            $fallbackField = null;
            foreach ($allFields as $f) {
                $fConf = json_decode($f['config'] ?: '{}', true);
                if (!empty($fConf['is_title'])) {
                    $displayField = $f;
                    break;
                }
                if (!$fallbackField && in_array($f['field_type'], ['text', 'name', 'email'])) {
                    $fallbackField = $f;
                }
            }
            if (!$displayField) {
                $displayField = $fallbackField;
            }

            if (!$displayField) {
                commerce_json_response(['success' => true, 'records' => [], 'total' => 0, 'page' => 1, 'limit' => $limit]);
            }

            $baseSql = "
                FROM {$prefix}module_records r
                LEFT JOIN {$prefix}module_record_values rv ON rv.record_id = r.id AND rv.field_id = ?
                WHERE r.module_id = ?
            ";
            $params = [(int)$displayField['id'], $targetModuleId];

            if ($search) {
                $baseSql .= " AND (rv.value LIKE ? OR r.id = ?)";
                $params[] = '%' . $search . '%';
                $params[] = (int)$search;
            }

            // Get total count for pagination
            $countStmt = $conn->prepare("SELECT COUNT(*) " . $baseSql);
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            // Get paginated records
            $sql = "SELECT r.id, COALESCE(NULLIF(TRIM(rv.value), ''), '(Empty)') AS display_value " . $baseSql . " ORDER BY r.id DESC LIMIT $limit OFFSET $offset";
            $rStmt = $conn->prepare($sql);
            $rStmt->execute($params);
            $results = $rStmt->fetchAll(PDO::FETCH_ASSOC);
            
            commerce_json_response(['success' => true, 'records' => $results, 'total' => $total, 'page' => $page, 'limit' => $limit]);

        case 'get_module_fields':
            $moduleId = (int)($_GET['module_id'] ?? $input['module_id'] ?? 0);
            if (!$moduleId) throw new RuntimeException('Module ID required');
            
            $stmt = $conn->prepare("SELECT id, label, field_type FROM {$prefix}module_fields WHERE module_id = ? ORDER BY sort_order ASC");
            $stmt->execute([$moduleId]);
            $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Add system fields
            $fields[] = ['id' => 'created_at', 'label' => 'Created Date', 'field_type' => 'datetime'];
            $fields[] = ['id' => 'updated_at', 'label' => 'Updated Date', 'field_type' => 'datetime'];
            
            commerce_json_response(['success' => true, 'fields' => $fields]);

        case 'list_dashboard_widgets':
            $stmt = $conn->query("
                SELECT w.*, m.name as module_name 
                FROM {$prefix}dashboard_widgets w
                LEFT JOIN {$prefix}modules m ON m.id = w.module_id
                ORDER BY w.sort_order ASC, w.id ASC
            ");
            $widgets = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($widgets as &$w) {
                if (in_array($w['field_id'], ['created_at', 'updated_at'])) {
                    $w['field_label'] = $w['field_id'] === 'created_at' ? 'Created Date' : 'Updated Date';
                } else {
                    $fStmt = $conn->prepare("SELECT label FROM {$prefix}module_fields WHERE id = ?");
                    $fStmt->execute([(int)$w['field_id']]);
                    $w['field_label'] = $fStmt->fetchColumn() ?: 'Unknown';
                }
            }
            unset($w);
            commerce_json_response(['success' => true, 'widgets' => $widgets]);

        case 'save_dashboard_widget':
            $wId = (int)($input['id'] ?? 0);
            $title = trim($input['title'] ?? '');
            $moduleId = (int)($input['module_id'] ?? 0);
            $fieldId = trim($input['field_id'] ?? '');
            $operator = trim($input['operator'] ?? '=');
            $value = trim($input['value'] ?? '');
            $icon = trim($input['icon'] ?? 'fa-solid fa-bell');
            $color = trim($input['color'] ?? 'var(--primary)');
            
            if (!$title) throw new RuntimeException('Widget Title is required');
            if (!$moduleId) throw new RuntimeException('Target Module is required');
            if (!$fieldId) throw new RuntimeException('Filter Field is required');
            
            // Construct rule array
            $rules = [
                [
                    'field_id' => $fieldId,
                    'operator' => $operator,
                    'value' => $value
                ]
            ];
            $rulesJson = json_encode($rules);
            
            if ($wId > 0) {
                $stmt = $conn->prepare("UPDATE {$prefix}dashboard_widgets SET title = ?, module_id = ?, field_id = ?, operator_type = ?, rules = ?, icon = ?, color = ? WHERE id = ?");
                $stmt->execute([$title, $moduleId, $fieldId, $operator, $rulesJson, $icon, $color, $wId]);
            } else {
                $maxStmt = $conn->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM {$prefix}dashboard_widgets");
                $sort = (int)$maxStmt->fetchColumn();
                $stmt = $conn->prepare("INSERT INTO {$prefix}dashboard_widgets (title, module_id, field_id, operator_type, rules, icon, color, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$title, $moduleId, $fieldId, $operator, $rulesJson, $icon, $color, $sort]);
            }
            commerce_json_response(['success' => true]);

        case 'delete_dashboard_widget':
            $wId = (int)($input['id'] ?? 0);
            if (!$wId) throw new RuntimeException('Widget ID required');
            $stmt = $conn->prepare("DELETE FROM {$prefix}dashboard_widgets WHERE id = ?");
            $stmt->execute([$wId]);
            commerce_json_response(['success' => true]);

        default:
            throw new RuntimeException("Unknown action: $action");
    }
} catch (Throwable $e) {
    commerce_json_response(['success' => false, 'error' => $e->getMessage()], 400);
}
