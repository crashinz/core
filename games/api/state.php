<?php
require_once __DIR__ . '/../../includes/base.php';
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $lobby = (string)($_GET['lobby'] ?? '');
    $stmt = $pdo->prepare('SELECT state_json FROM game_state WHERE lobby_code = ? LIMIT 1');
    $stmt->execute([$lobby]);
    $state = $stmt->fetchColumn();
    json_out(['state' => $state ? json_decode((string)$state, true) : null]);
}
$body = input_json();
$lobby = (string)($body['lobby_id'] ?? $body['lobby'] ?? '');
if ($lobby === '') json_out(['error' => 'missing lobby'], 400);
$pdo->prepare(db_uses_mysql_syntax($pdo)
    ? 'INSERT INTO game_state (lobby_code, state_json, updated_at) VALUES (?,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE state_json = VALUES(state_json), updated_at = CURRENT_TIMESTAMP'
    : 'INSERT OR REPLACE INTO game_state (lobby_code, state_json, updated_at) VALUES (?,?,CURRENT_TIMESTAMP)'
)
    ->execute([$lobby, json_encode($body['state'] ?? [])]);
json_out(['ok' => true]);
