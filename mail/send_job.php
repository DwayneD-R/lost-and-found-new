<?php
/**
 * Fallback background email sender, used when the server can't start
 * mail/send_cli.php as its own process. queue_app_email() (emails.php) POSTs a
 * signed job here and hangs up without waiting; this keeps going on its own
 * (ignore_user_abort), so nobody is held up by SMTP. If sending fails, a toast
 * is left for the person who caused the email (toasts table).
 *
 * Not for browsers: every request must carry a valid signature, made with
 * APP_SECRET, or it is refused.
 */
ignore_user_abort(true);
set_time_limit(300);   // total cap; PHPMailer's own Timeout is per wait, so a slow server can add up

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/toasts.php';
require_once __DIR__ . '/emails.php';

header('Content-Type: text/plain');

$payload = (string) ($_POST['payload'] ?? '');
$sig     = (string) ($_POST['sig'] ?? '');
if (APP_SECRET === '' || $payload === '' || !hash_equals(hash_hmac('sha256', $payload, APP_SECRET), $sig)) {
    http_response_code(403);
    exit('forbidden');
}
$job = json_decode((string) base64_decode($payload, true), true);
if (!is_array($job) || empty($job['to']) || abs(time() - (int) ($job['ts'] ?? 0)) > 300) {
    http_response_code(400);
    exit('bad job');
}

// Answer first, work after.
$reply = 'queued';
header('Content-Length: ' . strlen($reply));
header('Connection: close');
echo $reply;
while (ob_get_level() > 0) @ob_end_flush();
@flush();
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

$sent = false;   // set once send_app_email() has returned (true or false)
register_shutdown_function(function () use ($job, &$sent, &$pdo) {
    if ($sent) return;
    // Reaching here without a result means PHP died mid-send (time limit, fatal
    // error...). Give this a few seconds to run, then tell the person.
    @set_time_limit(10);
    error_log('App email error: background sender stopped before finishing (to: ' . ($job['to'] ?? '?') . ')');
    if (!empty($job['owner']) && isset($pdo)) {
        push_toast($pdo, (string) $job['owner'], (string) ($job['fail'] ?? 'An email could not be sent.'));
    }
});

$ok = send_app_email($job['to'], (string) ($job['name'] ?? ''), (string) ($job['subject'] ?? ''), (string) ($job['html'] ?? ''), (string) ($job['text'] ?? ''));
$sent = true;
if (!$ok && !empty($job['owner'])) {
    push_toast($pdo, (string) $job['owner'], (string) ($job['fail'] ?? 'An email could not be sent.'));
}
