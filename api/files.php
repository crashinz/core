<?php
require_once __DIR__ . '/../includes/base.php';
require_once __DIR__ . '/../includes/message_centre.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);

$pdo = db();
$sessionId = resolve_session_id($pdo, $_POST['session_id'] ?? '');
$participant = auth_participant($pdo, $sessionId, $_POST['join_token'] ?? '');
$authorContext = author_context_for_participant($pdo, $sessionId, $participant);

if (!empty($_FILES['file']) && is_array($_FILES['file'])) {
    $file = $_FILES['file'];
    $isVoiceNote = false;
} elseif (!empty($_FILES['audio']) && is_array($_FILES['audio'])) {
    $file = $_FILES['audio'];
    $isVoiceNote = true;
} else {
    json_out(['error' => 'File required'], 400);
}
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_out(['error' => 'Upload failed'], 400);
}
$tmpName = (string)$file['tmp_name'];
$originalName = trim((string)($file['name'] ?? 'attachment'));
if ($originalName === '') $originalName = 'attachment';
security_authorize_outside_content_or_json(
    $pdo,
    ['id' => (int)$participant['user_id']],
    $isVoiceNote ? 'voice_note_upload' : 'chat_file_upload',
    ['session_id' => $sessionId, 'channel' => (string)($_POST['channel'] ?? 'room')]
);

$channel = (string)($_POST['channel'] ?? 'room');
if (!in_array($channel, ['room', 'community', 'link', 'dm', 'game'], true)) $channel = 'room';
$requestedRelationshipIdentity = trim((string)($_POST['conversation_id'] ?? $_POST['relationship_id'] ?? ''));
$targetParticipantId = (int)($_POST['target_participant_id'] ?? 0);
if ($channel === 'link' && !avatar_relationship_chat_access(
    $pdo,
    $sessionId,
    (int)$participant['id'],
    $requestedRelationshipIdentity,
    $targetParticipantId
)) {
    json_out(['error' => 'Relationship conversation unavailable'], 403);
}

if ($channel === 'dm') {
    $validatedTargetUserId = (int)($_POST['target_user_id'] ?? 0);
    if (!$validatedTargetUserId || $validatedTargetUserId === (int)$participant['user_id']) {
        json_out(['error' => 'DM recipient required'], 400);
    }
    $validatedTarget = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
    $validatedTarget->execute([$validatedTargetUserId]);
    if (!$validatedTarget->fetch()) json_out(['error' => 'DM recipient not found'], 404);
    $validatedBlock = $pdo->prepare(
        'SELECT 1 FROM user_blocks
         WHERE (blocker_user_id = ? AND blocked_user_id = ?)
            OR (blocker_user_id = ? AND blocked_user_id = ?)
         LIMIT 1'
    );
    $validatedBlock->execute([(int)$participant['user_id'], $validatedTargetUserId, $validatedTargetUserId, (int)$participant['user_id']]);
    if ($validatedBlock->fetch()) json_out(['error' => 'You cannot DM this user.'], 403);
}

if ($channel === 'game') {
    $validatedLobby = (string)($_POST['lobby_code'] ?? '');
    if ($validatedLobby === '') json_out(['error' => 'Game required'], 400);
    $validatedGame = $pdo->prepare(
        'SELECT gl.*
           FROM game_lobbies gl
           JOIN game_sessions gs ON gs.lobby_code = gl.lobby_code
          WHERE gs.room_session_id = ? AND gl.lobby_code = ? AND gs.ended_at IS NULL AND gl.status <> "ended"
          LIMIT 1'
    );
    $validatedGame->execute([$sessionId, $validatedLobby]);
    $validatedGameRow = $validatedGame->fetch();
    if (!$validatedGameRow) json_out(['error' => 'Game not found'], 404);
    $validatedPlayerIds = array_filter([(int)($validatedGameRow['user1_id'] ?? 0), (int)($validatedGameRow['user2_id'] ?? 0)]);
    if (!in_array((int)$participant['id'], $validatedPlayerIds, true)) {
        json_out(['error' => 'Join the game to use game chat'], 403);
    }
}

