<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $convoId = (int)($_POST['convo_id'] ?? 0);
    $myId = $_SESSION['user_id'];

    $check = $pdo->prepare('SELECT user_a_id, user_b_id, is_system FROM conversations WHERE id = ?');
    $check->execute([$convoId]);
    $convo = $check->fetch();

    if (!$convo || ($convo['user_a_id'] != $myId && $convo['user_b_id'] != $myId)) {
        set_flash('Conversation not found.', 'error');
    } elseif ($convo['is_system']) {
        // Not just hidden in the UI — the System Notification thread can't
        // be deleted even via a direct POST to this script.
        set_flash("You can't delete the System Notification thread.", 'error');
    } else {
        // Photos cleared and the other person told (System Notification: who it was with, and what about)
        // — all inside one transaction, with the notices sent only after it commits.
        cleanup_conversations($pdo, 'c.id = ?', [$convoId], [(int) $myId], '', true);
        set_flash('Conversation deleted.', 'success');
    }
}

redirect('messages.php');
