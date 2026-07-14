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
    $requestedIdentity = trim((string)($body['conversation_id'] ?? $body['relationship_id'] ?? ''));
    $result = avatar_relationship_transaction($pdo, function() use (
        $pdo,
        $sessionId,
        $p,
        $requestedIdentity,
        $targetId,
        $active
    ): array {
        $access = avatar_relationship_chat_access(
            $pdo,
            $sessionId,
            (int)$p['id'],
            $requestedIdentity,
            $targetId,
            true
        );
        if (!$access) return ['error' => 'Relationship conversation unavailable', 'http_status' => 403];
        emit_community_event($pdo, 'link', $sessionId, $access['conversation_id'], 'link_typing', [
            'participant_id' => (int)$p['id'],
            'link_key' => $access['conversation_id'],
            'relationship_id' => $access['relationship_id'],
            'relationship_version' => $access['relationship_version'],
            'active' => $active,
        ]);
        return ['ok' => true, 'link_key' => $access['conversation_id']];
    });
    $status = (int)($result['http_status'] ?? 200);
    unset($result['http_status']);
    json_out($result, $status);
}

emit_event($pdo, $sessionId, 'typing', [
    'participant_id' => (int)$p['id'],
    'active' => $active,
]);

json_out(['ok' => true]);