$audience = [];
if ($channel === 'dm') {
    $candidateTargetUserId = (int)($_POST['target_user_id'] ?? 0);
    if ($candidateTargetUserId > 0) $audience[] = $candidateTargetUserId;
} elseif ($channel === 'link' && $targetParticipantId > 0) {
    $targetStatement = $pdo->prepare('SELECT user_id FROM participants WHERE session_id=? AND id=? LIMIT 1');
    $targetStatement->execute([$sessionId, $targetParticipantId]);
    $candidateTargetUserId = (int)$targetStatement->fetchColumn();
    if ($candidateTargetUserId > 0) $audience[] = $candidateTargetUserId;
} elseif ($channel === 'game' && !empty($validatedPlayerIds)) {
    $playerPlaceholders = implode(',', array_fill(0, count($validatedPlayerIds), '?'));
    $playerUsers = $pdo->prepare("SELECT user_id FROM participants WHERE id IN ({$playerPlaceholders})");
    $playerUsers->execute(array_values($validatedPlayerIds));
    $audience = array_map('intval', $playerUsers->fetchAll(PDO::FETCH_COLUMN));
}
try {
    $serverAsset = server_media_upload($pdo, $file, $participant, $sessionId, $channel, $isVoiceNote, $audience);
} catch (ServerMediaException $error) {
    json_out(['error' => $error->getMessage(), 'code' => $error->errorCode], $error->httpStatus);
}
$publicPath = (string)$serverAsset['downloadUrl'];
$mimeType = (string)$serverAsset['detectedMime'];

function file_reply_accessible(PDO $pdo, array $message, string $channel, int $sessionId, array $participant): bool {
    if (($message['scope'] ?? '') !== $channel) return false;
    if ($channel === 'community') return true;
    if ($channel === 'link') {
        return avatar_relationship_chat_message_accessible(
            $pdo,
            $message,
            $sessionId,
            (int)$participant['id']
        ) !== null;
    }
    if ($channel === 'dm') {
        $ids = explode(':', (string)($message['link_key'] ?? ''));
        $a = (int)($ids[1] ?? 0);
        $b = (int)($ids[2] ?? 0);
        return $a === (int)$participant['user_id'] || $b === (int)$participant['user_id'];
    }
    return false;
}

function file_reply_preview_text(array $message): string {
    $type = (string)($message['message_type'] ?? 'text');
    if ($type === 'gif') return 'sent a GIF';
    if ($type === 'gesture') {
        $gesture = message_gesture((string)($message['content'] ?? ''));
        return gesture_presentation_canonical_text(is_array($gesture) ? $gesture : []);
    }
    if ($type === 'file') return trim((string)($message['original_name'] ?? 'sent a file'));
    if ($type === 'voice_note') return 'sent a voice note';
    $text = trim(preg_replace('/\s+/', ' ', (string)($message['content'] ?? '')));
    return $text === '' ? 'Message' : (function_exists('mb_substr') ? mb_substr($text, 0, 180, 'UTF-8') : substr($text, 0, 180));
}

function file_reply_snapshot(PDO $pdo, string $channel, int $sessionId, array $participant): ?array {
    $replyId = (int)($_POST['reply_to_id'] ?? 0);
    if ($replyId <= 0 || $channel === 'game') return null;
    $replyChannel = (string)($_POST['reply_to_channel'] ?? $channel);
    if (str_starts_with($replyChannel, 'link:')) $replyChannel = 'link';
    if (str_starts_with($replyChannel, 'dm:')) $replyChannel = 'dm';
    if ($replyChannel !== $channel) json_out(['error' => 'Reply target unavailable'], 400);
    if ($channel === 'room') {
        $stmt = $pdo->prepare('SELECT * FROM messages WHERE id = ? AND session_id = ? AND COALESCE(is_deleted, 0) = 0 LIMIT 1');
        $stmt->execute([$replyId, $sessionId]);
        $message = $stmt->fetch();
    } else {
        $stmt = $pdo->prepare('SELECT * FROM community_messages WHERE id = ? AND COALESCE(is_deleted, 0) = 0 LIMIT 1');
        $stmt->execute([$replyId]);
        $message = $stmt->fetch();
        if ($message && !file_reply_accessible($pdo, $message, $channel, $sessionId, $participant)) $message = false;
    }
    if (!$message) json_out(['error' => 'Reply target unavailable'], 404);
    return [
        'id' => (int)$message['id'],
        'channel' => $channel,
        'participant_id' => isset($message['participant_id']) ? (int)$message['participant_id'] : null,
        'user_id' => isset($message['user_id']) ? (int)$message['user_id'] : null,
        'display_name' => $message['display_name'] ?? 'Someone',
        'message_type' => $message['message_type'] ?? 'text',
        'original_name' => $message['original_name'] ?? null,
        'preview' => file_reply_preview_text($message),
    ];
}

