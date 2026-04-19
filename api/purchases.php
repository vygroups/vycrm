<?php
// api/purchases.php - Purchase Bill Management API
session_start();
require_once '../includes/commerce.php';
require_once '../includes/api_auth.php';

try {
    $context = api_require_context();
    $conn = $context['conn'];
    $prefix = $context['prefix'];
    commerce_ensure_tables($conn, $prefix);

    $method = $_SERVER['REQUEST_METHOD'];
    $input = commerce_read_input();

    if ($method === 'POST') {
        $vendorId = isset($input['vendor_id']) && $input['vendor_id'] !== '' ? (int)$input['vendor_id'] : null;
        $vendorName = trim($input['vendor_name'] ?? '');
        $purchaseDate = trim($input['purchase_date'] ?? date('Y-m-d'));
        $status = $input['status'] ?? 'draft';
        $items = $input['items'] ?? [];
        $paidAmount = (float)($input['paid_amount'] ?? 0);

        if (!$vendorName) commerce_json_response(['success' => false, 'message' => 'Vendor name is required'], 422);
        if (empty($items)) commerce_json_response(['success' => false, 'message' => 'At least one item is required'], 422);

        $subtotal = 0;
        $taxTotal = 0;
        foreach($items as $item) {
            $qty = (float)$item['quantity'];
            $price = (float)$item['unit_price'];
            $tax = (float)$item['tax_percent'];
            $lineSub = $qty * $price;
            $subtotal += $lineSub;
            $taxTotal += ($lineSub * $tax / 100);
        }
        $grandTotal = $subtotal + $taxTotal;
        $paymentStatus = 'unpaid';
        if ($paidAmount >= $grandTotal) $paymentStatus = 'paid';
        elseif ($paidAmount > 0) $paymentStatus = 'partially_paid';

        $conn->beginTransaction();
        try {
            $purchaseNum = commerce_generate_purchase_number($conn, $prefix);
            $stmt = $conn->prepare("INSERT INTO {$prefix}purchases (purchase_number, vendor_id, vendor_name, purchase_date, subtotal, tax_total, grand_total, paid_amount, status, payment_status) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$purchaseNum, $vendorId, $vendorName, $purchaseDate, $subtotal, $taxTotal, $grandTotal, $paidAmount, $status, $paymentStatus]);
            $purchaseId = $conn->lastInsertId();

            $itemStmt = $conn->prepare("INSERT INTO {$prefix}purchase_items (purchase_id, product_id, item_name, quantity, unit_price, tax_percent, line_total) VALUES (?,?,?,?,?,?,?)");
            foreach($items as $item) {
                $lineTotal = ($item['quantity'] * $item['unit_price']) * (1 + $item['tax_percent']/100);
                $itemStmt->execute([$purchaseId, $item['product_id'] ?: null, $item['item_name'], $item['quantity'], $item['unit_price'], $item['tax_percent'], $lineTotal]);
            }
            $conn->commit();
            commerce_json_response(['success' => true, 'message' => 'Purchase recorded', 'data' => ['purchase_id' => $purchaseId, 'purchase_number' => $purchaseNum]]);
        } catch (Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    if ($method === 'GET') {
        $stmt = $conn->query("SELECT * FROM {$prefix}purchases ORDER BY created_at DESC");
        commerce_json_response(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

} catch (Throwable $e) {
    commerce_json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
