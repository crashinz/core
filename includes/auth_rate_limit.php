<?php
declare(strict_types=1);
final class CorechatChatPostRateException extends RuntimeException {
    public function __construct(public readonly int $userId, public readonly string $channel) {
        parent::__construct('You are sending messages too quickly.');
    }
}

/** Count accepted rows, including legacy author rows, across the whole account. */
function corechat_chat_post_recent_count(PDO $pdo, int $userId, string $cutoff): int {
    $count = 0;
    foreach (['messages','community_messages','game_chat_messages'] as $table) {
        $owner = $table === 'messages'
            ? 'm.participant_id IN (SELECT id FROM participants WHERE user_id=?)'
            : '(m.user_id=? OR (m.user_id IS NULL AND m.participant_id IN (SELECT id FROM participants WHERE user_id=?)))';
        $sql = 'SELECT m.id FROM ' . $table . ' m WHERE ' . $owner . ' AND m.sent_at>=?';
        // Current locking reads avoid a borrowed MySQL transaction's older snapshot.
        if (db_uses_mysql_syntax($pdo)) $sql .= ' FOR UPDATE';
        $statement = $pdo->prepare($sql);
        $statement->execute($table === 'messages' ? [$userId,$cutoff] : [$userId,$userId,$cutoff]);
        $count += count($statement->fetchAll(PDO::FETCH_COLUMN));
        $statement->closeCursor();
    }
    return $count;
}

