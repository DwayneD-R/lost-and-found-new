<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

auto_close_stale_reports($pdo);

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT r.*, u.first_name, u.last_name, u.id AS owner_id
     FROM reports r LEFT JOIN users u ON u.id = r.user_id
     WHERE r.id = ?"
);
$stmt->execute([$id]);
$report = $stmt->fetch();

$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$isPartial = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
if (!$report || ($report['deleted_at'] !== null && !$isAdmin)) {
    // The report modal (ItemModal in js/main.js) turns this 410 into a
    // "report was deleted" toast, e.g. from a stale match / edit notification.
    if ($isPartial) {
        http_response_code(410);
        die('This report has been deleted.');
    }
    // A direct visit (an email link, a stale bookmark): land back on the site with a toast, not a blank page.
    set_flash('That report was deleted or no longer exists.', 'error');
    redirect('index.php');
}

// Viewing a report opens as a modal over whatever page you were on (see
// ItemModal in js/main.js) rather than navigating to a new page — this is
// that modal's content, fetched over AJAX. It can be opened from any page
// at any depth (root pages, or admin/ one level down), so its own internal
// links/image need a path that works regardless of where the browser's
// current URL actually is — plain relative paths like "edit_report.php"
// would otherwise resolve against the OPENING page's location, not
// item.php's. $itemBase makes every such reference root-relative.
// Full-page mode (no AJAX header — a direct visit or JS disabled) is
// untouched: $itemBase stays '' and every link behaves exactly as before.
$itemBase = $isPartial ? rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/' : '';

// Side-by-side mode: a possible-match notification links here as
// item.php?id=THEIR_REPORT&mine=YOUR_REPORT. Your report goes on the left, the
// possible match on the right. "mine" only counts if it really is one of the
// signed-in user's own (not trashed) reports; otherwise this is the normal
// single-report view.
$mine = null;
$mineId = (int)($_GET['mine'] ?? 0);
if ($mineId && $mineId !== $id) {
    $mineStmt = $pdo->prepare(
        "SELECT r.*, u.first_name, u.last_name, u.id AS owner_id
         FROM reports r LEFT JOIN users u ON u.id = r.user_id
         WHERE r.id = ? AND r.user_id = ? AND r.deleted_at IS NULL"
    );
    $mineStmt->execute([$mineId, $_SESSION['user_id']]);
    $mine = $mineStmt->fetch() ?: null;
}

/**
 * One report's details. $stack = the compact single-column version used for
 * each side of the comparison; $hideReporter drops the "Reported by" field
 * (pointless on your own report).
 */
