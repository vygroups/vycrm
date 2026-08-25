<?php
require_once 'auth_check.php';
if (empty($_SESSION['is_admin'])) {
    header('Location: dashboard.php');
    exit;
}
require_once 'includes/commerce.php';
require_once 'includes/brand.php';
require_once 'includes/dynamic_modules.php';
require_once 'includes/calls_helper.php';

$context = commerce_get_tenant_context();
$conn = $context['conn'];
$prefix = $context['prefix'];
dm_ensure_tables($conn, $prefix);
commerce_ensure_tables($conn, $prefix);
calls_ensure_tables($conn, $prefix);

$callsEnabled = dm_get_system_setting($conn, $prefix, 'calls_enabled', '1') === '1';
if ($callsEnabled) {
    // If enabled, ensure the dynamic module exists and is active
    $checkMod = $conn->prepare("SELECT id, status FROM {$prefix}modules WHERE slug = 'calls' LIMIT 1");
    $checkMod->execute();
    $existingCallsMod = $checkMod->fetch(PDO::FETCH_ASSOC);
    if (!$existingCallsMod) {
        calls_ensure_dynamic_module($conn, $prefix);
    } elseif ($existingCallsMod['status'] === 'inactive') {
        $conn->prepare("UPDATE {$prefix}modules SET status = 'active' WHERE id = ?")->execute([$existingCallsMod['id']]);
    }
}

$fieldTypes = dm_field_types();
$countries = dm_get_countries();
$allModules = dm_fetch_all_modules($conn, $prefix);

// Fetch all campaign mappings already configured
$configuredMappings = [];
try {
    foreach ($allModules as $m) {
        $mappingKey = 'campaign_mapping_' . $m['id'];
        $val = dm_get_system_setting($conn, $prefix, $mappingKey, null);
        if ($val) {
            $configuredMappings[$m['id']] = [
                'id' => $m['id'],
                'name' => $m['name'],
                'mapping' => json_decode($val, true)
            ];
        }
    }
} catch (Exception $e) {}

// If editing a module
$editModule = null;
if (!empty($_GET['edit'])) {
    $editModule = dm_fetch_module_full($conn, $prefix, (int) $_GET['edit']);
}

$jsFields = [];
$usersList = [];
if ($editModule) {
    foreach ($editModule['blocks'] as $b) {
        foreach ($b['fields'] as $f) {
            $jsFields[] = [
                'id' => (int)$f['id'],
                'label' => $f['label'],
                'field_key' => $f['field_key'],
                'field_type' => $f['field_type'],
                'options' => $f['options'] ?? [],
                'config' => isset($f['config']) ? (is_array($f['config']) ? $f['config'] : json_decode($f['config'], true)) : null
            ];
        }
    }
}

try {
    $rawUsers = dm_fetch_users($conn, $prefix);
    foreach ($rawUsers as $u) {
        $fullName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
        $roleName = !empty($u['role_name']) ? " (" . $u['role_name'] . ")" : "";
        $displayName = $fullName ? "$fullName ({$u['username']})$roleName" : "{$u['username']}$roleName";
        $usersList[] = [
            'id' => (int)$u['id'],
            'username' => $u['username'],
            'name' => $displayName
        ];
    }
} catch (Exception $e) {}

$rolesList = [];
try {
    $rolesQuery = $conn->query("SELECT id, name FROM {$prefix}roles ORDER BY name ASC");
    while ($r = $rolesQuery->fetch(PDO::FETCH_ASSOC)) {
        $rolesList[] = $r;
    }
} catch (Exception $e) {}

$commConfigs = [];
try {
    $stmt = $conn->query("SELECT id, name, type, is_default FROM {$prefix}communication_configs ORDER BY type ASC, name ASC");
    $commConfigs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}


?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(brand_page_title('Module Manager')) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <link href="/assets/css/module_manager.css?v=<?= $v ?>" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="/assets/js/toast.js?v=<?= $v ?>"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js"></script>
    <style>
        .mm-icon-picker-selected {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            cursor: pointer;
            user-select: none;
        }

        .mm-icon-picker-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(40px, 1fr));
            gap: 8px;
            padding: 12px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            margin-top: 8px;
            max-height: 200px;
            overflow-y: auto;
        }

        .mm-icon-option {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            cursor: pointer;
            color: var(--text-muted);
            font-size: 16px;
            transition: all 0.2s;
        }

        .mm-icon-option:hover {
            background: rgba(123, 94, 240, 0.1);
            color: var(--primary);
        }

        .mm-icon-option.active {
            background: var(--primary);
            color: #fff;
        }
    </style>
</head>

