<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php');

$reportId = (int)($_POST['report_id'] ?? 0);
$myId = $_SESSION['user_id'];

$stmt = $pdo->prepare('SELECT user_id, status FROM reports WHERE id = ? AND deleted_at IS NULL');
$stmt->execute([$reportId]);
$report = $stmt->fetch();
if (!$report) { set_flash('Report not found.', 'error'); redirect('search.php'); }

$ownerId = (int)$report['user_id'];
if ($ownerId === 0) {
    set_flash("The person who filed that report no longer has an account, so they can't be messaged.", 'info');
    redirect('item.php?id=' . $reportId);
}
if ($ownerId === (int)$myId) { redirect('item.php?id=' . $reportId); }

// A resolved report is done: only admins can still start a conversation about it.
if ($report['status'] === 'resolved' && ($_SESSION['role'] ?? '') !== 'admin') {
    set_flash('That report has already been resolved, so it can no longer be messaged.', 'info');
    redirect('item.php?id=' . $reportId);
}

// A closed report can't be resolved any more, so its page offers "Contact Admin"
// (who can reopen it) instead of "Message Reporter": the conversation is with
// the admin, about this item. Admins themselves still message the reporter.
if ($report['status'] === 'closed' && ($_SESSION['role'] ?? '') !== 'admin') {
    $adminId = default_admin_id($pdo);
    if (!$adminId) { set_flash('No admin account is available to message right now.', 'error'); redirect('item.php?id=' . $reportId); }
    $ownerId = (int)$adminId;
}

// A conversation always stores (report_id, user_a_id = owner, user_b_id = other party).
// Safe if the button is double-clicked, or both people start it at once: no duplicates.
$convoId = find_or_create_conversation($pdo, $reportId, $ownerId, $myId);

redirect('messages.php?id=' . $convoId);
