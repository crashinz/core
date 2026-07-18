<?php
declare(strict_types=1);

ob_start();
$pollRequestId = bin2hex(random_bytes(8));
set_exception_handler(static function (Throwable $error) use ($pollRequestId): never {
    while (ob_get_level() > 0) ob_end_clean();
    error_log(sprintf(
        'room-poll failure [%s] %s: %s',
        $pollRequestId,
        get_class($error),
        $error->getMessage()
    ));
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo json_encode([
        'error' => 'Room events are temporarily unavailable.',
        'code' => 'ROOM_POLL_FAILED',
        'request_id' => $pollRequestId,
        'recoverable' => true,
    ], JSON_UNESCAPED_SLASHES);
    exit;
});

require_once __DIR__ . '/../includes/base.php';
header('Cache-Control: no-cache, no-store, must-revalidate');
$pdo = db();
$sessionKey = trim((string)($_GET['session_id'] ?? ''));
$joinToken = trim((string)($_GET['join_token'] ?? ''));
$noticeStmt = $pdo->prepare(
    'SELECT id, payload FROM room_deletion_notices
      WHERE session_public_id = ? AND join_token = ?
      ORDER BY id DESC LIMIT 1'
);
$roomDeletedNotice = function() use ($noticeStmt, $sessionKey, $joinToken): ?array {
    if ($sessionKey === '' || $joinToken === '') return null;
    $noticeStmt->execute([$sessionKey, $joinToken]);
    $notice = $noticeStmt->fetch();
    if (!$notice) return null;
    return [
        'id' => (int)$notice['id'],
        'type' => 'room_deleted',
        'payload' => json_decode($notice['payload'], true) ?: [],
    ];
};
if ($notice = $roomDeletedNotice()) {
    json_out(['events' => [$notice], 'community_events' => []]);
}
$sessionId = resolve_session_id($pdo, $sessionKey);
$last = (int)($_GET['last_event_id'] ?? 0);
$lastCommunity = (int)($_GET['last_community_event_id'] ?? 0);
$me = auth_participant($pdo, $sessionId, $joinToken);
cleanup_stale_participants($pdo, $sessionId);
cleanup_room_effects($pdo, $sessionId);
$dmLeft = 'dm:' . (int)$me['user_id'] . ':%';
$dmRight = 'dm:%:' . (int)$me['user_id'];
$initialLinkAccess = avatar_relationship_chat_access($pdo, $sessionId, (int)$me['id']);
$linkConversationId = (string)($initialLinkAccess['conversation_id'] ?? '');
session_write_close();

