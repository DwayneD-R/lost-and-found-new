<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reportId = (int)($_POST['report_id'] ?? 0);
    if (restore_reports($pdo, [$reportId], (int) $_SESSION['user_id'], true) === 1) {
        set_flash('Report restored.', 'success');
    } else {
        set_flash('Nothing was restored — that report was already restored or no longer exists.', 'info');
    }
}

redirect($_SERVER['HTTP_REFERER'] ?? 'reports.php?view=trash');
