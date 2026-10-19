<?php
/**
 * Every email the site sends: possible matches, "has your report been
 * resolved?", new conversations — and the verification / password-reset codes
 * from send_verification.php, which holds the SMTP_* settings and loads this file.
 *
 * Nothing here makes a page wait for the mail server: queue_app_email() hands
 * the email to a background process (mail/send_cli.php) — or, failing that, to
 * mail/send_job.php — and returns at once. If the email can't be sent, the
 * background sender leaves a toast for whoever caused it (see includes/toasts.php).
 *
 * Callers don't use this file directly — they go through app_mail() in
 * includes/functions.php, which loads it on demand, checks the person's
 * Settings (email_matches / email_resolved / email_conversations) and never
 * lets an email problem break the page it was triggered from.
 */
require_once __DIR__ . '/send_verification.php';

use PHPMailer\PHPMailer\PHPMailer;

// APP_SECRET (signs the one-click "mark as Resolved" links) is set up in send_verification.php,
// from config/mail.local.php or the environment — never from code that's committed.

/** Token for a "mark my report as Resolved" link: valid for one report + one user until $exp (unix time). */
function resolve_token($reportId, $userId, $exp) {
    if (APP_SECRET === '') return '';   // no secret configured: links can't be made (or accepted)
    return hash_hmac('sha256', 'resolve|' . (int) $reportId . '|' . (int) $userId . '|' . (int) $exp, APP_SECRET);
}

/** The full link put in the "has your report been resolved?" email (30 days). It needs BASE_URL to be reachable from the person's device (ngrok etc.). */
function resolve_url($reportId, $userId) {
    if (APP_SECRET === '') return '';
    $exp = time() + 30 * 86400;
    return app_url('resolve_report.php?r=' . (int) $reportId . '&u=' . (int) $userId . '&e=' . $exp . '&t=' . resolve_token($reportId, $userId, $exp));
}

