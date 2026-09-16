<?php
declare(strict_types=1);

/** Multiplayer Game Framework replay owner. Never included in player projections. */
const GAME_RECORDING_FORMAT = 'corechat-game-replay';
const GAME_RECORDING_VERSION = 1;
const GAME_RECORDING_GAMES = ['spades', 'hearts', 'checkers', 'chess', 'backgammon', 'acey-deucy', 'battleship', 'chinese-checkers', 'uno', 'nested-four', 'blackjack', 'puppy-panic', 'five-dice'];
const GAME_RECORDING_EVENT_BYTES = 4194304;
const GAME_RECORDING_QUEUE_BYTES = 33554432;

function game_recording_settings(): array
{
    $base = ['categoryId' => 'rooms-games', 'subsectionId' => 'game-recording',
        'subsectionLabel' => 'Game Recording', 'subsectionOrder' => 15, 'setupVisible' => true];
    $entries = [];
    $entries[] = $base + ['id' => 'game_recording_enabled', 'settingKey' => 'game_recording_enabled',
        'label' => 'Record games', 'type' => 'boolean', 'defaultValue' => true, 'order' => 1,
        'description' => 'Automatically save supported games for later review.',
        'helpText' => 'Records player names, stable account IDs, moves and scores. Hidden cards and fleets remain in protected server storage. Chat messages and IP addresses are excluded. Turning this off leaves existing recordings available.'];
    $entries[] = $base + ['id' => 'game_recording_storage_mb', 'settingKey' => 'game_recording_storage_mb',
        'label' => 'Recording storage (MB)', 'type' => 'number', 'defaultValue' => 1024,
        'minimum' => 16, 'maximum' => 1048576, 'step' => 1, 'order' => 2,
        'description' => 'Recording pauses at this limit; games keep working.',
        'helpText' => 'Existing recordings are never automatically deleted. Export and delete closed recordings to free space. A bounded database queue temporarily holds events awaiting file storage.'];
    $entries[] = $base + ['id' => 'game_recording_part_kb', 'settingKey' => 'game_recording_part_kb',
        'label' => 'Recording file part size (KB)', 'type' => 'number', 'defaultValue' => 1024,
        'minimum' => 64, 'maximum' => 4096, 'step' => 1, 'order' => 3,
        'description' => 'Large games continue in numbered compressed files.',
        'helpText' => 'Size before compression. A single game event stays together and can exceed the selected part size.'];
    foreach (GAME_RECORDING_GAMES as $i => $game) {
        $key = 'game_recording_' . str_replace('-', '_', $game);
        $entries[] = $base + ['id' => $key, 'settingKey' => $key, 'label' => ucwords(str_replace('-', ' ', $game)),
            'type' => 'boolean', 'defaultValue' => true, 'order' => 10 + $i,
            'description' => 'Include this game when game recording is enabled.',
            'helpText' => 'Applies to Practice and Recorded games. Resuming recording during a game is marked as a partial record.'];
    }
    return $entries;
}

function game_recording_enabled(PDO $pdo, string $game): bool
{
    return in_array($game, GAME_RECORDING_GAMES, true)
        && app_setting($pdo, 'game_recording_enabled', '1') === '1'
        && app_setting($pdo, 'game_recording_' . str_replace('-', '_', $game), '1') === '1';
}

function game_recording_json(array $value): string
{
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
}

/** Restrict keys at the adapter boundary, including nested unsolicited secrets. */
function game_recording_clean(mixed $value, int $depth = 0): mixed
{
    if ($depth > 32) throw new RuntimeException('Recording structure is too deep.');
    if (!is_array($value)) return is_scalar($value) || $value === null ? $value : null;
    $out = [];
    foreach ($value as $key => $item) {
        if (is_string($key) && preg_match('/(?:password|cookie|credential|authorization|token|chat|message|ipaddress|ip_address|remote_addr|email)/i', $key)) continue;
        $out[$key] = game_recording_clean($item, $depth + 1);
    }
    return $out;
}

function game_recording_pick(array $value, array $keys): array
{
    return game_recording_clean(array_intersect_key($value, array_fill_keys($keys, true)));
}

function game_recording_adapter(string $game): ?array
{
    if (!in_array($game, GAME_RECORDING_GAMES, true)) return null;
    $function = str_replace('-', '_', $game) . '_recording_adapter';
    if (!function_exists($function)) require_once __DIR__ . '/' . str_replace('-', '_', $game) . '_extension.php';
    return $function();
}

