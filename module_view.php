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

$data = dm_fetch_records($conn, $prefix, $moduleId, $search ?: null, 50, 0, $activeFilterRules);
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
    </style>
</head>
<body>
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main-content">
        <header class="topbar">
            <div class="breadcrumb">Modules / <span class="current"><?= htmlspecialchars($module['name']) ?></span></div>
            <div class="topbar-right">
                <a href="module_record.php?module=<?= $moduleId ?>" class="btn-primary" style="width:auto;padding:12px 24px;text-decoration:none;display:inline-flex;align-items:center;gap:8px;">
                    <i class="fa-solid fa-plus"></i> New Record
                </a>
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
                    <div class="table-responsive">
                        <table class="crm-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <?php foreach($fields as $f): ?>
                                    <th data-field-id="<?= $f['id'] ?>"><?= htmlspecialchars($f['label']) ?></th>
                                    <?php endforeach; ?>
                                    <?php if (!$hasSysCreatedAt): ?>
                                    <th data-column="created">Created</th>
                                    <?php endif; ?>
                                    <th class="sticky-actions-th">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="moduleRecordsTableBody">
                                <?php if(empty($records)): ?>
                                <tr><td colspan="<?= count($fields) + 3 ?>" style="text-align:center;padding:40px;color:var(--text-muted);">No records found. <a href="module_record.php?module=<?= $moduleId ?>" style="color:var(--primary);font-weight:600;">Create one</a></td></tr>
                                <?php else: ?>
                                <?php foreach($records as $i => $rec): ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <?php foreach($fields as $f): ?>
                                    <td data-field-id="<?= $f['id'] ?>" <?= in_array($f['field_type'], ['date', 'datetime', 'time', 'phone']) ? 'style="white-space: nowrap;"' : '' ?>><?php
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


function toggleSidebar() { document.getElementById('sidebar').classList.toggle('sidebar-collapsed'); }

// Column Configurator Logic
const USER_ID = <?= (int)($_SESSION['user_id'] ?? 0) ?>;
const MODULE_ID = <?= $moduleId ?>;
const STORAGE_KEY = `vycrm_col_vis_${USER_ID}_${MODULE_ID}`;
const ORDER_KEY = `vycrm_col_order_${USER_ID}_${MODULE_ID}`;

function toggleColumnSelector(event) {
    event.stopPropagation();
    const dropdown = document.getElementById('columnSelectorDropdown');
    dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
}

// Pagination state variables
const DEFAULT_PAGE_LIMIT = <?= $defaultRecordsPerPage ?>;
let currentPage = 1;
let currentPageLimit = DEFAULT_PAGE_LIMIT;

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
        offset: offset
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
            
            const total = r.data.total;
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
}

function renderRecordsTable(fields, records) {
    const tbody = document.getElementById('moduleRecordsTableBody');
    if (!tbody) return;
    
    if (!records || records.length === 0) {
        tbody.innerHTML = `<tr><td colspan="${fields.length + 3}" style="text-align:center;padding:40px;color:var(--text-muted);">No records found. <a href="module_record.php?module=${MODULE_ID}" style="color:var(--primary);font-weight:600;">Create one</a></td></tr>`;
        return;
    }
    
    let html = '';
    records.forEach((rec, i) => {
        html += `<tr>`;
        html += `<td>${i + 1}</td>`;
        
        fields.forEach(f => {
            const val = rec.values[f.id] !== undefined ? rec.values[f.id] : '';
            const isNowrap = ['date', 'datetime', 'time', 'phone'].includes(f.field_type) ? 'style="white-space: nowrap;"' : '';
            
            html += `<td data-field-id="${f.id}" ${isNowrap}>${formatFieldValue(val, f.field_type, f.options)}</td>`;
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
    
    applyColumnVisibility();
    const orderList = JSON.parse(localStorage.getItem(ORDER_KEY)) || [];
    applyColumnOrder(orderList);
}

function formatFieldValue(val, fieldType, fieldOptions = []) {
    if (val === null || val === undefined) return '-';
    
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
        
        const firstCell = cells[0]; // the '#' column
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
        if (firstCell) row.appendChild(firstCell);
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

document.addEventListener('DOMContentLoaded', () => {
    initColumnOrder();
    applyColumnVisibility();
    setupDragAndDrop();
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
</script>
</body>
</html>
