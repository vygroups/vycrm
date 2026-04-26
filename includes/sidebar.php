<?php
// includes/sidebar.php - Shared Sidebar Component
require_once __DIR__ . '/navigation_config.php';
require_once __DIR__ . '/dynamic_modules.php';
$currentFile = basename($_SERVER['PHP_SELF']);
$moduleConfig = vycrm_module_config();

// Load dynamic modules for sidebar
$_sidebarDynModules = [];
try {
    $_sidebarConn = Database::getTenantConn($_SESSION['tenant_db']);
    if ($_sidebarConn) {
        dm_ensure_tables($_sidebarConn, $_SESSION['tenant_prefix']);
        $_sidebarDynModules = dm_fetch_active_modules($_sidebarConn, $_SESSION['tenant_prefix']);
    }
} catch (Throwable $e) {
}
$_currentModuleId = (int) ($_GET['module'] ?? 0);
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-head">
        <a href="dashboard.php"><img src="<?= $companyLogo ?>?v=<?= $v ?>" alt="<?= $companyName ?>"
                style="max-height:50px;"></a>
        <div class="sidebar-toggle hidden-mobile" onclick="toggleSidebar()">
            <i class="fa-solid fa-chevron-left" id="toggleIcon"></i>
        </div>
    </div>
    <div class="sidebar-nav">
        <!-- Dashboard Section -->
        <a href="dashboard.php" class="nav-item <?= $currentFile === 'dashboard.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-chart-pie"></i><span class="nav-text">Dashboard</span>
        </a>

        <!-- Dynamic Modules -->
        <?php if (!empty($_sidebarDynModules)): ?>
            <div class="sidebar-section" onclick="toggleSidebarGroup('group-dyn')">
                <span>DYNAMIC MODULES</span> <i class="fa-solid fa-chevron-down toggle-caret"></i>
            </div>
            <div class="sidebar-group" id="group-dyn">
                <?php foreach ($_sidebarDynModules as $dm): ?>
                    <a href="module_view.php?module=<?= $dm['id'] ?>"
                        class="nav-item <?= (in_array($currentFile, ['module_view.php', 'module_record.php']) && $_currentModuleId == $dm['id']) ? 'active' : '' ?>">
                        <i class="<?= htmlspecialchars($dm['icon']) ?>"></i><span
                            class="nav-text"><?= htmlspecialchars($dm['name']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- HR / Attendance -->
        <div class="sidebar-section" onclick="toggleSidebarGroup('group-hr')">
            <span><?= htmlspecialchars(strtoupper($moduleConfig['hr_operations']['section_label'] ?? 'ATTENDANCE')) ?></span>
            <i class="fa-solid fa-chevron-down toggle-caret"></i>
        </div>
        <div class="sidebar-group" id="group-hr">
            <a href="attendance.php"
                class="nav-item <?= ($currentFile === 'attendance.php' || $currentFile === 'attendance_tenant.php') ? 'active' : '' ?>">
                <i class="fa-regular fa-calendar-check"></i><span class="nav-text">Attendance</span>
            </a>
            <a href="attendance_report.php"
                class="nav-item <?= $currentFile === 'attendance_report.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-chart-line"></i><span class="nav-text">Report</span>
            </a>
            <a href="manage_requests.php"
                class="nav-item <?= $currentFile === 'manage_requests.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-clipboard-check"></i><span class="nav-text">Approvals</span>
            </a>
        </div>

        <!-- Billing / Invoice -->
        <div class="sidebar-section" onclick="toggleSidebarGroup('group-bill')">
            <span><?= htmlspecialchars(strtoupper($moduleConfig['billing']['section_label'] ?? 'BILLING')) ?></span> <i
                class="fa-solid fa-chevron-down toggle-caret"></i>
        </div>
        <div class="sidebar-group" id="group-bill">
            <a href="customers.php"
                class="nav-item <?= ($currentFile === 'customers.php' || $currentFile === 'customer_create.php') ? 'active' : '' ?>">
                <i class="fa-solid fa-user-tag"></i><span class="nav-text">Customers</span>
            </a>
            <a href="invoices.php"
                class="nav-item <?= ($currentFile === 'invoices.php' || $currentFile === 'invoice_create.php') ? 'active' : '' ?>">
                <i class="fa-solid fa-file-invoice-dollar"></i><span class="nav-text">Sale Invoices</span>
            </a>
            <a href="vendors.php"
                class="nav-item <?= ($currentFile === 'vendors.php' || $currentFile === 'vendor_create.php') ? 'active' : '' ?>">
                <i class="fa-solid fa-truck-field"></i><span class="nav-text">Vendors</span>
            </a>
            <a href="purchases.php"
                class="nav-item <?= ($currentFile === 'purchases.php' || $currentFile === 'purchase_create.php') ? 'active' : '' ?>">
                <i class="fa-solid fa-receipt"></i><span class="nav-text">Purchase Bills</span>
            </a>
            <a href="expenses.php" class="nav-item <?= $currentFile === 'expenses.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-wallet"></i><span class="nav-text">Expenses</span>
            </a>
            <a href="products.php"
                class="nav-item <?= ($currentFile === 'products.php' || $currentFile === 'product_create.php') ? 'active' : '' ?>">
                <i class="fa-solid fa-boxes-stacked"></i><span class="nav-text">Products/Service</span>
            </a>
        </div>

        <!-- Settings -->
        <div class="sidebar-section" onclick="toggleSidebarGroup('group-set')">
            <span>SETTINGS</span> <i class="fa-solid fa-chevron-down toggle-caret"></i>
        </div>
        <div class="sidebar-group" id="group-set">
            <a href="profile.php" class="nav-item <?= $currentFile === 'profile.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-briefcase"></i><span class="nav-text">Business Profile</span>
            </a>
            <a href="invoice_settings.php"
                class="nav-item <?= $currentFile === 'invoice_settings.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-print"></i><span class="nav-text">Invoice Settings</span>
            </a>
            <a href="module_manager.php" class="nav-item <?= $currentFile === 'module_manager.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-cubes"></i><span class="nav-text">Module Manager</span>
            </a>
        </div>
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
        cursor: pointer;
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: color 0.2s;
        user-select: none;
    }

    .sidebar-section:hover {
        color: var(--primary);
    }

    .sidebar-section .toggle-caret {
        transition: transform 0.3s;
        font-size: 10px;
    }

    .sidebar-section.collapsed .toggle-caret {
        transform: rotate(-90deg);
    }

    .sidebar-group {
        max-height: 1000px;
        overflow: hidden;
        transition: max-height 0.3s ease-in-out;
    }

    .sidebar-group.collapsed {
        max-height: 0;
    }

    .sidebar-collapsed .sidebar-section {
        display: none;
    }

    .sidebar-collapsed .sidebar-group {
        max-height: none;
        overflow: visible;
    }
