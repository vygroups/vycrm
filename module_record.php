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

$defaultCountry = 'IN';
$defaultCurrency = '₹';
try {
    $profileStmt = $conn->query("SELECT country, currency_symbol FROM {$prefix}business_profile WHERE id = 1");
    $businessProfile = $profileStmt ? $profileStmt->fetch(PDO::FETCH_ASSOC) : null;
    if (!empty($businessProfile['country'])) {
        $defaultCountry = $businessProfile['country'];
    }
    if (!empty($businessProfile['currency_symbol'])) {
        $defaultCurrency = $businessProfile['currency_symbol'];
    }
} catch (Throwable $e) {}

$moduleId = (int)($_GET['module'] ?? 0);
if (!$moduleId) { header('Location: module_manager.php'); exit; }
$module = dm_fetch_module_full($conn, $prefix, $moduleId);
if (!$module) { header('Location: module_manager.php'); exit; }

$recordId = (int)($_GET['record'] ?? 0);

$record = $recordId ? dm_fetch_record($conn, $prefix, $recordId) : null;
$isEdit = !!$record;
$isViewOnly = !empty($_GET['view']);

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
    <link href="https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.css" rel="stylesheet">
    <script src="https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.js"></script>
    <script src="https://api.mapbox.com/mapbox-gl-js/plugins/mapbox-gl-geocoder/v5.0.0/mapbox-gl-geocoder.min.js"></script>
    <link rel="stylesheet" href="https://api.mapbox.com/mapbox-gl-js/plugins/mapbox-gl-geocoder/v5.0.0/mapbox-gl-geocoder.css" type="text/css">
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

        /* Premium Toasts */
        #vyToastContainer { position:fixed; top:20px; right:20px; z-index:99999; display:flex; flex-direction:column; gap:10px; }
        .vy-toast { border-radius:10px; padding:14px 20px; min-width:280px; max-width:360px; font-size:14px; font-weight:600; box-shadow:0 8px 25px rgba(0,0,0,.12); display:flex; align-items:center; gap:10px; opacity:0; transform:translateX(30px); transition:all .35s cubic-bezier(.25,.8,.25,1); }
        .vy-toast.show { opacity:1; transform:translateX(0); }
    </style>
