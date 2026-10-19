<?php
/**
 * Self-test for live streaming. Log in, then open /ajax/stream_check.php
 * in the browser. It runs a 5-second test and reports whether:
 *   - output buffering is really off (events arrive one per second, not
 *     all in a clump at the end), and
 *   - session locking is released (a normal request made WHILE the stream
 *     is running answers instantly instead of waiting for it to finish).
 * Safe to delete once you've confirmed both pass on your server.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/sse.php';

if (!is_logged_in()) {
    http_response_code(401);
    exit('Log in first, then reload this page.');
}

// An ordinary request that uses the session — exactly what a page load or
// "send message" does. If the stream still held the session lock, this
// would block until the stream finished.
if (isset($_GET['ping'])) {
    header('Content-Type: text/plain');
    echo 'pong';
    exit;
}

// The test stream: five ticks, one second apart.
if (isset($_GET['run'])) {
    sse_start();
    for ($i = 1; $i <= 5; $i++) {
        sse_send('tick', ['n' => $i]);
        sleep(1);
    }
    sse_send('done', new stdClass());
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Live stream self-test</title>
<style>
    body { font-family: system-ui, sans-serif; max-width: 560px; margin: 40px auto; padding: 0 16px; line-height: 1.5; }
    .row { padding: 10px 14px; border-radius: 8px; margin: 10px 0; background: #f2f0ea; }
    .ok   { background: #e3f3e6; }
    .fail { background: #fbe4e4; }
    code  { background: #eee; padding: 1px 5px; border-radius: 4px; }
</style>
</head>
<body>
<h1>Live stream self-test</h1>
<p id="status">Running (takes about 5 seconds)…</p>
<div id="log"></div>
<div id="verdicts"></div>

<script>
const log = document.getElementById('log');
const verdicts = document.getElementById('verdicts');
const t0 = performance.now();
const arrivals = [];
let pingMs = null;

function row(text, cls) {
    const d = document.createElement('div');
    d.className = 'row' + (cls ? ' ' + cls : '');
    d.textContent = text;
    return d;
}

const es = new EventSource('stream_check.php?run=1');

es.addEventListener('tick', (e) => {
    const ms = Math.round(performance.now() - t0);
    arrivals.push(ms);
    log.appendChild(row(`Event ${JSON.parse(e.data).n} arrived at ${ms} ms`));
});

// Mid-stream, make a normal session-using request and time it.
setTimeout(async () => {
    const start = performance.now();
    try {
        await fetch('stream_check.php?ping=1', { cache: 'no-store' });
        pingMs = Math.round(performance.now() - start);
    } catch (err) { pingMs = -1; }
}, 1500);

es.addEventListener('done', () => {
    es.close();
    document.getElementById('status').textContent = 'Finished.';
    // Wait a beat in case the ping response is still in flight.
    setTimeout(report, 300);
});

es.onerror = () => {
    if (es.readyState === EventSource.CLOSED) {
        document.getElementById('status').textContent = 'The stream failed to open (are you logged in?).';
    }
};

function report() {
    const span = arrivals.length > 1 ? arrivals[arrivals.length - 1] - arrivals[0] : 0;
    const streamed = arrivals.length === 5 && span >= 3000;
    verdicts.appendChild(streamed
        ? row(`✅ Output buffering: OFF. Events arrived live, one per second (spread over ${span} ms).`, 'ok')
        : row(`❌ Output buffering: ON somewhere. Events arrived in a clump (spread over only ${span} ms) instead of one per second. Check for gzip/proxy buffering in your web server config.`, 'fail'));

    const unlocked = pingMs !== null && pingMs >= 0 && pingMs < 1000;
    verdicts.appendChild(unlocked
        ? row(`✅ Session locking: released. A normal request made mid-stream answered in ${pingMs} ms.`, 'ok')
        : row(`❌ Session locking: the stream is still holding the session lock (a normal request took ${pingMs} ms — it had to wait for the stream to finish). Check that your session handler isn't a custom one that ignores session_write_close().`, 'fail'));
}
</script>
</body>
</html>
