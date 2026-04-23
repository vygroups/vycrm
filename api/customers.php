<?php
require_once '../includes/commerce.php';
require_once '../includes/api_auth.php';
session_start();
ob_start();

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
            $stmt = $conn->prepare("SELECT * FROM {$prefix}customers WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$customer) {
                commerce_json_response(['success' => false, 'message' => 'Customer not found'], 404);
            }
            commerce_json_response(['success' => true, 'data' => $customer]);
        }

        $search = trim((string) ($_GET['search'] ?? ''));
        commerce_json_response(['success' => true, 'data' => commerce_fetch_customers($conn, $prefix, $search)]);
    }

    if ($method === 'POST' && $action === 'create') {
        $name = trim((string) ($input['name'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $billingAddress = trim((string) ($input['billing_address'] ?? ''));
        $gstNumber = trim((string) ($input['gst_number'] ?? ''));
        $customerCode = trim((string) ($input['customer_code'] ?? ''));

        if ($name === '') {
            commerce_json_response(['success' => false, 'message' => 'Customer name is required'], 422);
        }

        $stmt = $conn->prepare("
            INSERT INTO {$prefix}customers (customer_code, name, phone, email, billing_address, gst_number, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $customerCode !== '' ? $customerCode : null,
            $name,
            $phone !== '' ? $phone : null,
            $email !== '' ? $email : null,
            $billingAddress !== '' ? $billingAddress : null,
            $gstNumber !== '' ? $gstNumber : null,
            $context['user_id']
        ]);

        $customerId = (int) $conn->lastInsertId();
        $detailStmt = $conn->prepare("SELECT * FROM {$prefix}customers WHERE id = ?");
        $detailStmt->execute([$customerId]);

        ob_clean();
        commerce_json_response([
            'success' => true,
            'message' => 'Customer created successfully',
            'data' => $detailStmt->fetch(PDO::FETCH_ASSOC)
        ], 201);
    }

    if ($method === 'POST' && $action === 'update') {
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            commerce_json_response(['success' => false, 'message' => 'Customer ID is required'], 422);
        }

        $checkStmt = $conn->prepare("SELECT id FROM {$prefix}customers WHERE id = ? LIMIT 1");
        $checkStmt->execute([$id]);
        if (!$checkStmt->fetch()) {
            commerce_json_response(['success' => false, 'message' => 'Customer not found'], 404);
        }

        $name = trim((string) ($input['name'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $billingAddress = trim((string) ($input['billing_address'] ?? ''));
        $gstNumber = trim((string) ($input['gst_number'] ?? ''));
        $customerCode = trim((string) ($input['customer_code'] ?? ''));

        if ($name === '') {
            commerce_json_response(['success' => false, 'message' => 'Customer name is required'], 422);
        }

        $stmt = $conn->prepare("
            UPDATE {$prefix}customers SET
                customer_code = ?, name = ?, phone = ?, email = ?,
                billing_address = ?, gst_number = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $customerCode !== '' ? $customerCode : null,
            $name,
            $phone !== '' ? $phone : null,
            $email !== '' ? $email : null,
            $billingAddress !== '' ? $billingAddress : null,
            $gstNumber !== '' ? $gstNumber : null,
            $id
        ]);

        $detailStmt = $conn->prepare("SELECT * FROM {$prefix}customers WHERE id = ?");
        $detailStmt->execute([$id]);

        ob_clean();
        commerce_json_response([
            'success' => true,
            'message' => 'Customer updated successfully',
            'data' => $detailStmt->fetch(PDO::FETCH_ASSOC)
        ]);
    }

    commerce_json_response(['success' => false, 'message' => 'Invalid request'], 400);
} catch (Throwable $e) {
    commerce_json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
