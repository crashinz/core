<?php
require_once __DIR__ . '/../includes/base.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);
$user = require_user();
$pdo = db();
$sessionId = null;
$roomPublicId = trim((string)($_POST['room_public_id'] ?? ''));
if (!empty($_POST['session_id'])) {
    $sessionId = resolve_session_id($pdo, $_POST['session_id'] ?? '');
    $participant = auth_participant($pdo, $sessionId, $_POST['join_token'] ?? '');
    if ((int)$participant['user_id'] !== (int)$user['id']) {
        json_out(['error' => 'Unauthorized'], 403);
    }
    $stmt = $pdo->prepare('SELECT r.*, rs.public_id AS session_public_id FROM rooms r JOIN room_sessions rs ON rs.room_id = r.id WHERE rs.id = ? LIMIT 1');
    $stmt->execute([$sessionId]);
} else {
    if ($roomPublicId === '') json_out(['error' => 'Room required'], 400);
    $stmt = $pdo->prepare('SELECT r.*, rs.id AS session_id, rs.public_id AS session_public_id FROM rooms r LEFT JOIN room_sessions rs ON rs.room_id = r.id WHERE r.public_id = ? LIMIT 1');
    $stmt->execute([$roomPublicId]);
}
$room = $stmt->fetch();
if (!$room) json_out(['error' => 'Room not found'], 404);
$canEditRoom = (int)$room['owner_id'] === (int)$user['id'] || in_array($user['role'] ?? 'user', ['admin', 'developer'], true);
if (!$canEditRoom) {
    json_out(['error' => 'Only the room owner, admins, or developers can edit this room'], 403);
}

$name = trim((string)($_POST['name'] ?? ''));
if ($name === '') json_out(['error' => 'Room name required'], 400);

$bgPath = $room['background_path'];
$bgMime = $room['background_mime'];
if (!empty($_FILES['background']['tmp_name']) && is_uploaded_file($_FILES['background']['tmp_name'])) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($_FILES['background']['tmp_name']) ?: '';
    $allowed = ['image/jpeg','image/png','image/webp','image/gif','video/mp4','video/webm'];
    if (!in_array($mime, $allowed, true)) json_out(['error' => 'Unsupported background type'], 400);
    $isVideo = str_starts_with($mime, 'video/');
    $maxBytes = $isVideo ? app_setting_bytes($pdo, 'room_video_max_size_mb', 200) : app_setting_bytes($pdo, 'room_image_max_size_mb', 10);
    if ((int)$_FILES['background']['size'] > $maxBytes) {
        json_out(['error' => 'Background file is too large'], 400);
    }
    $ext = match ($mime) {
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        default => 'jpg',
    };
    $dir = __DIR__ . '/../assets/uploads/backgrounds';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $file = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['background']['tmp_name'], $dir . '/' . $file)) {
        json_out(['error' => 'Could not save background'], 500);
    }
    $bgPath = '/assets/uploads/backgrounds/' . $file;
    $bgMime = $mime;
}

$pdo->prepare('UPDATE rooms SET name = ?, background_path = ?, background_mime = ? WHERE id = ?')
    ->execute([$name, $bgPath, $bgMime, (int)$room['id']]);

$payload = [
    'room_name' => $name,
    'background_path' => $bgPath,
    'background_mime' => $bgMime,
];
if ($sessionId) {
    emit_event($pdo, $sessionId, 'room_update', $payload);
} elseif (!empty($room['session_id'])) {
    emit_event($pdo, (int)$room['session_id'], 'room_update', $payload);
}
json_out($payload);
