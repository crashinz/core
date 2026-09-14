<?php
require_once __DIR__ . '/../includes/api_exception_handler.php';
$gameChatRequestId = api_install_exception_handler('game-chat', 'GAME_CHAT_SERVER_ERROR', 'Game chat could not be completed.');
define('CHATSPACE_SQLITE_POLL_REQUEST', ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET');
require_once __DIR__ . '/../includes/base.php';
require_once __DIR__ . '/../includes/message_centre.php';

try {
$user = require_user();
if ($_SERVER['REQUEST_METHOD'] === 'GET') session_write_close();
$pdo = db();
corechat_chat_post_rate_install_api_handler($pdo);
$source = $_SERVER['REQUEST_METHOD'] === 'POST' ? input_json() : $_GET;
$sessionId = resolve_session_id($pdo, $source['session_id'] ?? '');
$participant = auth_participant($pdo, $sessionId, $source['join_token'] ?? '');
if ((int)$participant['user_id'] !== (int)$user['id']) json_out(['error' => 'Unauthorized'], 403);
// Message persistence and limits use database transactions, not the login lock.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

foreach (['lobby_code', 'lobby_id', 'lobby'] as $field) {
    if (array_key_exists($field, $source) && !is_string($source[$field]) && !is_int($source[$field])) {
        json_out(['error' => 'Lobby required', 'code' => 'GAME_CHAT_LOBBY_INVALID'], 400);
    }
}
$lobby = (string)($source['lobby_code'] ?? $source['lobby_id'] ?? $source['lobby'] ?? '');
if ($lobby === '') json_out(['error' => 'Lobby required'], 400);

// Framework membership is authoritative even while legacy companion rows remain.
$frameworkBinding = null;
if (database_migration_table_exists($pdo, 'multiplayer_game_sessions')) {
    $binding = $pdo->prepare(
        "SELECT s.status,m.membership_status,m.participant_id
           FROM multiplayer_game_sessions s
           LEFT JOIN multiplayer_game_members m ON m.game_session_id=s.id AND m.user_id=?
          WHERE s.public_id=? AND s.source_room_session_id=? LIMIT 1"
    );
    $binding->execute([(int)$user['id'], $lobby, $sessionId]);
    $frameworkBinding = $binding->fetch();
    $binding->closeCursor();
    if (is_array($frameworkBinding)) {
        $membershipStatus = (string)($frameworkBinding['membership_status'] ?? '');
        if ((int)($frameworkBinding['participant_id'] ?? 0) !== (int)$participant['id']
            || !in_array($membershipStatus, ['active', 'departed'], true)) {
            json_out(['error' => 'Join or spectate the game to use game chat', 'code' => 'GAME_CHAT_ACCESS_DENIED'], 403);
        }
        if (in_array((string)$frameworkBinding['status'], ['abandoned', 'ended'], true)) {
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                json_out([
                    'messages' => [], 'typing' => [],
                    'chatLifecycle' => ['version' => 1, 'status' => 'closed', 'lobbyCode' => $lobby],
                ]);
            }
            json_out(['error' => 'This game session has ended.', 'code' => 'GAME_CHAT_SESSION_CLOSED'], 410);
        }
        if ($membershipStatus !== 'active') {
            json_out(['error' => 'Join or spectate the game to use game chat', 'code' => 'GAME_CHAT_ACCESS_DENIED'], 403);
        }
    }
}

$stmt = $pdo->prepare(
    'SELECT gl.*
       FROM game_lobbies gl
       JOIN game_sessions gs ON gs.lobby_code = gl.lobby_code
      WHERE gs.room_session_id = ? AND gl.lobby_code = ? AND gs.ended_at IS NULL AND gl.status <> "ended"
      LIMIT 1'
);
$stmt->execute([$sessionId, $lobby]);
$game = $stmt->fetch();
$stmt->closeCursor();
if (!$game && database_migration_table_exists($pdo, 'multiplayer_game_sessions')) {
    $framework = $pdo->prepare(
        "SELECT public_id AS lobby_code,status
           FROM multiplayer_game_sessions
          WHERE public_id=? AND source_room_session_id=?
            AND status IN ('lobby','active','paused','completed','forfeited') LIMIT 1"
    );
    $framework->execute([$lobby, $sessionId]);
    $game = $framework->fetch();
    $framework->closeCursor();
}
// Only authenticated, room-bound participants in a known closed game receive
// a lifecycle response. Unknown games and unauthorized callers keep their errors.
if (!$game && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $closed = $pdo->prepare(
        'SELECT 1 FROM game_lobbies gl
         JOIN game_sessions gs ON gs.lobby_code=gl.lobby_code
         WHERE gs.room_session_id=? AND gl.lobby_code=?
           AND (gs.ended_at IS NOT NULL OR gl.status="ended")
           AND (gl.user1_id=? OR gl.user2_id=?) LIMIT 1'
    );
    $closed->execute([$sessionId, $lobby, (int)$participant['id'], (int)$participant['id']]);
    $knownClosedGame = (bool)$closed->fetchColumn();
    $closed->closeCursor();
    if (!$knownClosedGame && database_migration_table_exists($pdo, 'multiplayer_game_sessions')) {
        $closed = $pdo->prepare(
            "SELECT 1 FROM multiplayer_game_sessions s
             JOIN multiplayer_game_members m ON m.game_session_id=s.id
             WHERE s.public_id=? AND s.source_room_session_id=?
               AND s.status IN ('abandoned','ended')
               AND m.user_id=? AND m.participant_id=?
               AND m.membership_status IN ('active','departed') LIMIT 1"
        );
        $closed->execute([$lobby, $sessionId, (int)$participant['user_id'], (int)$participant['id']]);
        $knownClosedGame = (bool)$closed->fetchColumn();
        $closed->closeCursor();
    }
    if ($knownClosedGame) {
        json_out([
            'messages' => [], 'typing' => [],
            'chatLifecycle' => ['version' => 1, 'status' => 'closed', 'lobbyCode' => $lobby],
        ]);
    }
}
if (!$game) json_out(['error' => 'Game not found', 'code' => 'GAME_CHAT_NOT_FOUND'], 404);
$playerIds = array_filter([(int)($game['user1_id'] ?? 0), (int)($game['user2_id'] ?? 0)]);
$frameworkMember = is_array($frameworkBinding);
if (!$frameworkMember && !in_array((int)$participant['id'], $playerIds, true)) {
    json_out(['error' => 'Join or spectate the game to use game chat'], 403);
}

$messagePayload = function(array $row) use ($pdo, $participant): array {
    $row = message_protection_project_row($row);
    $payload = [
        'id' => (int)$row['id'],
        'channel' => 'game',
        'lobby_code' => $row['lobby_code'],
        'participant_id' => (int)$row['participant_id'],
        'user_id' => (int)$row['author_user_id'],
        'display_name' => $row['author_display_name'] ?: 'Player',
        'avatar_url' => $row['webcam_path'] ?: resolve_avatar($row['avatar_path'] ?? 'preset:Default'),
        'role' => $row['role'] ?? 'user',
        'is_owner' => (bool)($row['is_owner'] ?? false),
        'content' => $row['content'],
        'message_type' => $row['message_type'] ?? 'text',
        'file_size' => $row['file_size'] !== null ? (int)$row['file_size'] : null,
        'mime_type' => $row['mime_type'] ?? null,
        'original_name' => $row['original_name'] ?? null,
        'sent_at' => $row['sent_at'],
        'protection_mode' => $row['protection_mode'],
        'protection_version' => $row['protection_version'],
        'protection_key_epoch' => $row['protection_key_epoch'],
        'protection_envelope' => $row['protection_envelope'] ?? null,
        'client_message_id' => $row['client_message_id'] ?? null,
    ];
    if (($payload['message_type'] ?? 'text') === 'gesture') {
        $payload['gesture'] = message_gesture((string)$payload['content']);
        $payload = gesture_capability_project_message_payload(
            $pdo,
            (int)$participant['user_id'],
            $payload
        );
    }
    return avatar_visibility_project_payload($pdo, (int)$participant['user_id'], $payload);
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $since = (int)($source['since_id'] ?? 0);
    $stmt = $pdo->prepare(
        'SELECT gcm.*, COALESCE(gcm.user_id, p.user_id) AS author_user_id,
                COALESCE(NULLIF(gcm.display_name, ""), p.display_name, "Player") AS author_display_name,
                p.avatar_path, p.webcam_path, u.role, 0 AS is_owner
           FROM game_chat_messages gcm
           LEFT JOIN participants p ON p.id = gcm.participant_id
           LEFT JOIN users u ON u.id = COALESCE(gcm.user_id, p.user_id)
          WHERE gcm.lobby_code = ? AND gcm.id > ?
          ORDER BY gcm.id ASC LIMIT 100'
    );
    $stmt->execute([$lobby, $since]);
    json_out([
        'messages' => array_map($messagePayload, $stmt->fetchAll()),
        'typing' => [],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);
$action = (string)($source['action'] ?? 'message');

if ($action === 'typing') {
    emit_community_event($pdo, 'game', $sessionId, $lobby, 'game_typing', [
        'lobby_code' => $lobby,
        'participant_id' => (int)$participant['id'],
        'active' => !empty($source['active']),
    ]);
    json_out(['ok' => true]);
}

$authorContext = author_context_for_participant($pdo, $sessionId, $participant);
if ($action === 'gesture') {
    $message = corechat_create_rate_limited_message($pdo, 'game', 'gesture', [
        'lobby_code' => $lobby,
        'participant' => $participant,
        'author_context' => $authorContext,
        'gesture_id' => (int)($source['gesture_id'] ?? 0),
        'request_key' => (string)($source['request_key'] ?? ''),
    ]);
    json_out(gesture_capability_project_message_payload(
        $pdo,
        (int)$participant['user_id'],
        $message
    ));
}

$content = trim((string)($source['content'] ?? ''));
$hasProtectedEnvelope = is_array($source['protection_envelope'] ?? null);
if ($content === '' && !$hasProtectedEnvelope) json_out(['error' => 'Message required'], 400);
$content = function_exists('mb_substr') ? mb_substr($content, 0, 1000) : substr($content, 0, 1000);
json_out(corechat_create_rate_limited_message($pdo, 'game', 'text', [
    'lobby_code' => $lobby,
    'participant' => $participant,
    'author_context' => $authorContext,
    'content' => $content,
    'client_message_id' => (string)($source['client_message_id'] ?? ''),
    'protection_envelope' => is_array($source['protection_envelope'] ?? null)
        ? $source['protection_envelope']
        : null,
]));
} catch (CorechatChatPostRateException $error) {
    limit_event_record_reached($pdo, 'chat_posts_per_second', 'member', 'user:' . $error->userId,
        'throttled', ['channel' => $error->channel]);
    while (ob_get_level() > 0) ob_end_clean();
    json_out(['error' => $error->getMessage()], 429);
} catch (FloodProtectionException $error) {
    auth_rate_retry_after_header($error->retryAfter);
    while (ob_get_level() > 0) ob_end_clean();
    json_out([
        'error' => $error->getMessage(),
        'code' => $error->errorCode,
        'retry_after' => $error->retryAfter,
    ], $error->httpStatus);
} catch (Throwable $error) {
    $retryable = db_is_transient_lock_error($error);
    $publicMessage = $retryable ? 'Game chat is briefly busy. Try again.' : 'Game chat could not be completed.';
    $code = $retryable ? 'MULTIPLAYER_GAME_DATABASE_BUSY' : 'GAME_CHAT_SERVER_ERROR';
    if ($retryable) header('Retry-After: 1');
    header('X-Request-ID: ' . $gameChatRequestId);
    try {
        api_exception_record($error, 'game-chat', $code, $publicMessage,
            ['route' => 'game-chat', 'requestMethod' => (string)($_SERVER['REQUEST_METHOD'] ?? '')],
            $gameChatRequestId, $pdo ?? null, isset($user['id']) ? (int)$user['id'] : null);
    } catch (Throwable $recordError) {
        error_log('Game chat diagnostic recording failed; request ' . $gameChatRequestId);
    }
    while (ob_get_level() > 0) ob_end_clean();
    json_out([
        'error' => $publicMessage,
        'code' => $code,
        'request_id' => $gameChatRequestId,
        'retryable' => $retryable,
        'retryAfterMs' => $retryable ? 500 : null,
    ], $retryable ? 503 : 500);
}
