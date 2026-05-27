<?php
require_once __DIR__ . '/../includes/base.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);

$pdo = db();
$sessionId = resolve_session_id($pdo, $_POST['session_id'] ?? '');
$participant = auth_participant($pdo, $sessionId, $_POST['join_token'] ?? '');
$authorContext = author_context_for_participant($pdo, $sessionId, $participant);

if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
    json_out(['error' => 'File required'], 400);
}

$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_out(['error' => 'Upload failed'], 400);
}
if ((int)$file['size'] > 50 * 1024 * 1024) {
    json_out(['error' => 'File is too large'], 400);
}

$tmpName = (string)$file['tmp_name'];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($tmpName) ?: 'application/octet-stream';
$originalName = trim((string)($file['name'] ?? 'attachment'));
if ($originalName === '') $originalName = 'attachment';
$originalExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$allowed = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
    'application/pdf' => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/rtf' => 'rtf',
    'text/rtf' => 'rtf',
    'text/plain' => 'txt',
];
$extMime = [
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'rtf' => 'application/rtf',
    'txt' => 'text/plain',
];

if (!isset($allowed[$mimeType]) && isset($extMime[$originalExt]) && in_array($mimeType, ['application/zip', 'application/octet-stream', 'text/plain'], true)) {
    $mimeType = $extMime[$originalExt];
}

if (!isset($allowed[$mimeType])) {
    json_out(['error' => 'Only images, PDFs, and documents are supported'], 400);
}

$uploadDir = __DIR__ . '/../assets/uploads/files';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0775, true);
}

$filename = 'f_' . bin2hex(random_bytes(16)) . '.' . $allowed[$mimeType];
$target = $uploadDir . '/' . $filename;
if (!move_uploaded_file($tmpName, $target)) {
    json_out(['error' => 'Could not save file'], 500);
}

$publicPath = '/assets/uploads/files/' . $filename;
$avatarUrl = $participant['webcam_path'] ?: resolve_avatar($participant['avatar_path']);
$channel = (string)($_POST['channel'] ?? 'room');
if (!in_array($channel, ['room', 'community', 'link', 'dm', 'game'], true)) $channel = 'room';

$baseMsg = [
    'participant_id' => (int)$participant['id'],
    'user_id' => (int)$participant['user_id'],
    'display_name' => $participant['display_name'],
    'avatar_path' => $participant['avatar_path'],
    'avatar_url' => $avatarUrl,
    'role' => $authorContext['role'],
    'is_owner' => $authorContext['is_owner'],
    'content' => $publicPath,
    'message_type' => 'file',
    'file_size' => (int)$file['size'],
    'mime_type' => $mimeType,
    'original_name' => $originalName,
    'sent_at' => gmdate('Y-m-d H:i:s'),
];

if ($channel === 'community') {
    $stmt = $pdo->prepare(
        "INSERT INTO community_messages (scope, participant_id, user_id, display_name, avatar_path, avatar_url, content, message_type, file_size, mime_type, original_name)
         VALUES ('community',?,?,?,?,?,?,?,?,?,?)"
    );
    $stmt->execute([(int)$participant['id'], (int)$participant['user_id'], $participant['display_name'], $participant['avatar_path'], $avatarUrl, $publicPath, 'file', (int)$file['size'], $mimeType, $originalName]);
    $msg = ['id' => (int)$pdo->lastInsertId(), 'channel' => 'community'] + $baseMsg;
    emit_community_event($pdo, 'community', null, null, 'community_message', $msg);
    json_out($msg);
}

