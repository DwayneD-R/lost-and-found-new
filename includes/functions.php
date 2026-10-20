<?php
if (session_status() === PHP_SESSION_NONE) {
    // The login cookie ends when the browser closes (lifetime 0), can't be read by scripts, and isn't
    // sent along with requests started from other sites.
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

// Never let the browser keep a copy of a page: after logging out, Back must not show what was on
// screen — it has to ask the server again (and the server will say "log in"). (This also stops the
// browser's instant back/forward cache from replaying a page.)
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/toasts.php';

/** Escape output for safe HTML rendering. */
function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/** Redirect helper. */
function redirect($path) {
    header('Location: ' . $path);
    exit;
}

/**
 * CSRF guard, applied to every POST on the site: a browser always says which site a POST came from
 * (the Origin header). If it names some other site, the request is refused no matter what the page
 * looked like — the session cookie's SameSite=Lax flag is the first line of defence, this is the second.
 * Requests with no Origin (server-to-server calls such as mail/send_job.php, old browsers) pass, and so
 * does the address in BASE_URL (e.g. an ngrok tunnel that rewrites the Host header).
 */
function enforce_same_origin() {
    if (PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') return;
    $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
    $allowed = [
        strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''))),
        strtolower((string) parse_url(BASE_URL, PHP_URL_HOST)),
    ];
    if ($originHost !== '' && in_array($originHost, $allowed, true)) return;
    http_response_code(403);
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Request blocked.', 'message' => 'Request blocked.', 'errors' => ['Request blocked.']]);
    } else {
        echo 'Request blocked.';
    }
    exit;
}
enforce_same_origin();

/** Seconds with no request AND no live page before a session is treated as abandoned (the safety net — see is_logged_in). */
const SESSION_ABANDON_SECONDS = 60;
/** After the "leave" beacon says a page was closed, a reload / next page has this long to show up before the login is ended. */
const SESSION_LEAVE_GRACE_SECONDS = 15;

/**
 * Is a user currently logged in? Checked on EVERY request, server-side — nothing here trusts the browser:
 *  1. the account must still exist and be verified (a deleted account's old session stops working at once),
 *     and the role always comes from the database, never from what the session remembers;
 *  2. closing the site's last tab logs you out. Every open page keeps a live connection (ajax/stream.php,
 *     one row per page in the presence table). Closing a page sends a "leave" beacon (ajax/leave.php) that
 *     stamps presence.left_at; once SESSION_LEAVE_GRACE_SECONDS pass with no live page, the next request
 *     ends the session. If the beacon never arrives (browser killed, network lost) the older rule still
 *     applies as a safety net: no request for SESSION_ABANDON_SECONDS and no live page either.
 * (Reopening within the grace period is fine, e.g. after an accidental close or a reload. Closing the whole
 * browser ends the cookie anyway.)
 */
function is_logged_in() {
    static $checked = false;
    static $ok = false;
    if (!isset($_SESSION['user_id'])) return false;
    if ($checked) return $ok;
    $checked = true;
    global $pdo;

    $stmt = $pdo->prepare('SELECT first_name, last_name, role, is_verified FROM users WHERE id = ?');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $u = $stmt->fetch();
    if (!$u || !$u['is_verified']) {
        end_session_now();
        set_flash('Your account is no longer available. Please log in again.', 'info');
        return $ok = false;
    }
    $_SESSION['role']       = $u['role'];
    $_SESSION['first_name'] = $u['first_name'];
    $_SESSION['last_name']  = $u['last_name'];

    if (session_abandoned()) {
        end_session_now();
        set_flash('You were signed out because the site was closed. Please log in again.', 'info');
        return $ok = false;
    }
    $_SESSION['last_request'] = time();
    return $ok = true;
}

/** Did every page of this login session go away (see is_logged_in)? Judged by the database clock. */
function session_abandoned() {
    global $pdo;
    $stmt = $pdo->prepare(
        'SELECT TIMESTAMPDIFF(SECOND, MAX(last_seen), NOW()) AS seen_ago,
                TIMESTAMPDIFF(SECOND, MAX(left_at), NOW())   AS left_ago
         FROM presence WHERE session_id = ?'
    );
    $stmt->execute([session_id()]);
    $row = $stmt->fetch();
    if ($row['seen_ago'] !== null && (int) $row['seen_ago'] <= ONLINE_WINDOW_SECONDS) return false;   // a page is open
    if ($row['left_ago'] !== null && (int) $row['left_ago'] > SESSION_LEAVE_GRACE_SECONDS) return true; // closed, and nobody came back
    $last = $_SESSION['last_request'] ?? null;
    return $last !== null && time() - (int) $last > SESSION_ABANDON_SECONDS;                              // safety net
}

/** Ends the login completely: forgets who it was, removes its live-page records and the cookie, and starts a clean session (so a message can still be shown). */
function end_session_now() {
    global $pdo;
    if (isset($pdo)) $pdo->prepare('DELETE FROM presence WHERE session_id = ?')->execute([session_id()]);
    $_SESSION = [];
    if (ini_get('session.use_cookies') && !headers_sent()) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', !empty($p['secure']), true);
    }
    session_regenerate_id(true);
}

/** Force login; bounce guests to the login page. */
function require_login() {
    if (!is_logged_in()) {
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            http_response_code(401);
            header('Content-Type: application/json');
            $msg = 'Your session has ended. Please log in again.';
            echo json_encode(['ok' => false, 'login' => true, 'error' => $msg, 'message' => $msg, 'errors' => [$msg]]);
            exit;
        }
        // Remember the page they were trying to open (an emailed link, say) so that logging in lands on it.
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') $_SESSION['return_to'] = $_SERVER['REQUEST_URI'] ?? '';
        redirect(site_root_url() . 'login.php');
    }
    housekeeping();
}

/**
 * Small background chores that piggy-back on a logged-in page load:
 *  - records "last active" for this user (at most once a minute),
 *  - removes conversations nobody has written in for CONVERSATION_EXPIRE_DAYS
 *    (checked at most once an hour per session),
 *  - sends the automatic "has your report been resolved?" check-ins (same schedule).
 * Never allowed to break the page it runs on.
 * (Needs users.last_active_at — see sql/schema.sql.)
 */
function housekeeping() {
    global $pdo;
    if (!isset($pdo) || !is_logged_in()) return;
    try {
        $now = time();
        if ($now - (int) ($_SESSION['last_touch'] ?? 0) >= 60) {
            $pdo->prepare('UPDATE users SET last_active_at = NOW() WHERE id = ?')->execute([$_SESSION['user_id']]);
            $_SESSION['last_touch'] = $now;
        }
        if ($now - (int) ($_SESSION['convo_sweep'] ?? 0) >= 3600) {
            auto_delete_stale_conversations($pdo);
            $_SESSION['convo_sweep'] = $now;
        }
        if ($now - (int) ($_SESSION['ask_sweep'] ?? 0) >= 3600) {
            auto_request_report_updates($pdo);
            $_SESSION['ask_sweep'] = $now;
        }
    } catch (Throwable $e) {
        // ignore — housekeeping is best-effort
    }
}

/**
 * Checks the signed-in admin's OWN password, on the server. Sensitive admin actions (delete / verify
 * accounts, CSV import) send it along as confirm_password; the pop-up that asks for it is only a
 * convenience — a request made without going through the page is refused here.
 */
function admin_password_ok(PDO $pdo, $password) {
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([(int) ($_SESSION['user_id'] ?? 0)]);
    $hash = $stmt->fetchColumn();
    return $hash && password_verify((string) $password, $hash);
}

/** Force admin role. */
function require_admin() {
    require_login();
    if (($_SESSION['role'] ?? '') !== 'admin') {
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'That action is for admins only.', 'message' => 'That action is for admins only.', 'errors' => ['That action is for admins only.']]);
            exit;
        }
        set_flash('That page is for admins only.', 'error');
        redirect(site_root_url() . 'index.php');
    }
}

/**
 * ---- Transactions -------------------------------------------------------
 * Anything that changes more than one row (deleting an account together with its conversations,
 * a bulk action, the "ask if resolved" request) runs inside db_transaction(): either every step
 * happens or none does, and other requests never see it half-done. A deadlock (two requests
 * waiting on each other's rows) is simply retried.
 *
 * Things that can't be undone — deleting photo files, sending notifications — are queued with
 * after_commit() and only run once the transaction has really committed; if it rolls back they
 * are dropped, so a failure can never leave a notice about something that didn't happen.
 */
function after_commit(callable $fn) {
    global $pdo;
    if (isset($pdo) && $pdo->inTransaction()) {
        $GLOBALS['__after_commit'][] = $fn;
    } else {
        $fn();
    }
}

function db_transaction(PDO $pdo, callable $fn) {
    if ($pdo->inTransaction()) return $fn();   // already inside one: join it (and its commit)

    for ($attempt = 1; ; $attempt++) {
        $GLOBALS['__after_commit'] = [];
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            break;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $GLOBALS['__after_commit'] = [];
            $deadlock = ($e instanceof PDOException) && in_array((string) $e->getCode(), ['40001', '1213'], true)
                     || strpos($e->getMessage(), 'Deadlock') !== false;
            if ($deadlock && $attempt < 3) { usleep(50000 * $attempt); continue; }
            throw $e;
        }
    }
    $tasks = $GLOBALS['__after_commit'];
    $GLOBALS['__after_commit'] = [];
    foreach ($tasks as $task) {
        try { $task(); } catch (Throwable $e) { error_log('After-commit task failed: ' . $e->getMessage()); }
    }
    return $result;
}

/** The site's own folder as a root-relative URL ("/lost-and-found/"), so a redirect works from any depth (admin/, ajax/, mail/). */
function site_root_url() {
    $dir = dirname($_SERVER['SCRIPT_NAME'] ?? '/');             // e.g. /lost-and-found/admin
    $dir = preg_replace('#/(admin|ajax|mail)$#', '', $dir);     // -> /lost-and-found
    return rtrim($dir, '/') . '/';
}

/**
 * Last line of defence: anything nobody caught (a database hiccup, a bug) used to be a
 * raw PHP error page, or a silent failure for AJAX requests. Now AJAX requests get a
 * JSON message (shown as a toast by the page) and normal pages a friendly error page.
 * The details always go to the PHP error log; admins also see them on the page.
 */
