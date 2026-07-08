<?php
require_once 'auth_check.php';
require_once 'includes/commerce.php';
require_once 'includes/brand.php';
require_once 'includes/dynamic_modules.php';

$context = commerce_get_tenant_context();
$conn = $context['conn'];
$prefix = $context['prefix'];

dm_ensure_tables($conn, $prefix);

$v = time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(brand_page_title('Email Signatures')) ?></title>
    <meta name="description" content="Manage reusable email signatures for your campaigns and templates.">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <link href="/assets/css/module_manager.css?v=<?= $v ?>" rel="stylesheet">
    <!-- CKEditor 5 Full build (includes image upload plugin) -->
    <!-- Include jQuery (required for Summernote) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <!-- Summernote Lite -->
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>
    <script src="/assets/js/toast.js?v=<?= $v ?>"></script>
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
        
        /* ═══════════════════════ SIGNATURES PAGE ═══════════════════════ */
        .sig-page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 32px;
            gap: 16px;
            flex-wrap: wrap;
        }
        .sig-page-title {
            font-size: 22px;
            font-weight: 800;
            color: var(--text-main);
            margin: 0 0 4px 0;
            letter-spacing: -0.3px;
        }
        .sig-page-subtitle {
            font-size: 13px;
            color: var(--text-muted);
            margin: 0;
        }

        /* ═══ Cards Grid ═══ */
        .sig-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }

        .sig-card {
            background: #fff;
            border: 1.5px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s;
            position: relative;
        }
        .sig-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 32px rgba(0,0,0,0.09);
            border-color: rgba(123,94,240,0.25);
        }
        .sig-card.is-default {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(123,94,240,0.10);
        }

        /* Left accent bar */
        .sig-card::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: linear-gradient(180deg, var(--primary), #a855f7);
            border-radius: 0;
            opacity: 0;
            transition: opacity 0.22s;
        }
        .sig-card:hover::before,
        .sig-card.is-default::before {
            opacity: 1;
        }

        .sig-card-head {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px 16px 10px 20px;
            border-bottom: 1px solid var(--border);
        }
        .sig-card-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: linear-gradient(135deg, rgba(123,94,240,0.12), rgba(168,85,247,0.08));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            color: var(--primary);
            flex-shrink: 0;
        }
        .sig-card-name {
            font-weight: 700;
            font-size: 14px;
            color: var(--text-main);
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .sig-default-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: linear-gradient(135deg, #f59e0b, #f97316);
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 20px;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }

        /* Live preview area */
        .sig-preview {
            flex: 1;
            padding: 16px 20px;
            font-size: 13px;
            color: var(--text-main);
            min-height: 100px;
            max-height: 160px;
            overflow: hidden;
            position: relative;
            line-height: 1.55;
        }
        .sig-preview::after {
            content: '';
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 40px;
            background: linear-gradient(transparent, #fff);
            pointer-events: none;
        }
        .sig-card:hover .sig-preview::after {
            background: linear-gradient(transparent, rgba(252,252,255,1));
        }

        /* Actions bar */
        .sig-card-actions {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 10px 16px;
            border-top: 1px solid var(--border);
            background: rgba(0,0,0,0.015);
        }
        .sig-action-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            padding: 6px 12px;
            border-radius: 8px;
            border: 1.5px solid var(--border);
            background: #fff;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.18s;
        }
        .sig-action-btn:hover {
            background: rgba(123,94,240,0.06);
            border-color: rgba(123,94,240,0.3);
            color: var(--primary);
        }
        .sig-action-btn.default-btn {
            border-color: #f59e0b;
            color: #f59e0b;
            background: rgba(245,158,11,0.06);
        }
        .sig-action-btn.default-btn:hover {
            background: rgba(245,158,11,0.12);
        }
        .sig-action-btn.default-active {
            background: linear-gradient(135deg, #f59e0b, #f97316);
            border-color: transparent;
            color: #fff;
        }
        .sig-action-btn.danger:hover {
            background: rgba(239,68,68,0.07);
            border-color: rgba(239,68,68,0.35);
            color: #ef4444;
        }
        .sig-action-btn.edit-btn:hover {
            background: rgba(59,130,246,0.07);
            border-color: rgba(59,130,246,0.35);
            color: #3b82f6;
        }

        /* New signature card */
        .sig-card-new {
            border: 2px dashed var(--border);
            background: rgba(123,94,240,0.02);
            border-radius: 16px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
            min-height: 220px;
            cursor: pointer;
            transition: all 0.22s;
            text-decoration: none;
            padding: 24px;
        }
        .sig-card-new:hover {
            border-color: var(--primary);
            background: rgba(123,94,240,0.05);
            transform: translateY(-2px);
        }
        .sig-card-new-icon {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            background: linear-gradient(135deg, rgba(123,94,240,0.15), rgba(168,85,247,0.10));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            color: var(--primary);
        }
        .sig-card-new-label {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-main);
            margin: 0;
        }
        .sig-card-new-sub {
            font-size: 12px;
            color: var(--text-muted);
            text-align: center;
            margin: 0;
        }

        /* Empty state */
        .sig-empty {
            text-align: center;
            padding: 60px 24px;
            color: var(--text-muted);
        }
        .sig-empty-icon {
            width: 72px; height: 72px;
            border-radius: 20px;
            background: linear-gradient(135deg, rgba(123,94,240,0.12), rgba(168,85,247,0.08));
            display: flex; align-items: center; justify-content: center;
            font-size: 28px; color: var(--primary);
            margin: 0 auto 16px;
        }

        /* ═══ Modal overrides ═══ */
        .sig-modal-body { padding: 24px 28px; }
        .sig-modal-body .ck-editor__editable { min-height: 220px; max-height: 340px; overflow-y: auto; }

        /* Image/GIF upload area */
        .sig-img-upload-bar {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
            flex-wrap: wrap;
        }
        .sig-img-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 14px;
            font-size: 12px;
            font-weight: 700;
            border: 1.5px dashed rgba(123,94,240,0.4);
            border-radius: 9px;
            background: rgba(123,94,240,0.04);
            color: var(--primary);
            cursor: pointer;
            transition: all 0.18s;
            user-select: none;
        }
        .sig-img-btn:hover {
            background: rgba(123,94,240,0.10);
            border-color: var(--primary);
            border-style: solid;
        }
        .sig-img-btn i { font-size: 13px; }
        .sig-img-uploading {
            font-size: 12px;
            color: var(--text-muted);
            display: none;
            align-items: center;
            gap: 6px;
        }
        .sig-img-uploading.visible { display: flex; }
        .sig-drop-zone {
            border: 2px dashed rgba(123,94,240,0.3);
            border-radius: 10px;
            padding: 10px 16px;
            font-size: 12px;
            color: var(--text-muted);
            text-align: center;
            transition: all 0.2s;
            cursor: pointer;
            flex: 1;
            min-width: 140px;
        }
        .sig-drop-zone.drag-over {
            background: rgba(123,94,240,0.08);
            border-color: var(--primary);
            color: var(--primary);
        }

        /* Name + default row */
        .sig-name-row {
            display: flex;
            gap: 12px;
            align-items: flex-end;
            margin-bottom: 16px;
        }
        .sig-name-row .form-group { flex: 1; margin: 0; }
        .sig-default-toggle {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            background: #fff;
            cursor: pointer;
            white-space: nowrap;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
            transition: all 0.18s;
            height: 42px;
            box-sizing: border-box;
        }
        .sig-default-toggle input[type=checkbox] { display:none; }
        .sig-default-toggle .star-icon { font-size: 15px; transition: color 0.2s; color: #d1d5db; }
        .sig-default-toggle.is-checked { border-color: #f59e0b; color: #f59e0b; background: rgba(245,158,11,0.07); }
        .sig-default-toggle.is-checked .star-icon { color: #f59e0b; }

        .sig-hints {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(123,94,240,0.05);
            border: 1px solid rgba(123,94,240,0.12);
            border-radius: 10px;
            padding: 10px 14px;
            margin-bottom: 14px;
            font-size: 12px;
            color: var(--text-muted);
        }
        .sig-hints i { color: var(--primary); }

        /* Loading skeleton */
        .sig-skeleton {
            border-radius: 16px;
            overflow: hidden;
            border: 1.5px solid var(--border);
            min-height: 220px;
            background: linear-gradient(90deg, #f3f4f6 25%, #e5e7eb 50%, #f3f4f6 75%);
            background-size: 200% 100%;
            animation: shimmer 1.4s infinite;
        }
        @keyframes shimmer {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
        @keyframes shimmer {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        /* Full-Screen Builder Styles for Signature Editor */
        #sigModal.show {
            display: flex !important;
            background: #f1f5f9 !important;
            align-items: flex-start !important;
            justify-content: flex-start !important;
            overflow-y: auto !important;
        }
        #sigModal.show .mm-modal {
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
        #sigModal.show .mm-modal-header {
            background: #fff !important;
            border-bottom: 1.5px solid var(--border) !important;
            padding: 16px 40px !important;
        }
        #sigModal.show .mm-modal-header h3 {
            font-size: 20px !important;
            font-weight: 800 !important;
            color: var(--text-main) !important;
            letter-spacing: -0.3px;
        }
        #sigModal.show .mm-modal-body {
            flex: 1 !important;
            max-height: none !important;
            overflow-y: auto !important;
            padding: 40px 40px 60px 40px !important;
            max-width: 1000px !important;
            width: 100% !important;
            margin: 0 auto !important;
            box-sizing: border-box !important;
        }
        #sigModal.show .mm-modal-footer {
            background: #fff !important;
            border-top: 1.5px solid var(--border) !important;
            padding: 16px 40px !important;
            display: flex !important;
            justify-content: flex-end !important;
            gap: 12px !important;
        }
        #sigModal.show .mm-modal-footer .mm-btn {
            background: #fff !important;
            border: 1.5px solid var(--border) !important;
            color: var(--text-muted) !important;
            border-radius: 8px !important;
            padding: 10px 20px !important;
            font-weight: 700 !important;
            cursor: pointer;
            transition: all 0.15s;
        }
        #sigModal.show .mm-modal-footer .mm-btn:hover {
            background: #f1f5f9 !important;
            color: var(--text-main) !important;
        }
        #sigModal.show .mm-modal-footer .mm-btn-primary {
            background: var(--primary) !important;
            border: 1.5px solid var(--primary) !important;
            color: #fff !important;
            border-radius: 8px !important;
            padding: 10px 24px !important;
            font-weight: 700 !important;
            cursor: pointer;
            transition: all 0.15s;
        }
        #sigModal.show .mm-modal-footer .mm-btn-primary:hover {
            background: var(--primary-hover) !important;
            border-color: var(--primary-hover) !important;
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
            <div class="breadcrumb">Campaigns / <span class="current">Email Signatures</span></div>
            <div class="topbar-right">
                <button class="btn-primary" style="width:auto;padding:11px 22px;" onclick="openSigModal()">
                    <i class="fa-solid fa-plus"></i> New Signature
                </button>
            </div>
        </header>

        <div class="content-scroll">
            <div style="max-width:1100px; padding:0 0 40px 0;">
                <!-- Page header -->
                <div class="sig-page-header">
                    <div>
                        <h1 class="sig-page-title"><i class="fa-solid fa-signature" style="color:var(--primary);margin-right:10px;font-size:20px;"></i>Email Signatures</h1>
                        <p class="sig-page-subtitle">Create rich, reusable signatures — insert them into any email template with one click, just like Outlook.</p>
                    </div>
                </div>

                <!-- Cards grid -->
                <div class="sig-grid" id="sigGrid">
                    <!-- Skeleton loaders -->
                    <div class="sig-skeleton"></div>
                    <div class="sig-skeleton"></div>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- ═══════════════ Create / Edit Signature Modal ═══════════════ -->
