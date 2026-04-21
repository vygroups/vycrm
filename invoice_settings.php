<?php
require_once 'auth_check.php';
require_once 'includes/commerce.php';
require_once 'includes/brand.php';

$context = commerce_get_tenant_context();
$conn = $context['conn'];
$prefix = $context['prefix'];
commerce_ensure_tables($conn, $prefix);

// Fetch existing settings
$stmt = $conn->query("SELECT * FROM {$prefix}invoice_settings WHERE id = 1");
$settings = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$settings) {
    $settings = [
        'printer_type' => 'regular',
        'layout_theme' => 'classic',
        'color_scheme' => '#1a1a2e',
        'paper_size' => 'A4',
        'show_company_name' => 1,
        'show_logo' => 1,
        'show_address' => 1,
        'show_email' => 1,
        'show_phone' => 1,
        'show_gstin' => 1,
        'show_bank_details' => 1,
        'show_terms' => 1,
        'show_signature' => 1,
        'show_acknowledgement' => 1,
        'show_hsn' => 1,
        'show_batch_info' => 0,
        'repeat_header' => 0,
        'default_printer' => 1,
    ];
}

// Fetch business profile for preview
$profileStmt = $conn->query("SELECT * FROM {$prefix}business_profile WHERE id = 1");
$biz = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$bizName = htmlspecialchars($biz['business_name'] ?? $companyName ?? 'Your Company');
$bizAddr = htmlspecialchars($biz['address'] ?? '123 Business Street, City');
$bizPhone = htmlspecialchars($biz['phone'] ?? '+91 98765 43210');
$bizEmail = htmlspecialchars($biz['email'] ?? 'contact@company.com');
$bizGstin = htmlspecialchars($biz['gstin'] ?? '33AABCU9603R1ZM');
$bizLogo = !empty($biz['logo_path']) ? '/' . $biz['logo_path'] : ($companyLogo ?? '');
$bankName = htmlspecialchars($biz['bank_name'] ?? 'State Bank of India');
$accountNo = htmlspecialchars($biz['account_no'] ?? '120028XXXXXX');
$ifscCode = htmlspecialchars($biz['ifsc_code'] ?? 'SBIN0007440');
$terms = htmlspecialchars($biz['terms'] ?? 'Thanks for doing business with us!');
$sigPath = !empty($biz['signature_path']) ? '/' . $biz['signature_path'] : '';

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'printer_type' => $_POST['printer_type'] ?? 'regular',
        'layout_theme' => $_POST['layout_theme'] ?? 'classic',
        'color_scheme' => $_POST['color_scheme'] ?? '#1a1a2e',
        'paper_size' => $_POST['paper_size'] ?? 'A4',
        'show_company_name' => isset($_POST['show_company_name']) ? 1 : 0,
        'show_logo' => isset($_POST['show_logo']) ? 1 : 0,
        'show_address' => isset($_POST['show_address']) ? 1 : 0,
        'show_email' => isset($_POST['show_email']) ? 1 : 0,
        'show_phone' => isset($_POST['show_phone']) ? 1 : 0,
        'show_gstin' => isset($_POST['show_gstin']) ? 1 : 0,
        'show_bank_details' => isset($_POST['show_bank_details']) ? 1 : 0,
        'show_terms' => isset($_POST['show_terms']) ? 1 : 0,
        'show_signature' => isset($_POST['show_signature']) ? 1 : 0,
        'show_acknowledgement' => isset($_POST['show_acknowledgement']) ? 1 : 0,
        'show_hsn' => isset($_POST['show_hsn']) ? 1 : 0,
        'show_batch_info' => isset($_POST['show_batch_info']) ? 1 : 0,
        'repeat_header' => isset($_POST['repeat_header']) ? 1 : 0,
        'default_printer' => isset($_POST['default_printer']) ? 1 : 0,
    ];

    $stmt = $conn->prepare("
        REPLACE INTO {$prefix}invoice_settings 
        (id, printer_type, layout_theme, color_scheme, paper_size,
         show_company_name, show_logo, show_address, show_email, show_phone, show_gstin,
         show_bank_details, show_terms, show_signature, show_acknowledgement,
         show_hsn, show_batch_info, repeat_header, default_printer)
        VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $data['printer_type'], $data['layout_theme'], $data['color_scheme'], $data['paper_size'],
        $data['show_company_name'], $data['show_logo'], $data['show_address'],
        $data['show_email'], $data['show_phone'], $data['show_gstin'],
        $data['show_bank_details'], $data['show_terms'], $data['show_signature'],
        $data['show_acknowledgement'], $data['show_hsn'], $data['show_batch_info'],
        $data['repeat_header'], $data['default_printer']
    ]);
    header("Location: invoice_settings.php?success=1");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(brand_page_title('Invoice Settings')) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <style>
        /* ========== Invoice Settings Layout ========== */
        .inv-settings-wrapper {
            display: grid;
            grid-template-columns: 220px 1fr 420px;
            gap: 0;
            height: calc(100vh - 80px);
            overflow: hidden;
        }

        /* ---- Settings Sidebar (Left Nav) ---- */
        .settings-nav {
            background: var(--surface);
            border-right: 1px solid var(--border);
            padding: 20px 0;
            overflow-y: auto;
        }
        .settings-nav-title {
            padding: 0 20px 16px;
            font-size: 11px;
            font-weight: 800;
            color: var(--text-muted);
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }
        .settings-nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 20px;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.2s;
            border-left: 3px solid transparent;
            position: relative;
        }
        .settings-nav-item:hover {
            color: var(--primary);
            background: rgba(123,94,240,0.04);
        }
        .settings-nav-item.active {
            color: var(--primary);
            background: rgba(123,94,240,0.08);
            border-left-color: var(--primary);
            font-weight: 700;
        }
        .settings-nav-item i {
            width: 20px;
            text-align: center;
            font-size: 15px;
        }
        .nav-badge {
            margin-left: auto;
            background: var(--primary);
            color: #fff;
            font-size: 10px;
            padding: 2px 7px;
            border-radius: 10px;
            font-weight: 700;
        }

        /* ---- Settings Content (Center) ---- */
        .settings-content {
            padding: 28px 32px;
            overflow-y: auto;
            background: var(--bg-color);
        }
        .settings-section {
            display: none;
        }
        .settings-section.active {
            display: block;
        }
        .section-heading {
            font-size: 20px;
            font-weight: 800;
            color: var(--text-main);
            margin-bottom: 6px;
        }
        .section-desc {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 24px;
            line-height: 1.6;
        }

        /* Printer Type Tabs */
        .printer-tabs {
            display: flex;
            gap: 0;
            margin-bottom: 28px;
            background: #f0eef8;
            border-radius: 14px;
            padding: 4px;
        }
        .printer-tab {
            flex: 1;
            text-align: center;
            padding: 12px 20px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            border-radius: 11px;
            transition: all 0.25s;
            color: var(--text-muted);
            position: relative;
        }
        .printer-tab.active {
            background: var(--primary);
            color: #fff;
            box-shadow: 0 4px 14px rgba(123,94,240,0.3);
        }
        .printer-tab:not(.active):hover {
            color: var(--primary);
        }
        .printer-tab i {
            margin-right: 8px;
        }

        /* Sub-tabs: Layout / Colors */
        .sub-tabs {
            display: flex;
            gap: 4px;
            margin-bottom: 24px;
        }
        .sub-tab {
            padding: 9px 22px;
            font-size: 13px;
            font-weight: 700;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s;
            color: var(--text-muted);
            background: transparent;
            border: 1.5px solid transparent;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .sub-tab.active {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }
        .sub-tab:not(.active):hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        .sub-panel {
            display: none;
        }
        .sub-panel.active {
            display: block;
        }

        /* Theme Cards */
        .theme-grid {
            display: flex;
            gap: 16px;
            overflow-x: auto;
            padding: 4px 0 16px;
            scroll-behavior: smooth;
        }
        .theme-card {
            min-width: 140px;
            max-width: 140px;
            background: var(--surface);
            border-radius: 16px;
            border: 2.5px solid var(--border);
            cursor: pointer;
            transition: all 0.25s;
            overflow: hidden;
            flex-shrink: 0;
        }
        .theme-card:hover {
            border-color: rgba(123,94,240,0.4);
            transform: translateY(-3px);
            box-shadow: 0 8px 24px rgba(123,94,240,0.15);
        }
        .theme-card.selected {
            border-color: var(--primary);
            box-shadow: 0 4px 20px rgba(123,94,240,0.25);
        }
        .theme-card.selected::after {
            content: '\f00c';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            top: 8px;
            right: 8px;
            background: var(--primary);
            color: #fff;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
        }
        .theme-card {
            position: relative;
        }
        .theme-thumb {
            width: 100%;
            height: 100px;
            background: #f8f8fc;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 12px;
        }
        .theme-thumb .mini-invoice {
            width: 80%;
            height: 80%;
            border-radius: 4px;
            position: relative;
            overflow: hidden;
        }
        /* Mini invoice thumbnails for each theme */
        .theme-classic .mini-invoice {
            border: 1.5px solid #1a1a2e;
            background: #fff;
        }
        .theme-classic .mini-invoice::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 18%;
            background: #f8f8fc;
            border-bottom: 1px solid #1a1a2e;
        }
        .theme-modern .mini-invoice {
            border: none;
            background: #fff;
            border-radius: 6px;
            overflow: hidden;
        }
        .theme-modern .mini-invoice::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 25%;
            background: linear-gradient(135deg, #7b5ef0, #a78bfa);
        }
        .theme-minimal .mini-invoice {
            border: none;
            background: #fff;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
        }
        .theme-minimal .mini-invoice::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: #1a1a2e;
        }
        .theme-bordered .mini-invoice {
            border: 2.5px solid #1a1a2e;
            background: #fff;
        }
        .theme-bordered .mini-invoice::before {
            content: '';
            position: absolute;
            inset: 3px;
            border: 1px solid #99999966;
        }
        .theme-compact .mini-invoice {
            border: 1px solid #ddd;
            background: #fff;
            border-radius: 3px;
        }
        .theme-compact .mini-invoice::before {
            content: '';
            position: absolute;
            top: 0; left: 0; bottom: 0;
            width: 4px;
            background: #7b5ef0;
        }
        .theme-elegant .mini-invoice {
            border: 1.5px solid #555;
            background: #fdfcfa;
        }
        .theme-elegant .mini-invoice::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 22%;
            background: linear-gradient(135deg, #333, #555);
        }
        .theme-elegant .mini-invoice::after {
            content: '';
            position: absolute;
            inset: 2px;
            border: 0.5px solid #99999944;
        }
        .theme-bold .mini-invoice {
            border: none;
            background: #fff;
            border-top: 4px solid #7b5ef0;
        }
        .theme-bold .mini-invoice::before {
            content: '';
            position: absolute;
            top: 4px; left: 0; right: 0;
            height: 28%;
            background: #1a1a2e;
        }
        .theme-professional .mini-invoice {
            border: 1px solid #d0d5dd;
            background: #fff;
            border-top: 3px solid #7b5ef0;
        }
        .theme-professional .mini-invoice::before {
            content: '';
            position: absolute;
            top: 20%; left: 0; right: 0;
            height: 15%;
            background: #f9fafb;
            border-top: 1px solid #e8e8e8;
            border-bottom: 1px solid #e8e8e8;
        }
        .theme-label {
            text-align: center;
            padding: 10px 8px 12px;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-main);
        }

        /* Color Scheme Picker */
        .color-grid {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }
        .color-swatch {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s;
            border: 3px solid transparent;
            position: relative;
        }
        .color-swatch:hover {
            transform: scale(1.12);
        }
        .color-swatch.selected {
            border-color: var(--text-main);
            box-shadow: 0 0 0 3px rgba(123,94,240,0.2);
        }
        .color-swatch.selected::after {
            content: '\f00c';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 14px;
            text-shadow: 0 1px 3px rgba(0,0,0,0.3);
        }
        .custom-color-wrap {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 8px;
        }
        .custom-color-wrap input[type="color"] {
            width: 44px;
            height: 44px;
            border: 2px solid var(--border);
            border-radius: 12px;
            cursor: pointer;
            padding: 2px;
        }

        /* Paper Size */
        .paper-select-group {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
        }
        .paper-option {
            flex: 1;
            text-align: center;
            padding: 14px 12px;
            background: var(--surface);
            border: 2px solid var(--border);
            border-radius: 14px;
            cursor: pointer;
            transition: all 0.2s;
            font-weight: 700;
            font-size: 14px;
            color: var(--text-muted);
        }
        .paper-option:hover {
            border-color: rgba(123,94,240,0.4);
        }
        .paper-option.selected {
            border-color: var(--primary);
            background: rgba(123,94,240,0.06);
            color: var(--primary);
        }
        .paper-option .paper-icon {
            font-size: 24px;
            margin-bottom: 6px;
            display: block;
        }

        /* ---- Field Configuration ---- */
        .field-section {
            background: var(--surface);
            border-radius: 18px;
            border: 1px solid var(--border);
            padding: 22px 24px;
            margin-bottom: 20px;
        }
        .field-section-title {
            font-size: 14px;
            font-weight: 800;
            color: var(--text-main);
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .field-section-title i {
            color: var(--primary);
            font-size: 16px;
        }
        .field-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 11px 0;
            border-bottom: 1px solid rgba(0,0,0,0.04);
        }
        .field-row:last-child {
            border-bottom: none;
        }
        .field-label {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .field-label i {
            width: 18px;
            text-align: center;
            color: var(--text-muted);
            font-size: 13px;
        }
        .field-hint {
            font-size: 11px;
            color: var(--text-muted);
            font-weight: 400;
            margin-left: 28px;
            margin-top: 2px;
        }

        /* Toggle Switch */
        .toggle-switch {
            position: relative;
            width: 46px;
            height: 26px;
            flex-shrink: 0;
        }
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background: #ddd;
            border-radius: 26px;
            transition: all 0.3s;
        }
        .toggle-slider::before {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            left: 3px;
            bottom: 3px;
            background: #fff;
            border-radius: 50%;
            transition: all 0.3s;
            box-shadow: 0 2px 4px rgba(0,0,0,0.15);
        }
        .toggle-switch input:checked + .toggle-slider {
            background: var(--primary);
        }
        .toggle-switch input:checked + .toggle-slider::before {
            transform: translateX(20px);
        }

        /* ---- Live Preview Panel (Right) ---- */
        .preview-panel {
            background: #e8ecf3;
            border-left: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .preview-header {
            padding: 16px 20px;
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .preview-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .preview-title i { color: var(--primary); }
        .preview-zoom {
            display: flex;
            gap: 6px;
        }
        .preview-zoom button {
            width: 30px;
            height: 30px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--surface);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            font-size: 12px;
            transition: all 0.2s;
        }
        .preview-zoom button:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        .preview-body {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            display: flex;
            justify-content: center;
        }
        .preview-invoice {
            width: 100%;
            max-width: 380px;
            background: #fff;
            box-shadow: 0 4px 24px rgba(0,0,0,0.12);
            transform-origin: top center;
            transition: transform 0.3s;
            font-size: 8px;
            font-family: 'Segoe UI', Arial, sans-serif;
            color: #1a1a2e;
            overflow: hidden;
        }

        /* Mini Invoice Preview Styles */
        .prev-border { border: 1.5px solid var(--inv-color, #1a1a2e); }
        .prev-title {
            text-align: center;
            font-size: 10px;
            font-weight: 700;
            padding: 5px;
            border-bottom: 1.5px solid var(--inv-color, #1a1a2e);
            background: #f8f8fc;
            letter-spacing: 1px;
        }
        .prev-header {
            display: flex;
            justify-content: space-between;
            padding: 8px 10px;
            border-bottom: 1.5px solid var(--inv-color, #1a1a2e);
            gap: 8px;
        }
        .prev-header-left { display: flex; align-items: flex-start; gap: 6px; }
        .prev-header-left img { max-height: 28px; max-width: 50px; object-fit: contain; }
        .prev-header-right { text-align: right; }
        .prev-header-right h3 { font-size: 11px; margin-bottom: 2px; }
        .prev-header-right p { font-size: 7px; line-height: 1.5; color: #555; }
        .prev-parties {
            display: grid;
            grid-template-columns: 1fr 1fr;
            border-bottom: 1.5px solid var(--inv-color, #1a1a2e);
        }
        .prev-parties > div { padding: 6px 10px; }
        .prev-parties > div:first-child { border-right: 1.5px solid var(--inv-color, #1a1a2e); }
        .prev-party-label { font-size: 6px; font-weight: 700; text-transform: uppercase; color: #888; margin-bottom: 2px; letter-spacing: 0.5px; }
        .prev-party-name { font-weight: 700; font-size: 8px; }
        .prev-party-sub { font-size: 6.5px; color: #666; line-height: 1.5; margin-top: 2px; }
        .prev-table { width: 100%; border-collapse: collapse; }
        .prev-table th {
            background: #f0eef8;
            font-size: 6px;
            padding: 4px 3px;
            text-align: center;
            font-weight: 700;
            text-transform: uppercase;
            border-bottom: 1.5px solid var(--inv-color, #1a1a2e);
            letter-spacing: 0.3px;
        }
        .prev-table td {
            padding: 4px 3px;
            text-align: center;
            font-size: 7px;
            border-bottom: 1px solid #eee;
        }
        .prev-table td:nth-child(2) { text-align: left; }
        .prev-table tfoot td { font-weight: 700; border-top: 1.5px solid var(--inv-color, #1a1a2e); background: #f8f8fc; }
        .prev-summary {
            display: flex;
            justify-content: flex-end;
            border-top: 1.5px solid var(--inv-color, #1a1a2e);
            padding: 6px 10px;
        }
        .prev-summary table td { padding: 2px 6px; font-size: 7px; }
        .prev-summary table td:last-child { text-align: right; font-weight: 600; }
        .prev-words {
            padding: 4px 10px;
            border-top: 1px solid #ddd;
            font-size: 6.5px;
        }
        .prev-words strong { display: block; font-size: 6px; text-transform: uppercase; margin-bottom: 1px; }
        .prev-footer {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            border-top: 1.5px solid var(--inv-color, #1a1a2e);
        }
        .prev-footer > div { padding: 6px 8px; font-size: 6.5px; line-height: 1.6; }
        .prev-footer > div:not(:last-child) { border-right: 1.5px solid var(--inv-color, #1a1a2e); }
        .prev-footer-label { font-weight: 700; font-size: 6px; text-transform: uppercase; margin-bottom: 3px; }
        .prev-sig-area { text-align: center; }
        .prev-sig-area img { max-height: 20px; margin: 3px 0; }
        .prev-sig-text { font-size: 6px; color: #888; border-top: 1px solid #bbb; display: inline-block; padding-top: 2px; margin-top: 3px; }
        .prev-ack {
            border-top: 1.5px dashed #999;
            padding: 6px 10px;
        }
        .prev-ack h4 { text-align: center; font-size: 7px; margin-bottom: 4px; }

        /* Thermal Preview */
        .thermal-preview .preview-invoice {
            max-width: 240px;
            font-size: 7px;
        }

        /* Success Toast */
        .save-toast {
            position: fixed;
            top: 24px;
            right: 24px;
            background: linear-gradient(135deg, #10b981, #059669);
            color: #fff;
            padding: 16px 28px;
            border-radius: 14px;
            font-weight: 700;
            font-size: 14px;
            box-shadow: 0 8px 30px rgba(16,185,129,0.35);
            z-index: 9999;
            display: none;
            animation: toastIn 0.4s ease;
        }
        .save-toast.show { display: flex; align-items: center; gap: 10px; }
        @keyframes toastIn {
            from { opacity: 0; transform: translateY(-20px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* Coming Soon overlay */
        .coming-soon-overlay {
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 60px 20px;
            text-align: center;
        }
        .coming-soon-overlay.active { display: flex; }
        .coming-soon-overlay i { font-size: 48px; color: var(--text-muted); margin-bottom: 16px; opacity: 0.4; }
        .coming-soon-overlay h3 { font-size: 18px; color: var(--text-muted); margin-bottom: 8px; }
        .coming-soon-overlay p { font-size: 13px; color: var(--text-muted); opacity: 0.7; }

        /* Responsive */
        @media (max-width: 1200px) {
            .inv-settings-wrapper {
                grid-template-columns: 200px 1fr;
            }
            .preview-panel { display: none; }
        }
        @media (max-width: 768px) {
            .inv-settings-wrapper {
                grid-template-columns: 1fr;
            }
            .settings-nav { display: none; }
        }
    </style>
</head>
<body>
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main-content" style="overflow:hidden;">
        <header class="topbar">
            <div class="breadcrumb">Settings / <span class="current">Invoice Settings</span></div>
            <div class="topbar-right">
                <button form="invoiceSettingsForm" class="btn-primary" style="width:auto;padding:12px 28px;">
                    <i class="fa-solid fa-check"></i> SAVE SETTINGS
                </button>
            </div>
        </header>

        <?php if(isset($_GET['success'])): ?>
            <div class="save-toast show" id="saveToast">
                <i class="fa-solid fa-circle-check"></i> Invoice settings saved successfully!
            </div>
        <?php endif; ?>

        <form id="invoiceSettingsForm" method="POST">
        <input type="hidden" name="printer_type" id="printerTypeInput" value="<?= htmlspecialchars($settings['printer_type']) ?>">
        <input type="hidden" name="layout_theme" id="layoutThemeInput" value="<?= htmlspecialchars($settings['layout_theme']) ?>">
        <input type="hidden" name="color_scheme" id="colorSchemeInput" value="<?= htmlspecialchars($settings['color_scheme']) ?>">
        <input type="hidden" name="paper_size" id="paperSizeInput" value="<?= htmlspecialchars($settings['paper_size']) ?>">

        <div class="inv-settings-wrapper">
            <!-- ===== LEFT: Settings Nav ===== -->
            <div class="settings-nav">
                <div class="settings-nav-title">Configure</div>
                <div class="settings-nav-item active" data-section="print" onclick="switchSection('print', this)">
                    <i class="fa-solid fa-print"></i> Print Settings
                </div>
                <div class="settings-nav-item" data-section="fields" onclick="switchSection('fields', this)">
                    <i class="fa-solid fa-sliders"></i> Field Configuration
                </div>
                <div class="settings-nav-item" data-section="table" onclick="switchSection('table', this)">
                    <i class="fa-solid fa-table-columns"></i> Table Columns
                </div>
                <div class="settings-nav-item" data-section="footer" onclick="switchSection('footer', this)">
                    <i class="fa-solid fa-shoe-prints"></i> Footer Sections
                </div>
                <div class="settings-nav-item" data-section="other" onclick="switchSection('other', this)">
                    <i class="fa-solid fa-gear"></i> Other Options
                </div>
            </div>

            <!-- ===== CENTER: Settings Panels ===== -->
            <div class="settings-content">

                <!-- PRINT SETTINGS -->
                <div class="settings-section active" id="section-print">
                    <div class="section-heading">Print Settings</div>
                    <div class="section-desc">Choose your printer type, invoice layout theme, color scheme, and paper size.</div>

                    <!-- Printer Type Tabs -->
                    <div class="printer-tabs">
                        <div class="printer-tab <?= $settings['printer_type'] === 'regular' ? 'active' : '' ?>" onclick="selectPrinterType('regular', this)">
                            <i class="fa-solid fa-print"></i> Regular Printer
                        </div>
                        <div class="printer-tab <?= $settings['printer_type'] === 'thermal' ? 'active' : '' ?>" onclick="selectPrinterType('thermal', this)">
                            <i class="fa-solid fa-receipt"></i> Thermal Printer
                        </div>
                    </div>

                    <!-- Regular Printer Content -->
                    <div id="regularContent" style="<?= $settings['printer_type'] === 'thermal' ? 'display:none' : '' ?>">
                        <!-- Sub-tabs: Layout / Colors -->
                        <div class="sub-tabs">
                            <div class="sub-tab active" onclick="switchSubTab('layout', this)">
                                <i class="fa-solid fa-table-cells-large"></i> Change Layout
                            </div>
                            <div class="sub-tab" onclick="switchSubTab('colors', this)">
                                <i class="fa-solid fa-palette"></i> Change Colors
                            </div>
                        </div>

                        <!-- Layout Sub-panel -->
                        <div class="sub-panel active" id="subpanel-layout">
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                                <span style="font-size:13px;font-weight:700;color:var(--text-muted);">Select Invoice Theme</span>
                                <div style="display:flex;gap:6px;">
                                    <button type="button" onclick="scrollThemes(-1)" style="width:30px;height:30px;border:1px solid var(--border);border-radius:8px;background:var(--surface);cursor:pointer;display:flex;align-items:center;justify-content:center;"><i class="fa-solid fa-chevron-left" style="font-size:11px;color:var(--text-muted);"></i></button>
                                    <button type="button" onclick="scrollThemes(1)" style="width:30px;height:30px;border:1px solid var(--border);border-radius:8px;background:var(--surface);cursor:pointer;display:flex;align-items:center;justify-content:center;"><i class="fa-solid fa-chevron-right" style="font-size:11px;color:var(--text-muted);"></i></button>
                                </div>
                            </div>
                            <div class="theme-grid" id="themeGrid">
                                <?php
                                $themes = [
                                    'classic' => ['label' => 'Classic', 'class' => 'theme-classic'],
                                    'modern' => ['label' => 'Modern', 'class' => 'theme-modern'],
                                    'minimal' => ['label' => 'Minimal', 'class' => 'theme-minimal'],
                                    'bordered' => ['label' => 'Bordered', 'class' => 'theme-bordered'],
                                    'compact' => ['label' => 'Compact', 'class' => 'theme-compact'],
                                    'elegant' => ['label' => 'Elegant', 'class' => 'theme-elegant'],
                                    'bold' => ['label' => 'Bold', 'class' => 'theme-bold'],
                                    'professional' => ['label' => 'Professional', 'class' => 'theme-professional'],
                                ];
                                foreach ($themes as $key => $theme):
                                ?>
                                <div class="theme-card <?= $theme['class'] ?> <?= $settings['layout_theme'] === $key ? 'selected' : '' ?>" onclick="selectTheme('<?= $key ?>', this)">
                                    <div class="theme-thumb">
                                        <div class="mini-invoice"></div>
                                    </div>
                                    <div class="theme-label"><?= $theme['label'] ?></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Colors Sub-panel -->
                        <div class="sub-panel" id="subpanel-colors">
                            <div style="font-size:13px;font-weight:700;color:var(--text-muted);margin-bottom:14px;">Invoice Accent Color</div>
                            <div class="color-grid">
                                <?php
                                $colors = ['#1a1a2e','#7b5ef0','#2563eb','#059669','#dc2626','#ea580c','#7c3aed','#0891b2','#4f46e5','#be185d'];
                                foreach ($colors as $color):
                                ?>
                                <div class="color-swatch <?= $settings['color_scheme'] === $color ? 'selected' : '' ?>" style="background:<?= $color ?>;" onclick="selectColor('<?= $color ?>', this)"></div>
                                <?php endforeach; ?>
                            </div>
                            <div class="custom-color-wrap">
                                <input type="color" id="customColorPicker" value="<?= htmlspecialchars($settings['color_scheme']) ?>" onchange="selectColor(this.value, null)">
                                <span style="font-size:13px;font-weight:600;color:var(--text-muted);">Custom Color</span>
                            </div>
                        </div>

                        <!-- Paper Size -->
                        <div style="margin-top:28px;">
                            <div style="font-size:13px;font-weight:700;color:var(--text-muted);margin-bottom:14px;">Paper Size</div>
                            <div class="paper-select-group">
                                <?php foreach (['A4','A5','Letter'] as $size): ?>
                                <div class="paper-option <?= $settings['paper_size'] === $size ? 'selected' : '' ?>" onclick="selectPaper('<?= $size ?>', this)">
                                    <span class="paper-icon"><i class="fa-regular fa-file"></i></span>
                                    <?= $size ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Thermal Printer Content -->
                    <div id="thermalContent" style="<?= $settings['printer_type'] === 'regular' ? 'display:none' : '' ?>">
                        <div class="coming-soon-overlay active">
                            <i class="fa-solid fa-receipt"></i>
                            <h3>Thermal Printer Support</h3>
                            <p>Thermal printer templates are coming soon. Configure your regular printer settings for now.</p>
                        </div>
                    </div>
                </div>

                <!-- FIELD CONFIGURATION -->
                <div class="settings-section" id="section-fields">
                    <div class="section-heading">Field Configuration</div>
                    <div class="section-desc">Toggle which company information fields appear on your printed invoices.</div>

                    <div class="field-section">
                        <div class="field-section-title"><i class="fa-solid fa-building"></i> Print Company Info / Header</div>

                        <?php
                        $headerFields = [
                            ['name' => 'show_company_name', 'label' => 'Company Name', 'icon' => 'fa-building', 'hint' => 'Display your business name at the top'],
                            ['name' => 'show_logo', 'label' => 'Company Logo', 'icon' => 'fa-image', 'hint' => 'Show your company logo in the header'],
                            ['name' => 'show_address', 'label' => 'Address', 'icon' => 'fa-location-dot', 'hint' => 'Show registered business address'],
                            ['name' => 'show_email', 'label' => 'Email', 'icon' => 'fa-envelope', 'hint' => 'Display business email'],
                            ['name' => 'show_phone', 'label' => 'Phone Number', 'icon' => 'fa-phone', 'hint' => 'Display business phone number'],
                            ['name' => 'show_gstin', 'label' => 'GSTIN on Invoice', 'icon' => 'fa-id-card', 'hint' => 'Show GST identification number'],
                        ];
                        foreach ($headerFields as $field):
                        ?>
                        <div class="field-row">
                            <div>
                                <div class="field-label"><i class="fa-solid <?= $field['icon'] ?>"></i> <?= $field['label'] ?></div>
                                <div class="field-hint"><?= $field['hint'] ?></div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="<?= $field['name'] ?>" <?= $settings[$field['name']] ? 'checked' : '' ?> onchange="updatePreview()">
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- TABLE COLUMNS -->
                <div class="settings-section" id="section-table">
                    <div class="section-heading">Table Columns</div>
                    <div class="section-desc">Configure which columns appear in the invoice items table.</div>

                    <div class="field-section">
                        <div class="field-section-title"><i class="fa-solid fa-table"></i> Item Table Fields</div>

                        <div class="field-row">
                            <div>
                                <div class="field-label"><i class="fa-solid fa-barcode"></i> HSN/SAC Code</div>
                                <div class="field-hint">Show HSN/SAC code column in the items table</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="show_hsn" <?= $settings['show_hsn'] ? 'checked' : '' ?> onchange="updatePreview()">
                                <span class="toggle-slider"></span>
                            </label>
                        </div>

                        <div class="field-row">
                            <div>
                                <div class="field-label"><i class="fa-solid fa-boxes-stacked"></i> Batch / MFG / EXP Info</div>
                                <div class="field-hint">Show Batch No., Manufacturing & Expiry Dates</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="show_batch_info" <?= $settings['show_batch_info'] ? 'checked' : '' ?> onchange="updatePreview()">
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- FOOTER SECTIONS -->
                <div class="settings-section" id="section-footer">
                    <div class="section-heading">Footer Sections</div>
                    <div class="section-desc">Control which sections appear at the bottom of your invoices.</div>

                    <div class="field-section">
                        <div class="field-section-title"><i class="fa-solid fa-shoe-prints"></i> Invoice Footer</div>

                        <div class="field-row">
                            <div>
                                <div class="field-label"><i class="fa-solid fa-landmark"></i> Bank Details</div>
                                <div class="field-hint">Show bank name, account number, IFSC code</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="show_bank_details" <?= $settings['show_bank_details'] ? 'checked' : '' ?> onchange="updatePreview()">
                                <span class="toggle-slider"></span>
                            </label>
                        </div>

                        <div class="field-row">
                            <div>
                                <div class="field-label"><i class="fa-solid fa-file-contract"></i> Terms & Conditions</div>
                                <div class="field-hint">Show terms and conditions section</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="show_terms" <?= $settings['show_terms'] ? 'checked' : '' ?> onchange="updatePreview()">
                                <span class="toggle-slider"></span>
                            </label>
                        </div>

                        <div class="field-row">
                            <div>
                                <div class="field-label"><i class="fa-solid fa-signature"></i> Authorized Signature</div>
                                <div class="field-hint">Show signature and authorization area</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="show_signature" <?= $settings['show_signature'] ? 'checked' : '' ?> onchange="updatePreview()">
                                <span class="toggle-slider"></span>
                            </label>
                        </div>

                        <div class="field-row">
                            <div>
                                <div class="field-label"><i class="fa-solid fa-clipboard-check"></i> Acknowledgement Section</div>
                                <div class="field-hint">Show tear-off acknowledgement stub at bottom</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="show_acknowledgement" <?= $settings['show_acknowledgement'] ? 'checked' : '' ?> onchange="updatePreview()">
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- OTHER OPTIONS -->
                <div class="settings-section" id="section-other">
                    <div class="section-heading">Other Options</div>
                    <div class="section-desc">Additional print behaviour settings.</div>

                    <div class="field-section">
                        <div class="field-section-title"><i class="fa-solid fa-gear"></i> Print Behaviour</div>

                        <div class="field-row">
                            <div>
                                <div class="field-label"><i class="fa-solid fa-copy"></i> Repeat Header on All Pages</div>
                                <div class="field-hint">For multi-page invoices, repeat the company header on each page</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="repeat_header" <?= $settings['repeat_header'] ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>

                        <div class="field-row">
                            <div>
                                <div class="field-label"><i class="fa-solid fa-star"></i> Make Regular Printer Default</div>
                                <div class="field-hint">Set regular printer as the default option when printing</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="default_printer" <?= $settings['default_printer'] ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== RIGHT: Live Preview ===== -->
            <div class="preview-panel" id="previewPanel">
                <div class="preview-header">
                    <div class="preview-title"><i class="fa-solid fa-eye"></i> Live Preview</div>
                    <div class="preview-zoom">
                        <button type="button" onclick="zoomPreview(-0.1)"><i class="fa-solid fa-minus"></i></button>
                        <button type="button" onclick="zoomPreview(0.1)"><i class="fa-solid fa-plus"></i></button>
                    </div>
                </div>
                <div class="preview-body">
                    <div class="preview-invoice prev-border" id="previewInvoice" style="--inv-color: <?= htmlspecialchars($settings['color_scheme']) ?>;">
                        <!-- Title -->
                        <div class="prev-title">Tax Invoice</div>

                        <!-- Header -->
                        <div class="prev-header" id="prevHeader">
                            <div class="prev-header-left">
                                <img src="<?= $bizLogo ?>?v=<?= $v ?>" alt="Logo" id="prevLogo" onerror="this.style.display='none'">
                            </div>
                            <div class="prev-header-right">
                                <h3 id="prevCompanyName"><?= $bizName ?></h3>
                                <p>
                                    <span id="prevAddress"><?= $bizAddr ?></span><br>
                                    <span id="prevPhone">Ph: <?= $bizPhone ?></span>
                                    <span id="prevEmail"> Email: <?= $bizEmail ?></span><br>
                                    <span id="prevGstin">GSTIN: <?= $bizGstin ?></span>
                                </p>
                            </div>
                        </div>

                        <!-- Parties -->
                        <div class="prev-parties">
                            <div>
                                <div class="prev-party-label">Bill To</div>
                                <div class="prev-party-name">Sample Customer</div>
                                <div class="prev-party-sub">123 Customer Street<br>City, State - 600001</div>
                            </div>
                            <div>
                                <div class="prev-party-label">Invoice Details</div>
                                <div class="prev-party-sub">
                                    <strong>Invoice No.:</strong> INV-001<br>
                                    <strong>Date:</strong> <?= date('d-m-Y') ?><br>
                                    <strong>Due Date:</strong> <?= date('d-m-Y', strtotime('+30 days')) ?>
                                </div>
                            </div>
                        </div>

                        <!-- Items Table -->
                        <table class="prev-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Item</th>
                                    <th id="prevHsnHeader">HSN</th>
                                    <th>Qty</th>
                                    <th>Rate</th>
                                    <th>CGST</th>
                                    <th>SGST</th>
                                    <th>Amt</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>1</td>
                                    <td>Product Alpha</td>
                                    <td class="prevHsnCol">3004</td>
                                    <td>10</td>
                                    <td>₹500</td>
                                    <td>₹450<br><span style="font-size:5px;color:#888;">9%</span></td>
                                    <td>₹450<br><span style="font-size:5px;color:#888;">9%</span></td>
                                    <td style="font-weight:600;">₹5,900</td>
                                </tr>
                                <tr>
                                    <td>2</td>
                                    <td>Product Beta</td>
                                    <td class="prevHsnCol">3003</td>
                                    <td>5</td>
                                    <td>₹200</td>
                                    <td>₹90<br><span style="font-size:5px;color:#888;">9%</span></td>
                                    <td>₹90<br><span style="font-size:5px;color:#888;">9%</span></td>
                                    <td style="font-weight:600;">₹1,180</td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3">Total</td>
                                    <td>15</td>
                                    <td></td>
                                    <td>₹540</td>
                                    <td>₹540</td>
                                    <td style="font-weight:700;">₹7,080</td>
                                </tr>
                            </tfoot>
                        </table>

                        <!-- Summary -->
                        <div class="prev-summary">
                            <table>
                                <tr><td>Sub Total</td><td>₹ 6,000.00</td></tr>
                                <tr><td>Tax (CGST + SGST)</td><td>₹ 1,080.00</td></tr>
                                <tr><td><strong>Grand Total</strong></td><td><strong>₹ 7,080.00</strong></td></tr>
                            </table>
                        </div>

                        <!-- Amount in Words -->
                        <div class="prev-words">
                            <strong>Amount in Words</strong>
                            Seven Thousand Eighty Rupees only
                        </div>

                        <!-- Footer -->
                        <div class="prev-footer" id="prevFooter">
                            <div id="prevBankDetails">
                                <div class="prev-footer-label">Bank Details</div>
                                <?= $bankName ?><br>
                                A/C: <?= $accountNo ?><br>
                                IFSC: <?= $ifscCode ?>
                            </div>
                            <div id="prevTerms">
                                <div class="prev-footer-label">Terms & Conditions</div>
                                <?= $terms ?>
                            </div>
                            <div class="prev-sig-area" id="prevSignature">
                                <div class="prev-footer-label">For: <?= $bizName ?></div>
                                <?php if ($sigPath): ?>
                                    <img src="<?= $sigPath ?>" alt="Signature">
                                <?php else: ?>
                                    <div style="height:20px;"></div>
                                <?php endif; ?>
                                <div class="prev-sig-text">Authorized Signatory</div>
                            </div>
                        </div>

                        <!-- Acknowledgement -->
                        <div class="prev-ack" id="prevAck">
                            <h4>Acknowledgement — <?= $bizName ?></h4>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:6.5px;">
                                <div>
                                    <div style="font-size:6px;font-weight:700;text-transform:uppercase;color:#888;">Invoice To:</div>
                                    <strong>Sample Customer</strong><br>
                                    123 Customer Street
                                </div>
                                <div style="text-align:right;">
                                    <div style="font-size:6px;font-weight:700;text-transform:uppercase;color:#888;">Invoice Details:</div>
                                    INV-001 | <?= date('d-m-Y') ?><br>
                                    Amount: ₹ 7,080.00
                                    <div style="border-top:1px dashed #999;display:inline-block;padding-top:2px;margin-top:8px;font-size:6px;color:#888;">Receiver's Seal & Sign</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        </form>
    </main>
</div>

<script>
    // Auto-dismiss toast
    const toast = document.getElementById('saveToast');
    if (toast) setTimeout(() => { toast.classList.remove('show'); }, 3000);

    // Section switching
    function switchSection(sectionId, el) {
        document.querySelectorAll('.settings-section').forEach(s => s.classList.remove('active'));
        document.querySelectorAll('.settings-nav-item').forEach(n => n.classList.remove('active'));
        document.getElementById('section-' + sectionId).classList.add('active');
        el.classList.add('active');
    }

    // Printer type
    function selectPrinterType(type, el) {
        document.getElementById('printerTypeInput').value = type;
        document.querySelectorAll('.printer-tab').forEach(t => t.classList.remove('active'));
        el.classList.add('active');
        document.getElementById('regularContent').style.display = type === 'regular' ? '' : 'none';
        document.getElementById('thermalContent').style.display = type === 'thermal' ? '' : 'none';
    }

    // Sub-tabs
    function switchSubTab(tab, el) {
        document.querySelectorAll('.sub-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.sub-panel').forEach(p => p.classList.remove('active'));
        el.classList.add('active');
        document.getElementById('subpanel-' + tab).classList.add('active');
    }

    // Theme selection
    function selectTheme(theme, el) {
        document.getElementById('layoutThemeInput').value = theme;
        document.querySelectorAll('.theme-card').forEach(c => c.classList.remove('selected'));
        el.classList.add('selected');
        updatePreview();
    }

    // Scroll themes
    function scrollThemes(direction) {
        const grid = document.getElementById('themeGrid');
        grid.scrollBy({ left: direction * 160, behavior: 'smooth' });
    }

    // Color selection
    function selectColor(color, el) {
        document.getElementById('colorSchemeInput').value = color;
        document.getElementById('customColorPicker').value = color;
        document.querySelectorAll('.color-swatch').forEach(s => s.classList.remove('selected'));
        if (el) el.classList.add('selected');
        document.getElementById('previewInvoice').style.setProperty('--inv-color', color);
        updatePreview();
    }

    // Paper size
    function selectPaper(size, el) {
        document.getElementById('paperSizeInput').value = size;
        document.querySelectorAll('.paper-option').forEach(p => p.classList.remove('selected'));
        el.classList.add('selected');
    }

    // Zoom
    let currentZoom = 1;
    function zoomPreview(delta) {
        currentZoom = Math.max(0.5, Math.min(1.5, currentZoom + delta));
        document.getElementById('previewInvoice').style.transform = 'scale(' + currentZoom + ')';
    }

    // Live Preview Update
    function updatePreview() {
        const get = name => {
            const el = document.querySelector('input[name="' + name + '"]');
            return el ? el.checked : false;
        };

        // Header fields
        const prevCompanyName = document.getElementById('prevCompanyName');
        const prevLogo = document.getElementById('prevLogo');
        const prevAddress = document.getElementById('prevAddress');
        const prevEmail = document.getElementById('prevEmail');
        const prevPhone = document.getElementById('prevPhone');
        const prevGstin = document.getElementById('prevGstin');

        if (prevCompanyName) prevCompanyName.style.display = get('show_company_name') ? '' : 'none';
        if (prevLogo) prevLogo.style.display = get('show_logo') ? '' : 'none';
        if (prevAddress) prevAddress.style.display = get('show_address') ? '' : 'none';
        if (prevEmail) prevEmail.style.display = get('show_email') ? '' : 'none';
        if (prevPhone) prevPhone.style.display = get('show_phone') ? '' : 'none';
        if (prevGstin) prevGstin.style.display = get('show_gstin') ? '' : 'none';

        // HSN columns
        const hsnHeader = document.getElementById('prevHsnHeader');
        const hsnCols = document.querySelectorAll('.prevHsnCol');
        const showHsn = get('show_hsn');
        if (hsnHeader) hsnHeader.style.display = showHsn ? '' : 'none';
        hsnCols.forEach(c => c.style.display = showHsn ? '' : 'none');

        // Footer sections
        const prevBankDetails = document.getElementById('prevBankDetails');
        const prevTerms = document.getElementById('prevTerms');
        const prevSignature = document.getElementById('prevSignature');
        const prevAck = document.getElementById('prevAck');
        const prevFooter = document.getElementById('prevFooter');

        const showBank = get('show_bank_details');
        const showTerms = get('show_terms');
        const showSig = get('show_signature');
        const showAck = get('show_acknowledgement');

        if (prevBankDetails) prevBankDetails.style.display = showBank ? '' : 'none';
        if (prevTerms) prevTerms.style.display = showTerms ? '' : 'none';
        if (prevSignature) prevSignature.style.display = showSig ? '' : 'none';
        if (prevAck) prevAck.style.display = showAck ? '' : 'none';

        // Adjust footer grid
        if (prevFooter) {
            const visibleCols = [showBank, showTerms, showSig].filter(Boolean).length;
            if (visibleCols === 0) {
                prevFooter.style.display = 'none';
            } else {
                prevFooter.style.display = 'grid';
                prevFooter.style.gridTemplateColumns = 'repeat(' + visibleCols + ', 1fr)';
            }
        }
    }

    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        sidebar.classList.toggle('sidebar-collapsed');
    }

    // Initial preview state
    updatePreview();
</script>
</body>
</html>
