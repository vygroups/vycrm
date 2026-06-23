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

$convertFromModuleId = (int)($_GET['convert_from_module'] ?? 0);
$convertFromRecordId = (int)($_GET['convert_from_record'] ?? 0);
$convertRuleId = (int)($_GET['convert_rule'] ?? 0);

$prefilledValues = [];
if ($convertFromModuleId && $convertFromRecordId && $convertRuleId) {
    // Fetch conversion rule
    $ruleStmt = $conn->prepare("SELECT * FROM {$prefix}module_conversion_rules WHERE id = ? AND source_module_id = ? AND target_module_id = ?");
    $ruleStmt->execute([$convertRuleId, $convertFromModuleId, $moduleId]);
    $rule = $ruleStmt->fetch(PDO::FETCH_ASSOC);
    if ($rule) {
        $mappings = json_decode($rule['field_mappings'], true) ?: [];
        
        // Fetch source record values
        $srcRecord = dm_fetch_record($conn, $prefix, $convertFromRecordId);
        if ($srcRecord) {
            foreach ($mappings as $targetFieldId => $sourceFieldId) {
                if (isset($srcRecord['values'][$sourceFieldId])) {
                    $prefilledValues[$targetFieldId] = $srcRecord['values'][$sourceFieldId];
                }
            }
        }
    }
}