<div class="mm-modal-overlay" id="sigModal">
    <div class="mm-modal mm-modal-lg" style="max-width:760px;">
        <div class="mm-modal-header">
            <h3 id="sigModalTitle" style="display:flex;align-items:center;gap:10px;">
                <span style="width:32px;height:32px;border-radius:9px;background:linear-gradient(135deg,rgba(123,94,240,0.15),rgba(168,85,247,0.1));display:inline-flex;align-items:center;justify-content:center;font-size:13px;color:var(--primary);"><i class="fa-solid fa-signature"></i></span>
                New Signature
            </h3>
            <button class="mm-icon-btn" onclick="closeSigModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="sig-modal-body">
            <input type="hidden" id="sigId" value="0">

            <!-- Name + Default toggle -->
            <div class="sig-name-row">
                <div class="form-group" style="display:flex;flex-direction:column;gap:6px;">
                    <label class="form-label">Signature Name *</label>
                    <input type="text" id="sigName" class="form-control" placeholder="e.g. John Smith – Sales Team" style="height:42px;">
                </div>
                <label class="sig-default-toggle" id="defaultToggle" for="sigIsDefault" onclick="toggleDefaultStyle()">
                    <input type="checkbox" id="sigIsDefault">
                    <i class="fa-solid fa-star star-icon"></i>
                    Set as Default
                </label>
            </div>

            <!-- Hint -->
            <div class="sig-hints">
                <i class="fa-solid fa-circle-info"></i>
                <span>Format your signature, attach images or GIFs using the toolbar or the button below. The <strong>default</strong> signature auto-appends when creating a new template.</span>
            </div>

            <!-- CKEditor area -->
            <div class="form-group" style="display:flex;flex-direction:column;gap:6px;">
                <label class="form-label">Signature Content *</label>
                <textarea id="sigContent" class="form-control" rows="6" placeholder="Type your signature here..."></textarea>

                <!-- Image / GIF attach bar -->
                <div class="sig-img-upload-bar">
                    <label class="sig-img-btn" for="sigImgFileInput" title="Attach image or GIF — inserts into editor">
                        <i class="fa-solid fa-image"></i> Attach Image / GIF
                    </label>
                    <input type="file" id="sigImgFileInput" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none;" onchange="handleSigImageUpload(this)">

                    <div class="sig-drop-zone" id="sigDropZone">
                        <i class="fa-solid fa-cloud-arrow-up" style="margin-right:5px;"></i> or drag &amp; drop image here
                    </div>

                    <div class="sig-img-uploading" id="sigImgUploading">
                        <i class="fa-solid fa-spinner fa-spin" style="color:var(--primary);"></i> Uploading...
                    </div>
                </div>
            </div>
        </div>
        <div class="mm-modal-footer">
            <button class="mm-btn" onclick="closeSigModal()">Cancel</button>
            <button class="mm-btn mm-btn-primary" onclick="saveSig()">
                <i class="fa-solid fa-check"></i> Save Signature
            </button>
        </div>
    </div>
