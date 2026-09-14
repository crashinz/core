<?php
require_once __DIR__ . '/_auth.php';
$pdo = db();
function game_compatibility_control_checkpoint(PDO $pdo, array $auth, mixed $throughValue): array
{
    if ((!is_string($throughValue) && !is_int($throughValue))
        || !preg_match('/^[1-9][0-9]{0,15}$/D', (string)$throughValue)
        || (strlen((string)$throughValue) === 16 && strcmp((string)$throughValue, '9007199254740991') > 0)) {
        throw new MultiplayerGameException('The game checkpoint is invalid.', 'MULTIPLAYER_GAME_REQUEST_INVALID', 422);
    }
    $through = (int)$throughValue;
    $lobby = (string)$auth['lobby'];
    $participantId = (int)$auth['participant']['id'];
    $join = $pdo->prepare('SELECT payload FROM game_moves WHERE lobby_code=? AND sequence=? AND user_id=? LIMIT 1');
    $join->execute([$lobby, $through, $participantId]);
    $joinPayload = $join->fetchColumn();
    $join->closeCursor();
    $joinMove = is_string($joinPayload) ? json_decode($joinPayload, true) : null;
    if (!is_array($joinMove) || ($joinMove['type'] ?? null) !== 'join') {
        throw new MultiplayerGameException('The game checkpoint is invalid.', 'MULTIPLAYER_GAME_REQUEST_INVALID', 422);
    }
    $type = db_uses_mysql_syntax($pdo)
        ? "JSON_UNQUOTE(CASE WHEN JSON_VALID(payload) THEN JSON_EXTRACT(payload, '$.type') ELSE NULL END)"
        : "CASE WHEN json_valid(payload) THEN json_extract(payload, '$.type') ELSE NULL END";
    $latest = $pdo->prepare("SELECT sequence, $type AS control_type FROM game_moves WHERE lobby_code=? AND sequence<=? AND $type IN ('game_over','restart') ORDER BY sequence DESC LIMIT 1");
    $latest->execute([$lobby, $through]);
    $control = $latest->fetch(PDO::FETCH_ASSOC);
    $latest->closeCursor();
    return [
        'version' => 1,
        'lobbyCode' => $lobby,
        'throughSequence' => $through,
        'control' => $control ? ['sequence' => (int)$control['sequence'], 'type' => (string)$control['control_type']] : null,
    ];
}

function game_compatibility_tetris_number(mixed $value, bool $zero = false): int
{
    if ((!is_int($value) && !is_string($value)) || !preg_match($zero ? '/^(0|[1-9][0-9]{0,15})$/D' : '/^[1-9][0-9]{0,15}$/D', (string)$value)
        || (strlen((string)$value) === 16 && strcmp((string)$value, '9007199254740991') > 0)) {
        throw new MultiplayerGameException('The local board checkpoint is invalid.', 'MULTIPLAYER_GAME_REQUEST_INVALID', 422);
    }
    return (int)$value;
}

function game_compatibility_tetris_marker(array $row): array
{
    $sequence = game_compatibility_tetris_number($row['sequence'] ?? null);
    $payload = json_decode((string)($row['payload'] ?? ''), true);
    $keys = ['type','version','control','generation','previousSequence'];
    if (!is_array($payload) || count($payload) !== count($keys) || array_diff($keys, array_keys($payload)) !== []
        || $payload['type'] !== 'tetris-local-board' || $payload['version'] !== 1
        || !in_array($payload['control'], ['reset','topout','resign'], true)) {
        throw new MultiplayerGameException('The local board checkpoint is unavailable.', 'MULTIPLAYER_GAME_STATE_INVALID', 409);
    }
    $generation = game_compatibility_tetris_number($payload['generation']);
    $previous = game_compatibility_tetris_number($payload['previousSequence'], true);
    if ($previous >= $sequence || ($payload['control'] === 'reset' ? $generation !== $sequence : ($generation > $previous || $generation >= $sequence))) {
        throw new MultiplayerGameException('The local board checkpoint is unavailable.', 'MULTIPLAYER_GAME_STATE_INVALID', 409);
    }
    return ['sequence' => $sequence, 'payload' => $payload];
}

