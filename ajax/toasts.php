<?php
require_once __DIR__ . '/../includes/functions.php';

// Toasts waiting for this visitor (see includes/toasts.php). Logged-in users
// get theirs through the live stream; this is what the login / register pages
// poll, since a guest has no stream.
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode(take_toasts($pdo, toast_owner()));
