<?php
require_once 'auth_check.php';
require_once 'includes/commerce.php';
require_once 'includes/brand.php';
require_once 'includes/dynamic_modules.php';

$v = time();

$context = commerce_get_tenant_context();
$conn = $context['conn'];
$prefix = $context['prefix'];
dm_ensure_tables($conn, $prefix);
commerce_ensure_tables($conn, $prefix);

$defaultRecordsPerPage = (int)dm_get_system_setting($conn, $prefix, 'records_per_page', 25);
if ($defaultRecordsPerPage <= 0 || $defaultRecordsPerPage > 1000) $defaultRecordsPerPage = 25;

$moduleId = (int)($_GET['module'] ?? 0);
if (!$moduleId) { header('Location: module_manager.php'); exit; }

$module = dm_fetch_module_full($conn, $prefix, $moduleId);
if (!$module) { header('Location: module_manager.php'); exit; }

$canImport = !isset($module['enable_import']) || (int)$module['enable_import'] !== 0;
$canExport = !isset($module['enable_export']) || (int)$module['enable_export'] !== 0;
$canMultiDelete = !isset($module['enable_multidelete']) || (int)$module['enable_multidelete'] !== 0;
$canCreate = !isset($module['enable_create']) || (int)$module['enable_create'] !== 0;
$canQuickCreate = !isset($module['enable_quickcreate']) || (int)$module['enable_quickcreate'] !== 0;

$quickCreateFields = [];
if ($canQuickCreate && !empty($module['blocks'])) {
    foreach ($module['blocks'] as $b) {
        foreach ($b['fields'] as $f) {
            if (!empty($f['is_quick_create'])) {
                $quickCreateFields[] = $f;
            }
        }
    }
}

// Fetch users for assigned_to fields in Quick Create modal if needed
$usersList = [];
if (!empty($quickCreateFields)) {
    try {
        $usersQuery = $conn->query("SELECT id, username, first_name, last_name FROM {$prefix}users ORDER BY username ASC");
        while ($u = $usersQuery->fetch(PDO::FETCH_ASSOC)) {
            $fullName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
            $usersList[] = [
                'id' => (int)$u['id'],
                'name' => $fullName ?: $u['username']
            ];
        }
    } catch (Exception $e) {}
}

$search = $_GET['search'] ?? '';

// Saved Filters logic
$activeFilterId = (int)($_GET['filter_id'] ?? 0);
$activeFilterRules = null;
$activeFilterName = '';

if ($activeFilterId) {
    $fStmt = $conn->prepare("SELECT filter_rules, name FROM {$prefix}module_saved_filters WHERE id = ? AND user_id = ?");
    $fStmt->execute([$activeFilterId, $_SESSION['user_id']]);
    $filterRow = $fStmt->fetch(PDO::FETCH_ASSOC);
    if ($filterRow) {
        $activeFilterRules = json_decode($filterRow['filter_rules'], true);
        $activeFilterName = $filterRow['name'];
    }
} elseif (isset($_GET['filter_rules'])) {
    $activeFilterRules = json_decode($_GET['filter_rules'], true);
} else {
    // Check if there is a default filter for this module and user
    $fStmt = $conn->prepare("SELECT id, filter_rules, name FROM {$prefix}module_saved_filters WHERE user_id = ? AND module_id = ? AND is_default = 1 LIMIT 1");
    $fStmt->execute([$_SESSION['user_id'], $moduleId]);
    $filterRow = $fStmt->fetch(PDO::FETCH_ASSOC);
    if ($filterRow) {
        $activeFilterId = (int)$filterRow['id'];
        $activeFilterRules = json_decode($filterRow['filter_rules'], true);
        $activeFilterName = $filterRow['name'];
    }
}

// Query visible fields to find default sort column (either the sys_created_at field ID, or 'created')
$fieldsStmt = $conn->prepare("SELECT id, field_type FROM {$prefix}module_fields WHERE module_id = ? AND is_list_visible = 1 ORDER BY sort_order ASC");
$fieldsStmt->execute([$moduleId]);
$visibleFields = $fieldsStmt->fetchAll(PDO::FETCH_ASSOC);

$defaultSortBy = 'created';
foreach ($visibleFields as $vf) {
    if ($vf['field_type'] === 'sys_created_at') {
        $defaultSortBy = (string)$vf['id'];
        break;
    }
}
$sortBy = $_GET['sort_by'] ?? $defaultSortBy;
$sortOrder = $_GET['sort_order'] ?? 'DESC';

$data = dm_fetch_records($conn, $prefix, $moduleId, $search ?: null, 50, 0, $activeFilterRules, $sortBy, $sortOrder);
$fields = $data['fields'];
$records = $data['records'];
$total = $data['total'];

$hasSysCreatedAt = false;
foreach ($fields as $f) {
    if ($f['field_type'] === 'sys_created_at') {
        $hasSysCreatedAt = true;
        break;
    }
}

$usersStmt = $conn->query("SELECT id, username, first_name, last_name FROM {$prefix}users");
$usersList = [];
foreach ($usersStmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
    $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
    $usersList[$u['id']] = $name ?: $u['username'];
}

// Prepare all fields list for dynamic JS filter building
$allModuleFields = [];
$hasCreatedBy = false;
$hasCreatedAt = false;
$hasUpdatedBy = false;
$hasUpdatedAt = false;