set_exception_handler(function (Throwable $e) {
    error_log('Unhandled: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) http_response_code(500);
    $msg = 'Something went wrong on our side. Please try again in a moment.';
    $xhr = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    if ($xhr) {
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $msg, 'message' => $msg, 'errors' => [$msg]]);
        return;
    }
    $detail = (($_SESSION['role'] ?? '') === 'admin')
        ? '<details style="margin-top:14px;text-align:left;"><summary>Details (admins only)</summary><pre style="white-space:pre-wrap;">'
          . htmlspecialchars($e->getMessage() . "\n" . basename($e->getFile()) . ':' . $e->getLine(), ENT_QUOTES, 'UTF-8') . '</pre></details>'
        : '';
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Something went wrong</title>'
       . '<div style="font-family:Arial,sans-serif;max-width:520px;margin:12vh auto;padding:0 18px;text-align:center;color:#23201B;">'
       . '<h1 style="font-size:1.4rem;">Something went wrong</h1><p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>'
       . '<p><a href="javascript:history.back()">Go back</a> &middot; <a href="' . htmlspecialchars(site_root_url(), ENT_QUOTES, 'UTF-8') . '">Home</a></p>' . $detail . '</div>';
});

/** Fetch the currently logged-in user's row, or null. */
function current_user(PDO $pdo) {
    if (!is_logged_in()) return null;
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

/**
 * Handle a single uploaded image (optional field).
 * Returns the relative path to store in DB, or null if no file was uploaded.
 */
function handle_image_upload($fieldName, $destSubdir) {
    if (empty($_FILES[$fieldName]['name']) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed with error code ' . $_FILES[$fieldName]['error']);
    }

    $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif'];
    $ext = strtolower(pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION));
    if (!array_key_exists($ext, $allowed)) {
        throw new RuntimeException('Only JPG, PNG, or GIF images are allowed.');
    }
    if ($_FILES[$fieldName]['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Image must be smaller than 5MB.');
    }

    $uploadDir = __DIR__ . '/../uploads/' . $destSubdir . '/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $newName = bin2hex(random_bytes(12)) . '.' . $ext;
    $destPath = $uploadDir . $newName;

    if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $destPath)) {
        throw new RuntimeException('Could not save uploaded file.');
    }

    return 'uploads/' . $destSubdir . '/' . $newName;
}

/**
 * Inline an icon from /assets/icons/{name}.svg, recolored to follow
 * `currentColor` so a wrapping button/link can control it (and change it
 * on :hover) purely with CSS `color`.
 */
function icon($name) {
    static $cache = [];
    if (isset($cache[$name])) return $cache[$name];

    $path = __DIR__ . '/../assets/icons/' . basename($name) . '.svg';
    if (!file_exists($path)) return '';

    $svg = file_get_contents($path);
    $svg = preg_replace('/<\?xml.*?\?>/s', '', $svg);
    $svg = preg_replace('/<!--.*?-->/s', '', $svg);
    $svg = preg_replace('/<!DOCTYPE.*?>/s', '', $svg);
    // Any hardcoded fill/stroke color becomes currentColor...
    $svg = preg_replace('/(fill|stroke)="#[0-9A-Fa-f]{3,8}"/', '$1="currentColor"', $svg);
    // ...and if the root <svg> has no fill at all (so its paths are relying
    // on the SVG default fill of black), give it currentColor too.
    $svg = preg_replace_callback('/<svg\b([^>]*)>/', function ($m) {
        return preg_match('/\bfill=/', $m[1]) ? $m[0] : ('<svg' . $m[1] . ' fill="currentColor">');
    }, $svg, 1);
    // Drop fixed pixel width/height so CSS controls the rendered size.
    $svg = preg_replace('/\s(width|height)="[^"]*"/', '', $svg);

    return $cache[$name] = trim($svg);
}

/**
 * Email of the built-in "System Notifications" account (the sender of
 * possible-match alerts — see system_user_id()). It's a real row
 * in `users` only because messages.sender_id and conversations.user_a_id are
 * NOT NULL foreign keys to that table, so a message needs SOME user to come
 * from. It's protected: admin/delete_user.php refuses to delete it, and
 * admin/users.php shows it without any actions.
 */
const SYSTEM_USER_EMAIL = 'system@lost-and-found.internal';

/** Categories offered by the report forms; also the Category filter's options. */
const REPORT_CATEGORIES = ['ID / Cards', 'Electronics', 'Bags', 'Clothing/Accessories', 'Books/Documents', 'Keys', 'Other'];

/**
 * The search box + filter dropdowns shown above every report table (Manage
 * Reports active/deleted, and My Reports). js/main.js filters the table's rows
 * live from these: the search matches the row's text, and each dropdown
 * matches the row's data-type / data-category / data-status attribute — so
 * every row in those tables needs those three attributes.
 *
 * $statuses: value => label for the Status dropdown; pass [] to leave that
 * dropdown out (the Deleted Reports table has no status column).
 */
function report_filter_toolbar(array $statuses = [], $placeholder = 'Search by item, category, status…', $afterSearch = '') {
    $out  = '<div class="table-toolbar report-filters">';
    $out .= '<input type="search" id="report-search" class="report-search" placeholder="' . e($placeholder) . '" aria-label="Search reports">';
    $out .= $afterSearch; // e.g. the Active / Deleted Reports switch on Manage Reports
    // All / Lost / Found as one-click buttons; they drive a hidden select so the filter code stays the same.
    $out .= '<select class="report-filter" data-filter="type" aria-label="Filter by type" hidden>'
          . '<option value="">All types</option><option value="lost">Lost</option><option value="found">Found</option></select>';
    $out .= '<div class="segmented filter-seg" role="group" aria-label="Report type" data-for="type">'
          . '<button type="button" class="seg-btn seg-all selected" data-value="" aria-pressed="true">All</button>'
          . '<button type="button" class="seg-btn seg-lost" data-value="lost" aria-pressed="false">Lost</button>'
          . '<button type="button" class="seg-btn seg-found" data-value="found" aria-pressed="false">Found</button></div>';
    $out .= '<select class="report-filter" data-filter="category" aria-label="Filter by category"><option value="">All categories</option>';
    foreach (REPORT_CATEGORIES as $c) $out .= '<option value="' . e($c) . '">' . e($c) . '</option>';
    $out .= '</select>';
    if ($statuses) {
        $out .= '<select class="report-filter" data-filter="status" aria-label="Filter by status"><option value="">All statuses</option>';
        foreach ($statuses as $val => $label) $out .= '<option value="' . e($val) . '">' . e($label) . '</option>';
        $out .= '</select>';
    }
    // Always shown, but disabled until a search or filter is set (js/main.js).
    $out .= '<button type="button" class="report-filter-clear" id="filters-reset" disabled>Clear filters</button>';
    return $out . '</div>';
}

/**
 * Look up what's needed to tell people their conversations are about to be
 * deleted: who was in each one and what it was about. Call BEFORE deleting.
 */
