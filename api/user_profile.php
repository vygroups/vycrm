<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$user_id = $_SESSION['user_id'];
$tenant_db = $_SESSION['tenant_db'];
$prefix = $_SESSION['tenant_prefix'];
$conn = Database::getTenantConn($tenant_db);

require_once '../includes/upload_paths.php';

$first_name = $_POST['first_name'] ?? '';
$last_name = $_POST['last_name'] ?? '';
$time_format = $_POST['time_format'] ?? '12h';
$date_format = $_POST['date_format'] ?? 'd M, Y';
$profile_picture_b64 = $_POST['profile_picture'] ?? '';
$profile_picture = '';

try {
    if ($profile_picture_b64 && strpos($profile_picture_b64, 'data:image') === 0) {
        $profile_picture_b64 = str_replace(' ', '+', $profile_picture_b64);
        list($type, $profile_picture_b64) = explode(';', $profile_picture_b64);
        list(, $profile_picture_b64) = explode(',', $profile_picture_b64);
        $data = base64_decode($profile_picture_b64);

        $ext = explode('/', $type)[1] ?? 'png';
        if (in_array($ext, ['jpeg', 'jpg', 'png', 'gif', 'webp'])) {
            $slug = upload_normalize_company_slug($_SESSION['tenant_slug']);
            $basename = 'user_' . $user_id . '_' . time();
            $rel_dir = upload_company_asset_dir($slug, 'profiles');
            upload_ensure_dir('../' . $rel_dir);
            $dest = $rel_dir . $basename . '.' . $ext;

            if (file_put_contents('../' . $dest, $data)) {
                $profile_picture = '/' . $dest;
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to write image file to disk.']);
                exit;
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid image format: ' . $ext]);
            exit;
        }
    }

    if ($profile_picture) {
        $stmt = $conn->prepare("UPDATE {$prefix}users SET first_name = ?, last_name = ?, time_format = ?, date_format = ?, profile_picture = ? WHERE id = ?");
        $stmt->execute([$first_name, $last_name, $time_format, $date_format, $profile_picture, $user_id]);
        $_SESSION['profile_picture'] = $profile_picture;
    } else {
        $stmt = $conn->prepare("UPDATE {$prefix}users SET first_name = ?, last_name = ?, time_format = ?, date_format = ? WHERE id = ?");
        $stmt->execute([$first_name, $last_name, $time_format, $date_format, $user_id]);
    }

    $_SESSION['first_name'] = $first_name;
    $_SESSION['last_name'] = $last_name;
    $_SESSION['time_format'] = $time_format;
    $_SESSION['date_format'] = $date_format;

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>