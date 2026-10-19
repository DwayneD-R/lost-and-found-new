<?php
/**
 * Shared setup for Server-Sent Events endpoints (ajax/stream.php and the
 * ajax/stream_check.php self-test). Everything that could stop a live
 * stream from actually being live is handled here, in one place.
 *
 * Two things can silently break SSE:
 *
 *  1. SESSION LOCKING. While a PHP script runs it holds an exclusive lock
 *     on the visitor's session file. A stream stays open for a minute, so
 *     every other request from the same person (loading a page, sending a
 *     message) would wait in line behind it and appear to hang. Fix: read
 *     what we need from the session, then release the lock right away.
 *
 *  2. OUTPUT BUFFERING. PHP, gzip, and web servers like to collect output
 *     and send it in one big chunk once the script ends. For a page that's
 *     efficient; for a stream it means events arrive in a clump after the
 *     stream is over instead of the moment they happen. Fix: switch every
 *     layer we control off, and tell the layers we don't control (nginx,
 *     Apache gzip) to stay out of the way.
 *
 * Call sse_start() AFTER reading anything you need from $_SESSION and
 * BEFORE producing any output.
 */

function sse_start() {
    // --- 1. Session lock ---------------------------------------------
    // $_SESSION stays readable after this; it just can't be written to,
    // so a stream must never call set_flash() or change session data.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // Keep running while the browser is connected, with no time limit;
    // stop when it goes away (checked via connection_aborted()).
    ignore_user_abort(false);
    @set_time_limit(0);

    // Warnings/notices must go to the error log, not into the response:
    // XAMPP ships with display_errors on, and printed text before the
    // headers are set makes the browser reject the stream outright.
    @ini_set('display_errors', '0');

    // --- 2. Output buffering -----------------------------------------
    @ini_set('zlib.output_compression', '0'); // gzip holds output back
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');       // Apache mod_deflate
    }

    // Anything PHP already buffered (php.ini's output_buffering, or stray
    // whitespace after a closing PHP tag in an include) is discarded, so the
    // first bytes on the wire are ours.
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    ob_implicit_flush(true);

    if (headers_sent($file, $line)) {
        // Some file printed output (often a blank line after a closing PHP tag) before we
        // could set stream headers — the stream will not work until fixed.
        error_log("SSE: output already sent by {$file}:{$line}");
    }

    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('X-Accel-Buffering: no'); // nginx: don't buffer this response

    // Reconnect quickly if the connection drops. The ~2KB comment pads
    // past any proxy/server buffer so the first real event isn't held back.
    echo "retry: 1500\n";
    echo ':' . str_repeat(' ', 2048) . "\n\n";
    sse_flush();
}

/** Push everything written so far to the browser immediately. */
function sse_flush() {
    if (ob_get_level() > 0) {
        @ob_flush();
    }
    flush();
}

function sse_send($event, $data, $id = null) {
    if ($id !== null) {
        echo "id: {$id}\n";
    }
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    sse_flush();
}

/** Comment line: keeps idle connections open, and writing it is how PHP
 *  notices the browser has gone away. */
function sse_ping() {
    echo ": ping\n\n";
    sse_flush();
}
