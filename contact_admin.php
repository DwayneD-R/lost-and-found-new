<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$myId = $_SESSION['user_id'];

// Admins already see every "Contact Admin" thread from the receiving side
// (via messages.php); this button is for regular users reaching out.
if (($_SESSION['role'] ?? '') === 'admin') {
    redirect('messages.php');
}

$adminId = default_admin_id($pdo);
if (!$adminId) {
    set_flash('No admin account is available to message right now.', 'error');
    redirect('messages.php');
}

// Reuse an existing general thread with the admin instead of spawning a new one each time the
// button is clicked (is_system = 0 in the lookup keeps this from picking up the person's automated
// "System Notification" thread, which also has report_id NULL). Safe against double clicks.
$convoId = find_or_create_conversation($pdo, null, $adminId, $myId, 'General Inquiry');

redirect('messages.php?id=' . $convoId);