/** Validate and trial-insert atomically; only a newly accepted post consumes allowance. */
function corechat_create_rate_limited_message(PDO $pdo, string $channel, string $type, array $payload): array {
    $maximum = corechat_limit_value($pdo, 'chat_posts_per_second', 3.0);
    if ($maximum === null) return create_message($pdo, $channel, $type, $payload);
    $userId = (int)($payload['user_id'] ?? $payload['participant']['user_id'] ?? 0);
    if ($userId < 1) throw new RuntimeException('An authenticated message author is required.');
    $transaction = null;
    $owned = false;
    // Respect the legacy relationship owner's raw SQLite transaction. When we
    // own a new transaction, use PDO visibility so both transaction families borrow it.
    if (!db_immediate_transaction_active($pdo)) {
        $transaction = database_transaction_begin($pdo, false);
        $owned = !empty($transaction['owned']);
    }
    try {
        if (db_uses_mysql_syntax($pdo)) {
            $lock = $pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
            $lock->execute([$userId]);
            $lock->fetchAll();
        } else {
            $pdo->prepare('UPDATE users SET id=id WHERE id=?')->execute([$userId]);
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - 1);
        $before = corechat_chat_post_recent_count($pdo, $userId, $cutoff);
        $message = create_message($pdo, $channel, $type, $payload);
        $after = corechat_chat_post_recent_count($pdo, $userId, $cutoff);
        if ($after > $before && $before >= $maximum) throw new CorechatChatPostRateException($userId, $channel);
        if ($owned) database_transaction_commit($pdo, $transaction);
        return $message;
    } catch (Throwable $error) {
        if ($owned) database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
}

/** Emit one private receipt only after all owned API transaction frames unwind. */
function corechat_chat_post_rate_install_api_handler(PDO $pdo): void {
    $previous = null;
    $previous = set_exception_handler(static function(Throwable $error) use ($pdo, &$previous): void {
        if ($error instanceof CorechatChatPostRateException) {
            limit_event_record_reached($pdo, 'chat_posts_per_second', 'member', 'user:' . $error->userId, 'throttled', ['channel'=>$error->channel]);
            json_out(['error'=>$error->getMessage()], 429);
        }
        if (is_callable($previous)) { $previous($error); return; }
        throw $error;
    });
}

const FLOOD_RELATIONSHIP_REQUEST_ENABLED_SETTING = 'flood_relationship_requests_enabled';
const FLOOD_RELATIONSHIP_REQUEST_LIMIT_SETTING = 'flood_relationship_requests_max_per_minute';
const FLOOD_AUTHENTICATION_ENABLED_SETTING = 'flood_authentication_protection_enabled';
const FLOOD_MEMBER_PROFILE_READ_ENABLED_SETTING = 'flood_member_profile_read_enabled';
const FLOOD_MEMBER_PROFILE_READ_LIMIT_SETTING = 'flood_member_profile_read_max_per_minute';
const FLOOD_GAME_SESSION_CREATION_ENABLED_SETTING = 'flood_game_session_creation_enabled';
const FLOOD_GAME_SESSION_CREATION_LIMIT_SETTING = 'flood_game_session_creation_max_per_ten_minutes';
const FLOOD_GAME_SESSION_JOINING_ENABLED_SETTING = 'flood_game_session_joining_enabled';
const FLOOD_GAME_SESSION_JOINING_LIMIT_SETTING = 'flood_game_session_joining_max_per_minute';

final class FloodProtectionException extends RuntimeException {
    public function __construct(
        string $message,
        public readonly string $control,
        public readonly int $retryAfter,
        public readonly string $errorCode = 'FLOOD_PROTECTION_LIMITED',
        public readonly int $httpStatus = 429
    ) {
        parent::__construct($message);
    }
}

function flood_protection_setting_defaults(): array {
    return [
        FLOOD_RELATIONSHIP_REQUEST_ENABLED_SETTING => '1',
        FLOOD_RELATIONSHIP_REQUEST_LIMIT_SETTING => '6',
        FLOOD_AUTHENTICATION_ENABLED_SETTING => '1',
        FLOOD_MEMBER_PROFILE_READ_ENABLED_SETTING => '1',
        FLOOD_MEMBER_PROFILE_READ_LIMIT_SETTING => '120',
        FLOOD_GAME_SESSION_CREATION_ENABLED_SETTING => '1',
        FLOOD_GAME_SESSION_CREATION_LIMIT_SETTING => '12',
        FLOOD_GAME_SESSION_JOINING_ENABLED_SETTING => '1',
        FLOOD_GAME_SESSION_JOINING_LIMIT_SETTING => '60',
    ];
}

function flood_protection_settings_registry_definitions(): array {
    $common = [
        'owner' => 'flood_protection',
        'categoryId' => 'moderation-privacy-security',
        'subsectionId' => 'flood-protection',
        'subsectionLabel' => 'Flood Protection',
        'subsectionOrder' => 18,
        'controlClass' => 'configurable-required',
        'setupVisible' => true,
        'adminVisible' => true,
        'authorization' => 'administrator-and-recent-authentication',
        'safeToReset' => true,
        'originalRelevant' => false,
        'originalValueAvailable' => false,
        'toolLogBehavior' => 'bounded-flood-protection-transition',
    ];
    $toggle = $common + [
        'type' => 'boolean',
        'defaultValue' => true,
        'optional' => true,
        'bulkGroup' => 'flood-protection',
    ];
    $limit = $common + [
        'type' => 'number',
        'optional' => false,
        'minimum' => 1,
        'step' => 1,
        'bulkOperations' => ['setting', 'subsection', 'category'],
        'limitGroup' => 'Account Protection',
    ];
    return [
        $toggle + [
            'id' => FLOOD_RELATIONSHIP_REQUEST_ENABLED_SETTING,
            'settingKey' => FLOOD_RELATIONSHIP_REQUEST_ENABLED_SETTING,
            'label' => 'Relationship Request Protection',
            'description' => 'Limits link/lap requests and invitations per account and opaque network reference. Does not limit accepting, rejecting, leaving, orientation or dragging.',
            'helpText' => 'Disabling this allows repeated requests and invitations to generate unwanted notifications.',
            'order' => 40,
            'bulkOperations' => ['setting', 'subsection', 'category', 'all-optional'],
        ],
        $limit + [
            'id' => FLOOD_RELATIONSHIP_REQUEST_LIMIT_SETTING,
            'settingKey' => FLOOD_RELATIONSHIP_REQUEST_LIMIT_SETTING,
            'label' => 'Relationship Requests and Invitations per Minute',
            'description' => 'Maximum request/invitation attempts per account per minute across rooms, using the shared Flood Protection counters. Repeated notifications are grouped in one passive notice.',
            'helpText' => 'Default: 6 per minute. Disabling this limit allows request notification spam. Accept, Reject, Cancel, leaving and movement do not consume it.',
            'defaultValue' => 6,
            'maximum' => 120,
            'unit' => 'requests/minute',
            'order' => 41,
        ],
        $toggle + [
            'id' => FLOOD_AUTHENTICATION_ENABLED_SETTING,
            'settingKey' => FLOOD_AUTHENTICATION_ENABLED_SETTING,
            'label' => 'Authentication Protection',
            'description' => 'Limits repeated authentication and recovery attempts without storing or displaying a raw network address.',
            'helpText' => 'Enabled by default. Disable All never changes this protection. Disabling it requires a fresh inline high-severity confirmation.',
            'order' => 1,
            'bulkOperations' => ['setting'],
        ],
        $toggle + [
            'id' => FLOOD_MEMBER_PROFILE_READ_ENABLED_SETTING,
            'settingKey' => FLOOD_MEMBER_PROFILE_READ_ENABLED_SETTING,
            'label' => 'Member Profile Read Protection',
            'description' => 'Limits repeated member-profile reads per signed-in member and opaque network reference.',
            'helpText' => 'The standard value is a conservative policy default, not a measured capacity claim.',
            'order' => 10,
            'bulkOperations' => ['setting', 'subsection', 'category', 'all-optional'],
        ],
        $limit + [
            'id' => FLOOD_MEMBER_PROFILE_READ_LIMIT_SETTING,
            'settingKey' => FLOOD_MEMBER_PROFILE_READ_LIMIT_SETTING,
            'label' => 'Member Profile Reads per Minute',
            'description' => 'Maximum accepted profile reads per signed-in member during one minute.',
            'helpText' => 'This is an editable policy limit and is not represented as a measured server capacity.',
            'defaultValue' => 120,
            'maximum' => 1000,
            'unit' => 'requests/minute',
            'order' => 11,
        ],
        $toggle + [
            'id' => FLOOD_GAME_SESSION_CREATION_ENABLED_SETTING,
            'settingKey' => FLOOD_GAME_SESSION_CREATION_ENABLED_SETTING,
            'label' => 'Game Creation Protection',
            'description' => 'Limits new game-session creation without consuming game turns, dice, saves, results, chat, or reconnects.',
            'helpText' => 'The standard value is a conservative policy default, not a measured capacity claim.',
            'order' => 20,
            'bulkOperations' => ['setting', 'subsection', 'category', 'all-optional'],
        ],
        $limit + [
            'id' => FLOOD_GAME_SESSION_CREATION_LIMIT_SETTING,
            'settingKey' => FLOOD_GAME_SESSION_CREATION_LIMIT_SETTING,
            'label' => 'Game Creations per Ten Minutes',
            'description' => 'Maximum accepted new game sessions per signed-in member during ten minutes.',
            'helpText' => 'This is an editable policy limit and is not represented as a measured server capacity.',
            'defaultValue' => 12,
            'maximum' => 120,
            'unit' => 'creations/10 minutes',
            'order' => 21,
        ],
        $toggle + [
            'id' => FLOOD_GAME_SESSION_JOINING_ENABLED_SETTING,
            'settingKey' => FLOOD_GAME_SESSION_JOINING_ENABLED_SETTING,
            'label' => 'Game Joining Protection',
            'description' => 'Limits new game memberships while allowing existing memberships and dedicated reconnects to continue.',
            'helpText' => 'The standard value is a conservative policy default, not a measured capacity claim.',
            'order' => 30,
            'bulkOperations' => ['setting', 'subsection', 'category', 'all-optional'],
        ],
        $limit + [
            'id' => FLOOD_GAME_SESSION_JOINING_LIMIT_SETTING,
            'settingKey' => FLOOD_GAME_SESSION_JOINING_LIMIT_SETTING,
            'label' => 'Game Joins per Minute',
            'description' => 'Maximum accepted new game memberships per signed-in member during one minute.',
            'helpText' => 'This is an editable policy limit and is not represented as a measured server capacity.',
            'defaultValue' => 60,
            'maximum' => 600,
            'unit' => 'joins/minute',
            'order' => 31,
        ],
    ];
}

function flood_protection_enabled(PDO $pdo, string $setting): bool {
    $defaults = flood_protection_setting_defaults();
    return app_setting($pdo, $setting, (string)($defaults[$setting] ?? '1')) === '1';
}

function flood_protection_catalog_projection(PDO $pdo): array {
    $controls = [
        'relationshipRequests' => FLOOD_RELATIONSHIP_REQUEST_ENABLED_SETTING,
        'authentication' => FLOOD_AUTHENTICATION_ENABLED_SETTING,
        'memberProfileRead' => FLOOD_MEMBER_PROFILE_READ_ENABLED_SETTING,
        'gameSessionCreation' => FLOOD_GAME_SESSION_CREATION_ENABLED_SETTING,
        'gameSessionJoining' => FLOOD_GAME_SESSION_JOINING_ENABLED_SETTING,
    ];
    $enabled = [];
    foreach ($controls as $name => $setting) $enabled[$name] = flood_protection_enabled($pdo, $setting);
    $enabledCount = count(array_filter($enabled));
    return [
        'controls' => $enabled,
        'state' => $enabledCount === count($enabled) ? 'all-enabled' : ($enabledCount === 0 ? 'all-disabled' : 'custom'),
        'authenticationExcludedFromDisableAll' => true,
        'automaticBanning' => false,
        'rawAddressStorageOrDisplay' => false,
    ];
}

function flood_protection_policy(PDO $pdo, string $control): array {
    return match ($control) {
        'relationship-request' => [
            'enabledSetting' => FLOOD_RELATIONSHIP_REQUEST_ENABLED_SETTING,
            'limitSetting' => FLOOD_RELATIONSHIP_REQUEST_LIMIT_SETTING,
            'limit' => max(1, (int)app_setting($pdo, FLOOD_RELATIONSHIP_REQUEST_LIMIT_SETTING, '6')),
            'windowSeconds' => 60,
            'label' => 'Relationship Request Protection',
        ],
        'member-profile-read' => [
            'enabledSetting' => FLOOD_MEMBER_PROFILE_READ_ENABLED_SETTING,
            'limitSetting' => FLOOD_MEMBER_PROFILE_READ_LIMIT_SETTING,
            'limit' => max(1, (int)app_setting($pdo, FLOOD_MEMBER_PROFILE_READ_LIMIT_SETTING, '120')),
            'windowSeconds' => 60,
            'label' => 'Member Profile Read Protection',
        ],
        'game-session-creation' => [
            'enabledSetting' => FLOOD_GAME_SESSION_CREATION_ENABLED_SETTING,
            'limitSetting' => FLOOD_GAME_SESSION_CREATION_LIMIT_SETTING,
            'limit' => max(1, (int)app_setting($pdo, FLOOD_GAME_SESSION_CREATION_LIMIT_SETTING, '12')),
            'windowSeconds' => 600,
            'label' => 'Game Creation Protection',
        ],
        'game-session-joining' => [
            'enabledSetting' => FLOOD_GAME_SESSION_JOINING_ENABLED_SETTING,
            'limitSetting' => FLOOD_GAME_SESSION_JOINING_LIMIT_SETTING,
            'limit' => max(1, (int)app_setting($pdo, FLOOD_GAME_SESSION_JOINING_LIMIT_SETTING, '60')),
            'windowSeconds' => 60,
            'label' => 'Game Joining Protection',
        ],
        default => throw new InvalidArgumentException('Unknown flood-protection control.'),
    };
}

function auth_rate_retry_after_header(int $seconds): void {
    if (!headers_sent()) header('Retry-After: ' . max(1, $seconds));
}

function flood_protection_consume(PDO $pdo, string $control, int $actorUserId): void {
    $policy = flood_protection_policy($pdo, $control);
    if (!flood_protection_enabled($pdo, (string)$policy['enabledSetting'])) return;
    if (!corechat_limit_is_enforced($pdo, (string)$policy['limitSetting'])) return;
    if ($actorUserId < 1) throw new InvalidArgumentException('A signed-in actor is required for flood protection.');
    if (!auth_rate_database_storage_available($pdo)) {
        throw new FloodProtectionException(
            (string)$policy['label'] . ' is temporarily unavailable.',
            $control,
            5,
            'FLOOD_PROTECTION_STORAGE_UNAVAILABLE',
            503
        );
    }

    $networkReference = network_privacy_opaque_identifier(client_ip_address());
    $scope = 'flood:' . $control;
    $dimensions = [
        ['name' => 'actor', 'value' => 'user:' . $actorUserId, 'limit' => (int)$policy['limit']],
        ['name' => 'network', 'value' => $networkReference, 'limit' => (int)$policy['limit'] * 4],
    ];
    $nowEpoch = time();
    $now = gmdate('Y-m-d H:i:s', $nowEpoch);
    $windowSeconds = (int)$policy['windowSeconds'];
    $cutoffEpoch = $nowEpoch - $windowSeconds;
    $transaction = database_transaction_begin($pdo, false);
    $ownsTransaction = !empty($transaction['owned']);
    $transactionActive = $ownsTransaction;
    try {
        // Reserve counter rows before reading: deferred SQLite read upgrades can
        // otherwise race, and a missing MySQL row has no counter lock to acquire.
        $reserve = $pdo->prepare(db_uses_mysql_syntax($pdo)
            ? 'INSERT INTO auth_attempts (scope,dimension,key_hash,attempts,last_attempt_at,locked_until) VALUES (?,?,?,0,?,NULL) ON DUPLICATE KEY UPDATE key_hash=VALUES(key_hash)'
            : 'INSERT INTO auth_attempts (scope,dimension,key_hash,attempts,last_attempt_at,locked_until) VALUES (?,?,?,0,?,NULL) ON CONFLICT(scope,dimension,key_hash) DO UPDATE SET key_hash=excluded.key_hash'
        );
        foreach ($dimensions as $dimension) {
            $reserve->execute([$scope, $dimension['name'], auth_rate_key_hash($scope, (string)$dimension['name'], (string)$dimension['value']), $now]);
        }
        $selectSql = 'SELECT attempts,last_attempt_at,locked_until FROM auth_attempts WHERE scope=? AND dimension=? AND key_hash=? LIMIT 1';
        if (db_uses_mysql_syntax($pdo)) $selectSql .= ' FOR UPDATE';
        $select = $pdo->prepare($selectSql);
        $states = [];
        $retryAfter = 0;
        foreach ($dimensions as $dimension) {
            $keyHash = auth_rate_key_hash($scope, (string)$dimension['name'], (string)$dimension['value']);
            $select->execute([$scope, $dimension['name'], $keyHash]);
            $row = $select->fetch() ?: [];
            $lastEpoch = strtotime((string)($row['last_attempt_at'] ?? '')) ?: 0;
            $lockedEpoch = strtotime((string)($row['locked_until'] ?? '')) ?: 0;
            $attempts = $lastEpoch >= $cutoffEpoch ? (int)($row['attempts'] ?? 0) : 0;
            if ($lockedEpoch > $nowEpoch) $retryAfter = max($retryAfter, $lockedEpoch - $nowEpoch);
            if ($attempts >= (int)$dimension['limit']) {
                $retryAfter = max($retryAfter, max(1, ($lastEpoch + $windowSeconds) - $nowEpoch));
            }
            $states[] = $dimension + ['keyHash' => $keyHash, 'attempts' => $attempts];
        }
        if ($retryAfter > 0) {
            if ($transactionActive) {
                database_transaction_rollback($pdo, $transaction);
                $transactionActive = false;
            }
            auth_rate_retry_after_header($retryAfter);
            limit_event_record(
                $pdo,
                (string)$policy['limitSetting'],
                (string)$policy['label'],
                (int)$policy['limit'],
                $windowSeconds . ' seconds',
                'member',
                'user:' . $actorUserId,
                'blocked',
                ['control' => $control, 'retryAfterSeconds' => $retryAfter]
            );
            throw new FloodProtectionException(
                (string)$policy['label'] . ' limited this request. Try again in ' . auth_rate_seconds($retryAfter) . '.',
                $control,
                $retryAfter
            );
        }
        $write = $pdo->prepare(db_uses_mysql_syntax($pdo)
            ? 'INSERT INTO auth_attempts (scope,dimension,key_hash,attempts,last_attempt_at,locked_until) VALUES (?,?,?,?,?,NULL) ON DUPLICATE KEY UPDATE attempts=VALUES(attempts),last_attempt_at=VALUES(last_attempt_at),locked_until=NULL'
            : 'INSERT INTO auth_attempts (scope,dimension,key_hash,attempts,last_attempt_at,locked_until) VALUES (?,?,?,?,?,NULL) ON CONFLICT(scope,dimension,key_hash) DO UPDATE SET attempts=excluded.attempts,last_attempt_at=excluded.last_attempt_at,locked_until=NULL'
        );
        foreach ($states as $state) {
            $write->execute([$scope, $state['name'], $state['keyHash'], (int)$state['attempts'] + 1, $now]);
        }
        if ($transactionActive) {
            database_transaction_commit($pdo, $transaction);
            $transactionActive = false;
        }
    } catch (Throwable $error) {
        if ($transactionActive) database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
}

function flood_protection_multiplayer_membership_exists(PDO $pdo, string $publicId, int $userId): bool {
    $stmt = $pdo->prepare(
        "SELECT 1 FROM multiplayer_game_members m JOIN multiplayer_game_sessions s ON s.id=m.game_session_id WHERE s.public_id=? AND m.user_id=? AND m.membership_status='active' LIMIT 1"
    );
    $stmt->execute([$publicId, $userId]);
    return (bool)$stmt->fetchColumn();
}

function auth_rate_scope_is_authentication(string $scope): bool {
    return !str_starts_with($scope, 'gesture:')
        && !str_starts_with($scope, 'outside:')
        && !str_starts_with($scope, 'flood:');
}

function auth_rate_dimension_limit_setting(string $scope, string $dimension): string {
    if (!auth_rate_scope_is_authentication($scope)) return '';
    if ($dimension === 'ip') return 'auth_ip_max_attempts';
    return $scope === 'recovery' ? 'auth_recovery_max_attempts' : 'auth_login_max_attempts';
}

function auth_rate_dimension_enforced(PDO $pdo, string $scope, string $dimension): bool {
    $setting = auth_rate_dimension_limit_setting($scope, $dimension);
    return $setting === '' || corechat_limit_is_enforced($pdo, $setting);
}

function auth_rate_window_enforced(PDO $pdo, string $scope): bool {
    return !auth_rate_scope_is_authentication($scope) || corechat_limit_is_enforced($pdo, 'auth_attempt_window_minutes');
}

function auth_rate_lockout_enforced(PDO $pdo, string $scope): bool {
    return !auth_rate_scope_is_authentication($scope) || corechat_limit_is_enforced($pdo, 'auth_lockout_minutes');
}

/**
 * Build 000049 authoritative request rate-limit owner.
 *
 * Login, recovery, database-update, gesture, and outside-content callers retain
 * their stable function APIs. This owner uses the caller's PDO and does not
 * acquire connection, transaction, request-authorization, or response
 * ownership.
 */

function client_ip_address(): string {
    if (function_exists('network_privacy_client_ip')) {
        return network_privacy_client_ip();
    }
    return trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown')) ?: 'unknown';
}

function auth_rate_seconds(int $seconds): string {
    $seconds = max(1, $seconds);
    if ($seconds < 60) return $seconds . ' second' . ($seconds === 1 ? '' : 's');
    $minutes = (int)ceil($seconds / 60);
    if ($minutes < 60) return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
    $hours = (int)ceil($minutes / 60);
    return $hours . ' hour' . ($hours === 1 ? '' : 's');
}

function auth_rate_scope_max(PDO $pdo, string $scope): int {
    if (str_starts_with($scope, 'gesture:')) {
        return max(1, (int)app_setting_float($pdo, 'gesture_mutation_rate_limit', 120));
    }
    if (str_starts_with($scope, 'outside:')) {
        return max(1, (int)app_setting_float($pdo, 'outside_content_rate_limit', 60));
    }
    $key = $scope === 'recovery' ? 'auth_recovery_max_attempts' : 'auth_login_max_attempts';
    return max(1, (int)app_setting_float($pdo, $key, $scope === 'recovery' ? 5 : 5));
}

function auth_rate_ip_max(PDO $pdo, string $scope = ''): int {
    if (str_starts_with($scope, 'gesture:')) {
        return max(1, (int)app_setting_float($pdo, 'gesture_mutation_ip_rate_limit', 600));
    }
    if (str_starts_with($scope, 'outside:')) {
        return max(1, (int)app_setting_float($pdo, 'outside_content_ip_rate_limit', 300));
    }
    return max(1, (int)app_setting_float($pdo, 'auth_ip_max_attempts', 30));
}

function auth_rate_window_minutes(PDO $pdo): float {
    return max(1.0, app_setting_float($pdo, 'auth_attempt_window_minutes', 15));
}

function auth_rate_lockout_minutes(PDO $pdo): float {
    return max(1.0, app_setting_float($pdo, 'auth_lockout_minutes', 15));
}

function auth_rate_key_hash(string $scope, string $dimension, string $value): string {
    $normalized = strtolower(trim($scope)) . "\n" . strtolower(trim($dimension)) . "\n" . strtolower(trim($value));
    return hash('sha256', $normalized);
}

function auth_rate_keys(string $scope, string $identifier): array {
    $identifier = trim($identifier) !== '' ? trim($identifier) : '(blank)';
    return [
        ['dimension' => 'identifier', 'hash' => auth_rate_key_hash($scope, 'identifier', $identifier)],
        ['dimension' => 'ip', 'hash' => auth_rate_key_hash($scope, 'ip', client_ip_address())],
    ];
}

function auth_rate_database_storage_available(PDO $pdo): bool {
    return function_exists('database_migration_table_exists')
        && database_migration_table_exists($pdo, 'auth_attempts');
}

function auth_rate_private_path(string $scope, string $dimension, string $keyHash): string {
    $directory = security_private_storage_directory('pre-migration-auth-rate-limits');
    $identity = hash('sha256', strtolower(trim($scope)) . "\n" . $dimension . "\n" . $keyHash);
    return $directory . DIRECTORY_SEPARATOR . $identity . '.json';
}

function auth_rate_private_read(string $path): array {
    if (!is_file($path)) return [];
    $handle = fopen($path, 'rb');
    if ($handle === false) throw new RuntimeException('Private authentication rate-limit state is unavailable.');
    try {
        if (!flock($handle, LOCK_SH)) throw new RuntimeException('Private authentication rate-limit state could not be locked.');
        $bytes = stream_get_contents($handle, 4097);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
    if (!is_string($bytes) || strlen($bytes) > 4096) {
        throw new RuntimeException('Private authentication rate-limit state is invalid.');
    }
    $decoded = $bytes === '' ? [] : json_decode($bytes, true);
    if (!is_array($decoded)) throw new RuntimeException('Private authentication rate-limit state is invalid.');
    return $decoded;
}

function auth_rate_private_write(string $path, array $state): void {
    $bytes = json_encode($state, JSON_UNESCAPED_SLASHES);
    if (!is_string($bytes) || strlen($bytes) > 4096) {
        throw new RuntimeException('Private authentication rate-limit state exceeded its bounded format.');
    }
    $handle = fopen($path, 'c+b');
    if ($handle === false) throw new RuntimeException('Private authentication rate-limit state is unavailable.');
    try {
        if (!flock($handle, LOCK_EX)) throw new RuntimeException('Private authentication rate-limit state could not be locked.');
        if (!ftruncate($handle, 0) || fseek($handle, 0) !== 0) {
            throw new RuntimeException('Private authentication rate-limit state could not be replaced.');
        }
        $written = fwrite($handle, $bytes);
        if ($written !== strlen($bytes) || !fflush($handle)) {
            throw new RuntimeException('Private authentication rate-limit state could not be persisted.');
        }
        if (function_exists('fsync') && !fsync($handle)) {
            throw new RuntimeException('Private authentication rate-limit state could not be synchronized.');
        }
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}

function auth_rate_private_increment(
    string $path,
    int $windowCutoff,
    int $now,
    int $lockedUntil,
    int $max
): void {
    $handle = fopen($path, 'c+b');
    if ($handle === false) throw new RuntimeException('Private authentication rate-limit state is unavailable.');
    try {
        if (!flock($handle, LOCK_EX)) throw new RuntimeException('Private authentication rate-limit state could not be locked.');
        $bytes = stream_get_contents($handle, 4097);
        if (!is_string($bytes) || strlen($bytes) > 4096) {
            throw new RuntimeException('Private authentication rate-limit state is invalid.');
        }
        $state = $bytes === '' ? [] : json_decode($bytes, true);
        if (!is_array($state)) throw new RuntimeException('Private authentication rate-limit state is invalid.');
        $attempts = (int)($state['last_attempt_at'] ?? 0) >= $windowCutoff
            ? ((int)($state['attempts'] ?? 0)) + 1
            : 1;
        $replacement = json_encode([
            'attempts' => $attempts,
            'last_attempt_at' => $now,
            'locked_until' => $attempts >= $max ? $lockedUntil : 0,
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($replacement) || strlen($replacement) > 4096) {
            throw new RuntimeException('Private authentication rate-limit state exceeded its bounded format.');
        }
        if (!ftruncate($handle, 0) || fseek($handle, 0) !== 0) {
            throw new RuntimeException('Private authentication rate-limit state could not be replaced.');
        }
        $written = fwrite($handle, $replacement);
        if ($written !== strlen($replacement) || !fflush($handle)) {
            throw new RuntimeException('Private authentication rate-limit state could not be persisted.');
        }
        if (function_exists('fsync') && !fsync($handle)) {
            throw new RuntimeException('Private authentication rate-limit state could not be synchronized.');
        }
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}

function auth_rate_private_status(string $scope, string $identifier, ?PDO $pdo = null): array {
    if ($pdo !== null && (!auth_rate_window_enforced($pdo, $scope) || !auth_rate_lockout_enforced($pdo, $scope))) {
        return ['allowed' => true, 'retry_after' => 0, 'message' => ''];
    }
    $now = time();
    $blockedUntil = 0;
    $blockingSettings = [];
    foreach (auth_rate_keys($scope, $identifier) as $key) {
        if ($pdo !== null && !auth_rate_dimension_enforced($pdo, $scope, (string)$key['dimension'])) continue;
        $state = auth_rate_private_read(
            auth_rate_private_path($scope, (string)$key['dimension'], (string)$key['hash'])
        );
        $blockedUntil = max($blockedUntil, (int)($state['locked_until'] ?? 0));
    }
    if ($blockedUntil > $now) {
        return [
            'allowed' => false,
            'retry_after' => $blockedUntil - $now,
            'message' => 'Too many attempts. Try again in ' . auth_rate_seconds($blockedUntil - $now) . '.',
        ];
    }
    return ['allowed' => true, 'retry_after' => 0, 'message' => ''];
}

function auth_rate_private_record_failure(PDO $pdo, string $scope, string $identifier): void {
    if (!auth_rate_window_enforced($pdo, $scope)) return;
    $now = time();
    $windowCutoff = $now - (int)ceil(auth_rate_window_minutes($pdo) * 60);
    $lockedUntil = auth_rate_lockout_enforced($pdo, $scope)
        ? $now + (int)ceil(auth_rate_lockout_minutes($pdo) * 60) : 0;
    foreach (auth_rate_keys($scope, $identifier) as $key) {
        if (!auth_rate_dimension_enforced($pdo, $scope, (string)$key['dimension'])) continue;
        $path = auth_rate_private_path($scope, (string)$key['dimension'], (string)$key['hash']);
        $max = $key['dimension'] === 'ip'
            ? auth_rate_ip_max($pdo, $scope)
            : auth_rate_scope_max($pdo, $scope);
        auth_rate_private_increment($path, $windowCutoff, $now, $lockedUntil, $max);
    }
}

function auth_rate_cleanup(PDO $pdo): void {
    $windowMinutes = auth_rate_window_minutes($pdo);
    $cutoff = gmdate('Y-m-d H:i:s', time() - (int)ceil($windowMinutes * 60));
    $now = gmdate('Y-m-d H:i:s');
    try {
        db_with_sqlite_lock_retry($pdo, static function () use ($pdo, $cutoff, $now): void {
            $stmt = $pdo->prepare('DELETE FROM auth_attempts WHERE last_attempt_at < ? AND (locked_until IS NULL OR locked_until < ?)');
            $stmt->execute([$cutoff, $now]);
        }, 'authentication rate-limit cleanup');
    } catch (Throwable $error) {
        // Expired-row cleanup is maintenance only. A still-busy writer must not
        // prevent a valid account from reaching the authentication checks.
        if (!db_is_transient_lock_error($error)) throw $error;
    }
}

function auth_rate_limit_status(PDO $pdo, string $scope, string $identifier): array {
    if (auth_rate_scope_is_authentication($scope)
        && !flood_protection_enabled($pdo, FLOOD_AUTHENTICATION_ENABLED_SETTING)) {
        return ['allowed' => true, 'retry_after' => 0, 'message' => ''];
    }
    if (!auth_rate_window_enforced($pdo, $scope)) return ['allowed' => true, 'retry_after' => 0, 'message' => ''];
    if (!auth_rate_lockout_enforced($pdo, $scope)) return ['allowed' => true, 'retry_after' => 0, 'message' => ''];
    if (!auth_rate_database_storage_available($pdo)) {
        $status = auth_rate_private_status($scope, $identifier, $pdo);
        if (empty($status['allowed'])) auth_rate_retry_after_header((int)($status['retry_after'] ?? 1));
        return $status;
    }
    auth_rate_cleanup($pdo);
    $now = time();
    $blockedUntil = 0;
    $blockingSettings = [];
    $stmt = $pdo->prepare('SELECT dimension, locked_until FROM auth_attempts WHERE scope = ? AND dimension = ? AND key_hash = ? LIMIT 1');
    foreach (auth_rate_keys($scope, $identifier) as $key) {
        if (!auth_rate_dimension_enforced($pdo, $scope, (string)$key['dimension'])) continue;
        $stmt->execute([$scope, $key['dimension'], $key['hash']]);
        $row = $stmt->fetch();
        if (!$row || empty($row['locked_until'])) continue;
        $until = strtotime((string)$row['locked_until']) ?: 0;
        if ($until > $now) {
            $blockedUntil = max($blockedUntil, $until);
            $dimensionSetting = auth_rate_dimension_limit_setting($scope, (string)$key['dimension']);
            if ($dimensionSetting !== '') $blockingSettings[$dimensionSetting] = true;
        }
    }
    $stmt->closeCursor();
    if ($blockedUntil > $now) {
        auth_rate_retry_after_header($blockedUntil - $now);
        foreach (array_keys($blockingSettings) as $setting) {
            limit_event_record_reached($pdo, $setting, 'authentication', 'scope:' . $scope . ':identifier:' . trim($identifier), 'blocked', ['retryAfterSeconds' => $blockedUntil - $now]);
        }
        if (auth_rate_scope_is_authentication($scope)) {
            limit_event_record_reached($pdo, 'auth_attempt_window_minutes', 'authentication', 'scope:' . $scope . ':identifier:' . trim($identifier), 'blocked', ['retryAfterSeconds' => $blockedUntil - $now]);
            limit_event_record_reached($pdo, 'auth_lockout_minutes', 'authentication', 'scope:' . $scope . ':identifier:' . trim($identifier), 'blocked', ['retryAfterSeconds' => $blockedUntil - $now]);
        }
        return [
            'allowed' => false,
            'retry_after' => $blockedUntil - $now,
            'message' => 'Too many attempts. Try again in ' . auth_rate_seconds($blockedUntil - $now) . '.',
        ];
    }
    return ['allowed' => true, 'retry_after' => 0, 'message' => ''];
}

function auth_rate_record_failure(PDO $pdo, string $scope, string $identifier): void {
    if (auth_rate_scope_is_authentication($scope)
        && !flood_protection_enabled($pdo, FLOOD_AUTHENTICATION_ENABLED_SETTING)) return;
    if (!auth_rate_window_enforced($pdo, $scope)) return;
    if (!auth_rate_database_storage_available($pdo)) {
        auth_rate_private_record_failure($pdo, $scope, $identifier);
        return;
    }
    $now = gmdate('Y-m-d H:i:s');
    $windowCutoff = gmdate('Y-m-d H:i:s', time() - (int)ceil(auth_rate_window_minutes($pdo) * 60));
    $lockedUntil = auth_rate_lockout_enforced($pdo, $scope)
        ? gmdate('Y-m-d H:i:s', time() + (int)ceil(auth_rate_lockout_minutes($pdo) * 60))
        : null;
    $write = $pdo->prepare(db_uses_mysql_syntax($pdo)
        ? 'INSERT INTO auth_attempts (scope, dimension, key_hash, attempts, last_attempt_at, locked_until) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE attempts=IF(last_attempt_at>=?,attempts+1,1),locked_until=IF(attempts>=?,?,NULL),last_attempt_at=VALUES(last_attempt_at)'
        : 'INSERT INTO auth_attempts (scope, dimension, key_hash, attempts, last_attempt_at, locked_until) VALUES (?,?,?,?,?,?) ON CONFLICT(scope, dimension, key_hash) DO UPDATE SET attempts=CASE WHEN auth_attempts.last_attempt_at>=? THEN auth_attempts.attempts+1 ELSE 1 END,locked_until=CASE WHEN (CASE WHEN auth_attempts.last_attempt_at>=? THEN auth_attempts.attempts+1 ELSE 1 END)>=CAST(? AS INTEGER) THEN ? ELSE NULL END,last_attempt_at=excluded.last_attempt_at'
    );
    foreach (auth_rate_keys($scope, $identifier) as $key) {
        if (!auth_rate_dimension_enforced($pdo, $scope, (string)$key['dimension'])) continue;
        $max = $key['dimension'] === 'ip' ? auth_rate_ip_max($pdo, $scope) : auth_rate_scope_max($pdo, $scope);
        $values = [$scope, $key['dimension'], $key['hash'], 1, $now, $max <= 1 ? $lockedUntil : null, $windowCutoff];
        if (!db_uses_mysql_syntax($pdo)) $values[] = $windowCutoff;
        $write->execute([...$values, $max, $lockedUntil]);
    }
}

function auth_rate_clear_identifier(PDO $pdo, string $scope, string $identifier): void {
    $hash = auth_rate_key_hash($scope, 'identifier', trim($identifier) !== '' ? trim($identifier) : '(blank)');
    if (!auth_rate_database_storage_available($pdo)) {
        auth_rate_private_write(
            auth_rate_private_path($scope, 'identifier', $hash),
            ['attempts' => 0, 'last_attempt_at' => 0, 'locked_until' => 0]
        );
        return;
    }
    $stmt = $pdo->prepare("DELETE FROM auth_attempts WHERE scope = ? AND dimension = 'identifier' AND key_hash = ?");
    $stmt->execute([$scope, $hash]);
}
