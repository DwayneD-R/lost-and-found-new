<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = (int)($_POST['user_id'] ?? 0);
    if (!admin_password_ok($pdo, $_POST['confirm_password'] ?? '')) {
        set_flash('Incorrect password — nothing was deleted.', 'error');
        redirect('users.php');
    }
    $emailStmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
    $emailStmt->execute([$userId]);
    if ($emailStmt->fetchColumn() === SYSTEM_USER_EMAIL) {
        // The built-in sender of possible-match alerts — deleting it would
        // wipe every user's System Notification thread (ON DELETE CASCADE).
        set_flash("The System Notifications account is built in and can't be deleted.", 'error');
    } elseif ($userId && $userId != $_SESSION['user_id']) {
        // Removes their conversations and the photos in them; their reports are kept (shown as "Deleted user").
        delete_user_accounts($pdo, [$userId]);
        set_flash('Account deleted.', 'success');
    }
}

redirect('users.php');
