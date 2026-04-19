<?php
// purchases.php - Purchase Bill Listing
require_once 'auth_check.php';
require_once 'includes/commerce.php';
require_once 'includes/brand.php';

$context = commerce_get_tenant_context();
$conn = $context['conn'];
$prefix = $context['prefix'];
commerce_ensure_tables($conn, $prefix);

// Fetch purchases
$stmt = $conn->query("
    SELECT p.*, COUNT(pi.id) as item_count 
    FROM {$prefix}purchases p 
    LEFT JOIN {$prefix}purchase_items pi ON pi.purchase_id = p.id
    GROUP BY p.id
    ORDER BY p.purchase_date DESC
");
$purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stats = commerce_fetch_purchase_stats($conn, $prefix);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(brand_page_title('Purchase Bills')) ?></title>
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
            grid-template-columns: repeat(2, 1fr);
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
            font-size: 24px;
            margin-top: 8px;
        }

        .stat-paid {
            color: #a78bfa;
        }

        .stat-unpaid {
            color: #fbbf24;
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

        .status-badge {
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 700;
        }

        .status-paid {
            background: rgba(123, 94, 240, .12);
            color: #7b5ef0;
        }

        .status-unpaid {
            background: rgba(239, 68, 68, .12);
            color: #dc2626;
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
                    <div class="breadcrumb">Purchases / <span class="current">Purchase Bills</span></div>
                </div>
                <div class="topbar-right">
                    <a href="purchase_create.php" class="btn-primary" style="width:auto;padding:13px 18px;"><i
                            class="fa-solid fa-plus"></i> Add Purchase</a>
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
                        <h1 class="hero-title">Purchase Outings</h1>
                        <p class="hero-copy">Track every rupee spent on inventory and procurement. Manage vendor bills
                            and pending payments.</p>
                    </div>
                    <div class="hero-meta">
                        <div class="hero-stat">
                            <span>Total Purchases</span>
                            <strong>₹<?= number_format($stats['total'], 2) ?></strong>
                        </div>
                        <div class="hero-stat">
                            <span>Total Paid</span>
                            <strong class="stat-paid">₹<?= number_format($stats['paid'], 2) ?></strong>
                        </div>
                        <div class="hero-stat">
                            <span>Unpaid Bills</span>
                            <strong class="stat-unpaid">₹<?= number_format($stats['unpaid'], 2) ?></strong>
                        </div>
                    </div>
                </section>

                <section class="module-panel">
                    <div class="panel-head">
                        <div>
                            <div class="panel-title">Recent Bills</div>
                            <div class="panel-copy">All purchase records</div>
                        </div>
                        <div class="panel-actions">
                            <input class="form-control" type="text" placeholder="Search bills or vendors...">
                            <a href="api/export.php?type=purchases" class="btn-secondary" style="padding:13px 22px;white-space:nowrap;"><i class="fa-solid fa-file-export"></i> Export CSV</a>
                            <a href="purchase_create.php" class="btn-primary" style="width:auto;padding:13px 24px;white-space:nowrap;"><i class="fa-solid fa-plus"></i> Add Purchase</a>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="crm-table">
                            <thead>
                                <tr>
                                    <th>Bill #</th>
                                    <th>Vendor</th>
                                    <th>Items</th>
                                    <th>Total Amount</th>
                                    <th>Paid</th>
                                    <th>Balance</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($purchases)): ?>
                                    <tr>
                                        <td colspan="8" class="table-empty">No purchase records yet.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($purchases as $p): ?>
                                        <tr>
                                            <td>
                                                <div class="text-bold"><?= htmlspecialchars($p['purchase_number']) ?></div>
                                            </td>
                                            <td><?= htmlspecialchars($p['vendor_name']) ?></td>
                                            <td><?= (int) $p['item_count'] ?></td>
                                            <td>₹<?= number_format($p['grand_total'], 2) ?></td>
                                            <td class="stat-paid">₹<?= number_format($p['paid_amount'], 2) ?></td>
                                            <td class="stat-unpaid">
                                                ₹<?= number_format($p['grand_total'] - $p['paid_amount'], 2) ?></td>
                                            <td>
                                                <span class="status-badge status-<?= $p['payment_status'] ?>">
                                                    <?= htmlspecialchars(ucfirst($p['payment_status'])) ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($p['purchase_date']) ?></td>
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
        function toggleSidebar() { document.getElementById('sidebar').classList.toggle('sidebar-collapsed'); }
    </script>
</body>

</html>
