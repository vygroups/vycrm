<?php
require_once __DIR__ . '/../config/database.php';

function commerce_get_tenant_context(): array
{
    require_once __DIR__ . '/api_auth.php';
    try {
        return api_require_context();
    } catch (Throwable $e) {
        throw new RuntimeException('Unauthorized: ' . $e->getMessage());
    }
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
            purchase_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            pts DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            ptr DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            mrp DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            unit VARCHAR(20) DEFAULT 'PCS',
            opening_stock DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            stock_quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            hsn_code VARCHAR(50) DEFAULT NULL,
            category VARCHAR(100) DEFAULT NULL,
            mfg_date DATE DEFAULT NULL,
            exp_date DATE DEFAULT NULL,
            status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_products_name (name),
            INDEX idx_products_status (status),
            INDEX idx_products_cat (category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    try { $conn->exec("ALTER TABLE {$prefix}products ADD COLUMN unit VARCHAR(20) DEFAULT 'PCS' AFTER tax_percent"); } catch (Throwable $e) {}
    try { $conn->exec("ALTER TABLE {$prefix}products ADD COLUMN opening_stock DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER unit"); } catch (Throwable $e) {}
    try { $conn->exec("ALTER TABLE {$prefix}products ADD COLUMN stock_quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER opening_stock"); } catch (Throwable $e) {}
    try { $conn->exec("ALTER TABLE {$prefix}products ADD COLUMN hsn_code VARCHAR(50) DEFAULT NULL AFTER stock_quantity"); } catch (Throwable $e) {}
    try { $conn->exec("ALTER TABLE {$prefix}products ADD COLUMN category VARCHAR(100) DEFAULT NULL AFTER hsn_code"); } catch (Throwable $e) {}

    // Migration: Add 4 price tiers + MFG/EXP to products
    try { $conn->exec("ALTER TABLE {$prefix}products ADD COLUMN purchase_price DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER unit_price"); } catch (Throwable $e) {}
    try { $conn->exec("ALTER TABLE {$prefix}products ADD COLUMN pts DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER purchase_price"); } catch (Throwable $e) {}
    try { $conn->exec("ALTER TABLE {$prefix}products ADD COLUMN ptr DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER pts"); } catch (Throwable $e) {}
    try { $conn->exec("ALTER TABLE {$prefix}products ADD COLUMN mrp DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER ptr"); } catch (Throwable $e) {}
    try { $conn->exec("ALTER TABLE {$prefix}products ADD COLUMN mfg_date DATE DEFAULT NULL AFTER category"); } catch (Throwable $e) {}
    try { $conn->exec("ALTER TABLE {$prefix}products ADD COLUMN exp_date DATE DEFAULT NULL AFTER mfg_date"); } catch (Throwable $e) {}

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
    } catch (Throwable $e) {}

    try { $conn->exec("ALTER TABLE {$prefix}invoices ADD COLUMN paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER grand_total"); } catch (Throwable $e) {}
    try { $conn->exec("ALTER TABLE {$prefix}invoices ADD COLUMN payment_status ENUM('unpaid', 'partially_paid', 'paid') NOT NULL DEFAULT 'unpaid' AFTER status"); } catch (Throwable $e) {}

    $conn->exec("
        CREATE TABLE IF NOT EXISTS {$prefix}invoice_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT NOT NULL,
            product_id INT DEFAULT NULL,
            item_name VARCHAR(150) NOT NULL,
            description TEXT DEFAULT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 1.00,
            unit VARCHAR(20) DEFAULT NULL,
            hsn_code VARCHAR(50) DEFAULT NULL,
            batch_no VARCHAR(100) DEFAULT NULL,
            mfg_date DATE DEFAULT NULL,
            exp_date DATE DEFAULT NULL,
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

    try { $conn->exec("ALTER TABLE {$prefix}invoice_items ADD COLUMN unit VARCHAR(20) DEFAULT NULL AFTER quantity"); } catch (Throwable $e) {}
    try { $conn->exec("ALTER TABLE {$prefix}invoice_items ADD COLUMN hsn_code VARCHAR(50) DEFAULT NULL AFTER unit"); } catch (Throwable $e) {}
    try { $conn->exec("ALTER TABLE {$prefix}invoice_items ADD COLUMN batch_no VARCHAR(100) DEFAULT NULL AFTER hsn_code"); } catch (Throwable $e) {}
    try { $conn->exec("ALTER TABLE {$prefix}invoice_items ADD COLUMN mfg_date DATE DEFAULT NULL AFTER batch_no"); } catch (Throwable $e) {}
    try { $conn->exec("ALTER TABLE {$prefix}invoice_items ADD COLUMN exp_date DATE DEFAULT NULL AFTER mfg_date"); } catch (Throwable $e) {}

    $conn->exec("
        CREATE TABLE IF NOT EXISTS {$prefix}attendance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            date DATE NOT NULL,
            punch_in DATETIME DEFAULT NULL,
            punch_out DATETIME DEFAULT NULL,
            total_hours VARCHAR(20) DEFAULT NULL,
            status VARCHAR(50) DEFAULT 'Present',
            type VARCHAR(50) DEFAULT 'shift',
            INDEX idx_attendance_user (user_id),
            INDEX idx_attendance_date (date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    try {
        $conn->exec("ALTER TABLE {$prefix}attendance ADD COLUMN type VARCHAR(50) DEFAULT 'shift' AFTER status");
    } catch (Throwable $e) {}

    $conn->exec("
        CREATE TABLE IF NOT EXISTS {$prefix}vendors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vendor_code VARCHAR(50) DEFAULT NULL UNIQUE,
            name VARCHAR(150) NOT NULL,
            phone VARCHAR(30) DEFAULT NULL,
            email VARCHAR(150) DEFAULT NULL,
            address TEXT DEFAULT NULL,
            gst_number VARCHAR(50) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_vendors_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS {$prefix}purchases (
            id INT AUTO_INCREMENT PRIMARY KEY,
            purchase_number VARCHAR(50) NOT NULL UNIQUE,
            vendor_id INT DEFAULT NULL,
            vendor_name VARCHAR(150) NOT NULL,
            purchase_date DATE NOT NULL,
            subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            tax_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            grand_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            status ENUM('draft', 'received', 'returned', 'cancelled') NOT NULL DEFAULT 'draft',
            payment_status ENUM('unpaid', 'partially_paid', 'paid') NOT NULL DEFAULT 'unpaid',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_purchases_number (purchase_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS {$prefix}purchase_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            purchase_id INT NOT NULL,
            product_id INT DEFAULT NULL,
            item_name VARCHAR(150) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 1.00,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            FOREIGN KEY (purchase_id) REFERENCES {$prefix}purchases(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS {$prefix}expenses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            expense_date DATE NOT NULL,
            category VARCHAR(100) NOT NULL,
            description TEXT,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            payment_mode VARCHAR(50) DEFAULT 'Cash',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS {$prefix}business_profile (
            id INT PRIMARY KEY DEFAULT 1,
            business_name VARCHAR(150) DEFAULT NULL,
            gstin VARCHAR(50) DEFAULT NULL,
            phone VARCHAR(30) DEFAULT NULL,
            email VARCHAR(150) DEFAULT NULL,
            address TEXT DEFAULT NULL,
            business_type VARCHAR(100) DEFAULT NULL,
            business_category VARCHAR(100) DEFAULT NULL,
            logo_path VARCHAR(255) DEFAULT NULL,
            signature_path VARCHAR(255) DEFAULT NULL,
            bank_name VARCHAR(150) DEFAULT NULL,
            account_no VARCHAR(100) DEFAULT NULL,
            ifsc_code VARCHAR(50) DEFAULT NULL,
            terms TEXT DEFAULT NULL,
            country VARCHAR(50) DEFAULT 'IN',
            currency_symbol VARCHAR(10) DEFAULT '₹',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    try { $conn->exec("ALTER TABLE {$prefix}business_profile ADD COLUMN logo_path VARCHAR(255) DEFAULT NULL AFTER business_category"); } catch (Throwable $e) {}
    try { $conn->exec("ALTER TABLE {$prefix}business_profile ADD COLUMN bank_name VARCHAR(150) DEFAULT NULL AFTER signature_path"); } catch (Throwable $e) {}
    try { $conn->exec("ALTER TABLE {$prefix}business_profile ADD COLUMN account_no VARCHAR(100) DEFAULT NULL AFTER bank_name"); } catch (Throwable $e) {}
    try { $conn->exec("ALTER TABLE {$prefix}business_profile ADD COLUMN ifsc_code VARCHAR(50) DEFAULT NULL AFTER account_no"); } catch (Throwable $e) {}
    try { $conn->exec("ALTER TABLE {$prefix}business_profile ADD COLUMN terms TEXT DEFAULT NULL AFTER ifsc_code"); } catch (Throwable $e) {}
    try { $conn->exec("ALTER TABLE {$prefix}business_profile ADD COLUMN country VARCHAR(50) DEFAULT 'IN' AFTER terms"); } catch (Throwable $e) {}
    try { $conn->exec("ALTER TABLE {$prefix}business_profile ADD COLUMN currency_symbol VARCHAR(10) DEFAULT '₹' AFTER country"); } catch (Throwable $e) {}

    // Invoice print/field settings
    $conn->exec("
        CREATE TABLE IF NOT EXISTS {$prefix}invoice_settings (
            id INT PRIMARY KEY DEFAULT 1,
            printer_type VARCHAR(20) NOT NULL DEFAULT 'regular',
            layout_theme VARCHAR(30) NOT NULL DEFAULT 'classic',
            color_scheme VARCHAR(7) NOT NULL DEFAULT '#1a1a2e',
            paper_size VARCHAR(10) NOT NULL DEFAULT 'A4',
            show_company_name TINYINT(1) NOT NULL DEFAULT 1,
            show_logo TINYINT(1) NOT NULL DEFAULT 1,
            show_address TINYINT(1) NOT NULL DEFAULT 1,
            show_email TINYINT(1) NOT NULL DEFAULT 1,
            show_phone TINYINT(1) NOT NULL DEFAULT 1,
            show_gstin TINYINT(1) NOT NULL DEFAULT 1,
            show_bank_details TINYINT(1) NOT NULL DEFAULT 1,
            show_terms TINYINT(1) NOT NULL DEFAULT 1,
            show_signature TINYINT(1) NOT NULL DEFAULT 1,
            show_acknowledgement TINYINT(1) NOT NULL DEFAULT 1,
            show_hsn TINYINT(1) NOT NULL DEFAULT 1,
            show_batch_info TINYINT(1) NOT NULL DEFAULT 0,
            repeat_header TINYINT(1) NOT NULL DEFAULT 0,
            default_printer TINYINT(1) NOT NULL DEFAULT 1,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    /* ──────────────────────────── DYNAMIC MODULES SCHEMA ──────────────────────────── */

    // 1. Modules
    $conn->exec("
        CREATE TABLE IF NOT EXISTS {$prefix}modules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            slug VARCHAR(150) NOT NULL UNIQUE,
            icon VARCHAR(100) DEFAULT 'fa-solid fa-cube',
            description TEXT DEFAULT NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            sort_order INT NOT NULL DEFAULT 0,
            visibility_rule ENUM('all','owner','role_down','role_equal_down','role_up') NOT NULL DEFAULT 'all',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_modules_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // 2. System Settings
    $conn->exec("
        CREATE TABLE IF NOT EXISTS {$prefix}system_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $conn->exec("INSERT IGNORE INTO {$prefix}system_settings (setting_key, setting_value) VALUES ('attendance_enabled', '1'), ('billing_enabled', '1')");

    // 3. Blocks (sections within a module form)
    $conn->exec("
        CREATE TABLE IF NOT EXISTS {$prefix}module_blocks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            module_id INT NOT NULL,
            name VARCHAR(150) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (module_id) REFERENCES {$prefix}modules(id) ON DELETE CASCADE,
            INDEX idx_blocks_module (module_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // 4. Fields
    $conn->exec("
        CREATE TABLE IF NOT EXISTS {$prefix}module_fields (
            id INT AUTO_INCREMENT PRIMARY KEY,
            block_id INT NOT NULL,
            module_id INT NOT NULL,
            field_key VARCHAR(100) NOT NULL,
            label VARCHAR(150) NOT NULL,
            field_type VARCHAR(50) NOT NULL DEFAULT 'text',
            placeholder VARCHAR(255) DEFAULT NULL,
            default_value TEXT DEFAULT NULL,
            is_required TINYINT(1) NOT NULL DEFAULT 0,
            is_unique TINYINT(1) NOT NULL DEFAULT 0,
            is_searchable TINYINT(1) NOT NULL DEFAULT 0,
            is_list_visible TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            config JSON DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (block_id) REFERENCES {$prefix}module_blocks(id) ON DELETE CASCADE,
            FOREIGN KEY (module_id) REFERENCES {$prefix}modules(id) ON DELETE CASCADE,
            INDEX idx_fields_block (block_id),
            INDEX idx_fields_module (module_id),
            UNIQUE KEY uk_field_key_module (module_id, field_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // 5. Field Options (for dropdown / multi_picker)
    $conn->exec("
        CREATE TABLE IF NOT EXISTS {$prefix}module_field_options (
            id INT AUTO_INCREMENT PRIMARY KEY,
            field_id INT NOT NULL,
            label VARCHAR(255) NOT NULL,
            value VARCHAR(255) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            FOREIGN KEY (field_id) REFERENCES {$prefix}module_fields(id) ON DELETE CASCADE,
            INDEX idx_options_field (field_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // 6. Field Rules (dependencies & conditionals)
    $conn->exec("
        CREATE TABLE IF NOT EXISTS {$prefix}module_field_rules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            field_id INT NOT NULL,
            rule_type ENUM('dependency','conditional') NOT NULL,
            source_field_id INT NOT NULL,
            operator VARCHAR(20) NOT NULL DEFAULT 'equals',
            value TEXT DEFAULT NULL,
            action VARCHAR(50) NOT NULL DEFAULT 'show',
            config JSON DEFAULT NULL,
            FOREIGN KEY (field_id) REFERENCES {$prefix}module_fields(id) ON DELETE CASCADE,
            FOREIGN KEY (source_field_id) REFERENCES {$prefix}module_fields(id) ON DELETE CASCADE,
            INDEX idx_rules_field (field_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // 7. Records (EAV — header row per module entry)
    $conn->exec("
        CREATE TABLE IF NOT EXISTS {$prefix}module_records (
            id INT AUTO_INCREMENT PRIMARY KEY,
            module_id INT NOT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by INT DEFAULT NULL,
            FOREIGN KEY (module_id) REFERENCES {$prefix}modules(id) ON DELETE CASCADE,
            INDEX idx_records_module (module_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // 8. Record Values (EAV — one row per field value)
    $conn->exec("
        CREATE TABLE IF NOT EXISTS {$prefix}module_record_values (
            id INT AUTO_INCREMENT PRIMARY KEY,
            record_id INT NOT NULL,
            field_id INT NOT NULL,
            value TEXT DEFAULT NULL,
            FOREIGN KEY (record_id) REFERENCES {$prefix}module_records(id) ON DELETE CASCADE,
            FOREIGN KEY (field_id) REFERENCES {$prefix}module_fields(id) ON DELETE CASCADE,
            INDEX idx_values_record (record_id),
            INDEX idx_values_field (field_id),
            UNIQUE KEY uk_record_field (record_id, field_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // 9. Record History / Audit Log
    $conn->exec("
        CREATE TABLE IF NOT EXISTS {$prefix}module_record_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            record_id INT NOT NULL,
            field_id INT NOT NULL,
            old_value TEXT DEFAULT NULL,
            new_value TEXT DEFAULT NULL,
            changed_by INT DEFAULT NULL,
            changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (record_id) REFERENCES {$prefix}module_records(id) ON DELETE CASCADE,
            FOREIGN KEY (field_id) REFERENCES {$prefix}module_fields(id) ON DELETE CASCADE,
            INDEX idx_history_record (record_id),
            INDEX idx_history_field (field_id)
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

function commerce_fetch_invoice_stats(PDO $conn, string $prefix): array
{
    require_once __DIR__ . '/dynamic_modules.php';
    $rule = dm_get_system_setting($conn, $prefix, 'billing_visibility', 'all');
    $isAdmin = !empty($_SESSION['is_admin']);
    $allowedUserIds = dm_get_visible_user_ids($conn, $prefix, (int)($_SESSION['user_id'] ?? 0), isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : null, $rule, $isAdmin);

    $where = "";
    if ($allowedUserIds !== null) {
        $where = "WHERE created_by IN (" . implode(',', array_map('intval', $allowedUserIds ?: [0])) . ")";
    }

    $stmt = $conn->query("
        SELECT 
            SUM(grand_total) as total_sale,
            SUM(paid_amount) as total_paid,
            SUM(grand_total - paid_amount) as total_unpaid
        FROM {$prefix}invoices
        $where
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    return [
        'total' => (float)($stats['total_sale'] ?? 0),
        'paid' => (float)($stats['total_paid'] ?? 0),
        'unpaid' => (float)($stats['total_unpaid'] ?? 0)
    ];
}

function commerce_fetch_purchase_stats(PDO $conn, string $prefix): array
{
    $stmt = $conn->query("
        SELECT 
            SUM(grand_total) as total_purchase,
            SUM(paid_amount) as total_paid,
            SUM(grand_total - paid_amount) as total_unpaid
        FROM {$prefix}purchases
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    return [
        'total' => (float)($stats['total_purchase'] ?? 0),
        'paid' => (float)($stats['total_paid'] ?? 0),
        'unpaid' => (float)($stats['total_unpaid'] ?? 0)
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

function commerce_generate_purchase_number(PDO $conn, string $prefix): string
{
    $dateKey = date('Ymd');
    $like = 'PUR-' . $dateKey . '-%';
    $stmt = $conn->prepare("SELECT purchase_number FROM {$prefix}purchases WHERE purchase_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$like]);
    $latest = $stmt->fetchColumn();

    $next = 1;
    if ($latest && preg_match('/(\d+)$/', $latest, $matches)) {
        $next = ((int) $matches[1]) + 1;
    }

    return sprintf('PUR-%s-%03d', $dateKey, $next);
}

function commerce_fetch_vendors(PDO $conn, string $prefix): array
{
    $stmt = $conn->query("SELECT * FROM {$prefix}vendors ORDER BY name ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    require_once __DIR__ . '/dynamic_modules.php';
    $rule = dm_get_system_setting($conn, $prefix, 'billing_visibility', 'all');
    $isAdmin = !empty($_SESSION['is_admin']);
    $allowedUserIds = dm_get_visible_user_ids($conn, $prefix, (int)($_SESSION['user_id'] ?? 0), isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : null, $rule, $isAdmin);

    $where = "";
    if ($allowedUserIds !== null) {
        $where = "WHERE i.created_by IN (" . implode(',', array_map('intval', $allowedUserIds ?: [0])) . ")";
    }

    $stmt = $conn->query("
        SELECT i.*,
               COUNT(ii.id) AS item_count
        FROM {$prefix}invoices i
        LEFT JOIN {$prefix}invoice_items ii ON ii.invoice_id = i.id
        $where
        GROUP BY i.id
        ORDER BY i.created_at DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
