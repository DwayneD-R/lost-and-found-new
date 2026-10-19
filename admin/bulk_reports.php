<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin();

// Applies the single-report actions (../delete_report.php, restore_report.php,
// delete_report_permanent.php, request_update.php) to many ids from the bulk actions bar on
// reports.php. It calls the very same functions (includes/functions.php), so every report is locked,
// re-checked and changed under the same rules as when done one at a time; anything another admin or
// the owner changed in the meantime is skipped and counted, never overwritten.

$ids = array_values(array_unique(array_filter(array_map('intval', $_POST['report_ids'] ?? []))));
$action = $_POST['bulk_action'] ?? '';
$backTo = ($_POST['view'] ?? '') === 'trash' ? 'reports.php?view=trash' : 'reports.php';

if (!$ids) {
    set_flash('No reports selected.', 'error');
    redirect($backTo);
}

$count = count($ids);
$myId  = (int) $_SESSION['user_id'];

switch ($action) {
    case 'delete': // active -> trash
        $n = soft_delete_reports($pdo, $ids, $myId, true);
        set_flash($n . ' of ' . $count . ' report(s) moved to trash.' . ($n < $count ? ' The rest were already deleted or removed.' : ''), $n ? 'success' : 'info');
        break;

    case 'restore': // trash -> active; no status restriction for admins
        $n = restore_reports($pdo, $ids, $myId, true);
        set_flash($n . ' of ' . $count . ' report(s) restored.' . ($n < $count ? ' The rest were already restored or removed.' : ''), $n ? 'success' : 'info');
        break;

    case 'ask_resolved': // the bulk version of the "Ask if resolved" button (admin/request_update.php)
        $placeholders = implode(',', array_fill(0, $count, '?'));
        $askStmt = $pdo->prepare(
            "SELECT r.id, r.user_id, r.item_name, r.created_at, r.resolved_asked_at FROM reports r
             JOIN users u ON u.id = r.user_id
             WHERE r.id IN ($placeholders) AND r.deleted_at IS NULL AND r.status = 'active'
               AND r.user_id <> ? AND u.role <> 'admin'"
        );
        $askStmt->execute(array_merge($ids, [$myId]));
        $asked = 0;
        $tooRecent = 0;
        foreach ($askStmt->fetchAll() as $r) {
            if (ask_available_at($r) > time()) { $tooRecent++; continue; }   // shared cooldown (automatic + manual)
            if (request_report_update($pdo, $r)) $asked++; else $tooRecent++;   // false = someone else asked / changed it first
        }
        $skipped = $count - $asked;
        $why = $skipped ? ' (' . $skipped . ' skipped: not Active, an admin\'s own' . ($tooRecent ? ', or still in the ' . RESOLVED_ASK_EVERY_DAYS . '-day cooldown' : '') . ')' : '';
        set_flash('Asked ' . $asked . ' reporter(s) whether their report has been resolved' . $why . '.', $asked ? 'success' : 'info');
        break;

    case 'delete_permanent':
        // All-or-nothing: the rows are locked, then their conversations and the reports are removed
        // together (photo files only after the commit); only reports still in the trash qualify.
        $n = permanently_delete_reports($pdo, $ids);
        set_flash($n . ' of ' . $count . ' report(s) permanently deleted.' . ($n < $count ? ' The rest were restored or already removed.' : ''), $n ? 'success' : 'info');
        break;

    default:
        set_flash('Unknown bulk action.', 'error');
}

redirect($backTo);
