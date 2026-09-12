<?php
// reminders.php - Reminders & Follow-up Notifications Hub with Google Calendar Cloud Sync
require_once 'auth_check.php';
require_once 'config/database.php';
require_once 'includes/commerce.php';
require_once 'includes/brand.php';
require_once 'includes/reminder_helper.php';
require_once 'includes/dynamic_modules.php';

$context = commerce_get_tenant_context();
$conn = $context['conn'];
$prefix = $context['prefix'];
$userId = (int)($context['user_id'] ?? ($_SESSION['user_id'] ?? 1));

reminder_ensure_tables($conn, $prefix);
dm_ensure_tables($conn, $prefix);

$modules = dm_fetch_active_modules($conn, $prefix);
$gcalConfig = reminder_get_google_calendar_config($conn, $prefix, $userId);
$isGcalConnected = !empty($gcalConfig) && (!empty($gcalConfig['access_token']) || !empty($gcalConfig['refresh_token']));

$v = time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(brand_page_title('Reminders Hub')) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link rel="shortcut icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <link href="/assets/css/module_manager.css?v=<?= $v ?>" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="/assets/js/toast.js?v=<?= $v ?>"></script>
    <style>
        .sticky-actions-th, .sticky-actions-td {
            position: sticky;
            right: 0;
            z-index: 2;
            background: var(--surface);
            border-left: 1px solid var(--border);
            box-shadow: -4px 0 8px rgba(0, 0, 0, 0.02);
        }
        .mm-icon-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text-muted);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all .2s ease;
            font-size: 13px;
            text-decoration: none;
        }
        .mm-icon-btn:hover {
            background: rgba(123, 94, 240, 0.08);
            color: var(--primary);
            border-color: var(--primary);
        }
        .mm-icon-btn.mm-icon-danger:hover {
            background: rgba(239, 68, 68, 0.08);
            color: #ef4444;
            border-color: rgba(239, 68, 68, 0.3);
        }
        
        .remind-stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 14px;
            margin-bottom: 20px;
        }
        .remind-stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 16px 18px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.02);
            transition: all 0.2s ease;
            cursor: pointer;
        }
        .remind-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.06);
            border-color: var(--primary);
        }
        .remind-stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        
        /* Modern Filter Tabs matching Dynamic Modules height */
        .remind-tab-bar {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 4px;
            height: 42px;
            box-sizing: border-box;
            overflow-x: auto;
        }
        .remind-tab-btn {
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 600;
            padding: 0 14px;
            height: 34px;
            border-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            transition: all 0.18s ease;
            box-sizing: border-box;
        }
        .remind-tab-btn:hover {
            color: var(--text-main);
            background: rgba(0, 0, 0, 0.03);
        }
        .remind-tab-btn.active {
            background: var(--primary);
            color: #fff;
            box-shadow: 0 2px 8px rgba(99, 102, 241, 0.25);
        }
        .remind-tab-btn .tab-badge {
            font-size: 11px;
            font-weight: 700;
            padding: 1px 6px;
            border-radius: 8px;
            background: rgba(0,0,0,0.08);
            color: inherit;
        }
        .remind-tab-btn.active .tab-badge {
            background: rgba(255, 255, 255, 0.25);
            color: #fff;
        }

        /* Reminder Table & Cards */
        .remind-table-container {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
        }
        .remind-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }
        .remind-table th {
            background: rgba(0, 0, 0, 0.02);
            padding: 12px 18px;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-muted);
            border-bottom: 1.5px solid var(--border);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .remind-table td {
            padding: 14px 18px;
            font-size: 13.5px;
            color: var(--text-main);
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }
        .remind-table tr:last-child td {
            border-bottom: none;
        }
        .remind-table tr:hover td {
            background: rgba(99, 102, 241, 0.015);
        }

        /* Channel Chips */
        .remind-chan-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 8px;
        }
        .remind-chan-wa { background: #dcfce7; color: #15803d; }
        .remind-chan-push { background: #e0e7ff; color: #4338ca; }
        .remind-chan-email { background: #fee2e2; color: #b91c1c; }

        /* Status Pills */
        .remind-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 11.5px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
        }
        .status-pending { background: #fef3c7; color: #b45309; }
        .status-sent { background: #dcfce7; color: #15803d; }
        .status-overdue { background: #fee2e2; color: #b91c1c; }
        .status-cancelled { background: #f1f5f9; color: #64748b; }

        /* Capsule Chips */
        .chip-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 12px;
            border-radius: 20px;
            background: var(--surface);
            border: 1px solid var(--border);
            color: var(--text-main);
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .chip-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: rgba(99, 102, 241, 0.05);
            transform: translateY(-1px);
        }

        /* Interactive Channel Cards */
        .channel-toggle-tile {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1.5px solid var(--border);
            background: var(--surface);
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            user-select: none;
        }
        .channel-icon-circle {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
        }
        .wa-bg { background: #dcfce7; color: #16a34a; }
        .push-bg { background: #e0e7ff; color: #4f46e5; }
        .email-bg { background: #fee2e2; color: #dc2626; }
        .channel-check-badge {
            width: 18px;
            height: 18px;
            border-radius: 50%;
            border: 1.5px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            color: transparent;
            transition: all 0.15s ease;
        }
        .active-wa { border-color: #16a34a; background: rgba(22, 163, 74, 0.04); }
        .active-wa .channel-check-badge { background: #16a34a; border-color: #16a34a; color: #fff; }
        .active-push { border-color: #4f46e5; background: rgba(79, 70, 229, 0.04); }
        .active-push .channel-check-badge { background: #4f46e5; border-color: #4f46e5; color: #fff; }
        .active-email { border-color: #dc2626; background: rgba(220, 38, 38, 0.04); }
        .active-email .channel-check-badge { background: #dc2626; border-color: #dc2626; color: #fff; }

        /* Google Calendar UI */
        .btn-gcal {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            height: 42px;
            padding: 0 16px;
            border-radius: 12px;
            background: var(--surface);
            border: 1.5px solid var(--border);
            color: var(--text-main);
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            box-sizing: border-box;
            white-space: nowrap;
            transition: all 0.2s ease;
        }
        .btn-gcal:hover {
            border-color: #ea4335;
            background: rgba(234, 67, 53, 0.04);
            color: #ea4335;
        }
        .btn-gcal.connected {
            border-color: rgba(16, 185, 129, 0.4);
            background: rgba(16, 185, 129, 0.06);
            color: #065f46;
        }
        .gcal-badge-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }
        .gcal-badge-dot.connected {
            background: #10b981;
            box-shadow: 0 0 6px #10b981;
        }
        .gcal-badge-dot.disconnected {
            background: #94a3b8;
        }

        /* Full Page & Content Scrollability */
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
        }
        .reminders-page .app-wrapper {
            height: 100vh;
            display: flex;
            overflow: hidden;
        }
        .reminders-page .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
            background: var(--bg-color);
        }
        .reminders-page .content-scroll,
        .reminders-page .content-body {
            flex: 1;
            overflow-y: auto !important;
            overflow-x: hidden;
            height: calc(100vh - 80px);
            box-sizing: border-box;
            -webkit-overflow-scrolling: touch;
        }
    </style>
</head>
<body class="reminders-page">
<div class="app-wrapper">
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleMobileSidebar()" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:90;"></div>
    <?php include 'includes/sidebar.php'; ?>
    <main class="main-content">
        <header class="topbar">
            <div class="flex items-center">
                <button class="btn-icon" onclick="toggleMobileSidebar()" style="margin-right:20px;display:none;" id="mobileToggle"><i class="fa-solid fa-bars"></i></button>
                <div class="breadcrumb">Home / <span class="current">Reminders</span></div>
            </div>
            <div class="topbar-right" style="display: flex; gap: 10px; align-items: center;">
                <button type="button" class="btn-gcal <?= $isGcalConnected ? 'connected' : '' ?>" onclick="openGoogleCalendarModal()" id="btnGcalTriggerTopbar" style="display: inline-flex; align-items: center; gap: 8px; height: 42px; border-radius: 12px; padding: 0 16px; font-weight: 600; box-sizing: border-box; white-space: nowrap; cursor: pointer;" title="Google Calendar Cloud Sync">
                    <i class="fa-brands fa-google" style="color: #ea4335; font-size: 15px;"></i>
                    <span class="gcal-btn-label"><?= $isGcalConnected ? 'Google Calendar' : 'Google Calendar' ?></span>
                    <span class="gcal-badge-dot <?= $isGcalConnected ? 'connected' : 'disconnected' ?>" id="gcalStatusDotTopbar"></span>
                </button>
                <button type="button" class="mm-btn mm-btn-outline btn-refresh-list-action" onclick="fetchRemindersList(this)" style="display: inline-flex; align-items: center; gap: 8px; padding: 12px 18px; font-weight: 600; height: 42px; border-radius: 12px; box-sizing: border-box;" title="Refresh list data">
                    <i class="fa-solid fa-rotate"></i> Refresh
                </button>
                <button type="button" class="btn-primary" onclick="openCreateReminderModal()" style="width: auto; padding: 12px 24px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; height: 42px; border-radius: 12px; box-sizing: border-box; font-weight: 600; cursor: pointer;">
                    <i class="fa-solid fa-plus"></i> New Reminder
                </button>
                <?php include 'includes/profile_pill.php'; ?>
            </div>
        </header>

        <div class="content-scroll" style="padding: 24px 30px;">
            <!-- Summary Stats Cards -->
            <div class="remind-stat-grid">
                <div class="remind-stat-card" onclick="switchTab('upcoming')">
                    <div class="remind-stat-icon" style="background: rgba(99, 102, 241, 0.1); color: var(--primary);">
                        <i class="fa-solid fa-clock"></i>
                    </div>
                    <div>
                        <div style="font-size: 22px; font-weight: 800; color: var(--text-main);" id="statUpcoming">0</div>
                        <div style="font-size: 12px; color: var(--text-muted); font-weight: 600;">Upcoming Reminders</div>
                    </div>
                </div>

                <div class="remind-stat-card" onclick="switchTab('today')">
                    <div class="remind-stat-icon" style="background: rgba(16, 185, 129, 0.1); color: #10b981;">
                        <i class="fa-solid fa-calendar-day"></i>
                    </div>
                    <div>
                        <div style="font-size: 22px; font-weight: 800; color: var(--text-main);" id="statToday">0</div>
                        <div style="font-size: 12px; color: var(--text-muted); font-weight: 600;">Due Today</div>
                    </div>
                </div>

                <div class="remind-stat-card" onclick="switchTab('overdue')">
                    <div class="remind-stat-icon" style="background: rgba(239, 68, 68, 0.1); color: #ef4444;">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </div>
                    <div>
                        <div style="font-size: 22px; font-weight: 800; color: var(--text-main);" id="statOverdue">0</div>
                        <div style="font-size: 12px; color: var(--text-muted); font-weight: 600;">Overdue Follow-ups</div>
                    </div>
                </div>

                <div class="remind-stat-card" onclick="switchTab('completed')">
                    <div class="remind-stat-icon" style="background: rgba(139, 92, 246, 0.1); color: #8b5cf6;">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <div>
                        <div style="font-size: 22px; font-weight: 800; color: var(--text-main);" id="statSent">0</div>
                        <div style="font-size: 12px; color: var(--text-muted); font-weight: 600;">Dispatched / Sent</div>
                    </div>
                </div>
            </div>

            <!-- Single-Line Controls, Filters & Google Calendar Bar -->
            <div class="mv-toolbar" style="display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 18px; flex-wrap: nowrap; overflow-x: auto; padding-bottom: 2px;">
                <!-- Left: Tab Navigation -->
                <div class="remind-tab-bar" style="flex-shrink: 0;">
                    <button type="button" class="remind-tab-btn active" data-tab="upcoming" onclick="switchTab('upcoming')">
                        <i class="fa-solid fa-clock"></i> Upcoming <span class="tab-badge" id="tabBadgeUpcoming">0</span>
                    </button>
                    <button type="button" class="remind-tab-btn" data-tab="today" onclick="switchTab('today')">
                        <i class="fa-solid fa-calendar-day"></i> Today <span class="tab-badge" id="tabBadgeToday">0</span>
                    </button>
                    <button type="button" class="remind-tab-btn" data-tab="overdue" onclick="switchTab('overdue')">
                        <i class="fa-solid fa-triangle-exclamation"></i> Overdue <span class="tab-badge" id="tabBadgeOverdue">0</span>
                    </button>
                    <button type="button" class="remind-tab-btn" data-tab="completed" onclick="switchTab('completed')">
                        <i class="fa-solid fa-circle-check"></i> Completed / Sent
                    </button>
                    <button type="button" class="remind-tab-btn" data-tab="all" onclick="switchTab('all')">
                        All
                    </button>
                </div>

                <!-- Right: Module Filter, Search & Google Calendar Settings -->
                <div style="display: flex; gap: 10px; align-items: center; flex-shrink: 0;">
                    <select id="filterModule" onchange="fetchRemindersList()" style="height: 42px; border-radius: 12px; padding: 0 14px; font-size: 13.5px; font-weight: 500; background: var(--surface); border: 1px solid var(--border); box-sizing: border-box; cursor: pointer; min-width: 170px; color: var(--text-main); outline: none;">
                        <option value="0">All Modules & General</option>
                        <option value="-1">🔔 General / Personal (No Module)</option>
                        <?php foreach ($modules as $m): ?>
                            <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <div class="mv-search" style="height: 42px; border-radius: 12px; padding: 0 14px; box-sizing: border-box; display: flex; align-items: center; gap: 8px; background: var(--surface); border: 1px solid var(--border); margin: 0;">
                        <i class="fa-solid fa-search" style="color: var(--text-muted); font-size: 13px;"></i>
                        <input type="text" id="filterSearch" placeholder="Search reminders..." oninput="debounceSearch()" style="border: none; outline: none; background: transparent; font-size: 13.5px; width: 170px; color: var(--text-main); padding: 0; height: 100%;">
                    </div>

                    <!-- Google Calendar Settings Button -->
                    <button type="button" class="btn-gcal <?= $isGcalConnected ? 'connected' : '' ?>" onclick="openGoogleCalendarModal()" id="btnGcalTrigger" title="Google Calendar Cloud Sync">
                        <i class="fa-brands fa-google" style="color: #ea4335; font-size: 14px;"></i>
                        <span id="gcalBtnText"><?= $isGcalConnected ? 'Google Calendar' : 'Google Calendar' ?></span>
                        <span class="gcal-badge-dot <?= $isGcalConnected ? 'connected' : 'disconnected' ?>" id="gcalStatusDot"></span>
                    </button>
                </div>
            </div>

            <!-- Reminders List / Table -->
            <div class="remind-table-container">
                <table class="remind-table">
                    <thead>
                        <tr>
                            <th style="width: 170px;">Module / Scope</th>
                            <th>Reminder Note & Details</th>
                            <th style="width: 200px;">Scheduled For</th>
                            <th style="width: 150px;">Channels</th>
                            <th style="width: 120px;">Status</th>
                            <th style="width: 110px; text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="remindersTableBody">
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 36px; color: var(--text-muted);">
                                <i class="fa-solid fa-spinner fa-spin" style="font-size: 20px; margin-bottom: 8px; display: block;"></i> Loading reminders...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<!-- GOOGLE CALENDAR CONNECTION & SETTINGS MODAL -->
<div class="mm-modal-overlay" id="googleCalendarModal" style="z-index: 10002; display: none;">
    <div class="mm-modal" style="width: 500px; max-width: 95vw; border-radius: 20px; box-shadow: 0 20px 40px rgba(0,0,0,0.2); overflow: hidden; display: flex; flex-direction: column; border: 1px solid var(--border);">
        <div class="mm-modal-header" style="padding: 18px 22px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; background: var(--surface);">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 40px; height: 40px; border-radius: 12px; background: rgba(234, 67, 53, 0.1); color: #ea4335; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                    <i class="fa-brands fa-google"></i>
                </div>
                <div>
                    <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: var(--text-main);">Google Calendar Sync</h3>
                    <p style="margin: 2px 0 0 0; font-size: 12px; color: var(--text-muted);">Sync reminders directly with your Gmail / Google Calendar</p>
                </div>
            </div>
            <button type="button" class="mm-modal-close" onclick="closeModal('googleCalendarModal')" style="background: none; border: none; font-size: 18px; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>
        <div class="mm-modal-body" style="padding: 24px; background: var(--surface);" id="gcalModalBody">
            <div style="text-align:center; padding: 20px; color: var(--text-muted);">
                <i class="fa-solid fa-spinner fa-spin" style="font-size:24px; margin-bottom:8px; display:block;"></i> Checking Google Calendar connection...
            </div>
        </div>
    </div>
</div>

<!-- SCHEDULE / EDIT REMINDER MODAL -->
<div class="mm-modal-overlay" id="hubReminderModal" style="z-index: 10001; display: none;">
    <div class="mm-modal" style="width: 540px; max-width: 95vw; border-radius: 20px; box-shadow: 0 20px 40px rgba(0,0,0,0.2); overflow: hidden; display: flex; flex-direction: column; border: 1px solid var(--border);">
        <div class="mm-modal-header" style="padding: 18px 22px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; background: var(--surface);">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 40px; height: 40px; border-radius: 12px; background: linear-gradient(135deg, rgba(99, 102, 241, 0.2), rgba(129, 140, 248, 0.1)); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 18px;">
                    <i class="fa-solid fa-bell"></i>
                </div>
                <div>
                    <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: var(--text-main);" id="hubModalTitle">Schedule Reminder</h3>
                    <p style="margin: 2px 0 0 0; font-size: 12px; color: var(--text-muted);" id="hubModalSubtitle">Create a multi-channel reminder or follow-up note</p>
                </div>
            </div>
            <button type="button" class="mm-modal-close" onclick="closeModal('hubReminderModal')" style="background: none; border: none; font-size: 18px; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>

        <div class="mm-modal-body" style="padding: 22px; max-height: 75vh; overflow-y: auto;">
            <input type="hidden" id="hubReminderId" value="">

            <!-- Scope / Module Picker -->
            <div style="margin-bottom: 16px;">
                <label style="font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 6px; display: block;">
                    Target Module (Optional)
                </label>
                <div style="display: flex; gap: 10px;">
                    <select id="hubModuleId" onchange="onHubModuleChange()" style="flex: 1; height: 42px; border-radius: 12px; padding: 0 12px; font-size: 13.5px; border: 1.5px solid var(--border); background: var(--surface); color: var(--text-main); outline: none;">
                        <option value="0">🔔 General / Personal (No Module)</option>
                        <?php foreach ($modules as $m): ?>
                            <option value="<?= $m['id'] ?>" data-icon="<?= htmlspecialchars($m['icon'] ?? 'fa-solid fa-cube') ?>"><?= htmlspecialchars($m['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" id="hubRecordId" placeholder="Record ID (e.g. 12)" style="width: 140px; height: 42px; border-radius: 12px; padding: 0 12px; font-size: 13.5px; border: 1.5px solid var(--border); background: var(--surface); color: var(--text-main); outline: none;">
                </div>
                <div style="font-size: 11.5px; color: var(--text-muted); margin-top: 4px;">
                    Leave blank to create a standalone personal reminder.
                </div>
            </div>

            <!-- Reminder Title / Notes -->
            <div style="margin-bottom: 16px;">
                <label style="font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 6px; display: block;">
                    Reminder Title / Notes <span style="color:#ef4444;">*</span>
                </label>
                <input type="text" id="hubTitle" placeholder="e.g., Follow up on quote approval" style="width: 100%; height: 44px; border-radius: 12px; padding: 0 14px; font-size: 14px; border: 1.5px solid var(--border); background: var(--surface); color: var(--text-main); outline: none; box-sizing: border-box;">
                
                <!-- Quick Preset Notes -->
                <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px;">
                    <span class="chip-btn" onclick="setHubQuickNote('Call back to discuss proposal')"><i class="fa-solid fa-phone"></i> Call back</span>
                    <span class="chip-btn" onclick="setHubQuickNote('Send payment reminder')"><i class="fa-solid fa-receipt"></i> Payment</span>
                    <span class="chip-btn" onclick="setHubQuickNote('Follow up on meeting notes')"><i class="fa-solid fa-handshake"></i> Follow up</span>
                    <span class="chip-btn" onclick="setHubQuickNote('Contract renewal reminder')"><i class="fa-solid fa-file-contract"></i> Renewal</span>
                </div>
            </div>

            <!-- Date & Time (From & Optional To) -->
            <div style="margin-bottom: 18px;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <!-- From Time (Required) -->
                    <div>
                        <label style="font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 6px; display: block;">
                            From Date & Time <span style="color:#ef4444;">*</span>
                        </label>
                        <div style="position: relative;">
                            <input type="text" id="hubRemindAt" placeholder="Start date & time..." style="width: 100%; height: 44px; border-radius: 12px; padding: 0 14px; padding-left: 38px; font-size: 13.5px; border: 1.5px solid var(--border); background: var(--surface); color: var(--text-main); outline: none; box-sizing: border-box; cursor: pointer;">
                            <i class="fa-solid fa-clock" style="position: absolute; left: 12px; top: 14px; color: var(--primary); font-size: 15px; pointer-events: none;"></i>
                        </div>
                    </div>

                    <!-- To Time (Optional) -->
                    <div>
                        <label style="font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 6px; display: flex; align-items: center; justify-content: space-between;">
                            <span>To Date & Time</span>
                            <span style="font-size: 11px; font-weight: 500; color: #10b981; text-transform: none;">Optional (+30m default)</span>
                        </label>
                        <div style="position: relative;">
                            <input type="text" id="hubEndAt" placeholder="End date & time (optional)..." style="width: 100%; height: 44px; border-radius: 12px; padding: 0 14px; padding-left: 38px; font-size: 13.5px; border: 1.5px solid var(--border); background: var(--surface); color: var(--text-main); outline: none; box-sizing: border-box; cursor: pointer;">
                            <i class="fa-solid fa-flag-checkered" style="position: absolute; left: 12px; top: 14px; color: #10b981; font-size: 14px; pointer-events: none;"></i>
                        </div>
                    </div>
                </div>

                <!-- Quick Timing Presets -->
                <div style="margin-top: 10px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                        <span style="font-size: 11.5px; font-weight: 600; color: var(--text-muted);">Quick Start:</span>
                        <span style="font-size: 11.5px; font-weight: 600; color: var(--text-muted);">Event Duration:</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 6px;">
                        <div style="display: flex; flex-wrap: wrap; gap: 5px;">
                            <span class="chip-btn" onclick="setHubQuickTiming('15m')">+15m</span>
                            <span class="chip-btn" onclick="setHubQuickTiming('30m')">+30m</span>
                            <span class="chip-btn" onclick="setHubQuickTiming('1h')">+1h</span>
                            <span class="chip-btn" onclick="setHubQuickTiming('tomorrow 9am')">Tmrw 9 AM</span>
                            <span class="chip-btn" onclick="setHubQuickTiming('tomorrow 3pm')">Tmrw 3 PM</span>
                        </div>
                        <div style="display: flex; flex-wrap: wrap; gap: 5px;">
                            <span class="chip-btn" onclick="setHubEndDuration(30)" title="Set 30 minutes duration" style="background: rgba(16, 185, 129, 0.08); color: #059669; border-color: rgba(16, 185, 129, 0.2);">+30m Default</span>
                            <span class="chip-btn" onclick="setHubEndDuration(60)" title="Set 1 hour duration" style="background: rgba(16, 185, 129, 0.08); color: #059669; border-color: rgba(16, 185, 129, 0.2);">+1h</span>
                            <span class="chip-btn" onclick="setHubEndDuration(120)" title="Set 2 hours duration" style="background: rgba(16, 185, 129, 0.08); color: #059669; border-color: rgba(16, 185, 129, 0.2);">+2h</span>
                            <span class="chip-btn" onclick="clearHubEndTime()" title="Clear optional end time" style="color: var(--text-muted);"><i class="fa-solid fa-xmark"></i></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Multi-Channel Delivery Selection -->
            <div style="margin-bottom: 12px;">
                <label style="font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 8px; display: block;">
                    Dispatch Channels
                </label>
                
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px;">
                    <!-- WhatsApp -->
                    <label class="channel-toggle-tile" id="hubTileWa">
                        <input type="checkbox" id="hubChanWa" style="display: none;" onchange="updateTileState('hubChanWa', 'hubTileWa', 'active-wa')">
                        <div class="channel-icon-circle wa-bg"><i class="fa-brands fa-whatsapp"></i></div>
                        <div style="flex: 1;">
                            <div style="font-size: 13px; font-weight: 700; color: var(--text-main);">WhatsApp</div>
                        </div>
                        <div class="channel-check-badge"><i class="fa-solid fa-check"></i></div>
                    </label>

                    <!-- Push Notification (FCM / Web) -->
                    <label class="channel-toggle-tile" id="hubTilePush">
                        <input type="checkbox" id="hubChanPush" checked style="display: none;" onchange="updateTileState('hubChanPush', 'hubTilePush', 'active-push')">
                        <div class="channel-icon-circle push-bg"><i class="fa-solid fa-mobile-screen"></i></div>
                        <div style="flex: 1;">
                            <div style="font-size: 13px; font-weight: 700; color: var(--text-main);">Push (Web/App)</div>
                        </div>
                        <div class="channel-check-badge"><i class="fa-solid fa-check"></i></div>
                    </label>

                    <!-- Email -->
                    <label class="channel-toggle-tile" id="hubTileEmail">
                        <input type="checkbox" id="hubChanEmail" checked style="display: none;" onchange="updateTileState('hubChanEmail', 'hubTileEmail', 'active-email')">
                        <div class="channel-icon-circle email-bg"><i class="fa-solid fa-envelope"></i></div>
                        <div style="flex: 1;">
                            <div style="font-size: 13px; font-weight: 700; color: var(--text-main);">Email</div>
                        </div>
                        <div class="channel-check-badge"><i class="fa-solid fa-check"></i></div>
                    </label>
                </div>
            </div>
        </div>

        <div class="mm-modal-footer" style="padding: 16px 22px; border-top: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; background: var(--surface);">
            <button type="button" class="mm-btn mm-btn-outline mm-btn-danger" id="btnDeleteHubReminder" style="display: none;" onclick="deleteCurrentHubReminder()">
                <i class="fa-solid fa-trash"></i> Delete
            </button>
            <div style="display: flex; gap: 8px; margin-left: auto;">
                <button type="button" class="mm-btn mm-btn-outline" onclick="closeModal('hubReminderModal')">Cancel</button>
                <button type="button" class="btn-primary" id="btnSubmitHubReminder" onclick="submitHubReminder()" style="width: auto; padding: 10px 20px; font-weight: 600;">
                    <i class="fa-solid fa-clock"></i> Set Reminder
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let hubFp = null;
let hubEndFp = null;
let hubDurationMinutes = 30; // default duration in minutes
let currentTab = 'upcoming';
let searchTimeout = null;

function toggleMobileSidebar() {
    const s = document.getElementById('sidebar');
    const o = document.getElementById('sidebarOverlay');
    if (s) s.classList.toggle('sidebar-open');
    if (o) o.style.display = s && s.classList.contains('sidebar-open') ? 'block' : 'none';
}

function syncHubEndTime(startDates) {
    if (!startDates || !startDates[0] || !hubEndFp) return;
    const startTs = startDates[0].getTime();
    const duration = (hubDurationMinutes && hubDurationMinutes > 0) ? hubDurationMinutes : 30;
    const newEnd = new Date(startTs + duration * 60000);
    hubEndFp.setDate(newEnd, false);
    hubEndFp.set('minDate', startDates[0]);
}

function initHubPicker() {
    if (document.getElementById('hubEndAt')) {
        hubEndFp = flatpickr("#hubEndAt", {
            enableTime: true,
            dateFormat: "Y-m-d H:i",
            time_24hr: true,
            minDate: "today",
            defaultDate: new Date(Date.now() + 45 * 60000),
            onChange: function(selectedDates) {
                if (selectedDates && selectedDates[0] && hubFp && hubFp.selectedDates[0]) {
                    const startTs = hubFp.selectedDates[0].getTime();
                    const endTs = selectedDates[0].getTime();
                    if (endTs > startTs) {
                        hubDurationMinutes = Math.max(5, Math.round((endTs - startTs) / 60000));
                    }
                }
            }
        });
    }

    if (document.getElementById('hubRemindAt')) {
        hubFp = flatpickr("#hubRemindAt", {
            enableTime: true,
            dateFormat: "Y-m-d H:i",
            time_24hr: true,
            minDate: "today",
            defaultDate: new Date(Date.now() + 15 * 60000),
            onChange: function(selectedDates) {
                syncHubEndTime(selectedDates);
            },
            onValueUpdate: function(selectedDates) {
                syncHubEndTime(selectedDates);
            }
        });

        // Also add input listener for manual typing
        const remindInput = document.getElementById('hubRemindAt');
        if (remindInput) {
            remindInput.addEventListener('input', () => {
                if (hubFp && hubFp.selectedDates) {
                    syncHubEndTime(hubFp.selectedDates);
                }
            });
        }
    }
}

function switchTab(tab) {
    currentTab = tab;
    document.querySelectorAll('.remind-tab-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.tab === tab);
    });
    fetchRemindersList();
}

function debounceSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        fetchRemindersList();
    }, 300);
}

function fetchRemindersList(btnTrigger) {
    let originalHtml = '';
    if (btnTrigger && btnTrigger.tagName) {
        btnTrigger.disabled = true;
        originalHtml = btnTrigger.innerHTML;
        btnTrigger.innerHTML = '<i class="fa-solid fa-rotate fa-spin"></i> Refreshing...';
    }

    const moduleId = document.getElementById('filterModule').value;
    const search = document.getElementById('filterSearch').value.trim();
    const tbody = document.getElementById('remindersTableBody');

    tbody.innerHTML = `
        <tr>
            <td colspan="6" style="text-align: center; padding: 36px; color: var(--text-muted);">
                <i class="fa-solid fa-spinner fa-spin" style="font-size: 20px; margin-bottom: 8px; display: block;"></i> Loading reminders...
            </td>
        </tr>
    `;

    fetch(`/api/reminders_api.php?action=list_all&tab=${encodeURIComponent(currentTab)}&module_id=${moduleId}&search=${encodeURIComponent(search)}`)
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; color:#ef4444; padding:24px;">${escapeHtml(res.error || 'Failed to fetch reminders.')}</td></tr>`;
                return;
            }

            // Update stats
            if (res.stats) {
                document.getElementById('statUpcoming').textContent = res.stats.count_upcoming || 0;
                document.getElementById('statToday').textContent = res.stats.count_today || 0;
                document.getElementById('statOverdue').textContent = res.stats.count_overdue || 0;
                document.getElementById('statSent').textContent = res.stats.count_sent || 0;

                const bUp = document.getElementById('tabBadgeUpcoming');
                if (bUp) bUp.textContent = res.stats.count_upcoming || 0;
                const bTd = document.getElementById('tabBadgeToday');
                if (bTd) bTd.textContent = res.stats.count_today || 0;
                const bOd = document.getElementById('tabBadgeOverdue');
                if (bOd) bOd.textContent = res.stats.count_overdue || 0;
            }

            const list = res.reminders || [];
            if (list.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 48px 20px; color: var(--text-muted);">
                            <div style="font-size: 32px; margin-bottom: 10px; opacity: 0.5;">🔔</div>
                            <div style="font-size: 15px; font-weight: 700; color: var(--text-main); margin-bottom: 4px;">No Reminders Found</div>
                            <div style="font-size: 13px;">There are no reminders matching the selected filter.</div>
                        </td>
                    </tr>
                `;
                return;
            }

            let html = '';
            const nowTs = Date.now();

            list.forEach(r => {
                const rTs = new Date(r.remind_at.replace(/-/g, '/')).getTime();
                const isPast = rTs < nowTs;

                let statusBadge = '<span class="remind-status-pill status-pending"><i class="fa-solid fa-clock"></i> Pending</span>';
                if (r.status === 'sent') {
                    statusBadge = '<span class="remind-status-pill status-sent"><i class="fa-solid fa-check"></i> Sent</span>';
                } else if (r.status === 'pending' && isPast) {
                    statusBadge = '<span class="remind-status-pill status-overdue"><i class="fa-solid fa-triangle-exclamation"></i> Overdue</span>';
                }

                let channelBadges = '';
                (r.channels_list || []).forEach(ch => {
                    if (ch === 'whatsapp') channelBadges += '<span class="remind-chan-badge remind-chan-wa"><i class="fa-brands fa-whatsapp"></i> WhatsApp</span> ';
                    if (ch === 'push') channelBadges += '<span class="remind-chan-badge remind-chan-push"><i class="fa-solid fa-mobile-screen"></i> Push</span> ';
                    if (ch === 'email') channelBadges += '<span class="remind-chan-badge remind-chan-email"><i class="fa-solid fa-envelope"></i> Email</span> ';
                });

                const rJson = JSON.stringify(r).replace(/"/g, '&quot;');
                const hasModule = Boolean(r.module_id && r.module_id > 0);
                const hasRecord = Boolean(r.module_id && r.record_id && r.record_id > 0);

                const moduleIcon = escapeHtml(r.module_icon || (hasModule ? 'fa-solid fa-cube' : 'fa-solid fa-bell'));
                const moduleName = escapeHtml(r.module_name || (hasModule ? 'Module' : 'General'));

                const recordSubtext = hasRecord ? `
                    <div style="font-size: 12px; color: var(--text-muted); display: flex; align-items: center; gap: 6px;">
                        <span>Record:</span>
                        <a href="module_record.php?module=${r.module_id}&record=${r.record_id}&view=1" style="color: var(--primary); font-weight: 600; text-decoration: none;" target="_blank">
                            #${r.record_id} <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 10px;"></i>
                        </a>
                    </div>
                ` : `
                    <div style="font-size: 11.5px; color: var(--text-muted); display: flex; align-items: center; gap: 4px;">
                        <span style="display:inline-block; width:6px; height:6px; border-radius:50%; background:#818cf8;"></span> General / Standalone
                    </div>
                `;

                const viewActionBtn = hasRecord ? `
                    <a href="module_record.php?module=${r.module_id}&record=${r.record_id}&view=1" class="mm-icon-btn" title="View Record" target="_blank">
                        <i class="fa-solid fa-eye"></i>
                    </a>
                ` : `
                    <button type="button" class="mm-icon-btn" onclick="openEditReminderModal(${rJson})" title="View / Edit Details">
                        <i class="fa-solid fa-eye"></i>
                    </button>
                `;

                const gcalSyncedBadge = r.google_event_id ? `
                    <span title="Synced to Google Calendar" style="display: inline-flex; align-items: center; gap: 3px; font-size: 11px; color: #ea4335; font-weight: 600; margin-left: 6px;">
                        <i class="fa-brands fa-google"></i>
                    </span>
                ` : '';

                const endAtDisplay = r.end_at ? `
                    <span style="color: var(--text-muted); font-size: 11.5px; font-weight: 400; margin-left: 4px;">
                        → ${escapeHtml(r.end_at.substring(11, 16) || r.end_at)}
                    </span>
                ` : '';

                html += `
                    <tr>
                        <td>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(99, 102, 241, 0.1); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 14px;">
                                    <i class="${moduleIcon}"></i>
                                </div>
                                <div>
                                    <div style="font-weight: 700; color: var(--text-main);">${moduleName}</div>
                                    ${recordSubtext}
                                </div>
                            </div>
                        </td>
                        <td>
                            <div style="font-weight: 600; color: var(--text-main); font-size: 14px; margin-bottom: 2px;">
                                ${escapeHtml(r.title)} ${gcalSyncedBadge}
                            </div>
                            <div style="font-size: 12px; color: var(--text-muted);">
                                ${escapeHtml(r.recipient_display_name || 'Assignee')}
                            </div>
                        </td>
                        <td>
                            <div style="font-weight: 600; color: var(--text-main); font-size: 13.5px; display: flex; align-items: center; gap: 5px;">
                                <i class="fa-regular fa-calendar" style="color: var(--primary);"></i>
                                ${escapeHtml(r.remind_at)} ${endAtDisplay}
                            </div>
                            <div style="font-size: 12px; margin-top: 3px;">
                                ${formatReminderRelativeTime(r.remind_at)}
                            </div>
                        </td>
                        <td>${channelBadges}</td>
                        <td>${statusBadge}</td>
                        <td class="sticky-actions-td">
                            <div style="display: flex; gap: 6px; justify-content: flex-end;">
                                ${viewActionBtn}
                                <button type="button" class="mm-icon-btn" onclick="openEditReminderModal(${rJson})" title="Edit Reminder">
                                    <i class="fa-solid fa-pencil"></i>
                                </button>
                                <button type="button" class="mm-icon-btn mm-icon-danger" onclick="deleteHubReminder(${r.id})" title="Delete Reminder">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });

            tbody.innerHTML = html;
        })
        .catch(err => {
            console.error('Error loading reminders:', err);
            tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; color:#ef4444; padding:24px;">Failed to load reminders: ${escapeHtml(err.message)}</td></tr>`;
        })
        .finally(() => {
            if (btnTrigger && btnTrigger.tagName) {
                btnTrigger.disabled = false;
                btnTrigger.innerHTML = originalHtml;
            }
        });
}

function updateTileState(checkboxId, tileId, activeClass) {
    const cb = document.getElementById(checkboxId);
    const tile = document.getElementById(tileId);
    if (!cb || !tile) return;
    tile.classList.toggle(activeClass, cb.checked);
}

function openCreateReminderModal() {
    document.getElementById('hubReminderId').value = '';
    document.getElementById('hubModalTitle').textContent = 'Schedule Reminder';
    document.getElementById('hubModalSubtitle').textContent = 'Create a multi-channel reminder or follow-up note';
    document.getElementById('hubModuleId').value = '0';
    document.getElementById('hubRecordId').value = '';
    document.getElementById('hubTitle').value = '';
    hubDurationMinutes = 30;

    const btnDelete = document.getElementById('btnDeleteHubReminder');
    if (btnDelete) btnDelete.style.display = 'none';

    const btnSubmit = document.getElementById('btnSubmitHubReminder');
    if (btnSubmit) btnSubmit.innerHTML = '<i class="fa-solid fa-clock"></i> Set Reminder';

    document.getElementById('hubChanWa').checked = false;
    document.getElementById('hubChanPush').checked = true;
    document.getElementById('hubChanEmail').checked = true;

    updateTileState('hubChanWa', 'hubTileWa', 'active-wa');
    updateTileState('hubChanPush', 'hubTilePush', 'active-push');
    updateTileState('hubChanEmail', 'hubTileEmail', 'active-email');

    if (!hubFp || !hubEndFp) initHubPicker();
    const startD = new Date(Date.now() + 15 * 60000);
    const endD = new Date(Date.now() + 45 * 60000);
    if (hubFp) hubFp.setDate(startD, false);
    if (hubEndFp) hubEndFp.setDate(endD, false);

    openModal('hubReminderModal');
}

function openEditReminderModal(r) {
    document.getElementById('hubReminderId').value = r.id;
    document.getElementById('hubModalTitle').textContent = 'Edit Scheduled Reminder';
    document.getElementById('hubModalSubtitle').textContent = `Reminder ID #${r.id}`;
    document.getElementById('hubModuleId').value = r.module_id || '0';
    document.getElementById('hubRecordId').value = r.record_id || '';
    document.getElementById('hubTitle').value = r.title || '';
    hubDurationMinutes = 30;

    const btnDelete = document.getElementById('btnDeleteHubReminder');
    if (btnDelete) btnDelete.style.display = 'inline-flex';

    const btnSubmit = document.getElementById('btnSubmitHubReminder');
    if (btnSubmit) btnSubmit.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Update Reminder';

    const channels = r.channels_list || [];
    document.getElementById('hubChanWa').checked = channels.includes('whatsapp');
    document.getElementById('hubChanPush').checked = channels.includes('push');
    document.getElementById('hubChanEmail').checked = channels.includes('email');

    updateTileState('hubChanWa', 'hubTileWa', 'active-wa');
    updateTileState('hubChanPush', 'hubTilePush', 'active-push');
    updateTileState('hubChanEmail', 'hubTileEmail', 'active-email');

    if (!hubFp || !hubEndFp) initHubPicker();
    if (hubFp && r.remind_at) {
        const startTs = new Date(r.remind_at.replace(/-/g, '/')).getTime();
        hubFp.setDate(new Date(startTs), false);

        if (r.end_at) {
            const endTs = new Date(r.end_at.replace(/-/g, '/')).getTime();
            if (endTs > startTs) {
                hubDurationMinutes = Math.max(5, Math.round((endTs - startTs) / 60000));
            }
            if (hubEndFp) hubEndFp.setDate(new Date(endTs), false);
        } else if (hubEndFp) {
            hubEndFp.setDate(new Date(startTs + 30 * 60000), false);
        }
    }

    openModal('hubReminderModal');
}

function onHubModuleChange() {
    const modId = parseInt(document.getElementById('hubModuleId').value) || 0;
    const recInput = document.getElementById('hubRecordId');
    if (modId === 0) {
        recInput.value = '';
        recInput.placeholder = 'N/A';
        recInput.disabled = true;
    } else {
        recInput.placeholder = 'Record ID (e.g. 12)';
        recInput.disabled = false;
    }
}

function setHubQuickNote(text) {
    const input = document.getElementById('hubTitle');
    if (input) input.value = text;
}

function setHubQuickTiming(preset) {
    const s = preset.toLowerCase();
    const d = new Date();

    if (s.includes('15m')) d.setMinutes(d.getMinutes() + 15);
    else if (s.includes('30m')) d.setMinutes(d.getMinutes() + 30);
    else if (s.includes('1h')) d.setHours(d.getHours() + 1);
    else if (s.includes('tomorrow') && s.includes('9')) { d.setDate(d.getDate() + 1); d.setHours(9, 0, 0, 0); }
    else if (s.includes('tomorrow') && s.includes('3')) { d.setDate(d.getDate() + 1); d.setHours(15, 0, 0, 0); }
    else d.setMinutes(d.getMinutes() + 15);

    if (hubFp) {
        hubFp.setDate(d, true);
    }
}

function setHubEndDuration(mins) {
    hubDurationMinutes = mins;
    let baseTime = new Date();
    if (hubFp && hubFp.selectedDates && hubFp.selectedDates[0]) {
        baseTime = new Date(hubFp.selectedDates[0]);
    }
    const endD = new Date(baseTime.getTime() + mins * 60000);
    if (hubEndFp) hubEndFp.setDate(endD, false);
}

function clearHubEndTime() {
    if (hubEndFp) hubEndFp.clear();
}

function submitHubReminder() {
    const editId = document.getElementById('hubReminderId').value;
    const moduleIdVal = parseInt(document.getElementById('hubModuleId').value) || 0;
    const recordIdVal = parseInt(document.getElementById('hubRecordId').value) || 0;
    const title = document.getElementById('hubTitle').value.trim();
    const remindAt = document.getElementById('hubRemindAt').value.trim();
    const endAt = document.getElementById('hubEndAt').value.trim();

    if (!title) return vyToast('Please enter a reminder note / title.', 'error');
    if (!remindAt) return vyToast('Please choose start date & time.', 'error');

    const channels = [];
    if (document.getElementById('hubChanWa').checked) channels.push('whatsapp');
    if (document.getElementById('hubChanPush').checked) channels.push('push');
    if (document.getElementById('hubChanEmail').checked) channels.push('email');
    if (channels.length === 0) return vyToast('Please select at least one notification channel.', 'error');

    const isUpdate = Boolean(editId);
    const payload = isUpdate ? {
        action: 'update',
        id: editId,
        module_id: moduleIdVal || null,
        record_id: recordIdVal || null,
        title: title,
        remind_at: remindAt,
        end_at: endAt || null,
        channels: channels,
        recipient_type: 'user'
    } : {
        action: 'create',
        module_id: moduleIdVal || null,
        record_id: recordIdVal || null,
        title: title,
        remind_at: remindAt,
        end_at: endAt || null,
        channels: channels,
        recipient_type: 'user'
    };

    const btnSubmit = document.getElementById('btnSubmitHubReminder');
    if (btnSubmit) {
        btnSubmit.disabled = true;
        btnSubmit.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Saving...`;
    }

    fetch('/api/reminders_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            if (res.google_error) {
                vyToast((isUpdate ? 'Reminder updated, but Google Calendar: ' : 'Reminder scheduled, but Google Calendar: ') + res.google_error, 'warning');
            } else {
                vyToast(isUpdate ? 'Reminder updated successfully!' : 'Reminder created successfully!', 'success');
            }
            closeModal('hubReminderModal');
            fetchRemindersList();
        } else {
            vyToast(res.error || 'Failed to save reminder', 'error');
        }
    })
    .catch(err => vyToast('Error: ' + err.message, 'error'))
    .finally(() => {
        if (btnSubmit) {
            btnSubmit.disabled = false;
            btnSubmit.innerHTML = isUpdate ? '<i class="fa-solid fa-floppy-disk"></i> Update Reminder' : '<i class="fa-solid fa-clock"></i> Set Reminder';
        }
    });
}

function deleteHubReminder(id) {
    if (!confirm('Are you sure you want to delete this reminder?')) return;
    fetch('/api/reminders_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', id: id })
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            vyToast('Reminder deleted.', 'success');
            fetchRemindersList();
        } else {
            vyToast(res.error || 'Failed to delete reminder.', 'error');
        }
    })
    .catch(err => vyToast('Error: ' + err.message, 'error'));
}

function deleteCurrentHubReminder() {
    const id = document.getElementById('hubReminderId').value;
    if (!id) return;
    deleteHubReminder(id);
    closeModal('hubReminderModal');
}

/* ──────────────────────────── GOOGLE CALENDAR MODAL & OAUTH ──────────────────────────── */

function openGoogleCalendarModal() {
    openModal('googleCalendarModal');
    loadGoogleCalendarStatus();
}

function loadGoogleCalendarStatus() {
    const body = document.getElementById('gcalModalBody');
    body.innerHTML = `
        <div style="text-align:center; padding: 24px; color: var(--text-muted);">
            <i class="fa-solid fa-spinner fa-spin" style="font-size:24px; margin-bottom:8px; display:block;"></i> Checking Google Calendar status...
        </div>
    `;

    fetch('/api/reminders_api.php?action=google_calendar_status')
        .then(r => r.json())
        .then(res => {
            const triggerBtns = [document.getElementById('btnGcalTrigger'), document.getElementById('btnGcalTriggerTopbar')];
            const statusDots = [document.getElementById('gcalStatusDot'), document.getElementById('gcalStatusDotTopbar')];

            if (res.success && res.connected && res.config) {
                const cfg = res.config;
                triggerBtns.forEach(btn => { if (btn) btn.classList.add('connected'); });
                statusDots.forEach(dot => { if (dot) dot.className = 'gcal-badge-dot connected'; });

                const avatarHtml = cfg.account_picture 
                    ? `<img src="${escapeHtml(cfg.account_picture)}" style="width: 48px; height: 48px; border-radius: 50%; object-fit: cover;">`
                    : `<div style="width: 48px; height: 48px; border-radius: 50%; background: #ea4335; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 700;">${(cfg.account_name || cfg.account_email || 'G').charAt(0).toUpperCase()}</div>`;

                body.innerHTML = `
                    <div style="background: rgba(16, 185, 129, 0.06); border: 1.5px solid rgba(16, 185, 129, 0.25); border-radius: 16px; padding: 18px; margin-bottom: 20px;">
                        <div style="display: flex; align-items: center; gap: 14px; margin-bottom: 12px;">
                            ${avatarHtml}
                            <div style="flex: 1;">
                                <div style="font-size: 15px; font-weight: 700; color: var(--text-main);">${escapeHtml(cfg.account_name || 'Google Account')}</div>
                                <div style="font-size: 13px; color: var(--text-muted);">${escapeHtml(cfg.account_email)}</div>
                            </div>
                            <span style="font-size: 11px; font-weight: 700; color: #059669; background: #d1fae5; padding: 4px 8px; border-radius: 12px; display: inline-flex; align-items: center; gap: 4px;">
                                <i class="fa-solid fa-circle-check"></i> Connected
                            </span>
                        </div>
                        <div style="font-size: 12px; color: var(--text-muted); border-top: 1px dashed rgba(16, 185, 129, 0.2); padding-top: 10px; display: flex; justify-content: space-between;">
                            <span>Connected: <strong>${escapeHtml(cfg.connected_at || 'Active')}</strong></span>
                            <span>Target: <strong>Primary Calendar</strong></span>
                        </div>
                    </div>

                    <div style="background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 16px; margin-bottom: 20px;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <div style="font-size: 14px; font-weight: 700; color: var(--text-main);">Auto-sync Reminders</div>
                                <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">Automatically push new reminders to Google Calendar</div>
                            </div>
                            <label style="position: relative; display: inline-block; width: 44px; height: 24px; margin: 0; cursor: pointer;">
                                <input type="checkbox" id="gcalToggleSync" ${cfg.sync_enabled ? 'checked' : ''} onchange="toggleGoogleCalendarSync(this.checked)" style="opacity: 0; width: 0; height: 0;">
                                <span style="position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: ${cfg.sync_enabled ? '#10b981' : '#cbd5e1'}; transition: .3s; border-radius: 24px;"></span>
                                <span style="position: absolute; content: ''; height: 18px; width: 18px; left: ${cfg.sync_enabled ? '23px' : '3px'}; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%;"></span>
                            </label>
                        </div>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <button type="button" class="btn-primary" onclick="syncAllToGoogleCalendar(this)" style="width: 100%; height: 44px; justify-content: center; font-weight: 600; display: inline-flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-arrows-rotate"></i> Sync Upcoming Reminders Now
                        </button>
                        <button type="button" class="mm-btn mm-btn-outline mm-btn-danger" onclick="disconnectGoogleCalendar(this)" style="width: 100%; height: 42px; justify-content: center; font-weight: 600; display: inline-flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-link-slash"></i> Disconnect Google Calendar
                        </button>
                    </div>
                `;
            } else {
                triggerBtns.forEach(btn => { if (btn) btn.classList.remove('connected'); });
                statusDots.forEach(dot => { if (dot) dot.className = 'gcal-badge-dot disconnected'; });

                body.innerHTML = `
                    <div style="text-align: center; padding: 10px 0 20px;">
                        <div style="width: 64px; height: 64px; border-radius: 20px; background: rgba(234, 67, 53, 0.1); color: #ea4335; display: inline-flex; align-items: center; justify-content: center; font-size: 32px; margin-bottom: 14px;">
                            <i class="fa-brands fa-google"></i>
                        </div>
                        <h4 style="margin: 0 0 6px 0; font-size: 17px; font-weight: 700; color: var(--text-main);">Connect Google Calendar</h4>
                        <p style="margin: 0 0 20px 0; font-size: 13px; color: var(--text-muted); line-height: 1.5; max-width: 380px; margin-left: auto; margin-right: auto;">
                            Link your Gmail / Google Workspace account to automatically synchronize all CRM reminders directly into your Google Calendar.
                        </p>

                        <button type="button" class="btn-primary" onclick="connectGoogleCalendar(this)" style="width: 100%; height: 46px; justify-content: center; font-size: 14.5px; font-weight: 700; background: #ea4335; display: inline-flex; align-items: center; gap: 10px; border-radius: 12px; box-shadow: 0 4px 12px rgba(234, 67, 53, 0.25);">
                            <i class="fa-brands fa-google" style="font-size: 18px;"></i> Sign in with Google / Connect Calendar
                        </button>

                        <div style="font-size: 12px; color: var(--text-muted); margin-top: 14px;">
                            <i class="fa-solid fa-lock" style="color: #10b981;"></i> Secure OAuth 2.0 connection. No passwords stored.
                        </div>
                    </div>
                `;
            }
        })
        .catch(err => {
            body.innerHTML = `<div style="color: #ef4444; padding: 20px; text-align: center;">Error loading status: ${escapeHtml(err.message)}</div>`;
        });
}

function connectGoogleCalendar(btn) {
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Redirecting to Google...`;
    }

    fetch('/api/reminders_api.php?action=google_calendar_get_auth_url')
        .then(r => r.json())
        .then(res => {
            if (res.success && res.auth_url) {
                window.location.href = res.auth_url;
            } else {
                vyToast(res.error || 'Failed to initialize Google login.', 'error');
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = `<i class="fa-brands fa-google"></i> Sign in with Google / Connect Calendar`;
                }
            }
        })
        .catch(err => {
            vyToast('Error: ' + err.message, 'error');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = `<i class="fa-brands fa-google"></i> Sign in with Google / Connect Calendar`;
            }
        });
}

function toggleGoogleCalendarSync(enabled) {
    fetch('/api/reminders_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'google_calendar_toggle_sync', enabled: enabled ? 1 : 0 })
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            vyToast(enabled ? 'Google Calendar auto-sync enabled.' : 'Google Calendar auto-sync paused.', 'success');
            loadGoogleCalendarStatus();
        } else {
            vyToast(res.error || 'Failed to update setting.', 'error');
        }
    })
    .catch(err => vyToast('Error: ' + err.message, 'error'));
}

function syncAllToGoogleCalendar(btn) {
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Syncing reminders...`;
    }

    fetch('/api/reminders_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'google_calendar_sync_all' })
    })
    .then(r => r.json())
    .then(res => {
        if (res.success && res.synced_count > 0) {
            vyToast(res.message || 'Reminders synced successfully!', 'success');
            fetchRemindersList();
        } else if (res.errors && res.errors.length > 0) {
            const errStr = res.errors.join("\n");
            if (errStr.includes('Google Calendar API has not been used') || errStr.includes('accessNotConfigured') || errStr.includes('disabled')) {
                alert("⚠️ Google Calendar API is not enabled in your Google Cloud Console project.\n\nPlease enable the 'Google Calendar API' in Google Cloud Console:\nhttps://console.developers.google.com/apis/api/calendar-json.googleapis.com/overview");
            } else if (errStr.includes('insufficientPermissions') || errStr.includes('ACCESS_TOKEN_SCOPE_INSUFFICIENT')) {
                alert("⚠️ Additional Google Calendar permission required.\n\nPlease click 'Disconnect' and then 'Sign in with Google' to grant Google Calendar permissions.");
            } else {
                alert("Google Calendar Sync Error:\n" + errStr);
            }
            vyToast(res.error || 'Google Calendar sync failed.', 'error');
        } else {
            vyToast(res.message || res.error || 'No upcoming reminders to sync.', 'info');
        }
    })
    .catch(err => vyToast('Error: ' + err.message, 'error'))
    .finally(() => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = `<i class="fa-solid fa-arrows-rotate"></i> Sync Upcoming Reminders Now`;
        }
    });
}

function disconnectGoogleCalendar(btn) {
    if (!confirm('Are you sure you want to disconnect Google Calendar? Reminders will stop syncing to your Google account.')) return;

    if (btn) {
        btn.disabled = true;
        btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Disconnecting...`;
    }

    fetch('/api/reminders_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'google_calendar_disconnect' })
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            vyToast('Google Calendar disconnected.', 'success');
            loadGoogleCalendarStatus();
            fetchRemindersList();
        } else {
            vyToast(res.error || 'Failed to disconnect.', 'error');
        }
    })
    .catch(err => vyToast('Error: ' + err.message, 'error'))
    .finally(() => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = `<i class="fa-solid fa-link-slash"></i> Disconnect Google Calendar`;
        }
    });
}

