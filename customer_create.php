<?php
// customer_create.php - Add Customer Page
require_once 'auth_check.php';
require_once 'includes/commerce.php';
require_once 'includes/brand.php';

$context = commerce_get_tenant_context();
$conn = $context['conn'];
$prefix = $context['prefix'];
commerce_ensure_tables($conn, $prefix);

$returnTo = trim((string) ($_GET['return_to'] ?? ''));
$safeReturnTo = in_array($returnTo, ['invoices.php', 'invoice_create.php'], true) ? $returnTo : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(brand_page_title('Add Customer')) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link rel="shortcut icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <style>
        .breadcrumb .current { color: #2563eb; }
        .module-panel { background: var(--surface); border-radius: 22px; box-shadow: var(--shadow-md); padding: 24px; }
        .panel-title { font-size: 24px; font-weight: 700; margin-bottom: 6px; }
        .panel-copy { color: var(--text-muted); margin-bottom: 22px; line-height: 1.5; }
        .inline-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .form-actions { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .btn-secondary { border: 1px solid var(--border); background: var(--surface); color: var(--text-main); border-radius: 12px; padding: 13px 16px; font-weight: 600; cursor: pointer; }
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
                    <div class="breadcrumb">Home / Customers<span class="current">Add Customer</span></div>
                </div>
                <div class="topbar-right">
                    <a href="customers.php" class="btn-secondary"><i class="fa-solid fa-table-list"></i> Customer List</a>
                </div>
            </header>
            <div class="content-scroll">
                <section class="module-panel">
                    <div class="panel-title">Add Customer</div>
                    <div class="panel-copy">Create a new customer profile to use in invoices and track interactions.</div>
                    <form id="customerForm">
                        <div class="form-group">
                            <label class="form-label">Customer Name</label>
                            <input class="form-control" type="text" name="name" placeholder="Acme Corporation" required>
                        </div>
                        <div class="inline-grid">
                            <div class="form-group">
                                <label class="form-label">Customer Code</label>
                                <input class="form-control" type="text" name="customer_code" placeholder="CUST-001">
                            </div>
                            <div class="form-group">
                                <label class="form-label">GST Number</label>
                                <input class="form-control" type="text" name="gst_number" placeholder="Optional">
                            </div>
                        </div>
                        <div class="inline-grid">
                            <div class="form-group">
                                <label class="form-label">Phone Number</label>
                                <input class="form-control" type="text" name="phone">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Email Address</label>
                                <input class="form-control" type="email" name="email">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Billing Address</label>
                            <textarea class="form-control" name="billing_address" rows="3" placeholder="Full address for invoicing"></textarea>
                        </div>
                        <div class="form-actions" style="margin-top:24px;">
                            <button class="btn-primary" type="submit">Save Customer</button>
                            <a href="customers.php" class="btn-secondary">Cancel</a>
                            <?php if ($safeReturnTo !== ''): ?>
                                <a href="<?= htmlspecialchars($safeReturnTo) ?>?customer_created=1" class="btn-secondary">Back To Invoice</a>
                            <?php endif; ?>
                        </div>
                        <div class="text-muted text-sm" id="customerStatusText" style="margin-top:16px;">Customer information is ready to be saved.</div>
                    </form>
                </section>
            </div>
        </main>
    </div>
    <script>
        const returnTo = <?= json_encode($safeReturnTo) ?>;

        document.getElementById('customerForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = e.target.querySelector('button[type="submit"]');
            const status = document.getElementById('customerStatusText');
            btn.disabled = true;
            status.textContent = 'Saving customer...';

            const formData = new FormData(e.target);
            const data = { action: 'create' };
            formData.forEach((v, k) => data[k] = v);

            try {
                const res = await fetch('/api/customers.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                const payload = await res.json();
                if (!payload.success) throw new Error(payload.message || 'Error saving customer');

                if (returnTo) {
                    window.location.href = `${returnTo}?customer_created=1`;
                } else {
                    window.location.href = 'customers.php';
                }
            } catch (err) {
                status.textContent = err.message;
                alert(err.message);
                btn.disabled = false;
            }
        });

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
    </script>
</body>
</html>
