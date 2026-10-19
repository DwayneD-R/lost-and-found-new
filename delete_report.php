<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

// This is a soft delete: the report is stamped with deleted_at rather than
// removed from the database. It disappears from Browse, the dashboard, and
// My Reports, but an admin can still see it in the trash, restore it, export
// it, or permanently delete it (see admin/reports.php).
// Locking, the ownership check and the "already deleted?" check all happen inside
// soft_delete_reports() (includes/functions.php), the same code the bulk delete uses.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reportId = (int)($_POST['report_id'] ?? 0);
    $isAdmin  = ($_SESSION['role'] ?? '') === 'admin';

    if (soft_delete_reports($pdo, [$reportId], (int) $_SESSION['user_id'], $isAdmin) === 1) {
        set_flash('Report deleted.', 'success');
    } else {
        set_flash('Nothing was deleted — that report was already deleted, no longer exists, or is not yours.', 'info');
    }
}

redirect($_SERVER['HTTP_REFERER'] ?? 'my_reports.php');