</style>

<script>
    // Restore state immediately to prevent visual jumping
    document.querySelectorAll('.sidebar-group').forEach(group => {
        const id = group.id;
        const state = localStorage.getItem('sidebar_state_' + id);
        if (state === 'collapsed') {
            group.classList.add('collapsed');
            if (group.previousElementSibling) group.previousElementSibling.classList.add('collapsed');
        } else if (state === 'expanded') {
            group.classList.remove('collapsed');
            if (group.previousElementSibling) group.previousElementSibling.classList.remove('collapsed');
        }
    });

    function toggleSidebarGroup(id) {
        // Only allow collapsing if sidebar is not minimized
        if (document.getElementById('sidebar').classList.contains('sidebar-collapsed')) return;
        const group = document.getElementById(id);
        if (!group) return;
        const section = group.previousElementSibling;
        const isCollapsed = group.classList.toggle('collapsed');
        if (section) section.classList.toggle('collapsed');

        // Save to localStorage
        localStorage.setItem('sidebar_state_' + id, isCollapsed ? 'collapsed' : 'expanded');
    }

    // Global Date & Time Formatter based on User Preferences
    window.vyUserPrefs = {
        timeFormat: "<?= $_SESSION['time_format'] ?? '12h' ?>",
        dateFormat: "<?= $_SESSION['date_format'] ?? 'd M, Y' ?>"
    };
    
    function formatVyDate(dateStr) {
        if (!dateStr) return '-';
        const d = new Date(dateStr);
        if (isNaN(d)) return dateStr;
        const day = String(d.getDate()).padStart(2, '0');
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const year = d.getFullYear();
        const shortMonths = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        switch (window.vyUserPrefs.dateFormat) {
            case 'Y-m-d': return `${year}-${month}-${day}`;
            case 'd/m/Y': return `${day}/${month}/${year}`;
            case 'm/d/Y': return `${month}/${day}/${year}`;
            default: return `${day} ${shortMonths[d.getMonth()]}, ${year}`;
        }
    }
    
    function formatVyTime(dateStr) {
        if (!dateStr) return '-';
        const d = new Date(dateStr);
        if (isNaN(d)) return dateStr;
        const is12h = window.vyUserPrefs.timeFormat === '12h';
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: is12h });
    }
</script>