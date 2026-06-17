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

$moduleId = (int)($_GET['module'] ?? 0);
if (!$moduleId) { header('Location: module_manager.php'); exit; }

$module = dm_fetch_module_full($conn, $prefix, $moduleId);
if (!$module) { header('Location: module_manager.php'); exit; }

$search = $_GET['search'] ?? '';
$data = dm_fetch_records($conn, $prefix, $moduleId, $search ?: null);
$fields = $data['fields'];
$records = $data['records'];
$total = $data['total'];

$usersStmt = $conn->query("SELECT id, username, first_name, last_name FROM {$prefix}users");
$usersList = [];
foreach ($usersStmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
    $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
    $usersList[$u['id']] = $name ?: $u['username'];
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
                    <form method="GET" class="mv-search">
                        <input type="hidden" name="module" value="<?= $moduleId ?>">
                        <i class="fa-solid fa-search"></i>
                        <input type="text" name="search" placeholder="Search records..." value="<?= htmlspecialchars($search) ?>">
                    </form>
                    <div style="display:flex;gap:10px;align-items:center;">
                        <span class="text-muted text-sm"><?= $total ?> record<?= $total !== 1 ? 's' : '' ?></span>
                        
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
                                    <div class="col-item" draggable="true" data-key="created" style="display:flex; align-items:center; gap:8px; font-size:13px; font-weight:500; cursor:grab; margin:0; user-select:none; color:var(--text); padding:6px 8px; border-radius:8px; border-top:1px solid var(--border); padding-top:8px; margin-top:4px;">
                                        <i class="fa-solid fa-grip-vertical" style="color:var(--text-muted); font-size:11px; cursor:grab;"></i>
                                        <input type="checkbox" class="col-toggle-checkbox" data-column="created" checked style="accent-color:var(--primary); width:16px; height:16px; cursor:pointer; margin:0;">
                                        <span style="cursor:pointer; flex:1;">Created On</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($_SESSION['is_admin'])): ?>
                        <a href="module_manager.php?edit=<?= $moduleId ?>" class="mm-btn mm-btn-sm mm-btn-outline"><i class="fa-solid fa-cog"></i> Configure</a>
                        <?php endif; ?>
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
                                    <th data-column="created">Created</th>
                                    <th class="sticky-actions-th">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
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
                                    <td data-column="created" class="text-muted text-sm" style="white-space: nowrap;"><?= date('d M Y', strtotime($rec['created_at'])) ?></td>
                                    <td class="sticky-actions-td">
                                        <div style="display:flex;gap:4px;">
                                            <a href="module_record.php?module=<?= $moduleId ?>&record=<?= $rec['id'] ?>&view=1" class="mm-icon-btn" title="View"><i class="fa-solid fa-eye"></i></a>
                                            <a href="module_record.php?module=<?= $moduleId ?>&record=<?= $rec['id'] ?>" class="mm-icon-btn" title="Edit"><i class="fa-solid fa-pencil"></i></a>
                                            <button class="mm-icon-btn mm-icon-danger" onclick="deleteRecord(<?= $rec['id'] ?>)" title="Delete"><i class="fa-solid fa-trash"></i></button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
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
    }).then(r => r.json()).then(r => { if (r.success) location.reload(); else alert(r.error); });
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

document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('columnSelectorDropdown');
    if (dropdown && !dropdown.contains(event.target)) {
        dropdown.style.display = 'none';
    }
});

function applyColumnVisibility() {
    let hiddenCols = [];
    try {
        hiddenCols = JSON.parse(localStorage.getItem(STORAGE_KEY)) || [];
    } catch(e) {}
    
    document.querySelectorAll('.col-toggle-checkbox').forEach(cb => {
        const fieldId = cb.dataset.fieldId;
        const column = cb.dataset.column;
        let isVisible = true;
        
        if (fieldId) {
            isVisible = !hiddenCols.includes(fieldId);
        } else if (column) {
            isVisible = !hiddenCols.includes(column);
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
    
    const rows = document.querySelectorAll('.crm-table tr');
    
    rows.forEach(row => {
        const cells = Array.from(row.children);
        if (cells.length <= 1) return; // Skip colspan rows or empty rows
        
        const headerCell = cells.find(c => c.tagName === 'TH' && c.classList.contains('sticky-actions-th'));
        const actionCell = cells.find(c => c.tagName === 'TD' && c.classList.contains('sticky-actions-td'));
        
        const firstCell = cells[0]; // the '#' column
        const sortableCells = cells.filter(c => c.dataset.fieldId || c.dataset.column);
        
        sortableCells.sort((a, b) => {
            const keyA = a.dataset.fieldId || a.dataset.column;
            const keyB = b.dataset.fieldId || b.dataset.column;
            let indexA = orderList.indexOf(keyA);
            let indexB = orderList.indexOf(keyB);
            
            if (indexA === -1) indexA = 999;
            if (indexB === -1) indexB = 999;
            
            return indexA - indexB;
        });
        
        row.innerHTML = '';
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
    const orderList = items.map(item => item.dataset.key);
    
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
        const key = fieldId || column;
        
        let hiddenCols = [];
        try {
            hiddenCols = JSON.parse(localStorage.getItem(STORAGE_KEY)) || [];
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
});
</script>
</body>
</html>
