<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin();

// Permanent removal. Only reachable from the trash view, and only for
// reports that have already been soft-deleted, so nothing active can be
// wiped by mistake. permanently_delete_reports() (includes/functions.php) locks the row first, so
// if someone restores it at that very moment we see that and delete nothing; conversations, the
// report and its photo record go together or not at all, and the photo file itself is only deleted
// once everything has committed.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reportId = (int)($_POST['report_id'] ?? 0);
    if (permanently_delete_reports($pdo, [$reportId]) === 1) set_flash('Report permanently deleted.', 'success');
    else set_flash('Nothing was deleted — that report was already removed or has just been restored.', 'info');
}

redirect($_SERVER['HTTP_REFERER'] ?? 'reports.php?view=trash');
