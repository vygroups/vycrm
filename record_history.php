<?php
require_once 'auth_check.php';
require_once 'includes/commerce.php';
require_once 'includes/brand.php';
require_once 'includes/dynamic_modules.php';
require_once 'includes/reminder_helper.php';

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

// Fetch all reminders for this record
$reminders = reminder_fetch_for_record($conn, $prefix, $moduleId, $recordId);

$totalReminders = count($reminders);
$pendingRemindersCount = 0;
$sentRemindersCount = 0;
foreach ($reminders as $rem) {
    if ($rem['status'] === 'pending') $pendingRemindersCount++;
    if ($rem['status'] === 'sent') $sentRemindersCount++;
}

// Helper to generate calendar sync links
function get_calendar_links(array $rem, string $moduleName, int $recId): array {
    $title = urlencode($rem['title'] ?: ($moduleName . ' Follow-up #' . $recId));
    $desc = urlencode(($rem['description'] ? $rem['description'] . "\n\n" : '') . "CRM Record: " . $moduleName . " #" . $recId);
    $startTime = strtotime($rem['remind_at']);
    if (!$startTime) $startTime = time() + 900;
    $endTime = $startTime + 1800; // 30 minutes
    $gStart = gmdate('Ymd\THis\Z', $startTime);
    $gEnd = gmdate('Ymd\THis\Z', $endTime);
    $gcalUrl = "https://calendar.google.com/calendar/render?action=TEMPLATE&text={$title}&dates={$gStart}/{$gEnd}&details={$desc}";
    
    $icsContent = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//VY-AI CRM//EN\r\nBEGIN:VEVENT\r\nUID:reminder-" . (int)$rem['id'] . "@vycrm\r\nDTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\nDTSTART:{$gStart}\r\nDTEND:{$gEnd}\r\nSUMMARY:" . addcslashes($rem['title'] ?: 'CRM Follow-up', ",;") . "\r\nDESCRIPTION:" . addcslashes($rem['description'] ?? '', ",;") . "\r\nEND:VEVENT\r\nEND:VCALENDAR";
    $icsData = "data:text/calendar;charset=utf8," . rawurlencode($icsContent);

    return ['google' => $gcalUrl, 'ics' => $icsData];
}