foreach ($module['blocks'] as $b) {
    foreach ($b['fields'] as $f) {
        $type = $f['field_type'];
        if ($type === 'sys_created_by') $hasCreatedBy = true;
        if ($type === 'sys_created_at') $hasCreatedAt = true;
        if ($type === 'sys_updated_by') $hasUpdatedBy = true;
        if ($type === 'sys_updated_at') $hasUpdatedAt = true;

        $allModuleFields[] = [
            'id' => (int)$f['id'],
            'label' => $f['label'],
            'field_key' => $f['field_key'],
            'field_type' => $f['field_type'],
            'options' => $f['options'] ?? []
        ];
    }
}
if (!$hasCreatedBy) {
    $allModuleFields[] = ['id' => 'created_by', 'label' => 'Created By', 'field_type' => 'user'];
}
if (!$hasCreatedAt) {
    $allModuleFields[] = ['id' => 'created_at', 'label' => 'Created On', 'field_type' => 'datetime'];
}
if (!$hasUpdatedBy) {
    $allModuleFields[] = ['id' => 'updated_by', 'label' => 'Updated By', 'field_type' => 'user'];
}
if (!$hasUpdatedAt) {
    $allModuleFields[] = ['id' => 'updated_at', 'label' => 'Updated On', 'field_type' => 'datetime'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(brand_page_title($module['name'])) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <link href="/assets/css/module_manager.css?v=<?= $v ?>" rel="stylesheet">
    <script src="/assets/js/toast.js?v=<?= $v ?>"></script>
    <?php if (!empty($quickCreateFields)): ?>
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.default.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <style>
        .ts-wrapper { width: 100%; }
        .ts-control { border-radius: 12px; border: 1px solid var(--border); padding: 8px 16px; font-size: 14px; min-height: 44px; background: var(--surface); display: flex; align-items: center; box-shadow: none !important; }
        .ts-control.focus { border-color: var(--primary); box-shadow: 0 0 0 2px rgba(123,94,240,0.2) !important; }
        .ts-dropdown { border-radius: 12px; border-color: var(--border); box-shadow: var(--shadow-lg); font-size: 14px; z-index: 10000; overflow: hidden; margin-top: 4px; }
        .ts-dropdown .active { background-color: rgba(123,94,240,0.1); color: var(--primary); }
        .dm-radio-group { display: flex; flex-wrap: wrap; gap: 16px; align-items: center; padding: 5px 0; }
        .dm-radio-group label { margin-bottom: 0 !important; white-space: nowrap; }
    </style>
    <?php endif; ?>
    <style>
        .col-item {
            transition: background 0.2s, border 0.2s;
        }
        .col-item:hover {
            background: rgba(123, 94, 240, 0.08);
        }
        .col-item.drag-over {
            border-bottom: 2px solid var(--primary) !important;
            background: rgba(123, 94, 240, 0.12) !important;
        }
        #filterPanel select, #filterPanel input:not([type="checkbox"]) {
            color: #374151 !important;
            background: #ffffff !important;
            padding: 6px 12px !important;
            height: 36px !important;
            box-sizing: border-box !important;
            border: 1.5px solid var(--border) !important;
            border-radius: 8px !important;
            font-size: 13px !important;
            display: inline-block !important;
        }
        /* Quick Filter Pills */
        .quick-filter-pill {
            padding: 6px 14px;
            border-radius: 50px;
            border: 1.5px solid var(--border);
            background: var(--surface);
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .quick-filter-pill:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: rgba(123, 94, 240, 0.04);
        }
        .quick-filter-pill.active {
            border-color: var(--primary);
            color: var(--primary);
            background: rgba(123, 94, 240, 0.08);
            font-weight: 700;
        }
        /* Premium Toasts */
        #vyToastContainer { position:fixed; top:20px; right:20px; z-index:99999; display:flex; flex-direction:column; gap:10px; }
        .vy-toast { background:#fff; border-radius:10px; padding:14px 20px; min-width:280px; max-width:360px; font-size:14px; font-weight:600; box-shadow:0 8px 25px rgba(0,0,0,.12); display:flex; align-items:center; gap:10px; opacity:0; transform:translateX(30px); transition:all .35s cubic-bezier(.25,.8,.25,1); }
        .vy-toast.show { opacity:1; transform:translateX(0); }

        /* Sortable headers style */
        .crm-table th[data-field-id], .crm-table th[data-column] {
            cursor: pointer;
            user-select: none;
            transition: background-color 0.2s;
        }
        .crm-table th[data-field-id]:hover, .crm-table th[data-column]:hover {
            background-color: rgba(123, 94, 240, 0.05) !important;
        }
        .sort-icon {
            margin-left: 6px;
            font-size: 11px;
            color: var(--text-muted);
            opacity: 0.5;
            transition: opacity 0.2s, color 0.2s;
        }
        .crm-table th[data-field-id]:hover .sort-icon, .crm-table th[data-column]:hover .sort-icon {
            opacity: 1;
            color: var(--primary);
        }
        .crm-table th.active-sort .sort-icon {
            opacity: 1;
            color: var(--primary);
        }
        .export-dropdown-item:hover {
            background-color: rgba(123, 94, 240, 0.05) !important;
            color: var(--primary) !important;
        }
    </style>
</head>
<body>
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main-content">
        <header class="topbar">
            <div class="breadcrumb">Modules / <span class="current"><?= htmlspecialchars($module['name']) ?></span></div>
            <div class="topbar-right" style="display:flex; gap:10px; align-items:center;">
                <?php if ($canQuickCreate && !empty($quickCreateFields)): ?>
                <button class="mm-btn mm-btn-outline" onclick="openQuickCreateModal()" style="display:inline-flex; align-items:center; gap:8px; padding:12px 20px; font-weight:600; height: 42px; border-radius: 12px; box-sizing: border-box;">
                    <i class="fa-solid fa-bolt" style="color:var(--primary);"></i> Quick Create
                </button>
                <?php endif; ?>
                <?php if ($canCreate): ?>
                <a href="module_record.php?module=<?= $moduleId ?>" class="btn-primary" style="width:auto;padding:12px 24px;text-decoration:none;display:inline-flex;align-items:center;gap:8px;height:42px;border-radius:12px;box-sizing:border-box;">
                    <i class="fa-solid fa-plus"></i> New Record
                </a>
                <?php endif; ?>
            </div>
        </header>
        <div class="content-scroll">
            <div class="mv-container">
                <div class="mv-toolbar">
                    <form method="GET" class="mv-search" onsubmit="handleSearch(event)">
                        <input type="hidden" name="module" value="<?= $moduleId ?>">
                        <i class="fa-solid fa-search"></i>
                        <input type="text" name="search" id="recordSearchInput" placeholder="Search records..." value="<?= htmlspecialchars($search) ?>">
                    </form>
                    <div style="display:flex;gap:10px;align-items:center;">
                        <span class="text-muted text-sm"><?= $total ?> record<?= $total !== 1 ? 's' : '' ?></span>
                        
                        <button id="btnBulkEdit" class="mm-btn mm-btn-sm mm-btn-primary" style="display: none; align-items: center; gap: 6px; height: 32px; padding: 4px 12px; border-radius: 8px; font-weight: 600;" onclick="openBulkEditModal()">
                            <i class="fa-solid fa-pen-to-square"></i> Bulk Edit (<span class="selectedCount">0</span>)
                        </button>
                        <button id="btnBulkDelete" class="mm-btn mm-btn-sm mm-btn-danger" style="display: none; align-items: center; gap: 6px; height: 32px; padding: 4px 10px; border-radius: 8px; font-weight: 600;" onclick="bulkDeleteRecords()">
                            <i class="fa-solid fa-trash"></i> Delete Selected (<span class="selectedCount">0</span>)
                        </button>
                        <button id="btnBulkExport" class="mm-btn mm-btn-sm mm-btn-outline" style="display: none; align-items: center; gap: 6px; height: 32px; padding: 4px 10px; border-radius: 8px; font-weight: 600; color: var(--primary); border-color: var(--primary);" onclick="bulkExportSelected()">
                            <i class="fa-solid fa-file-export"></i> Export Selected (<span class="selectedCount">0</span>)
                        </button>
                        <div style="display:inline-flex; align-items:center; gap:5px;">
                            <button id="btnFiltersToggle" class="mm-btn mm-btn-sm mm-btn-outline" onclick="toggleFilterPanel()" style="display:inline-flex;align-items:center;gap:6px; <?= (($activeFilterRules || $activeFilterId) ? 'background:rgba(123,94,240,0.1); border-color:var(--primary); color:var(--primary); font-weight:700;' : '') ?>">
                                <i class="fa-solid fa-filter"></i> Filters <span id="filtersActiveCount"><?= ($activeFilterRules && count($activeFilterRules) > 0) ? '(' . count($activeFilterRules) . ')' : '' ?></span>
                            </button>
                            <button id="btnFiltersClear" class="mm-btn mm-btn-sm mm-btn-outline mm-btn-danger" onclick="clearFiltersAjax(event)" style="display: <?= ($activeFilterRules || $activeFilterId) ? 'inline-flex' : 'none' ?>; align-items:center; justify-content:center; padding:6px 10px; border-radius:8px;" title="Clear Active Filter">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>

                        <div style="position:relative; display:inline-block;">
                            <button class="mm-btn mm-btn-sm mm-btn-outline" onclick="toggleColumnSelector(event)" style="display:inline-flex;align-items:center;gap:6px;">
                                <i class="fa-solid fa-table-columns"></i> Columns
                            </button>
                            <div id="columnSelectorDropdown" class="crm-card" style="display:none; position:absolute; right:0; top:40px; background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:15px; box-shadow:var(--shadow-lg); z-index:1000; min-width:240px;">
                                <h4 style="margin:0 0 10px; font-size:12px; font-weight:700; color:var(--text-muted); letter-spacing:0.5px; text-transform:uppercase; border-bottom:1px solid var(--border); padding-bottom:8px;">Columns Config</h4>
                                <div id="columnListContainer" style="display:flex; flex-direction:column; gap:4px; max-height:250px; overflow-y:auto; padding-top: 8px;">
                                    <?php foreach($fields as $f): ?>
                                    <div class="col-item" draggable="true" data-key="<?= $f['id'] ?>" style="display:flex; align-items:center; gap:8px; font-size:13px; font-weight:500; cursor:grab; margin:0; user-select:none; color:var(--text); padding:6px 8px; border-radius:8px;">
                                        <i class="fa-solid fa-grip-vertical" style="color:var(--text-muted); font-size:11px; cursor:grab;"></i>
                                        <input type="checkbox" class="col-toggle-checkbox" data-field-id="<?= $f['id'] ?>" checked style="accent-color:var(--primary); width:16px; height:16px; cursor:pointer; margin:0;">
                                        <span style="cursor:pointer; flex:1;"><?= htmlspecialchars($f['label']) ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                    <?php if (!$hasSysCreatedAt): ?>
                                    <div class="col-item" draggable="true" data-key="created" style="display:flex; align-items:center; gap:8px; font-size:13px; font-weight:500; cursor:grab; margin:0; user-select:none; color:var(--text); padding:6px 8px; border-radius:8px; border-top:1px solid var(--border); padding-top:8px; margin-top:4px;">
                                        <i class="fa-solid fa-grip-vertical" style="color:var(--text-muted); font-size:11px; cursor:grab;"></i>
                                        <input type="checkbox" class="col-toggle-checkbox" data-column="created" checked style="accent-color:var(--primary); width:16px; height:16px; cursor:pointer; margin:0;">
                                        <span style="cursor:pointer; flex:1;">Created On</span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Import Button -->
                        <?php if ($canImport): ?>
                        <button class="mm-btn mm-btn-sm mm-btn-outline" onclick="openImportModal()" style="display:inline-flex;align-items:center;gap:6px;">
                            <i class="fa-solid fa-file-import"></i> Import
                        </button>
                        <?php endif; ?>

                        <!-- Export Dropdown -->
                        <?php if ($canExport): ?>
                        <div style="position:relative; display:inline-block;">
                            <button class="mm-btn mm-btn-sm mm-btn-outline" onclick="toggleExportDropdown(event)" style="display:inline-flex;align-items:center;gap:6px;">
                                <i class="fa-solid fa-file-export"></i> Export
                            </button>
                            <div id="exportDropdown" class="crm-card" style="display:none; position:absolute; right:0; top:40px; background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:8px; box-shadow:var(--shadow-lg); z-index:1000; min-width:160px;">
                                <button class="export-dropdown-item" onclick="triggerExport('csv')" style="width:100%; text-align:left; background:none; border:none; padding:8px 12px; font-size:13px; font-weight:600; cursor:pointer; color:var(--text); border-radius:6px; display:flex; align-items:center; gap:8px;">
                                    <i class="fa-solid fa-file-csv" style="color:#10b981; font-size:16px;"></i> Export as CSV
                                </button>
                                <button class="export-dropdown-item" onclick="triggerExport('excel')" style="width:100%; text-align:left; background:none; border:none; padding:8px 12px; font-size:13px; font-weight:600; cursor:pointer; color:var(--text); border-radius:6px; display:flex; align-items:center; gap:8px; margin-top:2px;">
                                    <i class="fa-solid fa-file-excel" style="color:#3b82f6; font-size:16px;"></i> Export as Excel
                                </button>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($_SESSION['is_admin'])): ?>
                        <a href="module_manager.php?edit=<?= $moduleId ?>" class="mm-btn mm-btn-sm mm-btn-outline"><i class="fa-solid fa-cog"></i> Configure</a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Filter Pills Container -->
                <div id="quickFiltersContainer" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:15px; padding:0 5px;">
                    <!-- Saved filter pills will be rendered dynamically by JavaScript -->
                </div>

                <!-- Filters Configuring Expanding Panel -->
                <div id="filterPanel" class="crm-card" style="margin-bottom: 20px; padding: 20px; display: none;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px solid var(--border); padding-bottom: 10px; flex-wrap: wrap; gap: 10px;">
                        <h4 style="margin: 0; color: var(--text-main); font-weight: 700; display: flex; align-items: center; gap: 8px; font-size:15px;">
                            <i class="fa-solid fa-filter" style="color: var(--primary);"></i> Configure Filters
                        </h4>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <select id="savedFiltersDropdown" class="form-control" style="width: 200px; padding: 4px 10px; font-size: 13px; border-radius: 8px; border: 1.5px solid var(--border); height: 32px; background:#fff;" onchange="loadSavedFilter(this.value)">
                                <option value="">-- Apply Saved Filter --</option>
                            </select>
                            <button class="mm-btn mm-btn-sm mm-btn-outline mm-btn-danger" id="btnDeleteFilter" style="display: none; align-items: center; gap: 4px; height: 32px; padding: 4px 10px; border-radius:8px;" onclick="deleteActiveFilter()">
                                <i class="fa-solid fa-trash"></i> Delete
                            </button>
                        </div>
                    </div>

                    <div id="filterRulesContainer" style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 20px;">
                        <!-- Dynamic rules rows -->
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                        <div style="display: flex; gap: 10px;">
                            <button class="mm-btn mm-btn-sm mm-btn-outline" style="border-radius: 8px; font-size:13px; padding: 6px 12px;" onclick="createFilterRuleRow()">
                                <i class="fa-solid fa-plus"></i> Add Condition
                            </button>
                            <button class="mm-btn mm-btn-sm mm-btn-outline" style="border-radius: 8px; font-size:13px; padding: 6px 12px;" onclick="clearAllFilters()">
                                <i class="fa-solid fa-rotate-right"></i> Reset
                            </button>
                        </div>
                        
                        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                            <div id="saveFilterSection" style="display: flex; gap: 8px; align-items: center;">
                                <input type="text" id="filterSaveName" placeholder="Filter Name..." class="form-control" style="width: 180px; height: 32px; font-size: 13px; border-radius: 8px; border: 1.5px solid var(--border); padding: 4px 10px; background:#fff;">
                                <label style="display: flex; align-items: center; gap: 4px; font-size: 12px; cursor: pointer; color: var(--text-muted); font-weight: 600; user-select: none; margin:0;">
                                    <input type="checkbox" id="filterSetDefault" style="accent-color: var(--primary); width: 15px; height: 15px; cursor:pointer;"> Default
                                </label>
                                <button class="mm-btn mm-btn-sm mm-btn-outline" style="border-radius: 8px; font-size:13px; padding: 6px 12px;" onclick="saveCurrentFilter()">
                                    <i class="fa-solid fa-floppy-disk"></i> Save & Apply
                                </button>
                            </div>
                            <button class="mm-btn mm-btn-sm" style="background: var(--primary); color: #fff; border-radius: 8px; font-size:13px; padding: 6px 16px;" onclick="applyCurrentFilters()">
                                <i class="fa-solid fa-check"></i> Apply Only
                            </button>
                        </div>
                    </div>
                </div>

                <div class="table-panel">
                    <div id="selectAllBanner" style="display: none; background: rgba(123,94,240,0.08); border-bottom: 1px solid rgba(123,94,240,0.2); padding: 10px 16px; font-size: 13px; color: var(--text-dark); text-align: center; font-weight: 500;">
                        <span id="selectAllBannerText">All 0 records on this page are selected.</span>
                        <button type="button" id="btnSelectAllPages" onclick="selectAllAcrossPages()" style="background: none; border: none; color: var(--primary); font-weight: 700; cursor: pointer; text-decoration: underline; margin-left: 6px; font-size: 13px;">
                            Select all 0 records across all pages
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="crm-table">
                            <thead>
                                <tr>
                                    <?php if ($canMultiDelete): ?>
                                    <th class="checkbox-col" style="width: 40px; text-align: center;"><input type="checkbox" id="selectAllRecords" style="accent-color:var(--primary); width:16px; height:16px; cursor:pointer;" onclick="toggleSelectAll(this)"></th>
                                    <?php endif; ?>
                                    <th>#</th>
                                    <?php foreach($fields as $f): ?>
                                    <th data-field-id="<?= $f['id'] ?>" <?= $f['field_type'] === 'url' ? 'style="white-space: normal; word-break: break-all; min-width: 220px; max-width: 400px;"' : '' ?>><?= htmlspecialchars($f['label']) ?></th>
                                    <?php endforeach; ?>
                                    <?php if (!$hasSysCreatedAt): ?>
                                    <th data-column="created">Created</th>
                                    <?php endif; ?>
                                    <th class="sticky-actions-th">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="moduleRecordsTableBody">
                                <?php if(empty($records)): ?>
                                <tr><td colspan="<?= count($fields) + ($canMultiDelete ? 4 : 3) ?>" style="text-align:center;padding:40px;color:var(--text-muted);">No records found. <a href="module_record.php?module=<?= $moduleId ?>" style="color:var(--primary);font-weight:600;">Create one</a></td></tr>
                                <?php else: ?>
                                <?php foreach($records as $i => $rec): ?>
                                <tr>
                                    <?php if ($canMultiDelete): ?>
                                    <td class="checkbox-col" style="text-align: center;"><input type="checkbox" class="record-select" value="<?= $rec['id'] ?>" style="accent-color:var(--primary); width:16px; height:16px; cursor:pointer;" onchange="updateBulkDeleteButton()"></td>
                                    <?php endif; ?>
                                    <td><?= $i + 1 ?></td>
                                    <?php foreach($fields as $f): ?>
                                    <td data-field-id="<?= $f['id'] ?>" <?php
                                        if (in_array($f['field_type'], ['date', 'datetime', 'time', 'phone'])) {
                                            echo 'style="white-space: nowrap;"';
                                        } elseif ($f['field_type'] === 'url') {
                                            echo 'style="white-space: normal; word-break: break-all; min-width: 220px; max-width: 400px;"';
                                        }
                                    ?>><?php
                                        $val = $rec['values'][(int)$f['id']] ?? '';
                                        if ($f['field_type'] === 'checkbox') {
                                            echo $val ? '<i class="fa-solid fa-check" style="color:#10b981;"></i>' : '<i class="fa-solid fa-xmark" style="color:var(--text-muted);"></i>';
                                        } elseif ($f['field_type'] === 'attachment') {
                                            echo $val ? '<i class="fa-solid fa-paperclip" style="color:var(--primary);"></i> File' : '-';
                                        } elseif ($f['field_type'] === 'multi_picker') {
                                            $decoded = json_decode($val, true);
                                            echo is_array($decoded) ? htmlspecialchars(implode(', ', $decoded)) : htmlspecialchars($val);
                                        } elseif ($f['field_type'] === 'date' && $val) {
                                            $df = $_SESSION['date_format'] ?? 'd M, Y';
                                            echo htmlspecialchars(date($df, strtotime($val)));
                                        } elseif ($f['field_type'] === 'datetime' && $val) {
                                            $df = $_SESSION['date_format'] ?? 'd M, Y';
                                            $tf = ($_SESSION['time_format'] ?? '12h') === '24h' ? 'H:i' : 'h:i A';
                                            echo htmlspecialchars(date("$df $tf", strtotime($val)));
                                        } elseif ($f['field_type'] === 'time' && $val) {
                                            $tf = ($_SESSION['time_format'] ?? '12h') === '24h' ? 'H:i' : 'h:i A';
                                            echo htmlspecialchars(date($tf, strtotime($val)));
                                        } elseif ($f['field_type'] === 'assigned_to' && $val) {
                                            echo htmlspecialchars($usersList[$val] ?? "User #$val");
                                        } elseif ($f['field_type'] === 'duration' && $val !== '') {
                                            $seconds = (int)$val;
                                            if ($seconds < 60) {
                                                echo $seconds . ' sec';
                                            } elseif ($seconds < 3600) {
                                                echo floor($seconds / 60) . ' min ' . ($seconds % 60) . ' sec';
                                            } else {
                                                echo floor($seconds / 3600) . ' hr ' . floor(($seconds % 3600) / 60) . ' min';
                                            }
                                        } elseif ($f['field_type'] === 'url' && $val) {
                                            $cleanUrl = trim($val);
                                            $hrefUrl = $cleanUrl;
                                            if (!preg_match('/^https?:\/\//i', $hrefUrl) && !preg_match('/^\//', $hrefUrl)) {
                                                $hrefUrl = 'https://' . $hrefUrl;
                                            }
                                            $urlForSegment = $cleanUrl;
                                            if (substr($urlForSegment, -1) === '/') {
                                                $urlForSegment = substr($urlForSegment, 0, -1);
                                            }
                                            $lastSlash = strrpos($urlForSegment, '/');
                                            if ($lastSlash !== false) {
                                                $lastSegment = substr($urlForSegment, $lastSlash + 1);
                                            } else {
                                                $lastSegment = $urlForSegment;
                                            }
                                            if ($lastSegment === '') {
                                                $lastSegment = $cleanUrl;
                                            }
                                            $display = '.../' . $lastSegment;
                                            echo '<a href="' . htmlspecialchars($hrefUrl) . '" target="_blank" rel="noopener noreferrer" style="color: var(--primary); font-weight: 600; text-decoration: none; display: -webkit-inline-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; text-overflow: ellipsis; word-break: break-all;"><i class="fa-solid fa-link" style="font-size:11px; margin-right:4px; flex-shrink: 0; vertical-align: middle;"></i>' . htmlspecialchars($display) . '</a>';
                                        } else {
                                            $display = mb_strlen($val) > 50 ? mb_substr($val, 0, 50) . '…' : $val;
                                            echo htmlspecialchars($display ?: '-');
                                        }
                                    ?></td>
                                    <?php endforeach; ?>
                                    <?php if (!$hasSysCreatedAt): ?>
                                    <td data-column="created" class="text-muted text-sm" style="white-space: nowrap;"><?= date('d M Y', strtotime($rec['created_at'])) ?></td>
                                    <?php endif; ?>
                                    <td class="sticky-actions-td">
                                        <div style="display:flex;gap:4px;">
                                            <a href="module_record.php?module=<?= $moduleId ?>&record=<?= $rec['id'] ?>&view=1" class="mm-icon-btn" title="View"><i class="fa-solid fa-eye"></i></a>
                                            <?php if ($rec['can_edit'] ?? false): ?>
                                            <a href="module_record.php?module=<?= $moduleId ?>&record=<?= $rec['id'] ?>" class="mm-icon-btn" title="Edit"><i class="fa-solid fa-pencil"></i></a>
                                            <?php endif; ?>
                                            <a href="record_history.php?module=<?= $moduleId ?>&record=<?= $rec['id'] ?>" class="mm-icon-btn" title="History"><i class="fa-solid fa-clock-rotate-left"></i></a>
                                            <?php if ($rec['can_delete'] ?? false): ?>
                                            <button class="mm-icon-btn mm-icon-danger" onclick="deleteRecord(<?= $rec['id'] ?>)" title="Delete"><i class="fa-solid fa-trash"></i></button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination Controls -->
                    <div class="pagination-panel" style="display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; border-top: 1.5px solid var(--border); flex-wrap: wrap; gap: 15px;">
                        <div class="pagination-info" style="font-size: 13px; color: var(--text-muted); font-weight: 500;">
                            Showing <span id="paginationStart" style="font-weight: 700; color: var(--text-dark);">0</span> to <span id="paginationEnd" style="font-weight: 700; color: var(--text-dark);">0</span> of <span id="paginationTotal" style="font-weight: 700; color: var(--text-dark);">0</span> entries
                        </div>
                        <div style="display: flex; align-items: center; gap: 15px;">
                            <div class="pagination-page-size" style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-muted); font-weight: 500;">
                                Show 
                                <select id="paginationLimit" onchange="onPageSizeChange()" style="border: 1.5px solid var(--border); border-radius: 8px; padding: 4px 8px; font-size: 13px; font-weight: 600; outline: none; background: #fff; cursor: pointer; color: var(--text-dark);">
                                    <option value="10">10</option>
                                    <option value="25">25</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                    <option value="200">200</option>
                                    <option value="300">300</option>
                                    <option value="500">500</option>
                                </select>
                                entries
                            </div>
                            <div class="pagination-buttons" id="paginationButtons" style="display: flex; gap: 4px;">
                                <!-- Dynamic pagination buttons -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<script>
// Delete record helper
function deleteRecord(id) {
    if (!confirm('Delete this record?')) return;
    fetch('/api/modules.php', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'delete_record', id})
    }).then(r => r.json()).then(r => { 
        if (r.success) {
            vyToast('Record deleted successfully!', 'success');
            fetchAndRenderRecords(activeFilterRules, activeFilterId);
        } else { 
            vyToast(r.error, 'error'); 
        } 
    }).catch(e => vyToast('Error: ' + e.message, 'error'));
}

let isAllPagesSelected = false;

function toggleSelectAll(selectAllCheckbox) {
    if (!selectAllCheckbox.checked) {
        isAllPagesSelected = false;
    }
    const checkboxes = document.querySelectorAll('.record-select');
    checkboxes.forEach(cb => {
        const row = cb.closest('tr');
        if (row && row.style.display !== 'none') {
            cb.checked = selectAllCheckbox.checked;
        }
    });
    updateBulkDeleteButton();
}

function updateBulkDeleteButton() {
    const checkboxes = document.querySelectorAll('.record-select:checked');
    const selectedCount = checkboxes.length;
    const btnBulkEdit = document.getElementById('btnBulkEdit');
    const btnBulkDelete = document.getElementById('btnBulkDelete');
    const btnBulkExport = document.getElementById('btnBulkExport');
    const selectedSpans = document.querySelectorAll('.selectedCount');
    const selectAllBanner = document.getElementById('selectAllBanner');
    const selectAllBannerText = document.getElementById('selectAllBannerText');
    const btnSelectAllPages = document.getElementById('btnSelectAllPages');
    
    const totalRecords = typeof window.totalModuleRecords !== 'undefined' ? window.totalModuleRecords : <?= (int)$total ?>;
    const effectiveCount = isAllPagesSelected ? totalRecords : selectedCount;

    if (effectiveCount > 0) {
        if (btnBulkEdit) btnBulkEdit.style.display = 'inline-flex';
        if (btnBulkDelete) btnBulkDelete.style.display = 'inline-flex';
        if (btnBulkExport) btnBulkExport.style.display = 'inline-flex';
        selectedSpans.forEach(span => span.textContent = effectiveCount);
    } else {
        if (btnBulkEdit) btnBulkEdit.style.display = 'none';
        if (btnBulkDelete) btnBulkDelete.style.display = 'none';
        if (btnBulkExport) btnBulkExport.style.display = 'none';
    }
    
    const allCheckboxes = document.querySelectorAll('.record-select');
    const allVisible = Array.from(allCheckboxes).filter(cb => {
        const row = cb.closest('tr');
        return row && row.style.display !== 'none';
    });
    const allChecked = allVisible.length > 0 && allVisible.every(cb => cb.checked);
    const selectAllCheckbox = document.getElementById('selectAllRecords');
    if (selectAllCheckbox) {
        selectAllCheckbox.checked = allChecked || isAllPagesSelected;
        selectAllCheckbox.indeterminate = !allChecked && !isAllPagesSelected && allVisible.some(cb => cb.checked);
    }

    // Toggle "Select all records across all pages" banner
    if (selectAllBanner && (allChecked || isAllPagesSelected) && totalRecords > allVisible.length) {
        selectAllBanner.style.display = 'block';
        if (isAllPagesSelected) {
            if (selectAllBannerText) selectAllBannerText.textContent = `All ${totalRecords} records across all pages are selected.`;
            if (btnSelectAllPages) {
                btnSelectAllPages.textContent = 'Clear selection';
                btnSelectAllPages.onclick = clearAllSelections;
            }
        } else {
            if (selectAllBannerText) selectAllBannerText.textContent = `All ${allVisible.length} records on this page are selected.`;
            if (btnSelectAllPages) {
                btnSelectAllPages.textContent = `Select all ${totalRecords} records across all pages`;
                btnSelectAllPages.onclick = selectAllAcrossPages;
            }
        }
    } else if (selectAllBanner) {
        selectAllBanner.style.display = 'none';
    }
}

