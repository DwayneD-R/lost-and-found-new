<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$myId = $_SESSION['user_id'];
$convoId = (int)($_GET['id'] ?? 0);

// The live stream sends people here (?gone=1) when the conversation they had open was deleted.
if (!$convoId && isset($_GET['gone'])) set_flash('That conversation was deleted.', 'error');

// Rendering lives in includes/functions.php (get_conversations /
// render_convo_list) because ajax/stream.php pushes the same markup live.
$conversations = get_conversations($pdo, $myId);

// --- Active conversation (the "thread" pane on the right) ---------------
$convo = null;
$messages = [];
$threadTitle = '';

if ($convoId) {
    $isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

    $cstmt = $pdo->prepare(
        "SELECT c.*, r.item_name FROM conversations c LEFT JOIN reports r ON r.id = c.report_id WHERE c.id = ?"
    );
    $cstmt->execute([$convoId]);
    $convo = $cstmt->fetch();

    if (!$convo || ($convo['user_a_id'] != $myId && $convo['user_b_id'] != $myId)) {
        // Used to be a bare die() here — a blank, unstyled error page for
        // anything from a stale notification link to a conversation the
        // other person just deleted. Land back on Messages instead, with
        // an explanation, so there's no dead end.
        if ($isAjax) {
            header('Content-Type: application/json');
            http_response_code(404);
            set_flash("That conversation doesn't exist, or you no longer have access to it.", 'error');
            echo json_encode(['error' => "This conversation no longer exists."]);
            exit;
        }
        set_flash("That conversation doesn't exist, or you no longer have access to it.", 'error');
        redirect('messages.php');
    }

    // The other person in the conversation (for the three-dot menu: their email, the report, delete).
    $otherStmt = $pdo->prepare('SELECT first_name, last_name, email FROM users WHERE id = ?');
    $otherStmt->execute([(int) $convo['user_a_id'] === (int) $myId ? $convo['user_b_id'] : $convo['user_a_id']]);
    $other = $otherStmt->fetch() ?: ['first_name' => '', 'last_name' => '', 'email' => ''];

    $threadTitle = $convo['is_system'] ? 'System Notification' : ($convo['report_id'] ? $convo['item_name'] : ($convo['subject'] ?: 'General Inquiry'));

    // Handle sending a new message (text and/or image). The chat form is
    // submitted via fetch (see js/main.js) so the message can be appended
    // to the thread in place — no full page reload/redirect, so the
    // messenger panes never visibly jump/scroll on send. That JS sends an
    // X-Requested-With header; a plain form post (JS disabled) still works,
    // falling back to the old redirect.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // The System Notification thread is one-way. This guards the actual
        // insert, not just the UI below that hides the composer — a direct
        // POST (or a stale page that still shows the form) can't get a
        // message into it either.
        if ($convo['is_system']) {
            if ($isAjax) {
                header('Content-Type: application/json');
                http_response_code(403);
                echo json_encode(['error' => "You can't reply to system notifications."]);
                exit;
            }
            set_flash("You can't reply to system notifications.", 'error');
            redirect('messages.php?id=' . $convoId);
        }

        $body = trim($_POST['body'] ?? '');
        $imagePath = null;
        $uploadError = null;
        try {
            $imagePath = handle_image_upload('image', 'chat');
        } catch (RuntimeException $e) {
            $uploadError = $e->getMessage();
        }

        if ($uploadError) {
            if ($isAjax) {
                header('Content-Type: application/json');
                http_response_code(422);
                echo json_encode(['error' => $uploadError]);
                exit;
            }
            set_flash($uploadError, 'error');
        }

        $newId = null;
        if ($body !== '' || $imagePath) {
            // Is this the very first message? Then the other person hears about the new conversation by email.
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE conversation_id = ?');
            $countStmt->execute([$convoId]);
            $isFirstMessage = (int) $countStmt->fetchColumn() === 0;

            $ins = $pdo->prepare('INSERT INTO messages (conversation_id, sender_id, body, image_path) VALUES (?, ?, ?, ?)');
            $ins->execute([$convoId, $myId, $body ?: null, $imagePath]);
            $newId = $pdo->lastInsertId();

            if ($isFirstMessage) notify_new_conversation($pdo, $convo, $myId, $body);
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            $out = ['ok' => true, 'message' => null];
            if ($newId) {
                $mstmt = $pdo->prepare(
                    "SELECT m.id, m.body, m.image_path, m.created_at, m.sender_id, u.first_name
                     FROM messages m JOIN users u ON u.id = m.sender_id WHERE m.id = ?"
                );
                $mstmt->execute([$newId]);
                $m = $mstmt->fetch();
                $out['message'] = [
                    'id'          => (int) $m['id'],
                    'sender_id'   => (int) $m['sender_id'],
                    'first_name'  => $m['first_name'],
                    'body'        => $m['body'],
                    'image_path'  => $m['image_path'],
                    'created_at'  => $m['created_at'],
                ];
            }
            echo json_encode($out);
            exit;
        }

        redirect('messages.php?id=' . $convoId);
    }

    // Mark any messages from the other party as read now that this thread is open.
    $markRead = $pdo->prepare(
        'UPDATE messages SET is_read = 1 WHERE conversation_id = ? AND sender_id != ? AND is_read = 0'
    );
    $markRead->execute([$convoId, $myId]);

    $msgStmt = $pdo->prepare(
        "SELECT m.*, u.first_name FROM messages m JOIN users u ON u.id = m.sender_id
         WHERE m.conversation_id = ? ORDER BY m.created_at ASC"
    );
    $msgStmt->execute([$convoId]);
    $messages = $msgStmt->fetchAll();
}

