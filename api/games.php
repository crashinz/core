<?php
require_once __DIR__ . '/../includes/api_exception_handler.php';
$gameMutationRequestId = api_install_exception_handler(
    'room-mutation', 'MULTIPLAYER_GAME_REQUEST_FAILED', 'The game request could not be completed.'
);
require_once __DIR__ . '/../includes/base.php';
$pdo = db();

function game_catalog(): array {
    $catalog = [];
    foreach (multiplayer_game_registry() as $key => $definition) {
        $catalog[$key] = (int)$definition['gameId'];
    }
    return $catalog;
}

function game_api_read_with_retry(callable $read) {
    $lastError = null;
    for ($attempt = 0; $attempt < 4; $attempt++) {
        try {
            return $read();
        } catch (Throwable $error) {
            $lastError = $error;
            if (!db_is_transient_lock_error($error) || $attempt === 3) throw $error;
            usleep(25000 * ($attempt + 1));
        }
    }
    throw $lastError ?: new RuntimeException('Game catalog read failed.');
}

function game_room_projection_candidates(array $rows, array $currentPlayerSessions): array {
    // Current-session bindings decide which game may auto-open for a user,
    // not which valid room lobbies other users may discover and join.
    // Availability remains authoritative in the projection checks below.
    return array_values($rows);
}

function game_auth(PDO $pdo, int $sessionId, int $participantId, string $token): array {
    $p = auth_participant($pdo, $sessionId, $token);
    if ((int)$p['id'] !== $participantId) json_out(['error' => 'Unauthorized'], 403);
    return $p;
}

function game_catalog_recovery_projection(PDO $pdo): array {
    $catalog = [];
    foreach (multiplayer_game_registry() as $key => $definition) {
        try {
            if (!multiplayer_game_validate_extension_metadata($definition)) continue;
            $extensionId = trim((string)($definition['extensionId'] ?? ''));
            $enabled = $extensionId !== ''
                ? first_party_extension_enabled($pdo, $extensionId)
                : app_setting($pdo, multiplayer_game_setting_key((string)$key), '1') === '1';
            $catalog[] = array_replace($definition, [
                'name' => (string)($definition['name'] ?? $key),
                'enabled' => $enabled,
                'settingsControls' => ['controls' => []],
                'defaultSettings' => [],
                'catalogRecovered' => true,
            ]);
        } catch (Throwable $error) {
            if (db_is_transient_lock_error($error)) throw $error;
            error_log('Game catalog entry recovery failed for ' . (string)$key . ': ' . $error->getMessage());
        }
    }
    return $catalog;
}

