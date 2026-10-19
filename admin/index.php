<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$sysEmail = $pdo->quote(SYSTEM_USER_EMAIL); // the built-in alert sender isn't a real user
$counts = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM users WHERE email != $sysEmail) AS total_users,
        (SELECT COUNT(*) FROM reports WHERE deleted_at IS NULL) AS total_reports,
        (SELECT COUNT(*) FROM reports WHERE deleted_at IS NULL AND status='active') AS active_reports,
        (SELECT COUNT(*) FROM reports WHERE deleted_at IS NULL AND status='resolved') AS resolved_reports,
        (SELECT COUNT(*) FROM reports WHERE deleted_at IS NOT NULL) AS deleted_reports,
        (SELECT COUNT(*) FROM reports WHERE deleted_at IS NULL AND status='closed') AS closed_reports,
        (SELECT COUNT(*) FROM reports WHERE deleted_at IS NULL AND type='lost') AS lost_reports,
        (SELECT COUNT(*) FROM reports WHERE deleted_at IS NULL AND type='found') AS found_reports,
        (SELECT COUNT(*) FROM messages WHERE created_at >= CURDATE()) AS messages_today,
        (SELECT COUNT(*) FROM conversations) AS total_conversations"
)->fetch();

// Reports filed per day over the last 7 days (for the bar chart).
$daily = array_fill_keys(array_map(fn($i) => date('Y-m-d', strtotime("-$i days")), range(6, 0)), 0);
foreach ($pdo->query(
    "SELECT DATE(created_at) AS d, COUNT(*) AS n FROM reports
     WHERE created_at >= CURDATE() - INTERVAL 6 DAY GROUP BY DATE(created_at)"
) as $row) {
    if (isset($daily[$row['d']])) $daily[$row['d']] = (int) $row['n'];
}
$dailyMax = max(1, max($daily));

$lost  = (int) $counts['lost_reports'];
$found = (int) $counts['found_reports'];
$typeTotal = $lost + $found;
$lostPct  = $typeTotal ? round($lost * 100 / $typeTotal) : 0;
$foundPct = $typeTotal ? 100 - $lostPct : 0;
$statusTotal = max(1, (int) $counts['total_reports']);

$assetPrefix = '../';
$watch = 'dash';   // the live stream tells this page when the numbers change
$pageTitle = 'Admin Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <div>
        <h1>Admin Dashboard</h1>
        <p class="page-subtitle">An overview of reports, users and activity.</p>
    </div>
</div>

<div id="dash-live">
<div class="stat-grid stat-grid-4">
    <div class="stat-card"><span class="stat-ico ico-blue"><?= nav_icon('archive') ?></span><span class="stat-label">Total Reports</span><span class="stat-num"><?= (int)$counts['total_reports'] ?></span></div>
    <div class="stat-card"><span class="stat-ico ico-gold"><?= nav_icon('users') ?></span><span class="stat-label">Users</span><span class="stat-num"><?= (int)$counts['total_users'] ?></span></div>
    <div class="stat-card"><span class="stat-ico ico-green"><?= nav_icon('chat') ?></span><span class="stat-label">Messages Today</span><span class="stat-num"><?= (int)$counts['messages_today'] ?></span></div>
    <div class="stat-card"><span class="stat-ico ico-red"><?= nav_icon('trash') ?></span><span class="stat-label">In Trash</span><span class="stat-num"><?= (int)$counts['deleted_reports'] ?></span></div>
</div>

<div class="dash-grid">
    <section class="panel">
        <h2 class="panel-title">Reports by Type</h2>
        <div class="donut-wrap">
            <div class="donut" style="--p: <?= $typeTotal ? $lostPct : 0 ?>;<?= $typeTotal ? '' : ' --empty: 1;' ?>" role="img" aria-label="Lost <?= $lostPct ?>%, Found <?= $foundPct ?>%">
                <span><strong><?= $typeTotal ?></strong><small>reports</small></span>
            </div>
            <ul class="legend">
                <li><span class="dot dot-lost"></span>Lost <b><?= $lost ?></b> <em><?= $lostPct ?>%</em></li>
                <li><span class="dot dot-found"></span>Found <b><?= $found ?></b> <em><?= $foundPct ?>%</em></li>
            </ul>
        </div>
    </section>

    <section class="panel">
        <h2 class="panel-title">Reports by Status</h2>
        <div class="hbars">
            <?php foreach ([['Active', 'active_reports', 'active'], ['Resolved', 'resolved_reports', 'resolved'], ['Closed', 'closed_reports', 'closed']] as [$label, $key, $cls]):
                $n = (int) $counts[$key]; ?>
                <div class="hbar">
                    <div class="hbar-head"><span><?= $label ?></span><b><?= $n ?></b></div>
                    <div class="hbar-track"><span class="hbar-fill hbar-<?= $cls ?>" style="width: <?= round($n * 100 / $statusTotal) ?>%"></span></div>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="panel-note"><?= (int)$counts['total_conversations'] ?> conversations in total</p>
    </section>

    <section class="panel panel-wide">
        <h2 class="panel-title">New Reports — Last 7 Days</h2>
        <div class="vbars">
            <?php foreach ($daily as $d => $n): ?>
                <div class="vbar" title="<?= e(date('M j', strtotime($d))) ?>: <?= $n ?>">
                    <span class="vbar-num"><?= $n ?></span>
                    <div class="vbar-track"><span style="height: <?= round($n * 100 / $dailyMax) ?>%"></span></div>
                    <span class="vbar-label"><?= e(date('D', strtotime($d))) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel">
        <h2 class="panel-title">Quick Actions</h2>
        <div class="quick-actions">
            <a href="users.php"><?= nav_icon('users') ?><span>Manage Users</span><?= nav_icon('chevron') ?></a>
            <a href="reports.php"><?= nav_icon('clipboard') ?><span>Manage Reports</span><?= nav_icon('chevron') ?></a>
            <a href="reports.php?view=trash"><?= nav_icon('trash') ?><span>Trash</span><?= nav_icon('chevron') ?></a>
            <a href="export_reports.php"><?= nav_icon('download') ?><span>Export CSV</span><?= nav_icon('chevron') ?></a>
        </div>
    </section>
</div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
