<?php
require_once 'auth_check.php';
require_once 'config/database.php';
require_once 'includes/brand.php';
$v = time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(brand_page_title('Payroll Settings')) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link rel="shortcut icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <!-- Include jQuery (required for Summernote) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <!-- Summernote Lite -->
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>
    <style>
        /* Summernote Premium Styling Overrides */
        .note-editor.note-frame {
            border: 1.5px solid var(--border) !important;
            border-radius: 12px !important;
            overflow: hidden !important;
            background: #fff !important;
            box-shadow: var(--shadow-sm) !important;
        }
        .note-toolbar {
            background: #f8fafc !important;
            border-bottom: 1px solid var(--border) !important;
            padding: 8px 12px !important;
        }
        .note-btn {
            background: #fff !important;
            border: 1.5px solid var(--border) !important;
            color: var(--text-main) !important;
            border-radius: 8px !important;
            padding: 6px 12px !important;
            font-size: 13px !important;
            font-weight: 600 !important;
            transition: all 0.15s !important;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02) !important;
        }
        .note-btn:hover {
            background: rgba(123,94,240,0.06) !important;
            color: var(--primary) !important;
            border-color: rgba(123,94,240,0.25) !important;
        }
        .note-btn.active {
            background: rgba(123,94,240,0.1) !important;
            border-color: var(--primary) !important;
            color: var(--primary) !important;
        }
        .note-editable {
            font-family: inherit !important;
            font-size: 14px !important;
            color: var(--text-main) !important;
            line-height: 1.6 !important;
            min-height: 250px;
        }
        .note-statusbar {
            background: #f8fafc !important;
            border-top: 1px solid var(--border) !important;
        }
        
        .tabs { display: flex; border-bottom: 1px solid #e2e8f0; margin-bottom: 20px; }
        .tab { padding: 12px 24px; cursor: pointer; color: #64748b; font-weight: 600; border-bottom: 2px solid transparent; transition: all 0.2s; }
        .tab:hover { color: var(--primary); }
        .tab.active { color: var(--primary); border-bottom-color: var(--primary); }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        
        /* Hide direct Image URL insertion inputs in insert image dialog */
        .note-group-image-url {
            display: none !important;
        }

        /* Hide direct Image URL insertion inputs in insert image dialog */
        .note-group-image-url {
            display: none !important;
        }
        
        .badge { padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .month-picker { padding: 8px 12px; border-radius: 8px; border: 1px solid #e2e8f0; font-family: inherit; font-size: 14px; outline: none; }
        .ck-editor__editable { min-height: 300px; }
        
        .config-card { background: white; padding: 20px; border-radius: 10px; border: 1px solid #e2e8f0; max-width: 600px; }
        .tag-list { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        .tag-item { background: #f1f5f9; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-family: monospace; color: var(--primary); border: 1px solid #e2e8f0; cursor: pointer; }
        .tag-item:hover { background: var(--primary); color: white; }
    </style>
</head>
<body>
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>

    <main class="main-content">
        <header class="topbar">
            <div class="breadcrumb">Settings / <span class="current">Payroll</span></div>
        </header>

        <div class="content-scroll">
            <div class="tabs">
                <div class="tab active" onclick="switchTab('tab-config', this)">Module Configuration</div>
                <div class="tab" onclick="switchTab('tab-monthly', this)">Monthly Processing</div>
                <div class="tab" onclick="switchTab('tab-template', this)">Payslip Template</div>
            </div>

            <!-- CONFIG TAB -->
            <div id="tab-config" class="tab-content active">
                <div class="config-card">
                    <h3 style="margin-top:0;">Dynamic Data Source</h3>
                    <p class="text-muted" style="font-size:13px; margin-bottom: 20px;">
                        Select which Dynamic Module acts as your Employee database. The Payroll system will fetch records from this module automatically.
                    </p>
                    
                    <form id="configForm">
                        <div class="form-group">
                            <label class="form-label">Employee Module</label>
                            <select id="source_module_id" name="source_module_id" class="form-control" required onchange="loadConfigFields()">
                                <option value="">-- Select Module --</option>
                            </select>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div class="form-group">
                                <label class="form-label">Name Field</label>
                                <select id="name_field_id" name="name_field_id" class="form-control" required>
                                    <option value="">-- Select Field --</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Email Address Field</label>
                                <select id="email_field_id" name="email_field_id" class="form-control" required>
                                    <option value="">-- Select Field --</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Status Filter Field (Optional)</label>
                                <select id="filter_field_id" name="filter_field_id" class="form-control">
                                    <option value="">-- None --</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group" id="filterValueContainer">
                            <label class="form-label">Filter Value (e.g. Active)</label>
                            <p class="text-muted" style="font-size:12px; margin-top:0;">Only records matching this value will appear in the Monthly Roster.</p>
                            <input type="text" id="filter_value" name="filter_value" class="form-control">
                        </div>
                        <button type="submit" class="btn-primary" id="saveConfigBtn" style="margin-top: 20px;">SAVE CONFIGURATION</button>
                    </form>
                </div>
            </div>

            <!-- MONTHLY PROCESSING TAB -->
            <div id="tab-monthly" class="tab-content">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <label style="font-weight: bold; font-size: 14px;">Select Month:</label>
                        <input type="month" id="monthPicker" class="month-picker" value="<?= date('Y-m') ?>" onchange="loadMonthly()">
                    </div>
                    <button class="btn-primary" onclick="processAllUnpaid()" id="btnProcessAll" style="display:none; background-color: var(--primary);">
                        <i class="fa-solid fa-paper-plane"></i> Send All Unpaid
                    </button>
                </div>
                <div class="table-panel">
                    <div class="table-header"><div class="table-title">Payroll Roster</div></div>
                    <div class="table-responsive">
                        <table class="crm-table">
                            <thead>
                                <tr>
                                    <th>Employee Record</th>
                                    <th>Total Working Days</th>
                                    <th>LOP</th>
                                    <th>Paid Days</th>
                                    <th>Gross Earnings</th>
                                    <th>Total Deductions</th>
                                    <th>Net Payable</th>
                                    <th>Status</th>
                                    <th style="position: sticky; right: 0; background: #f8fafc; z-index: 10; box-shadow: -2px 0 5px rgba(0,0,0,0.05);">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="payrollTableBody">
                                <tr><td colspan="10" class="text-center text-muted">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TEMPLATE TAB -->
            <div id="tab-template" class="tab-content">
                <div class="table-panel" style="max-width: 900px;">
                    <div class="table-header"><div class="table-title">Dynamic Email Template</div></div>
                    <div style="padding: 20px;">
                        <p class="text-muted" style="margin-top:0;">
                            Click any variable below to insert it into your template. These variables will be dynamically replaced with the specific employee's data when processing the payslip.
                        </p>
                        
                        <div style="margin-bottom: 20px;">
                            <strong>Built-in Variables:</strong>
                            <div class="tag-list">
                                <span class="tag-item" onclick="insertTag('{{salary_month}}')">Salary Month</span>
                                <span class="tag-item" onclick="insertTag('{{total_working_days}}')">Total Working Days</span>
                                <span class="tag-item" onclick="insertTag('{{lop_days}}')">LOP Days</span>
                                <span class="tag-item" onclick="insertTag('{{paid_days}}')">Paid Days</span>
                                <span class="tag-item" onclick="insertTag('{{gross_earnings}}')">Gross Earnings</span>
                                <span class="tag-item" onclick="insertTag('{{total_deductions}}')">Total Deductions</span>
                                <span class="tag-item" onclick="insertTag('{{net_payable}}')">Net Payable</span>
                            </div>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <strong>Dynamic Module Variables:</strong>
                            <div class="tag-list" id="dynamicTagsList">
                                <span class="text-muted" style="font-size:12px;">Loading fields...</span>
                            </div>
                        </div>

                        <form id="templateForm">
                            <div class="form-group" style="margin-top: 20px;">
                                <label class="form-label">Email Subject</label>
                                <input type="text" class="form-control" name="subject" id="subject" required>
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                <div class="form-group">
                                    <label class="form-label">Global CC Email (Optional)</label>
                                    <input type="email" class="form-control" name="cc_email" id="cc_email">
                                    <small class="text-muted">A copy of every payslip will be sent here.</small>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Global BCC Email (Optional)</label>
                                    <input type="email" class="form-control" name="bcc_email" id="bcc_email">
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Email Body Content</label>
                                <textarea name="body" id="body" class="form-control" style="min-height:100px; padding:10px;"></textarea>
                                <small class="text-muted">This text appears directly in the email body (not the PDF). HTML is supported.</small>
                            </div>
                            <div class="form-group" style="margin-top: 20px;">
                                <label class="form-label">PDF Payslip Template</label>
                                <textarea name="pdf_body" id="pdf_body"></textarea>
                                <small class="text-muted">This content will be converted into a PDF file attached to the email.</small>
                            </div>
                            <button type="submit" class="btn-primary" id="saveBtn">SAVE TEMPLATE</button>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>

<script src="/assets/js/toast.js"></script>
<script>
let editorInstance;
let pdfEditorInstance;
let lastFocusedEditor = null;
let currentConfig = null;

function switchTab(tabId, el) {
    document.querySelectorAll('.tab-content').forEach(tc => tc.classList.remove('active'));
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.getElementById(tabId).classList.add('active');
    el.classList.add('active');

    if(tabId === 'tab-config') loadConfig();
    if(tabId === 'tab-monthly') loadMonthly();
    if(tabId === 'tab-template') initEditor();
}

// ================= CONFIG =================
async function loadConfig() {
    try {
        const res = await fetch('/api/payroll_api.php?action=get_config');
        const data = await res.json();
        if(data.success) {
            currentConfig = data;
            const modSelect = document.getElementById('source_module_id');
            modSelect.innerHTML = '<option value="">-- Select Module --</option>';
            data.modules.forEach(m => {
                const selected = m.id == data.source_module_id ? 'selected' : '';
                modSelect.innerHTML += `<option value="${m.id}" ${selected}>${m.name}</option>`;
            });

            populateAllFields(data.fields, data);
        }
    } catch(e) { vyToast("Error loading config", "error"); }
}

async function loadConfigFields() {
    const moduleId = document.getElementById('source_module_id').value;
    if(!moduleId) {
        document.getElementById('email_field_id').innerHTML = '<option value="">-- Select Email Field --</option>';
        return;
    }
    try {
        const res = await fetch(`/api/payroll_api.php?action=get_module_fields&module_id=${moduleId}`);
        const data = await res.json();
        if(data.success) {
            if(!currentConfig) currentConfig = {};
            currentConfig.fields = data.fields;
            populateAllFields(data.fields, currentConfig);
        }
    } catch(e) {}
}

function populateAllFields(fields, config) {
    const nameSelect = document.getElementById('name_field_id');
    const emailSelect = document.getElementById('email_field_id');
    const filterSelect = document.getElementById('filter_field_id');

    let baseHtml = '<option value="">-- None --</option>';
    let emailHtml = '<option value="">-- Select Field --</option>';
    let nameHtml = '<option value="">-- Select Field --</option>';
    
    fields.forEach(f => {
        emailHtml += `<option value="${f.id}" ${f.id == config.email_field_id ? 'selected' : ''}>${f.label}</option>`;
        nameHtml += `<option value="${f.id}" ${f.id == config.name_field_id ? 'selected' : ''}>${f.label}</option>`;
        
        baseHtml += `<option value="${f.id}">${f.label}</option>`;
    });

    nameSelect.innerHTML = nameHtml;
    emailSelect.innerHTML = emailHtml;
    filterSelect.innerHTML = baseHtml;
    
    if(config.filter_field_id) filterSelect.value = config.filter_field_id;
    
    // Will be handled by renderFilterValueInput
    // document.getElementById('filter_value').value = config.filter_value || '';
    renderFilterValueInput();
}

document.getElementById('filter_field_id').addEventListener('change', renderFilterValueInput);

function renderFilterValueInput() {
    const fieldId = document.getElementById('filter_field_id').value;
    const container = document.getElementById('filterValueContainer');
    if (!container) return;
    
    let html = `<label class="form-label">Filter Value</label>
                <p class="text-muted" style="font-size:12px; margin-top:0;">Only records matching this value will appear in the Monthly Roster.</p>
                <input type="text" id="filter_value" name="filter_value" class="form-control" value="${currentConfig?.filter_value || ''}">`;

    if (!fieldId || !currentConfig || !currentConfig.fields) {
        container.innerHTML = html;
        return;
    }
    
    const field = currentConfig.fields.find(f => f.id == fieldId);
    if (!field) {
        container.innerHTML = html;
        return;
    }
    
    if (field.field_type === 'dropdown' || field.field_type === 'radio_group' || field.field_type === 'multi_picker' || field.field_type === 'select' || field.field_type === 'radio') {
        let optionsHtml = '';
        if (field.options && field.options.length > 0) {
            field.options.forEach(opt => {
                const selected = (currentConfig?.filter_value == opt.value) ? 'selected' : '';
                optionsHtml += `<option value="${opt.value}" ${selected}>${opt.label}</option>`;
            });
        } else {
            // fallback to config.options if DB options are empty
            let options = [];
            try {
                const configObj = JSON.parse(field.config || '{}');
                if (configObj.options) {
                    options = configObj.options.split(',').map(s => s.trim()).filter(s => s);
                }
            } catch(e){}
            options.forEach(opt => {
                const selected = (currentConfig?.filter_value == opt) ? 'selected' : '';
                optionsHtml += `<option value="${opt}" ${selected}>${opt}</option>`;
            });
        }
        
        let selectHtml = `<select id="filter_value" name="filter_value" class="form-control">
            <option value="">-- Select Value --</option>
            ${optionsHtml}
        </select>`;
        
        container.innerHTML = `<label class="form-label">Filter Value</label>
            <p class="text-muted" style="font-size:12px; margin-top:0;">Only records matching this value will appear in the Monthly Roster.</p>
            ${selectHtml}`;
    } else if (field.field_type === 'user' || field.field_type === 'assigned_to') {
        let selectHtml = `<select id="filter_value" name="filter_value" class="form-control">
            <option value="">-- Select User --</option>`;
        if(currentConfig.users) {
            currentConfig.users.forEach(u => {
                const selected = (currentConfig.filter_value == u.id) ? 'selected' : '';
                selectHtml += `<option value="${u.id}" ${selected}>${u.first_name} ${u.last_name} (${u.username})</option>`;
            });
        }
        selectHtml += `</select>`;
        container.innerHTML = `<label class="form-label">Filter Value</label>
            <p class="text-muted" style="font-size:12px; margin-top:0;">Only records matching this value will appear in the Monthly Roster.</p>
            ${selectHtml}`;
    } else {
        container.innerHTML = html;
    }
}

document.getElementById('configForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('saveConfigBtn');
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> SAVING...';
    btn.disabled = true;

    const formData = new FormData(this);
    formData.append('action', 'save_config');
    
    try {
        const res = await fetch('/api/payroll_api.php', { method: 'POST', body: formData });
        const data = await res.json();
        if(data.success) {
            vyToast("Configuration Saved!", "success");
        } else {
            vyToast(data.message, "error");
        }
    } catch(e) { vyToast("Network error", "error"); }

    btn.innerHTML = 'SAVE CONFIGURATION';
    btn.disabled = false;
});

// ================= MONTHLY =================
let payrollRecords = [];
async function loadMonthly() {
    const month = document.getElementById('monthPicker').value;
    if(!month) return;
    const tbody = document.getElementById('payrollTableBody');
    tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted">Loading...</td></tr>';
    
    try {
        const res = await fetch(`/api/payroll_api.php?action=get_monthly_payroll&month=${month}`);
        const data = await res.json();
        if(data.success) {
            if (data.error) {
                tbody.innerHTML = `<tr><td colspan="10" class="text-center text-muted" style="color:red;">${data.error}</td></tr>`;
            } else {
                payrollRecords = data.records;
                renderMonthlyTable();
            }
        } else {
            tbody.innerHTML = `<tr><td colspan="10" class="text-center text-muted" style="color:red;">Error: ${data.message}</td></tr>`;
        }
    } catch(e) {
        tbody.innerHTML = `<tr><td colspan="10" class="text-center text-muted" style="color:red;">Network or Server Error</td></tr>`;
    }
}

function renderMonthlyTable() {
    const tbody = document.getElementById('payrollTableBody');
    tbody.innerHTML = '';
    
    const btnAll = document.getElementById('btnProcessAll');
    
    if(payrollRecords.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted">No records found in the configured module.</td></tr>';
        if(btnAll) btnAll.style.display = 'none';
        return;
    }
    
    let hasDrafts = payrollRecords.some(r => r.status !== 'paid');
    if(btnAll) btnAll.style.display = hasDrafts ? 'inline-flex' : 'none';
    
    payrollRecords.forEach(rec => {
        let statusHtml = '';
        if(rec.status === 'paid') {
            statusHtml = '<span class="badge" style="background:rgba(16, 185, 129, 0.1); color:#10b981;"><i class="fa-solid fa-check"></i> Paid</span>';
            if(rec.payslip_sent == 1) statusHtml += ' <span class="badge" style="background:rgba(59, 130, 246, 0.1); color:#3b82f6;" title="Payslip emailed"><i class="fa-solid fa-envelope"></i></span>';
        } else {
            statusHtml = '<span class="badge" style="background:rgba(245, 158, 11, 0.1); color:#f59e0b;">Draft</span>';
        }

        const disabled = rec.status === 'paid' ? 'disabled' : '';
        const twdInput = `<input type="number" id="twd_${rec.record_id}" step="0.5" class="form-control" style="width:70px; padding:4px 8px; font-size:13px;" value="${rec.total_working_days}" onchange="updateRow(${rec.record_id}, this.value, 'twd')" ${disabled}>`;
        const lopInput = `<input type="number" id="lop_${rec.record_id}" step="0.5" class="form-control" style="width:70px; padding:4px 8px; font-size:13px;" value="${rec.lop_days}" onchange="updateRow(${rec.record_id}, this.value, 'lop')" ${disabled}>`;
        const pdInput = `<input type="number" id="pd_${rec.record_id}" step="0.5" class="form-control" style="width:70px; padding:4px 8px; font-size:13px;" value="${rec.paid_days}" onchange="updateRow(${rec.record_id}, this.value, 'pd')" ${disabled}>`;
        const grossInput = `<input type="number" id="gross_${rec.record_id}" step="0.01" class="form-control" style="width:110px; padding:4px 8px; font-size:13px;" value="${rec.gross_earnings}" onchange="updateRow(${rec.record_id}, this.value, 'gross')" ${disabled}>`;
        const dedInput = `<input type="number" id="ded_${rec.record_id}" step="0.01" class="form-control" style="width:110px; padding:4px 8px; font-size:13px; color:red;" value="${rec.total_deductions}" onchange="updateRow(${rec.record_id}, this.value, 'ded')" ${disabled}>`;

        let actionHtml = rec.status === 'paid' 
            ? `<button class="btn-primary" style="padding: 6px 12px; font-size: 11px; background-color: #f59e0b;" onclick="processPayslip(${rec.record_id}, false)"><i class="fa-solid fa-rotate-right"></i> Resend</button>`
            : `<button class="btn-primary" style="padding: 6px 12px; font-size: 11px;" onclick="processPayslip(${rec.record_id})"><i class="fa-solid fa-paper-plane"></i> Send Payslip</button>`;

        tbody.innerHTML += `
            <tr>
                <td class="text-bold">${rec.title}</td>
                <td>${twdInput}</td>
                <td>${lopInput}</td>
                <td>${pdInput}</td>
                <td>${grossInput}</td>
                <td>${dedInput}</td>
                <td class="text-bold" style="color:#10b981;" id="net_${rec.record_id}">₹${parseFloat(rec.net_payable).toFixed(2)}</td>
                <td id="status_${rec.record_id}">${statusHtml}</td>
                <td id="action_${rec.record_id}" style="position: sticky; right: 0; background: white; z-index: 9; box-shadow: -2px 0 5px rgba(0,0,0,0.05);">${actionHtml}</td>
            </tr>
        `;
    });
}

async function updateRow(recordId, value, type) {
    const rec = payrollRecords.find(r => r.record_id == recordId);
    if(!rec) return;
    if(type === 'twd') rec.total_working_days = parseFloat(value) || 0;
    if(type === 'lop') rec.lop_days = parseFloat(value) || 0;
    if(type === 'pd') rec.paid_days = parseFloat(value) || 0;
    if(type === 'gross') rec.gross_earnings = parseFloat(value) || 0;
    if(type === 'ded') rec.total_deductions = parseFloat(value) || 0;
    rec.net_payable = rec.gross_earnings - rec.total_deductions;
    document.getElementById('net_' + recordId).innerHTML = '₹' + rec.net_payable.toFixed(2);

    const month = document.getElementById('monthPicker').value;
    const formData = new FormData();
    formData.append('action', 'save_salary');
    formData.append('record_id', recordId);
    formData.append('month', month);
    formData.append('total_working_days', rec.total_working_days);
    formData.append('lop_days', rec.lop_days);
    formData.append('paid_days', rec.paid_days);
    formData.append('gross_earnings', rec.gross_earnings);
    formData.append('total_deductions', rec.total_deductions);
    formData.append('net_payable', rec.net_payable);
    formData.append('status', rec.status);
    await fetch('/api/payroll_api.php', { method: 'POST', body: formData });
}

async function processPayslip(recordId, skipConfirm = false) {
    if(!skipConfirm && !confirm("Mark this salary as PAID and email the payslip?")) return false;
    const btnCell = document.getElementById('action_' + recordId);
    if(btnCell) btnCell.innerHTML = '<span class="text-muted"><i class="fa-solid fa-spinner fa-spin"></i> Processing...</span>';

    const rec = payrollRecords.find(r => r.record_id == recordId);
    
    // Ensure we grab latest values from inputs in case onchange hasn't fired
    const twdEl = document.getElementById(`twd_${recordId}`);
    if (twdEl) rec.total_working_days = parseFloat(twdEl.value) || 0;
    const lopEl = document.getElementById(`lop_${recordId}`);
    if (lopEl) rec.lop_days = parseFloat(lopEl.value) || 0;
    const pdEl = document.getElementById(`pd_${recordId}`);
    if (pdEl) rec.paid_days = parseFloat(pdEl.value) || 0;
    const grossEl = document.getElementById(`gross_${recordId}`);
    if (grossEl) rec.gross_earnings = parseFloat(grossEl.value) || 0;
    const dedEl = document.getElementById(`ded_${recordId}`);
    if (dedEl) rec.total_deductions = parseFloat(dedEl.value) || 0;

    await updateRow(recordId, rec.gross_earnings, 'gross');

    const month = document.getElementById('monthPicker').value;
    
    const formData = new FormData();
    formData.append('action', 'process_payslip');
    formData.append('record_id', recordId);
    formData.append('month', month);

    try {
        const res = await fetch('/api/payroll_api.php', { method: 'POST', body: formData });
        const data = await res.json();
        if(data.success) {
            if(!skipConfirm) vyToast("Payslip generated and sent!", "success");
            if(!skipConfirm) loadMonthly();
            return true;
        } else {
            if(!skipConfirm) vyToast(data.message, "error");
            if(btnCell) btnCell.innerHTML = `<button class="btn-primary" style="padding: 6px 12px; font-size: 11px;" onclick="processPayslip(${recordId})"><i class="fa-solid fa-paper-plane"></i> Send Payslip</button><br><small style="color:red;">Error</small>`;
            return false;
        }
    } catch(e) {
        if(!skipConfirm) vyToast("Network error", "error");
        if(btnCell) btnCell.innerHTML = `<button class="btn-primary" style="padding: 6px 12px; font-size: 11px;" onclick="processPayslip(${recordId})"><i class="fa-solid fa-paper-plane"></i> Send Payslip</button><br><small style="color:red;">Net Error</small>`;
        return false;
    }
}

async function processAllUnpaid() {
    const unpaid = payrollRecords.filter(r => r.status !== 'paid');
    if(unpaid.length === 0) {
        vyToast("No unpaid draft records found for this month.", "info");
        return;
    }
    
    if(!confirm(`You are about to process and email payslips to ${unpaid.length} employees. Continue?`)) return;
    
    const btn = document.getElementById('btnProcessAll');
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing All...';
    btn.disabled = true;
    
    let successCount = 0;
    for(let i = 0; i < unpaid.length; i++) {
        const rec = unpaid[i];
        const ok = await processPayslip(rec.record_id, true);
        if(ok) successCount++;
    }
    
    vyToast(`Successfully processed ${successCount} out of ${unpaid.length} payslips!`, successCount === unpaid.length ? "success" : "warning");
    btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Send All Unpaid';
    btn.disabled = false;
    loadMonthly();
}

// ================= TEMPLATE =================
async function initEditor() {
    const editorConfig = {
        placeholder: 'Template body...',
        tabsize: 2,
        height: 280,
        toolbar: [
            ['style', ['style']],
            ['font', ['bold', 'italic', 'underline', 'strikethrough', 'clear']],
            ['color', ['color']],
            ['alignment', ['align']],
            ['para', ['ul', 'ol', 'paragraph']],
            ['table', ['table']],
            ['insert', ['link', 'picture', 'video']],
            ['view', ['fullscreen', 'codeview', 'help']]
        ],
        popover: {
            image: [
                ['custom', ['imageLinkPrompt']],
                ['imagesize', ['imageSize100', 'imageSize50', 'imageSize25']],
                ['float', ['floatLeft', 'floatRight', 'floatNone']],
                ['remove', ['removeMedia']]
            ]
        },
        buttons: {
            imageLinkPrompt: function(context) {
                var ui = $.summernote.ui;
                return ui.button({
                    contents: '<i class="note-icon-link"/>',
                    tooltip: 'Insert Image Link',
                    click: function() {
                        var $img = $(context.invoke('editor.restoreTarget'));
                        if (!$img.length || !$img.is('img')) {
                            $img = $(document.getSelection().anchorNode).find('img');
                        }
                        if (!$img.length) {
                            $img = $('.note-control-selection-area').prev();
                        }
                        if (!$img.length || !$img.is('img')) {
                            alert('Please select an image first.');
                            return;
                        }
                        
                        var parentA = $img.parent('a');
                        var currentUrl = parentA.length ? parentA.attr('href') : '';
                        
                        var url = prompt('To what URL should this link go?', currentUrl || 'https://');
                        if (url === null) return; // user cancelled
                        
                        url = url.trim();
                        if (url) {
                            if (parentA.length) {
                                parentA.attr('href', url);
                            } else {
                                $img.wrap('<a href="' + url + '" target="_blank"></a>');
                            }
                        } else {
                            if (parentA.length) {
                                $img.unwrap();
                            }
                        }
                        context.invoke('editor.afterCommand');
                    }
                }).render();
            }
        },
        callbacks: {
            onImageUpload: function(files) {
                uploadSummernoteImage(files[0], this);
            }
        }
    };

    if (!editorInstance) {
        try {
            editorInstance = true; // flag to prevent duplicate init
            $('#body').summernote(Object.assign({}, editorConfig, {
                callbacks: {
                    onFocus: function() { lastFocusedEditor = '#body'; },
                    onKeyup: function() { $(this).summernote('saveRange'); },
                    onMouseup: function() { $(this).summernote('saveRange'); },
                    onBlur: function() { $(this).summernote('saveRange'); },
                    onImageUpload: function(files) { uploadSummernoteImage(files[0], this); }
                }
            }));
        } catch (e) { console.error(e); }
    }
    if (!pdfEditorInstance) {
        try {
            pdfEditorInstance = true; // flag to prevent duplicate init
            $('#pdf_body').summernote(Object.assign({}, editorConfig, {
                callbacks: {
                    onFocus: function() { lastFocusedEditor = '#pdf_body'; },
                    onKeyup: function() { $(this).summernote('saveRange'); },
                    onMouseup: function() { $(this).summernote('saveRange'); },
                    onBlur: function() { $(this).summernote('saveRange'); },
                    onImageUpload: function(files) { uploadSummernoteImage(files[0], this); }
                }
            }));
        } catch (e) { console.error(e); }
    }

    try {
        const res = await fetch('/api/payroll_api.php?action=get_template');
        const data = await res.json();
        if(data.success) {
            if (data.template) {
                document.getElementById('subject').value = data.template.subject;
                document.getElementById('cc_email').value = data.cc_email || '';
                document.getElementById('bcc_email').value = data.bcc_email || '';
                $('#body').summernote('code', data.template.body || '');
                if (data.pdf_template) {
                    $('#pdf_body').summernote('code', data.pdf_template.body || '');
                }
            }
            
            // Populate dynamic tags
            const tagsList = document.getElementById('dynamicTagsList');
            if (data.fields && data.fields.length > 0) {
                tagsList.innerHTML = '';
                data.fields.forEach(f => {
                    const tag = `{{${f.field_key}}}`;
                    const label = f.label || f.field_key;
                    tagsList.innerHTML += `<span class="tag-item" onclick="insertTag('${tag}')" title="${tag}">${label}</span>`;
                });
            } else {
                tagsList.innerHTML = '<span class="text-muted" style="font-size:12px;">No module configured or module has no fields.</span>';
            }
        }
    } catch (e) {}
}

async function uploadSummernoteImage(file, editor) {
    const allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!allowed.includes(file.type)) {
        vyToast('Only JPG, PNG, GIF, and WebP images are allowed.', 'error');
        return;
    }
    if (file.size > 5 * 1024 * 1024) {
        vyToast('Image must be smaller than 5 MB.', 'error');
        return;
    }
    const formData = new FormData();
    formData.append('upload', file);
    try {
        const res = await fetch('/api/upload_image.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        if (data.uploaded && data.url) {
            $(editor).summernote('insertImage', data.url);
        } else {
            vyToast(data.error?.message || 'Failed to upload image.', 'error');
        }
    } catch(e) {
        vyToast('Upload error: ' + e.message, 'error');
    }
}

function placeCursorAtEnd(editorSelector) {
    const $editable = $(editorSelector).next('.note-editor').find('.note-editable');
    if ($editable.length) {
        $editable.focus();
        if (typeof window.getSelection !== "undefined" && typeof document.createRange !== "undefined") {
            const range = document.createRange();
            range.selectNodeContents($editable[0]);
            range.collapse(false); // collapse to end (bottom)
            const sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
        }
    }
}

function insertTag(tag) {
    let selector = lastFocusedEditor || '#body';
    $(selector).summernote('focus');
    
    var hasRange = false;
    try {
        var r = $(selector).summernote('createRange');
        var $editable = $(selector).next('.note-editor').find('.note-editable');
        if (r && $editable.length && ($editable[0] === r.sc || $.contains($editable[0], r.sc))) {
            hasRange = true;
        }
    } catch(e) {}

    if (hasRange) {
        $(selector).summernote('restoreRange');
    } else {
        placeCursorAtEnd(selector);
    }
    $(selector).summernote('insertText', tag);
}

document.getElementById('templateForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('saveBtn');
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> SAVING...';
    btn.disabled = true;

    const formData = new FormData();
    formData.append('action', 'save_template');
    formData.append('subject', document.getElementById('subject').value);
    formData.append('cc_email', document.getElementById('cc_email').value);
    formData.append('bcc_email', document.getElementById('bcc_email').value);
    formData.append('body', $('#body').summernote('code'));
    formData.append('pdf_body', $('#pdf_body').summernote('code'));
    
    try {
        const res = await fetch('/api/payroll_api.php', { method: 'POST', body: formData });
        const data = await res.json();
        if(data.success) vyToast("Template Saved!", "success");
        else vyToast(data.message, "error");
    } catch(e) { vyToast("Network error", "error"); }

    btn.innerHTML = 'SAVE TEMPLATE';
    btn.disabled = false;
});

// Init
const urlParams = new URLSearchParams(window.location.search);
const activeTab = urlParams.get('tab') || 'config';

if (activeTab === 'monthly') {
    switchTab('tab-monthly', document.querySelectorAll('.tab')[1]);
} else if (activeTab === 'template') {
    switchTab('tab-template', document.querySelectorAll('.tab')[2]);
} else {
    switchTab('tab-config', document.querySelectorAll('.tab')[0]);
}
</script>
</body>
</html>
