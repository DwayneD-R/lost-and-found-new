<?php
/**
 * Server-Sent Events stream — replaces the old ajax/poll.php,
 * ajax/notifications.php and messages.php?ajax=1 polling endpoints.
 *
 * One stream per open page (not one per feature), because every open
 * stream holds a PHP worker for as long as it lives. Events pushed:
 *
 *   notifications  {unread, notifs[]}     every page: bell + menu badges
 *   convolist      {html}                 only with ?list=1 (Messages page)
 *   messages       [ {message}, ... ]     only with ?convo=ID (open thread)
 *   deleted        [ id, ... ]            only with ?convo=ID
 *   gone           {}                     open conversation was deleted
 *
 * Query params: convo, list, after (last message id already on screen),
 * since_deleted (unix time the page was rendered). On an automatic
 * EventSource reconnect the browser sends Last-Event-ID (the last message
 * id we pushed), which takes over from `after` so nothing is re-sent.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/sse.php';

if (!is_logged_in()) {
    http_response_code(401);
    exit;
}

$myId         = (int) $_SESSION['user_id'];
$convoId      = (int) ($_GET['convo'] ?? 0);
$wantList     = !empty($_GET['list']);
$afterId      = (int) ($_GET['after'] ?? 0);
$sinceDeleted = (int) ($_GET['since_deleted'] ?? 0);

// What else this page wants to hear about: the reports it is showing. The page says so with ?watch=
//   manage  every report (Manage Reports, admins only)     mine  the user's own (My Reports)
//   browse  every report that isn't deleted (Browse)       item:ID  one report (an open report page)
// users = Manage Users (admins), dash = the admin dashboard (admins).
// The stream then pushes a "reports" event whenever they change, and the page refreshes itself.
$watch = null;
$watchParam = (string) ($_GET['watch'] ?? '');
if ($watchParam === 'manage' && ($_SESSION['role'] ?? '') === 'admin') $watch = ['scope' => 'manage'];
elseif ($watchParam === 'mine')   $watch = ['scope' => 'mine'];
elseif ($watchParam === 'browse') $watch = ['scope' => 'browse'];
elseif (preg_match('/^item:(\d+)$/', $watchParam, $wm)) $watch = ['scope' => 'item', 'id' => (int) $wm[1]];
// ?watch=users (Manage Users, admins only): pushes a "presence" event whenever somebody goes online or offline.
$watchUsers = $watchParam === 'users' && ($_SESSION['role'] ?? '') === 'admin';
if ($watchUsers) $watch = ['scope' => 'users'];                       // Manage Users: accounts added / verified / deleted
if ($watchParam === 'dash' && ($_SESSION['role'] ?? '') === 'admin') $watch = ['scope' => 'dash'];   // Admin dashboard: the counts
$presencePrev = null;
$lastPresenceWatch = 0;
$watchPrev = null;
$lastWatch = 0;

$lastEventId = $_SERVER['HTTP_LAST_EVENT_ID'] ?? '';
if ($lastEventId !== '' && ctype_digit($lastEventId)) {
    $afterId = max($afterId, (int) $lastEventId);
}

// Releases the session lock and switches off every layer of output
// buffering (see includes/sse.php for why both matter).
$GLOBALS['__stream_session_id'] = session_id();   // remembered for the presence row (it ties this page to its login session)
sse_start();

/**
 * A short string that changes whenever the reports a page is watching change. Based on updated_at
 * (which MySQL moves on every change to a report) plus counts, so a new, deleted or restored report
 * shows up too — a permanent delete only lowers the count.
 */
