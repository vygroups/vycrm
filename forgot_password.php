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
                <h2 class="login-title">Reset Password</h2>
                <p class="login-subtitle">We will send a 6-digit OTP to your registered email.</p>

                <!-- STEP 1: Request OTP -->
                <form id="requestOtpForm">
                    <div class="form-group">
                        <label class="form-label">Company Slug</label>
                        <input type="text" class="form-control" name="company" placeholder="Company Slug" required value="<?= htmlspecialchars($companySlug) ?>">
                    </div>
                    <div class="form-group mb-4">
                        <label class="form-label">Username or Email</label>
                        <input type="text" class="form-control" name="username" id="fpUsername" placeholder="Username or Email" required>
                    </div>
                    <button type="submit" class="btn-primary" id="requestOtpBtn">Send OTP</button>
                    <div style="text-align:center; margin-top:15px;">
                        <a href="index.php" class="text-sm text-muted" style="text-decoration:none;">Back to Login</a>
                    </div>
                </form>

                <!-- STEP 2: Verify OTP & Reset -->
                <form id="resetPwdForm" style="display:none;">
                    <input type="hidden" name="company" id="resetCompany">
                    <input type="hidden" name="username" id="resetUsername">
                    
                    <div class="form-group">
                        <label class="form-label">6-Digit OTP</label>
                        <input type="text" class="form-control" name="otp" placeholder="Enter OTP" required maxlength="6" style="text-align:center; letter-spacing:8px; font-size:20px;">
                    </div>
                    <div class="form-group mb-4">
                        <label class="form-label">New Password</label>
                        <div style="position: relative;">
                            <input type="password" class="form-control" name="new_password" id="newPassword" placeholder="••••••••" required style="padding-right: 40px;">
                            <i class="fa-regular fa-eye" id="togglePassword" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--text-muted); padding: 5px;"></i>
                        </div>
                    </div>
                    <button type="submit" class="btn-primary" id="resetPwdBtn">Reset Password</button>
                    <div style="text-align:center; margin-top:15px;">
                        <a href="index.php" class="text-sm text-muted" style="text-decoration:none;">Cancel</a>
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
            const colors = { success:'#10b981', error:'#ef4444' };
            const c = document.getElementById('vyToastContainer');
            const t = document.createElement('div');
            t.className = 'vy-toast';
            t.style.borderLeft = '4px solid ' + (colors[type] || colors.error);
            t.innerHTML = `<span>${type == 'success' ? '✅' : '❌'}</span><span>${msg}</span>`;
            c.appendChild(t);
            requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('show')));
            setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 400); }, 3500);
        }

        document.getElementById('requestOtpForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = document.getElementById('requestOtpBtn');
            const formData = new FormData(this);
            formData.append('action', 'request_otp');
            
            btn.disabled = true;
            btn.textContent = "Sending...";

            fetch('/api/forgot_password_api.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    vyToast('OTP sent to your email!', 'success');
                    document.getElementById('resetCompany').value = formData.get('company');
                    document.getElementById('resetUsername').value = formData.get('username');
                    
                    document.getElementById('requestOtpForm').style.display = 'none';
                    document.getElementById('resetPwdForm').style.display = 'block';
                } else {
                    vyToast(data.message);
                }
            })
            .catch(err => {
                vyToast('A network error occurred');
            })
            .finally(() => {
                btn.disabled = false;
                btn.textContent = "Send OTP";
            });
        });

        document.getElementById('resetPwdForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = document.getElementById('resetPwdBtn');
            const formData = new FormData(this);
            formData.append('action', 'reset_password');
            
            btn.disabled = true;
            btn.textContent = "Resetting...";

            fetch('/api/forgot_password_api.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    vyToast('Password reset successful! Redirecting...', 'success');
                    setTimeout(() => window.location.href = 'index.php', 1500);
                } else {
                    vyToast(data.message);
                }
            })
            .catch(err => {
                vyToast('A network error occurred');
            })
            .finally(() => {
                btn.disabled = false;
                btn.textContent = "Reset Password";
            });
        });

        document.getElementById('togglePassword').addEventListener('click', function () {
            const passwordInput = document.getElementById('newPassword');
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });
    </script>
</body>
</html>
