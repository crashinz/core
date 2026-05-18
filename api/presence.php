<?php
require_once __DIR__ . '/../includes/base.php';
$pdo = db();
$sessionId = resolve_session_id($pdo, $_GET['session_id'] ?? '');
auth_participant($pdo, $sessionId, $_GET['join_token'] ?? '');
$stmt = $pdo->prepare('SELECT id, webcam_path, last_seen_at FROM participants WHERE session_id = ?');
$stmt->execute([$sessionId]);
$participants = array_map(fn($p) => [
    'id' => (int)$p['id'],
    'webcam_path' => $p['webcam_path'],
    'online' => $p['last_seen_at'] && strtotime($p['last_seen_at']) >= time() - 35,
], $stmt->fetchAll());
json_out(['participants' => $participants]);