function selectAllAcrossPages() {
    isAllPagesSelected = true;
    const checkboxes = document.querySelectorAll('.record-select');
    checkboxes.forEach(cb => cb.checked = true);
    const selectAllCheckbox = document.getElementById('selectAllRecords');
    if (selectAllCheckbox) selectAllCheckbox.checked = true;
    updateBulkDeleteButton();
}

function clearAllSelections() {
    isAllPagesSelected = false;
    const checkboxes = document.querySelectorAll('.record-select');
    checkboxes.forEach(cb => cb.checked = false);
    const selectAllCheckbox = document.getElementById('selectAllRecords');
    if (selectAllCheckbox) selectAllCheckbox.checked = false;
    updateBulkDeleteButton();
}

function bulkExportSelected() {
    const checkboxes = document.querySelectorAll('.record-select:checked');
    const ids = Array.from(checkboxes).map(cb => parseInt(cb.value));
    
    if (!isAllPagesSelected && ids.length === 0) return;
    
    const params = new URLSearchParams();
    params.append('module_id', MODULE_ID);
    params.append('format', 'csv');
    
    if (!isAllPagesSelected && ids.length > 0) {
        params.append('record_ids', ids.join(','));
    } else {
        const searchInput = document.getElementById('recordSearchInput');
        if (searchInput && searchInput.value.trim()) {
            params.append('search', searchInput.value.trim());
        }
        if (activeFilterRules && activeFilterRules.length > 0) {
            params.append('filter_rules', JSON.stringify(activeFilterRules));
        } else if (activeFilterId) {
            params.append('filter_id', activeFilterId);
        }
    }
    
    window.location.href = `/api/module_export.php?${params.toString()}`;
}

function bulkDeleteRecords() {
    const checkboxes = document.querySelectorAll('.record-select:checked');
    const ids = Array.from(checkboxes).map(cb => parseInt(cb.value));
    
    if (!isAllPagesSelected && ids.length === 0) return;
    
    const totalRecords = typeof window.totalModuleRecords !== 'undefined' ? window.totalModuleRecords : <?= (int)$total ?>;
    const countToDelete = isAllPagesSelected ? totalRecords : ids.length;
    
    if (!confirm(`Are you sure you want to delete the ${countToDelete} selected record(s)?`)) return;
    
    // Show deleting loader
    const progressModal = document.getElementById('deleteProgressModal');
    if (progressModal) progressModal.style.display = 'flex';
    
    const payload = { action: 'delete_record', module_id: MODULE_ID };
    if (!isAllPagesSelected && ids.length > 0) {
        payload.ids = ids;
    } else {
        // If all pages selected, fetch all matching IDs or pass filter
        const searchInput = document.getElementById('recordSearchInput');
        const searchVal = searchInput ? searchInput.value.trim() : null;
        payload.search = searchVal || null;
        payload.all_pages = true;
        if (activeFilterRules && activeFilterRules.length > 0) {
            payload.filter_rules = activeFilterRules;
        } else if (activeFilterId) {
            payload.filter_id = activeFilterId;
        }
    }
    
    // Wait slightly to make the transition look smooth and premium
    setTimeout(() => {
        fetch('/api/modules.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        })
        .then(r => r.json())
        .then(r => {
            // Hide deleting loader
            if (progressModal) progressModal.style.display = 'none';
            
            if (r.success) {
                // Show final result modal
                const resultText = document.getElementById('deleteResultText');
                if (resultText) {
                    resultText.textContent = `Successfully deleted ${ids.length} selected record(s).`;
                }
                const resultModal = document.getElementById('deleteResultModal');
                if (resultModal) resultModal.style.display = 'flex';
                
                // Reset Select All checkbox
                const selectAllCheckbox = document.getElementById('selectAllRecords');
                if (selectAllCheckbox) selectAllCheckbox.checked = false;
                fetchAndRenderRecords(activeFilterRules, activeFilterId);
            } else {
                vyToast(r.error || 'Failed to delete records.', 'error');
            }
        })
        .catch(e => {
            if (progressModal) progressModal.style.display = 'none';
            vyToast('Error: ' + e.message, 'error');
        });
    }, 800); // 800ms delay for premium feel
}

function closeDeleteResultModal() {
    const resultModal = document.getElementById('deleteResultModal');
    if (resultModal) resultModal.style.display = 'none';
}


// Quick Create Modal JS Logic
let qcTomSelectInstances = {};
let qcPickersInitialized = false;

function openQuickCreateModal() {
    const modal = document.getElementById('quickCreateModal');
    if (!modal) return;
    modal.style.display = 'flex';
    
    if (!qcPickersInitialized) {
        // Initialize Flatpickr for Date & Time fields
        const dateFormat = "<?= $_SESSION['date_format'] ?? 'd M, Y' ?>";
        const is12Hour = "<?= $_SESSION['time_format'] ?? '12h' ?>" === '12h';
        const timeFormat = is12Hour ? 'h:i K' : 'H:i';

        if (typeof flatpickr !== 'undefined') {
            flatpickr('.qc-date-picker', {
                altInput: true,
                altFormat: dateFormat,
                dateFormat: "Y-m-d",
                allowInput: true
            });

            flatpickr('.qc-datetime-picker', {
                enableTime: true,
                altInput: true,
                altFormat: dateFormat + " " + timeFormat,
                dateFormat: "Y-m-d H:i",
                time_24hr: !is12Hour,
                allowInput: true
            });

            flatpickr('.qc-time-picker', {
                enableTime: true,
                noCalendar: true,
                altInput: true,
                altFormat: timeFormat,
                dateFormat: "H:i",
                time_24hr: !is12Hour,
                allowInput: true
            });
        }

        // Initialize TomSelect for dropdowns and multi_picker
        if (typeof TomSelect !== 'undefined') {
            document.querySelectorAll('#quickCreateModal .qc-tom-select').forEach(el => {
                let isMulti = el.hasAttribute('multiple');
                let fieldId = el.dataset.fieldId;
                let isDropdown = el.classList.contains('qc-dropdown');
                
                let tsOptions = {
                    dropdownParent: 'body',
                    plugins: isMulti ? ['remove_button'] : [],
                    sortField: { field: 'text', direction: 'asc' }
                };

                if (isDropdown && !isMulti) {
                    tsOptions.create = function(input, callback) {
                        fetch('/api/modules.php', {
                            method: 'POST', headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({action: 'add_dropdown_option', field_id: fieldId, label: input})
                        }).then(r => r.json()).then(r => {
                            if (r.success) {
                                callback({value: r.value, text: r.label});
                            } else {
                                vyToast(r.error, 'error');
                                callback();
                            }
                        }).catch(() => callback());
                    };
                } else {
                    tsOptions.create = false;
                }

                qcTomSelectInstances[fieldId] = new TomSelect(el, tsOptions);
            });
        }
        
        qcPickersInitialized = true;
    }
}

function closeQuickCreateModal() {
    const modal = document.getElementById('quickCreateModal');
    if (modal) modal.style.display = 'none';
    
    // Reset Form
    const form = document.getElementById('quickCreateForm');
    if (form) form.reset();
    
    // Clear tom select values
    for (let fid in qcTomSelectInstances) {
        if (qcTomSelectInstances[fid]) {
            qcTomSelectInstances[fid].clear();
        }
    }
    
    // Clear API pickers
    document.querySelectorAll('#quickCreateModal .qc-api-hidden').forEach(input => {
        input.value = '';
    });
    document.querySelectorAll('#quickCreateModal [id^="qc-api-display-"]').forEach(span => {
        span.textContent = 'Search & Select...';
        span.style.color = 'var(--text-muted)';
    });
    document.querySelectorAll('#quickCreateModal .rp-clear-btn').forEach(btn => {
        btn.style.display = 'none';
    });
}

// Record Picker Modal logic (Quick Create version)
let currentPickerFieldId = null;
let currentPickerModuleId = null;
let currentPickerPage = 1;
let currentPickerQuery = '';
let searchTimeout = null;

function openRecordPickerModal(fieldId, linkedModuleId) {
    currentPickerFieldId = fieldId;
    currentPickerModuleId = linkedModuleId;
    currentPickerPage = 1;
    currentPickerQuery = '';
    
    // Ensure search UI is shown and quick create form is hidden initially
    togglePickerQuickCreate(false);
    
    const searchInput = document.getElementById('recordPickerSearch');
    if (searchInput) searchInput.value = '';
    
    const rpModal = document.getElementById('recordPickerModal');
    if (rpModal) rpModal.style.display = 'flex';
    
    setTimeout(() => { if (searchInput) searchInput.focus(); }, 100);
    loadRecordPickerData();
    
    // Check if linked module supports quick create
    checkPickerQuickCreateSupport(linkedModuleId);
}

// Lookup Picker Quick Create Logic
let currentPickerQcFields = [];
let currentPickerQcTomSelects = {};

async function checkPickerQuickCreateSupport(moduleId) {
    const btn = document.getElementById('rpQuickCreateBtn');
    if (!btn) return;
    btn.style.display = 'none';
    currentPickerQcFields = [];
    
    try {
        const response = await fetch(`/api/modules.php?action=get_quick_create_fields&module_id=${moduleId}`);
        const result = await response.json();
        if (result.success && result.fields && result.fields.length > 0) {
            currentPickerQcFields = result.fields;
            btn.style.display = 'inline-flex';
        }
    } catch (e) {
        console.error('Failed to load Quick Create support for lookup:', e);
    }
}

function togglePickerQuickCreate(show) {
    const searchWrapper = document.querySelector('#recordPickerModal .rp-search-wrapper');
    const contentDiv = document.getElementById('recordPickerContent');
    const paginationDiv = document.getElementById('recordPickerPagination');
    const formContainer = document.getElementById('rpQuickCreateFormContainer');
    const createBtn = document.getElementById('rpCreateBtn');
    const qcBtn = document.getElementById('rpQuickCreateBtn');
    
    if (show) {
        if (searchWrapper) searchWrapper.style.display = 'none';
        if (contentDiv) contentDiv.style.display = 'none';
        if (paginationDiv) paginationDiv.style.display = 'none';
        if (formContainer) formContainer.style.display = 'flex';
        if (createBtn) createBtn.style.display = 'none';
        if (qcBtn) qcBtn.style.display = 'none';
        
        renderPickerQcFields();
    } else {
        if (searchWrapper) searchWrapper.style.display = 'block';
        if (contentDiv) contentDiv.style.display = 'block';
        if (paginationDiv) paginationDiv.style.display = 'flex';
        if (formContainer) formContainer.style.display = 'none';
        if (createBtn) createBtn.style.display = 'inline-flex';
        if (qcBtn && currentPickerQcFields.length > 0) qcBtn.style.display = 'inline-flex';
        
        // Reset form
        const form = document.getElementById('rpQuickCreateForm');
        if (form) form.reset();
        for (let fid in currentPickerQcTomSelects) {
            if (currentPickerQcTomSelects[fid]) currentPickerQcTomSelects[fid].destroy();
        }
        currentPickerQcTomSelects = {};
    }
}

function renderPickerQcFields() {
    const grid = document.getElementById('rpQuickCreateFieldsGrid');
    if (!grid) return;
    grid.innerHTML = '';
    
    currentPickerQcFields.forEach(field => {
        const fid = field.id;
        const type = field.field_type;
        const label = field.label;
        const val = field.default_value || '';
        const isRequired = !!field.is_required;
        const reqStar = isRequired ? '<span style="color:#ef4444;">*</span>' : '';
        const fullWidth = ['textarea', 'attachment', 'name', 'address'].includes(type);
        
        const group = document.createElement('div');
        group.className = 'rp-qc-field-group';
        group.style.cssText = `display:flex; flex-direction:column; gap:4px; text-align:left; align-items:flex-start; ${fullWidth ? 'grid-column: span 2;' : ''}`;
        group.dataset.fieldId = fid;
        group.dataset.fieldType = type;
        group.dataset.fieldLabel = label;
        if (isRequired) group.dataset.required = '1';
        
        let inputHtml = '';
        switch (type) {
            case 'text': case 'email': case 'url': case 'number': case 'currency':
                const inputType = type === 'email' ? 'email' : (type === 'url' ? 'url' : (type === 'number' || type === 'currency' ? 'number' : 'text'));
                inputHtml = `<input type="${inputType}" class="form-control rp-qc-input" data-field-id="${fid}" placeholder="${field.placeholder || ''}" value="${val}" ${type === 'currency' ? 'step="0.01"' : ''} style="width:100%; padding:8px 12px; border-radius:8px; border:1.5px solid var(--border); font-size:13px; box-sizing:border-box;">`;
                break;
            case 'phone':
                inputHtml = `
                    <div style="display:flex; gap:6px; width:100%;">
                        <select class="form-control rp-qc-phone-prefix" data-field-id="${fid}" style="width:90px; padding:8px; border-radius:8px; border:1.5px solid var(--border); font-size:13px; background:#fff; box-sizing:border-box;">
                            <option value="+91" selected>IN (+91)</option>
                            <option value="+1">US (+1)</option>
                            <option value="+44">GB (+44)</option>
                            <option value="+971">AE (+971)</option>
                        </select>
                        <input type="text" class="form-control rp-qc-phone-number" data-field-id="${fid}" placeholder="${field.placeholder || ''}" value="${val}" style="flex:1; padding:8px 12px; border-radius:8px; border:1.5px solid var(--border); font-size:13px; box-sizing:border-box;">
                    </div>
                `;
                break;
            case 'textarea':
                inputHtml = `<textarea class="form-control rp-qc-input" data-field-id="${fid}" rows="2" placeholder="${field.placeholder || ''}" style="width:100%; padding:8px 12px; border-radius:8px; border:1.5px solid var(--border); font-size:13px; box-sizing:border-box; font-family:inherit;">${val}</textarea>`;
                break;
            case 'checkbox':
                inputHtml = `
                    <label style="display:flex; align-items:center; gap:6px; cursor:pointer; margin-top:4px; user-select:none;">
                        <input type="checkbox" class="rp-qc-checkbox" data-field-id="${fid}" ${val ? 'checked' : ''} style="accent-color:var(--primary); width:16px; height:16px;">
                        <span style="font-size:13px;">${field.placeholder || 'Yes'}</span>
                    </label>
                `;
                break;
            case 'dropdown':
                let optsHtml = '<option value="">Select...</option>';
                (field.options || []).forEach(opt => {
                    optsHtml += `<option value="${escapeHtml(opt.value)}" ${val === opt.value ? 'selected' : ''}>${escapeHtml(opt.label)}</option>`;
                });
                inputHtml = `<select class="rp-qc-tom-select rp-qc-dropdown" data-field-id="${fid}" style="width:100%;">${optsHtml}</select>`;
                break;
            case 'radio_group':
                let radios = '';
                (field.options || []).forEach(opt => {
                    radios += `
                        <label style="display:flex; align-items:center; gap:6px; cursor:pointer; margin:0;">
                            <input type="radio" name="rp_qc_radio_${fid}" class="rp-qc-radio" data-field-id="${fid}" value="${escapeHtml(opt.value)}" ${val === opt.value ? 'checked' : ''} style="accent-color:var(--primary); width:14px; height:14px;">
                            <span style="font-size:13px;">${escapeHtml(opt.label)}</span>
                        </label>
                    `;
                });
                inputHtml = `<div style="display:flex; gap:12px; flex-wrap:wrap; padding:4px 0;">${radios}</div>`;
                break;
            case 'multi_picker':
                let mOptsHtml = '';
                (field.options || []).forEach(opt => {
                    mOptsHtml += `<option value="${escapeHtml(opt.value)}">${escapeHtml(opt.label)}</option>`;
                });
                inputHtml = `<select multiple class="rp-qc-tom-select rp-qc-multi" data-field-id="${fid}" style="width:100%;">${mOptsHtml}</select>`;
                break;
            case 'date':
                inputHtml = `<input type="text" class="form-control rp-qc-date-picker" data-field-id="${fid}" value="${val}" placeholder="Select Date" style="width:100%; padding:8px 12px; border-radius:8px; border:1.5px solid var(--border); font-size:13px; background:#fff; box-sizing:border-box;">`;
                break;
            case 'datetime':
                inputHtml = `<input type="text" class="form-control rp-qc-datetime-picker" data-field-id="${fid}" value="${val}" placeholder="Select Date & Time" style="width:100%; padding:8px 12px; border-radius:8px; border:1.5px solid var(--border); font-size:13px; background:#fff; box-sizing:border-box;">`;
                break;
            case 'time':
                inputHtml = `<input type="text" class="form-control rp-qc-time-picker" data-field-id="${fid}" value="${val}" placeholder="Select Time" style="width:100%; padding:8px 12px; border-radius:8px; border:1.5px solid var(--border); font-size:13px; background:#fff; box-sizing:border-box;">`;
                break;
            case 'name':
                inputHtml = `
                    <div style="display:flex; gap:8px; width:100%;">
                        <input type="text" class="form-control rp-qc-name-field" data-field-id="${fid}" data-part="first" placeholder="First Name" style="flex:1; padding:8px 12px; border-radius:8px; border:1.5px solid var(--border); font-size:13px; box-sizing:border-box;">
                        <input type="text" class="form-control rp-qc-name-field" data-field-id="${fid}" data-part="last" placeholder="Last Name" style="flex:1; padding:8px 12px; border-radius:8px; border:1.5px solid var(--border); font-size:13px; box-sizing:border-box;">
                    </div>
                `;
                break;
            default:
                inputHtml = `<input type="text" class="form-control rp-qc-input" data-field-id="${fid}" value="${val}" style="width:100%; padding:8px 12px; border-radius:8px; border:1.5px solid var(--border); font-size:13px; box-sizing:border-box;">`;
        }
        
        group.innerHTML = `<label style="font-size:12px; font-weight:600; color:var(--text-main); margin-bottom: 2px;">${label} ${reqStar}</label>${inputHtml}`;
        grid.appendChild(group);
        
        // Initialize pickers on-demand
        if (type === 'date' && typeof flatpickr !== 'undefined') {
            flatpickr(group.querySelector('.rp-qc-date-picker'), { dateFormat: "Y-m-d", allowInput: true });
        } else if (type === 'datetime' && typeof flatpickr !== 'undefined') {
            flatpickr(group.querySelector('.rp-qc-datetime-picker'), { enableTime: true, dateFormat: "Y-m-d H:i", allowInput: true });
        } else if (type === 'time' && typeof flatpickr !== 'undefined') {
            flatpickr(group.querySelector('.rp-qc-time-picker'), { enableTime: true, noCalendar: true, dateFormat: "H:i", allowInput: true });
        }
        
        if ((type === 'dropdown' || type === 'multi_picker') && typeof TomSelect !== 'undefined') {
            const selectEl = group.querySelector('.rp-qc-tom-select');
            if (selectEl) {
                currentPickerQcTomSelects[fid] = new TomSelect(selectEl, {
                    dropdownParent: 'body',
                    plugins: type === 'multi_picker' ? ['remove_button'] : [],
                    sortField: { field: 'text', direction: 'asc' }
                });
            }
        }
    });
}

async function handlePickerQuickCreateSubmit(event) {
    event.preventDefault();
    
    const values = {};
    let hasErrors = false;
    
    const groups = document.querySelectorAll('#rpQuickCreateFormContainer .rp-qc-field-group');
    groups.forEach(group => {
        const fid = group.dataset.fieldId;
        const type = group.dataset.fieldType;
        const label = group.dataset.fieldLabel;
        const isRequired = group.dataset.required === '1';
        
        let value = '';
        
        if (type === 'name') {
            const firstEl = group.querySelector('.rp-qc-name-field[data-part="first"]');
            const lastEl = group.querySelector('.rp-qc-name-field[data-part="last"]');
            const first = firstEl ? firstEl.value.trim() : '';
            const last = lastEl ? lastEl.value.trim() : '';
            if (first || last) value = JSON.stringify({first, last});
        } else if (type === 'phone') {
            const prefix = group.querySelector('.rp-qc-phone-prefix').value;
            const number = group.querySelector('.rp-qc-phone-number').value.trim();
            if (number) value = prefix + ' ' + number;
        } else if (type === 'checkbox') {
            value = group.querySelector('.rp-qc-checkbox').checked ? '1' : '0';
        } else if (type === 'radio_group') {
            const checked = group.querySelector('.rp-qc-radio:checked');
            value = checked ? checked.value : '';
        } else if (type === 'multi_picker') {
            const ts = currentPickerQcTomSelects[fid];
            const selected = ts ? ts.getValue() : [];
            value = JSON.stringify(Array.isArray(selected) ? selected : [selected]);
        } else if (type === 'dropdown') {
            const ts = currentPickerQcTomSelects[fid];
            value = ts ? ts.getValue() : '';
        } else {
            const input = group.querySelector('.rp-qc-input');
            value = input ? input.value.trim() : '';
        }
        
        if (isRequired && (!value || value === '0' || value === '[]' || value === '{"first":"","last":""}')) {
            if (typeof vyToast !== 'undefined') vyToast(`Field "${label}" is required.`, 'error');
            else alert(`Field "${label}" is required.`);
            hasErrors = true;
        }
        values[fid] = value;
    });
    
    if (hasErrors) return;
    
    // Save record via AJAX
    const formData = new FormData();
    formData.append('action', 'save_record');
    formData.append('module_id', currentPickerModuleId);
    formData.append('record_id', 0);
    formData.append('values', JSON.stringify(values));
    
    try {
        const response = await fetch('/api/modules.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            if (typeof vyToast !== 'undefined') vyToast('Record created successfully!', 'success');
            togglePickerQuickCreate(false);
            loadRecordPickerData();
        } else {
            if (typeof vyToast !== 'undefined') vyToast(result.error || 'Failed to save record.', 'error');
            else alert(result.error || 'Failed to save record.');
        }
    } catch (e) {
        console.error(e);
        alert('Network error occurred: ' + e.message);
    }
}

function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.remove('show');
        modal.style.display = 'none';
    }
}

