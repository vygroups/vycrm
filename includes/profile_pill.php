<?php
// includes/profile_pill.php
require_once __DIR__ . '/upload_paths.php';

$username = $_SESSION['username'] ?? 'User';
$first_name = $_SESSION['first_name'] ?? '';
$last_name = $_SESSION['last_name'] ?? '';
$display_name = trim("$first_name $last_name") ?: $username;

$profile_pic = '';
if (!empty($_SESSION['profile_picture'])) {
    if (strpos($_SESSION['profile_picture'], '/') === 0) {
        $profile_pic = UPLOAD_BASE_URL . urlencode(ltrim($_SESSION['profile_picture'], '/'));
    } else {
        $profile_pic = UPLOAD_BASE_URL . urlencode($_SESSION['profile_picture']);
    }
} else {
    $profile_pic = "https://ui-avatars.com/api/?name=" . urlencode($display_name) . "&background=7b5ef0&color=fff";
}
?>
<div class="profile-pill" onclick="toggleProfileDropdown(event)">
    <img src="<?= $profile_pic ?>"
        onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($display_name) ?>&background=7b5ef0&color=fff'"
        alt="User">
    <span class="name"><?= htmlspecialchars($display_name) ?></span>
    <i class="fa-solid fa-chevron-down text-muted" style="margin-right:8px;font-size:12px;"></i>

    <!-- Profile Dropdown -->
    <div class="profile-dropdown" id="profileDropdown">
        <a href="/user_profile.php" class="dropdown-item"><i class="fa-regular fa-user"></i> My Profile</a>
        <div class="dropdown-divider"></div>
        <?php if (!empty($_SESSION['is_admin'])): ?>
            <a href="/users.php" class="dropdown-item"><i class="fa-solid fa-users"></i> User Management</a>
            <a href="/roles.php" class="dropdown-item"><i class="fa-solid fa-wand-magic-sparkles"></i> Studio (Roles)</a>
            <div class="dropdown-divider"></div>
        <?php endif; ?>
        <a href="/logout.php" class="dropdown-item" style="color: var(--hot);"><i
                class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
    </div>
</div>