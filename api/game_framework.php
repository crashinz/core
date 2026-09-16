<?php
declare(strict_types=1);

$gameRequestStartedAt = hrtime(true);
$gameRequestPreviousAt = $gameRequestStartedAt;
$gameRequestTiming = [];
$gameRequestMark = static function(string $stage) use (&$gameRequestPreviousAt, &$gameRequestTiming): void {
    $now = hrtime(true);
    $gameRequestTiming[] = 'cc_' . $stage . ';dur=' . number_format(max(0, $now - $gameRequestPreviousAt) / 1000000, 3, '.', '');
    $gameRequestPreviousAt = $now;
};

function game_framework_session_read_identity(array $source, int $participantId): string
{
    foreach (['game_session_id', 'public_id', 'participant_id'] as $field) {
        if (array_key_exists($field, $source) && !is_string($source[$field]) && !is_int($source[$field])) {
            throw new MultiplayerGameException('Game-session access is denied.', 'MULTIPLAYER_GAME_ACCESS_DENIED', 403);
        }
    }
    $publicId = trim((string)($source['game_session_id'] ?? $source['public_id'] ?? ''));
    if (isset($source['game_session_id'], $source['public_id'])
        && trim((string)$source['game_session_id']) !== trim((string)$source['public_id'])) {
        throw new MultiplayerGameException('Game-session access is denied.', 'MULTIPLAYER_GAME_ACCESS_DENIED', 403);
    }
    if (array_key_exists('participant_id', $source)) {
        $declaredParticipant = trim((string)$source['participant_id']);
        if (!preg_match('/^[1-9][0-9]*$/D', $declaredParticipant)
            || (string)$participantId !== $declaredParticipant) {
            throw new MultiplayerGameException('Game-session access is denied.', 'MULTIPLAYER_GAME_ACCESS_DENIED', 403);
        }
    }
    return $publicId;
}

function game_framework_session_read_binding(PDO $pdo, array $source, int $roomSessionId, int $userId, int $participantId): array
{
    $publicId = game_framework_session_read_identity($source, $participantId);
    // Keep the existing empty-session request handling; no closed outcome exists.
    if ($publicId === '') return ['closed' => false];
    $binding = $pdo->prepare(
        "SELECT s.status,s.ended_at,m.membership_status,m.departed_at
           FROM multiplayer_game_sessions s
           JOIN multiplayer_game_members m ON m.game_session_id=s.id
          WHERE s.public_id=? AND s.source_room_session_id=?
            AND m.user_id=? AND m.participant_id=? LIMIT 1"
    );
    $binding->execute([$publicId, $roomSessionId, $userId, $participantId]);
    $row = $binding->fetch();
    $binding->closeCursor();
    if (is_array($row) && (string)$row['membership_status'] === 'active') {
        // Active members retain their real completed/forfeited/ended projection.
        return ['closed' => false];
    }
    if (is_array($row) && (string)$row['status'] === 'ended' && $row['ended_at'] !== null
        && (string)$row['membership_status'] === 'departed' && $row['departed_at'] !== null) {
        return ['closed' => true];
    }
    throw new MultiplayerGameException('Game-session access is denied.', 'MULTIPLAYER_GAME_ACCESS_DENIED', 403);
}

function game_framework_record_failure(
    Throwable $error,
    string $component,
    string $code,
    string $message,
    array $context,
    string $requestId,
    ?PDO $pdo,
    ?int $userId
): void {
    try {
        api_exception_record($error, $component, $code, $message, $context, $requestId, $pdo, $userId);
    } catch (Throwable $recordError) {
        // Diagnostics must not replace the original typed API response.
        error_log('Game framework diagnostic recording failed; request ' . $requestId);
    }
}