</head>
<body>
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main-content">
        <header class="topbar">
            <div class="breadcrumb"><?= htmlspecialchars($module['name']) ?> / <span class="current"><?= $isViewOnly ? 'View Record' : ($isEdit ? 'Edit Record' : 'New Record') ?></span></div>
            <div class="topbar-right">
                <a href="module_view.php?module=<?= $moduleId ?>" class="mm-btn"><i class="fa-solid fa-arrow-left"></i> Back</a>
                <?php if(!$isViewOnly): ?>
                <button class="btn-primary" style="width:auto;padding:12px 24px;" onclick="saveRecord()"><i class="fa-solid fa-check"></i> Save</button>
                <?php endif; ?>
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
                            if (strpos($field['field_type'], 'sys_') === 0) {
                                continue;
                            }
                            $fid = $field['id'];
                            $val = $record['values'][$fid] ?? ($field['default_value'] ?? '');
                            $fullWidth = in_array($field['field_type'], ['textarea', 'attachment', 'name']);
                            $req = $field['is_required'] ? '<span class="required-star">*</span>' : '';
                        ?>
                            <div class="mr-field-group <?= $fullWidth ? 'full-width' : '' ?>" id="field-wrap-<?= $fid ?>" data-field-id="<?= $fid ?>" data-field-type="<?= $field['field_type'] ?>" data-field-label="<?= htmlspecialchars($field['label']) ?>">
                                <label class="mr-field-label"><?= htmlspecialchars($field['label']) ?> <?= $req ?></label>
                                <?php switch($field['field_type']):
                                    case 'text': case 'email': case 'url': case 'number': case 'currency': ?>
                                        <input type="<?= $field['field_type'] === 'email' ? 'email' : ($field['field_type'] === 'url' ? 'url' : ($field['field_type'] === 'number' || $field['field_type'] === 'currency' ? 'number' : 'text')) ?>"
                                               class="form-control dm-field" data-field-id="<?= $fid ?>"
                                               placeholder="<?= htmlspecialchars($field['placeholder'] ?? '') ?>"
                                               value="<?= htmlspecialchars($val) ?>"
                                               <?= $field['field_type'] === 'currency' ? 'step="0.01"' : '' ?>
                                               <?= $field['is_required'] ? 'required' : '' ?>>
                                    <?php break; case 'phone': 
                                        $dialCodes = [
                                            'IN' => '+91', 'US' => '+1', 'GB' => '+44', 'AE' => '+971', 'SA' => '+966',
                                            'AU' => '+61', 'CA' => '+1', 'SG' => '+65', 'MY' => '+60', 'DE' => '+49',
                                            'FR' => '+33', 'JP' => '+81', 'CN' => '+86', 'KR' => '+82', 'BR' => '+55',
                                            'ZA' => '+27', 'NZ' => '+64', 'QA' => '+974', 'KW' => '+965', 'BH' => '+973',
                                            'OM' => '+968', 'NP' => '+977', 'LK' => '+94', 'BD' => '+880'
                                        ];
                                        $selectedPrefix = $defaultCountry;
                                        $phoneNum = $val;
                                        foreach ($dialCodes as $cCode => $prefixCode) {
                                            if (strpos($val, $prefixCode) === 0) {
                                                $selectedPrefix = $cCode;
                                                $phoneNum = substr($val, strlen($prefixCode));
                                                $phoneNum = ltrim($phoneNum, " -");
                                                break;
                                            }
                                        }
                                        ?>
                                        <div class="phone-input-wrapper" style="display: flex; gap: 8px; width: 100%;">
                                            <select class="form-control phone-prefix-select dm-phone-prefix" data-field-id="<?= $fid ?>" style="width: 120px; flex-shrink: 0; padding: 12px 16px; border-radius: 12px; border: 1.5px solid var(--border); font-size: 14px; background: #fff; box-sizing: border-box;">
                                                <?php foreach ($dialCodes as $cCode => $prefixCode): ?>
                                                    <option value="<?= htmlspecialchars($prefixCode) ?>" <?= $selectedPrefix === $cCode ? 'selected' : '' ?>><?= $cCode ?> (<?= $prefixCode ?>)</option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="text" class="form-control dm-field dm-phone-number" data-field-id="<?= $fid ?>"
                                                   placeholder="<?= htmlspecialchars($field['placeholder'] ?? '') ?>"
                                                   value="<?= htmlspecialchars($phoneNum) ?>"
                                                   <?= $field['is_required'] ? 'required' : '' ?> style="flex-grow: 1;">
                                        </div>
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
                                        <select class="dm-field dm-tom-select dm-dropdown" data-field-id="<?= $fid ?>" <?= $field['is_required'] ? 'required' : '' ?> placeholder="Select or type to add...">
                                            <option value="">Select...</option>
                                            <?php foreach($field['options'] as $opt): ?>
                                            <option value="<?= htmlspecialchars($opt['value']) ?>" <?= $val === $opt['value'] ? 'selected' : '' ?>><?= htmlspecialchars($opt['label']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php break; case 'radio_group': ?>
                                        <div class="dm-radio-group">
                                            <?php foreach($field['options'] as $idx => $opt): ?>
                                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:6px;">
                                                <input type="radio" name="radio_<?= $fid ?>" class="dm-field" data-field-id="<?= $fid ?>" value="<?= htmlspecialchars($opt['value']) ?>" <?= $val === $opt['value'] ? 'checked' : '' ?> <?= ($field['is_required'] && $idx === 0) ? 'required' : '' ?> style="accent-color:var(--primary);width:16px;height:16px;">
                                                <span style="font-size:14px;"><?= htmlspecialchars($opt['label']) ?></span>
                                            </label>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php break; case 'multi_picker': ?>
                                        <?php $selectedVals = json_decode($val, true) ?: []; ?>
                                        <select multiple class="dm-field dm-multi-picker dm-tom-select" data-field-id="<?= $fid ?>" placeholder="Search and select...">
                                            <?php foreach($field['options'] as $opt): ?>
                                            <option value="<?= htmlspecialchars($opt['value']) ?>" <?= in_array($opt['value'], $selectedVals) ? 'selected' : '' ?>><?= htmlspecialchars($opt['label']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php break; case 'date': ?>
                                        <input type="text" class="form-control dm-field dm-date-picker" data-field-id="<?= $fid ?>" value="<?= htmlspecialchars($val) ?>" <?= $field['is_required'] ? 'required' : '' ?> placeholder="Select Date">
                                    <?php break; case 'datetime': ?>
                                        <input type="text" class="form-control dm-field dm-datetime-picker" data-field-id="<?= $fid ?>" value="<?= htmlspecialchars($val) ?>" <?= $field['is_required'] ? 'required' : '' ?> placeholder="Select Date & Time">
                                    <?php break; case 'time': ?>
                                        <input type="text" class="form-control dm-field dm-time-picker" data-field-id="<?= $fid ?>" value="<?= htmlspecialchars($val) ?>" <?= $field['is_required'] ? 'required' : '' ?> placeholder="Select Time">
                                    <?php break; case 'duration': ?>
                                        <div style="display:flex;gap:10px;align-items:center;">
                                            <input type="number" class="form-control dm-field" data-field-id="<?= $fid ?>" value="<?= htmlspecialchars($val) ?>" <?= $field['is_required'] ? 'required' : '' ?> min="0" placeholder="e.g. 100">
                                            <span class="text-muted" style="font-size:14px;">seconds</span>
                                        </div>
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
                                    <?php break; case 'district': ?>
                                        <select class="form-control dm-field dm-district-field" data-field-id="<?= $fid ?>" <?= $field['is_required'] ? 'required' : '' ?>>
                                            <option value="">Select District...</option>
                                        </select>
                                    <?php break; case 'assigned_to': ?>
                                        <select class="form-control dm-field" data-field-id="<?= $fid ?>" <?= $field['is_required'] ? 'required' : '' ?>>
                                            <option value="">Select User...</option>
                                            <?php foreach($users as $u): 
                                                $displayName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                                                $displayText = $displayName ? "$displayName (" . $u['username'] . ")" : $u['username'];
                                            ?>
                                            <option value="<?= $u['id'] ?>" <?= $val == $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($displayText) ?></option>
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
                                            <?php 
                                            $isImage = false;
                                            if ($val) {
                                                $ext = strtolower(pathinfo($val, PATHINFO_EXTENSION));
                                                $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                                            }
                                            ?>
                                            <?php if($isImage): ?>
                                                <div style="margin-bottom: 12px; position:relative; display:inline-block;">
                                                    <img src="/<?= htmlspecialchars($val) ?>" alt="Attachment" style="max-width:200px; max-height:200px; border-radius:8px; border:1px solid var(--border); box-shadow:var(--shadow-sm);">
                                                    <?php if(!$isViewOnly): ?>
                                                    <button type="button" class="mm-icon-btn" style="position:absolute; top:-10px; right:-10px; background:white; box-shadow:var(--shadow-md); z-index: 10;" onclick="document.getElementById('file-<?= $fid ?>').click();" title="Edit Image"><i class="fa-solid fa-pencil"></i></button>
                                                    <?php endif; ?>
                                                </div>
                                            <?php elseif($val): ?>
                                                <div class="text-sm text-muted" style="margin-bottom:8px;">
                                                    <i class="fa-solid fa-paperclip"></i> Current: <a href="/<?= htmlspecialchars($val) ?>" target="_blank" style="color:var(--primary);"><?= htmlspecialchars(basename($val)) ?></a>
                                                </div>
                                            <?php endif; ?>
                                            <input type="file" class="form-control" id="file-<?= $fid ?>" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx" <?= ($isImage && $val) || $isViewOnly ? 'style="display:none;"' : '' ?>>
                                        </div>
                                    <?php break; case 'address': ?>
                                        <div style="position:relative;">
                                            <textarea class="form-control dm-field" data-field-id="<?= $fid ?>" rows="3" placeholder="<?= htmlspecialchars($field['placeholder'] ?? 'Enter address') ?>"><?= htmlspecialchars($val) ?></textarea>
                                            <button class="mm-icon-btn" style="position:absolute; right:10px; bottom:10px; background:var(--surface);" onclick="openMapPicker(<?= $fid ?>, 'address')" type="button" title="Pick on map"><i class="fa-solid fa-map-location-dot" style="color:var(--primary);"></i></button>
                                        </div>
                                    <?php break; case 'map_picker': ?>
                                        <div style="position:relative;">
                                            <input type="text" class="form-control dm-field" data-field-id="<?= $fid ?>" placeholder="Latitude, Longitude" value="<?= htmlspecialchars($val) ?>">
                                            <button class="mm-icon-btn" style="position:absolute; right:5px; top:5px; background:var(--surface);" onclick="openMapPicker(<?= $fid ?>, 'coordinates')" type="button" title="Pick on map"><i class="fa-solid fa-location-crosshairs" style="color:var(--primary);"></i></button>
                                        </div>
                                    <?php break; case 'sys_created_by': case 'sys_created_at': case 'sys_updated_by': case 'sys_updated_at': ?>
                                        <div style="padding: 10px 14px; background: var(--surface); border: 1px solid var(--border); border-radius: 10px; font-size: 14px; color: var(--text-muted); min-height: 42px; display: flex; align-items: center;">
                                            <?= htmlspecialchars($val ?: 'N/A') ?>
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

<!-- Map Picker Modal -->
<div class="mm-modal-overlay" id="mapModal">
    <div class="mm-modal mm-modal-lg">
        <div class="mm-modal-header"><h3 id="mapModalTitle">Pick Location</h3><button class="mm-icon-btn" onclick="closeModal('mapModal')"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="mm-modal-body" style="padding:0;">
            <div id="mapContainer" style="width:100%; height:400px; border-bottom-left-radius: 20px; border-bottom-right-radius: 20px;"></div>
        </div>
        <div class="mm-modal-footer">
            <button class="mm-btn" onclick="closeModal('mapModal')">Cancel</button>
            <button class="mm-btn mm-btn-primary" onclick="confirmMapSelection()"><i class="fa-solid fa-check"></i> Confirm</button>
        </div>
    </div>
</div>

<script>
const API = '/api/modules.php';
const MODULE_ID = <?= $moduleId ?>;
const RECORD_ID = <?= $recordId ?: 'null' ?>;
const IS_VIEW_ONLY = <?= $isViewOnly ? 'true' : 'false' ?>;
const FIELD_RULES = <?= json_encode($allRules) ?>;
const STATES_DATA = <?= json_encode($states) ?>;

function getFieldValue(fieldId) {
    const fileInput = document.getElementById('file-' + fieldId);
    if (fileInput) return undefined; // Don't collect text value for files

    const multi = document.querySelector(`select.dm-multi-picker[data-field-id="${fieldId}"]`);
    if (multi) return JSON.stringify([...multi.selectedOptions].map(o => o.value));

    const phoneNumEl = document.querySelector(`.dm-phone-number[data-field-id="${fieldId}"]`);
    if (phoneNumEl) {
        const prefixEl = document.querySelector(`.dm-phone-prefix[data-field-id="${fieldId}"]`);
        const prefix = prefixEl ? prefixEl.value : '';
        const number = phoneNumEl.value.trim();
        return number ? (prefix + number) : '';
    }

    const el = document.querySelector(`.dm-field[data-field-id="${fieldId}"]`);
    if (el) {
        if (el.type === 'checkbox') return el.checked ? '1' : '';
        if (el.type === 'radio') {
            const checkedRadio = document.querySelector(`input[type="radio"][data-field-id="${fieldId}"]:checked`);
            return checkedRadio ? checkedRadio.value : '';
        }
        return el.value;
    }
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
        const val = getFieldValue(fid);
        if (val !== undefined) values[fid] = val;
    });
    return values;
}

