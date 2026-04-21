<?php
// includes/sidebar.php - Shared Sidebar Component
require_once __DIR__ . '/navigation_config.php';
$currentFile = basename($_SERVER['PHP_SELF']);
$moduleConfig = vycrm_module_config();
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-head">
        <a href="dashboard.php"><img src="<?= $companyLogo ?>?v=<?= $v ?>" alt="<?= $companyName ?>" style="max-height:50px;"></a>
        <div class="sidebar-toggle hidden-mobile" onclick="toggleSidebar()">
            <i class="fa-solid fa-chevron-left" id="toggleIcon"></i>
        </div>
    </div>
    <div class="sidebar-nav">
        <!-- Dashboard Section -->
        <a href="dashboard.php" class="nav-item <?= $currentFile === 'dashboard.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-chart-pie"></i><span class="nav-text">Dashboard</span>
        </a>

        <div class="sidebar-section"><?= htmlspecialchars(strtoupper($moduleConfig['billing']['section_label'])) ?></div>
        <a href="customers.php" class="nav-item <?= ($currentFile === 'customers.php' || $currentFile === 'customer_create.php') ? 'active' : '' ?>">
            <i class="fa-solid fa-user-tag"></i><span class="nav-text">Customers</span>
        </a>
        <a href="invoices.php" class="nav-item <?= ($currentFile === 'invoices.php' || $currentFile === 'invoice_create.php') ? 'active' : '' ?>">
            <i class="fa-solid fa-file-invoice-dollar"></i><span class="nav-text">Sale Invoices</span>
        </a>
        <a href="vendors.php" class="nav-item <?= ($currentFile === 'vendors.php' || $currentFile === 'vendor_create.php') ? 'active' : '' ?>">
            <i class="fa-solid fa-truck-field"></i><span class="nav-text">Vendors</span>
        </a>
        <a href="purchases.php" class="nav-item <?= ($currentFile === 'purchases.php' || $currentFile === 'purchase_create.php') ? 'active' : '' ?>">
            <i class="fa-solid fa-receipt"></i><span class="nav-text">Purchase Bills</span>
        </a>
        <a href="expenses.php" class="nav-item <?= $currentFile === 'expenses.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-wallet"></i><span class="nav-text">Expenses</span>
        </a>
        <a href="products.php" class="nav-item <?= ($currentFile === 'products.php' || $currentFile === 'product_create.php') ? 'active' : '' ?>">
            <i class="fa-solid fa-boxes-stacked"></i><span class="nav-text">Products/Service</span>
        </a>

        <div class="sidebar-section"><?= htmlspecialchars(strtoupper($moduleConfig['hr_operations']['section_label'])) ?></div>
        <a href="attendance.php" class="nav-item <?= ($currentFile === 'attendance.php' || $currentFile === 'attendance_tenant.php') ? 'active' : '' ?>">
            <i class="fa-regular fa-calendar-check"></i><span class="nav-text">Attendance</span>
        </a>
        <a href="attendance_report.php" class="nav-item <?= $currentFile === 'attendance_report.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-chart-line"></i><span class="nav-text">Report</span>
        </a>
        <a href="manage_requests.php" class="nav-item <?= $currentFile === 'manage_requests.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-clipboard-check"></i><span class="nav-text">Approvals</span>
        </a>

        <div class="sidebar-section">SETTINGS</div>
        <a href="profile.php" class="nav-item <?= $currentFile === 'profile.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-briefcase"></i><span class="nav-text">Business Profile</span>
        </a>
        <a href="invoice_settings.php" class="nav-item <?= $currentFile === 'invoice_settings.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-print"></i><span class="nav-text">Invoice Settings</span>
        </a>
    </div>
</aside>

<style>
.sidebar-section {
    padding: 20px 24px 8px;
    font-size: 11px;
    font-weight: 700;
    color: var(--text-muted);
    letter-spacing: 1.2px;
    text-transform: uppercase;
}
.sidebar-collapsed .sidebar-section {
    display: none;
}
</style>
