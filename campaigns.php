<?php
require_once 'auth_check.php';
require_once 'includes/commerce.php';
require_once 'includes/brand.php';
require_once 'includes/dynamic_modules.php';

$context = commerce_get_tenant_context();
$conn = $context['conn'];
$prefix = $context['prefix'];

// Ensure campaign tables are present
dm_ensure_tables($conn, $prefix);

$commConfigs = [];
try {
    $stmt = $conn->query("SELECT id, name, type, is_default FROM {$prefix}communication_configs ORDER BY type ASC, name ASC");
    $commConfigs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$activeModules = [];
try {
    $mStmt = $conn->query("SELECT id, name FROM {$prefix}modules WHERE status = 'active' ORDER BY name ASC");
    $allActiveModules = $mStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allActiveModules as $m) {
        $mappingKey = "campaign_mapping_" . $m['id'];
        $val = dm_get_system_setting($conn, $prefix, $mappingKey, null);
        if ($val) {
            $activeModules[] = [
                'id' => $m['id'],
                'name' => $m['name'],
                'mapping' => json_decode($val, true)
            ];
        }
    }
} catch (Exception $e) {}

// Load campaign custom fields
$campaignCustomFields = [];
try {
    $cfStmt = $conn->query("SELECT * FROM {$prefix}campaign_fields ORDER BY sort_order ASC, id ASC");
    $campaignCustomFields = $cfStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($campaignCustomFields as &$cf) {
        $cf['options'] = !empty($cf['options']) ? json_decode($cf['options'], true) : [];
        $cf['is_required'] = (int)$cf['is_required'];
    }
    unset($cf);
} catch (Exception $e) {}

$usersList = [];
try {
    $usersQuery = $conn->query("SELECT id, username, first_name, last_name FROM {$prefix}users ORDER BY username ASC");
    while ($u = $usersQuery->fetch(PDO::FETCH_ASSOC)) {
        $fullName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
        $usersList[$u['id']] = $fullName ?: $u['username'];
    }
} catch (Exception $e) {}