$v = time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(brand_page_title('Audit Trail & Reminders - ' . $module['name'])) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <link href="/assets/css/module_manager.css?v=<?= $v ?>" rel="stylesheet">
    <style>
        .audit-stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .audit-stat-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        }
        .audit-stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        .audit-stat-val {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-main);
            line-height: 1.2;
        }
        .audit-stat-lbl {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 500;
        }
        .rem-timeline-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 14px;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .rem-timeline-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .rem-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: capitalize;
        }
        .rem-badge-pending {
            background: #fef3c7;
            color: #b45309;
            border: 1px solid #fde68a;
        }
        .rem-badge-sent {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .rem-badge-failed {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .rem-badge-cancelled {
            background: #f3f4f6;
            color: #4b5563;
            border: 1px solid #e5e7eb;
        }
        .chan-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 7px;
            border-radius: 6px;
        }
        .chan-badge-wa { background: rgba(37,211,102,0.12); color: #166534; }
        .chan-badge-push { background: rgba(59,130,246,0.12); color: #1e40af; }
        .chan-badge-email { background: rgba(139,92,246,0.12); color: #5b21b6; }
        .cal-sync-btn {
            font-size: 11px;
            font-weight: 500;
            padding: 3px 8px;
            border-radius: 6px;
            border: 1px solid var(--border);
            color: var(--text-muted);
            background: #fff;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.15s ease;
        }
        .cal-sync-btn:hover {
            background: var(--bg-hover, #f8fafc);
            color: var(--primary);
            border-color: var(--primary);
        }
    </style>
</head>
<body>
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main-content">
        <header class="topbar">
            <div class="breadcrumb"><?= htmlspecialchars($module['name']) ?> / Record #<?= $recordId ?> / <span class="current">Audit Trail & Reminders</span></div>
            <div class="topbar-right">
                <a href="module_record.php?module=<?= $moduleId ?>&record=<?= $recordId ?>&view=1" class="mm-btn"><i class="fa-solid fa-arrow-left"></i> Back to Record</a>
                <a href="module_view.php?module=<?= $moduleId ?>" class="mm-btn"><i class="fa-solid fa-list"></i> List View</a>
            </div>
        </header>
        <div class="content-scroll">
            <div class="mr-form-container" style="max-width: 1000px;">
                
                <!-- Stat Summary Row -->
                <div class="audit-stat-grid" style="margin-top: 24px;">
                    <div class="audit-stat-card">
                        <div class="audit-stat-icon" style="background: rgba(123, 94, 240, 0.1); color: var(--primary);">
                            <i class="fa-solid fa-timeline"></i>
                        </div>
                        <div>
                            <div class="audit-stat-val"><?= count($fullRecordHistory) ?></div>
                            <div class="audit-stat-lbl">Field Updates</div>
                        </div>
                    </div>
                    <div class="audit-stat-card">
                        <div class="audit-stat-icon" style="background: rgba(59, 130, 246, 0.1); color: #2563eb;">
                            <i class="fa-solid fa-bell"></i>
                        </div>
                        <div>
                            <div class="audit-stat-val"><?= $totalReminders ?></div>
                            <div class="audit-stat-lbl">Total Reminders</div>
                        </div>
                    </div>
                    <div class="audit-stat-card">
                        <div class="audit-stat-icon" style="background: rgba(245, 158, 11, 0.1); color: #d97706;">
                            <i class="fa-solid fa-clock"></i>
                        </div>
                        <div>
                            <div class="audit-stat-val"><?= $pendingRemindersCount ?></div>
                            <div class="audit-stat-lbl">Scheduled / Pending</div>
                        </div>
                    </div>
                    <div class="audit-stat-card">
                        <div class="audit-stat-icon" style="background: rgba(16, 185, 129, 0.1); color: #059669;">
                            <i class="fa-solid fa-circle-check"></i>
                        </div>
                        <div>
                            <div class="audit-stat-val"><?= $sentRemindersCount ?></div>
                            <div class="audit-stat-lbl">Dispatched / Sent</div>
                        </div>
                    </div>
                </div>

                <!-- 1. REMINDERS AUDIT & LOGS SECTION -->
                <div class="mr-block" style="margin-bottom: 24px;">
                    <div class="mr-block-header" style="display:flex; justify-content:space-between; align-items:center;">
                        <span style="display:flex; align-items:center; gap:8px;">
                            <i class="fa-solid fa-bell" style="color: #2563eb;"></i> 
                            <strong>Scheduled Reminders & Follow-up History</strong>
                        </span>
                        <span style="font-size:12px; font-weight:600; padding:3px 10px; background:rgba(37,99,235,0.1); color:#2563eb; border-radius:12px;">
                            <?= $totalReminders ?> Reminder<?= $totalReminders === 1 ? '' : 's' ?>
                        </span>
                    </div>
                    <div class="mr-block-body">
                        <?php if (empty($reminders)): ?>
                            <div style="padding: 36px 20px; text-align: center; color: var(--text-muted);">
                                <i class="fa-regular fa-bell-slash" style="font-size: 32px; color: #cbd5e1; margin-bottom: 10px; display:block;"></i>
                                No reminders have been scheduled for this record yet.
                            </div>
                        <?php else: ?>
                            <div class="reminders-list" style="margin-top: 6px;">
                                <?php foreach ($reminders as $rem): 
                                    $statusClass = 'rem-badge-' . htmlspecialchars($rem['status']);
                                    $statusLabel = ucfirst($rem['status']);
                                    if ($rem['status'] === 'pending') $statusLabel = 'Scheduled';
                                    if ($rem['status'] === 'sent') $statusLabel = 'Dispatched';

                                    $remindTime = !empty($rem['remind_at']) ? date('d M, Y \a\t h:i A', strtotime($rem['remind_at'])) : '-';
                                    $sentTime = !empty($rem['sent_at']) ? date('d M, Y \a\t h:i A', strtotime($rem['sent_at'])) : null;
                                    $createdTime = !empty($rem['created_at']) ? date('d M, Y h:i A', strtotime($rem['created_at'])) : '-';
                                    $calLinks = get_calendar_links($rem, $module['name'], $recordId);
                                ?>
                                    <div class="rem-timeline-card">
                                        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px; flex-wrap:wrap; gap:8px;">
                                            <div>
                                                <h4 style="margin:0 0 4px 0; font-size:15px; font-weight:600; color:var(--text-main);">
                                                    <?= htmlspecialchars($rem['title'] ?: 'Follow-up Reminder') ?>
                                                </h4>
                                                <div style="font-size:12px; color:var(--text-muted); display:flex; flex-wrap:wrap; align-items:center; gap:10px;">
                                                    <span><i class="fa-solid fa-clock" style="color:var(--primary);"></i> <strong>Due:</strong> <?= $remindTime ?></span>
                                                    <span>•</span>
                                                    <span><strong>Target:</strong> <?= htmlspecialchars($rem['recipient_display_name'] ?? 'Assignee') ?></span>
                                                    <span>•</span>
                                                    <span><strong>By:</strong> <?= htmlspecialchars($rem['creator_display_name'] ?? 'System') ?> (<?= $createdTime ?>)</span>
                                                </div>
                                            </div>
                                            <div style="display:flex; align-items:center; gap:8px;">
                                                <span class="rem-badge <?= $statusClass ?>">
                                                    <?php if ($rem['status'] === 'pending'): ?>
                                                        <i class="fa-solid fa-clock"></i>
                                                    <?php elseif ($rem['status'] === 'sent'): ?>
                                                        <i class="fa-solid fa-circle-check"></i>
                                                    <?php elseif ($rem['status'] === 'failed'): ?>
                                                        <i class="fa-solid fa-circle-exclamation"></i>
                                                    <?php else: ?>
                                                        <i class="fa-solid fa-ban"></i>
                                                    <?php endif; ?>
                                                    <?= $statusLabel ?>
                                                </span>
                                            </div>
                                        </div>

                                        <?php if (!empty($rem['description'])): ?>
                                            <div style="background:#f8fafc; border:1px solid #f1f5f9; padding:8px 12px; border-radius:6px; font-size:13px; color:var(--text-main); margin-bottom:10px;">
                                                <?= nl2br(htmlspecialchars($rem['description'])) ?>
                                            </div>
                                        <?php endif; ?>

                                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; padding-top:8px; border-top:1px dashed var(--border); font-size:12px;">
                                            <div style="display:flex; align-items:center; gap:6px;">
                                                <span style="color:var(--text-muted); font-weight:500;">Channels:</span>
                                                <?php 
                                                $chans = $rem['channels_list'] ?? [];
                                                if (empty($chans) && !empty($rem['channels'])) {
                                                    $chans = array_filter(array_map('trim', explode(',', $rem['channels'])));
                                                }
                                                foreach ($chans as $ch):
                                                    if ($ch === 'whatsapp'):
                                                ?>
                                                    <span class="chan-badge chan-badge-wa"><i class="fa-brands fa-whatsapp"></i> WhatsApp</span>
                                                <?php elseif ($ch === 'push'): ?>
                                                    <span class="chan-badge chan-badge-push"><i class="fa-solid fa-bell"></i> Push (Web & Mobile)</span>
                                                <?php elseif ($ch === 'email'): ?>
                                                    <span class="chan-badge chan-badge-email"><i class="fa-solid fa-envelope"></i> Email</span>
                                                <?php endif; endforeach; ?>
                                            </div>

                                            <div style="display:flex; align-items:center; gap:6px;">
                                                <?php if ($sentTime): ?>
                                                    <span style="color:#059669; font-weight:500; font-size:11px;">
                                                        <i class="fa-solid fa-paper-plane"></i> Sent at <?= $sentTime ?>
                                                    </span>
                                                <?php endif; ?>
                                                
                                                <!-- Add to System / Google Calendar -->
                                                <a href="<?= $calLinks['google'] ?>" target="_blank" rel="noopener noreferrer" class="cal-sync-btn" title="Add to Google Calendar">
                                                    <i class="fa-brands fa-google"></i> Google Cal
                                                </a>
                                                <a href="<?= $calLinks['ics'] ?>" download="reminder-<?= (int)$rem['id'] ?>.ics" class="cal-sync-btn" title="Download .ics for Apple / Outlook / Device Calendar">
                                                    <i class="fa-solid fa-calendar-plus"></i> System Cal (.ics)
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 2. FIELD CHANGE LOGS / AUDIT TRAIL -->
                <div class="mr-block">
                    <div class="mr-block-header" style="display:flex; justify-content:space-between; align-items:center;">
                        <span><i class="fa-solid fa-timeline"></i> Record Field Change Logs</span>
                        <span style="font-size:12px; font-weight:600; padding:2px 8px; background:rgba(123,94,240,0.1); color:var(--primary); border-radius:10px;">Total Updates: <?= count($fullRecordHistory) ?></span>
                    </div>
                    <div class="mr-block-body">
                        <?php if(empty($fullRecordHistory)): ?>
                            <div style="padding: 30px; text-align: center; color: var(--text-muted);">
                                No field change logs found for this record.
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
