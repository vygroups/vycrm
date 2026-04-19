<?php
// vendors.php - Vendor List Page
require_once 'auth_check.php';
require_once 'includes/commerce.php';
require_once 'includes/brand.php';

$context = commerce_get_tenant_context();
$conn = $context['conn'];
$prefix = $context['prefix'];
commerce_ensure_tables($conn, $prefix);

// Fetch vendors
$vendors = commerce_fetch_vendors($conn, $prefix);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vy CRM - Vendor List</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <style>
        .module-hero {
            background: linear-gradient(135deg, #2d1b69 0%, #5b3cc4 52%, #7b5ef0 100%);
            color: #fff; border-radius: 24px; padding: 28px;
            box-shadow: var(--shadow-lg); margin-bottom: 28px;
        }
        .hero-title { font-size: 30px; margin-bottom: 10px; color: #fff; }
        .hero-copy { color: rgba(255,255,255,.82); max-width: 620px; line-height: 1.6; }
        .module-panel { background: var(--surface); border-radius: 22px; box-shadow: var(--shadow-md); padding: 24px; }
        .panel-head { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 20px; }
        .panel-actions { display: flex; align-items: center; gap: 12px; margin-left: auto; }
        .panel-title { font-size: 24px; font-weight: 700; margin-bottom: 4px; }
        .panel-copy { color: var(--text-muted); line-height: 1.5; font-size: 14px; }
        .btn-secondary { border: 1px solid var(--border); background: var(--surface); color: var(--text-main); border-radius: 12px; padding: 13px 16px; font-weight: 600; cursor: pointer; }
        .panel-actions .form-control { width: 260px; }
        .table-empty { text-align: center; padding: 40px 20px; color: var(--text-muted); }
        /* Modal */
        .modal-backdrop { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,.45); z-index:200; justify-content:center; align-items:center; }
        .modal-backdrop.is-open { display:flex; }
        .modal-box { background:#fff; border-radius:24px; width:100%; max-width:520px; box-shadow:0 20px 60px rgba(0,0,0,.2); overflow:hidden; animation: modalIn .25s ease; }
        @keyframes modalIn { from{opacity:0;transform:translateY(20px)scale(.97)} to{opacity:1;transform:translateY(0)scale(1)} }
        .modal-header { padding:24px 28px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; }
        .modal-header h3 { margin:0; font-size:18px; font-weight:700; }
        .modal-body { padding:28px; }
        .modal-body .form-group { margin-bottom:16px; }
        .modal-footer { padding:16px 28px; border-top:1px solid var(--border); display:flex; justify-content:flex-end; gap:12px; }
        .btn-close-modal { background:none; border:none; font-size:20px; color:var(--text-muted); cursor:pointer; padding:4px; }
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
                    <div class="breadcrumb">Billing / <span class="current">Vendors</span></div>
                </div>
                <div class="topbar-right">
                    <button onclick="openModal()" class="btn-primary" style="width:auto;padding:13px 18px;"><i class="fa-solid fa-plus"></i> Add Vendor</button>
                    <div class="profile-pill">
                        <img src="/images/admin.jpg" onerror="this.src='https://ui-avatars.com/api/?name=Admin&background=7b5ef0&color=fff'" alt="Admin">
                        <span class="name"><?= htmlspecialchars($_SESSION["username"]) ?></span>
                    </div>
                </div>
            </header>
            <div class="content-scroll">
                <section class="module-hero">
                    <h1 class="hero-title">Vendor Management</h1>
                    <p class="hero-copy">Maintain your supplier list and track your trade relations in one place.</p>
                </section>

                <section class="module-panel">
                    <div class="panel-head">
                        <div>
                            <div class="panel-title">Vendors / Suppliers</div>
                            <div class="panel-copy">Manage suppliers for purchase entries.</div>
                        </div>
                        <div class="panel-actions">
                            <input class="form-control" id="vendorSearch" type="text" placeholder="Search suppliers...">
                            <button onclick="openModal()" class="btn-primary" style="width:auto;padding:13px 24px;"><i class="fa-solid fa-plus"></i> New Vendor</button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="crm-table">
                            <thead>
                                <tr>
                                    <th>Vendor</th>
                                    <th>Contact</th>
                                    <th>Address</th>
                                    <th>GSTIN</th>
                                    <th>Added On</th>
                                </tr>
                            </thead>
                            <tbody id="vendorTableBody">
                                <?php if (empty($vendors)): ?>
                                    <tr>
                                        <td colspan="5" class="table-empty">No vendors found. Add your first supplier to continue.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($vendors as $vendor): ?>
                                        <tr>
                                            <td><div class="text-bold"><?= htmlspecialchars($vendor['name']) ?></div></td>
                                            <td>
                                                <div><?= htmlspecialchars($vendor['phone'] ?? '-') ?></div>
                                                <div class="text-muted text-sm"><?= htmlspecialchars($vendor['email'] ?? '-') ?></div>
                                            </td>
                                            <td><?= htmlspecialchars($vendor['address'] ?? '-') ?></td>
                                            <td><?= htmlspecialchars($vendor['gst_number'] ?? '-') ?></td>
                                            <td><?= date('Y-m-d', strtotime($vendor['created_at'])) ?></td>
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

    <!-- Add Vendor Modal -->
    <div class="modal-backdrop" id="vendorModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3><i class="fa-solid fa-truck-field" style="color:var(--primary);margin-right:8px;"></i> Add New Vendor</h3>
                <button class="btn-close-modal" onclick="closeModal()">&times;</button>
            </div>
            <form id="vendorForm">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Vendor Name *</label>
                        <input class="form-control" type="text" name="name" placeholder="e.g. ABC Supplies Pvt Ltd" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input class="form-control" type="text" name="phone" placeholder="+91 00000 00000">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input class="form-control" type="email" name="email" placeholder="vendor@example.com">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Address</label>
                        <textarea class="form-control" name="address" rows="2" placeholder="Full address..."></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">GSTIN</label>
                        <input class="form-control" type="text" name="gst_number" placeholder="22AAAAA0000A1Z5">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn-primary" style="width:auto;padding:12px 24px;" id="vendorSubmitBtn"><i class="fa-solid fa-check"></i> Save Vendor</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            const s = document.getElementById('sidebar');
            const i = document.getElementById('toggleIcon');
            s.classList.toggle('sidebar-collapsed');
            i.classList.toggle('fa-chevron-left', !s.classList.contains('sidebar-collapsed'));
            i.classList.toggle('fa-chevron-right', s.classList.contains('sidebar-collapsed'));
        }
        function toggleMobileSidebar() {
            const s = document.getElementById('sidebar');
            const o = document.getElementById('sidebarOverlay');
            s.classList.toggle('mobile-open');
            o.style.display = s.classList.contains('mobile-open') ? 'block' : 'none';
        }

        function openModal() { document.getElementById('vendorModal').classList.add('is-open'); }
        function closeModal() { document.getElementById('vendorModal').classList.remove('is-open'); }

        document.getElementById('vendorForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const form = e.currentTarget;
            const btn = document.getElementById('vendorSubmitBtn');
            const fd = new FormData(form);
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

            try {
                const res = await fetch('/api/vendors.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        name: fd.get('name'),
                        phone: fd.get('phone'),
                        email: fd.get('email'),
                        address: fd.get('address'),
                        gst_number: fd.get('gst_number')
                    })
                });
                const data = await res.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.message || 'Failed to add vendor');
                }
            } catch (err) {
                alert('Network error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-check"></i> Save Vendor';
            }
        });

        document.getElementById('vendorSearch').addEventListener('input', (e) => {
            const q = e.target.value.toLowerCase();
            document.querySelectorAll('#vendorTableBody tr').forEach(row => {
                if (row.querySelector('.table-empty')) return;
                row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    </script>
</body>
</html>