// Premium Toast Utility
function vyToast(msg, type = 'error') {
    const colors = { 
        success: { border: '#10b981', bg: '#ecfdf5', text: '#065f46' }, 
        error: { border: '#ef4444', bg: '#fef2f2', text: '#991b1b' } 
    };
    const config = colors[type] || colors.error;
    
    let container = document.getElementById('vyToastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'vyToastContainer';
        document.body.appendChild(container);
    }
    const t = document.createElement('div');
    t.className = 'vy-toast';
    t.style.background = config.bg;
    t.style.color = config.text;
    t.style.borderLeft = '4px solid ' + config.border;
    t.innerHTML = `<span>${type === 'success' ? '✅' : '❌'}</span><span>${msg}</span>`;
    container.appendChild(t);
    requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('show')));
    setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 400); }, 3500);
}

function showFieldError(fieldId, errorMsg) {
    const wrap = document.getElementById('field-wrap-' + fieldId);
    if (!wrap) return;

    wrap.querySelectorAll('.form-control, .ts-control, select, textarea').forEach(el => {
        el.style.borderColor = '#ef4444';
        el.classList.add('is-invalid');
    });

    let errDiv = wrap.querySelector('.error-msg');
    if (!errDiv) {
        errDiv = document.createElement('div');
        errDiv.className = 'error-msg';
        errDiv.style.color = '#ef4444';
        errDiv.style.fontSize = '12px';
        errDiv.style.marginTop = '4px';
        wrap.appendChild(errDiv);
    }
    errDiv.textContent = errorMsg;
}

