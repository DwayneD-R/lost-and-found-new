<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

// Opens as a modal over whatever page you were on (see ReportModal in
// js/main.js). The modal fetches this file over AJAX: a GET returns just the
// form fragment, a POST returns JSON ({ok, redirect} or {ok:false, errors}).
// A direct visit / JS disabled still gets the normal full page below.
// $itemBase makes the form's action and the redirect root-relative, since the
// modal can be opened from any depth (root pages, or admin/) — same trick as
// item.php.
$isPartial = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
$itemBase  = $isPartial ? rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/' : '';

$errors = [];
$categories = REPORT_CATEGORIES;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type        = $_POST['type'] ?? '';
    $itemName    = trim($_POST['item_name'] ?? '');
    $category    = trim($_POST['category'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $location    = trim($_POST['location'] ?? '');
    $itemDate    = $_POST['item_date'] ?? '';

    if (!in_array($type, ['lost', 'found'], true)) $errors[] = 'Select whether the item was lost or found.';
    if ($itemName === '') $errors[] = 'Item name is required.';
    elseif (mb_strlen($itemName) > 25) $errors[] = 'Item name must be 25 characters or fewer.';
    if (!in_array($category, $categories, true)) $errors[] = 'Choose a category.';
    if ($location === '') $errors[] = 'Location is required.';
    if (!$itemDate) $errors[] = 'Date is required.';

    $imagePath = null;
    if (!$errors) {
        try {
            $imagePath = handle_image_upload('image', 'items');
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (!$errors) {
        // The report is saved in one transaction. If that fails the uploaded photo is removed again, so
        // no orphan file is left behind. The possible-match check runs only after the report has committed
        // (it has its own transaction, so both sides are told or neither is).
        try {
            $newId = db_transaction($pdo, function () use ($pdo, $type, $itemName, $category, $description, $location, $itemDate, $imagePath) {
                $stmt = $pdo->prepare(
                    'INSERT INTO reports (user_id, type, item_name, category, description, location, item_date, image_path)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$_SESSION['user_id'], $type, $itemName, $category, $description, $location, $itemDate, $imagePath]);
                return (int) $pdo->lastInsertId();
            });
        } catch (Throwable $e) {
            if ($imagePath) delete_unused_uploads($pdo, [$imagePath]);
            throw $e;
        }

        // Check the opposite type (lost <-> found) for anything that looks
        // like it could be the same item, and have the system message this
        // reporter about it. See includes/functions.php.
        notify_possible_matches($pdo, [
            'id' => $newId, 'user_id' => $_SESSION['user_id'], 'type' => $type,
            'item_name' => $itemName, 'category' => $category, 'location' => $location, 'item_date' => $itemDate,
        ]);

        set_flash('Report submitted successfully.', 'success');
        if ($isPartial) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'redirect' => $itemBase . 'my_reports.php']);
            exit;
        }
        redirect('my_reports.php');
    }

    if ($isPartial) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'errors' => $errors]);
        exit;
    }
}

if (!$isPartial) {
    $pageTitle = 'Report an Item';
    require_once __DIR__ . '/includes/header.php';
}
?>
<?php if ($isPartial): ?><h2 class="modal-form-title">Report a Lost or Found Item</h2><?php else: ?><h1>Report a Lost or Found Item</h1><?php endif; ?>
<?php foreach ($errors as $err): ?>
    <div class="flash flash-error"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="post" action="<?= $isPartial ? e($itemBase . 'report.php') : '' ?>" enctype="multipart/form-data" class="form-card report-form">
    <label>This item was...
        <select name="type" required>
            <option value="" disabled<?= in_array($_POST['type'] ?? '', ['lost', 'found'], true) ? '' : ' selected' ?>>Select here</option>
            <option value="lost" <?= ($_POST['type'] ?? '') === 'lost' ? 'selected' : '' ?>>Lost</option>
            <option value="found" <?= ($_POST['type'] ?? '') === 'found' ? 'selected' : '' ?>>Found</option>
        </select>
    </label>
    <label>Category
        <select name="category" required>
            <option value="" disabled<?= in_array($_POST['category'] ?? '', $categories, true) ? '' : ' selected' ?>>Select here</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= e($c) ?>" <?= ($_POST['category'] ?? '') === $c ? 'selected' : '' ?>><?= e($c) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Item Name
        <input type="text" name="item_name" maxlength="25" placeholder="e.g. Black umbrella, Blue water bottle" value="<?= e($_POST['item_name'] ?? '') ?>" required>
    </label>
    <label>Date Lost / Found
        <input type="date" name="item_date" value="<?= e($_POST['item_date'] ?? '') ?>" required>
    </label>
    <label class="span-2">Location Last Seen / Found
        <input type="text" name="location" placeholder="e.g. Library, 2nd Floor" value="<?= e($_POST['location'] ?? '') ?>" required>
    </label>
    <label>Description (optional)
        <textarea name="description" rows="4" placeholder="Add details that help identify it — color, brand, marks, what was inside…"><?= e($_POST['description'] ?? '') ?></textarea>
    </label>
    <div class="form-field">
        <?php $photoType = in_array($_POST['type'] ?? '', ['lost', 'found'], true) ? $_POST['type'] : 'lost / found'; ?>
        <label data-photo-prefix="Photo of">Photo of <?= e($photoType) ?> item (optional)</label>
        <div class="file-picker file-picker-image" data-file-picker="image">
            <button type="button" class="file-picker-btn" aria-label="Choose image">+</button>
            <img class="file-picker-thumb" alt="" hidden>
            <input type="file" name="image" accept="image/*">
        </div>
    </div>
    <div class="form-submit span-2"><button type="submit">Submit Report</button></div>
</form>

<?php if (!$isPartial) require_once __DIR__ . '/includes/footer.php'; ?>
