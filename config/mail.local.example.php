<?php
/**
 * TEMPLATE for the email secrets. This file is safe to commit: it holds no real values.
 *
 * Setup: copy it to  config/mail.local.php  and fill in your own values.
 * mail.local.php is listed in .gitignore, so it is never committed or shared with the
 * code. Only this example is.
 *
 * On a host where you can't keep a file like that, set the same names as environment
 * variables instead: SMTP_USERNAME, SMTP_PASSWORD, APP_SECRET (and optionally SMTP_HOST,
 * SMTP_PORT, SMTP_FROM_NAME). A value in mail.local.php wins over an environment variable.
 */

// The Gmail address that sends the emails, and its *app password* (Google account ->
// Security -> 2-Step Verification -> App passwords). Not your normal Gmail password.
define('SMTP_USERNAME', 'your-sender-address@gmail.com');
define('SMTP_PASSWORD', 'your-16-character-app-password');

// A long random string that signs the one-click "mark as Resolved" links in emails.
// Make one up (40+ random characters) and keep it private. Changing it later makes
// links in emails already sent stop working.
// To generate one:  php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
define('APP_SECRET', 'replace-with-a-long-random-string');

// Optional (these are the defaults):
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_FROM_NAME', 'HCCD Lost and Found');
