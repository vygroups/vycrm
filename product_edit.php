<?php
require_once 'auth_check.php';
require_once 'includes/commerce.php';
require_once 'includes/brand.php';

$context = commerce_get_tenant_context();
$conn = $context['conn'];
$prefix = $context['prefix'];
commerce_ensure_tables($conn, $prefix);

$productId = (int) ($_GET['id'] ?? 0);
if ($productId <= 0) {
    header('Location: products.php');
    exit;
}

$stmt = $conn->prepare("SELECT * FROM {$prefix}products WHERE id = ? LIMIT 1");
$stmt->execute([$productId]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$product) {
    header('Location: products.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(brand_page_title('Edit Product')) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link rel="shortcut icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <style>
        .breadcrumb .current { color: var(--primary); }
        .module-panel {
            background: var(--surface);
            border-radius: 22px;
            box-shadow: var(--shadow-md);
            padding: 32px;
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
        .inline-grid-3 {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }
        .inline-grid-4 {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
        }
        .form-actions {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
            margin-top: 8px;
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
        .section-divider {
            border: none;
            border-top: 1.5px solid var(--border);
            margin: 28px 0;
        }
        .section-label {
            font-size: 14px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
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
                <div class="breadcrumb">Home / Products<span class="current">Edit Product</span></div>
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
                <div class="panel-title">Edit Product</div>
                <div class="panel-copy">Update product details. Changes will apply to future invoices only.</div>
                <form id="productForm">
                    <input type="hidden" name="id" value="<?= $product['id'] ?>">

                    <div class="section-label"><i class="fa-solid fa-cube"></i> Basic Information</div>
                    <div class="inline-grid">
                        <div class="form-group">
                            <label class="form-label">Product Name *</label>
                            <input class="form-control" type="text" name="name" value="<?= htmlspecialchars($product['name']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Product Code</label>
                            <input class="form-control" type="text" name="product_code" value="<?= htmlspecialchars($product['product_code'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="inline-grid-3">
                        <div class="form-group">
                            <label class="form-label">HSN / SAC Code</label>
                            <input class="form-control" type="text" name="hsn_code" value="<?= htmlspecialchars($product['hsn_code'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Category</label>
                            <input class="form-control" type="text" name="category" value="<?= htmlspecialchars($product['category'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select class="form-control" name="status">
                                <option value="active" <?= ($product['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= ($product['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                    </div>

                    <hr class="section-divider">
                    <div class="section-label"><i class="fa-solid fa-indian-rupee-sign"></i> Pricing & Tax</div>
                    <div class="inline-grid-4">
                        <div class="form-group">
                            <label class="form-label">Purchase Price (₹)</label>
                            <input class="form-control" type="number" name="purchase_price" min="0" step="0.01" value="<?= number_format((float)($product['purchase_price'] ?? 0), 2, '.', '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">PTS (₹)</label>
                            <input class="form-control" type="number" name="pts" min="0" step="0.01" value="<?= number_format((float)($product['pts'] ?? 0), 2, '.', '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">PTR (₹)</label>
                            <input class="form-control" type="number" name="ptr" min="0" step="0.01" value="<?= number_format((float)($product['ptr'] ?? 0), 2, '.', '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">MRP (₹)</label>
                            <input class="form-control" type="number" name="mrp" min="0" step="0.01" value="<?= number_format((float)($product['mrp'] ?? 0), 2, '.', '') ?>">
                        </div>
                    </div>
                    <div class="inline-grid-3" style="margin-top:14px;">
                        <div class="form-group">
                            <label class="form-label">Selling Price (₹) *</label>
                            <input class="form-control" type="number" name="unit_price" min="0" step="0.01" value="<?= number_format((float)$product['unit_price'], 2, '.', '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Tax %</label>
                            <input class="form-control" type="number" name="tax_percent" min="0" step="0.01" value="<?= number_format((float)$product['tax_percent'], 2, '.', '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Unit</label>
                            <select class="form-control" name="unit">
                                <?php
                                $units = ['PCS'=>'Pieces','BOX'=>'Box','STRIP'=>'Strip','PKT'=>'Packet','KG'=>'Kilogram','GM'=>'Gram','LTR'=>'Litre','ML'=>'Millilitre','MTR'=>'Metre','SET'=>'Set','PAIR'=>'Pair','DOZEN'=>'Dozen','VIAL'=>'Vial','TAB'=>'Tablet','CAP'=>'Capsule'];
                                $currentUnit = $product['unit'] ?? 'PCS';
                                foreach ($units as $code => $label):
                                ?>
                                    <option value="<?= $code ?>" <?= $currentUnit === $code ? 'selected' : '' ?>><?= $code ?> - <?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <hr class="section-divider">
                    <div class="section-label"><i class="fa-solid fa-calendar-days"></i> Batch & Dates <span style="font-weight:400;font-size:12px;color:var(--text-muted);">(Optional)</span></div>
                    <div class="inline-grid">
                        <div class="form-group">
                            <label class="form-label">MFG Date</label>
                            <input class="form-control" type="date" name="mfg_date" value="<?= htmlspecialchars($product['mfg_date'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">EXP Date</label>
                            <input class="form-control" type="date" name="exp_date" value="<?= htmlspecialchars($product['exp_date'] ?? '') ?>">
                        </div>
                    </div>

                    <hr class="section-divider">
                    <div class="section-label"><i class="fa-solid fa-warehouse"></i> Inventory</div>
                    <div class="inline-grid">
                        <div class="form-group">
                            <label class="form-label">Opening Stock</label>
                            <input class="form-control" type="number" name="opening_stock" min="0" step="0.01" value="<?= number_format((float)($product['opening_stock'] ?? 0), 2, '.', '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Current Stock</label>
                            <input class="form-control" type="number" name="stock_quantity" min="0" step="0.01" value="<?= number_format((float)($product['stock_quantity'] ?? 0), 2, '.', '') ?>">
                        </div>
                    </div>

                    <div class="form-group" style="margin-top:14px;">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="3"><?= htmlspecialchars($product['description'] ?? '') ?></textarea>
                    </div>
                    <div class="form-actions">
                        <button class="btn-primary" type="submit" style="width:auto;padding:14px 28px;"><i class="fa-solid fa-check"></i> Update Product</button>
                        <a href="products.php" class="btn-secondary"><i class="fa-solid fa-table-list"></i> Product List</a>
                    </div>
                    <div class="text-muted text-sm" id="productStatusText" style="margin-top:16px;">Product is ready to be updated.</div>
                </form>
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

document.getElementById('productForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const formData = new FormData(form);
    const submitButton = form.querySelector('button[type="submit"]');
    const statusText = document.getElementById('productStatusText');
    submitButton.disabled = true;
    statusText.textContent = 'Updating product...';

    try {
        const response = await fetch('/api/products.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'update',
                id: formData.get('id'),
                name: formData.get('name'),
                product_code: formData.get('product_code'),
                description: formData.get('description'),
                unit_price: formData.get('unit_price'),
                purchase_price: formData.get('purchase_price'),
                pts: formData.get('pts'),
                ptr: formData.get('ptr'),
                mrp: formData.get('mrp'),
                tax_percent: formData.get('tax_percent'),
                unit: formData.get('unit'),
                opening_stock: formData.get('opening_stock'),
                stock_quantity: formData.get('stock_quantity'),
                hsn_code: formData.get('hsn_code'),
                category: formData.get('category'),
                status: formData.get('status'),
                mfg_date: formData.get('mfg_date'),
                exp_date: formData.get('exp_date')
            })
        });
        const payload = await response.json();
        if (!payload.success) {
            statusText.textContent = payload.message || 'Unable to update product';
            alert(payload.message || 'Unable to update product');
            return;
        }

        statusText.textContent = 'Product updated successfully!';
        statusText.style.color = '#16a34a';
        setTimeout(() => { window.location.href = 'products.php'; }, 1200);
    } catch (error) {
        statusText.textContent = 'Unable to update product';
        alert('Unable to update product');
    } finally {
        submitButton.disabled = false;
    }
});
</script>
</body>
</html>