$pageTitle = $convo ? ('Chat — ' . $threadTitle) : 'Messages';
$bodyClass = 'messenger-page';
require_once __DIR__ . '/includes/header.php';
?>
<div class="messenger" data-has-active="<?= $convo ? '1' : '0' ?>">

    <div class="messenger-list-pane" id="messenger-list-pane">
        <div class="page-head">
            <h1>Messages</h1>
            <?php if (($_SESSION['role'] ?? '') !== 'admin'): ?>
                <a class="btn btn-secondary" href="contact_admin.php">Contact Admin</a>
            <?php endif; ?>
        </div>
        <div class="convo-list" id="convo-list" data-active="<?= (int)$convoId ?>">
        <?php render_convo_list($conversations, $convoId); ?>
        </div>
    </div>

    <div class="messenger-thread-pane" id="messenger-thread-pane">
    <?php if ($convo): ?>
        <div class="thread-head">
            <a href="messages.php" class="icon-btn thread-back" aria-label="Back to conversations">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
            </a>
            <h2 title="<?= e($threadTitle) ?>"><?= e($threadTitle) ?></h2>
            <?php if (!$convo['is_system']): ?>
                <?= convo_menu_button($convoId, trim($other['first_name'] . ' ' . $other['last_name']), $other['email'], $convo['report_id'], $convo['item_name']) ?>
            <?php endif; ?>
        </div>

        <?php $lastRenderedId = $messages ? (int) end($messages)['id'] : 0; ?>
        <div id="chat-window" class="chat-window" data-convo="<?= (int)$convoId ?>" data-me="<?= (int)$myId ?>" data-last-id="<?= $lastRenderedId ?>" data-since-deleted="<?= time() ?>" data-is-system="<?= $convo['is_system'] ? '1' : '0' ?>">
        <?php foreach ($messages as $m): ?>
            <?php
                $isDeleted = $m['deleted_at'] !== null;
                // Nobody can send into a System Notification thread (see the
                // guard in the POST handler above), so every message in one
                // is, by definition, incoming — never "mine", even for the
                // dedicated system account's own id by some future accident.
                $isMine = !$convo['is_system'] && $m['sender_id'] == $myId;
            ?>
            <div class="chat-bubble <?= $isMine ? 'mine' : 'theirs' ?><?= $isDeleted ? ' deleted' : '' ?>" data-message-id="<?= (int)$m['id'] ?>">
                <span class="chat-sender"><?= e($m['first_name']) ?></span>
                <?php if ($isDeleted): ?>
                    <p class="deleted-msg-text"><em>Deleted message</em></p>
                <?php else: ?>
                    <?php if ($m['body']): ?>
                        <?php $matchCard = render_match_card($m['body']) ?: render_admin_edit_card($m['body']) ?: render_notice_card($m['body']); ?>
                        <?php if ($matchCard): ?>
                            <?= $matchCard ?>
                        <?php else: ?>
                            <p><?= render_chat_text($m['body']) ?></p>
                            <?= match_likelihood_bar($m['body']) ?>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($m['image_path']): ?><img src="<?= e($m['image_path']) ?>" class="chat-image" alt="" loading="lazy"><?php endif; ?>
                <?php endif; ?>
                <span class="chat-time"><?= e(format_message_time($m['created_at'])) ?></span>
                <?php if ($isMine && !$isDeleted): ?>
                    <form method="post" action="delete_message.php" class="delete-msg-form confirm-form"
                          data-confirm-title="Delete this message?"
                          data-confirm-message="This replaces it with &quot;Deleted message&quot; for both of you."
                          data-confirm-label="Yes" data-confirm-danger="1">
                        <input type="hidden" name="message_id" value="<?= (int)$m['id'] ?>">
                        <input type="hidden" name="convo_id" value="<?= (int)$convoId ?>">
                        <button type="submit" class="delete-msg-btn" title="Delete message">&times;</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>

        <?php if ($convo['is_system']): ?>
            <p class="chat-form-disabled-note">This is an automated thread — you can't reply here.</p>
        <?php else: ?>
        <form method="post" enctype="multipart/form-data" class="chat-form" id="chat-form">
            <input type="text" name="body" placeholder="Type a message..." autocomplete="off">
            <div class="file-picker file-picker-image" data-file-picker="image">
                <button type="button" class="file-picker-btn" aria-label="Attach image">+</button>
                <img class="file-picker-thumb" alt="" hidden>
                <input type="file" name="image" accept="image/*">
            </div>
            <button type="submit">Send</button>
        </form>
        <?php endif; ?>
    <?php else: ?>
        <div class="messenger-empty">
            <svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            <p>Select a conversation to start chatting.</p>
        </div>
    <?php endif; ?>
    </div>

</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
