<?php
require_once 'auth_check.php';
require_once 'includes/commerce.php';
require_once 'includes/brand.php';

$context = commerce_get_tenant_context();
$conn = $context['conn'];
$prefix = $context['prefix'];
commerce_ensure_tables($conn, $prefix);

// Fetch invoice
$invoiceId = (int) ($_GET['id'] ?? 0);
$detail = commerce_fetch_invoice_detail($conn, $prefix, $invoiceId);
if (!$detail) {
    http_response_code(404);
    exit('Invoice not found');
}

$invoice = $detail['invoice'];
$items = $detail['items'];

// Fetch business profile
$profileStmt = $conn->query("SELECT * FROM {$prefix}business_profile WHERE id = 1");
$biz = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$bizName = htmlspecialchars($biz['business_name'] ?? $companyName);
$bizAddr = htmlspecialchars($biz['address'] ?? '');
$bizPhone = htmlspecialchars($biz['phone'] ?? '');
$bizEmail = htmlspecialchars($biz['email'] ?? '');
$bizGstin = htmlspecialchars($biz['gstin'] ?? '');
$bizLogo = !empty($biz['logo_path']) ? '/' . $biz['logo_path'] : $companyLogo;
$bankName = htmlspecialchars($biz['bank_name'] ?? '');
$accountNo = htmlspecialchars($biz['account_no'] ?? '');
$ifscCode = htmlspecialchars($biz['ifsc_code'] ?? '');
$terms = htmlspecialchars($biz['terms'] ?? 'Thanks for doing business with us!');
$sigPath = !empty($biz['signature_path']) ? '/' . $biz['signature_path'] : '';

// Fetch invoice print settings
$invSettingsStmt = $conn->query("SELECT * FROM {$prefix}invoice_settings WHERE id = 1");
$invSettings = $invSettingsStmt->fetch(PDO::FETCH_ASSOC);
if (!$invSettings) {
    $invSettings = [
        'printer_type' => 'regular', 'layout_theme' => 'classic',
        'color_scheme' => '#1a1a2e', 'paper_size' => 'A4',
        'show_company_name' => 1, 'show_logo' => 1, 'show_address' => 1,
        'show_email' => 1, 'show_phone' => 1, 'show_gstin' => 1,
        'show_bank_details' => 1, 'show_terms' => 1, 'show_signature' => 1,
        'show_acknowledgement' => 1, 'show_hsn' => 1, 'show_batch_info' => 0,
        'repeat_header' => 0, 'default_printer' => 1,
    ];
}

$C = htmlspecialchars($invSettings['color_scheme']);
$T = htmlspecialchars($invSettings['layout_theme']);
$paperSize = htmlspecialchars($invSettings['paper_size']);
$showCompanyName = (int) $invSettings['show_company_name'];
$showLogo = (int) $invSettings['show_logo'];
$showAddress = (int) $invSettings['show_address'];
$showEmail = (int) $invSettings['show_email'];
$showPhone = (int) $invSettings['show_phone'];
$showGstin = (int) $invSettings['show_gstin'];
$showBankDetails = (int) $invSettings['show_bank_details'];
$showTerms = (int) $invSettings['show_terms'];
$showSignature = (int) $invSettings['show_signature'];
$showAck = (int) $invSettings['show_acknowledgement'];
$showHsn = (int) $invSettings['show_hsn'];
$showBatchInfo = (int) $invSettings['show_batch_info'];
$repeatHeader = (int) $invSettings['repeat_header'];
$footerCols = ($showBankDetails ? 1 : 0) + ($showTerms ? 1 : 0) + ($showSignature ? 1 : 0);

$pageCss = match($paperSize) {
    'A5'     => '@page { size: A5 portrait; margin: 8mm; }',
    'Letter' => '@page { size: letter portrait; margin: 10mm; }',
    default  => '@page { size: A4 portrait; margin: 10mm; }',
};
$maxW = $paperSize === 'A5' ? '580px' : '820px';

