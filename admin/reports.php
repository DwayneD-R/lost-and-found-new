<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin();

auto_close_stale_reports($pdo);

$view = ($_GET['view'] ?? 'active') === 'trash' ? 'trash' : 'active';

if ($view === 'trash') {
    $reports = $pdo->query(
        "SELECT r.*, u.first_name, u.last_name, u.role AS reporter_role
         FROM reports r LEFT JOIN users u ON u.id = r.user_id
         WHERE r.deleted_at IS NOT NULL
         ORDER BY r.deleted_at DESC"
    )->fetchAll();
} else {
    $reports = $pdo->query(
        "SELECT r.*, u.first_name, u.last_name, u.role AS reporter_role
         FROM reports r LEFT JOIN users u ON u.id = r.user_id
         WHERE r.deleted_at IS NULL
         ORDER BY (r.status = 'closed'), r.created_at DESC"
    )->fetchAll();
}

$assetPrefix = '../';
$watch = 'manage';   // the live stream tells this page when any report changes
$pageTitle = 'Manage Reports';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-head page-head-admin">
    <h1>Manage Reports</h1>
    <div class="admin-links">
        <a class="btn btn-secondary" href="import_reports.php?view=<?= e($view) ?>">Import (CSV)</a>
        <a class="btn btn-secondary" href="export_reports.php?view=<?= e($view) ?>">Export (CSV)</a>
    </div>
</div>

<?php
// Active / Deleted Reports: one colour-coded dropdown on the search line (same
// control as All / Lost / Found on Browse) instead of a row of tabs.
$viewLabels = ['active' => 'Active Reports', 'trash' => 'Deleted Reports'];
ob_start(); ?>
<div class="segmented view-seg" role="group" aria-label="Which reports">
    <a class="seg-btn seg-active<?= $view === 'active' ? ' selected' : '' ?>" aria-pressed="<?= $view === 'active' ? 'true' : 'false' ?>" href="reports.php">Active</a>
    <a class="seg-btn seg-trash<?= $view === 'trash' ? ' selected' : '' ?>" aria-pressed="<?= $view === 'trash' ? 'true' : 'false' ?>" href="reports.php?view=trash">Deleted</a>
</div>
<?php $viewSwitch = ob_get_clean(); ?>

<?php if ($view === 'trash'): ?>

    <?= report_filter_toolbar(['active' => 'Active', 'resolved' => 'Resolved', 'closed' => 'Closed'], 'Search reports…', $viewSwitch) ?>

    <!-- The bulk form wraps ONLY the hidden inputs + actions bar. The row checkboxes
         join it through their form="bulk-form" attribute instead of sitting inside it,
         because the table's own per-row forms (status select, delete, restore) can't
         be nested inside another <form> — browsers silently drop nested form tags,
         which turned every row action into a bulk submit with nothing selected. -->
    <form method="post" action="bulk_reports.php" id="bulk-form">
        <input type="hidden" name="view" value="trash">
        <input type="hidden" name="bulk_action" id="bulk-action-input">

        <div class="bulk-actions-bar" id="bulk-actions-bar" hidden>
            <span id="bulk-selected-count">0 selected</span>
            <div class="bulk-actions-bar-buttons">
                <button type="button" class="btn btn-secondary" data-bulk-action="restore"
                        data-confirm="Restore the selected reports? They come back as active reports, visible on Browse again.">
                    <?= icon('restore') ?> Restore Selected
                </button>
                <button type="button" class="btn btn-danger" data-bulk-action="delete_permanent"
                        data-confirm="Permanently delete the selected reports? This cannot be undone — export a CSV backup first if you need one.">
                    <?= icon('trash') ?> Delete Permanently
                </button>
            </div>
        </div>

    </form>

        <div class="table-scroll"><table class="data-table" id="report-table">
            <thead>
                <tr>
                    <th class="select-col"><input type="checkbox" id="select-all" aria-label="Select all"></th>
                    <th class="sortable-th">Item</th><th class="sortable-th">Type</th><th class="sortable-th">Reporter</th><th class="sortable-th">Date Reported</th><th class="sortable-th">Deleted</th><th class="actions-th">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($reports as $r): ?>
                <tr data-report-id="<?= (int)$r['id'] ?>" data-type="<?= e($r['type']) ?>" data-category="<?= e($r['category']) ?>" data-status="<?= e($r['status']) ?>" title="Double-click to view this report">
                    <td class="select-col"><input type="checkbox" name="report_ids[]" class="row-select" form="bulk-form" value="<?= (int)$r['id'] ?>" aria-label="Select report"></td>
                    <td><span class="cell-text" title="<?= e($r['item_name']) ?>"><?= e($r['item_name']) ?></span></td>
                    <td><?= e(ucfirst($r['type'])) ?></td>
                    <td><span class="cell-text" title="<?= e(trim($r['first_name'] . ' ' . $r['last_name'])) ?>"><?= $r['user_id'] ? e($r['first_name'] . ' ' . $r['last_name']) : '<em class="detail-none">Deleted user</em>' ?></span></td>
                    <td class="date-reported-cell" data-sort-value="<?= (int)strtotime($r['created_at']) ?>"><?= report_dates_cell($r['created_at'], $r['status'], $r['reopened_at'] ?? null) ?></td>
                    <td data-sort-value="<?= (int)strtotime($r['deleted_at']) ?>"><?= e(date('M j, Y g:i A', strtotime($r['deleted_at']))) ?></td>
                    <td class="row-actions-cell">
                        <span class="row-actions">
                            <form method="post" action="restore_report.php" class="inline-form confirm-form"
                                  data-confirm-title="Restore this report?"
                                  data-confirm-message="This brings it back as an active report, visible on Browse again."
                                  data-confirm-label="Restore">
                                <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                                <button type="submit" class="icon-action-btn icon-restore" title="Restore report" aria-label="Restore report"><?= icon('restore') ?></button>
                            </form>
                            <form method="post" action="delete_report_permanent.php" class="inline-form confirm-form-typed"
                                  data-confirm-title="Permanently delete this report?"
                                  data-confirm-message="This cannot be undone — export a CSV backup first if you need one. The report and its photo reference will be gone for good."
                                  data-confirm-label="Delete Permanently" data-confirm-required-text="yes">
                                <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                                <button type="submit" class="icon-action-btn icon-delete" title="Delete permanently" aria-label="Delete permanently"><?= icon('trash') ?></button>
                            </form>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$reports): ?>
                <tr><td colspan="7">Trash is empty.</td></tr>
            <?php endif; ?>
            </tbody>
        </table></div>

