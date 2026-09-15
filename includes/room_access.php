<?php
declare(strict_types=1);

final class RoomPasswordException extends RuntimeException {}

function room_access_hash(mixed $password): ?string {
    if (!is_string($password) || strlen($password) > 72 || str_contains($password, "\0")) {
        throw new RoomPasswordException('Room passwords must be text of at most 72 bytes.');
    }
    return $password === '' ? null : password_hash($password, PASSWORD_DEFAULT);
}

function room_access_is_private(array $room): bool {
    return !empty($room['room_password_hash']);
}

function room_access_can_delete(array $user, array $room): bool {
    return (int)($user['id'] ?? 0) > 0 &&
        ((int)$room['owner_id'] === (int)$user['id'] || ($user['role'] ?? '') === 'admin');
}

function room_access_grant_key(array $room, array $user): string {
    return (int)$user['id'] . ':' . (string)$room['public_id'];
}

function room_access_allowed(array $room, array $user): bool {
    if (!room_access_is_private($room) || room_access_can_delete($user, $room)) return true;
    $grant = $_SESSION['room_password_grants'][room_access_grant_key($room, $user)] ?? null;
    return is_string($grant) && hash_equals(hash('sha256', $room['room_password_hash']), $grant);
}

function room_access_for_session(PDO $pdo, int $sessionId): array {
    $q = $pdo->prepare('SELECT r.id,r.public_id,r.owner_id,r.room_password_hash FROM rooms r JOIN room_sessions s ON s.room_id=r.id WHERE s.id=?');
    $q->execute([$sessionId]);
    $room = $q->fetch();
    if (!$room) json_out(['error'=>'Room not found'], 404);
    return $room;
}

function room_access_require(PDO $pdo, array $room, array $user): void {
    if (!room_access_allowed($room, $user)) {
        json_out(['error'=>'Enter the room password to join.', 'code'=>'ROOM_PASSWORD_REQUIRED',
            'entry_url'=>app_url('/chatroom.php?id='.rawurlencode($room['public_id']))], 403);
    }
}

/** Session grants contain a hash revision only; passwords never become URLs or stored plaintext. */
function room_access_unlock(PDO $pdo, array $room, array $user, mixed $password): bool {
    $identifier = room_access_grant_key($room, $user);
    $limit = auth_rate_limit_status($pdo, 'room-password', $identifier);
    if (empty($limit['allowed'])) throw new RoomPasswordException('Too many attempts. Please wait and try again.');
    if (!is_string($password) || strlen($password) > 72 || str_contains($password, "\0") ||
        !room_access_is_private($room) || !password_verify($password, $room['room_password_hash'])) {
        auth_rate_record_failure($pdo, 'room-password', $identifier);
        return false;
    }
    $_SESSION['room_password_grants'][$identifier] = hash('sha256', $room['room_password_hash']);
    auth_rate_clear_identifier($pdo, 'room-password', $identifier);
    return true;
}
