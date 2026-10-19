<?php
/**
 * TEMPORARY diagnostic for the background email sender. It runs each step
 * queue_app_email() uses, one at a time, and prints what really happens
 * (the real background sender hides every error).
 *
 *   http://localhost/lost-and-found/mail/test_send.php?to=you@example.com
 *   or: php mail/test_send.php you@example.com
 *
 * DELETE THIS FILE when you're done.
 */
if (PHP_SAPI !== 'cli' && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('forbidden');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/toasts.php';
require_once __DIR__ . '/emails.php';

$to = PHP_SAPI === 'cli' ? ($argv[1] ?? '') : ($_GET['to'] ?? '');
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) exit("Give an address: ?to=you@example.com\n");
if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=utf-8');

$job = ['to' => $to, 'name' => 'Test', 'subject' => 'HCCD background send test',
        'html' => '<b>Background send works.</b>', 'text' => 'Background send works.', 'owner' => '', 'fail' => 'test failed'];

echo "== Environment ==\n";
echo "PHP " . PHP_VERSION . " (" . PHP_OS . ") | openssl: " . (extension_loaded('openssl') ? 'yes' : 'NO') . "\n";
echo "disable_functions: " . (ini_get('disable_functions') ?: '(none)') . "\n";
echo "BASE_URL: " . BASE_URL . "\n";
$php = find_php_cli();
echo "php CLI found: " . ($php ?: 'NOT FOUND (tier 1 is skipped)') . "\n\n";

echo "== Tier 1: run send_cli.php and show its real output ==\n";
if ($php) {
    $file = tempnam(sys_get_temp_dir(), 'hccdmail');
    file_put_contents($file, json_encode($job));
    $cmd = '"' . $php . '" -f "' . realpath(__DIR__ . '/send_cli.php') . '" -- "' . $file . '" 2>&1';
    echo "$cmd\n";
    $out = []; $code = 0;
    exec($cmd, $out, $code);
    echo "exit code: $code (0 = sent, 2 = SMTP failed, 1 = bad job)\n";
    echo "output: " . ($out ? implode("\n", $out) : '(none)') . "\n";
    if (is_file($file)) @unlink($file);
} else {
    echo "skipped\n";
}

echo "\n== Tier 2: POST to send_job.php and read the HTTP answer ==\n";
$payload = base64_encode(json_encode($job + ['ts' => time()]));
$sig = hash_hmac('sha256', $payload, APP_SECRET);
$u = parse_url(BASE_URL);
$https = (($u['scheme'] ?? 'http') === 'https');
$port = (int) ($u['port'] ?? ($https ? 443 : 80));
$connect = ($u['host'] === 'localhost') ? '127.0.0.1' : $u['host'];
$path = rtrim($u['path'] ?? '', '/') . '/mail/send_job.php';
$fp = @fsockopen(($https ? 'ssl://' : '') . $connect, $port, $errno, $errstr, 3);
if (!$fp) {
    echo "could not connect to $connect:$port ($errstr)\n";
} else {
    $body = http_build_query(['payload' => $payload, 'sig' => $sig]);
    fwrite($fp, "POST $path HTTP/1.1\r\nHost: {$u['host']}" . (isset($u['port']) ? ':' . $u['port'] : '') .
        "\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\nConnection: Close\r\n\r\n$body");
    stream_set_timeout($fp, 5);
    $resp = stream_get_contents($fp, 600);
    fclose($fp);
    echo "answer from server:\n" . trim($resp) . "\n";
}

echo "\n== Direct send (same as the old, non-background version) ==\n";
echo send_app_email($to, 'Test', 'HCCD direct send test', 'Direct send works.', 'Direct send works.') ? "SENT OK\n" : "FAILED (see PHP error log)\n";

echo "\nCheck the inbox (and Spam) for up to 3 emails: tier 1, tier 2, direct.\n";
