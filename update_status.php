<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

// Changes a report's status. Used by the status dropdowns (a normal form post, then a redirect)
// and by the "Mark as Resolved" button on the resolved check-in notification (an AJAX post,
// answered with JSON).
//
// Concurrency: the form says which status the person was looking at ("expected") and it is REQUIRED.
// The row is locked while we look at it, and if it has moved on in the meantime (an admin resolved
// it, the owner reopened it, it was deleted) the change is NOT applied and the person is told what
// it is now — instead of silently overwriting whatever the other person just did.
//
// Who resolved it: when a report becomes Resolved the person can say who found / claimed the item.
// "resolved_by" is the id of someone the owner has talked to about this report (checked against the
// real list here, not trusted from the browser), or "other" together with "resolved_by_name", or empty.
$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
$result = ['ok' => false, 'message' => 'Could not update the report.'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reportId = (int)($_POST['report_id'] ?? 0);
    $status   = $_POST['status'] ?? '';
    $expected = (string) ($_POST['expected'] ?? '');
    $isAdmin  = ($_SESSION['role'] ?? '') === 'admin';
    $myId     = (int)$_SESSION['user_id'];
    $byInput  = (string) ($_POST['resolved_by'] ?? '');
    $byName   = trim((string) ($_POST['resolved_by_name'] ?? ''));

    // Owners can switch their own report between Active and Resolved. Admins can also close a report
    // by hand, and reopen a closed one (Active or Resolved). Only Active reports close on their own
    // after 30 days (auto_close_stale_reports()); a report that becomes Active again gets a fresh
    // 30 days (reopened_at).
    $choices = $isAdmin ? ['active', 'resolved', 'closed'] : ['active', 'resolved'];
    if (!in_array($status, $choices, true)) {
        $result['message'] = "That isn't a status you can set.";
    } elseif (!in_array($expected, ['active', 'resolved', 'closed'], true)) {
        $result['message'] = 'This page is out of date — reload it and try again.';
    } elseif (mb_strlen($byName) > 120) {
        $result['message'] = 'That name is too long.';
    } else {
        $result = db_transaction($pdo, function () use ($pdo, $reportId, $status, $expected, $isAdmin, $myId, $byInput, $byName) {
            $sel = $pdo->prepare('SELECT user_id, item_name, status, deleted_at FROM reports WHERE id = ? FOR UPDATE');
            $sel->execute([$reportId]);
            $report = $sel->fetch();

            if (!$report || $report['deleted_at'] !== null) {
                return ['ok' => false, 'message' => 'That report was deleted or no longer exists.'];
            }
            $wasClosed = $report['status'] === 'closed';
            $ownerId   = (int)$report['user_id'];

            if (!$isAdmin && $ownerId !== $myId) {
                return ['ok' => false, 'message' => 'You can only change the status of your own reports.'];
            }
            if (!$isAdmin && $wasClosed) {
                return ['ok' => false, 'message' => 'That report is closed — contact an admin if it should be reopened.'];
            }
            if ($expected !== $report['status']) {
                return ['ok' => false, 'conflict' => true, 'message' =>
                    'Someone just changed this report to ' . status_label($report['status']) . ' — your change was not applied. Nothing was overwritten.'];
            }
            if ($status === $report['status']) {
                return ['ok' => true, 'message' => 'It is already ' . status_label($status) . '.'];
            }

            if ($status === 'active') {
                $upd = $pdo->prepare("UPDATE reports SET status = 'active', reopened_at = NOW(), resolved_by_user_id = NULL, resolved_by_name = NULL, resolved_at = NULL WHERE id = ? AND status = ?");
                $upd->execute([$reportId, $expected]);
            } elseif ($status === 'resolved') {
                $byId = null; $name = null;
                if ($byInput === 'other' && $byName !== '') {
                    $name = $byName;
                } elseif (ctype_digit($byInput) && (int) $byInput > 0) {
                    foreach (report_resolver_candidates($pdo, $reportId, $ownerId) as $c) {
                        if ($c['id'] === (int) $byInput) { $byId = $c['id']; $name = $c['name']; }
                    }
                    if ($byId === null) return ['ok' => false, 'message' => 'That person has not been in a conversation about this report.'];
                }
                $upd = $pdo->prepare("UPDATE reports SET status = 'resolved', resolved_by_user_id = ?, resolved_by_name = ?, resolved_at = NOW() WHERE id = ? AND status = ?");
                $upd->execute([$byId, $name, $reportId, $expected]);
            } else {
                $upd = $pdo->prepare("UPDATE reports SET status = 'closed', resolved_by_user_id = NULL, resolved_by_name = NULL, resolved_at = NULL WHERE id = ? AND status = ?");
                $upd->execute([$reportId, $expected]);
            }
            if ($upd->rowCount() !== 1) {
                return ['ok' => false, 'conflict' => true, 'message' => 'Someone just changed this report — your change was not applied.'];
            }

            // An admin changing someone else's report tells them, via System Notification — sent
            // only after the change has really been saved.
            if ($isAdmin && $ownerId > 0 && $ownerId !== $myId) {
                $details = ['Status' => status_label($report['status']) . ' → ' . status_label($status)];
                if ($status === 'closed') {
                    $title = 'Report closed by an administrator';
                    $details['Next step'] = 'Contact an admin to reopen it';
                } else {
                    $title = $wasClosed ? 'Report reopened by an administrator' : 'Report status changed by an administrator';
                    if ($status === 'active') $details['Closes'] = 'after ' . REPORT_CLOSE_DAYS . ' days, unless resolved';
                }
                $itemName = $report['item_name'];
                after_commit(function () use ($pdo, $ownerId, $title, $details, $itemName, $reportId) {
                    notify_report_change($pdo, $ownerId, $title, $details, $itemName, $reportId,
                        'an administrator changed your report. Here is what happened.');
                });
            }
            return ['ok' => true, 'message' => $status === 'resolved' ? 'Marked as Resolved — thanks for letting us know.' : 'Status updated.'];
        });
    }
}

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}
set_flash($result['ok'] ? ($result['message'] ?: 'Status updated.') : $result['message'], $result['ok'] ? 'success' : 'error');
redirect($_SERVER['HTTP_REFERER'] ?? 'my_reports.php');
