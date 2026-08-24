<?php
// calls.php - Call Center & Mobile Calls Dashboard
require_once 'auth_check.php';
require_once 'config/database.php';
require_once 'includes/commerce.php';
require_once 'includes/brand.php';
require_once 'includes/calls_helper.php';

$context = commerce_get_tenant_context();
$conn = $context['conn'];
$prefix = $context['prefix'];
$userId = $context['user_id'] ?? null;
$username = $context['username'] ?? 'User';

calls_ensure_tables($conn, $prefix);

$callsEnabled = dm_get_system_setting($conn, $prefix, 'calls_enabled', '1') === '1';
$storageConfig = calls_get_storage_config($conn, $prefix, $userId);

// Fetch team members / agents for filter
$stmt = $conn->query("SELECT id, username, first_name, last_name FROM {$prefix}users ORDER BY first_name ASC");
$agents = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(brand_page_title('Mobile Calls & Recordings')) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link rel="shortcut icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <script src="/assets/js/toast.js?v=<?= $v ?>"></script>
    <style>
        .calls-hero {
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #312e81 100%);
            color: #fff;
            border-radius: 24px;
            padding: 26px 30px;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.15);
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        .calls-hero-title {
            font-size: 26px;
            font-weight: 800;
            margin-bottom: 6px;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .calls-hero-subtitle {
            color: rgba(255, 255, 255, 0.8);
            font-size: 13.5px;
            max-width: 600px;
            line-height: 1.5;
        }
        .hero-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .hero-btn {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.25);
            color: #fff;
            padding: 10px 18px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .hero-btn:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-1px);
            color: #fff;
        }
        .hero-btn-primary {
            background: #6366f1;
            border-color: #818cf8;
        }
        .hero-btn-primary:hover {
            background: #4f46e5;
        }

        /* KPI Stat Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
            margin-bottom: 24px;
        }
        .kpi-card {
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: 18px;
            padding: 18px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: var(--shadow-sm);
            transition: all 0.2s ease;
        }
        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        .kpi-icon {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .kpi-total .kpi-icon { background: rgba(99, 102, 241, 0.12); color: #6366f1; }
        .kpi-incoming .kpi-icon { background: rgba(16, 185, 129, 0.12); color: #10b981; }
        .kpi-outgoing .kpi-icon { background: rgba(14, 165, 233, 0.12); color: #0ea5e9; }
        .kpi-missed .kpi-icon { background: rgba(239, 68, 68, 0.12); color: #ef4444; }
        .kpi-duration .kpi-icon { background: rgba(245, 158, 11, 0.12); color: #f59e0b; }
        .kpi-recordings .kpi-icon { background: rgba(168, 85, 247, 0.12); color: #a855f7; }

        .kpi-value {
            font-size: 22px;
            font-weight: 800;
            color: var(--text);
            line-height: 1.2;
        }
        .kpi-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 2px;
        }

        /* Filter Panel */
        .filter-panel {
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: 18px;
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }
        .filter-group {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .filter-select, .filter-input {
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: 10px;
            padding: 8px 12px;
            font-size: 13px;
            color: var(--text);
            outline: none;
            transition: border-color 0.2s;
        }
        .filter-select:focus, .filter-input:focus {
            border-color: var(--primary);
        }

        /* Badges */
        .call-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: 0.3px;
        }
        .call-badge.incoming { background: rgba(16, 185, 129, 0.12); color: #10b981; }
        .call-badge.outgoing { background: rgba(14, 165, 233, 0.12); color: #0ea5e9; }
        .call-badge.missed { background: rgba(239, 68, 68, 0.12); color: #ef4444; }
        .call-badge.rejected { background: rgba(100, 116, 139, 0.14); color: #64748b; }
        .call-badge.blocked { background: rgba(234, 88, 12, 0.12); color: #ea580c; }

        .storage-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 2px 8px;
            border-radius: 6px;
            font-size: 10.5px;
            font-weight: 600;
            background: var(--surface-muted, #f1f5f9);
            color: var(--text-muted);
            border: 1px solid var(--border);
        }
        .storage-pill.google_drive { background: rgba(66, 133, 244, 0.1); color: #2563eb; border-color: rgba(66, 133, 244, 0.2); }
        .storage-pill.s3 { background: rgba(245, 158, 11, 0.1); color: #d97706; border-color: rgba(245, 158, 11, 0.2); }
        .storage-pill.cloudflare_r2 { background: rgba(249, 115, 22, 0.1); color: #ea580c; border-color: rgba(249, 115, 22, 0.2); }

        /* Audio Player Mini */
        .audio-player-wrapper {
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--surface-muted, #f8fafc);
            padding: 6px 12px;
            border-radius: 12px;
            border: 1px solid var(--border);
            max-width: 320px;
        }
        .play-btn {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #6366f1;
            color: #fff;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 11px;
            transition: background 0.2s;
        }
        .play-btn:hover {
            background: #4f46e5;
        }
        .audio-slider {
            flex: 1;
            height: 4px;
            border-radius: 2px;
            accent-color: #6366f1;
            cursor: pointer;
        }
        .audio-timer {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            min-width: 34px;
        }

        /* Modals */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }
        .modal-overlay.is-open {
            display: flex;
        }
        .modal-box {
            background: var(--surface);
            border-radius: 20px;
            padding: 26px;
            width: 580px;
            max-width: 94vw;
            max-height: 88vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
            border: 1.5px solid var(--border);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .modal-title {
            font-size: 18px;
            font-weight: 800;
            color: var(--text);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 18px;
            color: var(--text-muted);
            cursor: pointer;
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
                    <div class="breadcrumb">Home / Communications / <span class="current">Mobile Calls & Recordings</span></div>
                </div>
                <div class="topbar-right">
                    <button class="btn-primary" onclick="openCreateCallModal()" style="padding: 10px 18px; border-radius: 12px; font-weight: 700; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; background: #10b981; border: none; box-shadow: 0 4px 14px rgba(16, 185, 129, 0.3);">
                        <i class="fa-solid fa-plus"></i> Log Call
                    </button>
                    <button class="btn-secondary" onclick="openSyncModal()" style="padding: 10px 18px; border-radius: 12px; font-weight: 600; font-size: 13px; display: inline-flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-mobile-screen-button"></i> Mobile Sync
                    </button>
                    <a href="call_settings.php" class="btn-secondary" style="padding: 10px 16px; border-radius: 12px; font-weight: 600; font-size: 13px; display: inline-flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-gear"></i> Settings
                    </a>
                    <?php include 'includes/profile_pill.php'; ?>
                </div>
            </header>

            <div class="content-body" style="padding: 24px 30px;">
                <!-- Hero Banner -->
                <div class="hero-banner">
                    <div class="hero-content">
                        <h2><i class="fa-solid fa-phone-volume" style="margin-right: 8px; color: #a5b4fc;"></i> Calls & Voice Recordings</h2>
                        <p>Track incoming & outgoing client communications, stream call audio recordings, update outcomes, and convert prospects directly to CRM Leads.</p>
                        <div class="hero-badge">
                            <span class="live-pulse"></span> Auto-Sync: Real-Time Mobile &amp; CRM Unified
                        </div>
                    </div>
                    <div class="hero-actions">
                        <button class="hero-btn hero-btn-primary" onclick="openCreateCallModal()">
                            <i class="fa-solid fa-plus"></i> + Manually Log Call
                        </button>
                        <button class="hero-btn hero-btn-secondary" onclick="openUploadModal()">
                            <i class="fa-solid fa-arrow-up-from-bracket"></i> Upload Recording
                        </button>
                    </div>
                </div>

                <!-- KPI Statistics -->
                <div class="stats-grid" id="statsGrid">
                    <div class="kpi-card kpi-total">
                        <div class="kpi-icon"><i class="fa-solid fa-headset"></i></div>
                        <div>
                            <div class="kpi-value" id="statTotal">--</div>
                            <div class="kpi-label">Total Calls</div>
                        </div>
                    </div>
                    <div class="kpi-card kpi-incoming">
                        <div class="kpi-icon"><i class="fa-solid fa-arrow-down-left"></i></div>
                        <div>
                            <div class="kpi-value" id="statIncoming">--</div>
                            <div class="kpi-label">Incoming</div>
                        </div>
                    </div>
                    <div class="kpi-card kpi-outgoing">
                        <div class="kpi-icon"><i class="fa-solid fa-arrow-up-right"></i></div>
                        <div>
                            <div class="kpi-value" id="statOutgoing">--</div>
                            <div class="kpi-label">Outgoing</div>
                        </div>
                    </div>
                    <div class="kpi-card kpi-missed">
                        <div class="kpi-icon"><i class="fa-solid fa-phone-slash"></i></div>
                        <div>
                            <div class="kpi-value" id="statMissed">--</div>
                            <div class="kpi-label">Missed / Rejected</div>
                        </div>
                    </div>
                    <div class="kpi-card kpi-duration">
                        <div class="kpi-icon"><i class="fa-solid fa-clock"></i></div>
                        <div>
                            <div class="kpi-value" id="statDuration">--</div>
                            <div class="kpi-label">Talk Time</div>
                        </div>
                    </div>
                    <div class="kpi-card kpi-recordings">
                        <div class="kpi-icon"><i class="fa-solid fa-microphone-lines"></i></div>
                        <div>
                            <div class="kpi-value" id="statRecordings">--</div>
                            <div class="kpi-label">Recordings</div>
                        </div>
                    </div>
                </div>

                <!-- Filters & Actions -->
                <div class="filter-panel">
                    <div class="filter-group">
                        <input type="text" id="searchInput" class="filter-input" placeholder="Search phone, name, notes..." style="width: 240px;">
                        
                        <select id="typeFilter" class="filter-select">
                            <option value="">All Call Types</option>
                            <option value="incoming">Incoming</option>
                            <option value="outgoing">Outgoing</option>
                            <option value="missed">Missed</option>
                            <option value="rejected">Rejected</option>
                        </select>

                        <select id="agentFilter" class="filter-select">
                            <option value="">All Agents / Users</option>
                            <?php foreach ($agents as $ag): ?>
                                <option value="<?= $ag['id'] ?>"><?= htmlspecialchars($ag['first_name'] ? ($ag['first_name'] . ' ' . $ag['last_name']) : $ag['username']) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <select id="datePreset" class="filter-select">
                            <option value="all">All Dates</option>
                            <option value="today" selected>Today</option>
                            <option value="yesterday">Yesterday</option>
                            <option value="this_week">This Week</option>
                            <option value="this_month">This Month</option>
                            <option value="custom">Custom Range</option>
                        </select>

                        <div id="customDateRange" style="display: none; align-items: center; gap: 6px;">
                            <input type="date" id="dateFrom" class="filter-input" style="padding: 6px 10px;">
                            <span style="color: var(--text-muted);">-</span>
                            <input type="date" id="dateTo" class="filter-input" style="padding: 6px 10px;">
                        </div>

                        <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--text); cursor: pointer; user-select: none; margin-left: 8px;">
                            <input type="checkbox" id="hasRecordingFilter">
                            <span>With Audio Only</span>
                        </label>
                    </div>

                    <div class="filter-group">
                        <button class="btn-secondary" onclick="loadCalls(1)" style="padding: 8px 14px; border-radius: 10px; font-size: 13px;">
                            <i class="fa-solid fa-arrows-rotate"></i> Refresh
                        </button>
                    </div>
                </div>

                <!-- Call Records Table -->
                <section class="module-panel" style="padding: 0; overflow: hidden; border-radius: 20px;">
                    <div class="table-responsive">
                        <table class="crm-table" style="margin: 0; width: 100%;">
                            <thead>
                                <tr style="background: var(--surface-muted, #f8fafc); border-bottom: 1.5px solid var(--border);">
                                    <th style="padding: 14px 18px;">Caller & Contact</th>
                                    <th>Type</th>
                                    <th>Agent / User</th>
                                    <th>Start Time</th>
                                    <th>Duration</th>
                                    <th>Recording File</th>
                                    <th>Outcome / Notes</th>
                                    <th style="text-align: right; padding-right: 18px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="callsTableBody">
                                <tr>
                                    <td colspan="8" class="table-empty" style="padding: 40px; text-align: center;">
                                        <i class="fa-solid fa-spinner fa-spin" style="font-size: 24px; color: var(--primary);"></i>
                                        <div style="margin-top: 10px;">Loading call records...</div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div id="paginationWrap" style="display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; border-top: 1px solid var(--border); background: var(--surface);">
                        <div id="paginationInfo" style="font-size: 13px; color: var(--text-muted);">Showing 0 of 0 calls</div>
                        <div id="paginationButtons" style="display: flex; gap: 8px;"></div>
                    </div>
                </section>
            </div>
        </main>
    </div>

    <!-- Edit Notes & Outcome Modal -->
    <div class="modal-overlay" id="editNotesModal">
        <div class="modal-box">
            <div class="modal-header">
                <h4 class="modal-title"><i class="fa-solid fa-pen-to-square"></i> Call Remarks & Outcome</h4>
                <button class="modal-close" onclick="closeModal('editNotesModal')">&times;</button>
            </div>
            <form id="editNotesForm" onsubmit="saveCallNotes(event)">
                <input type="hidden" id="editCallId">
                <div style="margin-bottom: 14px;">
                    <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Caller / Contact</label>
                    <input type="text" id="editCallerInfo" class="filter-input" style="width: 100%; box-sizing: border-box;" readonly>
                </div>
                <div style="margin-bottom: 14px;">
                    <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Call Outcome</label>
                    <select id="editOutcome" class="filter-select" style="width: 100%; box-sizing: border-box;">
                        <option value="">-- Select Outcome --</option>
                        <option value="Interested">Interested / Hot Lead</option>
                        <option value="Callback Requested">Callback Requested</option>
                        <option value="Requirement Gathered">Requirement Gathered</option>
                        <option value="Deal Closed">Deal Closed</option>
                        <option value="Follow-up Needed">Follow-up Needed</option>
                        <option value="Not Interested">Not Interested</option>
                        <option value="Wrong Number">Wrong Number / Invalid</option>
                        <option value="No Answer">No Answer / Busy</option>
                    </select>
                </div>
                <div style="margin-bottom: 18px;">
                    <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Notes / Conversation Summary</label>
                    <textarea id="editNotes" class="filter-input" rows="4" style="width: 100%; box-sizing: border-box; resize: vertical;" placeholder="Add discussion points, customer requirements, follow-up dates..."></textarea>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn-secondary" onclick="closeModal('editNotesModal')">Cancel</button>
                    <button type="submit" class="btn-primary" style="padding: 10px 20px; border-radius: 10px;">Save Notes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Upload Recording Audio File Modal -->
    <div class="modal-overlay" id="uploadRecordingModal">
        <div class="modal-box">
            <div class="modal-header">
                <h4 class="modal-title"><i class="fa-solid fa-cloud-arrow-up"></i> Upload Call Recording Audio</h4>
                <button class="modal-close" onclick="closeModal('uploadRecordingModal')">&times;</button>
            </div>
            <form id="uploadRecordingForm" onsubmit="uploadRecordingAudio(event)">
                <div style="margin-bottom: 14px;">
                    <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Select Call Record (Optional)</label>
                    <select id="uploadCallSelect" class="filter-select" style="width: 100%; box-sizing: border-box;">
                        <option value="">-- Upload as standalone / Match automatically --</option>
                    </select>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Caller Phone Number</label>
                        <input type="text" id="uploadCallerNumber" class="filter-input" placeholder="+919876543210" style="width: 100%; box-sizing: border-box;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Call Date & Time</label>
                        <input type="datetime-local" id="uploadCallTime" class="filter-input" style="width: 100%; box-sizing: border-box;">
                    </div>
                </div>
                <div style="margin-bottom: 18px;">
                    <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Audio File (.mp3, .m4a, .wav, .aac, .amr)</label>
                    <input type="file" id="uploadAudioFile" accept="audio/*" class="filter-input" style="width: 100%; box-sizing: border-box;" required>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn-secondary" onclick="closeModal('uploadRecordingModal')">Cancel</button>
                    <button type="submit" id="btnUploadSubmit" class="btn-primary" style="padding: 10px 20px; border-radius: 10px;">
                        <i class="fa-solid fa-cloud-arrow-up"></i> Upload & Link
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Manually Log Call Modal -->
    <div class="modal-overlay" id="createCallModal">
        <div class="modal-box">
            <div class="modal-header">
                <h4 class="modal-title"><i class="fa-solid fa-phone-plus" style="color: #10b981;"></i> Manually Log a Call</h4>
                <button class="modal-close" onclick="closeModal('createCallModal')">&times;</button>
            </div>
            <form id="createCallForm" onsubmit="saveManualCall(event)">
                <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 12px; margin-bottom: 14px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Caller / Phone Number *</label>
                        <input type="text" id="manualPhone" class="filter-input" placeholder="+91 98765 43210" style="width: 100%; box-sizing: border-box;" required>
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Contact / Customer Name</label>
                        <input type="text" id="manualName" class="filter-input" placeholder="e.g. John Doe" style="width: 100%; box-sizing: border-box;">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Call Direction / Type *</label>
                        <select id="manualType" class="filter-select" style="width: 100%; box-sizing: border-box;">
                            <option value="outgoing">Outgoing Call</option>
                            <option value="incoming">Incoming Call</option>
                            <option value="missed">Missed Call</option>
                            <option value="rejected">Rejected / Busy</option>
                        </select>
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">SIM / Line</label>
                        <select id="manualSim" class="filter-select" style="width: 100%; box-sizing: border-box;">
                            <option value="SIM 1">SIM 1</option>
                            <option value="SIM 2">SIM 2</option>
                            <option value="Office Line">Office Line</option>
                        </select>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 12px; margin-bottom: 14px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Call Date & Time</label>
                        <input type="datetime-local" id="manualTime" class="filter-input" style="width: 100%; box-sizing: border-box;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Duration (Seconds)</label>
                        <input type="number" id="manualDuration" class="filter-input" placeholder="e.g. 120" style="width: 100%; box-sizing: border-box;" min="0">
                    </div>
                </div>

                <div style="margin-bottom: 14px;">
                    <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Call Outcome / Status</label>
                    <select id="manualOutcome" class="filter-select" style="width: 100%; box-sizing: border-box;">
                        <option value="">-- Select Outcome --</option>
                        <option value="Interested">Interested / Hot Lead</option>
                        <option value="Callback Requested">Callback Requested</option>
                        <option value="Requirement Gathered">Requirement Gathered</option>
                        <option value="Deal Closed">Deal Closed</option>
                        <option value="Follow-up Needed">Follow-up Needed</option>
                        <option value="Not Interested">Not Interested</option>
                        <option value="Wrong Number">Wrong Number / Invalid</option>
                        <option value="No Answer">No Answer / Busy</option>
                    </select>
                </div>

                <div style="margin-bottom: 18px;">
                    <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Discussion Notes & Remarks</label>
                    <textarea id="manualNotes" class="filter-input" rows="3" style="width: 100%; box-sizing: border-box; resize: vertical;" placeholder="Enter key conversation points, requirements, follow-up dates..."></textarea>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn-secondary" onclick="closeModal('createCallModal')">Cancel</button>
                    <button type="submit" id="btnManualSubmit" class="btn-primary" style="padding: 10px 20px; border-radius: 10px; background: #10b981;">
                        <i class="fa-solid fa-check"></i> Save & Log Call
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Convert Call to Lead / Customer Modal -->
    <div class="modal-overlay" id="convertLeadModal">
        <div class="modal-box">
            <div class="modal-header">
                <h4 class="modal-title"><i class="fa-solid fa-user-plus" style="color: #6366f1;"></i> Convert Call to CRM Lead / Customer</h4>
                <button class="modal-close" onclick="closeModal('convertLeadModal')">&times;</button>
            </div>
            <form id="convertLeadForm" onsubmit="saveConvertToCustomer(event)">
                <input type="hidden" id="convertCallId">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Customer / Lead Name *</label>
                        <input type="text" id="convertName" class="filter-input" placeholder="e.g. Anand Kumar" style="width: 100%; box-sizing: border-box;" required>
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Phone Number *</label>
                        <input type="text" id="convertPhone" class="filter-input" style="width: 100%; box-sizing: border-box;" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Email Address (Optional)</label>
                        <input type="email" id="convertEmail" class="filter-input" placeholder="customer@example.com" style="width: 100%; box-sizing: border-box;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Location / City</label>
                        <input type="text" id="convertAddress" class="filter-input" placeholder="e.g. Mumbai, India" style="width: 100%; box-sizing: border-box;">
                    </div>
                </div>

                <div style="margin-bottom: 18px;">
                    <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Lead Notes & Summary</label>
                    <textarea id="convertNotes" class="filter-input" rows="3" style="width: 100%; box-sizing: border-box; resize: vertical;" placeholder="Discussion points from the call..."></textarea>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn-secondary" onclick="closeModal('convertLeadModal')">Cancel</button>
                    <button type="submit" id="btnConvertSubmit" class="btn-primary" style="padding: 10px 20px; border-radius: 10px;">
                        <i class="fa-solid fa-user-check"></i> Convert to Customer / Lead
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Mobile Sync Instructions Modal -->
    <div class="modal-overlay" id="syncInfoModal">
        <div class="modal-box" style="width: 620px;">
            <div class="modal-header">
                <h4 class="modal-title"><i class="fa-solid fa-mobile-screen-button"></i> Mobile Calls Sync</h4>
                <button class="modal-close" onclick="closeModal('syncInfoModal')">&times;</button>
            </div>
            <div style="line-height: 1.6; font-size: 14px; color: var(--text);">
                <p>You can automatically sync your mobile phone calls (Incoming, Outgoing, Missed) and audio recordings directly from the <strong>VY AI CRM Mobile App</strong>:</p>
                <div style="background: var(--surface-muted, #f1f5f9); padding: 16px; border-radius: 14px; margin: 16px 0; border: 1px solid var(--border);">
                    <div style="font-weight: 700; margin-bottom: 8px; color: #4f46e5;"><i class="fa-solid fa-check-circle"></i> How to Sync Calls from Mobile:</div>
                    <ol style="margin: 0; padding-left: 20px;">
                        <li>Open the <strong>VY AI CRM App</strong> on your Android phone.</li>
                        <li>Open the sidebar drawer and tap <strong>"Mobile Calls"</strong>.</li>
                        <li>Grant Call Log permission when prompted.</li>
                        <li>Tap the <strong>"Sync Mobile Calls to CRM"</strong> button.</li>
                        <li>All call logs and linked call recordings will automatically appear in this dashboard!</li>
                    </ol>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px;">
                    <span style="font-size: 12px; color: var(--text-muted);"><i class="fa-solid fa-lock"></i> All call data is securely encrypted in transit.</span>
                    <button type="button" class="btn-primary" onclick="closeModal('syncInfoModal')">Got it</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentCalls = [];
        let currentPage = 1;
        let activeAudio = null;
        let activePlayBtn = null;

        document.addEventListener('DOMContentLoaded', () => {
            initDatePresets();
            loadStats();
            loadCalls(1);

            document.getElementById('searchInput').addEventListener('input', debounce(() => loadCalls(1), 400));
            document.getElementById('typeFilter').addEventListener('change', () => loadCalls(1));
            document.getElementById('agentFilter').addEventListener('change', () => loadCalls(1));
            document.getElementById('hasRecordingFilter').addEventListener('change', () => loadCalls(1));
            document.getElementById('dateFrom').addEventListener('change', () => loadCalls(1));
            document.getElementById('dateTo').addEventListener('change', () => loadCalls(1));
        });

        function initDatePresets() {
            const preset = document.getElementById('datePreset');
            const customWrap = document.getElementById('customDateRange');
            const dateFrom = document.getElementById('dateFrom');
            const dateTo = document.getElementById('dateTo');

            preset.addEventListener('change', () => {
                const today = new Date().toISOString().split('T')[0];
                if (preset.value === 'today') {
                    customWrap.style.display = 'none';
                    dateFrom.value = today;
                    dateTo.value = today;
                } else if (preset.value === 'yesterday') {
                    customWrap.style.display = 'none';
                    const yest = new Date(Date.now() - 864e5).toISOString().split('T')[0];
                    dateFrom.value = yest;
                    dateTo.value = yest;
                } else if (preset.value === 'this_week') {
                    customWrap.style.display = 'none';
                    const weekAgo = new Date(Date.now() - 7 * 864e5).toISOString().split('T')[0];
                    dateFrom.value = weekAgo;
                    dateTo.value = today;
                } else if (preset.value === 'this_month') {
                    customWrap.style.display = 'none';
                    const monthAgo = new Date(Date.now() - 30 * 864e5).toISOString().split('T')[0];
                    dateFrom.value = monthAgo;
                    dateTo.value = today;
                } else if (preset.value === 'all') {
                    customWrap.style.display = 'none';
                    dateFrom.value = '';
                    dateTo.value = '';
                } else if (preset.value === 'custom') {
                    customWrap.style.display = 'inline-flex';
                }
                loadCalls(1);
            });

            // Set default today
            dateFrom.value = new Date().toISOString().split('T')[0];
            dateTo.value = new Date().toISOString().split('T')[0];
        }

        async function loadStats() {
            try {
                const res = await fetch('/api/calls_api.php?action=stats');
                const data = await res.json();
                if (data.success && data.stats) {
                    const s = data.stats;
                    document.getElementById('statTotal').innerText = s.total_calls.toLocaleString();
                    document.getElementById('statIncoming').innerText = s.incoming_calls.toLocaleString();
                    document.getElementById('statOutgoing').innerText = s.outgoing_calls.toLocaleString();
                    document.getElementById('statMissed').innerText = (s.missed_calls + s.rejected_calls).toLocaleString();
                    document.getElementById('statDuration').innerText = s.total_duration_formatted;
                    document.getElementById('statRecordings').innerText = s.recorded_calls.toLocaleString();
                }
            } catch (e) {
                console.error('Stats error', e);
            }
        }

        async function loadCalls(page = 1) {
            currentPage = page;
            const tbody = document.getElementById('callsTableBody');
            tbody.innerHTML = `<tr><td colspan="8" class="table-empty"><i class="fa-solid fa-spinner fa-spin"></i> Loading calls...</td></tr>`;

            const params = new URLSearchParams({
                action: 'list',
                page: page,
                limit: 25,
                q: document.getElementById('searchInput').value,
                type: document.getElementById('typeFilter').value,
                user_id: document.getElementById('agentFilter').value,
                has_recording: document.getElementById('hasRecordingFilter').checked ? '1' : '',
                date_from: document.getElementById('dateFrom').value,
                date_to: document.getElementById('dateTo').value
            });

            try {
                const res = await fetch(`/api/calls_api.php?${params.toString()}`);
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Failed to load calls');

                currentCalls = data.calls || [];
                renderCallsTable(currentCalls);
                renderPagination(data.pagination);
                populateCallSelect(currentCalls);
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="8" class="table-empty" style="color: #ef4444;"><i class="fa-solid fa-circle-exclamation"></i> ${err.message}</td></tr>`;
            }
        }

        function renderCallsTable(calls) {
            const tbody = document.getElementById('callsTableBody');
            if (!calls.length) {
                tbody.innerHTML = `<tr><td colspan="8" class="table-empty"><i class="fa-solid fa-phone-slash" style="font-size: 28px; opacity: 0.4;"></i><div style="margin-top: 8px;">No call records found matching criteria.</div></td></tr>`;
                return;
            }

            let html = '';
            calls.forEach(call => {
                const typeIcon = getTypeIcon(call.call_type);
                const typeClass = call.call_type;
                const contactDisplay = call.contact_name 
                    ? `<div style="font-weight: 700; color: var(--text);">${escapeHtml(call.contact_name)}</div><div style="font-size: 12px; color: var(--text-muted);"><i class="fa-solid fa-phone" style="font-size: 10px;"></i> ${escapeHtml(call.caller_number)}</div>`
                    : `<div style="font-weight: 700; color: var(--text);"><i class="fa-solid fa-phone" style="font-size: 11px;"></i> ${escapeHtml(call.caller_number)}</div><div style="font-size: 11px; color: var(--text-muted);">Unsaved Contact</div>`;

                let audioPlayerHtml = `<span style="font-size: 12px; color: var(--text-muted);"><i class="fa-solid fa-microphone-slash"></i> No audio</span>`;
                if (call.recording_file_url) {
                    audioPlayerHtml = `
                        <div class="audio-player-wrapper">
                            <button type="button" class="play-btn" onclick="toggleAudio(this, '${escapeHtml(call.recording_file_url)}')">
                                <i class="fa-solid fa-play"></i>
                            </button>
                            <input type="range" class="audio-slider" min="0" max="100" value="0" oninput="seekAudio(this)">
                            <span class="audio-timer">00:00</span>
                            <a href="${escapeHtml(call.recording_file_url)}" target="_blank" download class="row-actions" title="Download Audio" style="color: var(--text-muted); font-size: 12px; text-decoration: none;">
                                <i class="fa-solid fa-download"></i>
                            </a>
                        </div>
                    `;
                }

                const storageBadge = call.recording_storage_type 
                    ? `<span class="storage-pill ${call.recording_storage_type}">${call.recording_storage_type.replace('_', ' ')}</span>`
                    : '';

                const customerBadge = call.customer_id
                    ? `<a href="customers.php?search=${encodeURIComponent(call.caller_number)}" target="_blank" style="font-size: 10.5px; background: rgba(99,102,241,0.12); color: #6366f1; padding: 2px 8px; border-radius: 6px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; margin-top: 4px;"><i class="fa-solid fa-user-check"></i> Customer: ${escapeHtml(call.matched_customer_name || 'Linked')}</a>`
                    : `<button type="button" onclick="openConvertLeadModal(${call.id})" style="font-size: 10.5px; background: rgba(16,185,129,0.12); color: #10b981; border: 1px solid rgba(16,185,129,0.25); padding: 2px 8px; border-radius: 6px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; margin-top: 4px;"><i class="fa-solid fa-user-plus"></i> Convert to Lead</button>`;

                html += `
                    <tr>
                        <td style="padding: 14px 18px;">
                            ${contactDisplay}
                            ${customerBadge}
                        </td>
                        <td>
                            <span class="call-badge ${typeClass}">
                                ${typeIcon} ${capitalize(call.call_type)}
                            </span>
                        </td>
                        <td>
                            <div style="font-size: 13px; font-weight: 600; color: var(--text);">
                                <i class="fa-solid fa-user-tie" style="color: var(--text-muted); font-size: 11px;"></i> ${escapeHtml(call.user_name || 'Agent')}
                            </div>
                            <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;">
                                <i class="fa-solid fa-sim-card" style="font-size: 10px; color: #6366f1;"></i> ${escapeHtml(call.sim_slot || 'SIM 1')}${call.sim_carrier ? ' • ' + escapeHtml(call.sim_carrier) : ''}
                            </div>
                            ${call.device_model ? `<div style="font-size: 10px; color: var(--text-muted);"><i class="fa-solid fa-mobile-screen" style="font-size: 9px;"></i> ${escapeHtml(call.device_model)}</div>` : ''}
                        </td>
                        <td>
                            <div style="font-size: 13px; font-weight: 600; color: var(--text);">${formatDateTime(call.call_start_time)}</div>
                        </td>
                        <td>
                            <span style="font-size: 13px; font-weight: 700; font-family: monospace; color: var(--text);">${call.duration_formatted}</span>
                        </td>
                        <td>
                            ${audioPlayerHtml}
                            <div style="margin-top: 4px;">${storageBadge}</div>
                        </td>
                        <td>
                            ${call.outcome ? `<span style="font-size: 11px; font-weight: 700; background: rgba(16, 185, 129, 0.12); color: #10b981; padding: 2px 8px; border-radius: 6px; display: inline-block; margin-bottom: 4px;">${escapeHtml(call.outcome)}</span>` : ''}
                            <div style="font-size: 12px; color: var(--text-muted); max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                ${escapeHtml(call.notes || 'No remarks')}
                            </div>
                        </td>
                        <td style="text-align: right; padding-right: 18px;">
                            <div class="row-actions" style="justify-content: flex-end;">
                                ${!call.customer_id ? `
                                    <button type="button" title="Convert Call to Lead" onclick="openConvertLeadModal(${call.id})" style="color: #10b981;">
                                        <i class="fa-solid fa-user-plus"></i>
                                    </button>
                                ` : ''}
                                <button type="button" title="Edit Remarks" onclick="openEditNotes(${call.id})">
                                    <i class="fa-solid fa-pen"></i>
                                </button>
                                <button type="button" title="Delete Call" onclick="deleteCall(${call.id})" style="color: #ef4444;">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });

            tbody.innerHTML = html;
        }

        function renderPagination(p) {
            if (!p || p.total_pages <= 1) {
                document.getElementById('paginationWrap').style.display = 'none';
                return;
            }
            document.getElementById('paginationWrap').style.display = 'flex';
            document.getElementById('paginationInfo').innerText = `Showing page ${p.page} of ${p.total_pages} (${p.total} total calls)`;

            let btns = '';
            if (p.page > 1) {
                btns += `<button class="btn-secondary" style="padding: 6px 12px; font-size: 12px;" onclick="loadCalls(${p.page - 1})"><i class="fa-solid fa-chevron-left"></i> Prev</button>`;
            }
            if (p.page < p.total_pages) {
                btns += `<button class="btn-secondary" style="padding: 6px 12px; font-size: 12px;" onclick="loadCalls(${p.page + 1})">Next <i class="fa-solid fa-chevron-right"></i></button>`;
            }
            document.getElementById('paginationButtons').innerHTML = btns;
        }

        function populateCallSelect(calls) {
            const select = document.getElementById('uploadCallSelect');
            select.innerHTML = '<option value="">-- Standalone Upload / Auto Link --</option>';
            calls.forEach(c => {
                select.innerHTML += `<option value="${c.id}">Call ID #${c.id} - ${escapeHtml(c.caller_number)} (${formatDateTime(c.call_start_time)})</option>`;
            });
        }

        // Audio Player Controls
        function toggleAudio(btn, audioUrl) {
            const wrapper = btn.closest('.audio-player-wrapper');
            const slider = wrapper.querySelector('.audio-slider');
            const timer = wrapper.querySelector('.audio-timer');

            if (activeAudio && activeAudio.src === audioUrl) {
                if (activeAudio.paused) {
                    activeAudio.play();
                    btn.innerHTML = '<i class="fa-solid fa-pause"></i>';
                } else {
                    activeAudio.pause();
                    btn.innerHTML = '<i class="fa-solid fa-play"></i>';
                }
                return;
            }

            if (activeAudio) {
                activeAudio.pause();
                if (activePlayBtn) activePlayBtn.innerHTML = '<i class="fa-solid fa-play"></i>';
            }

            activeAudio = new Audio(audioUrl);
            activePlayBtn = btn;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

            activeAudio.addEventListener('canplay', () => {
                activeAudio.play();
                btn.innerHTML = '<i class="fa-solid fa-pause"></i>';
            });

            activeAudio.addEventListener('timeupdate', () => {
                if (activeAudio.duration) {
                    const pct = (activeAudio.currentTime / activeAudio.duration) * 100;
                    slider.value = pct;
                    const mins = Math.floor(activeAudio.currentTime / 60);
                    const secs = Math.floor(activeAudio.currentTime % 60);
                    timer.innerText = `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
                }
            });

            activeAudio.addEventListener('ended', () => {
                btn.innerHTML = '<i class="fa-solid fa-play"></i>';
                slider.value = 0;
            });

            activeAudio.addEventListener('error', () => {
                btn.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i>';
                Toast.show('Could not stream audio recording directly.', 'error');
            });
        }

        function seekAudio(slider) {
            if (activeAudio && activeAudio.duration) {
                activeAudio.currentTime = (slider.value / 100) * activeAudio.duration;
            }
        }

        // 1. Manually Log Call
        function openCreateCallModal() {
            document.getElementById('manualTime').value = new Date().toISOString().slice(0, 16);
            document.getElementById('manualPhone').value = '';
            document.getElementById('manualName').value = '';
            document.getElementById('manualDuration').value = '120';
            document.getElementById('manualNotes').value = '';
            document.getElementById('manualOutcome').value = 'Interested';
            openModal('createCallModal');
        }

        async function saveManualCall(e) {
            e.preventDefault();
            const btn = document.getElementById('btnManualSubmit');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

            const payload = {
                action: 'create_call',
                caller_number: document.getElementById('manualPhone').value,
                contact_name: document.getElementById('manualName').value,
                call_type: document.getElementById('manualType').value,
                sim_slot: document.getElementById('manualSim').value,
                call_start_time: document.getElementById('manualTime').value,
                duration: parseInt(document.getElementById('manualDuration').value) || 0,
                outcome: document.getElementById('manualOutcome').value,
                notes: document.getElementById('manualNotes').value
            };

            try {
                const res = await fetch('/api/calls_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Failed to log call');
                Toast.show('Call logged successfully!', 'success');
                closeModal('createCallModal');
                loadCalls(1);
                loadStats();
            } catch (err) {
                Toast.show(err.message, 'error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-check"></i> Save & Log Call';
            }
        }

        // 2. Convert Call to Lead / Customer
        function openConvertLeadModal(callId) {
            const call = currentCalls.find(c => c.id == callId);
            if (!call) return;
            document.getElementById('convertCallId').value = call.id;
            document.getElementById('convertPhone').value = call.caller_number || '';
            document.getElementById('convertName').value = call.contact_name || '';
            document.getElementById('convertNotes').value = call.notes || '';
            document.getElementById('convertEmail').value = '';
            document.getElementById('convertAddress').value = '';
            openModal('convertLeadModal');
        }

        async function saveConvertToCustomer(e) {
            e.preventDefault();
            const btn = document.getElementById('btnConvertSubmit');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Converting...';

            const payload = {
                action: 'convert_to_customer',
                call_id: parseInt(document.getElementById('convertCallId').value) || 0,
                name: document.getElementById('convertName').value,
                phone: document.getElementById('convertPhone').value,
                email: document.getElementById('convertEmail').value,
                billing_address: document.getElementById('convertAddress').value,
                notes: document.getElementById('convertNotes').value
            };

            try {
                const res = await fetch('/api/calls_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Failed to convert');
                Toast.show('Converted to Lead & Customer successfully!', 'success');
                closeModal('convertLeadModal');
                loadCalls(currentPage);
                loadStats();
            } catch (err) {
                Toast.show(err.message, 'error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-user-check"></i> Convert to Customer / Lead';
            }
        }

        // Edit Notes
        function openEditNotes(callId) {
            const call = currentCalls.find(c => c.id == callId);
            if (!call) return;
            document.getElementById('editCallId').value = call.id;
            document.getElementById('editCallerInfo').value = `${call.contact_name || 'Contact'} (${call.caller_number}) - ${call.duration_formatted}`;
            document.getElementById('editOutcome').value = call.outcome || '';
            document.getElementById('editNotes').value = call.notes || '';
            openModal('editNotesModal');
        }

        async function saveCallNotes(e) {
            e.preventDefault();
            const callId = document.getElementById('editCallId').value;
            const outcome = document.getElementById('editOutcome').value;
            const notes = document.getElementById('editNotes').value;

            try {
                const res = await fetch('/api/calls_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'update_call',
                        id: callId,
                        outcome: outcome,
                        notes: notes
                    })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Failed to save');
                Toast.show('Call remarks updated successfully!', 'success');
                closeModal('editNotesModal');
                loadCalls(currentPage);
            } catch (err) {
                Toast.show(err.message, 'error');
            }
        }

        // Upload Recording
        function openUploadModal() {
            document.getElementById('uploadCallTime').value = new Date().toISOString().slice(0, 16);
            openModal('uploadRecordingModal');
        }

        async function uploadRecordingAudio(e) {
            e.preventDefault();
            const btn = document.getElementById('btnUploadSubmit');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Uploading...';

            const formData = new FormData();
            formData.append('action', 'upload_recording');
            formData.append('call_id', document.getElementById('uploadCallSelect').value);
            formData.append('caller_number', document.getElementById('uploadCallerNumber').value);
            formData.append('call_start_time', document.getElementById('uploadCallTime').value);
            formData.append('recording_file', document.getElementById('uploadAudioFile').files[0]);

            try {
                const res = await fetch('/api/calls_api.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Upload failed');
                Toast.show('Recording audio uploaded successfully!', 'success');
                closeModal('uploadRecordingModal');
                loadCalls(currentPage);
                loadStats();
            } catch (err) {
                Toast.show(err.message, 'error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-cloud-arrow-up"></i> Upload & Link';
            }
        }

        // Delete Call
        async function deleteCall(callId) {
            if (!confirm('Are you sure you want to delete this call record?')) return;
            try {
                const res = await fetch('/api/calls_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete_call', id: callId })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Failed to delete');
                Toast.show('Call record deleted', 'success');
                loadCalls(currentPage);
                loadStats();
            } catch (err) {
                Toast.show(err.message, 'error');
            }
        }

        function openSyncModal() {
            openModal('syncInfoModal');
        }

        function openModal(id) {
            document.getElementById(id).classList.add('is-open');
        }
        function closeModal(id) {
            document.getElementById(id).classList.remove('is-open');
        }

        function getTypeIcon(type) {
            switch (type) {
                case 'incoming': return '<i class="fa-solid fa-arrow-down-left"></i>';
                case 'outgoing': return '<i class="fa-solid fa-arrow-up-right"></i>';
                case 'missed': return '<i class="fa-solid fa-phone-slash"></i>';
                case 'rejected': return '<i class="fa-solid fa-xmark"></i>';
                case 'blocked': return '<i class="fa-solid fa-ban"></i>';
                default: return '<i class="fa-solid fa-phone"></i>';
            }
        }

        function formatDateTime(dtStr) {
            if (!dtStr) return '-';
            const d = new Date(dtStr);
            return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }

        function capitalize(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
        function escapeHtml(s) {
            if (!s) return '';
            return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }
        function debounce(fn, delay) {
            let timer;
            return function(...args) {
                clearTimeout(timer);
                timer = setTimeout(() => fn.apply(this, args), delay);
            };
        }
    </script>
</body>
</html>
