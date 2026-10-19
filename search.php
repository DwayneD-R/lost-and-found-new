<?php
require_once __DIR__ . '/includes/functions.php';
// Members only: guests are sent to the login page (they only see the 5 newest reports on the home page).
require_login();

auto_close_stale_reports($pdo);

$keyword  = trim($_GET['q'] ?? '');
$type     = $_GET['type'] ?? '';
$category = $_GET['category'] ?? '';
$status   = $_GET['status'] ?? '';

$categories = REPORT_CATEGORIES;

$sql = "SELECT r.*, u.first_name, u.last_name
        FROM reports r LEFT JOIN users u ON u.id = r.user_id
        WHERE r.deleted_at IS NULL";
$params = [];

if ($keyword !== '') {
    $sql .= " AND (r.item_name LIKE ? OR r.description LIKE ? OR r.location LIKE ?)";
    $like = "%$keyword%";
    array_push($params, $like, $like, $like);
}
if (in_array($type, ['lost', 'found'], true)) {
    $sql .= " AND r.type = ?";
    $params[] = $type;
}
if ($category !== '') {
    $sql .= " AND r.category = ?";
    $params[] = $category;
}
if (in_array($status, ['active', 'resolved', 'closed'], true)) {
    $sql .= " AND r.status = ?";
    $params[] = $status;
}
// Active reports first, then resolved, then closed last; newest
// first within each group.
$sql .= " ORDER BY CASE r.status WHEN 'active' THEN 0 WHEN 'resolved' THEN 1 ELSE 2 END, r.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reports = $stmt->fetchAll();

// Build a link to this same page with only "type" swapped, so switching
// between Lost / Found / All keeps the current keyword, category and status.
function type_link($type) {
    $params = $_GET;
    unset($params['ajax']);
    if ($type === '') {
        unset($params['type']);
    } else {
        $params['type'] = $type;
    }
    return 'search.php' . ($params ? '?' . http_build_query($params) : '');
}

// The card grid is rendered by this same function for both a normal page
// load and a live-filter AJAX request, so the two never drift apart.
function render_result_cards($reports) {
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
            <p title="<?= e($r['category'] . ' · ' . $r['location']) ?>"><?= e($r['category']) ?> &middot; <?= e($r['location']) ?></p>
            <p><?= e(date('M j, Y', strtotime($r['item_date']))) ?></p>
            <span class="status status-<?= e($r['status']) ?>"><?= e(status_label($r['status'])) ?></span>
        </div>
    </a>
    <?php endforeach;
    if (!$reports): ?>
        <p>No items match your search.</p>
    <?php endif;
}

// Live filtering (see js/main.js) re-requests this same page with ajax=1
// and swaps in just the cards below — no header/footer, no full reload.
if (isset($_GET['ajax'])) {
    header('Content-Type: text/html; charset=utf-8');
    render_result_cards($reports);
    exit;
}

if (is_logged_in()) $watch = 'browse';   // the live stream tells this page when the list of reports changes
$pageTitle = 'Browse Items';
require_once __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <h1>Browse Reports</h1>
        <p class="page-subtitle">Look through items reported by the campus community.</p>
    </div>
    <?php if (is_logged_in()): ?>
        <a class="btn" href="report.php">+ Report an Item</a>
    <?php else: ?>
        <a class="btn btn-secondary" href="login.php">Log in to report</a>
    <?php endif; ?>
</div>

<?php
// All / Lost / Found live in one dropdown button next to the search box. The
// button always shows the current choice, filled in its own colour, at a
// fixed width so switching never shifts the rest of the bar.
if (!in_array($type, ['lost', 'found'], true)) $type = '';
$typeLabels = ['' => 'All Items', 'lost' => 'Lost Items', 'found' => 'Found Items'];
$typeClass  = $type === 'lost' ? ' type-btn-lost' : ($type === 'found' ? ' type-btn-found' : '');
?>
<form method="get" class="filter-bar" id="filter-form">
    <?php if ($type !== ''): ?><input type="hidden" name="type" value="<?= e($type) ?>"><?php endif; ?>
    <input type="text" name="q" placeholder="Search by item, description or location..." value="<?= e($keyword) ?>"<?= isset($_GET['focus']) ? ' autofocus' : '' ?>>
    <div class="segmented" id="type-segmented" role="group" aria-label="Report type" data-type="<?= e($type) ?>">
        <?php foreach (['' => 'All', 'lost' => 'Lost', 'found' => 'Found'] as $value => $label): ?>
            <a class="seg-btn seg-<?= $value === '' ? 'all' : e($value) ?><?= $type === $value ? ' selected' : '' ?>" role="button" aria-pressed="<?= $type === $value ? 'true' : 'false' ?>" data-type="<?= e($value) ?>" href="<?= e(type_link($value)) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
    <select name="category">
        <option value="">Any Category</option>
        <?php foreach ($categories as $c): ?>
            <option value="<?= e($c) ?>" <?= $category === $c ? 'selected' : '' ?>><?= e($c) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="status">
        <option value="">Any Status</option>
        <?php foreach (['active', 'resolved', 'closed'] as $s): ?>
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option>
        <?php endforeach; ?>
    </select>
    <!-- Same button as the Manage Reports pages: always there, disabled until a search or filter is set. -->
    <button type="button" class="report-filter-clear" id="browse-clear"<?= ($category !== '' || $status !== '') ? '' : ' disabled' ?>>Clear filters</button>
</form>

<div class="card-grid" id="results">
<?php render_result_cards($reports); ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
