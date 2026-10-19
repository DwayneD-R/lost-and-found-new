<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin();
header('Content-Type: application/json');

// Checks the ADMIN'S OWN password (the person currently logged in) — this
// is "prove it's really you, on purpose" for a sensitive action on someone
// else's account, not a way to check anyone else's password.
$password = $_POST['password'] ?? '';
$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$hash = $stmt->fetchColumn();

echo json_encode(['ok' => (bool) ($hash && password_verify($password, $hash))]);
