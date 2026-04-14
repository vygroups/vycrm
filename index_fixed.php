<?php
// index.php - Premium Multi-Tenant Login Gateway
require_once 'config/database.php';
session_start();

$companySlug = $_GET['company'] ?? '';
$companyName = "Vy CRM";
$companyLogo = "images/logo.png";
$v = time();

if ($companySlug) {
    try {
        $db = Database::getMasterConn();
        $prefix = Database::getMasterPrefix();
        $stmt = $db->prepare("SELECT * FROM {$prefix}companies WHERE slug = ?");
        $stmt->execute([$companySlug]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($company) {
            $companyName = htmlspecialchars($company['name']);
            if ($company['logo']) $companyLogo = htmlspecialchars($company['logo']);
        }
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $companyName ?> - Secure Login</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <style>
        #vyToastContainer { position:fixed; top:20px; right:20px; z-index:99999; display:flex; flex-direction:column; gap:10px; }
        .vy-toast { background:#fff; border-radius:10px; padding:14px 20px; min-width:280px; max-width:340px; font-size:14px; font-weight:600; color:#2b3674; box-shadow:0 8px 25px rgba(0,0,0,.12); display:flex; align-items:center; gap:10px; opacity:0; transform:translateX(30px); transition:all .35s cubic-bezier(.25,.8,.25,1); }
        .vy-toast.show { opacity:1; transform:translateX(0); }
    </style>
</head>
<body>
    <div id="vyToastContainer"></div>
    <div class="login-wrapper">
        <div class="login-left">
            <div class="login-card">
                <div class="brand-logo">
                    <img src="<?= $companyLogo ?>?v=<?= $v ?>" alt="<?= $companyName ?>">
                </div>
                <h2 class="login-title">Welcome Back</h2>
                <p class="login-subtitle">Access your <strong><?= $companyName ?></strong> workspace</p>

                <form id="loginForm">
                    <input type="hidden" name="company" value="<?= htmlspecialchars($companySlug) ?>">
                    <div class="form-group">
                        <label class="form-label">Username or Email</label>
                        <input type="text" class="form-control" name="username" placeholder="admin" required>
                    </div>
                    <div class="form-group mb-4">
                        <div class="flex justify-between items-center mb-1">
                            <label class="form-label mb-0">Password</label>
                            <a href="#" class="text-sm text-muted">Forgot password?</a>
                        </div>
                        <input type="password" class="form-control" name="password" placeholder="••••••••" required>
                    </div>

                    <button type="submit" class="btn-primary" id="loginBtn">Sign In</button>
                    <p class="text-xs text-muted mt-3 text-center">Default Login: admin / admin@123</p>
                </form>
            </div>
        </div>
        <div class="login-right">
            <div style="text-align:center; color:white;">
                <h1 style="font-size: 48px; margin-bottom: 20px; color:white;"><?= $companyName ?></h1>
                <p style="font-size: 18px; opacity: 0.8; max-width: 400px; line-height: 1.6;">Building smarter workflows for modern companies. Login to access your CRM tools.</p>
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

        document.getElementById('loginForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = document.getElementById('loginBtn');
            const formData = new FormData(this);
            
            btn.disabled = true;
            btn.textContent = "Authenticating...";

            fetch('/api/login.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
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
    </script>
</body>
</html>