$v = time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(brand_page_title('Campaigns Manager')) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <link href="/assets/css/module_manager.css?v=<?= $v ?>" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="/assets/js/toast.js?v=<?= $v ?>"></script>
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px;
            text-align: center;
            box-shadow: var(--shadow-sm);
        }
        .stat-val {
            font-size: 24px;
            font-weight: 700;
            margin-top: 8px;
            color: var(--text);
        }
        .stat-label {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 600;
        }
        .csv-drop-zone {
            border: 2px dashed var(--primary);
            background: rgba(123, 94, 240, 0.02);
            border-radius: 12px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            margin-bottom: 20px;
        }
        .csv-drop-zone:hover {
            background: rgba(123, 94, 240, 0.06);
        }
        .csv-drop-zone i {
            font-size: 32px;
            color: var(--primary);
            margin-bottom: 12px;
        }
        .progress-bar-container {
            width: 100%;
            height: 12px;
            background: var(--border);
            border-radius: 6px;
            overflow: hidden;
            margin: 15px 0;
            display: none;
        }
        .progress-bar-fill {
            height: 100%;
            background: var(--primary);
            width: 0%;
            transition: width 0.2s;
        }
        .sending-logs {
            max-height: 200px;
            overflow-y: auto;
            background: #f9fafb;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 12px;
            font-family: monospace;
            font-size: 12px;
            display: none;
            margin-top: 15px;
        }
        .log-item {
            margin-bottom: 4px;
            display: flex;
            justify-content: space-between;
        }
        .log-success { color: #10b981; }
        .log-failed { color: #ef4444; }
    </style>
</head>
<body>
    <div class="app-wrapper">
        <?php include 'includes/sidebar.php'; ?>
        <main class="main-content">
            <header class="topbar">
                <div class="breadcrumb">Campaigns / <span class="current">Campaigns</span></div>
                <div class="topbar-right">
                    <button class="btn-primary" style="width:auto;padding:12px 24px;" onclick="openCreateModal()">
                        <i class="fa-solid fa-bullhorn"></i> Start Campaign
                    </button>
                </div>
            </header>

            <div class="content-scroll" id="campaignsDashboard">
                <!-- Campaigns Listing -->
                <div class="crm-card" style="padding: 24px; text-align: left;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3 style="margin: 0; font-size: 18px; font-weight: 700; color: var(--text-main);">My Campaigns</h3>
                    </div>

                    <div class="table-responsive">
                        <table class="crm-table" style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: rgba(0,0,0,0.02); border-bottom: 1px solid var(--border);">
                                    <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: var(--text-muted); font-size: 13px;">Campaign Name</th>
                                    <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: var(--text-muted); font-size: 13px;">Channel</th>
                                    <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: var(--text-muted); font-size: 13px;">Template</th>
                                    <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: var(--text-muted); font-size: 13px;">Progress / Stats</th>
                                    <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: var(--text-muted); font-size: 13px;">Status</th>
                                    <th style="padding: 12px 16px; text-align: right; font-weight: 600; color: var(--text-muted); font-size: 13px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="campaignsListBody">
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 30px; color: var(--text-muted);">Loading campaigns...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Campaign Details View Panel -->
            <div class="content-scroll" id="campaignDetailsPanel" style="display: none;">
                <div style="margin-bottom: 15px;">
                    <a href="javascript:void(0)" onclick="showDashboard()" style="color: var(--primary); font-weight: 600; font-size: 14px;"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a>
                </div>

                <div class="crm-card" style="padding: 24px; margin-bottom: 24px; text-align: left;">
                    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 15px; margin-bottom: 20px;">
                        <div>
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <h3 id="detailName" style="margin: 0; font-size: 20px; font-weight: 700;">Campaign Name</h3>
                                <button id="btnEditCampaign" class="mm-btn mm-btn-outline" style="padding: 4px 10px; font-size: 12px; height: 28px; display: inline-flex; align-items: center; gap: 4px;" onclick="openEditModal()"><i class="fa-solid fa-pen"></i> Edit Details</button>
                            </div>
                            <div id="detailMeta" style="font-size: 12px; color: var(--text-muted); margin-top: 4px;">Channel: Email | Template: Welcome</div>
                        </div>
                        <div id="campaignStatusBadge">
                            <span class="status-badge" style="background:#e0f2fe; color:#0369a1;">Completed</span>
                        </div>
                    </div>

                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-label">TOTAL CONTACTS</div>
                            <div class="stat-val" id="statTotal">0</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">SENT SUCCESSFULLY</div>
                            <div class="stat-val" id="statSent" style="color: #10b981;">0</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">FAILED DELIVERIES</div>
                            <div class="stat-val" id="statFailed" style="color: #ef4444;">0</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">PENDING QUEUE</div>
                            <div class="stat-val" id="statPending" style="color: #f59e0b;">0</div>
                        </div>
                    </div>

                    <!-- Sending Control -->
                    <div id="sendWizardSection" style="background: rgba(123, 94, 240, 0.04); border: 1px solid rgba(123, 94, 240, 0.12); padding: 20px; border-radius: 12px; margin-bottom: 20px; display: none;">
                        <h4 style="margin: 0 0 6px 0; font-size: 15px; font-weight: 700; color: var(--primary);">Execute Message Sending</h4>
                        <p style="margin: 0 0 15px 0; font-size: 13px; color: var(--text-muted);">Send personalized templates in progressive real-time batches to avoid request timeout.</p>
                        
                        <div id="campaignStatusText" style="font-weight: 700; font-size: 14px; margin-bottom: 6px;">Status: Ready</div>
                        <div class="progress-bar-container" id="progressBarBox">
                            <div class="progress-bar-fill" id="progressBar"></div>
                        </div>

                        <div style="display: flex; gap: 10px; margin-top: 15px;">
                            <button class="mm-btn mm-btn-primary" id="btnStartSend" onclick="startCampaignSending()"><i class="fa-solid fa-paper-plane"></i> Launch Sending Campaign</button>
                            <button class="mm-btn mm-btn-outline mm-btn-danger" id="btnStopCampaign" style="display: none;" onclick="stopCampaignSending()"><i class="fa-solid fa-stop"></i> Pause Sending</button>
                            <button class="mm-btn mm-btn-outline" id="btnRetryFailed" style="display: none;" onclick="retryFailedCampaign()"><i class="fa-solid fa-arrows-rotate"></i> Reset & Retry Failed</button>
                        </div>

                        <div class="sending-logs" id="sendingLogsBox"></div>
                    </div>

                    <!-- Import section for draft campaigns -->
                    <div id="importRecipientsSection" style="display: none;">
                        <div style="border-top: 1px solid var(--border); padding-top: 20px; margin-top: 20px;">
                            <h4 style="margin: 0 0 15px 0; font-size: 15px; font-weight: 700;">Import Campaign Contacts</h4>
                            
                            <!-- Tab Switcher -->
                            <div style="display: flex; gap: 15px; border-bottom: 2px solid var(--border); margin-bottom: 20px;">
                                <button type="button" class="tab-btn active" id="btnTabUpload" onclick="switchImportTab('upload')" style="background:none; border:none; padding:10px 15px; font-size:14px; font-weight:600; cursor:pointer; color:var(--primary); border-bottom:2px solid var(--primary); margin-bottom:-2px; outline:none;">
                                    <i class="fa-solid fa-file-excel"></i> CSV / Excel Upload
                                </button>
                                <button type="button" class="tab-btn" id="btnTabManual" onclick="switchImportTab('manual')" style="background:none; border:none; padding:10px 15px; font-size:14px; font-weight:600; cursor:pointer; color:var(--text-muted); border-bottom:2px solid transparent; margin-bottom:-2px; outline:none;">
                                    <i class="fa-solid fa-keyboard"></i> Manual Entry
                                </button>
                                <?php foreach ($activeModules as $mm): ?>
                                <button type="button" class="tab-btn btn-tab-mapped-module" id="btnTabModule_<?= $mm['id'] ?>" onclick="switchImportTab('module_<?= $mm['id'] ?>')" style="background:none; border:none; padding:10px 15px; font-size:14px; font-weight:600; cursor:pointer; color:var(--text-muted); border-bottom:2px solid transparent; margin-bottom:-2px; outline:none;">
                                    <i class="fa-solid fa-database"></i> <?= htmlspecialchars($mm['name']) ?>
                                </button>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- CSV Upload Content -->
                            <div id="tabContentUpload">
                                <p style="margin: 0 0 15px 0; font-size: 13px; color: var(--text-muted);">Upload contacts via a CSV or Excel (.xls, .xlsx) file containing columns: <strong>First Name, Last Name, Email, Phone, Company Name, Designation</strong></p>
                                <div class="csv-drop-zone" onclick="document.getElementById('csvFileInput').click()">
                                    <i class="fa-solid fa-file-excel"></i>
                                    <h4 style="margin: 0 0 4px 0;">Click or Drag CSV or Excel File Here</h4>
                                    <span style="font-size:12px; color:var(--text-muted);">CSV, XLS, and XLSX files are supported</span>
                                    <input type="file" id="csvFileInput" accept=".csv,.xls,.xlsx" style="display: none;" onchange="handleCsvFileSelected(event)">
                                </div>
                            </div>
                            
                            <!-- Manual Entry Content -->
                            <div id="tabContentManual" style="display:none; background: rgba(123, 94, 240, 0.02); border: 1px solid var(--border); border-radius: 12px; padding: 20px; margin-bottom: 20px;">
                                <h5 style="margin: 0 0 12px 0; font-size: 14px; font-weight: 700; color: var(--primary);">Add Contact Manually</h5>
                                <?php if (empty($campaignCustomFields)): ?>
                                <div style="text-align:center; padding: 20px; color: var(--text-muted); font-size: 13px; background: rgba(123,94,240,0.04); border-radius: 10px; margin-bottom: 15px;">
                                    <i class="fa-solid fa-sliders" style="font-size: 24px; margin-bottom: 8px; display:block; opacity:0.4;"></i>
                                    No fields configured yet. Go to <strong>Module Manager → Bulk Campaigns → Configure Fields</strong> to add fields first.
                                </div>
                                <?php else: ?>
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 15px;">
                                    <?php foreach ($campaignCustomFields as $cf): ?>
                                    <div class="form-group" style="margin:0; display:flex; flex-direction:column; gap:6px;">
                                        <label class="form-label" style="font-size:11px; margin-bottom:4px; font-weight:700;">
                                            <?= htmlspecialchars($cf['label']) ?><?= $cf['is_required'] ? ' <span style="color:#ef4444;">*</span>' : '' ?>
                                        </label>
                                        <?php if ($cf['field_type'] === 'textarea'): ?>
                                        <textarea id="manual_cf_<?= htmlspecialchars($cf['field_key']) ?>" class="form-control" style="padding:6px 12px; font-size:13px; background:#fff; height:60px; resize:vertical;" placeholder="<?= htmlspecialchars($cf['placeholder'] ?? '') ?>"></textarea>
                                        <?php elseif ($cf['field_type'] === 'select' && !empty($cf['options'])): ?>
                                        <select id="manual_cf_<?= htmlspecialchars($cf['field_key']) ?>" class="form-control" style="height:36px; padding:6px 12px; font-size:13px; background:#fff;">
                                            <option value="">-- Select <?= htmlspecialchars($cf['label']) ?> --</option>
                                            <?php foreach ($cf['options'] as $opt): ?>
                                            <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php else: ?>
                                        <input type="<?= htmlspecialchars($cf['field_type'] === 'phone' ? 'tel' : $cf['field_type']) ?>"
                                               id="manual_cf_<?= htmlspecialchars($cf['field_key']) ?>"
                                               class="form-control"
                                               style="height:36px; padding:6px 12px; font-size:13px; background:#fff;"
                                               placeholder="<?= htmlspecialchars($cf['placeholder'] ?? 'e.g. ' . $cf['label']) ?>">
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <button class="mm-btn mm-btn-primary" style="height:36px; padding:0 16px; font-size:13px;" onclick="addManualContact()"><i class="fa-solid fa-plus"></i> Add to list</button>
                                <?php endif; ?>
                            </div>
                            
                            <?php foreach ($activeModules as $mm): ?>
                            <!-- Module Tab Content: <?= htmlspecialchars($mm['name']) ?> -->
                            <div id="tabContentModule_<?= $mm['id'] ?>" class="tab-content-mapped-module" style="display:none; background: rgba(123, 94, 240, 0.02); border: 1px solid var(--border); border-radius: 12px; padding: 20px; margin-bottom: 20px;">
                                <h5 style="margin: 0 0 12px 0; font-size: 14px; font-weight: 700; color: var(--primary);">Link <?= htmlspecialchars($mm['name']) ?> Records</h5>
                                
                                <!-- Saved Filters + Search Bar -->
                                <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 12px; flex-wrap: wrap;">
                                    <button class="mm-btn mm-btn-sm mm-btn-outline" id="btnCampaignFiltersToggle_<?= $mm['id'] ?>" onclick="toggleCampaignFilterPanel(<?= $mm['id'] ?>)" style="display:inline-flex;align-items:center;gap:6px; height: 32px; padding: 4px 10px; border-radius: 8px;">
                                        <i class="fa-solid fa-filter"></i> Filters <span id="campaignFiltersActiveCount_<?= $mm['id'] ?>"></span>
                                    </button>
                                    <input type="text" id="importRecordsSearch_<?= $mm['id'] ?>" class="form-control" placeholder="Search records..." style="flex:1; min-width:150px; height:32px; font-size:12px; padding:4px 10px; background:#fff; box-sizing:border-box;" oninput="debounceRecordSearch(<?= $mm['id'] ?>)">
                                </div>
                                
                                <!-- Filters Configuring Expanding Panel -->
                                <div id="campaignFilterPanel_<?= $mm['id'] ?>" class="crm-card" style="margin-bottom: 20px; padding: 20px; display: none; background:#fff; border: 1px solid var(--border); box-shadow: var(--shadow-sm);">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px solid var(--border); padding-bottom: 10px; flex-wrap: wrap; gap: 10px;">
                                        <h4 style="margin: 0; color: var(--text-main); font-weight: 700; display: flex; align-items: center; gap: 8px; font-size:13px;">
                                            <i class="fa-solid fa-filter" style="color: var(--primary);"></i> Configure Filters
                                        </h4>
                                        <div style="display: flex; gap: 10px; align-items: center;">
                                            <select id="savedFilterSelect_<?= $mm['id'] ?>" class="form-control" style="width: 200px; padding: 4px 10px; font-size: 12px; border-radius: 8px; border: 1.5px solid var(--border); height: 32px; background:#fff;" onchange="applySavedFilter(<?= $mm['id'] ?>)">
                                                <option value="">-- Apply Saved Filter --</option>
                                            </select>
                                            <button class="mm-btn mm-btn-sm mm-btn-outline mm-btn-danger" id="btnDeleteCampaignFilter_<?= $mm['id'] ?>" style="display: none; align-items: center; gap: 4px; height: 32px; padding: 4px 10px; border-radius:8px;" onclick="deleteCampaignActiveFilter(<?= $mm['id'] ?>)">
                                                <i class="fa-solid fa-trash"></i> Delete
                                            </button>
                                        </div>
                                    </div>

                                    <div id="campaignFilterRulesContainer_<?= $mm['id'] ?>" style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 20px;">
                                        <!-- Dynamic rules rows -->
                                    </div>

                                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                                        <div style="display: flex; gap: 10px;">
                                            <button class="mm-btn mm-btn-sm mm-btn-outline" style="border-radius: 8px; font-size:12px; padding: 6px 12px;" onclick="createCampaignFilterRuleRow(<?= $mm['id'] ?>)">
                                                <i class="fa-solid fa-plus"></i> Add Condition
                                            </button>
                                            <button class="mm-btn mm-btn-sm mm-btn-outline" style="border-radius: 8px; font-size:12px; padding: 6px 12px;" onclick="clearAllCampaignFilters(<?= $mm['id'] ?>)">
                                                <i class="fa-solid fa-rotate-right"></i> Reset
                                            </button>
                                        </div>
                                        
                                        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                            <div style="display: flex; gap: 8px; align-items: center;">
                                                <input type="text" id="campaignFilterSaveName_<?= $mm['id'] ?>" placeholder="Filter Name..." class="form-control" style="width: 150px; height: 32px; font-size: 12px; border-radius: 8px; border: 1.5px solid var(--border); padding: 4px 10px; background:#fff;">
                                                <label style="display: flex; align-items: center; gap: 4px; font-size: 11px; cursor: pointer; color: var(--text-muted); font-weight: 600; user-select: none; margin:0;">
                                                    <input type="checkbox" id="campaignFilterSetDefault_<?= $mm['id'] ?>" style="accent-color: var(--primary); width: 14px; height: 14px; cursor:pointer;"> Default
                                                </label>
                                                <button class="mm-btn mm-btn-sm mm-btn-outline" style="border-radius: 8px; font-size:12px; padding: 6px 12px;" onclick="saveCurrentCampaignFilter(<?= $mm['id'] ?>)">
                                                    <i class="fa-solid fa-floppy-disk"></i> Save & Apply
                                                </button>
                                            </div>
                                            <button class="mm-btn mm-btn-sm" style="background: var(--primary); color: #fff; border-radius: 8px; font-size:12px; padding: 6px 16px;" onclick="applyCurrentCampaignFilters(<?= $mm['id'] ?>)">
                                                <i class="fa-solid fa-check"></i> Apply Only
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="table-responsive" style="max-height: 350px; overflow-y: auto; border: 1px solid var(--border); border-radius: 8px; margin-bottom: 15px; background: #fff;">
                                    <table class="crm-table" style="font-size: 12px; margin: 0; width: 100%;">
                                        <thead>
                                            <tr id="moduleRecordsHeader_<?= $mm['id'] ?>">
                                                <th style="width: 30px; text-align: center;"><input type="checkbox" id="selectAllModuleRecords_<?= $mm['id'] ?>" onchange="toggleSelectAllModuleRecords(<?= $mm['id'] ?>, this.checked)"></th>
                                                <th>Loading...</th>
                                            </tr>
                                        </thead>
                                        <tbody id="moduleRecordsImportBody_<?= $mm['id'] ?>">
                                            <tr><td colspan="10" style="text-align: center; padding: 20px; color: var(--text-muted);">Loading records...</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <button class="mm-btn mm-btn-primary" onclick="importSelectedModuleRecords(<?= $mm['id'] ?>)"><i class="fa-solid fa-file-import"></i> Import Selected Records</button>
                            </div>
                            <?php endforeach; ?>

                            <div id="csvPreviewSection" style="display: none;">
                                <h4 style="margin: 15px 0 8px 0; font-size: 13px; font-weight: 700; color: var(--text);">Contacts List Preview (<span id="previewCount">0</span> rows detected)</h4>
                                <div class="table-responsive" style="max-height: 250px; overflow-y: auto; border: 1px solid var(--border); border-radius: 8px; margin-bottom: 15px;">
                                    <table class="crm-table" style="font-size: 12px; margin: 0; width: 100%;">
                                        <thead>
                                            <tr id="csvPreviewHeader">
                                                <?php foreach ($campaignCustomFields as $cf): ?>
                                                <th><?= htmlspecialchars($cf['label']) ?></th>
                                                <?php endforeach; ?>
                                                <th style="text-align:right; position: sticky; right: 0; background: #fafafa; z-index: 10; box-shadow: -2px 0 5px rgba(0,0,0,0.04);">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="csvPreviewBody"></tbody>
                                    </table>
                                </div>
                                <button class="mm-btn mm-btn-primary" onclick="submitImportedRecipients()"><i class="fa-solid fa-file-import"></i> Save & Import Contacts</button>
                            </div>
                        </div>
                    </div>

                    <!-- Recipients list -->
                    <div style="border-top: 1px solid var(--border); padding-top: 20px; margin-top: 20px;">
                        <h4 style="margin: 0 0 15px 0; font-size: 15px; font-weight: 700;">Recipients Log</h4>
                        <div class="table-responsive">
                            <table class="crm-table" style="width: 100%;">
                                <thead>
                                    <tr id="recipientsLogHeader">
                                        <?php foreach ($campaignCustomFields as $cf): ?>
                                        <th><?= htmlspecialchars($cf['label']) ?></th>
                                        <?php endforeach; ?>
                                        <th>Status</th>
                                        <th>Log Message / Sent At</th>
                                    </tr>
                                </thead>
                                <tbody id="recipientsListBody">
                                    <tr>
                                        <td colspan="<?= 2 + count($campaignCustomFields) ?>" style="text-align: center; padding: 20px; color: var(--text-muted);">No recipients registered yet. Please upload contacts above.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Create Campaign Modal -->
    <div class="mm-modal-overlay" id="createCampaignModal">
        <div class="mm-modal">
            <div class="mm-modal-header">
                <h3>Start New Campaign</h3>
                <button class="mm-icon-btn" onclick="closeModal('createCampaignModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="mm-modal-body">
                <div class="form-group" style="display: flex; flex-direction: column; gap: 6px;">
                    <label class="form-label">Campaign Name *</label>
                    <input type="text" id="campaignName" class="form-control" placeholder="e.g. Q3 Lead Email Blast, WhatsApp Greeting" style="width: 100%; box-sizing: border-box;">
                </div>
                
                <div class="form-group" style="display: flex; flex-direction: column; gap: 6px; margin-top: 15px;">
                    <label class="form-label">Channel Mode *</label>
                    <select id="campaignType" class="form-control" style="width: 100%;" onchange="onCampaignTypeChange()">
                        <option value="email">Email Campaign</option>
                        <option value="whatsapp">WhatsApp Campaign</option>
                    </select>
                </div>

                <div class="form-group" style="display: flex; flex-direction: column; gap: 6px; margin-top: 15px;">
                    <label class="form-label">Message Template *</label>
                    <select id="campaignTemplateSelect" class="form-control" style="width: 100%;">
                        <option value="">-- Select Template --</option>
                    </select>
                </div>

                <div class="form-group" style="display: flex; flex-direction: column; gap: 6px; margin-top: 15px;">
                    <label class="form-label">Send From Config</label>
                    <select id="campaignCommConfig" class="form-control" style="width: 100%;">
                        <option value="">-- Use Default --</option>
                    </select>
                </div>

                <div class="form-group" style="display: flex; flex-direction: column; gap: 6px; margin-top: 15px;">
                    <label class="form-label">Send Mode *</label>
                    <select id="campaignSendMode" class="form-control" style="width: 100%;" onchange="onCampaignSendModeChange()">
                        <option value="immediate">Send Immediately</option>
                        <option value="schedule">Schedule for Later</option>
                    </select>
                </div>

                <div class="form-group" id="scheduleTimeGroup" style="display: none; flex-direction: column; gap: 6px; margin-top: 15px;">
                    <label class="form-label">Schedule Date & Time (5 Min Intervals) *</label>
                    <input type="text" id="campaignScheduledAt" class="form-control" placeholder="Select date & time..." style="width: 100%; box-sizing: border-box;">
                </div>

                <div class="form-group" style="display: flex; flex-direction: column; gap: 6px; margin-top: 15px;">
                    <label class="form-label">Delay Between Messages</label>
                    <select id="campaignSendDelay" class="form-control" style="width: 100%;">
                        <option value="0">No Delay</option>
                        <option value="1">1 Second</option>
                        <option value="2">2 Seconds</option>
                        <option value="5">5 Seconds</option>
                        <option value="10">10 Seconds</option>
                        <option value="15">15 Seconds</option>
                        <option value="30">30 Seconds</option>
                        <option value="60">1 Minute</option>
                        <option value="120">2 Minutes</option>
                        <option value="300">5 Minutes</option>
                        <option value="600">10 Minutes</option>
                        <option value="900">15 Minutes</option>
                        <option value="1200">20 Minutes</option>
                        <option value="1800">30 Minutes</option>
                    </select>
                </div>
            </div>
            <div class="mm-modal-footer">
                <button class="mm-btn" onclick="closeModal('createCampaignModal')">Cancel</button>
                <button class="mm-btn mm-btn-primary" onclick="createCampaign()"><i class="fa-solid fa-check"></i> Create Campaign</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        const API = 'api/campaigns_api.php';
        const commConfigs = <?= json_encode($commConfigs) ?>;
        const campaignCustomFields = <?= json_encode($campaignCustomFields) ?>;
        let campaignsList = [];
        let templatesList = [];
        let activeCampaign = null;
        let parsedRecipients = [];
        
        let isSending = false;
        let stopRequested = false;

        function openModal(id) { document.getElementById(id).classList.add('show'); }
        function closeModal(id) { document.getElementById(id).classList.remove('show'); }

        async function initPage() {
            await loadCampaigns();
            await fetchTemplates();
        }

        async function fetchTemplates() {
            try {
                const res = await fetch(`${API}?action=list_templates`);
                const data = await res.json();
                if (data.success) {
                    templatesList = data.templates;
                }
            } catch(e) {}
        }

        async function loadCampaigns() {
            try {
                const res = await fetch(`${API}?action=list_campaigns`);
                const data = await res.json();
                if (data.success) {
                    campaignsList = data.campaigns;
                    renderCampaignsTable();
                } else {
                    vyToast(data.error || 'Failed to load campaigns', 'error');
                }
            } catch(e) {
                vyToast('Connection error: ' + e.message, 'error');
            }
        }

        function renderCampaignsTable() {
            const tbody = document.getElementById('campaignsListBody');
            if (!tbody) return;

            if (campaignsList.length === 0) {
                tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:30px; color:var(--text-muted);">No campaigns configured. Click "Start Campaign" to launch!</td></tr>`;
                return;
            }

            tbody.innerHTML = campaignsList.map(c => {
                const chan = c.type === 'email' 
                    ? `<span class="mm-badge" style="background:rgba(59,130,246,0.1); color:#3b82f6;"><i class="fa-solid fa-envelope"></i> Email</span>`
                    : `<span class="mm-badge" style="background:rgba(16,185,129,0.1); color:#10b981;"><i class="fa-solid fa-message"></i> WhatsApp</span>`;

                let statText = '-';
                if (parseInt(c.total_recipients) > 0) {
                    statText = `<span style="font-weight:700;">${c.sent_count}</span> sent / <span style="font-weight:700; color:#ef4444;">${c.failed_count}</span> failed (${c.total_recipients} total)`;
                }

                let statusBadge = '';
                if (c.status === 'draft') statusBadge = '<span class="status-badge" style="background:rgba(107,114,128,0.1); color:#6b7280; padding:4px 8px; border-radius:4px; font-size:11px; font-weight:700;">Draft</span>';
                else if (c.status === 'scheduled') {
                    const timeStr = c.scheduled_at ? `${formatVyDate(c.scheduled_at)} ${formatVyTime(c.scheduled_at)}` : 'Scheduled';
                    statusBadge = `<span class="status-badge" style="background:rgba(59,130,246,0.1); color:#3b82f6; padding:4px 8px; border-radius:4px; font-size:11px; font-weight:700;" title="Scheduled at ${timeStr}"><i class="fa-solid fa-clock"></i> Scheduled</span>`;
                }
                else if (c.status === 'sending') statusBadge = '<span class="status-badge" style="background:rgba(245,158,11,0.1); color:#f59e0b; padding:4px 8px; border-radius:4px; font-size:11px; font-weight:700; animation:pulse 1.5s infinite;">Sending</span>';
                else if (c.status === 'completed') statusBadge = '<span class="status-badge" style="background:rgba(16,185,129,0.1); color:#10b981; padding:4px 8px; border-radius:4px; font-size:11px; font-weight:700;">Completed</span>';
                else statusBadge = '<span class="status-badge" style="background:rgba(239,68,68,0.1); color:#ef4444; padding:4px 8px; border-radius:4px; font-size:11px; font-weight:700;">Failed</span>';

                return `
                    <tr>
                        <td style="padding:12px 16px; border-bottom:1px solid var(--border);"><strong>${escapeHtml(c.name)}</strong></td>
                        <td style="padding:12px 16px; border-bottom:1px solid var(--border);">${chan}</td>
                        <td style="padding:12px 16px; border-bottom:1px solid var(--border);">${escapeHtml(c.template_name || '-')}</td>
                        <td style="padding:12px 16px; border-bottom:1px solid var(--border); font-size:12px;">${statText}</td>
                        <td style="padding:12px 16px; border-bottom:1px solid var(--border);">${statusBadge}</td>
                        <td style="padding:12px 16px; border-bottom:1px solid var(--border); text-align:right;">
                            <div style="display:inline-flex; gap:6px;">
                                <button class="mm-btn mm-btn-sm" onclick="viewCampaign(${c.id})"><i class="fa-solid fa-eye"></i> Manage</button>
                                <button class="mm-icon-btn mm-icon-danger" onclick="deleteCampaign(${c.id})" title="Delete"><i class="fa-solid fa-trash"></i></button>
                            </div>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function onCampaignTypeChange() {
            const type = document.getElementById('campaignType').value;
            const select = document.getElementById('campaignTemplateSelect');
            select.innerHTML = '<option value="">-- Select Template --</option>';
            
            const filtered = templatesList.filter(t => t.type === type);
            filtered.forEach(t => {
                const opt = document.createElement('option');
                opt.value = t.id;
                opt.textContent = t.name;
                select.appendChild(opt);
            });
            
            const configSelect = document.getElementById('campaignCommConfig');
            configSelect.innerHTML = '<option value="">-- Use Default --</option>';
            const configType = type === 'email' ? 'smtp' : (type === 'whatsapp' ? 'whatsapp' : '');
            if (configType) {
                commConfigs.filter(c => c.type === configType).forEach(c => {
                    configSelect.innerHTML += `<option value="${c.id}">${escapeHtml(c.name)}${c.is_default == 1 ? ' (Default)' : ''}</option>`;
                });
            }
        }

        function openCreateModal() {
            const modalEl = document.getElementById('createCampaignModal');
            modalEl.querySelector('h3').textContent = 'Start New Campaign';
            const saveBtn = modalEl.querySelector('.mm-modal-footer button.mm-btn-primary');
            saveBtn.innerHTML = '<i class="fa-solid fa-check"></i> Create Campaign';
            modalEl.removeAttribute('data-campaign-id');

            document.getElementById('campaignName').value = '';
            document.getElementById('campaignType').value = 'email';
            document.getElementById('campaignSendMode').value = 'immediate';
            document.getElementById('campaignSendDelay').value = '0';
            document.getElementById('campaignCommConfig').value = '';
            onCampaignSendModeChange();
            onCampaignTypeChange();
            openModal('createCampaignModal');
        }

        function openEditModal() {
            if (!activeCampaign) return;
            
            // Set modal title & button text
            const modalEl = document.getElementById('createCampaignModal');
            modalEl.querySelector('h3').textContent = 'Edit Campaign Details';
            const saveBtn = modalEl.querySelector('.mm-modal-footer button.mm-btn-primary');
            saveBtn.innerHTML = '<i class="fa-solid fa-check"></i> Save Changes';
            
            // Set campaign id attribute
            modalEl.setAttribute('data-campaign-id', activeCampaign.id);

            // Populate fields
            document.getElementById('campaignName').value = activeCampaign.name;
            document.getElementById('campaignType').value = activeCampaign.type;
            
            // Trigger channel change to populate communication configs and templates list
            onCampaignTypeChange();
            
            // Now select template and communication config
            document.getElementById('campaignTemplateSelect').value = activeCampaign.template_id;
            document.getElementById('campaignCommConfig').value = activeCampaign.communication_config_id || '';
            
            // Scheduled at / Send mode
            const sendModeSelect = document.getElementById('campaignSendMode');
            if (activeCampaign.scheduled_at) {
                sendModeSelect.value = 'schedule';
                onCampaignSendModeChange(); // Shows the scheduled time input
                if (fpCampaignSchedule) {
                    fpCampaignSchedule.setDate(new Date(activeCampaign.scheduled_at.replace(/-/g, '/')));
                } else {
                    document.getElementById('campaignScheduledAt').value = activeCampaign.scheduled_at;
                }
            } else {
                sendModeSelect.value = 'immediate';
                onCampaignSendModeChange(); // Hides the scheduled time input
            }
            
            document.getElementById('campaignSendDelay').value = activeCampaign.send_delay || '0';
            
            openModal('createCampaignModal');
        }

        function onCampaignSendModeChange() {
            const mode = document.getElementById('campaignSendMode').value;
            const group = document.getElementById('scheduleTimeGroup');
            if (mode === 'schedule') {
                group.style.display = 'flex';
                const now = new Date();
                now.setHours(now.getHours() + 1);
                // Round to the nearest 5 minutes
                const coeff = 1000 * 60 * 5;
                const rounded = new Date(Math.round(now.getTime() / coeff) * coeff);
                
                if (fpCampaignSchedule) {
                    fpCampaignSchedule.setDate(rounded);
                } else {
                    const offset = rounded.getTimezoneOffset() * 60000;
                    const localISOTime = (new Date(rounded - offset)).toISOString().slice(0, 16);
                    document.getElementById('campaignScheduledAt').value = localISOTime;
                }
            } else {
                group.style.display = 'none';
                if (fpCampaignSchedule) {
                    fpCampaignSchedule.clear();
                }
            }
        }

        async function createCampaign() {
            // Reset previous validation styles
            document.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
            
            const nameEl = document.getElementById('campaignName');
            const templateEl = document.getElementById('campaignTemplateSelect');
            const scheduledAtEl = document.getElementById('campaignScheduledAt');
            
            const name = nameEl.value.trim();
            const type = document.getElementById('campaignType').value;
            const template_id = templateEl.value;
            const send_mode = document.getElementById('campaignSendMode').value;
            const send_delay = parseInt(document.getElementById('campaignSendDelay').value) || 0;
            const scheduled_at = scheduledAtEl.value;
            const comm_config_id = document.getElementById('campaignCommConfig').value || null;

            if (!name) {
                vyToast('Campaign name is required.', 'error');
                nameEl.classList.add('is-invalid');
                nameEl.style.border = '1px solid #ef4444';
                nameEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            } else { nameEl.style.border = ''; }
            
            if (!template_id) {
                vyToast('Template selection is required.', 'error');
                templateEl.classList.add('is-invalid');
                templateEl.style.border = '1px solid #ef4444';
                templateEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            } else { templateEl.style.border = ''; }
            
            if (send_mode === 'schedule' && !scheduled_at) {
                vyToast('Schedule date and time is required.', 'error');
                const targetInput = fpCampaignSchedule ? fpCampaignSchedule.altInput : scheduledAtEl;
                targetInput.classList.add('is-invalid');
                targetInput.style.border = '1px solid #ef4444';
                targetInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            } else {
                if (fpCampaignSchedule && fpCampaignSchedule.altInput) {
                    fpCampaignSchedule.altInput.style.border = '';
                    fpCampaignSchedule.altInput.classList.remove('is-invalid');
                }
                scheduledAtEl.style.border = '';
            }

            const campaignId = document.getElementById('createCampaignModal').getAttribute('data-campaign-id');
            try {
                const res = await fetch(API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'save_campaign', id: campaignId ? parseInt(campaignId) : 0, name, type, template_id, send_mode, send_delay, scheduled_at, communication_config_id: comm_config_id })
                });
                const data = await res.json();
                if (data.success) {
                    vyToast(campaignId ? 'Campaign updated successfully!' : 'Campaign created successfully!', 'success');
                    closeModal('createCampaignModal');
                    if (campaignId) {
                        viewCampaign(campaignId);
                    } else {
                        loadCampaigns();
                        viewCampaign(data.id);
                    }
                } else {
                    vyToast(data.error, 'error');
                }
            } catch(e) {
                vyToast('Error: ' + e.message, 'error');
            }
        }

        async function deleteCampaign(id) {
            if (!confirm('Are you sure you want to delete this campaign?')) return;
            try {
                const res = await fetch(API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete_campaign', id })
                });
                const data = await res.json();
                if (data.success) {
                    vyToast('Campaign deleted.', 'success');
                    loadCampaigns();
                } else {
                    vyToast(data.error, 'error');
                }
            } catch(e) {
                vyToast('Error: ' + e.message, 'error');
            }
        }

        /* ════════════════════ CAMPAIGN DETAILS & MANAGEMENT ════════════════════ */

        async function viewCampaign(id) {
            try {
                const res = await fetch(`${API}?action=get_campaign&id=${id}`);
                const data = await res.json();
                if (data.success) {
                    activeCampaign = data.campaign;
                    showDetailsPanel(data.campaign, data.recipients);
                } else {
                    vyToast(data.error, 'error');
                }
            } catch(e) {
                vyToast('Connection failed: ' + e.message, 'error');
            }
        }

        function showDashboard() {
            document.getElementById('campaignDetailsPanel').style.display = 'none';
            document.getElementById('campaignsDashboard').style.display = 'block';
            loadCampaigns();
        }

        function showDetailsPanel(c, recipients) {
            document.getElementById('campaignsDashboard').style.display = 'none';
            document.getElementById('campaignDetailsPanel').style.display = 'block';

            // Toggle edit button visibility based on campaign status (only allow draft/scheduled edits)
            const editBtn = document.getElementById('btnEditCampaign');
            if (editBtn) {
                if (c.status === 'draft' || c.status === 'scheduled') {
                    editBtn.style.display = 'inline-flex';
                } else {
                    editBtn.style.display = 'none';
                }
            }

            document.getElementById('detailName').textContent = c.name;
            const chanLabel = c.type === 'email' ? 'Email Channel' : 'WhatsApp Channel';
            
            let metaText = `Channel: ${chanLabel} | Template: ${c.template_name}`;
            if (c.scheduled_at) {
                metaText += ` | Scheduled: ${formatVyDate(c.scheduled_at)} ${formatVyTime(c.scheduled_at)}`;
            }
            if (parseInt(c.send_delay) > 0) {
                metaText += ` | Delay: ${c.send_delay}s`;
            }
            document.getElementById('detailMeta').textContent = metaText;

            // Build Status Badge
            const badgeBox = document.getElementById('campaignStatusBadge');
            badgeBox.innerHTML = '';
            const span = document.createElement('span');
            span.className = 'status-badge';
            if (c.status === 'draft') {
                span.style.cssText = 'background:rgba(107,114,128,0.1); color:#6b7280; padding:4px 8px; border-radius:4px; font-size:11px; font-weight:700;';
                span.textContent = 'Draft';
            } else if (c.status === 'scheduled') {
                span.style.cssText = 'background:rgba(59,130,246,0.1); color:#3b82f6; padding:4px 8px; border-radius:4px; font-size:11px; font-weight:700;';
                const timeStr = c.scheduled_at ? `${formatVyDate(c.scheduled_at)} ${formatVyTime(c.scheduled_at)}` : '';
                span.innerHTML = `<i class="fa-solid fa-clock"></i> Scheduled ${timeStr ? 'for ' + timeStr : ''}`;
            } else if (c.status === 'sending') {
                span.style.cssText = 'background:rgba(245,158,11,0.1); color:#f59e0b; padding:4px 8px; border-radius:4px; font-size:11px; font-weight:700;';
                span.textContent = 'Sending';
            } else if (c.status === 'completed') {
                span.style.cssText = 'background:rgba(16,185,129,0.1); color:#10b981; padding:4px 8px; border-radius:4px; font-size:11px; font-weight:700;';
                span.textContent = 'Completed';
            } else {
                span.style.cssText = 'background:rgba(239,68,68,0.1); color:#ef4444; padding:4px 8px; border-radius:4px; font-size:11px; font-weight:700;';
                span.textContent = 'Failed';
            }
            badgeBox.appendChild(span);

            // Compute statistics
            const total = recipients.length;
            const sent = recipients.filter(r => r.status === 'sent').length;
            const failed = recipients.filter(r => r.status === 'failed').length;
            const pending = recipients.filter(r => r.status === 'pending').length;

            document.getElementById('statTotal').textContent = total;
            document.getElementById('statSent').textContent = sent;
            document.getElementById('statFailed').textContent = failed;
            document.getElementById('statPending').textContent = pending;

            // Send wizard section visibility
            const sendWizard = document.getElementById('sendWizardSection');
            if (total > 0) {
                sendWizard.style.display = 'block';
                document.getElementById('campaignStatusText').textContent = 'Status: ' + (c.status.charAt(0).toUpperCase() + c.status.slice(1));
                
                // Show retry button if there are failures and sending is not active
                document.getElementById('btnRetryFailed').style.display = (failed > 0 && !isSending) ? 'inline-flex' : 'none';
            } else {
                sendWizard.style.display = 'none';
            }

            // CSV upload section visibility
            document.getElementById('importRecipientsSection').style.display = (c.status === 'draft' || total === 0) ? 'block' : 'none';
            document.getElementById('csvPreviewSection').style.display = 'none';
            document.getElementById('csvFileInput').value = '';

            // Render Recipients Table
            renderRecipientsList(recipients);
        }

        function renderRecipientsList(recipients) {
            const tbody = document.getElementById('recipientsListBody');
            if (!tbody) return;

            const colSpan = 2 + campaignCustomFields.length;

            if (recipients.length === 0) {
                tbody.innerHTML = `<tr><td colspan="${colSpan}" style="text-align: center; padding: 20px; color: var(--text-muted);">No recipients registered yet. Please upload contacts above.</td></tr>`;
                return;
            }

            tbody.innerHTML = recipients.map(r => {
                let badge = '';
                if (r.status === 'pending') badge = '<span class="mm-badge" style="background:rgba(245,158,11,0.08); color:#f59e0b;">Pending</span>';
                else if (r.status === 'sent') badge = '<span class="mm-badge" style="background:rgba(16,185,129,0.08); color:#10b981;">Sent</span>';
                else badge = '<span class="mm-badge" style="background:rgba(239,68,68,0.08); color:#ef4444;">Failed</span>';

                let logMsg = '';
                if (r.status === 'failed' && r.error_message) {
                    logMsg = `<span style="color:#ef4444; font-size:11px;" title="${escapeHtml(r.error_message)}"><i class="fa-solid fa-triangle-exclamation"></i> ${escapeHtml(r.error_message)}</span>`;
                } else if (r.status === 'sent' && r.sent_at) {
                    logMsg = `<span style="color:var(--text-muted); font-size:11px;">Sent on ${formatVyDate(r.sent_at)}</span>`;
                }

                // All field cells from extra_data (or fixed columns as fallback)
                const fieldCells = campaignCustomFields.map(cf => {
                    const val = (r.extra_data && r.extra_data[cf.field_key] !== undefined && r.extra_data[cf.field_key] !== '')
                        ? r.extra_data[cf.field_key]
                        : (r[cf.field_key] || '-');
                    return `<td style="padding:10px 12px; border-bottom:1px solid var(--border); font-size:12px;">${escapeHtml(String(val))}</td>`;
                }).join('');

                return `
                    <tr>
                        ${fieldCells}
                        <td style="padding:10px 12px; border-bottom:1px solid var(--border);">${badge}</td>
                        <td style="padding:10px 12px; border-bottom:1px solid var(--border);">${logMsg}</td>
                    </tr>
                `;
            }).join('');
        }

        /* ════════════════════ CSV CONTACTS PARSING ════════════════════ */

        async function handleCsvFileSelected(event) {
            const file = event.target.files[0];
            if (!file) return;

            const formData = new FormData();
            formData.append('contacts_file', file);
            formData.append('action', 'parse_contacts_file');

            try {
                const res = await fetch(API, {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (data.success) {
                    parsedRecipients = data.contacts;
                    renderCsvPreview();
                    vyToast(`Parsed ${parsedRecipients.length} contacts!`, 'success');
                } else {
                    vyToast(data.error || 'Failed to parse file.', 'error');
                }
            } catch(e) {
                vyToast('File parsing failed: ' + e.message, 'error');
            }
            event.target.value = '';
        }

        function parseCSV(text) {
            const lines = text.split(/\r\n|\n/);
            if (lines.length === 0) return [];
            
            // Parse headers
            const headers = parseCSVRow(lines[0]);
            const result = [];
            
            for (let i = 1; i < lines.length; i++) {
                if (!lines[i].trim()) continue;
                const row = parseCSVRow(lines[i]);
                const obj = {};
                headers.forEach((header, index) => {
                    obj[header.trim()] = row[index] ? row[index].trim() : '';
                });
                result.push(obj);
            }
            return result;
        }

        function parseCSVRow(line) {
            const result = [];
            let current = '';
            let inQuotes = false;
            for (let i = 0; i < line.length; i++) {
                const char = line[i];
                if (char === '"') {
                    inQuotes = !inQuotes;
                } else if (char === ',' && !inQuotes) {
                    result.push(current);
                    current = '';
                } else {
                    current += char;
                }
            }
            result.push(current);
            return result;
        }

        function renderCsvPreview() {
            const previewSection = document.getElementById('csvPreviewSection');
            const tbody = document.getElementById('csvPreviewBody');
            const countSpan = document.getElementById('previewCount');

            tbody.innerHTML = '';
            countSpan.textContent = parsedRecipients.length;

            if (parsedRecipients.length === 0) {
                previewSection.style.display = 'none';
                return;
            }

            const colSpan = 1 + campaignCustomFields.length;
            previewSection.style.display = 'block';
            tbody.innerHTML = parsedRecipients.slice(0, 10).map((r, idx) => {
                const cells = campaignCustomFields.map(cf => {
                    const val = r[cf.field_key] || '';
                    return `<td>${escapeHtml(val)}</td>`;
                }).join('');

                return `
                    <tr>
                        ${cells}
                        <td style="text-align:right; position: sticky; right: 0; background: white; z-index: 9; box-shadow: -2px 0 5px rgba(0,0,0,0.04);">
                            <button class="mm-icon-btn mm-icon-danger" onclick="removePreviewRecipient(${idx})" style="padding:4px 8px; font-size:11px;" title="Remove"><i class="fa-solid fa-trash"></i></button>
                        </td>
                    </tr>
                `;
            }).join('') + (parsedRecipients.length > 10 ? `<tr><td colspan="${colSpan}" style="text-align:center; color:var(--text-muted); font-style:italic;">... and ${parsedRecipients.length - 10} more rows</td></tr>` : '');
        }

        function removePreviewRecipient(idx) {
            parsedRecipients.splice(idx, 1);
            renderCsvPreview();
        }

        function switchImportTab(type) {
            const tabUpload = document.getElementById('btnTabUpload');
            const tabManual = document.getElementById('btnTabManual');
            
            const contentUpload = document.getElementById('tabContentUpload');
            const contentManual = document.getElementById('tabContentManual');
            
            // Reset active states for all tabs
            if (tabUpload) {
                tabUpload.style.color = 'var(--text-muted)';
                tabUpload.style.borderBottom = '2px solid transparent';
            }
            if (tabManual) {
                tabManual.style.color = 'var(--text-muted)';
                tabManual.style.borderBottom = '2px solid transparent';
            }
            document.querySelectorAll('.btn-tab-mapped-module').forEach(btn => {
                btn.style.color = 'var(--text-muted)';
                btn.style.borderBottom = '2px solid transparent';
            });
            
            // Hide all tab contents
            if (contentUpload) contentUpload.style.display = 'none';
            if (contentManual) contentManual.style.display = 'none';
            document.querySelectorAll('.tab-content-mapped-module').forEach(content => {
                content.style.display = 'none';
            });
            
            if (type === 'upload') {
                if (tabUpload) {
                    tabUpload.style.color = 'var(--primary)';
                    tabUpload.style.borderBottom = '2px solid var(--primary)';
                }
                if (contentUpload) contentUpload.style.display = 'block';
            } else if (type === 'manual') {
                if (tabManual) {
                    tabManual.style.color = 'var(--primary)';
                    tabManual.style.borderBottom = '2px solid var(--primary)';
                }
                if (contentManual) contentManual.style.display = 'block';
            } else if (type.startsWith('module_')) {
                const moduleId = type.replace('module_', '');
                const tabModule = document.getElementById('btnTabModule_' + moduleId);
                const contentModule = document.getElementById('tabContentModule_' + moduleId);
                
                if (tabModule) {
                    tabModule.style.color = 'var(--primary)';
                    tabModule.style.borderBottom = '2px solid var(--primary)';
                }
                if (contentModule) {
                    contentModule.style.display = 'block';
                }
                loadModuleRecords(moduleId);
            }
        }

        const COMPANY_USERS = <?= json_encode($usersList) ?>;
        let activeModuleRecords = {};
        let activeModuleFields = {}; // Store fields per module for import lookup
        let debounceSearchTimeout = null;
        let activeModuleFilters = {}; // { moduleId: filter_rules | null }
        let campaignFilterRowsCount = {};
        let campaignSavedFiltersList = {};

        function debounceRecordSearch(moduleId) {
            clearTimeout(debounceSearchTimeout);
            debounceSearchTimeout = setTimeout(() => {
                loadModuleRecords(moduleId);
            }, 300);
        }

        function toggleCampaignFilterPanel(moduleId) {
            const panel = document.getElementById(`campaignFilterPanel_${moduleId}`);
            if (!panel) return;
            const isHidden = panel.style.display === 'none';
            panel.style.display = isHidden ? 'block' : 'none';
            
            // Add default row if empty
            const container = document.getElementById(`campaignFilterRulesContainer_${moduleId}`);
            if (isHidden && container && container.children.length === 0) {
                createCampaignFilterRuleRow(moduleId);
            }
        }

        function createCampaignFilterRuleRow(moduleId, ruleData = null) {
            const container = document.getElementById(`campaignFilterRulesContainer_${moduleId}`);
            if (!container) return;
            
            if (!campaignFilterRowsCount[moduleId]) campaignFilterRowsCount[moduleId] = 0;
            const count = ++campaignFilterRowsCount[moduleId];
            const rowId = `campaign-filter-rule-row-${moduleId}-${count}`;
            
            const fields = activeModuleFields[moduleId] || [];
            
            const rowHtml = `
                <div class="filter-rule-row" id="${rowId}" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 8px;">
                    <select class="form-control filter-field-select" style="width: 200px; height: 36px; font-size: 13px; background:#fff; border: 1.5px solid var(--border); border-radius:8px; cursor:pointer; padding: 4px 8px;" onchange="onCampaignFilterFieldChange('${rowId}', this.value, ${moduleId})">
                        <option value="">-- Choose Field --</option>
                        ${fields.map(f => `<option value="${f.id}">${escapeHtml(f.label)}</option>`).join('')}
                    </select>
                    
                    <select class="form-control filter-operator-select" style="width: 140px; height: 36px; font-size: 13px; background:#fff; border: 1.5px solid var(--border); border-radius:8px; cursor:pointer; padding: 4px 8px;">
                        <option value="=">=</option>
                        <option value="!=">!=</option>
                        <option value="LIKE">contains</option>
                        <option value="NOT LIKE">does not contain</option>
                        <option value=">">&gt;</option>
                        <option value="<">&lt;</option>
                        <option value=">=">&gt;=</option>
                        <option value="<=">&lt;=</option>
                    </select>
                    
                    <div class="filter-value-container" style="flex: 1; min-width: 150px;">
                        <input type="text" class="form-control filter-value-input" style="height: 36px; font-size: 13px; background:#fff; border: 1.5px solid var(--border); border-radius:8px; padding:4px 12px; box-sizing:border-box;" placeholder="Enter value...">
                    </div>
                    
                    <button class="btn-icon" style="background: rgba(239, 68, 68, 0.08); color: #ef4444; border: 1.5px solid rgba(239, 68, 68, 0.2); border-radius: 8px; width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; box-sizing:border-box;" onclick="document.getElementById('${rowId}').remove()" title="Remove Condition">
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
                onCampaignFilterFieldChange(rowId, ruleData.field_id, moduleId, ruleData.value);
                element.querySelector('.filter-operator-select').value = ruleData.operator;
            }
        }

        function onCampaignFilterFieldChange(rowId, fieldId, moduleId, value = '') {
            const rowEl = document.getElementById(rowId);
            if (!rowEl) return;
            
            const valueContainer = rowEl.querySelector('.filter-value-container');
            const fields = activeModuleFields[moduleId] || [];
            const field = fields.find(f => f.id == fieldId);
            
            if (!field) {
                valueContainer.innerHTML = `<input type="text" class="form-control filter-value-input" style="height: 36px; font-size: 13px; background:#fff; border: 1.5px solid var(--border); border-radius:8px; padding:4px 12px; box-sizing:border-box;" placeholder="Enter value...">`;
                return;
            }
            
            let html = '';
            if (field.field_type === 'user' || field.field_type === 'assigned_to' || field.field_type === 'sys_created_by' || field.field_type === 'sys_updated_by') {
                html = `
                    <select class="form-control filter-value-input" style="height: 36px; font-size: 13px; background:#fff; border: 1.5px solid var(--border); border-radius:8px; cursor:pointer; padding:4px 8px; box-sizing:border-box;">
                        <option value="">-- Select User --</option>
                        ${Object.entries(COMPANY_USERS).map(([id, name]) => `
                            <option value="${id}" ${value == id ? 'selected' : ''}>${escapeHtml(name)}</option>
                        `).join('')}
                    </select>
                `;
            } else if (field.field_type === 'checkbox') {
                html = `
                    <select class="form-control filter-value-input" style="height: 36px; font-size: 13px; background:#fff; border: 1.5px solid var(--border); border-radius:8px; cursor:pointer; padding:4px 8px; box-sizing:border-box;">
                        <option value="1" ${value == '1' ? 'selected' : ''}>Yes</option>
                        <option value="0" ${value == '0' ? 'selected' : ''}>No</option>
                    </select>
                `;
            } else if (field.field_type === 'select' || field.field_type === 'dropdown') {
                const opts = field.options || [];
                html = `
                    <select class="form-control filter-value-input" style="height: 36px; font-size: 13px; background:#fff; border: 1.5px solid var(--border); border-radius:8px; cursor:pointer; padding:4px 8px; box-sizing:border-box;">
                        <option value="">-- Choose Option --</option>
                        ${opts.map(opt => `
                            <option value="${opt.value || opt.label || opt.option_value}" ${value == (opt.value || opt.label || opt.option_value) ? 'selected' : ''}>${escapeHtml(opt.label || opt.value || opt.option_label || opt.option_value)}</option>
                        `).join('')}
                    </select>
                `;
            } else if (field.field_type === 'date' || field.field_type === 'sys_created_at' || field.field_type === 'sys_updated_at') {
                let valStr = value;
                if (value && value.includes(' ')) {
                    valStr = value.split(' ')[0];
                }
                html = `<input type="date" class="form-control filter-value-input" style="height: 36px; font-size: 13px; background:#fff; border: 1.5px solid var(--border); border-radius:8px; padding:4px 12px; box-sizing:border-box;" value="${escapeHtml(valStr)}">`;
            } else if (field.field_type === 'datetime') {
                let valStr = '';
                if (value) {
                    valStr = value.replace(' ', 'T').substring(0, 16);
                }
                html = `<input type="datetime-local" class="form-control filter-value-input" style="height: 36px; font-size: 13px; background:#fff; border: 1.5px solid var(--border); border-radius:8px; padding:4px 12px; box-sizing:border-box;" value="${escapeHtml(valStr)}">`;
            } else if (field.field_type === 'time') {
                html = `<input type="time" class="form-control filter-value-input" style="height: 36px; font-size: 13px; background:#fff; border: 1.5px solid var(--border); border-radius:8px; padding:4px 12px; box-sizing:border-box;" value="${escapeHtml(value)}">`;
            } else {
                html = `<input type="text" class="form-control filter-value-input" style="height: 36px; font-size: 13px; background:#fff; border: 1.5px solid var(--border); border-radius:8px; padding:4px 12px; box-sizing:border-box;" placeholder="Enter value..." value="${escapeHtml(value)}">`;
            }
            
            valueContainer.innerHTML = html;
        }

        function clearAllCampaignFilters(moduleId) {
            const container = document.getElementById(`campaignFilterRulesContainer_${moduleId}`);
            if (container) container.innerHTML = '';
            
            const saveNameInput = document.getElementById(`campaignFilterSaveName_${moduleId}`);
            if (saveNameInput) saveNameInput.value = '';
            
            const setDefaultInput = document.getElementById(`campaignFilterSetDefault_${moduleId}`);
            if (setDefaultInput) setDefaultInput.checked = false;
            
            activeModuleFilters[moduleId] = null;
            
            const pillCount = document.getElementById(`campaignFiltersActiveCount_${moduleId}`);
            if (pillCount) pillCount.textContent = '';
            
            const delBtn = document.getElementById(`btnDeleteCampaignFilter_${moduleId}`);
            if (delBtn) delBtn.style.display = 'none';
            
            const sel = document.getElementById(`savedFilterSelect_${moduleId}`);
            if (sel) sel.value = '';
            
            createCampaignFilterRuleRow(moduleId);
            loadModuleRecords(moduleId);
        }

        function getCampaignFilterRules(moduleId) {
            const rules = [];
            const container = document.getElementById(`campaignFilterRulesContainer_${moduleId}`);
            if (!container) return rules;
            
            const rows = container.querySelectorAll('.filter-rule-row');
            rows.forEach(row => {
                const fieldSelect = row.querySelector('.filter-field-select');
                const operatorSelect = row.querySelector('.filter-operator-select');
                const valueInput = row.querySelector('.filter-value-input');
                
                if (fieldSelect && fieldSelect.value) {
                    rules.push({
                        field_id: parseInt(fieldSelect.value),
                        operator: operatorSelect.value,
                        value: valueInput ? valueInput.value : ''
                    });
                }
            });
            return rules;
        }

        function applyCurrentCampaignFilters(moduleId) {
            const rules = getCampaignFilterRules(moduleId);
            if (rules.length === 0) {
                activeModuleFilters[moduleId] = null;
                const pillCount = document.getElementById(`campaignFiltersActiveCount_${moduleId}`);
                if (pillCount) pillCount.textContent = '';
            } else {
                activeModuleFilters[moduleId] = { filter_rules: rules };
                const pillCount = document.getElementById(`campaignFiltersActiveCount_${moduleId}`);
                if (pillCount) pillCount.textContent = `(${rules.length})`;
            }
            
            // Hide panel
            const panel = document.getElementById(`campaignFilterPanel_${moduleId}`);
            if (panel) panel.style.display = 'none';
            
            loadModuleRecords(moduleId);
        }

        async function saveCurrentCampaignFilter(moduleId) {
            const nameEl = document.getElementById(`campaignFilterSaveName_${moduleId}`);
            const name = nameEl ? nameEl.value.trim() : '';
            if (!name) {
                vyToast('Please enter a name for the filter.', 'error');
                return;
            }
            
            const rules = getCampaignFilterRules(moduleId);
            if (rules.length === 0) {
                vyToast('Please add at least one filter rule.', 'error');
                return;
            }
            
            const isDefault = document.getElementById(`campaignFilterSetDefault_${moduleId}`).checked ? 1 : 0;
            
            try {
                const response = await fetch('/api/modules.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'save_filter',
                        module_id: parseInt(moduleId),
                        name: name,
                        is_default: isDefault,
                        filter_rules: rules
                    })
                });
                const result = await response.json();
                if (result.success) {
                    vyToast('Filter saved successfully.', 'success');
                    nameEl.value = '';
                    document.getElementById(`campaignFilterSetDefault_${moduleId}`).checked = false;
                    
                    // Reload filters dropdown list
                    const filterSel = document.getElementById(`savedFilterSelect_${moduleId}`);
                    if (filterSel) {
                        filterSel.innerHTML = '<option value="">-- Apply Saved Filter --</option>';
                        const fRes = await fetch(`/api/modules.php?action=list_filters&module_id=${moduleId}`);
                        const fData = await fRes.json();
                        if (fData.success) {
                            campaignSavedFiltersList[moduleId] = fData.filters;
                            fData.filters.forEach(f => {
                                const opt = document.createElement('option');
                                opt.value = f.id;
                                opt.textContent = f.name + (f.is_default ? ' ★' : '');
                                filterSel.appendChild(opt);
                            });
                            filterSel.value = result.id;
                        }
                    }
                    
                    activeModuleFilters[moduleId] = { filter_id: result.id };
                    
                    const pillCount = document.getElementById(`campaignFiltersActiveCount_${moduleId}`);
                    if (pillCount) pillCount.textContent = `(${rules.length})`;
                    
                    const delBtn = document.getElementById(`btnDeleteCampaignFilter_${moduleId}`);
                    if (delBtn) delBtn.style.display = 'inline-flex';
                    
                    // Hide panel
                    const panel = document.getElementById(`campaignFilterPanel_${moduleId}`);
                    if (panel) panel.style.display = 'none';
                    
                    loadModuleRecords(moduleId);
                } else {
                    vyToast('Failed to save filter: ' + result.error, 'error');
                }
            } catch (e) {
                vyToast('Error saving filter: ' + e.message, 'error');
            }
        }

        async function deleteCampaignActiveFilter(moduleId) {
            const sel = document.getElementById(`savedFilterSelect_${moduleId}`);
            const filterId = sel ? sel.value : '';
            if (!filterId) return;
            
            if (!confirm('Are you sure you want to delete this saved filter?')) return;
            
            try {
                const response = await fetch('/api/modules.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'delete_filter',
                        id: parseInt(filterId)
                    })
                });
                const result = await response.json();
                if (result.success) {
                    vyToast('Filter deleted successfully.', 'success');
                    clearAllCampaignFilters(moduleId);
                    
                    // Reload filters dropdown list
                    const filterSel = document.getElementById(`savedFilterSelect_${moduleId}`);
                    if (filterSel) {
                        filterSel.innerHTML = '<option value="">-- Apply Saved Filter --</option>';
                        const fRes = await fetch(`/api/modules.php?action=list_filters&module_id=${moduleId}`);
                        const fData = await fRes.json();
                        if (fData.success) {
                            campaignSavedFiltersList[moduleId] = fData.filters;
                            fData.filters.forEach(f => {
                                const opt = document.createElement('option');
                                opt.value = f.id;
                                opt.textContent = f.name + (f.is_default ? ' ★' : '');
                                filterSel.appendChild(opt);
                            });
                        }
                    }
                } else {
                    vyToast('Failed to delete filter: ' + result.error, 'error');
                }
            } catch (e) {
                vyToast('Error deleting filter: ' + e.message, 'error');
            }
        }

        function loadCampaignSavedFilter(moduleId, filterId) {
            if (!filterId) {
                clearAllCampaignFilters(moduleId);
                return;
            }
            
            const filters = campaignSavedFiltersList[moduleId] || [];
            const filter = filters.find(f => f.id == filterId);
            if (!filter) return;
            
            const container = document.getElementById(`campaignFilterRulesContainer_${moduleId}`);
            if (container) container.innerHTML = '';
            
            const rules = filter.filter_rules || [];
            if (rules.length === 0) {
                createCampaignFilterRuleRow(moduleId);
            } else {
                rules.forEach(rule => createCampaignFilterRuleRow(moduleId, rule));
            }
            
            const delBtn = document.getElementById(`btnDeleteCampaignFilter_${moduleId}`);
            if (delBtn) delBtn.style.display = 'inline-flex';
            
            activeModuleFilters[moduleId] = { filter_id: parseInt(filterId) };
            
            const pillCount = document.getElementById(`campaignFiltersActiveCount_${moduleId}`);
            if (pillCount) pillCount.textContent = `(${rules.length})`;
        }

        function applySavedFilter(moduleId) {
            const sel = document.getElementById(`savedFilterSelect_${moduleId}`);
            const filterId = sel ? sel.value : '';
            loadCampaignSavedFilter(moduleId, filterId);
            loadModuleRecords(moduleId);
        }

        async function loadModuleRecords(moduleId) {
            const tbody = document.getElementById(`moduleRecordsImportBody_${moduleId}`);
            const headerRow = document.getElementById(`moduleRecordsHeader_${moduleId}`);
            if (!tbody) return;
            
            const colSpan = activeModuleFields[moduleId] ? activeModuleFields[moduleId].length + 1 : 5;
            tbody.innerHTML = `<tr><td colspan="${colSpan}" style="text-align: center; padding: 20px; color: var(--text-muted);">Loading records...</td></tr>`;
            
            try {
                // Load saved filters if not yet loaded
                const filterSel = document.getElementById(`savedFilterSelect_${moduleId}`);
                if (filterSel && filterSel.options.length <= 1) {
                    const fRes = await fetch(`/api/modules.php?action=list_filters&module_id=${moduleId}`);
                    const fData = await fRes.json();
                    if (fData.success && fData.filters.length > 0) {
                        campaignSavedFiltersList[moduleId] = fData.filters;
                        fData.filters.forEach(f => {
                            const opt = document.createElement('option');
                            opt.value = f.id;
                            opt.textContent = f.name + (f.is_default ? ' ★' : '');
                            filterSel.appendChild(opt);
                        });
                    }
                }

                // Build fetch payload
                const searchInput = document.getElementById(`importRecordsSearch_${moduleId}`);
                const search = searchInput ? searchInput.value : '';
                
                const payload = {
                    action: 'list_records',
                    module_id: parseInt(moduleId),
                    search: search || null,
                    limit: 200,
                    offset: 0
                };

                const filterInfo = activeModuleFilters[moduleId];
                if (filterInfo) {
                    if (filterInfo.filter_id) {
                        payload.filter_id = filterInfo.filter_id;
                    } else if (filterInfo.filter_rules) {
                        payload.filter_rules = filterInfo.filter_rules;
                    }
                }

                const recRes = await fetch('/api/modules.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const recData = await recRes.json();
                
                if (recData.success) {
                    const fields = recData.data.fields || [];
                    activeModuleFields[moduleId] = fields;
                    activeModuleRecords[moduleId] = recData.data.records || [];
                    const records = activeModuleRecords[moduleId];
                    
                    // Update header columns dynamically
                    if (headerRow) {
                        let headerHtml = `<th style="width: 30px; text-align: center;"><input type="checkbox" id="selectAllModuleRecords_${moduleId}" onchange="toggleSelectAllModuleRecords(${moduleId}, this.checked)"></th>`;
                        fields.forEach(f => {
                            headerHtml += `<th>${escapeHtml(f.label)}</th>`;
                        });
                        headerRow.innerHTML = headerHtml;
                    }
                    
                    if (records.length === 0) {
                        tbody.innerHTML = `<tr><td colspan="${fields.length + 1}" style="text-align: center; padding: 20px; color: var(--text-muted);">No records found in this module.</td></tr>`;
                        return;
                    }
                    
                    // Render all fields as columns
                    tbody.innerHTML = records.map(r => {
                        const values = r.values || {};
                        let rowHtml = `<tr>`;
                        rowHtml += `<td style="text-align: center; padding: 8px 12px;"><input type="checkbox" class="record-select-checkbox-${moduleId}" value="${r.id}"></td>`;
                        fields.forEach(f => {
                            const val = values[f.id] !== undefined ? values[f.id] : '';
                            rowHtml += `<td style="padding: 8px 12px;">${escapeHtml(String(val || '-'))}</td>`;
                        });
                        rowHtml += `</tr>`;
                        return rowHtml;
                    }).join('');
                    
                    // Reset master checkbox
                    const masterCheckbox = document.getElementById(`selectAllModuleRecords_${moduleId}`);
                    if (masterCheckbox) masterCheckbox.checked = false;
                } else {
                    const errMsg = recData.error || 'Unknown error';
                    tbody.innerHTML = `<tr><td colspan="5" style="text-align: center; padding: 20px; color: var(--danger);">Error: ${escapeHtml(errMsg)}</td></tr>`;
                }
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="5" style="text-align: center; padding: 20px; color: var(--danger);">Connection failed: ${escapeHtml(e.message)}</td></tr>`;
            }
        }
        
        function toggleSelectAllModuleRecords(moduleId, checked) {
            const checkboxes = document.querySelectorAll(`.record-select-checkbox-${moduleId}`);
            checkboxes.forEach(cb => cb.checked = checked);
        }

        function importSelectedModuleRecords(moduleId) {
            const checkedBoxes = document.querySelectorAll(`.record-select-checkbox-${moduleId}:checked`);
            if (checkedBoxes.length === 0) {
                vyToast('Please select at least one record to import.', 'error');
                return;
            }
            
            fetch(`api/campaigns_api.php?action=get_field_mapping&module_id=${moduleId}`)
                .then(res => res.json())
                .then(mapData => {
                    if (!mapData.success || !mapData.mapping) {
                        vyToast('No field mapping configured for this module.', 'error');
                        return;
                    }
                    
                    const mapping = mapData.mapping; // { first_name: 'field_key', email: 'field_key', ... }
                    const fields = mapData.all_fields || activeModuleFields[moduleId] || [];
                    const records = activeModuleRecords[moduleId] || [];
                    
                    // Build field_key -> field_id map
                    const keyToId = {};
                    fields.forEach(f => { keyToId[f.field_key] = f.id; });
                    
                    let count = 0;
                    let skippedDup = 0;
                    let skippedNoContact = 0;
                    
                    checkedBoxes.forEach(box => {
                        const recId = parseInt(box.value);
                        const r = records.find(item => item.id === recId);
                        if (r) {
                            const values = r.values || {};
                            
                            // Resolve each mapped field key -> field id -> value
                            const getVal = (mappedKey) => {
                                if (!mappedKey) return '';
                                const fid = keyToId[mappedKey];
                                return fid !== undefined && values[fid] !== undefined ? String(values[fid]).trim() : '';
                            };
                            
                            const first_name = getVal(mapping.first_name);
                            const last_name = getVal(mapping.last_name);
                            const email = getVal(mapping.email);
                            const phone = getVal(mapping.phone);
                            const company = getVal(mapping.company);
                            const designation = getVal(mapping.designation);
                            
                            if (!email && !phone) {
                                skippedNoContact++;
                                return;
                            }
                            
                            const isDup = parsedRecipients.some(pr => 
                                (email && pr.email === email) || (phone && pr.phone === phone)
                            );
                            if (isDup) {
                                skippedDup++;
                                return;
                            }
                            
                            parsedRecipients.push({
                                first_name, last_name, email, phone,
                                company_name: company, designation
                            });
                            count++;
                        }
                    });
                    
                    if (count > 0) {
                        let msg = `Added ${count} record${count !== 1 ? 's' : ''} to the campaign list.`;
                        if (skippedDup > 0) msg += ` (${skippedDup} duplicate${skippedDup !== 1 ? 's' : ''} skipped)`;
                        if (skippedNoContact > 0) msg += ` (${skippedNoContact} skipped — no email/phone mapped)`;
                        vyToast(msg, 'success');
                        renderCsvPreview();
                        checkedBoxes.forEach(box => box.checked = false);
                        const masterCheckbox = document.getElementById(`selectAllModuleRecords_${moduleId}`);
                        if (masterCheckbox) masterCheckbox.checked = false;
                    } else {
                        let reason = '';
                        if (skippedNoContact > 0 && skippedDup > 0) {
                            reason = `${skippedNoContact} record${skippedNoContact !== 1 ? 's' : ''} had no email/phone in the field mapping, and ${skippedDup} were duplicates.`;
                        } else if (skippedNoContact > 0) {
                            reason = `No email or phone found in the selected records. Check your field mapping under Module Manager → Bulk Campaigns → Field Mapping.`;
                        } else if (skippedDup > 0) {
                            reason = `All selected records are already in the campaign contacts list.`;
                        } else {
                            reason = 'Records could not be matched — check field mapping configuration.';
                        }
                        vyToast(reason, 'warning');
                    }
                })
                .catch(e => {
                    vyToast('Failed to load mapping: ' + e.message, 'error');
                });
        }

        function addManualContact() {
            if (!campaignCustomFields.length) {
                vyToast('No fields configured. Go to Module Manager → Configure Fields first.', 'error');
                return;
            }

            // Collect all field values dynamically
            const contact = {};
            let hasAnyValue = false;
            campaignCustomFields.forEach(cf => {
                const el = document.getElementById('manual_cf_' + cf.field_key);
                if (el) {
                    const val = el.value.trim();
                    contact[cf.field_key] = val;
                    if (val) hasAnyValue = true;
                }
            });

            if (!hasAnyValue) {
                vyToast('Please fill in at least one field.', 'error');
                return;
            }

            // Check required fields
            const missingRequired = campaignCustomFields.filter(cf => cf.is_required && !contact[cf.field_key]);
            if (missingRequired.length) {
                vyToast(`Required field missing: ${missingRequired[0].label}`, 'error');
                return;
            }

            parsedRecipients.push(contact);

            // Clear all inputs
            campaignCustomFields.forEach(cf => {
                const el = document.getElementById('manual_cf_' + cf.field_key);
                if (el) el.value = '';
            });
            
            renderCsvPreview();
            vyToast('Contact added to list!', 'success');
        }

        async function submitImportedRecipients() {
            if (!activeCampaign) return;
            if (parsedRecipients.length === 0) {
                vyToast('No records to import.', 'error');
                return;
            }

            try {
                const res = await fetch(API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'import_recipients',
                        campaign_id: activeCampaign.id,
                        recipients: parsedRecipients
                    })
                });
                const data = await res.json();
                if (data.success) {
                    vyToast(`Successfully imported ${data.count} contacts!`, 'success');
                    parsedRecipients = [];
                    document.getElementById('csvPreviewSection').style.display = 'none';
                    viewCampaign(activeCampaign.id);
                } else {
                    vyToast(data.error, 'error');
                }
            } catch(e) {
                vyToast('Connection failed: ' + e.message, 'error');
            }
        }


        /* ════════════════════ CAMPAIGN SEND LOGIC (REAL-TIME ENGINE) ════════════════════ */

        async function startCampaignSending() {
            if (!activeCampaign) return;
            isSending = true;
            stopRequested = false;

            const total = parseInt(document.getElementById('statTotal').textContent);
            const sentInitial = parseInt(document.getElementById('statSent').textContent);
            const failedInitial = parseInt(document.getElementById('statFailed').textContent);
            const pendingInitial = parseInt(document.getElementById('statPending').textContent);

            if (pendingInitial === 0) {
                vyToast('No pending messages in queue. Reset/Retry failed to start again.', 'warning');
                isSending = false;
                return;
            }

            document.getElementById('campaignStatusText').textContent = 'Status: Sending...';
            document.getElementById('btnStartSend').style.display = 'none';
            document.getElementById('btnRetryFailed').style.display = 'none';
            document.getElementById('btnStopCampaign').style.display = 'inline-flex';
            
            const progressBox = document.getElementById('progressBarBox');
            const progressBar = document.getElementById('progressBar');
            const logsBox = document.getElementById('sendingLogsBox');

            progressBox.style.display = 'block';
            logsBox.style.display = 'block';
            logsBox.innerHTML = '<div style="color:var(--primary); font-weight:700;">Initiating sending thread...</div>';

            let currentSent = sentInitial;
            let currentFailed = failedInitial;
            let currentSuccess = sentInitial;
            
            updateProgressPercent(currentSent + currentFailed, total);

            while (isSending && !stopRequested) {
                try {
                    const res = await fetch(API, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'send_next_recipient', campaign_id: activeCampaign.id })
                    });
                    const data = await res.json();
                    if (data.success) {
                        if (data.finished) {
                            logsBox.insertAdjacentHTML('beforeend', `<div style="font-weight:700; color:#10b981; margin-top:8px;">✔ CAMPAIGN FULLY PROCESS COMPLETE!</div>`);
                            vyToast('Campaign processing completed!', 'success');
                            break;
                        }

                        const rc = data.recipient;
                        const statusClass = rc.status === 'sent' ? 'log-success' : 'log-failed';
                        const statusIcon = rc.status === 'sent' ? '✔' : '✘';
                        const errorMsg = rc.error ? ` (${rc.error})` : '';

                        logsBox.insertAdjacentHTML('beforeend', `
                            <div class="log-item">
                                <span>${statusIcon} Send to <strong>${escapeHtml(rc.name)}</strong> [${escapeHtml(rc.contact)}]</span>
                                <span class="${statusClass}">${rc.status.toUpperCase()}${escapeHtml(errorMsg)}</span>
                            </div>
                        `);
                        logsBox.scrollTop = logsBox.scrollHeight;

                        if (rc.status === 'sent') {
                            currentSent++;
                        } else {
                            currentFailed++;
                        }

                        // Update dashboard stats in real-time
                        document.getElementById('statSent').textContent = currentSent;
                        document.getElementById('statFailed').textContent = currentFailed;
                        document.getElementById('statPending').textContent = total - (currentSent + currentFailed);

                        updateProgressPercent(currentSent + currentFailed, total);
                    } else {
                        logsBox.insertAdjacentHTML('beforeend', `<div style="color:#ef4444; font-weight:700; margin-top:8px;">✘ Server Error: ${escapeHtml(data.error)}</div>`);
                        vyToast(data.error || 'Server error occurred', 'error');
                        break;
                    }
                } catch(e) {
                    logsBox.insertAdjacentHTML('beforeend', `<div style="color:#ef4444; font-weight:700; margin-top:8px;">✘ Request connection failed: ${escapeHtml(e.message)}</div>`);
                    vyToast('Request failed: ' + e.message, 'error');
                    break;
                }
                
                // Yield thread: if send_delay is set, wait that many seconds, otherwise yield 100ms
                const delayMs = parseInt(activeCampaign.send_delay) > 0 ? (parseInt(activeCampaign.send_delay) * 1000) : 100;
                await new Promise(resolve => setTimeout(resolve, delayMs));
            }

            isSending = false;
            document.getElementById('btnStopCampaign').style.display = 'none';
            document.getElementById('btnStartSend').style.display = 'inline-flex';
            
            // Reload campaign details to refresh table and statuses properly
            await viewCampaign(activeCampaign.id);
        }

        function updateProgressPercent(processed, total) {
            const bar = document.getElementById('progressBar');
            if (!bar || total === 0) return;
            const pct = Math.min(100, Math.round((processed / total) * 100));
            bar.style.width = pct + '%';
        }

        function stopCampaignSending() {
            stopRequested = true;
            isSending = false;
            document.getElementById('campaignStatusText').textContent = 'Status: Paused';
            document.getElementById('sendingLogsBox').insertAdjacentHTML('beforeend', `<div style="color:#f59e0b; font-weight:700; margin-top:8px;">⚠ SENDING PAUSED BY USER.</div>`);
        }

        async function retryFailedCampaign() {
            if (!activeCampaign) return;
            if (!confirm('Are you sure you want to reset all failed recipients back to pending?')) return;

            try {
                const res = await fetch(API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'retry_failed', campaign_id: activeCampaign.id })
                });
                const data = await res.json();
                if (data.success) {
                    vyToast('Failed queue reset successfully!', 'success');
                    await viewCampaign(activeCampaign.id);
                } else {
                    vyToast(data.error || 'Failed to reset', 'error');
                }
            } catch(e) {
                vyToast('Connection failed: ' + e.message, 'error');
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

        let fpCampaignSchedule = null;
        document.addEventListener('DOMContentLoaded', () => {
            initPage();
            
            // Initialize Flatpickr for Campaign Scheduled At
            fpCampaignSchedule = flatpickr('#campaignScheduledAt', {
                enableTime: true,
                dateFormat: "Y-m-d H:i",
                altInput: true,
                altFormat: "d/m/Y, h:i K",
                minuteIncrement: 5,
                allowInput: false,
                onChange: function(selectedDates, dateStr, instance) {
                    if (selectedDates.length > 0) {
                        const d = selectedDates[0];
                        const m = d.getMinutes();
                        if (m % 5 !== 0) {
                            const rounded = new Date(Math.round(d.getTime() / 300000) * 300000);
                            instance.setDate(rounded, false);
                        }
                    }
                },
                onClose: function(selectedDates, dateStr, instance) {
                    if (selectedDates.length > 0) {
                        const d = selectedDates[0];
                        const m = d.getMinutes();
                        if (m % 5 !== 0) {
                            const rounded = new Date(Math.round(d.getTime() / 300000) * 300000);
                            instance.setDate(rounded, false);
                        }
                    }
                }
            });
        });
        function toggleSidebar() { document.getElementById('sidebar').classList.toggle('sidebar-collapsed'); }
    </script>
</body>
</html>
