<?php
require_once 'auth_check.php';
require_once 'includes/commerce.php';
require_once 'includes/brand.php';
require_once 'includes/dynamic_modules.php';

$context = commerce_get_tenant_context();
$conn = $context['conn'];
$prefix = $context['prefix'];
dm_ensure_tables($conn, $prefix);
commerce_ensure_tables($conn, $prefix);

$moduleId = (int)($_GET['module'] ?? 0);
if (!$moduleId) { header('Location: module_manager.php'); exit; }

$module = dm_fetch_module_full($conn, $prefix, $moduleId);
if (!$module) { header('Location: module_manager.php'); exit; }

$search = $_GET['search'] ?? '';
$data = dm_fetch_records($conn, $prefix, $moduleId, $search ?: null);
$fields = $data['fields'];
$records = $data['records'];
$total = $data['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(brand_page_title($module['name'])) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <link href="/assets/css/module_manager.css?v=<?= $v ?>" rel="stylesheet">
</head>
<body>
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main-content">
        <header class="topbar">
            <div class="breadcrumb">Modules / <span class="current"><?= htmlspecialchars($module['name']) ?></span></div>
            <div class="topbar-right">
                <a href="module_record.php?module=<?= $moduleId ?>" class="btn-primary" style="width:auto;padding:12px 24px;text-decoration:none;display:inline-flex;align-items:center;gap:8px;">
                    <i class="fa-solid fa-plus"></i> New Record
                </a>
            </div>
        </header>
        <div class="content-scroll">
            <div class="mv-container">
                <div class="mv-toolbar">
                    <form method="GET" class="mv-search">
                        <input type="hidden" name="module" value="<?= $moduleId ?>">
                        <i class="fa-solid fa-search"></i>
                        <input type="text" name="search" placeholder="Search records..." value="<?= htmlspecialchars($search) ?>">
                    </form>
                    <div style="display:flex;gap:10px;align-items:center;">
                        <span class="text-muted text-sm"><?= $total ?> record<?= $total !== 1 ? 's' : '' ?></span>
                        <a href="module_manager.php?edit=<?= $moduleId ?>" class="mm-btn mm-btn-sm mm-btn-outline"><i class="fa-solid fa-cog"></i> Configure</a>
                    </div>
                </div>

                <div class="table-panel">
                    <div class="table-responsive">
                        <table class="crm-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <?php foreach($fields as $f): ?>
                                    <th><?= htmlspecialchars($f['label']) ?></th>
                                    <?php endforeach; ?>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($records)): ?>
                                <tr><td colspan="<?= count($fields) + 3 ?>" style="text-align:center;padding:40px;color:var(--text-muted);">No records found. <a href="module_record.php?module=<?= $moduleId ?>" style="color:var(--primary);font-weight:600;">Create one</a></td></tr>
                                <?php else: ?>
                                <?php foreach($records as $i => $rec): ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <?php foreach($fields as $f): ?>
                                    <td><?php
                                        $val = $rec['values'][(int)$f['id']] ?? '';
                                        if ($f['field_type'] === 'checkbox') {
                                            echo $val ? '<i class="fa-solid fa-check" style="color:#10b981;"></i>' : '<i class="fa-solid fa-xmark" style="color:var(--text-muted);"></i>';
                                        } elseif ($f['field_type'] === 'attachment') {
                                            echo $val ? '<i class="fa-solid fa-paperclip" style="color:var(--primary);"></i> File' : '-';
                                        } elseif ($f['field_type'] === 'multi_picker') {
                                            $decoded = json_decode($val, true);
                                            echo is_array($decoded) ? htmlspecialchars(implode(', ', $decoded)) : htmlspecialchars($val);
                                        } else {
                                            $display = mb_strlen($val) > 50 ? mb_substr($val, 0, 50) . '…' : $val;
                                            echo htmlspecialchars($display ?: '-');
                                        }
                                    ?></td>
                                    <?php endforeach; ?>
                                    <td class="text-muted text-sm"><?= date('d M Y', strtotime($rec['created_at'])) ?></td>
                                    <td>
                                        <div style="display:flex;gap:4px;">
                                            <a href="module_record.php?module=<?= $moduleId ?>&record=<?= $rec['id'] ?>" class="mm-icon-btn" title="Edit"><i class="fa-solid fa-pencil"></i></a>
                                            <button class="mm-icon-btn mm-icon-danger" onclick="deleteRecord(<?= $rec['id'] ?>)" title="Delete"><i class="fa-solid fa-trash"></i></button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<script>
function deleteRecord(id) {
    if (!confirm('Delete this record?')) return;
    fetch('/api/modules.php', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'delete_record', id})
    }).then(r => r.json()).then(r => { if (r.success) location.reload(); else alert(r.error); });
}
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('sidebar-collapsed'); }
</script>
</body>
</html>