<?php else: ?>

    <?= report_filter_toolbar(['active' => 'Active', 'resolved' => 'Resolved', 'closed' => 'Closed'], 'Search reports…', $viewSwitch) ?>

    <!-- The bulk form wraps ONLY the hidden inputs + actions bar. The row checkboxes
         join it through their form="bulk-form" attribute instead of sitting inside it,
         because the table's own per-row forms (status select, delete, restore) can't
         be nested inside another <form> — browsers silently drop nested form tags,
         which turned every row action into a bulk submit with nothing selected. -->
    <form method="post" action="bulk_reports.php" id="bulk-form">
        <input type="hidden" name="view" value="active">
        <input type="hidden" name="bulk_action" id="bulk-action-input">

        <div class="bulk-actions-bar" id="bulk-actions-bar" hidden>
            <span id="bulk-selected-count">0 selected</span>
            <div class="bulk-actions-bar-buttons">
                <button type="button" class="btn btn-secondary" data-bulk-action="ask_resolved"
                        data-confirm="Ask the reporters of the selected reports whether they have been resolved? Only Active reports are included (not an admin's own, and not any asked in the last <?= RESOLVED_ASK_EVERY_DAYS ?> days — automatic check-ins count too). Each reporter gets a System Notification, plus an email with a one-click Resolved button if they have those emails on.">
                    <?= icon('mail') ?> Ask if Resolved
                </button>
                <button type="button" class="btn btn-danger" data-bulk-action="delete"
                        data-confirm="Move the selected reports to trash?">
                    <?= icon('trash') ?> Delete Selected
                </button>
            </div>
        </div>

    </form>

        <div class="table-scroll"><table class="data-table" id="report-table">
            <thead>
                <tr>
                    <th class="select-col"><input type="checkbox" id="select-all" aria-label="Select all"></th>
                    <th class="sortable-th">Item</th><th class="sortable-th">Type</th><th class="sortable-th">Reporter</th><th class="sortable-th">Status</th><th class="sortable-th">Date</th><th class="sortable-th">Date Reported</th><th class="actions-th">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($reports as $r): ?>
                <tr data-report-id="<?= (int)$r['id'] ?>" data-type="<?= e($r['type']) ?>" data-category="<?= e($r['category']) ?>" data-status="<?= e($r['status']) ?>" title="Double-click to view this report">
                    <td class="select-col"><input type="checkbox" name="report_ids[]" class="row-select" form="bulk-form" value="<?= (int)$r['id'] ?>" aria-label="Select report"></td>
                    <td><span class="cell-text" title="<?= e($r['item_name']) ?>"><a href="../item.php?id=<?= (int)$r['id'] ?>"><?= e($r['item_name']) ?></a></span></td>
                    <td><?= e(ucfirst($r['type'])) ?></td>
                    <td><span class="cell-text" title="<?= e(trim($r['first_name'] . ' ' . $r['last_name'])) ?>"><?= $r['user_id'] ? e($r['first_name'] . ' ' . $r['last_name']) : '<em class="detail-none">Deleted user</em>' ?></span></td>
                    <td data-sort-value="<?= e($r['status']) ?>">
                        <?php $isClosed = $r['status'] === 'closed'; ?>
                        <form method="post" action="../update_status.php" class="inline-form">
                            <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                            <input type="hidden" name="expected" value="<?= e($r['status']) ?>">
                            <select name="status" class="confirm-select status-select status-select-<?= e($r['status']) ?>"
                                    data-confirm-title="<?= $isClosed ? 'Reopen this report?' : 'Change status?' ?>"
                                    data-confirm-message="<?= $isClosed
                                        ? 'Reopen this report as {value}? The reporter will be notified. If it is Active it closes again after ' . REPORT_CLOSE_DAYS . ' days unless it is resolved.'
                                        : 'Mark this report as {value}? The reporter will be notified.' ?>">
                                <?php foreach (['active', 'resolved', 'closed'] as $s): ?>
                                    <option value="<?= $s ?>" <?= $r['status'] === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </td>
                    <td data-sort-value="<?= (int)strtotime($r['item_date']) ?>"><?= e(date('M j, Y', strtotime($r['item_date']))) ?></td>
                    <td class="date-reported-cell" data-sort-value="<?= (int)strtotime($r['created_at']) ?>"><?= report_dates_cell($r['created_at'], $r['status'], $r['reopened_at'] ?? null) ?></td>
                    <td class="row-actions-cell">
                        <span class="row-actions">
                            <?php /* Manual version of the automatic check-in: only for Active (not Resolved/Closed) reports, and never for an admin's own report — the admin is the one looking at this page. */ ?>
                            <?php if ($r['status'] === 'active' && $r['user_id'] && $r['reporter_role'] !== 'admin'): ?>
                                <?php
                                $askAt   = ask_available_at($r);
                                $cooling = $askAt > time();
                                $who     = $r['first_name'] . ' ' . $r['last_name'];
                                $cooldownNote = 'A reporter can be asked once every ' . RESOLVED_ASK_EVERY_DAYS . ' days — automatic check-ins count too. '
                                    . ($r['resolved_asked_at'] ? 'Last asked: ' . date('M j', strtotime($r['resolved_asked_at'])) . '.' : 'Not asked yet.');
                                ?>
                                <form method="post" action="request_update.php" class="inline-form confirm-form"
                                      data-confirm-title="Ask if this has been resolved?"
                                      data-confirm-message="<?= e($who) ?> gets a System Notification asking whether “<?= e($r['item_name']) ?>” has been resolved, plus an email with a one-click Resolved button if they have those emails on. Cooldown: <?= e($cooldownNote) ?>"
                                      data-confirm-label="Send">
                                    <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                                    <button type="submit" class="icon-action-btn icon-ask" <?= $cooling ? 'disabled' : '' ?>
                                            title="<?= $cooling ? e('Asked ' . date('M j', strtotime($r['resolved_asked_at'])) . ' — can ask again ' . date('M j', $askAt)) : 'Ask if resolved' ?>"
                                            aria-label="Ask if resolved"><?= icon('mail') ?></button>
                                </form>
                            <?php endif; ?>
                            <?php if ($r['status'] !== 'closed'): ?>
                                <a class="icon-action-btn icon-edit" href="../edit_report.php?id=<?= (int)$r['id'] ?>" title="Edit report" aria-label="Edit report"><?= icon('edit') ?></a>
                            <?php endif; ?>
                            <form method="post" action="../delete_report.php" class="inline-form confirm-form"
                                  data-confirm-title="Delete this report?"
                                  data-confirm-message="This moves it to the trash — it's hidden from Browse, but can be restored or permanently deleted from the Deleted Reports tab."
                                  data-confirm-label="Delete" data-confirm-danger="1">
                                <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                                <button type="submit" class="icon-action-btn icon-delete" title="Delete report" aria-label="Delete report"><?= icon('trash') ?></button>
                            </form>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$reports): ?>
                <tr><td colspan="8">No active reports.</td></tr>
            <?php endif; ?>
            </tbody>
        </table></div>

<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
