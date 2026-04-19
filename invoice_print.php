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

// Number to words helper
function numberToWords($num) {
    $ones = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten',
             'Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
    $tens = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    $num = (int) round($num);
    if ($num === 0) return 'Zero';
    if ($num < 0) return 'Negative ' . numberToWords(-$num);
    $words = '';
    if (intval($num / 10000000)) { $words .= numberToWords(intval($num / 10000000)) . ' Crore '; $num %= 10000000; }
    if (intval($num / 100000)) { $words .= numberToWords(intval($num / 100000)) . ' Lakh '; $num %= 100000; }
    if (intval($num / 1000)) { $words .= numberToWords(intval($num / 1000)) . ' Thousand '; $num %= 1000; }
    if (intval($num / 100)) { $words .= $ones[intval($num / 100)] . ' Hundred '; $num %= 100; }
    if ($num > 0) {
        if ($words != '') $words .= 'and ';
        if ($num < 20) { $words .= $ones[$num]; }
        else { $words .= $tens[intval($num / 10)]; if ($num % 10) $words .= ' ' . $ones[$num % 10]; }
    }
    return trim($words);
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
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #e8ecf3; color: #1a1a2e; font-size: 13px; }
        .no-print { display: flex; justify-content: center; gap: 12px; padding: 16px; background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
        .no-print a, .no-print button { padding: 10px 22px; border-radius: 10px; font-weight: 600; font-size: 14px; cursor: pointer; text-decoration: none; border: none; }
        .no-print .btn-back { background: #f1f5f9; color: #475569; }
        .no-print .btn-print { background: #7b5ef0; color: #fff; }
        .invoice-page { max-width: 820px; margin: 24px auto; background: #fff; box-shadow: 0 4px 24px rgba(0,0,0,.1); }
        .inv-border { border: 2px solid #1a1a2e; }
        /* Header */
        .inv-header { display: flex; justify-content: space-between; align-items: flex-start; padding: 20px 24px; border-bottom: 2px solid #1a1a2e; }
        .inv-header-left { display: flex; align-items: flex-start; gap: 16px; }
        .inv-header-left img { max-height: 56px; }
        .inv-header-right { text-align: right; }
        .inv-header-right h2 { font-size: 20px; margin-bottom: 4px; }
        .inv-header-right p { font-size: 12px; line-height: 1.6; color: #444; }
        .inv-title { text-align: center; font-size: 16px; font-weight: 700; padding: 8px; border-bottom: 2px solid #1a1a2e; background: #f8f8fc; letter-spacing: 1px; }
        /* Bill To / Invoice Details */
        .inv-parties { display: grid; grid-template-columns: 1fr 1fr; border-bottom: 2px solid #1a1a2e; }
        .inv-parties > div { padding: 14px 24px; }
        .inv-parties > div:first-child { border-right: 2px solid #1a1a2e; }
        .inv-parties .label { font-size: 11px; font-weight: 700; text-transform: uppercase; color: #666; margin-bottom: 6px; letter-spacing: .8px; }
        .inv-parties .value { font-weight: 700; font-size: 14px; }
        .inv-parties .sub { font-size: 12px; color: #555; line-height: 1.6; margin-top: 4px; }
        /* Items Table */
        .inv-table { width: 100%; border-collapse: collapse; }
        .inv-table th { background: #f0eef8; color: #333; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; padding: 10px 8px; border-bottom: 2px solid #1a1a2e; text-align: center; font-weight: 700; }
        .inv-table td { padding: 10px 8px; border-bottom: 1px solid #e0e0e0; text-align: center; font-size: 12px; }
        .inv-table td:nth-child(2) { text-align: left; }
        .inv-table tfoot td { font-weight: 700; border-top: 2px solid #1a1a2e; background: #f8f8fc; }
        /* Totals section */
        .inv-summary { border-top: 2px solid #1a1a2e; }
        .inv-summary-row { display: flex; justify-content: flex-end; }
        .inv-summary-table { width: 360px; }
        .inv-summary-table td { padding: 8px 16px; font-size: 13px; }
        .inv-summary-table td:last-child { text-align: right; font-weight: 600; }
        .inv-summary-table tr:last-child td { font-weight: 700; font-size: 15px; border-top: 2px solid #1a1a2e; }
        /* Amount in words */
        .inv-words { padding: 12px 24px; border-top: 1px solid #ddd; font-size: 12px; }
        .inv-words strong { display: block; margin-bottom: 2px; font-size: 11px; text-transform: uppercase; }
        /* Footer */
        .inv-footer { display: grid; grid-template-columns: 1fr 1fr 1fr; border-top: 2px solid #1a1a2e; }
        .inv-footer > div { padding: 16px 20px; font-size: 12px; line-height: 1.7; }
        .inv-footer > div:not(:last-child) { border-right: 2px solid #1a1a2e; }
        .inv-footer .footer-label { font-weight: 700; font-size: 11px; text-transform: uppercase; margin-bottom: 8px; color: #333; }
        .inv-footer .sig-area { text-align: center; }
        .inv-footer .sig-area img { max-height: 50px; margin: 8px 0; }
        .inv-footer .sig-area .sig-text { font-size: 11px; color: #666; border-top: 1px solid #aaa; display: inline-block; padding-top: 4px; margin-top: 8px; }
        /* Acknowledgement */
        .inv-ack { border-top: 2px dashed #999; margin-top: 0; padding: 16px 24px; }
        .inv-ack h4 { text-align: center; margin-bottom: 10px; font-size: 13px; }
        .inv-ack-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; font-size: 12px; }
        .inv-ack-grid .ack-label { font-size: 11px; font-weight: 700; text-transform: uppercase; color: #666; }
        .inv-ack-right { text-align: right; }
        .inv-ack-right .sig-line { border-top: 1px dashed #999; display: inline-block; padding-top: 4px; margin-top: 30px; font-size: 11px; color: #666; }
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
    <button class="btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print Invoice</button>
</div>

<div class="invoice-page inv-border">
    <!-- Title -->
    <div class="inv-title">Tax Invoice</div>

    <!-- Company Header -->
    <div class="inv-header">
        <div class="inv-header-left">
            <img src="<?= $bizLogo ?>?v=<?= $v ?>" alt="Logo" onerror="this.style.display='none'">
        </div>
        <div class="inv-header-right">
            <h2><?= $bizName ?></h2>
            <p>
                <?= nl2br($bizAddr) ?><br>
                <?php if ($bizPhone): ?>Phone: <?= $bizPhone ?> <?php endif; ?>
                <?php if ($bizEmail): ?>Email: <?= $bizEmail ?><?php endif; ?><br>
                <?php if ($bizGstin): ?>GSTIN: <?= $bizGstin ?><?php endif; ?>
            </p>
        </div>
    </div>

    <!-- Bill To / Invoice Details -->
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

    <!-- Items Table -->
    <table class="inv-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Item Name</th>
                <th>HSN/SAC</th>
                <th>Qty</th>
                <th>Unit</th>
                <th>Rate (₹)</th>
                <th>Taxable Amt</th>
                <th>CGST</th>
                <th>SGST</th>
                <th>Amount (₹)</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $totalQty = 0;
            $totalTaxable = 0;
            $totalCgst = 0;
            $totalSgst = 0;
            $totalAmt = 0;
            foreach ($items as $idx => $item):
                $qty = (float) $item['quantity'];
                $rate = (float) $item['unit_price'];
                $taxPct = (float) $item['tax_percent'];
                $taxable = round($qty * $rate, 2);
                $halfTax = $taxPct / 2;
                $cgst = round($taxable * ($halfTax / 100), 2);
                $sgst = $cgst;
                $lineAmt = round($taxable + $cgst + $sgst, 2);

                $totalQty += $qty;
                $totalTaxable += $taxable;
                $totalCgst += $cgst;
                $totalSgst += $sgst;
                $totalAmt += $lineAmt;
            ?>
            <tr>
                <td><?= $idx + 1 ?></td>
                <td><?= htmlspecialchars($item['item_name']) ?></td>
                <td><?= htmlspecialchars($item['hsn_code'] ?? '-') ?></td>
                <td><?= number_format($qty, 0) ?></td>
                <td><?= htmlspecialchars($item['unit'] ?? 'PCS') ?></td>
                <td>₹ <?= number_format($rate, 2) ?></td>
                <td>₹ <?= number_format($taxable, 2) ?></td>
                <td>₹ <?= number_format($cgst, 2) ?><br><span style="font-size:10px;color:#888;">(<?= number_format($halfTax, 1) ?>%)</span></td>
                <td>₹ <?= number_format($sgst, 2) ?><br><span style="font-size:10px;color:#888;">(<?= number_format($halfTax, 1) ?>%)</span></td>
                <td style="font-weight:600;">₹ <?= number_format($lineAmt, 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3" style="text-align:left;padding-left:24px;">Total</td>
                <td><?= number_format($totalQty, 0) ?></td>
                <td></td>
                <td></td>
                <td>₹ <?= number_format($totalTaxable, 2) ?></td>
                <td>₹ <?= number_format($totalCgst, 2) ?></td>
                <td>₹ <?= number_format($totalSgst, 2) ?></td>
                <td style="font-weight:700;">₹ <?= number_format($totalAmt, 2) ?></td>
            </tr>
        </tfoot>
    </table>

    <!-- Summary -->
    <div class="inv-summary">
        <div class="inv-summary-row">
            <table class="inv-summary-table">
                <tr><td>Sub Total</td><td>₹ <?= number_format((float)$invoice['subtotal'], 2) ?></td></tr>
                <tr><td>Tax (CGST + SGST)</td><td>₹ <?= number_format((float)$invoice['tax_total'], 2) ?></td></tr>
                <tr><td>Total</td><td>₹ <?= number_format($grandTotal, 2) ?></td></tr>
                <tr><td>Received</td><td>₹ <?= number_format($paidAmount, 2) ?></td></tr>
            </table>
        </div>
    </div>

    <!-- Amount in Words -->
    <div class="inv-words">
        <strong>Invoice Amount In Words</strong>
        <?= $totalInWords ?>
    </div>

    <!-- Footer: Bank, Terms, Signature -->
    <div class="inv-footer">
        <div>
            <div class="footer-label">Bank Details</div>
            <?php if ($bankName): ?>Name: <?= $bankName ?><br><?php endif; ?>
            <?php if ($accountNo): ?>Account No.: <?= $accountNo ?><br><?php endif; ?>
            <?php if ($ifscCode): ?>IFSC Code: <?= $ifscCode ?><br><?php endif; ?>
            <?php if ($bizName): ?>Account Holder: <?= $bizName ?><?php endif; ?>
        </div>
        <div>
            <div class="footer-label">Terms and Conditions</div>
            <?= nl2br($terms) ?>
        </div>
        <div class="sig-area">
            <div class="footer-label">For: <?= $bizName ?></div>
            <?php if ($sigPath): ?>
                <img src="<?= $sigPath ?>" alt="Signature">
            <?php else: ?>
                <div style="height:50px;"></div>
            <?php endif; ?>
            <div class="sig-text">Authorized Signatory</div>
        </div>
    </div>

    <!-- Acknowledgement Stub -->
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
                Invoice No.: <?= htmlspecialchars($invoice['invoice_number']) ?><br>
                Invoice Date: <?= date('d-m-Y', strtotime($invoice['invoice_date'])) ?><br>
                Invoice Amount: ₹ <?= number_format($grandTotal, 2) ?>
                <div class="sig-line">Receiver's Seal & Sign</div>
            </div>
        </div>
    </div>
</div>

</body>
</html>