function conversation_notice_info(PDO $pdo, array $convoIds) {
    $convoIds = array_values(array_unique(array_filter(array_map('intval', $convoIds))));
    if (!$convoIds) return [];
    $ph = implode(',', array_fill(0, count($convoIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT c.id, c.report_id, c.subject, c.user_a_id, c.user_b_id,
                r.item_name,
                ua.first_name AS a_first, ua.last_name AS a_last, ua.email AS a_email,
                ub.first_name AS b_first, ub.last_name AS b_last, ub.email AS b_email
         FROM conversations c
         LEFT JOIN reports r ON r.id = c.report_id
         JOIN users ua ON ua.id = c.user_a_id
         JOIN users ub ON ub.id = c.user_b_id
         WHERE c.id IN ($ph) AND c.is_system = 0"
    );
    $stmt->execute($convoIds);
    return $stmt->fetchAll();
}

/**
 * Send each person in these (already deleted) conversations a System
 * Notification saying who the conversation was with and what it was about.
 * $exceptUserId is left out — normally the person who just deleted it.
 * $reason, if given, is shown as the "Reason" row, e.g. "No new messages in 30 days".
 */
function notify_conversations_deleted(PDO $pdo, array $info, $exceptUserId = 0, $reason = '', $withLink = true) {
    $except = array_map('intval', (array) $exceptUserId);
    foreach ($info as $c) {
        $about = $c['report_id'] ? '“' . ($c['item_name'] ?? 'a removed item') . '”' : 'General inquiry';
        $people = [
            [(int) $c['user_a_id'], $c['a_email'], trim($c['b_first'] . ' ' . $c['b_last'])],
            [(int) $c['user_b_id'], $c['b_email'], trim($c['a_first'] . ' ' . $c['a_last'])],
        ];
        foreach ($people as [$userId, $email, $otherName]) {
            if (in_array($userId, $except, true) || $email === SYSTEM_USER_EMAIL) continue;
            $details = ['With' => $otherName, 'About' => $about];
            if ($reason !== '') $details['Reason'] = $reason;
            // No "View report" button when the report itself is gone.
            send_system_message($pdo, $userId, build_notice('Conversation deleted', $details, null, ($withLink && $c['report_id']) ? $c['report_id'] : null));
        }
    }
}

/**
 * Delete every conversation matching $where (SQL on the conversations table, alias c;
 * never the System Notification threads): their photos, then the rows, then a System
 * Notification to each person left, saying who it was with, what about, and why.
 * $exceptUserIds are people who are being deleted themselves, so aren't told.
 * (The foreign keys would already remove the rows when a report or account is deleted;
 * this is what also clears the photos and tells the other person.)
 */
function cleanup_conversations(PDO $pdo, $where, array $params, array $exceptUserIds, $reason, $withLink = false) {
    return db_transaction($pdo, function () use ($pdo, $where, $params, $exceptUserIds, $reason, $withLink) {
        // FOR UPDATE: if two requests try to delete the same conversations at the same moment, the
        // second one waits, then finds them gone and does nothing — so nobody is notified twice.
        $stmt = $pdo->prepare("SELECT c.id FROM conversations c WHERE c.is_system = 0 AND ($where) FOR UPDATE");
        $stmt->execute($params);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if (!$ids) return 0;

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $imgStmt = $pdo->prepare("SELECT image_path FROM messages WHERE conversation_id IN ($ph) AND image_path IS NOT NULL AND image_path <> ''");
        $imgStmt->execute($ids);
        $images = $imgStmt->fetchAll(PDO::FETCH_COLUMN);
        $info = conversation_notice_info($pdo, $ids);

        $pdo->prepare("DELETE FROM conversations WHERE id IN ($ph)")->execute($ids);

        // Only once this has committed: clear the photos, then tell the people left.
        after_commit(function () use ($pdo, $images, $info, $exceptUserIds, $reason, $withLink) {
            delete_unused_uploads($pdo, $images);
            notify_conversations_deleted($pdo, $info, $exceptUserIds, $reason, $withLink);
        });
        return count($ids);
    });
}

/** Conversations about reports that are about to be permanently deleted. */
function cleanup_conversations_for_reports(PDO $pdo, array $reportIds, $reason = 'The report was permanently deleted') {
    $ids = array_values(array_unique(array_filter(array_map('intval', $reportIds))));
    if (!$ids) return;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    cleanup_conversations($pdo, "c.report_id IN ($ph)", $ids, [], $reason);
}

/** Conversations of accounts that are about to be deleted. (Their reports are NOT deleted: see delete_user_accounts().) */
function cleanup_conversations_for_users(PDO $pdo, array $userIds, $reason = 'The account was deleted') {
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (!$ids) return;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    cleanup_conversations($pdo, "c.user_a_id IN ($ph) OR c.user_b_id IN ($ph)", array_merge($ids, $ids), $ids, $reason);
}

/**
 * Delete conversations that haven't had a message for CONVERSATION_EXPIRE_DAYS
 * (or, if they never got one, that were created that long ago). The pinned
 * System Notification threads are never touched. Messages go with the
 * conversation (ON DELETE CASCADE) and so do their photos.
 */
function auto_delete_stale_conversations(PDO $pdo) {
    $days = (int) CONVERSATION_EXPIRE_DAYS;
    cleanup_conversations(
        $pdo,
        "COALESCE((SELECT MAX(m.created_at) FROM messages m WHERE m.conversation_id = c.id), c.created_at) < (NOW() - INTERVAL $days DAY)",
        [], [], 'No new messages in ' . $days . ' days', true
    );
}

/**
 * Delete user accounts (never the built-in System account), together with their conversations and the
 * photos sent in those. Their REPORTS ARE KEPT: reports.user_id becomes NULL (ON DELETE SET NULL, see
 * sql/schema.sql) and the report shows "Deleted user" as its reporter, so a lost or found item never
 * disappears just because the person's account did. Returns how many accounts were deleted.
 * Callers are responsible for not passing the signed-in admin's own id.
 */
function delete_user_accounts(PDO $pdo, array $ids) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return 0;

    return db_transaction($pdo, function () use ($pdo, $ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $okStmt = $pdo->prepare("SELECT id FROM users WHERE id IN ($ph) AND email <> ? ORDER BY id FOR UPDATE");
        $okStmt->execute(array_merge($ids, [SYSTEM_USER_EMAIL]));
        $ids = array_map('intval', $okStmt->fetchAll(PDO::FETCH_COLUMN));
        if (!$ids) return 0;
        $ph = implode(',', array_fill(0, count($ids), '?'));

        // Their conversations go first (photos cleared and the other people told — after the commit).
        cleanup_conversations_for_users($pdo, $ids);

        $del = $pdo->prepare("DELETE FROM users WHERE id IN ($ph)");
        $del->execute($ids);
        return $del->rowCount();
    });
}

/** A chat message's timestamp: just the time if it's from today, otherwise with the date (and year if not this year). */
function format_message_time($datetime) {
    $ts = strtotime((string) $datetime);
    if (!$ts) return '';
    if (date('Y-m-d', $ts) === date('Y-m-d')) return date('g:i A', $ts);
    if (date('Y', $ts) === date('Y'))         return date('M j, g:i A', $ts);
    return date('M j, Y, g:i A', $ts);
}

/** Search + filters for the Manage Users table (same container as the report tables' toolbar). */
function user_filter_toolbar($placeholder = 'Search users…') {
    $out  = '<div class="table-toolbar report-filters">';
    $out .= '<input type="search" id="report-search" class="report-search" placeholder="' . e($placeholder) . '" aria-label="Search users">';
    $out .= '<select class="report-filter" data-filter="verified" aria-label="Filter by verification">'
          . '<option value="">All accounts</option><option value="yes">Verified</option><option value="no">Unverified</option></select>';
    // Finds old unused accounts: pick what to compare (last active / joined) and
    // which side of the day. The dropdown and the date are one joined control.
    // (Accounts with no recorded activity count from the day they joined.)
    $modes = ['active-before' => 'Active before', 'active-after' => 'Active after', 'joined-before' => 'Joined before', 'joined-after' => 'Joined after'];
    $out .= '<div class="date-filter" title="Accounts with no recorded activity count from the day they joined.">'
          . '<div class="type-dropdown date-mode-dropdown">'
          . '<button type="button" class="date-mode-btn" id="date-mode" data-mode="active-before" aria-haspopup="listbox" aria-expanded="false">'
          . '<span id="date-mode-label">Active before</span>'
          . '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 4l4 4 4-4"/></svg></button>'
          . '<div class="type-dropdown-menu" id="date-mode-menu" role="listbox" hidden>';
    foreach ($modes as $value => $label) {
        $out .= '<span class="type-option date-mode-option' . ($value === 'active-before' ? ' selected' : '') . '" role="option" tabindex="0" data-mode="' . e($value) . '">' . e($label) . '</span>';
    }
    $out .= '</div></div>'
          . '<input type="date" class="report-date-filter" data-date-filter="last-active" aria-label="Date"></div>';
    $out .= '<button type="button" class="report-filter-clear" id="filters-reset" disabled>Clear filters</button>';
    return $out . '</div>';
}

/**
 * ---- Uploaded-image cleanup -------------------------------------------
 * Photos live in uploads/items (reports) and uploads/chat (messages). The
 * database only stores the path, so removing a row used to leave the file
 * behind forever. These helpers delete a file once nothing references it.
 */

/** Delete one uploaded file by its stored path ("uploads/chat/abc.jpg") — only ever inside uploads/. */
function delete_upload_file($path) {
    $path = (string) $path;
    if ($path === '' || preg_match('#^https?://#i', $path)) return;
    $rel = ltrim(str_replace('\\', '/', $path), '/');
    if (strpos($rel, 'uploads/') !== 0 || strpos($rel, '..') !== false) return;
    $full = dirname(__DIR__) . '/' . $rel;
    if (is_file($full)) @unlink($full);
}

/**
 * Delete each of these uploaded files unless a report or a message still
 * points at it (e.g. a CSV import can reuse a path). Call it AFTER the rows
 * that referenced the files have been removed or updated.
 */
function delete_unused_uploads(PDO $pdo, array $paths) {
    $inReports  = $pdo->prepare('SELECT 1 FROM reports  WHERE image_path = ? LIMIT 1');
    $inMessages = $pdo->prepare('SELECT 1 FROM messages WHERE image_path = ? LIMIT 1');
    foreach (array_unique(array_filter($paths)) as $p) {
        $inReports->execute([$p]);
        if ($inReports->fetchColumn()) continue;
        $inMessages->execute([$p]);
        if ($inMessages->fetchColumn()) continue;
        delete_upload_file($p);
    }
}

/**
 * Does this report's image file actually exist on disk? Uploads aren't in
 * git (see .gitignore), so a copy of the project, or a restored database,
 * can easily have report rows pointing at files that aren't there — callers
 * show the "No Image" placeholder instead of a broken-image icon.
 */
function report_image_exists($path) {
    if (!$path) return false;
    if (preg_match('#^https?://#i', $path)) return true; // remote: can't check, let the browser try
    return is_file(dirname(__DIR__) . '/' . ltrim($path, '/'));
}

/**
 * Generate a fresh 6-digit verification code for a not-yet-verified user,
 * store it (15 minute expiry), and email it. Shared by the "resend code"
 * form on verify.php and the automatic resend triggered from login.php
 * when someone tries to log in before verifying.
 */
function send_verification_code(PDO $pdo, $userId, $email, $firstName) {
    $code    = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires = date('Y-m-d H:i:s', time() + 15 * 60);
    $upd = $pdo->prepare('UPDATE users SET verify_code = ?, verify_expires = ? WHERE id = ?');
    $upd->execute([$code, $expires, $userId]);

    if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
        require_once __DIR__ . '/../mail/send_verification.php';
        @send_verification_email($email, $firstName, $code, verification_link($email, $code));
    }
    return $code;
}

/** The one-click verification link put in the verification email (opens verify.php, which verifies and sends them to login). */
function verification_link($email, $code) {
    return app_url('verify.php?email=' . urlencode($email) . '&code=' . urlencode($code));
}

/**
 * This computer's address on the local network (e.g. 192.168.1.20), or '' if it can't be found.
 * Used so links in emails open on OTHER devices: "localhost" in an email only works on the server itself.
 */
function server_lan_ip() {
    static $ip = null;
    if ($ip !== null) return $ip;
    $ip = '';
    $ok = fn($v) => is_string($v) && filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                    && strpos($v, '127.') !== 0 && $v !== '0.0.0.0' && strpos($v, '169.254.') !== 0;
    // 1. The address the web server itself is listening on (when it isn't loopback).
    if ($ok($_SERVER['SERVER_ADDR'] ?? '')) return $ip = $_SERVER['SERVER_ADDR'];
    // 2. Ask the OS which interface it would use to reach the network. A UDP "connect" sends nothing.
    $s = @stream_socket_client('udp://8.8.8.8:53', $en, $es, 1);
    if ($s) {
        $name = @stream_socket_get_name($s, false);
        fclose($s);
        $cand = $name ? preg_replace('/:\d+$/', '', $name) : '';
        if ($ok($cand)) return $ip = $cand;
    }
    // 3. The machine's own host name.
    $cand = @gethostbyname((string) gethostname());
    if ($ok($cand)) return $ip = $cand;
    return $ip;
}

/**
 * The address links in emails should start with, e.g. "http://192.168.1.20/lost-and-found".
 *  - Inside a web request it follows how this page was reached, so the links work for whoever is on the
 *    same network: opened as localhost / 127.0.0.1 -> this computer's LAN IP; opened by IP -> that IP;
 *    opened by the host name in BASE_URL (an ngrok / public address) -> that address.
 *  - Any other Host header is ignored (it could be forged) and BASE_URL is used.
 *  - Outside a web request (command line) it is BASE_URL.
 */