function openModal(id) {
    const m = document.getElementById(id);
    if (m) {
        m.classList.add('show');
        m.style.display = 'flex';
    }
}

function closeModal(id) {
    const m = document.getElementById(id);
    if (m) {
        m.classList.remove('show');
        m.style.display = 'none';
    }
}

function escapeHtml(text) {
    if (!text) return '';
    return text.toString()
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function formatReminderRelativeTime(dateStr) {
    if (!dateStr) return '';
    const target = new Date(dateStr.replace(/-/g, '/')).getTime();
    if (isNaN(target)) return '';
    const now = Date.now();
    const diffMs = target - now;
    const diffSec = Math.round(diffMs / 1000);
    const diffMin = Math.round(diffSec / 60);
    const diffHours = Math.round(diffMin / 60);
    const diffDays = Math.round(diffHours / 24);

    if (diffMs < 0) {
        // In the past
        const absMin = Math.abs(diffMin);
        const absHours = Math.abs(diffHours);
        const absDays = Math.abs(diffDays);

        if (absMin < 1) return '<span style="color:#ef4444;font-weight:600;"><i class="fa-solid fa-clock-rotate-left"></i> Just now</span>';
        if (absMin < 60) return `<span style="color:#ef4444;font-weight:600;"><i class="fa-solid fa-clock-rotate-left"></i> ${absMin} min${absMin === 1 ? '' : 's'} ago</span>`;
        if (absHours < 24) return `<span style="color:#ef4444;font-weight:600;"><i class="fa-solid fa-clock-rotate-left"></i> ${absHours} hour${absHours === 1 ? '' : 's'} ago</span>`;
        return `<span style="color:#64748b;font-weight:600;"><i class="fa-solid fa-clock-rotate-left"></i> ${absDays} day${absDays === 1 ? '' : 's'} ago</span>`;
    } else {
        // In the future
        if (diffMin < 1) return '<span style="color:#10b981;font-weight:600;"><i class="fa-solid fa-hourglass-start"></i> In a moment</span>';
        if (diffMin < 60) return `<span style="color:#10b981;font-weight:600;"><i class="fa-solid fa-hourglass-start"></i> In ${diffMin} min${diffMin === 1 ? '' : 's'}</span>`;
        if (diffHours < 24) return `<span style="color:#6366f1;font-weight:600;"><i class="fa-solid fa-hourglass-half"></i> In ${diffHours} hour${diffHours === 1 ? '' : 's'}</span>`;
        if (diffDays === 1) return `<span style="color:#8b5cf6;font-weight:600;"><i class="fa-solid fa-calendar-day"></i> Tomorrow</span>`;
        return `<span style="color:#64748b;font-weight:600;"><i class="fa-solid fa-calendar-days"></i> In ${diffDays} days</span>`;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    initHubPicker();
    fetchRemindersList();

    // Handle Google OAuth return params
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('google_connected') === '1') {
        vyToast('Google Calendar connected successfully! Your reminders will now sync to Google Calendar.', 'success');
        // Clean URL
        const cleanUrl = window.location.pathname;
        window.history.replaceState({}, document.title, cleanUrl);
    } else if (urlParams.get('google_error')) {
        vyToast('Google Calendar connection failed: ' + urlParams.get('google_error'), 'error');
        const cleanUrl = window.location.pathname;
        window.history.replaceState({}, document.title, cleanUrl);
    }
});
</script>
</body>
</html>