function game_recording_state(string $game, array $state): array
{
    $adapter = game_recording_adapter($game);
    if ($adapter === null) return [];
    $result = game_recording_pick($state, $adapter['stateKeys']);
    // Shared timing/votes are useful to replay lifecycle, but never copy members or room context.
    if (isset($state['_framework'])) $result['_framework'] = game_recording_pick((array)$state['_framework'], ['schemaVersion', 'players', 'pause', 'inactivity', 'timing', 'serviceInterruption', 'disconnectClaim']);
    return $result;
}

function game_recording_step(string $game, array $before, int $actor, string $action, array $payload, array $after, array $context, ?array $trace = null): array
{
    $adapter = game_recording_adapter($game);
    $step = ['actorId' => $actor, 'actorType' => $actor < 0 ? 'bot' : 'human', 'action' => $action,
        'payload' => game_recording_pick($payload, $adapter['payloadKeys'] ?? []),
        'before' => game_recording_state($game, $before), 'after' => game_recording_state($game, $after)];
    $step['randomness'] = game_recording_pick((array)($context['authoritativeRandomness'] ?? []), ['deck', 'dice', 'bytes', 'initialDealerIndex', 'dealerIndex', 'seed', 'reshuffleSeed', 'starterIndex', 'openingRolls', 'shoe', 'readySeed', 'starterOffset']);
    if ($game === 'five-dice' && isset($context['randomnessRequestId'])) $step['randomness']['requestId'] = (string)$context['randomnessRequestId'];
    if (isset($context['nowUnixMs'])) $step['nowUnixMs'] = (int)$context['nowUnixMs'];
    if ($trace !== null) $step['botDecision'] = game_recording_pick($trace,
        ['engine', 'difficulty', 'legal', 'selected', 'reason', 'candidateScores', 'publicObservation', 'elapsedMs', 'bid', 'candidates', 'returning', 'cardCount']);
    return $step;
}

