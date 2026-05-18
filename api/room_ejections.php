<?php
require_once __DIR__ . '/../includes/base.php';

$user = require_user();
$pdo = db();

function room_from_request(PDO $pdo, array $source): array {
    if (!empty($source['session_id'])) {
        $sessionId = resolve_session_id($pdo, $source['session_id']);
        $stmt = $pdo->prepare('SELECT r.*, rs.public_id AS session_public_id FROM rooms r JOIN room_sessions rs ON rs.room_id = r.id WHERE rs.id = ? LIMIT 1');
        $stmt->execute([$sessionId]);
    } else {
        $roomPublicId = trim((string)($source['room_public_id'] ?? ''));
        if ($roomPublicId === '') json_out(['error' => 'Room required'], 400);
        $stmt = $pdo->prepare('SELECT r.*, rs.public_id AS session_public_id FROM rooms r LEFT JOIN room_sessions rs ON rs.room_id = r.id WHERE r.public_id = ? LIMIT 1');
        $stmt->execute([$roomPublicId]);
    }
    $room = $stmt->fetch();
    if (!$room) json_out(['error' => 'Room not found'], 404);
    return $room;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $room = room_from_request($pdo, $_GET);
    if (!can_use_host_tools($user, $room)) json_out(['error' => 'Unauthorized'], 403);
    $stmt = $pdo->prepare(
        'SELECT re.id, re.user_id, re.duration_minutes, re.permanent, re.created_at, re.expires_at,
                u.display_name, by_user.display_name AS ejected_by_name
           FROM room_ejections re
           JOIN users u ON u.id = re.user_id
           JOIN users by_user ON by_user.id = re.ejected_by_user_id
          WHERE re.room_id = ? AND ' . active_ejection_sql('re') . '
          ORDER BY re.created_at DESC'
    );
    $stmt->execute([(int)$room['id']]);
    $ejections = array_map(fn(array $row): array => [
        'id' => (int)$row['id'],
        'user_id' => (int)$row['user_id'],
        'display_name' => $row['display_name'],
        'ejected_by_name' => $row['ejected_by_name'],
        'duration_minutes' => $row['duration_minutes'] !== null ? (int)$row['duration_minutes'] : null,
        'permanent' => (bool)$row['permanent'],
        'created_at' => $row['created_at'],
        'expires_at' => $row['expires_at'],
    ], $stmt->fetchAll());
    json_out(['ejections' => $ejections]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Unsupported method'], 405);

$body = input_json();
$room = room_from_request($pdo, $body);
if (!can_use_host_tools($user, $room)) json_out(['error' => 'Unauthorized'], 403);
if (($body['action'] ?? '') !== 'delete') json_out(['error' => 'Unknown action'], 400);

$id = (int)($body['id'] ?? 0);
if (!$id) json_out(['error' => 'Ejection required'], 400);
$stmt = $pdo->prepare('SELECT user_id FROM room_ejections WHERE id = ? AND room_id = ? LIMIT 1');
$stmt->execute([$id, (int)$room['id']]);
$targetUserId = (int)($stmt->fetchColumn() ?: 0);
$pdo->prepare('DELETE FROM room_ejections WHERE id = ? AND room_id = ?')->execute([$id, (int)$room['id']]);
log_tool($pdo, (int)$user['id'], 'undo_room_kick', $targetUserId ?: null, (int)$room['id'], 'Deleted room ejection');
json_out(['ok' => true]);