function validateFields() {
    let hasErrors = false;
    
    // Clear previous error messages & styles
    document.querySelectorAll('.mr-field-group .error-msg').forEach(el => el.remove());
    document.querySelectorAll('.mr-field-group .form-control, .mr-field-group .ts-control, .mr-field-group select, .mr-field-group textarea').forEach(el => {
        el.style.borderColor = '';
        el.classList.remove('is-invalid');
    });
    
    document.querySelectorAll('.mr-field-group').forEach(wrap => {
        if (wrap.style.display === 'none') return;
        
        const fid = wrap.dataset.fieldId;
        const type = wrap.dataset.fieldType;
        const label = wrap.dataset.fieldLabel;
        const val = getFieldValue(fid);
        const isRequired = wrap.querySelector('[required]') !== null || wrap.querySelector('.required-star') !== null;
        
        // 1. Mandatory Check
        if (isRequired) {
            if (!val || val === '""' || val === '[]' || val === '{"first":"","last":""}') {
                showFieldError(fid, `"${label}" is required.`);
                hasErrors = true;
                return;
            }
        }
        
        // If field is empty and not required, skip format checks
        if (!val || val === '""' || val === '[]' || val === '{"first":"","last":""}') {
            return;
        }
        
        // 2. Format Checks
        if (type === 'email') {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(val)) {
                showFieldError(fid, `"${label}" must be a valid email address.`);
                hasErrors = true;
            }
        } else if (type === 'phone') {
            const phoneRegex = /^\+?[0-9\s\-()]{7,20}$/;
            if (!phoneRegex.test(val)) {
                showFieldError(fid, `"${label}" must be a valid phone number.`);
                hasErrors = true;
            }
        } else if (type === 'number' || type === 'currency' || type === 'duration') {
            if (isNaN(val) || val.trim() === '') {
                showFieldError(fid, `"${label}" must be a valid number.`);
                hasErrors = true;
            }
        } else if (type === 'url') {
            const urlRegex = /^(https?:\/\/)?([\da-z\.-]+)\.([a-z\.]{2,6})([\/\w \.-]*)*\/?$/;
            if (!urlRegex.test(val)) {
                showFieldError(fid, `"${label}" must be a valid URL.`);
                hasErrors = true;
            }
        } else if (type === 'name') {
            try {
                const nameObj = JSON.parse(val);
                if (isRequired && (!nameObj.first || nameObj.first.trim() === '')) {
                    showFieldError(fid, `"${label}" First Name is required.`);
                    hasErrors = true;
                }
            } catch(e) {}
        }
    });
    
    return hasErrors;
}

