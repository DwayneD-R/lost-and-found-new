<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin();

// Opens as a popup over Manage Reports (ReportModal in js/main.js): an AJAX
// GET returns just the form, an AJAX POST returns JSON. A direct visit still
// renders the full page as a fallback.
$isPartial = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

$results = null;
$error = null;

// The tab the import was started from (Active or Deleted). Imports return
// to it, so the page doesn't jump to a different tab afterwards.
$returnView = (($_GET['view'] ?? '') === 'trash') ? 'trash' : 'active';
$returnUrl  = 'reports.php' . ($returnView === 'trash' ? '?view=trash' : '');

// CSV import using PHP's built-in fgetcsv() — no external library. Meant
// for bringing back reports from a backup exported by export_reports.php
// (Manage Reports -> Trash -> Export, or Export All), typically after a
// permanent delete. Imported rows come back in as new reports — active ones
// as active, and rows that were deleted when exported go straight into the
// Deleted Reports tab. The original reporter is matched by email where
// possible.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // The admin's password is required. The popup checks it first (see
    // verify_password.php) and then sends it along as confirm_password, so
    // it's checked again here — a direct POST can't skip it.
    if (!admin_password_ok($pdo, $_POST['confirm_password'] ?? '')) {
        $error = 'Incorrect password — nothing was imported.';
    } elseif (empty($_FILES['csv']['tmp_name']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Please choose a CSV file to upload.';
    } else {
        $handle = fopen($_FILES['csv']['tmp_name'], 'r');
        $header = fgetcsv($handle); // discard header row
        $imported = 0;
        $importedDeleted = 0;
        $skipped = 0;

        // The whole file is imported in ONE transaction: a bad row or a crash halfway can't leave half a
        // backup behind. (Possible-match emails go out only after it has committed.)
        db_transaction($pdo, function () use ($pdo, $handle, &$imported, &$importedDeleted, &$skipped) {

        // Column order matches export_reports.php:
        // ID, Type, Item Name, Category, Description, Location, Item Date,
        // Image Path, Status, Reported By, Reporter Email, Created At, Deleted At
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 11) { $skipped++; continue; }
            [$oldId, $type, $itemName, $category, $description, $location,
             $itemDate, $imagePath, $status, $reportedBy, $reporterEmail] = array_pad(array_slice($row, 0, 11), 11, null);

            $itemName = trim((string)$itemName);
            if ($itemName === '') { $skipped++; continue; }

            if (!in_array($type, ['lost', 'found'], true)) $type = 'lost';
            $status = strtolower(trim((string)$status));
            if ($status === 'pending') $status = 'active'; // older exports called active reports "pending"
            if ($status === 'claimed') $status = 'resolved'; // older exports called resolved reports "claimed"
            if (!in_array($status, ['active', 'resolved', 'closed'], true)) $status = 'active';

            // A row that was deleted when it was exported stays deleted: it
            // goes into the Deleted Reports tab, not the active list.
            $deletedRaw = trim((string)($row[12] ?? ''));
            $deletedAt  = $deletedRaw !== '' ? date('Y-m-d H:i:s', strtotime($deletedRaw) ?: time()) : null;

            $userId = null;
            if ($reporterEmail) {
                $u = $pdo->prepare('SELECT id FROM users WHERE email = ?');
                $u->execute([trim($reporterEmail)]);
                $userId = $u->fetchColumn() ?: null;
            }
            if (!$userId) {
                // No matching account (e.g. the reporter's account was later
                // deleted) — attribute the restored report to the importing
                // admin and keep a record of who originally filed it.
                $userId = $_SESSION['user_id'];
                $note = '[Imported — original reporter: ' . ($reportedBy ?: 'unknown') . ' <' . ($reporterEmail ?: 'unknown') . '>]';
                $description = trim((string)$description) !== '' ? $description . "\n\n" . $note : $note;
            }

            $ins = $pdo->prepare(
                'INSERT INTO reports (user_id, type, item_name, category, description, location, item_date, image_path, status, deleted_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $userId,
                $type,
                $itemName,
                $category ?: 'Other',
                $description ?: null,
                $location ?: 'Unknown',
                ($itemDate && strtotime($itemDate)) ? date('Y-m-d', strtotime($itemDate)) : date('Y-m-d'),
                $imagePath ?: null,
                $status,
                $deletedAt,
            ]);
            // Imported reports used to skip the possible-match check that
            // report.php runs on every new report, so nobody (the reporter,
            // or the owner of a matching report) was ever alerted about them.
            // Deleted ones are left out, same as any report in the trash.
            if ($status === 'active' && $deletedAt === null) {
                notify_possible_matches($pdo, [
                    'id' => $pdo->lastInsertId(), 'user_id' => $userId, 'type' => $type,
                    'item_name' => $itemName, 'category' => $category ?: 'Other',
                    'location' => $location ?: 'Unknown',
                    'item_date' => ($itemDate && strtotime($itemDate)) ? date('Y-m-d', strtotime($itemDate)) : date('Y-m-d'),
                ]);
            }
            if ($deletedAt !== null) $importedDeleted++; else $imported++;
        }
        });
        fclose($handle);
        $results = ['imported' => $imported, 'deleted' => $importedDeleted, 'skipped' => $skipped];
    }

    $resultMessage = $results
        ? ($results['imported'] + $results['deleted']) . ' report(s) imported'
            . ($results['deleted'] ? ' (' . $results['deleted'] . ' into Deleted Reports)' : '')
            . ', ' . $results['skipped'] . ' row(s) skipped.'
        : null;

    if ($isPartial) {
        header('Content-Type: application/json');
        if ($error) {
            echo json_encode(['ok' => false, 'errors' => [$error]]);
        } else {
            set_flash($resultMessage, 'success');
            echo json_encode(['ok' => true, 'redirect' => $returnUrl]);
        }
        exit;
    }
}

