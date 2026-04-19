<?php
// api/vendors.php - Vendor Management API
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
        $name = trim($input['name'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $email = trim($input['email'] ?? '');
        $address = trim($input['address'] ?? '');
        $gst = trim($input['gst_number'] ?? '');

        if (!$name) {
            commerce_json_response(['success' => false, 'message' => 'Vendor name is required'], 422);
        }

        $stmt = $conn->prepare("INSERT INTO {$prefix}vendors (name, phone, email, address, gst_number) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $phone, $email, $address, $gst]);
        
        commerce_json_response([
            'success' => true, 
            'message' => 'Vendor added successfully',
            'data' => ['id' => $conn->lastInsertId()]
        ]);
    }

    if ($method === 'GET') {
        $vendors = commerce_fetch_vendors($conn, $prefix);
        commerce_json_response(['success' => true, 'data' => $vendors]);
    }

} catch (Throwable $e) {
    commerce_json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
