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

$returnTo = trim((string) ($_GET['return_to'] ?? ''));
$safeReturnTo = in_array($returnTo, ['invoices.php', 'invoice_create.php'], true) ? $returnTo : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vy CRM - Add Product</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <style>
        .breadcrumb .current { color: #2563eb; }
        .module-panel {
            background: var(--surface);
            border-radius: 22px;
            box-shadow: var(--shadow-md);
            padding: 24px;
        }
        .panel-title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 6px;
        }
        .panel-copy {
            color: var(--text-muted);
            margin-bottom: 22px;
            line-height: 1.5;
        }
        .inline-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
        .form-actions {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
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
                <div class="breadcrumb">Home / Products<span class="current">Add Product</span></div>
            </div>
            <div class="topbar-right">
                <a href="products.php" class="btn-secondary"><i class="fa-solid fa-table-list"></i> Product List</a>
                <div class="profile-pill">
                    <img src="/images/admin.jpg" onerror="this.src='https://ui-avatars.com/api/?name=Admin&background=7b5ef0&color=fff'" alt="Admin">
                    <span class="name"><?= htmlspecialchars($_SESSION["username"]) ?></span>
                </div>
            </div>
        </header>
        <div class="content-scroll">
            <section class="module-panel">
                <div class="panel-title">Add Product</div>
                <div class="panel-copy">Create products on a separate page and reuse them in invoice rows.</div>
                <form id="productForm">
                    <div class="form-group">
                        <label class="form-label">Product Name</label>
                        <input class="form-control" type="text" name="name" placeholder="Website Design Package" required>
                    </div>
                    <div class="inline-grid">
                        <div class="form-group">
                            <label class="form-label">Product Code</label>
                            <input class="form-control" type="text" name="product_code" placeholder="PRD-001">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select class="form-control" name="status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="inline-grid">
                        <div class="form-group">
                            <label class="form-label">Unit Price</label>
                            <input class="form-control" type="number" name="unit_price" min="0" step="0.01" value="0" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Tax %</label>
                            <input class="form-control" type="number" name="tax_percent" min="0" step="0.01" value="0">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="4" placeholder="Optional line item description"></textarea>
                    </div>
                    <div class="form-actions">
                        <button class="btn-primary" type="submit">Save Product</button>
                        <a href="products.php" class="btn-secondary"><i class="fa-solid fa-table-list"></i> Product List</a>
                        <?php if ($safeReturnTo !== ''): ?>
                            <a href="<?= htmlspecialchars($safeReturnTo) ?>?product_created=1" class="btn-secondary">Back To Invoice</a>
                        <?php endif; ?>
                    </div>
                    <div class="text-muted text-sm" id="productStatusText" style="margin-top:16px;">Product is ready to be saved.</div>
                </form>
            </section>
        </div>
    </main>
</div>
<script>
const returnTo = <?= json_encode($safeReturnTo) ?>;

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

document.getElementById('productForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const formData = new FormData(form);
    const submitButton = form.querySelector('button[type="submit"]');
    const statusText = document.getElementById('productStatusText');
    submitButton.disabled = true;
    statusText.textContent = 'Saving product...';

    try {
        const response = await fetch('/api/products.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'create',
                name: formData.get('name'),
                product_code: formData.get('product_code'),
                description: formData.get('description'),
                unit_price: formData.get('unit_price'),
                tax_percent: formData.get('tax_percent'),
                status: formData.get('status')
            })
        });
        const payload = await response.json();
        if (!payload.success) {
            statusText.textContent = payload.message || 'Unable to create product';
            alert(payload.message || 'Unable to create product');
            return;
        }

        if (returnTo) {
            window.location.href = `${returnTo}?product_created=1`;
            return;
        }

        window.location.href = 'products.php';
    } catch (error) {
        statusText.textContent = 'Unable to create product';
        alert('Unable to create product');
    } finally {
        submitButton.disabled = false;
    }
});
</script>
</body>
</html>