if (!$isPartial) {
    $assetPrefix = '../';
    $pageTitle = 'Import Reports';
    require_once __DIR__ . '/../includes/header.php';
}
?>
<?php if ($isPartial): ?><h2 class="modal-form-title">Import Reports from CSV</h2><?php else: ?><h1>Import Reports from CSV</h1>
<p><a href="<?= e($returnUrl) ?>">&larr; Back to Manage Reports</a></p><?php endif; ?>
<p class="hint-text">
    Restore reports from a CSV exported by this system. Active rows come back as new, active reports,
    and rows that were deleted when exported go straight into the Deleted Reports tab. Each report is
    matched to its original reporter by email where possible; unmatched rows are attributed to your
    admin account with a note about who originally filed them.
</p>

<?php if (!$isPartial): ?>
    <?php if ($error): ?>
        <script>(window.__pendingToasts = window.__pendingToasts || []).push({msg: <?= json_encode($error) ?>, type: 'error'});</script>
    <?php elseif ($results): ?>
        <script>(window.__pendingToasts = window.__pendingToasts || []).push({msg: <?= json_encode($resultMessage) ?>, type: 'success'});</script>
    <?php endif; ?>
<?php endif; ?>

<form method="post" action="import_reports.php?view=<?= e($returnView) ?>" enctype="multipart/form-data" class="form-card import-form confirm-form-password"
      data-confirm-title="Import this CSV?"
      data-confirm-message="Every valid row comes back in as a new report (deleted ones go to Deleted Reports). Nothing already in the system is changed or overwritten — it only adds. Enter your password to confirm it's you."
      data-confirm-label="Import"
      data-confirm-verify-url="verify_password.php">
    <label class="span-2">CSV File</label>
    <div class="file-picker span-2" data-file-picker="file">
        <button type="button" class="file-picker-btn" aria-label="Choose CSV file">+</button>
        <span class="file-picker-filename" hidden></span>
        <input type="file" name="csv" accept=".csv,text/csv" required>
    </div>
    <div class="form-submit span-2"><button type="submit">Import</button></div>
</form>
<?php if (!$isPartial) require_once __DIR__ . '/../includes/footer.php'; ?>