function debouncedSearchRecordPicker() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        searchRecordPicker();
    }, 300);
}

function searchRecordPicker() {
    currentPickerQuery = document.getElementById('recordPickerSearch').value.trim();
    currentPickerPage = 1;
    loadRecordPickerData();
}

function changeRecordPickerPage(dir) {
    currentPickerPage += dir;
    loadRecordPickerData();
}

function createNewRecordFromPicker() {
    if (!currentPickerModuleId) return;
    window.open(`module_record.php?module=${currentPickerModuleId}`, '_blank');
}

function loadRecordPickerData() {
    const contentDiv = document.getElementById('recordPickerContent');
    if (!contentDiv) return;
    
    contentDiv.innerHTML = '<div style="text-align:center; padding:40px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin" style="font-size:24px; margin-bottom:10px; display:block;"></i> Loading...</div>';
    
    document.getElementById('rpPrevBtn').disabled = true;
    document.getElementById('rpNextBtn').disabled = true;

    fetch(`/api/modules.php?action=lookup_records&target_module_id=${currentPickerModuleId}&search=${encodeURIComponent(currentPickerQuery)}&page=${currentPickerPage}`)
    .then(r => r.json())
    .then(r => {
        if (!r.success) {
            contentDiv.innerHTML = `<div style="color:#ef4444; text-align:center; padding: 20px;">Error: ${r.error}</div>`;
            return;
        }

        if (r.records.length === 0) {
            contentDiv.innerHTML = `
                <div style="text-align:center; padding:40px; color:var(--text-muted); font-size: 14px;">
                    <p style="margin-bottom:15px;">No records found.</p>
                    <button type="button" class="mm-btn mm-btn-primary" onclick="createNewRecordFromPicker()">
                        <i class="fa-solid fa-plus"></i> Create New Record
                    </button>
                </div>
            `;
            document.getElementById('rpPageInfo').textContent = 'Page 1 of 1';
            return;
        }

        let html = '<div class="rp-list-section">Results</div>';
        html += '<div style="display:flex; flex-direction:column; gap:4px;">';
        r.records.forEach(rec => {
            const firstLetter = rec.display_value ? rec.display_value.charAt(0).toUpperCase() : '#';
            html += `
                <div class="rp-item" onclick="selectRecordFromPicker(${rec.id}, '${escapeHtml(rec.display_value)}')" style="display: flex; align-items: center; padding: 10px; border-radius: 8px; cursor: pointer; transition: background 0.2s; gap: 12px; hover: background: rgba(0,0,0,0.03);">
                    <div class="rp-item-icon" style="width: 32px; height: 32px; border-radius: 50%; background: rgba(123,94,240,0.1); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 600; flex-shrink: 0;">${firstLetter}</div>
                    <div class="rp-item-content" style="flex: 1; min-width: 0;">
                        <div class="rp-item-title" style="font-size: 13px; font-weight: 600; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 2px;">${escapeHtml(rec.display_value)}</div>
                        <div class="rp-item-subtitle" style="font-size: 11px; color: var(--text-muted);">Record #${rec.id}</div>
                    </div>
                    <div style="font-size: 12px; font-weight: 600; color: var(--primary); flex-shrink: 0;">
                        Select
                    </div>
                </div>
            `;
        });
        html += '</div>';

        contentDiv.innerHTML = html;

        const totalPages = Math.ceil(r.total / r.limit) || 1;
        document.getElementById('rpPageInfo').textContent = `Page ${r.page} of ${totalPages}`;
        
        document.getElementById('rpPrevBtn').disabled = r.page <= 1;
        document.getElementById('rpNextBtn').disabled = r.page >= totalPages;
    })
    .catch(() => {
        contentDiv.innerHTML = `<div style="color:#ef4444; text-align:center; padding: 20px;">Network error occurred.</div>`;
    });
}

function selectRecordFromPicker(id, displayValue) {
    const hiddenInput = document.querySelector(`.qc-api-hidden[data-field-id="${currentPickerFieldId}"]`);
    if (hiddenInput) {
        hiddenInput.value = id;
        hiddenInput.dispatchEvent(new Event('change'));
    }
    
    const displaySpan = document.getElementById(`qc-api-display-${currentPickerFieldId}`);
    if (displaySpan) {
        displaySpan.textContent = displayValue;
        displaySpan.style.color = 'var(--text-main)';
    }

    const clearBtn = document.getElementById(`qc-clear-btn-${currentPickerFieldId}`);
    if (clearBtn) clearBtn.style.display = 'inline-flex';

    closeModal('recordPickerModal');
}

function clearQcApiPicker(fieldId) {
    const hiddenInput = document.querySelector(`.qc-api-hidden[data-field-id="${fieldId}"]`);
    if (hiddenInput) {
        hiddenInput.value = '';
        hiddenInput.dispatchEvent(new Event('change'));
    }
    
    const displaySpan = document.getElementById(`qc-api-display-${fieldId}`);
    if (displaySpan) {
        displaySpan.textContent = 'Search & Select...';
        displaySpan.style.color = 'var(--text-muted)';
    }

    const clearBtn = document.getElementById(`qc-clear-btn-${fieldId}`);
    if (clearBtn) clearBtn.style.display = 'none';
}