</div>

<!-- ═══════════════ Delete Confirm Modal ═══════════════ -->
<div class="mm-modal-overlay" id="sigDeleteModal">
    <div class="mm-modal" style="max-width:400px;">
        <div class="mm-modal-header">
            <h3 style="color:#ef4444;display:flex;align-items:center;gap:8px;"><i class="fa-solid fa-triangle-exclamation"></i> Delete Signature</h3>
            <button class="mm-icon-btn" onclick="closeModal('sigDeleteModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="mm-modal-body" style="padding:24px 28px;">
            <p style="margin:0 0 6px;font-size:15px;font-weight:600;color:var(--text-main);">Are you sure?</p>
            <p style="margin:0;font-size:13px;color:var(--text-muted);">This will permanently delete the signature <strong id="deleteConfirmName"></strong>. This action cannot be undone.</p>
        </div>
        <div class="mm-modal-footer">
            <button class="mm-btn" onclick="closeModal('sigDeleteModal')">Cancel</button>
            <button class="mm-btn" style="background:#ef4444;color:#fff;border-color:#ef4444;" onclick="confirmDeleteSig()">
                <i class="fa-solid fa-trash"></i> Delete
            </button>
        </div>
    </div>
</div>

<script>
const API = 'api/campaigns_api.php';
let sigsList = [];
let sigEditorInstance = null;
let pendingDeleteId = null;