function app_base_url() {
    static $base = null;
    if ($base !== null) return $base;
    $configured = rtrim(BASE_URL, '/');
    $base = $configured;
    $hostHeader = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if (PHP_SAPI === 'cli' || $hostHeader === '') return $base;

    $cfg = parse_url($configured) ?: [];
    if (!preg_match('/^(\[[0-9a-f:]+\]|[^:]+)(?::(\d+))?$/i', $hostHeader, $m)) return $base;
    $host = strtolower(trim($m[1], '[]'));
    $port = $m[2] ?? '';

    $isLoopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    $isIp       = (bool) filter_var($host, FILTER_VALIDATE_IP);
    $isConfigured = $host === strtolower((string) ($cfg['host'] ?? ''));
    if ($isLoopback) {
        $lan = server_lan_ip();
        if ($lan === '') return $base;
        $host = $lan;
    } elseif (!$isIp && !$isConfigured) {
        return $base;
    }

    $https  = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
              || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $scheme = $https ? 'https' : 'http';
    $path   = (string) ($cfg['path'] ?? '');
    // Work out the folder this app lives in from the running script, so a renamed folder still gives right links.
    $script = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $root   = realpath(dirname(__DIR__));
    $name   = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script && $root && strpos($script, $root) === 0 && $name !== '') {
        $rel = str_replace('\\', '/', substr($script, strlen($root)));
        if ($rel !== '' && substr($name, -strlen($rel)) === $rel) $path = rtrim(substr($name, 0, -strlen($rel)), '/');
    }
    $hostOut = strpos($host, ':') !== false ? '[' . $host . ']' : $host;
    return $base = $scheme . '://' . $hostOut . ($port !== '' && !in_array($port, ['80', '443'], true) ? ':' . $port : '') . $path;
}

/** Full URL for a page on this site, for links in emails (see app_base_url() for how the address is chosen). */
function app_url($path) {
    return app_base_url() . '/' . ltrim($path, '/');
}

/**
 * Generates a password-reset code and emails it. Reuses the verify_code /
 * verify_expires columns, which are empty for any verified account (they're
 * cleared when an account is verified), so no extra table or column is
 * needed. Used by forgot_password.php.
 */
function send_password_reset_code(PDO $pdo, $userId, $email, $firstName) {
    $code    = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires = date('Y-m-d H:i:s', time() + 15 * 60);
    $upd = $pdo->prepare('UPDATE users SET verify_code = ?, verify_expires = ? WHERE id = ?');
    $upd->execute([$code, $expires, $userId]);

    if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
        require_once __DIR__ . '/../mail/send_verification.php';
        @send_password_reset_email($email, $firstName, $code);
    }
    return $code;
}

/**
 * School-email fields only ask for the part before the "@": the domain is
 * shown next to the box as fixed text and appended here, on the server, after
 * submit. An address that already contains an "@" is left alone (the register
 * page then rejects a non-school domain, and login finds no such account).
 */
function normalize_school_email($raw) {
    $email = strtolower(trim((string) $raw));
    if ($email === '') return '';
    return strpos($email, '@') === false ? $email . SCHOOL_EMAIL_DOMAIN : $email;
}

/**
 * School emails are firstname.lastname@holychild.edu.ph, so the account's name is simply read from the
 * email instead of being typed again: "maria.santos@..." -> ["Maria", "Santos"]. Digits are ignored, dots /
 * hyphens / underscores separate the words, the LAST word is the last name and everything before it is the
 * first name ("juan.carlos.dela.cruz" -> "Juan Carlos Dela", "Cruz"). An email with just one word has no last name.
 * @return array [first, last]
 */
function name_from_school_email($email) {
    $local = email_local_part(strtolower(trim((string) $email)));
    $local = preg_replace('/@.*$/', '', $local);
    $words = array_values(array_filter(preg_split('/[^a-z]+/', $local)));
    if (!$words) return [$local !== '' ? ucfirst($local) : 'User', ''];
    $words = array_map(fn($w) => mb_convert_case($w, MB_CASE_TITLE, 'UTF-8'), $words);
    $last  = count($words) > 1 ? array_pop($words) : '';
    return [implode(' ', $words), $last];
}

/** "name@holychild.edu.ph" -> "name" (anything else is returned unchanged). */
function email_local_part($email) {
    $email = (string) $email;
    $d = SCHOOL_EMAIL_DOMAIN;
    if (strlen($email) > strlen($d) && strcasecmp(substr($email, -strlen($d)), $d) === 0) {
        return substr($email, 0, -strlen($d));
    }
    return $email;
}

/**
 * The "[ name | @holychild.edu.ph ]" email field: an editable box with the
 * school domain as a fixed, uneditable suffix. Posts as `email` (local part
 * only — see normalize_school_email()). js/main.js strips any "@..." typed or
 * pasted into it.
 */
function school_email_input($value = '', $autocomplete = 'email', $placeholder = 'name') {
    return '<div class="email-field">'
         . '<input type="text" name="email" value="' . e(email_local_part($value)) . '" inputmode="email" autocapitalize="none" '
         . 'autocomplete="' . e($autocomplete) . '" spellcheck="false" placeholder="' . e($placeholder) . '" required>'
         . '<span class="email-suffix">' . e(SCHOOL_EMAIL_DOMAIN) . '</span>'
         . '</div>';
}

