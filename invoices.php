<?php
require_once 'auth_check.php';
require_once 'includes/commerce.php';
require_once 'includes/brand.php';

$context = commerce_get_tenant_context();
$conn = $context['conn'];
$prefix = $context['prefix'];
commerce_ensure_tables($conn, $prefix);

$products = commerce_fetch_products($conn, $prefix);
$activeProducts = array_values(array_filter($products, static fn($product) => $product['status'] === 'active'));
$invoices = commerce_fetch_invoices($conn, $prefix);
$stats = commerce_fetch_invoice_stats($conn, $prefix);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(brand_page_title('Invoice List')) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link rel="shortcut icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <style>
        .module-hero {
            background: linear-gradient(135deg, #2d1b69 0%, #5b3cc4 52%, #7b5ef0 100%);
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

        .stat-paid { color: #a78bfa; }
        .stat-unpaid { color: #f59e0b; }

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

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 999px;
            padding: 7px 12px;
            font-size: 12px;
            font-weight: 700;
        }

        .status-draft {
            background: rgba(59, 130, 246, .12);
            color: #2563eb;
        }

        .status-paid {
            background: rgba(123, 94, 240, .12);
            color: #7b5ef0;
        }

        .status-sent {
            background: rgba(245, 158, 11, .12);
            color: #d97706;
        }

        .status-cancelled {
            background: rgba(239, 68, 68, .12);
            color: #dc2626;
        }

        .table-empty {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-muted);
        }

        .actions-cell {
            width: 72px;
            text-align: right;
        }

        .row-menu-wrap {
            position: relative;
            display: inline-block;
        }

        .menu-trigger {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text-main);
            cursor: pointer;
        }

        .row-menu {
            display: none;
            position: absolute;
            right: 0;
            top: calc(100% + 8px);
            min-width: 170px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: var(--shadow-md);
            padding: 8px;
            z-index: 20;
        }

        .row-menu.is-open {
            display: block;
        }

        .row-menu a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 10px;
            color: var(--text-main);
            text-decoration: none;
        }

        .row-menu a:hover {
            background: #eff6ff;
            color: #1d4ed8;
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
        <?php include 'includes/sidebar.php'; ?>
        <main class="main-content">
            <header class="topbar">
                <div class="flex items-center">
                    <button class="btn-icon" onclick="toggleMobileSidebar()" style="margin-right:20px;display:none;"
                        id="mobileToggle"><i class="fa-solid fa-bars"></i></button>
                    <div class="breadcrumb">Home / Invoices<span class="current">Invoice List</span></div>
                </div>
                <div class="topbar-right">
                    <a href="invoice_create.php" class="btn-primary" style="width:auto;padding:13px 18px;"><i
                            class="fa-solid fa-plus"></i> Create Invoice</a>
                    <div class="profile-pill">
                        <img src="/images/admin.jpg"
                            onerror="this.src='https://ui-avatars.com/api/?name=Admin&background=7b5ef0&color=fff'"
                            alt="Admin">
                        <span class="name"><?= htmlspecialchars($_SESSION["username"]) ?></span>
                    </div>
                </div>
            </header>
            <div class="content-scroll">
                <section class="module-hero">
                    <div>
                        <h1 class="hero-title">Sales Overview</h1>
                        <p class="hero-copy">Manage your sales, track payments, and view your business performance at a glance.</p>
                    </div>
                    <div class="hero-meta">
                        <div class="hero-stat">
                            <span>Total Sales</span>
                            <strong>₹<?= number_format($stats['total'], 2) ?></strong>
                        </div>
                        <div class="hero-stat">
                            <span>Paid</span>
                            <strong class="stat-paid">₹<?= number_format($stats['paid'], 2) ?></strong>
                        </div>
                        <div class="hero-stat">
                            <span>Total Unpaid</span>
                            <strong class="stat-unpaid">₹<?= number_format($stats['unpaid'], 2) ?></strong>
                        </div>
                    </div>
                </section>

                <section class="module-panel">
                    <div class="panel-head">
                        <div>
                            <div class="panel-title">Invoices</div>
                            <div class="panel-copy">All sale invoices</div>
                        </div>
                        <div class="panel-actions">
                            <input class="form-control" id="invoiceSearch" type="text" placeholder="Search by invoice or customer">
                            <a href="api/export.php?type=invoices" class="btn-secondary" style="padding:13px 22px;white-space:nowrap;"><i class="fa-solid fa-file-export"></i> Export CSV</a>
                            <a href="invoice_create.php" class="btn-primary" style="width:auto;padding:13px 24px;white-space:nowrap;"><i class="fa-solid fa-plus"></i> New Invoice</a>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="crm-table">
                            <thead>
                                <tr>
                                    <th>Invoice</th>
                                    <th>Customer</th>
                                    <th>Items</th>
                                    <th>Total</th>
                                    <th>Paid</th>
                                    <th>Balance</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th class="actions-cell">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="invoiceTableBody">
                                <?php if (empty($invoices)): ?>
                                    <tr>
                                        <td colspan="7" class="table-empty">No invoices yet. Use Create Invoice to add the
                                            first one.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($invoices as $invoice): ?>
                                        <tr>
                                            <td>
                                                <div class="text-bold"><?= htmlspecialchars($invoice['invoice_number']) ?></div>
                                            </td>
                                            <td><?= htmlspecialchars($invoice['customer_name']) ?></td>
                                            <td><?= (int) $invoice['item_count'] ?></td>
                                            <td><?= number_format((float) $invoice['grand_total'], 2) ?></td>
                                            <td class="stat-paid"><?= number_format((float) ($invoice['paid_amount'] ?? 0), 2) ?></td>
                                            <td class="stat-unpaid"><?= number_format((float) ($invoice['grand_total'] - ($invoice['paid_amount'] ?? 0)), 2) ?></td>
                                            <td><span
                                                    class="status-badge status-<?= htmlspecialchars($invoice['status']) ?>"><?= htmlspecialchars(ucfirst($invoice['status'])) ?></span>
                                            </td>
                                            <td><?= htmlspecialchars($invoice['invoice_date']) ?></td>
                                            <td class="actions-cell">
                                                <div class="row-menu-wrap">
                                                    <button class="menu-trigger" type="button" aria-label="Invoice actions"
                                                        aria-expanded="false">
                                                        <i class="fa-solid fa-ellipsis-vertical"></i>
                                                    </button>
                                                    <div class="row-menu">
                                                        <a href="invoice_print.php?id=<?= (int) $invoice['id'] ?>"
                                                            target="_blank" rel="noopener">
                                                            <i class="fa-solid fa-print"></i> Print Invoice
                                                        </a>
                                                    </div>
                                                </div>
                                            </td>
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

        document.querySelectorAll('.row-menu-wrap').forEach((menuWrap) => {
            const trigger = menuWrap.querySelector('.menu-trigger');
            const menu = menuWrap.querySelector('.row-menu');

            trigger.addEventListener('click', (event) => {
                event.stopPropagation();
                const isOpen = menu.classList.contains('is-open');
                document.querySelectorAll('.row-menu.is-open').forEach((openMenu) => {
                    openMenu.classList.remove('is-open');
                    openMenu.parentElement.querySelector('.menu-trigger').setAttribute('aria-expanded', 'false');
                });

                if (!isOpen) {
                    menu.classList.add('is-open');
                    trigger.setAttribute('aria-expanded', 'true');
                }
            });
        });

        document.addEventListener('click', () => {
            document.querySelectorAll('.row-menu.is-open').forEach((openMenu) => {
                openMenu.classList.remove('is-open');
                openMenu.parentElement.querySelector('.menu-trigger').setAttribute('aria-expanded', 'false');
            });
        });

        document.getElementById('invoiceSearch').addEventListener('input', (event) => {
            const search = event.target.value.trim().toLowerCase();
            document.querySelectorAll('#invoiceTableBody tr').forEach((row) => {
                if (row.querySelector('.table-empty')) {
                    return;
                }
                row.style.display = row.textContent.toLowerCase().includes(search) ? '' : 'none';
            });
        });
    </script>
</body>

</html>
