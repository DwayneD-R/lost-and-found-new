<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin();

// Bulk verify / delete from the Manage Users table. Same rules as the
// one-at-a-time verify_user.php and delete_user.php: the built-in System
// account and the signed-in admin's own account are never touched, even if
// their ids are sent by hand.

$myId   = (int) $_SESSION['user_id'];
$action = $_POST['bulk_action'] ?? '';
$ids    = array_values(array_unique(array_filter(array_map('intval', $_POST['user_ids'] ?? []))));
$ids    = array_values(array_diff($ids, [$myId]));

if (!$ids) {
    set_flash('No users selected.', 'error');
    redirect('users.php');
}
if (!admin_password_ok($pdo, $_POST['confirm_password'] ?? '')) {
    set_flash('Incorrect password — nothing was changed.', 'error');
    redirect('users.php');
}

$count = count($ids);
$placeholders = implode(',', array_fill(0, $count, '?'));

switch ($action) {
    case 'verify':
        $stmt = $pdo->prepare(
            "UPDATE users SET is_verified = 1, verify_code = NULL, verify_expires = NULL
             WHERE id IN ($placeholders) AND is_verified = 0 AND email <> ?"
        );
        $stmt->execute(array_merge($ids, [SYSTEM_USER_EMAIL]));
        set_flash($stmt->rowCount() . ' of ' . $count . ' account(s) verified.', 'success');
        break;

    case 'delete':
        $deleted = delete_user_accounts($pdo, $ids);
        set_flash($deleted . ' of ' . $count . ' account(s) deleted.', 'success');
        break;

    default:
        set_flash('Unknown bulk action.', 'error');
}

redirect('users.php');