function watch_fingerprint(PDO $pdo, array $watch, $myId) {
    $cols = "CONCAT_WS('|', COUNT(*), COALESCE(UNIX_TIMESTAMP(MAX(updated_at)), 0), COALESCE(SUM(deleted_at IS NOT NULL), 0), COALESCE(SUM(status = 'resolved'), 0), COALESCE(SUM(status = 'closed'), 0))";
    switch ($watch['scope']) {
        case 'manage': return (string) $pdo->query("SELECT $cols FROM reports")->fetchColumn();
        case 'browse': return (string) $pdo->query("SELECT $cols FROM reports WHERE deleted_at IS NULL")->fetchColumn();
        case 'mine':
            $st = $pdo->prepare("SELECT $cols FROM reports WHERE user_id = ?");
            $st->execute([$myId]);
            return (string) $st->fetchColumn();
        case 'users':
            return (string) $pdo->query("SELECT CONCAT_WS('|', COUNT(*), COALESCE(MAX(id), 0), COALESCE(SUM(is_verified), 0), COALESCE(SUM(role = 'admin'), 0)) FROM users")->fetchColumn();
        case 'dash':
            return (string) $pdo->query("SELECT CONCAT_WS('|', (SELECT $cols FROM reports), (SELECT COUNT(*) FROM users), (SELECT COUNT(*) FROM conversations))")->fetchColumn();
        case 'item':
            $st = $pdo->prepare("SELECT CONCAT_WS('|', status, deleted_at IS NOT NULL, UNIX_TIMESTAMP(updated_at), version) FROM reports WHERE id = ?");
            $st->execute([$watch['id']]);
            $v = $st->fetchColumn();
            return $v === false ? 'gone' : (string) $v;
    }
    return '';
}

/** What the page is told when watched reports change (an item page also gets that report's state). */
function watch_payload(PDO $pdo, array $watch) {
    $out = ['scope' => $watch['scope']];
    if ($watch['scope'] === 'item') {
        $st = $pdo->prepare('SELECT status, deleted_at FROM reports WHERE id = ?');
        $st->execute([$watch['id']]);
        $r = $st->fetch();
        $out['id'] = $watch['id'];
        $out['exists'] = (bool) $r;
        $out['status'] = $r ? $r['status'] : null;
        $out['deleted'] = $r ? $r['deleted_at'] !== null : true;
    }
    return $out;
}

/** Everyone's online state for Manage Users (same data the table is first rendered with). */
function users_presence_payload(PDO $pdo) {
    $stmt = $pdo->prepare(
        'SELECT id, last_active_at,
                EXISTS (SELECT 1 FROM presence p WHERE p.user_id = users.id AND p.last_seen >= (NOW() - INTERVAL ' . (int) ONLINE_WINDOW_SECONDS . ' SECOND)) AS is_online
         FROM users WHERE email <> ?'
    );
    $stmt->execute([SYSTEM_USER_EMAIL]);
    $out = [];
    foreach ($stmt->fetchAll() as $u) {
        $ts = !empty($u['last_active_at']) ? (int) strtotime($u['last_active_at']) : 0;
        $out[] = ['id' => (int) $u['id'], 'online' => !empty($u['is_online']), 'ts' => $ts, 'label' => $ts ? date('M j, Y', $ts) : ''];
    }
    return $out;
}

function stream_fingerprint(PDOStatement $stmt, $myId) {
    $stmt->execute([$myId, $myId, $myId, $myId]);
    return implode('|', $stmt->fetch(PDO::FETCH_NUM));
}

