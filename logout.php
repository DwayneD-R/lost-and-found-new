<?php
require_once __DIR__ . '/includes/functions.php';

// Ends the login completely (session, cookie, live-page records). The pages themselves are never
// cached (see the headers in includes/functions.php), so pressing Back afterwards can't show the
// account again — the server just says "log in".
end_session_now();
redirect('login.php');