function escapeHtml(text) {
    if (!text) return '';
    return text.toString()
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

async function handleQuickCreateSubmit(event) {
    event.preventDefault();
    
    // Collect and validate fields
    const values = {};
    let hasValidationErrors = false;
    
    const fieldGroups = document.querySelectorAll('#quickCreateModal .qc-field-group');
    fieldGroups.forEach(group => {
        const fid = group.dataset.fieldId;
        const type = group.dataset.fieldType;
        const label = group.dataset.fieldLabel;
        const isRequired = group.dataset.required === '1';
        
        let value = '';
        
        if (type === 'name') {
            const firstInput = group.querySelector('.qc-name-field[data-part="first"]');
            const lastInput = group.querySelector('.qc-name-field[data-part="last"]');
            const first = firstInput ? firstInput.value.trim() : '';
            const last = lastInput ? lastInput.value.trim() : '';
            if (first || last) {
                value = JSON.stringify({first, last});
            }
        } else if (type === 'phone') {
            const prefix = group.querySelector('.qc-phone-prefix').value;
            const number = group.querySelector('.qc-phone-number').value.trim();
            if (number) {
                value = prefix + ' ' + number;
            }
        } else if (type === 'checkbox') {
            value = group.querySelector('.qc-checkbox').checked ? '1' : '0';
        } else if (type === 'radio_group') {
            const checkedRadio = group.querySelector('.qc-radio:checked');
            value = checkedRadio ? checkedRadio.value : '';
        } else if (type === 'multi_picker') {
            const tsInstance = qcTomSelectInstances[fid];
            const selected = tsInstance ? tsInstance.getValue() : [];
            value = JSON.stringify(Array.isArray(selected) ? selected : [selected]);
        } else if (type === 'dropdown') {
            const tsInstance = qcTomSelectInstances[fid];
            value = tsInstance ? tsInstance.getValue() : '';
        } else if (type === 'api_call_picker') {
            value = group.querySelector('.qc-api-hidden').value;
        } else if (type === 'assigned_to') {
            value = group.querySelector('.qc-select').value;
        } else {
            const input = group.querySelector('.qc-input');
            value = input ? input.value.trim() : '';
        }
        
        if (isRequired && (!value || value === '0' || value === '[]' || value === '{"first":"","last":""}')) {
            vyToast(`Field "${label}" is required.`, 'error');
            hasValidationErrors = true;
        }
        
        values[fid] = value;
    });
    
    if (hasValidationErrors) return;
    
    // Save record via AJAX
    const formData = new FormData();
    formData.append('action', 'save_record');
    formData.append('module_id', MODULE_ID);
    formData.append('record_id', 0);
    formData.append('values', JSON.stringify(values));
    
    try {
        const response = await fetch('/api/modules.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            vyToast('Record created successfully!', 'success');
            closeQuickCreateModal();
            fetchAndRenderRecords(activeFilterRules, activeFilterId);
        } else {
            vyToast(result.error || 'Failed to save record.', 'error');
        }
    } catch(e) {
        vyToast('Network error occurred: ' + e.message, 'error');
    }
}


function toggleSidebar() { document.getElementById('sidebar').classList.toggle('sidebar-collapsed'); }

// Column Configurator Logic
const USER_ID = <?= (int)($_SESSION['user_id'] ?? 0) ?>;
const MODULE_ID = <?= $moduleId ?>;
const STORAGE_KEY = `vycrm_col_vis_${USER_ID}_${MODULE_ID}`;
const ORDER_KEY = `vycrm_col_order_${USER_ID}_${MODULE_ID}`;
const CAN_MULTI_DELETE = <?= $canMultiDelete ? 'true' : 'false' ?>;

function toggleColumnSelector(event) {
    event.stopPropagation();
    const dropdown = document.getElementById('columnSelectorDropdown');
    dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
}

// Pagination state variables
const DEFAULT_PAGE_LIMIT = <?= $defaultRecordsPerPage ?>;
let currentPage = 1;
let currentPageLimit = DEFAULT_PAGE_LIMIT;

// Sorting state variables
const DEFAULT_SORT_BY = <?= json_encode($defaultSortBy) ?>;
const DEFAULT_SORT_ORDER = 'DESC';
let currentSortBy = <?= json_encode($sortBy) ?>;
let currentSortOrder = <?= json_encode($sortOrder) ?>;

// Saved Filters and Dynamic Rules logic
const COMPANY_USERS = <?= json_encode($usersList) ?>;
const ALL_MODULE_FIELDS = <?= json_encode($allModuleFields) ?>;

let savedFiltersList = [];
let activeFilterId = <?= (int)($activeFilterId ?? 0) ?>;
let activeFilterName = <?= json_encode($activeFilterName ?? '') ?>;
let activeFilterRules = <?= json_encode($activeFilterRules ?? []) ?>;
let filterRowsCount = 0;

function createFilterRuleRow(ruleData = null) {
    const container = document.getElementById('filterRulesContainer');
    const rowId = `filter-rule-row-${++filterRowsCount}`;
    
    const rowHtml = `
        <div class="filter-rule-row" id="${rowId}" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 8px;">
            <select class="form-control filter-field-select" style="width: 200px; height: 36px; font-size: 13px; background:#fff; border: 1.5px solid var(--border); border-radius:8px; cursor:pointer;" onchange="onFilterFieldChange('${rowId}', this.value)">
                <option value="">-- Choose Field --</option>
                ${ALL_MODULE_FIELDS.map(f => `<option value="${f.id}">${escapeHtml(f.label)}</option>`).join('')}
            </select>
            
            <select class="form-control filter-operator-select" style="width: 140px; height: 36px; font-size: 13px; background:#fff; border: 1.5px solid var(--border); border-radius:8px; cursor:pointer;">
                <option value="=">=</option>
                <option value="!=">!=</option>
                <option value="LIKE">contains</option>
                <option value="NOT LIKE">does not contain</option>
                <option value=">">&gt;</option>
                <option value="<">&lt;</option>
                <option value=">=">&gt;=</option>
                <option value="<=">&lt;=</option>
            </select>
            
            <div class="filter-value-container" style="flex: 1; min-width: 200px;">
                <input type="text" class="form-control filter-value-input" style="height: 36px; font-size: 13px; background:#fff; border: 1.5px solid var(--border); border-radius:8px;" placeholder="Enter value...">
            </div>
            
            <button class="btn-icon" style="background: rgba(239, 68, 68, 0.08); color: #ef4444; border: 1.5px solid rgba(239, 68, 68, 0.2); border-radius: 8px; width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer;" onclick="document.getElementById('${rowId}').remove()" title="Remove Condition">
                <i class="fa-solid fa-trash"></i>
            </button>
        </div>
    `;
    
    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = rowHtml;
    const element = tempDiv.firstElementChild;
    container.appendChild(element);
    
    if (ruleData) {
        element.querySelector('.filter-field-select').value = ruleData.field_id;
        onFilterFieldChange(rowId, ruleData.field_id, ruleData.value);
        element.querySelector('.filter-operator-select').value = ruleData.operator;
    }
}

function onFilterFieldChange(rowId, fieldId, value = '') {
    const rowEl = document.getElementById(rowId);
    if (!rowEl) return;
    
    const valueContainer = rowEl.querySelector('.filter-value-container');
    const field = ALL_MODULE_FIELDS.find(f => f.id == fieldId);
    
    if (!field) {
        valueContainer.innerHTML = `<input type="text" class="form-control filter-value-input" style="height: 36px; font-size: 13px; background:#fff; border: 1.5px solid var(--border); border-radius:8px;" placeholder="Enter value...">`;
        return;
    }
    
    let html = '';
    if (field.field_type === 'user' || field.field_type === 'assigned_to' || field.field_type === 'sys_created_by' || field.field_type === 'sys_updated_by') {
        html = `
            <select class="form-control filter-value-input" style="height: 36px; font-size: 13px; background:#fff; border: 1.5px solid var(--border); border-radius:8px; cursor:pointer;">
                <option value="">-- Select User --</option>
                ${Object.entries(COMPANY_USERS).map(([id, name]) => `
                    <option value="${id}" ${value == id ? 'selected' : ''}>${escapeHtml(name)}</option>
                `).join('')}
            </select>
        `;
    } else if (field.field_type === 'checkbox') {
        html = `
            <select class="form-control filter-value-input" style="height: 36px; font-size: 13px; background:#fff; border: 1.5px solid var(--border); border-radius:8px; cursor:pointer;">
                <option value="1" ${value == '1' ? 'selected' : ''}>Yes</option>
                <option value="0" ${value == '0' ? 'selected' : ''}>No</option>
            </select>
        `;
    } else if (field.field_type === 'select' || field.field_type === 'dropdown') {
        const opts = field.options || [];
        html = `
            <select class="form-control filter-value-input" style="height: 36px; font-size: 13px; background:#fff; border: 1.5px solid var(--border); border-radius:8px; cursor:pointer;">
                <option value="">-- Choose Option --</option>
                ${opts.map(opt => `
                    <option value="${opt.option_value}" ${value == opt.option_value ? 'selected' : ''}>${escapeHtml(opt.option_label || opt.option_value)}</option>
                `).join('')}
            </select>
        `;
    } else if (field.field_type === 'date' || field.field_type === 'sys_created_at' || field.field_type === 'sys_updated_at') {
        let valStr = value;
        if (value && value.includes(' ')) {
            valStr = value.split(' ')[0]; // extract date part only
        }
        html = `<input type="date" class="form-control filter-value-input" style="height: 36px; font-size: 13px; background:#fff; border: 1.5px solid var(--border); border-radius:8px;" value="${escapeHtml(valStr)}">`;
    } else if (field.field_type === 'datetime') {
        let valStr = '';
        if (value) {
            valStr = value.replace(' ', 'T').substring(0, 16);
        }
        html = `<input type="datetime-local" class="form-control filter-value-input" style="height: 36px; font-size: 13px; background:#fff; border: 1.5px solid var(--border); border-radius:8px;" value="${escapeHtml(valStr)}">`;
    } else if (field.field_type === 'time') {
        html = `<input type="time" class="form-control filter-value-input" style="height: 36px; font-size: 13px; background:#fff; border: 1.5px solid var(--border); border-radius:8px;" value="${escapeHtml(value)}">`;
    } else {
        html = `<input type="text" class="form-control filter-value-input" style="height: 36px; font-size: 13px; background:#fff; border: 1.5px solid var(--border); border-radius:8px;" placeholder="Enter value..." value="${escapeHtml(value)}">`;
    }
    
    valueContainer.innerHTML = html;
}

function getCurrentFilterRules() {
    const rules = [];
    document.querySelectorAll('.filter-rule-row').forEach(row => {
        const fieldSelect = row.querySelector('.filter-field-select');
        const operatorSelect = row.querySelector('.filter-operator-select');
        const valueInput = row.querySelector('.filter-value-input');
        
        if (fieldSelect && fieldSelect.value) {
            rules.push({
                field_id: fieldSelect.value,
                operator: operatorSelect.value,
                value: valueInput ? valueInput.value : ''
            });
        }
    });
    return rules;
}

async function loadSavedFiltersList() {
    try {
        const res = await fetch(`/api/modules.php?action=list_filters&module_id=${MODULE_ID}`);
        const data = await res.json();
        if (data.success && data.filters) {
            savedFiltersList = data.filters;
            const dropdown = document.getElementById('savedFiltersDropdown');
            dropdown.innerHTML = `<option value="">-- Apply Saved Filter --</option>` + 
                savedFiltersList.map(f => `
                    <option value="${f.id}" ${activeFilterId == f.id ? 'selected' : ''}>
                        ${escapeHtml(f.name)} ${f.is_default ? '(Default)' : ''}
                    </option>
                `).join('');
            
            updateFilterControlUI();
            renderQuickFilterPills();
        }
    } catch(e) {
        console.error("Failed to load saved filters list", e);
    }
}

function renderQuickFilterPills() {
    const container = document.getElementById('quickFiltersContainer');
    if (!container) return;
    
    if (savedFiltersList.length === 0) {
        container.style.display = 'none';
        return;
    }
    
    container.style.display = 'flex';
    let html = `
        <button class="quick-filter-pill ${(!activeFilterId && !activeFilterRules?.length) ? 'active' : ''}" onclick="applyQuickFilter(0)">
            All
        </button>
    `;
    
    savedFiltersList.forEach(f => {
        const isActive = activeFilterId == f.id;
        html += `
            <button class="quick-filter-pill ${isActive ? 'active' : ''}" onclick="applyQuickFilter(${f.id})">
                <i class="fa-solid fa-filter" style="font-size:11px;"></i>
                ${escapeHtml(f.name)}
                ${f.is_default ? '<span style="font-size:9px; opacity:0.8; font-weight:normal;">(Default)</span>' : ''}
            </button>
        `;
    });
    
    container.innerHTML = html;
}

async function applyQuickFilter(id) {
    if (!id) {
        await clearFiltersAjax();
        return;
    }
    
    const filter = savedFiltersList.find(f => f.id == id);
    if (!filter) return;
    
    activeFilterId = filter.id;
    activeFilterName = filter.name;
    activeFilterRules = filter.filter_rules || [];
    
    const dropdown = document.getElementById('savedFiltersDropdown');
    if (dropdown) dropdown.value = id;
    
    const container = document.getElementById('filterRulesContainer');
    if (container) {
        container.innerHTML = '';
        if (activeFilterRules.length > 0) {
            activeFilterRules.forEach(rule => createFilterRuleRow(rule));
        } else {
            createFilterRuleRow();
        }
    }
    
    await fetchAndRenderRecords(activeFilterRules, activeFilterId);
    
    updateFilterControlUI();
    renderQuickFilterPills();
}

function updateFilterControlUI() {
    const delBtn = document.getElementById('btnDeleteFilter');
    const saveNameInput = document.getElementById('filterSaveName');
    const setDefaultCheckbox = document.getElementById('filterSetDefault');
    
    if (activeFilterId > 0) {
        delBtn.style.display = 'inline-flex';
        saveNameInput.value = activeFilterName;
        const currentFilter = savedFiltersList.find(f => f.id == activeFilterId);
        if (currentFilter) {
            setDefaultCheckbox.checked = !!currentFilter.is_default;
        }
    } else {
        delBtn.style.display = 'none';
    }
}

function loadSavedFilter(id) {
    if (!id) {
        applyQuickFilter(0);
        return;
    }
    applyQuickFilter(id);
}

function applyCurrentFilters() {
    const rules = getCurrentFilterRules();
    activeFilterRules = rules;
    activeFilterId = 0;
    activeFilterName = '';
    
    const dropdown = document.getElementById('savedFiltersDropdown');
    if (dropdown) dropdown.value = '';
    
    fetchAndRenderRecords(rules, 0).then(() => {
        updateFilterControlUI();
        renderQuickFilterPills();
    });
}

async function clearFiltersAjax(event) {
    if (event) event.preventDefault();
    
    activeFilterRules = [];
    activeFilterId = 0;
    activeFilterName = '';
    
    const container = document.getElementById('filterRulesContainer');
    if (container) {
        container.innerHTML = '';
        createFilterRuleRow();
    }
    
    const saveNameInput = document.getElementById('filterSaveName');
    if (saveNameInput) saveNameInput.value = '';
    const setDefaultCheckbox = document.getElementById('filterSetDefault');
    if (setDefaultCheckbox) setDefaultCheckbox.checked = false;
    
    const dropdown = document.getElementById('savedFiltersDropdown');
    if (dropdown) dropdown.value = '';
    
    await fetchAndRenderRecords(null, 0);
    
    updateFilterControlUI();
    renderQuickFilterPills();
}

function clearAllFilters() {
    document.getElementById('filterRulesContainer').innerHTML = '';
    document.getElementById('filterSaveName').value = '';
    document.getElementById('filterSetDefault').checked = false;
    createFilterRuleRow();
}

function toggleFilterPanel() {
    const panel = document.getElementById('filterPanel');
    if (panel.style.display === 'none') {
        panel.style.display = 'block';
        if (document.getElementById('filterRulesContainer').children.length === 0) {
            if (activeFilterRules && activeFilterRules.length > 0) {
                activeFilterRules.forEach(rule => createFilterRuleRow(rule));
            } else {
                createFilterRuleRow();
            }
        }
    } else {
        panel.style.display = 'none';
    }
}

async function saveCurrentFilter() {
    const name = document.getElementById('filterSaveName').value.trim();
    const isDefault = document.getElementById('filterSetDefault').checked ? 1 : 0;
    const rules = getCurrentFilterRules();
    
    if (!name) {
        vyToast('Please enter a name for the filter.', 'error');
        return;
    }
    if (rules.length === 0) {
        vyToast('Please add at least one condition/rule to save.', 'error');
        return;
    }
    
    const payload = {
        action: 'save_filter',
        module_id: MODULE_ID,
        name: name,
        filter_rules: rules,
        is_default: isDefault
    };
    
    if (activeFilterId > 0 && name === activeFilterName) {
        payload.id = activeFilterId;
    }
    
    try {
        const res = await fetch('/api/modules.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.success && data.id) {
            vyToast('Filter saved successfully!', 'success');
            activeFilterId = data.id;
            activeFilterName = name;
            activeFilterRules = rules;
            
            await loadSavedFiltersList();
            await fetchAndRenderRecords(activeFilterRules, activeFilterId);
        } else {
            vyToast('Error saving filter: ' + (data.error || 'Unknown error'), 'error');
        }
    } catch(e) {
        vyToast('Request failed: ' + e.message, 'error');
    }
}

async function deleteActiveFilter() {
    if (!activeFilterId) return;
    if (!confirm('Are you sure you want to delete this saved filter?')) return;
    
    try {
        const res = await fetch('/api/modules.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                action: 'delete_filter',
                id: activeFilterId
            })
        });
        const data = await res.json();
        if (data.success) {
            vyToast('Filter deleted successfully!', 'success');
            activeFilterId = 0;
            activeFilterName = '';
            activeFilterRules = [];
            
            const container = document.getElementById('filterRulesContainer');
            if (container) {
                container.innerHTML = '';
                createFilterRuleRow();
            }
            
            const saveNameInput = document.getElementById('filterSaveName');
            if (saveNameInput) saveNameInput.value = '';
            const setDefaultCheckbox = document.getElementById('filterSetDefault');
            if (setDefaultCheckbox) setDefaultCheckbox.checked = false;
            
            await loadSavedFiltersList();
            await fetchAndRenderRecords(null, 0);
        } else {
            vyToast('Error deleting filter: ' + (data.error || 'Unknown error'), 'error');
        }
    } catch(e) {
        vyToast('Request failed: ' + e.message, 'error');
    }
}

