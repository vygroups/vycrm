<?php
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
} catch (Throwable $e) {
}

$context = commerce_get_tenant_context();
$conn = $context['conn'];
$prefix = $context['prefix'];
commerce_ensure_tables($conn, $prefix);

$products = commerce_fetch_products($conn, $prefix);
$activeProducts = array_values(array_filter($products, static fn($product) => $product['status'] === 'active'));
$inactiveProducts = count($products) - count($activeProducts);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vy CRM - Product List</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <style>
        .module-hero {
            background: linear-gradient(135deg, #12315f 0%, #2854a3 52%, #4f7ff0 100%);
            color: #fff;
            border-radius: 24px;
            padding: 28px;
            box-shadow: var(--shadow-lg);
            margin-bottom: 28px;
            display: grid;
            grid-template-columns: 1.4fr 1fr;
            gap: 24px;
        }

        .hero-title {
            font-size: 30px;
            margin-bottom: 10px;
            color: #fff;
        }

        .hero-copy {
            color: rgba(255, 255, 255, .82);
            max-width: 620px;
            line-height: 1.6;
        }

        .hero-meta {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .hero-stat {
            background: rgba(255, 255, 255, .12);
            border: 1px solid rgba(255, 255, 255, .14);
            border-radius: 18px;
            padding: 18px;
        }

        .hero-stat span {
            display: block;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: .75;
        }

        .hero-stat strong {
            display: block;
            font-size: 26px;
            margin-top: 8px;
        }

        .module-panel {
            background: var(--surface);
            border-radius: 22px;
            box-shadow: var(--shadow-md);
            padding: 24px;
        }

        .panel-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
        }

        .panel-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-left: auto;
        }

        .panel-title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .panel-copy {
            color: var(--text-muted);
            line-height: 1.5;
        }

        .btn-secondary {
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text-main);
            border-radius: 12px;
            padding: 13px 16px;
            font-weight: 600;
            cursor: pointer;
        }

        .panel-actions .form-control {
            width: 260px;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 999px;
            padding: 7px 12px;
            font-size: 12px;
            font-weight: 700;
        }

        .status-active {
            background: rgba(16, 185, 129, .12);
            color: #059669;
        }

        .status-inactive {
            background: rgba(239, 68, 68, .12);
            color: #dc2626;
        }

        .table-empty {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-muted);
        }

        @media (max-width: 1180px) {
            .module-hero {
                grid-template-columns: 1fr;
            }

            .panel-head,
            .panel-actions {
                flex-direction: column;
                align-items: flex-start;
            }

            .panel-actions .form-control {
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <div class="app-wrapper">
        <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleMobileSidebar()"
            style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:90;">
        </div>
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-head">
                <a href="dashboard.php"><img src="<?= $companyLogo ?>?v=<?= $v ?>" alt="<?= $companyName ?>"
                        style="max-height:50px;"></a>
                <div class="sidebar-toggle hidden-mobile" onclick="toggleSidebar()">
                    <i class="fa-solid fa-chevron-left" id="toggleIcon"></i>
                </div>
            </div>
            <div class="sidebar-nav">
                <a href="dashboard.php" class="nav-item"><i class="fa-solid fa-chart-pie"></i><span
                        class="nav-text">Dashboard</span></a>
                <a href="attendance.php" class="nav-item"><i class="fa-regular fa-clock"></i><span
                        class="nav-text">Attendance</span></a>
                <a href="#" class="nav-item"><i class="fa-solid fa-ticket"></i><span class="nav-text">Tickets</span></a>
                <a href="invoices.php" class="nav-item"><i class="fa-solid fa-file-invoice"></i><span
                        class="nav-text">Invoices</span></a>
                <a href="products.php" class="nav-item active"><i class="fa-solid fa-boxes-stacked"></i><span
                        class="nav-text">Products</span></a>
                <a href="manage_requests.php" class="nav-item"><i class="fa-solid fa-clipboard-check"></i><span
                        class="nav-text">Approvals</span></a>
            </div>
        </aside>
        <main class="main-content">
            <header class="topbar">
                <div class="flex items-center">
                    <button class="btn-icon" onclick="toggleMobileSidebar()" style="margin-right:20px;display:none;"
                        id="mobileToggle"><i class="fa-solid fa-bars"></i></button>
                    <div class="breadcrumb">Home / Products<span class="current">Product List</span></div>
                </div>
                <div class="topbar-right">
                    <a href="product_create.php" class="btn-primary" style="width:auto;padding:13px 18px;"><i
                            class="fa-solid fa-plus"></i> Add Product</a>
                    <div class="profile-pill">
                        <img src="/images/admin.jpg"
                            onerror="this.src='https://ui-avatars.com/api/?name=Admin&background=7b5ef0&color=fff'"
                            alt="Admin">
                        <span class="name"><?= htmlspecialchars($_SESSION["username"]) ?></span>
                    </div>
                </div>
            </header>
            <div class="content-scroll">

                <section class="module-panel">
                    <div class="panel-head">
                        <div>
                            <div class="panel-title">Products</div>
                            <div class="panel-copy">Stored through the reusable product API and shown as a dedicated
                                list page.</div>
                        </div>
                        <div class="panel-actions">
                            <input class="form-control" id="productSearch" type="text"
                                placeholder="Search by name or code">
                            <a href="product_create.php" class="btn-secondary"><i class="fa-solid fa-plus"></i> New
                                Product</a>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="crm-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Code</th>
                                    <th>Price</th>
                                    <th>Tax</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody id="productTableBody">
                                <?php if (empty($products)): ?>
                                    <tr>
                                        <td colspan="6" class="table-empty">No products yet. Add your first product to
                                            enable invoice selection.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($products as $product): ?>
                                        <tr>
                                            <td>
                                                <div class="text-bold"><?= htmlspecialchars($product['name']) ?></div>
                                                <div class="text-muted text-sm">
                                                    <?= htmlspecialchars((string) ($product['description'] ?? '')) ?>
                                                </div>
                                            </td>
                                            <td><?= htmlspecialchars((string) ($product['product_code'] ?? '-')) ?></td>
                                            <td><?= number_format((float) $product['unit_price'], 2) ?></td>
                                            <td><?= number_format((float) $product['tax_percent'], 2) ?>%</td>
                                            <td><span
                                                    class="status-pill <?= $product['status'] === 'active' ? 'status-active' : 'status-inactive' ?>"><?= htmlspecialchars(ucfirst($product['status'])) ?></span>
                                            </td>
                                            <td><?= date('Y-m-d', strtotime($product['created_at'])) ?></td>
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
        const allProducts = <?= json_encode(array_values(array_map(static function ($product) {
            return [
                'name' => (string) $product['name'],
                'description' => (string) ($product['description'] ?? ''),
                'product_code' => (string) ($product['product_code'] ?? ''),
                'unit_price' => (float) $product['unit_price'],
                'tax_percent' => (float) $product['tax_percent'],
                'status' => (string) $product['status'],
                'created_at' => (string) ($product['created_at'] ?? ''),
            ];
        }, $products)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const currency = (value) => Number(value || 0).toFixed(2);

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
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function capitalize(value) {
            if (!value) return '';
            return value.charAt(0).toUpperCase() + value.slice(1);
        }

        function renderProducts(products) {
            const tbody = document.getElementById('productTableBody');
            const totalEl = document.getElementById('totalProducts');
            const activeEl = document.getElementById('activeProducts');
            const inactiveEl = document.getElementById('inactiveProducts');

            if (totalEl) totalEl.textContent = products.length;
            if (activeEl) activeEl.textContent = products.filter((product) => product.status === 'active').length;
            if (inactiveEl) inactiveEl.textContent = products.filter((product) => product.status !== 'active').length;

            if (!products.length) {
                tbody.innerHTML = '<tr><td colspan="6" class="table-empty">No matching products found.</td></tr>';
                return;
            }

            tbody.innerHTML = products.map((product) => `
        <tr>
            <td>
                <div class="text-bold">${escapeHtml(product.name)}</div>
                <div class="text-muted text-sm">${escapeHtml(product.description || '')}</div>
            </td>
            <td>${escapeHtml(product.product_code || '-')}</td>
            <td>${currency(product.unit_price)}</td>
            <td>${currency(product.tax_percent)}%</td>
            <td><span class="status-pill ${product.status === 'active' ? 'status-active' : 'status-inactive'}">${escapeHtml(capitalize(product.status))}</span></td>
            <td>${escapeHtml((product.created_at || '').slice(0, 10))}</td>
        </tr>
    `).join('');
        }

        document.getElementById('productSearch').addEventListener('input', (event) => {
            const search = event.target.value.trim().toLowerCase();
            const filteredProducts = allProducts.filter((product) => {
                return [
                    product.name,
                    product.description,
                    product.product_code,
                    product.status
                ].join(' ').toLowerCase().includes(search);
            });
            renderProducts(filteredProducts);
        });
    </script>
</body>

</html>