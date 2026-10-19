<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin();

// The "Ask if resolved" button in Manage Reports: the admin's manual version of
// the automatic check-in. Only Active (not yet Resolved) reports, and sharing the
// automatic check-in's cooldown (RESOLVED_ASK_EVERY_DAYS) — a request of either kind
// holds off the next of either kind, so nobody gets asked twice in a row.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reportId = (int) ($_POST['report_id'] ?? 0);

    $stmt = $pdo->prepare(
        'SELECT r.id, r.user_id, r.item_name, r.created_at, r.status, r.deleted_at, r.resolved_asked_at, u.first_name, u.last_name, u.role AS reporter_role
         FROM reports r LEFT JOIN users u ON u.id = r.user_id WHERE r.id = ?'
    );
    $stmt->execute([$reportId]);
    $r = $stmt->fetch();

    if (!$r || $r['deleted_at'] !== null) {
        set_flash('That report no longer exists.', 'error');
    } elseif ($r['status'] !== 'active') {
        set_flash('Only Active reports can be asked — this one is already ' . status_label($r['status']) . '.', 'error');
    } elseif (empty($r['user_id'])) {
        set_flash("That report's reporter no longer has an account, so there is nobody to ask.", 'error');
    } elseif ((int) $r['user_id'] === (int) $_SESSION['user_id'] || $r['reporter_role'] === 'admin') {
        set_flash("That is an admin's own report — admins manage their reports themselves.", 'error');
    } elseif (ask_available_at($r) > time()) {
        // One cooldown for automatic and manual requests alike.
        set_flash(trim($r['first_name'] . ' ' . $r['last_name']) . ' was already asked on ' . date('M j', strtotime($r['resolved_asked_at']))
                  . ' — you can ask again on ' . date('M j', ask_available_at($r)) . '.', 'info');
    } else {
        if (request_report_update($pdo, $r)) {
            set_flash('Asked ' . trim($r['first_name'] . ' ' . $r['last_name']) . ' whether “' . $r['item_name'] . '” has been resolved.', 'success');
        } else {
            // Lost the race: someone else just asked, or the report changed (resolved / closed / deleted) a moment ago.
            set_flash('Nothing was sent — that report was just asked or just changed by someone else. The list now shows the latest.', 'info');
        }
    }
}

redirect($_SERVER['HTTP_REFERER'] ?? 'reports.php');
