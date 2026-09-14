<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/base.php';

/**
 * Authenticates a transitional embedded-game request against the room owner
 * and Build 000056 membership. Existing games use this adapter until their
 * separate extension migrations; it is not a second game-session owner.
 */
function game_compatibility_request_source(array $source): array
{
    $needed = ['session_id', 'participant_id', 'join_token'];
    $missing = array_filter($needed, static fn(string $key): bool => trim((string)($source[$key] ?? '')) === '');
    if ($missing === []) return $source;

    $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $parts = $referer !== '' ? parse_url($referer) : false;
    $requestHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $refererHost = is_array($parts) ? strtolower((string)($parts['host'] ?? '')) : '';
    $requestHostOnly = preg_replace('/:\d+$/', '', $requestHost) ?? $requestHost;
    $path = is_array($parts) ? (string)($parts['path'] ?? '') : '';
    if (!is_array($parts) || $refererHost === '' || !hash_equals($requestHostOnly, $refererHost)
        || !str_contains($path, '/games/')) {
        throw new MultiplayerGameException('Authenticated game context is required.', 'MULTIPLAYER_GAME_CONTEXT_REQUIRED', 403);
    }
    parse_str((string)($parts['query'] ?? ''), $query);
    foreach ($needed as $key) {
        if (trim((string)($source[$key] ?? '')) === '' && isset($query[$key])) $source[$key] = $query[$key];
    }
    return $source;
}

function game_compatibility_auth(PDO $pdo, array $source, bool $allowCompletedRead = false, bool $allowTerminalLeave = false): array
{
    $source = game_compatibility_request_source($source);
    $lobby = trim((string)($source['lobby_code'] ?? $source['lobby_id'] ?? $source['lobby'] ?? ''));
    if ($lobby === '') throw new MultiplayerGameException('Game session is required.', 'MULTIPLAYER_GAME_SESSION_REQUIRED', 422);
    $session = $pdo->prepare('SELECT room_session_id, ended_at FROM game_sessions WHERE lobby_code=? LIMIT 1');
    $session->execute([$lobby]);
    $legacySession = $session->fetch(PDO::FETCH_ASSOC);
    $session->closeCursor();
    $roomSessionId = (int)($legacySession['room_session_id'] ?? 0);
    $legacyEnded = is_array($legacySession) && trim((string)($legacySession['ended_at'] ?? '')) !== '';
    $completedReadRequested = $allowCompletedRead
        && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'GET';
    $terminalLeaveRequested = $allowTerminalLeave
        && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST';
    $declaredRoomSessionId = resolve_session_id($pdo, $source['session_id'] ?? '');
    if ($roomSessionId < 1 || $declaredRoomSessionId !== $roomSessionId
        || ($legacyEnded && !$completedReadRequested && !$terminalLeaveRequested)) {
        throw new MultiplayerGameException('Game session is unavailable.', 'MULTIPLAYER_GAME_SESSION_UNAVAILABLE', 404);
    }
    $participant = auth_participant($pdo, $roomSessionId, (string)($source['join_token'] ?? ''));
    $declaredParticipant = (int)($source['participant_id'] ?? $source['user_id'] ?? 0);
    if ($declaredParticipant > 0 && $declaredParticipant !== (int)$participant['id']) {
        throw new MultiplayerGameException('Game participant identity changed.', 'MULTIPLAYER_GAME_PARTICIPANT_MISMATCH', 403);
    }
    $user = require_user();
    if ((int)$user['id'] !== (int)$participant['user_id']) {
        throw new MultiplayerGameException('Game authorization is denied.', 'MULTIPLAYER_GAME_ACCESS_DENIED', 403);
    }
    $framework = multiplayer_game_require_member($pdo, $lobby, (int)$user['id']);
    $frameworkStatus = (string)($framework['status'] ?? '');
    $terminalNoop = $terminalLeaveRequested && !in_array($frameworkStatus, ['active','paused'], true);
    if (($completedReadRequested && $frameworkStatus !== 'completed')
        || (!$completedReadRequested && !$terminalNoop && !in_array($frameworkStatus, ['active','paused'], true))) {
        throw new MultiplayerGameException('Game session is unavailable.', 'MULTIPLAYER_GAME_SESSION_UNAVAILABLE', 404);
    }
    $completedRead = $completedReadRequested && $frameworkStatus === 'completed';
    $gameKey = trim((string)($framework['game_key'] ?? ''));
    $definition = $gameKey !== '' ? multiplayer_game_definition($pdo, $gameKey) : [];
    if (($definition['compatibility'] ?? false) !== true) {
        throw new MultiplayerGameException(
            'This compatibility route is unavailable for the selected game.',
            'MULTIPLAYER_GAME_COMPATIBILITY_REQUIRED',
            404
        );
    }

    $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $parts = $referer !== '' ? parse_url($referer) : false;
    $requestHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $requestHostOnly = preg_replace('/:\d+$/', '', $requestHost) ?? $requestHost;
    $refererHost = is_array($parts) ? strtolower((string)($parts['host'] ?? '')) : '';
    $refererPath = is_array($parts) ? (string)($parts['path'] ?? '') : '';
    $gamePath = trim((string)($definition['path'] ?? ''), '/');
    $gameEntry = ltrim((string)($definition['entry'] ?? ''), '/');
    $expectedSuffix = '/games/' . $gamePath . '/' . $gameEntry;
    if ($refererHost === '' || !hash_equals($requestHostOnly, $refererHost)
        || $gamePath === '' || $gameEntry === '' || !str_ends_with($refererPath, $expectedSuffix)) {
        throw new MultiplayerGameException(
            'The compatibility game source does not match this session.',
            'MULTIPLAYER_GAME_COMPATIBILITY_SOURCE_MISMATCH',
            403
        );
    }
    return [
        'source' => $source,
        'lobby' => $lobby,
        'roomSessionId' => $roomSessionId,
        'participant' => $participant,
        'user' => $user,
        'framework' => $framework,
        'completedRead' => $completedRead,
        'terminalNoop' => $terminalNoop,
    ];
}

