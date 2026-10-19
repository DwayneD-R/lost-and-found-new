<?php
/**
 * A tiny queue of toast messages waiting to be shown to someone. Used when
 * something finishes AFTER the page already answered — e.g. an email that
 * the background sender (mail/send_job.php) couldn't deliver.
 *
 * "owner" is who should see it: 'u:<userId>' for a logged-in user (the live
 * stream in ajax/stream.php delivers it), or 's:<sessionId>' for a guest on
 * the login / register pages (ajax/toasts.php is polled for those).
 * Needs the `toasts` table — see sql/schema.sql.
 */
function toast_owner() {
    return isset($_SESSION['user_id']) ? 'u:' . (int) $_SESSION['user_id'] : 's:' . session_id();
}

function push_toast(PDO $pdo, $owner, $message) {
    try {
        $pdo->prepare('INSERT INTO toasts (owner, message) VALUES (?, ?)')->execute([$owner, mb_strimwidth((string) $message, 0, 250, '…')]);
        $pdo->exec('DELETE FROM toasts WHERE created_at < (NOW() - INTERVAL 1 DAY)'); // never let old ones pile up
    } catch (Throwable $e) {
        // best-effort
    }
}

/** Returns the waiting messages for this owner (and removes them). */
function take_toasts(PDO $pdo, $owner) {
    try {
        $stmt = $pdo->prepare('SELECT id, message FROM toasts WHERE owner = ? ORDER BY id');
        $stmt->execute([$owner]);
        $rows = $stmt->fetchAll();
        if (!$rows) return [];
        $ids = array_column($rows, 'id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM toasts WHERE id IN ($ph)")->execute($ids);
        return array_column($rows, 'message');
    } catch (Throwable $e) {
        return [];
    }
}