function render_report_detail(array $report, $itemBase, $stack = false, $hideReporter = false) {
    $isOwner = $report['owner_id'] == $_SESSION['user_id'];
    ?>
<div class="item-detail<?= $stack ? ' item-detail-stack' : '' ?>" data-report-live data-report-id="<?= (int)$report['id'] ?>" data-report-status="<?= e($report['status']) ?>">
    <div class="item-detail-image">
        <?php if ($report['image_path'] && report_image_exists($report['image_path'])): ?>
            <img class="zoomable" src="<?= $itemBase ?><?= e($report['image_path']) ?>" alt="<?= e($report['item_name']) ?>" loading="lazy" title="Click to enlarge">
        <?php else: ?>
            <div class="no-image large">No Image</div>
        <?php endif; ?>
    </div>
    <div class="item-detail-info">
        <div class="item-detail-head">
            <div class="item-detail-tags">
                <span class="badge badge-<?= e($report['type']) ?>"><?= e(ucfirst($report['type'])) ?></span>
                <span class="status status-<?= e($report['status']) ?>"><?= e(status_label($report['status'])) ?></span>
            </div>
            <h1><?= e($report['item_name']) ?></h1>
        </div>

        <!-- Each detail sits in its own field with a capped height that
             scrolls internally, so a long location or description never
             pushes the action button (below) out of view. -->
        <div class="detail-grid">
            <div class="detail-field">
                <span class="detail-label">Category</span>
                <div class="detail-value"><?= e($report['category']) ?></div>
            </div>
            <div class="detail-field">
                <span class="detail-label">Date Lost / Found</span>
                <div class="detail-value"><?= e(date('M j, Y', strtotime($report['item_date']))) ?></div>
            </div>
            <div class="detail-field span-2">
                <span class="detail-label">Location Last Seen / Found</span>
                <div class="detail-value"><?= e($report['location']) ?></div>
            </div>
            <?php if (!$hideReporter): ?>
            <div class="detail-field span-2">
                <span class="detail-label">Reported by</span>
                <div class="detail-value"><?= $report['owner_id'] ? e($report['first_name'] . ' ' . $report['last_name']) : '<em class="detail-none">Deleted user</em>' ?></div>
            </div>
            <?php endif; ?>
            <?php if ($report['status'] === 'resolved' && !empty($report['resolved_by_name'])): ?>
            <div class="detail-field span-2">
                <span class="detail-label">Found / claimed by</span>
                <div class="detail-value"><?= e($report['resolved_by_name']) ?></div>
            </div>
            <?php endif; ?>
        </div>
        <div class="detail-field detail-desc">
            <span class="detail-label">Description</span>
            <div class="detail-value"><?= $report['description'] ? nl2br(e($report['description'])) : '<em class="detail-none">No description provided.</em>' ?></div>
        </div>

        <?php
        // Closed reports can't be edited (only deleted, from the report tables).
        $canEdit = $report['status'] !== 'closed';
        // Closed = nobody can claim it any more, so people are pointed at the admin.
        $contactLabel = ($report['status'] === 'closed' && ($_SESSION['role'] ?? '') !== 'admin') ? 'Chat Admin' : 'Chat Reporter';
        // A resolved report is done, so people (other than admins) aren't offered a way to message its reporter.
        // Nor is there anyone to message when the reporter's account was deleted.
        $hideContact = !$report['owner_id'] || ($report['status'] === 'resolved' && !$isOwner && ($_SESSION['role'] ?? '') !== 'admin');
        ?>
        <?php if ($report['deleted_at'] === null && (!$isOwner || $canEdit) && (!$hideContact || (($_SESSION['role'] ?? '') === 'admin' && $canEdit))): ?>
            <div class="item-actions">
            <?php if ($isOwner): ?>
                <a class="btn btn-secondary" href="<?= $itemBase ?>edit_report.php?id=<?= (int)$report['id'] ?>">Edit Report</a>
            <?php else: ?>
                <?php if (!$hideContact): ?>
                <form method="post" action="<?= $itemBase ?>start_conversation.php">
                    <input type="hidden" name="report_id" value="<?= (int)$report['id'] ?>">
                    <button type="submit" class="btn"><?= e($contactLabel) ?></button>
                </form>
                <?php endif; ?>
                <?php if (($_SESSION['role'] ?? '') === 'admin' && $canEdit): ?>
                    <a class="btn btn-secondary" href="<?= $itemBase ?>edit_report.php?id=<?= (int)$report['id'] ?>">Edit Report</a>
                <?php endif; ?>
            <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php
}

if (!$isPartial) {
    $pageTitle = $report['item_name'];
    $watch = 'item:' . (int) $report['id'];   // if someone changes or deletes this report while you're looking at it, this page finds out
    require_once __DIR__ . '/includes/header.php';
}
?>
<?php if ($report['deleted_at'] !== null): ?>
    <div class="flash flash-error">This report is in the trash — only visible to admins. <a href="<?= $itemBase ?>admin/reports.php?view=trash">Manage in Trash &rarr;</a></div>
<?php endif; ?>
<?php if ($mine): ?>
    <div class="item-compare">
        <div class="compare-col">
            <p class="compare-label">Your report</p>
            <?php render_report_detail($mine, $itemBase, true, true); ?>
        </div>
        <div class="compare-col compare-col-match">
            <p class="compare-label compare-label-match">Possible match</p>
            <?php render_report_detail($report, $itemBase, true); ?>
        </div>
    </div>
<?php else: ?>
    <?php render_report_detail($report, $itemBase, false); ?>
<?php endif; ?>
<?php if (!$isPartial) require_once __DIR__ . '/includes/footer.php'; ?>
