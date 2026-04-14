<?php
// includes/sidebar.php - Shared Sidebar Component
$currentFile = basename($_SERVER['PHP_SELF']);
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-head">
        <a href="dashboard.php"><img src="<?= $companyLogo ?>?v=<?= $v ?>" alt="<?= $companyName ?>" style="max-height:50px;"></a>
        <div class="sidebar-toggle hidden-mobile" onclick="toggleSidebar()">
            <i class="fa-solid fa-chevron-left" id="toggleIcon"></i>
        </div>
    </div>
    <div class="sidebar-nav">
        <a href="dashboard.php" class="nav-item <?= $currentFile === 'dashboard.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-chart-pie"></i><span class="nav-text">Dashboard</span>
        </a>
        <a href="attendance.php" class="nav-item <?= ($currentFile === 'attendance.php' || $currentFile === 'attendance_tenant.php') ? 'active' : '' ?>">
            <i class="fa-regular fa-clock"></i><span class="nav-text">Attendance</span>
        </a>
        <a href="attendance_report.php" class="nav-item <?= $currentFile === 'attendance_report.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-chart-line"></i><span class="nav-text">Attendance Report</span>
        </a>
        <a href="customers.php" class="nav-item <?= ($currentFile === 'customers.php' || $currentFile === 'customer_create.php') ? 'active' : '' ?>">
            <i class="fa-solid fa-users-gear"></i><span class="nav-text">Customers</span>
        </a>
        <a href="invoices.php" class="nav-item <?= ($currentFile === 'invoices.php' || $currentFile === 'invoice_create.php') ? 'active' : '' ?>">
            <i class="fa-solid fa-file-invoice"></i><span class="nav-text">Invoices</span>
        </a>
        <a href="products.php" class="nav-item <?= ($currentFile === 'products.php' || $currentFile === 'product_create.php') ? 'active' : '' ?>">
            <i class="fa-solid fa-boxes-stacked"></i><span class="nav-text">Products</span>
        </a>
        <a href="manage_requests.php" class="nav-item <?= $currentFile === 'manage_requests.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-clipboard-check"></i><span class="nav-text">Approvals</span>
        </a>
    </div>
</aside>