/** Simple flash-message helper using the session. */
function set_flash($msg, $type = 'info') {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}
function get_flash() {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

/**
 * Lazy "cron": reports past their 30-day window auto-close instead of
 * needing anyone to pick "Closed" manually. Since this app has no server
 * cron access, this runs a cheap, indexed UPDATE at the top of the pages
 * that list reports — safe to call repeatedly, it only touches rows that
 * are still open and past the window.
 */
function auto_close_stale_reports(PDO $pdo) {
    // Only Active reports close on their own; a Resolved report stays as it is.
    // The 30 days run from when the report was made — or from when it last
    // became Active again / was reopened (reopened_at), so it isn't closed
    // again on the very next page load.
    // The rows are locked and closed in one transaction; each owner is told (System Notification +
    // email, see notify_report_change) only after it has committed. A second request running at the
    // same moment finds nothing left to close, so nobody is told twice.
    $due = "deleted_at IS NULL AND status = 'active' AND COALESCE(reopened_at, created_at) < (NOW() - INTERVAL " . (int) REPORT_CLOSE_DAYS . " DAY)";
    if (!$pdo->query("SELECT 1 FROM reports WHERE $due LIMIT 1")->fetchColumn()) return;   // the usual case: nothing to do
    db_transaction($pdo, function () use ($pdo, $due) {
        $rows = $pdo->query("SELECT id, user_id, item_name FROM reports WHERE $due ORDER BY id FOR UPDATE")->fetchAll();
        if (!$rows) return;
        $ids = array_column($rows, 'id');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE reports SET status = 'closed' WHERE id IN ($ph) AND status = 'active'")->execute($ids);
        foreach ($rows as $r) {
            if ((int) $r['user_id'] <= 0) continue;
            $r = (array) $r;
            after_commit(function () use ($pdo, $r) {
                notify_report_change($pdo, (int) $r['user_id'], 'Report closed automatically',
                    ['Status' => 'Active → Closed', 'Reason' => 'No update for ' . REPORT_CLOSE_DAYS . ' days', 'Next step' => 'Contact an admin to reopen it'],
                    $r['item_name'], (int) $r['id'],
                    'your report was closed automatically because it stayed Active for ' . REPORT_CLOSE_DAYS . ' days. Contact an admin if it should be reopened.');
            });
        }
    });
}

/**
 * Tells a report's owner about a change to it: a System Notification (the bell / Messages) AND an email,
 * which they can switch off in Settings ("Report updates", type "reports"). Used when an admin edits,
 * closes, reopens or resolves a report, and when a report closes by itself. Call it after the change has
 * committed. $details is [label => value] (shown on the notice card and as the email's rows); $intro
 * completes "Hi NAME, ..." in the email.
 */
function notify_report_change(PDO $pdo, $userId, $title, array $details, $itemName, $reportId, $intro, $noticeBody = null) {
    if ($userId <= 0) return;
    send_system_message($pdo, $userId, $noticeBody ?? build_notice($title, $details, $itemName, $reportId));
    $rows = [['Report', $itemName]];
    foreach ($details as $label => $value) $rows[] = [$label, $value];
    app_mail($pdo, $userId, 'reports', $title . ': “' . $itemName . '”', $title, $intro, [
        'rows'   => $rows,
        'button' => ['View the report', app_url('item.php?id=' . (int) $reportId)],
    ]);
}

/** What a report's status is called on screen. */
function status_label($status) {
    return ['active' => 'Active', 'resolved' => 'Resolved', 'closed' => 'Closed', 'deleted' => 'Deleted'][$status] ?? ucfirst((string) $status);
}

/** Days after it's filed that a report auto-closes (see auto_close_stale_reports()). */
const REPORT_CLOSE_DAYS = 30;

/** A user is "online" while they have a live connection (see the presence table): every open page's stream re-stamps its row every 10s, so a row not refreshed for this many seconds means the page is gone. */
const ONLINE_WINDOW_SECONDS = 25;

/** A conversation with no new message for this many days is deleted (see auto_delete_stale_conversations). */
const CONVERSATION_EXPIRE_DAYS = 30;

/** An Active report that hasn't been touched for this many days gets a "has it been resolved?" check-in (and again every this many days). */
const RESOLVED_ASK_EVERY_DAYS = 14;

/**
 * Cell content for the "Date Reported" column of the report tables: the date,
 * with a small "(closes in N days)" line under it while the report is still
 * open (the exact close date is in its tooltip). Closed reports get no line.
 */
function report_dates_cell($createdAt, $status, $reopenedAt = null) {
    $created = strtotime($createdAt);
    $out = e(date('m-d-Y', $created));
    if ($status === 'active') {
        // Only Active reports count down (resolved ones never auto-close);
        // from the report date, or from the last time it became Active again.
        $from    = $reopenedAt ? strtotime($reopenedAt) : $created;
        $closeTs = strtotime('+' . REPORT_CLOSE_DAYS . ' days', $from);
        $days    = max(0, (int) ceil(($closeTs - time()) / 86400));
        $label   = $days === 0 ? 'closes today' : 'closes in ' . $days . ' day' . ($days === 1 ? '' : 's');
        $out .= '<span class="date-close" title="Closes on ' . e(date('m-d-Y', $closeTs)) . '">(' . $label . ')</span>';
    } else {
        // Resolved and Closed rows: no countdown, but the same two-line cell
        // (invisible text) so every row is exactly as tall.
        $out .= '<span class="date-close date-close-spacer" aria-hidden="true">&nbsp;</span>';
    }
    return $out;
}

/**
 * The admin a user's "Contact Admin" button reaches by default: the
 * lowest-id admin account (the seeded System Admin, on a fresh install).
 */
function default_admin_id(PDO $pdo) {
    $stmt = $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
    return $stmt->fetchColumn() ?: null;
}

/** The user's System Notification conversation id, or null if they don't have one yet. */
function system_conversation_id(PDO $pdo, $userId) {
    $stmt = $pdo->prepare('SELECT id FROM conversations WHERE is_system = 1 AND user_b_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

/**
 * Recent unread messages for a user, across all their conversations —
 * powers the header's two dropdowns. $systemOnly splits the two: null for
 * both kinds together, true for just the System Notification thread
 * (the bell), false for just real conversations with other people (Chats).
 */
function recent_unread_messages(PDO $pdo, $userId, $limit = 30, $systemOnly = null) {
    $sql = "SELECT m.conversation_id AS convo_id, m.body, m.image_path, m.created_at,
                   u.first_name, u.last_name
            FROM messages m
            JOIN conversations c ON c.id = m.conversation_id
            JOIN users u ON u.id = m.sender_id
            WHERE (c.user_a_id = ? OR c.user_b_id = ?)
              AND m.sender_id != ?
              AND m.is_read = 0"
         . ($systemOnly === null ? '' : ' AND c.is_system = ' . ($systemOnly ? '1' : '0'))
         . ' ORDER BY m.created_at DESC LIMIT ?';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $userId, PDO::PARAM_INT);
    $stmt->bindValue(3, $userId, PDO::PARAM_INT);
    $stmt->bindValue(4, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Number of unread messages for a user (sent by the other party, not yet
 * opened). Same $systemOnly split as recent_unread_messages() — the bell
 * (system matches alerts) and Chats (real conversations) are counted and
 * shown completely separately in the header.
 */
function unread_message_count(PDO $pdo, $userId, $systemOnly = null) {
    $sql = "SELECT COUNT(*) FROM messages m
            JOIN conversations c ON c.id = m.conversation_id
            WHERE (c.user_a_id = ? OR c.user_b_id = ?)
              AND m.sender_id != ?
              AND m.is_read = 0"
         . ($systemOnly === null ? '' : ' AND c.is_system = ' . ($systemOnly ? '1' : '0'));
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $userId, $userId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Every conversation the user is in, newest activity first, with the
 * last-message preview fields and unread count the list needs. Shared by
 * messages.php (initial render) and ajax/stream.php (live updates).
 */
function get_conversations(PDO $pdo, $myId) {
    $stmt = $pdo->prepare(
        "SELECT c.id AS convo_id, c.report_id, c.subject, c.is_system, r.item_name,
            IF(c.user_a_id = ?, ub.first_name, ua.first_name) AS other_first,
            IF(c.user_a_id = ?, ub.last_name, ua.last_name) AS other_last,
            IF(c.user_a_id = ?, ub.email, ua.email) AS other_email,
            EXISTS (SELECT 1 FROM presence p WHERE p.user_id = IF(c.user_a_id = ?, c.user_b_id, c.user_a_id)
                    AND p.last_seen >= (NOW() - INTERVAL " . (int) ONLINE_WINDOW_SECONDS . " SECOND)) AS other_online,
            (SELECT body FROM messages m WHERE m.conversation_id = c.id ORDER BY m.created_at DESC LIMIT 1) AS last_body,
            (SELECT image_path FROM messages m WHERE m.conversation_id = c.id ORDER BY m.created_at DESC LIMIT 1) AS last_image,
            (SELECT deleted_at FROM messages m WHERE m.conversation_id = c.id ORDER BY m.created_at DESC LIMIT 1) AS last_deleted,
            (SELECT created_at FROM messages m WHERE m.conversation_id = c.id ORDER BY m.created_at DESC LIMIT 1) AS last_time,
            (SELECT COUNT(*) FROM messages m2 WHERE m2.conversation_id = c.id AND m2.sender_id != ? AND m2.is_read = 0) AS unread_count
     FROM conversations c
     LEFT JOIN reports r ON r.id = c.report_id
     JOIN users ua ON ua.id = c.user_a_id
     JOIN users ub ON ub.id = c.user_b_id
     WHERE c.user_a_id = ? OR c.user_b_id = ?
     ORDER BY c.is_system DESC, last_time DESC"
    );
    $stmt->execute([$myId, $myId, $myId, $myId, $myId, $myId, $myId]);
    return $stmt->fetchAll();
}

/**
 * The three-dot button for a conversation (conversation list rows and the open chat's title bar).
 * js/main.js (ConvoMenu) opens a small menu from its data-* attributes: the other person's email
 * (a mailto: link), the report the conversation is about, and delete conversation.
 */
function convo_menu_button($convoId, $otherName, $otherEmail, $reportId = null, $reportName = '') {
    return '<button type="button" class="kebab-btn convo-menu-btn" aria-haspopup="menu" aria-expanded="false" title="More" aria-label="More options"'
         . ' data-convo="' . (int) $convoId . '" data-name="' . e($otherName) . '" data-email="' . e($otherEmail) . '"'
         . ' data-report="' . ($reportId ? (int) $reportId : '') . '" data-report-name="' . e($reportName) . '">'
         . '<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>'
         . '</button>';
}

/**
 * Renders the conversation list markup. Used for a normal page load and
 * for the "convolist" events pushed over the SSE stream, so the two can
 * never drift apart.
 */
function render_convo_list($conversations, $activeId = 0) {
    $isAdmin = ($_SESSION['role'] ?? '') === 'admin';
    foreach ($conversations as $c): ?>
    <?php /* A user's "Contact Admin" thread (no report attached) is drawn red so it stands out; admin-side threads stay normal. */ ?>
    <div class="convo-row-wrap<?= $c['is_system'] ? ' convo-system' : '' ?><?= (!$isAdmin && !$c['is_system'] && !$c['report_id']) ? ' convo-inquiry' : '' ?><?= (int)$c['convo_id'] === (int)$activeId ? ' active' : '' ?>">
        <a class="convo-row" href="messages.php?id=<?= (int)$c['convo_id'] ?>" title="<?= $c['is_system'] ? 'System Notification' : e($c['other_first'] . ' ' . $c['other_last'] . ' — re: ' . ($c['report_id'] ? $c['item_name'] : ($c['subject'] ?: 'General Inquiry'))) ?>">
            <div>
                <?php if (!$c['is_system'] && !empty($c['other_online'])): ?><span class="convo-online" title="Online now" aria-label="Online now"></span><?php endif; ?>
                <strong><?= $c['is_system'] ? 'System Notification' : e($c['other_first'] . ' ' . $c['other_last']) ?></strong>
                <?php if (!$c['is_system']): ?>
                    <span class="convo-item">re: <?= e($c['report_id'] ? $c['item_name'] : ($c['subject'] ?: 'General Inquiry')) ?></span>
                <?php endif; ?>
                <?php if ($c['unread_count'] > 0): ?>
                    <span class="badge-count"><?= $c['unread_count'] > 99 ? '99+' : $c['unread_count'] ?></span>
                <?php endif; ?>
            </div>
            <p class="convo-preview">
                <?php if ($c['last_deleted']): ?>
                    <em>Deleted message</em>
                <?php elseif ($c['last_body']): ?>
                    <?= e(message_snippet($c['last_body'])) ?>
                <?php elseif ($c['last_image']): ?>
                    Sent a photo
                <?php else: ?>
                    No messages yet.
                <?php endif; ?>
            </p>
        </a>
        <?php if (!$c['is_system']): ?>
            <?= convo_menu_button($c['convo_id'], trim($c['other_first'] . ' ' . $c['other_last']), $c['other_email'], $c['report_id'], $c['item_name']) ?>
        <?php endif; ?>
    </div>
    <?php endforeach;
    if (!$conversations): ?>
        <p class="convo-empty">No conversations yet. Message someone from an item's page to get started.</p>
    <?php endif;
}

/**
 * Escapes a chat message body and turns any "item.php?id=123" reference in
 * it into a real link — this is the only kind of link the system's
 * possible-match messages contain, and chat bodies are otherwise rendered
 * as plain text, so without this they'd show as inert text the person has
 * to retype into the address bar. Safe for ordinary human messages too: the
 * pattern is a fixed path with a numeric id, built only after escaping, so
 * there's no way to inject an arbitrary href through it.
 */
function render_chat_text($text) {
    // No nl2br(): the bubble's CSS is `white-space: pre-wrap`, which already
    // renders each newline as a line break. nl2br() ALSO adds a <br> in front
    // of every newline, so every line break showed up twice (a blank line
    // between each line of a system notification).
    $escaped = e($text);
    return preg_replace('/\bitem\.php\?id=(\d+)\b/', '<a href="item.php?id=$1">$0</a>', $escaped);
}

/**
 * If a chat message mentions a match likelihood ("Likelihood: NN%" — the
 * system's possible-match messages always do, see notify_possible_matches())
 * returns a small labelled meter for it: a header ("Match likelihood" and
 * "NN% · Low/Medium/High"), a bar, and a one-line note on what the number
 * means. The tier (not a rainbow hue sweep) picks one of the site's own
 * colors, so it sits in the design instead of fighting it. Returns '' for
 * any ordinary human message, since it won't contain that phrase. Mirrored
 * in js/main.js's buildMatchBar() for messages that arrive live over the SSE
 * stream — keep the two in step.
 */
function match_likelihood_bar($text) {
    if (!preg_match('/Likelihood:\s*(\d+)%/', (string) $text, $mm)) return '';
    $pct = max(0, min(100, (int) $mm[1]));
    if ($pct >= 80)     { $tier = 'high';   $label = 'High'; }
    elseif ($pct >= 70) { $tier = 'medium'; $label = 'Medium'; }
    else                { $tier = 'low';    $label = 'Low'; }
    return '<div class="match-meter match-' . $tier . '" title="' . $pct . '% likely to be the same item">'
         . '<div class="match-meter-head"><span>Match likelihood</span><strong>' . $pct . '% · ' . $label . '</strong></div>'
         . '<div class="match-bar"><div class="match-bar-fill" style="width:' . $pct . '%"></div></div>'
         . '<p class="match-meter-note">The fuller the bar, the closer the two reports match on item name, location and date.</p>'
         . '</div>';
}

/**
 * Turns a possible-match system message (see notify_possible_matches()) into a
 * compact card: title, the two report names, the likelihood meter, and a
 * "Compare reports" button that opens them side by side. Returns '' for any
 * message that isn't one (including older ones that don't parse), so callers
 * fall back to showing the plain text. Mirrored by buildMatchCard() in
 * js/main.js for live messages — keep the two in step.
 */
function render_match_card($text) {
    $text = (string) $text;
    if (!preg_match('/^Possible Match!/', $text)) return '';
    if (!preg_match('/^Your Report: "(.*)"\s*$/m', $text, $mine)) return '';
    if (!preg_match('/^Matching Report: "(.*)"\s*$/m', $text, $theirs)) return '';
    if (!preg_match('/^Link: item\.php\?id=(\d+)(?:&mine=(\d+))?/m', $text, $link)) return '';
    $bar = match_likelihood_bar($text);
    if ($bar === '') return '';

    $hasMine = !empty($link[2]);
    $href    = 'item.php?id=' . $link[1] . ($hasMine ? '&mine=' . $link[2] : '');
    return '<div class="match-card">'
         . '<div class="match-card-title">Possible match found</div>'
         . '<dl class="match-card-pair">'
         .   '<div><dt>Your report</dt><dd title="' . e($mine[1]) . '">' . e($mine[1]) . '</dd></div>'
         .   '<div><dt>May match</dt><dd title="' . e($theirs[1]) . '">' . e($theirs[1]) . '</dd></div>'
         . '</dl>'
         . $bar
         . '<a class="btn match-card-btn" href="' . e($href) . '">' . ($hasMine ? 'Compare reports' : 'View report') . '</a>'
         . '</div>';
}

/**
 * ---- System Notification "notice cards" ---------------------------------
 * Every System Notification is shown as the same kind of card (title, a few
 * label/value rows, and a button to the relevant report when there is one) —
 * like the possible-match and admin-edit cards. This is the shared format
 * for the rest of them (status changes, reopened/closed reports, deleted
 * conversations). The message body looks like:
 *
 *   Notice: Report reopened
 *   Report: "Black wallet"
 *   Details: Status: Closed → Active; Closes: after 30 days, unless resolved
 *   Link: item.php?id=12
 *
 * "Report" and "Link" are optional. Keep render_notice_card() and the
 * JavaScript buildNoticeCard() in js/main.js in step.
 */
function build_notice($title, array $details, $reportName = null, $reportId = null, $action = null) {
    $clean = fn($v) => trim(str_replace(["\r", "\n", ';'], [' ', ' ', ','], (string) $v));
    $out = 'Notice: ' . $clean($title);
    if ($reportName !== null) $out .= "\nReport: \"" . $clean($reportName) . '"';
    $parts = [];
    foreach ($details as $label => $value) $parts[] = $clean(str_replace(':', '', $label)) . ': ' . $clean($value);
    if ($parts) $out .= "\nDetails: " . implode('; ', $parts);
    if ($reportId) $out .= "\nLink: item.php?id=" . (int) $reportId;
    if ($reportId && $action === 'resolve') $out .= "\nAction: resolve:" . (int) $reportId;   // a "Mark as Resolved" button right on the card
    return $out;
}

function render_notice_card($text) {
    $text = (string) $text;
    if (!preg_match('/^Notice: (.+)$/m', $text, $title) || strpos($text, 'Notice: ') !== 0) return '';

    $rows = [];
    if (preg_match('/^Report: "(.*)"\s*$/m', $text, $name)) $rows[] = ['Report', $name[1]];
    if (preg_match('/^Details: (.*)$/m', $text, $details)) {
        foreach (explode('; ', trim($details[1])) as $d) {
            if ($d === '') continue;
            if (preg_match('/^([^:]+): (.*)$/', $d, $m)) $rows[] = [$m[1], $m[2]];
            else $rows[] = ['Info', $d];
        }
    }
    $pair = '';
    foreach ($rows as [$label, $value]) {
        $pair .= '<div><dt>' . e($label) . '</dt><dd title="' . e($value) . '">' . e($value) . '</dd></div>';
    }
    // Optional "Mark as Resolved" button (the "has your report been resolved?" check-in). Looked up now,
    // so a report that's already resolved (or gone) isn't offered the button again.
    // (Older notices were saved as "Action: claim:ID"; both spellings are understood.)
    $action = '';
    if (preg_match('/^Action: (?:claim|resolve):(\d+)\s*$/m', $text, $act)) {
        global $pdo;
        $st = $pdo->prepare('SELECT status, deleted_at FROM reports WHERE id = ?');
        $st->execute([(int) $act[1]]);
        $row = $st->fetch();
        if ($row && $row['deleted_at'] === null) {
            if ($row['status'] === 'active') {
                $action = '<button type="button" class="btn match-card-btn notice-action" data-action="resolve" data-report="' . (int) $act[1] . '">Mark as Resolved</button>';
            } elseif ($row['status'] === 'resolved') {
                $action = '<span class="match-card-done">Marked as Resolved ✓</span>';
            }
        }
    }
    $view = preg_match('/^Link: item\.php\?id=(\d+)\s*$/m', $text, $link)
        ? '<a class="btn match-card-btn' . ($action !== '' ? ' btn-secondary' : '') . '" href="item.php?id=' . (int) $link[1] . '">View report</a>' : '';
    return '<div class="match-card admin-edit-card">'
         . '<div class="match-card-title">' . e(trim($title[1])) . '</div>'
         . ($pair !== '' ? '<dl class="match-card-pair">' . $pair . '</dl>' : '')
         . ($action . $view !== '' ? '<div class="match-card-actions">' . $action . $view . '</div>' : '')
         . '</div>';
}

/** One-line text for a message in lists (conversation list, header dropdowns): card messages show their title. */
function message_snippet($body, $width = 60) {
    $body = (string) $body;
    if (strpos($body, 'Notice: ') === 0) {
        $first = strtok($body, "\n");
        return mb_strimwidth(substr($first, strlen('Notice: ')), 0, $width, '…');
    }
    if (preg_match('/^An administrator edited your report\./', $body)) return 'Report edited by an administrator';
    return mb_strimwidth($body, 0, $width, '…');
}

/**
 * Turns the "An administrator edited your report." system message (see
 * edit_report.php) into a compact card — title, report name, what changed,
 * and a "View report" button — the same look as the possible-match card.
 * Returns '' for any other message so callers fall back to plain text.
 * Mirrored by buildAdminEditCard() in js/main.js for live messages — keep
 * the two in step.
 */
function render_admin_edit_card($text) {
    $text = (string) $text;
    if (!preg_match('/^An administrator edited your report\./', $text)) return '';
    if (!preg_match('/^Report: "(.*)"\s*$/m', $text, $name)) return '';
    if (!preg_match('/^Changes: (.*)$/m', $text, $changes)) return '';
    if (!preg_match('/^Link: item\.php\?id=(\d+)\s*$/m', $text, $link)) return '';

    // One row per change, laid out and styled exactly like the match card's
    // "Your report / May match" rows ("Item name: A → B" -> label + value).
    $rows = [['Report', $name[1]]];
    foreach (explode('; ', trim($changes[1])) as $c) {
        if ($c === '') continue;
        if (preg_match('/^([^:]+): (.*)$/', $c, $m))  $rows[] = [$m[1], $m[2]];
        elseif ($c === 'Description updated')         $rows[] = ['Info', 'Updated'];
        elseif ($c === 'Photo replaced')              $rows[] = ['Photo', 'Replaced'];
        else                                          $rows[] = ['Change', $c];
    }
    $pair = '';
    foreach ($rows as [$label, $value]) {
        $pair .= '<div><dt>' . e($label) . '</dt><dd title="' . e($value) . '">' . e($value) . '</dd></div>';
    }
    return '<div class="match-card admin-edit-card">'
         . '<div class="match-card-title">Report edited by an administrator</div>'
         . '<dl class="match-card-pair">' . $pair . '</dl>'
         . '<a class="btn match-card-btn" href="item.php?id=' . (int) $link[1] . '">View report</a>'
         . '</div>';
}

/**
 * Bell/badge payload: unread total plus the most recent unread messages.
 * Pushed over the SSE stream as the "notifications" event.
 */
function notification_payload(PDO $pdo, $userId) {
    $shape = function ($systemOnly) use ($pdo, $userId) {
        return [
            'unread' => unread_message_count($pdo, $userId, $systemOnly),
            'notifs' => array_map(function ($n) {
                return [
                    'convo_id'   => (int) $n['convo_id'],
                    'first_name' => $n['first_name'],
                    'last_name'  => $n['last_name'],
                    'snippet'    => $n['body'] ? message_snippet($n['body']) : 'Sent a photo',
                ];
            }, recent_unread_messages($pdo, $userId, 30, $systemOnly)),
        ];
    };
    // "system" feeds the bell (possible-match alerts — a dot, no number);
    // "chat" feeds the separate Chats icon (real conversations — a number).
    return ['system' => $shape(true), 'chat' => $shape(false)];
}

/**
 * Possible-match notifications (Reporting <-> Messaging Module bridge).
 *
 * When someone files a report, we look for existing reports of the
 * OPPOSITE type (lost <-> found) that look like they could be the same
 * item, and if any look promising, the system (a dedicated account, see
 * system_user_id() — NOT the human admin) opens a conversation
 * with the person who just filed the report and lists them.
 *
 * This runs at creation time, from whichever side was just filed
 * (report.php calls notify_possible_matches() right after the INSERT), and
 * again after an edit that changes the lost/found selection or the category
 * (see edit_report.php).
 * Running it from both sides is what makes it symmetric: filing a lost
 * report checks existing found reports; later filing the matching found
 * report checks existing lost reports and catches the earlier one then.
 * Nobody is notified a second time when a newer, closer match shows up
 * later — see the note on notify_possible_matches() if that's wanted too.
 */

// Tuning knobs, kept together so they're easy to revisit.
const MATCH_SCORE_THRESHOLD   = 60;  // 0-100; below this, don't suggest it
const MATCH_MAX_RESULTS       = 3;   // most matches to mention in one message
const MATCH_CANDIDATE_LIMIT   = 200; // candidates scored per report (newest first)
const MATCH_DATE_WINDOW_DAYS  = 60;  // item_date gap beyond this scores 0 on recency
const MATCH_NAME_GATE         = 75;  // with no shared word, the two names must still be at least this % alike

/** Lower-cased words of an item name worth comparing: 3+ letters (or "id"), a trailing plural "s" removed. */
function match_name_words($name) {
    $words = [];
    foreach (preg_split('/[^a-z0-9]+/', mb_strtolower(trim($name))) as $w) {
        if ($w === '' || (mb_strlen($w) < 3 && $w !== 'id')) continue;
        if (mb_strlen($w) > 3 && substr($w, -1) === 's') $w = substr($w, 0, -1);
        $words[$w] = true;
    }
    return array_keys($words);
}

/**
 * 0-100 likeness score between two reports (already known to be opposite types, same category, both
 * active). The ITEM NAME has to agree first: being the same category with nearby dates is NOT evidence
 * of a match on its own (a "Black wallet" and a "Blue backpack" filed the same day are not a match), so
 * unless the names share a word, contain one another, or are at least MATCH_NAME_GATE % alike, the
 * score is 0 whatever the location and date say. Once the names agree:
 *   - 45 pts  how alike the two names are overall (PHP's similar_text())
 *   - 25 pts  they share a word / one name contains the other ("wallet" ~ "brown wallet", "phone" ~ "iPhone")
 *   - 15 pts  the locations overlap
 *   - 15 pts  the item dates are close (a few days = full marks, MATCH_DATE_WINDOW_DAYS apart = none)
 * The threshold is MATCH_SCORE_THRESHOLD; tiers shown to people: 80+ High, 70+ Medium, else Low.
 */
function match_score(array $a, array $b) {
    $nameA = mb_strtolower(trim($a['item_name']));
    $nameB = mb_strtolower(trim($b['item_name']));
    if ($nameA === '' || $nameB === '') return 0;

    similar_text($nameA, $nameB, $namePct);
    $sharedWord = (bool) array_intersect(match_name_words($nameA), match_name_words($nameB));
    $contains   = $nameA === $nameB || mb_strpos($nameA, $nameB) !== false || mb_strpos($nameB, $nameA) !== false;
    $strong     = $sharedWord || $contains;

    if (!$strong && $namePct < MATCH_NAME_GATE) return 0;   // the names don't agree: nothing else can rescue it

    $score = $namePct * 0.45;
    if ($strong) $score += 25;

    $locA = mb_strtolower(trim($a['location']));
    $locB = mb_strtolower(trim($b['location']));
    if ($locA !== '' && $locB !== '') {
        similar_text($locA, $locB, $locPct);
        $score += $locPct * 0.15;
    }

    $daysApart = abs((strtotime($a['item_date']) - strtotime($b['item_date'])) / 86400);
    $score += max(0, 1 - ($daysApart / MATCH_DATE_WINDOW_DAYS)) * 15;

    return min(100, $score);
}

/**
 * Up to $limit opposite-type, same-category, still-active reports that
 * look like they could be the same item as $report, best match first.
 * $report is a full row from `reports` (as inserted/fetched, with id,
 * user_id, type, category, item_name, location, item_date). Reports whose
 * reporter's account was deleted are never suggested: nobody could be told.
 */
function find_possible_matches(PDO $pdo, array $report, $limit = MATCH_MAX_RESULTS) {
    $oppositeType = $report['type'] === 'lost' ? 'found' : 'lost';

    $stmt = $pdo->prepare(
        "SELECT * FROM reports
         WHERE type = ? AND category = ? AND status = 'active' AND deleted_at IS NULL
           AND user_id IS NOT NULL AND user_id != ? AND id != ?
         ORDER BY created_at DESC
         LIMIT " . (int) MATCH_CANDIDATE_LIMIT
    );
    $stmt->execute([$oppositeType, $report['category'], $report['user_id'], $report['id']]);

    $scored = [];
    foreach ($stmt->fetchAll() as $candidate) {
        $score = match_score($report, $candidate);
        if ($score >= MATCH_SCORE_THRESHOLD) {
            $scored[] = ['report' => $candidate, 'score' => $score];
        }
    }
    usort($scored, fn($x, $y) => $y['score'] <=> $x['score']);
    return array_slice($scored, 0, $limit);
}

/**
 * Every user has exactly one standing "System Notification" conversation
 * (report_id NULL, is_system = 1 — see the schema comment in sql/schema.sql
 * for why that flag has to be there), created the first time they're sent
 * one. Returns its id, or null if there's no seeded admin account to act
 * as the system.
 */

/**
 * The built-in "System" account used as the sender of automated messages (possible-match alerts,
 * status changes...). Deliberately NOT default_admin_id(): that is a real person who can log in and
 * file reports, and using them as "the system" made them message themselves and showed every other
 * user's System Notification thread in their inbox. This account can't log in (its password hash in
 * sql/schema.sql is not a valid hash). It is seeded by sql/schema.sql — this only looks it up.
 */
function system_user_id(PDO $pdo) {
    static $id = null;
    if ($id !== null) return $id;
    $find = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $find->execute([SYSTEM_USER_EMAIL]);
    $found = $find->fetchColumn();
    if (!$found) throw new RuntimeException('The System Notifications account is missing: import sql/schema.sql (or run sql/upgrade.sql).');
    return $id = (int) $found;
}

/**
 * Find the conversation between these two people about this report (or a general inquiry when
 * $reportId is null), creating it if needed — safe if the button is double-clicked or both people
 * start it at the same moment (no duplicates, no error page). Returns the conversation id.
 */
function find_or_create_conversation(PDO $pdo, $reportId, $userAId, $userBId, $subject = null) {
    $find = $reportId === null
        ? $pdo->prepare('SELECT id FROM conversations WHERE report_id IS NULL AND is_system = 0 AND user_a_id = ? AND user_b_id = ?')
        : $pdo->prepare('SELECT id FROM conversations WHERE report_id = ? AND user_a_id = ? AND user_b_id = ?');
    $args = $reportId === null ? [$userAId, $userBId] : [$reportId, $userAId, $userBId];

    $find->execute($args);
    $id = $find->fetchColumn();
    if ($id) return (int) $id;

    try {
        return db_transaction($pdo, function () use ($pdo, $find, $args, $reportId, $userAId, $userBId, $subject) {
            // Serialise on the person starting it, then look again (covers the NULL-report case the UNIQUE key can't).
            $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$userBId]);
            $find->execute($args);
            $id = $find->fetchColumn();
            if ($id) return (int) $id;
            $ins = $pdo->prepare('INSERT INTO conversations (report_id, subject, user_a_id, user_b_id) VALUES (?, ?, ?, ?)');
            $ins->execute([$reportId, $subject, $userAId, $userBId]);
            return (int) $pdo->lastInsertId();
        });
    } catch (PDOException $e) {
        // The UNIQUE key caught a duplicate from a simultaneous request: use the one that won.
        $find->execute($args);
        $id = $find->fetchColumn();
        if ($id) return (int) $id;
        throw $e;
    }
}

function get_or_create_system_conversation(PDO $pdo, $userId) {
    $systemId = system_user_id($pdo);
    if (!$systemId) return null;

    $find = $pdo->prepare('SELECT id FROM conversations WHERE is_system = 1 AND user_a_id = ? AND user_b_id = ?');
    $find->execute([$systemId, $userId]);
    $id = $find->fetchColumn();
    if ($id) return (int) $id;

    // The table's UNIQUE key can't stop duplicate system threads (report_id is NULL for them), so two
    // notifications created at the same instant could each make one. Locking this user's row for a
    // moment makes the second one wait, look again, and find the first one's thread.
    return db_transaction($pdo, function () use ($pdo, $systemId, $userId, $find) {
        $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$userId]);
        $find->execute([$systemId, $userId]);
        $id = $find->fetchColumn();
        if ($id) return (int) $id;
        $ins = $pdo->prepare('INSERT INTO conversations (report_id, subject, user_a_id, user_b_id, is_system) VALUES (NULL, ?, ?, ?, 1)');
        $ins->execute(['System Notification', $systemId, $userId]);
        return (int) $pdo->lastInsertId();
    });
}

/** Drops one message into $userId's System Notification thread. */
function send_system_message(PDO $pdo, $userId, $body) {
    $convoId = get_or_create_system_conversation($pdo, $userId);
    if (!$convoId) return;
    $systemId = system_user_id($pdo);
    $msg = $pdo->prepare('INSERT INTO messages (conversation_id, sender_id, body) VALUES (?, ?, ?)');
    $msg->execute([$convoId, $systemId, $body]);
}

/**
 * Finds possible matches for a freshly-created report and, for each one,
 * messages BOTH sides through their own System Notification thread — the
 * person who just filed $report, and the owner of the existing report it
 * might match. Each gets the pairing from their own point of view ("your
 * report" is always the one that particular recipient filed).
 */
function notify_possible_matches(PDO $pdo, array $report) {
    // Both sides' System Notifications for ALL the matches are written in one transaction: either
    // everyone involved is told or nobody is (never "the reporter heard about it, the other owner didn't").
    // The emails go out only after it has committed, so a rolled-back run can't leave an email behind.
    db_transaction($pdo, function () use ($pdo, $report) {
        foreach (find_possible_matches($pdo, $report) as $m) {
            $match = $m['report'];
            $pct   = (int) round($m['score']);

            // The last line is "Link: item.php?id=THEIR_REPORT&mine=YOUR_REPORT" (a
            // plain relative path: every top-level page lives in the same
            // directory). render_match_card() / buildMatchCard() in main.js turn
            // the whole message into the compact card with a "Compare reports"
            // button, which opens both reports side by side (item.php's mine=
            // mode). Keep this format in step with those two parsers.
            send_system_message($pdo, (int) $report['user_id'],
                "Possible Match! Likelihood: {$pct}%\n"
                . 'Your Report: "' . $report['item_name'] . '"' . "\n"
                . 'Matching Report: "' . $match['item_name'] . '"' . "\n"
                . 'Link: item.php?id=' . $match['id'] . '&mine=' . $report['id']);

            send_system_message($pdo, (int) $match['user_id'],
                "Possible Match! Likelihood: {$pct}%\n"
                . 'Your Report: "' . $match['item_name'] . '"' . "\n"
                . 'Matching Report: "' . $report['item_name'] . '"' . "\n"
                . 'Link: item.php?id=' . $report['id'] . '&mine=' . $match['id']);

            // The same alert by email, for anyone who has "possible matches" emails on.
            after_commit(function () use ($pdo, $report, $match, $pct) {
                foreach ([[$report, $match], [$match, $report]] as [$mine, $theirs]) {
                    app_mail($pdo, (int) $mine['user_id'], 'matches',
                        'Possible match for your report “' . $mine['item_name'] . '”',
                        'A possible match was found',
                        'a report was filed that looks like it could be the same item as yours.',
                        [
                            'rows'   => [['Your report', $mine['item_name']], ['May match', $theirs['item_name']], ['Likelihood', $pct . '%', $pct]],
                            'button' => ['Compare reports', app_url('item.php?id=' . $theirs['id'] . '&mine=' . $mine['id'])],
                        ]);
                }
            });
        }
    });
}

/**
 * ---- Report operations (delete / restore / permanent delete / resolver) ------------------------
 * ONE implementation each, used by the single-report endpoints and by the bulk actions, so both give
 * the same guarantees: everything runs in a transaction, the rows are locked first (in id order, so two
 * requests can never wait on each other), the state is checked AGAIN under the lock, and only rows that
 * are still in the expected state are changed. Each returns how many rows it really changed; the rest
 * were already changed by someone else, are not the person's to change, or no longer exist.
 */

/** SELECT ... FOR UPDATE on these reports, in id order. Returns the rows keyed by id. */
function lock_reports(PDO $pdo, array $ids) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    sort($ids);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM reports WHERE id IN ($ph) ORDER BY id FOR UPDATE");
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll() as $r) $out[(int) $r['id']] = $r;
    return $out;
}

