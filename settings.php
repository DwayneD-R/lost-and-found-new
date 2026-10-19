<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

// "Settings" in the side menu: which emails you want. Opens as a popup (the
// same ReportModal as Report Item / Export: an AJAX request gets just the
// form, a normal visit gets it as a full page).
//
// Account verification and password-reset emails are NOT here — they're needed
// to use the account, so they're always sent. The notifications inside the
// site (the bell, Messages) aren't affected by these settings either.
$isPartial = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
$myId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare('UPDATE users SET email_matches = ?, email_resolved = ?, email_conversations = ?, email_reports = ? WHERE id = ?');
    $stmt->execute([
        empty($_POST['email_matches']) ? 0 : 1,
        empty($_POST['email_resolved']) ? 0 : 1,
        empty($_POST['email_conversations']) ? 0 : 1,
        empty($_POST['email_reports']) ? 0 : 1,
        $myId,
    ]);
    if ($isPartial) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'close' => true, 'message' => 'Email settings saved.']);
        exit;
    }
    set_flash('Email settings saved.', 'success');
    redirect('settings.php');
}

$stmt = $pdo->prepare('SELECT email_matches, email_resolved, email_conversations, email_reports FROM users WHERE id = ?');
$stmt->execute([$myId]);
$prefs = $stmt->fetch() ?: ['email_matches' => 1, 'email_resolved' => 1, 'email_conversations' => 1, 'email_reports' => 1];

$options = [
    'email_matches'       => ['Possible matches', 'When a lost or found report may match one of yours'],
    'email_resolved'      => ['Is it resolved?', 'A check-in asking whether your report has been resolved, with a one-click "mark as Resolved" button'],
    'email_conversations' => ['New conversations', 'When someone starts a conversation with you'],
    'email_reports'       => ['Report updates', 'When your report is edited, closed or reopened (by an administrator, or automatically after ' . REPORT_CLOSE_DAYS . ' days)'],
];

if (!$isPartial) {
    $pageTitle = 'Settings';
    require_once __DIR__ . '/includes/header.php';
}
?>
<?php if ($isPartial): ?><h2 class="modal-form-title">Email Settings</h2><?php else: ?><h1>Email Settings</h1><?php endif; ?>
<p class="hint-text">Choose which emails you receive. Notifications on the site itself (the bell and Messages) always keep working.</p>

<form method="post" action="<?= e($_SERVER['SCRIPT_NAME']) ?>" class="form-card export-form settings-form">
    <?php foreach ($options as $name => [$title, $desc]): ?>
        <label class="toggle-row">
            <span><strong><?= e($title) ?></strong><small><?= e($desc) ?></small></span>
            <input type="checkbox" name="<?= e($name) ?>" value="1" class="switch"<?= !empty($prefs[$name]) ? ' checked' : '' ?>>
        </label>
    <?php endforeach; ?>
    <p class="hint-text span-2" style="margin:0;">Verification and password-reset emails are always sent, since you need them to use your account.</p>
    <div class="form-submit span-2"><button type="submit">Save</button></div>
</form>
<?php if (!$isPartial) require_once __DIR__ . '/includes/footer.php'; ?>
