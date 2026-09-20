<?php
require_once __DIR__ . '/../includes/base.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);

$body = input_json();
$pdo = db();
$sessionId = resolve_session_id($pdo, $body['session_id'] ?? '');
$roomIdStatement = $pdo->prepare('SELECT room_id FROM room_sessions WHERE id=? LIMIT 1');
$roomIdStatement->execute([$sessionId]);
$roomId = (int)($roomIdStatement->fetchColumn() ?: 0);
$joinToken = (string)($body['join_token'] ?? '');
$p = auth_participant($pdo, $sessionId, $joinToken);

$gameDisconnect = multiplayer_game_disconnect_room_participant(
    $pdo,
    $sessionId,
    (int)$p['id'],
    (int)$p['user_id'],
    'room-leave'
);
foreach ((array)($gameDisconnect['sessions'] ?? []) as $gameSession) {
    emit_event($pdo, $sessionId, 'game_update', ['lobby_code' => (string)$gameSession['publicId']]);
}

$relationshipDeparture = avatar_relationship_force_participant_departure(
    $pdo,
    $sessionId,
    (int)$p['id'],
    'room-leave'
);
$pdo->prepare("UPDATE participants SET last_seen_at = NULL, webcam_path = NULL, webcam_enabled = 0, linked_to_participant_id = NULL, link_mode = 'normal' WHERE id = ? OR linked_to_participant_id = ?")
    ->execute([(int)$p['id'], (int)$p['id']]);
$pdo->prepare('DELETE FROM voice_sessions WHERE participant_id = ?')->execute([(int)$p['id']]);
$pdo->prepare('UPDATE users SET current_room_id = NULL, last_seen_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int)$p['user_id']]);
live_website_rooms_mark_empty_if_unoccupied($pdo, $roomId);
emit_event($pdo, $sessionId, 'voice', [
    'participant_id' => (int)$p['id'],
    'active' => false,
]);
$pdo->prepare('INSERT INTO media_signals (session_id, media, from_participant_id, to_participant_id, type, data, expires_at) VALUES (?,?,?,?,?,?,?)')
    ->execute([$sessionId, 'voice', (int)$p['id'], 0, 'leave', json_encode(['participant_id' => (int)$p['id']]), gmdate('Y-m-d H:i:s', time() + 600)]);
// An explicit departure removes the participant from other clients. A missing
// heartbeat uses presence_leave separately to represent temporary absence.
emit_event($pdo, $sessionId, 'participant_leave', [
    'participant_id' => (int)$p['id'],
    'display_name' => $p['display_name'],
]);
json_out([
    'ok' => true,
    'relationship_departure' => $relationshipDeparture,
    'game_disconnect' => $gameDisconnect,
]);