/** Move reports to the trash. Owners can only delete their own; admins can delete any. */
function soft_delete_reports(PDO $pdo, array $ids, $actorId, $isAdmin) {
    return db_transaction($pdo, function () use ($pdo, $ids, $actorId, $isAdmin) {
        $todo = [];
        foreach (lock_reports($pdo, $ids) as $id => $r) {
            if ($r['deleted_at'] !== null) continue;                                   // someone already deleted it
            if (!$isAdmin && (int) $r['user_id'] !== (int) $actorId) continue;         // not theirs
            $todo[] = $id;
        }
        if (!$todo) return 0;
        $ph = implode(',', array_fill(0, count($todo), '?'));
        $upd = $pdo->prepare("UPDATE reports SET deleted_at = NOW() WHERE id IN ($ph) AND deleted_at IS NULL");
        $upd->execute($todo);
        return $upd->rowCount();
    });
}

/** Bring reports back from the trash. Owners: only their own, and only while still Active. Admins: any. */
function restore_reports(PDO $pdo, array $ids, $actorId, $isAdmin) {
    return db_transaction($pdo, function () use ($pdo, $ids, $actorId, $isAdmin) {
        $todo = [];
        foreach (lock_reports($pdo, $ids) as $id => $r) {
            if ($r['deleted_at'] === null) continue;                                   // already restored
            if (!$isAdmin && ((int) $r['user_id'] !== (int) $actorId || $r['status'] !== 'active')) continue;
            $todo[] = $id;
        }
        if (!$todo) return 0;
        $ph = implode(',', array_fill(0, count($todo), '?'));
        $upd = $pdo->prepare("UPDATE reports SET deleted_at = NULL WHERE id IN ($ph) AND deleted_at IS NOT NULL");
        $upd->execute($todo);
        return $upd->rowCount();
    });
}