try {
    // Is the requested conversation real, and is this user in it?
    if ($convoId) {
        $check = $pdo->prepare('SELECT user_a_id, user_b_id FROM conversations WHERE id = ?');
        $check->execute([$convoId]);
        $c = $check->fetch();
        if (!$c || ($c['user_a_id'] != $myId && $c['user_b_id'] != $myId)) {
            sse_send('gone', new stdClass());
            exit;
        }
    }

    // One cheap query that changes whenever anything this user can see changes:
    // a new/deleted/read message or a conversation created/deleted. Only when
    // it moves do we run the heavier queries and push anything.
    $fingerprintStmt = $pdo->prepare(
        "SELECT COALESCE(MAX(m.id), 0) AS max_id,
                COALESCE(MAX(UNIX_TIMESTAMP(m.deleted_at)), 0) AS max_del,
                COALESCE(SUM(m.sender_id != ? AND m.is_read = 0), 0) AS unread,
                COUNT(DISTINCT c.id) AS convos,
                COALESCE(SUM(DISTINCT CASE WHEN EXISTS (SELECT 1 FROM presence p WHERE p.user_id = uo.id AND p.last_seen >= (NOW() - INTERVAL " . (int) ONLINE_WINDOW_SECONDS . " SECOND)) THEN uo.id ELSE 0 END), 0) AS online_ids
         FROM conversations c
         LEFT JOIN messages m ON m.conversation_id = c.id
         LEFT JOIN users uo ON uo.id = IF(c.user_a_id = ?, c.user_b_id, c.user_a_id)
         WHERE c.user_a_id = ? OR c.user_b_id = ?"
    );

    $newMsgs = $pdo->prepare(
        "SELECT m.id, m.body, m.image_path, m.created_at, m.sender_id, m.deleted_at, u.first_name
         FROM messages m JOIN users u ON u.id = m.sender_id
         WHERE m.conversation_id = ? AND m.id > ?
         ORDER BY m.id ASC"
    );
    $newDeleted = $pdo->prepare(
        "SELECT id FROM messages
         WHERE conversation_id = ? AND deleted_at IS NOT NULL
           AND UNIX_TIMESTAMP(deleted_at) >= ?
         ORDER BY deleted_at ASC"
    );

    $fingerprint = null;
    $sentDeleted = [];
    $deadline    = time() + 55;   // recycle the worker; EventSource reconnects by itself
    $lastBeat    = time();
    $lastSeen    = 0;             // last time we stamped this user's "last active" (see below)
    $lastToastCheck = 0;

    // Presence: this open page counts as the user being online. One row per open
    // connection, re-stamped every 10s below; the row simply goes stale if the page
    // vanishes, and if PHP notices the browser hang up (tab closed, navigated away,
    // laptop shut) the shutdown function below lets it expire within seconds instead
    // of waiting out the full window. Nothing depends on logging out.
    $conn = bin2hex(random_bytes(8));
    $lastPresence = time();
    $sessionId = $GLOBALS['__stream_session_id'];   // captured before the session was released
    $pdo->exec('DELETE FROM presence WHERE last_seen < (NOW() - INTERVAL 10 MINUTE)');
    $pdo->prepare('INSERT INTO presence (conn, user_id, session_id, last_seen) VALUES (?, ?, ?, NOW())')->execute([$conn, $myId, $sessionId]);
    // Tell the page which connection it is, so it can send the "leave" beacon (ajax/leave.php) when it closes.
    sse_send('hello', ['conn' => $conn]);
    register_shutdown_function(function () use ($pdo, $conn, $myId) {
        if (!connection_aborted()) return;   // just the 55s recycle: the next stream takes over
        try {
            // Leave a few seconds of grace so moving between pages doesn't flicker "offline".
            $pdo->prepare('UPDATE presence SET last_seen = (NOW() - INTERVAL ' . (int) (ONLINE_WINDOW_SECONDS - 5) . ' SECOND) WHERE conn = ?')->execute([$conn]);
            $pdo->prepare('UPDATE users SET last_active_at = NOW() WHERE id = ?')->execute([$myId]);
        } catch (Throwable $e) {}
    });

    while (!connection_aborted() && time() < $deadline) {
        // While a page is open and connected, keep the user's "last active" fresh
        // (Manage Users and the conversation list show a green "online" mark for anyone
        // seen within ONLINE_WINDOW_SECONDS).
        // Messages for this user that were produced after their page had already answered
        // (e.g. "an email couldn't be sent") — shown as toasts.
        if (time() - $lastToastCheck >= 2) {
            $waiting = take_toasts($pdo, 'u:' . $myId);
            if ($waiting) sse_send('toasts', $waiting);
            $lastToastCheck = time();
        }
        // Reports this page is showing: tell it when they change (checked every 2s; the first look is
        // just the starting point, so opening the page never triggers a refresh by itself).
        if ($watch !== null && time() - $lastWatch >= 2) {
            $lastWatch = time();
            try {
                $wf = watch_fingerprint($pdo, $watch, $myId);
                if ($watchPrev === null) {
                    $watchPrev = $wf;
                } elseif ($wf !== $watchPrev) {
                    $watchPrev = $wf;
                    sse_send('reports', watch_payload($pdo, $watch));
                }
            } catch (Throwable $e) {
                error_log('watch failed: ' . $e->getMessage());
                $watch = null;   // stop trying on this connection
            }
        }
        // Manage Users: push who is online whenever that changes (checked every 2s; the first look is
        // just the starting point).
        if ($watchUsers && time() - $lastPresenceWatch >= 2) {
            $lastPresenceWatch = time();
            $list = users_presence_payload($pdo);
            $fp = md5(json_encode(array_map(fn($u) => [$u['id'], $u['online']], $list)));
            if ($presencePrev === null) {
                $presencePrev = $fp;
            } elseif ($fp !== $presencePrev) {
                $presencePrev = $fp;
                sse_send('presence', $list);
            }
        }
        if (time() - $lastPresence >= 10) {
            $lastPresence = time();
            // This page's own row decides what happens next:
            //   gone  -> the login session ended (logout in another tab, or closed too long): go to the login screen;
            //   left  -> the page sent its leave beacon (it is closing): stop streaming, and do NOT bring the row back to life;
            //   else  -> still here: stamp it.
            $own = $pdo->prepare('SELECT left_at FROM presence WHERE conn = ?');
            $own->execute([$conn]);
            $row = $own->fetch();
            if (!$row) { sse_send('signedout', new stdClass()); exit; }
            if ($row['left_at'] !== null) exit;
            $pdo->prepare('UPDATE presence SET last_seen = NOW() WHERE conn = ? AND left_at IS NULL')->execute([$conn]);
        }
        if (time() - $lastSeen >= 60) {
            try { $pdo->prepare('UPDATE users SET last_active_at = NOW() WHERE id = ?')->execute([$myId]); } catch (Throwable $e) {}
            $lastSeen = time();
        }

        $current = stream_fingerprint($fingerprintStmt, $myId);

        if ($current !== $fingerprint) {
            $fingerprint = $current;

            if ($convoId) {
                // NOTE: this stream deliberately does NOT mark anything as read.
                // A stream can outlive the page that opened it (the server only
                // notices the browser left when a write fails), and a leftover
                // stream marking messages read would swallow the notification
                // for the next message. The open page confirms what it has
                // actually shown via ajax/mark_read.php instead.

                $newMsgs->execute([$convoId, $afterId]);
                $rows = [];
                foreach ($newMsgs->fetchAll() as $r) {
                    $deleted = $r['deleted_at'] !== null;
                    $rows[] = [
                        'id'         => (int) $r['id'],
                        'sender_id'  => (int) $r['sender_id'],
                        'first_name' => $r['first_name'],
                        'body'       => $deleted ? null : $r['body'],
                        'image_path' => $deleted ? null : $r['image_path'],
                        'created_at' => $r['created_at'],
                        'deleted_at' => $r['deleted_at'],
                    ];
                }
                if ($rows) {
                    $afterId = $rows[count($rows) - 1]['id'];
                    sse_send('messages', $rows, $afterId);
                }

                $newDeleted->execute([$convoId, $sinceDeleted]);
                $ids = [];
                foreach ($newDeleted->fetchAll() as $d) {
                    $id = (int) $d['id'];
                    if (!isset($sentDeleted[$id])) {
                        $sentDeleted[$id] = true;
                        $ids[] = $id;
                    }
                }
                if ($ids) {
                    sse_send('deleted', $ids);
                }
            }

            sse_send('notifications', notification_payload($pdo, $myId));

            if ($wantList) {
                ob_start();
                render_convo_list(get_conversations($pdo, $myId), $convoId);
                sse_send('convolist', ['html' => ob_get_clean()]);
            }

            $lastBeat = time();
        }

        // Comment line: keeps idle connections open through proxies, and is
        // how PHP notices the browser went away (a write to a dead socket).
        if (time() - $lastBeat >= 5) {
            sse_ping();
            $lastBeat = time();
        }

        sleep(1);
    }
} catch (Throwable $e) {
    // Most likely a SQL/schema problem. Log it and tell the browser, rather
    // than dying silently and leaving the page looking frozen.
    error_log('SSE stream error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    sse_send('problem', ['message' => $e->getMessage()]);
}