async function fetchAndRenderRecords(filterRules = null, filterId = 0, page = 1) {
    currentPage = page;
    const searchInput = document.getElementById('recordSearchInput');
    const searchVal = searchInput ? searchInput.value.trim() : '';
    
    const limitSelect = document.getElementById('paginationLimit');
    if (limitSelect) {
        const val = parseInt(currentPageLimit);
        let optionExists = false;
        for (let i = 0; i < limitSelect.options.length; i++) {
            if (parseInt(limitSelect.options[i].value) === val) {
                optionExists = true;
                break;
            }
        }
        if (!optionExists) {
            const opt = document.createElement('option');
            opt.value = val;
            opt.textContent = val;
            let inserted = false;
            for (let i = 0; i < limitSelect.options.length; i++) {
                if (parseInt(limitSelect.options[i].value) > val) {
                    limitSelect.add(opt, i);
                    inserted = true;
                    break;
                }
            }
            if (!inserted) {
                limitSelect.add(opt);
            }
        }
        limitSelect.value = val;
    }
    
    const offset = (currentPage - 1) * currentPageLimit;
    
    const payload = {
        action: 'list_records',
        module_id: MODULE_ID,
        search: searchVal || null,
        limit: currentPageLimit,
        offset: offset,
        sort_by: currentSortBy,
        sort_order: currentSortOrder
    };
    
    if (filterRules && filterRules.length > 0) {
        payload.filter_rules = filterRules;
    } else if (filterId) {
        payload.filter_id = filterId;
    }
    
    try {
        const res = await fetch('/api/modules.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        });
        const r = await res.json();
        
        if (r.success && r.data) {
            renderRecordsTable(r.data.fields, r.data.records);
            updateHeaderSortIcons();
            
            const total = r.data.total;
            window.totalModuleRecords = total;
            isAllPagesSelected = false;
            const selectAllCheckbox = document.getElementById('selectAllRecords');
            if (selectAllCheckbox) selectAllCheckbox.checked = false;
            updateBulkDeleteButton();

            const countLabel = document.querySelector('.mv-toolbar .text-muted.text-sm');
            if (countLabel) {
                countLabel.textContent = `${total} record${total !== 1 ? 's' : ''}`;
            }
            
            // Update pagination stats UI
            const start = total === 0 ? 0 : offset + 1;
            const end = Math.min(offset + currentPageLimit, total);
            
            const pStart = document.getElementById('paginationStart');
            const pEnd = document.getElementById('paginationEnd');
            const pTotal = document.getElementById('paginationTotal');
            if (pStart) pStart.textContent = start;
            if (pEnd) pEnd.textContent = end;
            if (pTotal) pTotal.textContent = total;
            
            renderPaginationButtons(total, currentPageLimit, currentPage);
            
            const filtersBtn = document.getElementById('btnFiltersToggle');
            const clearBtn = document.getElementById('btnFiltersClear');
            const activeCountSpan = document.getElementById('filtersActiveCount');
            
            const activeRulesCount = filterRules ? filterRules.length : 0;
            const hasActiveFilter = (activeRulesCount > 0 || filterId > 0);
            
            if (filtersBtn) {
                if (hasActiveFilter) {
                    filtersBtn.style.background = 'rgba(123,94,240,0.1)';
                    filtersBtn.style.borderColor = 'var(--primary)';
                    filtersBtn.style.color = 'var(--primary)';
                    filtersBtn.style.fontWeight = '700';
                    activeCountSpan.textContent = activeRulesCount > 0 ? `(${activeRulesCount})` : '';
                } else {
                    filtersBtn.style.background = '';
                    filtersBtn.style.borderColor = '';
                    filtersBtn.style.color = '';
                    filtersBtn.style.fontWeight = '';
                    activeCountSpan.textContent = '';
                }
            }
            
            if (clearBtn) {
                clearBtn.style.display = hasActiveFilter ? 'inline-flex' : 'none';
            }
        } else {
            vyToast('Failed to load records: ' + (r.error || 'Unknown error'), 'error');
        }
    } catch(e) {
        vyToast('Failed to fetch records: ' + e.message, 'error');
    }
}

function onPageSizeChange() {
    const sizeSelect = document.getElementById('paginationLimit');
    if (!sizeSelect) return;
    currentPageLimit = parseInt(sizeSelect.value);
    currentPage = 1;
    fetchAndRenderRecords(activeFilterRules, activeFilterId, 1);
}

function goToPage(page) {
    currentPage = page;
    fetchAndRenderRecords(activeFilterRules, activeFilterId, page);
}

function renderPaginationButtons(total, limit, currentPage) {
    const container = document.getElementById('paginationButtons');
    if (!container) return;
    container.innerHTML = '';
    
    const totalPages = Math.ceil(total / limit);
    if (totalPages <= 1) {
        return;
    }
    
    const addButton = (page, label, active = false, disabled = false) => {
        const btn = document.createElement('button');
        btn.className = active ? 'mm-btn mm-btn-sm' : 'mm-btn mm-btn-sm mm-btn-outline';
        btn.style.cssText = `
            border-radius: 8px;
            font-size: 13px;
            padding: 4px 10px;
            min-width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            ${active ? 'background: var(--primary); color: #fff;' : 'background: #fff; border: 1.5px solid var(--border); color: var(--text-dark);'}
            ${disabled ? 'opacity: 0.5; cursor: not-allowed;' : 'cursor: pointer;'}
        `;
        btn.textContent = label;
        if (!disabled) {
            btn.onclick = () => goToPage(page);
        }
        container.appendChild(btn);
    };
    
    // Prev Button
    addButton(currentPage - 1, 'Prev', false, currentPage === 1);
    
    // Page Numbers
    const range = 2;
    for (let i = 1; i <= totalPages; i++) {
        if (i === 1 || i === totalPages || (i >= currentPage - range && i <= currentPage + range)) {
            addButton(i, i, i === currentPage);
        } else if (i === currentPage - range - 1 || i === currentPage + range + 1) {
            const dots = document.createElement('span');
            dots.textContent = '...';
            dots.style.cssText = 'display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; color: var(--text-muted); font-weight: 600;';
            container.appendChild(dots);
        }
    }
    
    // Next Button
    addButton(currentPage + 1, 'Next', false, currentPage === totalPages);
}function renderRecordsTable(fields, records) {
    const selectAllCheckbox = document.getElementById('selectAllRecords');
    if (selectAllCheckbox) selectAllCheckbox.checked = false;

    const tbody = document.getElementById('moduleRecordsTableBody');
    if (!tbody) return;
    
    if (!records || records.length === 0) {
        tbody.innerHTML = `<tr><td colspan="${fields.length + (CAN_MULTI_DELETE ? 4 : 3)}" style="text-align:center;padding:40px;color:var(--text-muted);">No records found. <a href="module_record.php?module=${MODULE_ID}" style="color:var(--primary);font-weight:600;">Create one</a></td></tr>`;
        updateBulkDeleteButton();
        return;
    }
    
    let html = '';
    records.forEach((rec, i) => {
        html += `<tr>`;
        if (CAN_MULTI_DELETE) {
            html += `<td class="checkbox-col" style="text-align: center;"><input type="checkbox" class="record-select" value="${rec.id}" style="accent-color:var(--primary); width:16px; height:16px; cursor:pointer;" onchange="updateBulkDeleteButton()"></td>`;
        }
        html += `<td>${i + 1}</td>`;
        
        fields.forEach(f => {
            const val = rec.values[f.id] !== undefined ? rec.values[f.id] : '';
            let cellStyle = '';
            if (['date', 'datetime', 'time', 'phone'].includes(f.field_type)) {
                cellStyle = 'style="white-space: nowrap;"';
            } else if (f.field_type === 'url') {
                cellStyle = 'style="white-space: normal; word-break: break-all; min-width: 220px; max-width: 400px;"';
            }
            
            html += `<td data-field-id="${f.id}" ${cellStyle}>${formatFieldValue(val, f.field_type, f.options)}</td>`;
        });
        
        const hasSysCreatedAt = fields.some(f => f.field_type === 'sys_created_at');
        if (!hasSysCreatedAt) {
            html += `<td data-column="created" class="text-muted text-sm" style="white-space: nowrap;">${formatCreatedDate(rec.created_at)}</td>`;
        }
        
        let editBtn = rec.can_edit ? `<a href="module_record.php?module=${MODULE_ID}&record=${rec.id}" class="mm-icon-btn" title="Edit"><i class="fa-solid fa-pencil"></i></a>` : '';
        let deleteBtn = rec.can_delete ? `<button class="mm-icon-btn mm-icon-danger" onclick="deleteRecord(${rec.id})" title="Delete"><i class="fa-solid fa-trash"></i></button>` : '';
 
        html += `
            <td class="sticky-actions-td">
                <div style="display:flex;gap:4px;">
                    <a href="module_record.php?module=${MODULE_ID}&record=${rec.id}&view=1" class="mm-icon-btn" title="View"><i class="fa-solid fa-eye"></i></a>
                    ${editBtn}
                    <a href="record_history.php?module=${MODULE_ID}&record=${rec.id}" class="mm-icon-btn" title="History"><i class="fa-solid fa-clock-rotate-left"></i></a>
                    ${deleteBtn}
                </div>
            </td>
        `;
        html += `</tr>`;
    });
    
    tbody.innerHTML = html;
    
    updateBulkDeleteButton();
    applyColumnVisibility();
    const orderList = JSON.parse(localStorage.getItem(ORDER_KEY)) || [];
    applyColumnOrder(orderList);
}

function formatFieldValue(val, fieldType, fieldOptions = []) {
    if (val === null || val === undefined) return '-';
    
    if (fieldType === 'url' && val) {
        let cleanUrl = val.toString().trim();
        if (!cleanUrl) return '-';
        
        let href = cleanUrl;
        if (!/^https?:\/\//i.test(cleanUrl) && !/^\//.test(cleanUrl)) {
            href = 'https://' + cleanUrl;
        }
        let urlForSegment = cleanUrl;
        if (urlForSegment.endsWith('/')) {
            urlForSegment = urlForSegment.slice(0, -1);
        }
        let lastSegment = urlForSegment.substring(urlForSegment.lastIndexOf('/') + 1);
        if (!lastSegment) {
            lastSegment = cleanUrl;
        }
        let display = '.../' + lastSegment;
        return `<a href="${escapeHtml(href)}" target="_blank" rel="noopener noreferrer" style="color:var(--primary); font-weight:600; text-decoration:none; display: -webkit-inline-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; text-overflow: ellipsis; word-break: break-all;"><i class="fa-solid fa-link" style="font-size:11px; margin-right:4px; flex-shrink: 0; vertical-align: middle;"></i>${escapeHtml(display)}</a>`;
    }
    if (fieldType === 'checkbox') {
        return val ? '<i class="fa-solid fa-check" style="color:#10b981;"></i>' : '<i class="fa-solid fa-xmark" style="color:var(--text-muted);"></i>';
    }
    if (fieldType === 'attachment') {
        return val ? '<i class="fa-solid fa-paperclip" style="color:var(--primary);"></i> File' : '-';
    }
    if (fieldType === 'multi_picker') {
        try {
            const decoded = JSON.parse(val);
            return Array.isArray(decoded) ? escapeHtml(decoded.join(', ')) : escapeHtml(val);
        } catch(e) {
            return escapeHtml(val);
        }
    }
    if (fieldType === 'date' && val) {
        return escapeHtml(formatVyDate(val));
    }
    if (fieldType === 'datetime' && val) {
        return escapeHtml(formatVyDate(val) + ' ' + formatVyTime(val));
    }
    if (fieldType === 'time' && val) {
        return escapeHtml(formatVyTime(val));
    }
    if ((fieldType === 'assigned_to' || fieldType === 'user') && val) {
        return escapeHtml(COMPANY_USERS[val] || `User #${val}`);
    }
    if (fieldType === 'duration' && val !== '') {
        const seconds = parseInt(val, 10);
        if (isNaN(seconds)) return escapeHtml(val);
        if (seconds < 60) {
            return seconds + ' sec';
        } else if (seconds < 3600) {
            return Math.floor(seconds / 60) + ' min ' + (seconds % 60) + ' sec';
        } else {
            return Math.floor(seconds / 3600) + ' hr ' + Math.floor((seconds % 3600) / 60) + ' min';
        }
    }
    
    const display = val.toString().length > 50 ? val.toString().substring(0, 50) + '…' : val.toString();
    return escapeHtml(display || '-');
}

function formatCreatedDate(dateStr) {
    if (!dateStr) return '-';
    const d = new Date(dateStr);
    if (isNaN(d)) return dateStr;
    const day = String(d.getDate()).padStart(2, '0');
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const year = d.getFullYear();
    return `${day} ${months[d.getMonth()]} ${year}`;
}

function handleSearch(event) {
    if (event) event.preventDefault();
    fetchAndRenderRecords(activeFilterRules, activeFilterId);
}

function escapeHtml(str) {
    if (!str) return '';
    return str.toString()
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('columnSelectorDropdown');
    if (dropdown && !dropdown.contains(event.target)) {
        dropdown.style.display = 'none';
    }
    const expDropdown = document.getElementById('exportDropdown');
    if (expDropdown && !expDropdown.contains(event.target) && !event.target.closest('button[onclick="toggleExportDropdown(event)"]')) {
        expDropdown.style.display = 'none';
    }
});

function applyColumnVisibility() {
    let hiddenCols = [];
    try {
        hiddenCols = (JSON.parse(localStorage.getItem(STORAGE_KEY)) || []).map(String);
    } catch(e) {}
    
    document.querySelectorAll('.col-toggle-checkbox').forEach(cb => {
        const fieldId = cb.dataset.fieldId;
        const column = cb.dataset.column;
        let isVisible = true;
        
        if (fieldId) {
            isVisible = !hiddenCols.includes(String(fieldId));
        } else if (column) {
            isVisible = !hiddenCols.includes(String(column));
        }
        
        cb.checked = isVisible;
        
        let elements = [];
        if (fieldId) {
            elements = document.querySelectorAll(`th[data-field-id="${fieldId}"], td[data-field-id="${fieldId}"]`);
        } else if (column) {
            elements = document.querySelectorAll(`th[data-column="${column}"], td[data-column="${column}"]`);
        }
        
        elements.forEach(el => {
            el.style.display = isVisible ? '' : 'none';
        });
    });
}

function applyColumnOrder(orderList) {
    if (!orderList || orderList.length === 0) return;
    
    // Normalize order keys to strings
    const strOrderList = orderList.map(String);
    
    const rows = document.querySelectorAll('.crm-table tr');
    
    rows.forEach(row => {
        const cells = Array.from(row.children);
        if (cells.length <= 1) return; // Skip colspan rows or empty rows
        
        const headerCell = cells.find(c => c.tagName === 'TH' && c.classList.contains('sticky-actions-th'));
        const actionCell = cells.find(c => c.tagName === 'TD' && c.classList.contains('sticky-actions-td'));
        
        const checkboxCell = cells.find(c => c.classList.contains('checkbox-col'));
        const indexCell = cells.find(c => !c.dataset.fieldId && !c.dataset.column && !c.classList.contains('sticky-actions-th') && !c.classList.contains('sticky-actions-td') && !c.classList.contains('checkbox-col'));
        const sortableCells = cells.filter(c => c.dataset.fieldId || c.dataset.column);
        
        sortableCells.sort((a, b) => {
            const keyA = String(a.dataset.fieldId || a.dataset.column || '');
            const keyB = String(b.dataset.fieldId || b.dataset.column || '');
            let indexA = strOrderList.indexOf(keyA);
            let indexB = strOrderList.indexOf(keyB);
            
            if (indexA === -1) indexA = 999;
            if (indexB === -1) indexB = 999;
            
            return indexA - indexB;
        });
        
        // Re-append nodes in the sorted order (moves existing nodes safely)
        if (checkboxCell) row.appendChild(checkboxCell);
        if (indexCell) row.appendChild(indexCell);
        sortableCells.forEach(cell => {
            row.appendChild(cell);
        });
        const finalCell = headerCell || actionCell;
        if (finalCell) row.appendChild(finalCell);
    });
}

function saveColumnOrder() {
    const container = document.getElementById('columnListContainer');
    const items = Array.from(container.querySelectorAll('.col-item'));
    const orderList = items.map(item => String(item.dataset.key || ''));
    
    localStorage.setItem(ORDER_KEY, JSON.stringify(orderList));
    applyColumnOrder(orderList);
}

// Drag & Drop Handling
let dragSrcEl = null;

function handleDragStart(e) {
    this.style.opacity = '0.4';
    dragSrcEl = this;
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', this.dataset.key);
}

function handleDragOver(e) {
    if (e.preventDefault) {
        e.preventDefault();
    }
    e.dataTransfer.dropEffect = 'move';
    return false;
}

function handleDragEnter(e) {
    this.classList.add('drag-over');
}

function handleDragLeave(e) {
    this.classList.remove('drag-over');
}

function handleDrop(e) {
    e.stopPropagation();
    e.preventDefault();
    
    if (dragSrcEl !== this) {
        const container = document.getElementById('columnListContainer');
        const listItems = Array.from(container.querySelectorAll('.col-item'));
        const indexSrc = listItems.indexOf(dragSrcEl);
        const indexDst = listItems.indexOf(this);
        
        if (indexSrc < indexDst) {
            container.insertBefore(dragSrcEl, this.nextSibling);
        } else {
            container.insertBefore(dragSrcEl, this);
        }
        
        saveColumnOrder();
    }
    return false;
}

function handleDragEnd(e) {
    this.style.opacity = '1';
    document.querySelectorAll('.col-item').forEach(item => {
        item.classList.remove('drag-over');
    });
}

function setupDragAndDrop() {
    const items = document.querySelectorAll('.col-item');
    items.forEach(item => {
        item.addEventListener('dragstart', handleDragStart, false);
        item.addEventListener('dragenter', handleDragEnter, false);
        item.addEventListener('dragover', handleDragOver, false);
        item.addEventListener('dragleave', handleDragLeave, false);
        item.addEventListener('drop', handleDrop, false);
        item.addEventListener('dragend', handleDragEnd, false);
    });
}

function initColumnOrder() {
    let orderList = [];
    try {
        orderList = JSON.parse(localStorage.getItem(ORDER_KEY)) || [];
    } catch(e) {}
    
    if (orderList.length > 0) {
        const container = document.getElementById('columnListContainer');
        const itemsMap = {};
        container.querySelectorAll('.col-item').forEach(item => {
            itemsMap[item.dataset.key] = item;
        });
        
        orderList.forEach(key => {
            if (itemsMap[key]) {
                container.appendChild(itemsMap[key]);
                delete itemsMap[key];
            }
        });
        
        Object.values(itemsMap).forEach(item => {
            container.appendChild(item);
        });
        
        applyColumnOrder(orderList);
    }
}

document.querySelectorAll('.col-toggle-checkbox').forEach(cb => {
    cb.addEventListener('change', function() {
        const fieldId = this.dataset.fieldId;
        const column = this.dataset.column;
        const key = String(fieldId || column);
        
        let hiddenCols = [];
        try {
            hiddenCols = (JSON.parse(localStorage.getItem(STORAGE_KEY)) || []).map(String);
        } catch(e) {}
        
        if (this.checked) {
            hiddenCols = hiddenCols.filter(c => c !== key);
        } else {
            if (!hiddenCols.includes(key)) {
                hiddenCols.push(key);
            }
        }
        
        localStorage.setItem(STORAGE_KEY, JSON.stringify(hiddenCols));
        applyColumnVisibility();
    });
});

function setupHeaderSortListeners() {
    document.querySelectorAll('th[data-field-id], th[data-column]').forEach(th => {
        th.style.cursor = 'pointer';
        
        let icon = th.querySelector('.sort-icon');
        if (!icon) {
            icon = document.createElement('i');
            icon.className = 'fa-solid fa-sort sort-icon';
            icon.style.marginLeft = '8px';
            icon.style.opacity = '0.4';
            th.appendChild(icon);
        }
        
        th.addEventListener('click', () => {
            const fieldId = th.dataset.fieldId;
            const column = th.dataset.column;
            const key = fieldId || column;
            handleHeaderSort(key);
        });
    });
}

function handleHeaderSort(key) {
    if (currentSortBy == key) {
        if (currentSortOrder === 'ASC') {
            currentSortOrder = 'DESC';
        } else {
            // Already DESC
            if (key == DEFAULT_SORT_BY) {
                currentSortOrder = 'ASC';
            } else {
                currentSortBy = DEFAULT_SORT_BY;
                currentSortOrder = DEFAULT_SORT_ORDER;
            }
        }
    } else {
        currentSortBy = key;
        currentSortOrder = 'ASC';
    }
    fetchAndRenderRecords(activeFilterRules, activeFilterId, 1);
}

