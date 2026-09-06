<?php
// index.php - Premium Multi-Tenant Login Gateway
require_once 'config/database.php';
session_start();

$companySlug = $_GET['company'] ?? $_COOKIE['vy_company_slug'] ?? '';

// Redirect to dashboard if already logged in for this company
if (isset($_SESSION['token']) && isset($_SESSION['tenant_slug']) && time() < $_SESSION['expiry']) {
    if (!$companySlug || $_SESSION['tenant_slug'] === $companySlug) {
        header('Location: /dashboard.php');
        exit;
    }
}

$companyName = "Vy CRM";
$companyLogo = "/images/logo.png";
$v = time();
$company = null;

if ($companySlug) {
    try {
        $db = Database::getMasterConn();
        $prefix = Database::getMasterPrefix();
        $stmt = $db->prepare("SELECT * FROM {$prefix}companies WHERE slug = ?");
        $stmt->execute([$companySlug]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($company) {
            $companyName = htmlspecialchars($company['name']);
            if ($company['logo']) {
                require_once 'includes/upload_paths.php';
                $companyLogo = UPLOAD_BASE_URL . implode('/', array_map('urlencode', explode('/', ltrim($company['logo'], '/'))));
            }
            // set up a safe cache buster appended string
            $cacheBuster = (strpos($companyLogo, '?') !== false ? '&' : '?') . 'v=' . $v;
            
            // Set/Renew persistent branding cookie for 10 years
            setcookie('vy_company_slug', $companySlug, time() + 315360000, '/');
        } else {
            // Invalid company, delete the cookie
            setcookie('vy_company_slug', '', time() - 3600, '/');
            $companySlug = '';
        }
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script>
        // 1. Save valid company slug to localStorage
        <?php if (!empty($companySlug) && $company): ?>
            localStorage.setItem('vy_company_slug', <?= json_encode($companySlug) ?>);
        <?php endif; ?>

        // 2. If no company context is loaded, attempt redirect from localStorage
        <?php if (empty($companySlug)): ?>
            const cachedSlug = localStorage.getItem('vy_company_slug');
            if (cachedSlug) {
                window.location.href = '/' + cachedSlug;
            }
        <?php endif; ?>
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Login - <?= $companyName ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="<?= $companyLogo ?><?= $cacheBuster ?>">
    <link rel="shortcut icon" href="<?= $companyLogo ?><?= $cacheBuster ?>">
    <base href="/">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <style>
        #vyToastContainer { position:fixed; top:20px; right:20px; z-index:99999; display:flex; flex-direction:column; gap:10px; }
        .vy-toast { background:#fff; border-radius:10px; padding:14px 20px; min-width:280px; max-width:340px; font-size:14px; font-weight:600; color:#2b3674; box-shadow:0 8px 25px rgba(0,0,0,.12); display:flex; align-items:center; gap:10px; opacity:0; transform:translateX(30px); transition:all .35s cubic-bezier(.25,.8,.25,1); }
        .vy-toast.show { opacity:1; transform:translateX(0); }
    </style>
</head>
<body>
    <!-- DEBUG: PHP EDITS ARE LIVE -->
    <div id="vyToastContainer"></div>
    <div class="login-wrapper">
        <div class="login-left">
            <div class="login-card">
                <div class="brand-logo">
                    <img src="<?= $companyLogo ?><?= $cacheBuster ?>" alt="<?= $companyName ?>">
                </div>
                <h2 class="login-title">Welcome Back</h2>
                <p class="login-subtitle">Access your <strong><?= $companyName ?></strong> workspace</p>

                <form id="loginForm">
                    <input type="hidden" name="company" value="<?= htmlspecialchars($companySlug) ?>">
                    <div class="form-group">
                        <label class="form-label">Username or Email</label>
                        <input type="text" class="form-control" name="username" placeholder="Username or Email" required>
                    </div>
                    <div class="form-group mb-4">
                        <div class="flex justify-between items-center mb-1">
                            <label class="form-label mb-0">Password</label>
                            <a href="forgot_password.php" class="text-sm text-muted" style="text-decoration:none;">Forgot password?</a>
                        </div>
                        <div style="position: relative;">
                            <input type="password" class="form-control" name="password" id="loginPassword" placeholder="••••••••" required style="padding-right: 40px;">
                            <i class="fa-regular fa-eye" id="togglePassword" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--text-muted); padding: 5px;"></i>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary" id="loginBtn">Sign In</button>
                </form>

                <!-- Two-Step Verification Form -->
                <form id="tfaForm" style="display: none;">
                    <input type="hidden" name="action" value="verify_2fa">
                    <input type="hidden" name="company" id="tfaCompany" value="<?= htmlspecialchars($companySlug) ?>">
                    <input type="hidden" name="user_id" id="tfaUserId" value="">
                    
                    <div style="text-align: center; margin-bottom: 20px;">
                        <div style="width: 54px; height: 54px; border-radius: 50%; background: #f3e8ff; color: #7b5ef0; display: inline-flex; align-items: center; justify-content: center; font-size: 22px; margin-bottom: 12px; box-shadow: 0 4px 15px rgba(123,94,240,0.2);">
                            <i class="fa-solid fa-shield-halved"></i>
                        </div>
                        <h3 style="font-size: 20px; font-weight: 800; color: #1e1b4b; margin: 0 0 6px;">Two-Step Verification</h3>
                        <p style="font-size: 13px; color: #64748b; margin: 0; line-height: 1.4;">Enter the 6-digit code sent to<br><b id="tfaMaskedEmail" style="color:#1e1b4b;">your email</b></p>
                    </div>

                    <div class="form-group mb-4">
                        <label class="form-label" style="text-align:center; display:block; font-size:12px; text-transform:uppercase; letter-spacing:0.5px;">6-Digit Verification Code</label>
                        <input type="text" class="form-control" name="otp" id="tfaOtpInput" placeholder="••••••" maxlength="6" pattern="[0-9]{6}" autocomplete="one-time-code" required style="text-align: center; font-size: 24px; font-weight: 800; letter-spacing: 6px; padding: 12px; border-radius: 12px; border: 2px solid rgba(123,94,240,0.3); background: #fdfcff;">
                    </div>

                    <button type="submit" class="btn-primary" id="tfaSubmitBtn" style="margin-bottom: 14px;">Verify & Continue</button>
                    
                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 13px; margin-top: 6px;">
                        <button type="button" id="resendTfaBtn" onclick="resend2FA()" style="background:none; border:none; color:#7b5ef0; font-weight:700; cursor:pointer; padding:4px 0;">Resend Code</button>
                        <button type="button" onclick="cancel2FA()" style="background:none; border:none; color:#64748b; font-weight:600; cursor:pointer; padding:4px 0;"><i class="fa-solid fa-arrow-left"></i> Back to Login</button>
                    </div>
                </form>
            </div>
        </div>
        <div class="login-right">
            <div class="login-glass-card">
                <h1 style="font-size: 56px; margin-bottom: 24px; color:white; font-weight: 800; letter-spacing: -1px;"><?= $companyName ?></h1>
                <p style="font-size: 20px; opacity: 0.9; line-height: 1.6; font-weight: 400;">The next generation of customer relationship management. Join <strong>1,000+ companies</strong> building smarter workflows.</p>
                <div style="margin-top: 40px; display: flex; justify-content: center; gap: 20px;">
                    <div style="text-align: left;">
                        <div style="font-size: 24px; font-weight: 700;">96%</div>
                        <div style="font-size: 12px; opacity: 0.7; text-transform: uppercase;">Efficiency</div>
                    </div>
                    <div style="width: 1px; background: rgba(255,255,255,0.2);"></div>
                    <div style="text-align: left;">
                        <div style="font-size: 24px; font-weight: 700;">24/7</div>
                        <div style="font-size: 12px; opacity: 0.7; text-transform: uppercase;">Real-time</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function vyToast(msg, type = 'error') {
            const colors = { success:'#10b981', error:'#ef4444', info:'#7b5ef0' };
            const c = document.getElementById('vyToastContainer');
            const t = document.createElement('div');
            t.className = 'vy-toast';
            t.style.borderLeft = '4px solid ' + (colors[type] || colors.error);
            t.innerHTML = `<span>${type == 'success' ? '✅' : (type == 'info' ? 'ℹ️' : '❌')}</span><span>${msg}</span>`;
            c.appendChild(t);
            requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('show')));
            setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 400); }, 3500);
        }

        async function getWebPushToken() {
            try {
                if (typeof window.requestPushNotificationPermission === 'function') {
                    return await window.requestPushNotificationPermission();
                }
            } catch (e) {}
            return null;
        }

        document.getElementById('loginForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = document.getElementById('loginBtn');
            const formData = new FormData(this);
            
            btn.disabled = true;
            btn.textContent = "Authenticating...";

            // Request push notification permission & get web FCM token on login button click
            const webFcmToken = await getWebPushToken();
            if (webFcmToken) {
                formData.append('fcm_token', webFcmToken);
            } else {
                formData.append('fcm_token', '');
            }
            formData.append('device_type', 'web');

            fetch('/api/login.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.requires_2fa) {
                    document.getElementById('loginForm').style.display = 'none';
                    document.getElementById('tfaForm').style.display = 'block';
                    document.getElementById('tfaUserId').value = data.user_id;
                    document.getElementById('tfaCompany').value = data.company || <?= json_encode($companySlug) ?>;
                    document.getElementById('tfaMaskedEmail').textContent = data.email || 'your registered email';
                    document.querySelector('.login-title').style.display = 'none';
                    document.querySelector('.login-subtitle').style.display = 'none';
                    vyToast(data.message, 'info');
                    document.getElementById('tfaOtpInput').focus();
                    startResendCountdown(30);
                    return;
                }

                if (data.success) {
                    vyToast('Login Successful! Redirecting...', 'success');
                    setTimeout(() => window.location.href = data.redirect, 1000);
                } else {
                    vyToast(data.message);
                    btn.disabled = false;
                    btn.textContent = "Sign In";
                }
            })
            .catch(err => {
                vyToast('A network error occurred');
                btn.disabled = false;
                btn.textContent = "Sign In";
            });
        });

        document.getElementById('tfaForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = document.getElementById('tfaSubmitBtn');
            btn.disabled = true;
            btn.textContent = "Verifying Code...";
            const formData = new FormData(this);

            const webFcmToken = await getWebPushToken();
            if (webFcmToken) {
                formData.append('fcm_token', webFcmToken);
            } else {
                formData.append('fcm_token', '');
            }
            formData.append('device_type', 'web');

            fetch('/api/login.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    vyToast('Verification Successful! Redirecting...', 'success');
                    setTimeout(() => window.location.href = data.redirect || '/dashboard.php', 1000);
                } else {
                    vyToast(data.message, 'error');
                    btn.disabled = false;
                    btn.textContent = "Verify & Continue";
                }
            })
            .catch(err => {
                vyToast('A network error occurred', 'error');
                btn.disabled = false;
                btn.textContent = "Verify & Continue";
            });
        });

        let resendTimer = null;
        function startResendCountdown(seconds) {
            const btn = document.getElementById('resendTfaBtn');
            btn.disabled = true;
            let remaining = seconds;
            btn.textContent = `Resend in ${remaining}s`;
            clearInterval(resendTimer);
            resendTimer = setInterval(() => {
                remaining--;
                if (remaining <= 0) {
                    clearInterval(resendTimer);
                    btn.disabled = false;
                    btn.textContent = "Resend Code";
                } else {
                    btn.textContent = `Resend in ${remaining}s`;
                }
            }, 1000);
        }

        async function resend2FA() {
            const btn = document.getElementById('resendTfaBtn');
            btn.disabled = true;
            btn.textContent = "Sending...";
            try {
                const formData = new FormData();
                formData.append('action', 'resend_2fa');
                formData.append('company', document.getElementById('tfaCompany').value);
                formData.append('user_id', document.getElementById('tfaUserId').value);

                const res = await fetch('/api/login.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    vyToast(data.message, 'success');
                    startResendCountdown(45);
                } else {
                    vyToast(data.message, 'error');
                    btn.disabled = false;
                    btn.textContent = "Resend Code";
                }
            } catch (err) {
                vyToast('Network error while resending code', 'error');
                btn.disabled = false;
                btn.textContent = "Resend Code";
            }
        }

        function cancel2FA() {
            document.getElementById('tfaForm').style.display = 'none';
            document.getElementById('loginForm').style.display = 'block';
            document.querySelector('.login-title').style.display = '';
            document.querySelector('.login-subtitle').style.display = '';
            const btn = document.getElementById('loginBtn');
            btn.disabled = false;
            btn.textContent = "Sign In";
        }

        document.getElementById('togglePassword').addEventListener('click', function () {
            const passwordInput = document.getElementById('loginPassword');
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });
    </script>
    <script src="/assets/js/firebase-init.js"></script>
</body>
</html>