function numberToWords($num) {
    $ones = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten',
             'Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
    $tens = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    $num = (int) round($num);
    if ($num === 0) return 'Zero';
    if ($num < 0) return 'Negative ' . numberToWords(-$num);
    $w = '';
    if (intval($num / 10000000)) { $w .= numberToWords(intval($num / 10000000)) . ' Crore '; $num %= 10000000; }
    if (intval($num / 100000)) { $w .= numberToWords(intval($num / 100000)) . ' Lakh '; $num %= 100000; }
    if (intval($num / 1000)) { $w .= numberToWords(intval($num / 1000)) . ' Thousand '; $num %= 1000; }
    if (intval($num / 100)) { $w .= $ones[intval($num / 100)] . ' Hundred '; $num %= 100; }
    if ($num > 0) {
        if ($w != '') $w .= 'and ';
        if ($num < 20) { $w .= $ones[$num]; }
        else { $w .= $tens[intval($num / 10)]; if ($num % 10) $w .= ' ' . $ones[$num % 10]; }
    }
    return trim($w);
}

$grandTotal = (float) $invoice['grand_total'];
$totalInWords = numberToWords($grandTotal) . ' Rupees only';
$paidAmount = (float) ($invoice['paid_amount'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tax Invoice - <?= htmlspecialchars($invoice['invoice_number']) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        <?= $pageCss ?>

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', Arial, sans-serif; background: #e8ecf3; color: #1a1a2e; font-size: 13px; }

        /* Toolbar */
        .no-print { display: flex; justify-content: center; gap: 12px; padding: 16px; background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
        .no-print a, .no-print button { padding: 10px 22px; border-radius: 10px; font-weight: 600; font-size: 14px; cursor: pointer; text-decoration: none; border: none; display: inline-flex; align-items: center; gap: 8px; }
        .no-print .btn-back { background: #f1f5f9; color: #475569; }
        .no-print .btn-print { background: <?= $C ?>; color: #fff; }
        .no-print .btn-settings { background: <?= $C ?>12; color: <?= $C ?>; }

        /* Page container */
        .invoice-page { max-width: <?= $maxW ?>; margin: 24px auto; background: #fff; box-shadow: 0 4px 24px rgba(0,0,0,.1); overflow: hidden; }

        <?php if ($paperSize === 'A5'): ?>
        .invoice-page { font-size: 11px; }
        .inv-header-right h2 { font-size: 16px !important; }
        .inv-table th, .inv-table td { padding: 7px 5px !important; font-size: 10px !important; }
        .inv-footer > div { font-size: 10px !important; padding: 10px 14px !important; }
        <?php endif; ?>

        /* =====================================================
           COMMON LAYOUT (shared across all themes)
           ===================================================== */
        .inv-header { display: flex; justify-content: space-between; align-items: flex-start; padding: 20px 24px; }
        .inv-header-left { display: flex; align-items: flex-start; gap: 16px; }
        .inv-header-left img { max-height: 56px; }
        .inv-header-right { text-align: right; }
        .inv-header-right h2 { font-size: 20px; font-weight: 800; margin-bottom: 4px; }
        .inv-header-right p { font-size: 12px; line-height: 1.6; color: #555; }
        .inv-title { text-align: center; font-size: 16px; font-weight: 700; padding: 8px; letter-spacing: 1px; }
        .inv-parties { display: grid; grid-template-columns: 1fr 1fr; }
        .inv-parties > div { padding: 14px 24px; }
        .inv-parties .label { font-size: 11px; font-weight: 700; text-transform: uppercase; color: #666; margin-bottom: 6px; letter-spacing: .8px; }
        .inv-parties .value { font-weight: 700; font-size: 14px; }
        .inv-parties .sub { font-size: 12px; color: #555; line-height: 1.6; margin-top: 4px; }
        .inv-table { width: 100%; border-collapse: collapse; }
        .inv-table th { font-size: 11px; text-transform: uppercase; letter-spacing: .5px; padding: 10px 8px; text-align: center; font-weight: 700; }
        .inv-table td { padding: 10px 8px; text-align: center; font-size: 12px; }
        .inv-table td:nth-child(2) { text-align: left; }
        .inv-table tfoot td { font-weight: 700; }
        .inv-summary-row { display: flex; justify-content: flex-end; }
        .inv-summary-table { width: 360px; }
        .inv-summary-table td { padding: 8px 16px; font-size: 13px; }
        .inv-summary-table td:last-child { text-align: right; font-weight: 600; }
        .inv-summary-table tr:last-child td { font-weight: 700; font-size: 15px; }
        .inv-words { padding: 12px 24px; font-size: 12px; }
        .inv-words strong { display: block; margin-bottom: 2px; font-size: 11px; text-transform: uppercase; }
        .inv-footer { display: grid; grid-template-columns: repeat(<?= max($footerCols, 1) ?>, 1fr); }
        .inv-footer > div { padding: 16px 20px; font-size: 12px; line-height: 1.7; }
        .inv-footer .footer-label { font-weight: 700; font-size: 11px; text-transform: uppercase; margin-bottom: 8px; }
        .inv-footer .sig-area { text-align: center; }
        .inv-footer .sig-area img { max-height: 50px; margin: 8px 0; }
        .inv-footer .sig-area .sig-text { font-size: 11px; color: #666; border-top: 1px solid #aaa; display: inline-block; padding-top: 4px; margin-top: 8px; }
        .inv-ack { padding: 16px 24px; }
        .inv-ack h4 { text-align: center; margin-bottom: 10px; font-size: 13px; }
        .inv-ack-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; font-size: 12px; }
        .inv-ack-grid .ack-label { font-size: 11px; font-weight: 700; text-transform: uppercase; color: #666; }
        .inv-ack-right { text-align: right; }
        .inv-ack-right .sig-line { border-top: 1px dashed #999; display: inline-block; padding-top: 4px; margin-top: 30px; font-size: 11px; color: #666; }

        /* =====================================================
           THEME-SPECIFIC STYLES
           ===================================================== */

        <?php if ($T === 'classic'): ?>
        /* ─── CLASSIC: Traditional bordered invoice ─── */
        .invoice-page { border: 2px solid <?= $C ?>; }
        .inv-title { background: #f8f8fc; border-bottom: 2px solid <?= $C ?>; color: #333; }
        .inv-header { border-bottom: 2px solid <?= $C ?>; }
        .inv-parties { border-bottom: 2px solid <?= $C ?>; }
        .inv-parties > div:first-child { border-right: 2px solid <?= $C ?>; }
        .inv-table th { background: #f0eef8; color: #333; border-bottom: 2px solid <?= $C ?>; }
        .inv-table td { border-bottom: 1px solid #e0e0e0; }
        .inv-table tfoot td { border-top: 2px solid <?= $C ?>; background: #f8f8fc; }
        .inv-summary { border-top: 2px solid <?= $C ?>; }
        .inv-summary-table tr:last-child td { border-top: 2px solid <?= $C ?>; }
        .inv-words { border-top: 1px solid #ddd; }
        .inv-footer { border-top: 2px solid <?= $C ?>; }
        .inv-footer > div:not(:last-child) { border-right: 2px solid <?= $C ?>; }
        .inv-footer .footer-label { color: #333; }
        .inv-ack { border-top: 2px dashed #999; }
        <?php endif; ?>

        <?php if ($T === 'modern'): ?>
        /* ─── MODERN: Gradient header, rounded, contemporary ─── */
        .invoice-page { border: none; border-radius: 16px; box-shadow: 0 8px 40px rgba(0,0,0,.12); }
        .inv-title { background: linear-gradient(135deg, <?= $C ?>, <?= $C ?>cc); color: #fff; border: none; font-size: 18px; padding: 14px; letter-spacing: 3px; font-weight: 800; }
        .inv-header { border-bottom: none; background: linear-gradient(180deg, <?= $C ?>0d 0%, transparent 100%); padding: 24px 28px; }
        .inv-header-right h2 { color: <?= $C ?>; }
        .inv-parties { border-bottom: none; background: #fafafa; border-top: 1px solid #eee; border-bottom: 1px solid #eee; }
        .inv-parties > div:first-child { border-right: 1px solid #e0e0e0; }
        .inv-table th { background: <?= $C ?>; color: #fff; border: none; font-size: 10px; padding: 12px 8px; }
        .inv-table td { border-bottom: 1px solid #f0f0f5; }
        .inv-table tfoot td { border-top: 2px solid <?= $C ?>; background: <?= $C ?>08; }
        .inv-summary { border-top: none; background: <?= $C ?>05; padding: 8px 0; }
        .inv-summary-table tr:last-child td { border-top: 2px solid <?= $C ?>; color: <?= $C ?>; font-size: 16px; }
        .inv-words { border-top: 1px solid #eee; background: #fafafa; }
        .inv-footer { border-top: 3px solid <?= $C ?>; }
        .inv-footer > div:not(:last-child) { border-right: 1px solid #e0e0e0; }
        .inv-footer .footer-label { color: <?= $C ?>; }
        .inv-ack { border-top: 2px dashed <?= $C ?>66; background: <?= $C ?>04; }
        <?php endif; ?>

        <?php if ($T === 'minimal'): ?>
        /* ─── MINIMAL: Ultra clean, whitespace-heavy ─── */
        .invoice-page { border: none; border-top: 5px solid <?= $C ?>; }
        .inv-title { background: transparent; border-bottom: none; font-weight: 600; color: #999; font-size: 14px; letter-spacing: 4px; text-transform: uppercase; padding: 16px 24px; text-align: left; }
        .inv-header { border-bottom: 1px solid #eee; padding: 16px 24px; }
        .inv-parties { border-bottom: 1px solid #eee; }
        .inv-parties > div:first-child { border-right: 1px solid #eee; }
        .inv-table th { background: transparent; color: #999; font-weight: 600; border-bottom: 2px solid #eee; text-transform: uppercase; font-size: 10px; }
        .inv-table td { border-bottom: 1px solid #f5f5f5; color: #444; }
        .inv-table tfoot td { border-top: 2px solid #eee; background: transparent; color: #333; }
        .inv-summary { border-top: 1px solid #eee; }
        .inv-summary-table tr:last-child td { border-top: 2px solid #ddd; }
        .inv-words { border-top: 1px solid #f0f0f0; color: #888; }
        .inv-footer { border-top: 1px solid #eee; }
        .inv-footer > div:not(:last-child) { border-right: 1px solid #eee; }
        .inv-footer .footer-label { color: #999; }
        .inv-ack { border-top: 1px dashed #ddd; }
        <?php endif; ?>

        <?php if ($T === 'bordered'): ?>
        /* ─── BORDERED: Double border decorative frame ─── */
        .invoice-page { border: 3px solid <?= $C ?>; position: relative; }
        .invoice-page::after { content: ''; position: absolute; inset: 5px; border: 1.5px solid <?= $C ?>55; pointer-events: none; z-index: 0; }
        .invoice-page > * { position: relative; z-index: 1; }
        .inv-title { background: <?= $C ?>; color: #fff; border-bottom: none; font-weight: 800; padding: 10px; }
        .inv-header { border-bottom: 2px solid <?= $C ?>; margin: 0 8px; padding: 16px; }
        .inv-parties { border-bottom: 2px solid <?= $C ?>; margin: 0 8px; }
        .inv-parties > div:first-child { border-right: 2px solid <?= $C ?>; }
        .inv-table { margin: 0; }
        .inv-table th { background: <?= $C ?>15; color: <?= $C ?>; border-bottom: 2px solid <?= $C ?>; }
        .inv-table td { border-bottom: 1px solid <?= $C ?>20; }
        .inv-table tfoot td { border-top: 2px solid <?= $C ?>; background: <?= $C ?>08; }
        .inv-summary { border-top: 2px solid <?= $C ?>; margin: 0 8px; }
        .inv-summary-table tr:last-child td { border-top: 2px solid <?= $C ?>; color: <?= $C ?>; }
        .inv-words { border-top: 1px solid <?= $C ?>30; margin: 0 8px; }
        .inv-footer { border-top: 2px solid <?= $C ?>; margin: 0 8px; }
        .inv-footer > div:not(:last-child) { border-right: 2px solid <?= $C ?>; }
        .inv-footer .footer-label { color: <?= $C ?>; }
        .inv-ack { border-top: 2px dashed <?= $C ?>66; margin: 0 8px; }
        <?php endif; ?>

        <?php if ($T === 'compact'): ?>
        /* ─── COMPACT: Left accent bar, dense layout ─── */
        .invoice-page { border: 1px solid #e0e0e0; border-left: 6px solid <?= $C ?>; }
        .inv-title { background: #fff; border-bottom: 1px solid #eee; color: <?= $C ?>; font-size: 15px; text-align: left; padding: 10px 20px; }
        .inv-header { border-bottom: 1px solid #eee; padding: 14px 20px; }
        .inv-header-right h2 { color: <?= $C ?>; font-size: 18px; }
        .inv-parties { border-bottom: 1px solid #eee; }
        .inv-parties > div { padding: 10px 20px; }
        .inv-parties > div:first-child { border-right: 1px solid #eee; }
        .inv-table th { background: <?= $C ?>0a; color: <?= $C ?>; border-bottom: 2px solid <?= $C ?>30; font-size: 10px; padding: 8px 6px; }
        .inv-table td { padding: 8px 6px; border-bottom: 1px solid #f0f0f0; font-size: 11px; }
        .inv-table tfoot td { border-top: 2px solid <?= $C ?>30; background: #fafafa; }
        .inv-summary { border-top: 1px solid #eee; }
        .inv-summary-table td { padding: 6px 14px; font-size: 12px; }
        .inv-summary-table tr:last-child td { border-top: 2px solid <?= $C ?>; color: <?= $C ?>; }
        .inv-words { border-top: 1px solid #eee; padding: 8px 20px; }
        .inv-footer { border-top: 1px solid #eee; }
        .inv-footer > div { padding: 12px 16px; }
        .inv-footer > div:not(:last-child) { border-right: 1px solid #eee; }
        .inv-footer .footer-label { color: <?= $C ?>; }
        .inv-ack { border-top: 1px dashed #ccc; padding: 12px 20px; }
        <?php endif; ?>

        <?php if ($T === 'elegant'): ?>
        /* ─── ELEGANT: Serif touches, refined decorative lines ─── */
        .invoice-page { border: 2px solid #333; position: relative; }
        .invoice-page::before { content: ''; position: absolute; top: 3px; left: 3px; right: 3px; bottom: 3px; border: 1px solid #99999966; pointer-events: none; z-index: 0; }
        .invoice-page > * { position: relative; z-index: 1; }
        .inv-title { background: linear-gradient(135deg, #333 0%, #555 100%); color: #f0e6d3; border: none; font-family: Georgia, 'Times New Roman', serif; font-size: 18px; letter-spacing: 4px; padding: 12px; }
        .inv-header { border-bottom: 1px solid #ccc; background: linear-gradient(180deg, #faf8f5 0%, #fff 100%); }
        .inv-header-right h2 { font-family: Georgia, serif; color: #333; }
        .inv-parties { border-bottom: 1px solid #ccc; background: #fdfcfa; }
        .inv-parties > div:first-child { border-right: 1px solid #ccc; }
        .inv-parties .label { color: #888; letter-spacing: 1.2px; }
        .inv-table th { background: #f5f0ea; color: #555; border-bottom: 2px solid #ccc; font-family: Georgia, serif; letter-spacing: 1px; }
        .inv-table td { border-bottom: 1px solid #ece8e2; }
        .inv-table tfoot td { border-top: 2px solid #ccc; background: #faf8f5; }
        .inv-summary { border-top: 1px solid #ddd; background: #fdfcfa; }
        .inv-summary-table tr:last-child td { border-top: 2px solid #333; font-family: Georgia, serif; }
        .inv-words { border-top: 1px solid #e8e4de; font-style: italic; color: #777; }
        .inv-footer { border-top: 2px solid #333; background: #fdfcfa; }
        .inv-footer > div:not(:last-child) { border-right: 1px solid #ccc; }
        .inv-footer .footer-label { color: #555; font-family: Georgia, serif; letter-spacing: 1px; }
        .inv-ack { border-top: 1px dashed #aaa; background: #faf8f5; }
        <?php endif; ?>

        <?php if ($T === 'bold'): ?>
        /* ─── BOLD: Dark accent header, high contrast ─── */
        .invoice-page { border: none; border-top: 8px solid <?= $C ?>; box-shadow: 0 8px 40px rgba(0,0,0,.15); }
        .inv-title { background: <?= $C ?>; color: #fff; font-size: 20px; font-weight: 900; padding: 16px; letter-spacing: 6px; text-transform: uppercase; border: none; }
        .inv-header { background: #1a1a2e; color: #fff; padding: 24px 28px; border: none; }
        .inv-header-right h2 { color: #fff; font-size: 22px; }
        .inv-header-right p { color: #ccc; }
        .inv-header-left img { filter: brightness(0) invert(1); }
        .inv-parties { border-bottom: none; }
        .inv-parties > div:first-child { border-right: 3px solid <?= $C ?>; }
        .inv-parties .label { color: <?= $C ?>; font-weight: 800; }
        .inv-table th { background: #1a1a2e; color: #fff; border: none; padding: 14px 8px; font-size: 11px; font-weight: 800; }
        .inv-table td { border-bottom: 1px solid #eee; padding: 12px 8px; }
        .inv-table tr:nth-child(even) td { background: #fafafa; }
        .inv-table tfoot td { border-top: 3px solid <?= $C ?>; background: #1a1a2e; color: #fff; }
        .inv-summary { border-top: none; background: #fafafa; padding: 12px 0; }
        .inv-summary-table tr:last-child td { border-top: 3px solid <?= $C ?>; font-size: 17px; color: <?= $C ?>; }
        .inv-words { border-top: none; background: <?= $C ?>08; padding: 14px 24px; }
        .inv-words strong { color: <?= $C ?>; }
        .inv-footer { border-top: 4px solid <?= $C ?>; background: #1a1a2e; color: #ddd; }
        .inv-footer > div:not(:last-child) { border-right: 1px solid #333; }
        .inv-footer .footer-label { color: <?= $C ?>; }
        .inv-footer .sig-area .sig-text { color: #aaa; border-top-color: #555; }
        .inv-ack { border-top: 3px dashed <?= $C ?>44; background: #f8f8f8; }
        <?php endif; ?>

        <?php if ($T === 'professional'): ?>
        /* ─── PROFESSIONAL: Corporate, structured grid ─── */
        .invoice-page { border: 1px solid #d0d5dd; border-top: 4px solid <?= $C ?>; }
        .inv-title { background: <?= $C ?>08; color: <?= $C ?>; border-bottom: 1px solid #d0d5dd; font-weight: 800; font-size: 15px; }
        .inv-header { border-bottom: 1px solid #d0d5dd; background: #f9fafb; }
        .inv-header-right h2 { color: #1a1a2e; }
        .inv-parties { border-bottom: 1px solid #d0d5dd; }
        .inv-parties > div:first-child { border-right: 1px solid #d0d5dd; background: #f9fafb; }
        .inv-parties .label { color: <?= $C ?>; font-weight: 800; letter-spacing: 1px; }
        .inv-table th { background: <?= $C ?>0d; color: #333; border-bottom: 2px solid <?= $C ?>40; font-weight: 700; }
        .inv-table td { border-bottom: 1px solid #e8e8e8; }
        .inv-table tr:hover td { background: #f9fafb; }
        .inv-table tfoot td { border-top: 2px solid <?= $C ?>; background: #f9fafb; }
        .inv-summary { border-top: 1px solid #d0d5dd; }
        .inv-summary-table tr:last-child td { border-top: 2px solid <?= $C ?>; color: <?= $C ?>; }
        .inv-words { border-top: 1px solid #e8e8e8; background: #f9fafb; }
        .inv-footer { border-top: 2px solid <?= $C ?>; }
        .inv-footer > div:not(:last-child) { border-right: 1px solid #d0d5dd; }
        .inv-footer .footer-label { color: <?= $C ?>; }
        .inv-ack { border-top: 2px dashed #d0d5dd; background: #f9fafb; }
        <?php endif; ?>

        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .invoice-page { box-shadow: none; margin: 0; max-width: none; }
        }
    </style>
</head>
<body>
<div class="no-print">
    <a href="invoices.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Back</a>
    <a href="invoice_settings.php" class="btn-settings"><i class="fa-solid fa-gear"></i> Print Settings</a>
    <button class="btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print Invoice</button>
</div>

<div class="invoice-page">
    <div class="inv-title">Tax Invoice</div>

    <div class="inv-header">
        <div class="inv-header-left">
            <?php if ($showLogo && $bizLogo): ?>
                <img src="<?= $bizLogo ?>?v=<?= $v ?>" alt="Logo" onerror="this.style.display='none'">
            <?php endif; ?>
        </div>
        <div class="inv-header-right">
            <?php if ($showCompanyName): ?><h2><?= $bizName ?></h2><?php endif; ?>
            <p>
                <?php if ($showAddress && $bizAddr): ?><?= nl2br($bizAddr) ?><br><?php endif; ?>
                <?php if ($showPhone && $bizPhone): ?>Phone: <?= $bizPhone ?> <?php endif; ?>
                <?php if ($showEmail && $bizEmail): ?>Email: <?= $bizEmail ?><?php endif; ?>
                <?php if (($showPhone && $bizPhone) || ($showEmail && $bizEmail)): ?><br><?php endif; ?>
                <?php if ($showGstin && $bizGstin): ?>GSTIN: <?= $bizGstin ?><?php endif; ?>
            </p>
        </div>
    </div>

    <div class="inv-parties">
        <div>
            <div class="label">Bill To</div>
            <div class="value"><?= htmlspecialchars($invoice['customer_name']) ?></div>
            <div class="sub">
                <?= htmlspecialchars($invoice['billing_address'] ?? '') ?><br>
                <?php if (!empty($invoice['gst_number'])): ?>GSTIN: <?= htmlspecialchars($invoice['gst_number']) ?><br><?php endif; ?>
                <?php if (!empty($invoice['customer_phone'])): ?>Contact: <?= htmlspecialchars($invoice['customer_phone']) ?><?php endif; ?>
            </div>
        </div>
        <div>
            <div class="label">Invoice Details</div>
            <div class="sub">
                <strong>Invoice No.:</strong> <?= htmlspecialchars($invoice['invoice_number']) ?><br>
                <strong>Date:</strong> <?= date('d-m-Y', strtotime($invoice['invoice_date'])) ?><br>
                <?php if (!empty($invoice['due_date'])): ?><strong>Due Date:</strong> <?= date('d-m-Y', strtotime($invoice['due_date'])) ?><br><?php endif; ?>
                <strong>Status:</strong> <?= htmlspecialchars(ucfirst($invoice['status'])) ?>
            </div>
        </div>
    </div>

    <table class="inv-table">
        <thead><tr>
            <th>#</th><th>Item Name</th>
            <?php if ($showHsn): ?><th>HSN/SAC</th><?php endif; ?>
            <th>Qty</th><th>Unit</th>
            <?php if ($showBatchInfo): ?><th>Batch</th><th>MFG</th><th>EXP</th><?php endif; ?>
            <th>Rate (₹)</th><th>Taxable</th><th>CGST</th><th>SGST</th><th>Amount (₹)</th>
        </tr></thead>
        <tbody>
        <?php
        $tQty=0; $tTax=0; $tCg=0; $tSg=0; $tAmt=0;
        foreach ($items as $i => $item):
            $q=(float)$item['quantity']; $r=(float)$item['unit_price']; $tp=(float)$item['tax_percent'];
            $taxable=round($q*$r,2); $ht=$tp/2; $cg=round($taxable*($ht/100),2); $sg=$cg;
            $la=round($taxable+$cg+$sg,2);
            $tQty+=$q; $tTax+=$taxable; $tCg+=$cg; $tSg+=$sg; $tAmt+=$la;
        ?>
        <tr>
            <td><?= $i+1 ?></td>
            <td><?= htmlspecialchars($item['item_name']) ?></td>
            <?php if ($showHsn): ?><td><?= htmlspecialchars($item['hsn_code'] ?? '-') ?></td><?php endif; ?>
            <td><?= number_format($q,0) ?></td>
            <td><?= htmlspecialchars($item['unit'] ?? 'PCS') ?></td>
            <?php if ($showBatchInfo): ?>
                <td><?= htmlspecialchars($item['batch_no'] ?? '-') ?></td>
                <td><?= !empty($item['mfg_date']) ? date('m/Y', strtotime($item['mfg_date'])) : '-' ?></td>
                <td><?= !empty($item['exp_date']) ? date('m/Y', strtotime($item['exp_date'])) : '-' ?></td>
            <?php endif; ?>
            <td>₹ <?= number_format($r,2) ?></td>
            <td>₹ <?= number_format($taxable,2) ?></td>
            <td>₹ <?= number_format($cg,2) ?><br><span style="font-size:10px;color:#888;">(<?= number_format($ht,1) ?>%)</span></td>
            <td>₹ <?= number_format($sg,2) ?><br><span style="font-size:10px;color:#888;">(<?= number_format($ht,1) ?>%)</span></td>
            <td style="font-weight:600;">₹ <?= number_format($la,2) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr>
            <td colspan="<?= $showHsn ? 3 : 2 ?>" style="text-align:left;padding-left:24px;">Total</td>
            <td><?= number_format($tQty,0) ?></td><td></td>
            <?php if ($showBatchInfo): ?><td colspan="3"></td><?php endif; ?>
            <td></td>
            <td>₹ <?= number_format($tTax,2) ?></td>
            <td>₹ <?= number_format($tCg,2) ?></td>
            <td>₹ <?= number_format($tSg,2) ?></td>
            <td style="font-weight:700;">₹ <?= number_format($tAmt,2) ?></td>
        </tr></tfoot>
    </table>

    <div class="inv-summary"><div class="inv-summary-row">
        <table class="inv-summary-table">
            <tr><td>Sub Total</td><td>₹ <?= number_format((float)$invoice['subtotal'],2) ?></td></tr>
            <tr><td>Tax (CGST + SGST)</td><td>₹ <?= number_format((float)$invoice['tax_total'],2) ?></td></tr>
            <tr><td>Total</td><td>₹ <?= number_format($grandTotal,2) ?></td></tr>
            <tr><td>Received</td><td>₹ <?= number_format($paidAmount,2) ?></td></tr>
        </table>
    </div></div>

    <div class="inv-words">
        <strong>Invoice Amount In Words</strong>
        <?= $totalInWords ?>
    </div>

    <?php if ($footerCols > 0): ?>
    <div class="inv-footer">
        <?php if ($showBankDetails): ?>
        <div>
            <div class="footer-label">Bank Details</div>
            <?php if ($bankName): ?>Name: <?= $bankName ?><br><?php endif; ?>
            <?php if ($accountNo): ?>A/C No.: <?= $accountNo ?><br><?php endif; ?>
            <?php if ($ifscCode): ?>IFSC: <?= $ifscCode ?><br><?php endif; ?>
            <?php if ($bizName): ?>Holder: <?= $bizName ?><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($showTerms): ?>
        <div>
            <div class="footer-label">Terms & Conditions</div>
            <?= nl2br($terms) ?>
        </div>
        <?php endif; ?>
        <?php if ($showSignature): ?>
        <div class="sig-area">
            <div class="footer-label">For: <?= $bizName ?></div>
            <?php if ($sigPath): ?><img src="<?= $sigPath ?>" alt="Signature"><?php else: ?><div style="height:50px;"></div><?php endif; ?>
            <div class="sig-text">Authorized Signatory</div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($showAck): ?>
    <div class="inv-ack">
        <h4>Acknowledgement — <?= $bizName ?></h4>
        <div class="inv-ack-grid">
            <div>
                <div class="ack-label">Invoice To:</div>
                <strong><?= htmlspecialchars($invoice['customer_name']) ?></strong><br>
                <?= htmlspecialchars($invoice['billing_address'] ?? '') ?>
            </div>
            <div class="inv-ack-right">
                <div class="ack-label">Invoice Details:</div>
                No.: <?= htmlspecialchars($invoice['invoice_number']) ?><br>
                Date: <?= date('d-m-Y', strtotime($invoice['invoice_date'])) ?><br>
                Amount: ₹ <?= number_format($grandTotal,2) ?>
                <div class="sig-line">Receiver's Seal & Sign</div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
