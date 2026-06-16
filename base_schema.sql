CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS role_hierarchy (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_role_id INT NOT NULL,
    child_role_id INT NOT NULL,
    FOREIGN KEY (parent_role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (child_role_id) REFERENCES roles(id) ON DELETE CASCADE,
    UNIQUE KEY unique_link (parent_role_id, child_role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    first_name VARCHAR(50) DEFAULT '',
    last_name VARCHAR(50) DEFAULT '',
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100) NOT NULL,
    role_id INT DEFAULT NULL,
    is_admin TINYINT(1) DEFAULT 0,
    time_format VARCHAR(10) DEFAULT '12h',
    date_format VARCHAR(20) DEFAULT 'd M, Y',
    profile_picture TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    date DATE NOT NULL,
    punch_in DATETIME DEFAULT NULL,
    punch_out DATETIME DEFAULT NULL,
    total_hours VARCHAR(20) DEFAULT NULL,
    status VARCHAR(50) DEFAULT 'Present',
    type VARCHAR(50) DEFAULT 'shift',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS business_profile (
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
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leaves (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    leave_type VARCHAR(100) NOT NULL,
    from_date DATE NOT NULL,
    to_date DATE NOT NULL,
    reason TEXT,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    date DATE NOT NULL,
    time_window VARCHAR(100) NOT NULL,
    duration VARCHAR(50) NOT NULL,
    reason TEXT,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS enquiries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    enquiry_no VARCHAR(50) NOT NULL UNIQUE,
    student_name VARCHAR(100) NOT NULL,
    status ENUM('New Lead', 'Submitted', 'Fee Offer Sent', 'Cold', 'Warm', 'Hot') DEFAULT 'New Lead',
    score INT DEFAULT 0,
    bucket ENUM('PRIMARY', 'SECONDARY', 'UNSCORED') DEFAULT 'UNSCORED',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS customers (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invoices (
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
    paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    notes TEXT DEFAULT NULL,
    status ENUM('draft', 'sent', 'paid', 'cancelled') NOT NULL DEFAULT 'draft',
    payment_status ENUM('unpaid', 'partially_paid', 'paid') NOT NULL DEFAULT 'unpaid',
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_invoices_number (invoice_number),
    INDEX idx_invoices_customer (customer_name),
    INDEX idx_invoices_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invoice_items (
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
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    INDEX idx_invoice_items_invoice (invoice_id),
    INDEX idx_invoice_items_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vendors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendor_code VARCHAR(50) DEFAULT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    gst_number VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vendors_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchases (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_id INT NOT NULL,
    product_id INT DEFAULT NULL,
    item_name VARCHAR(150) NOT NULL,
    quantity DECIMAL(12,2) NOT NULL DEFAULT 1.00,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    expense_date DATE NOT NULL,
    category VARCHAR(100) NOT NULL,
    description TEXT,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    payment_mode VARCHAR(50) DEFAULT 'Cash',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invoice_settings (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS modules (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('attendance_enabled', '1'), ('billing_enabled', '1');

CREATE TABLE IF NOT EXISTS module_blocks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE,
    INDEX idx_blocks_module (module_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_fields (
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
    FOREIGN KEY (block_id) REFERENCES module_blocks(id) ON DELETE CASCADE,
    FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE,
    INDEX idx_fields_block (block_id),
    INDEX idx_fields_module (module_id),
    UNIQUE KEY uk_field_key_module (module_id, field_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_field_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    field_id INT NOT NULL,
    label VARCHAR(255) NOT NULL,
    value VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    FOREIGN KEY (field_id) REFERENCES module_fields(id) ON DELETE CASCADE,
    INDEX idx_options_field (field_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_field_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    field_id INT NOT NULL,
    rule_type ENUM('dependency','conditional') NOT NULL,
    source_field_id INT NOT NULL,
    operator VARCHAR(20) NOT NULL DEFAULT 'equals',
    value TEXT DEFAULT NULL,
    action VARCHAR(50) NOT NULL DEFAULT 'show',
    config JSON DEFAULT NULL,
    FOREIGN KEY (field_id) REFERENCES module_fields(id) ON DELETE CASCADE,
    FOREIGN KEY (source_field_id) REFERENCES module_fields(id) ON DELETE CASCADE,
    INDEX idx_rules_field (field_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module_id INT NOT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT DEFAULT NULL,
    FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE,
    INDEX idx_records_module (module_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_record_values (
    id INT AUTO_INCREMENT PRIMARY KEY,
    record_id INT NOT NULL,
    field_id INT NOT NULL,
    value TEXT DEFAULT NULL,
    FOREIGN KEY (record_id) REFERENCES module_records(id) ON DELETE CASCADE,
    FOREIGN KEY (field_id) REFERENCES module_fields(id) ON DELETE CASCADE,
    INDEX idx_values_record (record_id),
    INDEX idx_values_field (field_id),
    UNIQUE KEY uk_record_field (record_id, field_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
