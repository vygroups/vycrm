<?php
// migrate_payroll.php
require_once 'config/database.php';

try {
    $masterConn = Database::getMasterConn();
    $prefix = Database::getMasterPrefix();
    $masterDB = Database::getMasterDBName();
    
    // Get all companies
    $stmt = $masterConn->query("SELECT db_name, slug FROM {$prefix}companies");
    $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($tenants as $tenant) {
        $dbName = $tenant['db_name'];
        $slug = $tenant['slug'];
        
        // Determine prefix based on isolation logic from login API
        $isIsolated = ($dbName != $masterDB);
        $tenantPrefix = $isIsolated ? "" : $slug . "_";
        
        echo "Migrating tenant DB: $dbName (Prefix: '$tenantPrefix')\n";
        
        $conn = Database::getTenantConn($dbName);
        if (!$conn) {
            echo "  [SKIPPED] Could not connect to $dbName\n";
            continue;
        }

        try {
            // 1. Employee Details
            $conn->exec("CREATE TABLE IF NOT EXISTS {$tenantPrefix}employee_details (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL UNIQUE,
                employee_id_string VARCHAR(50) DEFAULT NULL,
                designation VARCHAR(150) DEFAULT NULL,
                joining_date DATE DEFAULT NULL,
                basic_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                hra DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                other_allowances DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                standard_deductions DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                bank_name VARCHAR(150) DEFAULT NULL,
                account_no VARCHAR(100) DEFAULT NULL,
                ifsc_code VARCHAR(50) DEFAULT NULL,
                pan_number VARCHAR(50) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES {$tenantPrefix}users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            echo "  [SUCCESS] Created employee_details\n";

            // 2. Salaries
            $conn->exec("CREATE TABLE IF NOT EXISTS {$tenantPrefix}salaries (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                salary_month VARCHAR(20) NOT NULL,
                gross_earnings DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                total_deductions DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                net_payable DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                status ENUM('draft', 'paid') NOT NULL DEFAULT 'draft',
                payslip_sent TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES {$tenantPrefix}users(id) ON DELETE CASCADE,
                UNIQUE KEY uk_user_month (user_id, salary_month)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            echo "  [SUCCESS] Created salaries\n";

            // 3. Email Templates
            $conn->exec("CREATE TABLE IF NOT EXISTS {$tenantPrefix}email_templates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                module VARCHAR(50) NOT NULL UNIQUE,
                subject VARCHAR(255) NOT NULL,
                body TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            echo "  [SUCCESS] Created email_templates\n";

            // Insert default payslip template
            $defaultBody = '
            <div style="font-family: sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;">
                <div style="background: #1e293b; color: #fff; padding: 20px; text-align: center;">
                    <h2>Payslip for {{salary_month}}</h2>
                </div>
                <div style="padding: 20px;">
                    <p>Dear <strong>{{employee_name}}</strong>,</p>
                    <p>Please find the details of your salary for the month of <strong>{{salary_month}}</strong> below:</p>
                    <table style="width: 100%; border-collapse: collapse; margin-top: 15px;">
                        <tr style="border-bottom: 1px solid #e2e8f0;"><td style="padding: 8px 0;"><strong>Employee ID:</strong></td><td style="text-align: right;">{{employee_id}}</td></tr>
                        <tr style="border-bottom: 1px solid #e2e8f0;"><td style="padding: 8px 0;"><strong>Designation:</strong></td><td style="text-align: right;">{{designation}}</td></tr>
                        <tr style="border-bottom: 1px solid #e2e8f0;"><td style="padding: 8px 0;"><strong>Basic Salary:</strong></td><td style="text-align: right;">{{basic_salary}}</td></tr>
                        <tr style="border-bottom: 1px solid #e2e8f0;"><td style="padding: 8px 0;"><strong>HRA:</strong></td><td style="text-align: right;">{{hra}}</td></tr>
                        <tr style="border-bottom: 1px solid #e2e8f0;"><td style="padding: 8px 0;"><strong>Other Allowances:</strong></td><td style="text-align: right;">{{other_allowances}}</td></tr>
                        <tr style="border-bottom: 1px solid #e2e8f0;"><td style="padding: 8px 0;"><strong>Gross Earnings:</strong></td><td style="text-align: right;"><strong>{{gross_earnings}}</strong></td></tr>
                        <tr style="border-bottom: 1px solid #e2e8f0;"><td style="padding: 8px 0; color: #ef4444;"><strong>Total Deductions:</strong></td><td style="text-align: right; color: #ef4444;">{{total_deductions}}</td></tr>
                        <tr><td style="padding: 12px 0; font-size: 18px;"><strong>Net Payable:</strong></td><td style="text-align: right; font-size: 18px; color: #10b981;"><strong>{{net_payable}}</strong></td></tr>
                    </table>
                    <div style="margin-top: 20px; padding: 15px; background: #f8fafc; border-radius: 6px;">
                        <p style="margin: 0; font-size: 14px;"><strong>Bank Name:</strong> {{bank_name}}</p>
                        <p style="margin: 5px 0 0; font-size: 14px;"><strong>Account No:</strong> {{account_no}}</p>
                    </div>
                    <p style="margin-top: 20px; font-size: 12px; color: #64748b; text-align: center;">This is a system-generated email and does not require a signature.</p>
                </div>
            </div>';

            $chkStmt = $conn->prepare("SELECT id FROM {$tenantPrefix}email_templates WHERE module = 'payslip'");
            $chkStmt->execute();
            if (!$chkStmt->fetchColumn()) {
                $insStmt = $conn->prepare("INSERT INTO {$tenantPrefix}email_templates (module, subject, body) VALUES ('payslip', 'Payslip for {{salary_month}}', ?)");
                $insStmt->execute([$defaultBody]);
                echo "  [SUCCESS] Inserted default payslip email template\n";
            }
        } catch (Exception $e) {
            echo "  [ERROR] " . $e->getMessage() . "\n";
        }
    }
    echo "\nMigration process completed.\n";
} catch (Exception $e) {
    die("Migration Global Error: " . $e->getMessage());
}
?>
