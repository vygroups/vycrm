<?php
// customers.php - Customer List Page
require_once 'auth_check.php';
require_once 'includes/commerce.php';
require_once 'includes/brand.php';

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
    <title><?= htmlspecialchars(brand_page_title('Customer List')) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link rel="shortcut icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
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
        .row-actions { display: flex; gap: 6px; align-items: center; }
        .row-actions button {
            width: 34px; height: 34px; border-radius: 10px; display: inline-flex;
            align-items: center; justify-content: center; border: 1px solid var(--border);
            background: var(--surface); color: var(--text-muted); cursor: pointer;
            font-size: 13px; transition: all .2s;
        }
        .row-actions button:hover { background: var(--primary); color: #fff; border-color: var(--primary); }
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:100; align-items:center; justify-content:center; }
        .modal-overlay.is-open { display:flex; }
        .modal-box { background:#fff; border-radius:20px; padding:28px; width:560px; max-width:92vw; max-height:85vh; overflow-y:auto; box-shadow:0 12px 48px rgba(0,0,0,.2); }
        .modal-title { font-size:20px; font-weight:700; margin-bottom:18px; display:flex; justify-content:space-between; align-items:center; }
        .modal-title button { background:none; border:none; font-size:20px; cursor:pointer; color:var(--text-muted); }
        .modal-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .modal-actions { display:flex; gap:12px; margin-top:20px; justify-content:flex-end; }
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
                                    <th>Actions</th>
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
                                            <td>
                                                <div class="row-actions">
                                                    <button onclick="openEditCustomerModal(<?= (int)$customer['id'] ?>)" title="Edit"><i class="fa-solid fa-pen-to-square"></i></button>
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

    <!-- Edit Customer Modal -->
    <div class="modal-overlay" id="editCustomerModal">
        <div class="modal-box">
            <div class="modal-title">Edit Customer <button onclick="closeEditCustModal()">&times;</button></div>
            <form id="editCustomerForm">
                <input type="hidden" name="id" id="editCustId">
                <div class="form-group">
                    <label class="form-label">Customer Name *</label>
                    <input class="form-control" name="name" id="editCustName" required>
                </div>
                <div class="modal-grid">
                    <div class="form-group">
                        <label class="form-label">Customer Code</label>
                        <input class="form-control" name="customer_code" id="editCustCode">
                    </div>
                    <div class="form-group">
                        <label class="form-label">GST Number</label>
                        <input class="form-control" name="gst_number" id="editCustGst">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input class="form-control" name="phone" id="editCustPhone">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input class="form-control" type="email" name="email" id="editCustEmail">
                    </div>
                </div>
                <div class="form-group" style="margin-top:12px;">
                    <label class="form-label">Billing Address</label>
                    <textarea class="form-control" name="billing_address" id="editCustAddr" rows="3"></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-secondary" onclick="closeEditCustModal()">Cancel</button>
                    <button type="submit" class="btn-primary" style="width:auto;padding:12px 24px;">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const allCustomers = <?= json_encode($customers) ?>;

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('sidebar-collapsed');
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

        // Search
        document.getElementById('customerSearch').addEventListener('input', (e) => {
            const s = e.target.value.toLowerCase();
            document.querySelectorAll('#customerTableBody tr').forEach(row => {
                if (row.querySelector('.table-empty')) return;
                row.style.display = row.textContent.toLowerCase().includes(s) ? '' : 'none';
            });
        });

        // Edit modal
        async function openEditCustomerModal(id) {
            try {
                const res = await fetch('/api/customers.php?action=detail&id=' + id);
                const p = await res.json();
                if (!p.success) { alert(p.message); return; }
                const c = p.data;
                document.getElementById('editCustId').value = c.id;
                document.getElementById('editCustName').value = c.name || '';
                document.getElementById('editCustCode').value = c.customer_code || '';
                document.getElementById('editCustGst').value = c.gst_number || '';
                document.getElementById('editCustPhone').value = c.phone || '';
                document.getElementById('editCustEmail').value = c.email || '';
                document.getElementById('editCustAddr').value = c.billing_address || '';
                document.getElementById('editCustomerModal').classList.add('is-open');
            } catch (e) { alert('Unable to load customer'); }
        }
        function closeEditCustModal() {
            document.getElementById('editCustomerModal').classList.remove('is-open');
        }

        document.getElementById('editCustomerForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const fd = new FormData(e.target);
            const data = { action: 'update' };
            fd.forEach((v, k) => data[k] = v);
            const btn = e.target.querySelector('button[type="submit"]');
            btn.disabled = true;
            try {
                const res = await fetch('/api/customers.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                const p = await res.json();
                if (!p.success) { alert(p.message); return; }
                closeEditCustModal();
                window.location.reload();
            } catch (e) { alert('Unable to update customer'); }
            finally { btn.disabled = false; }
        });
    </script>
</body>
</html>
