<?php
// user_profile.php - User Personal Profile & Settings
require_once 'auth_check.php';
require_once 'config/database.php';
require_once 'includes/brand.php';

$user_id = $_SESSION['user_id'];
$tenant_db = $_SESSION['tenant_db'];
$prefix = $_SESSION['tenant_prefix'];
$conn = Database::getTenantConn($tenant_db);

$stmt = $conn->prepare("SELECT u.*, r.name as role_name FROM {$prefix}users u LEFT JOIN {$prefix}roles r ON u.role_id = r.id WHERE u.id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$time_format = $user['time_format'] ?? '12h';
$date_format = $user['date_format'] ?? 'd M, Y';
$profile_picture = $user['profile_picture'] ?? '';
require_once 'includes/upload_paths.php';
$profile_picture_url = '';
if (!empty($profile_picture)) {
    // If it's a legacy absolute path, just use it, otherwise use UPLOAD_BASE_URL
    if (strpos($profile_picture, '/') === 0) {
        $profile_picture_url = UPLOAD_BASE_URL . urlencode(ltrim($profile_picture, '/'));
    } else {
        $profile_picture_url = UPLOAD_BASE_URL . urlencode($profile_picture);
    }
} else {
    $profile_picture_url = 'https://ui-avatars.com/api/?name=' . urlencode($user['username']) . '&background=7b5ef0&color=fff';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(brand_page_title('User Profile')) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <style>
        .profile-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-main);
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-size: 14px;
            background: #f9f9f9;
        }

        .profile-pic-preview {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--primary);
            margin-bottom: 15px;
        }

        .save-btn {
            padding: 12px 24px;
            font-size: 16px;
            border-radius: 10px;
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
                    <div class="breadcrumb">Home / <span class="current">My Profile</span></div>
                </div>
            </header>
            <div class="content-scroll">
                <div class="profile-container">
                    <div
                        style="background:var(--surface); padding:30px; border-radius:20px; box-shadow:var(--shadow-sm);">
                        <h2 class="mb-4">Profile & Settings</h2>
                        <form id="userProfileForm" onsubmit="saveProfile(event)">
                            <div class="form-group" style="text-align: center;">
                                <img id="picPreview"
                                    src="<?= htmlspecialchars($profile_picture_url) ?>"
                                    class="profile-pic-preview">
                                <br>
                                <label for="profile_pic" class="btn-primary"
                                    style="display:inline-block; padding:8px 16px; cursor:pointer; width:auto; border-radius:8px;">Change
                                    Picture</label>
                                <input type="file" id="profile_pic" accept="image/*" style="display:none;"
                                    onchange="previewImage(this)">
                            </div>
                            <div style="display: flex; gap: 15px;">
                                <div class="form-group" style="flex: 1;">
                                    <label class="form-label">First Name</label>
                                    <input type="text" id="first_name" class="form-control" value="<?= htmlspecialchars($user['first_name'] ?? '') ?>" placeholder="e.g. John">
                                </div>
                                <div class="form-group" style="flex: 1;">
                                    <label class="form-label">Last Name</label>
                                    <input type="text" id="last_name" class="form-control" value="<?= htmlspecialchars($user['last_name'] ?? '') ?>" placeholder="e.g. Doe">
                                </div>
                            </div>
                            <div style="display: flex; gap: 15px;">
                                <div class="form-group" style="flex: 1;">
                                    <label class="form-label">Username</label>
                                    <input type="text" class="form-control"
                                        value="<?= htmlspecialchars($user['username']) ?>" readonly style="opacity:0.7;">
                                </div>
                                <div class="form-group" style="flex: 1;">
                                    <label class="form-label">Role</label>
                                    <input type="text" class="form-control"
                                        value="<?= htmlspecialchars($user['role_name'] ?? 'No Role Assigned') ?>" readonly style="opacity:0.7;">
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>"
                                    readonly style="opacity:0.7;">
                            </div>

                            <h3 class="mt-5 mb-3">Preferences</h3>
                            <div class="form-group">
                                <label class="form-label">Time Format</label>
                                <select id="time_format" class="form-control">
                                    <option value="12h" <?= $time_format == '12h' ? 'selected' : '' ?>>12 Hours (02:30 PM)
                                    </option>
                                    <option value="24h" <?= $time_format == '24h' ? 'selected' : '' ?>>24 Hours (14:30)
                                    </option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Date Format</label>
                                <select id="date_format" class="form-control">
                                    <option value="d M, Y" <?= $date_format == 'd M, Y' ? 'selected' : '' ?>>15 Apr, 2026
                                        (d M, Y)</option>
                                    <option value="Y-m-d" <?= $date_format == 'Y-m-d' ? 'selected' : '' ?>>2026-04-15
                                        (Y-m-d)</option>
                                    <option value="d/m/Y" <?= $date_format == 'd/m/Y' ? 'selected' : '' ?>>15/04/2026
                                        (d/m/Y)</option>
                                    <option value="m/d/Y" <?= $date_format == 'm/d/Y' ? 'selected' : '' ?>>04/15/2026
                                        (m/d/Y)</option>
                                </select>
                            </div>

                            <h3 class="mt-5 mb-3">Security Settings</h3>
                            <div class="form-group" style="display:flex; gap:15px;">
                                <button type="button" class="btn-primary" style="background:#4b5563; width:auto;" onclick="openPasswordModal()">
                                    <i class="fa-solid fa-lock"></i> Change Password
                                </button>
                                <button type="button" class="btn-primary" style="background:#ef4444; width:auto;" onclick="openResetPasswordModal()">
                                    <i class="fa-solid fa-envelope"></i> Reset via Email
                                </button>
                            </div>

                            <div class="form-group mt-5 text-right">
                                <button type="submit" class="btn-primary save-btn" style="width:auto;"><i class="fa-solid fa-save"></i> Save Settings</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Password Modal -->
    <div class="modal" id="pwdModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
        <div class="modal-content" style="background:white; padding:30px; border-radius:20px; width:100%; max-width:400px; box-shadow:var(--shadow-lg);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 style="margin:0;"><i class="fa-solid fa-lock" style="color:var(--primary);"></i> Change Password</h3>
                <button type="button" onclick="closePasswordModal()" style="background:none; border:none; cursor:pointer; font-size:18px; color:var(--text-muted);"><i class="fa-solid fa-xmark"></i></button>
            </div>
            
            <div id="pwdNormalFlow">
                <div class="form-group">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 5px;">
                        <label class="form-label" style="margin-bottom:0;">Old Password</label>
                        <a href="#" onclick="requestProfileOtp(); return false;" class="text-sm text-muted" style="text-decoration:none;">Forgot Password?</a>
                    </div>
                    <input type="password" id="old_pwd" class="form-control" placeholder="Enter current password">
                </div>
                <div class="form-group">
                    <label class="form-label">New Password</label>
                    <input type="password" id="new_pwd" class="form-control" placeholder="Enter new password">
                </div>
                <div class="form-group">
                    <label class="form-label">Confirm New Password</label>
                    <input type="password" id="confirm_pwd" class="form-control" placeholder="Confirm new password">
                </div>
                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                    <button type="button" class="btn-primary" style="background:#9ca3af; width:auto;" onclick="closePasswordModal()">Cancel</button>
                    <button type="button" class="btn-primary" style="width:auto;" onclick="submitPasswordChange(this)">Update Password</button>
                </div>
            </div>

            <div id="pwdOtpFlow" style="display:none;">
                <p style="font-size: 14px; color: var(--text-muted); margin-bottom: 15px;">An OTP has been sent to your email.</p>
                <div class="form-group">
                    <label class="form-label">6-Digit OTP</label>
                    <input type="text" id="otp_code" class="form-control" placeholder="Enter OTP" maxlength="6" style="text-align:center; letter-spacing:8px; font-size:20px;">
                </div>
                <div class="form-group">
                    <label class="form-label">New Password</label>
                    <input type="password" id="otp_new_pwd" class="form-control" placeholder="Enter new password">
                </div>
                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                    <button type="button" class="btn-primary" style="background:#9ca3af; width:auto;" onclick="closePasswordModal()">Cancel</button>
                    <button type="button" class="btn-primary" id="btnVerifyOtp" style="width:auto;" onclick="submitOtpChange(this)">Reset Password</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentImageBase64 = null;

        function previewImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function (e) {
                    document.getElementById('picPreview').src = e.target.result;
                    currentImageBase64 = e.target.result;
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        async function saveProfile(e) {
            e.preventDefault();
            const first_name = document.getElementById('first_name').value;
            const last_name = document.getElementById('last_name').value;
            const time_format = document.getElementById('time_format').value;
            const date_format = document.getElementById('date_format').value;

            const formData = new FormData();
            formData.append('first_name', first_name);
            formData.append('last_name', last_name);
            formData.append('time_format', time_format);
            formData.append('date_format', date_format);
            if (currentImageBase64) {
                formData.append('profile_picture', currentImageBase64);
            }

            const btn = document.querySelector('.save-btn');
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

            const res = await fetch('/api/user_profile.php', {
                method: 'POST',
                body: formData
            });

            const data = await res.json();
            btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Settings';

            if (data.success) {
                alert('Profile updated successfully!');
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        }

        function toggleMobileSidebar() {
            const s = document.getElementById('sidebar');
            const o = document.getElementById('sidebarOverlay');
            s.classList.toggle('mobile-open');
            o.style.display = s.classList.contains('mobile-open') ? 'block' : 'none';
        }

        function openPasswordModal() {
            document.getElementById('pwdModal').style.display = 'flex';
            document.getElementById('pwdNormalFlow').style.display = 'block';
            document.getElementById('pwdOtpFlow').style.display = 'none';
        }

        function openResetPasswordModal() {
            document.getElementById('pwdModal').style.display = 'flex';
            document.getElementById('pwdNormalFlow').style.display = 'none';
            document.getElementById('pwdOtpFlow').style.display = 'block';
            requestProfileOtp();
        }

        function closePasswordModal() {
            document.getElementById('pwdModal').style.display = 'none';
            document.getElementById('old_pwd').value = '';
            document.getElementById('new_pwd').value = '';
            document.getElementById('confirm_pwd').value = '';
            document.getElementById('otp_code').value = '';
            document.getElementById('otp_new_pwd').value = '';
        }

        async function requestProfileOtp() {
            const fd = new FormData();
            fd.append('action', 'request_otp');
            fd.append('company', '<?= htmlspecialchars($_SESSION['tenant_slug'] ?? '') ?>');
            fd.append('username', '<?= htmlspecialchars($user['username'] ?? '') ?>');

            try {
                const res = await fetch('/api/forgot_password_api.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    alert("OTP sent to your email!");
                    document.getElementById('pwdNormalFlow').style.display = 'none';
                    document.getElementById('pwdOtpFlow').style.display = 'block';
                } else {
                    alert(data.message || "Failed to send OTP.");
                }
            } catch(e) {
                alert("Error sending OTP.");
            }
        }

        async function submitOtpChange(btn) {
            const otp = document.getElementById('otp_code').value;
            const new_pwd = document.getElementById('otp_new_pwd').value;

            if (!otp || !new_pwd) {
                alert("Please enter OTP and new password");
                return;
            }

            const fd = new FormData();
            fd.append('action', 'reset_password');
            fd.append('company', '<?= htmlspecialchars($_SESSION['tenant_slug'] ?? '') ?>');
            fd.append('username', '<?= htmlspecialchars($user['username'] ?? '') ?>');
            fd.append('otp', otp);
            fd.append('new_password', new_pwd);

            const prevHtml = btn.innerHTML;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
            btn.disabled = true;

            try {
                const res = await fetch('/api/forgot_password_api.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    alert("Password updated successfully!");
                    closePasswordModal();
                } else {
                    alert(data.message || "Failed to update password");
                }
            } catch(e) {
                alert("Error updating password");
            }

            btn.innerHTML = prevHtml;
            btn.disabled = false;
        }

        async function submitPasswordChange(btn) {
            const old_pwd = document.getElementById('old_pwd').value;
            const new_pwd = document.getElementById('new_pwd').value;
            const confirm_pwd = document.getElementById('confirm_pwd').value;

            if (!old_pwd || !new_pwd || !confirm_pwd) {
                alert("Please fill all fields");
                return;
            }
            if (new_pwd !== confirm_pwd) {
                alert("New passwords do not match");
                return;
            }

            const fd = new FormData();
            fd.append('action', 'change_password');
            fd.append('old_password', old_pwd);
            fd.append('new_password', new_pwd);

            const prevHtml = btn.innerHTML;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
            btn.disabled = true;

            try {
                const res = await fetch('/api/user_profile.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    alert("Password updated successfully!");
                    closePasswordModal();
                } else {
                    alert(data.message || "Failed to update password");
                }
            } catch(e) {
                alert("Error updating password");
            }

            btn.innerHTML = prevHtml;
            btn.disabled = false;
        }
    </script>
</body>

</html>