<?php
require_once __DIR__ . '/../includes/base.php';
require_once __DIR__ . '/../includes/message_centre.php';

final class CorechatUploadedMediaReplyException extends RuntimeException
{
    public function __construct(public readonly int $httpStatus)
    {
        parent::__construct('Reply target unavailable');
    }
}


final class CorechatUploadedMediaGameException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus)
    {
        parent::__construct($message);
    }
}

/**
 * Resolve media admission, not historical download authorization.
 * A framework identity must never fall back to its legacy compatibility seats.
 */
function uploaded_media_game_access(PDO $pdo, int $sessionId, array $participant, string $lobby, int $userId, bool $lock = false): array
{
    if ($userId <= 0 || (int)($participant['user_id'] ?? 0) !== $userId) {
        throw new CorechatUploadedMediaGameException('Unauthorized', 403);
    }
    if ($lobby === '') throw new CorechatUploadedMediaGameException('Game required', 400);
    if (database_migration_table_exists($pdo, 'multiplayer_game_sessions')) {
        $sql = 'SELECT id,source_room_session_id,status FROM multiplayer_game_sessions WHERE public_id=? LIMIT 1';
        if ($lock && db_uses_mysql_syntax($pdo)) $sql .= ' FOR UPDATE';
        $statement = $pdo->prepare($sql);
        $statement->execute([$lobby]);
        $framework = $statement->fetch();
        $statement->closeCursor();
        if (is_array($framework)) {
            if ((int)$framework['source_room_session_id'] !== $sessionId
                || !in_array((string)$framework['status'], ['lobby','active','paused','completed','forfeited'], true)) {
                throw new CorechatUploadedMediaGameException('Game not found', 404);
            }
            try {
                multiplayer_game_require_member($pdo, $lobby, $userId);
            } catch (MultiplayerGameException $error) {
                if ($error->httpStatus !== 403) throw $error;
                throw new CorechatUploadedMediaGameException('Join or spectate the game to use game chat', 403);
            }
            $sql = "SELECT user_id,role,membership_status FROM multiplayer_game_members WHERE game_session_id=? ORDER BY id";
            if ($lock && db_uses_mysql_syntax($pdo)) $sql .= ' FOR UPDATE';
            $members = $pdo->prepare($sql);
            $members->execute([(int)$framework['id']]);
            $rows = $members->fetchAll();
            $members->closeCursor();
            $audience = [];
            foreach ($rows as $member) {
                if ((string)$member['membership_status'] === 'active'
                    && in_array((string)$member['role'], ['master','player','spectator'], true)
                    && (int)$member['user_id'] > 0) {
                    $audience[] = (int)$member['user_id'];
                }
            }
            $audience = array_values(array_unique($audience));
            if (!in_array($userId, $audience, true)) {
                throw new CorechatUploadedMediaGameException('Join or spectate the game to use game chat', 403);
            }
            return ['framework' => true, 'audience' => $audience];
        }
    }
    $sql = 'SELECT gl.* FROM game_lobbies gl JOIN game_sessions gs ON gs.lobby_code=gl.lobby_code
            WHERE gs.room_session_id=? AND gl.lobby_code=? AND gs.ended_at IS NULL AND gl.status <> "ended" LIMIT 1';
    if ($lock && db_uses_mysql_syntax($pdo)) $sql .= ' FOR UPDATE';
    $statement = $pdo->prepare($sql);
    $statement->execute([$sessionId, $lobby]);
    $legacy = $statement->fetch();
    $statement->closeCursor();
    if (!is_array($legacy)) throw new CorechatUploadedMediaGameException('Game not found', 404);
    $playerIds = array_values(array_filter([(int)($legacy['user1_id'] ?? 0), (int)($legacy['user2_id'] ?? 0)]));
    if (!in_array((int)$participant['id'], $playerIds, true)) {
        throw new CorechatUploadedMediaGameException('Join the game to use game chat', 403);
    }
    $placeholders = implode(',', array_fill(0, count($playerIds), '?'));
    $players = $pdo->prepare("SELECT user_id FROM participants WHERE id IN ({$placeholders})");
    $players->execute($playerIds);
    $audience = array_map('intval', $players->fetchAll(PDO::FETCH_COLUMN));
    $players->closeCursor();
    return ['framework' => false, 'audience' => $audience];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);

