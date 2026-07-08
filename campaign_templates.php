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
        .ck-editor__editable {
            min-height: 250px;
        }
        /* Signature insert button & dropdown */
        .sig-insert-wrap {
            position: relative;
            display: inline-block;
        }
        .sig-insert-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            font-size: 12px;
            font-weight: 700;
            border: 1.5px solid rgba(123,94,240,0.35);
            border-radius: 8px;
            background: rgba(123,94,240,0.06);
            color: var(--primary);
            cursor: pointer;
            transition: all 0.18s;
            white-space: nowrap;
        }
        .sig-insert-btn:hover {
            background: rgba(123,94,240,0.12);
            border-color: var(--primary);
        }
        .sig-insert-btn i { font-size: 11px; }
        .sig-dropdown {
            display: none;
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            min-width: 240px;
            background: #fff;
            border: 1.5px solid var(--border);
            border-radius: 12px;
            box-shadow: 0 12px 32px rgba(0,0,0,0.12);
            z-index: 9999;
            overflow: hidden;
            padding: 6px;
        }
        .sig-dropdown.open { display: block; }
        .sig-drop-header {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            padding: 6px 10px 4px;
        }
        .sig-drop-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.15s;
        }
        .sig-drop-item:hover { background: rgba(123,94,240,0.07); }
        .sig-drop-icon {
            width: 28px; height: 28px;
            border-radius: 8px;
            background: linear-gradient(135deg,rgba(123,94,240,0.14),rgba(168,85,247,0.08));
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; color: var(--primary); flex-shrink: 0;
        }
        .sig-drop-name {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-main);
            flex: 1;
        }
        .sig-drop-default-star { color: #f59e0b; font-size: 11px; }
        .sig-drop-empty {
            padding: 12px;
            text-align: center;
            font-size: 12px;
            color: var(--text-muted);
        }
        .sig-drop-footer {
            border-top: 1px solid var(--border);
            margin-top: 4px;
            padding: 6px;
        }
        .sig-drop-manage {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 7px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            color: var(--primary);
            cursor: pointer;
            text-decoration: none;
            transition: background 0.15s;
        }
        .sig-drop-manage:hover { background: rgba(123,94,240,0.06); }

        /* Full-Screen Builder Styles for Template Editor */
        #templateModal.show {
            display: flex !important;
            background: #f1f5f9 !important;
            align-items: flex-start !important;
            justify-content: flex-start !important;
            overflow-y: auto !important;
        }
        #templateModal.show .mm-modal {
            width: 100% !important;
            max-width: 100% !important;
            height: 100vh !important;
            max-height: 100vh !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            display: flex !important;
            flex-direction: column !important;
            background: #f8fafc !important;
            animation: none !important;
        }
        #templateModal.show .mm-modal-header {
            background: #fff !important;
            border-bottom: 1.5px solid var(--border) !important;
            padding: 16px 40px !important;
        }
        #templateModal.show .mm-modal-header h3 {
            font-size: 20px !important;
            font-weight: 800 !important;
            color: var(--text-main) !important;
            letter-spacing: -0.3px;
        }
        #templateModal.show .mm-modal-body {
            flex: 1 !important;
            max-height: none !important;
            overflow-y: auto !important;
            padding: 40px 40px 60px 40px !important;
            max-width: 1000px !important;
            width: 100% !important;
            margin: 0 auto !important;
            box-sizing: border-box !important;
        }
        #templateModal.show .mm-modal-footer {
            background: #fff !important;
            border-top: 1.5px solid var(--border) !important;
            padding: 16px 40px !important;
            display: flex !important;
            justify-content: flex-end !important;
            gap: 12px !important;
        }
        #templateModal.show .mm-modal-footer .mm-btn {
            background: #fff !important;
            border: 1.5px solid var(--border) !important;
            color: var(--text-muted) !important;
            border-radius: 8px !important;
            padding: 10px 20px !important;
            font-weight: 700 !important;
            cursor: pointer;
            transition: all 0.15s;
        }
        #templateModal.show .mm-modal-footer .mm-btn:hover {
            background: #f1f5f9 !important;
            color: var(--text-main) !important;
        }

        /* Hide direct Image URL insertion inputs in insert image dialog */
        .note-group-image-url {
            display: none !important;
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
                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:4px;">
                        <label class="form-label" style="margin:0;">Message Body *</label>
                        <div class="sig-insert-wrap" id="sigInsertWrap">
                            <button type="button" class="sig-insert-btn" onclick="toggleSigDropdown(event)">
                                <i class="fa-solid fa-signature"></i> Insert Signature <i class="fa-solid fa-chevron-down" style="font-size:9px;"></i>
                            </button>
                            <div class="sig-dropdown" id="sigDropdown">
                                <div class="sig-drop-header">Your Signatures</div>
                                <div id="sigDropList"><div class="sig-drop-empty"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</div></div>
                                <div class="sig-drop-footer">
                                    <a href="email_signatures.php" target="_blank" class="sig-drop-manage">
                                        <i class="fa-solid fa-arrow-up-right-from-square"></i> Manage Signatures
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <textarea id="templateBody" class="form-control" rows="8" placeholder="Message content..." style="width: 100%; box-sizing: border-box; font-family: inherit;"></textarea>
                </div>

                <!-- Personalization placeholders -->
                <div class="placeholder-container">
                    <h4 style="margin: 0 0 8px 0; font-size: 13px; font-weight: 700; color: var(--primary);">Personalization Placeholders</h4>
                    <p style="margin: 0 0 10px 0; font-size: 12px; color: var(--text-muted);">Place your cursor in Subject or Body and click a tag to insert it:</p>
                    <div style="display: flex; flex-wrap: wrap;" id="placeholderTagsContainer">
                        <span style="color: var(--text-muted); font-size: 12px;">Loading fields...</span>
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
        let signaturesList = [];
        let _sigDropOpen = false;

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

        let editorInstance = null; // kept for backwards compatibility / code references

        async function initCKEditor() {
            try {
                $('#templateBody').summernote({
                    placeholder: 'Message content...',
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
                        },
                        onKeyup: function() {
                            $(this).summernote('saveRange');
                        },
                        onMouseup: function() {
                            $(this).summernote('saveRange');
                        },
                        onBlur: function() {
                            $(this).summernote('saveRange');
                        }
                    }
                });
                onTypeChange();
            } catch (e) {
                console.error('Summernote init error:', e);
            }
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

        function onTypeChange() {
            const type = document.getElementById('templateType').value;
            document.getElementById('subjectGroup').style.display = type === 'email' ? 'flex' : 'none';

            const noteEditor = document.querySelector('.note-editor');
            const textareaEl = document.getElementById('templateBody');
            
            if (type === 'email') {
                if (noteEditor) {
                    noteEditor.style.display = 'block';
                    textareaEl.style.display = 'none';
                } else {
                    textareaEl.style.display = 'block';
                }
            } else {
                if (noteEditor) {
                    noteEditor.style.display = 'none';
                    // Sync plain text content back to textarea from Summernote
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = $('#templateBody').summernote('code');
                    textareaEl.value = tempDiv.textContent || tempDiv.innerText || '';
                }
                textareaEl.style.display = 'block';
            }
        }

        function openTemplateModal(editData = null) {
            document.getElementById('templateId').value = editData ? editData.id : '0';
            document.getElementById('modalTitle').textContent = editData ? 'Edit Template' : 'New Template';
            document.getElementById('templateName').value = editData ? editData.name : '';
            document.getElementById('templateType').value = editData ? editData.type : 'email';
            document.getElementById('templateSubject').value = editData ? (editData.subject || '') : '';

            let bodyVal = editData ? editData.body : '';

            // Auto-append default signature for new templates
            if (!editData) {
                const defSig = signaturesList.find(s => s.is_default == 1);
                if (defSig) {
                    bodyVal = (bodyVal ? bodyVal + '<br><br>' : '') + defSig.content;
                }
            }

            document.getElementById('templateBody').value = bodyVal;
            try {
                $('#templateBody').summernote('code', bodyVal);
            } catch (e) {}

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
            // Reset previous validation styles
            document.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
            document.getElementById('templateName').style.border = '';
            document.getElementById('templateBody').style.border = '';
            document.getElementById('templateSubject').style.border = '';
            const noteEditor = document.querySelector('.note-editor');
            if (noteEditor) noteEditor.style.border = '';
            
            const nameEl = document.getElementById('templateName');
            const bodyEl = document.getElementById('templateBody');
            const subEl = document.getElementById('templateSubject');
            
            const id = parseInt(document.getElementById('templateId').value);
            const name = nameEl.value.trim();
            const type = document.getElementById('templateType').value;
            const subject = subEl.value.trim();
            
            const body = (type === 'email') ? $('#templateBody').summernote('code').trim() : bodyEl.value.trim();

            if (!name) {
                vyToast('Template name is required.', 'error');
                nameEl.classList.add('is-invalid');
                nameEl.style.border = '1px solid #ef4444';
                nameEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }
            if (type === 'email' && !subject) {
                vyToast('Email subject is required.', 'error');
                subEl.classList.add('is-invalid');
                subEl.style.border = '1px solid #ef4444';
                subEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }
            if (!body || body === '<p><br></p>' || body === '<br>') {
                vyToast('Message body is required.', 'error');
                if (type === 'email' && noteEditor) {
                    noteEditor.style.border = '1px solid #ef4444';
                } else {
                    bodyEl.classList.add('is-invalid');
                    bodyEl.style.border = '1px solid #ef4444';
                }
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
                if (type === 'email') {
                    $('#templateBody').summernote('focus');
                    var hasRange = false;
                    try {
                        var r = $('#templateBody').summernote('createRange');
                        var $editable = $('#templateBody').next('.note-editor').find('.note-editable');
                        if (r && $editable.length && ($editable[0] === r.sc || $.contains($editable[0], r.sc))) {
                            hasRange = true;
                        }
                    } catch(e) {}

                    if (hasRange) {
                        $('#templateBody').summernote('restoreRange');
                    } else {
                        placeCursorAtEnd('#templateBody');
                    }
                    $('#templateBody').summernote('insertText', placeholder);
                } else {
                    bodyEl.focus();
                    const start = bodyEl.selectionStart;
                    const end = bodyEl.selectionEnd;
                    bodyEl.value = bodyEl.value.substring(0, start) + placeholder + bodyEl.value.substring(end);
                    bodyEl.focus();
                    bodyEl.selectionStart = bodyEl.selectionEnd = start + placeholder.length;
                }
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

        async function loadCampaignFieldPlaceholders() {
            try {
                const res = await fetch(`${API}?action=list_campaign_fields`);
                const data = await res.json();
                const container = document.getElementById('placeholderTagsContainer');
                if (!container) return;
                if (data.success && data.fields && data.fields.length > 0) {
                    container.innerHTML = data.fields.map(f => {
                        const tag = '{' + f.label + '}';
                        return `<span class="placeholder-btn" onclick="insertPlaceholder('${tag.replace(/'/g, "\\'")}')">${escapeHtml(tag)}</span>`;
                    }).join('');
                } else {
                    container.innerHTML = '<span style="color:var(--text-muted); font-size:12px; font-style:italic;">No campaign fields configured yet. Add fields in Module Manager → Configure Fields.</span>';
                }
            } catch(e) {
                const container = document.getElementById('placeholderTagsContainer');
                if (container) container.innerHTML = '<span style="color:var(--text-muted); font-size:12px;">Failed to load placeholders.</span>';
            }
        }

        /* ── Signature helpers ── */
        async function loadSignatures() {
            try {
                const res = await fetch(`${API}?action=list_signatures`);
                const data = await res.json();
                if (data.success) {
                    signaturesList = data.signatures;
                    renderSigDropdown();
                }
            } catch(e) { /* silent */ }
        }

        function renderSigDropdown() {
            const list = document.getElementById('sigDropList');
            if (!list) return;
            if (!signaturesList || signaturesList.length === 0) {
                list.innerHTML = `<div class="sig-drop-empty">No signatures yet. <a href="email_signatures.php" target="_blank" style="color:var(--primary);">Create one →</a></div>`;
                return;
            }
            list.innerHTML = signaturesList.map(s => `
                <div class="sig-drop-item" onclick="insertSignature(${s.id})">
                    <div class="sig-drop-icon"><i class="fa-solid fa-signature"></i></div>
                    <span class="sig-drop-name">${escapeHtml(s.name)}</span>
                    ${s.is_default ? '<i class="fa-solid fa-star sig-drop-default-star" title="Default"></i>' : ''}
                </div>`).join('');
        }

        function toggleSigDropdown(e) {
            e.stopPropagation();
            const dd = document.getElementById('sigDropdown');
            _sigDropOpen = !_sigDropOpen;
            dd.classList.toggle('open', _sigDropOpen);
        }

        function insertSignature(id) {
            const sig = signaturesList.find(s => s.id == id);
            if (!sig) return;
            const type = document.getElementById('templateType').value;
            if (type === 'email') {
                $('#templateBody').summernote('focus');
                var hasRange = false;
                try {
                    var r = $('#templateBody').summernote('createRange');
                    var $editable = $('#templateBody').next('.note-editor').find('.note-editable');
                    if (r && $editable.length && ($editable[0] === r.sc || $.contains($editable[0], r.sc))) {
                        hasRange = true;
                    }
                } catch(e) {}

                if (hasRange) {
                    $('#templateBody').summernote('restoreRange');
                } else {
                    placeCursorAtEnd('#templateBody');
                }
                $('#templateBody').summernote('pasteHTML', '<br><br>' + sig.content);
            }
            // close dropdown
            document.getElementById('sigDropdown').classList.remove('open');
            _sigDropOpen = false;
        }

        document.addEventListener('click', (e) => {
            if (_sigDropOpen && !e.target.closest('#sigInsertWrap')) {
                document.getElementById('sigDropdown')?.classList.remove('open');
                _sigDropOpen = false;
            }
        });

        document.addEventListener('DOMContentLoaded', () => {
            loadTemplates();
            loadCampaignFieldPlaceholders();
            loadSignatures();
            initCKEditor();
        });
        function toggleSidebar() { document.getElementById('sidebar').classList.toggle('sidebar-collapsed'); }
    </script>
</body>
</html>
