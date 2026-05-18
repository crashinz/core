<?php
require_once __DIR__ . '/../../includes/base.php';
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $lobby = (string)($_GET['lobby'] ?? '');
    $last = (int)($_GET['lastSeq'] ?? 0);
    $stmt = $pdo->prepare('SELECT sequence, user_id, payload FROM game_moves WHERE lobby_code = ? AND sequence > ? ORDER BY sequence ASC LIMIT 250');
    $stmt->execute([$lobby, $last]);
    json_out(['moves' => array_map(fn($m) => [
        'sequence' => (int)$m['sequence'],
        'user_id' => (int)$m['user_id'],
        'payload' => json_decode($m['payload'], true),
    ], $stmt->fetchAll())]);
}
$body = input_json();
$lobby = (string)($body['lobby_id'] ?? $body['lobby'] ?? '');
$user = (int)($body['user_id'] ?? 0);
$payload = $body['payload'] ?? [];
if ($lobby === '' || $user <= 0) json_out(['error' => 'missing fields'], 400);
$stmt = $pdo->prepare('SELECT COALESCE(MAX(sequence),0)+1 FROM game_moves WHERE lobby_code = ?');
$stmt->execute([$lobby]);
$seq = (int)$stmt->fetchColumn();
$pdo->prepare('INSERT INTO game_moves (lobby_code, user_id, payload, sequence) VALUES (?,?,?,?)')
    ->execute([$lobby, $user, json_encode($payload), $seq]);
json_out(['ok' => true, 'sequence' => $seq]);