function updateHeaderSortIcons() {
    document.querySelectorAll('th[data-field-id], th[data-column]').forEach(th => {
        const fieldId = th.dataset.fieldId;
        const column = th.dataset.column;
        const key = fieldId || column;
        
        const isActive = (key == currentSortBy);
        th.classList.remove('active-sort');
        
        let icon = th.querySelector('.sort-icon');
        if (!icon) {
            icon = document.createElement('i');
            icon.className = 'sort-icon';
            icon.style.marginLeft = '8px';
            th.appendChild(icon);
        }
        
        if (isActive) {
            th.classList.add('active-sort');
            icon.className = currentSortOrder === 'ASC' 
                ? 'fa-solid fa-arrow-up-wide-short sort-icon' 
                : 'fa-solid fa-arrow-down-short-wide sort-icon';
            icon.style.opacity = '1';
        } else {
            icon.className = 'fa-solid fa-sort sort-icon';
            icon.style.opacity = '0.4';
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initColumnOrder();
    applyColumnVisibility();
    setupDragAndDrop();
    setupHeaderSortListeners();
    updateHeaderSortIcons();
    
    // Call initial fetch to set up pagination stats, pagination buttons, sorting headers, etc.
    fetchAndRenderRecords(activeFilterRules, activeFilterId, 1);

    loadSavedFiltersList().then(() => {
        const container = document.getElementById('filterRulesContainer');
        if (container && container.children.length === 0) {
            if (activeFilterRules && activeFilterRules.length > 0) {
                activeFilterRules.forEach(rule => createFilterRuleRow(rule));
            } else {
                createFilterRuleRow();
            }
        }
    });
});

function toggleExportDropdown(event) {
    event.stopPropagation();
    const dropdown = document.getElementById('exportDropdown');
    dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
}

function triggerExport(format) {
    // Close dropdown
    const dropdown = document.getElementById('exportDropdown');
    if (dropdown) dropdown.style.display = 'none';
    
    // Get search value
    const searchInput = document.getElementById('recordSearchInput');
    const searchVal = searchInput ? searchInput.value.trim() : '';
    
    // Prepare URL parameters
    const params = new URLSearchParams({
        module_id: MODULE_ID,
        format: format
    });
    
    if (searchVal) {
        params.append('search', searchVal);
    }
    if (activeFilterRules && activeFilterRules.length > 0) {
        params.append('filter_rules', JSON.stringify(activeFilterRules));
    } else if (activeFilterId) {
        params.append('filter_id', activeFilterId);
    }
    if (currentSortBy) {
        params.append('sort_by', currentSortBy);
        params.append('sort_order', currentSortOrder);
    }
    
    // Redirect to trigger browser download
    window.location.href = `/api/module_export.php?${params.toString()}`;
}

function openImportModal() {
    const modal = document.getElementById('importModal');
    if (modal) modal.style.display = 'flex';
}

function closeImportModal() {
    const modal = document.getElementById('importModal');
    if (modal) modal.style.display = 'none';
    const form = document.getElementById('importForm');
    if (form) form.reset();
}

function downloadTemplate() {
    const params = new URLSearchParams({
        module_id: MODULE_ID,
        template: '1'
    });
    window.location.href = `/api/module_export.php?${params.toString()}`;
}

async function handleImportSubmit(event) {
    event.preventDefault();
    const fileInput = document.getElementById('importFile');
    if (!fileInput || !fileInput.files.length) return;
    
    const submitBtn = event.target.querySelector('button[type="submit"]');
    const importProgressModal = document.getElementById('importProgressModal');
    
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" style="margin-right:6px;"></i> Importing...';
    }
    if (importProgressModal) {
        importProgressModal.style.display = 'flex';
    }
    
    const formData = new FormData();
    formData.append('import_file', fileInput.files[0]);
    formData.append('module_id', MODULE_ID);
    
    try {
        const response = await fetch('/api/module_import.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (importProgressModal) importProgressModal.style.display = 'none';
        
        if (result.success) {
            vyToast(result.message || 'Import successful!', 'success');
            closeImportModal();
            // Refresh table
            fetchAndRenderRecords(activeFilterRules, activeFilterId, 1);
        } else {
            vyToast(result.error || 'Import failed.', 'error');
        }
    } catch (e) {
        if (importProgressModal) importProgressModal.style.display = 'none';
        vyToast('Import request failed: ' + e.message, 'error');
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = 'Upload and Import';
        }
    }
}
</script>
<!-- Import Modal -->
<div class="mm-modal-overlay" id="importModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div class="crm-card" style="width:100%; max-width:500px; padding:24px; border-radius:16px; background:var(--surface); box-shadow:var(--shadow-lg);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom:1px solid var(--border); padding-bottom:12px;">
            <h3 style="margin:0; font-size:16px; font-weight:700; color:var(--text-main); display:flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-file-import" style="color:var(--primary);"></i> Import Records
            </h3>
            <button class="mm-icon-btn" onclick="closeImportModal()" style="background:none; border:none; cursor:pointer; font-size:16px; color:var(--text);"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div style="margin-bottom:20px;">
            <p style="font-size:13px; color:var(--text-muted); margin:0 0 12px; line-height:1.5;">
                Upload a CSV or Excel (.xls, .xlsx) file containing records for this module. The first row must contain column headers that match the module fields' names.
            </p>
            <div style="display:flex; gap:10px; margin-bottom:16px;">
                <button class="mm-btn mm-btn-sm mm-btn-outline" onclick="downloadTemplate()" style="display:inline-flex; align-items:center; gap:6px; font-size:12px; padding:6px 12px;">
                    <i class="fa-solid fa-download"></i> Download CSV Template
                </button>
            </div>
            <form id="importForm" onsubmit="handleImportSubmit(event)">
                <div class="form-group" style="display:flex; flex-direction:column; gap:6px;">
                    <label class="form-label" style="font-size:13px; font-weight:600; color:var(--text-main);">Choose CSV or Excel File *</label>
                    <input type="file" id="importFile" accept=".csv,.xls,.xlsx" required class="form-control" style="width:100%; padding:8px 12px; border-radius:8px; border:1.5px solid var(--border); font-size:13px;">
                </div>
                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:24px; border-top:1px solid var(--border); padding-top:16px;">
                    <button type="button" class="mm-btn mm-btn-sm mm-btn-outline" onclick="closeImportModal()">Cancel</button>
                    <button type="submit" class="mm-btn mm-btn-sm" style="background:var(--primary); color:#fff; border:none; border-radius:8px; padding:8px 16px; font-size:13px; font-weight:600; cursor:pointer;">Upload and Import</button>
                </div>
            </form>
        </div>
    </div>
</div>
<!-- Import Progress Modal Loader -->
<div class="mm-modal-overlay" id="importProgressModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.65); z-index:10000; align-items:center; justify-content:center; backdrop-filter: blur(4px);">
    <div class="crm-card" style="width:100%; max-width:400px; padding:30px; border-radius:20px; background:var(--surface); box-shadow:var(--shadow-lg); text-align:center;">
        <div style="margin-bottom: 20px;">
            <i class="fa-solid fa-circle-notch fa-spin" style="font-size:48px; color:var(--primary);"></i>
        </div>
        <h3 style="margin:0 0 8px; font-size:18px; font-weight:700; color:var(--text-main);">Importing Records</h3>
        <p style="margin:0; font-size:14px; color:var(--text-muted); line-height:1.5;">Please wait, parsing and importing records from file...</p>
    </div>
</div>
<!-- Bulk Delete Progress Modal -->
<div class="mm-modal-overlay" id="deleteProgressModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.65); z-index:10000; align-items:center; justify-content:center; backdrop-filter: blur(4px);">
    <div class="crm-card" style="width:100%; max-width:400px; padding:30px; border-radius:20px; background:var(--surface); box-shadow:var(--shadow-lg); text-align:center;">
        <div style="margin-bottom: 20px;">
            <i class="fa-solid fa-circle-notch fa-spin" style="font-size:48px; color:var(--primary);"></i>
        </div>
        <h3 style="margin:0 0 8px; font-size:18px; font-weight:700; color:var(--text-main);">Deleting Records</h3>
        <p style="margin:0; font-size:14px; color:var(--text-muted); line-height:1.5;">Please wait, deleting the selected records...</p>
    </div>
</div>
<!-- Bulk Delete Result Modal -->
<div class="mm-modal-overlay" id="deleteResultModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.65); z-index:10000; align-items:center; justify-content:center; backdrop-filter: blur(4px);">
    <div class="crm-card" style="width:100%; max-width:420px; padding:30px; border-radius:20px; background:var(--surface); box-shadow:var(--shadow-lg); text-align:center;">
        <div style="margin-bottom: 20px;">
            <i class="fa-solid fa-circle-check" style="font-size:54px; color:#10b981;"></i>
        </div>
        <h3 style="margin:0 0 10px; font-size:20px; font-weight:700; color:var(--text-main);">Delete Complete</h3>
        <p style="margin:0 0 24px; font-size:14px; color:var(--text-muted); line-height:1.5;" id="deleteResultText">Successfully deleted the selected records.</p>
        <button class="mm-btn" style="background:var(--primary); color:#fff; border:none; border-radius:10px; padding:10px 24px; font-size:14px; font-weight:600; cursor:pointer; width:100%;" onclick="closeDeleteResultModal()">Dismiss</button>
    </div>
    </div>
</div>

<?php if (!empty($quickCreateFields)): ?>
<!-- Quick Create Modal -->
<div class="mm-modal-overlay" id="quickCreateModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9998; align-items:center; justify-content:center;">
    <div class="crm-card" style="width:100%; max-width:700px; padding:24px; border-radius:16px; background:var(--surface); box-shadow:var(--shadow-lg); display:flex; flex-direction:column; max-height:90vh;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom:1px solid var(--border); padding-bottom:12px; flex-shrink:0;">
            <h3 style="margin:0; font-size:16px; font-weight:700; color:var(--text-main); display:flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-bolt" style="color:var(--primary);"></i> Quick Create - <?= htmlspecialchars($module['name']) ?>
            </h3>
            <button class="mm-icon-btn" onclick="closeQuickCreateModal()" style="background:none; border:none; cursor:pointer; font-size:16px; color:var(--text);"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div style="flex:1; overflow-y:auto; padding-right:4px;">
            <form id="quickCreateForm" onsubmit="handleQuickCreateSubmit(event)">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; padding-bottom:10px;">
                    <?php 
                    $defaultCountry = 'IN';
                    foreach ($quickCreateFields as $field): 
                        $fid = $field['id'];
                        $val = $field['default_value'] ?? '';
                        $fullWidth = in_array($field['field_type'], ['textarea', 'attachment', 'name', 'address']);
                        $req = $field['is_required'] ? '<span class="required-star" style="color:#ef4444;">*</span>' : '';
                    ?>
                        <div style="display:flex; flex-direction:column; gap:6px; <?= $fullWidth ? 'grid-column: span 2;' : '' ?>" class="qc-field-group" data-field-id="<?= $fid ?>" data-field-type="<?= $field['field_type'] ?>" data-field-label="<?= htmlspecialchars($field['label']) ?>" <?= $field['is_required'] ? 'data-required="1"' : '' ?>>
                            <label style="font-size:13px; font-weight:600; color:var(--text-main); text-align: left; align-self: flex-start;"><?= htmlspecialchars($field['label']) ?> <?= $req ?></label>
                            
                            <?php switch($field['field_type']):
                                case 'text': case 'email': case 'url': case 'number': case 'currency': ?>
                                    <input type="<?= $field['field_type'] === 'email' ? 'email' : ($field['field_type'] === 'url' ? 'url' : ($field['field_type'] === 'number' || $field['field_type'] === 'currency' ? 'number' : 'text')) ?>"
                                           class="form-control qc-input" data-field-id="<?= $fid ?>"
                                           placeholder="<?= htmlspecialchars($field['placeholder'] ?? '') ?>"
                                           value="<?= htmlspecialchars($val) ?>"
                                           <?= $field['field_type'] === 'currency' ? 'step="0.01"' : '' ?>
                                           style="width:100%; padding:10px 14px; border-radius:10px; border:1.5px solid var(--border); font-size:14px; box-sizing:border-box;">
                                <?php break; case 'phone': 
                                    $dialCodes = [
                                        'IN' => '+91', 'US' => '+1', 'GB' => '+44', 'AE' => '+971', 'SA' => '+966',
                                        'AU' => '+61', 'CA' => '+1', 'SG' => '+65', 'MY' => '+60', 'DE' => '+49',
                                        'FR' => '+33', 'JP' => '+81', 'CN' => '+86', 'KR' => '+82', 'BR' => '+55',
                                        'ZA' => '+27', 'NZ' => '+64', 'QA' => '+974', 'KW' => '+965', 'BH' => '+973',
                                        'OM' => '+968', 'NP' => '+977', 'LK' => '+94', 'BD' => '+880'
                                    ];
                                    ?>
                                    <div style="display: flex; gap: 8px; width: 100%;">
                                        <select class="form-control qc-phone-prefix" data-field-id="<?= $fid ?>" style="width: 110px; flex-shrink: 0; padding: 10px 12px; border-radius: 10px; border: 1.5px solid var(--border); font-size: 14px; background: #fff; box-sizing: border-box;">
                                            <?php foreach ($dialCodes as $cCode => $prefixCode): ?>
                                                <option value="<?= htmlspecialchars($prefixCode) ?>" <?= $defaultCountry === $cCode ? 'selected' : '' ?>><?= $cCode ?> (<?= $prefixCode ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="text" class="form-control qc-phone-number" data-field-id="<?= $fid ?>"
                                               placeholder="<?= htmlspecialchars($field['placeholder'] ?? '') ?>"
                                               value="<?= htmlspecialchars($val) ?>"
                                               style="flex-grow: 1; padding:10px 14px; border-radius:10px; border:1.5px solid var(--border); font-size:14px; box-sizing:border-box;">
                                    </div>
                                <?php break; case 'textarea': ?>
                                    <textarea class="form-control qc-input" data-field-id="<?= $fid ?>" rows="3"
                                              placeholder="<?= htmlspecialchars($field['placeholder'] ?? '') ?>"
                                              style="width:100%; padding:10px 14px; border-radius:10px; border:1.5px solid var(--border); font-size:14px; box-sizing:border-box; font-family:inherit;"><?= htmlspecialchars($val) ?></textarea>
                                <?php break; case 'checkbox': ?>
                                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:6px;user-select:none;">
                                        <input type="checkbox" class="qc-checkbox" data-field-id="<?= $fid ?>" <?= $val ? 'checked' : '' ?> style="accent-color:var(--primary);width:18px;height:18px;">
                                        <span style="font-size:14px;"><?= htmlspecialchars($field['placeholder'] ?: 'Yes') ?></span>
                                    </label>
                                <?php break; case 'dropdown': ?>
                                    <select class="qc-tom-select qc-dropdown" data-field-id="<?= $fid ?>" placeholder="Select or type to add...">
                                        <option value="">Select...</option>
                                        <?php foreach($field['options'] as $opt): ?>
                                        <option value="<?= htmlspecialchars($opt['value']) ?>" <?= $val === $opt['value'] ? 'selected' : '' ?>><?= htmlspecialchars($opt['label']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php break; case 'radio_group': ?>
                                    <div class="dm-radio-group" style="display:flex; gap:16px; flex-wrap:wrap; padding:8px 0;">
                                        <?php foreach($field['options'] as $idx => $opt): ?>
                                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin:0;">
                                            <input type="radio" name="qc_radio_<?= $fid ?>" class="qc-radio" data-field-id="<?= $fid ?>" value="<?= htmlspecialchars($opt['value']) ?>" <?= $val === $opt['value'] ? 'checked' : '' ?> style="accent-color:var(--primary);width:16px;height:16px;">
                                            <span style="font-size:14px;"><?= htmlspecialchars($opt['label']) ?></span>
                                        </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php break; case 'multi_picker': ?>
                                    <select multiple class="qc-multi-picker qc-tom-select" data-field-id="<?= $fid ?>" placeholder="Search and select...">
                                        <?php foreach($field['options'] as $opt): ?>
                                        <option value="<?= htmlspecialchars($opt['value']) ?>"><?= htmlspecialchars($opt['label']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php break; case 'date': ?>
                                    <input type="text" class="form-control qc-date-picker" data-field-id="<?= $fid ?>" value="<?= htmlspecialchars($val) ?>" placeholder="Select Date" style="width:100%; padding:10px 14px; border-radius:10px; border:1.5px solid var(--border); font-size:14px; background:#fff; box-sizing:border-box;">
                                <?php break; case 'datetime': ?>
                                    <input type="text" class="form-control qc-datetime-picker" data-field-id="<?= $fid ?>" value="<?= htmlspecialchars($val) ?>" placeholder="Select Date & Time" style="width:100%; padding:10px 14px; border-radius:10px; border:1.5px solid var(--border); font-size:14px; background:#fff; box-sizing:border-box;">
                                <?php break; case 'time': ?>
                                    <input type="text" class="form-control qc-time-picker" data-field-id="<?= $fid ?>" value="<?= htmlspecialchars($val) ?>" placeholder="Select Time" style="width:100%; padding:10px 14px; border-radius:10px; border:1.5px solid var(--border); font-size:14px; background:#fff; box-sizing:border-box;">
                                <?php break; case 'duration': ?>
                                    <div style="display:flex;gap:10px;align-items:center;">
                                        <input type="number" class="form-control qc-input" data-field-id="<?= $fid ?>" value="<?= htmlspecialchars($val) ?>" min="0" placeholder="e.g. 100" style="flex:1; padding:10px 14px; border-radius:10px; border:1.5px solid var(--border); font-size:14px; box-sizing:border-box;">
                                        <span class="text-muted" style="font-size:14px;">seconds</span>
                                    </div>
                                <?php break; case 'name': ?>
                                    <div style="display:flex;gap:12px;">
                                        <input type="text" class="form-control qc-name-field" data-field-id="<?= $fid ?>" data-part="first" placeholder="First Name" style="flex:1; padding:10px 14px; border-radius:10px; border:1.5px solid var(--border); font-size:14px; box-sizing:border-box;">
                                        <input type="text" class="form-control qc-name-field" data-field-id="<?= $fid ?>" data-part="last" placeholder="Last Name" style="flex:1; padding:10px 14px; border-radius:10px; border:1.5px solid var(--border); font-size:14px; box-sizing:border-box;">
                                    </div>
                                <?php break; case 'assigned_to': ?>
                                    <select class="form-control qc-select" data-field-id="<?= $fid ?>" style="width:100%; padding:10px 14px; border-radius:10px; border:1.5px solid var(--border); font-size:14px; background:#fff; box-sizing:border-box; height:44px;">
                                        <option value="">Select User...</option>
                                        <?php foreach($usersList as $u): ?>
                                        <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php break; case 'api_call_picker': 
                                    $cfg = $field['config'] ?? []; $linkedModId = $cfg['linked_module_id'] ?? 0;
                                    ?>
                                    <div class="qc-api-picker-wrapper" data-field-id="<?= $fid ?>" data-linked-module="<?= $linkedModId ?>" style="position: relative;">
                                        <input type="hidden" class="qc-api-hidden" data-field-id="<?= $fid ?>" value="">
                                        <div style="display:flex; align-items:center; gap:8px;">
                                            <div class="form-control" style="flex:1; cursor:pointer; background:var(--surface); display:flex; align-items:center; justify-content:space-between; padding: 10px 14px; border-radius: 10px; border: 1.5px solid var(--border); font-size: 14px;" onclick="openRecordPickerModal(<?= $fid ?>, <?= $linkedModId ?>)">
                                                <span id="qc-api-display-<?= $fid ?>" style="color: var(--text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                                    Search & Select...
                                                </span>
                                                <i class="fa-solid fa-chevron-down" style="color:var(--text-muted); font-size:12px; flex-shrink:0;"></i>
                                            </div>
                                            <button type="button" class="mm-icon-btn rp-clear-btn" id="qc-clear-btn-<?= $fid ?>" onclick="clearQcApiPicker(<?= $fid ?>)" style="color:#ef4444; flex-shrink:0; display:none;" title="Clear Selection"><i class="fa-solid fa-xmark"></i></button>
                                        </div>
                                    </div>
                                <?php break; case 'address': ?>
                                    <textarea class="form-control qc-input" data-field-id="<?= $fid ?>" rows="2" placeholder="<?= htmlspecialchars($field['placeholder'] ?? 'Enter address') ?>" style="width:100%; padding:10px 14px; border-radius:10px; border:1.5px solid var(--border); font-size:14px; box-sizing:border-box; font-family:inherit;"></textarea>
                                <?php break; default: ?>
                                    <input type="text" class="form-control qc-input" data-field-id="<?= $fid ?>" value="<?= htmlspecialchars($val) ?>" style="width:100%; padding:10px 14px; border-radius:10px; border:1.5px solid var(--border); font-size:14px; box-sizing:border-box;">
                            <?php endswitch; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:24px; border-top:1px solid var(--border); padding-top:16px; flex-shrink:0;">
                    <button type="button" class="mm-btn mm-btn-outline" onclick="closeQuickCreateModal()">Cancel</button>
                    <button type="submit" class="mm-btn" style="background:var(--primary); color:#fff; border:none; border-radius:8px; padding:10px 20px; font-size:13px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:8px;">
                        <i class="fa-solid fa-check"></i> Save Record
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Quick Create Record Picker Modal -->
<div class="mm-modal-overlay" id="recordPickerModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:10000; align-items:center; justify-content:center;">
    <div class="crm-card" style="width:100%; max-width:600px; padding:24px; border-radius:16px; background:var(--surface); box-shadow:var(--shadow-lg); display:flex; flex-direction:column; min-height:450px;">
        <div class="mm-modal-header" style="display:flex; justify-content:space-between; align-items:center; width:100%; border-bottom:1px solid var(--border); padding-bottom:12px; margin-bottom:16px;">
            <div style="display:flex; align-items:center; gap:8px;">
                <h3 id="recordPickerModalTitle" style="margin:0; font-size:16px; font-weight:700;">Select Record</h3>
                <button type="button" class="mm-btn mm-btn-primary mm-btn-sm" id="rpCreateBtn" onclick="createNewRecordFromPicker()" style="font-size:12px; height:28px; padding:0 12px; display:inline-flex; align-items:center; gap:6px;">
                    <i class="fa-solid fa-plus"></i> Create New
                </button>
                <button type="button" class="mm-btn mm-btn-outline mm-btn-sm" id="rpQuickCreateBtn" onclick="togglePickerQuickCreate(true)" style="font-size:12px; height:28px; padding:0 12px; display:none; align-items:center; gap:6px;">
                    <i class="fa-solid fa-bolt" style="color:var(--primary);"></i> Quick Create
                </button>
            </div>
            <button class="mm-icon-btn" onclick="closeModal('recordPickerModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="mm-modal-body" style="padding: 0; flex:1; display:flex; flex-direction:column;">
            <div class="rp-search-wrapper" style="position:relative; margin-bottom:16px;">
                <i class="fa-solid fa-magnifying-glass" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:14px;"></i>
                <input type="text" id="recordPickerSearch" class="rp-search-input" placeholder="Search item..." oninput="debouncedSearchRecordPicker()" style="width:100%; padding:10px 12px 10px 36px; border-radius:8px; border:1.5px solid var(--border); font-size:14px; box-sizing:border-box; outline:none;">
            </div>
            
            <div id="recordPickerContent" style="flex:1; overflow-y:auto; min-height:220px; border:1px solid var(--border); border-radius:8px; padding:8px; background:var(--surface);">
                <!-- Content via AJAX -->
            </div>
            
            <div class="rp-pagination" id="recordPickerPagination" style="display:flex; justify-content:space-between; align-items:center; margin-top:16px; padding-top:12px; border-top:1px solid var(--border);">
                <button class="mm-btn" id="rpPrevBtn" onclick="changeRecordPickerPage(-1)" style="padding:6px 12px; font-size:12px;">Previous</button>
                <span id="rpPageInfo" style="font-size:13px; font-weight:600; color:var(--text-muted);">Page 1</span>
                <button class="mm-btn" id="rpNextBtn" onclick="changeRecordPickerPage(1)" style="padding:6px 12px; font-size:12px;">Next</button>
            </div>

            <!-- Dynamic Quick Create Form inside picker -->
            <div id="rpQuickCreateFormContainer" style="display:none; flex:1; flex-direction:column; gap:16px;">
                <form id="rpQuickCreateForm" onsubmit="handlePickerQuickCreateSubmit(event)">
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; max-height: 300px; overflow-y: auto; padding: 4px;" id="rpQuickCreateFieldsGrid">
                        <!-- Dynamic inputs loaded via AJAX -->
                    </div>
                    <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px; border-top:1px solid var(--border); padding-top:12px;">
                        <button type="button" class="mm-btn mm-btn-sm mm-btn-outline" onclick="togglePickerQuickCreate(false)">Back to Search</button>
                        <button type="submit" class="mm-btn mm-btn-sm" style="background:var(--primary); color:#fff; border:none; border-radius:8px; padding:8px 16px; font-size:13px; font-weight:600; cursor:pointer;">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<!-- Bulk Edit Modal -->
<div class="mm-modal-overlay" id="bulkEditModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:99999; align-items:center; justify-content:center;">
    <div class="crm-card" style="width:520px; max-width:92%; background:var(--surface); border-radius:16px; padding:24px; box-shadow:var(--shadow-xl); border:1px solid var(--border);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
            <h3 style="margin:0; font-size:18px; font-weight:700; color:var(--text-main);"><i class="fa-solid fa-pen-to-square" style="color:var(--primary); margin-right:8px;"></i> Bulk Edit Records</h3>
            <button class="mm-icon-btn" onclick="closeBulkEditModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <p style="color:var(--text-muted); font-size:13px; margin-bottom:15px;">
            Update field values for <strong id="bulkEditRecordCount" style="color:var(--primary);">0</strong> selected record(s).
        </p>

        <div style="display:flex; flex-direction:column; gap:14px;">
            <!-- Field Selection -->
            <div>
                <label class="form-label" style="font-weight:600; font-size:13px; color:var(--text); margin-bottom:6px; display:block;">Select Field to Edit *</label>
                <select id="bulkEditFieldSelect" class="form-control" onchange="onBulkEditFieldSelectChange(this)" style="width:100%; box-sizing:border-box;">
                    <option value="">-- Choose Field --</option>
                </select>
            </div>

            <!-- Action / Operation Selection -->
            <div>
                <label class="form-label" style="font-weight:600; font-size:13px; color:var(--text); margin-bottom:6px; display:block;">Action / Operation *</label>
                <select id="bulkEditOperationSelect" class="form-control" onchange="onBulkEditOperationChange(this)" style="width:100%; box-sizing:border-box;">
                    <option value="set_value">Set New Value (Replace)</option>
                    <option value="clear_value">Clear Field / Remove Value (Set Empty)</option>
                    <option value="remove_specific_text">Remove Specific Value / Text from Records</option>
                </select>
            </div>

            <!-- Dynamic Input Container -->
            <div id="bulkEditValueWrapper">
                <label class="form-label" style="font-weight:600; font-size:13px; color:var(--text); margin-bottom:6px; display:block;" id="bulkEditValueLabel">New Value</label>
                <div id="bulkEditValueInputContainer">
                    <input type="text" id="bulkEditValueInput" class="form-control" placeholder="Select a field first..." disabled style="width:100%; box-sizing:border-box;">
                </div>
            </div>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:24px; padding-top:16px; border-top:1px solid var(--border);">
            <button class="mm-btn mm-btn-outline" onclick="closeBulkEditModal()">Cancel</button>
            <button id="btnSubmitBulkEdit" class="mm-btn mm-btn-primary" onclick="submitBulkEdit()" style="display:inline-flex; align-items:center; gap:6px;">
                <i class="fa-solid fa-check"></i> Apply Bulk Edit
            </button>
        </div>
    </div>
</div>

<script>
let bulkEditSelectedField = null;

function openBulkEditModal() {
    const selectedCheckboxes = document.querySelectorAll('.record-select:checked');
    const selectedCount = selectedCheckboxes.length;
    const totalRecords = typeof window.totalModuleRecords !== 'undefined' ? window.totalModuleRecords : <?= (int)$total ?>;
    const effectiveCount = isAllPagesSelected ? totalRecords : selectedCount;

    if (effectiveCount === 0) {
        vyToast('Please select at least one record to edit.', 'warning');
        return;
    }

    document.getElementById('bulkEditRecordCount').textContent = effectiveCount.toLocaleString();

    const selectEl = document.getElementById('bulkEditFieldSelect');
    selectEl.innerHTML = '<option value="">-- Choose Field --</option>';
    
    if (typeof ALL_MODULE_FIELDS !== 'undefined') {
        ALL_MODULE_FIELDS.forEach(f => {
            if (!['sys_created_by', 'sys_created_at', 'sys_updated_by', 'sys_updated_at', 'created_by', 'created_at', 'updated_by', 'updated_at'].includes(f.field_type) && !['created_by', 'created_at', 'updated_by', 'updated_at'].includes(f.id)) {
                const opt = document.createElement('option');
                opt.value = f.id;
                opt.textContent = f.label;
                selectEl.appendChild(opt);
            }
        });
    }

    document.getElementById('bulkEditOperationSelect').value = 'set_value';
    document.getElementById('bulkEditValueWrapper').style.display = 'block';
    document.getElementById('bulkEditValueLabel').textContent = 'New Value';
    document.getElementById('bulkEditValueInputContainer').innerHTML = '<input type="text" id="bulkEditValueInput" class="form-control" placeholder="Select a field first..." disabled style="width:100%; box-sizing:border-box;">';

    document.getElementById('bulkEditModal').style.display = 'flex';
}

function closeBulkEditModal() {
    document.getElementById('bulkEditModal').style.display = 'none';
}

async function onBulkEditFieldSelectChange(selectEl) {
    const fieldId = selectEl.value;
    const inputContainer = document.getElementById('bulkEditValueInputContainer');
    
    if (!fieldId) {
        inputContainer.innerHTML = '<input type="text" id="bulkEditValueInput" class="form-control" placeholder="Select a field first..." disabled style="width:100%; box-sizing:border-box;">';
        bulkEditSelectedField = null;
        return;
    }

    const field = ALL_MODULE_FIELDS.find(f => f.id == fieldId);
    bulkEditSelectedField = field;
    
    if (!field) return;

    const fType = field.field_type;
    const labelLower = (field.label || '').toLowerCase().trim();

    const isUserField = fType === 'user' || fType === 'assigned_to' || ['assignee', 'assigned to'].includes(labelLower);
    const isDateField = fType === 'date';
    const isDatetimeField = fType === 'datetime' || fType === 'datetime-local';
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
            return `<option value="${escapeHtml(optVal)}">${escapeHtml(optLbl)}</option>`;
        }).join('');

        inputContainer.innerHTML = `
            <select id="bulkEditValueInput" class="form-control" style="width:100%; box-sizing:border-box;">
                <option value="">-- Choose Option --</option>
                ${optHtml}
            </select>
        `;
    } else if (fType === 'checkbox') {
        inputContainer.innerHTML = `
            <select id="bulkEditValueInput" class="form-control" style="width:100%; box-sizing:border-box;">
                <option value="1">Yes (Checked)</option>
                <option value="0">No (Unchecked)</option>
            </select>
        `;
    } else if (isUserField) {
        const userOpts = (typeof COMPANY_USERS !== 'undefined' ? COMPANY_USERS : []).map(u => `<option value="${u.id}">${escapeHtml(u.name || u.username)}</option>`).join('');
        inputContainer.innerHTML = `
            <select id="bulkEditValueInput" class="form-control" style="width:100%; box-sizing:border-box;">
                <option value="">-- Choose User --</option>
                ${userOpts}
            </select>
        `;
    } else if (isDateField) {
        inputContainer.innerHTML = `<input type="date" id="bulkEditValueInput" class="form-control dm-date-picker" placeholder="YYYY-MM-DD" style="width:100%; box-sizing:border-box;">`;
        if (window.flatpickr) {
            flatpickr(inputContainer.querySelector('.dm-date-picker'), { dateFormat: 'Y-m-d', altInput: true, altFormat: 'd M, Y' });
        }
    } else if (isDatetimeField) {
        inputContainer.innerHTML = `<input type="datetime-local" id="bulkEditValueInput" class="form-control dm-datetime-picker" style="width:100%; box-sizing:border-box;">`;
        if (window.flatpickr) {
            flatpickr(inputContainer.querySelector('.dm-datetime-picker'), { enableTime: true, dateFormat: 'Y-m-d H:i:S', altInput: true, altFormat: 'd M, Y h:i K' });
        }
    } else if (isTimeField) {
        inputContainer.innerHTML = `<input type="time" id="bulkEditValueInput" class="form-control" style="width:100%; box-sizing:border-box;">`;
    } else if (fType === 'number' || fType === 'decimal' || fType === 'currency') {
        inputContainer.innerHTML = `<input type="number" step="any" id="bulkEditValueInput" class="form-control" placeholder="Enter number..." style="width:100%; box-sizing:border-box;">`;
    } else if (fType === 'api_call_picker') {
        const linkedModId = field.config ? field.config.linked_module_id : 0;
        if (linkedModId) {
            try {
                const res = await fetch(`/api/modules.php?action=lookup_records&target_module_id=${linkedModId}`);
                const rData = await res.json();
                if (rData.success && rData.records) {
                    const recOpts = rData.records.map(rec => `<option value="${rec.id}">${escapeHtml(rec.display_value)} (ID: ${rec.id})</option>`).join('');
                    inputContainer.innerHTML = `
                        <select id="bulkEditValueInput" class="form-control" style="width:100%; box-sizing:border-box;">
                            <option value="">-- Select Linked Record --</option>
                            ${recOpts}
                        </select>
                    `;
                    return;
                }
            } catch(e) {}
        }
        inputContainer.innerHTML = `<input type="text" id="bulkEditValueInput" class="form-control" placeholder="Enter value..." style="width:100%; box-sizing:border-box;">`;
    } else {
        inputContainer.innerHTML = `<input type="text" id="bulkEditValueInput" class="form-control" placeholder="Enter value..." style="width:100%; box-sizing:border-box;">`;
    }
}

function onBulkEditOperationChange(selectEl) {
    const op = selectEl.value;
    const wrapper = document.getElementById('bulkEditValueWrapper');
    const label = document.getElementById('bulkEditValueLabel');
    
    if (op === 'clear_value') {
        wrapper.style.display = 'none';
    } else {
        wrapper.style.display = 'block';
        if (op === 'remove_specific_text') {
            label.textContent = 'Value / Text to Remove *';
        } else {
            label.textContent = 'New Value *';
        }
    }
}

async function submitBulkEdit() {
    const fieldId = document.getElementById('bulkEditFieldSelect').value;
    const operation = document.getElementById('bulkEditOperationSelect').value;
    const valInput = document.getElementById('bulkEditValueInput');
    const val = valInput ? valInput.value : '';

    if (!fieldId) {
        vyToast('Please select a field to edit.', 'warning');
        return;
    }

    if (operation !== 'clear_value' && val === '' && operation === 'remove_specific_text') {
        vyToast('Please enter the text/value to remove.', 'warning');
        return;
    }

    const selectedCheckboxes = document.querySelectorAll('.record-select:checked');
    const recordIds = Array.from(selectedCheckboxes).map(cb => cb.value);

    const btnSubmit = document.getElementById('btnSubmitBulkEdit');
    btnSubmit.disabled = true;
    btnSubmit.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Updating...';

    try {
        const res = await fetch('/api/modules.php?action=bulk_edit_records', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                module_id: MODULE_ID,
                field_id: fieldId,
                operation: operation,
                value: val,
                record_ids: recordIds,
                all_selected: isAllPagesSelected,
                search: document.getElementById('recordSearchInput') ? document.getElementById('recordSearchInput').value : ''
            })
        });

        const data = await res.json();
        if (data.success) {
            closeBulkEditModal();
            vyToast(`Successfully updated ${data.updated_count} record(s)!`, 'success');
            setTimeout(() => { location.reload(); }, 600);
        } else {
            vyToast(data.error || 'Failed to update records.', 'error');
        }
    } catch (e) {
        console.error('Bulk edit error:', e);
        vyToast(e.message || 'An error occurred during bulk edit.', 'error');
    } finally {
        btnSubmit.disabled = false;
        btnSubmit.innerHTML = '<i class="fa-solid fa-check"></i> Apply Bulk Edit';
    }
}
</script>
</body>
</html>
