<?php
require_once '../includes/commerce.php';
require_once '../includes/api_auth.php';
session_start();

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
            $id = (int) ($_GET['id'] ?? 0);
            $stmt = $conn->prepare("SELECT * FROM {$prefix}products WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                commerce_json_response(['success' => false, 'message' => 'Product not found'], 404);
            }

            commerce_json_response(['success' => true, 'data' => $product]);
        }

        $search = trim((string) ($_GET['search'] ?? ''));
        $products = commerce_fetch_products($conn, $prefix, $search);
        commerce_json_response(['success' => true, 'data' => $products]);
    }

    if ($method === 'POST' && $action === 'create') {
        $name = trim((string) ($input['name'] ?? ''));
        $productCode = trim((string) ($input['product_code'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $unitPrice = (float) ($input['unit_price'] ?? 0);
        $purchasePrice = (float) ($input['purchase_price'] ?? 0);
        $pts = (float) ($input['pts'] ?? 0);
        $ptr = (float) ($input['ptr'] ?? 0);
        $mrp = (float) ($input['mrp'] ?? 0);
        $taxPercent = (float) ($input['tax_percent'] ?? 0);
        $unit = trim((string) ($input['unit'] ?? 'PCS'));
        $openingStock = (float) ($input['opening_stock'] ?? 0);
        $hsnCode = trim((string) ($input['hsn_code'] ?? ''));
        $category = trim((string) ($input['category'] ?? ''));
        $status = trim((string) ($input['status'] ?? 'active'));
        $mfgDate = trim((string) ($input['mfg_date'] ?? ''));
        $expDate = trim((string) ($input['exp_date'] ?? ''));

        if ($name === '') {
            commerce_json_response(['success' => false, 'message' => 'Product name is required'], 422);
        }

        if ($unitPrice < 0 || $taxPercent < 0) {
            commerce_json_response(['success' => false, 'message' => 'Price and tax must be positive'], 422);
        }

        $validUnits = ['PCS', 'BOX', 'STRIP', 'PKT', 'KG', 'GM', 'LTR', 'ML', 'MTR', 'SET', 'PAIR', 'DOZEN', 'VIAL', 'TAB', 'CAP'];
        if (!in_array($unit, $validUnits)) {
            $unit = 'PCS';
        }

        $stmt = $conn->prepare("
            INSERT INTO {$prefix}products (product_code, name, description, unit_price, purchase_price, pts, ptr, mrp, tax_percent, unit, opening_stock, stock_quantity, hsn_code, category, mfg_date, exp_date, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $productCode !== '' ? $productCode : null,
            $name,
            $description !== '' ? $description : null,
            $unitPrice,
            $purchasePrice,
            $pts,
            $ptr,
            $mrp,
            $taxPercent,
            $unit,
            $openingStock,
            $openingStock, // stock_quantity starts as opening_stock
            $hsnCode !== '' ? $hsnCode : null,
            $category !== '' ? $category : null,
            $mfgDate !== '' ? $mfgDate : null,
            $expDate !== '' ? $expDate : null,
            in_array($status, ['active', 'inactive'], true) ? $status : 'active',
            $context['user_id']
        ]);

        $productId = (int) $conn->lastInsertId();
        $detailStmt = $conn->prepare("SELECT * FROM {$prefix}products WHERE id = ?");
        $detailStmt->execute([$productId]);

        commerce_json_response([
            'success' => true,
            'message' => 'Product created successfully',
            'data' => $detailStmt->fetch(PDO::FETCH_ASSOC)
        ], 201);
    }

    if ($method === 'POST' && $action === 'update') {
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            commerce_json_response(['success' => false, 'message' => 'Product ID is required'], 422);
        }

        // Check product exists
        $checkStmt = $conn->prepare("SELECT id FROM {$prefix}products WHERE id = ? LIMIT 1");
        $checkStmt->execute([$id]);
        if (!$checkStmt->fetch()) {
            commerce_json_response(['success' => false, 'message' => 'Product not found'], 404);
        }

        $name = trim((string) ($input['name'] ?? ''));
        $productCode = trim((string) ($input['product_code'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $unitPrice = (float) ($input['unit_price'] ?? 0);
        $purchasePrice = (float) ($input['purchase_price'] ?? 0);
        $pts = (float) ($input['pts'] ?? 0);
        $ptr = (float) ($input['ptr'] ?? 0);
        $mrp = (float) ($input['mrp'] ?? 0);
        $taxPercent = (float) ($input['tax_percent'] ?? 0);
        $unit = trim((string) ($input['unit'] ?? 'PCS'));
        $openingStock = (float) ($input['opening_stock'] ?? 0);
        $hsnCode = trim((string) ($input['hsn_code'] ?? ''));
        $category = trim((string) ($input['category'] ?? ''));
        $status = trim((string) ($input['status'] ?? 'active'));
        $mfgDate = trim((string) ($input['mfg_date'] ?? ''));
        $expDate = trim((string) ($input['exp_date'] ?? ''));

        if ($name === '') {
            commerce_json_response(['success' => false, 'message' => 'Product name is required'], 422);
        }

        if ($unitPrice < 0 || $taxPercent < 0) {
            commerce_json_response(['success' => false, 'message' => 'Price and tax must be positive'], 422);
        }

        $validUnits = ['PCS', 'BOX', 'STRIP', 'PKT', 'KG', 'GM', 'LTR', 'ML', 'MTR', 'SET', 'PAIR', 'DOZEN', 'VIAL', 'TAB', 'CAP'];
        if (!in_array($unit, $validUnits)) {
            $unit = 'PCS';
        }

        $stmt = $conn->prepare("
            UPDATE {$prefix}products SET
                product_code = ?, name = ?, description = ?,
                unit_price = ?, purchase_price = ?, pts = ?, ptr = ?, mrp = ?,
                tax_percent = ?, unit = ?, opening_stock = ?,
                hsn_code = ?, category = ?, mfg_date = ?, exp_date = ?,
                status = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $productCode !== '' ? $productCode : null,
            $name,
            $description !== '' ? $description : null,
            $unitPrice,
            $purchasePrice,
            $pts,
            $ptr,
            $mrp,
            $taxPercent,
            $unit,
            $openingStock,
            $hsnCode !== '' ? $hsnCode : null,
            $category !== '' ? $category : null,
            $mfgDate !== '' ? $mfgDate : null,
            $expDate !== '' ? $expDate : null,
            in_array($status, ['active', 'inactive'], true) ? $status : 'active',
            $id
        ]);

        $detailStmt = $conn->prepare("SELECT * FROM {$prefix}products WHERE id = ?");
        $detailStmt->execute([$id]);

        commerce_json_response([
            'success' => true,
            'message' => 'Product updated successfully',
            'data' => $detailStmt->fetch(PDO::FETCH_ASSOC)
        ]);
    }

    if ($method === 'POST' && $action === 'delete') {
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            commerce_json_response(['success' => false, 'message' => 'Product ID is required'], 422);
        }

        $checkStmt = $conn->prepare("SELECT id FROM {$prefix}products WHERE id = ? LIMIT 1");
        $checkStmt->execute([$id]);
        if (!$checkStmt->fetch()) {
            commerce_json_response(['success' => false, 'message' => 'Product not found'], 404);
        }

        // Check if product is used in any invoices
        $usageStmt = $conn->prepare("SELECT COUNT(*) FROM {$prefix}invoice_items WHERE product_id = ?");
        $usageStmt->execute([$id]);
        $usageCount = (int) $usageStmt->fetchColumn();

        if ($usageCount > 0) {
            commerce_json_response(['success' => false, 'message' => "Cannot delete — product is used in {$usageCount} invoice item(s). Set it to Inactive instead."], 422);
        }

        $deleteStmt = $conn->prepare("DELETE FROM {$prefix}products WHERE id = ?");
        $deleteStmt->execute([$id]);

        commerce_json_response([
            'success' => true,
            'message' => 'Product deleted successfully'
        ]);
    }

    commerce_json_response(['success' => false, 'message' => 'Invalid request'], 400);
} catch (Throwable $e) {
    commerce_json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
