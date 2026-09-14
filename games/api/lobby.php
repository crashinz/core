<?php
require_once __DIR__ . '/_auth.php';
$pdo = db();

function game_lobby_payload(array $row): array {
    return [
        'ok' => true,
        'lobby_id' => $row['lobby_code'],
        'lobby_code' => $row['lobby_code'],
        'game_id' => (int)$row['game_id'],
        'user1_id' => $row['user1_id'] ? (int)$row['user1_id'] : null,
        'user2_id' => $row['user2_id'] ? (int)$row['user2_id'] : null,
        'round_number' => max(1, (int)($row['round_number'] ?? 1)),
        'status' => $row['status'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
    $auth = game_compatibility_auth($pdo, $_GET);
    $action = $auth['source']['action'] ?? 'status';
    $lobby = (string)$auth['lobby'];
    $stmt = $pdo->prepare('SELECT * FROM game_lobbies WHERE lobby_code = ? LIMIT 1');
    $stmt->execute([$lobby]);
    $row = $stmt->fetch();
    $stmt->closeCursor();
    if (!$row) json_out(['error' => 'not found'], 404);
    game_compatibility_touch_connection($pdo, $auth);
    json_out(game_lobby_payload($row));
    } catch (MultiplayerGameException $error) {
        json_out(['error' => $error->getMessage(), 'code' => $error->errorCode], $error->httpStatus);
    }
}

$body = input_json();
try {
$auth = game_compatibility_auth($pdo, $body);
$body = $auth['source'];
$action = $body['action'] ?? '';
$lobby = (string)$auth['lobby'];
$user = (int)$auth['participant']['id'];
if ($lobby === '') json_out(['error' => 'missing lobby'], 400);

if ($action === 'join') {
    $stmt = $pdo->prepare('SELECT * FROM game_lobbies WHERE lobby_code = ? LIMIT 1');
    $stmt->execute([$lobby]);
    $row = $stmt->fetch();
    $stmt->closeCursor();
    if (!$row) json_out(['error' => 'not found'], 404);
    if (!$row['user1_id']) {
        $pdo->prepare('UPDATE game_lobbies SET user1_id = ?, status = "waiting", updated_at = CURRENT_TIMESTAMP WHERE lobby_code = ?')->execute([$user, $lobby]);
    } elseif (!$row['user2_id'] && (int)$row['user1_id'] !== $user) {
        $pdo->prepare('UPDATE game_lobbies SET user2_id = ?, status = "active", updated_at = CURRENT_TIMESTAMP WHERE lobby_code = ?')->execute([$user, $lobby]);
    }
    $stmt = $pdo->prepare('SELECT * FROM game_lobbies WHERE lobby_code = ? LIMIT 1');
    $stmt->execute([$lobby]);
    $row = $stmt->fetch();
    $stmt->closeCursor();
    $sessionStmt = $pdo->prepare('SELECT room_session_id FROM game_sessions WHERE lobby_code = ? AND ended_at IS NULL LIMIT 1');
    $sessionStmt->execute([$lobby]);
    $sessionId = (int)($sessionStmt->fetchColumn() ?: 0);
    $sessionStmt->closeCursor();
    if ($sessionId) emit_event($pdo, $sessionId, 'game_update', ['lobby_code' => $lobby]);
    json_out(game_lobby_payload($row));
}

if ($action === 'close') {
    if ((string)$auth['framework']['member_role'] !== 'master') json_out(['error' => 'Only the game Master can close this game'], 403);
    $pdo->prepare('UPDATE game_lobbies SET status = "ended", updated_at = CURRENT_TIMESTAMP WHERE lobby_code = ?')->execute([$lobby]);
    $pdo->prepare('UPDATE game_sessions SET ended_at = CURRENT_TIMESTAMP WHERE lobby_code = ?')->execute([$lobby]);
    $pdo->prepare("UPDATE multiplayer_game_sessions SET status='ended',ended_at=COALESCE(ended_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP WHERE public_id=?")
        ->execute([$lobby]);
    $pdo->prepare('DELETE FROM game_moves WHERE lobby_code = ?')->execute([$lobby]);
    $pdo->prepare('DELETE FROM game_state WHERE lobby_code = ?')->execute([$lobby]);
    $pdo->prepare('DELETE FROM game_chat_messages WHERE lobby_code = ?')->execute([$lobby]);
    if (database_migration_table_exists($pdo, 'game_chat_typing')) {
        $pdo->prepare('DELETE FROM game_chat_typing WHERE lobby_code = ?')->execute([$lobby]);
    }
    $stmt = $pdo->prepare('SELECT room_session_id FROM game_sessions WHERE lobby_code = ? LIMIT 1');
    $stmt->execute([$lobby]);
    $sessionId = (int)($stmt->fetchColumn() ?: 0);
    $stmt->closeCursor();
    if ($sessionId) emit_event($pdo, $sessionId, 'game_end', ['lobby_code' => $lobby]);
    json_out(['ok' => true]);
}

json_out(['ok' => true]);
} catch (MultiplayerGameException $error) {
    json_out(['error' => $error->getMessage(), 'code' => $error->errorCode], $error->httpStatus);
}