/** Capture is best effort, but failures are explicitly visible and never become complete replay claims. */
function game_recording_capture_core(PDO $pdo, string $publicId, string $kind, array $details = []): void
{
    {
        $stmt = $pdo->prepare('SELECT * FROM multiplayer_game_sessions WHERE public_id=?');
        $stmt->execute([$publicId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($session)) return;
        $definition = multiplayer_game_definition($pdo, (string)$session['game_key'], false);
        $game = game_recording_game((string)($definition['extensionId'] ?? ''));
        if (!in_array($game, GAME_RECORDING_GAMES, true)) return;
        $id = hash('sha256', $publicId);
        $record = $pdo->prepare('SELECT * FROM multiplayer_game_recordings WHERE id=?');
        $record->execute([$id]);
        $row = $record->fetch(PDO::FETCH_ASSOC);
        if (is_array($row) && (int)$row['deleted'] !== 0) return;
        // Lobby reconnects can create shared state before the final players and
        // bot choices exist. Start the archive with the actual initial game.
        if (!is_array($row) && $session['status'] === 'lobby' && empty($session['started_at'])) return;
        if (!game_recording_enabled($pdo, $game)) {
            if (is_array($row)) $pdo->prepare("UPDATE multiplayer_game_recordings SET gap_count=gap_count+1,error_code='recording-disabled',status=?,last_version=? WHERE id=?")
                ->execute([$session['status'], (int)$session['state_version'], $id]);
            return;
        }
        $state = json_decode((string)$session['state_json'], true) ?: [];
        if ($state === []) return;
        $version = (int)$session['state_version'];
        // Lifecycle synchronization can be safely repeated after retries and during cleanup.
        $eventKey = hash('sha256', $kind . ':' . $version . ':' . $session['status'] . ':' . ($details['identity'] ?? ''));
        if (is_array($row) && (string)$row['last_event_key'] === $eventKey) return;
        if (!is_array($row)) {
            $players = $pdo->prepare('SELECT m.user_id,u.display_name,m.seat_number FROM multiplayer_game_members m JOIN users u ON u.id=m.user_id WHERE m.game_session_id=? AND m.role IN (\'master\',\'player\') ORDER BY m.seat_number');
            $players->execute([(int)$session['id']]);
            $names = [];
            foreach ($players->fetchAll(PDO::FETCH_ASSOC) as $player) $names[] = ['id' => (int)$player['user_id'], 'name' => (string)$player['display_name'], 'seat' => $player['seat_number'] === null ? null : (int)$player['seat_number'], 'type' => 'human'];
            foreach ((array)($state['bots'] ?? []) as $botId => $bot) $names[] = ['id' => (int)$botId, 'name' => (string)($bot['displayName'] ?? $bot['name'] ?? 'Bot'), 'type' => 'bot', 'difficulty' => (string)($bot['difficulty'] ?? '')];
            $files = [str_replace('-', '_', $game) . '_extension.php'];
            if ($game === 'spades') $files = array_merge($files, ['spades_bot_support.php', 'spades_bot_endgame_support.php']);
            if ($game === 'battleship') $files[] = 'battleship_bot_support.php';
            if (in_array($game, ['spades','battleship'], true)) $files[] = 'paced_bot_support.php';
            if ($game === 'chess') $files[] = 'chess_bot_support.php';
            if ($game === 'checkers') $files[] = 'checkers_bot_support.php';
            if ($game === 'five-dice') $files[] = 'five_dice_bot_support.php';
            if ($game === 'puppy-panic') $files[] = 'puppy_panic_bot_support.php';
            if ($game === 'blackjack') $files = array_merge($files, ['blackjack_bot_support.php','blackjack_expert.php']);
            if ($game === 'acey-deucy') $files[] = 'acey_deucy_bot_support.php';
            if ($game === 'backgammon') $files[] = 'backgammon_bot_support.php';
            if ($game === 'nested-four') $files[] = 'nested_four_bot_support.php';
            $hashes = [];
            foreach ($files as $file) $hashes[$file] = hash_file('sha256', __DIR__ . '/' . $file);
            $metadata = ['format' => GAME_RECORDING_FORMAT, 'formatVersion' => GAME_RECORDING_VERSION,
                'game' => $game, 'gameSessionId' => $publicId, 'mode' => $session['mode'],
                'players' => $names, 'startedAt' => $session['started_at'], 'rulesVersion' => $definition['adaptationVersion'],
                'sourceHashes' => $hashes, 'settings' => game_recording_clean(json_decode((string)$session['settings_json'], true) ?: []),
                'hiddenStatePolicy' => 'administrator-export-after-closure', 'partialStart' => $kind !== 'start'];
            $pdo->prepare('INSERT INTO multiplayer_game_recordings (id,session_public_id,game,status,metadata_json,gap_count) VALUES (?,?,?,?,?,?)')
                ->execute([$id, $publicId, $game, $session['status'], game_recording_json($metadata), $kind === 'start' ? 0 : 1]);
            $record->execute([$id]); $row = $record->fetch(PDO::FETCH_ASSOC);
        }
        if ((int)$row['event_count'] > 0 && ($version > (int)$row['last_version'] + 1 || ($kind === 'lifecycle' && $version !== (int)$row['last_version']))) {
            $pdo->prepare("UPDATE multiplayer_game_recordings SET gap_count=gap_count+1,error_code='version-gap' WHERE id=?")->execute([$id]);
        }
        $sequence = (int)$row['event_count'] + 1;
        $event = ['sequence' => $sequence, 'kind' => $kind, 'at' => gmdate('c'), 'stateVersion' => $version,
            'status' => $session['status'], 'previousHash' => (string)$row['last_hash'],
            'state' => game_recording_state($game, $state), 'steps' => $details['steps'] ?? []];
        if ($sequence === 1) $event['metadata'] = json_decode((string)$row['metadata_json'], true);
        if (!empty($session['result_public_id'])) {
            $resultQuery = $pdo->prepare('SELECT public_id,result_json,result_sha256,recorded_at FROM multiplayer_game_results WHERE public_id=?');
            $resultQuery->execute([(string)$session['result_public_id']]);
            $result = $resultQuery->fetch(PDO::FETCH_ASSOC);
            if (!is_array($result)) throw new RuntimeException('Recorded result is missing.');
            $event['recordedResult'] = ['id' => $result['public_id'], 'sha256' => $result['result_sha256'], 'recordedAt' => $result['recorded_at'],
                'result' => game_recording_pick(json_decode((string)$result['result_json'], true) ?: [], ['members', 'terminalStateVersion', 'displayNameSnapshot', 'recordClass'])];
        }
        if (isset($details['lifecycle'])) $event['lifecycle'] = game_recording_pick($details['lifecycle'], ['voteType', 'voteValue']);
        if (isset($details['actorId'])) $event['actorId'] = (int)$details['actorId'];
        if (isset($details['sourceGameSessionId'])) $event['sourceGameSessionId'] = (string)$details['sourceGameSessionId'];
        if (!empty($details['stepsIncomplete'])) $event['stepsIncomplete'] = true;
        $event['hash'] = hash('sha256', game_recording_json($event));
        $json = game_recording_json($event) . "\n";
        $reserve = $pdo->prepare('UPDATE multiplayer_game_recording_control SET queue_bytes=queue_bytes+? WHERE id=1 AND queue_bytes+?<=?');
        if (strlen($json) <= GAME_RECORDING_EVENT_BYTES) {
            // SQLite compares expression results to text parameters by storage class.
            // Bind numbers explicitly so the shared queue ceiling is enforced on both engines.
            $reserve->bindValue(1, strlen($json), PDO::PARAM_INT);
            $reserve->bindValue(2, strlen($json), PDO::PARAM_INT);
            $reserve->bindValue(3, GAME_RECORDING_QUEUE_BYTES, PDO::PARAM_INT);
            $reserve->execute();
        }
        if (strlen($json) > GAME_RECORDING_EVENT_BYTES || $reserve->rowCount() !== 1) {
            $pdo->prepare("UPDATE multiplayer_game_recordings SET gap_count=gap_count+1,error_code='queue-limit',status=? WHERE id=?")->execute([$session['status'], $id]);
            return;
        }
        $partLimit = max(65536, min(4194304, (int)app_setting($pdo, 'game_recording_part_kb', '1024') * 1024));
        $part = (int)$row['part_number'];
        $partBytes = (int)$row['part_bytes'];
        if ($partBytes > 0 && $partBytes + strlen($json) > $partLimit) { $part++; $partBytes = 0; }
        $pdo->prepare('INSERT INTO multiplayer_game_recording_events (recording_id,sequence_number,part_number,payload_json,payload_bytes) VALUES (?,?,?,?,?)')
            ->execute([$id, $sequence, $part, $json, strlen($json)]);
        $pdo->prepare('UPDATE multiplayer_game_recordings SET event_count=?,last_version=?,last_event_key=?,last_hash=?,status=?,part_number=?,part_bytes=?,gap_count=gap_count+? WHERE id=?')
            ->execute([$sequence, $version, $eventKey, $event['hash'], $session['status'], $part, $partBytes + strlen($json), !empty($details['stepsIncomplete']) ? 1 : 0, $id]);
    }
}

function game_recording_capture(PDO $pdo, string $publicId, string $kind, array $details = []): void
{
    $transaction = null;
    $savepoint = false;
    try {
        $transaction = database_transaction_begin($pdo, true);
        multiplayer_game_lock_session($pdo, $publicId);
        $pdo->exec('SAVEPOINT game_recording_capture');
        $savepoint = true;
        game_recording_capture_core($pdo, $publicId, $kind, $details);
        $pdo->exec('RELEASE SAVEPOINT game_recording_capture');
        $savepoint = false;
        $GLOBALS['game_recording_pending'][spl_object_id($pdo)] = true;
        database_transaction_commit($pdo, $transaction);
    } catch (Throwable $error) {
        try {
            if ($savepoint) {
                $pdo->exec('ROLLBACK TO SAVEPOINT game_recording_capture');
                $pdo->exec('RELEASE SAVEPOINT game_recording_capture');
            }
            if (is_array($transaction)) database_transaction_rollback($pdo, $transaction);
            $pdo->prepare("UPDATE multiplayer_game_recordings SET gap_count=gap_count+1,error_code='capture-failed' WHERE session_public_id=?")->execute([$publicId]);
            $pdo->exec("UPDATE multiplayer_game_recording_control SET capture_failures=capture_failures+1,last_error='capture-failed' WHERE id=1");
        } catch (Throwable $ignored) {}
        error_log('Game recording capture failed. Replay may be incomplete.');
    }
}

function game_recording_path(string $id, int $part): string
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $id) || $part < 1 || $part > 999999) throw new InvalidArgumentException('Invalid recording identity.');
    return security_private_storage_directory('game-recordings') . DIRECTORY_SEPARATOR . $id . '.' . sprintf('%06d', $part) . '.jsonl.gz';
}

