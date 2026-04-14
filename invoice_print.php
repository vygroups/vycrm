<?php
require_once 'auth_check.php';
require_once 'includes/commerce.php';

$v = time();
$companyLogo = "/images/logo.png";
$companyName = "Vy CRM";

try {
    $brandDb = Database::getMasterConn();
    $brandPrefix = Database::getMasterPrefix();
    $brandStmt = $brandDb->prepare("SELECT * FROM {$brandPrefix}companies WHERE slug = ?");
    $brandStmt->execute([$_SESSION['tenant_slug']]);
    $company = $brandStmt->fetch(PDO::FETCH_ASSOC);
    if ($company && $company['logo']) {
        $companyLogo = '/' . $company['logo'];
        $companyName = htmlspecialchars($company['name']);
    }
} catch (Throwable $e) {
}

$context = commerce_get_tenant_context();
$conn = $context['conn'];
$prefix = $context['prefix'];
commerce_ensure_tables($conn, $prefix);

$invoiceId = (int) ($_GET['id'] ?? 0);
$detail = commerce_fetch_invoice_detail($conn, $prefix, $invoiceId);
if (!$detail) {
    http_response_code(404);
    exit('Invoice not found');
}

$invoice = $detail['invoice'];
$items = $detail['items'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Invoice - <?= htmlspecialchars($invoice['invoice_number']) ?></title>
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <style>
        body { background:#eef4ff; }
        .print-shell { max-width:960px; margin:24px auto; padding:0 20px; }
        .print-actions { display:flex; justify-content:flex-end; gap:12px; margin-bottom:18px; }
        .print-card {
            background:#fff;
            border-radius:24px;
            box-shadow:var(--shadow-lg);
            padding:34px;
            border-top:8px solid #3b82f6;
        }
        .print-head {
            display:flex;
            justify-content:space-between;
            gap:24px;
            margin-bottom:28px;
        }
        .brand-title { font-size:28px; color:#1d4ed8; margin-bottom:8px; }
        .invoice-badge {
            background:#dbeafe;
            color:#1d4ed8;
            padding:8px 12px;
            border-radius:999px;
            font-weight:700;
            display:inline-flex;
        }
        .detail-grid {
            display:grid;
            grid-template-columns:repeat(3, minmax(0, 1fr));
            gap:18px;
            margin-bottom:24px;
        }
        .detail-box {
            background:#f8fbff;
            border:1px solid #dbeafe;
            border-radius:18px;
            padding:18px;
        }
        .detail-box span { display:block; font-size:12px; text-transform:uppercase; color:#64748b; margin-bottom:8px; }
        .invoice-table { width:100%; border-collapse:collapse; margin:24px 0; }
        .invoice-table th, .invoice-table td { padding:14px 12px; border-bottom:1px solid #e5eefc; text-align:left; }
        .invoice-table th { color:#64748b; font-size:12px; text-transform:uppercase; letter-spacing:.8px; }
        .totals { margin-left:auto; width:320px; }
        .total-row { display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid #e5eefc; }
        .total-row strong { font-size:18px; color:#1d4ed8; }
        @media print {
            body { background:#fff; }
            .print-actions { display:none; }
            .print-shell { max-width:none; margin:0; padding:0; }
            .print-card { box-shadow:none; border-radius:0; border-top:0; }
        }
    </style>
</head>
<body>
<div class="print-shell">
    <div class="print-actions">
        <a href="invoices.php" class="btn-secondary">Back To List</a>
        <button class="btn-primary" style="width:auto;padding:13px 18px;" onclick="window.print()">Print Invoice</button>
    </div>
    <div class="print-card">
        <div class="print-head">
            <div>
                <img src="<?= $companyLogo ?>?v=<?= $v ?>" alt="<?= $companyName ?>" style="max-height:56px; margin-bottom:12px;">
                <div class="brand-title"><?= $companyName ?></div>
                <div><?= nl2br(htmlspecialchars((string) ($invoice['billing_address'] ?? ''))) ?></div>
            </div>
            <div style="text-align:right;">
                <div class="invoice-badge"><?= htmlspecialchars($invoice['invoice_number']) ?></div>
                <div style="margin-top:14px;"><strong>Date:</strong> <?= htmlspecialchars($invoice['invoice_date']) ?></div>
                <div><strong>Due:</strong> <?= htmlspecialchars((string) ($invoice['due_date'] ?? '-')) ?></div>
                <div><strong>Status:</strong> <?= htmlspecialchars(ucfirst($invoice['status'])) ?></div>
            </div>
        </div>

        <div class="detail-grid">
            <div class="detail-box">
                <span>Bill To</span>
                <strong><?= htmlspecialchars($invoice['customer_name']) ?></strong><br>
                <?= htmlspecialchars((string) ($invoice['customer_phone'] ?? '-')) ?><br>
                <?= htmlspecialchars((string) ($invoice['customer_email'] ?? '-')) ?>
            </div>
            <div class="detail-box">
                <span>Customer Code</span>
                <strong><?= htmlspecialchars((string) ($invoice['customer_code'] ?? '-')) ?></strong><br>
                GST: <?= htmlspecialchars((string) ($invoice['gst_number'] ?? '-')) ?>
            </div>
            <div class="detail-box">
                <span>Notes</span>
                <?= nl2br(htmlspecialchars((string) ($invoice['notes'] ?? 'No notes'))) ?>
            </div>
        </div>

        <table class="invoice-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Description</th>
                    <th>Qty</th>
                    <th>Rate</th>
                    <th>Tax</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['item_name']) ?></td>
                        <td><?= htmlspecialchars((string) ($item['description'] ?? '-')) ?></td>
                        <td><?= number_format((float) $item['quantity'], 2) ?></td>
                        <td><?= number_format((float) $item['unit_price'], 2) ?></td>
                        <td><?= number_format((float) $item['tax_percent'], 2) ?>%</td>
                        <td><?= number_format((float) $item['line_total'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="totals">
            <div class="total-row"><span>Subtotal</span><span><?= number_format((float) $invoice['subtotal'], 2) ?></span></div>
            <div class="total-row"><span>Tax</span><span><?= number_format((float) $invoice['tax_total'], 2) ?></span></div>
            <div class="total-row"><strong>Grand Total</strong><strong><?= number_format((float) $invoice['grand_total'], 2) ?></strong></div>
        </div>
    </div>
</div>
</body>
</html>