function saveRecord() {
    if (IS_VIEW_ONLY) return;
    
    const hasErrors = validateFields();
    if (hasErrors) {
        vyToast('Please correct the highlighted errors before saving.', 'error');
        return;
    }

    const values = collectValues();
    const formData = new FormData();
    formData.append('action', 'save_record');
    formData.append('module_id', MODULE_ID);
    formData.append('record_id', RECORD_ID || 0);
    formData.append('values', JSON.stringify(values));

    // Append attachments
    document.querySelectorAll('.dm-attachment input[type="file"]').forEach(input => {
        if (input.files.length > 0) {
            const fid = input.id.replace('file-', '');
            formData.append(`attachments[${fid}]`, input.files[0]);
        }
    });

    fetch(API, {
        method: 'POST', body: formData
    }).then(r => r.json()).then(r => {
        if (r.success) { 
            vyToast('Record saved successfully!', 'success');
            setTimeout(() => { window.location.href = 'module_view.php?module=' + MODULE_ID; }, 1000);
        }
        else vyToast(r.error, 'error');
    }).catch(e => vyToast('Error: ' + e.message, 'error'));
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

const DISTRICTS_DATA = <?= json_encode(dm_get_districts()) ?>;

// State → District dependency
document.querySelectorAll('.dm-state-field').forEach(sel => {
    sel.addEventListener('change', function() {
        const state = this.value;
        const distSelects = document.querySelectorAll('.dm-district-field');
        distSelects.forEach(ds => {
            ds.innerHTML = '<option value="">Select District...</option>';
            const distForState = DISTRICTS_DATA[state] || {};
            Object.entries(distForState).forEach(([code, name]) => {
                ds.innerHTML += `<option value="${code}">${name}</option>`;
            });
            ds.dispatchEvent(new Event('change'));
        });
    });
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

// Disable fields if view only
if (IS_VIEW_ONLY) {
    document.querySelectorAll('.dm-field, .dm-multi-picker, .dm-name-field, .dm-phone-prefix, input[type=file]').forEach(el => el.disabled = true);
    document.querySelectorAll('.mr-add-option, .mm-icon-btn, .dm-api-picker button').forEach(el => {
        if(el.closest('.mr-form-container')) el.style.display = 'none';
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

// Initialize Tom Select
document.querySelectorAll('.dm-tom-select').forEach(el => {
    let isMulti = el.hasAttribute('multiple');
    let fieldId = el.dataset.fieldId;
    let isDropdown = el.classList.contains('dm-dropdown');
    
    new TomSelect(el, {
        dropdownParent: 'body',
        create: (isDropdown && !isMulti) ? function(input, callback) {
            fetch(API, {
                method: 'POST', headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({action: 'add_dropdown_option', field_id: fieldId, label: input})
            }).then(r => r.json()).then(r => {
                if (r.success) {
                    callback({value: r.value, text: r.label});
                } else {
                    alert(r.error);
                    callback();
                }
            }).catch(() => callback());
        } : false,
        plugins: isMulti ? ['remove_button'] : [],
        sortField: { field: 'text', direction: 'asc' }
    });
});

// Update image preview on file selection
document.querySelectorAll('.dm-attachment input[type="file"]').forEach(input => {
    input.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            const file = this.files[0];
            const isImage = file.type.startsWith('image/');
            const container = this.closest('.dm-attachment');
            let img = container.querySelector('img');
            
            if (isImage) {
                const url = URL.createObjectURL(file);
                if (img) {
                    img.src = url;
                } else {
                    const div = document.createElement('div');
                    div.style.cssText = 'margin-bottom: 12px; position:relative; display:inline-block;';
                    div.innerHTML = `<img src="${url}" style="max-width:200px; max-height:200px; border-radius:8px; border:1px solid var(--border); box-shadow:var(--shadow-sm);">
                                     <button type="button" class="mm-icon-btn" style="position:absolute; top:-10px; right:-10px; background:white; box-shadow:var(--shadow-md); z-index: 10;" onclick="document.getElementById('${this.id}').click();"><i class="fa-solid fa-pencil"></i></button>`;
                    container.insertBefore(div, this);
                    const oldText = container.querySelector('.text-sm');
                    if (oldText) oldText.remove();
                }
            } else {
                if (img) img.closest('div').remove();
                let text = container.querySelector('.text-sm');
                if (!text) {
                    text = document.createElement('div');
                    text.className = 'text-sm text-muted';
                    text.style.marginBottom = '8px';
                    container.insertBefore(text, this);
                }
                text.innerHTML = `<i class="fa-solid fa-paperclip"></i> Selected: <b>${file.name}</b>`;
            }
        }
    });
});

// Initialize Flatpickr for Date & Time fields
const dateFormat = "<?= $_SESSION['date_format'] ?? 'd M, Y' ?>";
const is12Hour = "<?= $_SESSION['time_format'] ?? '12h' ?>" === '12h';
const timeFormat = is12Hour ? 'h:i K' : 'H:i';

flatpickr('.dm-date-picker', {
    altInput: true,
    altFormat: dateFormat,
    dateFormat: "Y-m-d",
    allowInput: true
});

flatpickr('.dm-datetime-picker', {
    enableTime: true,
    altInput: true,
    altFormat: dateFormat + " " + timeFormat,
    dateFormat: "Y-m-d H:i",
    time_24hr: !is12Hour,
    allowInput: true
});

flatpickr('.dm-time-picker', {
    enableTime: true,
    noCalendar: true,
    altInput: true,
    altFormat: timeFormat,
    dateFormat: "H:i",
    time_24hr: !is12Hour,
    allowInput: true
});

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
// Pre-populate state and district fields on edit
if ($record) {
    foreach ($module['blocks'] as $block) {
        foreach ($block['fields'] as $field) {
            if ($field['field_type'] === 'state' && !empty($record['values'][$field['id']])) {
                echo "setTimeout(()=>{ const ss=document.querySelector('.dm-state-field[data-field-id=\"{$field['id']}\"]'); if(ss){const opt=document.createElement('option');opt.value=" . json_encode($record['values'][$field['id']]) . ";opt.textContent=" . json_encode($record['values'][$field['id']]) . ";opt.selected=true;ss.appendChild(opt); ss.dispatchEvent(new Event('change'));} },500);\n";
            }
            if ($field['field_type'] === 'district' && !empty($record['values'][$field['id']])) {
                echo "setTimeout(()=>{ const ds=document.querySelector('.dm-district-field[data-field-id=\"{$field['id']}\"]'); if(ds){const opt=document.createElement('option');opt.value=" . json_encode($record['values'][$field['id']]) . ";opt.textContent=" . json_encode($record['values'][$field['id']]) . ";opt.selected=true;ds.appendChild(opt);} },800);\n";
            }
        }
    }
}
?>

// Mapbox logic
const MAPBOX_CONFIG = <?= json_encode(dm_get_mapbox_config()) ?>;
mapboxgl.accessToken = MAPBOX_CONFIG.access_token;
let currentMap = null;
let currentMapMarker = null;
let currentMapTarget = null;
let currentMapMode = null;

function openMapPicker(fieldId, mode = 'coordinates') {
    currentMapTarget = fieldId;
    currentMapMode = mode;
    document.getElementById('mapModalTitle').textContent = mode === 'address' ? 'Pick Address' : 'Pick Location';
    document.getElementById('mapModal').classList.add('show');
    
    if (!currentMap) {
        currentMap = new mapboxgl.Map({
            container: 'mapContainer',
            style: 'mapbox://styles/mapbox/streets-v12',
            center: [78.9629, 20.5937],
            zoom: 4
        });
        
        // Add Zoom & Rotation Controls
        currentMap.addControl(new mapboxgl.NavigationControl(), 'bottom-right');
        
        // Add Fullscreen Control
        currentMap.addControl(new mapboxgl.FullscreenControl(), 'top-right');
        
        // Add User Location Control
        currentMap.addControl(new mapboxgl.GeolocateControl({
            positionOptions: { enableHighAccuracy: true },
            trackUserLocation: true,
            showUserHeading: true
        }), 'bottom-right');

        // Add Search (Geocoder) Control
        const geocoder = new MapboxGeocoder({
            accessToken: mapboxgl.accessToken,
            mapboxgl: mapboxgl,
            marker: false,
            placeholder: 'Search for a place...'
        });
        currentMap.addControl(geocoder, 'top-left');

        geocoder.on('result', (e) => {
            const coords = e.result.center; // [lng, lat]
            if (!currentMapMarker) {
                currentMapMarker = new mapboxgl.Marker({color: 'var(--primary)'}).setLngLat(coords).addTo(currentMap);
            } else {
                currentMapMarker.setLngLat(coords);
            }
        });
        
        currentMap.on('click', (e) => {
            if (!currentMapMarker) {
                currentMapMarker = new mapboxgl.Marker({color: 'var(--primary)'}).setLngLat(e.lngLat).addTo(currentMap);
            } else {
                currentMapMarker.setLngLat(e.lngLat);
            }
        });
    }
    setTimeout(() => currentMap.resize(), 100);
}

function confirmMapSelection() {
    if (!currentMapMarker) {
        alert('Please click on the map to select a location.');
        return;
    }
    const lngLat = currentMapMarker.getLngLat();
    
    if (currentMapMode === 'address') {
        const el = document.querySelector(`textarea[data-field-id="${currentMapTarget}"]`);
        el.value = 'Loading address...';
        fetch(`https://api.mapbox.com/geocoding/v5/mapbox.places/${lngLat.lng},${lngLat.lat}.json?access_token=${MAPBOX_CONFIG.access_token}`)
            .then(r => r.json())
            .then(data => {
                if (data.features && data.features.length > 0) {
                    el.value = data.features[0].place_name;
                    el.dispatchEvent(new Event('change'));
                } else {
                    el.value = '';
                    alert('Could not determine address for this location.');
                }
            });
    } else {
        const el = document.querySelector(`input[data-field-id="${currentMapTarget}"]`);
        el.value = `${lngLat.lat}, ${lngLat.lng}`;
        el.dispatchEvent(new Event('change'));
    }
    closeModal('mapModal');
}
function closeModal(id){ document.getElementById(id).classList.remove('show'); }

function toggleSidebar(){document.getElementById('sidebar').classList.toggle('sidebar-collapsed');}
</script>
</body>
</html>