function game_recording_lock()
{
    $file = security_private_storage_directory('game-recordings') . DIRECTORY_SEPARATOR . 'archive.lock';
    $lock = @fopen($file, 'c');
    if ($lock === false) throw new RuntimeException('Recording storage unavailable.');
    if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return null; }
    return $lock;
}

/** Only called outside the outermost game transaction. Durable queue survives disk failure. */
function game_recording_flush(PDO $pdo, int $maximumEvents = 16): void
{
    $lock = null;
    try {
        if ($pdo->inTransaction() || (function_exists('db_immediate_transaction_active') && db_immediate_transaction_active($pdo))) return;
        $pendingSql = "SELECT e.recording_id,e.sequence_number,e.part_number,e.payload_json FROM multiplayer_game_recording_events e JOIN multiplayer_game_recordings r ON r.id=e.recording_id ORDER BY CASE WHEN r.error_code='storage-unavailable' THEN 1 ELSE 0 END,e.recording_id,e.sequence_number LIMIT " . max(1, min(64, $maximumEvents));
        $pending = $pdo->query($pendingSql)->fetchAll(PDO::FETCH_ASSOC);
        if (!$pending) return;
        $lock = game_recording_lock();
        if ($lock === null) return;
        // Refresh under the lock: another request may have drained/deleted the first snapshot.
        $pending = $pdo->query($pendingSql)->fetchAll(PDO::FETCH_ASSOC);
        $root = security_private_storage_directory('game-recordings');
        $used = 0;
        foreach (new DirectoryIterator($root) as $file) if ($file->isFile() && str_ends_with($file->getFilename(), '.jsonl.gz')) $used += $file->getSize();
        $limit = max(16, (int)app_setting($pdo, 'game_recording_storage_mb', '1024')) * 1048576;
        $failedRecordings = [];
        foreach ($pending as $row) {
            $id = (string)$row['recording_id'];
            if (isset($failedRecordings[$id])) continue;
            try {
            $path = game_recording_path($id, (int)$row['part_number']);
            $oldSize = is_file($path) ? (int)filesize($path) : 0;
            $content = $oldSize ? @gzdecode((string)file_get_contents($path), GAME_RECORDING_EVENT_BYTES * 2) : '';
            if (!is_string($content)) throw new RuntimeException('Recording part failed verification.');
            $existing = false;
            foreach (explode("\n", trim($content)) as $line) {
                if ($line === '') continue;
                $event = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                if ((int)$event['sequence'] === (int)$row['sequence_number']) {
                    if ($line . "\n" !== (string)$row['payload_json']) throw new RuntimeException('Recording sequence conflict.');
                    $existing = true;
                }
            }
            if (!$existing) {
                $next = gzencode($content . $row['payload_json'], 6);
                if ($next === false) throw new RuntimeException('Recording compression unavailable.');
                if ($used - $oldSize + strlen($next) > $limit) {
                    $pdo->prepare("UPDATE multiplayer_game_recordings SET error_code='storage-limit' WHERE id=?")->execute([$id]);
                    continue;
                }
                $temporary = $path . '.tmp';
                if (@file_put_contents($temporary, $next, LOCK_EX) !== strlen($next) || !@rename($temporary, $path)) throw new RuntimeException('Recording write failed.');
                @chmod($path, 0600);
                clearstatcache(true, $path);
                $used += strlen($next) - $oldSize;
            }
            $transaction = database_transaction_begin($pdo, true);
            try {
                $pdo->prepare("UPDATE multiplayer_game_recordings SET archived_count=CASE WHEN archived_count<? THEN ? ELSE archived_count END,error_code=CASE WHEN gap_count=0 THEN '' ELSE error_code END WHERE id=?")
                    ->execute([$row['sequence_number'], $row['sequence_number'], $id]);
                $pdo->prepare('UPDATE multiplayer_game_recording_control SET queue_bytes=queue_bytes-? WHERE id=1')->execute([strlen((string)$row['payload_json'])]);
                $pdo->prepare('DELETE FROM multiplayer_game_recording_events WHERE recording_id=? AND sequence_number=?')->execute([$id, $row['sequence_number']]);
                database_transaction_commit($pdo, $transaction);
            } catch (Throwable $error) {
                database_transaction_rollback($pdo, $transaction);
                throw $error;
            }
            } catch (Throwable $error) {
                $failedRecordings[$id] = true;
                $pdo->prepare("UPDATE multiplayer_game_recordings SET error_code='storage-unavailable' WHERE id=?")->execute([$id]);
                error_log('A game recording file could not be written. Its events remain queued.');
            }
        }
    } catch (Throwable $error) {
        error_log('Game recording files could not be written. Events remain queued.');
        try { $pdo->exec("UPDATE multiplayer_game_recordings SET error_code='storage-unavailable' WHERE event_count>archived_count AND deleted=0"); } catch (Throwable $ignored) {}
    } finally {
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

function game_recording_synchronize(PDO $pdo, bool $all = false): void
{
    // Catch lifecycle owners and expiry before session cleanup can remove the source.
    try {
        $keys = []; $enabledKeys = [];
        foreach (multiplayer_game_registry() as $key => $definition) {
            $game = game_recording_game((string)($definition['extensionId'] ?? ''));
            if (!in_array($game, GAME_RECORDING_GAMES, true)) continue;
            $keys[] = $pdo->quote((string)$key);
            if (game_recording_enabled($pdo, $game)) $enabledKeys[] = $pdo->quote((string)$key);
        }
        if ($keys === []) return;
        $newRecord = $enabledKeys === [] ? '0=1' : '(r.id IS NULL AND s.game_key IN (' . implode(',', $enabledKeys) . '))';
        $sql = 'SELECT s.public_id FROM multiplayer_game_sessions s LEFT JOIN multiplayer_game_recordings r ON s.public_id=r.session_public_id WHERE s.started_at IS NOT NULL AND s.game_key IN (' . implode(',', $keys) . ') AND (' . $newRecord . ' OR (r.deleted=0 AND (r.status<>s.status OR r.last_version<>s.state_version)))';
        $rows = $pdo->query($sql . ($all ? '' : ' LIMIT 64'))->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $publicId) game_recording_capture($pdo, (string)$publicId, 'lifecycle');
    } catch (Throwable $ignored) {}
}

function game_recording_catalog(PDO $pdo, int $offset = 0): array
{
    game_recording_synchronize($pdo);
    game_recording_flush($pdo, 64);
    $rows = $pdo->query('SELECT id,game,status,event_count,archived_count,gap_count,error_code,part_number,created_at,metadata_json,deleted FROM multiplayer_game_recordings WHERE deleted<>1 ORDER BY created_at DESC,id DESC LIMIT 101 OFFSET ' . max(0, min(1000000, $offset)))->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($rows) > 100;
    $rows = array_slice($rows, 0, 100);
    $used = 0;
    foreach (new DirectoryIterator(security_private_storage_directory('game-recordings')) as $file) if ($file->isFile() && str_ends_with($file->getFilename(), '.jsonl.gz')) $used += $file->getSize();
    foreach ($rows as &$row) {
        $metadata = json_decode((string)$row['metadata_json'], true) ?: [];
        $row['players'] = $metadata['players'] ?? [];
        unset($row['metadata_json']);
        $row['closed'] = in_array($row['status'], ['completed', 'forfeited', 'abandoned', 'ended'], true);
        $row['exportable'] = $row['closed'] && (int)$row['deleted'] === 0 && (int)$row['event_count'] > 0 && (int)$row['archived_count'] === (int)$row['event_count'];
        $row['complete'] = $row['exportable'] && (int)$row['gap_count'] === 0;
    }
    unset($row);
    $health = $pdo->query('SELECT capture_failures,last_error FROM multiplayer_game_recording_control WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    return ['recordings' => $rows, 'hasMore' => $hasMore, 'offset' => $offset, 'health' => $health, 'storageBytes' => $used,
        'storageLimitBytes' => (int)app_setting($pdo, 'game_recording_storage_mb', '1024') * 1048576,
        'queuedBytes' => (int)$pdo->query('SELECT COALESCE(SUM(payload_bytes),0) FROM multiplayer_game_recording_events')->fetchColumn(),
        'games' => GAME_RECORDING_GAMES, 'formatVersion' => GAME_RECORDING_VERSION];
}

function game_recording_require_closed(PDO $pdo, string $id): array
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $id)) throw new MultiplayerGameException('Recording not found.', 'RECORDING_NOT_FOUND', 404);
    $q = $pdo->prepare('SELECT r.*,s.status AS live_status FROM multiplayer_game_recordings r LEFT JOIN multiplayer_game_sessions s ON s.public_id=r.session_public_id WHERE r.id=? AND r.deleted<>1');
    $q->execute([$id]); $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) throw new MultiplayerGameException('Recording not found.', 'RECORDING_NOT_FOUND', 404);
    if (!in_array((string)($row['live_status'] ?? $row['status']), ['completed','forfeited','abandoned','ended'], true)) throw new MultiplayerGameException('This game is still open. Its hidden information cannot be exported or deleted.', 'RECORDING_GAME_OPEN', 409);
    return $row;
}

