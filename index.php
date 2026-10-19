<?php
require_once __DIR__ . '/includes/functions.php';
// Open to guests too: they see the recent reports and a sign-in prompt.
if (is_logged_in()) housekeeping();

auto_close_stale_reports($pdo);

$stmt = $pdo->query(
    "SELECT r.*
     FROM reports r
     WHERE r.deleted_at IS NULL
     ORDER BY r.created_at DESC
     LIMIT " . (is_logged_in() ? 8 : 5)
);
$reports = $stmt->fetchAll();

// The cards are rendered by one function for the normal page and for the live refresh (below), so the two
// never drift apart.
function time_ago_short($ts) {
    $d = time() - strtotime($ts);
    if ($d < 60) return 'Just now';
    if ($d < 3600) return floor($d / 60) . ' min ago';
    if ($d < 86400) { $h = floor($d / 3600); return $h . ' hour' . ($h == 1 ? '' : 's') . ' ago'; }
    if ($d < 604800) { $n = floor($d / 86400); return $n . ' day' . ($n == 1 ? '' : 's') . ' ago'; }
    return date('M j, Y', strtotime($ts));
}

function render_recent_cards($reports) {
    foreach ($reports as $r): ?>
    <a class="item-card" href="item.php?id=<?= (int)$r['id'] ?>">
        <div class="item-thumb">
            <?php if ($r['image_path'] && report_image_exists($r['image_path'])): ?>
                <img src="<?= e($r['image_path']) ?>" alt="<?= e($r['item_name']) ?>" loading="lazy">
            <?php else: ?>
                <div class="no-image">No Image</div>
            <?php endif; ?>
            <span class="badge badge-<?= e($r['type']) ?>"><?= e(ucfirst($r['type'])) ?></span>
        </div>
        <div class="item-body">
            <h3 title="<?= e($r['item_name']) ?>"><?= e($r['item_name']) ?></h3>
            <p class="item-meta"><span class="type-text type-<?= e($r['type']) ?>"><?= e(ucfirst($r['type'])) ?></span> &middot; <?= e($r['category']) ?></p>
            <p title="<?= e($r['location']) ?>"><?= e($r['location']) ?></p>
            <div class="item-foot">
                <span class="item-time"><?= e(time_ago_short($r['created_at'])) ?></span>
                <span class="status status-<?= e($r['status']) ?>"><?= e(status_label($r['status'])) ?></span>
            </div>
        </div>
    </a>
    <?php endforeach;
    if (!$reports): ?>
        <div class="empty-state"><h3>Nothing reported yet</h3><p>When someone posts a lost or found item, it will appear here.</p></div>
    <?php endif;
}

// Live refresh (js/main.js): the stream says the reports changed (one was deleted, restored, added...),
// and the page asks for just the cards again.
if (isset($_GET['ajax'])) {
    header('Content-Type: text/html; charset=utf-8');
    render_recent_cards($reports);
    exit;
}

$loggedIn = is_logged_in();
if ($loggedIn) $watch = 'browse';   // the live stream tells this page when the list of reports changes
$stats = $pdo->query(
    "SELECT COUNT(*) AS total,
            SUM(status='active') AS active,
            SUM(status='resolved') AS resolved,
            SUM(status='closed') AS closed
     FROM reports WHERE deleted_at IS NULL"
)->fetch();
$hour = (int) date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$pageTitle = $loggedIn ? 'Home' : 'Welcome';
require_once __DIR__ . '/includes/header.php';
?>
<section class="welcome">
    <div class="welcome-copy">
        <span class="eyebrow">Holy Child College of Davao</span>
        <?php if ($loggedIn): ?>
            <h1><?= e($greet) ?>, <?= e($_SESSION['first_name'] ?? '') ?>!<br><em>Lost or found something?</em></h1>
            <p>Help others find what they're looking for.</p>
        <?php else: ?>
            <h1>Lost something?<br><em>Found something?</em></h1>
            <p>Report a lost item or browse found reports to find what you're looking for.</p>
        <?php endif; ?>
        <div class="welcome-actions">
            <?php if ($loggedIn): ?>
                <a class="btn" href="report.php">+ Report an Item</a>
                <a class="btn btn-secondary" href="search.php">Browse Reports</a>
            <?php else: ?>
                <a class="btn" href="register.php">Get started</a>
                <a class="btn btn-secondary" href="login.php">Log in</a>
            <?php endif; ?>
        </div>
    </div>
</section>

<div class="stat-grid stat-grid-4">
    <div class="stat-card"><span class="stat-ico ico-blue"><?= nav_icon('archive') ?></span><span class="stat-label">Total Reports</span><span class="stat-num"><?= (int)$stats['total'] ?></span></div>
    <div class="stat-card"><span class="stat-ico ico-gold"><?= nav_icon('clock') ?></span><span class="stat-label">Active</span><span class="stat-num"><?= (int)$stats['active'] ?></span></div>
    <div class="stat-card"><span class="stat-ico ico-green"><?= nav_icon('check') ?></span><span class="stat-label">Resolved</span><span class="stat-num"><?= (int)$stats['resolved'] ?></span></div>
    <div class="stat-card"><span class="stat-ico ico-muted"><?= nav_icon('file') ?></span><span class="stat-label">Closed</span><span class="stat-num"><?= (int)$stats['closed'] ?></span></div>
</div>

<div class="section-row">
    <h2>Recent Reports</h2>
    <a class="view-all" href="<?= is_logged_in() ? 'search.php' : 'login.php' ?>"><?= is_logged_in() ? 'View all' : 'Log in to see all' ?> &rarr;</a>
</div>

<div class="card-grid" id="recent-results">
<?php render_recent_cards($reports); ?>
</div>

<?php if (!$loggedIn): ?>
<div class="guest-cta">
    <div>
        <h3>Want to report an item or contact a finder?</h3>
        <p>Sign in with your school email to post reports and send messages.</p>
    </div>
    <div class="welcome-actions">
        <a class="btn" href="login.php">Log in</a>
        <a class="btn btn-secondary" href="register.php">Create account</a>
    </div>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
