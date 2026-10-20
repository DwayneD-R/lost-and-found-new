<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

// Modal-friendly, same approach as report.php: AJAX GET returns just the
// form, AJAX POST returns JSON, a normal visit renders the full page.
$isPartial = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
$itemBase  = $isPartial ? rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/' : '';

auto_close_stale_reports($pdo);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
// Owners edit their own reports; admins can edit anyone's (from Manage
// Reports), and the reporter is told when they do (see below).
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
if ($isAdmin) {
    $stmt = $pdo->prepare('SELECT * FROM reports WHERE id = ?');
    $stmt->execute([$id]);
} else {
    $stmt = $pdo->prepare('SELECT * FROM reports WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $_SESSION['user_id']]);
}
$report = $stmt->fetch();
if (!$report) {
    if ($isPartial) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'errors' => ['Report not found.']]);
        exit;
    }
    set_flash('Report not found.', 'error');
    redirect('my_reports.php');
}

// Closed reports can't be edited — only deleted. Deleted reports can't be edited at all (restore first).
if ($report['status'] === 'closed' || $report['deleted_at'] !== null) {
    $msg = $report['deleted_at'] !== null ? "Deleted reports can't be edited." : "Closed reports can't be edited.";
    if ($isPartial) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'errors' => [$msg]]);
        exit;
    }
    set_flash($msg, 'error');
    redirect($isAdmin ? 'admin/reports.php' : 'my_reports.php');
}

