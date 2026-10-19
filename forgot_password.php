<?php
require_once __DIR__ . '/includes/functions.php';

// AJAX endpoint behind the "Forgot password?" popup on the login page (see
// ForgotModal in js/main.js). Two actions, both POST:
//   action=send   email                          -> emails a 6-digit code
//   action=reset  email, code, password, confirm -> sets the new password
// Always answers with JSON: {ok: bool, message: string}.
// Same email-code flow as registration (verify.php): the code lives in the
// users.verify_code / verify_expires columns and is good for 15 minutes.

if (is_logged_in() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('login.php'); // not meant to be opened as a page
}

header('Content-Type: application/json');

function reply($ok, $message, array $extra = []) {
    echo json_encode(['ok' => $ok, 'message' => $message] + $extra);
    exit;
}

$action = $_POST['action'] ?? '';
$email  = normalize_school_email($_POST['email'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) reply(false, 'Enter a valid email address.');

$stmt = $pdo->prepare('SELECT id, first_name, is_verified, verify_code, verify_expires FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

// The built-in System Notifications account isn't a person's account.
if (!$user || $email === SYSTEM_USER_EMAIL) reply(false, 'No account found with that email address.');

if (!$user['is_verified']) {
    // For an unverified account verify_code holds its REGISTRATION code, so
    // don't overwrite it — it needs verifying first (logging in offers that).
    reply(false, "That account hasn't been verified yet. Log in to enter your verification code first.");
}

if ($action === 'send') {
    // A code that's still valid is reused: just tell the popup to show the
    // code step, without emailing another. "Resend Code" sends force=1 to
    // ask for a genuinely new one.
    $force       = ($_POST['force'] ?? '') === '1';
    $hasLiveCode = $user['verify_code'] && $user['verify_expires'] && strtotime($user['verify_expires']) > time();
    if ($hasLiveCode && !$force) {
        reply(true, 'A code was already sent to your email and is still valid. Enter it below.', ['reused' => true]);
    }

    // 60-second cooldown between codes (issued = expires - 15 minutes).
    if ($user['verify_expires'] && strtotime($user['verify_expires']) - 15 * 60 > time() - 60) {
        reply(false, 'A code was just sent. Please wait a minute before requesting another.');
    }
    send_password_reset_code($pdo, $user['id'], $email, $user['first_name']);
    reply(true, 'We sent a 6-digit code to your school email. Check your Spam folder too.');
}

if ($action === 'reset') {
    $code     = trim($_POST['code'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    if (!$user['verify_code'] || !hash_equals((string) $user['verify_code'], $code)) reply(false, 'Incorrect code. Please try again.');
    if (!$user['verify_expires'] || strtotime($user['verify_expires']) < time()) reply(false, 'This code has expired. Request a new one.');
    if (strlen($password) < 8) reply(false, 'Password must be at least 8 characters.');
    if ($password !== $confirm) reply(false, 'Passwords do not match.');

    $upd = $pdo->prepare('UPDATE users SET password_hash = ?, verify_code = NULL, verify_expires = NULL WHERE id = ?');
    $upd->execute([password_hash($password, PASSWORD_BCRYPT), $user['id']]);
    reply(true, 'Your password has been changed. You can now log in.');
}

reply(false, 'Something went wrong.');