require_once __DIR__ . '/../includes/api_exception_handler.php';
$gameFrameworkRequestCorrelationId = api_install_exception_handler(
    'game-framework',
    'MULTIPLAYER_GAME_SERVER_ERROR',
    'The game action could not be completed.',
    ['route' => 'game-framework']
);
define('CHATSPACE_SQLITE_POLL_REQUEST', ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET');
require_once __DIR__ . '/../includes/base.php';
$gameRequestMark('bootstrap');

$user = require_user();
$gameRequestMark('auth');
// Review state is session-owned and never enters the live-match dispatcher.
$reviewSource = $_SERVER['REQUEST_METHOD'] === 'POST' ? input_json() : $_GET;
if (is_string($reviewSource['game_session_id'] ?? null) && str_starts_with($reviewSource['game_session_id'], 'review-')) {
    require_once __DIR__ . '/../includes/game_review.php';
    try { json_out(game_review_dispatch(db(), $user, $reviewSource)); }
    catch (MultiplayerGameException $error) { json_out(['error'=>$error->getMessage(), 'code'=>$error->errorCode], $error->httpStatus); }
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') session_write_close();
$pdo = db();
$gameRequestMark('db');
// Recover committed replay events left by interrupted requests; never expose archive data.
game_recording_flush($pdo, 4);
$source = $reviewSource;
$action = trim((string)($source['action'] ?? ($_SERVER['REQUEST_METHOD'] === 'GET' ? 'catalog' : '')));

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'catalog') {
        json_out([
            'registryRevision' => MULTIPLAYER_GAME_REGISTRY_REVISION,
            'catalog' => multiplayer_game_with_transient_action_retry(
                $pdo, static fn(): array => multiplayer_game_catalog_projection($pdo, (int)$user['id'])
            ),
            'profiles' => [
                'one-player' => ['practice', 'settings', 'save', 'resume', 'lifecycle', 'cleanup'],
                'versus' => ['sessions', 'players', 'spectators', 'acceptance', 'authoritative-state', 'results', 'records', 'chat', 'cleanup'],
            ],
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'cleanup') {
        if ((string)($user['role'] ?? '') !== 'admin') {
            throw new MultiplayerGameException('Administrator authorization is required.', 'MULTIPLAYER_GAME_ADMIN_REQUIRED', 403);
        }
        security_require_recent_authentication();
        json_out(multiplayer_game_cleanup($pdo));
    }

    $roomSessionId = resolve_session_id($pdo, $source['session_id'] ?? '');
    $participant = auth_participant($pdo, $roomSessionId, (string)($source['join_token'] ?? ''));
    if ((int)$participant['user_id'] !== (int)$user['id']) json_out(['error' => 'Unauthorized'], 403);
    $publicId = $_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'session'
        ? game_framework_session_read_identity($source, (int)$participant['id'])
        : trim((string)($source['game_session_id'] ?? $source['public_id'] ?? ''));

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($action === 'list') {
            json_out(multiplayer_game_with_transient_action_retry($pdo, static fn(): array => [
                'catalog' => multiplayer_game_catalog_projection($pdo, (int)$user['id']),
                'sessions' => multiplayer_game_list_room_sessions($pdo, $roomSessionId, (int)$user['id']),
            ]));
        }
        if ($action === 'session' && $publicId !== '') {
            $read = multiplayer_game_with_transient_action_retry(
                $pdo,
                static function() use ($pdo, $source, $roomSessionId, $user, $participant, $publicId, $gameRequestMark): array {
                    $binding = game_framework_session_read_binding(
                        $pdo, $source, $roomSessionId, (int)$user['id'], (int)$participant['id']
                    );
                    if ($binding['closed'] === true) return ['closed' => true];
                    $gameRequestMark('authorize');
                    multiplayer_game_touch_connection($pdo, $publicId, (int)$user['id']);
                    $gameRequestMark('touch');
                    return ['closed' => false, 'projection' => multiplayer_game_project_session($pdo, $publicId, (int)$user['id'])];
                }
            );
            if ($read['closed'] === true) {
                json_out([
                    'error' => 'This game session has ended.',
                    'code' => 'MULTIPLAYER_GAME_SESSION_CLOSED',
                    'gameLifecycle' => ['version' => 1, 'status' => 'closed', 'lobbyCode' => $publicId],
                ], 410);
            }
            $projection = $read['projection'];
            $envelopeProblem = multiplayer_game_session_envelope_problem($projection);
            if ($envelopeProblem !== null) {
                game_framework_record_failure(
                    new UnexpectedValueException('Invalid game session response envelope.'),
                    'game-framework',
                    'MULTIPLAYER_GAME_SESSION_ENVELOPE_INVALID',
                    'The game action could not be completed.',
                    [
                        'route' => 'game-framework',
                        'action' => 'session',
                        'stage' => 'session-envelope-validation',
                        'requestMethod' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
                        'gameIdentityHash' => api_exception_game_hash((string)($projection['gameKey'] ?? '')),
                        'gameSessionIdentityHash' => api_exception_game_session_hash($roomSessionId, $publicId),
                    ] + $envelopeProblem,
                    $gameFrameworkRequestCorrelationId,
                    $pdo,
                    (int)$user['id']
                );
                header('X-Request-ID: ' . $gameFrameworkRequestCorrelationId);
                $response = api_exception_game_public_response(
                    'The game action could not be completed.',
                    'MULTIPLAYER_GAME_SERVER_ERROR',
                    $gameFrameworkRequestCorrelationId,
                    false,
                    null
                );
                $response['diagnostic'] = $envelopeProblem;
                while (ob_get_level() > 0) ob_end_clean();
                json_out($response, 500);
            }
            // Settlement deadlines are server-authored. Return the matching
            // server clock so clients never schedule them against a skewed
            // workstation clock.
            $projection['nowUnixMs'] = (int) floor(microtime(true) * 1000);
            $gameRequestMark('projection');
            // Fixed stage names and durations only, on this authenticated GET.
            // No identity, URL, request payload or authentication data is exposed.
            $gameRequestTiming[] = 'cc_total;dur=' . number_format(max(0, hrtime(true) - $gameRequestStartedAt) / 1000000, 3, '.', '');
            header('Server-Timing: ' . implode(', ', $gameRequestTiming), false);
            json_out($projection);
        }
        if ($action === 'resume' && $publicId !== '') {
            $saved = multiplayer_game_resume($pdo, $publicId, (int)$user['id']);
            if (in_array((string)($saved['gameKey'] ?? ''), ['g_b64p3_tetris', 'g_b64p3_space'], true)) {
                // Loading remains server-side. A browser inspecting a save gets
                // only its normal projection, never a future-piece seed or bag.
                $definition = multiplayer_game_definition($pdo, (string)$saved['gameKey'], false);
                $saved['state'] = multiplayer_game_project_extension_state(
                    $pdo, $definition, (array)($saved['state'] ?? []), (int)$user['id']
                );
                $saved['viewerProjection'] = true;
            }
            json_out($saved);
        }
        if ($action === 'options') {
            json_out(multiplayer_game_with_transient_action_retry($pdo,
                static fn(): array => multiplayer_game_options($pdo, (int)$user['id'], trim((string)($source['game_key'] ?? '')))));
        }
        if ($action === 'records') {
            json_out(multiplayer_game_with_transient_action_retry($pdo,
                static fn(): array => multiplayer_game_records($pdo, (int)$user['id'], trim((string)($source['game_key'] ?? '')))));
        }
        json_out(['error' => 'Unknown game-framework query'], 400);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);
    if ($action === 'create') {
        $creationTransaction = database_transaction_begin($pdo, true);
        try {
            flood_protection_consume($pdo, 'game-session-creation', (int)$user['id']);
            $frameworkSession = multiplayer_game_create_session(
                $pdo,
                $roomSessionId,
                $participant,
                trim((string)($source['game_key'] ?? '')),
                trim((string)($source['mode'] ?? 'practice')),
                is_array($source['settings'] ?? null) ? $source['settings'] : [],
                trim((string)($source['client_epoch'] ?? ''))
            );
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
                    'user:' . (int)$user['id'],
                    'blocked',
                    ['control' => $error->control, 'retryAfterSeconds' => $error->retryAfter]
                );
            }
            throw $error;
        }
        json_out($frameworkSession);
    }
    if ($action === 'options') {
        json_out(multiplayer_game_options(
            $pdo,
            (int)$user['id'],
            trim((string)($source['game_key'] ?? '')),
            is_array($source['options'] ?? null) ? $source['options'] : []
        ));
    }
    if ($publicId === '') throw new MultiplayerGameException('Game session is required.', 'MULTIPLAYER_GAME_SESSION_REQUIRED', 422);
    if (in_array($action, ['join','spectate'], true)) {
        $frameworkSession = multiplayer_game_with_transient_action_retry(
            $pdo,
            static function () use ($pdo, $publicId, $user, $participant, $action, $source): array {
                $transaction = database_transaction_begin($pdo, true);
                try {
                    if (!flood_protection_multiplayer_membership_exists($pdo, $publicId, (int)$user['id'])) {
                        flood_protection_consume($pdo, 'game-session-joining', (int)$user['id']);
                    }
                    $framework = multiplayer_game_join_session(
                        $pdo,
                        $publicId,
                        $participant,
                        $action === 'spectate' ? 'spectator' : 'player',
                        trim((string)($source['client_epoch'] ?? ''))
                    );
                    database_transaction_commit($pdo, $transaction);
                    return $framework;
                } catch (Throwable $error) {
                    database_transaction_rollback($pdo, $transaction);
                    // A rejected membership must not spend accepted-join budget.
                    // Its confirmed limit denial survives the owned rollback once.
                    if (!empty($transaction['owned'])
                        && $error instanceof FloodProtectionException
                        && $error->control === 'game-session-joining'
                        && $error->errorCode === 'FLOOD_PROTECTION_LIMITED') {
                        limit_event_record_reached(
                            $pdo,
                            FLOOD_GAME_SESSION_JOINING_LIMIT_SETTING,
                            'member',
                            'user:' . (int)$user['id'],
                            'blocked',
                            ['control' => $error->control, 'retryAfterSeconds' => $error->retryAfter]
                        );
                    }
                    throw $error;
                }
            }
        );
        json_out($frameworkSession);
    }
    if ($action === 'accept') {
        $frameworkSession = multiplayer_game_accept_and_start_if_ready(
            $pdo,
            $publicId,
            (int)$user['id'],
            (string)($source['settings_sha256'] ?? ''),
            isset($source['mode']) ? (string)$source['mode'] : null
        );
        if ((string)$frameworkSession['status'] === 'active') {
            $pdo->prepare('UPDATE game_lobbies SET status = "active", updated_at = CURRENT_TIMESTAMP WHERE lobby_code = ?')->execute([$publicId]);
        }
        emit_event($pdo, $roomSessionId, 'game_update', ['lobby_code' => $publicId]);
        json_out($frameworkSession);
    }
    if ($action === 'update-settings') {
        $result = multiplayer_game_update_settings(
            $pdo,
            $publicId,
            (int)$user['id'],
            is_array($source['settings'] ?? null) ? $source['settings'] : [],
            isset($source['mode']) ? (string)$source['mode'] : null
        );
        emit_event($pdo, $roomSessionId, 'game_update', ['lobby_code' => $publicId]);
        json_out($result);
    }
    if ($action === 'set-lobby-bot') {
        $result = multiplayer_game_set_lobby_bot($pdo, $publicId, (int)$user['id'], (int)($source['seat'] ?? 0), (string)($source['difficulty'] ?? ''), (string)($source['settings_sha256'] ?? ''), (string)($source['player_set_sha256'] ?? ''));
        emit_event($pdo, $roomSessionId, 'game_update', ['lobby_code' => $publicId]);
        json_out($result);
    }
    if ($action === 'choose-seat') {
        $result = multiplayer_game_choose_seat($pdo, $publicId, (int)$user['id'], (int)($source['seat'] ?? 0), (string)($source['decision'] ?? ''), (string)($source['request_id'] ?? ''));
        emit_event($pdo, $roomSessionId, 'game_update', ['lobby_code' => $publicId]);
        json_out($result);
    }
    if ($action === 'request-seat') {
        $result = multiplayer_game_request_seat($pdo, $publicId, (int)$user['id']);
        emit_event($pdo, $roomSessionId, 'game_update', ['lobby_code' => $publicId]);
        json_out($result);
    }
    if ($action === 'resolve-seat-request') {
        $result = multiplayer_game_resolve_seat_request(
            $pdo,
            $publicId,
            (int)$user['id'],
            (int)($source['requested_user_id'] ?? 0),
            trim((string)($source['decision'] ?? ''))
        );
        emit_event($pdo, $roomSessionId, 'game_update', ['lobby_code' => $publicId]);
        json_out($result);
    }
    if ($action === 'remove-spectator') {
        $result = multiplayer_game_remove_spectator(
            $pdo,
            $publicId,
            (int)$user['id'],
            (int)($source['spectator_user_id'] ?? 0),
            trim((string)($source['reason'] ?? ''))
        );
        emit_event($pdo, $roomSessionId, 'game_update', ['lobby_code' => $publicId]);
        json_out($result);
    }
    if ($action === 'start') {
        $framework = multiplayer_game_start_session($pdo, $publicId, (int)$user['id']);
        $pdo->prepare('UPDATE game_lobbies SET status = "active", updated_at = CURRENT_TIMESTAMP WHERE lobby_code = ?')->execute([$publicId]);
        emit_event($pdo, $roomSessionId, 'game_update', ['lobby_code' => $publicId]);
        json_out($framework);
    }
    if ($action === 'record-action') {
        json_out(multiplayer_game_record_compatibility_action(
            $pdo,
            $publicId,
            (int)$user['id'],
            trim((string)($source['request_id'] ?? '')),
            (int)($source['expected_version'] ?? -1),
            trim((string)($source['action_type'] ?? 'compatibility-action')),
            is_array($source['payload'] ?? null) ? $source['payload'] : []
        ));
    }
    if ($action === 'extension-action') {
        // CSRF, login and room/participant binding have already been checked.
        // Every game's mutations use database ownership, not the PHP login lock.
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        if (in_array((string)($source['action_type'] ?? ''), ['arcade-input', 'arcade-tick'], true)) {
            // base.php has already enforced POST CSRF and require_user(), and
            // the participant binding above is authenticated. Game mutation
            // is protected by database transactions, not the PHP session lock.
            $gameRequestMark('authorize');
            header_register_callback(static function () use (&$gameRequestTiming, $gameRequestStartedAt): void {
                $timing = $gameRequestTiming;
                $timing[] = 'cc_total;dur=' . number_format(max(0, hrtime(true) - $gameRequestStartedAt) / 1000000, 3, '.', '');
                header('Server-Timing: ' . implode(', ', $timing), false);
            });
        }
        $result = multiplayer_game_extension_action(
            $pdo,
            $publicId,
            (int)$user['id'],
            trim((string)($source['request_id'] ?? '')),
            (int)($source['expected_version'] ?? -1),
            trim((string)($source['action_type'] ?? '')),
            is_array($source['payload'] ?? null) ? $source['payload'] : [],
            trim((string)($source['randomness_request_id'] ?? '')),
            in_array((string)($source['action_type'] ?? ''), ['arcade-input', 'arcade-tick'], true)
                ? $gameRequestMark : null
        );
        $gameRequestMark('apply');
        if (!empty($result['terminal']) && empty($result['idempotentReplay'])) {
            emit_event($pdo, $roomSessionId, 'game_end', ['lobby_code' => $publicId]);
        }
        // Return the same viewer-safe projection used by the session query so
        // the acting client can render the accepted move without a second
        // local HTTP/database round trip.
        $result['session'] = multiplayer_game_project_session($pdo, $publicId, (int)$user['id']);
        $gameRequestMark('project');
        $result['session']['nowUnixMs'] = (int) floor(microtime(true) * 1000);
        json_out($result);
    }
    if ($action === 'randomness') {
        json_out(multiplayer_game_randomness(
            $pdo,
            $publicId,
            (int)$user['id'],
            trim((string)($source['request_id'] ?? '')),
            isset($source['commitment_sha256']) ? (string)$source['commitment_sha256'] : null,
            trim((string)($source['purpose'] ?? 'gameplay'))
        ));
    }
    if ($action === 'reveal-practice-randomness') {
        json_out(multiplayer_game_reveal_practice_randomness(
            $pdo,
            $publicId,
            (int)$user['id'],
            trim((string)($source['request_id'] ?? '')),
            $source['reveal'] ?? null
        ));
    }
    if ($action === 'complete') {
        $result = multiplayer_game_complete_session(
            $pdo,
            $publicId,
            (int)$user['id'],
            is_array($source['result'] ?? null) ? $source['result'] : []
        );
        if (empty($result['idempotentReplay'])) {
            emit_event($pdo, $roomSessionId, 'game_end', ['lobby_code' => $publicId]);
        }
        json_out($result);
    }
    if ($action === 'save') {
        json_out(multiplayer_game_save($pdo, $publicId, (int)$user['id'], (int)($source['expected_version'] ?? -1)));
    }
    if ($action === 'resume') {
        $result = multiplayer_game_restore_saved_game(
            $pdo,
            $publicId,
            (int)$user['id'],
            (int)($source['expected_version'] ?? -1)
        );
        emit_event($pdo, $roomSessionId, 'game_update', ['lobby_code' => $publicId]);
        json_out($result);
    }
    if ($action === 'disconnect') {
        $result = multiplayer_game_with_transient_action_retry(
            $pdo,
            static fn(): array => multiplayer_game_disconnect($pdo, $publicId, (int)$user['id'])
        );
        emit_event($pdo, $roomSessionId, 'game_update', ['lobby_code' => $publicId]);
        json_out($result);
    }
    if ($action === 'reconnect') {
        $result = multiplayer_game_with_transient_action_retry(
            $pdo,
            static fn(): array => multiplayer_game_reconnect(
                $pdo,
                $publicId,
                $participant,
                trim((string)($source['client_epoch'] ?? ''))
            )
        );
        emit_event($pdo, $roomSessionId, 'game_update', ['lobby_code' => $publicId]);
        json_out($result);
    }
    if ($action === 'service-interruption') {
        $result = multiplayer_game_set_service_interruption(
            $pdo,
            $publicId,
            $user,
            filter_var($source['active'] ?? false, FILTER_VALIDATE_BOOLEAN),
            isset($source['started_at']) ? (string)$source['started_at'] : null
        );
        if (!empty($result['serviceInterruptionChanged'])) {
            emit_event($pdo, $roomSessionId, 'game_update', ['lobby_code' => $publicId]);
        }
        json_out($result);
    }
    if ($action === 'vote') {
        $result = multiplayer_game_vote($pdo, $publicId, (int)$user['id'], trim((string)($source['vote_type'] ?? '')), trim((string)($source['vote'] ?? '')));
        $eventType = in_array((string)($result['resolved'] ?? ''), ['void', 'abandon', 'forfeit'], true)
            ? 'game_end'
            : 'game_update';
        emit_event($pdo, $roomSessionId, $eventType, ['lobby_code' => $publicId]);
        json_out($result);
    }
    if ($action === 'rematch') {
        $result = multiplayer_game_request_rematch($pdo, $publicId, (int)$user['id']);
        if (($result['status'] ?? '') === 'started') {
            emit_event($pdo, $roomSessionId, 'game_update', ['lobby_code' => $publicId]);
            emit_event($pdo, $roomSessionId, 'game_start', [
                'lobby_code' => (string)($result['session']['publicId'] ?? ''),
                'game_type' => (string)($result['session']['gameKey'] ?? ''),
                'started_by_id' => (int)$participant['id'],
                'started_by_name' => (string)($user['display_name'] ?? 'Player'),
            ]);
        }
        json_out($result);
    }
    if ($action === 'forfeit') {
        $result = multiplayer_game_forfeit($pdo, $publicId, (int)$user['id']);
        emit_event($pdo, $roomSessionId, 'game_update', ['lobby_code' => $publicId]);
        json_out($result);
    }
    if ($action === 'depart') {
        $result = multiplayer_game_depart($pdo, $publicId, (int)$user['id'], (string)($source['reason'] ?? 'departed'));
        $terminalStatuses = ['abandoned', 'completed', 'forfeited', 'ended'];
        $eventType = !empty($result['terminal'])
            || in_array((string)($result['status'] ?? ''), $terminalStatuses, true)
            || in_array((string)($result['masterDisposition']['status'] ?? ''), $terminalStatuses, true)
            ? 'game_end'
            : 'game_update';
        emit_event($pdo, $roomSessionId, $eventType, ['lobby_code' => $publicId]);
        json_out($result);
    }
    if ($action === 'presentation-pack') {
        json_out(multiplayer_game_set_presentation_pack(
            $pdo,
            (int)$user['id'],
            trim((string)($source['game_key'] ?? '')),
            trim((string)($source['pack_id'] ?? ''))
        ));
    }
    json_out(['error' => 'Unknown game-framework action'], 400);
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
    $response = ['error' => $error->getMessage(), 'code' => $error->errorCode] + $error->facts;
    if ($error->httpStatus >= 500) {
        game_framework_record_failure(
            $error,
            'game-framework',
            $error->errorCode,
            'The game action could not be completed.',
            [
                'route' => 'game-framework',
                'action' => (string)($action ?? 'unknown'),
                'stage' => (($action ?? '') === 'session') ? 'session-projection' : 'action-dispatch',
                'requestMethod' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
                'gameIdentityHash' => api_exception_game_hash((string)($source['game_key'] ?? '')),
                'gameSessionIdentityHash' => api_exception_game_session_hash((int)($roomSessionId ?? 0), (string)($publicId ?? '')),
            ],
            $gameFrameworkRequestCorrelationId,
            $pdo ?? null,
            (int)($user['id'] ?? 0)
        );
        header('X-Request-ID: ' . $gameFrameworkRequestCorrelationId);
        $response['request_id'] = $gameFrameworkRequestCorrelationId;
    }
    while (ob_get_level() > 0) ob_end_clean();
    json_out($response, $error->httpStatus);
} catch (Throwable $error) {
    $retryable = db_is_transient_lock_error($error);
    $publicMessage = $retryable
        ? 'The game is briefly busy. Its latest state has been restored; try the action again.'
        : 'The game action could not be completed.';
    $code = $retryable ? 'MULTIPLAYER_GAME_DATABASE_BUSY' : 'MULTIPLAYER_GAME_SERVER_ERROR';
    game_framework_record_failure(
        $error,
        'game-framework',
        $code,
        $publicMessage,
        [
            'route' => 'game-framework',
            'action' => (string)($action ?? 'unknown'),
            'stage' => (($action ?? '') === 'session') ? 'session-projection' : 'action-dispatch',
            'requestMethod' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
            'gameIdentityHash' => api_exception_game_hash((string)($source['game_key'] ?? '')),
            'gameSessionIdentityHash' => api_exception_game_session_hash((int)($roomSessionId ?? 0), (string)($publicId ?? '')),
        ],
        $gameFrameworkRequestCorrelationId,
        $pdo ?? null,
        (int)($user['id'] ?? 0)
    );
    header('X-Request-ID: ' . $gameFrameworkRequestCorrelationId);
    while (ob_get_level() > 0) ob_end_clean();
    json_out(api_exception_game_public_response(
        $publicMessage,
        $code,
        $gameFrameworkRequestCorrelationId,
        $retryable,
        $retryable ? 500 : null
    ), $retryable ? 503 : 500);
}