function game_end_abandoned_sessions_for_user(PDO $pdo, int $roomSessionId, int $userId): void {
    $sessions = $pdo->prepare(
        "SELECT DISTINCT gs.lobby_code
           FROM game_sessions gs
           JOIN multiplayer_game_sessions mgs ON mgs.public_id=gs.lobby_code
           JOIN multiplayer_game_members mgm ON mgm.game_session_id=mgs.id
          WHERE gs.room_session_id=?
            AND gs.ended_at IS NULL
            AND mgs.status IN ('lobby','active','paused')
            AND mgm.user_id=?
            AND mgm.membership_status='active'"
    );
    $sessions->execute([$roomSessionId, $userId]);
    foreach ($sessions->fetchAll(PDO::FETCH_COLUMN) as $lobby) {
        try {
            $projection = multiplayer_game_room_projection($pdo, (string)$lobby, $roomSessionId, $userId);
        } catch (Throwable $error) {
            error_log('Abandoned game projection failed for ' . (string)$lobby . ': ' . $error->getMessage());
            continue;
        }
        if (!is_array($projection) || (int)($projection['onlinePlayerCount'] ?? 0) > 0) continue;
        $pdo->prepare('UPDATE game_lobbies SET status = "ended", updated_at = CURRENT_TIMESTAMP WHERE lobby_code = ?')->execute([$lobby]);
        $pdo->prepare('UPDATE game_sessions SET ended_at = CURRENT_TIMESTAMP WHERE room_session_id = ? AND lobby_code = ?')->execute([$roomSessionId, $lobby]);
        $pdo->prepare("UPDATE multiplayer_game_sessions SET status='ended',ended_at=COALESCE(ended_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP WHERE public_id=?")->execute([$lobby]);
        $pdo->prepare('DELETE FROM game_moves WHERE lobby_code = ?')->execute([$lobby]);
        $pdo->prepare('DELETE FROM game_state WHERE lobby_code = ?')->execute([$lobby]);
        $pdo->prepare('DELETE FROM game_chat_messages WHERE lobby_code = ?')->execute([$lobby]);
        emit_event($pdo, $roomSessionId, 'game_end', ['lobby_code' => (string)$lobby, 'reason' => 'replaced-abandoned-game']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sessionId = resolve_session_id($pdo, $_GET['session_id'] ?? '');
    $viewer = game_auth($pdo, $sessionId, (int)($_GET['participant_id'] ?? 0), (string)($_GET['join_token'] ?? ''));
    $gameApiStage = 'catalog-projection';
    set_exception_handler(static function(Throwable $error) use (&$gameApiStage): void {
        error_log('Authenticated game API failure at ' . $gameApiStage . ': ' . $error->getMessage());
        json_out([
            'error' => 'Game catalog request failed',
            'stage' => $gameApiStage,
            'failureType' => get_class($error),
        ], 500);
    });
    register_shutdown_function(static function() use (&$gameApiStage): void {
        $error = error_get_last();
        if (!is_array($error) || !in_array((int)($error['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
        error_log('Authenticated game API fatal failure at ' . $gameApiStage . ': ' . (string)($error['message'] ?? 'unknown fatal error'));
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'error' => 'Game catalog request failed',
            'stage' => $gameApiStage,
            'failureType' => 'FatalError',
        ]);
    });
    $catalogRecovered = false;
    $catalogProjection = [];
    if (($_GET['view'] ?? '') !== 'room') {
        try {
            $catalogProjection = game_api_read_with_retry(
                static fn() => multiplayer_game_catalog_projection($pdo, (int)$viewer['user_id'])
            );
        } catch (Throwable $error) {
            error_log('Full game catalog projection failed: ' . $error->getMessage());
            $gameApiStage = 'catalog-recovery';
            $catalogProjection = game_api_read_with_retry(
                static fn() => game_catalog_recovery_projection($pdo)
            );
            $catalogRecovered = true;
        }
    }
    $gameApiStage = 'authenticated-game-list';
    set_exception_handler(static function(Throwable $error) use ($catalogProjection): void {
        error_log('Authenticated game catalog recovery: ' . $error->getMessage());
        json_out([
            'catalog' => $catalogProjection,
            'games' => [],
            'recentGames' => [],
            'catalogRecovered' => true,
        ]);
    });
    $stmt = $pdo->prepare(
        'SELECT a.lobby_code, a.game_type, a.started_by_participant_id, p.display_name AS started_by_name,
                gl.user1_id, gl.user2_id, gl.round_number,
                p1.user_id AS user1_user_id, p1.display_name AS user1_name, p1.avatar_path AS user1_avatar, p1.webcam_path AS user1_webcam,
                p2.user_id AS user2_user_id, p2.display_name AS user2_name, p2.avatar_path AS user2_avatar, p2.webcam_path AS user2_webcam
         FROM game_sessions a
         JOIN game_lobbies gl ON gl.lobby_code = a.lobby_code
         JOIN multiplayer_game_sessions mgs ON mgs.public_id = a.lobby_code
         LEFT JOIN participants p ON p.id = a.started_by_participant_id
         LEFT JOIN participants p1 ON p1.id = gl.user1_id
         LEFT JOIN participants p2 ON p2.id = gl.user2_id
         WHERE a.room_session_id = ? AND a.ended_at IS NULL AND gl.status <> "ended"
           AND mgs.status IN ("lobby","active","paused")
         ORDER BY a.started_at DESC'
    );
    $stmt->execute([$sessionId]);
    $activeRows = array_map(static function(array $row): array {
        $row['room_list_kind'] = 'active';
        return $row;
    }, $stmt->fetchAll());
    $recent = $pdo->prepare(
        'SELECT a.lobby_code, a.game_type, a.started_by_participant_id, p.display_name AS started_by_name,
                gl.user1_id, gl.user2_id, gl.round_number,
                p1.user_id AS user1_user_id, p1.display_name AS user1_name, p1.avatar_path AS user1_avatar, p1.webcam_path AS user1_webcam,
                p2.user_id AS user2_user_id, p2.display_name AS user2_name, p2.avatar_path AS user2_avatar, p2.webcam_path AS user2_webcam
         FROM game_sessions a
         JOIN game_lobbies gl ON gl.lobby_code = a.lobby_code
         JOIN multiplayer_game_sessions mgs ON mgs.public_id = a.lobby_code
         JOIN multiplayer_game_members viewer_member ON viewer_member.game_session_id = mgs.id
              AND viewer_member.user_id = ? AND viewer_member.role IN ("master","player")
              AND viewer_member.membership_status = "active"
         LEFT JOIN participants p ON p.id = a.started_by_participant_id
         LEFT JOIN participants p1 ON p1.id = gl.user1_id
         LEFT JOIN participants p2 ON p2.id = gl.user2_id
         WHERE a.room_session_id = ? AND mgs.status IN ("completed","forfeited","abandoned")
         ORDER BY COALESCE(mgs.ended_at,mgs.updated_at) DESC,mgs.id DESC LIMIT 5'
    );
    $recent->execute([(int)$viewer['user_id'], $sessionId]);
    $recentRows = array_map(static function(array $row): array {
        $row['room_list_kind'] = 'recent';
        return $row;
    }, $recent->fetchAll());
    $currentPlayerSessions = multiplayer_game_room_list_current_player_sessions($pdo, $sessionId);
    $projectedGames = array_values(array_filter(array_map(function(array $r) use ($pdo, $viewer, $sessionId, $currentPlayerSessions): ?array {
        $isRecent = (string)($r['room_list_kind'] ?? 'active') === 'recent';
        try {
            $framework = multiplayer_game_room_projection(
                $pdo,
                (string)$r['lobby_code'],
                $sessionId,
                (int)$viewer['user_id']
            );
        } catch (Throwable $error) {
            error_log('Room game projection failed for ' . (string)$r['lobby_code'] . ': ' . $error->getMessage());
            return null;
        }
        $viewerCurrentPlayerSession =
            ($currentPlayerSessions[(int)$viewer['user_id']] ?? null) === (string)$r['lobby_code'];
        if (!$isRecent && is_array($framework)
            && (int)($framework['activePlayerCount'] ?? 0) > 0
            && (int)($framework['onlinePlayerCount'] ?? 0) === 0
            && !$viewerCurrentPlayerSession) {
            return null;
        }
        $frameworkPlayers = array_values(array_filter(
            (array)($framework['members'] ?? []),
            static fn(array $member): bool => in_array((string)($member['role'] ?? ''), ['master','player'], true)
                && (string)($member['membershipStatus'] ?? '') === 'active'
                && (int)($member['participantId'] ?? 0) > 0
        ));
        if ($frameworkPlayers === []) return null;
        if (!$isRecent && !$viewerCurrentPlayerSession
            && !array_filter($frameworkPlayers, static fn(array $member): bool => (bool)($member['online'] ?? false))) {
            return null;
        }
        $participantRows = [];
        $participantIds = array_values(array_unique(array_map(
            static fn(array $member): int => (int)$member['participantId'],
            $frameworkPlayers
        )));
        if ($participantIds) {
            $placeholders = implode(',', array_fill(0, count($participantIds), '?'));
            $participants = $pdo->prepare(
                "SELECT id,user_id,display_name,avatar_path,webcam_path FROM participants "
                . "WHERE session_id=? AND id IN ({$placeholders})"
            );
            $participants->execute(array_merge([$sessionId], $participantIds));
            foreach ($participants->fetchAll() as $participantRow) {
                $participantRows[(int)$participantRow['id']] = $participantRow;
            }
        }
        $players = array_map(static function(array $member) use ($participantRows): array {
            $participantId = (int)$member['participantId'];
            $participant = $participantRows[$participantId] ?? [];
            $avatarPath = $participant['avatar_path'] ?? 'preset:Default';
            return [
                'participant_id' => $participantId,
                'user_id' => (int)$member['userId'],
                'display_name' => (string)($participant['display_name'] ?? $member['displayName'] ?? 'Player'),
                'avatar_path' => $avatarPath,
                'avatar_url' => $participant['webcam_path'] ?? resolve_avatar($avatarPath),
                'seat' => (int)($member['seat'] ?? 0),
                'role' => (string)$member['role'],
                'accepted' => (bool)($member['accepted'] ?? false),
                'membershipStatus' => (string)$member['membershipStatus'],
                'reconnectDeadlineAt' => $member['reconnectDeadlineAt'] ?? null,
                'online' => (bool)($member['online'] ?? false),
            ];
        }, $frameworkPlayers);
        return [
            'lobby_code' => $r['lobby_code'],
            'game_type' => $r['game_type'],
            'started_by_id' => (int)$r['started_by_participant_id'],
            'started_by_name' => $r['started_by_name'] ?: 'Someone',
            'round_number' => max(1, (int)($r['round_number'] ?? 1)),
            'room_list_kind' => $isRecent ? 'recent' : 'active',
            'framework' => $framework,
            'players' => array_map(
                fn(array $player): array => avatar_visibility_project_payload($pdo, (int)$viewer['user_id'], $player),
                $players
            ),
        ];
    }, array_merge(game_room_projection_candidates($activeRows, $currentPlayerSessions), $recentRows)), static fn(mixed $game): bool => is_array($game)));
    $games = array_values(array_filter(
        $projectedGames,
        static fn(array $game): bool => (string)($game['room_list_kind'] ?? '') === 'active'
    ));
    $recentGames = array_values(array_filter(
        $projectedGames,
        static fn(array $game): bool => (string)($game['room_list_kind'] ?? '') === 'recent'
    ));
    json_out([
      'catalog' => $catalogProjection,
      'games' => $games,
      'recentGames' => $recentGames,
      'catalogRecovered' => $catalogRecovered,
    ]);
}

function game_exit_conflicting_sessions_for_user(
    PDO $pdo,
    int $roomSessionId,
    int $userId,
    ?string $preserveLobby = null
): array {
    $sql = "SELECT DISTINCT mgs.public_id
              FROM multiplayer_game_sessions mgs
              JOIN multiplayer_game_members mgm ON mgm.game_session_id=mgs.id
             WHERE mgs.source_room_session_id=?
               AND mgs.status IN ('lobby','active','paused')
               AND mgm.user_id=?
               AND mgm.membership_status='active'";
    $params = [$roomSessionId, $userId];
    if ($preserveLobby !== null && $preserveLobby !== '') {
        $sql .= ' AND mgs.public_id<>?';
        $params[] = $preserveLobby;
    }
    $sql .= ' ORDER BY mgs.created_at ASC,mgs.id ASC';

    $sessions = $pdo->prepare($sql);
    $sessions->execute($params);
    $events = [];
    foreach ($sessions->fetchAll(PDO::FETCH_COLUMN) as $lobby) {
        $lobby = (string)$lobby;
        $result = multiplayer_game_with_transient_action_retry(
            $pdo,
            static fn(): array => multiplayer_game_exit_session(
                $pdo,
                $lobby,
                $userId,
                'switched-game-surface'
            )
        );
        $events[] = [
            'event' => (string)($result['event'] ?? 'game_update'),
            'lobby_code' => $lobby,
        ];
    }
    return $events;
}

function game_emit_switched_session_events(PDO $pdo, int $roomSessionId, array $events): void {
    foreach ($events as $event) {
        emit_event(
            $pdo,
            $roomSessionId,
            (string)($event['event'] ?? 'game_update'),
            ['lobby_code' => (string)($event['lobby_code'] ?? '')]
        );
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = input_json();
    $action = $body['action'] ?? '';
    $sessionId = resolve_session_id($pdo, $body['session_id'] ?? '');
    $participantId = (int)($body['participant_id'] ?? 0);
    $participant = game_auth($pdo, $sessionId, $participantId, (string)($body['join_token'] ?? ''));
    try {
    if ($action === 'start') {
        $type = trim((string)($body['game_type'] ?? ''));
        $allowed = game_catalog();
        if ($type === '' || !array_key_exists($type, $allowed)) json_out(['error' => 'Unknown game'], 400);
        $creationTransaction = database_transaction_begin($pdo, true);
        try {
            flood_protection_consume($pdo, 'game-session-creation', (int)$participant['user_id']);
            game_end_abandoned_sessions_for_user($pdo, $sessionId, (int)$participant['user_id']);
            $switchedSessionEvents = game_exit_conflicting_sessions_for_user(
                $pdo,
                $sessionId,
                (int)$participant['user_id']
            );
            game_emit_switched_session_events($pdo, $sessionId, $switchedSessionEvents);
            $frameworkSession = multiplayer_game_create_session(
                $pdo,
                $sessionId,
                $participant,
                $type,
                (string)($body['mode'] ?? 'practice'),
                is_array($body['settings'] ?? null) ? $body['settings'] : [],
                (string)($body['client_epoch'] ?? '')
            );
            $lobby = (string)$frameworkSession['publicId'];
            $pdo->prepare('INSERT INTO game_sessions (room_session_id, game_type, lobby_code, started_by_participant_id) VALUES (?,?,?,?)')->execute([$sessionId, $type, $lobby, $participantId]);
            $pdo->prepare('INSERT INTO game_lobbies (lobby_code, game_id, user1_id, status) VALUES (?,?,?,?)')->execute([$lobby, $allowed[$type], $participantId, 'waiting']);
            $frameworkSession = multiplayer_game_with_transient_action_retry(
                $pdo,
                static function() use ($pdo, $lobby, $participant, $frameworkSession): array {
                    $transaction = database_transaction_begin($pdo, true);
                    try {
                        $acceptedFramework = multiplayer_game_accept_and_start_if_ready(
                            $pdo,
                            $lobby,
                            (int)$participant['user_id'],
                            (string)($frameworkSession['settingsSha256'] ?? ''),
                            (string)($frameworkSession['mode'] ?? 'practice')
                        );
                        $lobbyStatus = (string)($acceptedFramework['status'] ?? '') === 'active' ? 'active' : 'waiting';
                        $pdo->prepare('UPDATE game_lobbies SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE lobby_code = ?')->execute([$lobbyStatus, $lobby]);
                        database_transaction_commit($pdo, $transaction);
                        return $acceptedFramework;
                    } catch (Throwable $error) {
                        database_transaction_rollback($pdo, $transaction);
                        throw $error;
                    }
                }
            );
            $name = $pdo->query('SELECT display_name FROM participants WHERE id = ' . $participantId)->fetchColumn() ?: 'Someone';
            emit_event($pdo, $sessionId, 'game_start', ['lobby_code' => $lobby, 'game_type' => $type, 'started_by_id' => $participantId, 'started_by_name' => $name]);
            emit_event($pdo, $sessionId, 'game_update', ['lobby_code' => $lobby]);
            database_transaction_commit($pdo, $creationTransaction);
        } catch (Throwable $error) {
            database_transaction_rollback($pdo, $creationTransaction);
            // The limiter borrowed this transaction; persist its confirmed denial
            // exactly once after the API owner rolls back the attempted creation.
            if (!empty($creationTransaction['owned'])
                && $error instanceof FloodProtectionException
                && $error->control === 'game-session-creation'
                && $error->errorCode === 'FLOOD_PROTECTION_LIMITED') {
                limit_event_record_reached(
                    $pdo,
                    FLOOD_GAME_SESSION_CREATION_LIMIT_SETTING,
                    'member',
                    'user:' . (int)$participant['user_id'],
                    'blocked',
                    ['control' => $error->control, 'retryAfterSeconds' => $error->retryAfter]
                );
            }
            throw $error;
        }
        json_out([
            'ok' => true,
            'lobby_code' => $lobby,
            'game_type' => $type,
            'started_by_id' => $participantId,
            'started_by_name' => $name,
            'framework' => $frameworkSession,
        ]);
    }
    if ($action === 'join') {
        $lobby = (string)($body['lobby_code'] ?? $body['lobby_id'] ?? $body['lobby'] ?? '');
        if ($lobby === '') json_out(['error' => 'Lobby required'], 400);
        $frameworkSession = multiplayer_game_with_transient_action_retry(
            $pdo,
            static function() use (
                $pdo,
                $sessionId,
                $lobby,
                $participant,
                $participantId,
                $body
            ): array {
                $transaction = database_transaction_begin($pdo, true);
                try {
                    $stmt = $pdo->prepare('SELECT gl.*,gs.game_type FROM game_lobbies gl JOIN game_sessions gs ON gs.lobby_code = gl.lobby_code WHERE gs.room_session_id = ? AND gl.lobby_code = ? AND gs.ended_at IS NULL LIMIT 1');
                    $stmt->execute([$sessionId, $lobby]);
                    $row = $stmt->fetch();
                    if (!$row || $row['status'] === 'ended') {
                        throw new MultiplayerGameException('Game not found.', 'MULTIPLAYER_GAME_SESSION_UNAVAILABLE', 404);
                    }
                    if (!flood_protection_multiplayer_membership_exists($pdo, $lobby, (int)$participant['user_id'])) {
                        flood_protection_consume($pdo, 'game-session-joining', (int)$participant['user_id']);
                    }
                    $definition = multiplayer_game_definition($pdo, (string)$row['game_type']);
                    $role = 'player';
                    $activePlayers = $pdo->prepare("SELECT COUNT(*) FROM multiplayer_game_members WHERE game_session_id=(SELECT id FROM multiplayer_game_sessions WHERE public_id=? LIMIT 1) AND role IN ('master','player') AND membership_status='active'");
                    $activePlayers->execute([$lobby]);
                    $existingPlayer = $pdo->prepare("SELECT 1 FROM multiplayer_game_members WHERE game_session_id=(SELECT id FROM multiplayer_game_sessions WHERE public_id=? LIMIT 1) AND user_id=? AND role IN ('master','player') AND membership_status='active' LIMIT 1");
                    $existingPlayer->execute([$lobby, (int)$participant['user_id']]);
                    if (!$existingPlayer->fetchColumn() && (int)$activePlayers->fetchColumn() >= (int)$definition['maxPlayers']) {
                        $role = 'spectator';
                    }
                    $framework = multiplayer_game_join_session(
                        $pdo,
                        $lobby,
                        $participant,
                        $role,
                        (string)($body['client_epoch'] ?? '')
                    );
                    if (!$row['user1_id']) {
                        $pdo->prepare('UPDATE game_lobbies SET user1_id = ?, status = "waiting", updated_at = CURRENT_TIMESTAMP WHERE lobby_code = ?')->execute([$participantId, $lobby]);
                    } elseif (!$row['user2_id'] && (int)$row['user1_id'] !== $participantId) {
                        $pdo->prepare('UPDATE game_lobbies SET user2_id = ?, updated_at = CURRENT_TIMESTAMP WHERE lobby_code = ?')->execute([$participantId, $lobby]);
                    }
                    $lobbyStatus = (string)($framework['status'] ?? '') === 'active' ? 'active' : 'waiting';
                    $pdo->prepare('UPDATE game_lobbies SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE lobby_code = ?')->execute([$lobbyStatus, $lobby]);
                    emit_event($pdo, $sessionId, 'game_update', ['lobby_code' => $lobby]);
                    database_transaction_commit($pdo, $transaction);
                    return $framework;
                } catch (Throwable $error) {
                    database_transaction_rollback($pdo, $transaction);
                    // The limiter borrowed this transaction, so its denial event
                    // rolled back too. Persist it once after the API owner unwinds.
                    if (!empty($transaction['owned'])
                        && $error instanceof FloodProtectionException
                        && $error->control === 'game-session-joining'
                        && $error->errorCode === 'FLOOD_PROTECTION_LIMITED') {
                        limit_event_record_reached(
                            $pdo,
                            FLOOD_GAME_SESSION_JOINING_LIMIT_SETTING,
                            'member',
                            'user:' . (int)$participant['user_id'],
                            'blocked',
                            ['control' => $error->control, 'retryAfterSeconds' => $error->retryAfter]
                        );
                    }
                    throw $error;
                }
            }
        );
        $switchedSessionEvents = game_exit_conflicting_sessions_for_user(
            $pdo,
            $sessionId,
            (int)$participant['user_id'],
            $lobby
        );
        game_emit_switched_session_events($pdo, $sessionId, $switchedSessionEvents);
        json_out(['ok' => true, 'lobby_code' => $lobby]);
    }
    if ($action === 'accept') {
        $lobby = (string)($body['lobby_code'] ?? $body['lobby_id'] ?? $body['lobby'] ?? '');
        if ($lobby === '') json_out(['error' => 'Lobby required'], 400);
        $frameworkSession = multiplayer_game_with_transient_action_retry(
            $pdo,
            static function() use ($pdo, $lobby, $participant, $body, $sessionId): array {
                $transaction = database_transaction_begin($pdo, true);
                try {
                    $framework = multiplayer_game_accept_and_start_if_ready(
                        $pdo,
                        $lobby,
                        (int)$participant['user_id'],
                        (string)($body['settings_sha256'] ?? ''),
                        isset($body['mode']) ? (string)$body['mode'] : null
                    );
                    if ((string)$framework['status'] === 'active') {
                        $pdo->prepare('UPDATE game_lobbies SET status = "active", updated_at = CURRENT_TIMESTAMP WHERE lobby_code = ?')->execute([$lobby]);
                    }
                    emit_event($pdo, $sessionId, 'game_update', ['lobby_code' => $lobby]);
                    database_transaction_commit($pdo, $transaction);
                    return $framework;
                } catch (Throwable $error) {
                    database_transaction_rollback($pdo, $transaction);
                    throw $error;
                }
            }
        );
        json_out(['ok' => true, 'framework' => $frameworkSession]);
    }
    if ($action === 'close') {
        $lobby = (string)($body['lobby_code'] ?? $body['lobby_id'] ?? $body['lobby'] ?? '');
        if ($lobby === '') json_out(['error' => 'Lobby required'], 400);
        $stmt = $pdo->prepare('SELECT lobby_code FROM game_sessions WHERE room_session_id = ? AND lobby_code = ? LIMIT 1');
        $stmt->execute([$sessionId, $lobby]);
        if (!$stmt->fetchColumn()) json_out(['error' => 'Game not found'], 404);
        $result = multiplayer_game_exit_session($pdo, $lobby, (int)$participant['user_id'], 'closed-game-surface');
        emit_event($pdo, $sessionId, (string)$result['event'], ['lobby_code' => $lobby]);
        json_out($result);
    }
    } catch (FloodProtectionException $error) {
        auth_rate_retry_after_header($error->retryAfter);
        while (ob_get_level() > 0) ob_end_clean();
        json_out([
            'error' => $error->getMessage(),
            'code' => $error->errorCode,
            'retry_after' => $error->retryAfter,
            'control' => $error->control,
        ], $error->httpStatus);
    } catch (MultiplayerGameException $error) {
        while (ob_get_level() > 0) ob_end_clean();
        json_out(['error' => $error->getMessage(), 'code' => $error->errorCode] + $error->facts, $error->httpStatus);
    } catch (Throwable $error) {
        error_log(sprintf(
            'Room game API failure during %s: %s: %s',
            $action !== '' ? $action : 'unknown action',
            get_class($error),
            $error->getMessage()
        ));
        $busy = db_is_transient_lock_error($error);
        $publicMessage = $busy ? 'The game is busy. Please try again.' : 'The game request could not be completed.';
        $code = $busy ? 'MULTIPLAYER_GAME_DATABASE_BUSY' : 'MULTIPLAYER_GAME_REQUEST_FAILED';
        try {
            api_exception_record($error, 'room-mutation', $code, $publicMessage,
                ['route' => 'api', 'action' => (string)$action, 'stage' => 'mutation', 'requestMethod' => 'POST'],
                $gameMutationRequestId, $pdo, (int)($participant['user_id'] ?? 0));
        } catch (Throwable) {
            error_log('Room game diagnostic recording failed; request ' . $gameMutationRequestId);
        }
        header('X-Request-ID: ' . $gameMutationRequestId);
        if ($busy) header('Retry-After: 1');
        while (ob_get_level() > 0) ob_end_clean();
        json_out([
            'error' => $publicMessage,
            'code' => $code,
            'request_id' => $gameMutationRequestId,
            'retryable' => $busy,
            'retryAfterMs' => $busy ? 500 : null,
        ], $busy ? 503 : 500);
    }
}

json_out(['error' => 'Bad request'], 400);
