<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $messageId = (int)($_POST['message_id'] ?? 0);
    $convoId   = (int)($_POST['convo_id'] ?? 0);
    $myId      = $_SESSION['user_id'];

    // Soft delete: the row stays (so the thread doesn't leave a gap in the
    // conversation) but its content is cleared and deleted_at is stamped.
    // Both sides then render it as "Deleted message" — the sender right
    // away on this reload, the other party via the live SSE stream, which pushes
    // newly-deleted message ids (see ajax/stream.php).
    $imgStmt = $pdo->prepare('SELECT image_path FROM messages WHERE id = ? AND sender_id = ? AND deleted_at IS NULL');
    $imgStmt->execute([$messageId, $myId]);
    $images = $imgStmt->fetchAll(PDO::FETCH_COLUMN);

    $stmt = $pdo->prepare(
        "UPDATE messages SET body = NULL, image_path = NULL, deleted_at = NOW()
         WHERE id = ? AND sender_id = ? AND deleted_at IS NULL"
    );
    $stmt->execute([$messageId, $myId]);
    if ($stmt->rowCount()) {
        delete_unused_uploads($pdo, $images); // the photo is gone from the chat, so delete the file too
        set_flash('Message deleted.', 'success');
    }
}

redirect('messages.php?id=' . (int)($_POST['convo_id'] ?? 0));
