<?php
session_start();
ob_start();
require_once '../includes/commerce.php';
require_once '../includes/api_auth.php';

try {
    $context = api_require_context();
    $conn = $context['conn'];
    $prefix = $context['prefix'];
    commerce_ensure_tables($conn, $prefix);

    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? $_POST['action'] ?? 'list';
    $input = commerce_read_input();
    if (isset($input['action'])) {
        $action = $input['action'];
    }

    if ($method === 'GET') {
        if ($action === 'detail') {
            $invoiceId = (int) ($_GET['id'] ?? 0);
            $detail = commerce_fetch_invoice_detail($conn, $prefix, $invoiceId);
            if (!$detail) {
                commerce_json_response(['success' => false, 'message' => 'Invoice not found'], 404);
            }
            commerce_json_response(['success' => true, 'data' => $detail]);
        }

        $invoices = commerce_fetch_invoices($conn, $prefix);
        commerce_json_response(['success' => true, 'data' => $invoices]);
    }

    if ($method === 'POST' && $action === 'create') {
        $customerId = isset($input['customer_id']) && $input['customer_id'] !== '' ? (int) $input['customer_id'] : null;
        $customerName = trim((string) ($input['customer_name'] ?? ''));
        $customerPhone = trim((string) ($input['customer_phone'] ?? ''));
        $customerEmail = trim((string) ($input['customer_email'] ?? ''));
        $billingAddress = trim((string) ($input['billing_address'] ?? ''));
        $invoiceDate = trim((string) ($input['invoice_date'] ?? date('Y-m-d')));
        $dueDate = trim((string) ($input['due_date'] ?? ''));
        $notes = trim((string) ($input['notes'] ?? ''));
        $status = trim((string) ($input['status'] ?? 'draft'));
        $items = $input['items'] ?? [];

        if ($customerName === '') {
            commerce_json_response(['success' => false, 'message' => 'Customer name is required'], 422);
        }

        if (!is_array($items) || count($items) === 0) {
            commerce_json_response(['success' => false, 'message' => 'At least one invoice item is required'], 422);
        }

        if ($customerId) {
            $customerStmt = $conn->prepare("SELECT * FROM {$prefix}customers WHERE id = ? LIMIT 1");
            $customerStmt->execute([$customerId]);
            $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);
            if (!$customer) {
                commerce_json_response(['success' => false, 'message' => 'Selected customer not found'], 422);
            }

            $customerName = $customer['name'];
            $customerPhone = (string) ($customer['phone'] ?? '');
            $customerEmail = (string) ($customer['email'] ?? '');
            $billingAddress = (string) ($customer['billing_address'] ?? '');
        }

        $normalizedItems = [];
        $subtotal = 0.0;
        $taxTotal = 0.0;
        $grandTotal = 0.0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $productId = isset($item['product_id']) && $item['product_id'] !== '' ? (int) $item['product_id'] : null;
            $itemName = trim((string) ($item['item_name'] ?? ''));
            $description = trim((string) ($item['description'] ?? ''));
            $quantity = (float) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $taxPercent = (float) ($item['tax_percent'] ?? 0);

            if ($productId) {
                $productStmt = $conn->prepare("SELECT id, name, description, unit_price, tax_percent FROM {$prefix}products WHERE id = ? LIMIT 1");
                $productStmt->execute([$productId]);
                $product = $productStmt->fetch(PDO::FETCH_ASSOC);

                if (!$product) {
                    commerce_json_response(['success' => false, 'message' => 'One selected product was not found'], 422);
                }

                if ($itemName === '') {
                    $itemName = $product['name'];
                }
                if ($description === '') {
                    $description = (string) ($product['description'] ?? '');
                }
                if ($unitPrice <= 0) {
                    $unitPrice = (float) $product['unit_price'];
                }
                if ($taxPercent < 0.00001) {
                    $taxPercent = (float) $product['tax_percent'];
                }
            }

            if ($itemName === '' || $quantity <= 0 || $unitPrice < 0 || $taxPercent < 0) {
                commerce_json_response(['success' => false, 'message' => 'Each item needs a valid product, quantity, price, and tax'], 422);
            }

            $lineSubtotal = round($quantity * $unitPrice, 2);
            $lineTax = round($lineSubtotal * ($taxPercent / 100), 2);
            $lineTotal = round($lineSubtotal + $lineTax, 2);

            $subtotal += $lineSubtotal;
            $taxTotal += $lineTax;
            $grandTotal += $lineTotal;

            $normalizedItems[] = [
                'product_id' => $productId,
                'item_name' => $itemName,
                'description' => $description !== '' ? $description : null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'tax_percent' => $taxPercent,
                'line_subtotal' => $lineSubtotal,
                'line_tax' => $lineTax,
                'line_total' => $lineTotal,
            ];
        }

        if (count($normalizedItems) === 0) {
            commerce_json_response(['success' => false, 'message' => 'At least one valid invoice item is required'], 422);
        }

        $conn->beginTransaction();
        try {
            $invoiceNumber = commerce_generate_invoice_number($conn, $prefix);
            $invoiceStmt = $conn->prepare("
                INSERT INTO {$prefix}invoices (
                    invoice_number, customer_id, customer_name, customer_phone, customer_email, billing_address,
                    invoice_date, due_date, subtotal, tax_total, grand_total, notes, status, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $invoiceStmt->execute([
                $invoiceNumber,
                $customerId,
                $customerName,
                $customerPhone !== '' ? $customerPhone : null,
                $customerEmail !== '' ? $customerEmail : null,
                $billingAddress !== '' ? $billingAddress : null,
                $invoiceDate,
                $dueDate !== '' ? $dueDate : null,
                round($subtotal, 2),
                round($taxTotal, 2),
                round($grandTotal, 2),
                $notes !== '' ? $notes : null,
                in_array($status, ['draft', 'sent', 'paid', 'cancelled'], true) ? $status : 'draft',
                $context['user_id']
            ]);

            $invoiceId = (int) $conn->lastInsertId();
            $itemStmt = $conn->prepare("
                INSERT INTO {$prefix}invoice_items (
                    invoice_id, product_id, item_name, description, quantity, unit_price,
                    tax_percent, line_subtotal, line_tax, line_total
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($normalizedItems as $item) {
                $itemStmt->execute([
                    $invoiceId,
                    $item['product_id'],
                    $item['item_name'],
                    $item['description'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['tax_percent'],
                    $item['line_subtotal'],
                    $item['line_tax'],
                    $item['line_total'],
                ]);
            }

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollBack();
            throw $e;
        }

        ob_clean();
        commerce_json_response([
            'success' => true,
            'message' => 'Invoice created successfully',
            'data' => [
                'invoice_id' => $invoiceId,
                'invoice_number' => $invoiceNumber,
                'subtotal' => round($subtotal, 2),
                'tax_total' => round($taxTotal, 2),
                'grand_total' => round($grandTotal, 2),
            ]
        ], 201);
    }

    commerce_json_response(['success' => false, 'message' => 'Invalid request'], 400);
} catch (Throwable $e) {
    commerce_json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
