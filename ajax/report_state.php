<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();

// What a report looks like right now (is it still there, what status). The open report popup asks this
// when the live stream says reports changed, so it can refresh itself — or close, with a toast, if the
// report was deleted. Deleted reports count as "gone" for everyone except admins, as on the item page.
header('Content-Type: application/json');
$stmt = $pdo->prepare('SELECT status, deleted_at FROM reports WHERE id = ?');
$stmt->execute([(int) ($_GET['id'] ?? 0)]);
$r = $stmt->fetch();
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

if (!$r || ($r['deleted_at'] !== null && !$isAdmin)) {
    echo json_encode(['exists' => false]);
} else {
    echo json_encode(['exists' => true, 'status' => $r['status'], 'deleted' => $r['deleted_at'] !== null]);
}
