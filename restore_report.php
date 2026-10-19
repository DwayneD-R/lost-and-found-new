<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

// A user can undo their own delete, but only while the report is still
// active — once it's been resolved/closed, or an admin has already acted
// on it in the trash, restoring is left to admin/restore_report.php.
// The row is locked and re-checked inside restore_reports() (includes/functions.php).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reportId = (int)($_POST['report_id'] ?? 0);

    if (restore_reports($pdo, [$reportId], (int) $_SESSION['user_id'], false) === 1) {
        set_flash('Report restored.', 'success');
    } else {
        set_flash('This report can no longer be restored.', 'error');
    }
}

redirect('my_reports.php');
