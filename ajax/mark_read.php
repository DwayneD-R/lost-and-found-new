<?php
/**
 * Read receipts. The open conversation page calls this once it has actually
 * shown messages from the other person (and the tab is visible).
 *
 * POST: convo_id, up_to (highest message id now on screen)
 *
 * Only messages up to that id are marked read, so a message that arrives a
 * moment later (or while the tab is hidden) still counts as unread and
 * still raises the notification badge.
 */
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

$myId    = (int) $_SESSION['user_id'];
$convoId = (int) ($_POST['convo_id'] ?? 0);
$upTo    = (int) ($_POST['up_to'] ?? 0);

if (!$convoId || !$upTo) {
    http_response_code(422);
    echo json_encode(['ok' => false]);
    exit;
}

// Only a participant can mark a conversation's messages read.
$check = $pdo->prepare('SELECT 1 FROM conversations WHERE id = ? AND (user_a_id = ? OR user_b_id = ?)');
$check->execute([$convoId, $myId, $myId]);
if (!$check->fetchColumn()) {
    http_response_code(404);
    echo json_encode(['ok' => false]);
    exit;
}

$stmt = $pdo->prepare(
    'UPDATE messages SET is_read = 1
     WHERE conversation_id = ? AND sender_id != ? AND is_read = 0 AND id <= ?'
);
$stmt->execute([$convoId, $myId, $upTo]);

echo json_encode(['ok' => true]);
