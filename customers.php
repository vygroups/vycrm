<?php
// customers.php - Customer List Page
require_once 'auth_check.php';
require_once 'includes/commerce.php';

$v = time();
$companyLogo = "/images/logo.png";
$companyName = "Vy CRM";

try {
    $brandDb = Database::getMasterConn();
    $brandPrefix = Database::getMasterPrefix();
    $brandStmt = $brandDb->prepare("SELECT * FROM {$brandPrefix}companies WHERE slug = ?");
    $brandStmt->execute([$_SESSION['tenant_slug']]);
    $company = $brandStmt->fetch(PDO::FETCH_ASSOC);
    if ($company && $company['logo']) {
        $companyLogo = '/' . $company['logo'];
        $companyName = htmlspecialchars($company['name']);
    }
} catch (Throwable $e) {}

$context = commerce_get_tenant_context();
$conn = $context['conn'];
$prefix = $context['prefix'];
commerce_ensure_tables($conn, $prefix);

$customers = commerce_fetch_customers($conn, $prefix);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vy CRM - Customer List</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <style>
        .module-hero {
            background: linear-gradient(135deg, #1e293b 0%, #334155 52%, #475569 100%);
            color: #fff;
            border-radius: 24px;
            padding: 28px;
            box-shadow: var(--shadow-lg);
            margin-bottom: 28px;
            display: grid;
            grid-template-columns: 1.4fr 1fr;
            gap: 24px;
        }
        .hero-title { font-size: 30px; margin-bottom: 10px; color: #fff; }
        .hero-copy { color: rgba(255, 255, 255, .82); max-width: 620px; line-height: 1.6; }
        .module-panel { background: var(--surface); border-radius: 22px; box-shadow: var(--shadow-md); padding: 24px; }
        .panel-head { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 20px; }
        .panel-actions { display: flex; align-items: center; gap: 12px; margin-left: auto; }
        .panel-title { font-size: 24px; font-weight: 700; margin-bottom: 6px; }
        .panel-copy { color: var(--text-muted); line-height: 1.5; }
        .btn-secondary { border: 1px solid var(--border); background: var(--surface); color: var(--text-main); border-radius: 12px; padding: 13px 16px; font-weight: 600; cursor: pointer; }
        .panel-actions .form-control { width: 260px; }
        .table-empty { text-align: center; padding: 40px 20px; color: var(--text-muted); }
        @media (max-width: 1180px) {
            .panel-head, .panel-actions { flex-direction: column; align-items: flex-start; }
            .panel-actions .form-control { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="app-wrapper">
        <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleMobileSidebar()" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:90;"></div>
        <?php include 'includes/sidebar.php'; ?>
        <main class="main-content">
            <header class="topbar">
                <div class="flex items-center">
                    <button class="btn-icon" onclick="toggleMobileSidebar()" style="margin-right:20px;display:none;" id="mobileToggle"><i class="fa-solid fa-bars"></i></button>
                    <div class="breadcrumb">Home / Customers<span class="current">Customer List</span></div>
                </div>
                <div class="topbar-right">
                    <a href="customer_create.php" class="btn-primary" style="width:auto;padding:13px 18px;"><i class="fa-solid fa-plus"></i> Add Customer</a>
                    <div class="profile-pill">
                        <img src="/images/admin.jpg" onerror="this.src='https://ui-avatars.com/api/?name=Admin&background=7b5ef0&color=fff'" alt="Admin">
                        <span class="name"><?= htmlspecialchars($_SESSION["username"]) ?></span>
                    </div>
                </div>
            </header>
            <div class="content-scroll">
                <section class="module-panel">
                    <div class="panel-head">
                        <div>
                            <div class="panel-title">Customers</div>
                            <div class="panel-copy">Manage your customer database for integrated invoicing and communications.</div>
                        </div>
                        <div class="panel-actions">
                            <input class="form-control" id="customerSearch" type="text" placeholder="Search by name, email or phone">
                            <a href="customer_create.php" class="btn-secondary"><i class="fa-solid fa-plus"></i> New Customer</a>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="crm-table">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Contact</th>
                                    <th>Address</th>
                                    <th>GST</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody id="customerTableBody">
                                <?php if (empty($customers)): ?>
                                    <tr>
                                        <td colspan="5" class="table-empty">No customers yet. Add your first customer to get started.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($customers as $customer): ?>
                                        <tr>
                                            <td>
                                                <div class="text-bold"><?= htmlspecialchars($customer['name']) ?></div>
                                                <div class="text-muted text-sm"><?= htmlspecialchars((string)($customer['customer_code'] ?? '-')) ?></div>
                                            </td>
                                            <td>
                                                <div><?= htmlspecialchars((string)($customer['phone'] ?? '-')) ?></div>
                                                <div class="text-muted text-sm"><?= htmlspecialchars((string)($customer['email'] ?? '-')) ?></div>
                                            </td>
                                            <td>
                                                <div class="text-muted text-sm" style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                    <?= htmlspecialchars((string)($customer['billing_address'] ?? '-')) ?>
                                                </div>
                                            </td>
                                            <td><?= htmlspecialchars((string)($customer['gst_number'] ?? '-')) ?></td>
                                            <td><?= date('Y-m-d', strtotime($customer['created_at'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </main>
    </div>
    <script>
        const allCustomers = <?= json_encode($customers) ?>;

        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const icon = document.getElementById('toggleIcon');
            sidebar.classList.toggle('sidebar-collapsed');
            icon.classList.toggle('fa-chevron-left', !sidebar.classList.contains('sidebar-collapsed'));
            icon.classList.toggle('fa-chevron-right', sidebar.classList.contains('sidebar-collapsed'));
        }

        function toggleMobileSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.toggle('mobile-open');
            overlay.style.display = sidebar.classList.contains('mobile-open') ? 'block' : 'none';
        }

        function escapeHtml(value) {
            return String(value || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        function renderCustomers(data) {
            const tbody = document.getElementById('customerTableBody');
            if (!data.length) {
                tbody.innerHTML = '<tr><td colspan="5" class="table-empty">No matching customers found.</td></tr>';
                return;
            }
            tbody.innerHTML = data.map(c => `
                <tr>
                    <td>
                        <div class="text-bold">${escapeHtml(c.name)}</div>
                        <div class="text-muted text-sm">${escapeHtml(c.customer_code || '-')}</div>
                    </td>
                    <td>
                        <div>${escapeHtml(c.phone || '-')}</div>
                        <div class="text-muted text-sm">${escapeHtml(c.email || '-')}</div>
                    </td>
                    <td>
                        <div class="text-muted text-sm" style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            ${escapeHtml(c.billing_address || '-')}
                        </div>
                    </td>
                    <td>${escapeHtml(c.gst_number || '-')}</td>
                    <td>${(c.created_at || '').slice(0, 10)}</td>
                </tr>
            `).join('');
        }

        document.getElementById('customerSearch').addEventListener('input', (e) => {
            const s = e.target.value.toLowerCase();
            const filtered = allCustomers.filter(c => 
                c.name.toLowerCase().includes(s) || 
                (c.email && c.email.toLowerCase().includes(s)) || 
                (c.phone && c.phone.toLowerCase().includes(s)) ||
                (c.customer_code && c.customer_code.toLowerCase().includes(s))
            );
            renderCustomers(filtered);
        });
    </script>
</body>
</html>