function game_compatibility_tetris_context(PDO $pdo, array $auth): array
{
    $session = multiplayer_game_require_member($pdo, (string)$auth['lobby'], (int)$auth['user']['id'], ['master','player']);
    $definition = multiplayer_game_definition($pdo, (string)$session['game_key']);
    if (($definition['path'] ?? '') !== 'tetris-versus' || ($definition['entry'] ?? '') !== 'tetris-versus.html'
        || !in_array((string)$session['status'], ['active','paused'], true)) {
        throw new MultiplayerGameException('The local board checkpoint is unavailable.', 'MULTIPLAYER_GAME_ACCESS_DENIED', 403);
    }
    return $session;
}

function game_compatibility_tetris_type_sql(PDO $pdo): string
{
    return db_uses_mysql_syntax($pdo)
        ? "JSON_UNQUOTE(CASE WHEN JSON_VALID(payload) THEN JSON_EXTRACT(payload, '$.type') ELSE NULL END)"
        : "CASE WHEN json_valid(payload) THEN json_extract(payload, '$.type') ELSE NULL END";
}

function game_compatibility_tetris_latest(PDO $pdo, array $auth, int $through): ?array
{
    $type = game_compatibility_tetris_type_sql($pdo);
    $stmt = $pdo->prepare("SELECT sequence,payload FROM game_moves WHERE lobby_code=? AND user_id=? AND sequence<=? AND $type='tetris-local-board' ORDER BY sequence DESC LIMIT 1");
    $stmt->execute([(string)$auth['lobby'], (int)$auth['participant']['id'], $through]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    return is_array($row) ? game_compatibility_tetris_marker($row) : null;
}

function game_compatibility_tetris_projection(string $lobby, int $through, ?array $marker): array
{
    $control = $marker['payload']['control'] ?? null;
    return ['version' => 1, 'lobbyCode' => $lobby, 'throughSequence' => $through,
        'boardGeneration' => (int)($marker['payload']['generation'] ?? 0),
        'controlSequence' => (int)($marker['sequence'] ?? 0),
        'state' => $control === 'reset' ? 'active' : ($control === 'topout' ? 'topout' : ($control === 'resign' ? 'resigned' : 'unknown'))];
}

function game_compatibility_tetris_terminal_restore(PDO $pdo, array $auth): array
{
    $session = multiplayer_game_require_member($pdo, (string)$auth['lobby'], (int)$auth['user']['id'], ['master','player']);
    $definition = multiplayer_game_definition($pdo, (string)$session['game_key']);
    if (($auth['completedRead'] ?? false) !== true
        || ($definition['path'] ?? '') !== 'tetris-versus'
        || ($definition['entry'] ?? '') !== 'tetris-versus.html'
        || (string)($session['status'] ?? '') !== 'completed') {
        throw new MultiplayerGameException('The terminal board checkpoint is unavailable.', 'MULTIPLAYER_GAME_ACCESS_DENIED', 403);
    }

    $lobby = (string)$auth['lobby'];
    $seatStmt = $pdo->prepare('SELECT user1_id,user2_id FROM game_lobbies WHERE lobby_code=? LIMIT 1');
    $seatStmt->execute([$lobby]);
    $seatRow = $seatStmt->fetch(PDO::FETCH_ASSOC);
    $seatStmt->closeCursor();
    $seats = [1 => (int)($seatRow['user1_id'] ?? 0), 2 => (int)($seatRow['user2_id'] ?? 0)];
    $viewerParticipant = (int)$auth['participant']['id'];
    $viewerSeat = array_search($viewerParticipant, $seats, true);
    if (!in_array($viewerSeat, [1, 2], true) || count(array_filter($seats)) !== 2 || $seats[1] === $seats[2]) {
        throw new MultiplayerGameException('The terminal player set is unavailable.', 'MULTIPLAYER_GAME_STATE_INVALID', 409);
    }

    $type = game_compatibility_tetris_type_sql($pdo);
    $stmt = $pdo->prepare("SELECT sequence,user_id,payload FROM game_moves WHERE lobby_code=? AND $type IN ('restart','tetris-local-board') ORDER BY sequence ASC");
    $stmt->execute([$lobby]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    $resets = [];
    $terminal = null;
    foreach ($rows as $row) {
        $sequence = game_compatibility_tetris_number($row['sequence'] ?? null);
        $payload = json_decode((string)($row['payload'] ?? ''), true);
        if (is_array($payload) && ($payload['type'] ?? null) === 'restart') {
            $resets = [];
            $terminal = null;
            continue;
        }
        $marker = game_compatibility_tetris_marker($row);
        $actor = (int)($row['user_id'] ?? 0);
        if (!in_array($actor, $seats, true)) {
            throw new MultiplayerGameException('The terminal player is invalid.', 'MULTIPLAYER_GAME_STATE_INVALID', 409);
        }
        if (($marker['payload']['control'] ?? null) === 'reset') {
            $resets[$actor] = $marker;
            continue;
        }
        $reset = $resets[$actor] ?? null;
        if ($terminal === null && is_array($reset)
            && (int)$reset['sequence'] === (int)$marker['payload']['generation']
            && (int)$reset['sequence'] === (int)$marker['payload']['previousSequence']) {
            $terminal = ['sequence' => $sequence, 'actor' => $actor, 'marker' => $marker];
        }
    }
    if ($terminal === null) {
        throw new MultiplayerGameException('The terminal board checkpoint is unavailable.', 'MULTIPLAYER_GAME_STATE_INVALID', 409);
    }
    $actorSeat = array_search((int)$terminal['actor'], $seats, true);
    return [
        'version' => 1,
        'lobbyCode' => $lobby,
        'sequence' => (int)$terminal['sequence'],
        'control' => (string)$terminal['marker']['payload']['control'],
        'actorParticipantId' => (int)$terminal['actor'],
        'actorSeat' => (int)$actorSeat,
        'viewerSeat' => (int)$viewerSeat,
        'generation' => (int)$terminal['marker']['payload']['generation'],
    ];
}

function game_compatibility_tetris_checkpoint(PDO $pdo, array $auth, mixed $throughValue): array
{
    game_compatibility_tetris_context($pdo, $auth);
    $through = game_compatibility_tetris_number($throughValue);
    $lobby = (string)$auth['lobby'];
    $actor = (int)$auth['participant']['id'];
    $join = $pdo->prepare('SELECT payload FROM game_moves WHERE lobby_code=? AND sequence=? AND user_id=? LIMIT 1');
    $join->execute([$lobby, $through, $actor]);
    $raw = $join->fetchColumn();
    $join->closeCursor();
    $payload = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($payload) || ($payload['type'] ?? null) !== 'join') {
        throw new MultiplayerGameException('The local board checkpoint is invalid.', 'MULTIPLAYER_GAME_REQUEST_INVALID', 422);
    }
    $marker = game_compatibility_tetris_latest($pdo, $auth, $through);
    $result = game_compatibility_tetris_projection($lobby, $through, $marker);
    $type = game_compatibility_tetris_type_sql($pdo);
    if ($marker === null) {
        $prior = $pdo->prepare("SELECT 1 FROM game_moves WHERE lobby_code=? AND sequence<? AND ((user_id=? AND COALESCE($type,'') NOT IN ('join','ready','leave')) OR $type='topout') LIMIT 1");
        $prior->execute([$lobby, $through, $actor]);
        $exists = $prior->fetchColumn();
        $prior->closeCursor();
        if ($exists === false) $result['state'] = 'fresh';
    } elseif ($result['state'] === 'active') {
        $peer = $pdo->prepare("SELECT 1 FROM game_moves WHERE lobby_code=? AND user_id<>? AND sequence>? AND sequence<=? AND $type='topout' LIMIT 1");
        $peer->execute([$lobby, $actor, $result['boardGeneration'], $through]);
        $exists = $peer->fetchColumn();
        $peer->closeCursor();
        if ($exists !== false) $result['state'] = 'peer-topout';
    }
    return $result;
}

function game_compatibility_tetris_replay_matches(array $payload, string $control, int $expected, int $generation, ?array $previous): bool
{
    if (($payload['control'] ?? null) !== $control || ($payload['previousSequence'] ?? null) !== $expected) return false;
    if ($control !== 'reset') return ($payload['generation'] ?? null) === $generation;
    if ($expected === 0) return $generation === 0;
    return $previous !== null && ($previous['sequence'] ?? null) === $expected
        && ($previous['payload']['generation'] ?? null) === $generation;
}

function game_compatibility_tetris_control(PDO $pdo, array $auth, array $input): array
{
    $control = $input['control'] ?? null;
    if (!in_array($control, ['reset','topout','resign'], true)) {
        throw new MultiplayerGameException('The local board control is invalid.', 'MULTIPLAYER_GAME_REQUEST_INVALID', 422);
    }
    $expected = game_compatibility_tetris_number($input['expectedSequence'] ?? null, true);
    $generation = game_compatibility_tetris_number($input['boardGeneration'] ?? null, true);
    $transaction = database_transaction_begin($pdo, true);
    try {
        multiplayer_game_lock_session($pdo, (string)$auth['lobby']);
        $session = game_compatibility_tetris_context($pdo, $auth);
        if ((string)$session['status'] !== 'active') {
            throw new MultiplayerGameException('The local board cannot change now.', 'MULTIPLAYER_GAME_STATE_STALE', 409);
        }
        $current = game_compatibility_tetris_latest($pdo, $auth, 9007199254740991);
        $currentSequence = (int)($current['sequence'] ?? 0);
        $currentGeneration = (int)($current['payload']['generation'] ?? 0);
        if ($currentSequence !== $expected) {
            $p = $current['payload'] ?? [];
            $previous = $control === 'reset' && $expected > 0
                ? game_compatibility_tetris_latest($pdo, $auth, $expected) : null;
            if (game_compatibility_tetris_replay_matches($p, $control, $expected, $generation, $previous)) {
                $result = game_compatibility_tetris_projection((string)$auth['lobby'], $currentSequence, $current);
                database_transaction_commit($pdo, $transaction);
                return $result;
            }
            throw new MultiplayerGameException('This local board changed elsewhere.', 'MULTIPLAYER_GAME_STATE_STALE', 409);
        }
        if ($generation !== $currentGeneration || ($control !== 'reset' && ($generation < 1 || ($current['payload']['control'] ?? '') !== 'reset'))) {
            throw new MultiplayerGameException('This local board changed elsewhere.', 'MULTIPLAYER_GAME_STATE_STALE', 409);
        }
        $next = $pdo->prepare('SELECT COALESCE(MAX(sequence),0)+1 FROM game_moves WHERE lobby_code=?');
        $next->execute([(string)$auth['lobby']]);
        $sequence = game_compatibility_tetris_number((int)$next->fetchColumn());
        $next->closeCursor();
        $payload = ['type' => 'tetris-local-board', 'version' => 1, 'control' => $control,
            'generation' => $control === 'reset' ? $sequence : $generation, 'previousSequence' => $expected];
        game_compatibility_record($pdo, $auth, 'legacy-move', $payload);
        $pdo->prepare('INSERT INTO game_moves (lobby_code,user_id,payload,sequence) VALUES (?,?,?,?)')
            ->execute([(string)$auth['lobby'], (int)$auth['participant']['id'], json_encode($payload), $sequence]);
        $result = game_compatibility_tetris_projection((string)$auth['lobby'], $sequence, ['sequence' => $sequence, 'payload' => $payload]);
        database_transaction_commit($pdo, $transaction);
        return $result;
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
}

function game_compatibility_move_write(PDO $pdo, array $auth, mixed $payload): int
{
    $transaction = database_transaction_begin($pdo, true);
    try {
        $lobby = (string)$auth['lobby'];
        $user = (int)$auth['participant']['id'];
        multiplayer_game_lock_session($pdo, $lobby);
        game_compatibility_record($pdo, $auth, 'legacy-move', is_array($payload) ? $payload : []);
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(sequence),0)+1 FROM game_moves WHERE lobby_code = ?');
        $stmt->execute([$lobby]);
        $seq = (int)$stmt->fetchColumn();
        $stmt->closeCursor();
        $pdo->prepare('INSERT INTO game_moves (lobby_code, user_id, payload, sequence) VALUES (?,?,?,?)')
            ->execute([$lobby, $user, json_encode($payload), $seq]);
        database_transaction_commit($pdo, $transaction);
        return $seq;
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
}

function game_compatibility_moves_error_envelope(bool $retryable): array
{
    return ['status' => $retryable ? 503 : 500, 'body' => [
        'error' => $retryable
            ? 'The game is briefly busy. Try the action again.'
            : 'The game action could not be completed.',
        'code' => $retryable ? 'MULTIPLAYER_GAME_DATABASE_BUSY' : 'MULTIPLAYER_GAME_SERVER_ERROR',
        'retryable' => $retryable,
        'retryAfterMs' => $retryable ? 500 : null,
    ]];
}

function game_compatibility_moves_failure(Throwable $error): never
{
    $response = game_compatibility_moves_error_envelope(db_is_transient_lock_error($error));
    error_log('Compatibility game request failed: ' . $response['body']['code']);
    json_out($response['body'], $response['status']);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
    $terminalRestore = ($_GET['action'] ?? null) === 'tetris-terminal-restore';
    $auth = game_compatibility_auth($pdo, $_GET, $terminalRestore);
    $lobby = (string)$auth['lobby'];
    if ($terminalRestore) {
        json_out(game_compatibility_tetris_terminal_restore($pdo, $auth));
    }
    if (($_GET['action'] ?? null) === 'tetris-board-checkpoint') {
        json_out(game_compatibility_tetris_checkpoint($pdo, $auth, $_GET['throughSequence'] ?? null));
    }
    if (($_GET['action'] ?? null) === 'control-checkpoint') {
        json_out(game_compatibility_control_checkpoint($pdo, $auth, $_GET['throughSequence'] ?? null));
    }
    $last = (int)($_GET['lastSeq'] ?? 0);
    $stmt = $pdo->prepare('SELECT sequence, user_id, payload FROM game_moves WHERE lobby_code = ? AND sequence > ? ORDER BY sequence ASC LIMIT 250');
    $stmt->execute([$lobby, $last]);
    json_out(['moves' => array_map(fn($m) => [
        'sequence' => (int)$m['sequence'],
        'user_id' => (int)$m['user_id'],
        'payload' => json_decode($m['payload'], true),
    ], $stmt->fetchAll())]);
    } catch (MultiplayerGameException $error) {
        json_out(['error' => $error->getMessage(), 'code' => $error->errorCode], $error->httpStatus);
    } catch (Throwable $error) {
        game_compatibility_moves_failure($error);
    }
}
$body = input_json();
try {
$terminalLeave = is_array($body['payload'] ?? null) && ($body['payload']['type'] ?? null) === 'leave';
$auth = game_compatibility_auth($pdo, $body, false, $terminalLeave);
$body = $auth['source'];
if (($auth['terminalNoop'] ?? false) === true) {
    json_out(['ok' => true, 'accepted' => false, 'terminal' => true]);
}
if (($body['action'] ?? null) === 'tetris-board-control') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new MultiplayerGameException('Local board controls require POST.', 'MULTIPLAYER_GAME_METHOD_NOT_ALLOWED', 405);
    }
    json_out(game_compatibility_tetris_control($pdo, $auth, $body));
}
if (is_array($body['payload'] ?? null) && (($body['payload']['type'] ?? null) === 'tetris-local-board')) {
    throw new MultiplayerGameException('Use the authenticated local board control.', 'MULTIPLAYER_GAME_REQUEST_INVALID', 422);
}
$lobby = (string)$auth['lobby'];
$user = (int)$auth['participant']['id'];
$payload = $body['payload'] ?? [];
if ($lobby === '' || $user <= 0) json_out(['error' => 'missing fields'], 400);
$seq = game_compatibility_move_write($pdo, $auth, $payload);
json_out(['ok' => true, 'sequence' => $seq]);
} catch (MultiplayerGameException $error) {
    json_out(['error' => $error->getMessage(), 'code' => $error->errorCode], $error->httpStatus);
} catch (Throwable $error) {
    game_compatibility_moves_failure($error);
}
