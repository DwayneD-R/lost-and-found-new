<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin();

// last_active_at is stamped by housekeeping() / the live stream (see sql/schema.sql); the Online now marks
// are kept current by the stream's "presence" event (js/main.js).
$users = $pdo->prepare(
    'SELECT u.*,
            (SELECT COUNT(*) FROM reports r WHERE r.user_id = u.id AND r.deleted_at IS NULL AND r.status = \'active\') AS active_reports,
            EXISTS (SELECT 1 FROM presence p WHERE p.user_id = u.id AND p.last_seen >= (NOW() - INTERVAL ' . (int) ONLINE_WINDOW_SECONDS . ' SECOND)) AS is_online
     FROM users u
     WHERE u.email <> ?
     ORDER BY u.created_at DESC'
);
$users->execute([SYSTEM_USER_EMAIL]); // the built-in System Notifications account isn't managed here
$users = $users->fetchAll();


$assetPrefix = '../';
$watch = 'users';   // the live stream tells this page who is online / offline (no polling, no refresh)
$pageTitle = 'Manage Users';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-head page-head-admin">
    <h1>Manage Users</h1>
</div>
<?= user_filter_toolbar() ?>

<!-- Same layout as Manage Reports: the bulk form wraps only the hidden inputs and the
     actions bar; the row checkboxes join it through their form="bulk-form" attribute. -->
<form method="post" action="bulk_users.php" id="bulk-form">
    <input type="hidden" name="bulk_action" id="bulk-action-input">

    <div class="bulk-actions-bar" id="bulk-actions-bar" data-verify-url="verify_password.php" hidden>
        <span id="bulk-selected-count">0 selected</span>
        <div class="bulk-actions-bar-buttons">
            <button type="button" class="btn btn-secondary" data-bulk-action="verify" data-bulk-password="1"
                    data-confirm="This manually marks the selected accounts as verified, skipping their email code — they'll be able to log in right away. Accounts that are already verified are left alone. Enter your password to confirm it's you.">
                <?= icon('check') ?> Verify Selected
            </button>
            <button type="button" class="btn btn-danger" data-bulk-action="delete" data-bulk-password="1"
                    data-confirm="This removes the selected accounts from use. Their reports and conversations are preserved, but the accounts cannot log in or receive new messages. Enter your password to confirm it's you.">
                <?= icon('trash') ?> Delete Selected
            </button>
        </div>
    </div>
</form>

<div class="table-scroll"><table class="data-table" id="report-table" data-noun="user">
    <thead>
        <tr>
            <th class="select-col"><input type="checkbox" id="select-all" aria-label="Select all"></th>
            <th class="sortable-th">Name</th>
            <th class="sortable-th">Email</th>
            <th class="sortable-th">Role</th>
            <th class="sortable-th">Verified</th>
            <th class="sortable-th">Active Reports</th>
            <th class="sortable-th">Last Active</th>
            <th class="sortable-th">Joined</th>
            <th class="actions-th">Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($users as $u): ?>
        <?php
            $isBuiltIn = $u['email'] === SYSTEM_USER_EMAIL;
            $isMe      = (int) $u['id'] === (int) $_SESSION['user_id'];
            $canSelect = !$isBuiltIn && !$isMe;
            $lastTs    = !empty($u['last_active_at']) ? (int) strtotime($u['last_active_at']) : 0;
            // Online = has a live connection right now (presence table), judged by the database clock.
            $isOnline  = !empty($u['is_online']);
        ?>
        <?php $effectiveActive = $lastTs ?: (int) strtotime($u['created_at']); // no recorded activity yet -> counts from when they joined ?>
        <tr data-user-id="<?= (int)$u['id'] ?>" data-role="<?= e($u['role']) ?>" data-verified="<?= $u['is_verified'] ? 'yes' : 'no' ?>" data-last-active="<?= $effectiveActive ?>" data-joined="<?= (int) strtotime($u['created_at']) ?>">
            <td class="select-col">
                <?php if ($canSelect): ?>
                    <input type="checkbox" name="user_ids[]" class="row-select" form="bulk-form" value="<?= (int)$u['id'] ?>" aria-label="Select user">
                <?php endif; ?>
            </td>
            <td><span class="cell-text" title="<?= e(trim($u['first_name'] . ' ' . $u['last_name'])) ?>"><?= e($u['first_name'] . ' ' . $u['last_name']) ?></span></td>
            <td><span class="cell-text" title="<?= e($u['email']) ?>"><?= e($u['email']) ?></span></td>
            <td><?= e(ucfirst($u['role'])) ?></td>
            <td><?= $u['is_verified'] ? 'Yes' : 'No' ?></td>
            <td data-sort-value="<?= (int)$u['active_reports'] ?>"><?= (int)$u['active_reports'] ?></td>
            <td class="last-active-cell" data-sort-value="<?= $lastTs ?>">
                <?php if ($isOnline): ?>
                    <span class="online-now">Online now</span>
                <?php elseif ($lastTs): ?>
                    <?= e(date('M j, Y', $lastTs)) ?>
                <?php else: ?>
                    <span class="detail-none">Never</span>
                <?php endif; ?>
            </td>
            <td data-sort-value="<?= (int)strtotime($u['created_at']) ?>"><?= e(date('M j, Y', strtotime($u['created_at']))) ?></td>
            <td class="row-actions-cell">
                <span class="row-actions">
                <?php if ($isBuiltIn): ?>
                    <em>Built-in</em>
                <?php elseif (!$isMe): ?>
                    <?php if (!$u['is_verified']): ?>
                        <!-- Verifying is still "on someone else's account", so it
                             gets the same password check as delete, not just a
                             plain confirm — an accidental click here silently
                             lets an unverified account log in. -->
                        <form method="post" action="verify_user.php" class="inline-form confirm-form-password"
                              data-confirm-title="Verify this account?"
                              data-confirm-message="This manually marks <?= e($u['first_name'] . ' ' . $u['last_name']) ?>'s account as verified, skipping their email code — they'll be able to log in right away. Enter your password to confirm it's you."
                              data-confirm-label="Verify Account"
                              data-confirm-verify-url="verify_password.php">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <button type="submit" class="icon-action-btn icon-verify" title="Verify account" aria-label="Verify account"><?= icon('check') ?></button>
                        </form>
                    <?php endif; ?>
                    <form method="post" action="delete_user.php" class="inline-form confirm-form-password"
                          data-confirm-title="Delete this account?"
                          data-confirm-message="This removes <?= e($u['first_name'] . ' ' . $u['last_name']) ?>'s account from use. Their reports and conversations are preserved, but the account cannot log in or receive new messages. Enter your password to confirm it's you."
                          data-confirm-label="Delete Account"
                          data-confirm-verify-url="verify_password.php">
                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                        <button type="submit" class="icon-action-btn icon-delete" title="Delete account" aria-label="Delete account"><?= icon('trash') ?></button>
                    </form>
                <?php else: ?>
                    <em>You</em>
                <?php endif; ?>
                </span>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
