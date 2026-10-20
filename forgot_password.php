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

// Whether an email has an account is never revealed here: an unknown address, the built-in System account
// and an account that isn't verified yet all get the same answer as a real one. (The code just never arrives.)
$generic = 'If that email has a verified account, we sent a 6-digit code to it. Check your Spam folder too. If you never verified it, log in to enter your verification code first.';
$eligible = $user && $email !== SYSTEM_USER_EMAIL && $user['is_verified'];

if ($action === 'send') {
    if (!$eligible) reply(true, $generic);

    // A code that's still valid is reused: just tell the popup to show the
    // code step, without emailing another. "Resend Code" sends force=1 to
    // ask for a genuinely new one.
    $force       = ($_POST['force'] ?? '') === '1';
    $hasLiveCode = $user['verify_code'] && $user['verify_expires'] && strtotime($user['verify_expires']) > time();
    if ($hasLiveCode && !$force) {
        reply(true, $generic);   // same words as a fresh send, so this can't be told apart from an unknown address
    }

    // 60-second cooldown between codes (issued = expires - 15 minutes).
    if (code_recently_sent($user)) {
        reply(true, $generic);   // (nothing new is sent; the one from a moment ago is still good)
    }
    send_password_reset_code($pdo, $user['id'], $email, $user['first_name']);
    reply(true, $generic);
}

if ($action === 'reset') {
    $code     = trim($_POST['code'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    if (!$eligible) reply(false, 'Incorrect code. Please try again.');
    if (!$user['verify_code']) reply(false, 'Too many wrong attempts, so that code no longer works. Go back and request a new one.');
    if (!hash_equals((string) $user['verify_code'], $code)) {
        $burned = code_register_failure($pdo, $user['id']);
        reply(false, $burned ? 'Too many wrong attempts, so that code no longer works. Go back and request a new one.' : 'Incorrect code. Please try again.');
    }
    if (!$user['verify_expires'] || strtotime($user['verify_expires']) < time()) reply(false, 'This code has expired. Request a new one.');
    if (strlen($password) < 8) reply(false, 'Password must be at least 8 characters.');
    if ($password !== $confirm) reply(false, 'Passwords do not match.');

    // The code is checked and used up in the same statement, so two requests can't both use it.
    $upd = $pdo->prepare('UPDATE users SET password_hash = ?, verify_code = NULL, verify_expires = NULL WHERE id = ? AND is_verified = 1 AND verify_code = ? AND verify_expires >= NOW()');
    $upd->execute([password_hash($password, PASSWORD_BCRYPT), $user['id'], $user['verify_code']]);
    if ($upd->rowCount() !== 1) reply(false, 'This code has expired. Request a new one.');
    reply(true, 'Your password has been changed. You can now log in.');
}

reply(false, 'Something went wrong.');
