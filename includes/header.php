<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($pageTitle) ? e($pageTitle) . ' — ' : '' ?><?= SITE_NAME ?></title>
<link rel="icon" type="image/png" href="<?= isset($assetPrefix) ? $assetPrefix : '' ?>assets/LOSTANDFOUNDLOGO.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Questrial&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= isset($assetPrefix) ? $assetPrefix : '' ?>css/style.css?v=<?= filemtime(__DIR__ . '/../css/style.css') ?>">
</head>
<body<?= isset($bodyClass) && $bodyClass !== '' ? ' class="' . e($bodyClass) . '"' : '' ?> data-prefix="<?= isset($assetPrefix) ? e($assetPrefix) : '' ?>"<?= (($_SESSION['role'] ?? '') === 'admin') ? ' data-role="admin"' : '' ?><?= !empty($watch) ? ' data-watch="' . e($watch) . '"' : '' ?>>
<?php
$prefix   = isset($assetPrefix) ? $assetPrefix : '';
$isAdmin  = is_logged_in() && ($_SESSION['role'] ?? '') === 'admin';
// The bell is for the System Notification thread (possible-match alerts)
// only; Chats is for everything else (real conversations with people).
// Kept as two completely separate counts/lists — see notification_payload().
$sysUnread  = is_logged_in() ? unread_message_count($pdo, $_SESSION['user_id'], true) : 0;
$sysNotifs  = is_logged_in() ? recent_unread_messages($pdo, $_SESSION['user_id'], 30, true) : [];
$chatUnread = is_logged_in() ? unread_message_count($pdo, $_SESSION['user_id'], false) : 0;
$chatNotifs = is_logged_in() ? recent_unread_messages($pdo, $_SESSION['user_id'], 30, false) : [];
// The bell's "See all messages" opens the System Notification thread itself
// when it exists (otherwise it goes to the plain Messages page).
$sysConvoId = is_logged_in() ? system_conversation_id($pdo, $_SESSION['user_id']) : null;
// Name + email shown at the top of the sliding menu.
$sideName  = is_logged_in() ? trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) : '';
$sideEmail = '';
if (is_logged_in()) {
    $emailStmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
    $emailStmt->execute([$_SESSION['user_id']]);
    $sideEmail = (string) $emailStmt->fetchColumn();
}
?>
<?php
if (!function_exists('nav_icon')) {
    function nav_icon($name) {
        $p = [
            'home'     => '<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
            'search'   => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
            'plus'     => '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>',
            'file'     => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M8 13h8M8 17h5"/>',
            'chat'     => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
            'help'     => '<circle cx="12" cy="12" r="9"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3M12 17h.01"/>',
            'grid'     => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
            'clipboard'=> '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>',
            'users'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
            'trash'    => '<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>',
            'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
            'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
            'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
            'login'    => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/>',
            'userplus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>',
            'check'    => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
            'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'archive'  => '<rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v11a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8M10 12h4"/>',
            'chevron'  => '<path d="m9 6 6 6-6 6"/>',
            'menu'     => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        ][$name] ?? '';
        return '<svg class="nav-ico" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
    }
}
$selfPath   = str_replace('\\', '/', $_SERVER['PHP_SELF'] ?? '');
$selfFile   = basename($selfPath);
$inAdmin    = strpos($selfPath, '/admin/') !== false;
$navActive  = function ($file, $admin = false, $extra = null) use ($selfFile, $inAdmin) {
    if ($admin !== $inAdmin || $selfFile !== $file) return '';
    if ($extra !== null && !$extra) return '';
    return ' class="active" aria-current="page"';
};
$isTrashView = $inAdmin && $selfFile === 'reports.php' && (($_GET['view'] ?? '') === 'trash');
$initials = '';
if ($sideName !== '') {
    foreach (preg_split('/\s+/', $sideName) as $w) { if ($w !== '' && strlen($initials) < 2) $initials .= strtoupper($w[0]); }
}
?>
<div class="app-shell">
<div id="side-overlay" class="side-overlay"></div>
<aside id="side-menu" class="side-menu" aria-label="Main menu">
    <div class="side-menu-head">
        <a class="brand" href="<?= $prefix ?>index.php">
            <img class="brand-logo" src="<?= $prefix ?>assets/LOSTANDFOUNDLOGOWTITLE.png" alt="HCCD Lost &amp; Found">
        </a>
        <button type="button" id="menu-close" class="icon-btn menu-close" aria-label="Close menu">
            <svg width="16" height="16" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M2 2l10 10M12 2L2 12"/></svg>
        </button>
    </div>

    <nav class="side-nav">
        <a href="<?= $prefix ?>index.php"<?= $navActive('index.php') ?>><?= nav_icon('home') ?><span>Home</span></a>
        <?php if (is_logged_in()): ?><a href="<?= $prefix ?>search.php"<?= $navActive('search.php') ?>><?= nav_icon('search') ?><span>Browse</span></a><?php endif; ?>
        <?php if (is_logged_in()): ?>
            <a href="<?= $prefix ?>report.php"<?= $navActive('report.php') ?>><?= nav_icon('plus') ?><span>Report Item</span></a>
            <a href="<?= $prefix ?>my_reports.php"<?= $navActive('my_reports.php') ?>><?= nav_icon('file') ?><span>My Reports</span></a>
            <a href="<?= $prefix ?>messages.php"<?= $navActive('messages.php') ?>><?= nav_icon('chat') ?><span>Messages</span> <span class="badge-count" id="sidemenu-badge"<?= $chatUnread > 0 ? '' : ' hidden' ?>><?= $chatUnread > 99 ? '99+' : $chatUnread ?></span></a>
            <?php if (!$isAdmin): ?>
                <a href="<?= $prefix ?>contact_admin.php"<?= $navActive('contact_admin.php') ?>><?= nav_icon('help') ?><span>Contact Admin</span></a>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
                <div class="side-menu-divider">Admin</div>
                <a href="<?= $prefix ?>admin/index.php"<?= $navActive('index.php', true) ?>><?= nav_icon('grid') ?><span>Dashboard</span></a>
                <a href="<?= $prefix ?>admin/reports.php"<?= $navActive('reports.php', true, !$isTrashView) ?>><?= nav_icon('clipboard') ?><span>Manage Reports</span></a>
                <a href="<?= $prefix ?>admin/reports.php?view=trash"<?= $isTrashView ? ' class="active" aria-current="page"' : '' ?>><?= nav_icon('trash') ?><span>Trash</span></a>
                <a href="<?= $prefix ?>admin/users.php"<?= $navActive('users.php', true) ?>><?= nav_icon('users') ?><span>Manage Users</span></a>
            <?php endif; ?>
        <?php else: ?>
            <a href="<?= $prefix ?>login.php"<?= $navActive('login.php') ?>><?= nav_icon('login') ?><span>Login</span></a>
            <a href="<?= $prefix ?>register.php"<?= $navActive('register.php') ?>><?= nav_icon('userplus') ?><span>Register</span></a>
        <?php endif; ?>
    </nav>

    <div class="side-bottom">
        <?php if (is_logged_in()): ?>
            <div class="side-menu-user">
                <span class="avatar"><?= e($initials ?: '?') ?></span>
                <div class="side-menu-user-text">
                    <div class="side-menu-name" title="<?= e($sideName) ?>"><?= e($sideName) ?></div>
                    <div class="side-menu-email" title="<?= e($sideEmail) ?>"><?= e($sideEmail) ?></div>
                </div>
            </div>
            <a href="<?= $prefix ?>settings.php" class="side-settings-link<?= $selfFile === 'settings.php' ? ' active' : '' ?>"><?= nav_icon('settings') ?><span>Settings</span></a>
            <a href="<?= $prefix ?>logout.php" class="side-menu-logout confirm-link"
               data-confirm-title="Log out?"
               data-confirm-message="You'll need to sign in again to use your account."
               data-confirm-label="Log out"><?= nav_icon('logout') ?><span>Logout</span></a>
        <?php else: ?>
            <p class="side-help">Found something? Help it find its way home.</p>
        <?php endif; ?>
    </div>