<body>
    <div class="app-wrapper">
        <?php include 'includes/sidebar.php'; ?>
        <main class="main-content">
            <header class="topbar">
                <div class="breadcrumb">Settings / <span
                        class="current"><?= $editModule ? 'Edit Module' : 'Module Manager' ?></span></div>
                <div class="topbar-right">
                    <?php if (!$editModule): ?>
                        <button class="btn-primary" style="width:auto;padding:12px 24px;" onclick="openCreateModal()">
                            <i class="fa-solid fa-plus"></i> New Module
                        </button>
                    <?php endif; ?>
                </div>
            </header>
            <div class="content-scroll">
                <?php if ($editModule): ?>
                    <!-- ═══════════ MODULE EDITOR VIEW ═══════════ -->
                    <div class="mm-editor">
                        <div class="mm-editor-header">
                            <a href="module_manager.php" class="mm-back"><i class="fa-solid fa-arrow-left"></i> All
                                Modules</a>
                            <div class="mm-editor-title">
                                <i class="<?= htmlspecialchars($editModule['icon']) ?>" style="color:var(--primary);"></i>
                                <span id="moduleNameDisplay"><?= htmlspecialchars($editModule['name']) ?></span>
                                <button class="mm-edit-name-btn" onclick="editModuleName()"><i
                                        class="fa-solid fa-pencil"></i></button>
                            </div>
                            <div class="mm-editor-actions">
                                <button class="mm-btn mm-btn-outline" onclick="openPermissionsModal()" title="Configure module permissions">
                                    <i class="fa-solid fa-shield-halved"></i> Permissions
                                </button>
                                <button class="mm-btn mm-btn-outline" onclick="addBlock()"><i
                                        class="fa-solid fa-layer-group"></i> Add Block</button>
                                <a href="module_view.php?module=<?= $editModule['id'] ?>" class="mm-btn mm-btn-primary"><i
                                        class="fa-solid fa-eye"></i> View Records</a>
                            </div>
                        </div>

                        <!-- Sub-Navigation Tabs -->
                        <div class="mm-tabs-wrapper" style="border-bottom:1.5px solid var(--border); margin-bottom:20px; display:flex; gap:20px;">
                            <button id="tabBtnFields" class="tab-btn active" onclick="switchEditorTab('fields')" style="padding:10px 5px; background:none; border:none; color:var(--primary); font-size:14px; font-weight:700; cursor:pointer; position:relative; display:flex; align-items:center; gap:8px;">
                                <i class="fa-solid fa-cubes"></i> Fields & Layout
                                <span class="tab-indicator" style="position:absolute; bottom:-1px; left:0; width:100%; height:3px; background:var(--primary); border-radius:3px 3px 0 0;"></span>
                            </button>
                            <button id="tabBtnWorkflows" class="tab-btn" onclick="switchEditorTab('workflows')" style="padding:10px 5px; background:none; border:none; color:var(--text-muted); font-size:14px; font-weight:600; cursor:pointer; position:relative; display:flex; align-items:center; gap:8px;">
                                <i class="fa-solid fa-bolt"></i> Workflow Automation
                                <span class="tab-indicator" style="position:absolute; bottom:-1px; left:0; width:100%; height:3px; background:transparent; border-radius:3px 3px 0 0;"></span>
                            </button>
                            <button id="tabBtnConversion" class="tab-btn" onclick="switchEditorTab('conversion')" style="padding:10px 5px; background:none; border:none; color:var(--text-muted); font-size:14px; font-weight:600; cursor:pointer; position:relative; display:flex; align-items:center; gap:8px;">
                                <i class="fa-solid fa-arrows-spin"></i> Record Conversion
                                <span class="tab-indicator" style="position:absolute; bottom:-1px; left:0; width:100%; height:3px; background:transparent; border-radius:3px 3px 0 0;"></span>
                            </button>
                        </div>

                        <div id="tabContentFields">
                            <div id="blocksContainer">
                            <?php foreach ($editModule['blocks'] as $block): ?>
                                <div class="mm-block" data-block-id="<?= $block['id'] ?>">
                                    <div class="mm-block-header">
                                        <div class="mm-block-title">
                                            <i class="fa-solid fa-grip-vertical mm-drag-handle"></i>
                                            <span class="block-name-text"><?= htmlspecialchars($block['name']) ?></span>
                                            <button class="mm-icon-btn" onclick="editBlockName(<?= $block['id'] ?>, this)"><i
                                                    class="fa-solid fa-pencil"></i></button>
                                        </div>
                                        <div class="mm-block-actions">
                                            <button class="mm-btn mm-btn-sm"
                                                onclick="openFieldModal(<?= $block['id'] ?>, <?= $editModule['id'] ?>)">
                                                <i class="fa-solid fa-plus"></i> Add Field
                                            </button>
                                            <button class="mm-icon-btn mm-icon-danger"
                                                onclick="deleteBlock(<?= $block['id'] ?>)"><i
                                                    class="fa-solid fa-trash"></i></button>
                                        </div>
                                    </div>
                                    <div class="mm-fields-list" id="fields-<?= $block['id'] ?>">
                                        <?php if (empty($block['fields'])): ?>
                                            <div class="mm-empty-fields">No fields yet. Click "Add Field" to start.</div>
                                        <?php else: ?>
                                            <?php foreach ($block['fields'] as $field): ?>
                                                <div class="mm-field-row" data-field-id="<?= $field['id'] ?>">
                                                    <div class="mm-field-info">
                                                        <i class="fa-solid fa-grip-vertical mm-field-drag-handle"></i>
                                                        <i
                                                            class="<?= htmlspecialchars($fieldTypes[$field['field_type']]['icon'] ?? 'fa-solid fa-font') ?> mm-field-icon"></i>
                                                        <div>
                                                            <div class="mm-field-label"><?= htmlspecialchars($field['label']) ?></div>
                                                            <div class="mm-field-meta">
                                                                <span class="mm-field-type"><?= htmlspecialchars($fieldTypes[$field['field_type']]['label'] ?? $field['field_type']) ?></span>
                                                                 <?php 
                                                                    $cfgObj = [];
                                                                    if (!empty($field['config'])) {
                                                                        $cfgObj = is_string($field['config']) ? json_decode($field['config'], true) : $field['config'];
                                                                    }
                                                                 ?>
                                                                 <?php if (!empty($field['is_required'])): ?><span class="mm-badge mm-badge-red">Required</span><?php endif; ?>
                                                                 <?php if (!empty($field['is_unique'])): ?><span class="mm-badge mm-badge-red">Unique</span><?php endif; ?>
                                                                 <?php if (!empty($field['is_searchable'])): ?><span class="mm-badge">Searchable</span><?php endif; ?>
                                                                 <?php if (!empty($field['is_list_visible'])): ?><span class="mm-badge">Show in List</span><?php endif; ?>
                                                                 <?php if (!empty($field['is_mobile_list_visible'])): ?><span class="mm-badge">Show in Mobile List</span><?php endif; ?>
                                                                 <?php if (!empty($field['is_quick_create'])): ?><span class="mm-badge">Quick Create</span><?php endif; ?>
                                                                 <?php if (!empty($cfgObj['is_title'])): ?><span class="mm-badge" style="background:rgba(37,99,235,0.12); color:#2563EB; font-weight:700;">Title (Record Name)</span><?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="mm-field-actions">
                                                        <button class="mm-icon-btn"
                                                            onclick="editField(<?= $field['id'] ?>, <?= htmlspecialchars(json_encode($field)) ?>)"
                                                            title="Edit"><i class="fa-solid fa-pencil"></i></button>
                                                        <?php if (in_array($field['field_type'], ['dropdown', 'multi_picker', 'radio_group'])): ?>
                                                            <button class="mm-icon-btn"
                                                                onclick="manageOptions(<?= $field['id'] ?>, <?= htmlspecialchars(json_encode($field['options'])) ?>)"
                                                                title="Options"><i class="fa-solid fa-list"></i></button>
                                                        <?php endif; ?>
                                                        <button class="mm-icon-btn"
                                                            onclick="manageRules(<?= $field['id'] ?>, <?= $editModule['id'] ?>)"
                                                            title="Rules"
                                                            <?= !empty($field['rules']) ? 'style="background: rgba(123,94,240,0.15); color: var(--primary); font-weight: bold; border: 1px solid rgba(123,94,240,0.3);"' : '' ?>><i class="fa-solid fa-code-branch"></i></button>
                                                        <button class="mm-icon-btn mm-icon-danger"
                                                            onclick="deleteField(<?= $field['id'] ?>)"><i
                                                                class="fa-solid fa-trash"></i></button>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if (empty($editModule['blocks'])): ?>
                            <div class="mm-empty-state"><i class="fa-solid fa-layer-group"></i>
                                <p>No blocks yet. Add a block to start building your module.</p>
                            </div>
                        <?php endif; ?>
                        </div> <!-- End of tabContentFields -->

                        <!-- tabContentWorkflows -->
                        <div id="tabContentWorkflows" style="display:none; background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:24px; box-shadow:var(--shadow-sm);">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                                <h3 style="margin:0; font-size:16px; font-weight:700; color:var(--text-main);">Automation Rules</h3>
                                <div style="display:flex; gap:10px;">
                                    <button class="mm-btn mm-btn-primary" onclick="openWorkflowModal()" style="display:inline-flex; align-items:center; gap:6px;">
                                        <i class="fa-solid fa-plus"></i> Add Workflow
                                    </button>
                                </div>
                            </div>
                            
                            <div class="crm-card" style="padding:0; overflow:hidden; margin-bottom:30px; border:1px solid var(--border);">
                                <table class="crm-table" style="margin:0; width:100%; border-collapse:collapse;">
                                    <thead>
                                        <tr style="background:rgba(0,0,0,0.02); border-bottom:1px solid var(--border);">
                                            <th style="padding:12px 16px; text-align:left; font-weight:600; color:var(--text-muted); font-size:13px;">Rule Name</th>
                                            <th style="padding:12px 16px; text-align:left; font-weight:600; color:var(--text-muted); font-size:13px;">Trigger Condition</th>
                                            <th style="padding:12px 16px; text-align:left; font-weight:600; color:var(--text-muted); font-size:13px;">Action To Take</th>
                                            <th style="padding:12px 16px; text-align:left; font-weight:600; color:var(--text-muted); font-size:13px;">Recipient</th>
                                            <th style="padding:12px 16px; text-align:left; font-weight:600; color:var(--text-muted); font-size:13px;">Status</th>
                                            <th style="padding:12px 16px; text-align:right; font-weight:600; color:var(--text-muted); font-size:13px;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="workflowsListBody">
                                        <!-- Dynamically loaded workflows -->
                                    </tbody>
                                </table>
                            </div>

                            <!-- Execution logs history -->
                            <div style="margin-top:40px;">
                                <h3 style="margin:0 0 15px; font-size:16px; font-weight:700; color:var(--text-main); display:flex; align-items:center; gap:8px;">
                                    <i class="fa-solid fa-clock-rotate-left" style="color:var(--primary);"></i> Execution History Logs
                                </h3>
                                <div class="crm-card" style="padding:0; overflow:hidden; border:1px solid var(--border);">
                                    <table class="crm-table" style="margin:0; width:100%; border-collapse:collapse;">
                                        <thead>
                                            <tr style="background:rgba(0,0,0,0.02); border-bottom:1px solid var(--border);">
                                                <th style="padding:12px 16px; text-align:left; font-weight:600; color:var(--text-muted); font-size:13px;">Triggered At</th>
                                                <th style="padding:12px 16px; text-align:left; font-weight:600; color:var(--text-muted); font-size:13px;">Workflow Rule</th>
                                                <th style="padding:12px 16px; text-align:left; font-weight:600; color:var(--text-muted); font-size:13px;">Action</th>
                                                <th style="padding:12px 16px; text-align:left; font-weight:600; color:var(--text-muted); font-size:13px;">Recipient</th>
                                                <th style="padding:12px 16px; text-align:left; font-weight:600; color:var(--text-muted); font-size:13px;">Status</th>
                                                <th style="padding:12px 16px; text-align:left; font-weight:600; color:var(--text-muted); font-size:13px;">Log Message</th>
                                            </tr>
                                        </thead>
                                        <tbody id="workflowLogsBody">
                                            <!-- Dynamically loaded logs -->
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- tabContentConversion -->
                        <div id="tabContentConversion" style="display:none; background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:24px; box-shadow:var(--shadow-sm);">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                                <h3 style="margin:0; font-size:16px; font-weight:700; color:var(--text-main);">Record Conversion Rules</h3>
                                <button class="mm-btn mm-btn-primary" onclick="openConversionModal()" style="display:inline-flex; align-items:center; gap:6px;">
                                    <i class="fa-solid fa-plus"></i> Add Conversion Rule
                                </button>
                            </div>
                            
                            <div class="crm-card" style="padding:0; overflow:hidden; border:1px solid var(--border);">
                                <table class="crm-table" style="margin:0; width:100%; border-collapse:collapse;">
                                    <thead>
                                        <tr style="background:rgba(0,0,0,0.02); border-bottom:1px solid var(--border);">
                                            <th style="padding:12px 16px; text-align:left; font-weight:600; color:var(--text-muted); font-size:13px;">Target Module</th>
                                            <th style="padding:12px 16px; text-align:left; font-weight:600; color:var(--text-muted); font-size:13px;">Button Label</th>
                                            <th style="padding:12px 16px; text-align:left; font-weight:600; color:var(--text-muted); font-size:13px;">Mapped Fields</th>
                                            <th style="padding:12px 16px; text-align:left; font-weight:600; color:var(--text-muted); font-size:13px;">Status</th>
                                            <th style="padding:12px 16px; text-align:right; font-weight:600; color:var(--text-muted); font-size:13px;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="conversionListBody">
                                        <!-- Dynamically loaded rules -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- ═══════════ MODULE LIST VIEW ═══════════ -->
                    <!-- ═══════════ SYSTEM MODULES ═══════════ -->
                    <div class="mm-system-settings" style="margin-bottom: 28px;">
                        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 20px;">
                            <div style="width: 40px; height: 40px; background: linear-gradient(135deg, var(--primary), #a78bfa); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                <i class="fa-solid fa-shield-halved" style="color: #fff; font-size: 18px;"></i>
                            </div>
                            <div>
                                <h4 style="margin: 0 0 2px; font-size: 16px; font-weight: 700; color: var(--text);">System Modules</h4>
                                <p style="margin: 0; font-size: 13px; color: var(--text-muted);">Enable or disable core CRM functionality per role.</p>
                            </div>
                        </div>
                        <?php
                        $attendanceEnabled = dm_get_system_setting($conn, $prefix, 'attendance_enabled', '1') === '1';
                        $billingEnabled = dm_get_system_setting($conn, $prefix, 'billing_enabled', '1') === '1';
                        $campaignsEnabled = dm_get_system_setting($conn, $prefix, 'campaigns_enabled', '1') === '1';
                        $attendanceVisibility = dm_get_system_setting($conn, $prefix, 'attendance_visibility', 'all');
                        $billingVisibility = dm_get_system_setting($conn, $prefix, 'billing_visibility', 'all');
                        $campaignsVisibility = dm_get_system_setting($conn, $prefix, 'campaigns_visibility', 'all');
                        ?>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px;">

                            <!-- Attendance Card -->
                            <div style="background: var(--surface); border: 1.5px solid var(--border); border-radius: 14px; padding: 16px; box-shadow: var(--shadow-sm); transition: box-shadow 0.2s;" onmouseover="this.style.boxShadow='0 4px 20px rgba(123,94,240,0.13)'" onmouseout="this.style.boxShadow='var(--shadow-sm)'">
                                <div style="display: flex; align-items: flex-start; gap: 10px;">
                                    <div style="width: 36px; height: 36px; background: linear-gradient(135deg, #6366f1, #818cf8); border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                        <i class="fa-solid fa-calendar-check" style="color: #fff; font-size: 16px;"></i>
                                    </div>
                                    <div style="flex: 1; min-width: 0;">
                                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 4px;">
                                            <h5 style="margin: 0; font-size: 13px; font-weight: 700; color: var(--text);">Attendance & Ops</h5>
                                            <label style="display: flex; align-items: center; cursor: pointer; flex-shrink: 0;">
                                                <input type="checkbox" class="sys-toggle" <?= $attendanceEnabled ? 'checked' : '' ?> onchange="toggleSystemModule('attendance_enabled', this.checked, this)" style="display:none;">
                                                <span class="sys-toggle-pill <?= $attendanceEnabled ? 'active' : '' ?>"></span>
                                            </label>
                                        </div>
                                        <div style="padding-top: 8px;">
                                            <select class="form-control" onchange="updateSystemVisibility('attendance_visibility', this.value)" style="width: 100%; font-size: 11px; padding: 4px 8px; border-radius: 6px; border: 1.5px solid var(--border); background: var(--surface); cursor: pointer; height: 28px; box-sizing: border-box;">
                                                <option value="all" <?= $attendanceVisibility === 'all' ? 'selected' : '' ?>>Show to all</option>
                                                <option value="owner" <?= $attendanceVisibility === 'owner' ? 'selected' : '' ?>>Owner Only</option>
                                                <option value="role_down" <?= $attendanceVisibility === 'role_down' ? 'selected' : '' ?>>Lower Roles</option>
                                                <option value="role_equal_down" <?= $attendanceVisibility === 'role_equal_down' ? 'selected' : '' ?>>Equal &amp; Lower</option>
                                                <option value="role_up" <?= $attendanceVisibility === 'role_up' ? 'selected' : '' ?>>Upper Roles</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Billing Card -->
                            <div style="background: var(--surface); border: 1.5px solid var(--border); border-radius: 14px; padding: 16px; box-shadow: var(--shadow-sm); transition: box-shadow 0.2s;" onmouseover="this.style.boxShadow='0 4px 20px rgba(123,94,240,0.13)'" onmouseout="this.style.boxShadow='var(--shadow-sm)'">
                                <div style="display: flex; align-items: flex-start; gap: 10px;">
                                    <div style="width: 36px; height: 36px; background: linear-gradient(135deg, #0ea5e9, #38bdf8); border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                        <i class="fa-solid fa-file-invoice-dollar" style="color: #fff; font-size: 16px;"></i>
                                    </div>
                                    <div style="flex: 1; min-width: 0;">
                                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 4px;">
                                            <h5 style="margin: 0; font-size: 13px; font-weight: 700; color: var(--text);">Billing & Transactions</h5>
                                            <label style="display: flex; align-items: center; cursor: pointer; flex-shrink: 0;">
                                                <input type="checkbox" class="sys-toggle" <?= $billingEnabled ? 'checked' : '' ?> onchange="toggleSystemModule('billing_enabled', this.checked, this)" style="display:none;">
                                                <span class="sys-toggle-pill <?= $billingEnabled ? 'active' : '' ?>"></span>
                                            </label>
                                        </div>
                                        <div style="padding-top: 8px;">
                                            <select class="form-control" onchange="updateSystemVisibility('billing_visibility', this.value)" style="width: 100%; font-size: 11px; padding: 4px 8px; border-radius: 6px; border: 1.5px solid var(--border); background: var(--surface); cursor: pointer; height: 28px; box-sizing: border-box;">
                                                <option value="all" <?= $billingVisibility === 'all' ? 'selected' : '' ?>>Show to all</option>
                                                <option value="owner" <?= $billingVisibility === 'owner' ? 'selected' : '' ?>>Owner Only</option>
                                                <option value="role_down" <?= $billingVisibility === 'role_down' ? 'selected' : '' ?>>Lower Roles</option>
                                                <option value="role_equal_down" <?= $billingVisibility === 'role_equal_down' ? 'selected' : '' ?>>Equal &amp; Lower</option>
                                                <option value="role_up" <?= $billingVisibility === 'role_up' ? 'selected' : '' ?>>Upper Roles</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Campaigns Card -->
                            <div style="background: var(--surface); border: 1.5px solid var(--border); border-radius: 14px; padding: 16px; box-shadow: var(--shadow-sm); transition: box-shadow 0.2s;" onmouseover="this.style.boxShadow='0 4px 20px rgba(123,94,240,0.13)'" onmouseout="this.style.boxShadow='var(--shadow-sm)'">
                                <div style="display: flex; align-items: flex-start; gap: 10px;">
                                    <div style="width: 36px; height: 36px; background: linear-gradient(135deg, #10b981, #34d399); border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                        <i class="fa-solid fa-bullhorn" style="color: #fff; font-size: 16px;"></i>
                                    </div>
                                    <div style="flex: 1; min-width: 0;">
                                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 4px;">
                                            <h5 style="margin: 0; font-size: 13px; font-weight: 700; color: var(--text);">Bulk Campaigns</h5>
                                            <label style="display: flex; align-items: center; cursor: pointer; flex-shrink: 0;">
                                                <input type="checkbox" class="sys-toggle" <?= $campaignsEnabled ? 'checked' : '' ?> onchange="toggleSystemModule('campaigns_enabled', this.checked, this)" style="display:none;">
                                                <span class="sys-toggle-pill <?= $campaignsEnabled ? 'active' : '' ?>"></span>
                                            </label>
                                        </div>
                                        <div style="padding-top: 8px;">
                                            <select class="form-control" onchange="updateSystemVisibility('campaigns_visibility', this.value)" style="width: 100%; font-size: 11px; padding: 4px 8px; border-radius: 6px; border: 1.5px solid var(--border); background: var(--surface); cursor: pointer; height: 28px; box-sizing: border-box;">
                                                <option value="all" <?= $campaignsVisibility === 'all' ? 'selected' : '' ?>>Show to all</option>
                                                <option value="owner" <?= $campaignsVisibility === 'owner' ? 'selected' : '' ?>>Owner Only</option>
                                                <option value="role_down" <?= $campaignsVisibility === 'role_down' ? 'selected' : '' ?>>Lower Roles</option>
                                                <option value="role_equal_down" <?= $campaignsVisibility === 'role_equal_down' ? 'selected' : '' ?>>Equal &amp; Lower</option>
                                                <option value="role_up" <?= $campaignsVisibility === 'role_up' ? 'selected' : '' ?>>Upper Roles</option>
                                            </select>
                                        </div>
                                        <div style="padding-top: 6px;">
                                            <button class="mm-btn mm-btn-outline" style="width: 100%; font-size: 11px; padding: 4px 8px; height: 28px; display: flex; align-items: center; justify-content: center; gap: 4px;" onclick="openCampaignMappingModal()">
                                                <i class="fa-solid fa-map-location-dot"></i> Field Mapping
                                            </button>
                                        </div>
                                        <div style="padding-top: 6px;">
                                            <button class="mm-btn mm-btn-outline" style="width: 100%; font-size: 11px; padding: 4px 8px; height: 28px; display: flex; align-items: center; justify-content: center; gap: 4px;" onclick="openCampaignFieldsModal()">
                                                <i class="fa-solid fa-sliders"></i> Configure Fields
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Payroll Card -->
                            <?php
                            $payrollEnabled = dm_get_system_setting($conn, $prefix, 'payroll_enabled', '1') === '1';
                            $payrollVisibility = dm_get_system_setting($conn, $prefix, 'payroll_visibility', 'all');
                            ?>
                            <div style="background: var(--surface); border: 1.5px solid var(--border); border-radius: 14px; padding: 16px; box-shadow: var(--shadow-sm); transition: box-shadow 0.2s;" onmouseover="this.style.boxShadow='0 4px 20px rgba(123,94,240,0.13)'" onmouseout="this.style.boxShadow='var(--shadow-sm)'">
                                <div style="display: flex; align-items: flex-start; gap: 10px;">
                                    <div style="width: 36px; height: 36px; background: linear-gradient(135deg, #f59e0b, #fbbf24); border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                        <i class="fa-solid fa-money-check-dollar" style="color: #fff; font-size: 16px;"></i>
                                    </div>
                                    <div style="flex: 1; min-width: 0;">
                                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 4px;">
                                            <h5 style="margin: 0; font-size: 13px; font-weight: 700; color: var(--text);">Payroll Management</h5>
                                            <label style="display: flex; align-items: center; cursor: pointer; flex-shrink: 0;">
                                                <input type="checkbox" class="sys-toggle" <?= $payrollEnabled ? 'checked' : '' ?> onchange="toggleSystemModule('payroll_enabled', this.checked, this)" style="display:none;">
                                                <span class="sys-toggle-pill <?= $payrollEnabled ? 'active' : '' ?>"></span>
                                            </label>
                                        </div>
                                        <div style="padding-top: 8px;">
                                            <select class="form-control" onchange="updateSystemVisibility('payroll_visibility', this.value)" style="width: 100%; font-size: 11px; padding: 4px 8px; border-radius: 6px; border: 1.5px solid var(--border); background: var(--surface); cursor: pointer; height: 28px; box-sizing: border-box;">
                                                <option value="all" <?= $payrollVisibility === 'all' ? 'selected' : '' ?>>Show to all</option>
                                                <option value="owner" <?= $payrollVisibility === 'owner' ? 'selected' : '' ?>>Owner Only</option>
                                                <option value="role_down" <?= $payrollVisibility === 'role_down' ? 'selected' : '' ?>>Lower Roles</option>
                                                <option value="role_equal_down" <?= $payrollVisibility === 'role_equal_down' ? 'selected' : '' ?>>Equal &amp; Lower</option>
                                                <option value="role_up" <?= $payrollVisibility === 'role_up' ? 'selected' : '' ?>>Upper Roles</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Mobile Calls & Recordings Card -->
                            <?php
                            $callsEnabled = dm_get_system_setting($conn, $prefix, 'calls_enabled', '1') === '1';
                            $callsVisibility = dm_get_system_setting($conn, $prefix, 'calls_visibility', 'all');
                            ?>
                            <div style="background: var(--surface); border: 1.5px solid var(--border); border-radius: 14px; padding: 16px; box-shadow: var(--shadow-sm); transition: box-shadow 0.2s;" onmouseover="this.style.boxShadow='0 4px 20px rgba(123,94,240,0.13)'" onmouseout="this.style.boxShadow='var(--shadow-sm)'">
                                <div style="display: flex; align-items: flex-start; gap: 10px;">
                                    <div style="width: 36px; height: 36px; background: linear-gradient(135deg, #4f46e5, #818cf8); border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                        <i class="fa-solid fa-phone-volume" style="color: #fff; font-size: 16px;"></i>
                                    </div>
                                    <div style="flex: 1; min-width: 0;">
                                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 4px;">
                                            <h5 style="margin: 0; font-size: 13px; font-weight: 700; color: var(--text);">Mobile Calls & Voice</h5>
                                            <label style="display: flex; align-items: center; cursor: pointer; flex-shrink: 0;">
                                                <input type="checkbox" class="sys-toggle" <?= $callsEnabled ? 'checked' : '' ?> onchange="toggleSystemModule('calls_enabled', this.checked, this)" style="display:none;">
                                                <span class="sys-toggle-pill <?= $callsEnabled ? 'active' : '' ?>"></span>
                                            </label>
                                        </div>
                                        <div id="calls-settings-controls" style="display: <?= $callsEnabled ? 'block' : 'none' ?>;">
                                            <div style="padding-top: 8px;">
                                                <select class="form-control" onchange="updateSystemVisibility('calls_visibility', this.value)" style="width: 100%; font-size: 11px; padding: 4px 8px; border-radius: 6px; border: 1.5px solid var(--border); background: var(--surface); cursor: pointer; height: 28px; box-sizing: border-box;">
                                                    <option value="all" <?= $callsVisibility === 'all' ? 'selected' : '' ?>>Show to all</option>
                                                    <option value="owner" <?= $callsVisibility === 'owner' ? 'selected' : '' ?>>Owner Only</option>
                                                    <option value="role_down" <?= $callsVisibility === 'role_down' ? 'selected' : '' ?>>Lower Roles</option>
                                                    <option value="role_equal_down" <?= $callsVisibility === 'role_equal_down' ? 'selected' : '' ?>>Equal &amp; Lower</option>
                                                    <option value="role_up" <?= $callsVisibility === 'role_up' ? 'selected' : '' ?>>Upper Roles</option>
                                                </select>
                                            </div>
                                            <div style="padding-top: 6px;">
                                                <a href="call_settings.php" class="mm-btn mm-btn-outline" style="width: 100%; font-size: 11px; padding: 4px 8px; height: 28px; display: flex; align-items: center; justify-content: center; gap: 4px; text-decoration: none; box-sizing: border-box;">
                                                    <i class="fa-solid fa-cloud-arrow-up" style="color: #6366f1;"></i> Storage Settings
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Dashboard Widgets Card -->
                            <div style="background: var(--surface); border: 1.5px solid var(--border); border-radius: 14px; padding: 16px; box-shadow: var(--shadow-sm); transition: box-shadow 0.2s;" onmouseover="this.style.boxShadow='0 4px 20px rgba(123,94,240,0.13)'" onmouseout="this.style.boxShadow='var(--shadow-sm)'">
                                <div style="display: flex; align-items: flex-start; gap: 10px;">
                                    <div style="width: 36px; height: 36px; background: linear-gradient(135deg, #7b5ef0, #a78bfa); border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                        <i class="fa-solid fa-gauge" style="color: #fff; font-size: 16px;"></i>
                                    </div>
                                    <div style="flex: 1; min-width: 0;">
                                        <h5 style="margin: 0 0 4px; font-size: 13px; font-weight: 700; color: var(--text);">Dashboard Widgets</h5>
                                        <p style="margin: 0 0 10px; font-size: 11px; color: var(--text-muted);">Configure dynamic insight widgets on CRM dashboard.</p>
                                        <button class="mm-btn mm-btn-outline" style="width: 100%; font-size: 11px; padding: 4px 8px; height: 28px; display: flex; align-items: center; justify-content: center; gap: 4px;" onclick="openWidgetsConfigModal()">
                                            <i class="fa-solid fa-sliders"></i> Configure Widgets
                                        </button>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>

                    <?php
                    $visibleDynamicModules = array_values(array_filter($allModules, function($m) use ($callsEnabled) {
                        if ($m['slug'] === 'calls' && !$callsEnabled) {
                            return false;
                        }
                        return true;
                    }));
                    ?>

                    <div class="mm-grid" id="moduleGrid">
                        <?php if (empty($visibleDynamicModules)): ?>
                            <div class="mm-empty-state" style="grid-column:1/-1;">
                                <i class="fa-solid fa-cubes"></i>
                                <h3>No Modules Yet</h3>
                                <p>Create your first dynamic module to get started.</p>
                                <button class="mm-btn mm-btn-primary" onclick="openCreateModal()"><i
                                        class="fa-solid fa-plus"></i> Create Module</button>
                            </div>
                        <?php else: ?>
                            <?php foreach ($visibleDynamicModules as $mod): ?>
                                <div class="mm-module-card <?= $mod['status'] === 'inactive' ? 'mm-inactive' : '' ?>" id="mod-card-<?= $mod['id'] ?>">
                                    <div style="display:flex; align-items:center; justify-content:space-between; padding:0 0 12px 0; margin-bottom:12px; border-bottom:1px solid var(--border);">
                                        <div style="display:flex; align-items:center; gap:12px;">
                                            <div class="mm-card-icon"><i class="<?= htmlspecialchars($mod['icon']) ?>"></i></div>
                                            <h4 style="margin:0;"><?= htmlspecialchars($mod['name']) ?></h4>
                                        </div>
                                        <label style="display:flex;align-items:center;cursor:pointer;flex-shrink:0;margin:0;">
                                            <input type="checkbox" class="mod-toggle" <?= $mod['status'] === 'active' ? 'checked' : '' ?>
                                                onchange="toggleModuleStatus(<?= $mod['id'] ?>, this.checked, this)"
                                                style="display:none;">
                                            <span class="sys-toggle-pill <?= $mod['status'] === 'active' ? 'active' : '' ?>"></span>
                                        </label>
                                    </div>
                                    <p class="mm-card-desc"><?= htmlspecialchars($mod['description'] ?: 'No description') ?></p>
                                    <div class="mm-card-stats">
                                        <span><i class="fa-solid fa-layer-group"></i> <?= $mod['block_count'] ?> Blocks</span>
                                        <span><i class="fa-solid fa-list"></i> <?= $mod['field_count'] ?> Fields</span>
                                        <span><i class="fa-solid fa-database"></i> <?= $mod['record_count'] ?> Records</span>
                                    </div>
                                    <div class="mm-card-actions">
                                        <a href="module_manager.php?edit=<?= $mod['id'] ?>" class="mm-btn mm-btn-sm"><i
                                                class="fa-solid fa-cog"></i> Configure</a>
                                        <a href="module_view.php?module=<?= $mod['id'] ?>"
                                             class="mm-btn mm-btn-sm mm-btn-primary"><i class="fa-solid fa-eye"></i> View</a>
                                        <button class="mm-icon-btn mm-icon-danger"
                                            onclick="deleteModule(<?= $mod['id'] ?>, '<?= htmlspecialchars($mod['name']) ?>')"><i
                                                class="fa-solid fa-trash"></i></button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Campaigns Field Mapping Modal -->
    <div class="mm-modal-overlay" id="campaignMappingModal">
        <div class="mm-modal mm-modal-lg">
            <div class="mm-modal-header">
                <h3>Campaign Field Mapping</h3>
                <button class="mm-icon-btn" onclick="closeModal('campaignMappingModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="mm-modal-body">

                <?php if (!empty($configuredMappings)): ?>
                <!-- Already configured modules -->
                <div style="margin-bottom: 24px;">
                    <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); margin-bottom: 10px;">Configured Modules</div>
                    <div style="display: flex; flex-direction: column; gap: 8px;" id="configuredMappingsList">
                        <?php foreach ($configuredMappings as $cm): ?>
                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: rgba(123,94,240,.05); border: 1px solid rgba(123,94,240,.15); border-radius: 10px;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <i class="fa-solid fa-database" style="color: var(--primary); font-size: 14px;"></i>
                                <span style="font-weight: 700; font-size: 14px;"><?= htmlspecialchars($cm['name']) ?></span>
                                <span style="font-size: 11px; color: var(--text-muted); background: var(--surface); border: 1px solid var(--border); border-radius: 20px; padding: 2px 8px;">
                                    <?php
                                        $mapped = array_filter($cm['mapping']);
                                        echo count($mapped) . ' field' . (count($mapped) !== 1 ? 's' : '') . ' mapped';
                                    ?>
                                </span>
                            </div>
                            <div style="display: flex; gap: 6px;">
                                <button class="mm-btn mm-btn-outline" style="padding: 5px 12px; font-size: 12px; height: 30px;" onclick="loadMappingForEdit(<?= $cm['id'] ?>)">
                                    <i class="fa-solid fa-pencil"></i> Edit
                                </button>
                                <button class="mm-icon-btn mm-icon-danger" title="Remove Mapping" onclick="removeCampaignMapping(<?= $cm['id'] ?>, '<?= htmlspecialchars(addslashes($cm['name'])) ?>')">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div style="border-top: 1px solid var(--border); margin-bottom: 20px;"></div>
                <?php endif; ?>

                <!-- Add / Edit a mapping -->
                <div class="form-group">
                    <label class="form-label"><?= empty($configuredMappings) ? 'Select Module to Map' : 'Add / Edit Module Mapping' ?></label>
                    <select id="mappingModuleSelect" class="form-control" onchange="onMappingModuleChange()">
                        <option value="">-- Choose Module --</option>
                        <?php foreach ($allModules as $m): ?>
                            <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?><?= isset($configuredMappings[$m['id']]) ? ' ✓' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div id="mappingFieldsContainer" style="display: none; margin-top: 20px; border-top: 1px solid var(--border); padding-top: 20px;">
                    <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 20px;">
                        Map the campaign fields (left) to the fields in your selected module (right).
                    </p>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; align-items: center; margin-bottom: 12px;">
                        <div style="font-weight: 700; color: var(--text-main);">Campaign Field</div>
                        <div style="font-weight: 700; color: var(--text-main);">Module Field</div>
                    </div>
                    
                    <!-- Mapping Rows -->
                    <?php
                    $fieldsToMap = [
                        'first_name' => 'First Name *',
                        'last_name' => 'Last Name',
                        'email' => 'Email *',
                        'email2' => 'Additional Email',
                        'phone' => 'Phone',
                        'phone2' => 'Additional Phone',
                        'company' => 'Company Name',
                        'designation' => 'Designation'
                    ];
                    foreach ($fieldsToMap as $key => $label):
                    ?>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; align-items: center; margin-bottom: 12px;">
                        <div style="font-size: 13px; font-weight: 600; color: var(--text);"><?= htmlspecialchars($label) ?></div>
                        <div>
                            <select id="map_field_<?= $key ?>" class="form-control mapping-dropdown" style="width: 100%;">
                                <option value="">-- Don't Map --</option>
                            </select>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="mm-modal-footer">
                <button class="mm-btn" onclick="closeModal('campaignMappingModal')">Cancel</button>
                <button class="mm-btn mm-btn-primary" id="btnSaveCampaignMapping" onclick="saveCampaignMapping()" disabled><i class="fa-solid fa-check"></i> Save Mapping</button>
            </div>
        </div>
    </div>

    <!-- Campaign Configure Fields Modal -->
    <div class="mm-modal-overlay" id="campaignFieldsModal">
        <div class="mm-modal" style="max-width: 680px;">
            <div class="mm-modal-header">
                <h3><i class="fa-solid fa-sliders" style="color: var(--primary); margin-right: 8px;"></i> Configure Campaign Fields</h3>
                <button class="mm-icon-btn" onclick="closeModal('campaignFieldsModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="mm-modal-body" style="padding: 0;">
                <!-- All configurable fields -->
                <div style="padding: 16px 20px;">
                    <p style="margin: 0 0 10px 0; font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.6px;">Campaign Fields</p>
                    <div id="campaignFieldsList" style="margin-bottom: 14px; display: flex; flex-direction: column; gap: 6px; min-height: 40px;">
                        <div style="text-align:center; padding:20px; color:var(--text-muted); font-size:13px;" id="campaignFieldsEmpty">Loading...</div>
                    </div>

                    <!-- Add field form -->
                    <div id="campaignFieldAddForm" style="background: rgba(123,94,240,0.03); border: 1.5px dashed var(--border); border-radius: 10px; padding: 14px; display:none;">
                        <p style="margin:0 0 10px 0; font-size:12px; font-weight:700; color:var(--primary);" id="campaignFieldFormTitle">Add New Field</p>
                        <input type="hidden" id="cfEditId" value="0">
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:10px;">
                            <div>
                                <label class="form-label" style="font-size:11px;">Label *</label>
                                <input type="text" id="cfLabel" class="form-control" placeholder="e.g. Age, City, Score" style="height:36px; padding:6px 12px; font-size:13px;">
                            </div>
                            <div>
                                <label class="form-label" style="font-size:11px;">Field Type</label>
                                <select id="cfType" class="form-control" style="height:36px; padding:6px 12px; font-size:13px; line-height:1.5;" onchange="onCfTypeChange()">
                                    <option value="text">Text</option>
                                    <option value="number">Number</option>
                                    <option value="email">Email</option>
                                    <option value="phone">Phone</option>
                                    <option value="date">Date</option>
                                    <option value="url">URL</option>
                                    <option value="textarea">Textarea</option>
                                    <option value="select">Dropdown (Select)</option>
                                </select>
                            </div>
                        </div>
                        <div style="display:grid; grid-template-columns:1fr auto; gap:10px; align-items:end; margin-bottom:10px;">
                            <div>
                                <label class="form-label" style="font-size:11px;">Placeholder</label>
                                <input type="text" id="cfPlaceholder" class="form-control" placeholder="e.g. Enter age" style="height:36px; padding:6px 12px; font-size:13px;">
                            </div>
                            <label style="display:flex; align-items:center; gap:6px; font-size:12px; cursor:pointer; padding-bottom:4px;">
                                <input type="checkbox" id="cfRequired" style="width:14px; height:14px; accent-color:var(--primary);">
                                Required
                            </label>
                        </div>
                        <div id="cfOptionsGroup" style="display:none; margin-bottom:10px;">
                            <label class="form-label" style="font-size:11px;">Options (comma-separated)</label>
                            <input type="text" id="cfOptions" class="form-control" placeholder="Option 1, Option 2, Option 3" style="height:36px; padding:6px 12px; font-size:13px;">
                        </div>
                        <div style="display:flex; gap:8px;">
                            <button class="mm-btn mm-btn-primary" style="height:32px; padding:0 14px; font-size:12px;" onclick="saveCampaignField()"><i class="fa-solid fa-check"></i> Save Field</button>
                            <button class="mm-btn" style="height:32px; padding:0 14px; font-size:12px;" onclick="cancelCfEdit()">Cancel</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mm-modal-footer">
                <button class="mm-btn" onclick="closeModal('campaignFieldsModal')">Close</button>
                <button class="mm-btn mm-btn-primary" onclick="showCfAddForm()"><i class="fa-solid fa-plus"></i> Add Field</button>
            </div>
        </div>
    </div>

    <!-- Dashboard Widgets Config Modal -->
    <div class="mm-modal-overlay" id="widgetsConfigModal">
        <div class="mm-modal mm-modal-lg" style="max-width:850px;">
            <div class="mm-modal-header">
                <h3>Configure Dashboard Widgets</h3>
                <button class="mm-icon-btn" onclick="closeModal('widgetsConfigModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="mm-modal-body" style="padding: 24px; min-height: 400px; display: flex; flex-direction: column; gap: 20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border); padding-bottom:12px;">
                    <span style="font-size:13px; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Active Widgets</span>
                    <button type="button" class="mm-btn mm-btn-primary mm-btn-sm" onclick="showAddWidgetForm()">
                        <i class="fa-solid fa-plus"></i> Add New Widget
                    </button>
                </div>
                
                <!-- Widget Form (Hidden by default) -->
                <div id="widgetFormContainer" style="display:none; background:#f8fafc; border:1px solid var(--border); border-radius:12px; padding:20px; box-shadow:var(--shadow-sm);">
                    <h4 id="widgetFormTitle" style="margin:0 0 15px; font-size:15px; font-weight:700; color:var(--primary);">Add Dashboard Widget</h4>
                    <input type="hidden" id="widgetId" value="">
                    
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:15px;">
                        <div class="form-group" style="display:flex; flex-direction:column; gap:4px;">
                            <label class="form-label" style="font-size:12px; font-weight:700;">Widget Title *</label>
                            <input type="text" id="widgetTitle" class="form-control" placeholder="e.g. Follow ups due today" style="height:38px; box-sizing:border-box; padding: 6px 12px;">
                        </div>
                        <div class="form-group" style="display:flex; flex-direction:column; gap:4px;">
                            <label class="form-label" style="font-size:12px; font-weight:700;">Target Module *</label>
                            <select id="widgetModule" class="form-control" style="height: 38px; padding-top: 6px; padding-bottom: 6px; line-height: 1.5;" onchange="onWidgetModuleChange()">
                                <option value="">-- Select Module --</option>
                                <?php foreach ($allModules as $mod): ?>
                                <option value="<?= $mod['id'] ?>"><?= htmlspecialchars($mod['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; margin-bottom:15px;">
                        <div class="form-group" style="display:flex; flex-direction:column; gap:4px;">
                            <label class="form-label" style="font-size:12px; font-weight:700;">Filter Field *</label>
                            <select id="widgetField" class="form-control" style="height: 38px; padding-top: 6px; padding-bottom: 6px; line-height: 1.5;" onchange="onWidgetFieldChange()">
                                <option value="">-- Select Field --</option>
                            </select>
                        </div>
                        <div class="form-group" style="display:flex; flex-direction:column; gap:4px;">
                            <label class="form-label" style="font-size:12px; font-weight:700;">Condition *</label>
                            <select id="widgetOperator" class="form-control" style="height: 38px; padding-top: 6px; padding-bottom: 6px; line-height: 1.5;" onchange="onWidgetOperatorChange()">
                                <optgroup label="Relative Time Windows">
                                    <option value="days_within_past">Within last X days</option>
                                    <option value="days_older">Older than X days</option>
                                    <option value="days_within_future">Within next X days</option>
                                </optgroup>
                                <optgroup label="Standard Date Periods">
                                    <option value="today">Is Today</option>
                                    <option value="yesterday">Is Yesterday</option>
                                    <option value="this_week">Is This Week</option>
                                    <option value="this_month">Is This Month</option>
                                </optgroup>
                                <optgroup label="Basic Comparisons">
                                    <option value="=">Equals (=)</option>
                                    <option value="!=">Not Equals (!=)</option>
                                    <option value="LIKE">Contains (LIKE)</option>
                                    <option value="NOT LIKE">Does Not Contain</option>
                                    <option value=">">Greater Than (&gt;)</option>
                                    <option value="<">Less Than (&lt;)</option>
                                    <option value=">=">Greater Than or Equal (&gt;=)</option>
                                    <option value="<=">Less Than or Equal (&lt;=)</option>
                                </optgroup>
                                <optgroup label="Presence / Empty">
                                    <option value="empty">Is Empty (Blank)</option>
                                    <option value="not_empty">Is Not Empty</option>
                                </optgroup>
                            </select>
                        </div>
                        <div class="form-group" id="widgetValueGroup" style="display:flex; flex-direction:column; gap:4px;">
                            <label class="form-label" style="font-size:12px; font-weight:700;">Value</label>
                            <input type="text" id="widgetValue" class="form-control" placeholder="Value..." style="height:38px; box-sizing:border-box; padding: 6px 12px;">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; margin-bottom:15px;">
                        <div class="form-group" style="display:flex; flex-direction:column; gap:4px;">
                            <label class="form-label" style="font-size:12px; font-weight:700;">Display Format *</label>
                            <select id="widgetDisplayType" class="form-control" style="height: 38px; padding-top: 6px; padding-bottom: 6px; line-height: 1.5;">
                                <option value="count">🔢 Number (Count)</option>
                                <option value="percentage">📊 Percentage (%)</option>
                                <option value="both">🔢 + 📊 Both (Count & %)</option>
                            </select>
                        </div>
                        <div class="form-group" style="display:flex; flex-direction:column; gap:4px;">
                            <label class="form-label" style="font-size:12px; font-weight:700;">Icon *</label>
                            <select id="widgetIcon" class="form-control" style="height: 38px; padding-top: 6px; padding-bottom: 6px; line-height: 1.5;">
                                <option value="fa-solid fa-clock">⏰ Clock</option>
                                <option value="fa-solid fa-bell">🔔 Bell</option>
                                <option value="fa-solid fa-circle-exclamation">⚠️ Warning Alert</option>
                                <option value="fa-solid fa-database">🛢️ Database</option>
                                <option value="fa-solid fa-users">👥 Users</option>
                                <option value="fa-solid fa-user-check">👤 User Check</option>
                                <option value="fa-solid fa-chart-line">📈 Trend Chart</option>
                                <option value="fa-solid fa-list-check">☑️ Task List</option>
                                <option value="fa-solid fa-dollar-sign">💲 Dollar Sign</option>
                                <option value="fa-solid fa-envelope">✉️ Envelope</option>
                            </select>
                        </div>
                        <div class="form-group" style="display:flex; flex-direction:column; gap:4px;">
                            <label class="form-label" style="font-size:12px; font-weight:700;">Widget Color *</label>
                            <select id="widgetColor" class="form-control" style="height: 38px; padding-top: 6px; padding-bottom: 6px; line-height: 1.5;">
                                <option value="#7b5ef0" style="color:#7b5ef0; font-weight:700;">Purple (Primary)</option>
                                <option value="#3b82f6" style="color:#3b82f6; font-weight:700;">Blue (Info)</option>
                                <option value="#10b981" style="color:#10b981; font-weight:700;">Green (Success)</option>
                                <option value="#f59e0b" style="color:#f59e0b; font-weight:700;">Orange (Warning)</option>
                                <option value="#ef4444" style="color:#ef4444; font-weight:700;">Red (Danger)</option>
                            </select>
                        </div>
                    </div>
                    
                    <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:20px;">
                        <button type="button" class="mm-btn" onclick="hideWidgetForm()">Cancel</button>
                        <button type="button" class="mm-btn mm-btn-primary" onclick="saveWidget()"><i class="fa-solid fa-check"></i> Save Widget</button>
                    </div>
                </div>
                
                <!-- Widgets List -->
                <div style="flex:1; overflow-y:auto; border:1px solid var(--border); border-radius:12px; background:#fff; min-height:200px;">
                    <table class="crm-table" style="margin:0; width:100%;">
                        <thead>
                            <tr style="background:#fafafa; border-bottom:1px solid var(--border);">
                                <th style="padding:10px 15px;">Title</th>
                                <th style="padding:10px 15px;">Module</th>
                                <th style="padding:10px 15px;">Display Format</th>
                                <th style="padding:10px 15px;">Condition Description</th>
                                <th style="padding:10px 15px; text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="widgetsListTableBody">
                            <tr><td colspan="5" style="text-align:center; padding:30px; color:var(--text-muted);">No dashboard widgets configured. Click "Add New Widget" to create one.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Module Modal -->
    <div class="mm-modal-overlay" id="createModuleModal">
        <div class="mm-modal">
            <div class="mm-modal-header">
                <h3>Create New Module</h3><button class="mm-icon-btn" onclick="closeModal('createModuleModal')"><i
                        class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="mm-modal-body">
                <div class="form-group"><label class="form-label">Module Name *</label><input type="text"
                        id="newModuleName" class="form-control" placeholder="e.g. Leads, Tickets, Deals"></div>
                <div class="form-group"><label class="form-label">Choose Icon</label>
                    <input type="hidden" id="newModuleIcon" value="fa-solid fa-cube">
                    <div class="mm-icon-picker-selected"
                        onclick="document.getElementById('iconPickerGrid').style.display=document.getElementById('iconPickerGrid').style.display==='none'?'grid':'none'">
                        <i class="fa-solid fa-cube" id="selectedIconPreview"></i> <span id="selectedIconName">fa-solid
                            fa-cube</span> <i class="fa-solid fa-chevron-down"
                            style="margin-left:auto;font-size:11px;color:var(--text-muted);"></i>
                    </div>
                    <div class="mm-icon-picker-grid" id="iconPickerGrid" style="display:none;"></div>
                </div>
                <div class="form-group"><label class="form-label">Visibility Rule</label>
                    <select id="newModuleVisibility" class="form-control">
                        <option value="all">Show to all</option>
                        <option value="owner">Only Owner (Creator)</option>
                        <option value="role_down">Lower Level Roles</option>
                        <option value="role_equal_down">Equal and Lower Roles</option>
                        <option value="role_up">Upper Level Roles</option>
                    </select>
                </div>
                <div class="form-group"><label class="form-label">Description</label><textarea id="newModuleDesc"
                        class="form-control" rows="2" placeholder="Brief description..."></textarea></div>
            </div>
            <div class="mm-modal-footer">
                <button class="mm-btn" onclick="closeModal('createModuleModal')">Cancel</button>
                <button class="mm-btn mm-btn-primary" onclick="createModule()"><i class="fa-solid fa-check"></i>
                    Create</button>
            </div>
        </div>
    </div>

    <!-- Add Field Modal -->
    <div class="mm-modal-overlay" id="addFieldModal">
        <div class="mm-modal mm-modal-lg">
            <div class="mm-modal-header">
                <h3 id="fieldModalTitle">Add Field</h3><button class="mm-icon-btn"
                    onclick="closeModal('addFieldModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="mm-modal-body">
                <input type="hidden" id="fieldBlockId"><input type="hidden" id="fieldModuleId"><input type="hidden"
                    id="fieldEditId">
                <div class="mm-form-grid">
                    <div class="form-group"><label class="form-label">Label *</label><input type="text" id="fieldLabel"
                            class="form-control" placeholder="Field label"></div>
                    <div class="form-group"><label class="form-label">Type *</label>
                        <select id="fieldType" class="form-control" onchange="onFieldTypeChange()">
                            <?php foreach ($fieldTypes as $key => $ft): ?>
                                <option value="<?= $key ?>"><?= htmlspecialchars($ft['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Placeholder</label><input type="text"
                            id="fieldPlaceholder" class="form-control"></div>
                    <div class="form-group" id="fieldDefaultGroup">
                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:4px;">
                            <label class="form-label" style="margin:0;">Default Value</label>
                            <div id="fieldDynamicPresetGroup" style="display:none; align-items:center; gap:4px;">
                                <span style="font-size:11px; color:var(--text-muted);">Presets:</span>
                                <select id="fieldPresetSelect" class="form-control" style="padding:2px 6px; font-size:12px; height:24px; width:auto; border-radius:6px;" onchange="applyDatePreset(this.value)">
                                    <option value="">-- Pick Fixed Date / Time --</option>
                                    <option value="today">Today (Current Date)</option>
                                    <option value="tomorrow">Tomorrow (+1 Day)</option>
                                    <option value="yesterday">Yesterday (-1 Day)</option>
                                    <option value="+7_days">+7 Days (Next Week)</option>
                                    <option value="+14_days">+14 Days</option>
                                    <option value="+30_days">+30 Days (Next Month)</option>
                                    <option value="-7_days">-7 Days</option>
                                    <option value="-14_days">-14 Days</option>
                                    <option value="-30_days">-30 Days</option>
                                    <option value="now">Current Time (Now)</option>
                                </select>
                            </div>
                        </div>
                        <input type="text" id="fieldDefault" class="form-control" placeholder="Default value" oninput="updatePresetUiFromValue(this.value)">
                        <select id="fieldDefaultAssigned" class="form-control" style="display:none;" onchange="document.getElementById('fieldDefault').value = this.value">
                            <option value="">-- None (Leave Empty) --</option>
                            <option value="current_user">⚡ Current Logged-in User</option>
                            <optgroup label="Or select a specific user:">
                                <?php foreach ($usersList as $u): ?>
                                    <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                        <div id="fieldPresetBadge" style="display:none; margin-top:4px; font-size:11px; font-weight:600; color:var(--primary);">
                            <i class="fa-solid fa-bolt"></i> <span id="fieldPresetText">Dynamic preset active</span>
                        </div>
                    </div>
                </div>
                <div class="mm-checkbox-row">
                    <label><input type="checkbox" id="fieldRequired"> Required</label>
                    <label><input type="checkbox" id="fieldUnique"> Unique</label>
                    <label><input type="checkbox" id="fieldSearchable"> Searchable</label>
                    <label><input type="checkbox" id="fieldListVisible" checked> Show in List</label>
                    <label><input type="checkbox" id="fieldMobileListVisible"> Show in Mobile List</label>
                    <label><input type="checkbox" id="fieldQuickCreate"> Quick Create</label>
                    <label><input type="checkbox" id="fieldIsTitle"> Title (Record Name)</label>
                </div>
                <!-- Options for dropdown/multi_picker/radio_group -->
                <div id="fieldOptionsSection" style="display:none;" class="mm-options-section">
                    <label class="form-label">Options</label>
                    <div id="fieldOptionsList"></div>
                    <button class="mm-btn mm-btn-sm" onclick="addOptionRow()"><i class="fa-solid fa-plus"></i> Add
                        Option</button>
                </div>
                <!-- Config for api_call_picker -->
                <div id="fieldApiConfig" style="display:none;">
                    <label class="form-label">Linked Module</label>
                    <select id="fieldLinkedModule" class="form-control">
                        <option value="">Select module...</option>
                        <?php foreach ($allModules as $m): ?>
                            <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="mm-modal-footer">
                <button class="mm-btn" onclick="closeModal('addFieldModal')">Cancel</button>
                <button class="mm-btn mm-btn-primary" onclick="saveField()"><i class="fa-solid fa-check"></i> Save
                    Field</button>
            </div>
        </div>
    </div>

    <!-- Rules Modal -->
    <div class="mm-modal-overlay" id="rulesModal">
        <div class="mm-modal mm-modal-lg">
            <div class="mm-modal-header">
                <h3>Field Rules</h3><button class="mm-icon-btn" onclick="closeModal('rulesModal')"><i
                        class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="mm-modal-body">
                <input type="hidden" id="rulesFieldId">
                <div id="rulesList"></div>
                <button class="mm-btn mm-btn-sm" onclick="addRuleRow()"><i class="fa-solid fa-plus"></i> Add
                    Rule</button>
            </div>
            <div class="mm-modal-footer">
                <button class="mm-btn" onclick="closeModal('rulesModal')">Cancel</button>
                <button class="mm-btn mm-btn-primary" onclick="saveRules()"><i class="fa-solid fa-check"></i> Save
                    Rules</button>
            </div>
        </div>
    </div>



    <!-- Add/Edit Workflow Modal -->
    <div class="mm-modal-overlay" id="workflowModal">
        <div class="mm-modal mm-modal-lg" style="background:#fff; border-radius:12px; width:700px; box-shadow:var(--shadow-lg);">
            <div class="mm-modal-header" style="display:flex; justify-content:space-between; align-items:center; padding:20px; border-bottom:1px solid var(--border);">
                <h3 id="workflowModalTitle" style="margin:0; font-size:18px; font-weight:700; color:var(--text-main);">Add Workflow Rule</h3>
                <button class="mm-icon-btn" onclick="closeModal('workflowModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="mm-modal-body" style="padding:20px; max-height:70vh; overflow-y:auto;">
                <input type="hidden" id="workflowId">
                
                <div class="form-group" style="margin-bottom:15px; display:flex; flex-direction:column; gap:6px;">
                    <label class="form-label" style="font-weight:600; font-size:13px; color:var(--text);">Rule Name *</label>
                    <input type="text" id="workflowName" class="form-control" placeholder="e.g. Send Email to Lead on Status Change" style="width:100%; box-sizing:border-box;">
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:15px;">
                    <div class="form-group" style="display:flex; flex-direction:column; gap:6px;">
                        <label class="form-label" style="font-weight:600; font-size:13px; color:var(--text);">Execute Workflow *</label>
                        <select id="workflowTriggerEvent" class="form-control" style="width:100%;">
                            <option value="create_or_edit">When Record is Created or Edited</option>
                            <option value="create">When Record is Created Only</option>
                            <option value="edit">When Record is Edited Only</option>
                        </select>
                    </div>
                    <div class="form-group" style="display:flex; flex-direction:column; gap:6px;">
                        <label class="form-label" style="font-weight:600; font-size:13px; color:var(--text);">Trigger Condition *</label>
                        <select id="workflowConditionType" class="form-control" onchange="onWorkflowConditionTypeChange()" style="width:100%;">
                            <option value="field_value">Specific Field Matches Value</option>
                            <option value="field_changed">Specific Field is Changed</option>
                            <option value="always">Always (No Condition)</option>
                        </select>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:15px;">
                    <div class="form-group" id="workflowFieldGroup" style="display:flex; flex-direction:column; gap:6px;">
                        <label class="form-label" style="font-weight:600; font-size:13px; color:var(--text);">When Field *</label>
                        <select id="workflowTriggerField" class="form-control" onchange="onWorkflowTriggerFieldChange()" style="width:100%;">
                            <option value="">-- Select trigger field --</option>
                        </select>
                    </div>
                    <div class="form-group" id="workflowValueGroup" style="display:flex; flex-direction:column; gap:6px;">
                        <label class="form-label" style="font-weight:600; font-size:13px; color:var(--text);">Changes to Value *</label>
                        <div id="workflowTriggerValueContainer">
                            <input type="text" id="workflowTriggerValue" class="form-control" placeholder="Trigger value" style="width:100%;">
                        </div>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:15px;">
                    <div class="form-group" style="display:flex; flex-direction:column; gap:6px;">
                        <label class="form-label" style="font-weight:600; font-size:13px; color:var(--text);">Action *</label>
                        <select id="workflowActionType" class="form-control" onchange="onWorkflowActionTypeChange()" style="width:100%; margin-bottom:8px;">
                            <option value="email">Send Email</option>
                            <option value="whatsapp">Send WhatsApp Message</option>
                            <option value="push">Send Push Notification</option>
                        </select>
                        <div id="workflowConfigContainer" style="display:block;">
                            <label class="form-label" style="font-weight:600; font-size:13px; color:var(--text);">Send From Config</label>
                            <select id="workflowCommConfig" class="form-control" style="width:100%;">
                                <option value="">-- Use Default --</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group" style="display:flex; flex-direction:column; gap:6px;">
                        <label class="form-label" style="font-weight:600; font-size:13px; color:var(--text);">Recipient *</label>
                        <select id="workflowRecipientField" class="form-control" onchange="onWorkflowRecipientFieldChange()" style="width:100%; margin-bottom:8px;">
                            <option value="">-- Select Recipient Field --</option>
                        </select>
                        <div id="workflowRecipientCustomContainer">
                            <input type="text" id="workflowRecipientCustom" class="form-control" placeholder="Or enter static email/phone..." style="width:100%; box-sizing:border-box;">
                        </div>
                    </div>
                </div>

                <!-- Template Subject -->
                <div class="form-group" id="workflowSubjectGroup" style="margin-bottom:15px; display:flex; flex-direction:column; gap:6px;">
                    <label class="form-label" style="font-weight:600; font-size:13px; color:var(--text);">Email Subject *</label>
                    <input type="text" id="workflowSubject" class="form-control" placeholder="Email subject" style="width:100%; box-sizing:border-box;">
                </div>

                <!-- Template Body -->
                <div class="form-group" style="margin-bottom:15px; display:flex; flex-direction:column; gap:6px;">
                    <label class="form-label" style="font-weight:600; font-size:13px; color:var(--text);">Message Body *</label>
                    <textarea id="workflowBody" class="form-control" rows="6" placeholder="Message content..." style="width:100%; box-sizing:border-box; font-family:inherit;"></textarea>
                </div>

                <!-- Placeholders guide -->
                <div style="background:rgba(123,94,240,0.06); padding:15px; border-radius:8px; font-size:12px; border:1px solid rgba(123,94,240,0.12);">
                    <h4 style="margin:0 0 8px; font-weight:700; color:var(--primary); font-size:13px;">Template Placeholders</h4>
                    <p style="margin:0 0 10px; color:var(--text-muted);">Click any field label to insert its tag dynamically into Subject or Body:</p>
                    <div id="workflowPlaceholdersList" style="display:flex; flex-wrap:wrap; gap:6px;">
                        <!-- Populate placeholders dynamically -->
                    </div>
                </div>
            </div>
            <div class="mm-modal-footer" style="display:flex; justify-content:flex-end; gap:10px; padding:20px; border-top:1px solid var(--border);">
                <button class="mm-btn" onclick="closeModal('workflowModal')">Cancel</button>
                <button class="mm-btn mm-btn-primary" onclick="saveWorkflow()"><i class="fa-solid fa-check"></i> Save Workflow</button>
            </div>
        </div>
    </div>

    <!-- Add/Edit Conversion Rule Modal -->
    <div class="mm-modal-overlay" id="conversionModal">
        <div class="mm-modal mm-modal-lg" style="background:#fff; border-radius:12px; width:700px; box-shadow:var(--shadow-lg);">
            <div class="mm-modal-header" style="display:flex; justify-content:space-between; align-items:center; padding:20px; border-bottom:1px solid var(--border);">
                <h3 id="conversionModalTitle" style="margin:0; font-size:18px; font-weight:700; color:var(--text-main);">Add Conversion Rule</h3>
                <button class="mm-icon-btn" onclick="closeModal('conversionModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="mm-modal-body" style="padding:20px; max-height:70vh; overflow-y:auto;">
                <input type="hidden" id="conversionRuleId">
                
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:15px;">
                    <div class="form-group" style="display:flex; flex-direction:column; gap:6px;">
                        <label class="form-label" style="font-weight:600; font-size:13px; color:var(--text);">Target Module *</label>
                        <select id="conversionTargetModule" class="form-control" onchange="onConversionTargetModuleChange()" style="width:100%;">
                            <option value="">-- Choose Target Module --</option>
                            <?php foreach ($allModules as $m): if ($editModule && $m['id'] == $editModule['id']) continue; ?>
                                <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="display:flex; flex-direction:column; gap:6px;">
                        <label class="form-label" style="font-weight:600; font-size:13px; color:var(--text);">Button Label *</label>
                        <input type="text" id="conversionButtonLabel" class="form-control" placeholder="e.g. Convert to Lead" style="width:100%; box-sizing:border-box;">
                    </div>
                </div>

                <div style="margin-top:20px; margin-bottom:15px;">
                    <h4 style="margin:0 0 10px; font-size:14px; font-weight:700; color:var(--text-main);">Field Mappings</h4>
                    <p style="margin:0 0 15px; font-size:12px; color:var(--text-muted);">Map the fields of the Target Module to corresponding source fields in the current module:</p>
                    <div id="conversionMappingsContainer" style="display:flex; flex-direction:column; gap:10px;">
                        <!-- Populate mapping rows dynamically -->
                        <div style="text-align:center; padding:20px; color:var(--text-muted); font-size:13px; border:1px dashed var(--border); border-radius:8px;">
                            Please choose a Target Module first.
                        </div>
                    </div>
                </div>
            </div>
            <div class="mm-modal-footer" style="display:flex; justify-content:flex-end; gap:10px; padding:20px; border-top:1px solid var(--border);">
                <button class="mm-btn" onclick="closeModal('conversionModal')">Cancel</button>
                <button class="mm-btn mm-btn-primary" onclick="saveConversionRule()"><i class="fa-solid fa-check"></i> Save Rule</button>
            </div>
        </div>
    </div>

    <div class="mm-modal-overlay" id="permissionsModal">
        <div class="mm-modal" style="width:500px; border-radius:12px; box-shadow:var(--shadow-lg);">
            <div class="mm-modal-header" style="padding:20px; border-bottom:1px solid var(--border);">
                <h3><i class="fa-solid fa-shield-halved" style="color:var(--primary); margin-right:8px;"></i> Module Permissions</h3>
                <button class="mm-icon-btn" onclick="closeModal('permissionsModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="mm-modal-body" style="padding:20px; max-height:70vh; overflow-y:auto;">
                <div class="form-group" style="margin-bottom:20px;">
                    <label class="form-label" style="font-weight:600; color:var(--text-main);">View Visibility</label>
                    <p style="font-size:12px; color:var(--text-muted); margin:0 0 8px 0;">Who can see the records?</p>
                    <select id="permVisibility" class="form-control" onchange="toggleSpecificRoles('view')">
                        <option value="all">Show to all</option>
                        <option value="owner">Creator Only</option>
                        <option value="role_down">Creator & Upper Roles (Managers)</option>
                        <option value="role_equal_down">Creator, Peers & Upper Roles</option>
                        <option value="role_up">Creator & Lower Roles (Subordinates)</option>
                        <option value="specific_roles">Specific Roles...</option>
                    </select>
                    <div id="permViewRolesContainer" style="display:none; margin-top:10px; background:var(--surface); padding:10px; border:1px solid var(--border); border-radius:8px; max-height:180px; overflow-y:auto;">
                        <!-- Checkboxes populated via JS -->
                    </div>
                    <button type="button" id="permViewRolesDoneBtn" style="display:none; margin-top:6px; padding:6px 16px; font-size:12px; font-weight:600; background:var(--primary); color:#fff; border:none; border-radius:6px; cursor:pointer;" onclick="document.getElementById('permViewRolesContainer').style.display='none'; this.style.display='none';">Done</button>
                </div>

                <div class="form-group" style="margin-bottom:20px; border-top:1px solid var(--border); padding-top:16px;">
                    <label class="form-label" style="font-weight:600; color:var(--text-main);">Edit Permission</label>
                    <p style="font-size:12px; color:var(--text-muted); margin:0 0 8px 0;">Who can edit existing records?</p>
                    <select id="permEditRule" class="form-control" onchange="toggleSpecificRoles('edit')">
                        <option value="all">Anyone who can view</option>
                        <option value="owner">Creator Only</option>
                        <option value="role_down">Creator & Upper Roles</option>
                        <option value="role_equal_down">Creator, Peers & Upper Roles</option>
                        <option value="role_up">Creator & Lower Roles</option>
                        <option value="specific_roles">Specific Roles...</option>
                    </select>
                    <div id="permEditRolesContainer" style="display:none; margin-top:10px; background:var(--surface); padding:10px; border:1px solid var(--border); border-radius:8px; max-height:180px; overflow-y:auto;">
                        <!-- Checkboxes populated via JS -->
                    </div>
                    <button type="button" id="permEditRolesDoneBtn" style="display:none; margin-top:6px; padding:6px 16px; font-size:12px; font-weight:600; background:var(--primary); color:#fff; border:none; border-radius:6px; cursor:pointer;" onclick="document.getElementById('permEditRolesContainer').style.display='none'; this.style.display='none';">Done</button>
                </div>

                <div class="form-group" style="margin-bottom:20px; border-top:1px solid var(--border); padding-top:16px;">
                    <label class="form-label" style="font-weight:600; color:var(--text-main);">Delete Permission</label>
                    <p style="font-size:12px; color:var(--text-muted); margin:0 0 8px 0;">Who can delete records?</p>
                    <select id="permDeleteRule" class="form-control" onchange="toggleSpecificRoles('delete')">
                        <option value="all">Anyone who can view</option>
                        <option value="owner">Creator Only</option>
                        <option value="role_down">Creator & Upper Roles</option>
                        <option value="role_equal_down">Creator, Peers & Upper Roles</option>
                        <option value="role_up">Creator & Lower Roles</option>
                        <option value="specific_roles">Specific Roles...</option>
                        <option value="admin_only">Only Admin</option>
                        <option value="upper_role_only">Only Upper Role</option>
                    </select>
                    <div id="permDeleteRolesContainer" style="display:none; margin-top:10px; background:var(--surface); padding:10px; border:1px solid var(--border); border-radius:8px; max-height:180px; overflow-y:auto;">
                        <!-- Checkboxes populated via JS -->
                    </div>
                    <button type="button" id="permDeleteRolesDoneBtn" style="display:none; margin-top:6px; padding:6px 16px; font-size:12px; font-weight:600; background:var(--primary); color:#fff; border:none; border-radius:6px; cursor:pointer;" onclick="document.getElementById('permDeleteRolesContainer').style.display='none'; this.style.display='none';">Done</button>
                </div>

                <div class="form-group" style="margin-bottom:20px; border-top:1px solid var(--border); padding-top:16px;">
                    <label class="form-label" style="font-weight:600; color:var(--text-main);">Feature Controls</label>
                    <p style="font-size:12px; color:var(--text-muted); margin:0 0 10px 0;">Enable or disable features for non-admin users:</p>
                    <div style="display:flex; flex-direction:column; gap:8px;">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:13px; font-weight:600; color:var(--text-main); user-select:none; margin:0;">
                            <input type="checkbox" id="permEnableImport" style="accent-color:var(--primary); width:16px; height:16px; cursor:pointer;"> Enable Import
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:13px; font-weight:600; color:var(--text-main); user-select:none; margin:0;">
                            <input type="checkbox" id="permEnableExport" style="accent-color:var(--primary); width:16px; height:16px; cursor:pointer;"> Enable Export
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:13px; font-weight:600; color:var(--text-main); user-select:none; margin:0;">
                            <input type="checkbox" id="permEnableMultiDelete" style="accent-color:var(--primary); width:16px; height:16px; cursor:pointer;"> Enable Multi Delete
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:13px; font-weight:600; color:var(--text-main); user-select:none; margin:0;">
                            <input type="checkbox" id="permEnableCreate" style="accent-color:var(--primary); width:16px; height:16px; cursor:pointer;"> Enable Create
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:13px; font-weight:600; color:var(--text-main); user-select:none; margin:0;">
                            <input type="checkbox" id="permEnableQuickCreate" style="accent-color:var(--primary); width:16px; height:16px; cursor:pointer;"> Enable Quick Create
                        </label>
                    </div>
                </div>
            </div>
            <div class="mm-modal-footer" style="padding:20px; border-top:1px solid var(--border);">
                <button class="mm-btn" onclick="closeModal('permissionsModal')">Cancel</button>
                <button class="mm-btn mm-btn-primary" onclick="savePermissions()"><i class="fa-solid fa-check"></i> Save Permissions</button>
            </div>
        </div>
    </div>

    <script>
        const API = '/api/modules.php';
        const MODULE_ID = <?= $editModule ? $editModule['id'] : 'null' ?>;
        const ALL_FIELD_TYPES = <?= json_encode($fieldTypes) ?>;
        const EDIT_MODULE_FIELDS = <?= json_encode($jsFields) ?>;
        const ALL_USERS = <?= json_encode($usersList) ?>;
        const ALL_ROLES = <?= json_encode($rolesList) ?>;
        const ALL_COUNTRIES = <?= json_encode(dm_get_countries()) ?>;
        const ALL_STATES = <?= json_encode(dm_get_states()) ?>;
        const ALL_DISTRICTS = <?= json_encode(dm_get_districts()) ?>;
        const commConfigs = <?= json_encode($commConfigs) ?>;
        
        <?php if ($editModule): ?>
        const MODULE_PERMS = {
            visibility: <?= json_encode($editModule['visibility_rule'] ?? 'all') ?>,
            edit_rule: <?= json_encode($editModule['edit_rule'] ?? 'all') ?>,
            edit_roles: <?= json_encode(explode(',', $editModule['edit_roles'] ?? '')) ?>,
            delete_rule: <?= json_encode($editModule['delete_rule'] ?? 'all') ?>,
            delete_roles: <?= json_encode(explode(',', $editModule['delete_roles'] ?? '')) ?>,
            enable_import: <?= (int)($editModule['enable_import'] ?? 1) ?>,
            enable_export: <?= (int)($editModule['enable_export'] ?? 1) ?>,
            enable_multidelete: <?= (int)($editModule['enable_multidelete'] ?? 1) ?>,
            enable_create: <?= (int)($editModule['enable_create'] ?? 1) ?>,
            enable_quickcreate: <?= (int)($editModule['enable_quickcreate'] ?? 1) ?>
        };
        <?php endif; ?>


        function api(action, data = {}) {
            return fetch(API, {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action, ...data })
            }).then(r => r.json());
        }
        function openModal(id) { document.getElementById(id).classList.add('show'); }
        function openCreateModal() { document.getElementById('createModuleModal').classList.add('show'); document.getElementById('newModuleName').focus(); }
        function closeModal(id) { document.getElementById(id).classList.remove('show'); }

        function createModule() {
            const name = document.getElementById('newModuleName').value.trim();
            if (!name) return alert('Name is required');
            api('create', {
                name,
                icon: document.getElementById('newModuleIcon').value,
                description: document.getElementById('newModuleDesc').value,
                visibility_rule: document.getElementById('newModuleVisibility').value
            })
                .then(r => { if (r.success) location.href = 'module_manager.php?edit=' + r.id; else vyToast(r.error, 'error'); });
        }
        function deleteModule(id, name) {
            if (!confirm('Delete module "' + name + '" and all its data?')) return;
            api('delete', { id }).then(r => { if (r.success) location.reload(); else alert(r.error); });
        }
        function editModuleName() {
            const el = document.getElementById('moduleNameDisplay');
            const name = prompt('Module Name:', el.textContent);
            if (!name) return;
            api('update', { id: MODULE_ID, name }).then(r => { if (r.success) el.textContent = name; else alert(r.error); });
        }

        function toggleModuleStatus(id, currentStatus) {
            const status = currentStatus === 'active' ? 'inactive' : 'active';
            api('update', { id, status }).then(r => {
                if (r.success) location.reload();
                else alert(r.error);
            });
        }
        function toggleSystemModule(key, enabled, checkbox) {
            // Map setting key → sidebar container ID
            const sidebarMap = {
                'attendance_enabled': 'sidebar-module-attendance',
                'billing_enabled':    'sidebar-module-billing',
                'campaigns_enabled':  'sidebar-module-campaigns',
                'payroll_enabled':    'sidebar-module-payroll',
                'calls_enabled':      'sidebar-module-calls'
            };

            // Optimistically update pill visual
            if (checkbox) {
                const pill = checkbox.parentElement.querySelector('.sys-toggle-pill');
                if (pill) pill.classList.toggle('active', enabled);
            }
            // Optimistically update sidebar
            const sidebarEl = document.getElementById(sidebarMap[key]);
            if (sidebarEl) sidebarEl.style.display = enabled ? '' : 'none';
            
            if (key === 'billing_enabled') {
                const invoiceSettingsEl = document.getElementById('sidebar-invoice-settings');
                if (invoiceSettingsEl) invoiceSettingsEl.style.display = enabled ? '' : 'none';
            }

            if (key === 'calls_enabled') {
                const callsCtrl = document.getElementById('calls-settings-controls');
                if (callsCtrl) callsCtrl.style.display = enabled ? 'block' : 'none';
            }

            api('update_system_setting', { key, value: enabled ? '1' : '0' }).then(r => {
                if (r.success) {
                    if (key === 'calls_enabled') {
                        location.reload();
                    }
                } else {
                    // Revert on failure
                    if (checkbox) {
                        checkbox.checked = !enabled;
                        const pill = checkbox.parentElement.querySelector('.sys-toggle-pill');
                        if (pill) pill.classList.toggle('active', !enabled);
                    }
                    if (sidebarEl) sidebarEl.style.display = enabled ? 'none' : '';
                    if (key === 'billing_enabled') {
                        const invoiceSettingsEl = document.getElementById('sidebar-invoice-settings');
                        if (invoiceSettingsEl) invoiceSettingsEl.style.display = enabled ? 'none' : '';
                    }
                    alert(r.error || 'Failed to save setting.');
                }
            });
        }
        function updateSystemVisibility(key, val) {
            api('update_system_setting', { key, value: val }).then(r => {
                if (!r.success) alert(r.error || 'Failed to save setting.');
            });
        }

        // No custom pill click listener needed — <label> natively toggles the hidden checkbox

        function toggleModuleStatus(id, enabled, checkbox) {
            const card = document.getElementById('mod-card-' + id);
            const pill = checkbox ? checkbox.parentElement.querySelector('.sys-toggle-pill') : null;

            // Optimistic UI update
            if (pill) pill.classList.toggle('active', enabled);
            if (card) card.classList.toggle('mm-inactive', !enabled);

            // Update sidebar nav item for this module
            const sidebarLink = document.querySelector('#group-dyn a[href="module_view.php?module=' + id + '"]');
            if (sidebarLink) sidebarLink.style.display = enabled ? '' : 'none';

            api('toggle_module_status', { id: id, status: enabled ? 'active' : 'inactive' }).then(r => {
                if (!r.success) {
                    // Revert on failure
                    if (checkbox) checkbox.checked = !enabled;
                    if (pill) pill.classList.toggle('active', !enabled);
                    if (card) card.classList.toggle('mm-inactive', enabled);
                    if (sidebarLink) sidebarLink.style.display = enabled ? 'none' : '';
                    alert(r.error || 'Failed to update module status.');
                }
            });
        }

        // Icon Picker Setup
        const ICONS = [
            'fa-solid fa-cube', 'fa-solid fa-cubes', 'fa-solid fa-box', 'fa-solid fa-boxes-stacked',
            'fa-solid fa-users', 'fa-solid fa-user', 'fa-solid fa-user-tie', 'fa-solid fa-address-book',
            'fa-solid fa-building', 'fa-solid fa-store', 'fa-solid fa-shop', 'fa-solid fa-industry',
            'fa-solid fa-chart-line', 'fa-solid fa-chart-pie', 'fa-solid fa-chart-bar', 'fa-solid fa-receipt',
            'fa-solid fa-file-invoice', 'fa-solid fa-file-invoice-dollar', 'fa-solid fa-file-lines', 'fa-solid fa-file-contract',
            'fa-solid fa-wallet', 'fa-solid fa-money-bill', 'fa-solid fa-money-bill-wave', 'fa-solid fa-coins',
            'fa-solid fa-credit-card', 'fa-solid fa-tags', 'fa-solid fa-tag', 'fa-solid fa-cart-shopping',
            'fa-solid fa-truck', 'fa-solid fa-truck-fast', 'fa-solid fa-calendar', 'fa-solid fa-calendar-days',
            'fa-solid fa-clock', 'fa-solid fa-bell', 'fa-solid fa-envelope', 'fa-solid fa-phone',
            'fa-solid fa-location-dot', 'fa-solid fa-map', 'fa-solid fa-earth-americas', 'fa-solid fa-globe',
            'fa-solid fa-heart', 'fa-solid fa-star', 'fa-solid fa-thumbs-up', 'fa-solid fa-bookmark',
            'fa-solid fa-gear', 'fa-solid fa-wrench', 'fa-solid fa-screwdriver-wrench', 'fa-solid fa-shield-halved'
        ];

        function initIconPicker() {
            const grid = document.getElementById('iconPickerGrid');
            if (!grid) return;
            grid.innerHTML = '';
            ICONS.forEach(icon => {
                const div = document.createElement('div');
                div.className = 'mm-icon-option';
                div.innerHTML = `<i class="${icon}"></i>`;
                div.title = icon;
                div.onclick = () => selectIcon(icon);
                grid.appendChild(div);
            });
        }

        function selectIcon(icon) {
            document.getElementById('newModuleIcon').value = icon;
            document.getElementById('selectedIconPreview').className = icon;
            document.getElementById('selectedIconName').textContent = icon;
            document.getElementById('iconPickerGrid').style.display = 'none';

            document.querySelectorAll('.mm-icon-option').forEach(el => {
                el.classList.toggle('active', el.querySelector('i').className === icon);
            });
        }

        function initFieldDragging() {
            const lists = document.querySelectorAll('.mm-fields-list');
            lists.forEach(list => {
                const blockId = list.id.replace('fields-', '');
                list.setAttribute('data-block-id', blockId);
                
                new Sortable(list, {
                    group: 'fields',
                    handle: '.mm-field-drag-handle',
                    animation: 150,
                    ghostClass: 'sortable-ghost',
                    chosenClass: 'sortable-chosen',
                    onEnd: function(evt) {
                        saveFieldOrder();
                    }
                });
            });
        }

        function saveFieldOrder() {
            const orders = [];
            const lists = document.querySelectorAll('.mm-fields-list');
            lists.forEach(list => {
                const blockId = parseInt(list.getAttribute('data-block-id'));
                const rows = list.querySelectorAll('.mm-field-row');
                
                // Show/hide empty fields placeholder if list gets empty
                const emptyPlaceholder = list.querySelector('.mm-empty-fields');
                if (rows.length === 0) {
                    if (!emptyPlaceholder) {
                        const placeholder = document.createElement('div');
                        placeholder.className = 'mm-empty-fields';
                        placeholder.textContent = 'No fields yet. Click "Add Field" to start.';
                        list.appendChild(placeholder);
                    }
                } else {
                    if (emptyPlaceholder) {
                        emptyPlaceholder.remove();
                    }
                }
                
                rows.forEach((row, index) => {
                    const fieldId = parseInt(row.getAttribute('data-field-id'));
                    orders.push({
                        id: fieldId,
                        sort_order: index + 1,
                        block_id: blockId
                    });
                });
            });
            
            api('reorder_fields', { orders })
                .then(r => {
                    if (r.success) {
                        vyToast('Fields reordered successfully!', 'success');
                    } else {
                        vyToast(r.error || 'Failed to reorder fields', 'error');
                    }
                })
                .catch(e => {
                    vyToast('Error reordering fields: ' + e.message, 'error');
                });
        }

        document.addEventListener('DOMContentLoaded', () => {
            initIconPicker();
            initFieldDragging();
            api('get_users').then(r => {
                if (r && r.success && r.users) {
                    window.ALL_USERS = r.users.map(u => {
                        const fullName = ((u.first_name || '') + ' ' + (u.last_name || '')).trim();
                        const roleStr = u.role_name ? ` (${u.role_name})` : '';
                        const name = fullName ? `${fullName} (${u.username})${roleStr}` : `${u.username}${roleStr}`;
                        return { id: u.id, username: u.username, name: name };
                    });
                    populateAssignedUsersDropdown(document.getElementById('fieldDefaultAssigned')?.value || document.getElementById('fieldDefault')?.value || '');
                }
            }).catch(e => {});
        });
        function addBlock() {
            const name = prompt('Block Name:', 'New Block');
            if (!name) return;
            api('create_block', { module_id: MODULE_ID, name }).then(r => { if (r.success) location.reload(); else alert(r.error); });
        }
        function editBlockName(id, btn) {
            const span = btn.closest('.mm-block-title').querySelector('.block-name-text');
            const name = prompt('Block Name:', span.textContent);
            if (!name) return;
            api('update_block', { id, name }).then(r => { if (r.success) span.textContent = name; else alert(r.error); });
        }
        function deleteBlock(id) {
            if (!confirm('Delete this block and all its fields?')) return;
            api('delete_block', { id }).then(r => { if (r.success) location.reload(); else alert(r.error); });
        }

        // Field Modal
        function openFieldModal(blockId, moduleId, editData = null) {
            document.getElementById('fieldBlockId').value = blockId;
            document.getElementById('fieldModuleId').value = moduleId;
            document.getElementById('fieldEditId').value = editData ? editData.id : '';
            document.getElementById('fieldModalTitle').textContent = editData ? 'Edit Field' : 'Add Field';
            document.getElementById('fieldLabel').value = editData ? editData.label : '';
            document.getElementById('fieldType').value = editData ? editData.field_type : 'text';
            document.getElementById('fieldPlaceholder').value = editData ? (editData.placeholder || '') : '';
            const defVal = editData ? (editData.default_value || '') : '';
            document.getElementById('fieldDefault').value = defVal;
            document.getElementById('fieldDefaultAssigned').value = defVal;
            document.getElementById('fieldRequired').checked = editData ? !!editData.is_required : false;
            document.getElementById('fieldUnique').checked = editData ? !!editData.is_unique : false;
            document.getElementById('fieldSearchable').checked = editData ? !!editData.is_searchable : false;
            document.getElementById('fieldListVisible').checked = editData ? !!editData.is_list_visible : true;
            document.getElementById('fieldMobileListVisible').checked = editData ? (editData.is_mobile_list_visible !== undefined ? (editData.is_mobile_list_visible == 1 || editData.is_mobile_list_visible === true) : false) : false;
            document.getElementById('fieldQuickCreate').checked = editData ? !!editData.is_quick_create : false;
            
            let configObj = {};
            if (editData && editData.config) {
                try { configObj = typeof editData.config === 'string' ? JSON.parse(editData.config) : editData.config; } catch(e){}
            }
            document.getElementById('fieldIsTitle').checked = !!configObj.is_title;
            
            document.getElementById('fieldOptionsList').innerHTML = '';
            if (editData && editData.options) editData.options.forEach(o => addOptionRow(o.label, o.value));
            onFieldTypeChange();
            updatePresetUiFromValue(defVal);
            document.getElementById('addFieldModal').classList.add('show');
        }
        function editField(id, fieldData) { openFieldModal(fieldData.block_id, fieldData.module_id, fieldData); }
        let defaultPickerInstance = null;

        const DYNAMIC_PRESET_LABELS = {
            'current_user': 'Dynamic: Automatically sets to the Name of the user creating the record',
            '{current_user}': 'Dynamic: Automatically sets to the Name of the user creating the record',
            'current_user_id': 'Dynamic: Automatically sets to the User ID of the user creating the record',
            '{current_user_id}': 'Dynamic: Automatically sets to the User ID of the user creating the record',
            'current_user_email': 'Dynamic: Automatically sets to the Email of the user creating the record',
            '{current_user_email}': 'Dynamic: Automatically sets to the Email of the user creating the record',
            'today': 'Dynamic: Automatically sets to Current Date (Today) when record is created',
            '{today}': 'Dynamic: Automatically sets to Current Date (Today) when record is created',
            'tomorrow': 'Dynamic: Automatically sets to Tomorrow (+1 Day) when record is created',
            'yesterday': 'Dynamic: Automatically sets to Yesterday (-1 Day) when record is created',
            '+7_days': 'Dynamic: Automatically sets to 7 Days Ahead when record is created',
            '+14_days': 'Dynamic: Automatically sets to 14 Days Ahead when record is created',
            '+30_days': 'Dynamic: Automatically sets to 30 Days Ahead when record is created',
            '-7_days': 'Dynamic: Automatically sets to 7 Days Before when record is created',
            '-14_days': 'Dynamic: Automatically sets to 14 Days Before when record is created',
            '-30_days': 'Dynamic: Automatically sets to 30 Days Before when record is created',
            'now': 'Dynamic: Automatically sets to Current Time / Timestamp when record is created',
            '{now}': 'Dynamic: Automatically sets to Current Time / Timestamp when record is created',
        };

        function applyDatePreset(val) {
            const defInput = document.getElementById('fieldDefault');
            const badge = document.getElementById('fieldPresetBadge');
            const badgeText = document.getElementById('fieldPresetText');
            
            if (val) {
                if (defaultPickerInstance) {
                    defaultPickerInstance.clear();
                }
                defInput.value = val;
                badge.style.display = 'block';
                badgeText.textContent = DYNAMIC_PRESET_LABELS[val] || `Dynamic: ${val}`;
            } else {
                defInput.value = '';
                badge.style.display = 'none';
            }
        }

        function updatePresetUiFromValue(val) {
            const presetSelect = document.getElementById('fieldPresetSelect');
            const badge = document.getElementById('fieldPresetBadge');
            const badgeText = document.getElementById('fieldPresetText');
            const lower = (val || '').toString().trim().toLowerCase();

            if (presetSelect) {
                presetSelect.value = DYNAMIC_PRESET_LABELS[lower] ? lower : '';
            }
            if (badge && badgeText) {
                if (DYNAMIC_PRESET_LABELS[lower]) {
                    badge.style.display = 'block';
                    badgeText.textContent = DYNAMIC_PRESET_LABELS[lower];
                } else {
                    badge.style.display = 'none';
                }
            }
        }

        function populateAssignedUsersDropdown(selectedVal = '') {
            const select = document.getElementById('fieldDefaultAssigned');
            if (!select) return;
            const users = (typeof ALL_USERS !== 'undefined' ? ALL_USERS : (window.ALL_USERS || []));
            let html = '<option value="">-- None (Leave Empty) --</option>';
            html += '<option value="current_user">⚡ Current Logged-in User</option>';
            if (users && users.length > 0) {
                html += '<optgroup label="Or select a specific user:">';
                users.forEach(u => {
                    const isSel = (String(selectedVal) === String(u.id) || String(selectedVal) === String(u.username)) ? 'selected' : '';
                    html += `<option value="${u.id}" ${isSel}>${u.name || u.username}</option>`;
                });
                html += '</optgroup>';
            }
            select.innerHTML = html;
            if (selectedVal) select.value = selectedVal;
        }

        function onFieldTypeChange() {
            const t = document.getElementById('fieldType').value;
            const isAssigned = ['assigned_to', 'user', 'owner', 'author'].includes(t);
            const isDateOrTime = ['date', 'datetime', 'time'].includes(t);

            document.getElementById('fieldOptionsSection').style.display = (t === 'dropdown' || t === 'multi_picker' || t === 'radio_group') ? '' : 'none';
            document.getElementById('fieldApiConfig').style.display = t === 'api_call_picker' ? '' : 'none';

            document.getElementById('fieldDefaultAssigned').style.display = isAssigned ? '' : 'none';
            document.getElementById('fieldDefault').style.display = isAssigned ? 'none' : '';
            document.getElementById('fieldDynamicPresetGroup').style.display = isDateOrTime ? 'flex' : 'none';

            if (isAssigned) {
                const currentVal = document.getElementById('fieldDefault').value || document.getElementById('fieldDefaultAssigned').value || '';
                populateAssignedUsersDropdown(currentVal);
            }

            const defInput = document.getElementById('fieldDefault');
            if (defaultPickerInstance) {
                defaultPickerInstance.destroy();
                defaultPickerInstance = null;
            }

            if (t === 'date') {
                defInput.placeholder = 'YYYY-MM-DD or select preset';
                defaultPickerInstance = flatpickr(defInput, {
                    dateFormat: 'Y-m-d',
                    allowInput: true,
                    onChange: function(selectedDates, dateStr) {
                        if (dateStr) {
                            updatePresetUiFromValue(dateStr);
                        }
                    }
                });
            } else if (t === 'time') {
                defInput.placeholder = 'HH:MM or select preset';
                defaultPickerInstance = flatpickr(defInput, {
                    enableTime: true,
                    noCalendar: true,
                    dateFormat: 'H:i',
                    allowInput: true,
                    onChange: function(selectedDates, dateStr) {
                        if (dateStr) {
                            updatePresetUiFromValue(dateStr);
                        }
                    }
                });
            } else if (t === 'datetime') {
                defInput.placeholder = 'YYYY-MM-DD HH:MM or select preset';
                defaultPickerInstance = flatpickr(defInput, {
                    enableTime: true,
                    dateFormat: 'Y-m-d H:i',
                    allowInput: true,
                    onChange: function(selectedDates, dateStr) {
                        if (dateStr) {
                            updatePresetUiFromValue(dateStr);
                        }
                    }
                });
            } else {
                defInput.placeholder = '';
            }

            updatePresetUiFromValue(defInput.value);
        }
        function addOptionRow(label = '', value = '') {
            const div = document.createElement('div');
            div.className = 'mm-option-row';
            div.innerHTML = `<input type="text" class="form-control opt-label" placeholder="Label" value="${label}">
        <input type="text" class="form-control opt-value" placeholder="Value" value="${value || label}">
        <button class="mm-icon-btn mm-icon-danger" onclick="this.parentElement.remove()"><i class="fa-solid fa-xmark"></i></button>`;
            document.getElementById('fieldOptionsList').appendChild(div);
        }
        function saveField() {
            const editId = document.getElementById('fieldEditId').value;
            const t = document.getElementById('fieldType').value;
            const isAssigned = ['assigned_to', 'user', 'owner', 'author'].includes(t);
            const defVal = isAssigned ? document.getElementById('fieldDefaultAssigned').value : document.getElementById('fieldDefault').value;

            const data = {
                block_id: +document.getElementById('fieldBlockId').value,
                module_id: +document.getElementById('fieldModuleId').value,
                label: document.getElementById('fieldLabel').value.trim(),
                field_type: t,
                placeholder: document.getElementById('fieldPlaceholder').value,
                default_value: defVal,
                is_required: +document.getElementById('fieldRequired').checked,
                is_unique: +document.getElementById('fieldUnique').checked,
                is_searchable: +document.getElementById('fieldSearchable').checked,
                is_list_visible: +document.getElementById('fieldListVisible').checked,
                is_mobile_list_visible: +document.getElementById('fieldMobileListVisible').checked,
                is_quick_create: +document.getElementById('fieldQuickCreate').checked,
            };
            if (!data.label) return alert('Label is required');
            // Options
            const optRows = document.querySelectorAll('#fieldOptionsList .mm-option-row');
            if (optRows.length) {
                data.options = [...optRows].map(r => ({ label: r.querySelector('.opt-label').value, value: r.querySelector('.opt-value').value }));
            }
            // Config object
            data.config = {
                is_title: document.getElementById('fieldIsTitle').checked
            };
            
            // Config for api_call_picker
            if (data.field_type === 'api_call_picker') {
                data.config.linked_module_id = +document.getElementById('fieldLinkedModule').value;
            }
            const action = editId ? 'update_field' : 'create_field';
            if (editId) data.id = +editId;
            api(action, data).then(r => { if (r.success) location.reload(); else alert(r.error); });
        }
        function deleteField(id) {
            if (!confirm('Delete this field?')) return;
            api('delete_field', { id }).then(r => { if (r.success) location.reload(); else alert(r.error); });
        }
        function manageOptions(fieldId, options) {
            openFieldModal(0, MODULE_ID);
            document.getElementById('fieldEditId').value = fieldId;
            document.getElementById('fieldOptionsSection').style.display = '';
            document.getElementById('fieldOptionsList').innerHTML = '';
            (options || []).forEach(o => addOptionRow(o.label, o.value));
        }

        // Rules
        let rulesModuleFields = [];
        function manageRules(fieldId, moduleId) {
            document.getElementById('rulesFieldId').value = fieldId;
            document.getElementById('rulesList').innerHTML = '<p class="text-muted">Loading...</p>';
            // Fetch module fields for source selection
            api('get', { id: moduleId }).then(r => {
                if (!r.success) return;
                rulesModuleFields = [];
                (r.module.blocks || []).forEach(b => (b.fields || []).forEach(f => { rulesModuleFields.push(f); }));
                // Fetch existing rules
                const targetField = [];
                (r.module.blocks || []).forEach(b => (b.fields || []).forEach(f => { if (f.id === fieldId && f.rules) targetField.push(...f.rules); }));
                document.getElementById('rulesList').innerHTML = '';
                (targetField || []).forEach(rule => addRuleRow(rule));
                document.getElementById('rulesModal').classList.add('show');
            });
        }
        function addRuleRow(rule = {}) {
            const div = document.createElement('div');
            div.className = 'mm-rule-row';
            div.style.cssText = 'display:flex; gap:8px; align-items:center; margin-bottom:10px; flex-wrap:wrap;';
            let fieldOpts = rulesModuleFields.map(f => `<option value="${f.id}" ${rule.source_field_id == f.id ? 'selected' : ''}>${f.label}</option>`).join('');
            
            const cfg = rule.config ? (typeof rule.config === 'string' ? JSON.parse(rule.config) : rule.config) : {};
            const actionVal = rule.action_value || cfg.action_value || '';

            div.innerHTML = `
                <select class="form-control rule-source" onchange="onRuleSourceChange(this)" style="width:160px;">${fieldOpts}</select>
                <select class="form-control rule-op" onchange="onRuleOpChange(this)" style="width:140px;">
                    <option value="equals" ${rule.operator === 'equals' ? 'selected' : ''}>Equals</option>
                    <option value="not_equals" ${rule.operator === 'not_equals' ? 'selected' : ''}>Not Equals</option>
                    <option value="contains" ${rule.operator === 'contains' ? 'selected' : ''}>Contains</option>
                    <option value="not_empty" ${rule.operator === 'not_empty' ? 'selected' : ''}>Not Empty</option>
                    <option value="is_empty" ${rule.operator === 'is_empty' ? 'selected' : ''}>Is Empty</option>
                    <option value="greater_than" ${rule.operator === 'greater_than' ? 'selected' : ''}>Greater Than</option>
                    <option value="less_than" ${rule.operator === 'less_than' ? 'selected' : ''}>Less Than</option>
                    <option value="on_change" ${rule.operator === 'on_change' ? 'selected' : ''}>Value Changes (On Change)</option>
                </select>
                <div class="rule-value-container" style="flex: 1; min-width: 120px; display: flex;"></div>
                <select class="form-control rule-action" onchange="onRuleActionChange(this)" style="width:180px;">
                    <option value="show" ${rule.action === 'show' ? 'selected' : ''}>Show</option>
                    <option value="hide" ${rule.action === 'hide' ? 'selected' : ''}>Hide</option>
                    <option value="require" ${rule.action === 'require' ? 'selected' : ''}>Make Required</option>
                    <option value="optional" ${rule.action === 'optional' ? 'selected' : ''}>Make Optional</option>
                    <option value="set_value" ${rule.action === 'set_value' ? 'selected' : ''}>Set Field Value</option>
                    <option value="set_relative_date" ${rule.action === 'set_relative_date' ? 'selected' : ''}>Set Date (Relative Offset)</option>
                    <option value="copy_value" ${rule.action === 'copy_value' ? 'selected' : ''}>Copy Source Value</option>
                    <option value="update_other_module" ${rule.action === 'update_other_module' ? 'selected' : ''}>Update Other Module Field</option>
                </select>
                <div class="rule-action-value-container" style="flex: 2; min-width: 200px; display: flex; gap: 6px; flex-wrap: wrap;"></div>
                <select class="form-control rule-apply-on" title="When to apply this rule" style="width:135px;">
                    <option value="all" ${(cfg.apply_on === 'all' || !cfg.apply_on) ? 'selected' : ''}>Create & Edit</option>
                    <option value="edit_only" ${cfg.apply_on === 'edit_only' ? 'selected' : ''}>Edit Mode Only</option>
                    <option value="create_only" ${cfg.apply_on === 'create_only' ? 'selected' : ''}>Create Mode Only</option>
                </select>
                <button class="mm-icon-btn mm-icon-danger" onclick="this.parentElement.remove()"><i class="fa-solid fa-xmark"></i></button>`;
            document.getElementById('rulesList').appendChild(div);
            
            const sourceSelect = div.querySelector('.rule-source');
            onRuleSourceChange(sourceSelect, rule.value || '');
            const opSelect = div.querySelector('.rule-op');
            onRuleOpChange(opSelect);
            const actionSelect = div.querySelector('.rule-action');
            onRuleActionChange(actionSelect, actionVal, cfg);
        }

        function onRuleOpChange(opSelect) {
            const row = opSelect.closest('.mm-rule-row');
            if (!row) return;
            const valContainer = row.querySelector('.rule-value-container');
            if (!valContainer) return;
            
            const op = opSelect.value;
            if (op === 'not_empty' || op === 'is_empty' || op === 'on_change') {
                valContainer.style.display = 'none';
            } else {
                valContainer.style.display = 'flex';
            }
        }

        async function onRuleTargetModuleChange(modSelect, targetFieldId = 0) {
            const row = modSelect.closest('.mm-rule-row');
            if (!row) return;
            const fieldSelect = row.querySelector('.rule-target-field');
            if (!fieldSelect) return;
            
            const targetModId = modSelect.value;
            if (!targetModId) {
                fieldSelect.innerHTML = '<option value="">-- Target Field --</option>';
                return;
            }
            
            try {
                const r = await api('get', { id: targetModId });
                if (r.success && r.module) {
                    let fields = [];
                    (r.module.blocks || []).forEach(b => (b.fields || []).forEach(f => fields.push(f)));
                    fieldSelect.innerHTML = fields.map(f => `<option value="${f.id}" ${targetFieldId == f.id ? 'selected' : ''}>${escapeHtml(f.label)}</option>`).join('');
                }
            } catch(e) {
                console.error(e);
            }
        }

        async function onRuleActionChange(actionSelect, currentActionValue = '', cfg = {}) {
            const row = actionSelect.closest('.mm-rule-row');
            if (!row) return;
            const actionValContainer = row.querySelector('.rule-action-value-container');
            if (!actionValContainer) return;
            
            const action = actionSelect.value;
            if (action === 'set_value') {
                actionValContainer.style.display = 'flex';
                actionValContainer.innerHTML = `<input type="text" class="form-control rule-action-value" placeholder="Set value..." value="${escapeHtml(currentActionValue)}" style="width:100%;">`;
            } else if (action === 'set_relative_date') {
                actionValContainer.style.display = 'flex';
                const opts = [
                    { v: '-14_days_today', l: '14 Days Before Current Date (-14 Days)' },
                    { v: '-7_days_today', l: '7 Days Before Current Date (-7 Days)' },
                    { v: '0_days_today', l: 'Current Date / Today' },
                    { v: '+7_days_today', l: '7 Days After Current Date (+7 Days)' },
                    { v: '+14_days_today', l: '14 Days After Current Date (+14 Days)' },
                    { v: '+30_days_today', l: '30 Days After Current Date (+30 Days)' },
                    { v: '-14_days_source', l: '14 Days Before Source Date' },
                    { v: '+14_days_source', l: '14 Days After Source Date' },
                ];
                const optHtml = opts.map(o => `<option value="${o.v}" ${currentActionValue == o.v ? 'selected' : ''}>${o.l}</option>`).join('');
                actionValContainer.innerHTML = `
                    <select class="form-control rule-action-value" style="width:100%;">
                        ${optHtml}
                    </select>
                `;
            } else if (action === 'update_other_module') {
                actionValContainer.style.display = 'flex';
                
                // Fetch list of modules if needed
                let modOpts = '<option value="">-- Target Module --</option>';
                try {
                    const mListRes = await api('list');
                    if (mListRes.success && mListRes.modules) {
                        modOpts += mListRes.modules.map(m => `<option value="${m.id}" ${cfg.target_module_id == m.id ? 'selected' : ''}>${escapeHtml(m.name)}</option>`).join('');
                    }
                } catch(e) {}

                let currentModuleFieldOpts = rulesModuleFields.map(f => `<option value="field_${f.id}" ${(cfg.value_mode === ('field_' + f.id) || (cfg.value_mode === 'copy_value' && cfg.source_copy_field_id == f.id)) ? 'selected' : ''}>Copy Value From: ${escapeHtml(f.label)}</option>`).join('');

                actionValContainer.innerHTML = `
                    <select class="form-control rule-target-module" onchange="onRuleTargetModuleChange(this)" style="width:130px;">
                        ${modOpts}
                    </select>
                    <select class="form-control rule-target-field" style="width:130px;">
                        <option value="">-- Target Field --</option>
                    </select>
                    <select class="form-control rule-target-mode" style="width:200px;">
                        <option value="today" ${cfg.value_mode === 'today' ? 'selected' : ''}>Set Today's Date</option>
                        <option value="custom" ${cfg.value_mode === 'custom' ? 'selected' : ''}>Set Custom Static Value</option>
                        <optgroup label="Copy Value From Current Record">
                            ${currentModuleFieldOpts}
                        </optgroup>
                    </select>
                `;
                
                const modSelect = actionValContainer.querySelector('.rule-target-module');
                if (modSelect && modSelect.value) {
                    onRuleTargetModuleChange(modSelect, cfg.target_field_id || 0);
                }
            } else {
                actionValContainer.style.display = 'none';
                actionValContainer.innerHTML = '';
            }
        }

        async function createDynamicInputField(field, value = '', isRule = false) {
            const fType = field.field_type;
            const labelLower = (field.label || '').toLowerCase().trim();
            const idAttr = isRule ? '' : 'id="workflowTriggerValue"';
            const classAttr = isRule ? 'class="form-control rule-value"' : 'class="form-control"';
            const styleAttr = 'style="width:100%;"';
            
            const isUserField = fType === 'user' || 
                                fType === 'assigned_to' || 
                                fType === 'sys_created_by' || 
                                fType === 'sys_updated_by' || 
                                ['assignee', 'assigned to', 'created by', 'updated by'].includes(labelLower);

            const isDateField = fType === 'date';
            const isDatetimeField = fType === 'datetime' || fType === 'datetime-local' || fType === 'sys_created_at' || fType === 'sys_updated_at';
            const isTimeField = fType === 'time';
            
            const isOptionField = fType === 'select' || fType === 'dropdown' || fType === 'multi_picker' || fType === 'radio_group';
            
            if (isOptionField) {
                const opts = field.options || [];
                let parsedOpts = [];
                if (typeof opts === 'string') {
                    try { parsedOpts = JSON.parse(opts); } catch(e) {}
                } else if (Array.isArray(opts)) {
                    parsedOpts = opts;
                }
                const optHtml = parsedOpts.map(o => {
                    const optVal = (o && typeof o === 'object') ? (o.value || o.option_value || '') : o;
                    const optLbl = (o && typeof o === 'object') ? (o.label || o.option_label || optVal) : o;
                    return `<option value="${escapeHtml(optVal)}" ${value == optVal ? 'selected' : ''}>${escapeHtml(optLbl)}</option>`;
                }).join('');
                
                return `
                    <select ${idAttr} ${classAttr} ${styleAttr}>
                        <option value="">-- Choose option --</option>
                        ${optHtml}
                    </select>
                `;
            } else if (fType === 'checkbox') {
                return `
                    <select ${idAttr} ${classAttr} ${styleAttr}>
                        <option value="1" ${value == '1' ? 'selected' : ''}>Yes</option>
                        <option value="0" ${value == '0' ? 'selected' : ''}>No</option>
                    </select>
                `;
            } else if (isUserField) {
                const userOpts = ALL_USERS.map(u => `<option value="${u.id}" ${value == u.id ? 'selected' : ''}>${escapeHtml(u.name)}</option>`).join('');
                return `
                    <select ${idAttr} ${classAttr} ${styleAttr}>
                        <option value="">-- Choose User --</option>
                        ${userOpts}
                    </select>
                `;
            } else if (isDateField) {
                return `<input type="date" ${idAttr} ${classAttr} ${styleAttr} value="${escapeHtml(value)}">`;
            } else if (isDatetimeField) {
                let val = value || '';
                if (val.includes(' ')) {
                    val = val.replace(' ', 'T');
                }
                return `<input type="datetime-local" ${idAttr} ${classAttr} ${styleAttr} value="${escapeHtml(val)}">`;
            } else if (isTimeField) {
                return `<input type="time" ${idAttr} ${classAttr} ${styleAttr} value="${escapeHtml(value)}">`;
            } else if (fType === 'country') {
                const countryOpts = Object.entries(ALL_COUNTRIES || {}).map(([code, name]) => 
                    `<option value="${escapeHtml(code)}" ${value == code ? 'selected' : ''}>${escapeHtml(name)}</option>`
                ).join('');
                return `
                    <select ${idAttr} ${classAttr} ${styleAttr}>
                        <option value="">-- Choose Country --</option>
                        ${countryOpts}
                    </select>
                `;
            } else if (fType === 'state') {
                let stateHtml = '';
                for (const [countryCode, statesObj] of Object.entries(ALL_STATES || {})) {
                    const countryName = ALL_COUNTRIES[countryCode] || countryCode;
                    stateHtml += `<optgroup label="${escapeHtml(countryName)}">`;
                    for (const [stateCode, stateName] of Object.entries(statesObj || {})) {
                        stateHtml += `<option value="${escapeHtml(stateCode)}" ${value == stateCode ? 'selected' : ''}>${escapeHtml(stateName)}</option>`;
                    }
                    stateHtml += `</optgroup>`;
                }
                return `
                    <select ${idAttr} ${classAttr} ${styleAttr}>
                        <option value="">-- Choose State --</option>
                        ${stateHtml}
                    </select>
                `;
            } else if (fType === 'district') {
                let districtHtml = '';
                for (const [stateCode, districtsObj] of Object.entries(ALL_DISTRICTS || {})) {
                    let stateName = stateCode;
                    for (const [cCode, statesObj] of Object.entries(ALL_STATES || {})) {
                        if (statesObj[stateCode]) {
                            stateName = statesObj[stateCode];
                            break;
                        }
                    }
                    districtHtml += `<optgroup label="${escapeHtml(stateName)}">`;
                    for (const [distCode, distName] of Object.entries(districtsObj || {})) {
                        districtHtml += `<option value="${escapeHtml(distCode)}" ${value == distCode ? 'selected' : ''}>${escapeHtml(distName)}</option>`;
                    }
                    districtHtml += `</optgroup>`;
                }
                return `
                    <select ${idAttr} ${classAttr} ${styleAttr}>
                        <option value="">-- Choose District --</option>
                        ${districtHtml}
                    </select>
                `;
            } else if (fType === 'api_call_picker') {
                const linkedModId = field.config ? field.config.linked_module_id : 0;
                if (linkedModId) {
                    try {
                        const res = await fetch(`${API}?action=lookup_records&target_module_id=${linkedModId}`);
                        const rData = await res.json();
                        if (rData.success && rData.records) {
                            const recOpts = rData.records.map(rec => `<option value="${rec.id}" ${value == rec.id ? 'selected' : ''}>${escapeHtml(rec.display_value)} (ID: ${rec.id})</option>`).join('');
                            return `
                                <select ${idAttr} ${classAttr} ${styleAttr}>
                                    <option value="">-- Choose Record --</option>
                                    ${recOpts}
                                </select>
                            `;
                        }
                    } catch(e) {}
                }
                return `<input type="text" ${idAttr} ${classAttr} ${styleAttr} placeholder="Trigger value (ID)" value="${escapeHtml(value)}">`;
            } else if (fType === 'number' || fType === 'decimal' || fType === 'currency') {
                return `<input type="number" step="any" ${idAttr} ${classAttr} ${styleAttr} placeholder="Value" value="${escapeHtml(value)}">`;
            } else {
                return `<input type="text" ${idAttr} ${classAttr} ${styleAttr} placeholder="Value" value="${escapeHtml(value)}">`;
            }
        }

        async function onRuleSourceChange(selectEl, currentValue = '') {
            const row = selectEl.closest('.mm-rule-row');
            if (!row) return;
            const valContainer = row.querySelector('.rule-value-container');
            if (!valContainer) return;
            
            const fieldId = selectEl.value;
            if (!fieldId) {
                valContainer.innerHTML = `<input type="text" class="form-control rule-value" placeholder="Value" value="${escapeHtml(currentValue)}" style="width:100%;">`;
                return;
            }
            
            const field = rulesModuleFields.find(f => f.id == fieldId);
            if (!field) {
                valContainer.innerHTML = `<input type="text" class="form-control rule-value" placeholder="Value" value="${escapeHtml(currentValue)}" style="width:100%;">`;
                return;
            }
            
            valContainer.innerHTML = await createDynamicInputField(field, currentValue, true);
        }
        function saveRules() {
            const fieldId = +document.getElementById('rulesFieldId').value;
            const rows = document.querySelectorAll('#rulesList .mm-rule-row');
            const rules = [...rows].map(r => {
                const action = r.querySelector('.rule-action').value;
                const actionValEl = r.querySelector('.rule-action-value');
                const actionValue = actionValEl ? actionValEl.value : '';
                const ruleValEl = r.querySelector('.rule-value');
                const ruleValue = ruleValEl ? ruleValEl.value : '';
                const ruleTypeEl = r.querySelector('.rule-type');
                
                const targetModEl = r.querySelector('.rule-target-module');
                const targetFldEl = r.querySelector('.rule-target-field');
                const targetModeEl = r.querySelector('.rule-target-mode');
                const applyOnEl = r.querySelector('.rule-apply-on');
                const applyOn = applyOnEl ? applyOnEl.value : 'all';
                
                const configObj = {
                    action_value: actionValue,
                    apply_on: applyOn,
                    target_module_id: targetModEl ? (parseInt(targetModEl.value) || 0) : 0,
                    target_field_id: targetFldEl ? (parseInt(targetFldEl.value) || 0) : 0,
                    value_mode: targetModeEl ? targetModeEl.value : 'copy_value'
                };
                
                return {
                    rule_type: ruleTypeEl ? ruleTypeEl.value : 'conditional',
                    source_field_id: +r.querySelector('.rule-source').value,
                    operator: r.querySelector('.rule-op').value,
                    value: ruleValue,
                    action: action,
                    action_value: actionValue,
                    config: configObj
                };
            });
            api('save_field_rules', { field_id: fieldId, rules }).then(r => { if (r.success) { closeModal('rulesModal'); vyToast('Rules saved successfully!', 'success'); } else vyToast(r.error, 'error'); });
        }

        async function createDynamicInputField(field, value = '', isRule = false) {
            const fType = field.field_type;
            const labelLower = (field.label || '').toLowerCase().trim();
            const idAttr = isRule ? '' : 'id="workflowTriggerValue"';
            const classAttr = isRule ? 'class="form-control rule-value"' : 'class="form-control"';
            const styleAttr = 'style="width:100%;"';
            
            const isUserField = fType === 'user' || 
                                fType === 'assigned_to' || 
                                fType === 'sys_created_by' || 
                                fType === 'sys_updated_by' || 
                                ['assignee', 'assigned to', 'created by', 'updated by'].includes(labelLower);

            const isDateField = fType === 'date';
            const isDatetimeField = fType === 'datetime' || fType === 'datetime-local' || fType === 'sys_created_at' || fType === 'sys_updated_at';
            const isTimeField = fType === 'time';
            
            const isOptionField = fType === 'select' || fType === 'dropdown' || fType === 'multi_picker' || fType === 'radio_group';
            
            if (isOptionField) {
                const opts = field.options || [];
                let parsedOpts = [];
                if (typeof opts === 'string') {
                    try { parsedOpts = JSON.parse(opts); } catch(e) {}
                } else if (Array.isArray(opts)) {
                    parsedOpts = opts;
                }
                const optHtml = parsedOpts.map(o => {
                    const optVal = (o && typeof o === 'object') ? (o.value || o.option_value || '') : o;
                    const optLbl = (o && typeof o === 'object') ? (o.label || o.option_label || optVal) : o;
                    return `<option value="${escapeHtml(optVal)}" ${value == optVal ? 'selected' : ''}>${escapeHtml(optLbl)}</option>`;
                }).join('');
                
                return `
                    <select ${idAttr} ${classAttr} ${styleAttr}>
                        <option value="">-- Choose option --</option>
                        ${optHtml}
                    </select>
                `;
            } else if (fType === 'checkbox') {
                return `
                    <select ${idAttr} ${classAttr} ${styleAttr}>
                        <option value="1" ${value == '1' ? 'selected' : ''}>Yes</option>
                        <option value="0" ${value == '0' ? 'selected' : ''}>No</option>
                    </select>
                `;
            } else if (isUserField) {
                const userOpts = ALL_USERS.map(u => `<option value="${u.id}" ${value == u.id ? 'selected' : ''}>${escapeHtml(u.name)}</option>`).join('');
                return `
                    <select ${idAttr} ${classAttr} ${styleAttr}>
                        <option value="">-- Choose User --</option>
                        ${userOpts}
                    </select>
                `;
            } else if (isDateField) {
                return `<input type="date" ${idAttr} ${classAttr} ${styleAttr} value="${escapeHtml(value)}">`;
            } else if (isDatetimeField) {
                let val = value || '';
                if (val.includes(' ')) {
                    val = val.replace(' ', 'T');
                }
                return `<input type="datetime-local" ${idAttr} ${classAttr} ${styleAttr} value="${escapeHtml(val)}">`;
            } else if (isTimeField) {
                return `<input type="time" ${idAttr} ${classAttr} ${styleAttr} value="${escapeHtml(value)}">`;
            } else if (fType === 'country') {
                const countryOpts = Object.entries(ALL_COUNTRIES || {}).map(([code, name]) => 
                    `<option value="${escapeHtml(code)}" ${value == code ? 'selected' : ''}>${escapeHtml(name)}</option>`
                ).join('');
                return `
                    <select ${idAttr} ${classAttr} ${styleAttr}>
                        <option value="">-- Choose Country --</option>
                        ${countryOpts}
                    </select>
                `;
            } else if (fType === 'state') {
                let stateHtml = '';
                for (const [countryCode, statesObj] of Object.entries(ALL_STATES || {})) {
                    const countryName = ALL_COUNTRIES[countryCode] || countryCode;
                    stateHtml += `<optgroup label="${escapeHtml(countryName)}">`;
                    for (const [stateCode, stateName] of Object.entries(statesObj || {})) {
                        stateHtml += `<option value="${escapeHtml(stateCode)}" ${value == stateCode ? 'selected' : ''}>${escapeHtml(stateName)}</option>`;
                    }
                    stateHtml += `</optgroup>`;
                }
                return `
                    <select ${idAttr} ${classAttr} ${styleAttr}>
                        <option value="">-- Choose State --</option>
                        ${stateHtml}
                    </select>
                `;
            } else if (fType === 'district') {
                let districtHtml = '';
                for (const [stateCode, districtsObj] of Object.entries(ALL_DISTRICTS || {})) {
                    let stateName = stateCode;
                    for (const [cCode, statesObj] of Object.entries(ALL_STATES || {})) {
                        if (statesObj[stateCode]) {
                            stateName = statesObj[stateCode];
                            break;
                        }
                    }
                    districtHtml += `<optgroup label="${escapeHtml(stateName)}">`;
                    for (const [distCode, distName] of Object.entries(districtsObj || {})) {
                        districtHtml += `<option value="${escapeHtml(distCode)}" ${value == distCode ? 'selected' : ''}>${escapeHtml(distName)}</option>`;
                    }
                    districtHtml += `</optgroup>`;
                }
                return `
                    <select ${idAttr} ${classAttr} ${styleAttr}>
                        <option value="">-- Choose District --</option>
                        ${districtHtml}
                    </select>
                `;
            } else if (fType === 'api_call_picker') {
                const linkedModId = field.config ? field.config.linked_module_id : 0;
                if (linkedModId) {
                    try {
                        const res = await fetch(`${API}?action=lookup_records&target_module_id=${linkedModId}`);
                        const rData = await res.json();
                        if (rData.success && rData.records) {
                            const recOpts = rData.records.map(rec => `<option value="${rec.id}" ${value == rec.id ? 'selected' : ''}>${escapeHtml(rec.display_value)} (ID: ${rec.id})</option>`).join('');
                            return `
                                <select ${idAttr} ${classAttr} ${styleAttr}>
                                    <option value="">-- Choose Record --</option>
                                    ${recOpts}
                                </select>
                            `;
                        }
                    } catch(e) {}
                }
                return `<input type="text" ${idAttr} ${classAttr} ${styleAttr} placeholder="Trigger value (ID)" value="${escapeHtml(value)}">`;
            } else if (fType === 'number' || fType === 'decimal' || fType === 'currency') {
                return `<input type="number" step="any" ${idAttr} ${classAttr} ${styleAttr} placeholder="Value" value="${escapeHtml(value)}">`;
            } else {
                return `<input type="text" ${idAttr} ${classAttr} ${styleAttr} placeholder="Value" value="${escapeHtml(value)}">`;
            }
        }

        async function onRuleSourceChange(selectEl, currentValue = '') {
            const row = selectEl.closest('.mm-rule-row');
            if (!row) return;
            const valContainer = row.querySelector('.rule-value-container');
            if (!valContainer) return;
            
            const fieldId = selectEl.value;
            if (!fieldId) {
                valContainer.innerHTML = `<input type="text" class="form-control rule-value" placeholder="Value" value="${escapeHtml(currentValue)}" style="width:100%;">`;
                return;
            }
            
            const field = rulesModuleFields.find(f => f.id == fieldId);
            if (!field) {
                valContainer.innerHTML = `<input type="text" class="form-control rule-value" placeholder="Value" value="${escapeHtml(currentValue)}" style="width:100%;">`;
                return;
            }
            
            valContainer.innerHTML = await createDynamicInputField(field, currentValue, true);
        }
        function saveRules() {
            const fieldId = +document.getElementById('rulesFieldId').value;
            const rows = document.querySelectorAll('#rulesList .mm-rule-row');
            const rules = [...rows].map(r => {
                const action = r.querySelector('.rule-action').value;
                const actionValEl = r.querySelector('.rule-action-value');
                const actionValue = actionValEl ? actionValEl.value : '';
                const ruleValEl = r.querySelector('.rule-value');
                const ruleValue = ruleValEl ? ruleValEl.value : '';
                const ruleTypeEl = r.querySelector('.rule-type');
                
                const targetModEl = r.querySelector('.rule-target-module');
                const targetFldEl = r.querySelector('.rule-target-field');
                const targetModeEl = r.querySelector('.rule-target-mode');
                
                const configObj = {
                    action_value: actionValue,
                    target_module_id: targetModEl ? (parseInt(targetModEl.value) || 0) : 0,
                    target_field_id: targetFldEl ? (parseInt(targetFldEl.value) || 0) : 0,
                    value_mode: targetModeEl ? targetModeEl.value : 'copy_value'
                };
                
                return {
                    rule_type: ruleTypeEl ? ruleTypeEl.value : 'conditional',
                    source_field_id: +r.querySelector('.rule-source').value,
                    operator: r.querySelector('.rule-op').value,
                    value: ruleValue,
                    action: action,
                    action_value: actionValue,
                    config: configObj
                };
            });
            api('save_field_rules', { field_id: fieldId, rules }).then(r => { if (r.success) { closeModal('rulesModal'); vyToast('Rules saved successfully!', 'success'); } else vyToast(r.error, 'error'); });
        }

        /* ════════════════ WORKFLOW AUTOMATION ENGINE CLIENT ════════════════ */
        let workflowsList = [];

        function switchEditorTab(tab) {
            const fieldsTab = document.getElementById('tabBtnFields');
            const workflowsTab = document.getElementById('tabBtnWorkflows');
            const conversionTab = document.getElementById('tabBtnConversion');
            
            const fieldsContent = document.getElementById('tabContentFields');
            const workflowsContent = document.getElementById('tabContentWorkflows');
            const conversionContent = document.getElementById('tabContentConversion');
            
            // Remove active classes
            [fieldsTab, workflowsTab, conversionTab].forEach(t => {
                if (t) {
                    t.classList.remove('active');
                    t.querySelector('.tab-indicator').style.backgroundColor = 'transparent';
                }
            });
            [fieldsContent, workflowsContent, conversionContent].forEach(c => {
                if (c) c.style.display = 'none';
            });
            
            if (tab === 'fields') {
                fieldsTab.classList.add('active');
                fieldsTab.querySelector('.tab-indicator').style.backgroundColor = 'var(--primary)';
                fieldsContent.style.display = 'block';
            } else if (tab === 'workflows') {
                workflowsTab.classList.add('active');
                workflowsTab.querySelector('.tab-indicator').style.backgroundColor = 'var(--primary)';
                workflowsContent.style.display = 'block';
                loadWorkflows();
                loadWorkflowLogs();
            } else if (tab === 'conversion') {
                conversionTab.classList.add('active');
                conversionTab.querySelector('.tab-indicator').style.backgroundColor = 'var(--primary)';
                conversionContent.style.display = 'block';
                loadConversionRules();
            }
        }



        async function loadWorkflows() {
            try {
                const res = await fetch(`${API}?action=list_workflows&module_id=${MODULE_ID}`);
                const data = await res.json();
                if (data.success && data.workflows) {
                    workflowsList = data.workflows;
                    renderWorkflowsTable();
                }
            } catch(e) {
                vyToast('Failed to load workflows: ' + e.message, 'error');
            }
        }

        function renderWorkflowsTable() {
            const tbody = document.getElementById('workflowsListBody');
            if (!tbody) return;
            
            if (workflowsList.length === 0) {
                tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:30px; color:var(--text-muted);">No workflow rules configured yet.</td></tr>`;
                return;
            }
            
            tbody.innerHTML = workflowsList.map(w => {
                const triggerEvent = w.trigger_event || 'create_or_edit';
                const condType = w.condition_type || 'field_value';
                
                let eventText = '';
                if (triggerEvent === 'create') {
                    eventText = 'On creation';
                } else if (triggerEvent === 'edit') {
                    eventText = 'On update';
                } else {
                    eventText = 'On creation/update';
                }

                let condition = '';
                if (condType === 'always') {
                    condition = `${eventText}: <em>Always trigger</em>`;
                } else if (condType === 'field_changed') {
                    const triggerField = w.trigger_field_label || `Field #${w.trigger_field_id}`;
                    condition = `${eventText}: If <strong>${escapeHtml(triggerField)}</strong> is changed`;
                } else {
                    const triggerField = w.trigger_field_label || `Field #${w.trigger_field_id}`;
                    condition = `${eventText}: If <strong>${escapeHtml(triggerField)}</strong> matches "<strong>${escapeHtml(w.trigger_value || '')}</strong>"`;
                }
                let action = '';
                if (w.action_type === 'email') {
                    action = '<i class="fa-solid fa-envelope" style="color:#3b82f6;"></i> Send Email';
                } else if (w.action_type === 'whatsapp') {
                    action = '<i class="fa-solid fa-message" style="color:#10b981;"></i> Send WhatsApp';
                } else if (w.action_type === 'push') {
                    action = '<i class="fa-solid fa-bell" style="color:#eab308;"></i> Push Notification';
                }
                
                let recipient = '';
                if (w.recipient_field_id) {
                    recipient = `Field: ${escapeHtml(w.recipient_field_label)}`;
                } else if (w.recipient_custom) {
                    recipient = `Custom: ${escapeHtml(w.recipient_custom)}`;
                }
                
                const isChecked = w.status === 'active' ? 'checked' : '';
                const toggleSwitch = `
                    <label class="switch-toggle" style="position:relative; display:inline-block; width:40px; height:20px; vertical-align:middle; cursor:pointer;">
                        <input type="checkbox" ${isChecked} onchange="toggleWorkflowStatus(${w.id}, this.checked)" style="opacity:0; width:0; height:0; position:absolute;">
                        <span class="slider-round" style="position:absolute; inset:0; background-color:#ccc; transition:.4s; border-radius:34px; ${w.status === 'active' ? 'background-color:var(--primary);' : ''}"></span>
                        <span class="slider-dot" style="position:absolute; content:''; height:14px; width:14px; left:3px; bottom:3px; background-color:white; transition:.4s; border-radius:50%; ${w.status === 'active' ? 'transform:translateX(20px);' : ''}"></span>
                    </label>
                `;
                
                return `
                    <tr>
                        <td style="padding:12px 16px; border-bottom:1px solid var(--border);"><strong>${escapeHtml(w.name)}</strong></td>
                        <td style="padding:12px 16px; border-bottom:1px solid var(--border);">${condition}</td>
                        <td style="padding:12px 16px; border-bottom:1px solid var(--border);">${action}</td>
                        <td style="padding:12px 16px; border-bottom:1px solid var(--border);">${recipient}</td>
                        <td style="padding:12px 16px; border-bottom:1px solid var(--border);">${toggleSwitch}</td>
                        <td style="padding:12px 16px; border-bottom:1px solid var(--border); text-align:right;">
                            <div style="display:inline-flex; gap:6px;">
                                <button class="mm-icon-btn" onclick="editWorkflow(${w.id})" title="Edit"><i class="fa-solid fa-pencil"></i></button>
                                <button class="mm-icon-btn mm-icon-danger" onclick="deleteWorkflow(${w.id})" title="Delete"><i class="fa-solid fa-trash"></i></button>
                            </div>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        async function loadWorkflowLogs() {
            try {
                const res = await fetch(`${API}?action=list_workflow_logs&module_id=${MODULE_ID}`);
                const data = await res.json();
                const tbody = document.getElementById('workflowLogsBody');
                if (!tbody) return;
                
                if (data.success && data.logs) {
                    if (data.logs.length === 0) {
                        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:20px; color:var(--text-muted);">No execution history recorded.</td></tr>`;
                        return;
                    }
                    tbody.innerHTML = data.logs.map(l => {
                        const dateStr = formatVyDate(l.sent_at) + ' ' + formatVyTime(l.sent_at);
                        const statusBadge = l.status === 'sent' 
                            ? '<span class="status-badge" style="background:rgba(16,185,129,0.1); color:#10b981; padding:4px 8px; border-radius:4px; font-size:11px; font-weight:700;">Sent</span>'
                            : '<span class="status-badge" style="background:rgba(239,68,68,0.1); color:#ef4444; padding:4px 8px; border-radius:4px; font-size:11px; font-weight:700;">Failed</span>';
                        
                        let action = '';
                        if (l.action_type === 'email') {
                            action = 'Email';
                        } else if (l.action_type === 'whatsapp') {
                            action = 'WhatsApp';
                        } else if (l.action_type === 'push') {
                            action = 'Push Notification';
                        }
                        const ruleName = escapeHtml(l.workflow_name || `Rule #${l.workflow_id}`);
                        
                        return `
                            <tr>
                                <td style="padding:12px 16px; border-bottom:1px solid var(--border); white-space:nowrap;" class="text-sm text-muted">${dateStr}</td>
                                <td style="padding:12px 16px; border-bottom:1px solid var(--border);"><strong>${ruleName}</strong></td>
                                <td style="padding:12px 16px; border-bottom:1px solid var(--border);">${action}</td>
                                <td style="padding:12px 16px; border-bottom:1px solid var(--border);">${escapeHtml(l.recipient)}</td>
                                <td style="padding:12px 16px; border-bottom:1px solid var(--border);">${statusBadge}</td>
                                <td style="padding:12px 16px; border-bottom:1px solid var(--border); class="text-sm" title="${escapeHtml(l.error_message || '')}">${escapeHtml(l.error_message || 'OK')}</td>
                            </tr>
                        `;
                    }).join('');
                }
            } catch(e) {
                console.error("Failed to load workflow logs", e);
            }
        }

        function openWorkflowModal(editData = null) {
            document.getElementById('workflowId').value = editData ? editData.id : '';
            document.getElementById('workflowModalTitle').textContent = editData ? 'Edit Workflow Rule' : 'Add Workflow Rule';
            document.getElementById('workflowName').value = editData ? editData.name : '';
            
            // Set trigger event and condition type
            document.getElementById('workflowTriggerEvent').value = editData ? (editData.trigger_event || 'create_or_edit') : 'create_or_edit';
            document.getElementById('workflowConditionType').value = editData ? (editData.condition_type || 'field_value') : 'field_value';
            onWorkflowConditionTypeChange();

            const triggerSelect = document.getElementById('workflowTriggerField');
            triggerSelect.innerHTML = '<option value="">-- Select trigger field --</option>' + 
                EDIT_MODULE_FIELDS.map(f => `<option value="${f.id}">${escapeHtml(f.label)}</option>`).join('');
            
            const recipientSelect = document.getElementById('workflowRecipientField');
            recipientSelect.innerHTML = '<option value="">-- Select Recipient Field --</option>' + 
                EDIT_MODULE_FIELDS.map(f => `<option value="${f.id}">${escapeHtml(f.label)}</option>`).join('');
            
            triggerSelect.value = editData ? (editData.trigger_field_id || '') : '';
            onWorkflowTriggerFieldChange(editData ? (editData.trigger_value || '') : '');
            
            document.getElementById('workflowActionType').value = editData ? editData.action_type : 'email';
            onWorkflowActionTypeChange();
            if (editData && editData.communication_config_id) {
                document.getElementById('workflowCommConfig').value = editData.communication_config_id;
            } else {
                document.getElementById('workflowCommConfig').value = '';
            }
            
            recipientSelect.value = editData ? (editData.recipient_field_id || '') : '';
            document.getElementById('workflowRecipientCustom').value = editData ? (editData.recipient_custom || '') : '';
            onWorkflowRecipientFieldChange();
            
            document.getElementById('workflowSubject').value = editData ? (editData.template_subject || '') : '';
            document.getElementById('workflowBody').value = editData ? editData.template_body : '';
            
            const placeholdersContainer = document.getElementById('workflowPlaceholdersList');
            let placeholdersHtml = EDIT_MODULE_FIELDS.map(f => `
                <span onclick="insertWorkflowPlaceholder('{${escapeHtml(f.label)}}')" style="background:#fff; border:1px solid var(--primary); color:var(--primary); font-size:11px; padding:3.5px 8px; border-radius:4px; cursor:pointer; user-select:none; font-weight:600; transition:all 0.15s; display:inline-block;" onmouseover="this.style.background='rgba(123,94,240,0.08)'" onmouseout="this.style.background='#fff'">
                    {${escapeHtml(f.label)}}
                </span>
            `).join('');
            
            const sysPls = ['{Created By}', '{Created On}', '{Updated By}', '{Updated On}', '{Record ID}'];
            placeholdersHtml += sysPls.map(pl => `
                <span onclick="insertWorkflowPlaceholder('${pl}')" style="background:#fff; border:1px solid #718096; color:#4a5568; font-size:11px; padding:3.5px 8px; border-radius:4px; cursor:pointer; user-select:none; font-weight:600; transition:all 0.15s; display:inline-block;" onmouseover="this.style.background='#edf2f7'" onmouseout="this.style.background='#fff'">
                    ${pl}
                </span>
            `).join('');
            
            placeholdersContainer.innerHTML = placeholdersHtml;
            
            openModal('workflowModal');
        }

        function onWorkflowConditionTypeChange() {
            const condType = document.getElementById('workflowConditionType').value;
            const fieldGroup = document.getElementById('workflowFieldGroup');
            const valueGroup = document.getElementById('workflowValueGroup');
            
            if (condType === 'always') {
                fieldGroup.style.display = 'none';
                valueGroup.style.display = 'none';
            } else if (condType === 'field_changed') {
                fieldGroup.style.display = 'block';
                valueGroup.style.display = 'none';
            } else {
                fieldGroup.style.display = 'block';
                valueGroup.style.display = 'block';
            }
        }

        async function onWorkflowTriggerFieldChange(selectedValue = '') {
            const fieldId = document.getElementById('workflowTriggerField').value;
            const valueContainer = document.getElementById('workflowTriggerValueContainer');
            
            if (!fieldId) {
                valueContainer.innerHTML = `<input type="text" id="workflowTriggerValue" class="form-control" placeholder="Trigger value" style="width:100%;">`;
                return;
            }
            
            const field = EDIT_MODULE_FIELDS.find(f => f.id == fieldId);
            if (!field) {
                valueContainer.innerHTML = `<input type="text" id="workflowTriggerValue" class="form-control" placeholder="Trigger value" style="width:100%;">`;
                return;
            }

            valueContainer.innerHTML = await createDynamicInputField(field, selectedValue, false);
        }

        function onWorkflowActionTypeChange() {
            const actionType = document.getElementById('workflowActionType').value;
            const subjectGroup = document.getElementById('workflowSubjectGroup');
            const configType = actionType === 'email' ? 'smtp' : (actionType === 'whatsapp' ? 'whatsapp' : '');
            
            const configSelect = document.getElementById('workflowCommConfig');
            configSelect.innerHTML = '<option value="">-- Use Default --</option>';
            if (configType) {
                document.getElementById('workflowConfigContainer').style.display = 'block';
                commConfigs.filter(c => c.type === configType).forEach(c => {
                    configSelect.innerHTML += `<option value="${c.id}">${escapeHtml(c.name)}${c.is_default == 1 ? ' (Default)' : ''}</option>`;
                });
            } else {
                document.getElementById('workflowConfigContainer').style.display = 'none';
            }
            
            if (actionType === 'email') {
                subjectGroup.style.display = 'flex';
            } else {
                subjectGroup.style.display = 'none';
            }
        }

        function onWorkflowRecipientFieldChange() {
            const recipientField = document.getElementById('workflowRecipientField').value;
            const customContainer = document.getElementById('workflowRecipientCustomContainer');
            
            if (!recipientField) {
                customContainer.style.display = 'block';
            } else {
                customContainer.style.display = 'none';
                document.getElementById('workflowRecipientCustom').value = '';
            }
        }

        function insertWorkflowPlaceholder(placeholder) {
            const textarea = document.getElementById('workflowBody');
            const inputSubject = document.getElementById('workflowSubject');
            
            if (document.activeElement === inputSubject) {
                const start = inputSubject.selectionStart;
                const end = inputSubject.selectionEnd;
                inputSubject.value = inputSubject.value.substring(0, start) + placeholder + inputSubject.value.substring(end);
                inputSubject.focus();
                inputSubject.selectionStart = inputSubject.selectionEnd = start + placeholder.length;
            } else {
                textarea.focus();
                const start = textarea.selectionStart;
                const end = textarea.selectionEnd;
                textarea.value = textarea.value.substring(0, start) + placeholder + textarea.value.substring(end);
                textarea.focus();
                textarea.selectionStart = textarea.selectionEnd = start + placeholder.length;
            }
        }

        async function saveWorkflow() {
            const id = document.getElementById('workflowId').value;
            const name = document.getElementById('workflowName').value.trim();
            const triggerEvent = document.getElementById('workflowTriggerEvent').value;
            const conditionType = document.getElementById('workflowConditionType').value;
            const triggerFieldId = document.getElementById('workflowTriggerField').value;
            const triggerValueSelect = document.getElementById('workflowTriggerValue');
            const triggerValue = triggerValueSelect ? triggerValueSelect.value.trim() : '';
            const actionType = document.getElementById('workflowActionType').value;
            const recipientFieldId = document.getElementById('workflowRecipientField').value;
            const recipientCustom = document.getElementById('workflowRecipientCustom').value.trim();
            const templateSubject = document.getElementById('workflowSubject').value.trim();
            const templateBody = document.getElementById('workflowBody').value.trim();
            const commConfigId = document.getElementById('workflowCommConfig').value || null;
            
            if (!name) {
                vyToast('Rule name is required.', 'error');
                return;
            }
            if (conditionType !== 'always' && !triggerFieldId) {
                vyToast('Trigger field is required.', 'error');
                return;
            }
            if (conditionType === 'field_value' && !triggerValue) {
                vyToast('Trigger value is required.', 'error');
                return;
            }
            if (!recipientFieldId && !recipientCustom) {
                vyToast('Please specify a recipient field or enter a custom recipient.', 'error');
                return;
            }
            if (actionType === 'email' && !templateSubject) {
                vyToast('Email subject is required.', 'error');
                return;
            }
            if (!templateBody) {
                vyToast('Message body is required.', 'error');
                return;
            }
            
            const payload = {
                action: 'save_workflow',
                module_id: MODULE_ID,
                name,
                trigger_event: triggerEvent,
                condition_type: conditionType,
                trigger_field_id: conditionType !== 'always' ? triggerFieldId : null,
                trigger_value: conditionType === 'field_value' ? triggerValue : null,
                action_type: actionType,
                recipient_field_id: recipientFieldId || null,
                recipient_custom: recipientCustom || null,
                template_subject: templateSubject || null,
                template_body: templateBody,
                communication_config_id: commConfigId,
                status: 'active'
            };
            
            if (id) {
                payload.id = id;
            }
            
            try {
                const res = await fetch(API, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    vyToast('Workflow rule saved successfully!', 'success');
                    closeModal('workflowModal');
                    loadWorkflows();
                    loadWorkflowLogs();
                } else {
                    vyToast('Failed to save workflow: ' + (data.error || 'Unknown error'), 'error');
                }
            } catch(e) {
                vyToast('Request failed: ' + e.message, 'error');
            }
        }

        async function toggleWorkflowStatus(id, active) {
            const workflow = workflowsList.find(w => w.id == id);
            if (!workflow) return;
            
            const payload = {
                action: 'save_workflow',
                id: workflow.id,
                module_id: MODULE_ID,
                name: workflow.name,
                trigger_field_id: workflow.trigger_field_id,
                trigger_value: workflow.trigger_value,
                action_type: workflow.action_type,
                recipient_field_id: workflow.recipient_field_id,
                recipient_custom: workflow.recipient_custom,
                template_subject: workflow.template_subject,
                template_body: workflow.template_body,
                status: active ? 'active' : 'inactive'
            };
            
            try {
                const res = await fetch(API, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    vyToast(`Workflow status set to ${active ? 'Active' : 'Inactive'}`, 'success');
                    loadWorkflows();
                } else {
                    vyToast('Failed to change status: ' + data.error, 'error');
                }
            } catch(e) {
                vyToast('Request failed: ' + e.message, 'error');
            }
        }

        async function editWorkflow(id) {
            const workflow = workflowsList.find(w => w.id == id);
            if (!workflow) return;
            openWorkflowModal(workflow);
        }

        async function deleteWorkflow(id) {
            if (!confirm('Are you sure you want to delete this workflow rule?')) return;
            try {
                const res = await fetch(API, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        action: 'delete_workflow',
                        id
                    })
                });
                const data = await res.json();
                if (data.success) {
                    vyToast('Workflow deleted successfully.', 'success');
                    loadWorkflows();
                    loadWorkflowLogs();
                } else {
                    vyToast('Failed to delete workflow: ' + data.error, 'error');
                }
            } catch(e) {
                vyToast('Request failed: ' + e.message, 'error');
            }
        }

        let conversionRulesList = [];
        async function loadConversionRules() {
            try {
                const res = await fetch(`${API}?action=list_conversion_rules&source_module_id=${MODULE_ID}`);
                const data = await res.json();
                if (data.success && data.rules) {
                    conversionRulesList = data.rules;
                    renderConversionTable();
                }
            } catch (e) {
                vyToast('Failed to load conversion rules: ' + e.message, 'error');
            }
        }
        
        function renderConversionTable() {
            const tbody = document.getElementById('conversionListBody');
            if (!tbody) return;
            
            if (conversionRulesList.length === 0) {
                tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; padding:30px; color:var(--text-muted);">No conversion rules configured yet.</td></tr>`;
                return;
            }
            
            tbody.innerHTML = conversionRulesList.map(r => {
                let mappings = {};
                try { mappings = JSON.parse(r.field_mappings || '{}'); } catch(e) {}
                const mapCount = Object.keys(mappings).length;
                
                const isChecked = r.status === 'active' ? 'checked' : '';
                const toggleSwitch = `
                    <label class="switch-toggle" style="position:relative; display:inline-block; width:40px; height:20px; vertical-align:middle; cursor:pointer;">
                        <input type="checkbox" ${isChecked} onchange="toggleConversionStatus(${r.id}, this.checked)" style="opacity:0; width:0; height:0; position:absolute;">
                        <span class="slider-round" style="position:absolute; inset:0; background-color:#ccc; transition:.4s; border-radius:34px; ${r.status === 'active' ? 'background-color:var(--primary);' : ''}"></span>
                        <span class="slider-dot" style="position:absolute; content:''; height:14px; width:14px; left:3px; bottom:3px; background-color:white; transition:.4s; border-radius:50%; ${r.status === 'active' ? 'transform:translateX(20px);' : ''}"></span>
                    </label>
                `;
                
                return `
                    <tr>
                        <td style="padding:12px 16px; border-bottom:1px solid var(--border);"><strong>${escapeHtml(r.target_module_name)}</strong></td>
                        <td style="padding:12px 16px; border-bottom:1px solid var(--border);">${escapeHtml(r.button_label)}</td>
                        <td style="padding:12px 16px; border-bottom:1px solid var(--border);"><span class="mm-badge">${mapCount} field mappings</span></td>
                        <td style="padding:12px 16px; border-bottom:1px solid var(--border);">${toggleSwitch}</td>
                        <td style="padding:12px 16px; border-bottom:1px solid var(--border); text-align:right;">
                            <div style="display:inline-flex; gap:6px;">
                                <button class="mm-icon-btn" onclick="editConversionRule(${r.id})" title="Edit"><i class="fa-solid fa-pencil"></i></button>
                                <button class="mm-icon-btn mm-icon-danger" onclick="deleteConversionRule(${r.id})" title="Delete"><i class="fa-solid fa-trash"></i></button>
                            </div>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        async function toggleConversionStatus(id, active) {
            const rule = conversionRulesList.find(r => r.id == id);
            if (!rule) return;
            
            let mappings = {};
            try { mappings = JSON.parse(rule.field_mappings || '{}'); } catch(e) {}
            
            const payload = {
                action: 'save_conversion_rule',
                id: rule.id,
                source_module_id: MODULE_ID,
                target_module_id: rule.target_module_id,
                button_label: rule.button_label,
                field_mappings: mappings,
                status: active ? 'active' : 'inactive'
            };
            
            try {
                const res = await api('save_conversion_rule', payload);
                if (res.success) {
                    vyToast(`Rule status set to ${active ? 'Active' : 'Inactive'}`, 'success');
                    loadConversionRules();
                } else {
                    vyToast('Failed to change status: ' + res.error, 'error');
                }
            } catch (e) {
                vyToast('Request failed: ' + e.message, 'error');
            }
        }

        function openConversionModal(editData = null) {
            document.getElementById('conversionRuleId').value = editData ? editData.id : '';
            document.getElementById('conversionModalTitle').textContent = editData ? 'Edit Conversion Rule' : 'Add Conversion Rule';
            document.getElementById('conversionTargetModule').value = editData ? editData.target_module_id : '';
            document.getElementById('conversionButtonLabel').value = editData ? editData.button_label : '';
            
            document.getElementById('conversionTargetModule').disabled = !!editData;
            
            if (editData) {
                let mappings = {};
                try { mappings = JSON.parse(editData.field_mappings || '{}'); } catch(e) {}
                onConversionTargetModuleChange(mappings);
            } else {
                document.getElementById('conversionMappingsContainer').innerHTML = `
                    <div style="text-align:center; padding:20px; color:var(--text-muted); font-size:13px; border:1px dashed var(--border); border-radius:8px;">
                        Please choose a Target Module first.
                    </div>
                `;
            }
            openModal('conversionModal');
        }

        let targetModuleFields = [];
        async function onConversionTargetModuleChange(prefilledMappings = null) {
            const targetModuleId = document.getElementById('conversionTargetModule').value;
            const container = document.getElementById('conversionMappingsContainer');
            
            if (!targetModuleId) {
                container.innerHTML = `
                    <div style="text-align:center; padding:20px; color:var(--text-muted); font-size:13px; border:1px dashed var(--border); border-radius:8px;">
                        Please choose a Target Module first.
                    </div>
                `;
                return;
            }
            
            container.innerHTML = `<div style="text-align:center; padding:20px; color:var(--text-muted); font-size:13px;">Loading target fields...</div>`;
            
            try {
                const res = await api('get', { id: parseInt(targetModuleId) });
                if (res.success && res.module) {
                    targetModuleFields = [];
                    (res.module.blocks || []).forEach(b => {
                        (b.fields || []).forEach(f => {
                            targetModuleFields.push(f);
                        });
                    });
                    
                    if (targetModuleFields.length === 0) {
                        container.innerHTML = `
                            <div style="text-align:center; padding:20px; color:var(--text-muted); font-size:13px; border:1px dashed var(--border); border-radius:8px;">
                                Target module has no fields configured.
                            </div>
                        `;
                        return;
                    }
                    
                    let html = `
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; font-weight:bold; font-size:12px; color:var(--text-muted); border-bottom:1px solid var(--border); padding-bottom:8px; margin-bottom:8px;">
                            <div>TARGET FIELD</div>
                            <div>SOURCE FIELD (FROM THIS MODULE)</div>
                        </div>
                    `;
                    
                    targetModuleFields.forEach(tf => {
                        const prefilledSourceId = prefilledMappings ? prefilledMappings[tf.id] : '';
                        html += `
                            <div class="conversion-mapping-row" data-target-field-id="${tf.id}" style="display:grid; grid-template-columns:1fr 1fr; gap:15px; align-items:center;">
                                <div style="font-weight:600; font-size:13px; color:var(--text-main);">${escapeHtml(tf.label)} <span style="font-size:11px; color:var(--text-muted); font-weight:normal;">(${escapeHtml(tf.field_type)})</span></div>
                                <div>
                                    <select class="form-control mapping-source-field" style="width:100%;">
                                        <option value="">-- Do Not Map --</option>
                                        ${EDIT_MODULE_FIELDS.map(sf => {
                                            const isSelected = prefilledSourceId == sf.id ? 'selected' : '';
                                            return `<option value="${sf.id}" ${isSelected}>${escapeHtml(sf.label)} (${escapeHtml(sf.field_type)})</option>`;
                                        }).join('')}
                                    </select>
                                </div>
                            </div>
                        `;
                    });
                    
                    container.innerHTML = html;
                } else {
                    container.innerHTML = `<div style="text-align:center; padding:20px; color:var(--danger); font-size:13px;">Error: ${escapeHtml(res.error || 'Failed to load module fields')}</div>`;
                }
            } catch (e) {
                container.innerHTML = `<div style="text-align:center; padding:20px; color:var(--danger); font-size:13px;">Error: ${escapeHtml(e.message)}</div>`;
            }
        }

        async function saveConversionRule() {
            const id = document.getElementById('conversionRuleId').value;
            const targetModuleId = document.getElementById('conversionTargetModule').value;
            const buttonLabel = document.getElementById('conversionButtonLabel').value.trim();
            
            if (!targetModuleId) {
                vyToast('Target Module is required.', 'error');
                return;
            }
            if (!buttonLabel) {
                vyToast('Button label is required.', 'error');
                return;
            }
            
            const mappings = {};
            const rows = document.querySelectorAll('#conversionMappingsContainer .conversion-mapping-row');
            let hasMapping = false;
            rows.forEach(r => {
                const targetFid = r.getAttribute('data-target-field-id');
                const sourceFid = r.querySelector('.mapping-source-field').value;
                if (sourceFid) {
                    mappings[targetFid] = parseInt(sourceFid);
                    hasMapping = true;
                }
            });
            
            if (!hasMapping) {
                if (!confirm('You have not mapped any fields. The target record will be created empty. Proceed?')) {
                    return;
                }
            }
            
            const payload = {
                action: 'save_conversion_rule',
                source_module_id: MODULE_ID,
                target_module_id: parseInt(targetModuleId),
                button_label: buttonLabel,
                field_mappings: mappings,
                status: 'active'
            };
            if (id) {
                payload.id = parseInt(id);
            }
            
            try {
                const res = await api('save_conversion_rule', payload);
                if (res.success) {
                    vyToast('Conversion rule saved successfully!', 'success');
                    closeModal('conversionModal');
                    loadConversionRules();
                } else {
                    vyToast('Failed to save rule: ' + res.error, 'error');
                }
            } catch (e) {
                vyToast('Error: ' + e.message, 'error');
            }
        }

        function editConversionRule(id) {
            const rule = conversionRulesList.find(r => r.id == id);
            if (!rule) return;
            openConversionModal(rule);
        }

        async function deleteConversionRule(id) {
            if (!confirm('Are you sure you want to delete this conversion rule?')) return;
            try {
                const res = await api('delete_conversion_rule', { id });
                if (res.success) {
                    vyToast('Conversion rule deleted successfully.', 'success');
                    loadConversionRules();
                } else {
                    vyToast('Failed to delete rule: ' + res.error, 'error');
                }
            } catch (e) {
                vyToast('Error: ' + e.message, 'error');
            }
        }

        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function buildRolesCheckboxes(containerId, selectedRoles) {
            const container = document.getElementById(containerId);
            container.innerHTML = '';
            if (!ALL_ROLES || ALL_ROLES.length === 0) {
                container.innerHTML = '<div style="color:var(--text-muted); font-size:12px;">No roles available.</div>';
                return;
            }
            ALL_ROLES.forEach(r => {
                const checked = selectedRoles.includes(r.id.toString()) ? 'checked' : '';
                container.innerHTML += `
                    <label style="display:flex; align-items:center; gap:8px; margin-bottom:6px; cursor:pointer; font-size:13px; color:var(--text-main);">
                        <input type="checkbox" value="${r.id}" ${checked}> ${escapeHtml(r.name)}
                    </label>
                `;
            });
        }

        function openPermissionsModal() {
            document.getElementById('permVisibility').value = MODULE_PERMS.visibility;
            document.getElementById('permEditRule').value = MODULE_PERMS.edit_rule;
            document.getElementById('permDeleteRule').value = MODULE_PERMS.delete_rule;
            
            document.getElementById('permEnableImport').checked = parseInt(MODULE_PERMS.enable_import) !== 0;
            document.getElementById('permEnableExport').checked = parseInt(MODULE_PERMS.enable_export) !== 0;
            document.getElementById('permEnableMultiDelete').checked = parseInt(MODULE_PERMS.enable_multidelete) !== 0;
            document.getElementById('permEnableCreate').checked = parseInt(MODULE_PERMS.enable_create ?? 1) !== 0;
            document.getElementById('permEnableQuickCreate').checked = parseInt(MODULE_PERMS.enable_quickcreate ?? 1) !== 0;
            
            buildRolesCheckboxes('permViewRolesContainer', MODULE_PERMS.visibility_roles || []);
            buildRolesCheckboxes('permEditRolesContainer', MODULE_PERMS.edit_roles);
            buildRolesCheckboxes('permDeleteRolesContainer', MODULE_PERMS.delete_roles);
            
            toggleSpecificRoles('view');
            toggleSpecificRoles('edit');
            toggleSpecificRoles('delete');
            
            openModal('permissionsModal');
        }

        function toggleSpecificRoles(type) {
            let rule, container, doneBtn;
            if (type === 'view') {
                rule = document.getElementById('permVisibility').value;
                container = document.getElementById('permViewRolesContainer');
                doneBtn = document.getElementById('permViewRolesDoneBtn');
            } else if (type === 'edit') {
                rule = document.getElementById('permEditRule').value;
                container = document.getElementById('permEditRolesContainer');
                doneBtn = document.getElementById('permEditRolesDoneBtn');
            } else {
                rule = document.getElementById('permDeleteRule').value;
                container = document.getElementById('permDeleteRolesContainer');
                doneBtn = document.getElementById('permDeleteRolesDoneBtn');
            }
            const show = rule === 'specific_roles';
            container.style.display = show ? 'block' : 'none';
            if (doneBtn) doneBtn.style.display = show ? 'inline-block' : 'none';
        }

        function savePermissions() {
            const visibility = document.getElementById('permVisibility').value;
            const edit_rule = document.getElementById('permEditRule').value;
            const delete_rule = document.getElementById('permDeleteRule').value;
            
            const view_roles = [];
            if (visibility === 'specific_roles') {
                document.querySelectorAll('#permViewRolesContainer input:checked').forEach(cb => view_roles.push(cb.value));
            }
            const edit_roles = [];
            if (edit_rule === 'specific_roles') {
                document.querySelectorAll('#permEditRolesContainer input:checked').forEach(cb => edit_roles.push(cb.value));
            }
            const delete_roles = [];
            if (delete_rule === 'specific_roles') {
                document.querySelectorAll('#permDeleteRolesContainer input:checked').forEach(cb => delete_roles.push(cb.value));
            }
            
            const enable_import = document.getElementById('permEnableImport').checked ? 1 : 0;
            const enable_export = document.getElementById('permEnableExport').checked ? 1 : 0;
            const enable_multidelete = document.getElementById('permEnableMultiDelete').checked ? 1 : 0;
            const enable_create = document.getElementById('permEnableCreate').checked ? 1 : 0;
            const enable_quickcreate = document.getElementById('permEnableQuickCreate').checked ? 1 : 0;
            
            api('update', {
                id: MODULE_ID,
                visibility_rule: visibility,
                visibility_roles: view_roles.join(','),
                edit_rule: edit_rule,
                edit_roles: edit_roles.join(','),
                delete_rule: delete_rule,
                delete_roles: delete_roles.join(','),
                enable_import: enable_import,
                enable_export: enable_export,
                enable_multidelete: enable_multidelete,
                enable_create: enable_create,
                enable_quickcreate: enable_quickcreate
            }).then(r => {
                if (r.success) {
                    MODULE_PERMS.visibility = visibility;
                    MODULE_PERMS.visibility_roles = view_roles;
                    MODULE_PERMS.edit_rule = edit_rule;
                    MODULE_PERMS.edit_roles = edit_roles;
                    MODULE_PERMS.delete_rule = delete_rule;
                    MODULE_PERMS.delete_roles = delete_roles;
                    MODULE_PERMS.enable_import = enable_import;
                    MODULE_PERMS.enable_export = enable_export;
                    MODULE_PERMS.enable_multidelete = enable_multidelete;
                    MODULE_PERMS.enable_create = enable_create;
                    MODULE_PERMS.enable_quickcreate = enable_quickcreate;
                    vyToast('Permissions updated successfully.', 'success');
                    closeModal('permissionsModal');
                } else {
                    vyToast('Failed to update permissions: ' + r.error, 'error');
                }
            }).catch(e => vyToast('Error: ' + e.message, 'error'));
        }

        /* ════════════════════ CAMPAIGN CONFIGURE FIELDS ════════════════════ */

        let campaignFieldsData = [];

        async function openCampaignFieldsModal() {
            openModal('campaignFieldsModal');
            cancelCfEdit();
            await loadCampaignFields();
        }

        async function loadCampaignFields() {
            // Reset list to loading state (recreates #campaignFieldsEmpty safely)
            document.getElementById('campaignFieldsList').innerHTML = '<div style="text-align:center; padding:20px; color:var(--text-muted); font-size:13px;" id="campaignFieldsEmpty">Loading...</div>';
            try {
                const res = await fetch('api/campaigns_api.php?action=list_campaign_fields');
                const data = await res.json();
                if (data.success) {
                    campaignFieldsData = data.fields || [];
                    renderCampaignFieldsList();
                } else {
                    vyToast(data.error || 'Failed to load fields', 'error');
                }
            } catch(e) {
                vyToast('Connection error: ' + e.message, 'error');
            }
        }

        function renderCampaignFieldsList() {
            const container = document.getElementById('campaignFieldsList');
            if (!campaignFieldsData.length) {
                container.innerHTML = '<div style="text-align:center; padding:20px; color:var(--text-muted); font-size:13px;">No custom fields yet. Click "+ Add Field" to create one.</div>';
                return;
            }
            const typeIcons = { text:'fa-font', number:'fa-hashtag', email:'fa-envelope', phone:'fa-phone', date:'fa-calendar', url:'fa-link', textarea:'fa-align-left', select:'fa-chevron-down' };
            container.innerHTML = campaignFieldsData.map(f => `
                <div style="display:flex; align-items:center; gap:10px; background:#fff; border:1.5px solid var(--border); border-radius:10px; padding:10px 14px;">
                    <div style="width:30px; height:30px; background:rgba(123,94,240,0.1); border-radius:8px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <i class="fa-solid ${typeIcons[f.field_type] || 'fa-font'}" style="color:var(--primary); font-size:12px;"></i>
                    </div>
                    <div style="flex:1; min-width:0;">
                        <div style="font-size:13px; font-weight:700; color:var(--text);">${escapeHtml(f.label)}${f.is_required ? ' <span style="color:#ef4444; font-size:10px;">*required</span>' : ''}</div>
                        <div style="font-size:11px; color:var(--text-muted);">key: <code style="background:rgba(0,0,0,0.05); padding:1px 4px; border-radius:3px;">${escapeHtml(f.field_key)}</code> · type: ${f.field_type}${f.placeholder ? ' · placeholder: ' + escapeHtml(f.placeholder) : ''}</div>
                    </div>
                    <div style="display:flex; gap:6px; flex-shrink:0;">
                        <button class="mm-btn mm-btn-sm" onclick="editCampaignField(${f.id})"><i class="fa-solid fa-pencil"></i> Edit</button>
                        <button class="mm-icon-btn mm-icon-danger" onclick="deleteCampaignField(${f.id})" title="Delete"><i class="fa-solid fa-trash"></i></button>
                    </div>
                </div>
            `).join('');
        }

        function showCfAddForm() {
            document.getElementById('cfEditId').value = '0';
            document.getElementById('cfLabel').value = '';
            document.getElementById('cfType').value = 'text';
            document.getElementById('cfPlaceholder').value = '';
            document.getElementById('cfRequired').checked = false;
            document.getElementById('cfOptions').value = '';
            document.getElementById('cfOptionsGroup').style.display = 'none';
            document.getElementById('campaignFieldFormTitle').textContent = 'Add New Field';
            document.getElementById('campaignFieldAddForm').style.display = 'block';
            document.getElementById('cfLabel').focus();
        }

        function editCampaignField(id) {
            const f = campaignFieldsData.find(x => x.id == id);
            if (!f) return;
            document.getElementById('cfEditId').value = f.id;
            document.getElementById('cfLabel').value = f.label;
            document.getElementById('cfType').value = f.field_type;
            document.getElementById('cfPlaceholder').value = f.placeholder || '';
            document.getElementById('cfRequired').checked = !!f.is_required;
            document.getElementById('cfOptions').value = Array.isArray(f.options) ? f.options.join(', ') : '';
            onCfTypeChange();
            document.getElementById('campaignFieldFormTitle').textContent = 'Edit Field';
            document.getElementById('campaignFieldAddForm').style.display = 'block';
            document.getElementById('cfLabel').focus();
        }

        function cancelCfEdit() {
            document.getElementById('campaignFieldAddForm').style.display = 'none';
        }

        function onCfTypeChange() {
            const type = document.getElementById('cfType').value;
            document.getElementById('cfOptionsGroup').style.display = type === 'select' ? 'block' : 'none';
        }

        async function saveCampaignField() {
            const id = parseInt(document.getElementById('cfEditId').value) || 0;
            const label = document.getElementById('cfLabel').value.trim();
            const fieldType = document.getElementById('cfType').value;
            const placeholder = document.getElementById('cfPlaceholder').value.trim();
            const isRequired = document.getElementById('cfRequired').checked ? 1 : 0;
            const optionsRaw = document.getElementById('cfOptions').value.trim();
            const options = fieldType === 'select' && optionsRaw ? optionsRaw.split(',').map(s => s.trim()).filter(Boolean) : [];

            if (!label) { vyToast('Field label is required', 'error'); return; }

            try {
                const res = await fetch('api/campaigns_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'save_campaign_field', id, label, field_type: fieldType, placeholder, is_required: isRequired, options })
                });
                const data = await res.json();
                if (data.success) {
                    vyToast(id ? 'Field updated!' : 'Field added!', 'success');
                    cancelCfEdit();
                    await loadCampaignFields();
                } else {
                    vyToast(data.error || 'Failed to save field', 'error');
                }
            } catch(e) {
                vyToast('Connection error: ' + e.message, 'error');
            }
        }

        async function deleteCampaignField(id) {
            if (!confirm('Delete this custom field? Existing data in this field will be lost.')) return;
            try {
                const res = await fetch('api/campaigns_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete_campaign_field', id })
                });
                const data = await res.json();
                if (data.success) {
                    vyToast('Field deleted', 'success');
                    await loadCampaignFields();
                } else {
                    vyToast(data.error || 'Failed to delete', 'error');
                }
            } catch(e) {
                vyToast('Connection error', 'error');
            }
        }

        async function openCampaignMappingModal() {
            document.getElementById('mappingModuleSelect').value = '';
            document.getElementById('mappingFieldsContainer').style.display = 'none';
            document.getElementById('btnSaveCampaignMapping').disabled = true;
            openModal('campaignMappingModal');
        }

        async function loadMappingForEdit(moduleId) {
            const sel = document.getElementById('mappingModuleSelect');
            sel.value = moduleId;
            await onMappingModuleChange();
            // Scroll to the mapping fields
            const container = document.getElementById('mappingFieldsContainer');
            if (container) container.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        async function removeCampaignMapping(moduleId, moduleName) {
            if (!confirm(`Remove Campaign field mapping for "${moduleName}"? This cannot be undone.`)) return;
            try {
                const res = await fetch('api/campaigns_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete_field_mapping', module_id: parseInt(moduleId) })
                });
                const data = await res.json();
                if (data.success) {
                    vyToast(`Mapping for "${moduleName}" removed.`, 'success');
                    // Remove the card from UI
                    const list = document.getElementById('configuredMappingsList');
                    if (list) {
                        const cards = list.querySelectorAll('[data-module-id]');
                        cards.forEach(c => { if (parseInt(c.dataset.moduleId) === moduleId) c.remove(); });
                    }
                    // Refresh after short delay to show updated state
                    setTimeout(() => location.reload(), 800);
                } else {
                    vyToast(data.error || 'Failed to remove mapping', 'error');
                }
            } catch (e) {
                vyToast('Error: ' + e.message, 'error');
            }
        }

        async function onMappingModuleChange() {
            const moduleId = document.getElementById('mappingModuleSelect').value;
            const container = document.getElementById('mappingFieldsContainer');
            const saveBtn = document.getElementById('btnSaveCampaignMapping');
            
            if (!moduleId) {
                container.style.display = 'none';
                saveBtn.disabled = true;
                return;
            }
            
            try {
                // Fetch module schema (blocks and fields) using existing api/modules.php?action=get
                const res = await fetch(`api/modules.php?action=get&id=${moduleId}`);
                const data = await res.json();
                if (data.success) {
                    const module = data.module;
                    
                    // Collect all custom fields from all blocks
                    const fields = [];
                    module.blocks.forEach(block => {
                        block.fields.forEach(field => {
                            fields.push(field);
                        });
                    });
                    
                    // Populate each mapping dropdown
                    const dropdowns = document.querySelectorAll('.mapping-dropdown');
                    dropdowns.forEach(dd => {
                        dd.innerHTML = '<option value="">-- Don\'t Map --</option>';
                        fields.forEach(f => {
                            dd.innerHTML += `<option value="${f.field_key}">${escapeHtml(f.label)} (${f.field_key})</option>`;
                        });
                    });
                    
                    // Now, fetch any existing saved mapping for this module
                    const mapRes = await fetch(`api/campaigns_api.php?action=get_field_mapping&module_id=${moduleId}`);
                    const mapData = await mapRes.json();
                    
                    if (mapData.success && mapData.mapping) {
                        const m = mapData.mapping;
                        Object.keys(m).forEach(key => {
                            const dd = document.getElementById(`map_field_${key}`);
                            if (dd) dd.value = m[key] || '';
                        });
                    }
                    
                    container.style.display = 'block';
                    saveBtn.disabled = false;
                } else {
                    vyToast(data.error || 'Failed to fetch module fields', 'error');
                }
            } catch (e) {
                vyToast('Connection failed: ' + e.message, 'error');
            }
        }

        async function saveCampaignMapping() {
            const moduleId = document.getElementById('mappingModuleSelect').value;
            if (!moduleId) return;
            
            const mapping = {
                first_name: document.getElementById('map_field_first_name').value,
                last_name: document.getElementById('map_field_last_name').value,
                email: document.getElementById('map_field_email').value,
                email2: document.getElementById('map_field_email2').value,
                phone: document.getElementById('map_field_phone').value,
                phone2: document.getElementById('map_field_phone2').value,
                company: document.getElementById('map_field_company').value,
                designation: document.getElementById('map_field_designation').value
            };
            
            try {
                const res = await fetch('api/campaigns_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'save_field_mapping',
                        module_id: parseInt(moduleId),
                        mapping: mapping
                    })
                });
                const data = await res.json();
                if (data.success) {
                    vyToast('Campaign field mapping saved successfully!', 'success');
                    // Reload after short delay so toast is visible and list refreshes
                    setTimeout(() => location.reload(), 800);
                } else {
                    vyToast(data.error || 'Failed to save mapping', 'error');
                }
            } catch (e) {
                vyToast('Connection error: ' + e.message, 'error');
            }
        }


        /* ════════════════════ DASHBOARD WIDGETS MANAGER ════════════════════ */
        let activeWidgetsList = [];

        function openWidgetsConfigModal() {
            openModal('widgetsConfigModal');
            loadWidgetsList();
        }

        async function loadWidgetsList() {
            const tbody = document.getElementById('widgetsListTableBody');
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:20px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Loading widgets...</td></tr>';
            
            try {
                const res = await fetch(`${API}?action=list_dashboard_widgets`);
                const data = await res.json();
                if (data.success) {
                    activeWidgetsList = data.widgets;
                    renderWidgetsTable();
                } else {
                    vyToast(data.error || 'Failed to load widgets', 'error');
                }
            } catch (e) {
                vyToast('Connection failed: ' + e.message, 'error');
            }
        }

        function renderWidgetsTable() {
            const tbody = document.getElementById('widgetsListTableBody');
            if (activeWidgetsList.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:30px; color:var(--text-muted);">No dashboard widgets configured. Click "Add New Widget" to create one.</td></tr>';
                return;
            }

            tbody.innerHTML = activeWidgetsList.map(w => {
                const iconHtml = `<span style="display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:6px; background:${w.color}15; color:${w.color}; margin-right:8px;"><i class="${w.icon}"></i></span>`;
                
                let formatBadge = '<span style="background:#e0e7ff; color:#4338ca; padding:3px 9px; border-radius:10px; font-size:11px; font-weight:700;">🔢 Number</span>';
                if (w.display_type === 'percentage') {
                    formatBadge = '<span style="background:#ecfdf5; color:#065f46; padding:3px 9px; border-radius:10px; font-size:11px; font-weight:700;">📊 Percentage (%)</span>';
                } else if (w.display_type === 'both') {
                    formatBadge = '<span style="background:#fef3c7; color:#92400e; padding:3px 9px; border-radius:10px; font-size:11px; font-weight:700;">🔢+📊 Both</span>';
                }

                // Parse condition details
                let rules = [];
                try { rules = JSON.parse(w.rules); } catch(ex) {}
                const rule = rules[0] || {};
                
                let condDesc = '';
                if (rule.operator === 'today') condDesc = `[${w.field_label}] is Today`;
                else if (rule.operator === 'yesterday') condDesc = `[${w.field_label}] is Yesterday`;
                else if (rule.operator === 'this_week') condDesc = `[${w.field_label}] is This Week`;
                else if (rule.operator === 'this_month') condDesc = `[${w.field_label}] is This Month`;
                else if (rule.operator === 'days_within_past') condDesc = `[${w.field_label}] is within last ${rule.value} days`;
                else if (rule.operator === 'days_older') condDesc = `[${w.field_label}] is older than ${rule.value} days`;
                else if (rule.operator === 'days_within_future') condDesc = `[${w.field_label}] is within next ${rule.value} days`;
                else if (rule.operator === 'empty') condDesc = `[${w.field_label}] is Empty`;
                else if (rule.operator === 'not_empty') condDesc = `[${w.field_label}] is Not Empty`;
                else if (rule.operator === 'LIKE') condDesc = `[${w.field_label}] contains "${rule.value}"`;
                else if (rule.operator === 'NOT LIKE') condDesc = `[${w.field_label}] does not contain "${rule.value}"`;
                else condDesc = `[${w.field_label}] ${rule.operator} "${rule.value}"`;

                return `
                    <tr style="border-bottom:1px solid var(--border);">
                        <td style="padding:12px 15px; font-weight:700; color:var(--text-main);">${iconHtml}${escapeHtml(w.title)}</td>
                        <td style="padding:12px 15px;">${escapeHtml(w.module_name || 'Unknown')}</td>
                        <td style="padding:12px 15px;">${formatBadge}</td>
                        <td style="padding:12px 15px; font-size:12px; font-family:monospace; color:#3b82f6;">${escapeHtml(condDesc)}</td>
                        <td style="padding:12px 15px; text-align:right;">
                            <div style="display:inline-flex; gap:6px;">
                                <button type="button" class="mm-btn mm-btn-sm" onclick="editWidget(${w.id})"><i class="fa-solid fa-pencil"></i> Edit</button>
                                <button type="button" class="mm-icon-btn mm-icon-danger" onclick="deleteWidget(${w.id})"><i class="fa-solid fa-trash"></i></button>
                            </div>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function showAddWidgetForm() {
            document.getElementById('widgetFormTitle').textContent = 'Add Dashboard Widget';
            document.getElementById('widgetId').value = '';
            document.getElementById('widgetTitle').value = '';
            document.getElementById('widgetModule').value = '';
            document.getElementById('widgetField').innerHTML = '<option value="">-- Select Field --</option>';
            document.getElementById('widgetOperator').value = 'days_within_past';
            document.getElementById('widgetValue').value = '';
            document.getElementById('widgetDisplayType').value = 'count';
            document.getElementById('widgetIcon').value = 'fa-solid fa-clock';
            document.getElementById('widgetColor').value = '#7b5ef0';
            
            document.getElementById('widgetFormContainer').style.display = 'block';
            onWidgetOperatorChange();
        }

        function hideWidgetForm() {
            document.getElementById('widgetFormContainer').style.display = 'none';
        }

        async function onWidgetModuleChange() {
            const moduleId = document.getElementById('widgetModule').value;
            const fieldSelect = document.getElementById('widgetField');
            fieldSelect.innerHTML = '<option value="">Loading fields...</option>';

            if (!moduleId) {
                fieldSelect.innerHTML = '<option value="">-- Select Field --</option>';
                return;
            }

            try {
                const res = await fetch(`${API}?action=get_module_fields&module_id=${moduleId}`);
                const data = await res.json();
                if (data.success) {
                    fieldSelect.innerHTML = '<option value="">-- Select Field --</option>' + data.fields.map(f => {
                        return `<option value="${f.id}">${escapeHtml(f.label)}</option>`;
                    }).join('');
                } else {
                    vyToast('Failed to load fields', 'error');
                }
            } catch (e) {
                vyToast('Connection failed: ' + e.message, 'error');
            }
        }

        function onWidgetFieldChange() {
            // No-op
        }

        function onWidgetOperatorChange() {
            const op = document.getElementById('widgetOperator').value;
            const valGroup = document.getElementById('widgetValueGroup');
            const valInput = document.getElementById('widgetValue');
            const noValOps = ['today', 'yesterday', 'this_week', 'this_month', 'empty', 'not_empty'];
            const showValue = !noValOps.includes(op);
            valGroup.style.display = showValue ? 'flex' : 'none';
            if (!showValue) {
                valInput.value = '';
            } else {
                if (['days_within_past', 'days_older', 'days_within_future'].includes(op)) {
                    valInput.placeholder = 'Number of days (e.g. 7 or 14)...';
                    valInput.type = 'number';
                } else {
                    valInput.placeholder = 'Value...';
                    valInput.type = 'text';
                }
            }
        }

        function editWidget(id) {
            const w = activeWidgetsList.find(x => x.id == id);
            if (!w) return;

            document.getElementById('widgetFormTitle').textContent = 'Edit Dashboard Widget';
            document.getElementById('widgetId').value = w.id;
            document.getElementById('widgetTitle').value = w.title;
            document.getElementById('widgetModule').value = w.module_id;
            document.getElementById('widgetDisplayType').value = w.display_type || 'count';
            document.getElementById('widgetIcon').value = w.icon;
            document.getElementById('widgetColor').value = w.color;
            
            // Re-load fields dynamically
            const fieldSelect = document.getElementById('widgetField');
            fieldSelect.innerHTML = '<option value="">Loading fields...</option>';
            
            fetch(`${API}?action=get_module_fields&module_id=${w.module_id}`)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    fieldSelect.innerHTML = '<option value="">-- Select Field --</option>' + data.fields.map(f => {
                        return `<option value="${f.id}">${escapeHtml(f.label)}</option>`;
                    }).join('');
                    
                    // Set correct field value
                    fieldSelect.value = w.field_id;
                    
                    // Parse rule properties
                    let rules = [];
                    try { rules = JSON.parse(w.rules); } catch(ex) {}
                    const rule = rules[0] || {};
                    
                    document.getElementById('widgetOperator').value = rule.operator || '=';
                    document.getElementById('widgetValue').value = rule.value || '';
                    
                    document.getElementById('widgetFormContainer').style.display = 'block';
                    onWidgetOperatorChange();
                }
            });
        }

        async function saveWidget() {
            const id = document.getElementById('widgetId').value;
            const title = document.getElementById('widgetTitle').value.trim();
            const moduleId = document.getElementById('widgetModule').value;
            const fieldId = document.getElementById('widgetField').value;
            const operator = document.getElementById('widgetOperator').value;
            const value = document.getElementById('widgetValue').value.trim();
            const display_type = document.getElementById('widgetDisplayType').value;
            const icon = document.getElementById('widgetIcon').value;
            const color = document.getElementById('widgetColor').value;

            if (!title) {
                vyToast('Widget Title is required.', 'error');
                return;
            }
            if (!moduleId) {
                vyToast('Target Module is required.', 'error');
                return;
            }
            if (!fieldId) {
                vyToast('Filter Field is required.', 'error');
                return;
            }

            try {
                const res = await fetch(API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'save_dashboard_widget',
                        id: id ? parseInt(id) : 0,
                        title,
                        module_id: parseInt(moduleId),
                        field_id: fieldId,
                        operator,
                        value,
                        display_type,
                        icon,
                        color
                    })
                });
                const data = await res.json();
                if (data.success) {
                    vyToast(id ? 'Widget updated successfully!' : 'Widget created successfully!', 'success');
                    hideWidgetForm();
                    loadWidgetsList();
                } else {
                    vyToast(data.error || 'Failed to save widget', 'error');
                }
            } catch (e) {
                vyToast('Connection failed: ' + e.message, 'error');
            }
        }

        async function deleteWidget(id) {
            if (!confirm('Are you sure you want to delete this widget?')) return;
            try {
                const res = await fetch(API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'delete_dashboard_widget',
                        id: parseInt(id)
                    })
                });
                const data = await res.json();
                if (data.success) {
                    vyToast('Widget deleted successfully!', 'success');
                    loadWidgetsList();
                } else {
                    vyToast(data.error || 'Failed to delete widget', 'error');
                }
            } catch (e) {
                vyToast('Connection failed: ' + e.message, 'error');
            }
        }

        function toggleSidebar() { document.getElementById('sidebar').classList.toggle('sidebar-collapsed'); }
    </script>
</body>

</html>