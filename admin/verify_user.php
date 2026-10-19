<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    if (!admin_password_ok($pdo, $_POST['confirm_password'] ?? '')) {
        set_flash('Incorrect password — nothing was changed.', 'error');
    } elseif ($userId) {
        $stmt = $pdo->prepare(
            'UPDATE users SET is_verified = 1, verify_code = NULL, verify_expires = NULL WHERE id = ?'
        );
        $stmt->execute([$userId]);
        set_flash('Account manually verified.', 'success');
    }
}

redirect('users.php');
