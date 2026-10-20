<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/mail/emails.php'; // resolve_token()

// The button in the "has your report been resolved?" email lands here. No login needed: the signed
// link itself (report + person + expiry) is the proof.
//
// Opening the link (GET) only shows a page — the change is made by the POST a button on it sends. That
// way an email scanner that merely "visits" links can't resolve a report by accident. The page asks who
// found / claimed the item and lists the people this report's owner has talked to, so that is ONE click
// (no typing): a name, "Someone else" (type a name), or "Skip".
$reportId = (int) ($_GET['r'] ?? $_POST['r'] ?? 0);
$userId   = (int) ($_GET['u'] ?? $_POST['u'] ?? 0);
$exp      = (int) ($_GET['e'] ?? $_POST['e'] ?? 0);
$token    = (string) ($_GET['t'] ?? $_POST['t'] ?? '');

$state  = 'invalid';   // invalid | confirm | done | already | closed
$report = null;
$people = [];
$flash  = '';

if (APP_SECRET !== '' && $token !== '' && $reportId && $userId && $exp > time() && hash_equals(resolve_token($reportId, $userId, $exp), $token)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Lock the report, check it again, change it — all or nothing.
        $outcome = db_transaction($pdo, function () use ($pdo, $reportId, $userId) {
            $stmt = $pdo->prepare('SELECT id, item_name, status FROM reports WHERE id = ? AND user_id = ? AND deleted_at IS NULL FOR UPDATE');
            $stmt->execute([$reportId, $userId]);
            $r = $stmt->fetch();
            if (!$r) return ['invalid', null];
            if ($r['status'] === 'resolved') return ['already', $r];
            if ($r['status'] === 'closed')   return ['closed', $r];

            $byId = null; $name = null;
            $choice = (string) ($_POST['resolved_by'] ?? '');
            if ($choice === 'other' && trim((string) ($_POST['resolved_by_name'] ?? '')) === '') {
                return ['needname', $r];   // pressed Save with the box empty: nothing is changed
            }
            if ($choice === 'other') {
                $name = mb_substr(trim((string) $_POST['resolved_by_name']), 0, 120);
            } elseif (ctype_digit($choice)) {
                foreach (report_resolver_candidates($pdo, $reportId, $userId) as $c) {
                    if ($c['id'] === (int) $choice) { $byId = $c['id']; $name = $c['name']; }
                }
            }
            $upd = $pdo->prepare("UPDATE reports SET status = 'resolved', resolved_by_user_id = ?, resolved_by_name = ?, resolved_at = NOW() WHERE id = ? AND status = 'active'");
            $upd->execute([$byId, $name, $reportId]);
            return [$upd->rowCount() === 1 ? 'done' : 'already', $r];
        });
        [$state, $report] = $outcome;
        if ($state === 'needname') {
            $state  = 'confirm';
            $people = report_resolver_candidates($pdo, $reportId, $userId);
            $flash  = 'Type a name first, or pick someone from the list, or skip.';
        }
    } else {
        $stmt = $pdo->prepare('SELECT id, item_name, status FROM reports WHERE id = ? AND user_id = ? AND deleted_at IS NULL');
        $stmt->execute([$reportId, $userId]);
        $report = $stmt->fetch();
        if ($report) {
            if ($report['status'] === 'resolved')    $state = 'already';
            elseif ($report['status'] === 'closed')  $state = 'closed';
            else { $state = 'confirm'; $people = report_resolver_candidates($pdo, $reportId, $userId); }
        }
    }
}

$pageTitle = 'Mark report as Resolved';
require_once __DIR__ . '/includes/header.php';

$hidden = '<input type="hidden" name="r" value="' . (int) $reportId . '"><input type="hidden" name="u" value="' . (int) $userId . '">'
        . '<input type="hidden" name="e" value="' . (int) $exp . '"><input type="hidden" name="t" value="' . e($token) . '">';
?>
<div class="form-card resolver-card" style="max-width:520px;margin:40px auto;text-align:center;display:block;">
    <?php if ($state === 'confirm'): ?>
        <h1>Mark your report as Resolved</h1>
        <p>“<?= e($report['item_name']) ?>”</p>
        <p><strong>Who found or claimed it?</strong><br><small>One click is all it takes — you can skip this.</small></p>
        <?php if ($flash !== ''): ?><div class="flash flash-error"><?= e($flash) ?></div><?php endif; ?>
        <form method="post" action="resolve_report.php" class="resolver-form" id="resolver-form">
            <?= $hidden ?>
            <select name="resolved_by" class="status-select status-select-resolved resolver-select" aria-label="Who found or claimed the item">
                <option value="">Select someone (optional)</option>
                <?php foreach ($people as $p): ?>
                    <option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option>
                <?php endforeach; ?>
                <option value="other">Someone else</option>
            </select>
            <div class="resolver-other">
                <input type="text" name="resolved_by_name" maxlength="120" placeholder="Someone else — type a name">
            </div>
            <div class="modal-actions">
                <button type="submit" name="resolved_by" value="" class="btn btn-secondary resolver-skip" formnovalidate>Skip — just mark it Resolved</button>
                <button type="submit" name="resolved_by" value="other" class="btn" id="resolver-save">Save</button>
            </div>
        </form>
        <script>
        // Save with an empty name box: an error toast and nothing is sent. Enter in the box means Save
        // (not the first person's name, which is what a form's default button would be).
        (function () {
            var form = document.getElementById('resolver-form');
            var input = form.querySelector('[name="resolved_by_name"]');
            var picker = form.querySelector('[name="resolved_by"]');
            var save = document.getElementById('resolver-save');
            var updateOtherField = function () {
                var isOther = picker.value === 'other';
                input.disabled = !isOther;
                input.required = isOther;
                if (!isOther) input.value = '';
            };
            picker.addEventListener('change', updateOtherField);
            updateOtherField();
            input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); save.click(); } });
            form.addEventListener('submit', function (e) {
                if (e.submitter === save && picker.value === 'other' && input.value.trim() === '') {
                    e.preventDefault();
                    Toast.show('Type a name first, or pick someone from the list, or skip.', 'error');
                    input.focus();
                }
            });
        })();
        </script>
    <?php elseif ($state === 'done'): ?>
        <h1>Thanks — it's marked as Resolved</h1>
        <p>“<?= e($report['item_name']) ?>” is now Resolved, so it stays open and won't be closed automatically.</p>
        <p><a class="btn" href="index.php">Back to the site</a></p>
    <?php elseif ($state === 'already'): ?>
        <h1>Already marked as Resolved</h1>
        <p>“<?= e($report['item_name']) ?>” was already Resolved — nothing more to do.</p>
        <p><a class="btn" href="index.php">Back to the site</a></p>
    <?php elseif ($state === 'closed'): ?>
        <h1>This report is closed</h1>
        <p>“<?= e($report['item_name']) ?>” has already been closed. Contact an admin if it should be reopened.</p>
        <p><a class="btn" href="index.php">Back to the site</a></p>
    <?php else: ?>
        <h1>This link isn't valid</h1>
        <p>It may have expired, or the report no longer exists. You can still change a report's status from <strong>My Reports</strong> after logging in.</p>
        <p><a class="btn" href="login.php">Log in</a></p>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
