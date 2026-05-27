<?php
require_once __DIR__ . '/../includes/base.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);

$pdo = db();
$sessionId = resolve_session_id($pdo, $_POST['session_id'] ?? '');
$participant = auth_participant($pdo, $sessionId, $_POST['join_token'] ?? '');
$authorContext = author_context_for_participant($pdo, $sessionId, $participant);

if (empty($_FILES['audio']) || !is_array($_FILES['audio'])) {
    json_out(['error' => 'Voice note required'], 400);
}

$file = $_FILES['audio'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_out(['error' => 'Voice note upload failed'], 400);
}
if ((int)$file['size'] > 20 * 1024 * 1024) {
    json_out(['error' => 'Voice note is too large'], 400);
}

$tmpName = (string)$file['tmp_name'];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($tmpName) ?: (string)($file['type'] ?? 'application/octet-stream');
if ($mimeType === 'application/octet-stream' && !empty($file['type'])) {
    $mimeType = (string)$file['type'];
}
$extensions = [
    'audio/webm' => 'webm',
    'video/webm' => 'webm',
    'audio/ogg' => 'ogg',
    'application/ogg' => 'ogg',
    'audio/mpeg' => 'mp3',
    'audio/mp4' => 'm4a',
    'audio/wav' => 'wav',
    'audio/x-wav' => 'wav',
];

if (!isset($extensions[$mimeType]) && !str_starts_with($mimeType, 'audio/')) {
    json_out(['error' => 'Unsupported voice note format'], 400);
}

$extension = $extensions[$mimeType] ?? 'audio';
$uploadDir = __DIR__ . '/../assets/uploads/voice';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0775, true);
}

$filename = 'vn_' . bin2hex(random_bytes(16)) . '.' . $extension;
$target = $uploadDir . '/' . $filename;
if (!move_uploaded_file($tmpName, $target)) {
    json_out(['error' => 'Could not save voice note'], 500);
}

$publicPath = '/assets/uploads/voice/' . $filename;
$avatarUrl = $participant['webcam_path'] ?: resolve_avatar($participant['avatar_path']);

function voice_reply_preview_text(array $message): string {
    $type = (string)($message['message_type'] ?? 'text');
    if ($type === 'gif') return 'sent a GIF';
    if ($type === 'gesture') {
        $gesture = message_gesture((string)($message['content'] ?? ''));
        return trim((string)($gesture['text'] ?? $gesture['name'] ?? $message['original_name'] ?? 'sent a gesture'));
    }
    if ($type === 'file') return trim((string)($message['original_name'] ?? 'sent a file'));
    if ($type === 'voice_note') return 'sent a voice note';
    $text = trim(preg_replace('/\s+/', ' ', (string)($message['content'] ?? '')));
    return $text === '' ? 'Message' : (function_exists('mb_substr') ? mb_substr($text, 0, 180, 'UTF-8') : substr($text, 0, 180));
}

$replyTo = null;
$replyId = (int)($_POST['reply_to_id'] ?? 0);
if ($replyId > 0) {
    if ((string)($_POST['reply_to_channel'] ?? 'room') !== 'room') json_out(['error' => 'Reply target unavailable'], 400);
    $stmt = $pdo->prepare('SELECT * FROM messages WHERE id = ? AND session_id = ? AND COALESCE(is_deleted, 0) = 0 LIMIT 1');
    $stmt->execute([$replyId, $sessionId]);
    $message = $stmt->fetch();
    if (!$message) json_out(['error' => 'Reply target unavailable'], 404);
    $replyTo = [
        'id' => (int)$message['id'],
        'channel' => 'room',
        'participant_id' => isset($message['participant_id']) ? (int)$message['participant_id'] : null,
        'user_id' => isset($message['user_id']) ? (int)$message['user_id'] : null,
        'display_name' => $message['display_name'] ?? 'Someone',
        'message_type' => $message['message_type'] ?? 'text',
        'original_name' => $message['original_name'] ?? null,
        'preview' => voice_reply_preview_text($message),
    ];
}
$replyToJson = $replyTo ? json_encode($replyTo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

$stmt = $pdo->prepare(
    "INSERT INTO messages (session_id, participant_id, user_id, display_name, avatar_path, avatar_url, content, reply_to_json, message_type, file_size, mime_type, original_name)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
);
$stmt->execute([$sessionId, (int)$participant['id'], (int)$participant['user_id'], $participant['display_name'], $participant['avatar_path'], $avatarUrl, $publicPath, $replyToJson, 'voice_note', (int)$file['size'], $mimeType, 'Voice Note']);
$id = (int)$pdo->lastInsertId();

$msg = [
    'id' => $id,
    'participant_id' => (int)$participant['id'],
    'user_id' => (int)$participant['user_id'],
    'display_name' => $participant['display_name'],
    'avatar_path' => $participant['avatar_path'],
    'avatar_url' => $avatarUrl,
    'role' => $authorContext['role'],
    'is_owner' => $authorContext['is_owner'],
    'content' => $publicPath,
    'message_type' => 'voice_note',
    'file_size' => (int)$file['size'],
    'mime_type' => $mimeType,
    'original_name' => 'Voice Note',
    'reply_to' => $replyTo,
    'sent_at' => gmdate('Y-m-d H:i:s'),
];

emit_event($pdo, $sessionId, 'message', $msg);
json_out($msg);
