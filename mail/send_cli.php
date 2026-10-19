<?php
/**
 * Background email sender, run as its own process:
 *     php mail/send_cli.php <job-file>
 * queue_app_email() (emails.php) starts it and does NOT wait for it, so the
 * page that caused the email is already finished while this talks to the mail
 * server. The job file holds the email; it is deleted as soon as it is read.
 * If sending fails, a toast is left for the person who caused the email.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$file = $argv[1] ?? '';
$raw  = (is_string($file) && is_file($file)) ? (string) file_get_contents($file) : '';
if ($file !== '' && is_file($file)) @unlink($file);
$job = json_decode($raw, true);
if (!is_array($job) || empty($job['to'])) exit(1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/toasts.php';
require_once __DIR__ . '/emails.php';

$ok = send_app_email($job['to'], (string) ($job['name'] ?? ''), (string) ($job['subject'] ?? ''), (string) ($job['html'] ?? ''), (string) ($job['text'] ?? ''));
if (!$ok && !empty($job['owner'])) {
    push_toast($pdo, (string) $job['owner'], (string) ($job['fail'] ?? 'An email could not be sent.'));
}
exit($ok ? 0 : 2);