// Fetch field change counts if editing an existing record
$fieldChangeCounts = [];
$fullRecordHistory = [];
if ($recordId) {
    // 1. Get counts
    $cntStmt = $conn->prepare("
        SELECT field_id, COUNT(*) as cnt 
        FROM {$prefix}module_record_history 
        WHERE record_id = ? 
        GROUP BY field_id
    ");
    $cntStmt->execute([$recordId]);
    $fieldChangeCounts = $cntStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // 2. Get full audit log
    $histStmt = $conn->prepare("
        SELECT h.*, u.username, u.first_name, u.last_name, f.label as field_label 
        FROM {$prefix}module_record_history h
        LEFT JOIN users u ON u.id = h.changed_by
        LEFT JOIN {$prefix}module_fields f ON f.id = h.field_id
        WHERE h.record_id = ?
        ORDER BY h.changed_at DESC
    ");
    $histStmt->execute([$recordId]);
    $fullRecordHistory = $histStmt->fetchAll(PDO::FETCH_ASSOC);
}

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
                <?php if($isViewOnly): ?>
                    <!-- Conversion actions -->
                    <?php
                    $convStmt = $conn->prepare("
                        SELECT c.*, tm.name as target_module_name 
                        FROM {$prefix}module_conversion_rules c 
                        JOIN {$prefix}modules tm ON tm.id = c.target_module_id 
                        WHERE c.source_module_id = ? AND c.status = 'active'
                    ");
                    $convStmt->execute([$moduleId]);
                    $convRules = $convStmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($convRules as $rule):
                    ?>
                        <a href="module_record.php?module=<?= $rule['target_module_id'] ?>&convert_from_module=<?= $moduleId ?>&convert_from_record=<?= $recordId ?>&convert_rule=<?= $rule['id'] ?>" class="btn-primary" style="width:auto;padding:12px 24px;background:var(--primary);color:white;text-decoration:none;display:inline-flex;align-items:center;gap:8px;">
                            <i class="fa-solid fa-arrows-spin"></i> <?= htmlspecialchars($rule['button_label']) ?>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <button class="btn-primary" style="width:auto;padding:12px 24px;" onclick="saveRecord()"><i class="fa-solid fa-check"></i> Save</button>
                <?php endif; ?>
            </div>
        </header>
        <div class="content-scroll">
            <div class="mr-form-container">
                <?php if ($isViewOnly): ?>
                    <!-- Prefilled conversion alert -->
                    <?php if (!empty($record['converted_from_module_id']) && !empty($record['converted_from_record_id'])): 
                        $srcMod = dm_fetch_module_full($conn, $prefix, $record['converted_from_module_id']);
                        if ($srcMod):
                    ?>
                        <div style="background:rgba(123,94,240,0.06); border:1px solid rgba(123,94,240,0.12); border-radius:12px; padding:12px 20px; margin-bottom:20px; font-weight:600; font-size:13px; display:flex; align-items:center; gap:8px; box-shadow:var(--shadow-sm);">
                            <i class="fa-solid fa-arrows-spin" style="color:var(--primary);"></i>
                            <span style="color:var(--text);">Converted from <?= htmlspecialchars($srcMod['name']) ?>:</span>
                            <a href="module_record.php?module=<?= $srcMod['id'] ?>&record=<?= $record['converted_from_record_id'] ?>&view=1" style="color:var(--primary); text-decoration:none;">Record #<?= $record['converted_from_record_id'] ?></a>
                        </div>
                    <?php endif; endif; ?>

                    <!-- Outgoing conversion alerts -->
                    <?php
                    $destStmt = $conn->prepare("
                        SELECT r.id, r.module_id, m.name as module_name 
                        FROM {$prefix}module_records r 
                        JOIN {$prefix}modules m ON m.id = r.module_id 
                        WHERE r.converted_from_module_id = ? AND r.converted_from_record_id = ?
                    ");
                    $destStmt->execute([$moduleId, $recordId]);
                    $destRecords = $destStmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($destRecords as $dest):
                    ?>
                        <div style="background:rgba(16,185,129,0.06); border:1px solid rgba(16,185,129,0.12); border-radius:12px; padding:12px 20px; margin-bottom:20px; font-weight:600; font-size:13px; display:flex; align-items:center; gap:8px; box-shadow:var(--shadow-sm);">
                            <i class="fa-solid fa-check-circle" style="color:#10b981;"></i>
                            <span style="color:var(--text);">Converted to <?= htmlspecialchars($dest['module_name']) ?>:</span>
                            <a href="module_record.php?module=<?= $dest['module_id'] ?>&record=<?= $dest['id'] ?>&view=1" style="color:#10b981; text-decoration:none;">Record #<?= $dest['id'] ?></a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($convertFromModuleId && $convertFromRecordId && $convertRuleId): 
                    $srcMod = dm_fetch_module_full($conn, $prefix, $convertFromModuleId);
                    if ($srcMod):
                ?>
                    <div style="background:rgba(123,94,240,0.08); border:1px solid rgba(123,94,240,0.2); border-radius:12px; padding:14px 20px; margin-bottom:20px; font-weight:600; font-size:14px; display:flex; align-items:center; gap:8px; box-shadow:var(--shadow-sm);">
                        <i class="fa-solid fa-info-circle" style="color:var(--primary); font-size:16px;"></i>
                        <span style="color:var(--text-main);">New Record pre-filled from converted <?= htmlspecialchars($srcMod['name']) ?>:</span>
                        <a href="module_record.php?module=<?= $convertFromModuleId ?>&record=<?= $convertFromRecordId ?>&view=1" target="_blank" style="color:var(--primary); text-decoration:underline;">Record #<?= $convertFromRecordId ?> <i class="fa-solid fa-up-right-from-square" style="font-size:10px;"></i></a>
                    </div>
                <?php endif; endif; ?>
                <?php foreach ($module['blocks'] as $block): ?>
                <div class="mr-block">
                    <div class="mr-block-header"><i class="fa-solid fa-layer-group"></i> <?= htmlspecialchars($block['name']) ?></div>
                    <div class="mr-block-body">
                        <div class="mr-field-grid">
                        <?php foreach ($block['fields'] as $field):
                            if (strpos($field['field_type'], 'sys_') === 0 && !$isViewOnly) {
                                continue;
                            }
                            $fid = $field['id'];
                            $val = $record['values'][$fid] ?? ($prefilledValues[$fid] ?? ($field['default_value'] ?? ''));
                            $fullWidth = in_array($field['field_type'], ['textarea', 'attachment', 'name']);
                            $req = $field['is_required'] ? '<span class="required-star">*</span>' : '';
                        ?>
                            <div class="mr-field-group <?= $fullWidth ? 'full-width' : '' ?>" id="field-wrap-<?= $fid ?>" data-field-id="<?= $fid ?>" data-field-type="<?= $field['field_type'] ?>" data-field-label="<?= htmlspecialchars($field['label']) ?>">
                                <label class="mr-field-label">
                                    <?= htmlspecialchars($field['label']) ?> <?= $req ?>
                                    <?php if (!empty($fieldChangeCounts[$fid])): ?>
                                        <span class="field-history-badge" onclick="openFieldHistory(<?= $fid ?>, '<?= htmlspecialchars($field['label']) ?>')" title="Click to view change history" style="cursor:pointer; margin-left:8px; font-size:11px; padding:2px 8px; background:rgba(123,94,240,0.1); color:var(--primary); border-radius:12px; font-weight:600; display:inline-flex; align-items:center; gap:4px;">
                                            <i class="fa-solid fa-clock-rotate-left"></i> <?= $fieldChangeCounts[$fid] ?> change<?= $fieldChangeCounts[$fid] > 1 ? 's' : '' ?>
                                        </span>
                                    <?php endif; ?>
                                </label>
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
                                        <?php 
                                            $cfg = $field['config'] ?? []; $linkedModId = $cfg['linked_module_id'] ?? 0;
                                            $displayTxt = '';
                                            if ($val) {
                                                // Find first text/name field as display field
                                                $dfStmt = $conn->prepare("SELECT id FROM {$prefix}module_fields WHERE module_id = ? AND field_type IN ('text','name','email') ORDER BY sort_order ASC LIMIT 1");
                                                $dfStmt->execute([$linkedModId]);
                                                $displayFieldId = $dfStmt->fetchColumn();
                                                if ($displayFieldId) {
                                                    $rvStmt = $conn->prepare("SELECT value FROM {$prefix}module_record_values WHERE record_id = ? AND field_id = ?");
                                                    $rvStmt->execute([$val, $displayFieldId]);
                                                    $dval = $rvStmt->fetchColumn();
                                                    if ($dval) $displayTxt = $dval . " (#$val)";
                                                }
                                                if (!$displayTxt) $displayTxt = "Record #$val";
                                            }
                                        ?>
                                        <div class="dm-api-picker-wrapper" data-field-id="<?= $fid ?>" data-linked-module="<?= $linkedModId ?>" style="position: relative;">
                                            <input type="hidden" class="dm-field dm-api-hidden" data-field-id="<?= $fid ?>" value="<?= htmlspecialchars($val) ?>" <?= $field['is_required'] ? 'required' : '' ?>>
                                            <div style="display:flex; align-items:center; gap:8px;">
                                                <div class="form-control" style="flex:1; cursor:pointer; background:var(--surface); display:flex; align-items:center; justify-content:space-between;" onclick="openRecordPickerModal(<?= $fid ?>, <?= $linkedModId ?>)">
                                                    <span id="api-display-<?= $fid ?>" style="color: <?= $val ? 'var(--text-main)' : 'var(--text-muted)' ?>; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                                        <?= $val ? htmlspecialchars($displayTxt) : "Search & Select..." ?>
                                                    </span>
                                                    <i class="fa-solid fa-chevron-down" style="color:var(--text-muted); font-size:12px; flex-shrink:0;"></i>
                                                </div>
                                                <?php if($val && !$isViewOnly): ?>
                                                <button type="button" class="mm-icon-btn rp-clear-btn" onclick="clearApiPicker(<?= $fid ?>)" style="color:#ef4444; flex-shrink:0;" title="Clear Selection"><i class="fa-solid fa-xmark"></i></button>
                                                <?php endif; ?>
                                            </div>
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

                <?php if ($recordId && !empty($fullRecordHistory)): ?>
                <!-- Record Audit Trail Card -->
                <div class="mr-block" style="margin-top: 28px;">
                    <div class="mr-block-header" style="display:flex; justify-content:space-between; align-items:center;">
                        <span><i class="fa-solid fa-timeline"></i> Record Audit Trail / Change Logs</span>
                        <span style="font-size:12px; font-weight:600; padding:2px 8px; background:rgba(123,94,240,0.1); color:var(--primary); border-radius:10px;">Total Updates: <?= count($fullRecordHistory) ?></span>
                    </div>
                    <div class="mr-block-body">
                        <div class="audit-timeline" style="position:relative; padding-left:24px; border-left:2px solid var(--border); margin: 10px 0;">
                            <?php foreach ($fullRecordHistory as $log): 
                                $displayName = trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? ''));
                                $userDisplay = $displayName ?: ($log['username'] ?? 'System/Unknown');
                                $dateDisplay = date('d M, Y H:i', strtotime($log['changed_at']));
                            ?>
                                <div class="timeline-item" style="position:relative; margin-bottom:20px;">
                                    <div class="timeline-dot" style="position:absolute; left:-31px; top:4px; width:12px; height:12px; border-radius:50%; background:var(--primary); border:2px solid #fff;"></div>
                                    <div class="timeline-meta" style="font-size:12px; color:var(--text-muted); margin-bottom:4px; display:flex; align-items:center; gap:8px;">
                                        <strong><?= htmlspecialchars($userDisplay) ?></strong>
                                        <span>•</span>
                                        <span><?= $dateDisplay ?></span>
                                    </div>
                                    <div class="timeline-content" style="font-size:14px; color:var(--text-main); background:#fcfcfd; padding:10px 14px; border-radius:8px; border:1px solid var(--border);">
                                        Updated field <span style="font-weight:600; color:var(--primary); cursor:pointer; text-decoration:underline;" onclick="openFieldHistory(<?= intval($log['field_id']) ?>, '<?= htmlspecialchars(addslashes($log['field_label'] ?: 'Unknown Field'), ENT_QUOTES) ?>')" title="Click to view history for this field"><?= htmlspecialchars($log['field_label'] ?: 'Unknown Field') ?></span>:
                                        <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-top:6px; font-size:13px;">
                                            <span style="text-decoration:line-through; color:#ef4444; background:rgba(239,68,68,0.08); padding:2px 6px; border-radius:4px; font-family:monospace;"><?= htmlspecialchars($log['old_value'] !== '' ? $log['old_value'] : '(empty)') ?></span>
                                            <i class="fa-solid fa-arrow-right" style="color:var(--text-muted); font-size:12px;"></i>
                                            <span style="color:#10b981; background:rgba(16,185,129,0.08); padding:2px 6px; border-radius:4px; font-weight:600; font-family:monospace;"><?= htmlspecialchars($log['new_value'] !== '' ? $log['new_value'] : '(empty)') ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<!-- Field History Modal -->
<div class="mm-modal-overlay" id="historyModal">
    <div class="mm-modal">
        <div class="mm-modal-header">
            <h3 id="historyModalTitle">Field Change History</h3>
            <button class="mm-icon-btn" onclick="closeModal('historyModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="mm-modal-body" style="padding:20px; max-height:400px; overflow-y:auto;">
            <div id="historyModalContent">
                <!-- Content loaded via Ajax -->
            </div>
        </div>
        <div class="mm-modal-footer">
            <button class="mm-btn mm-btn-primary" onclick="closeModal('historyModal')">Close</button>
        </div>
    </div>
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

<!-- Record Picker Modal -->
<style>
#recordPickerModal .mm-modal { border-radius: 16px; box-shadow: 0 20px 40px rgba(0,0,0,0.1); border: none; overflow: hidden; max-width: 600px; }
#recordPickerModal .mm-modal-header { border-bottom: none; padding: 24px 24px 16px 24px; display: flex; justify-content: space-between; align-items: center; }
#recordPickerModal .mm-modal-header h3 { margin: 0; font-size: 18px; font-weight: 600; color: var(--text-main); }
#recordPickerModal .mm-modal-header .mm-icon-btn { background: transparent; border: 1px solid var(--border); border-radius: 50%; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; color: var(--text-main); transition: all 0.2s; }
#recordPickerModal .mm-modal-header .mm-icon-btn:hover { background: var(--surface-hover); border-color: var(--text-muted); }
#recordPickerModal .rp-search-wrapper { position: relative; margin-bottom: 20px; }
#recordPickerModal .rp-search-wrapper i { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 16px; }
#recordPickerModal .rp-search-input { width: 100%; padding: 12px 16px 12px 42px; border-radius: 12px; border: 1.5px solid var(--primary); font-size: 15px; color: var(--text-main); outline: none; transition: box-shadow 0.2s; box-shadow: 0 0 0 3px rgba(123,94,240,0.1); }
#recordPickerModal .rp-search-input:focus { box-shadow: 0 0 0 4px rgba(123,94,240,0.15); }
#recordPickerModal .rp-list-section { font-size: 13px; font-weight: 500; color: var(--text-muted); margin-bottom: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
#recordPickerModal .rp-item { display: flex; align-items: center; padding: 12px 16px; margin: 0 -16px; border-radius: 8px; cursor: pointer; transition: background 0.2s; gap: 12px; }
#recordPickerModal .rp-item:hover { background: rgba(0,0,0,0.03); }
#recordPickerModal .rp-item-icon { width: 36px; height: 36px; border-radius: 50%; background: rgba(123,94,240,0.1); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 600; flex-shrink: 0; }
#recordPickerModal .rp-item-content { flex: 1; min-width: 0; }
#recordPickerModal .rp-item-title { font-size: 14px; font-weight: 500; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 2px; }
#recordPickerModal .rp-item-subtitle { font-size: 13px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
#recordPickerModal .rp-item-right { font-size: 13px; font-weight: 500; color: var(--text-main); flex-shrink: 0; }
#recordPickerModal .rp-pagination { display: flex; justify-content: space-between; align-items: center; margin-top: 15px; padding-top: 15px; border-top: 1px solid var(--border); }
</style>
<div class="mm-modal-overlay" id="recordPickerModal">
    <div class="mm-modal mm-modal-lg">
        <div class="mm-modal-header">
            <h3 id="recordPickerModalTitle">Select Record</h3>
            <button class="mm-icon-btn" onclick="closeModal('recordPickerModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="mm-modal-body" style="padding: 0 24px 24px 24px; min-height: 400px; display: flex; flex-direction: column;">
            <div class="rp-search-wrapper">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="recordPickerSearch" class="rp-search-input" placeholder="Search item..." oninput="debouncedSearchRecordPicker()">
            </div>
            
            <div id="recordPickerContent" style="flex: 1; overflow-y: auto; min-height: 250px;">
                <!-- Content via AJAX -->
            </div>
            
            <div class="rp-pagination" id="recordPickerPagination">
                <button class="mm-btn" id="rpPrevBtn" onclick="changeRecordPickerPage(-1)">Previous</button>
                <span id="rpPageInfo" style="font-size:13px; font-weight:600; color:var(--text-muted);">Page 1</span>
                <button class="mm-btn" id="rpNextBtn" onclick="changeRecordPickerPage(1)">Next</button>
            </div>
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

    const urlParams = new URLSearchParams(window.location.search);
    const convModule = urlParams.get('convert_from_module');
    const convRecord = urlParams.get('convert_from_record');
    if (convModule && convRecord) {
        formData.append('converted_from_module_id', convModule);
        formData.append('converted_from_record_id', convRecord);
    }

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
    
    let tsOptions = {
        dropdownParent: 'body',
        plugins: isMulti ? ['remove_button'] : [],
        sortField: { field: 'text', direction: 'asc' }
    };

    if (isDropdown && !isMulti) {
        tsOptions.create = function(input, callback) {
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
        };
    } else {
        tsOptions.create = false;
    }

    new TomSelect(el, tsOptions);
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

// Record Picker Modal logic
let currentPickerFieldId = null;
let currentPickerModuleId = null;
let currentPickerPage = 1;
let currentPickerQuery = '';
let searchTimeout = null;

function openRecordPickerModal(fieldId, linkedModuleId) {
    if (IS_VIEW_ONLY) return;
    currentPickerFieldId = fieldId;
    currentPickerModuleId = linkedModuleId;
    currentPickerPage = 1;
    currentPickerQuery = '';
    const searchInput = document.getElementById('recordPickerSearch');
    searchInput.value = '';
    document.getElementById('recordPickerModal').classList.add('show');
    setTimeout(() => searchInput.focus(), 100);
    loadRecordPickerData();
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

function loadRecordPickerData() {
    const contentDiv = document.getElementById('recordPickerContent');
    contentDiv.innerHTML = '<div style="text-align:center; padding:40px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin" style="font-size:24px; margin-bottom:10px; display:block;"></i> Loading...</div>';
    
    document.getElementById('rpPrevBtn').disabled = true;
    document.getElementById('rpNextBtn').disabled = true;

    fetch(`${API}?action=lookup_records&target_module_id=${currentPickerModuleId}&search=${encodeURIComponent(currentPickerQuery)}&page=${currentPickerPage}`)
    .then(r => r.json())
    .then(r => {
        if (!r.success) {
            contentDiv.innerHTML = `<div style="color:#ef4444; text-align:center; padding: 20px;">Error: ${r.error}</div>`;
            return;
        }

        if (r.records.length === 0) {
            contentDiv.innerHTML = '<div style="text-align:center; padding:40px; color:var(--text-muted); font-size: 14px;">No records found.</div>';
            document.getElementById('rpPageInfo').textContent = 'Page 1 of 1';
            return;
        }

        let html = '<div class="rp-list-section">Results</div>';
        html += '<div style="display:flex; flex-direction:column;">';
        r.records.forEach(rec => {
            const firstLetter = rec.display_value ? rec.display_value.charAt(0).toUpperCase() : '#';
            html += `
                <div class="rp-item" onclick="selectRecordFromPicker(${rec.id}, '${escapeHtml(rec.display_value)}')">
                    <div class="rp-item-icon">${firstLetter}</div>
                    <div class="rp-item-content">
                        <div class="rp-item-title">${escapeHtml(rec.display_value)}</div>
                        <div class="rp-item-subtitle">Record #${rec.id}</div>
                    </div>
                    <div class="rp-item-right">
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
    const hiddenInput = document.querySelector(`.dm-api-hidden[data-field-id="${currentPickerFieldId}"]`);
    if (hiddenInput) {
        hiddenInput.value = id;
        hiddenInput.dispatchEvent(new Event('change'));
    }
    
    const displaySpan = document.getElementById(`api-display-${currentPickerFieldId}`);
    if (displaySpan) {
        displaySpan.textContent = displayValue + ' (#' + id + ')';
        displaySpan.style.color = 'var(--text-main)';
    }

    const wrapper = document.querySelector(`.dm-api-picker-wrapper[data-field-id="${currentPickerFieldId}"]`);
    if (wrapper) {
        let clearBtn = wrapper.querySelector('.rp-clear-btn');
        if (!clearBtn) {
            const flexDiv = wrapper.querySelector('div[style*="display:flex; align-items:center"]');
            if (flexDiv) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'mm-icon-btn rp-clear-btn';
                btn.style.color = '#ef4444';
                btn.style.flexShrink = '0';
                btn.title = 'Clear Selection';
                btn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
                btn.onclick = () => clearApiPicker(currentPickerFieldId);
                flexDiv.appendChild(btn);
            }
        }
    }

    closeModal('recordPickerModal');
}

function clearApiPicker(fieldId) {
    const hiddenInput = document.querySelector(`.dm-api-hidden[data-field-id="${fieldId}"]`);
    if (hiddenInput) {
        hiddenInput.value = '';
        hiddenInput.dispatchEvent(new Event('change'));
    }
    
    const displaySpan = document.getElementById(`api-display-${fieldId}`);
    if (displaySpan) {
        displaySpan.textContent = 'Search & Select...';
        displaySpan.style.color = 'var(--text-muted)';
    }

    const wrapper = document.querySelector(`.dm-api-picker-wrapper[data-field-id="${fieldId}"]`);
    if (wrapper) {
        const clearBtn = wrapper.querySelector('.rp-clear-btn');
        if (clearBtn) clearBtn.remove();
    }
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

function openFieldHistory(fieldId, fieldLabel) {
    document.getElementById('historyModalTitle').innerHTML = `<i class="fa-clock-rotate-left fa-solid"></i> Change History: <b>${fieldLabel}</b>`;
    const contentDiv = document.getElementById('historyModalContent');
    contentDiv.innerHTML = '<div style="text-align:center; padding:20px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin" style="font-size:24px; margin-bottom:10px; display:block;"></i> Loading history logs...</div>';
    document.getElementById('historyModal').classList.add('show');

    fetch(API + `?action=get_record_history&record_id=${RECORD_ID}&field_id=${fieldId}`)
    .then(r => r.json())
    .then(r => {
        if (!r.success) {
            contentDiv.innerHTML = `<div style="color:#ef4444; text-align:center;">Error: ${r.error || 'Failed to load'}</div>`;
            return;
        }
        if (!r.history || r.history.length === 0) {
            contentDiv.innerHTML = '<div style="text-align:center; padding:20px; color:var(--text-muted);">No changes logged for this field.</div>';
            return;
        }
        
        let html = '<div style="display:flex; flex-direction:column; gap:16px;">';
        r.history.forEach(log => {
            const oldVal = log.old_value !== '' ? log.old_value : '(empty)';
            const newVal = log.new_value !== '' ? log.new_value : '(empty)';
            html += `
                <div style="border-bottom:1px solid var(--border); padding-bottom:12px;">
                    <div style="font-size:12px; color:var(--text-muted); margin-bottom:4px; display:flex; justify-content:space-between;">
                        <strong>${log.user_display}</strong>
                        <span>${log.date_display}</span>
                    </div>
                    <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center; font-size:13px; margin-top:6px;">
                        <span style="text-decoration:line-through; color:#ef4444; background:rgba(239,68,68,0.08); padding:2px 6px; border-radius:4px; font-family:monospace;">${escapeHtml(oldVal)}</span>
                        <i class="fa-solid fa-arrow-right" style="color:var(--text-muted); font-size:11px;"></i>
                        <span style="color:#10b981; background:rgba(16,185,129,0.08); padding:2px 6px; border-radius:4px; font-weight:600; font-family:monospace;">${escapeHtml(newVal)}</span>
                    </div>
                </div>
            `;
        });
        html += '</div>';
        contentDiv.innerHTML = html;
    })
    .catch(err => {
        contentDiv.innerHTML = `<div style="color:#ef4444; text-align:center;">Network error occurred.</div>`;
    });
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
</script>
</body>
</html>
