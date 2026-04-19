<?php
// purchase_create.php - Create Purchase Bill
require_once 'auth_check.php';
require_once 'includes/commerce.php';
require_once 'includes/brand.php';

$context = commerce_get_tenant_context();
$conn = $context['conn'];
$prefix = $context['prefix'];
commerce_ensure_tables($conn, $prefix);

$products = commerce_fetch_products($conn, $prefix);
$activeProducts = array_values(array_filter($products, static fn($p) => $p['status'] === 'active'));
$vendors = commerce_fetch_vendors($conn, $prefix);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vy CRM - Add Purchase Bill</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <style>
        .module-panel { background: var(--surface); border-radius: 22px; box-shadow: var(--shadow-md); padding: 30px; margin-top: 20px; }
        .panel-title { font-size: 20px; font-weight: 700; margin-bottom: 25px; }
        .inline-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .item-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .item-table th { text-align: left; padding: 12px; font-size: 13px; color: var(--text-muted); text-transform: uppercase; border-bottom: 2px solid var(--border); }
        .item-table td { padding: 12px; border-bottom: 1px solid var(--border); }
        .summary-card { background: #f8fafc; border-radius: 12px; padding: 20px; margin-top: 20px; border: 1px solid var(--border); }
        .summary-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e2e8f0; }
        .summary-row:last-child { border-bottom: 0; font-weight: 700; font-size: 18px; }
    </style>
</head>
<body>
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main-content">
        <header class="topbar">
            <div class="breadcrumb">Purchases / <span class="current">New Bill</span></div>
            <div class="topbar-right">
                <a href="purchases.php" class="btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
            </div>
        </header>

        <div class="content-scroll">
            <section class="module-panel">
                <div class="panel-title">Add Purchase Bill</div>
                <form id="purchaseForm">
                    <div class="inline-grid">
                        <div class="form-group">
                            <label class="form-label">Vendor / Supplier</label>
                            <select name="vendor_id" id="vendorSelect" class="form-control" required>
                                <option value="">-- Select Vendor --</option>
                                <?php foreach ($vendors as $ven): ?>
                                    <option value="<?= $ven['id'] ?>"><?= htmlspecialchars($ven['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="vendor_name" id="vendorNameHidden">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Bill Date</label>
                            <input type="date" name="purchase_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <table class="item-table">
                        <thead>
                            <tr>
                                <th style="width: 40%;">Item Details</th>
                                <th>Quantity</th>
                                <th>Unit Price (₹)</th>
                                <th>Tax (%)</th>
                                <th>Total (₹)</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="itemRows"></tbody>
                    </table>
                    <button type="button" class="btn-secondary" onclick="addRow()"><i class="fa-solid fa-plus"></i> Add Line</button>

                    <div style="display: grid; grid-template-columns: 1fr 400px; gap: 40px; margin-top: 40px;">
                        <div>
                            <div class="form-group">
                                <label class="form-label">Payment Made (₹)</label>
                                <input type="number" step="0.01" name="paid_amount" id="paidAmount" class="form-control" value="0" placeholder="Amount paid to vendor">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-control">
                                    <option value="received">Received</option>
                                    <option value="draft">Draft</option>
                                    <option value="returned">Returned</option>
                                </select>
                            </div>
                        </div>
                        <div class="summary-card">
                            <div class="summary-row"><span>Subtotal</span><span id="subtotal">0.00</span></div>
                            <div class="summary-row"><span>Tax</span><span id="tax">0.00</span></div>
                            <div class="summary-row"><span>Grand Total</span><span id="total">0.00</span></div>
                        </div>
                    </div>

                    <div style="margin-top: 30px; display: flex; justify-content: flex-end;">
                        <button type="submit" class="btn-primary" style="width: auto; padding: 15px 40px;">SAVE BILL</button>
                    </div>
                </form>
            </section>
        </div>
    </main>
</div>

<script>
    const products = <?= json_encode($activeProducts) ?>;
    
    function addRow() {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>
                <select class="form-control prod-select" onchange="applyProd(this)">
                    <option value="">Select Item</option>
                    ${products.map(p => `<option value="${p.id}">${p.name}</option>`).join('')}
                </select>
                <input type="text" class="form-control item-name" placeholder="Or enter manual item name" style="margin-top:5px;">
            </td>
            <td><input type="number" class="form-control qty" value="1" oninput="calc()"></td>
            <td><input type="number" class="form-control price" value="0" step="0.01" oninput="calc()"></td>
            <td><input type="number" class="form-control tax" value="0" step="0.01" oninput="calc()"></td>
            <td><input type="text" class="form-control line-total" value="0.00" readonly></td>
            <td><button type="button" class="btn-icon" onclick="this.parentElement.parentElement.remove(); calc();"><i class="fa-solid fa-trash"></i></button></td>
        `;
        document.getElementById('itemRows').appendChild(tr);
    }

    function applyProd(sel) {
        const row = sel.parentElement.parentElement;
        const p = products.find(prod => String(prod.id) === String(sel.value));
        if (p) {
            row.querySelector('.item-name').value = p.name;
            row.querySelector('.price').value = p.unit_price;
            row.querySelector('.tax').value = p.tax_percent;
        }
        calc();
    }

    function calc() {
        let sub = 0; let tax = 0;
        document.querySelectorAll('#itemRows tr').forEach(row => {
            const q = parseFloat(row.querySelector('.qty').value) || 0;
            const p = parseFloat(row.querySelector('.price').value) || 0;
            const t = parseFloat(row.querySelector('.tax').value) || 0;
            const lineSub = q * p;
            const lineTax = lineSub * (t/100);
            row.querySelector('.line-total').value = (lineSub + lineTax).toFixed(2);
            sub += lineSub;
            tax += lineTax;
        });
        document.getElementById('subtotal').innerText = sub.toFixed(2);
        document.getElementById('tax').innerText = tax.toFixed(2);
        document.getElementById('total').innerText = (sub + tax).toFixed(2);
    }

    document.getElementById('purchaseForm').onsubmit = async (e) => {
        e.preventDefault();
        const vendorSelect = document.getElementById('vendorSelect');
        document.getElementById('vendorNameHidden').value = vendorSelect.options[vendorSelect.selectedIndex].text;
        
        const items = [];
        document.querySelectorAll('#itemRows tr').forEach(row => {
            items.push({
                product_id: row.querySelector('.prod-select').value,
                item_name: row.querySelector('.item-name').value,
                quantity: row.querySelector('.qty').value,
                unit_price: row.querySelector('.price').value,
                tax_percent: row.querySelector('.tax').value
            });
        });

        const data = {
            vendor_id: vendorSelect.value,
            vendor_name: document.getElementById('vendorNameHidden').value,
            purchase_date: e.target.purchase_date.value,
            paid_amount: e.target.paid_amount.value,
            status: e.target.status.value,
            items: items
        };

        const res = await fetch('api/purchases.php', {
            method: 'POST',
            body: JSON.stringify(data),
            headers: {'Content-Type': 'application/json'}
        });
        const out = await res.json();
        if (out.success) {
            alert('Purchase saved successfully!');
            location.href = 'purchases.php';
        } else {
            alert('Error: ' + out.message);
        }
    };

    addRow();
    function toggleSidebar() { document.getElementById('sidebar').classList.toggle('sidebar-collapsed'); }
</script>
</body>
</html>