$stmt = $pdo->prepare('SELECT id, type, payload FROM events WHERE session_id = ? AND id > ? ORDER BY id ASC LIMIT 200');
$mapRoomEvent = function(array $event) use ($pdo, $sessionId, $me): array {
    $payload = json_decode((string)$event['payload'], true) ?: [];
    if (in_array((string)$event['type'], ['relationship', 'link'], true)
        && isset($payload['relationship'])
        && !empty($payload['relationship_id'])
        && !empty($payload['relationship_version'])) {
        $relationshipStmt = $pdo->prepare(
            'SELECT id, version FROM avatar_relationships
              WHERE session_id = ? AND relationship_public_id = ? LIMIT 1'
        );
        $relationshipStmt->execute([$sessionId, (string)$payload['relationship_id']]);
        $relationship = $relationshipStmt->fetch() ?: null;
        if ($relationship && (int)$relationship['version'] === (int)$payload['relationship_version']) {
            $payload['relationship'] = avatar_relationship_payload(
                $pdo,
                (int)$relationship['id'],
                (int)$me['id']
            );
        }
    }
    return [
        'id' => (int)$event['id'],
        'type' => (string)$event['type'],
        'payload' => $payload,
    ];
};
$communityStmt = $pdo->prepare(
    "SELECT ce.id, ce.scope, ce.link_key, ce.type, ce.payload FROM community_events ce
     WHERE ce.id > ?
       AND (
         ce.scope = 'community'
         OR (
           ce.scope = 'link'
           AND ce.session_id = ?
           AND ce.link_key IN (
             SELECT ar.conversation_public_id
               FROM avatar_relationship_members viewer_membership
               JOIN avatar_relationships ar ON ar.id = viewer_membership.relationship_id
              WHERE ar.session_id = ? AND ar.status = 'active'
                AND ar.divergence_status = 'synced'
                AND viewer_membership.participant_id = ?
                AND viewer_membership.membership_status = 'active'
                AND viewer_membership.active_participant_id = ?
                AND NOT EXISTS (
                  SELECT 1
                    FROM avatar_relationship_members other_membership
                    JOIN participants other_participant ON other_participant.id = other_membership.participant_id
                    JOIN user_blocks ub
                      ON (ub.blocker_user_id = ? AND ub.blocked_user_id = other_participant.user_id)
                      OR (ub.blocked_user_id = ? AND ub.blocker_user_id = other_participant.user_id)
                   WHERE other_membership.relationship_id = ar.id
                     AND other_membership.membership_status = 'active'
                     AND other_membership.participant_id <> viewer_membership.participant_id
                )
           )
         )
         OR (
           ce.scope = 'dm'
           AND (ce.link_key LIKE ? OR ce.link_key LIKE ?)
         )
         OR (
           ce.scope = 'game'
           AND ce.session_id = ?
           AND ce.link_key IN (
             SELECT gl.lobby_code
             FROM game_lobbies gl
             JOIN game_sessions gs ON gs.lobby_code = gl.lobby_code
             WHERE gs.room_session_id = ?
               AND gs.ended_at IS NULL
               AND gl.status <> 'ended'
               AND (gl.user1_id = ? OR gl.user2_id = ?)
           )
         )
       )
     ORDER BY ce.id ASC LIMIT 200"
);
$pollAttempts = 20;
$pollSleepMicroseconds = 100000;
for ($i = 0; $i < $pollAttempts; $i++) {
    if ($notice = $roomDeletedNotice()) {
        json_out(['events' => [$notice], 'community_events' => []]);
    }
    $stmt->execute([$sessionId, $last]);
    $rows = $stmt->fetchAll();
    $linkAccess = $linkConversationId !== ''
        ? avatar_relationship_chat_access($pdo, $sessionId, (int)$me['id'], $linkConversationId)
        : null;
    $communityStmt->execute([
        $lastCommunity,
        $sessionId,
        $sessionId,
        (int)$me['id'],
        (int)$me['id'],
        (int)$me['user_id'],
        (int)$me['user_id'],
        $dmLeft,
        $dmRight,
        $sessionId,
        $sessionId,
        (int)$me['id'],
        (int)$me['id'],
    ]);
    $communityRows = array_values(array_filter($communityStmt->fetchAll(), function(array $event) use ($linkAccess): bool {
        if ((string)($event['scope'] ?? '') !== 'link') return true;
        if (!$linkAccess || (string)($event['link_key'] ?? '') !== (string)$linkAccess['conversation_id']) return false;
        $payload = json_decode((string)$event['payload'], true) ?: [];
        $messageId = (int)($payload['message_id'] ?? $payload['id'] ?? 0);
        return $messageId <= 0 || $messageId > (int)$linkAccess['visible_after_message_id'];
    }));
    if ($rows || $communityRows) {
        json_out([
        'events' => array_map($mapRoomEvent, $rows),
        'community_events' => array_map(fn($e) => [
            'id' => (int)$e['id'],
            'type' => $e['type'],
            'payload' => json_decode($e['payload'], true) ?: [],
        ], $communityRows),
        ]);
    }
    usleep($pollSleepMicroseconds);
}
json_out(['events' => [], 'community_events' => []]);
