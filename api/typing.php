<?php
require_once __DIR__ . '/../includes/base.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);
$body = input_json();
$pdo = db();
$sessionId = resolve_session_id($pdo, $body['session_id'] ?? '');
$p = auth_participant($pdo, $sessionId, $body['join_token'] ?? '');
$active = !empty($body['active']);
$channel = (string)($body['channel'] ?? 'room');

if ($channel === 'community') {
    json_out(['ok' => true]);
}

if ($channel === 'link') {
    $targetId = (int)($body['target_participant_id'] ?? 0);
    if (!$targetId) json_out(['error' => 'Linked participant required'], 400);
    $stmt = $pdo->prepare(
        'SELECT id FROM participants
         WHERE session_id = ? AND id = ?
           AND (linked_to_participant_id = ? OR id = (SELECT linked_to_participant_id FROM participants WHERE id = ?))
         LIMIT 1'
    );
    $stmt->execute([$sessionId, $targetId, (int)$p['id'], (int)$p['id']]);
    if (!$stmt->fetch()) json_out(['error' => 'You are not linked to that participant'], 403);
    $linkKey = link_key_for((int)$p['id'], $targetId);
    emit_community_event($pdo, 'link', $sessionId, $linkKey, 'link_typing', [
        'participant_id' => (int)$p['id'],
        'link_key' => $linkKey,
        'active' => $active,
    ]);
    json_out(['ok' => true]);
}

emit_event($pdo, $sessionId, 'typing', [
    'participant_id' => (int)$p['id'],
    'active' => $active,
]);

json_out(['ok' => true]);
