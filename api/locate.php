<?php
require_once __DIR__ . '/../includes/base.php';
$user = require_user();
$pdo = db();
$q = trim((string)($_GET['q'] ?? ''));
$like = '%' . $q . '%';
$stmt = $pdo->prepare(
    'SELECT u.id,
            u.display_name,
            u.avatar_path,
            COALESCE(live_room.id, owned_room.id) AS room_id,
            COALESCE(live_room.public_id, owned_room.public_id) AS room_public_id,
            COALESCE(live_room.name, owned_room.name) AS room_name,
            CASE WHEN re.id IS NULL THEN 0 ELSE 1 END AS room_ejected
       FROM users u
       LEFT JOIN (
            SELECT p.user_id, r.id, r.public_id, r.name, MAX(p.last_seen_at) AS seen_at
              FROM participants p
              JOIN room_sessions rs ON rs.id = p.session_id
              JOIN rooms r ON r.id = rs.room_id
             WHERE p.last_seen_at >= ?
             GROUP BY p.user_id
       ) live_room ON live_room.user_id = u.id
       LEFT JOIN rooms owned_room ON owned_room.owner_id = u.id
       LEFT JOIN room_ejections re ON re.room_id = COALESCE(live_room.id, owned_room.id)
            AND re.user_id = ?
            AND ' . active_ejection_sql('re') . '
      WHERE u.id != ?
        AND (? = "" OR u.display_name LIKE ?)
      ORDER BY u.display_name ASC'
);
$stmt->execute([stale_cutoff($pdo), (int)$user['id'], (int)$user['id'], $q, $like]);
$friends = array_map(fn($u) => avatar_visibility_project_payload($pdo, (int)$user['id'], [
    'id' => (int)$u['id'],
    'user_id' => (int)$u['id'],
    'display_name' => $u['display_name'],
    'avatar_path' => $u['avatar_path'],
    'avatar_url' => resolve_avatar($u['avatar_path']),
    'room_id' => $u['room_public_id'] ?: null,
    'room_name' => $u['room_name'] ?: null,
    'room_ejected' => !empty($u['room_ejected']),
]), $stmt->fetchAll());
json_out(['friends' => $friends]);
