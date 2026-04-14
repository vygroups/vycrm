<?php
require_once __DIR__ . '/../config/database.php';

function commerce_get_tenant_context(): array
{
    if (!isset($_SESSION['user_id'], $_SESSION['tenant_db'], $_SESSION['tenant_prefix'])) {
        throw new RuntimeException('Unauthorized');
    }

    $conn = Database::getTenantConn($_SESSION['tenant_db']);
    if (!$conn) {
        throw new RuntimeException('Database connection failed');
    }

    return [
        'user_id' => (int) $_SESSION['user_id'],
        'username' => $_SESSION['username'] ?? '',
        'db_name' => $_SESSION['tenant_db'],
        'prefix' => $_SESSION['tenant_prefix'],
        'conn' => $conn,
    ];
}

function commerce_ensure_tables(PDO $conn, string $prefix): void
{
    $conn->exec("
        CREATE TABLE IF NOT EXISTS {$prefix}customers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_code VARCHAR(50) DEFAULT NULL UNIQUE,
            name VARCHAR(150) NOT NULL,
            phone VARCHAR(30) DEFAULT NULL,
            email VARCHAR(150) DEFAULT NULL,
            billing_address TEXT DEFAULT NULL,
            gst_number VARCHAR(50) DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_customers_name (name),
            INDEX idx_customers_phone (phone),
            INDEX idx_customers_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS {$prefix}products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_code VARCHAR(50) DEFAULT NULL UNIQUE,
            name VARCHAR(150) NOT NULL,
            description TEXT DEFAULT NULL,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_products_name (name),
            INDEX idx_products_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS {$prefix}invoices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_number VARCHAR(50) NOT NULL UNIQUE,
            customer_id INT DEFAULT NULL,
            customer_name VARCHAR(150) NOT NULL,
            customer_phone VARCHAR(30) DEFAULT NULL,
            customer_email VARCHAR(150) DEFAULT NULL,
            billing_address TEXT DEFAULT NULL,
            invoice_date DATE NOT NULL,
            due_date DATE DEFAULT NULL,
            subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            tax_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            grand_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            notes TEXT DEFAULT NULL,
            status ENUM('draft', 'sent', 'paid', 'cancelled') NOT NULL DEFAULT 'draft',
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_invoices_number (invoice_number),
            INDEX idx_invoices_customer (customer_name),
            INDEX idx_invoices_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    try {
        $conn->exec("ALTER TABLE {$prefix}invoices ADD COLUMN customer_id INT DEFAULT NULL AFTER invoice_number");
    } catch (Throwable $e) {
    }

    $conn->exec("
        CREATE TABLE IF NOT EXISTS {$prefix}invoice_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT NOT NULL,
            product_id INT DEFAULT NULL,
            item_name VARCHAR(150) NOT NULL,
            description TEXT DEFAULT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 1.00,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            line_subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            line_tax DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (invoice_id) REFERENCES {$prefix}invoices(id) ON DELETE CASCADE,
            INDEX idx_invoice_items_invoice (invoice_id),
            INDEX idx_invoice_items_product (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function commerce_fetch_customers(PDO $conn, string $prefix, ?string $search = null): array
{
    if ($search !== null && $search !== '') {
        $stmt = $conn->prepare("
            SELECT *
            FROM {$prefix}customers
            WHERE name LIKE ? OR phone LIKE ? OR email LIKE ?
            ORDER BY created_at DESC
        ");
        $term = '%' . $search . '%';
        $stmt->execute([$term, $term, $term]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $conn->query("
        SELECT *
        FROM {$prefix}customers
        ORDER BY created_at DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function commerce_fetch_invoice_detail(PDO $conn, string $prefix, int $invoiceId): ?array
{
    $stmt = $conn->prepare("
        SELECT i.*, c.customer_code, c.gst_number
        FROM {$prefix}invoices i
        LEFT JOIN {$prefix}customers c ON c.id = i.customer_id
        WHERE i.id = ?
        LIMIT 1
    ");
    $stmt->execute([$invoiceId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) {
        return null;
    }

    $itemsStmt = $conn->prepare("
        SELECT *
        FROM {$prefix}invoice_items
        WHERE invoice_id = ?
        ORDER BY id ASC
    ");
    $itemsStmt->execute([$invoiceId]);

    return [
        'invoice' => $invoice,
        'items' => $itemsStmt->fetchAll(PDO::FETCH_ASSOC),
    ];
}

function commerce_read_input(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw ?: '[]', true);
        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}

function commerce_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function commerce_generate_invoice_number(PDO $conn, string $prefix): string
{
    $dateKey = date('Ymd');
    $like = 'INV-' . $dateKey . '-%';
    $stmt = $conn->prepare("SELECT invoice_number FROM {$prefix}invoices WHERE invoice_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$like]);
    $latest = $stmt->fetchColumn();

    $next = 1;
    if ($latest && preg_match('/(\d+)$/', $latest, $matches)) {
        $next = ((int) $matches[1]) + 1;
    }

    return sprintf('INV-%s-%03d', $dateKey, $next);
}

function commerce_fetch_products(PDO $conn, string $prefix, ?string $search = null): array
{
    if ($search !== null && $search !== '') {
        $stmt = $conn->prepare("
            SELECT *
            FROM {$prefix}products
            WHERE status = 'active' AND (name LIKE ? OR product_code LIKE ?)
            ORDER BY created_at DESC
        ");
        $term = '%' . $search . '%';
        $stmt->execute([$term, $term]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $conn->query("
        SELECT *
        FROM {$prefix}products
        ORDER BY created_at DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function commerce_fetch_invoices(PDO $conn, string $prefix): array
{
    $stmt = $conn->query("
        SELECT i.*,
               COUNT(ii.id) AS item_count
        FROM {$prefix}invoices i
        LEFT JOIN {$prefix}invoice_items ii ON ii.invoice_id = i.id
        GROUP BY i.id
        ORDER BY i.created_at DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
