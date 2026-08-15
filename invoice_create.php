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
$customers = commerce_fetch_customers($conn, $prefix);
$productCreated = isset($_GET['product_created']) ? 1 : 0;
$customerCreated = isset($_GET['customer_created']) ? 1 : 0;
$invoiceColumnsConfig = commerce_get_invoice_columns_config($conn, $prefix);
$activeColumns = array_values(array_filter($invoiceColumnsConfig, static fn($col) => !empty($col['enabled'])));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(brand_page_title('Create Invoice')) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link rel="shortcut icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <style>
        .breadcrumb .current { color: #2563eb; }
        .module-panel {
            background: var(--surface);
            border-radius: 22px;
            box-shadow: var(--shadow-md);
            padding: 24px;
        }
        .panel-title { font-size: 18px; margin-bottom: 6px; }
        .panel-copy { color: var(--text-muted); line-height: 1.5; margin-bottom: 20px; }
        .inline-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
        .item-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 12px;
        }
        .item-table th {
            text-align: left;
            font-size: 12px;
            color: var(--text-muted);
            padding: 0 10px 4px;
            text-transform: uppercase;
            letter-spacing: .8px;
        }
        .item-table td {
            background: #f9fbff;
            padding: 10px;
            vertical-align: top;
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
        }
        .item-table td:first-child {
            border-left: 1px solid var(--border);
            border-radius: 14px 0 0 14px;
        }
        .item-table td:last-child {
            border-right: 1px solid var(--border);
            border-radius: 0 14px 14px 0;
        }
        .item-table .form-control {
            background: #fff;
            padding: 12px 14px;
        }
        .item-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-top: 16px;
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
        .summary-card {
            background: linear-gradient(180deg, #f8fbff 0%, #eef5ff 100%);
            border: 1px solid #dde8fb;
            border-radius: 20px;
            padding: 18px;
            margin-top: 18px;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid rgba(43,54,116,.08);
        }
        .summary-row:last-child { border-bottom: 0; }
        .summary-row strong { font-size: 18px; }
        .small-action {
            width: 42px;
            height: 42px;
        }
        .success-actions {
            display: none;
            align-items: center;
            gap: 12px;
            margin-top: 18px;
            flex-wrap: wrap;
        }
        .success-actions.is-visible {
            display: flex;
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
                <div class="breadcrumb">Home / Invoices<span class="current">Create Invoice</span></div>
            </div>
            <div class="topbar-right">
                <a href="invoices.php" class="btn-secondary"><i class="fa-solid fa-table-list"></i> Invoice List</a>
                <?php include 'includes/profile_pill.php'; ?>
            </div>
        </header>
        <div class="content-scroll">
            <section class="module-panel">
                <div class="panel-title">Invoice Form</div>
                <div class="panel-copy">This page only handles creation. Listing stays on the invoice list page.</div>
                <form id="invoiceForm">
                    <div class="inline-grid">
                        <div class="form-group">
                            <label class="form-label">Customer Name</label>
                            <div class="flex items-center" style="gap:10px;">
                                <select class="form-control" name="customer_id" id="customerSelect">
                                    <option value="">-- Select Existing Customer --</option>
                                    <?php foreach ($customers as $cust): ?>
                                        <option value="<?= $cust['id'] ?>"><?= htmlspecialchars($cust['name']) ?></option>
                                    <?php endforeach; ?>
                                    <option value="new">Add New Customer (Custom Name)</option>
                                </select>
                                <a href="customer_create.php?return_to=invoice_create.php" class="btn-secondary" style="white-space:nowrap;"><i class="fa-solid fa-user-plus"></i> New Customer</a>
                            </div>
                            <input class="form-control" type="text" name="customer_name" id="customerNameInput" placeholder="Enter customer name" style="margin-top:10px;">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Customer Phone</label>
                            <input class="form-control" type="text" name="customer_phone" id="customerPhoneInput">
                        </div>
                    </div>
                    <div class="inline-grid">
                        <div class="form-group">
                            <label class="form-label">Customer Email</label>
                            <input class="form-control" type="email" name="customer_email" id="customerEmailInput">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select class="form-control" name="status">
                                <option value="draft">Draft</option>
                                <option value="sent">Sent</option>
                                <option value="paid">Paid</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                    <div class="inline-grid">
                        <div class="form-group">
                            <label class="form-label">Invoice Date</label>
                            <input class="form-control" type="date" name="invoice_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Due Date</label>
                            <input class="form-control" type="date" name="due_date">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Billing Address</label>
                        <textarea class="form-control" name="billing_address" id="billingAddressInput" rows="3"></textarea>
                    </div>

                    <table class="item-table">
                        <thead>
                            <tr>
                                <?php foreach ($activeColumns as $col): ?>
                                    <th><?= htmlspecialchars($col['label']) ?></th>
                                <?php endforeach; ?>
                                <th style="width:12%;">Total</th>
                                <th style="width:50px;"></th>
                            </tr>
                        </thead>
                        <tbody id="itemRows"></tbody>
                    </table>

                    <div class="item-actions">
                        <div class="flex items-center" style="gap:12px;flex-wrap:wrap;">
                            <button class="btn-secondary" type="button" id="addRowBtn"><i class="fa-solid fa-plus"></i> Add Line</button>
                            <a class="btn-secondary" href="product_create.php?return_to=invoice_create.php"><i class="fa-solid fa-arrow-up-right-from-square"></i> Add Product</a>
                            <a class="btn-secondary" href="invoice_settings.php" target="_blank" title="Configure visible columns and pricing"><i class="fa-solid fa-sliders"></i> Configure Columns</a>
                        </div>
                        <div class="text-muted text-sm">Line item fields & pricing columns are 100% dynamic. You can customize visible columns in Settings.</div>
                    </div>

                    <div class="form-group" style="margin-top:18px;">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="4" placeholder="Payment terms, delivery note, internal comment"></textarea>
                    </div>

                    <div class="summary-card">
                        <div class="summary-row"><span>Subtotal</span><strong id="subtotalValue">0.00</strong></div>
                        <div class="summary-row"><span>Total Tax</span><strong id="taxValue">0.00</strong></div>
                        <div class="summary-row"><span>Grand Total</span><strong id="grandValue">0.00</strong></div>
                    </div>

                    <div class="item-actions">
                        <div class="text-muted text-sm" id="invoiceStatusText">Invoice is ready to be saved.</div>
                        <button class="btn-primary" type="submit">Create Invoice</button>
                    </div>
                    <div class="success-actions" id="successActions">
                        <a class="btn-primary" id="printInvoiceBtn" href="#" target="_blank" rel="noopener"><i class="fa-solid fa-print"></i> Print Invoice</a>
                        <a class="btn-secondary" href="invoices.php"><i class="fa-solid fa-table-list"></i> Go To Invoice List</a>
                    </div>
                </form>
            </section>
        </div>
    </main>
</div>
<script>
const products = <?= json_encode(array_values(array_map(static function ($product) {
    return [
        'id' => (int) $product['id'],
        'name' => $product['name'],
        'description' => $product['description'] ?? '',
        'unit_price' => (float) $product['unit_price'],
        'tax_percent' => (float) $product['tax_percent'],
        'unit' => $product['unit'] ?? 'PCS',
        'hsn_code' => $product['hsn_code'] ?? '',
        'mfg_date' => $product['mfg_date'] ?? '',
        'exp_date' => $product['exp_date'] ?? '',
        'status' => $product['status'],
    ];
}, $activeProducts)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const customers = <?= json_encode($customers) ?>;
const productCreated = <?= $productCreated ?> === 1;
const customerCreated = <?= $customerCreated ?> === 1;

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

function money(value) {
    return Number(value || 0).toFixed(2);
}

const activeColumns = <?= json_encode($activeColumns) ?>;

function createProductOptions() {
    const baseOption = '<option value="">Select product / item</option>';
    return baseOption + products.map((product) => `<option value="${product.id}">${escapeHtml(product.name)}</option>`).join('');
}

function createRow(selectedProductId = '') {
    const tbody = document.getElementById('itemRows');
    const row = document.createElement('tr');

    let tdsHtml = '';
    activeColumns.forEach(col => {
        if (col.key === 'item_name') {
            tdsHtml += `<td>
                <select class="form-control js-product" style="margin-bottom:4px;">${createProductOptions()}</select>
                <input class="form-control js-item-name" type="text" placeholder="Or Custom Item Name" style="display:none;margin-bottom:4px;">
                <input class="form-control js-description" type="text" placeholder="Description (Optional)">
            </td>`;
        } else if (col.key === 'quantity') {
            tdsHtml += `<td><input class="form-control js-qty" type="number" min="0.01" step="0.01" value="1"></td>`;
        } else if (col.key === 'unit') {
            tdsHtml += `<td><input class="form-control js-unit" type="text" value="PCS" placeholder="Unit"></td>`;
        } else if (col.key === 'unit_price') {
            tdsHtml += `<td><input class="form-control js-price" type="number" min="0" step="0.01" value="0"></td>`;
        } else if (col.key === 'tax_percent') {
            tdsHtml += `<td><input class="form-control js-tax" type="number" min="0" step="0.01" value="0"></td>`;
        } else if (col.key === 'hsn_code') {
            tdsHtml += `<td><input class="form-control js-hsn" type="text" placeholder="HSN/SAC"></td>`;
        } else {
            const inputType = col.type === 'number' || col.type === 'decimal' ? 'number' : (col.type === 'date' ? 'date' : 'text');
            tdsHtml += `<td><input class="form-control js-col-${col.key}" type="${inputType}" step="any" placeholder="${escapeHtml(col.label)}"></td>`;
        }
    });

    tdsHtml += `<td><input class="form-control js-total" type="text" value="0.00" readonly></td>`;
    tdsHtml += `<td><button class="btn-icon small-action" type="button" title="Delete Row"><i class="fa-solid fa-trash"></i></button></td>`;

    row.innerHTML = tdsHtml;

    // Fallback hidden inputs if standard fields are not enabled as columns
    ['qty', 'price', 'tax', 'unit', 'hsn', 'batch', 'mfg', 'exp'].forEach(k => {
        if (!row.querySelector('.js-' + k)) {
            const inp = document.createElement('input'); inp.type = 'hidden'; inp.className = 'js-' + k;
            inp.value = (k === 'qty' ? '1' : (k === 'unit' ? 'PCS' : '0'));
            row.appendChild(inp);
        }
    });

    const productSelect = row.querySelector('.js-product');
    if (productSelect) {
        productSelect.value = selectedProductId;
        productSelect.addEventListener('change', () => applyProduct(row));
    }

    row.querySelectorAll('input').forEach(inp => {
        if (!inp.classList.contains('js-total')) {
            inp.addEventListener('input', () => recalculateRow(row));
        }
    });

    row.querySelector('.small-action').addEventListener('click', () => {
        row.remove();
        if (!tbody.children.length) createRow();
        recalculateSummary();
    });

    tbody.appendChild(row);
    if (selectedProductId) {
        applyProduct(row);
    } else {
        recalculateRow(row);
    }
}

function applyProduct(row) {
    const productSelect = row.querySelector('.js-product');
    if (!productSelect) return;
    const productId = Number(productSelect.value || 0);
    const product = products.find((entry) => entry.id === productId);
    
    const descInp = row.querySelector('.js-description');
    const priceInp = row.querySelector('.js-price');
    const taxInp = row.querySelector('.js-tax');
    const unitInp = row.querySelector('.js-unit');
    const hsnInp = row.querySelector('.js-hsn');

    if (!product) {
        if (descInp) descInp.value = '';
        if (priceInp) priceInp.value = '0';
        if (taxInp) taxInp.value = '0';
        if (unitInp) unitInp.value = 'PCS';
        if (hsnInp) hsnInp.value = '';
        recalculateRow(row);
        return;
    }
    if (descInp) descInp.value = product.description || '';
    if (priceInp) priceInp.value = product.unit_price;
    if (taxInp) taxInp.value = product.tax_percent;
    if (unitInp) unitInp.value = product.unit || 'PCS';
    if (hsnInp) hsnInp.value = product.hsn_code || '';
    recalculateRow(row);
}

function recalculateRow(row) {
    const qty = Number(row.querySelector('.js-qty')?.value || 1);
    const price = Number(row.querySelector('.js-price')?.value || 0);
    const charged = Number(row.querySelector('.js-col-charged_amount')?.value || 0);
    const received = Number(row.querySelector('.js-col-received_amount')?.value || 0);
    const charges = Number(row.querySelector('.js-col-charges')?.value || 0);
    const taxPct = Number(row.querySelector('.js-tax')?.value || 0);

    let rowBase = charged > 0 ? charged : (qty * price);
    let rowSubtotal = rowBase + charges;
    let rowTax = (rowSubtotal * taxPct) / 100;
    let rowTotal = rowSubtotal + rowTax;

    const totalEl = row.querySelector('.js-total');
    if (totalEl) totalEl.value = money(rowTotal);
    recalculateSummary();
}

function recalculateSummary() {
    let subtotal = 0;
    let taxTotal = 0;

    document.querySelectorAll('#itemRows tr').forEach((row) => {
        const qty = Number(row.querySelector('.js-qty')?.value || 1);
        const price = Number(row.querySelector('.js-price')?.value || 0);
        const charged = Number(row.querySelector('.js-col-charged_amount')?.value || 0);
        const charges = Number(row.querySelector('.js-col-charges')?.value || 0);
        const taxPct = Number(row.querySelector('.js-tax')?.value || 0);

        let rowBase = charged > 0 ? charged : (qty * price);
        let rowSubtotal = rowBase + charges;
        subtotal += rowSubtotal;
        taxTotal += (rowSubtotal * taxPct) / 100;
    });

    document.getElementById('subtotalValue').textContent = money(subtotal);
    document.getElementById('taxValue').textContent = money(taxTotal);
    document.getElementById('grandValue').textContent = money(subtotal + taxTotal);
}

function buildPayload() {
    const form = document.getElementById('invoiceForm');
    const formData = new FormData(form);
    const items = Array.from(document.querySelectorAll('#itemRows tr')).map((row) => {
        const productSelect = row.querySelector('.js-product');
        const productId = productSelect ? productSelect.value : null;
        const product = products.find((entry) => entry.id === Number(productId));

        const customData = {};
        activeColumns.forEach(col => {
            const inp = row.querySelector('.js-col-' + col.key);
            if (inp) {
                customData[col.key] = inp.value;
            }
        });

        const customNameInp = row.querySelector('.js-item-name');
        let itemName = product ? product.name : (customNameInp ? customNameInp.value : '');
        if (!itemName) itemName = 'Line Item';

        return {
            product_id: productId || null,
            item_name: itemName,
            description: row.querySelector('.js-description')?.value || '',
            quantity: row.querySelector('.js-qty')?.value || 1,
            unit_price: row.querySelector('.js-price')?.value || 0,
            tax_percent: row.querySelector('.js-tax')?.value || 0,
            unit: row.querySelector('.js-unit')?.value || 'PCS',
            hsn_code: row.querySelector('.js-hsn')?.value || '',
            batch_no: row.querySelector('.js-batch')?.value || '',
            mfg_date: row.querySelector('.js-mfg')?.value || '',
            exp_date: row.querySelector('.js-exp')?.value || '',
            custom_data: customData
        };
    });

    return {
        action: 'create',
        customer_id: formData.get('customer_id'),
        customer_name: formData.get('customer_name'),
        customer_phone: formData.get('customer_phone'),
        customer_email: formData.get('customer_email'),
        billing_address: formData.get('billing_address'),
        invoice_date: formData.get('invoice_date'),
        due_date: formData.get('due_date'),
        notes: formData.get('notes'),
        status: formData.get('status'),
        items
    };
}

function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

document.getElementById('customerSelect').addEventListener('change', function() {
    const cid = this.value;
    const nameInput = document.getElementById('customerNameInput');
    const phoneInput = document.getElementById('customerPhoneInput');
    const emailInput = document.getElementById('customerEmailInput');
    const addressInput = document.getElementById('billingAddressInput');
    
    if (cid === 'new' || cid === '') {
        nameInput.value = '';
        phoneInput.value = '';
        emailInput.value = '';
        addressInput.value = '';
        nameInput.style.display = 'block';
        nameInput.required = true;
    } else {
        const cust = customers.find(c => String(c.id) === String(cid));
        if (cust) {
            nameInput.value = cust.name;
            phoneInput.value = cust.phone || '';
            emailInput.value = cust.email || '';
            addressInput.value = cust.billing_address || '';
            nameInput.style.display = 'none';
            nameInput.required = false;
        }
    }
});

document.getElementById('addRowBtn').addEventListener('click', () => createRow());

document.getElementById('invoiceForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const formElement = event.currentTarget;
    const statusText = document.getElementById('invoiceStatusText');
    const submitButton = formElement.querySelector('button[type="submit"]');
    const successActions = document.getElementById('successActions');
    const printInvoiceBtn = document.getElementById('printInvoiceBtn');
    statusText.textContent = 'Saving invoice...';
    successActions.classList.remove('is-visible');
    submitButton.disabled = true;

    try {
        const response = await fetch('/api/invoices.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(buildPayload())
        });
        
        const responseText = (await response.text()).trim();
        let payload = { success: false, message: 'Parse error' };
        
        try {
            // Try direct parse first
            payload = JSON.parse(responseText);
        } catch (parseError) {
            // Fallback: extract JSON if there's garbage output around it
            const startIdx = responseText.indexOf('{');
            const endIdx = responseText.lastIndexOf('}');
            
            if (startIdx !== -1 && endIdx !== -1 && endIdx > startIdx) {
                const potentialJson = responseText.substring(startIdx, endIdx + 1);
                try {
                    payload = JSON.parse(potentialJson);
                } catch (e) {
                    console.error('Inner Parse Error:', e, 'Text:', potentialJson);
                }
            }

            // If we still don't have success but the response was OK, treat as success if no payload
            if (!payload.success && response.ok && responseText.toLowerCase().includes('"success":true')) {
                payload = { success: true, data: {} };
            }
            
            if (!payload.success && !response.ok) {
                console.error('Response Text:', responseText);
                throw new Error('Server returned invalid data format: ' + responseText.slice(0, 50));
            }
        }

        if (!payload.success) {
            statusText.textContent = payload.message || 'Unable to create invoice';
            alert(payload.message || 'Unable to create invoice');
            return;
        }

        const invoiceId = payload.data ? payload.data.invoice_id : 0;
        const invoiceNumber = payload.data ? payload.data.invoice_number : 'Invoice';
        printInvoiceBtn.href = `invoice_print.php?id=${invoiceId}`;
        printInvoiceBtn.setAttribute('aria-label', `Print ${invoiceNumber}`);
        statusText.textContent = `${invoiceNumber} created successfully. You can print it or go to the invoice list.`;
        successActions.classList.add('is-visible');
        formElement.reset();
        document.getElementById('itemRows').innerHTML = '';
        createRow();
        recalculateSummary();
    } catch (error) {
        console.error('Invoice Creation Error:', error);
        statusText.textContent = error.message || 'Unable to create invoice';
        alert(error.message || 'Unable to create invoice');
    } finally {
        submitButton.disabled = false;
    }
});

createRow();
if (productCreated) {
    document.getElementById('invoiceStatusText').textContent = 'Product added. You can now select it in the invoice row.';
}
</script>
</body>
</html>
