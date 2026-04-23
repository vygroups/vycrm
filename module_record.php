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

$recordId = (int)($_GET['record'] ?? 0);
$record = $recordId ? dm_fetch_record($conn, $prefix, $recordId) : null;
$isEdit = !!$record;

$users = dm_fetch_users($conn, $prefix);
$countries = dm_get_countries();
$states = dm_get_states();
$allModules = dm_fetch_active_modules($conn, $prefix);

// Collect all field rules for JS
$allRules = [];
foreach ($module['blocks'] as $block) {
    foreach ($block['fields'] as $field) {
        if (!empty($field['rules'])) {
            $allRules[$field['id']] = $field['rules'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(brand_page_title(($isEdit ? 'Edit' : 'New') . ' ' . $module['name'])) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <link href="/assets/css/module_manager.css?v=<?= $v ?>" rel="stylesheet">
</head>
<body>
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main-content">
        <header class="topbar">
            <div class="breadcrumb"><?= htmlspecialchars($module['name']) ?> / <span class="current"><?= $isEdit ? 'Edit Record' : 'New Record' ?></span></div>
            <div class="topbar-right">
                <a href="module_view.php?module=<?= $moduleId ?>" class="mm-btn"><i class="fa-solid fa-arrow-left"></i> Back</a>
                <button class="btn-primary" style="width:auto;padding:12px 24px;" onclick="saveRecord()"><i class="fa-solid fa-check"></i> Save</button>
            </div>
        </header>
        <div class="content-scroll">
            <div class="mr-form-container">
                <?php foreach ($module['blocks'] as $block): ?>
                <div class="mr-block">
                    <div class="mr-block-header"><i class="fa-solid fa-layer-group"></i> <?= htmlspecialchars($block['name']) ?></div>
                    <div class="mr-block-body">
                        <div class="mr-field-grid">
                        <?php foreach ($block['fields'] as $field):
                            $fid = $field['id'];
                            $val = $record['values'][$fid] ?? ($field['default_value'] ?? '');
                            $fullWidth = in_array($field['field_type'], ['textarea', 'attachment', 'name']);
                            $req = $field['is_required'] ? '<span class="required-star">*</span>' : '';
                        ?>
                            <div class="mr-field-group <?= $fullWidth ? 'full-width' : '' ?>" id="field-wrap-<?= $fid ?>" data-field-id="<?= $fid ?>">
                                <label class="mr-field-label"><?= htmlspecialchars($field['label']) ?> <?= $req ?></label>
                                <?php switch($field['field_type']):
                                    case 'text': case 'email': case 'url': case 'number': case 'currency': case 'phone': ?>
                                        <input type="<?= $field['field_type'] === 'email' ? 'email' : ($field['field_type'] === 'url' ? 'url' : ($field['field_type'] === 'number' || $field['field_type'] === 'currency' ? 'number' : 'text')) ?>"
                                               class="form-control dm-field" data-field-id="<?= $fid ?>"
                                               placeholder="<?= htmlspecialchars($field['placeholder'] ?? '') ?>"
                                               value="<?= htmlspecialchars($val) ?>"
                                               <?= $field['field_type'] === 'currency' ? 'step="0.01"' : '' ?>
                                               <?= $field['is_required'] ? 'required' : '' ?>>
                                    <?php break; case 'textarea': ?>
                                        <textarea class="form-control dm-field" data-field-id="<?= $fid ?>" rows="3"
                                                  placeholder="<?= htmlspecialchars($field['placeholder'] ?? '') ?>"
                                                  <?= $field['is_required'] ? 'required' : '' ?>><?= htmlspecialchars($val) ?></textarea>
                                    <?php break; case 'checkbox': ?>
                                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                            <input type="checkbox" class="dm-field" data-field-id="<?= $fid ?>" <?= $val ? 'checked' : '' ?> style="accent-color:var(--primary);width:18px;height:18px;">
                                            <span style="font-size:14px;"><?= htmlspecialchars($field['placeholder'] ?: 'Yes') ?></span>
                                        </label>
                                    <?php break; case 'dropdown': ?>
                                        <select class="form-control dm-field" data-field-id="<?= $fid ?>" <?= $field['is_required'] ? 'required' : '' ?>>
                                            <option value="">Select...</option>
                                            <?php foreach($field['options'] as $opt): ?>
                                            <option value="<?= htmlspecialchars($opt['value']) ?>" <?= $val === $opt['value'] ? 'selected' : '' ?>><?= htmlspecialchars($opt['label']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="mr-add-option" onclick="addNewOption(<?= $fid ?>, this)"><i class="fa-solid fa-plus"></i> Add new option</button>
                                    <?php break; case 'multi_picker': ?>
                                        <?php $selectedVals = json_decode($val, true) ?: []; ?>
                                        <div class="dm-multi-picker" data-field-id="<?= $fid ?>">
                                        <?php foreach($field['options'] as $opt): ?>
                                            <label style="display:flex;align-items:center;gap:6px;margin-bottom:4px;cursor:pointer;">
                                                <input type="checkbox" value="<?= htmlspecialchars($opt['value']) ?>" <?= in_array($opt['value'], $selectedVals) ? 'checked' : '' ?> style="accent-color:var(--primary);">
                                                <?= htmlspecialchars($opt['label']) ?>
                                            </label>
                                        <?php endforeach; ?>
                                        </div>
                                    <?php break; case 'date': ?>
                                        <input type="date" class="form-control dm-field" data-field-id="<?= $fid ?>" value="<?= htmlspecialchars($val) ?>" <?= $field['is_required'] ? 'required' : '' ?>>
                                    <?php break; case 'datetime': ?>
                                        <input type="datetime-local" class="form-control dm-field" data-field-id="<?= $fid ?>" value="<?= htmlspecialchars($val) ?>" <?= $field['is_required'] ? 'required' : '' ?>>
                                    <?php break; case 'time': ?>
                                        <input type="time" class="form-control dm-field" data-field-id="<?= $fid ?>" value="<?= htmlspecialchars($val) ?>" <?= $field['is_required'] ? 'required' : '' ?>>
                                    <?php break; case 'name': ?>
                                        <?php $nameParts = json_decode($val, true) ?: ['first'=>'','last'=>'']; ?>
                                        <div style="display:flex;gap:12px;">
                                            <input type="text" class="form-control dm-name-field" data-field-id="<?= $fid ?>" data-part="first" placeholder="First Name" value="<?= htmlspecialchars($nameParts['first'] ?? '') ?>">
                                            <input type="text" class="form-control dm-name-field" data-field-id="<?= $fid ?>" data-part="last" placeholder="Last Name" value="<?= htmlspecialchars($nameParts['last'] ?? '') ?>">
                                        </div>
                                    <?php break; case 'country': ?>
                                        <select class="form-control dm-field dm-country-field" data-field-id="<?= $fid ?>" <?= $field['is_required'] ? 'required' : '' ?>>
                                            <option value="">Select Country...</option>
                                            <?php foreach($countries as $code => $name): ?>
                                            <option value="<?= $code ?>" <?= $val === $code ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php break; case 'state': ?>
                                        <select class="form-control dm-field dm-state-field" data-field-id="<?= $fid ?>" <?= $field['is_required'] ? 'required' : '' ?>>
                                            <option value="">Select State...</option>
                                        </select>
                                    <?php break; case 'assigned_to': ?>
                                        <select class="form-control dm-field" data-field-id="<?= $fid ?>" <?= $field['is_required'] ? 'required' : '' ?>>
                                            <option value="">Select User...</option>
                                            <?php foreach($users as $u): ?>
                                            <option value="<?= $u['id'] ?>" <?= $val == $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['username']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php break; case 'api_call_picker': ?>
                                        <?php $cfg = $field['config'] ?? []; $linkedModId = $cfg['linked_module_id'] ?? 0; ?>
                                        <div class="dm-api-picker" data-field-id="<?= $fid ?>" data-linked-module="<?= $linkedModId ?>">
                                            <select class="form-control dm-field" data-field-id="<?= $fid ?>" <?= $field['is_required'] ? 'required' : '' ?>>
                                                <option value="">Search & Select...</option>
                                                <?php if($val): ?><option value="<?= htmlspecialchars($val) ?>" selected>Record #<?= htmlspecialchars($val) ?></option><?php endif; ?>
                                            </select>
                                            <button class="mr-add-option" onclick="searchLinkedRecords(<?= $fid ?>, <?= $linkedModId ?>)"><i class="fa-solid fa-search"></i> Search</button>
                                        </div>
                                    <?php break; case 'attachment': ?>
                                        <div class="dm-attachment" data-field-id="<?= $fid ?>">
                                            <input type="file" class="form-control" id="file-<?= $fid ?>" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx">
                                            <?php if($val): ?><div class="text-sm text-muted" style="margin-top:4px;"><i class="fa-solid fa-paperclip"></i> Current: <?= htmlspecialchars(basename($val)) ?></div><?php endif; ?>
                                        </div>
                                    <?php break; default: ?>
                                        <input type="text" class="form-control dm-field" data-field-id="<?= $fid ?>" value="<?= htmlspecialchars($val) ?>">
                                <?php endswitch; ?>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </main>
</div>
<script>
const API = '/api/modules.php';
const MODULE_ID = <?= $moduleId ?>;
const RECORD_ID = <?= $recordId ?: 'null' ?>;
const FIELD_RULES = <?= json_encode($allRules) ?>;
const STATES_DATA = <?= json_encode($states) ?>;

function getFieldValue(fieldId) {
    const el = document.querySelector(`.dm-field[data-field-id="${fieldId}"]`);
    if (el) {
        if (el.type === 'checkbox') return el.checked ? '1' : '';
        return el.value;
    }
    const multi = document.querySelector(`.dm-multi-picker[data-field-id="${fieldId}"]`);
    if (multi) return JSON.stringify([...multi.querySelectorAll('input:checked')].map(c => c.value));
    const names = document.querySelectorAll(`.dm-name-field[data-field-id="${fieldId}"]`);
    if (names.length) {
        const obj = {};
        names.forEach(n => obj[n.dataset.part] = n.value);
        return JSON.stringify(obj);
    }
    return '';
}

function collectValues() {
    const values = {};
    document.querySelectorAll('[data-field-id]').forEach(el => {
        const fid = el.dataset.fieldId;
        if (values[fid] !== undefined) return;
        values[fid] = getFieldValue(fid);
    });
    return values;
}

function saveRecord() {
    const values = collectValues();
    fetch(API, {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'save_record', module_id: MODULE_ID, record_id: RECORD_ID, values})
    }).then(r => r.json()).then(r => {
        if (r.success) { window.location.href = 'module_view.php?module=' + MODULE_ID; }
        else alert(r.error);
    }).catch(e => alert('Error: ' + e.message));
}

// Country → State dependency
document.querySelectorAll('.dm-country-field').forEach(sel => {
    sel.addEventListener('change', function() {
        const country = this.value;
        const stateSelects = document.querySelectorAll('.dm-state-field');
        stateSelects.forEach(ss => {
            ss.innerHTML = '<option value="">Select State...</option>';
            const statesForCountry = STATES_DATA[country] || {};
            Object.entries(statesForCountry).forEach(([code, name]) => {
                ss.innerHTML += `<option value="${code}">${name}</option>`;
            });
        });
    });
    // Trigger on load if value exists
    if (sel.value) sel.dispatchEvent(new Event('change'));
});

// Conditional / Dependency Rules Engine
function applyRules() {
    Object.entries(FIELD_RULES).forEach(([targetFieldId, rules]) => {
        const wrap = document.getElementById('field-wrap-' + targetFieldId);
        if (!wrap) return;
        const input = wrap.querySelector('.dm-field, .dm-multi-picker, .dm-name-field');
        let shouldShow = true, shouldRequire = null;

        rules.forEach(rule => {
            const sourceVal = getFieldValue(rule.source_field_id);
            let match = false;
            switch (rule.operator) {
                case 'equals': match = sourceVal === rule.value; break;
                case 'not_equals': match = sourceVal !== rule.value; break;
                case 'contains': match = sourceVal.includes(rule.value || ''); break;
                case 'not_empty': match = sourceVal !== ''; break;
            }
            if (rule.action === 'show') { if (!match) shouldShow = false; }
            else if (rule.action === 'hide') { if (match) shouldShow = false; }
            else if (rule.action === 'require') { shouldRequire = match; }
            else if (rule.action === 'optional') { if (match) shouldRequire = false; }
        });

        wrap.style.display = shouldShow ? '' : 'none';
        if (input && shouldRequire !== null) {
            if (input.setAttribute) {
                if (shouldRequire) input.setAttribute('required', '');
                else input.removeAttribute('required');
            }
            const label = wrap.querySelector('.mr-field-label');
            if (label) {
                let star = label.querySelector('.required-star');
                if (shouldRequire && !star) label.innerHTML += '<span class="required-star">*</span>';
                else if (!shouldRequire && star) star.remove();
            }
        }
    });
}

// Listen for changes on all fields to trigger rules
document.querySelectorAll('.dm-field, .dm-name-field').forEach(el => {
    el.addEventListener('change', applyRules);
    el.addEventListener('input', applyRules);
});
document.querySelectorAll('.dm-multi-picker input').forEach(el => {
    el.addEventListener('change', applyRules);
});
applyRules();

// Add new dropdown option on-the-fly
function addNewOption(fieldId, btn) {
    const label = prompt('New option:');
    if (!label) return;
    fetch(API, {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'add_dropdown_option', field_id: fieldId, label})
    }).then(r => r.json()).then(r => {
        if (r.success) {
            const sel = document.querySelector(`select.dm-field[data-field-id="${fieldId}"]`);
            if (sel) {
                const opt = document.createElement('option');
                opt.value = r.value; opt.textContent = r.label; opt.selected = true;
                sel.appendChild(opt);
            }
        } else alert(r.error);
    });
}

// API Call Picker search
function searchLinkedRecords(fieldId, linkedModuleId) {
    const q = prompt('Search:');
    if (q === null) return;
    fetch(API + '?action=lookup_records&target_module_id=' + linkedModuleId + '&search=' + encodeURIComponent(q))
    .then(r => r.json()).then(r => {
        if (!r.success) return alert(r.error);
        const sel = document.querySelector(`select.dm-field[data-field-id="${fieldId}"]`);
        sel.innerHTML = '<option value="">Select...</option>';
        (r.records || []).forEach(rec => {
            sel.innerHTML += `<option value="${rec.id}">${rec.display_value} (#${rec.id})</option>`;
        });
    });
}

<?php
// Pre-populate state field on edit
if ($record) {
    foreach ($module['blocks'] as $block) {
        foreach ($block['fields'] as $field) {
            if ($field['field_type'] === 'state' && !empty($record['values'][$field['id']])) {
                echo "setTimeout(()=>{ const ss=document.querySelector('.dm-state-field[data-field-id=\"{$field['id']}\"]'); if(ss){const opt=document.createElement('option');opt.value=" . json_encode($record['values'][$field['id']]) . ";opt.textContent=" . json_encode($record['values'][$field['id']]) . ";opt.selected=true;ss.appendChild(opt);} },500);\n";
            }
        }
    }
}
?>

function toggleSidebar(){document.getElementById('sidebar').classList.toggle('sidebar-collapsed');}
</script>
</body>
</html>