/* ─── Modal helpers ─── */
function openModal(id)  { document.getElementById(id).classList.add('show'); }
function closeModal(id) { document.getElementById(id).classList.remove('show'); }

function openSigModal(data = null) {
    document.getElementById('sigId').value = data ? data.id : '0';
    document.getElementById('sigModalTitle').innerHTML = `
        <span style="width:32px;height:32px;border-radius:9px;background:linear-gradient(135deg,rgba(123,94,240,0.15),rgba(168,85,247,0.1));display:inline-flex;align-items:center;justify-content:center;font-size:13px;color:var(--primary);">
            <i class="fa-solid fa-signature"></i></span>
        ${data ? 'Edit Signature' : 'New Signature'}`;
    document.getElementById('sigName').value = data ? data.name : '';
    document.getElementById('sigIsDefault').checked = data ? !!data.is_default : false;

    const content = data ? data.content : '';
    document.getElementById('sigContent').value = content;
    try {
        $('#sigContent').summernote('code', content);
    } catch (e) {}

    toggleDefaultStyle();
    openModal('sigModal');
    setTimeout(() => document.getElementById('sigName').focus(), 120);
}

function closeSigModal() {
    closeModal('sigModal');
}

function toggleDefaultStyle() {
    const cb = document.getElementById('sigIsDefault');
    const toggle = document.getElementById('defaultToggle');
    toggle.classList.toggle('is-checked', cb.checked);
}

