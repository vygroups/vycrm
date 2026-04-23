<?php
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

try {
    switch ($action) {

        /* ════════════════════ MODULE CRUD ════════════════════ */

        case 'list':
            $modules = dm_fetch_all_modules($conn, $prefix);
            commerce_json_response(['success' => true, 'modules' => $modules]);

        case 'list_active':
            $modules = dm_fetch_active_modules($conn, $prefix);
            commerce_json_response(['success' => true, 'modules' => $modules]);

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
                INSERT INTO {$prefix}modules (name, slug, icon, description, sort_order) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$name, $slug, $icon, $desc, $maxSort + 1]);
            $moduleId = (int)$conn->lastInsertId();

            // Auto-create a default block
            $bStmt = $conn->prepare("INSERT INTO {$prefix}module_blocks (module_id, name, sort_order) VALUES (?, 'General Information', 0)");
            $bStmt->execute([$moduleId]);

            commerce_json_response(['success' => true, 'id' => $moduleId, 'slug' => $slug]);

        case 'update':
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new RuntimeException('Module ID required');
            $sets = [];
            $params = [];
            foreach (['name', 'icon', 'description', 'status'] as $col) {
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
                 is_required, is_unique, is_searchable, is_list_visible, sort_order, config) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $blockId, $moduleId, $fieldKey, $label, $fieldType,
                $input['placeholder'] ?? null,
                $input['default_value'] ?? null,
                (int)($input['is_required'] ?? 0),
                (int)($input['is_unique'] ?? 0),
                (int)($input['is_searchable'] ?? 0),
                (int)($input['is_list_visible'] ?? 1),
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
            foreach (['is_required', 'is_unique', 'is_searchable', 'is_list_visible', 'sort_order'] as $col) {
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
            $stmt = $conn->prepare("UPDATE {$prefix}module_fields SET sort_order = ? WHERE id = ?");
            foreach ($orders as $o) {
                $stmt->execute([(int)$o['sort_order'], (int)$o['id']]);
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
                    $stmt->execute([
                        $fieldId,
                        $r['rule_type'] ?? 'conditional',
                        (int)($r['source_field_id'] ?? 0),
                        $r['operator'] ?? 'equals',
                        $r['value'] ?? null,
                        $r['action'] ?? 'show',
                        isset($r['config']) ? json_encode($r['config']) : null,
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

        /* ════════════════════ RECORD CRUD ════════════════════ */

        case 'list_records':
            $moduleId = (int)($input['module_id'] ?? $_GET['module_id'] ?? 0);
            if (!$moduleId) throw new RuntimeException('Module ID required');
            $search = $input['search'] ?? $_GET['search'] ?? null;
            $limit = (int)($input['limit'] ?? $_GET['limit'] ?? 50);
            $offset = (int)($input['offset'] ?? $_GET['offset'] ?? 0);
            $data = dm_fetch_records($conn, $prefix, $moduleId, $search, $limit, $offset);
            commerce_json_response(['success' => true, 'data' => $data]);

        case 'get_record':
            $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
            if (!$id) throw new RuntimeException('Record ID required');
            $record = dm_fetch_record($conn, $prefix, $id);
            if (!$record) throw new RuntimeException('Record not found');
            commerce_json_response(['success' => true, 'record' => $record]);

        case 'save_record':
            $moduleId = (int)($input['module_id'] ?? 0);
            $recordId = (int)($input['record_id'] ?? 0);
            $values = $input['values'] ?? [];

            if (!$moduleId) throw new RuntimeException('Module ID required');

            $conn->beginTransaction();
            try {
                if ($recordId) {
                    // Update existing
                    $conn->prepare("UPDATE {$prefix}module_records SET updated_at = NOW() WHERE id = ?")->execute([$recordId]);
                } else {
                    // Create new
                    $conn->prepare("INSERT INTO {$prefix}module_records (module_id, created_by) VALUES (?, ?)")->execute([$moduleId, $userId]);
                    $recordId = (int)$conn->lastInsertId();
                }

                // Upsert values
                $upsertStmt = $conn->prepare("
                    INSERT INTO {$prefix}module_record_values (record_id, field_id, value)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE value = VALUES(value)
                ");
                foreach ($values as $fieldId => $value) {
                    if (is_array($value)) {
                        $value = json_encode($value);
                    }
                    $upsertStmt->execute([$recordId, (int)$fieldId, $value]);
                }

                $conn->commit();
                commerce_json_response(['success' => true, 'record_id' => $recordId]);
            } catch (Throwable $e) {
                $conn->rollBack();
                throw $e;
            }

        case 'delete_record':
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new RuntimeException('Record ID required');
            $conn->prepare("DELETE FROM {$prefix}module_records WHERE id = ?")->execute([$id]);
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

        /* ════════════════ LOOKUP: Records of another module (for API Call Picker) ════════════════ */

        case 'lookup_records':
            $targetModuleId = (int)($input['target_module_id'] ?? $_GET['target_module_id'] ?? 0);
            $search = $input['search'] ?? $_GET['search'] ?? '';
            if (!$targetModuleId) throw new RuntimeException('Target module ID required');

            // Find first text/name field as display field
            $dfStmt = $conn->prepare("
                SELECT id, label FROM {$prefix}module_fields 
                WHERE module_id = ? AND field_type IN ('text','name','email')
                ORDER BY sort_order ASC LIMIT 1
            ");
            $dfStmt->execute([$targetModuleId]);
            $displayField = $dfStmt->fetch(PDO::FETCH_ASSOC);

            if (!$displayField) {
                commerce_json_response(['success' => true, 'records' => []]);
            }

            $sql = "
                SELECT r.id, rv.value AS display_value
                FROM {$prefix}module_records r
                JOIN {$prefix}module_record_values rv ON rv.record_id = r.id AND rv.field_id = ?
                WHERE r.module_id = ?
            ";
            $params = [(int)$displayField['id'], $targetModuleId];

            if ($search) {
                $sql .= " AND rv.value LIKE ?";
                $params[] = '%' . $search . '%';
            }

            $sql .= " ORDER BY rv.value ASC LIMIT 30";
            $rStmt = $conn->prepare($sql);
            $rStmt->execute($params);
            $results = $rStmt->fetchAll(PDO::FETCH_ASSOC);
            commerce_json_response(['success' => true, 'records' => $results]);

        default:
            throw new RuntimeException("Unknown action: $action");
    }
} catch (Throwable $e) {
    commerce_json_response(['success' => false, 'error' => $e->getMessage()], 400);
}