$isAdminEdit = $isAdmin && (int)$report['user_id'] !== (int)$_SESSION['user_id'];

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
    elseif (mb_strlen($location) > 150) $errors[] = 'Location must be 150 characters or fewer.';
    if (mb_strlen($description) > 2000) $errors[] = 'Description must be 2000 characters or fewer.';
    $dateObj = $itemDate ? DateTime::createFromFormat('!Y-m-d', (string) $itemDate) : false;
    if (!$itemDate) $errors[] = 'Date is required.';
    elseif (!$dateObj || $dateObj->format('Y-m-d') !== $itemDate) $errors[] = 'Enter a valid date.';
    elseif ($itemDate > date('Y-m-d')) $errors[] = 'The date can’t be in the future.';

    $imagePath = $report['image_path'];
    $newUpload = null;
    if (!$errors) {
        try {
            $newUpload = handle_image_upload('image', 'items');
            if ($newUpload) $imagePath = $newUpload;
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (!$errors) {
        // Optimistic locking, inside one transaction: the row is locked, then checked again (still there,
        // not deleted, not closed, same version the form was opened on) and only then changed. Otherwise
        // two people editing at once would silently overwrite each other (the last save would win), or a
        // report an admin just deleted / closed would be edited anyway. The reporter's notice, the
        // possible-match check and removing the replaced photo all happen only after it has committed.
        try {
            $outcome = db_transaction($pdo, function () use ($pdo, $id, $type, $itemName, $category, $description, $location, $itemDate, $imagePath, $isAdminEdit) {
                $lock = $pdo->prepare('SELECT * FROM reports WHERE id = ? FOR UPDATE');
                $lock->execute([$id]);
                $cur = $lock->fetch();
                if (!$cur)                          return ['This report no longer exists.', null];
                if ($cur['deleted_at'] !== null)    return ['This report was deleted while you were editing it, so your changes were not saved.', null];
                if ($cur['status'] === 'closed')    return ["This report was closed while you were editing it, and closed reports can't be edited.", null];
                if ((int) $cur['version'] !== (int) ($_POST['version'] ?? 0)) {
                    return ['This report was changed by someone else while you were editing it, so your changes were not saved. Close this window and open it again to see the latest version, then redo your edit.', null];
                }

                $upd = $pdo->prepare(
                    'UPDATE reports SET type=?, item_name=?, category=?, description=?, location=?, item_date=?, image_path=?, version = version + 1
                     WHERE id=? AND version=? AND deleted_at IS NULL AND status <> \'closed\''
                );
                $upd->execute([$type, $itemName, $category, $description, $location, $itemDate, $imagePath, $id, $cur['version']]);
                if ($upd->rowCount() !== 1) return ['This report was changed by someone else while you were editing it, so your changes were not saved.', null];

                // The replaced photo is no longer used (deleted only after the commit).
                if ($imagePath !== $cur['image_path'] && $cur['image_path']) {
                    $old = $cur['image_path'];
                    after_commit(function () use ($pdo, $old) { delete_unused_uploads($pdo, [$old]); });
                }

                // An admin changed someone else's report: tell the reporter what changed.
                if ($isAdminEdit && (int) $cur['user_id'] > 0) {
                    $short = fn($v, $w = 60) => mb_strimwidth(trim(str_replace(["\r", "\n", ';'], [' ', ' ', ','], (string)$v)), 0, $w, '…');
                    $changes = [];
                    if ($type !== $cur['type'])                         $changes[] = 'Type: ' . ucfirst($cur['type']) . ' → ' . ucfirst($type);
                    if ($itemName !== trim((string)$cur['item_name']))  $changes[] = 'Item name: ' . $short($cur['item_name']) . ' → ' . $short($itemName);
                    if ($category !== trim((string)$cur['category']))   $changes[] = 'Category: ' . $cur['category'] . ' → ' . $category;
                    if ($location !== trim((string)$cur['location']))   $changes[] = 'Location: ' . $short($cur['location']) . ' → ' . $short($location);
                    if (date('Y-m-d', strtotime($itemDate)) !== date('Y-m-d', strtotime($cur['item_date'])))
                        $changes[] = 'Date: ' . date('M j, Y', strtotime($cur['item_date'])) . ' → ' . date('M j, Y', strtotime($itemDate));
                    if ($description !== trim((string)$cur['description'])) {
                        $oldDesc = trim((string)$cur['description']);
                        if ($oldDesc === '')         $changes[] = 'Info: Added “' . $short($description, 70) . '”';
                        elseif ($description === '') $changes[] = 'Info: Description removed';
                        else                         $changes[] = 'Info: ' . $short($oldDesc, 40) . ' → ' . $short($description, 40);
                    }
                    if ($imagePath !== $cur['image_path'])              $changes[] = 'Photo replaced';
                    if ($changes) {
                        $ownerId = (int) $cur['user_id'];
                        $notice  = "An administrator edited your report.\n"
                                 . 'Report: "' . $itemName . '"' . "\n"
                                 . 'Changes: ' . implode('; ', $changes) . "\n"
                                 . 'Link: item.php?id=' . (int) $id;
                        $changeList = $changes;
                        after_commit(function () use ($pdo, $ownerId, $notice, $changeList, $itemName, $id) {
                            notify_report_change($pdo, $ownerId, 'Your report was edited by an administrator',
                                ['Changes' => implode('; ', $changeList)], $itemName, (int) $id,
                                'an administrator edited your report.', $notice);
                        });
                    }
                }

                // Re-run the match check only if the lost/found selection or the category changed, and
                // only for a report that is still active (see notify_possible_matches() in functions.php).
                $matchFieldsChanged = $type !== $cur['type'] || $category !== trim((string) $cur['category']);
                if ($matchFieldsChanged && $cur['status'] === 'active' && (int) $cur['user_id'] > 0) {
                    $forMatch = [
                        'id' => $id, 'user_id' => $cur['user_id'], 'type' => $type,
                        'item_name' => $itemName, 'category' => $category, 'location' => $location, 'item_date' => $itemDate,
                    ];
                    after_commit(function () use ($pdo, $forMatch) { notify_possible_matches($pdo, $forMatch); });
                }
                return [null, $cur];
            });
        } catch (Throwable $e) {
            if ($newUpload) delete_unused_uploads($pdo, [$newUpload]);   // nothing was saved: the new photo isn't used
            throw $e;
        }

        [$failure, $saved] = $outcome;
        if ($failure !== null) {
            $errors[] = $failure;
            if ($newUpload) delete_unused_uploads($pdo, [$newUpload]);   // the new photo isn't used
        } else {
            set_flash($isAdminEdit ? 'Report updated. The reporter has been notified.' : 'Report updated.', 'success');

            // Owners go back to My Reports. An admin editing someone else's
            // report goes back to wherever they were (Manage Reports, with its
            // filters), as long as the referring page is on this site.
            $back = $itemBase . 'my_reports.php';
            if ($isAdminEdit) {
                $back = $itemBase . 'admin/reports.php';
                $ref  = $_SERVER['HTTP_REFERER'] ?? '';
                if ($ref && parse_url($ref, PHP_URL_HOST) === ($_SERVER['HTTP_HOST'] ?? '') && strpos($ref, 'edit_report') === false) {
                    $back = $ref;
                }
            }
            if ($isPartial) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'redirect' => $back]);
                exit;
            }
            redirect($isAdminEdit ? 'admin/reports.php' : 'my_reports.php');
        }
    }

    if ($isPartial) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'errors' => $errors]);
        exit;
    }
}