/**
 * Remove reports for good (admins only — callers check). Only reports still in the trash qualify, so a
 * report restored a moment earlier is left alone. Its conversations, the report rows and the photo
 * records go in one transaction; the photo FILES are deleted only after it has committed.
 */
function permanently_delete_reports(PDO $pdo, array $ids) {
    return db_transaction($pdo, function () use ($pdo, $ids) {
        $rows = array_filter(lock_reports($pdo, $ids), fn($r) => $r['deleted_at'] !== null);
        if (!$rows) return 0;
        $goneIds = array_keys($rows);
        cleanup_conversations_for_reports($pdo, $goneIds);
        $ph = implode(',', array_fill(0, count($goneIds), '?'));
        $del = $pdo->prepare("DELETE FROM reports WHERE id IN ($ph) AND deleted_at IS NOT NULL");
        $del->execute($goneIds);
        $images = array_column($rows, 'image_path');
        after_commit(function () use ($pdo, $images) { delete_unused_uploads($pdo, $images); });
        return $del->rowCount();
    });
}

/** What to show as a report's reporter: their name, or "Deleted user" once the account is gone. */
function reporter_name(array $row) {
    $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    return (empty($row['user_id']) && empty($row['owner_id'])) || $name === '' ? 'Deleted user' : $name;
}

