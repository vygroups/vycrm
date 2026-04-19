<?php
// api/export.php - General CSV Export Utility
require_once '../auth_check.php';
require_once '../includes/commerce.php';

$type = $_GET['type'] ?? '';
if (!$type) die("Invalid type");

$context = commerce_get_tenant_context();
$conn = $context['conn'];
$prefix = $context['prefix'];

$data = [];
$filename = $type . "_export_" . date('Y-m-d') . ".csv";

switch ($type) {
    case 'invoices':
        $stmt = $conn->query("SELECT invoice_number, customer_name, invoice_date, grand_total, paid_amount, status FROM {$prefix}invoices ORDER BY invoice_date DESC");
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
    case 'purchases':
        $stmt = $conn->query("SELECT purchase_number, vendor_name, purchase_date, grand_total, paid_amount, payment_status FROM {$prefix}purchases ORDER BY purchase_date DESC");
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
    case 'expenses':
        $stmt = $conn->query("SELECT expense_date, category, description, amount, payment_mode FROM {$prefix}expenses ORDER BY expense_date DESC");
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
    case 'products':
        $stmt = $conn->query("SELECT product_code, name, unit_price, tax_percent, status FROM {$prefix}products ORDER BY name ASC");
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
    default:
        die("Unsupported type");
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

$output = fopen('php://output', 'w');

// Header
if (!empty($data)) {
    fputcsv($output, array_keys($data[0]));
}

// Rows
foreach ($data as $row) {
    fputcsv($output, $row);
}

fclose($output);
exit;
