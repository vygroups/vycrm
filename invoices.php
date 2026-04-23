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

        .stat-paid {
            color: #a78bfa;
        }

        .stat-unpaid {
            color: #f59e0b;
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

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 999px;
            padding: 7px 12px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all .2s;
            position: relative;
            user-select: none;
        }

        .status-badge:hover {
            transform: scale(1.05);
            box-shadow: 0 2px 8px rgba(0, 0, 0, .12);
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

        .status-dropdown {
            display: none;
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: var(--shadow-md);
            padding: 6px;
            z-index: 30;
            min-width: 140px;
        }

        .status-dropdown.is-open {
            display: block;
        }

        .status-dropdown button {
            display: block;
            width: 100%;
            text-align: left;
            padding: 8px 12px;
            border: none;
            background: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background .15s;
        }

        .status-dropdown button:hover {
            background: #f0f0ff;
        }

        /* Edit Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .45);
            z-index: 100;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.is-open {
            display: flex;
        }

        .modal-box {
            background: #fff;
            border-radius: 20px;
            padding: 28px;
            width: 560px;
            max-width: 92vw;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 12px 48px rgba(0, 0, 0, .2);
        }

        .modal-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title button {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: var(--text-muted);
        }

        .modal-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .modal-actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
            justify-content: flex-end;
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
                <section class="module-hero" style="padding:16px 24px; margin-bottom:16px; grid-template-columns:auto 1fr; gap:16px;">
                    <div style="display:flex; align-items:center; gap:16px;">
                        <h1 class="hero-title" style="font-size:20px; margin:0;">Sales Overview</h1>
                    </div>
                    <div class="hero-meta" style="grid-template-columns:repeat(3, auto); gap:10px;">
                        <div class="hero-stat" style="padding:10px 16px; border-radius:12px;">
                            <span style="font-size:10px;">Total Sales</span>
                            <strong style="font-size:18px; margin-top:2px;">₹<?= number_format($stats['total'], 2) ?></strong>
                        </div>
                        <div class="hero-stat" style="padding:10px 16px; border-radius:12px;">
                            <span style="font-size:10px;">Paid</span>
                            <strong class="stat-paid" style="font-size:18px; margin-top:2px;">₹<?= number_format($stats['paid'], 2) ?></strong>
                        </div>
                        <div class="hero-stat" style="padding:10px 16px; border-radius:12px;">
                            <span style="font-size:10px;">Unpaid</span>
                            <strong class="stat-unpaid" style="font-size:18px; margin-top:2px;">₹<?= number_format($stats['unpaid'], 2) ?></strong>
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
                            <input class="form-control" id="invoiceSearch" type="text"
                                placeholder="Search by invoice or customer">
                            <a href="api/export.php?type=invoices" class="btn-secondary"
                                style="padding:13px 22px;white-space:nowrap;"><i class="fa-solid fa-file-export"></i>
                                Export CSV</a>
                            <a href="invoice_create.php" class="btn-primary"
                                style="width:auto;padding:13px 24px;white-space:nowrap;"><i
                                    class="fa-solid fa-plus"></i> New Invoice</a>
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
                                            <td class="stat-paid">
                                                <?= number_format((float) ($invoice['paid_amount'] ?? 0), 2) ?>
                                            </td>
                                            <td class="stat-unpaid">
                                                <?= number_format((float) ($invoice['grand_total'] - ($invoice['paid_amount'] ?? 0)), 2) ?>
                                            </td>
                                            <td style="position:relative;">
                                                <span class="status-badge status-<?= htmlspecialchars($invoice['status']) ?>"
                                                    onclick="toggleStatusDropdown(event, this)"
                                                    data-id="<?= (int) $invoice['id'] ?>"
                                                    data-status="<?= htmlspecialchars($invoice['status']) ?>"><?= htmlspecialchars(ucfirst($invoice['status'])) ?></span>
                                                <div class="status-dropdown">
                                                    <button type="button"
                                                        onclick="updateInvoiceStatus(<?= (int) $invoice['id'] ?>, 'draft', this)">📝
                                                        Draft</button>
                                                    <button type="button"
                                                        onclick="updateInvoiceStatus(<?= (int) $invoice['id'] ?>, 'sent', this)">📤
                                                        Sent</button>
                                                    <button type="button"
                                                        onclick="updateInvoiceStatus(<?= (int) $invoice['id'] ?>, 'paid', this)">✅
                                                        Paid</button>
                                                    <button type="button"
                                                        onclick="updateInvoiceStatus(<?= (int) $invoice['id'] ?>, 'cancelled', this)">❌
                                                        Cancelled</button>
                                                </div>
                                            </td>
                                            <td><?= htmlspecialchars($invoice['invoice_date']) ?></td>
                                            <td class="actions-cell">
                                                <div class="row-menu-wrap">
                                                    <button class="menu-trigger" type="button" aria-label="Invoice actions"
                                                        aria-expanded="false">
                                                        <i class="fa-solid fa-ellipsis-vertical"></i>
                                                    </button>
                                                    <div class="row-menu">
                                                        <a href="#"
                                                            onclick="openEditInvoiceModal(<?= (int) $invoice['id'] ?>); return false;">
                                                            <i class="fa-solid fa-pen-to-square"></i> Edit Invoice
                                                        </a>
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
    <!-- Edit Invoice Modal -->
    <div class="modal-overlay" id="editInvoiceModal">
        <div class="modal-box">
            <div class="modal-title">Edit Invoice <button onclick="closeEditModal()">&times;</button></div>
            <form id="editInvoiceForm">
                <input type="hidden" name="id" id="editInvId">
                <div class="modal-grid">
                    <div class="form-group"><label class="form-label">Customer Name *</label><input class="form-control"
                            name="customer_name" id="editInvCustName" required></div>
                    <div class="form-group"><label class="form-label">Phone</label><input class="form-control"
                            name="customer_phone" id="editInvPhone"></div>
                    <div class="form-group"><label class="form-label">Email</label><input class="form-control"
                            name="customer_email" id="editInvEmail"></div>
                    <div class="form-group"><label class="form-label">Status</label>
                        <select class="form-control" name="status" id="editInvStatus">
                            <option value="draft">Draft</option>
                            <option value="sent">Sent</option>
                            <option value="paid">Paid</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Invoice Date</label><input class="form-control"
                            type="date" name="invoice_date" id="editInvDate"></div>
                    <div class="form-group"><label class="form-label">Due Date</label><input class="form-control"
                            type="date" name="due_date" id="editInvDueDate"></div>
                    <div class="form-group"><label class="form-label">Paid Amount (₹)</label><input class="form-control"
                            type="number" step="0.01" min="0" name="paid_amount" id="editInvPaid"></div>
                </div>
                <div class="form-group" style="margin-top:12px;"><label class="form-label">Billing
                        Address</label><textarea class="form-control" name="billing_address" id="editInvAddr"
                        rows="2"></textarea></div>
                <div class="form-group"><label class="form-label">Notes</label><textarea class="form-control"
                        name="notes" id="editInvNotes" rows="2"></textarea></div>
                <div class="modal-actions">
                    <button type="button" class="btn-secondary" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn-primary" style="width:auto;padding:12px 24px;">Save
                        Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('sidebar-collapsed');
        }
        function toggleMobileSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.toggle('mobile-open');
            overlay.style.display = sidebar.classList.contains('mobile-open') ? 'block' : 'none';
        }

        // Row menus
        document.querySelectorAll('.row-menu-wrap').forEach(w => {
            const tr = w.querySelector('.menu-trigger');
            const m = w.querySelector('.row-menu');
            tr.addEventListener('click', e => {
                e.stopPropagation();
                const open = m.classList.contains('is-open');
                document.querySelectorAll('.row-menu.is-open').forEach(o => o.classList.remove('is-open'));
                if (!open) m.classList.add('is-open');
            });
        });
        document.addEventListener('click', () => {
            document.querySelectorAll('.row-menu.is-open').forEach(o => o.classList.remove('is-open'));
            document.querySelectorAll('.status-dropdown.is-open').forEach(o => o.classList.remove('is-open'));
        });

        // Status dropdown
        function toggleStatusDropdown(e, badge) {
            e.stopPropagation();
            const dd = badge.nextElementSibling;
            document.querySelectorAll('.status-dropdown.is-open').forEach(o => { if (o !== dd) o.classList.remove('is-open'); });
            dd.classList.toggle('is-open');
        }

        async function updateInvoiceStatus(id, status, btn) {
            const dd = btn.closest('.status-dropdown');
            dd.classList.remove('is-open');
            const badge = dd.previousElementSibling;
            badge.style.opacity = '0.5';
            try {
                const res = await fetch('/api/invoices.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'update_status', id, status })
                });
                const p = await res.json();
                if (!p.success) { alert(p.message); return; }
                badge.textContent = status.charAt(0).toUpperCase() + status.slice(1);
                badge.className = 'status-badge status-' + status;
                badge.dataset.status = status;
            } catch (e) { alert('Unable to update status'); }
            finally { badge.style.opacity = '1'; }
        }

        // Edit modal
        async function openEditInvoiceModal(id) {
            document.querySelectorAll('.row-menu.is-open').forEach(o => o.classList.remove('is-open'));
            try {
                const res = await fetch('/api/invoices.php?action=detail&id=' + id);
                const p = await res.json();
                if (!p.success) { alert(p.message); return; }
                const inv = p.data.invoice;
                document.getElementById('editInvId').value = inv.id;
                document.getElementById('editInvCustName').value = inv.customer_name || '';
                document.getElementById('editInvPhone').value = inv.customer_phone || '';
                document.getElementById('editInvEmail').value = inv.customer_email || '';
                document.getElementById('editInvStatus').value = inv.status || 'draft';
                document.getElementById('editInvDate').value = inv.invoice_date || '';
                document.getElementById('editInvDueDate').value = inv.due_date || '';
                document.getElementById('editInvPaid').value = inv.paid_amount || '0';
                document.getElementById('editInvAddr').value = inv.billing_address || '';
                document.getElementById('editInvNotes').value = inv.notes || '';
                document.getElementById('editInvoiceModal').classList.add('is-open');
            } catch (e) { alert('Unable to load invoice'); }
        }
        function closeEditModal() { document.getElementById('editInvoiceModal').classList.remove('is-open'); }

        document.getElementById('editInvoiceForm').addEventListener('submit', async e => {
            e.preventDefault();
            const fd = new FormData(e.target);
            const data = { action: 'update' };
            fd.forEach((v, k) => data[k] = v);
            const btn = e.target.querySelector('button[type="submit"]');
            btn.disabled = true;
            try {
                const res = await fetch('/api/invoices.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data)
                });
                const p = await res.json();
                if (!p.success) { alert(p.message); return; }
                closeEditModal();
                window.location.reload();
            } catch (e) { alert('Unable to update invoice'); }
            finally { btn.disabled = false; }
        });

        // Search
        document.getElementById('invoiceSearch').addEventListener('input', e => {
            const s = e.target.value.trim().toLowerCase();
            document.querySelectorAll('#invoiceTableBody tr').forEach(row => {
                if (row.querySelector('.table-empty')) return;
                row.style.display = row.textContent.toLowerCase().includes(s) ? '' : 'none';
            });
        });
    </script>
</body>

</html>