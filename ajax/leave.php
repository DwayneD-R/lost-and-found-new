<?php
/**
 * "This page was closed" beacon. js/main.js sends it (navigator.sendBeacon) when a page is really going
 * away (tab closed, navigated off, reloaded — not a back/forward-cache freeze). It marks that page's
 * presence row as left, which does two things at once:
 *   - the person shows as offline within a few seconds instead of waiting out the timeout, and
 *   - once SESSION_LEAVE_GRACE_SECONDS pass with no other page of the same login session alive, the
 *     next request ends the session (see is_logged_in() in includes/functions.php).
 * The timeout there stays as the safety net for when this beacon never arrives.
 *
 * Authorization is server-side: the row is only touched if it belongs to THIS login session and user
 * (a made-up conn id from somewhere else changes nothing). It deliberately does NOT call is_logged_in(),
 * so a closing page can't be what ends someone's session.
 */
require_once __DIR__ . '/../includes/functions.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_SESSION['user_id'])) {
    $conn = (string) ($_POST['conn'] ?? '');
    if (preg_match('/^[0-9a-f]{16}$/', $conn)) {
        // last_seen is set to "5 seconds from offline": a reload or a click to another page of the site
        // has that long to open its own live connection before the person looks offline.
        $pdo->prepare(
            'UPDATE presence SET last_seen = (NOW() - INTERVAL ' . (int) (ONLINE_WINDOW_SECONDS - 5) . ' SECOND), left_at = NOW()
             WHERE conn = ? AND session_id = ? AND user_id = ?'
        )->execute([$conn, session_id(), (int) $_SESSION['user_id']]);
    }
}
http_response_code(204);