/* ─── Init Summernote Editor ─── */
async function initSigEditor() {
    try {
        $('#sigContent').summernote({
            placeholder: 'Type your signature here...',
            tabsize: 2,
            height: 280,
            toolbar: [
                ['style', ['style']],
                ['font', ['bold', 'italic', 'underline', 'clear']],
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
                    uploadSigImage(files[0]);
                }
            }
        });

        // Wire up drag-and-drop zone
        const dropZone = document.getElementById('sigDropZone');
        if (dropZone) {
            dropZone.addEventListener('dragover', (e) => {
                e.preventDefault();
                dropZone.classList.add('drag-over');
            });
            dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
            dropZone.addEventListener('drop', (e) => {
                e.preventDefault();
                dropZone.classList.remove('drag-over');
                const files = e.dataTransfer.files;
                if (files && files[0]) uploadSigImage(files[0]);
            });
            dropZone.addEventListener('click', () => document.getElementById('sigImgFileInput').click());
        }
    } catch(e) { console.error('Summernote init:', e); }
}

/* ─── Image Upload helpers ─── */
function handleSigImageUpload(input) {
    if (input.files && input.files[0]) uploadSigImage(input.files[0]);
    input.value = ''; // reset so same file can be re-selected
}

async function uploadSigImage(file) {
    const allowed = ['image/jpeg','image/png','image/gif','image/webp'];
    if (!allowed.includes(file.type)) {
        vyToast('Only JPG, PNG, GIF, and WebP images are allowed.', 'error');
        return;
    }
    if (file.size > 5 * 1024 * 1024) {
        vyToast('Image must be smaller than 5 MB.', 'error');
        return;
    }

    const uploading = document.getElementById('sigImgUploading');
    if (uploading) uploading.classList.add('visible');

    const formData = new FormData();
    formData.append('upload', file);

    try {
        const res = await fetch('/api/upload_image.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.uploaded && data.url) {
            // Insert image into Summernote
            $('#sigContent').summernote('insertImage', data.url);
            vyToast('Image inserted!', 'success');
        } else {
            vyToast(data.error?.message || 'Upload failed.', 'error');
        }
    } catch(e) {
        vyToast('Upload error: ' + e.message, 'error');
    } finally {
        if (uploading) uploading.classList.remove('visible');
    }
}

/* ─── Load & Render ─── */
async function loadSigs() {
    try {
        const res = await fetch(`${API}?action=list_signatures`);
        const data = await res.json();
        if (data.success) {
            sigsList = data.signatures;
            renderSigGrid();
        } else {
            vyToast(data.error || 'Failed to load signatures', 'error');
        }
    } catch(e) {
        vyToast('Connection error: ' + e.message, 'error');
    }
}

function renderSigGrid() {
    const grid = document.getElementById('sigGrid');
    if (!grid) return;

    let html = '';

    if (sigsList.length === 0) {
        grid.innerHTML = `
            <div style="grid-column:1/-1;">
                <div class="sig-empty">
                    <div class="sig-empty-icon"><i class="fa-solid fa-signature"></i></div>
                    <p style="font-size:16px;font-weight:700;color:var(--text-main);margin:0 0 6px;">No signatures yet</p>
                    <p style="margin:0 0 20px;font-size:13px;">Create your first email signature to start inserting it into templates.</p>
                    <button class="mm-btn mm-btn-primary" onclick="openSigModal()">
                        <i class="fa-solid fa-plus"></i> Create First Signature
                    </button>
                </div>
            </div>`;
        return;
    }

    sigsList.forEach(sig => {
        const isDefault = sig.is_default == 1;
        html += `
        <div class="sig-card ${isDefault ? 'is-default' : ''}" id="sigcard-${sig.id}">
            <div class="sig-card-head">
                <div class="sig-card-icon"><i class="fa-solid fa-signature"></i></div>
                <span class="sig-card-name" title="${escHtml(sig.name)}">${escHtml(sig.name)}</span>
                ${isDefault ? `<span class="sig-default-badge"><i class="fa-solid fa-star"></i> Default</span>` : ''}
            </div>
            <div class="sig-preview">${sig.content}</div>
            <div class="sig-card-actions">
                ${isDefault
                    ? `<button class="sig-action-btn default-btn default-active" title="This is your default signature" disabled>
                            <i class="fa-solid fa-star"></i> Default
                       </button>`
                    : `<button class="sig-action-btn default-btn" onclick="setDefault(${sig.id})" title="Set as default">
                            <i class="fa-regular fa-star"></i> Set Default
                       </button>`
                }
                <div style="flex:1;"></div>
                <button class="sig-action-btn edit-btn" onclick="editSig(${sig.id})">
                    <i class="fa-solid fa-pencil"></i> Edit
                </button>
                <button class="sig-action-btn danger" onclick="promptDeleteSig(${sig.id}, '${escHtml(sig.name).replace(/'/g,"\\'")}')">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </div>
        </div>`;
    });

    // Add the "New signature" card
    html += `
    <div class="sig-card-new" onclick="openSigModal()">
        <div class="sig-card-new-icon"><i class="fa-solid fa-plus"></i></div>
        <p class="sig-card-new-label">New Signature</p>
        <p class="sig-card-new-sub">Click to create a new<br>reusable email signature</p>
    </div>`;

    grid.innerHTML = html;
}