function game_recording_delete(PDO $pdo, string $id): void
{
    $lock = game_recording_lock();
    if ($lock === null) throw new MultiplayerGameException('Recording storage is busy. Try again.', 'RECORDING_BUSY', 409);
    try {
        game_recording_require_closed($pdo, $id);
        // Tombstone first prevents a late retry or lifecycle check from recreating a deleted archive.
        $pdo->prepare('UPDATE multiplayer_game_recordings SET deleted=2,error_code=\'deletion-pending\' WHERE id=?')->execute([$id]);
        $transaction = database_transaction_begin($pdo, true);
        try {
            $pendingBytes = $pdo->prepare('SELECT COALESCE(SUM(payload_bytes),0) FROM multiplayer_game_recording_events WHERE recording_id=?');
            $pendingBytes->execute([$id]);
            $pdo->prepare('UPDATE multiplayer_game_recording_control SET queue_bytes=queue_bytes-? WHERE id=1')->execute([(int)$pendingBytes->fetchColumn()]);
            $pdo->prepare('DELETE FROM multiplayer_game_recording_events WHERE recording_id=?')->execute([$id]);
            database_transaction_commit($pdo, $transaction);
        } catch (Throwable $error) { database_transaction_rollback($pdo, $transaction); throw $error; }
        foreach (glob(security_private_storage_directory('game-recordings') . DIRECTORY_SEPARATOR . $id . '.*') ?: [] as $file) {
            if (!preg_match('/\.[0-9]{6}\.jsonl\.gz(?:\.tmp)?$/D', $file)) continue;
            if (!@unlink($file)) throw new RuntimeException('A recording file could not be deleted.');
        }
        $pdo->prepare("UPDATE multiplayer_game_recordings SET deleted=1,metadata_json='{}' WHERE id=?")->execute([$id]);
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function game_recording_archive_lines(array $row): Generator
{
    for ($part = 1; $part <= (int)$row['part_number']; $part++) {
        $path = game_recording_path((string)$row['id'], $part);
        $content = is_file($path) ? @gzdecode((string)file_get_contents($path), GAME_RECORDING_EVENT_BYTES * 2) : false;
        if (!is_string($content)) throw new RuntimeException('A recording part is missing or corrupt.');
        foreach (explode("\n", rtrim($content, "\n")) as $line) if ($line !== '') yield $line . "\n";
    }
}

function game_recording_verify_archive(array $row): void
{
    $sequence = 0;
    $hash = '';
    foreach (game_recording_archive_lines($row) as $line) {
        $event = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($event) || (int)($event['sequence'] ?? 0) !== ++$sequence || ($event['previousHash'] ?? null) !== $hash) throw new RuntimeException('Recording order failed verification.');
        $hash = (string)($event['hash'] ?? '');
        unset($event['hash']);
        if (!hash_equals($hash, hash('sha256', game_recording_json($event)))) throw new RuntimeException('Recording content failed verification.');
    }
    if ($sequence !== (int)$row['event_count'] || $hash !== $row['last_hash']) throw new RuntimeException('Recording is incomplete on disk.');
}

function game_recording_observe(array $context, string $game, array $before, int $actor, string $action, array $payload, array $after, ?array $trace = null): void
{
    if (!isset($context['recordingCollector'])) return;
    try { ($context['recordingCollector'])(game_recording_step($game, $before, $actor, $action, $payload, $after, $context, $trace)); }
    catch (Throwable $ignored) { ($context['recordingCollector'])(['recordingError' => true]); }
}

function game_recording_game(string $extensionId): string
{
    return $extensionId === 'backgammon-first-party' ? 'backgammon' : $extensionId;
}
