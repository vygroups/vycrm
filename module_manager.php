<?php
require_once 'auth_check.php';
require_once 'includes/commerce.php';
require_once 'includes/brand.php';
require_once 'includes/dynamic_modules.php';

$context = commerce_get_tenant_context();
$conn = $context['conn'];
$prefix = $context['prefix'];
dm_ensure_tables($conn, $prefix);
commerce_ensure_tables($conn, $prefix);

$fieldTypes = dm_field_types();
$countries = dm_get_countries();
$allModules = dm_fetch_all_modules($conn, $prefix);

// If editing a module
$editModule = null;
if (!empty($_GET['edit'])) {
    $editModule = dm_fetch_module_full($conn, $prefix, (int) $_GET['edit']);
}

$jsFields = [];
if ($editModule) {
    foreach ($editModule['blocks'] as $b) {
        foreach ($b['fields'] as $f) {
            $jsFields[] = [
                'id' => (int)$f['id'],
                'label' => $f['label'],
                'field_key' => $f['field_key'],
                'field_type' => $f['field_type'],
                'options' => $f['options'] ?? []
            ];
        }
    }
}
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
    <script src="/assets/js/toast.js?v=<?= $v ?>"></script>
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
                                <select class="mm-btn mm-btn-outline" onchange="updateVisibility(this.value)"
                                    style="padding:8px 12px; font-size:13px;">
                                    <option value="all" <?= $editModule['visibility_rule'] === 'all' ? 'selected' : '' ?>>Show
                                        to all</option>
                                    <option value="owner" <?= $editModule['visibility_rule'] === 'owner' ? 'selected' : '' ?>>
                                        Owner Only</option>
                                    <option value="role_down" <?= $editModule['visibility_rule'] === 'role_down' ? 'selected' : '' ?>>Lower Roles</option>
                                    <option value="role_equal_down" <?= $editModule['visibility_rule'] === 'role_equal_down' ? 'selected' : '' ?>>Equal & Lower</option>
                                    <option value="role_up" <?= $editModule['visibility_rule'] === 'role_up' ? 'selected' : '' ?>>Upper Roles</option>
                                </select>
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
                                                        <i
                                                            class="<?= htmlspecialchars($fieldTypes[$field['field_type']]['icon'] ?? 'fa-solid fa-font') ?> mm-field-icon"></i>
                                                        <div>
                                                            <div class="mm-field-label"><?= htmlspecialchars($field['label']) ?></div>
                                                            <div class="mm-field-meta">
                                                                <span
                                                                    class="mm-field-type"><?= htmlspecialchars($fieldTypes[$field['field_type']]['label'] ?? $field['field_type']) ?></span>
                                                                <?php if ($field['is_required']): ?><span
                                                                        class="mm-badge mm-badge-red">Required</span><?php endif; ?>
                                                                <?php if ($field['is_searchable']): ?><span
                                                                        class="mm-badge">Searchable</span><?php endif; ?>
                                                                <?php if ($field['is_list_visible']): ?><span
                                                                        class="mm-badge">List</span><?php endif; ?>
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
                                                            title="Rules"><i class="fa-solid fa-code-branch"></i></button>
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
                                <button class="mm-btn mm-btn-primary" onclick="openWorkflowModal()" style="display:inline-flex; align-items:center; gap:6px;">
                                    <i class="fa-solid fa-plus"></i> Add Workflow
                                </button>
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
                    </div>

                <?php else: ?>
                    <!-- ═══════════ MODULE LIST VIEW ═══════════ -->
                    <div class="mm-system-settings"
                        style="background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 20px; margin-bottom: 24px; display: flex; align-items: center; gap: 40px; box-shadow: var(--shadow-sm);">
                        <div style="flex: 1;">
                            <h4 style="margin: 0 0 4px; font-size: 15px; font-weight: 600; color: var(--text);">System
                                Modules</h4>
                            <p style="margin: 0; font-size: 13px; color: var(--text-muted);">Enable or disable core CRM
                                functionality.</p>
                        </div>
                        <?php
                        $attendanceEnabled = dm_get_system_setting($conn, $prefix, 'attendance_enabled', '1') === '1';
                        $billingEnabled = dm_get_system_setting($conn, $prefix, 'billing_enabled', '1') === '1';
                        $attendanceVisibility = dm_get_system_setting($conn, $prefix, 'attendance_visibility', 'all');
                        $billingVisibility = dm_get_system_setting($conn, $prefix, 'billing_visibility', 'all');
                        ?>
                        <div style="display: flex; gap: 30px; flex-wrap: wrap;">
                            <div
                                style="display: flex; align-items: center; gap: 12px; padding-right: 30px; border-right: 1px solid var(--border); flex-wrap: wrap;">
                                <i class="fa-solid fa-calendar-check"
                                    style="color: var(--primary); font-size: 18px;"></i>
                                <span style="font-weight: 500; font-size: 14px;">Attendance & Operations</span>
                                <div class="form-check form-switch" style="margin-left: 8px;">
                                    <input class="form-check-input" type="checkbox"
                                        style="cursor: pointer; width: 40px; height: 20px;"
                                        <?= $attendanceEnabled ? 'checked' : '' ?>
                                        onchange="toggleSystemModule('attendance_enabled', this.checked)">
                                </div>
                                <select class="form-control" onchange="updateSystemVisibility('attendance_visibility', this.value)" style="width: 135px; font-size: 12px; margin-left: 8px; padding: 4px 8px; border-radius: 8px; border: 1.5px solid var(--border); background: #fff; cursor: pointer; height: 32px; box-sizing: border-box;">
                                    <option value="all" <?= $attendanceVisibility === 'all' ? 'selected' : '' ?>>Show to all</option>
                                    <option value="owner" <?= $attendanceVisibility === 'owner' ? 'selected' : '' ?>>Owner Only</option>
                                    <option value="role_down" <?= $attendanceVisibility === 'role_down' ? 'selected' : '' ?>>Lower Roles</option>
                                    <option value="role_equal_down" <?= $attendanceVisibility === 'role_equal_down' ? 'selected' : '' ?>>Equal & Lower</option>
                                    <option value="role_up" <?= $attendanceVisibility === 'role_up' ? 'selected' : '' ?>>Upper Roles</option>
                                </select>
                            </div>

                            <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                                <i class="fa-solid fa-file-invoice-dollar" style="color: var(--primary); font-size: 18px;"></i>
                                <span style="font-weight: 500; font-size: 14px;">Billing & Transactions</span>
                                <div class="form-check form-switch" style="margin-left: 8px;">
                                    <input class="form-check-input" type="checkbox"
                                        style="cursor: pointer; width: 40px; height: 20px;"
                                        <?= $billingEnabled ? 'checked' : '' ?>
                                        onchange="toggleSystemModule('billing_enabled', this.checked)">
                                </div>
                                <select class="form-control" onchange="updateSystemVisibility('billing_visibility', this.value)" style="width: 135px; font-size: 12px; margin-left: 8px; padding: 4px 8px; border-radius: 8px; border: 1.5px solid var(--border); background: #fff; cursor: pointer; height: 32px; box-sizing: border-box;">
                                    <option value="all" <?= $billingVisibility === 'all' ? 'selected' : '' ?>>Show to all</option>
                                    <option value="owner" <?= $billingVisibility === 'owner' ? 'selected' : '' ?>>Owner Only</option>
                                    <option value="role_down" <?= $billingVisibility === 'role_down' ? 'selected' : '' ?>>Lower Roles</option>
                                    <option value="role_equal_down" <?= $billingVisibility === 'role_equal_down' ? 'selected' : '' ?>>Equal & Lower</option>
                                    <option value="role_up" <?= $billingVisibility === 'role_up' ? 'selected' : '' ?>>Upper Roles</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="mm-grid" id="moduleGrid">
                        <?php if (empty($allModules)): ?>
                            <div class="mm-empty-state" style="grid-column:1/-1;">
                                <i class="fa-solid fa-cubes"></i>
                                <h3>No Modules Yet</h3>
                                <p>Create your first dynamic module to get started.</p>
                                <button class="mm-btn mm-btn-primary" onclick="openCreateModal()"><i
                                        class="fa-solid fa-plus"></i> Create Module</button>
                            </div>
                        <?php else: ?>
                            <?php foreach ($allModules as $mod): ?>
                                <div class="mm-module-card <?= $mod['status'] === 'inactive' ? 'mm-inactive' : '' ?>">
                                    <div class="mm-card-icon"><i class="<?= htmlspecialchars($mod['icon']) ?>"></i></div>
                                    <h4><?= htmlspecialchars($mod['name']) ?></h4>
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
                    <div class="form-group"><label class="form-label">Default Value</label><input type="text"
                            id="fieldDefault" class="form-control"></div>
                </div>
                <div class="mm-checkbox-row">
                    <label><input type="checkbox" id="fieldRequired"> Required</label>
                    <label><input type="checkbox" id="fieldUnique"> Unique</label>
                    <label><input type="checkbox" id="fieldSearchable"> Searchable</label>
                    <label><input type="checkbox" id="fieldListVisible" checked> Show in List</label>
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
                        <label class="form-label" style="font-weight:600; font-size:13px; color:var(--text);">When Field *</label>
                        <select id="workflowTriggerField" class="form-control" onchange="onWorkflowTriggerFieldChange()" style="width:100%;">
                            <option value="">-- Select trigger field --</option>
                        </select>
                    </div>
                    <div class="form-group" style="display:flex; flex-direction:column; gap:6px;">
                        <label class="form-label" style="font-weight:600; font-size:13px; color:var(--text);">Changes to Value *</label>
                        <div id="workflowTriggerValueContainer">
                            <input type="text" id="workflowTriggerValue" class="form-control" placeholder="Trigger value" style="width:100%;">
                        </div>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:15px;">
                    <div class="form-group" style="display:flex; flex-direction:column; gap:6px;">
                        <label class="form-label" style="font-weight:600; font-size:13px; color:var(--text);">Action *</label>
                        <select id="workflowActionType" class="form-control" onchange="onWorkflowActionTypeChange()" style="width:100%;">
                            <option value="email">Send Email</option>
                            <option value="whatsapp">Send WhatsApp Message</option>
                        </select>
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

    <script>
        const API = '/api/modules.php';
        const MODULE_ID = <?= $editModule ? $editModule['id'] : 'null' ?>;
        const ALL_FIELD_TYPES = <?= json_encode($fieldTypes) ?>;
        const EDIT_MODULE_FIELDS = <?= json_encode($jsFields) ?>;

        function api(action, data = {}) {
            return fetch(API, {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action, ...data })
            }).then(r => r.json());
        }
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
        function updateVisibility(val) {
            api('update', { id: MODULE_ID, visibility_rule: val }).then(r => {
                if (!r.success) alert(r.error);
            });
        }
        function toggleModuleStatus(id, currentStatus) {
            const status = currentStatus === 'active' ? 'inactive' : 'active';
            api('update', { id, status }).then(r => {
                if (r.success) location.reload();
                else alert(r.error);
            });
        }
        function toggleSystemModule(key, enabled) {
            api('update_system_setting', { key, value: enabled ? '1' : '0' }).then(r => {
                if (r.success) location.reload();
                else alert(r.error);
            });
        }
        function updateSystemVisibility(key, val) {
            api('update_system_setting', { key, value: val }).then(r => {
                if (r.success) location.reload();
                else alert(r.error);
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

        document.addEventListener('DOMContentLoaded', initIconPicker);
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
            document.getElementById('fieldDefault').value = editData ? (editData.default_value || '') : '';
            document.getElementById('fieldRequired').checked = editData ? !!editData.is_required : false;
            document.getElementById('fieldUnique').checked = editData ? !!editData.is_unique : false;
            document.getElementById('fieldSearchable').checked = editData ? !!editData.is_searchable : false;
            document.getElementById('fieldListVisible').checked = editData ? !!editData.is_list_visible : true;
            document.getElementById('fieldOptionsList').innerHTML = '';
            if (editData && editData.options) editData.options.forEach(o => addOptionRow(o.label, o.value));
            onFieldTypeChange();
            document.getElementById('addFieldModal').classList.add('show');
        }
        function editField(id, fieldData) { openFieldModal(fieldData.block_id, fieldData.module_id, fieldData); }
        function onFieldTypeChange() {
            const t = document.getElementById('fieldType').value;
            document.getElementById('fieldOptionsSection').style.display = (t === 'dropdown' || t === 'multi_picker' || t === 'radio_group') ? '' : 'none';
            document.getElementById('fieldApiConfig').style.display = t === 'api_call_picker' ? '' : 'none';
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
            const data = {
                block_id: +document.getElementById('fieldBlockId').value,
                module_id: +document.getElementById('fieldModuleId').value,
                label: document.getElementById('fieldLabel').value.trim(),
                field_type: document.getElementById('fieldType').value,
                placeholder: document.getElementById('fieldPlaceholder').value,
                default_value: document.getElementById('fieldDefault').value,
                is_required: +document.getElementById('fieldRequired').checked,
                is_unique: +document.getElementById('fieldUnique').checked,
                is_searchable: +document.getElementById('fieldSearchable').checked,
                is_list_visible: +document.getElementById('fieldListVisible').checked,
            };
            if (!data.label) return alert('Label is required');
            // Options
            const optRows = document.querySelectorAll('#fieldOptionsList .mm-option-row');
            if (optRows.length) {
                data.options = [...optRows].map(r => ({ label: r.querySelector('.opt-label').value, value: r.querySelector('.opt-value').value }));
            }
            // Config for api_call_picker
            if (data.field_type === 'api_call_picker') {
                data.config = { linked_module_id: +document.getElementById('fieldLinkedModule').value };
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
                (r.module.blocks || []).forEach(b => (b.fields || []).forEach(f => { if (f.id !== fieldId) rulesModuleFields.push(f); }));
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
            let fieldOpts = rulesModuleFields.map(f => `<option value="${f.id}" ${rule.source_field_id == f.id ? 'selected' : ''}>${f.label}</option>`).join('');
            div.innerHTML = `
        <select class="form-control rule-type"><option value="conditional" ${rule.rule_type === 'conditional' ? 'selected' : ''}>Conditional</option><option value="dependency" ${rule.rule_type === 'dependency' ? 'selected' : ''}>Dependency</option></select>
        <select class="form-control rule-source">${fieldOpts}</select>
        <select class="form-control rule-op"><option value="equals" ${rule.operator === 'equals' ? 'selected' : ''}>Equals</option><option value="not_equals" ${rule.operator === 'not_equals' ? 'selected' : ''}>Not Equals</option><option value="contains" ${rule.operator === 'contains' ? 'selected' : ''}>Contains</option><option value="not_empty" ${rule.operator === 'not_empty' ? 'selected' : ''}>Not Empty</option></select>
        <input type="text" class="form-control rule-value" placeholder="Value" value="${rule.value || ''}">
        <select class="form-control rule-action"><option value="show" ${rule.action === 'show' ? 'selected' : ''}>Show</option><option value="hide" ${rule.action === 'hide' ? 'selected' : ''}>Hide</option><option value="require" ${rule.action === 'require' ? 'selected' : ''}>Make Required</option><option value="optional" ${rule.action === 'optional' ? 'selected' : ''}>Make Optional</option></select>
        <button class="mm-icon-btn mm-icon-danger" onclick="this.parentElement.remove()"><i class="fa-solid fa-xmark"></i></button>`;
            document.getElementById('rulesList').appendChild(div);
        }
            api('save_field_rules', { field_id: fieldId, rules }).then(r => { if (r.success) { closeModal('rulesModal'); vyToast('Rules saved successfully!', 'success'); } else vyToast(r.error, 'error'); });
        }

        /* ════════════════ WORKFLOW AUTOMATION ENGINE CLIENT ════════════════ */
        let workflowsList = [];

        function switchEditorTab(tab) {
            const fieldsTab = document.getElementById('tabBtnFields');
            const workflowsTab = document.getElementById('tabBtnWorkflows');
            const fieldsContent = document.getElementById('tabContentFields');
            const workflowsContent = document.getElementById('tabContentWorkflows');
            
            if (tab === 'fields') {
                fieldsTab.classList.add('active');
                fieldsTab.querySelector('.tab-indicator').style.backgroundColor = 'var(--primary)';
                workflowsTab.classList.remove('active');
                workflowsTab.querySelector('.tab-indicator').style.backgroundColor = 'transparent';
                
                fieldsContent.style.display = 'block';
                workflowsContent.style.display = 'none';
            } else {
                workflowsTab.classList.add('active');
                workflowsTab.querySelector('.tab-indicator').style.backgroundColor = 'var(--primary)';
                fieldsTab.classList.remove('active');
                fieldsTab.querySelector('.tab-indicator').style.backgroundColor = 'transparent';
                
                fieldsContent.style.display = 'none';
                workflowsContent.style.display = 'block';
                
                loadWorkflows();
                loadWorkflowLogs();
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
                const triggerField = w.trigger_field_label || `Field #${w.trigger_field_id}`;
                const condition = `If <strong>${escapeHtml(triggerField)}</strong> changes to "<strong>${escapeHtml(w.trigger_value)}</strong>"`;
                const action = w.action_type === 'email' ? '<i class="fa-solid fa-envelope" style="color:#3b82f6;"></i> Send Email' : '<i class="fa-solid fa-message" style="color:#10b981;"></i> Send WhatsApp';
                
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
                        
                        const action = l.action_type === 'email' ? 'Email' : 'WhatsApp';
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
            
            const triggerSelect = document.getElementById('workflowTriggerField');
            triggerSelect.innerHTML = '<option value="">-- Select trigger field --</option>' + 
                EDIT_MODULE_FIELDS.map(f => `<option value="${f.id}">${escapeHtml(f.label)}</option>`).join('');
            
            const recipientSelect = document.getElementById('workflowRecipientField');
            recipientSelect.innerHTML = '<option value="">-- Select Recipient Field --</option>' + 
                EDIT_MODULE_FIELDS.map(f => `<option value="${f.id}">${escapeHtml(f.label)}</option>`).join('');
            
            triggerSelect.value = editData ? editData.trigger_field_id : '';
            onWorkflowTriggerFieldChange(editData ? editData.trigger_value : '');
            
            document.getElementById('workflowActionType').value = editData ? editData.action_type : 'email';
            onWorkflowActionTypeChange();
            
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

        function onWorkflowTriggerFieldChange(selectedValue = '') {
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
            
            if (field.field_type === 'select' || field.field_type === 'dropdown') {
                const opts = field.options || [];
                valueContainer.innerHTML = `
                    <select id="workflowTriggerValue" class="form-control" style="width:100%;">
                        <option value="">-- Choose status/option --</option>
                        ${opts.map(o => `<option value="${o.option_value}" ${selectedValue == o.option_value ? 'selected' : ''}>${escapeHtml(o.option_label || o.option_value)}</option>`).join('')}
                    </select>
                `;
            } else if (field.field_type === 'checkbox') {
                valueContainer.innerHTML = `
                    <select id="workflowTriggerValue" class="form-control" style="width:100%;">
                        <option value="1" ${selectedValue == '1' ? 'selected' : ''}>Yes</option>
                        <option value="0" ${selectedValue == '0' ? 'selected' : ''}>No</option>
                    </select>
                `;
            } else {
                valueContainer.innerHTML = `<input type="text" id="workflowTriggerValue" class="form-control" placeholder="Trigger value" value="${escapeHtml(selectedValue)}" style="width:100%;">`;
            }
        }

        function onWorkflowActionTypeChange() {
            const actionType = document.getElementById('workflowActionType').value;
            const subjectGroup = document.getElementById('workflowSubjectGroup');
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
            const triggerFieldId = document.getElementById('workflowTriggerField').value;
            const triggerValueSelect = document.getElementById('workflowTriggerValue');
            const triggerValue = triggerValueSelect ? triggerValueSelect.value.trim() : '';
            const actionType = document.getElementById('workflowActionType').value;
            const recipientFieldId = document.getElementById('workflowRecipientField').value;
            const recipientCustom = document.getElementById('workflowRecipientCustom').value.trim();
            const templateSubject = document.getElementById('workflowSubject').value.trim();
            const templateBody = document.getElementById('workflowBody').value.trim();
            
            if (!name) {
                vyToast('Rule name is required.', 'error');
                return;
            }
            if (!triggerFieldId) {
                vyToast('Trigger field is required.', 'error');
                return;
            }
            if (!triggerValue) {
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
                trigger_field_id: triggerFieldId,
                trigger_value: triggerValue,
                action_type: actionType,
                recipient_field_id: recipientFieldId || null,
                recipient_custom: recipientCustom || null,
                template_subject: templateSubject || null,
                template_body: templateBody,
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

        function toggleSidebar() { document.getElementById('sidebar').classList.toggle('sidebar-collapsed'); }
    </script>
</body>

</html>