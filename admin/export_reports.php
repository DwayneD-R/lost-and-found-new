<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin();

// CSV export using PHP's built-in fputcsv() — no external library.
//
// One "Export" button on Manage Reports opens a popup (ReportModal in
// js/main.js) where the admin picks what to include: active reports,
// deleted reports, or both. This file does three jobs:
//   - AJAX GET (no choice made yet): returns just the popup form
//   - GET with ?active=1 and/or ?deleted=1: downloads the CSV
//   - plain visit with no choice: the same form as a full page (fallback)
// The old ?scope=all|trash|active links still work.
$isPartial = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

$wantActive  = !empty($_GET['active']);
$wantDeleted = !empty($_GET['deleted']);
if (isset($_GET['scope'])) {
    $scope = $_GET['scope'];
    $wantActive  = in_array($scope, ['all', 'active'], true);
    $wantDeleted = in_array($scope, ['all', 'trash'], true);
}

if (!$isPartial && ($wantActive || $wantDeleted)) {
    $sql = "SELECT r.id, r.type, r.item_name, r.category, r.description, r.location,
                   r.item_date, r.image_path, r.status, r.created_at, r.deleted_at, r.resolved_by_name,
                   r.user_id, u.first_name, u.last_name, u.email
            FROM reports r
            LEFT JOIN users u ON u.id = r.user_id";
    if ($wantActive && !$wantDeleted) {
        $sql .= " WHERE r.deleted_at IS NULL";
    } elseif ($wantDeleted && !$wantActive) {
        $sql .= " WHERE r.deleted_at IS NOT NULL";
    }
    $sql .= " ORDER BY r.created_at DESC";

    $reports = $pdo->query($sql)->fetchAll();

    $label    = ($wantActive && $wantDeleted) ? 'all_reports_' : ($wantDeleted ? 'deleted_reports_' : 'active_reports_');
    $filename = $label . date('Y-m-d_His') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // Byte-order mark so the CSV opens with correct UTF-8 encoding in Excel.
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, [
        'ID', 'Type', 'Item Name', 'Category', 'Description', 'Location',
        'Item Date', 'Image Path', 'Status', 'Reported By', 'Reporter Email',
        'Created At', 'Deleted At', 'Resolved By',
    ]);

    foreach ($reports as $r) {
        fputcsv($out, [
            $r['id'],
            $r['type'],
            $r['item_name'],
            $r['category'],
            $r['description'],
            $r['location'],
            $r['item_date'],
            $r['image_path'],
            $r['status'],
            reporter_name($r),
            $r['email'],
            $r['created_at'],
            $r['deleted_at'],
            $r['resolved_by_name'],
        ]);
    }

    fclose($out);
    exit;
}

// --- The popup form ---------------------------------------------------
// Pre-select whichever tab the admin clicked Export from.
$fromTrash = (($_GET['view'] ?? '') === 'trash');

if (!$isPartial) {
    $assetPrefix = '../';
    $pageTitle = 'Export Reports';
    require_once __DIR__ . '/../includes/header.php';
}
?>
<?php if ($isPartial): ?><h2 class="modal-form-title">Export Reports to CSV</h2><?php else: ?><h1>Export Reports to CSV</h1>
<p><a href="reports.php<?= $fromTrash ? '?view=trash' : '' ?>">&larr; Back to Manage Reports</a></p><?php endif; ?>
<p class="hint-text">Choose what goes into the CSV. Turn on both to export everything.</p>

<form method="get" action="export_reports.php" class="form-card export-form" data-download="1">
    <label class="toggle-row">
        <span><strong>Active reports</strong><small>Reports currently visible on Browse</small></span>
        <input type="checkbox" name="active" value="1" class="switch"<?= $fromTrash ? '' : ' checked' ?>>
    </label>
    <label class="toggle-row">
        <span><strong>Deleted reports</strong><small>Reports in the Deleted Reports tab</small></span>
        <input type="checkbox" name="deleted" value="1" class="switch"<?= $fromTrash ? ' checked' : '' ?>>
    </label>
    <div class="form-submit span-2"><button type="submit">Export</button></div>
</form>
<?php if (!$isPartial) require_once __DIR__ . '/../includes/footer.php'; ?>
