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
    <title><?= htmlspecialchars(brand_page_title('Campaign Templates')) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <link href="/assets/css/module_manager.css?v=<?= $v ?>" rel="stylesheet">
    <script src="/assets/js/toast.js?v=<?= $v ?>"></script>
    <style>
        .placeholder-btn {
            background: #fff;
            border: 1px solid var(--primary);
            color: var(--primary);
            font-size: 11px;
            padding: 4px 8px;
            border-radius: 4px;
            cursor: pointer;
            user-select: none;
            font-weight: 600;
            transition: all 0.15s;
            display: inline-block;
            margin-right: 6px;
            margin-bottom: 6px;
        }
        .placeholder-btn:hover {
            background: rgba(123, 94, 240, 0.08);
        }
        .placeholder-container {
            background: rgba(123, 94, 240, 0.04);
            border: 1px solid rgba(123, 94, 240, 0.12);
            padding: 12px;
            border-radius: 8px;
            margin-top: 8px;
        }
    </style>
</head>
<body>
    <div class="app-wrapper">
        <?php include 'includes/sidebar.php'; ?>
        <main class="main-content">
            <header class="topbar">
                <div class="breadcrumb">Campaigns / <span class="current">Templates</span></div>
                <div class="topbar-right">
                    <button class="btn-primary" style="width:auto;padding:12px 24px;" onclick="openTemplateModal()">
                        <i class="fa-solid fa-plus"></i> New Template
                    </button>
                </div>
            </header>
            
            <div class="content-scroll">
                <div class="crm-card" style="padding: 24px; text-align: left;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3 style="margin: 0; font-size: 18px; font-weight: 700; color: var(--text-main);">Template Library</h3>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="crm-table" style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: rgba(0,0,0,0.02); border-bottom: 1px solid var(--border);">
                                    <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: var(--text-muted); font-size: 13px;">Name</th>
                                    <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: var(--text-muted); font-size: 13px;">Type</th>
                                    <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: var(--text-muted); font-size: 13px;">Email Subject</th>
                                    <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: var(--text-muted); font-size: 13px;">Created At</th>
                                    <th style="padding: 12px 16px; text-align: right; font-weight: 600; color: var(--text-muted); font-size: 13px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="templatesListBody">
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 30px; color: var(--text-muted);">Loading templates...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Add/Edit Template Modal -->
    <div class="mm-modal-overlay" id="templateModal">
        <div class="mm-modal mm-modal-lg">
            <div class="mm-modal-header">
                <h3 id="modalTitle">New Template</h3>
                <button class="mm-icon-btn" onclick="closeModal('templateModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="mm-modal-body">
                <input type="hidden" id="templateId" value="0">
                
                <div class="mm-form-grid" style="grid-template-columns: 2fr 1fr;">
                    <div class="form-group" style="display: flex; flex-direction: column; gap: 6px;">
                        <label class="form-label">Template Name *</label>
                        <input type="text" id="templateName" class="form-control" placeholder="e.g. Welcome Message, Festive Offer" style="width: 100%; box-sizing: border-box;">
                    </div>
                    <div class="form-group" style="display: flex; flex-direction: column; gap: 6px;">
                        <label class="form-label">Channel Type *</label>
                        <select id="templateType" class="form-control" style="width: 100%;" onchange="onTypeChange()">
                            <option value="email">Email</option>
                            <option value="whatsapp">WhatsApp</option>
                        </select>
                    </div>
                </div>

                <div class="form-group" id="subjectGroup" style="margin-top: 15px; display: flex; flex-direction: column; gap: 6px;">
                    <label class="form-label">Email Subject *</label>
                    <input type="text" id="templateSubject" class="form-control" placeholder="Subject line" style="width: 100%; box-sizing: border-box;">
                </div>

                <div class="form-group" style="margin-top: 15px; display: flex; flex-direction: column; gap: 6px;">
                    <label class="form-label">Message Body *</label>
                    <textarea id="templateBody" class="form-control" rows="8" placeholder="Message content..." style="width: 100%; box-sizing: border-box; font-family: inherit;"></textarea>
                </div>

                <!-- Personalization placeholders -->
                <div class="placeholder-container">
                    <h4 style="margin: 0 0 8px 0; font-size: 13px; font-weight: 700; color: var(--primary);">Personalization Placeholders</h4>
                    <p style="margin: 0 0 10px 0; font-size: 12px; color: var(--text-muted);">Place your cursor in Subject or Body and click a tag to insert it:</p>
                    <div style="display: flex; flex-wrap: wrap;">
                        <span class="placeholder-btn" onclick="insertPlaceholder('{First Name}')">{First Name}</span>
                        <span class="placeholder-btn" onclick="insertPlaceholder('{Last Name}')">{Last Name}</span>
                        <span class="placeholder-btn" onclick="insertPlaceholder('{Company Name}')">{Company Name}</span>
                        <span class="placeholder-btn" onclick="insertPlaceholder('{Designation}')">{Designation}</span>
                    </div>
                </div>
            </div>
            <div class="mm-modal-footer">
                <button class="mm-btn" onclick="closeModal('templateModal')">Cancel</button>
                <button class="mm-btn mm-btn-primary" onclick="saveTemplate()"><i class="fa-solid fa-check"></i> Save Template</button>
            </div>
        </div>
    </div>

    <script>
        const API = 'api/campaigns_api.php';
        let templatesList = [];

        function openModal(id) { document.getElementById(id).classList.add('show'); }
        function closeModal(id) { document.getElementById(id).classList.remove('show'); }

        async function loadTemplates() {
            try {
                const res = await fetch(`${API}?action=list_templates`);
                const data = await res.json();
                if (data.success) {
                    templatesList = data.templates;
                    renderTemplatesTable();
                } else {
                    vyToast(data.error || 'Failed to load templates', 'error');
                }
            } catch(e) {
                vyToast('Connection error: ' + e.message, 'error');
            }
        }

        function renderTemplatesTable() {
            const tbody = document.getElementById('templatesListBody');
            if (!tbody) return;

            if (templatesList.length === 0) {
                tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; padding:30px; color:var(--text-muted);">No campaign templates found. Create one to get started!</td></tr>`;
                return;
            }

            tbody.innerHTML = templatesList.map(t => {
                const typeLabel = t.type === 'email' 
                    ? `<span class="mm-badge" style="background:rgba(59,130,246,0.1); color:#3b82f6;"><i class="fa-solid fa-envelope"></i> Email</span>` 
                    : `<span class="mm-badge" style="background:rgba(16,185,129,0.1); color:#10b981;"><i class="fa-solid fa-message"></i> WhatsApp</span>`;

                return `
                    <tr>
                        <td style="padding:12px 16px; border-bottom:1px solid var(--border);"><strong>${escapeHtml(t.name)}</strong></td>
                        <td style="padding:12px 16px; border-bottom:1px solid var(--border);">${typeLabel}</td>
                        <td style="padding:12px 16px; border-bottom:1px solid var(--border);">${escapeHtml(t.subject || '-')}</td>
                        <td style="padding:12px 16px; border-bottom:1px solid var(--border); font-size:12px; color:var(--text-muted);">${formatVyDate(t.created_at)}</td>
                        <td style="padding:12px 16px; border-bottom:1px solid var(--border); text-align:right;">
                            <div style="display:inline-flex; gap:6px;">
                                <button class="mm-icon-btn" onclick="editTemplate(${t.id})" title="Edit"><i class="fa-solid fa-pencil"></i></button>
                                <button class="mm-icon-btn mm-icon-danger" onclick="deleteTemplate(${t.id})" title="Delete"><i class="fa-solid fa-trash"></i></button>
                            </div>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function onTypeChange() {
            const type = document.getElementById('templateType').value;
            document.getElementById('subjectGroup').style.display = type === 'email' ? 'flex' : 'none';
        }

        function openTemplateModal(editData = null) {
            document.getElementById('templateId').value = editData ? editData.id : '0';
            document.getElementById('modalTitle').textContent = editData ? 'Edit Template' : 'New Template';
            document.getElementById('templateName').value = editData ? editData.name : '';
            document.getElementById('templateType').value = editData ? editData.type : 'email';
            document.getElementById('templateSubject').value = editData ? (editData.subject || '') : '';
            document.getElementById('templateBody').value = editData ? editData.body : '';
            
            onTypeChange();
            openModal('templateModal');
        }

        async function editTemplate(id) {
            const temp = templatesList.find(t => t.id == id);
            if (!temp) return;
            try {
                const res = await fetch(`${API}?action=get_template&id=${id}`);
                const data = await res.json();
                if (data.success) {
                    openTemplateModal(data.template);
                } else {
                    vyToast(data.error, 'error');
                }
            } catch(e) {
                vyToast('Failed to fetch details: ' + e.message, 'error');
            }
        }

        async function saveTemplate() {
            const id = parseInt(document.getElementById('templateId').value);
            const name = document.getElementById('templateName').value.trim();
            const type = document.getElementById('templateType').value;
            const subject = document.getElementById('templateSubject').value.trim();
            const body = document.getElementById('templateBody').value.trim();

            if (!name) {
                vyToast('Template name is required.', 'error');
                return;
            }
            if (type === 'email' && !subject) {
                vyToast('Email subject is required.', 'error');
                return;
            }
            if (!body) {
                vyToast('Message body is required.', 'error');
                return;
            }

            const payload = { id, name, type, subject: type === 'email' ? subject : null, body };

            try {
                const res = await fetch(API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'save_template', ...payload })
                });
                const data = await res.json();
                if (data.success) {
                    vyToast('Template saved successfully!', 'success');
                    closeModal('templateModal');
                    loadTemplates();
                } else {
                    vyToast(data.error || 'Failed to save', 'error');
                }
            } catch(e) {
                vyToast('Error: ' + e.message, 'error');
            }
        }

        async function deleteTemplate(id) {
            if (!confirm('Are you sure you want to delete this template?')) return;
            try {
                const res = await fetch(API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete_template', id })
                });
                const data = await res.json();
                if (data.success) {
                    vyToast('Template deleted successfully.', 'success');
                    loadTemplates();
                } else {
                    vyToast(data.error, 'error');
                }
            } catch(e) {
                vyToast('Error: ' + e.message, 'error');
            }
        }

        function insertPlaceholder(placeholder) {
            const bodyEl = document.getElementById('templateBody');
            const subEl = document.getElementById('templateSubject');
            const type = document.getElementById('templateType').value;

            if (type === 'email' && document.activeElement === subEl) {
                const start = subEl.selectionStart;
                const end = subEl.selectionEnd;
                subEl.value = subEl.value.substring(0, start) + placeholder + subEl.value.substring(end);
                subEl.focus();
                subEl.selectionStart = subEl.selectionEnd = start + placeholder.length;
            } else {
                bodyEl.focus();
                const start = bodyEl.selectionStart;
                const end = bodyEl.selectionEnd;
                bodyEl.value = bodyEl.value.substring(0, start) + placeholder + bodyEl.value.substring(end);
                bodyEl.focus();
                bodyEl.selectionStart = bodyEl.selectionEnd = start + placeholder.length;
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

        document.addEventListener('DOMContentLoaded', loadTemplates);
        function toggleSidebar() { document.getElementById('sidebar').classList.toggle('sidebar-collapsed'); }
    </script>
</body>
</html>