</aside>

<div class="workspace">
<header class="site-header">
    <div class="header-inner">
        <button type="button" id="menu-toggle" class="icon-btn menu-toggle" aria-label="Open menu" aria-expanded="false" aria-controls="side-menu"><?= nav_icon('menu') ?></button>
        <a class="brand brand-mobile" href="<?= $prefix ?>index.php">
            <img class="brand-logo" src="<?= $prefix ?>assets/LOSTANDFOUNDLOGOWTITLE.png" alt="HCCD Lost &amp; Found">
        </a>
        <?php if (is_logged_in()): ?>
            <span class="topbar-title">HCCD <span>/</span> Lost &amp; Found</span>
        <?php endif; ?>

        <?php if (is_logged_in()): ?>
            <div class="header-icons">
                <?php if ($isAdmin): ?>
                    <span class="admin-tag" title="You're signed in to the admin account">Admin</span>
                <?php endif; ?>
                <div class="icon-dropdown">
                    <button type="button" id="notif-toggle" class="icon-btn" aria-label="Notifications<?= $sysUnread > 0 ? ' (new)' : '' ?>" aria-expanded="false" aria-controls="notif-panel">
                        <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                        <!-- Dot only, deliberately no count: this is for the
                             System Notification thread (possible-match
                             alerts), where "how many" matters less than
                             "is there something new". -->
                        <span class="badge-dot icon-badge" id="notif-badge"<?= $sysUnread > 0 ? '' : ' hidden' ?>></span>
                    </button>
                    <div id="notif-panel" class="dropdown-panel" role="menu">
                        <h4>Notifications</h4>
                        <div id="notif-list">
                        <?php if ($sysNotifs): ?>
                            <?php foreach ($sysNotifs as $n): ?>
                                <a class="notif-item" href="<?= $prefix ?>messages.php?id=<?= (int)$n['convo_id'] ?>">
                                    <strong><?= e($n['first_name'] . ' ' . $n['last_name']) ?></strong>
                                    <p><?= e($n['body'] ? message_snippet($n['body']) : 'Sent a photo') ?></p>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="dropdown-empty">No new notifications.</p>
                        <?php endif; ?>
                        </div>
                        <a class="dropdown-footer-link" id="notif-see-all" href="<?= $prefix ?>messages.php<?= $sysConvoId ? '?id=' . (int)$sysConvoId : '' ?>">See all messages</a>
                    </div>
                </div>

                <div class="icon-dropdown">
                    <button type="button" id="chat-toggle" class="icon-btn" aria-label="Chats<?= $chatUnread > 0 ? ' (' . $chatUnread . ' unread)' : '' ?>" aria-expanded="false" aria-controls="chat-panel">
                        <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        <span class="badge-count icon-badge" id="chat-badge"<?= $chatUnread > 0 ? '' : ' hidden' ?>><?= $chatUnread > 99 ? '99+' : $chatUnread ?></span>
                    </button>
                    <div id="chat-panel" class="dropdown-panel" role="menu">
                        <h4>Chats</h4>
                        <div id="chat-list">
                        <?php if ($chatNotifs): ?>
                            <?php foreach ($chatNotifs as $n): ?>
                                <a class="notif-item" href="<?= $prefix ?>messages.php?id=<?= (int)$n['convo_id'] ?>">
                                    <strong><?= e($n['first_name'] . ' ' . $n['last_name']) ?></strong>
                                    <p><?= e($n['body'] ? message_snippet($n['body']) : 'Sent a photo') ?></p>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="dropdown-empty">No new messages.</p>
                        <?php endif; ?>
                        </div>
                        <a class="dropdown-footer-link" href="<?= $prefix ?>messages.php">See all messages</a>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <nav class="guest-nav">
                <a class="btn btn-sm" href="<?= $prefix ?>login.php">Login</a>
            </nav>
        <?php endif; ?>
    </div>
</header>

<main class="container">
<script>window.IS_LOGGED_IN = <?= is_logged_in() ? 'true' : 'false' ?>; window.SITE_PREFIX = <?= json_encode($prefix) ?>;</script>
<script>window.SCHOOL_EMAIL_DOMAIN = <?= json_encode(SCHOOL_EMAIL_DOMAIN) ?>;</script>
<?php
// Session flash messages now surface as a toast instead of a static banner
// (Toast isn't defined yet here — js/main.js loads at the end of the body —
// so this just queues it; main.js drains window.__pendingToasts on load).
// A background refresh of a page (X-Live-Refresh, see the live updates in js/main.js) must not use up a message
// that was meant for the person's next real page load.
$flash = empty($_SERVER['HTTP_X_LIVE_REFRESH']) ? get_flash() : null;
if ($flash): ?>
<script>(window.__pendingToasts = window.__pendingToasts || []).push(<?= json_encode($flash) ?>);</script>
<?php endif; ?>
