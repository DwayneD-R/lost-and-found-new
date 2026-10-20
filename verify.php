<?php
require_once __DIR__ . '/includes/functions.php';
if (is_logged_in()) redirect('index.php');

// Verification normally happens as a modal over register.php or login.php
// (see VerifyModal in js/main.js), which talks to this same file over AJAX
// — detected here via the header fetch() sends. This page is kept working
// standalone too (plain form, full-page errors) as a fallback for an old
// bookmarked link or JS being unavailable.
$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

$errors = [];
$prefillEmail = $_SESSION['pending_verify_email'] ?? ($_GET['email'] ?? '');

// One-click link from the verification email (verify.php?email=...&code=...):
// verifies right away, then sends them to the login page. A stale or wrong
// link just falls through to the normal page below with an error.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['email']) && !empty($_GET['code'])) {
    $linkEmail = normalize_school_email($_GET['email']);
    $linkCode  = trim($_GET['code']);
    $stmt = $pdo->prepare('SELECT id, verify_code, verify_expires FROM users WHERE email = ? AND is_verified = 0');
    $stmt->execute([$linkEmail]);
    $linkUser = $stmt->fetch();
    if ($linkUser && $linkUser['verify_code'] && hash_equals((string) $linkUser['verify_code'], $linkCode)
        && strtotime($linkUser['verify_expires']) >= time()) {
        // One atomic step: only the request that still finds the code in place gets to use it.
        $upd = $pdo->prepare('UPDATE users SET is_verified = 1, verify_code = NULL, verify_expires = NULL WHERE id = ? AND is_verified = 0 AND verify_code = ?');
        $upd->execute([$linkUser['id'], $linkCode]);
        if ($upd->rowCount() === 1) {
            unset($_SESSION['pending_verify_email']);
            set_flash('Your account has been verified. You can now log in.', 'success');
            redirect('login.php');
        }
    } elseif ($linkUser && $linkUser['verify_code']) {
        code_register_failure($pdo, $linkUser['id']);   // a wrong code in a link counts as a wrong guess too
    }
    $errors[] = 'That verification link is invalid or has expired. Enter the code from your email, or request a new one below.';
}

// Handle "resend code"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend'])) {
    $email = normalize_school_email($_POST['email'] ?? '');
    $stmt = $pdo->prepare('SELECT id, first_name, verify_expires FROM users WHERE email = ? AND is_verified = 0');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Same message whether or not the account exists, to avoid leaking which emails are registered.
    $message = 'If that email needs verification, a new code has been sent.';
    if ($user && code_recently_sent($user)) {
        // Someone clicking Resend over and over would flood the inbox: one new code per minute.
        $message = 'A code was just sent. Please wait a minute before requesting another.';
    } elseif ($user) {
        send_verification_code($pdo, $user['id'], $email, $user['first_name']);
    }
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'message' => $message]);
        exit;
    }
    set_flash($message, 'info');
    $prefillEmail = $email;
}

// Handle code submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['code'])) {
    $email = normalize_school_email($_POST['email'] ?? '');
    $code  = trim($_POST['code'] ?? '');

    $stmt = $pdo->prepare(
        'SELECT id, verify_code, verify_expires FROM users WHERE email = ? AND is_verified = 0'
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        $errors[] = 'No pending verification found for that email.';
    } elseif (!$user['verify_code']) {
        $errors[] = $user['verify_expires']
            ? 'Too many wrong attempts, so that code no longer works. Request a new one.'
            : 'No active code for that email. Request a new one.';
    } elseif (!hash_equals((string) $user['verify_code'], $code)) {
        $burned = code_register_failure($pdo, $user['id']);
        $errors[] = $burned ? 'Too many wrong attempts, so that code no longer works. Request a new one.' : 'Incorrect code. Please try again.';
    } elseif (strtotime($user['verify_expires']) < time()) {
        $errors[] = 'This code has expired. Request a new one below.';
    } else {
        // One atomic step: the code is checked and used up in the same statement.
        $upd = $pdo->prepare('UPDATE users SET is_verified = 1, verify_code = NULL, verify_expires = NULL WHERE id = ? AND is_verified = 0 AND verify_code = ? AND verify_expires >= NOW()');
        $upd->execute([$user['id'], $user['verify_code']]);
        if ($upd->rowCount() !== 1) {
            $errors[] = 'This code has expired. Request a new one below.';
        } else {
            unset($_SESSION['pending_verify_email']);
            // The pop-up moves on to login.php straight away, so a toast shown on this page would vanish —
            // leave the message for the login page to show.
            set_flash('Your account has been verified. You can now log in.', 'success');
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'redirect' => 'login.php', 'message' => 'Your account has been verified. You can now log in.']);
                exit;
            }
            redirect('login.php');
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => $errors[0]]);
        exit;
    }
    $prefillEmail = $email;
}

$pageTitle = 'Verify Your Account';
require_once __DIR__ . '/includes/header.php';
?>
<h1>Enter Verification Code</h1>
<p>We sent a 6-digit code to your school email. Enter it below to activate your account.</p>

<?php foreach ($errors as $err): ?>
    <script>(window.__pendingToasts = window.__pendingToasts || []).push({msg: <?= json_encode($err) ?>, type: 'error'});</script>
<?php endforeach; ?>

<form method="post" class="form-card">
    <label>School Email
        <?= school_email_input($prefillEmail) ?>
    </label>
    <label>Verification Code
        <div class="code-boxes" data-code-boxes data-name="code" data-required data-autofocus></div>
    </label>
    <button type="submit">Verify</button>
</form>

<form method="post" class="form-card" style="margin-top:10px;">
    <input type="hidden" name="email" value="<?= e($prefillEmail) ?>">
    <button type="submit" name="resend" value="1" class="btn" style="background:#4a4f5a;">Resend Code</button>
</form>

<p><a href="login.php">Back to login</a></p>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