/* ─── Edit ─── */
async function editSig(id) {
    try {
        const res = await fetch(`${API}?action=get_signature&id=${id}`);
        const data = await res.json();
        if (data.success) openSigModal(data.signature);
        else vyToast(data.error, 'error');
    } catch(e) {
        vyToast('Failed to load signature: ' + e.message, 'error');
    }
}

/* ─── Save ─── */
async function saveSig() {
    const id = parseInt(document.getElementById('sigId').value);
    const name = document.getElementById('sigName').value.trim();
    const content = $('#sigContent').summernote('code').trim();
    const isDefault = document.getElementById('sigIsDefault').checked ? 1 : 0;

    if (!name) {
        vyToast('Signature name is required.', 'error');
        document.getElementById('sigName').focus();
        return;
    }
    if (!content || content === '<p><br></p>' || content === '<br>') {
        vyToast('Signature content cannot be empty.', 'error');
        return;
    }

    try {
        const res = await fetch(API, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({action:'save_signature', id, name, content, is_default: isDefault})
        });
        const data = await res.json();
        if (data.success) {
            vyToast('Signature saved successfully!', 'success');
            closeSigModal();
            loadSigs();
        } else {
            vyToast(data.error || 'Failed to save', 'error');
        }
    } catch(e) {
        vyToast('Error: ' + e.message, 'error');
    }
}

/* ─── Set Default ─── */
async function setDefault(id) {
    try {
        const res = await fetch(API, {
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body: JSON.stringify({action:'set_default_signature', id})
        });
        const data = await res.json();
        if (data.success) {
            vyToast('Default signature updated!', 'success');
            loadSigs();
        } else {
            vyToast(data.error, 'error');
        }
    } catch(e) {
        vyToast('Error: ' + e.message, 'error');
    }
}

/* ─── Delete ─── */
function promptDeleteSig(id, name) {
    pendingDeleteId = id;
    document.getElementById('deleteConfirmName').textContent = `"${name}"`;
    openModal('sigDeleteModal');
}

async function confirmDeleteSig() {
    if (!pendingDeleteId) return;
    try {
        const res = await fetch(API, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({action:'delete_signature', id: pendingDeleteId})
        });
        const data = await res.json();
        if (data.success) {
            vyToast('Signature deleted.', 'success');
            closeModal('sigDeleteModal');
            pendingDeleteId = null;
            loadSigs();
        } else {
            vyToast(data.error, 'error');
        }
    } catch(e) {
        vyToast('Error: ' + e.message, 'error');
    }
}

/* ─── Utility ─── */
function escHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}

/* ─── Init ─── */
document.addEventListener('DOMContentLoaded', () => {
    loadSigs();
    initSigEditor();

    // Close modal on backdrop click
    document.getElementById('sigModal').addEventListener('click', function(e) {
        if (e.target === this) closeSigModal();
    });
    document.getElementById('sigDeleteModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal('sigDeleteModal');
    });
});

function toggleSidebar() { document.getElementById('sidebar').classList.toggle('sidebar-collapsed'); }
</script>
</body>
</html>
