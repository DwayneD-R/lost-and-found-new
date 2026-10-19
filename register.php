<?php
require_once __DIR__ . '/includes/functions.php';
if (is_logged_in()) redirect('index.php');

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email     = normalize_school_email($_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';
    $confirm   = $_POST['confirm_password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
    elseif (!str_ends_with($email, SCHOOL_EMAIL_DOMAIN)) $errors[] = 'You must register with your school email (' . SCHOOL_EMAIL_DOMAIN . ').';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirm) $errors[] = 'Passwords do not match.';

    if (!$errors) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors[] = 'An account with that email already exists.';
        }
    }

    if (!$errors) {
        // The name comes from the email (firstname.lastname@...) — nothing more to type.
        [$firstName, $lastName] = name_from_school_email($email);
        $code    = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = date('Y-m-d H:i:s', time() + 15 * 60); // 15-minute validity
        $hash    = password_hash($password, PASSWORD_BCRYPT);

        $stmt = $pdo->prepare(
            'INSERT INTO users (first_name, last_name, email, password_hash, role, is_verified, verify_code, verify_expires)
             VALUES (?, ?, ?, ?, "user", 0, ?, ?)'
        );
        $stmt->execute([$firstName, $lastName, $email, $hash, $code, $expires]);

        // Best-effort verification email; account still gets created either way.
        $mailFile = __DIR__ . '/mail/send_verification.php';
        if (file_exists(__DIR__ . '/vendor/autoload.php')) {
            require_once $mailFile;
            @send_verification_email($email, $firstName, $code, verification_link($email, $code));
        }

        $_SESSION['pending_verify_email'] = $email;
        set_flash('Account created! Check your school email (including Spam) for a 6-digit verification code.', 'success');
        // Verification happens as a modal over this same page now, instead
        // of navigating to verify.php (see VerifyModal in js/main.js) — so
        // stay here rather than redirecting, and clear the submitted values
        // so the registration form behind the modal doesn't still show them.
        $justRegisteredEmail = $email;
        $_POST = [];
    }
}

$pageTitle = 'Register';
require_once __DIR__ . '/includes/header.php';
?>
<h1>Create an Account</h1>
<?php foreach ($errors as $err): ?>
    <script>(window.__pendingToasts = window.__pendingToasts || []).push({msg: <?= json_encode($err) ?>, type: 'error'});</script>
<?php endforeach; ?>

<form method="post" class="form-card">
    <label>School Email
        <?= school_email_input($_POST['email'] ?? '', 'email', 'firstname.lastname') ?>
    </label>
    <label>Password
        <input type="password" name="password" minlength="8" required>
    </label>
    <label>Confirm Password
        <input type="password" name="confirm_password" minlength="8" required>
    </label>
    <button type="submit">Register</button>
</form>
<p>Already have an account? <a href="login.php">Log in</a></p>
<?php if (isset($justRegisteredEmail)): ?>
<script>document.addEventListener('DOMContentLoaded', () => VerifyModal.open(<?= json_encode($justRegisteredEmail) ?>));</script>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