/** Low-level send, right now. Returns true if the SMTP server accepted it. Used by the background sender. */
function send_app_email($toEmail, $toName, $subject, $html, $text) {
    if (SMTP_USERNAME === '' || SMTP_PASSWORD === '') {
        error_log('App email not sent: SMTP is not configured (create config/mail.local.php from config/mail.local.example.php).');
        return false;
    }
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        // Connect over IPv4: on some Windows networks PHP first tries Gmail's IPv6
        // address, which stalls until the timeout. The certificate is still checked
        // against the real host name.
        $ip = gethostbyname(SMTP_HOST);
        if ($ip !== SMTP_HOST && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $mail->Host        = $ip;
            $mail->SMTPOptions = ['ssl' => ['peer_name' => SMTP_HOST]];
        }
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->Timeout    = 60;   // per wait, not total (the background senders have their own overall limit)
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(SMTP_USERNAME, SMTP_FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $text;
        $mail->send();
        return true;
    } catch (\Throwable $e) {
        error_log('App email error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Queue an email WITHOUT making the caller wait for the mail server — the page
 * (or AJAX request) that caused it finishes immediately. In order of preference:
 *   1. start mail/send_cli.php as a separate background process,
 *   2. else POST the signed job to mail/send_job.php on this same site and
 *      hang up without waiting for any answer,
 *   3. else (neither is possible on this server) send it right here.
 * Whichever sends it, a failure leaves $failMessage as a toast for the person
 * who triggered it. Returns true once the email has been handed off.
 */
function queue_app_email($toEmail, $toName, $subject, $html, $text, $failMessage = '') {
    global $pdo;
    $owner = function_exists('toast_owner') ? toast_owner() : '';
    $fail  = $failMessage !== '' ? $failMessage : 'An email could not be sent.';
    $job   = ['to' => $toEmail, 'name' => $toName, 'subject' => $subject, 'html' => $html, 'text' => $text, 'owner' => $owner, 'fail' => $fail];

    if (spawn_email_job($job)) return true;
    if (dispatch_email_job($job)) return true;

    $ok = send_app_email($toEmail, $toName, $subject, $html, $text);
    if (!$ok && $owner !== '' && isset($pdo)) push_toast($pdo, $owner, $fail);
    return $ok;
}

/** Where is the command-line PHP? (Under Apache, PHP_BINARY is Apache's own program, not php.) */
function find_php_cli() {
    $win  = stripos(PHP_OS, 'WIN') === 0;
    $name = $win ? 'php.exe' : 'php';
    $dirs = [PHP_BINDIR, dirname((string) ini_get('extension_dir')), dirname((string) php_ini_loaded_file())];
    foreach ($dirs as $d) {
        if ($d && $d !== '.' && is_file($d . DIRECTORY_SEPARATOR . $name)) return $d . DIRECTORY_SEPARATOR . $name;
    }
    return null;
}

/** Tier 1: run mail/send_cli.php in the background. Returns false if this server can't do that. */
function spawn_email_job(array $job) {
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    $win = stripos(PHP_OS, 'WIN') === 0;
    $canRun = $win ? (function_exists('popen') && !in_array('popen', $disabled, true))
                   : (function_exists('exec') && !in_array('exec', $disabled, true));
    if (!$canRun) return false;

    $php    = find_php_cli();
    $script = realpath(__DIR__ . '/send_cli.php');
    if (!$php || !$script) return false;

    $file = @tempnam(sys_get_temp_dir(), 'hccdmail');
    if (!$file) return false;
    if (@file_put_contents($file, json_encode($job)) === false) { @unlink($file); return false; }

    if ($win) {
        // "start /B" returns at once; the new process carries on by itself.
        $cmd = 'start /B "" "' . $php . '" -f "' . $script . '" -- "' . $file . '" > NUL 2>&1';
        $h = @popen($cmd, 'r');
        if ($h === false) { @unlink($file); return false; }
        pclose($h);
    } else {
        @exec(escapeshellarg($php) . ' -f ' . escapeshellarg($script) . ' -- ' . escapeshellarg($file) . ' > /dev/null 2>&1 &');
    }
    return true;
}

/**
 * Tier 2: POST the signed job to mail/send_job.php on this site and hang up
 * straight away. It does NOT wait for a reply (a reply can be held back by
 * output buffering until the email is sent, which is exactly the delay this
 * exists to avoid). False only if the site can't be reached at all.
 */
function dispatch_email_job(array $job) {
    $payload = base64_encode(json_encode($job + ['ts' => time()]));
    $sig     = hash_hmac('sha256', $payload, APP_SECRET);

    $u = parse_url(BASE_URL);
    if (!$u || empty($u['host'])) return false;
    $https   = (($u['scheme'] ?? 'http') === 'https');
    $port    = (int) ($u['port'] ?? ($https ? 443 : 80));
    $host    = $u['host'];
    $connect = ($host === 'localhost') ? '127.0.0.1' : $host;   // skips an IPv6 "localhost" that Apache may not listen on
    $path    = rtrim($u['path'] ?? '', '/') . '/mail/send_job.php';

    $fp = @fsockopen(($https ? 'ssl://' : '') . $connect, $port, $errno, $errstr, 2);
    if (!$fp) return false;

    $body = http_build_query(['payload' => $payload, 'sig' => $sig]);
    $req  = "POST $path HTTP/1.1\r\n"
          . 'Host: ' . $host . (isset($u['port']) ? ':' . $u['port'] : '') . "\r\n"
          . "Content-Type: application/x-www-form-urlencoded\r\n"
          . 'Content-Length: ' . strlen($body) . "\r\n"
          . "Connection: Close\r\n\r\n" . $body;
    $written = @fwrite($fp, $req);
    fclose($fp);
    return $written !== false && $written >= strlen($req);
}

/**
 * Branded HTML + plain-text bodies: heading, intro line, an optional big verification $code, label/value rows,
 * a main button and an optional text link. $settingsNote = false leaves out the "turn these emails off in
 * Settings" line (the code emails can't be switched off).
 */
function build_email_bodies($heading, $intro, array $rows, $button = null, $link = null, $footer = '', $code = null, $settingsNote = true) {
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

    $rowsHtml = '';
    $rowsText = '';
    foreach ($rows as $row) {
        $label = $row[0];
        $value = $row[1];
        $pct   = isset($row[2]) ? max(0, min(100, (int) $row[2])) : null;   // optional 3rd item: draw a percentage bar
        // A table-based bar, because that's what email apps (Outlook included) draw reliably.
        $bar = $pct === null ? '' :
            '<table role="presentation" width="180" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-top:6px;"><tr>'
          . ($pct > 0   ? '<td width="' . $pct . '%" height="8" bgcolor="#1C4B7C" style="background:#1C4B7C;height:8px;line-height:8px;font-size:0;border-radius:4px 0 0 4px;">&nbsp;</td>' : '')
          . ($pct < 100 ? '<td width="' . (100 - $pct) . '%" height="8" bgcolor="#E3DFD3" style="background:#E3DFD3;height:8px;line-height:8px;font-size:0;">&nbsp;</td>' : '')
          . '</tr></table>';
        $rowsHtml .= '<tr><td style="padding:6px 14px 6px 0;color:#746E60;font-size:13px;white-space:nowrap;vertical-align:top;">' . $h($label)
                   . '</td><td style="padding:6px 0;color:#23201B;font-size:14px;font-weight:600;">' . $h($value) . $bar . '</td></tr>';
        $rowsText .= $label . ': ' . $value . "\n";
    }
    $buttonHtml = $button
        ? '<p style="margin:24px 0 8px;text-align:center;"><a href="' . $h($button[1]) . '" style="display:inline-block;background:#7C1F2B;color:#ffffff;text-decoration:none;'
          . 'padding:12px 22px;border-radius:8px;font-weight:600;font-size:15px;">' . $h($button[0]) . '</a></p>' : '';
    $linkHtml = $link
        ? '<p style="margin:8px 0 0;font-size:13px;text-align:center;"><a href="' . $h($link[1]) . '" style="color:#1C4B7C;">' . $h($link[0]) . '</a></p>' : '';

    $codeHtml = $code !== null
        ? '<p style="margin:16px 0;text-align:center;"><span style="display:inline-block;background:#F6F4EF;border:1px solid #E3DFD3;border-radius:10px;padding:12px 22px;'
          . 'font-size:30px;font-weight:700;letter-spacing:8px;color:#23201B;font-family:Consolas,Menlo,monospace;">' . $h($code) . '</span></p>' : '';
    $settingsLine = 'You can turn these emails on or off any time: open the menu on the site and choose Settings.';

    $html = '<div style="background:#F6F4EF;padding:24px 12px;font-family:Arial,Helvetica,sans-serif;">'
          . '<div style="max-width:520px;margin:0 auto;background:#ffffff;border:1px solid #E3DFD3;border-radius:12px;overflow:hidden;">'
          . '<div style="background:#7C1F2B;color:#ffffff;padding:14px 22px;font-weight:700;font-size:15px;">' . $h(SITE_NAME) . '</div>'
          . '<div style="padding:22px;">'
          . '<h2 style="margin:0 0 10px;font-size:19px;color:#23201B;">' . $h($heading) . '</h2>'
          . '<p style="margin:0 0 14px;font-size:14px;line-height:1.5;color:#23201B;">' . $h($intro) . '</p>'
          . $codeHtml
          . ($rowsHtml ? '<table role="presentation" style="border-collapse:collapse;margin:6px 0 4px;">' . $rowsHtml . '</table>' : '')
          . $buttonHtml . $linkHtml
          . '</div>'
          . '<div style="padding:14px 22px;border-top:1px solid #E3DFD3;color:#746E60;font-size:12px;line-height:1.5;">'
          . ($footer !== '' ? $h($footer) . ($settingsNote ? '<br>' : '') : '')
          . ($settingsNote ? $settingsLine : '')
          . '</div></div></div>';

    $text = $heading . "\n\n" . $intro . "\n\n" . ($code !== null ? $code . "\n\n" : '') . $rowsText
          . ($button ? "\n" . $button[0] . ': ' . $button[1] . "\n" : '')
          . ($link ? $link[0] . ': ' . $link[1] . "\n" : '')
          . ($settingsNote ? "\n" . $settingsLine . "\n" : '');
    return [$html, $text];
}

/** Does this person want this kind of email? Defaults to yes for an unknown type. */
function user_wants_email(PDO $pdo, $userId, $type) {
    $cols = ['matches' => 'email_matches', 'resolved' => 'email_resolved', 'conversations' => 'email_conversations', 'reports' => 'email_reports'];
    if (!isset($cols[$type])) return true;
    $stmt = $pdo->prepare('SELECT ' . $cols[$type] . ' FROM users WHERE id = ?');
    $stmt->execute([(int) $userId]);
    $v = $stmt->fetchColumn();
    return $v === false ? false : (bool) $v;
}

/**
 * Email one user, if they're a real, verified account and have this kind of
 * email turned on in Settings. $opts: rows => [[label, value], ...],
 * button => [label, url], link => [label, url], footer => string.
 */
function mail_to_user(PDO $pdo, $userId, $type, $subject, $heading, $intro, array $opts = []) {
    $stmt = $pdo->prepare('SELECT email, first_name, is_verified FROM users WHERE id = ?');
    $stmt->execute([(int) $userId]);
    $u = $stmt->fetch();
    if (!$u || !$u['is_verified'] || $u['email'] === SYSTEM_USER_EMAIL) return false;
    if (!user_wants_email($pdo, $userId, $type)) return false;

    [$html, $text] = build_email_bodies(
        $heading,
        'Hi ' . $u['first_name'] . ', ' . $intro,
        $opts['rows'] ?? [],
        $opts['button'] ?? null,
        $opts['link'] ?? null,
        $opts['footer'] ?? ''
    );
    return queue_app_email($u['email'], $u['first_name'], $subject, $html, $text,
        $opts['fail'] ?? 'An email couldn’t be sent to one of the people involved.');
}
