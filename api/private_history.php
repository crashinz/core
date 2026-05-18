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
    if (!$targetId || $targetId === (int)$participant['id']) json_out(['error' => 'Linked participant required'], 400);
    $stmt = $pdo->prepare(
        'SELECT id FROM participants
         WHERE session_id = ? AND id = ?
           AND (linked_to_participant_id = ? OR id = (SELECT linked_to_participant_id FROM participants WHERE id = ?))
         LIMIT 1'
    );
    $stmt->execute([$sessionId, $targetId, (int)$participant['id'], (int)$participant['id']]);
    if (!$stmt->fetch()) json_out(['error' => 'You are not linked to that participant'], 403);
    $linkKey = link_key_for((int)$participant['id'], $targetId);
    $clearSessionId = $sessionId;
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