$replyTo = $channel === 'link' ? null : file_reply_snapshot($pdo, $channel, $sessionId, $participant);
$replyToJson = $replyTo ? json_encode($replyTo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

function uploaded_media_message(PDO $pdo, string $channel, string $messageType, array $participant, array $authorContext, string $content, array $file, string $mimeType, string $originalName, ?array $replyTo, ?string $replyToJson, string $assetPublicId, array $route = []): array {
    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) {
            if (db_uses_mysql_syntax($pdo)) $pdo->beginTransaction();
            else $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
        }
        $message = create_message($pdo, $channel, $messageType, [
            'session_id' => $route['session_id'] ?? null,
            'participant' => $participant,
            'author_context' => $authorContext,
            'content' => $content,
            'file_size' => (int)$file['size'],
            'mime_type' => $mimeType,
            'original_name' => $originalName,
            'reply_to' => $replyTo,
            'reply_to_json' => $replyToJson,
            'link_key' => $route['link_key'] ?? null,
            'relationship_id' => $route['relationship_id'] ?? null,
            'relationship_version' => $route['relationship_version'] ?? null,
            'dm_key' => $route['dm_key'] ?? null,
            'target_user_id' => $route['target_user_id'] ?? null,
            'lobby_code' => $route['lobby_code'] ?? null,
        ]);
        $table = $channel === 'room' ? 'messages' : ($channel === 'game' ? 'game_chat_messages' : 'community_messages');
        server_media_add_reference(
            $pdo,
            $assetPublicId,
            $table,
            (int)$message['id'],
            $channel,
            (string)($route['link_key'] ?? $route['dm_key'] ?? $route['lobby_code'] ?? '')
        );
        if ($ownsTransaction) $pdo->commit();
        return $message + ['server_media_id' => $assetPublicId, 'delivery' => 'server'];
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        if ($ownsTransaction) server_media_discard_unreferenced($pdo, $assetPublicId);
        throw $error;
    }
}

if ($channel === 'community') {
    json_out(uploaded_media_message($pdo, 'community', $isVoiceNote ? 'voice_note' : 'file', $participant, $authorContext, $publicPath, $file, $mimeType, $isVoiceNote ? 'Voice Note' : $originalName, $replyTo, $replyToJson, (string)$serverAsset['id']));
}

if ($channel === 'link') {
    try {
        $result = avatar_relationship_transaction($pdo, function() use (
        $pdo,
        $sessionId,
        $participant,
        $requestedRelationshipIdentity,
        $targetParticipantId,
        $isVoiceNote,
        $authorContext,
        $publicPath,
        $file,
        $mimeType,
        $originalName,
        $serverAsset
    ): array {
        $access = avatar_relationship_chat_access(
            $pdo,
            $sessionId,
            (int)$participant['id'],
            $requestedRelationshipIdentity,
            $targetParticipantId,
            true
        );
        if (!$access) return ['error' => 'Relationship conversation unavailable', 'http_status' => 403];
        $replyTo = file_reply_snapshot($pdo, 'link', $sessionId, $participant);
        $replyToJson = $replyTo
            ? json_encode($replyTo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : null;
        return uploaded_media_message(
            $pdo,
            'link',
            $isVoiceNote ? 'voice_note' : 'file',
            $participant,
            $authorContext,
            $publicPath,
            $file,
            $mimeType,
            $isVoiceNote ? 'Voice Note' : $originalName,
            $replyTo,
            $replyToJson,
            (string)$serverAsset['id'],
            [
                'session_id' => $sessionId,
                'link_key' => $access['conversation_id'],
                'relationship_id' => $access['relationship_id'],
                'relationship_version' => $access['relationship_version'],
            ]
        );
        });
    } catch (Throwable $error) {
        server_media_discard_unreferenced($pdo, (string)$serverAsset['id']);
        throw $error;
    }
    if (!empty($result['error'])) {
        server_media_discard_unreferenced($pdo, (string)$serverAsset['id']);
        $status = (int)($result['http_status'] ?? 403);
        unset($result['http_status']);
        json_out($result, $status);
    }
    json_out($result);
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
    json_out(uploaded_media_message($pdo, 'dm', $isVoiceNote ? 'voice_note' : 'file', $participant, $authorContext, $publicPath, $file, $mimeType, $isVoiceNote ? 'Voice Note' : $originalName, $replyTo, $replyToJson, (string)$serverAsset['id'], [
        'dm_key' => $dmKey,
        'target_user_id' => $targetUserId,
    ]));
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
    json_out(uploaded_media_message($pdo, 'game', $isVoiceNote ? 'voice_note' : 'file', $participant, $authorContext, $publicPath, $file, $mimeType, $isVoiceNote ? 'Voice Note' : $originalName, $replyTo, $replyToJson, (string)$serverAsset['id'], ['lobby_code' => $lobby]));
}

json_out(uploaded_media_message($pdo, 'room', $isVoiceNote ? 'voice_note' : 'file', $participant, $authorContext, $publicPath, $file, $mimeType, $isVoiceNote ? 'Voice Note' : $originalName, $replyTo, $replyToJson, (string)$serverAsset['id'], [
    'session_id' => $sessionId,
]));
