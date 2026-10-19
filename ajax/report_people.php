<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();

// The people a report's owner has talked to about it — offered as one-click choices for "who found or
// claimed it?" when the report is marked Resolved (see ResolverPicker in js/main.js). Only the report's
// owner or an admin may ask.
header('Content-Type: application/json');
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT user_id, status, deleted_at FROM reports WHERE id = ?');
$stmt->execute([$id]);
$r = $stmt->fetch();
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

if (!$r || $r['deleted_at'] !== null || (!$isAdmin && (int) $r['user_id'] !== (int) $_SESSION['user_id'])) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'people' => []]);
    exit;
}
echo json_encode(['ok' => true, 'people' => report_resolver_candidates($pdo, $id, (int) $r['user_id'])]);
