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

// Back to the page the person came from — but only if that page is on this site (the Referer header is
// sent by the browser and could name anywhere), and never to the report's own page, which no longer exists.
$back = 'my_reports.php';
$ref  = (string) ($_SERVER['HTTP_REFERER'] ?? '');
if ($ref !== '') {
    $p = parse_url($ref);
    $sameHost = isset($p['host']) && strcasecmp($p['host'], preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''))) === 0;
    if ($sameHost && !preg_match('#/item\.php$#', (string) ($p['path'] ?? ''))) {
        $back = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    }
}
redirect($back);
