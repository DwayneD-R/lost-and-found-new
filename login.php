<?php
require_once __DIR__ . '/includes/functions.php';
if (is_logged_in()) redirect('index.php');

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = normalize_school_email($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        // Shown as a toast (see the __pendingToasts script further down).
        $errors[] = 'No account found with that email address.';
    } elseif (!password_verify($password, $user['password_hash'])) {
        $errors[] = 'Incorrect password.';
    } elseif (!$user['is_verified']) {
        // Don't just tell them to go verify — send a fresh code and take
        // them straight to the page that accepts it, since there was
        // otherwise no way back to it once the registration page was closed.
        send_verification_code($pdo, $user['id'], $email, $user['first_name']);
        $_SESSION['pending_verify_email'] = $email;
        set_flash('Please verify your email before logging in — we just sent a new code to ' . $email . '.', 'info');
        // Modal over this page instead of navigating to verify.php — see
        // VerifyModal in js/main.js and the matching note in register.php.
        $pendingVerifyEmail = $email;
    } else {
        session_regenerate_id(true);   // a new session id at login, so an old one can't be reused
        $_SESSION['last_request'] = time();
        $_SESSION['user_id']    = $user['id'];
        $_SESSION['first_name'] = $user['first_name'];
        $_SESSION['role']       = $user['role'];
        // Back to the page they were trying to open (e.g. the "Compare reports" link in an email), if any.
        $back = (string) ($_SESSION['return_to'] ?? '');
        unset($_SESSION['return_to']);
        if ($back !== '' && $back[0] === '/' && strncmp($back, '//', 2) !== 0 && strpbrk($back, "\\\r\n") === false
            && !preg_match('#/(login|logout|register|verify|forgot_password)\.php|/ajax/#', $back)) {
            redirect($back);
        }
        redirect($user['role'] === 'admin' ? 'admin/index.php' : 'index.php');
    }
}

$pageTitle = 'Login';
require_once __DIR__ . '/includes/header.php';
?>
<h1>Log In</h1>
<?php foreach ($errors as $err): ?>
    <script>(window.__pendingToasts = window.__pendingToasts || []).push({msg: <?= json_encode($err) ?>, type: 'error'});</script>
<?php endforeach; ?>

<form method="post" class="form-card">
    <label>School Email
        <?= school_email_input($_POST['email'] ?? '', 'username') ?>
    </label>
    <label>Password
        <input type="password" name="password" required>
    </label>
    <button type="submit">Log In</button>
</form>
<p><a href="#" data-forgot-password>Forgot your password?</a></p>
<p>No account yet? <a href="register.php">Register</a></p>
<?php if (isset($pendingVerifyEmail)): ?>
<script>document.addEventListener('DOMContentLoaded', () => VerifyModal.open(<?= json_encode($pendingVerifyEmail) ?>));</script>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
