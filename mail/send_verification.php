<?php
/**
 * SMTP settings + the two "code" emails (account verification and password
 * reset). Sending goes through queue_app_email() in emails.php: the request
 * that asked for the email carries on straight away, and if the email can't
 * be sent the person gets a toast.
 * Requires: composer require phpmailer/phpmailer
 *
 * Set the SMTP account in config/mail.local.php (see config/mail.local.example.php).
 */
require_once __DIR__ . '/../vendor/autoload.php';

/*
 * SMTP settings. The secrets (the sending address and its app password, and the key that
 * signs email links) are NOT kept in this file: they come from config/mail.local.php
 * (git-ignored; copy config/mail.local.example.php) or from environment variables.
 * Anything missing stays empty, and sending then fails with a clear message instead of
 * using a password that was ever committed.
 */
$__mailLocal = __DIR__ . '/../config/mail.local.php';
if (is_file($__mailLocal)) require_once $__mailLocal;
foreach ([
    'SMTP_HOST'      => 'smtp.gmail.com',
    'SMTP_PORT'      => 587,
    'SMTP_FROM_NAME' => 'HCCD Lost and Found',
    'SMTP_USERNAME'  => '',
    'SMTP_PASSWORD'  => '',
] as $__k => $__default) {
    if (!defined($__k)) {
        $__env = getenv($__k);
        define($__k, ($__env !== false && $__env !== '') ? ($__k === 'SMTP_PORT' ? (int) $__env : $__env) : $__default);
    }
}
if (!defined('APP_SECRET')) {
    $__env = getenv('APP_SECRET');
    // Fall back to something derived from the mail password so links still can't be forged — but only
    // if there is one; with neither, resolve links are switched off (see resolve_token()).
    define('APP_SECRET', ($__env !== false && $__env !== '') ? $__env : (SMTP_PASSWORD !== '' ? hash('sha256', 'hccd-lost-and-found|' . SMTP_PASSWORD . '|' . DB_PASS) : ''));
}
unset($__mailLocal, $__k, $__default, $__env);

/**
 * Sends a 6-digit verification code, plus a one-click link, to be used on the site.
 * Same look as every other email (see build_email_bodies() in emails.php).
 * @return bool true if it was handed off for sending
 */
function send_verification_email(string $toEmail, string $toName, string $code, string $link = ''): bool {
    [$html, $text] = build_email_bodies(
        'Verify your account',
        'Hi ' . $toName . ', enter this code on the verification page to activate your account' . ($link !== '' ? ', or just use the button below' : '') . '. It expires in 15 minutes.',
        [],
        $link !== '' ? ['Verify my account', $link] : null,
        null,
        'If you did not sign up, you can ignore this email. It may land in your Spam folder.',
        $code,
        false
    );
    return queue_app_email($toEmail, $toName, 'Your HCCD Lost and Found verification code', $html, $text,
        'We couldn’t send the verification email. Use “Resend code” to try again.');
}

/**
 * Sends a 6-digit password-reset code (the "Forgot password?" popup on the login page): the same
 * layout as the other emails, without a button.
 * @return bool true if it was handed off for sending
 */
function send_password_reset_email(string $toEmail, string $toName, string $code): bool {
    [$html, $text] = build_email_bodies(
        'Reset your password',
        'Hi ' . $toName . ', use this code in the password reset window on the login page. It expires in 15 minutes.',
        [],
        null,
        null,
        'If you did not ask to reset your password, you can ignore this email — your password has not been changed. It may land in your Spam folder.',
        $code,
        false
    );
    return queue_app_email($toEmail, $toName, 'Your HCCD Lost and Found password reset code', $html, $text,
        'We couldn’t send the password reset email. Please try again in a moment.');
}

// The sender itself (background hand-off, SMTP, toasts) lives in emails.php.
require_once __DIR__ . '/emails.php';
