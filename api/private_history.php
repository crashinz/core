<?php
require_once __DIR__ . '/../includes/base.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);

$body = input_json();
$pdo = db();
$sessionId = resolve_session_id($pdo, $body['session_id'] ?? '');
$participant = auth_participant($pdo, $sessionId, $body['join_token'] ?? '');
$action = (string)($body['action'] ?? '');
$channel = (string)($body['channel'] ?? '');

if ($action !== 'clear') json_out(['error' => 'Unsupported action'], 400);
if (!in_array($channel, ['link', 'dm'], true)) json_out(['error' => 'Unsupported channel'], 400);

$linkKey = '';
$clearSessionId = 0;

if ($channel === 'link') {
    $targetId = (int)($body['target_participant_id'] ?? 0);
    $requestedIdentity = trim((string)($body['conversation_id'] ?? $body['relationship_id'] ?? ''));
    $result = avatar_relationship_transaction($pdo, function() use (
        $pdo,
        $sessionId,
        $participant,
        $requestedIdentity,
        $targetId
    ): array {
        $access = avatar_relationship_chat_access(
            $pdo,
            $sessionId,
            (int)$participant['id'],
            $requestedIdentity,
            $targetId,
            true
        );
        if (!$access) return ['error' => 'Relationship conversation unavailable', 'http_status' => 403];
        $clearedAt = gmdate('Y-m-d H:i:s');
        $stmt = $pdo->prepare(db_uses_mysql_syntax($pdo)
            ? 'INSERT INTO private_message_clears (user_id, scope, session_id, link_key, cleared_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE cleared_at = VALUES(cleared_at)'
            : 'INSERT INTO private_message_clears (user_id, scope, session_id, link_key, cleared_at) VALUES (?,?,?,?,?) ON CONFLICT(user_id, scope, session_id, link_key) DO UPDATE SET cleared_at = excluded.cleared_at'
        );
        $stmt->execute([(int)$participant['user_id'], 'link', $sessionId, $access['conversation_id'], $clearedAt]);
        return [
            'ok' => true,
            'channel' => 'link',
            'link_key' => $access['conversation_id'],
            'relationship_id' => $access['relationship_id'],
            'cleared_at' => $clearedAt,
        ];
    });
    $status = (int)($result['http_status'] ?? 200);
    unset($result['http_status']);
    json_out($result, $status);
}

if ($channel === 'dm') {
    $targetUserId = (int)($body['target_user_id'] ?? 0);
    if (!$targetUserId || $targetUserId === (int)$participant['user_id']) json_out(['error' => 'DM recipient required'], 400);
    $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$targetUserId]);
    if (!$stmt->fetch()) json_out(['error' => 'DM recipient not found'], 404);
    $linkKey = dm_key_for((int)$participant['user_id'], $targetUserId);
}

$clearedAt = gmdate('Y-m-d H:i:s');
$stmt = $pdo->prepare(db_uses_mysql_syntax($pdo)
    ? 'INSERT INTO private_message_clears (user_id, scope, session_id, link_key, cleared_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE cleared_at = VALUES(cleared_at)'
    : 'INSERT INTO private_message_clears (user_id, scope, session_id, link_key, cleared_at) VALUES (?,?,?,?,?) ON CONFLICT(user_id, scope, session_id, link_key) DO UPDATE SET cleared_at = excluded.cleared_at'
);
$stmt->execute([(int)$participant['user_id'], $channel, $clearSessionId, $linkKey, $clearedAt]);

json_out([
    'ok' => true,
    'channel' => $channel,
    'link_key' => $linkKey,
    'cleared_at' => $clearedAt,
]);
