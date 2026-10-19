<?php
// chat.php was merged into messages.php (Messenger-style combined view —
// conversation list + active thread in one page). Kept as a thin redirect
// so old bookmarks/links to chat.php?id=X still land in the right thread.
require_once __DIR__ . '/includes/functions.php';
require_login();
redirect('messages.php?id=' . (int)($_GET['id'] ?? 0));