/**
 * The people a report's owner has talked to about that report (everyone but the owner and the system):
 * the likely finder / claimer, so "who resolved it?" is a click instead of typing. [{id, name}, ...]
 */
function report_resolver_candidates(PDO $pdo, $reportId, $ownerId) {
    if (!$ownerId) return [];
    $stmt = $pdo->prepare(
        "SELECT DISTINCT u.id, u.first_name, u.last_name
         FROM conversations c
         JOIN users u ON u.id = IF(c.user_a_id = ?, c.user_b_id, c.user_a_id)
         WHERE c.report_id = ? AND c.is_system = 0 AND (c.user_a_id = ? OR c.user_b_id = ?)
           AND u.id <> ? AND u.email <> ?
         ORDER BY u.first_name, u.last_name"
    );
    $stmt->execute([$ownerId, $reportId, $ownerId, $ownerId, $ownerId, SYSTEM_USER_EMAIL]);
    return array_map(fn($u) => ['id' => (int) $u['id'], 'name' => trim($u['first_name'] . ' ' . $u['last_name'])], $stmt->fetchAll());
}

/**
 * Sends an email through mail/emails.php if the mail setup is present. Always
 * safe to call: no vendor/ folder, a person who turned that email off in
 * Settings, or an SMTP failure all just mean "nothing sent" — never an error.
 * $type is one of: matches, resolved, conversations. See mail_to_user().
 */
function app_mail(PDO $pdo, $userId, $type, $subject, $heading, $intro, array $opts = []) {
    try {
        if (!app_mail_ready()) return false;
        return mail_to_user($pdo, $userId, $type, $subject, $heading, $intro, $opts);
    } catch (Throwable $e) {
        return false;
    }
}

/** Loads mail/emails.php (PHPMailer etc.) on first use. False if the mail setup isn't installed. */
function app_mail_ready() {
    if (!file_exists(__DIR__ . '/../vendor/autoload.php')) return false;
    require_once __DIR__ . '/../mail/emails.php';
    return true;
}

/**
 * When may this report be asked "has it been resolved?" again (unix time; 0 = right now)?
 * ONE cooldown for both kinds of request: whether the last one came from the automatic
 * check-in or from an admin's button, the next one — of either kind — waits
 * RESOLVED_ASK_EVERY_DAYS days (resolved_asked_at is stamped by both).
 */
function ask_available_at(array $report) {
    if (empty($report['resolved_asked_at'])) return 0;
    return strtotime($report['resolved_asked_at']) + (int) RESOLVED_ASK_EVERY_DAYS * 86400;
}

/**
 * Ask a report's owner whether it has been resolved: a System Notification card
 * (with a View report button) and, if they have those emails on, an email with
 * a one-click "mark as Resolved" button. $report needs id, user_id, item_name,
 * created_at. Used by the automatic check-ins and by the admin's "Ask if
 * resolved" button in Manage Reports.
 */
function request_report_update(PDO $pdo, array $report) {
    $uid = (int) $report['user_id'];
    if ($uid <= 0) return false;   // the reporter's account was deleted: nobody to ask

    // Claiming the cooldown IS the check: this single UPDATE only succeeds if the report is still
    // Active, still there, and nobody (the automatic check-in, another admin, a double click) has
    // asked within the cooldown. Two requests at once can't both win, so nobody is asked twice.
    $sent = db_transaction($pdo, function () use ($pdo, $report, $uid) {
        $claim = $pdo->prepare(
            "UPDATE reports SET resolved_asked_at = NOW()
             WHERE id = ? AND status = 'active' AND deleted_at IS NULL
               AND (resolved_asked_at IS NULL OR resolved_asked_at <= (NOW() - INTERVAL " . (int) RESOLVED_ASK_EVERY_DAYS . " DAY))"
        );
        $claim->execute([(int) $report['id']]);
        if ($claim->rowCount() !== 1) return false;

        send_system_message($pdo, $uid, build_notice('Has your report been resolved?', [
            'Status'    => 'Active',
            'Posted'    => date('M j, Y', strtotime($report['created_at'])),
            'Next step' => 'Mark it Resolved once it is',
        ], $report['item_name'], $report['id'], 'resolve'));

        // The email goes out only after the above has committed.
        after_commit(function () use ($pdo, $report, $uid) {
            if (!app_mail_ready()) return;
            app_mail($pdo, $uid, 'resolved',
                'Has your report “' . $report['item_name'] . '” been resolved?',
                'Has your report been resolved?',
                'your report is still marked Active. If the item has been found or returned, you can mark it as Resolved with one click.',
                [
                    'rows'   => [['Report', $report['item_name']], ['Posted', date('M j, Y', strtotime($report['created_at']))]],
                    'button' => resolve_url($report['id'], $uid) !== '' ? ['Yes, mark it as Resolved', resolve_url($report['id'], $uid)] : null,
                    'link'   => ['Not yet — view the report', app_url('item.php?id=' . (int) $report['id'])],
                    'footer' => 'Active reports close on their own after ' . REPORT_CLOSE_DAYS . ' days; Resolved reports stay open.',
                ]);
        });
        return true;
    });
    return $sent;
}

/**
 * The automatic check-ins: every Active report that has gone RESOLVED_ASK_EVERY_DAYS
 * without a check-in (counted from when it was posted, last reopened or last asked)
 * is asked whether it has been resolved. A few per run, so one page load never has
 * to send a flood of emails.
 */
function auto_request_report_updates(PDO $pdo, $limit = 5) {
    $days  = (int) RESOLVED_ASK_EVERY_DAYS;
    $limit = (int) $limit;
    $rows = $pdo->query(
        "SELECT r.id, r.user_id, r.item_name, r.created_at
         FROM reports r
         JOIN users u ON u.id = r.user_id
         WHERE r.deleted_at IS NULL AND r.status = 'active' AND u.role <> 'admin' AND u.email <> " . $pdo->quote(SYSTEM_USER_EMAIL) . "
           AND GREATEST(COALESCE(r.resolved_asked_at, '1970-01-01 00:00:00'), COALESCE(r.reopened_at, r.created_at))
               <= (NOW() - INTERVAL $days DAY)
         ORDER BY r.created_at ASC
         LIMIT $limit"
    )->fetchAll();
    foreach ($rows as $r) request_report_update($pdo, $r);
}

/** First message in a conversation: tell the other person by email (if they have "new conversation" emails on). */
function notify_new_conversation(PDO $pdo, array $convo, $senderId, $body) {
    $otherId = (int) ($convo['user_a_id'] == $senderId ? $convo['user_b_id'] : $convo['user_a_id']);
    $stmt = $pdo->prepare('SELECT first_name, last_name FROM users WHERE id = ?');
    $stmt->execute([(int) $senderId]);
    $from = $stmt->fetch();
    if (!$from) return;
    $name  = trim($from['first_name'] . ' ' . $from['last_name']);
    $about = !empty($convo['report_id']) ? '“' . ($convo['item_name'] ?? 'an item') . '”' : 'General inquiry';
    $rows  = [['From', $name], ['About', $about]];
    if ($body !== null && $body !== '') $rows[] = ['Message', mb_strimwidth($body, 0, 140, '…')];
    app_mail($pdo, $otherId, 'conversations',
        $name . ' started a conversation with you',
        'New conversation',
        $name . ' sent you a message.',
        ['rows' => $rows, 'button' => ['Open conversation', app_url('messages.php?id=' . (int) $convo['id'])]]);
}