$pdo = db();
corechat_chat_post_rate_install_api_handler($pdo);
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

$validatedGameAccess = null;
$validatedGameUserId = 0;
if ($channel === 'game') {
    $gameUser = require_user();
    $validatedGameUserId = (int)$gameUser['id'];
    try {
        $validatedGameAccess = uploaded_media_game_access($pdo, $sessionId, $participant, (string)($_POST['lobby_code'] ?? ''), $validatedGameUserId);
    } catch (CorechatUploadedMediaGameException $error) {
        json_out(['error' => $error->getMessage()], $error->httpStatus);
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
} elseif ($channel === 'game') {
    $audience = $validatedGameAccess['audience'];
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
    if ($replyChannel !== $channel) throw new CorechatUploadedMediaReplyException(400);
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
    if (!$message) throw new CorechatUploadedMediaReplyException(404);
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

try {
    $replyTo = $channel === 'link' ? null : file_reply_snapshot($pdo, $channel, $sessionId, $participant);
    $replyToJson = $replyTo ? json_encode($replyTo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
} catch (CorechatUploadedMediaReplyException $error) {
    server_media_discard_unreferenced($pdo, (string)$serverAsset['id']);
    json_out(['error' => $error->getMessage()], $error->httpStatus);
}

function uploaded_media_message(PDO $pdo, string $channel, string $messageType, array $participant, array $authorContext, string $content, array $file, string $mimeType, string $originalName, ?array $replyTo, ?string $replyToJson, string $assetPublicId, array $route = []): array {
    $ownsTransaction = !$pdo->inTransaction();
    $transaction = [];
    try {
        $transaction = database_transaction_begin($pdo, true);
        $ownsTransaction = !empty($transaction['owned']);
        if ($channel === 'game') {
            $access = uploaded_media_game_access(
                $pdo,
                (int)($route['game_room_session_id'] ?? 0),
                $participant,
                (string)($route['lobby_code'] ?? ''),
                (int)($route['game_actor_user_id'] ?? 0),
                true
            );
            // Finalize this new asset's recipient snapshot only after revalidation.
            // Existing stored-audience historical download policy is unchanged.
            $pdo->prepare("UPDATE server_media_assets SET audience_json=? WHERE public_id=? AND uploader_user_id=? AND status='active'")
                ->execute([json_encode($access['audience'], JSON_UNESCAPED_SLASHES), $assetPublicId, (int)$participant['user_id']]);
        }
        $message = corechat_create_rate_limited_message($pdo, $channel, $messageType, [
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
        database_transaction_commit($pdo, $transaction);
        return $message + ['server_media_id' => $assetPublicId, 'delivery' => 'server'];
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
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
        if ($error instanceof CorechatUploadedMediaReplyException) {
            json_out(['error' => $error->getMessage()], $error->httpStatus);
        }
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
    try {
        $message = uploaded_media_message($pdo, 'game', $isVoiceNote ? 'voice_note' : 'file', $participant, $authorContext, $publicPath, $file, $mimeType, $isVoiceNote ? 'Voice Note' : $originalName, $replyTo, $replyToJson, (string)$serverAsset['id'], [
            'lobby_code' => (string)($_POST['lobby_code'] ?? ''),
            'game_room_session_id' => $sessionId,
            'game_actor_user_id' => $validatedGameUserId,
        ]);
    } catch (CorechatUploadedMediaGameException $error) {
        json_out(['error' => $error->getMessage()], $error->httpStatus);
    }
    json_out($message);
}

json_out(uploaded_media_message($pdo, 'room', $isVoiceNote ? 'voice_note' : 'file', $participant, $authorContext, $publicPath, $file, $mimeType, $isVoiceNote ? 'Voice Note' : $originalName, $replyTo, $replyToJson, (string)$serverAsset['id'], [
    'session_id' => $sessionId,
]));