function game_compatibility_connection_scope(array $auth): ?array
{
    if (!is_array($auth['user'] ?? null) || !is_array($auth['participant'] ?? null)
        || !is_array($auth['framework'] ?? null)) return null;
    $userId = (int)($auth['user']['id'] ?? 0);
    $participantId = (int)($auth['participant']['id'] ?? 0);
    $roomSessionId = (int)($auth['roomSessionId'] ?? 0);
    $publicId = (string)($auth['lobby'] ?? '');
    $framework = $auth['framework'];
    if ($userId < 1 || $participantId < 1 || $roomSessionId < 1 || $publicId === ''
        || (int)($auth['participant']['user_id'] ?? 0) !== $userId
        || (int)($auth['participant']['session_id'] ?? 0) !== $roomSessionId
        || (string)($framework['public_id'] ?? '') !== $publicId
        || (int)($framework['source_room_session_id'] ?? 0) !== $roomSessionId
        || (string)($framework['membership_status'] ?? '') !== 'active'
        || !in_array((string)($framework['member_role'] ?? ''), ['master', 'player'], true)
        || (int)($framework['seat_number'] ?? 0) < 1
        || !in_array((string)($framework['status'] ?? ''), ['active', 'paused'], true)
        || trim((string)($framework['extension_id'] ?? '')) !== '') return null;
    return ['publicId' => $publicId, 'roomSessionId' => $roomSessionId,
        'userId' => $userId, 'participantId' => $participantId];
}

function game_compatibility_touch_connection(PDO $pdo, array $auth): bool
{
    // Called only after compatibility authentication and a successful lobby lookup.
    $scope = game_compatibility_connection_scope($auth);
    if ($scope === null) return false;
    $sessionSql = "SELECT s.id FROM multiplayer_game_sessions s
        JOIN participants p ON p.session_id=s.source_room_session_id
        WHERE s.public_id=? AND s.source_room_session_id=? AND p.id=? AND p.user_id=?
          AND s.status IN ('active','paused') AND COALESCE(s.extension_id,'')=''
          AND s.expires_at>CURRENT_TIMESTAMP";
    $memberWhere = "game_session_id IN ($sessionSql) AND user_id=? AND participant_id=?
        AND membership_status='active' AND role IN ('master','player') AND seat_number>0";
    $bindings = [$scope['publicId'], $scope['roomSessionId'], $scope['participantId'],
        $scope['userId'], $scope['userId'], $scope['participantId']];
    $staleBefore = gmdate('Y-m-d H:i:s', time() - 15);
    $current = $pdo->prepare('SELECT last_seen_at FROM multiplayer_game_members WHERE ' . $memberWhere . ' LIMIT 1');
    $current->execute($bindings);
    $member = $current->fetch();
    $current->closeCursor();
    if (!is_array($member)) return false;
    $lastSeenAt = $member['last_seen_at'] ?? null;
    if ($lastSeenAt !== null && (string)$lastSeenAt >= $staleBefore) return false;
    // Repeat every membership/session binding in the write to reject an exit,
    // room change, seat replacement or terminal transition between read and write.
    $touch = $pdo->prepare('UPDATE multiplayer_game_members SET last_seen_at=CURRENT_TIMESTAMP WHERE '
        . $memberWhere . ' AND (last_seen_at IS NULL OR last_seen_at<?)');
    $touch->execute([...$bindings, $staleBefore]);
    return $touch->rowCount() === 1;
}

function game_compatibility_record(PDO $pdo, array $auth, string $action, array $payload = []): array
{
    $fresh = multiplayer_game_require_member($pdo, (string)$auth['lobby'], (int)$auth['user']['id'], ['master','player']);
    if ((string)$fresh['status'] === 'lobby') {
        // The compatibility adapter begins existing one-player games at first
        // open and versus games after both accepted player seats are present.
        try {
            multiplayer_game_start_session($pdo, (string)$auth['lobby'], (int)$fresh['master_user_id']);
            $fresh = multiplayer_game_require_member($pdo, (string)$auth['lobby'], (int)$auth['user']['id'], ['master','player']);
        } catch (MultiplayerGameException $error) {
            if (!in_array($error->errorCode, ['MULTIPLAYER_GAME_MINIMUM_PLAYERS','MULTIPLAYER_GAME_ACCEPTANCE_REQUIRED'], true)) throw $error;
        }
    }
    if ((string)$fresh['status'] !== 'active') return ['ok' => true, 'recorded' => false, 'state' => $fresh['status']];
    return multiplayer_game_record_compatibility_action(
        $pdo,
        (string)$auth['lobby'],
        (int)$auth['user']['id'],
        'compat-' . uuid_v4(),
        (int)$fresh['state_version'],
        $action,
        $payload
    );
}