if ($channel === 'link') {
    $targetId = (int)($_POST['target_participant_id'] ?? 0);
    if (!$targetId) json_out(['error' => 'Linked participant required'], 400);
    $stmt = $pdo->prepare(
        'SELECT id FROM participants
         WHERE session_id = ? AND id = ?
           AND (linked_to_participant_id = ? OR id = (SELECT linked_to_participant_id FROM participants WHERE id = ?))
         LIMIT 1'
    );
    $stmt->execute([$sessionId, $targetId, (int)$participant['id'], (int)$participant['id']]);
    if (!$stmt->fetch()) json_out(['error' => 'You are not linked to that participant'], 403);
    $linkKey = link_key_for((int)$participant['id'], $targetId);
    $stmt = $pdo->prepare(
        "INSERT INTO community_messages (scope, session_id, link_key, participant_id, user_id, display_name, avatar_path, avatar_url, content, message_type, file_size, mime_type, original_name)
         VALUES ('link',?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    $stmt->execute([$sessionId, $linkKey, (int)$participant['id'], (int)$participant['user_id'], $participant['display_name'], $participant['avatar_path'], $avatarUrl, $publicPath, 'file', (int)$file['size'], $mimeType, $originalName]);
    $msg = ['id' => (int)$pdo->lastInsertId(), 'channel' => 'link', 'link_key' => $linkKey] + $baseMsg;
    emit_community_event($pdo, 'link', $sessionId, $linkKey, 'link_message', $msg);
    json_out($msg);
}

if ($channel === 'dm') {
    $targetUserId = (int)($_POST['target_user_id'] ?? 0);
    if (!$targetUserId || $targetUserId === (int)$participant['user_id']) json_out(['error' => 'DM recipient required'], 400);
    $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$targetUserId]);
    if (!$stmt->fetch()) json_out(['error' => 'DM recipient not found'], 404);
    $stmt = $pdo->prepare(
        'SELECT 1 FROM user_blocks
         WHERE (blocker_user_id = ? AND blocked_user_id = ?)
            OR (blocker_user_id = ? AND blocked_user_id = ?)
         LIMIT 1'
    );
    $stmt->execute([(int)$participant['user_id'], $targetUserId, $targetUserId, (int)$participant['user_id']]);
    if ($stmt->fetch()) json_out(['error' => 'You cannot DM this user.'], 403);
    $dmKey = dm_key_for((int)$participant['user_id'], $targetUserId);
    $stmt = $pdo->prepare(
        "INSERT INTO community_messages (scope, link_key, participant_id, user_id, display_name, avatar_path, avatar_url, content, message_type, file_size, mime_type, original_name)
         VALUES ('dm',?,?,?,?,?,?,?,?,?,?,?)"
    );
    $stmt->execute([$dmKey, (int)$participant['id'], (int)$participant['user_id'], $participant['display_name'], $participant['avatar_path'], $avatarUrl, $publicPath, 'file', (int)$file['size'], $mimeType, $originalName]);
    $msg = ['id' => (int)$pdo->lastInsertId(), 'channel' => 'dm', 'dm_key' => $dmKey, 'target_user_id' => $targetUserId, 'partner_user_id' => $targetUserId, 'is_owner' => false] + $baseMsg;
    emit_community_event($pdo, 'dm', null, $dmKey, 'dm_message', $msg);
    json_out($msg);
}

if ($channel === 'game') {
    $lobby = (string)($_POST['lobby_code'] ?? '');
    if ($lobby === '') json_out(['error' => 'Game required'], 400);
    $stmt = $pdo->prepare(
        'SELECT gl.*
           FROM game_lobbies gl
           JOIN game_sessions gs ON gs.lobby_code = gl.lobby_code
          WHERE gs.room_session_id = ? AND gl.lobby_code = ? AND gs.ended_at IS NULL AND gl.status <> "ended"
          LIMIT 1'
    );
    $stmt->execute([$sessionId, $lobby]);
    $game = $stmt->fetch();
    if (!$game) json_out(['error' => 'Game not found'], 404);
    $playerIds = array_filter([(int)($game['user1_id'] ?? 0), (int)($game['user2_id'] ?? 0)]);
    if (!in_array((int)$participant['id'], $playerIds, true)) json_out(['error' => 'Join the game to use game chat'], 403);
    $stmt = $pdo->prepare(
        'INSERT INTO game_chat_messages (lobby_code, participant_id, content, message_type, file_size, mime_type, original_name)
         VALUES (?,?,?,?,?,?,?)'
    );
    $stmt->execute([$lobby, (int)$participant['id'], $publicPath, 'file', (int)$file['size'], $mimeType, $originalName]);
    $msg = ['id' => (int)$pdo->lastInsertId(), 'channel' => 'game', 'lobby_code' => $lobby] + $baseMsg;
    json_out($msg);
}

$stmt = $pdo->prepare(
    "INSERT INTO messages (session_id, participant_id, user_id, display_name, avatar_path, avatar_url, content, message_type, file_size, mime_type, original_name)
     VALUES (?,?,?,?,?,?,?,?,?,?,?)"
);
$stmt->execute([$sessionId, (int)$participant['id'], (int)$participant['user_id'], $participant['display_name'], $participant['avatar_path'], $avatarUrl, $publicPath, 'file', (int)$file['size'], $mimeType, $originalName]);
$id = (int)$pdo->lastInsertId();

$msg = ['id' => $id] + $baseMsg;

emit_event($pdo, $sessionId, 'message', $msg);
json_out($msg);
