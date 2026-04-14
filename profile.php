<?php require_once 'auth_check.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Vy CRM</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="/assets/css/styles.css" rel="stylesheet">
</head>
<body>
<div class="app-wrapper">
    <aside class="sidebar">
        <div class="sidebar-head"><a href="dashboard.php"><img src="/images/logo.png" style="max-height:50px;"></a></div>
        <div class="sidebar-nav">
             <a href="dashboard.php" class="nav-item"><i class="fa-solid fa-chart-pie"></i><span class="nav-text">Dashboard</span></a>
             <a href="users.php" class="nav-item"><i class="fa-solid fa-users-gear"></i><span class="nav-text">Users</span></a>
        </div>
    </aside>
    <main class="main-content">
        <header class="topbar">
            <div class="breadcrumb">Settings / <span class="current">My Profile</span></div>
        </header>
        <div class="content-scroll">
            <div class="table-panel" style="max-width: 600px; margin: 0 auto; padding: 40px;">
                <div style="text-align: center; margin-bottom: 30px;">
                    <img src="/images/admin.jpg" onerror="this.src='https://ui-avatars.com/api/?name=Admin&background=7b5ef0&color=fff'" style="width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 4px solid var(--primary);">
                    <h2 style="margin-top: 15px;"><?= htmlspecialchars($_SESSION["username"]) ?></h2>
                    <p class="text-muted"><?= strtoupper($_SESSION["username"]) ?> Account</p>
                </div>
                <div class="form-group"><label class="form-label">Username</label><input type="text" class="form-control" value="<?= htmlspecialchars($_SESSION["username"]) ?>" disabled></div>
                <div class="form-group"><label class="form-label">Email Address</label><input type="email" class="form-control" value="admin@company.com"></div>
                <div class="form-group"><label class="form-label">New Password</label><input type="password" class="form-control" placeholder="••••••••"></div>
                <button class="btn-primary" style="margin-top: 20px;">UPDATE PROFILE</button>
            </div>
        </div>
    </main>
</div>
</body>
</html>
