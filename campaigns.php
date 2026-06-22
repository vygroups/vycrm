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
                            <h3 id="detailName" style="margin: 0 0 4px 0; font-size: 20px; font-weight: 700;">Campaign Name</h3>
                            <div id="detailMeta" style="font-size: 12px; color: var(--text-muted);">Channel: Email | Template: Welcome</div>
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
                                    <i class="fa-solid fa-file-csv"></i> CSV Upload
                                </button>
                                <button type="button" class="tab-btn" id="btnTabManual" onclick="switchImportTab('manual')" style="background:none; border:none; padding:10px 15px; font-size:14px; font-weight:600; cursor:pointer; color:var(--text-muted); border-bottom:2px solid transparent; margin-bottom:-2px; outline:none;">
                                    <i class="fa-solid fa-keyboard"></i> Manual Entry
                                </button>
                            </div>
                            
                            <!-- CSV Upload Content -->
                            <div id="tabContentUpload">
                                <p style="margin: 0 0 15px 0; font-size: 13px; color: var(--text-muted);">Upload contacts via a CSV file containing columns: <strong>First Name, Last Name, Email, Phone, Company Name, Designation</strong></p>
                                <div class="csv-drop-zone" onclick="document.getElementById('csvFileInput').click()">
                                    <i class="fa-solid fa-file-csv"></i>
                                    <h4 style="margin: 0 0 4px 0;">Click or Drag CSV File Here</h4>
                                    <span style="font-size:12px; color:var(--text-muted);">Only CSV files are supported</span>
                                    <input type="file" id="csvFileInput" accept=".csv" style="display: none;" onchange="handleCsvFileSelected(event)">
                                </div>
                            </div>
                            
                            <!-- Manual Entry Content -->
                            <div id="tabContentManual" style="display:none; background: rgba(123, 94, 240, 0.02); border: 1px solid var(--border); border-radius: 12px; padding: 20px; margin-bottom: 20px;">
                                <h5 style="margin: 0 0 12px 0; font-size: 14px; font-weight: 700; color: var(--primary);">Add Contact Manually</h5>
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 15px;">
                                    <div class="form-group" style="margin:0; display:flex; flex-direction:column; gap:6px;">
                                        <label class="form-label" style="font-size:11px; margin-bottom:4px; font-weight:700;">First Name</label>
                                        <input type="text" id="manualFirstName" class="form-control" style="height:36px; padding:6px 12px; font-size:13px; background:#fff;" placeholder="e.g. John">
                                    </div>
                                    <div class="form-group" style="margin:0; display:flex; flex-direction:column; gap:6px;">
                                        <label class="form-label" style="font-size:11px; margin-bottom:4px; font-weight:700;">Last Name</label>
                                        <input type="text" id="manualLastName" class="form-control" style="height:36px; padding:6px 12px; font-size:13px; background:#fff;" placeholder="e.g. Doe">
                                    </div>
                                    <div class="form-group" style="margin:0; display:flex; flex-direction:column; gap:6px;">
                                        <label class="form-label" style="font-size:11px; margin-bottom:4px; font-weight:700;">Email</label>
                                        <input type="email" id="manualEmail" class="form-control" style="height:36px; padding:6px 12px; font-size:13px; background:#fff;" placeholder="e.g. john@example.com">
                                    </div>
                                    <div class="form-group" style="margin:0; display:flex; flex-direction:column; gap:6px;">
                                        <label class="form-label" style="font-size:11px; margin-bottom:4px; font-weight:700;">Phone / Mobile</label>
                                        <input type="text" id="manualPhone" class="form-control" style="height:36px; padding:6px 12px; font-size:13px; background:#fff;" placeholder="e.g. 9876543210">
                                    </div>
                                    <div class="form-group" style="margin:0; display:flex; flex-direction:column; gap:6px;">
                                        <label class="form-label" style="font-size:11px; margin-bottom:4px; font-weight:700;">Company Name</label>
                                        <input type="text" id="manualCompany" class="form-control" style="height:36px; padding:6px 12px; font-size:13px; background:#fff;" placeholder="e.g. Acme Corp">
                                    </div>
                                    <div class="form-group" style="margin:0; display:flex; flex-direction:column; gap:6px;">
                                        <label class="form-label" style="font-size:11px; margin-bottom:4px; font-weight:700;">Designation</label>
                                        <input type="text" id="manualDesignation" class="form-control" style="height:36px; padding:6px 12px; font-size:13px; background:#fff;" placeholder="e.g. Director">
                                    </div>
                                </div>
                                <button class="mm-btn mm-btn-primary" style="height:36px; padding:0 16px; font-size:13px;" onclick="addManualContact()"><i class="fa-solid fa-plus"></i> Add to list</button>
                            </div>

                            <div id="csvPreviewSection" style="display: none;">
                                <h4 style="margin: 15px 0 8px 0; font-size: 13px; font-weight: 700; color: var(--text);">Contacts List Preview (<span id="previewCount">0</span> rows detected)</h4>
                                <div class="table-responsive" style="max-height: 250px; overflow-y: auto; border: 1px solid var(--border); border-radius: 8px; margin-bottom: 15px;">
                                    <table class="crm-table" style="font-size: 12px; margin: 0; width: 100%;">
                                        <thead>
                                            <tr>
                                                <th>First Name</th>
                                                <th>Last Name</th>
                                                <th>Email</th>
                                                <th>Phone</th>
                                                <th>Company</th>
                                                <th>Designation</th>
                                                <th style="text-align:right;">Actions</th>
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
                                    <tr>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Company</th>
                                        <th>Designation</th>
                                        <th>Status</th>
                                        <th>Log Message / Sent At</th>
                                    </tr>
                                </thead>
                                <tbody id="recipientsListBody">
                                    <tr>
                                        <td colspan="7" style="text-align: center; padding: 20px; color: var(--text-muted);">No recipients registered yet. Please upload contacts above.</td>
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
                    <label class="form-label">Send Mode *</label>
                    <select id="campaignSendMode" class="form-control" style="width: 100%;" onchange="onCampaignSendModeChange()">
                        <option value="immediate">Send Immediately</option>
                        <option value="schedule">Schedule for Later</option>
                    </select>
                </div>

                <div class="form-group" id="scheduleTimeGroup" style="display: none; flex-direction: column; gap: 6px; margin-top: 15px;">
                    <label class="form-label">Schedule Date & Time *</label>
                    <input type="datetime-local" id="campaignScheduledAt" class="form-control" style="width: 100%; box-sizing: border-box;">
                </div>

                <div class="form-group" style="display: flex; flex-direction: column; gap: 6px; margin-top: 15px;">
                    <label class="form-label">Delay Between Messages</label>
                    <select id="campaignSendDelay" class="form-control" style="width: 100%;">
                        <option value="0">No Delay</option>
                        <option value="1">1 Second</option>
                        <option value="2">2 Seconds</option>
                        <option value="5">5 Seconds</option>
                        <option value="10">10 Seconds</option>
                    </select>
                </div>
            </div>
            <div class="mm-modal-footer">
                <button class="mm-btn" onclick="closeModal('createCampaignModal')">Cancel</button>
                <button class="mm-btn mm-btn-primary" onclick="createCampaign()"><i class="fa-solid fa-check"></i> Create Campaign</button>
            </div>
        </div>
    </div>

    <script>
        const API = 'api/campaigns_api.php';
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
        }

        function openCreateModal() {
            document.getElementById('campaignName').value = '';
            document.getElementById('campaignType').value = 'email';
            document.getElementById('campaignSendMode').value = 'immediate';
            document.getElementById('campaignSendDelay').value = '0';
            onCampaignSendModeChange();
            onCampaignTypeChange();
            openModal('createCampaignModal');
        }

        function onCampaignSendModeChange() {
            const mode = document.getElementById('campaignSendMode').value;
            const group = document.getElementById('scheduleTimeGroup');
            if (mode === 'schedule') {
                group.style.display = 'flex';
                const now = new Date();
                now.setHours(now.getHours() + 1);
                const offset = now.getTimezoneOffset() * 60000;
                const localISOTime = (new Date(now - offset)).toISOString().slice(0, 16);
                document.getElementById('campaignScheduledAt').value = localISOTime;
            } else {
                group.style.display = 'none';
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
                scheduledAtEl.classList.add('is-invalid');
                scheduledAtEl.style.border = '1px solid #ef4444';
                scheduledAtEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            } else { scheduledAtEl.style.border = ''; }

            try {
                const res = await fetch(API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'save_campaign', name, type, template_id, send_mode, send_delay, scheduled_at })
                });
                const data = await res.json();
                if (data.success) {
                    vyToast('Campaign created successfully!', 'success');
                    closeModal('createCampaignModal');
                    loadCampaigns();
                    // Open it immediately
                    viewCampaign(data.id);
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

            if (recipients.length === 0) {
                tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; padding: 20px; color: var(--text-muted);">No recipients registered yet. Please upload contacts above.</td></tr>`;
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

                return `
                    <tr>
                        <td style="padding:10px 12px; border-bottom:1px solid var(--border);"><strong>${escapeHtml(r.first_name + ' ' + r.last_name)}</strong></td>
                        <td style="padding:10px 12px; border-bottom:1px solid var(--border); font-size:12px;">${escapeHtml(r.email || '-')}</td>
                        <td style="padding:10px 12px; border-bottom:1px solid var(--border); font-size:12px;">${escapeHtml(r.phone || '-')}</td>
                        <td style="padding:10px 12px; border-bottom:1px solid var(--border); font-size:12px;">${escapeHtml(r.company_name || '-')}</td>
                        <td style="padding:10px 12px; border-bottom:1px solid var(--border); font-size:12px;">${escapeHtml(r.designation || '-')}</td>
                        <td style="padding:10px 12px; border-bottom:1px solid var(--border);">${badge}</td>
                        <td style="padding:10px 12px; border-bottom:1px solid var(--border);">${logMsg}</td>
                    </tr>
                `;
            }).join('');
        }

        /* ════════════════════ CSV CONTACTS PARSING ════════════════════ */

        function handleCsvFileSelected(event) {
            const file = event.target.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = function(e) {
                const text = e.target.result;
                parsedRecipients = parseCSV(text);
                renderCsvPreview();
            };
            reader.readAsText(file);
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

            previewSection.style.display = 'block';
            tbody.innerHTML = parsedRecipients.slice(0, 10).map((r, idx) => {
                const fName = r.first_name || r['First Name'] || '';
                const lName = r.last_name || r['Last Name'] || '';
                const email = r.email || r['Email'] || '';
                const phone = r.phone || r['Phone'] || r['Mobile'] || '';
                const company = r.company_name || r['Company Name'] || r['Company'] || '';
                const desig = r.designation || r['Designation'] || '';

                return `
                    <tr>
                        <td>${escapeHtml(fName)}</td>
                        <td>${escapeHtml(lName)}</td>
                        <td>${escapeHtml(email)}</td>
                        <td>${escapeHtml(phone)}</td>
                        <td>${escapeHtml(company)}</td>
                        <td>${escapeHtml(desig)}</td>
                        <td style="text-align:right;">
                            <button class="mm-icon-btn mm-icon-danger" onclick="removePreviewRecipient(${idx})" style="padding:4px 8px; font-size:11px;" title="Remove"><i class="fa-solid fa-trash"></i></button>
                        </td>
                    </tr>
                `;
            }).join('') + (parsedRecipients.length > 10 ? `<tr><td colspan="7" style="text-align:center; color:var(--text-muted); font-style:italic;">... and ${parsedRecipients.length - 10} more rows</td></tr>` : '');
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
            
            if (type === 'upload') {
                tabUpload.style.color = 'var(--primary)';
                tabUpload.style.borderBottom = '2px solid var(--primary)';
                tabManual.style.color = 'var(--text-muted)';
                tabManual.style.borderBottom = '2px solid transparent';
                
                contentUpload.style.display = 'block';
                contentManual.style.display = 'none';
            } else {
                tabManual.style.color = 'var(--primary)';
                tabManual.style.borderBottom = '2px solid var(--primary)';
                tabUpload.style.color = 'var(--text-muted)';
                tabUpload.style.borderBottom = '2px solid transparent';
                
                contentManual.style.display = 'block';
                contentUpload.style.display = 'none';
            }
        }

        function addManualContact() {
            const fName = document.getElementById('manualFirstName').value.trim();
            const lName = document.getElementById('manualLastName').value.trim();
            const email = document.getElementById('manualEmail').value.trim();
            const phone = document.getElementById('manualPhone').value.trim();
            const company = document.getElementById('manualCompany').value.trim();
            const desig = document.getElementById('manualDesignation').value.trim();
            
            if (!fName && !lName) {
                vyToast('Please enter at least First Name or Last Name.', 'error');
                return;
            }
            if (!email && !phone) {
                vyToast('Please enter either Email or Phone number.', 'error');
                return;
            }
            
            parsedRecipients.push({
                first_name: fName,
                last_name: lName,
                email: email,
                phone: phone,
                company_name: company,
                designation: desig
            });
            
            // Clear inputs
            document.getElementById('manualFirstName').value = '';
            document.getElementById('manualLastName').value = '';
            document.getElementById('manualEmail').value = '';
            document.getElementById('manualPhone').value = '';
            document.getElementById('manualCompany').value = '';
            document.getElementById('manualDesignation').value = '';
            
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

        document.addEventListener('DOMContentLoaded', initPage);
        function toggleSidebar() { document.getElementById('sidebar').classList.toggle('sidebar-collapsed'); }
    </script>
</body>
</html>
