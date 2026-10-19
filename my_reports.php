<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

auto_close_stale_reports($pdo);

// Default row order: reports you can edit and delete, then ones you can only delete
// (closed), then deleted ones you can restore, then deleted ones waiting on an admin.
$canRestoreAll = (($_SESSION['role'] ?? '') === 'admin') ? 1 : 0;
$stmt = $pdo->prepare(
    "SELECT * FROM reports WHERE user_id = ?
     ORDER BY CASE
         WHEN deleted_at IS NULL AND status <> 'closed' THEN 0
         WHEN deleted_at IS NULL THEN 1
         WHEN status = 'active' OR $canRestoreAll = 1 THEN 2
         ELSE 3
     END, created_at DESC"
);
$stmt->execute([$_SESSION['user_id']]);
$reports = $stmt->fetchAll();

$watch = 'mine';   // the live stream tells this page when any of your reports change
$pageTitle = 'My Reports';
require_once __DIR__ . '/includes/header.php';
?>
<h1>My Reports</h1>

<?= report_filter_toolbar(['active' => 'Active', 'resolved' => 'Resolved', 'closed' => 'Closed', 'deleted' => 'Deleted'], 'Search reports…') ?>

<div class="table-scroll"><table class="data-table" id="report-table">
    <thead>
        <tr><th class="sortable-th">Item</th><th class="sortable-th">Type</th><th class="sortable-th">Status</th><th class="sortable-th">Date</th><th class="sortable-th">Date Reported</th><th class="actions-th">Actions</th></tr>
    </thead>
    <tbody>
    <?php foreach ($reports as $r): ?>
        <?php $isDeleted = $r['deleted_at'] !== null; $isAdminUser = ($_SESSION['role'] ?? '') === 'admin'; ?>
        <tr<?= $isDeleted ? ' class="row-deleted"' : ' data-report-id="' . (int)$r['id'] . '" title="Double-click to view this report"' ?>
            data-type="<?= e($r['type']) ?>" data-category="<?= e($r['category']) ?>" data-status="<?= $isDeleted ? 'deleted' : e($r['status']) ?>">
            <td><span class="cell-text" title="<?= e($r['item_name']) ?>"><?php if ($isDeleted): ?><?= e($r['item_name']) ?><?php else: ?><a href="item.php?id=<?= (int)$r['id'] ?>"><?= e($r['item_name']) ?></a><?php endif; ?></span></td>
            <td><?= e(ucfirst($r['type'])) ?></td>
            <td data-sort-value="<?= $isDeleted ? 'deleted' : e($r['status']) ?>">
                <?php if ($isDeleted): ?>
                    <span class="status status-deleted">Deleted &mdash; pending removal</span>
                <?php elseif ($r['status'] === 'closed' && !$isAdminUser): ?>
                    <span class="status status-closed">Closed</span>
                <?php else: ?>
                    <?php /* Admins get the same status dropdown as Manage Reports: Active, Resolved or Closed, and a closed report can be reopened. */ ?>
                    <?php $statusChoices = $isAdminUser ? ['active', 'resolved', 'closed'] : ['active', 'resolved']; $isClosed = $r['status'] === 'closed'; ?>
                    <form method="post" action="update_status.php" class="inline-form">
                        <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="expected" value="<?= e($r['status']) ?>">
                        <select name="status" class="confirm-select status-select status-select-<?= e($r['status']) ?>"
                                data-confirm-title="<?= $isClosed ? 'Reopen this report?' : 'Change status?' ?>"
                                data-confirm-message="<?= $isClosed
                                    ? 'Reopen this report as {value}? If it is Active it closes again after ' . REPORT_CLOSE_DAYS . ' days unless it is resolved.'
                                    : "Mark this report as {value}? The other person (if any) will see the new status on the item's page." ?>">
                            <?php foreach ($statusChoices as $s): ?>
                                <option value="<?= $s ?>" <?= $r['status'] === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php endif; ?>
            </td>
            <td data-sort-value="<?= (int)strtotime($r['item_date']) ?>"><?= e(date('M j, Y', strtotime($r['item_date']))) ?></td>
            <td class="date-reported-cell" data-sort-value="<?= (int)strtotime($r['created_at']) ?>"><?= report_dates_cell($r['created_at'], $r['status'], $r['reopened_at'] ?? null) ?></td>
            <?php $awaitingReview = $isDeleted && $r['status'] !== 'active' && !$isAdminUser; ?>
            <td class="row-actions-cell<?= $awaitingReview ? ' awaiting-review' : '' ?>">
                <?php if ($isDeleted): ?>
                    <?php if ($r['status'] === 'active' || $isAdminUser): ?>
                        <?php /* Admins can restore any of their deleted reports, whatever its status. */ ?>
                        <span class="row-actions">
                        <form method="post" action="<?= $isAdminUser ? 'admin/restore_report.php' : 'restore_report.php' ?>" class="inline-form confirm-form"
                              data-confirm-title="Restore this report?"
                              data-confirm-message="<?= $isAdminUser ? "This brings it back as an active report — it'll be visible on Browse and your My Reports list again." : "This brings it back as an active report — it'll be visible on Browse and your My Reports list again." ?>"
                              data-confirm-label="Restore">
                            <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                            <button type="submit" class="icon-action-btn icon-restore" title="Restore report" aria-label="Restore report"><?= icon('restore') ?></button>
                        </form>
                        <?php if ($isAdminUser): ?>
                            <?php /* Same as the Deleted Reports tab in Manage Reports: admins can also remove it for good. */ ?>
                            <form method="post" action="admin/delete_report_permanent.php" class="inline-form confirm-form-typed"
                                  data-confirm-title="Permanently delete this report?"
                                  data-confirm-message="This cannot be undone — export a CSV backup first if you need one. The report and its photo will be gone for good."
                                  data-confirm-label="Delete Permanently" data-confirm-required-text="yes">
                                <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                                <button type="submit" class="icon-action-btn icon-delete" title="Delete permanently" aria-label="Delete permanently"><?= icon('trash') ?></button>
                            </form>
                        <?php endif; ?>
                        </span>
                    <?php else: ?>
                        <em>Awaiting admin review</em>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="row-actions">
                        <?php if ($r['status'] !== 'closed'): ?>
                            <a class="icon-action-btn icon-edit" href="edit_report.php?id=<?= (int)$r['id'] ?>" title="Edit report" aria-label="Edit report"><?= icon('edit') ?></a>
                        <?php endif; ?>
                        <form method="post" action="delete_report.php" class="inline-form confirm-form"
                              data-confirm-title="Delete this report?"
                              data-confirm-message="This moves it to the trash — it's hidden from Browse and My Reports, but you (or an admin) can still restore it later, while it's active."
                              data-confirm-label="Delete" data-confirm-danger="1">
                            <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                            <button type="submit" class="icon-action-btn icon-delete" title="Delete report" aria-label="Delete report"><?= icon('trash') ?></button>
                        </form>
                    </span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$reports): ?>
        <tr><td colspan="6">You haven't submitted any reports yet.</td></tr>
    <?php endif; ?>
    </tbody>
</table></div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
