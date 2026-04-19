<?php
require_once 'auth_check.php';
require_once 'config/database.php';
require_once 'includes/brand.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(brand_page_title('Studio')) ?></title>
    <link rel="icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link rel="shortcut icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link href="/assets/css/styles.css" rel="stylesheet">
</head>
<body>
<div class="app-wrapper">
    <aside class="sidebar">
        <div class="sidebar-head"><a href="dashboard.php"><img src="<?= $companyLogo ?>?v=<?= $v ?>" alt="<?= $companyName ?>" style="max-height:50px;"></a></div>
        <div class="sidebar-nav">
             <a href="dashboard.php" class="nav-item"><i class="fa-solid fa-chart-pie"></i><span class="nav-text">Dashboard</span></a>
             <a href="users.php" class="nav-item"><i class="fa-solid fa-users-gear"></i><span class="nav-text">Users</span></a>
        </div>
    </aside>
    <main class="main-content">
        <header class="topbar"><div class="breadcrumb">Vy CRM / <span class="current">Studio</span></div></header>
        <div class="content-scroll">
            <div class="table-panel" style="padding: 100px; text-align: center;">
                <h1 style="font-size: 48px; color: var(--primary); margin-bottom: 20px;">🎨 Studio Mode</h1>
                <p style="font-size: 20px; color: var(--text-muted);">This is where you can customize your CRM interface and workflows. Coming soon!</p>
            </div>
        </div>
    </main>
</div>
</body>
</html>
