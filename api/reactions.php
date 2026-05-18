<?php
require_once __DIR__ . '/../includes/base.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);
$body = input_json();
$pdo = db();
$sessionId = resolve_session_id($pdo, $body['session_id'] ?? '');
$participant = auth_participant($pdo, $sessionId, $body['join_token'] ?? '');
$messageId = (int)($body['message_id'] ?? 0);
$emoji = trim((string)($body['emoji'] ?? ''));
$channel = (string)($body['channel'] ?? 'room');
if (str_starts_with($channel, 'link:')) $channel = 'link';
if (str_starts_with($channel, 'dm:')) $channel = 'dm';
$allowed = ['❤️', '👍', '👎', '😂', '😌', '😏', '✅', '⭐'];
if (!$messageId || !in_array($emoji, $allowed, true)) json_out(['error' => 'Reaction required'], 400);

function dm_target_from_reaction_key(string $key, int $currentUserId): int {
    $ids = explode(':', $key);
    $a = (int)($ids[1] ?? 0);
    $b = (int)($ids[2] ?? 0);
    return $a === $currentUserId ? $b : $a;
}

if ($channel !== 'room') {
    if (!in_array($channel, ['community', 'link', 'dm'], true)) json_out(['error' => 'Unsupported channel'], 400);
    $stmt = $pdo->prepare('SELECT * FROM community_messages WHERE id = ? AND scope = ? AND COALESCE(is_deleted, 0) = 0 LIMIT 1');
    $stmt->execute([$messageId, $channel]);
    $message = $stmt->fetch();
    if (!$message) json_out(['error' => 'Message not found'], 404);
    if ($channel === 'link') {
        $ids = array_map('intval', explode(':', (string)$message['link_key']));
        if ((int)$message['session_id'] !== $sessionId || !in_array((int)$participant['id'], $ids, true)) {
            json_out(['error' => 'Message not found'], 404);
        }
    }
    if ($channel === 'dm') {
        $ids = explode(':', (string)$message['link_key']);
        $a = (int)($ids[1] ?? 0);
        $b = (int)($ids[2] ?? 0);
        if ($a !== (int)$participant['user_id'] && $b !== (int)$participant['user_id']) {
            json_out(['error' => 'Message not found'], 404);
        }
    }

    $stmt = $pdo->prepare('SELECT id, emoji FROM community_message_reactions WHERE message_id = ? AND participant_id = ? LIMIT 1');
    $stmt->execute([$messageId, (int)$participant['id']]);
    $existing = $stmt->fetch();
    $removed = false;
    if ($existing && $existing['emoji'] === $emoji) {
        $pdo->prepare('DELETE FROM community_message_reactions WHERE id = ?')->execute([(int)$existing['id']]);
        $removed = true;
    } elseif ($existing) {
        $pdo->prepare('UPDATE community_message_reactions SET emoji = ?, created_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$emoji, (int)$existing['id']]);
    } else {
        $pdo->prepare('INSERT INTO community_message_reactions (message_id, participant_id, user_id, emoji) VALUES (?,?,?,?)')
            ->execute([$messageId, (int)$participant['id'], (int)$participant['user_id'], $emoji]);
    }

    $payload = [
        'message_id' => $messageId,
        'channel' => $channel,
        'participant_id' => (int)$participant['id'],
        'user_id' => (int)$participant['user_id'],
        'display_name' => $participant['display_name'],
        'avatar_url' => $participant['webcam_path'] ?: resolve_avatar($participant['avatar_path']),
        'emoji' => $emoji,
        'removed' => $removed,
    ];
    if ($channel === 'link') $payload['link_key'] = $message['link_key'];
    if ($channel === 'dm') {
        $payload['dm_key'] = $message['link_key'];
        $payload['target_user_id'] = dm_target_from_reaction_key((string)$message['link_key'], (int)$participant['user_id']);
        $payload['partner_user_id'] = $payload['target_user_id'];
    }
    emit_community_event(
        $pdo,
        $channel,
        $channel === 'link' ? $sessionId : null,
        $channel === 'community' ? null : (string)$message['link_key'],
        'message_reaction',
        $payload
    );
    json_out(['ok' => true] + $payload);
}

$stmt = $pdo->prepare('SELECT id FROM messages WHERE id = ? AND session_id = ? AND COALESCE(is_deleted, 0) = 0 LIMIT 1');
$stmt->execute([$messageId, $sessionId]);
if (!$stmt->fetch()) json_out(['error' => 'Message not found'], 404);

$stmt = $pdo->prepare('SELECT id, emoji FROM message_reactions WHERE message_id = ? AND participant_id = ? LIMIT 1');
$stmt->execute([$messageId, (int)$participant['id']]);
$existing = $stmt->fetch();
$removed = false;
if ($existing && $existing['emoji'] === $emoji) {
    $pdo->prepare('DELETE FROM message_reactions WHERE id = ?')->execute([(int)$existing['id']]);
    $removed = true;
} elseif ($existing) {
    $pdo->prepare('UPDATE message_reactions SET emoji = ?, created_at = CURRENT_TIMESTAMP WHERE id = ?')
        ->execute([$emoji, (int)$existing['id']]);
} else {
    $pdo->prepare('INSERT INTO message_reactions (message_id, participant_id, user_id, emoji) VALUES (?,?,?,?)')
        ->execute([$messageId, (int)$participant['id'], (int)$participant['user_id'], $emoji]);
}

$payload = [
    'message_id' => $messageId,
    'participant_id' => (int)$participant['id'],
    'user_id' => (int)$participant['user_id'],
    'display_name' => $participant['display_name'],
    'avatar_url' => $participant['webcam_path'] ?: resolve_avatar($participant['avatar_path']),
    'emoji' => $emoji,
    'removed' => $removed,
];
emit_event($pdo, $sessionId, 'reaction', $payload);
json_out(['ok' => true] + $payload);