if (!$isPartial) {
    $pageTitle = 'Edit Report';
    require_once __DIR__ . '/includes/header.php';
}
?>
<?php if ($isPartial): ?><h2 class="modal-form-title">Edit Report</h2><?php else: ?><h1>Edit Report</h1><?php endif; ?>
<?php foreach ($errors as $err): ?>
    <div class="flash flash-error"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="post" action="<?= $isPartial ? e($itemBase . 'edit_report.php?id=' . (int)$id) : '' ?>" enctype="multipart/form-data" class="form-card report-form confirm-form"
      data-confirm-title="Save these changes?"
      data-confirm-message="<?= $isAdminEdit ? "This updates the report's details right away, and the reporter will be notified of what changed." : "This updates the report's details right away — anyone viewing or messaging about it will see the new version." ?>"
      data-confirm-label="Save Changes">
    <input type="hidden" name="version" value="<?= (int)($report['version'] ?? 1) ?>">
    <label>This item was...
        <select name="type" required>
            <option value="lost" <?= $report['type'] === 'lost' ? 'selected' : '' ?>>Lost</option>
            <option value="found" <?= $report['type'] === 'found' ? 'selected' : '' ?>>Found</option>
        </select>
    </label>
    <label>Category
        <select name="category" required>
            <?php foreach ($categories as $c): ?>
                <option value="<?= e($c) ?>" <?= $report['category'] === $c ? 'selected' : '' ?>><?= e($c) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Item Name
        <input type="text" name="item_name" maxlength="25" placeholder="e.g. Black umbrella, Blue water bottle" value="<?= e($report['item_name']) ?>" required>
    </label>
    <label>Date Lost / Found
        <input type="date" name="item_date" max="<?= date('Y-m-d') ?>" value="<?= e($report['item_date']) ?>" required>
    </label>
    <label class="span-2">Location Last Seen / Found
        <input type="text" name="location" maxlength="150" placeholder="e.g. Library, 2nd Floor" value="<?= e($report['location']) ?>" required>
    </label>
    <label>Description (optional)
        <textarea name="description" rows="4" maxlength="2000" placeholder="Add details that help identify it — color, brand, marks, what was inside…"><?= e($report['description']) ?></textarea>
    </label>
    <div class="form-field">
        <label data-photo-prefix="Replace photo of">Replace photo of <?= e($report['type']) ?> item (optional)</label>
        <div class="form-image-row">
            <?php if ($report['image_path'] && report_image_exists($report['image_path'])): ?>
                <img class="preview-img" src="<?= e($itemBase . $report['image_path']) ?>" alt="Current image" loading="lazy">
            <?php endif; ?>
            <div class="file-picker file-picker-image" data-file-picker="image">
                <button type="button" class="file-picker-btn" aria-label="Choose image">+</button>
                <img class="file-picker-thumb" alt="" hidden>
                <input type="file" name="image" accept="image/*">
            </div>
        </div>
    </div>
    <div class="form-submit span-2"><button type="submit">Save Changes</button></div>
</form>

<?php if (!$isPartial) require_once __DIR__ . '/includes/footer.php'; ?>
