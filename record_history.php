<?php
require_once 'auth_check.php';
require_once 'includes/commerce.php';
require_once 'includes/brand.php';
require_once 'includes/dynamic_modules.php';

$context = commerce_get_tenant_context();
$conn = $context['conn'];
$prefix = $context['prefix'];

$moduleId = (int)($_GET['module'] ?? 0);
$recordId = (int)($_GET['record'] ?? 0);

if (!$moduleId || !$recordId) { header('Location: module_manager.php'); exit; }

$module = dm_fetch_module_full($conn, $prefix, $moduleId);
if (!$module) { header('Location: module_manager.php'); exit; }

// Fetch full audit log
$histStmt = $conn->prepare("
    SELECT h.*, u.username, u.first_name, u.last_name, f.label as field_label 
    FROM {$prefix}module_record_history h
    LEFT JOIN users u ON u.id = h.changed_by
    LEFT JOIN {$prefix}module_fields f ON f.id = h.field_id
    JOIN {$prefix}module_records r ON r.id = h.record_id
    WHERE h.record_id = ?
      AND ABS(TIMESTAMPDIFF(SECOND, h.changed_at, r.created_at)) > 2
    ORDER BY h.changed_at DESC
");
$histStmt->execute([$recordId]);
$fullRecordHistory = $histStmt->fetchAll(PDO::FETCH_ASSOC);

$v = time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(brand_page_title('Audit Trail - ' . $module['name'])) ?></title>
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
            <div class="breadcrumb"><?= htmlspecialchars($module['name']) ?> / Record #<?= $recordId ?> / <span class="current">Audit Trail</span></div>
            <div class="topbar-right">
                <a href="module_record.php?module=<?= $moduleId ?>&record=<?= $recordId ?>&view=1" class="mm-btn"><i class="fa-solid fa-arrow-left"></i> Back to Record</a>
                <a href="module_view.php?module=<?= $moduleId ?>" class="mm-btn"><i class="fa-solid fa-list"></i> List View</a>
            </div>
        </header>
        <div class="content-scroll">
            <div class="mr-form-container">
                <div class="mr-block" style="margin-top: 28px;">
                    <div class="mr-block-header" style="display:flex; justify-content:space-between; align-items:center;">
                        <span><i class="fa-solid fa-timeline"></i> Record Audit Trail / Change Logs</span>
                        <span style="font-size:12px; font-weight:600; padding:2px 8px; background:rgba(123,94,240,0.1); color:var(--primary); border-radius:10px;">Total Updates: <?= count($fullRecordHistory) ?></span>
                    </div>
                    <div class="mr-block-body">
                        <?php if(empty($fullRecordHistory)): ?>
                            <div style="padding: 30px; text-align: center; color: var(--text-muted);">
                                No change logs found for this record.
                            </div>
                        <?php else: ?>
                            <div class="audit-timeline" style="position:relative; padding-left:24px; border-left:2px solid var(--border); margin: 10px 0;">
                                <?php foreach ($fullRecordHistory as $log): 
                                    $displayName = trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? ''));
                                    $userDisplay = $displayName ?: ($log['username'] ?? 'System/Unknown');
                                    $dateDisplay = date('d M, Y H:i', strtotime($log['changed_at']));
                                ?>
                                    <div class="timeline-item" style="position:relative; margin-bottom:20px;">
                                        <div class="timeline-dot" style="position:absolute; left:-31px; top:4px; width:12px; height:12px; border-radius:50%; background:var(--primary); border:2px solid #fff;"></div>
                                        <div class="timeline-meta" style="font-size:12px; color:var(--text-muted); margin-bottom:4px; display:flex; align-items:center; gap:8px;">
                                            <strong><?= htmlspecialchars($userDisplay) ?></strong>
                                            <span>•</span>
                                            <span><?= $dateDisplay ?></span>
                                        </div>
                                        <div class="timeline-content" style="font-size:14px; color:var(--text-main); background:#fcfcfd; padding:10px 14px; border-radius:8px; border:1px solid var(--border);">
                                            Updated field <span style="font-weight:600; color:var(--primary);"><?= htmlspecialchars($log['field_label'] ?: 'Unknown Field') ?></span>:
                                            <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-top:6px; font-size:13px;">
                                                <span style="text-decoration:line-through; color:#ef4444; background:rgba(239,68,68,0.08); padding:2px 6px; border-radius:4px; font-family:monospace;"><?= htmlspecialchars($log['old_value'] !== '' ? $log['old_value'] : '(empty)') ?></span>
                                                <i class="fa-solid fa-arrow-right" style="color:var(--text-muted); font-size:12px;"></i>
                                                <span style="color:#10b981; background:rgba(16,185,129,0.08); padding:2px 6px; border-radius:4px; font-weight:600; font-family:monospace;"><?= htmlspecialchars($log['new_value'] !== '' ? $log['new_value'] : '(empty)') ?></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('sidebar-collapsed'); }
</script>
</body>
</html>
