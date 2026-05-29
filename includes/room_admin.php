<?php
require_once __DIR__ . '/base.php';

function can_manage_room(array $user, array $room): bool {
    return (int)$room['owner_id'] === (int)$user['id'] || in_array($user['role'] ?? 'user', ['admin', 'developer'], true);
}

function room_admin_load_context(PDO $pdo, array $user, array $source, string $permission, bool $requireParticipant = false): array {
    $sessionId = null;
    $participant = null;
    $roomPublicId = trim((string)($source['room_public_id'] ?? ''));
    if (!empty($source['session_id'])) {
        $sessionId = resolve_session_id($pdo, $source['session_id'] ?? '');
        if ($requireParticipant) {
            $participant = auth_participant($pdo, $sessionId, $source['join_token'] ?? '');
            if ((int)$participant['user_id'] !== (int)$user['id']) json_out(['error' => 'Unauthorized'], 403);
        }
        $stmt = $pdo->prepare(
            'SELECT r.*, rs.id AS session_id, rs.public_id AS session_public_id
               FROM rooms r
               JOIN room_sessions rs ON rs.room_id = r.id
              WHERE rs.id = ?
              LIMIT 1'
        );
        $stmt->execute([$sessionId]);
    } else {
        if ($roomPublicId === '') json_out(['error' => 'Room required'], 400);
        $stmt = $pdo->prepare(
            'SELECT r.*, rs.id AS session_id, rs.public_id AS session_public_id
               FROM rooms r
               LEFT JOIN room_sessions rs ON rs.room_id = r.id
              WHERE r.public_id = ?
              LIMIT 1'
        );
        $stmt->execute([$roomPublicId]);
    }
    $room = $stmt->fetch();
    if (!$room) json_out(['error' => 'Room not found'], 404);

    if ($permission === 'manage' && !can_manage_room($user, $room)) {
        json_out(['error' => 'Only the room owner, admins, or developers can manage this room'], 403);
    }
    if ($permission === 'host_tools' && !can_use_host_tools($user, $room)) {
        json_out(['error' => 'Room owner, guide, developer, or admin access required.'], 403);
    }

    return [
        'room' => $room,
        'session_id' => $sessionId ?? (int)($room['session_id'] ?? 0),
        'session_public_id' => (string)($room['session_public_id'] ?? ''),
        'participant' => $participant,
    ];
}
