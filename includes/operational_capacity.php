<?php
declare(strict_types=1);

/**
 * Build 000052 mandatory-core operational capacity authority.
 *
 * Profile values are conservative recommendations derived from the private,
 * reproducible SQLite/MariaDB measurement program. They are health targets and
 * bounded work sizes, never admission-control or security-policy overrides.
 */

const OPERATIONAL_CAPACITY_PROFILE_SETTING = 'operational_capacity_profile';
const OPERATIONAL_CAPACITY_REVISION_SETTING = 'operational_capacity_revision';
const OPERATIONAL_CAPACITY_PROVENANCE = 'Measured SQLite and MariaDB capacity baseline';

final class OperationalCapacityException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $httpStatus = 409,
        public readonly array $projection = []
    ) {
        parent::__construct($message);
    }
}

function operational_capacity_definitions(): array
{
    return [
        'capacity_active_rooms_target' => [
            'label' => 'Active rooms warning threshold',
            'description' => 'Warn when active rooms exceed the measured operating target.',
            'minimum' => 1, 'maximum' => 24, 'unit' => 'rooms',
        ],
        'capacity_active_users_target' => [
            'label' => 'Active users warning threshold',
            'description' => 'Warn when active signed-in users exceed the measured operating target.',
            'minimum' => 10, 'maximum' => 400, 'unit' => 'users',
        ],
        'capacity_active_participants_target' => [
            'label' => 'Active participants warning threshold',
            'description' => 'Warn when active room participants exceed the measured operating target.',
            'minimum' => 5, 'maximum' => 240, 'unit' => 'participants',
        ],
        'capacity_event_batch_limit' => [
            'label' => 'Event delivery batch limit',
            'description' => 'Bound one event-delivery response without changing authorization or ordering.',
            'minimum' => 25, 'maximum' => 200, 'unit' => 'events',
        ],
        'capacity_event_replay_window' => [
            'label' => 'Event replay window',
            'description' => 'Bound the retained cursor window available to an authorized transport.',
            'minimum' => 100, 'maximum' => 1600, 'unit' => 'events',
        ],
        'capacity_maintenance_batch_size' => [
            'label' => 'Maintenance batch size',
            'description' => 'Bound one background maintenance unit.',
            'minimum' => 10, 'maximum' => 200, 'unit' => 'records',
        ],
        'capacity_diagnostic_cleanup_batch_size' => [
            'label' => 'Diagnostic cleanup batch size',
            'description' => 'Bound one explicitly invoked diagnostic-retention cleanup unit.',
            'minimum' => 5, 'maximum' => 120, 'unit' => 'records',
        ],
        'capacity_slow_request_ms' => [
            'label' => 'Slow request threshold',
            'description' => 'Classify sanitized aggregate request timing without storing request content.',
            'minimum' => 250, 'maximum' => 5000, 'unit' => 'milliseconds',
        ],
    ];
}

function operational_capacity_profiles(): array
{
    return [
        'shared-conservative' => [
            'label' => 'Shared/Conservative',
            'description' => 'Safe new-installation baseline for bounded shared-host resources.',
            'values' => [
                'capacity_active_rooms_target' => 2,
                'capacity_active_users_target' => 20,
                'capacity_active_participants_target' => 12,
                'capacity_event_batch_limit' => 50,
                'capacity_event_replay_window' => 200,
                'capacity_maintenance_batch_size' => 25,
                'capacity_diagnostic_cleanup_batch_size' => 10,
                'capacity_slow_request_ms' => 1500,
            ],
        ],
        'standard' => [
            'label' => 'Standard',
            'description' => 'Measured starting point for a typical managed PHP/database host.',
            'values' => [
                'capacity_active_rooms_target' => 6,
                'capacity_active_users_target' => 80,
                'capacity_active_participants_target' => 48,
                'capacity_event_batch_limit' => 100,
                'capacity_event_replay_window' => 500,
                'capacity_maintenance_batch_size' => 50,
                'capacity_diagnostic_cleanup_batch_size' => 25,
                'capacity_slow_request_ms' => 1000,
            ],
        ],
        'dedicated-high-capacity' => [
            'label' => 'Dedicated/High-Capacity',
            'description' => 'Measured starting point for a controlled dedicated runtime and database.',
            'values' => [
                'capacity_active_rooms_target' => 12,
                'capacity_active_users_target' => 200,
                'capacity_active_participants_target' => 120,
                'capacity_event_batch_limit' => 100,
                'capacity_event_replay_window' => 800,
                'capacity_maintenance_batch_size' => 100,
                'capacity_diagnostic_cleanup_batch_size' => 50,
                'capacity_slow_request_ms' => 750,
            ],
        ],
        'custom' => [
            'label' => 'Custom',
            'description' => 'Explicit administrator values inside the certified hard safety envelope.',
            'values' => null,
        ],
    ];
}

function operational_capacity_setting_defaults(bool $freshInstall): array
{
    $defaults = operational_capacity_profiles()['shared-conservative']['values'];
    return array_merge($defaults, [
        OPERATIONAL_CAPACITY_PROFILE_SETTING => $freshInstall ? 'shared-conservative' : 'custom',
        OPERATIONAL_CAPACITY_REVISION_SETTING => '1',
    ]);
}

function operational_capacity_detect_fresh_install(PDO $pdo): bool
{
    try {
        return (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
    } catch (Throwable) {
        return false;
    }
}

function operational_capacity_schema_statements(PDO $pdo): array
{
    if (db_uses_mysql_syntax($pdo)) {
        return [
            "CREATE TABLE IF NOT EXISTS operational_capacity_requests (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(96) NOT NULL UNIQUE,
                request_hash VARCHAR(64) NOT NULL,
                selected_profile VARCHAR(64) NOT NULL,
                result_revision INT NOT NULL,
                actor_user_id INT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_operational_capacity_requests_created (created_at),
                CONSTRAINT fk_operational_capacity_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }
    return [
        "CREATE TABLE IF NOT EXISTS operational_capacity_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            request_hash TEXT NOT NULL,
            selected_profile TEXT NOT NULL,
            result_revision INTEGER NOT NULL,
            actor_user_id INTEGER DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
        )",
        'CREATE INDEX IF NOT EXISTS idx_operational_capacity_requests_created ON operational_capacity_requests(created_at)',
    ];
}

function operational_capacity_install(PDO $pdo, ?bool $freshInstall = null): void
{
    foreach (operational_capacity_schema_statements($pdo) as $statement) $pdo->exec($statement);
    $freshInstall ??= operational_capacity_detect_fresh_install($pdo);
    foreach (operational_capacity_setting_defaults($freshInstall) as $key => $value) {
        $stmt = $pdo->prepare('SELECT 1 FROM app_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        if (!$stmt->fetchColumn()) set_app_setting($pdo, $key, (string)$value);
    }
}

function operational_capacity_schema_valid(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT public_id, request_hash, selected_profile, result_revision, actor_user_id, created_at FROM operational_capacity_requests WHERE 1 = 0');
        foreach (array_keys(operational_capacity_setting_defaults(false)) as $key) {
            $stmt = $pdo->prepare('SELECT 1 FROM app_settings WHERE setting_key = ? LIMIT 1');
            $stmt->execute([$key]);
            if (!$stmt->fetchColumn()) return false;
        }
        return true;
    } catch (Throwable) {
        return false;
    }
}

function operational_capacity_validate_values(array $values, bool $complete = true): array
{
    $definitions = operational_capacity_definitions();
    if ($complete && array_diff(array_keys($definitions), array_keys($values))) {
        throw new OperationalCapacityException('Every profile-owned capacity value is required.', 'CAPACITY_VALUES_INCOMPLETE', 400);
    }
    $normalized = [];
    foreach ($values as $key => $value) {
        if (!isset($definitions[$key])) {
            throw new OperationalCapacityException('An unknown capacity setting was provided.', 'CAPACITY_SETTING_UNKNOWN', 400);
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new OperationalCapacityException($definitions[$key]['label'] . ' must be a whole number.', 'CAPACITY_VALUE_INVALID', 400);
        }
        $integer = (int)$value;
        if ($integer < $definitions[$key]['minimum'] || $integer > $definitions[$key]['maximum']) {
            throw new OperationalCapacityException(
                $definitions[$key]['label'] . ' must remain inside the certified hard safety envelope.',
                'CAPACITY_VALUE_OUTSIDE_CERTIFIED_ENVELOPE',
                400
            );
        }
        $normalized[$key] = $integer;
    }
    return $normalized;
}

function operational_capacity_values(PDO $pdo): array
{
    $defaults = operational_capacity_profiles()['shared-conservative']['values'];
    $values = [];
    foreach ($defaults as $key => $default) $values[$key] = (int)app_setting($pdo, $key, (string)$default);
    return operational_capacity_validate_values($values);
}

function operational_capacity_profile_match(array $values): string
{
    foreach (operational_capacity_profiles() as $id => $profile) {
        if ($id !== 'custom' && $profile['values'] === $values) return $id;
    }
    return 'custom';
}

function operational_capacity_counts(PDO $pdo): array
{
    $safeCount = static function (PDO $pdo, string $sql): int {
        try {
            return max(0, (int)$pdo->query($sql)->fetchColumn());
        } catch (Throwable) {
            return 0;
        }
    };
    $cutoff = gmdate('Y-m-d H:i:s', time() - 120);
    $presence = $pdo->prepare('SELECT COUNT(*) FROM participants WHERE COALESCE(last_seen_at, joined_at) >= ?');
    try {
        $presence->execute([$cutoff]);
        $activeParticipants = max(0, (int)$presence->fetchColumn());
    } catch (Throwable) {
        $activeParticipants = $safeCount($pdo, 'SELECT COUNT(*) FROM participants');
    }
    return [
        'activeRooms' => (static function (PDO $pdo, string $cutoff): int {
            try {
                $stmt = $pdo->prepare('SELECT COUNT(DISTINCT session_id) FROM participants WHERE COALESCE(last_seen_at, joined_at) >= ?');
                $stmt->execute([$cutoff]);
                return max(0, (int)$stmt->fetchColumn());
            } catch (Throwable) {
                return 0;
            }
        })($pdo, $cutoff),
        'activeUsers' => (static function (PDO $pdo, string $cutoff): int {
            try {
                $stmt = $pdo->prepare('SELECT COUNT(DISTINCT user_id) FROM participants WHERE COALESCE(last_seen_at, joined_at) >= ?');
                $stmt->execute([$cutoff]);
                return max(0, (int)$stmt->fetchColumn());
            } catch (Throwable) {
                return 0;
            }
        })($pdo, $cutoff),
        'activeParticipants' => $activeParticipants,
    ];
}

function operational_capacity_projection(PDO $pdo): array
{
    $values = operational_capacity_values($pdo);
    $storedProfile = app_setting($pdo, OPERATIONAL_CAPACITY_PROFILE_SETTING, 'custom');
    if (!isset(operational_capacity_profiles()[$storedProfile])) $storedProfile = 'custom';
    if ($storedProfile !== 'custom' && operational_capacity_profiles()[$storedProfile]['values'] !== $values) $storedProfile = 'custom';
    $counts = operational_capacity_counts($pdo);
    $definitions = operational_capacity_definitions();
    $utilization = [
        'activeRooms' => [
            'current' => $counts['activeRooms'],
            'target' => $values['capacity_active_rooms_target'],
        ],
        'activeUsers' => [
            'current' => $counts['activeUsers'],
            'target' => $values['capacity_active_users_target'],
        ],
        'activeParticipants' => [
            'current' => $counts['activeParticipants'],
            'target' => $values['capacity_active_participants_target'],
        ],
    ];
    foreach ($utilization as &$row) {
        $row['percent'] = $row['target'] > 0 ? min(999, (int)round(($row['current'] / $row['target']) * 100)) : 0;
        $row['warning'] = $row['current'] > $row['target'];
    }
    unset($row);
    $profiles = [];
    foreach (operational_capacity_profiles() as $id => $profile) {
        $profiles[] = [
            'id' => $id,
            'label' => $profile['label'],
            'description' => $profile['description'],
            'values' => $profile['values'],
            'applicable' => $id !== 'custom',
        ];
    }
    return [
        'schemaId' => 'chatspace.operational-capacity',
        'schemaVersion' => 1,
        'revision' => max(1, (int)app_setting($pdo, OPERATIONAL_CAPACITY_REVISION_SETTING, '1')),
        'selectedProfile' => $storedProfile,
        'selectedProfileLabel' => operational_capacity_profiles()[$storedProfile]['label'],
        'values' => $values,
        'definitions' => $definitions,
        'profiles' => $profiles,
        'provenance' => OPERATIONAL_CAPACITY_PROVENANCE,
        'freshInstallDefault' => 'shared-conservative',
        'upgradeBehavior' => 'Preserve existing behavior and classify it as Custom until an administrator deliberately applies a measured profile.',
        'utilization' => $utilization,
        'warnings' => array_values(array_map(
            static fn(string $key): string => $key . ' is above its measured health target.',
            array_keys(array_filter($utilization, static fn(array $row): bool => $row['warning']))
        )),
        'safetyBoundary' => 'Capacity values do not weaken authentication, authorization, privacy, validation, integrity, emergency host protection, or resource-exhaustion safeguards.',
        'unmeasured' => [
            'uploadDownloadConcurrency' => 'No safe adjustable bound established; mandatory existing limits remain.',
            'voiceParticipation' => 'Recommendation deferred to the named voice-quality audit.',
            'floodProtection' => 'No adjustable recommendation is available; existing protection remains active.',
        ],
    ];
}

function operational_capacity_preview(PDO $pdo, string $profileId): array
{
    $profile = operational_capacity_profiles()[$profileId] ?? null;
    if (!$profile || $profileId === 'custom') {
        throw new OperationalCapacityException('Choose one measured capacity profile.', 'CAPACITY_PROFILE_INVALID', 400);
    }
    $current = operational_capacity_values($pdo);
    $changes = [];
    foreach ($profile['values'] as $key => $value) {
        if ($current[$key] === $value) continue;
        $changes[] = [
            'settingId' => $key,
            'label' => operational_capacity_definitions()[$key]['label'],
            'currentValue' => $current[$key],
            'proposedValue' => $value,
            'impact' => $value < $current[$key]
                ? 'The measured target or bounded work size will be reduced; current activity is not terminated.'
                : 'The measured target or bounded work size will increase inside the certified hard envelope.',
        ];
    }
    return [
        'profileId' => $profileId,
        'profileLabel' => $profile['label'],
        'expectedRevision' => operational_capacity_projection($pdo)['revision'],
        'changes' => $changes,
        'changeCount' => count($changes),
        'requiresConfirmation' => true,
        'provenance' => OPERATIONAL_CAPACITY_PROVENANCE,
        'currentUtilization' => operational_capacity_projection($pdo)['utilization'],
    ];
}

function operational_capacity_request_id(string $value): string
{
    $value = trim($value);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,95}$/', $value)) {
        throw new OperationalCapacityException('A stable capacity request ID is required.', 'CAPACITY_REQUEST_ID_INVALID', 400);
    }
    return $value;
}

function operational_capacity_store_values_locked(PDO $pdo, array $values, string $profileId, int $nextRevision): void
{
    foreach (operational_capacity_validate_values($values) as $key => $value) set_app_setting($pdo, $key, (string)$value);
    set_app_setting($pdo, OPERATIONAL_CAPACITY_PROFILE_SETTING, $profileId);
    set_app_setting($pdo, OPERATIONAL_CAPACITY_REVISION_SETTING, (string)$nextRevision);
}

function operational_capacity_apply_custom_values_locked(PDO $pdo, array $values, int $expectedRevision): array
{
    $projection = operational_capacity_projection($pdo);
    if ($expectedRevision !== (int)$projection['revision']) {
        throw new OperationalCapacityException('Capacity settings changed in another context. Reload and review again.', 'CAPACITY_REVISION_STALE', 409, $projection);
    }
    $normalized = operational_capacity_validate_values($values, false);
    $merged = array_replace($projection['values'], $normalized);
    operational_capacity_store_values_locked($pdo, $merged, 'custom', $expectedRevision + 1);
    return operational_capacity_projection($pdo);
}

function operational_capacity_apply_profile(
    PDO $pdo,
    string $profileId,
    mixed $expectedRevision,
    string $requestId,
    bool $confirmed,
    int $actorUserId
): array {
    if (!$confirmed) {
        throw new OperationalCapacityException('Review and confirm every proposed capacity change.', 'CAPACITY_PROFILE_CONFIRMATION_REQUIRED', 409, operational_capacity_preview($pdo, $profileId));
    }
    $profile = operational_capacity_profiles()[$profileId] ?? null;
    if (!$profile || $profileId === 'custom') {
        throw new OperationalCapacityException('Choose one measured capacity profile.', 'CAPACITY_PROFILE_INVALID', 400);
    }
    if (filter_var($expectedRevision, FILTER_VALIDATE_INT) === false || (int)$expectedRevision < 1) {
        throw new OperationalCapacityException('A current capacity revision is required.', 'CAPACITY_REVISION_REQUIRED', 400);
    }
    $expectedRevision = (int)$expectedRevision;
    $requestId = operational_capacity_request_id($requestId);
    $requestHash = hash('sha256', $profileId . "\n" . $expectedRevision);
    $transaction = database_transaction_begin($pdo, true);
    try {
        $existing = $pdo->prepare('SELECT request_hash, selected_profile, result_revision FROM operational_capacity_requests WHERE public_id = ? LIMIT 1');
        $existing->execute([$requestId]);
        $prior = $existing->fetch();
        if ($prior) {
            if (!hash_equals((string)$prior['request_hash'], $requestHash)) {
                throw new OperationalCapacityException('That request ID was already used for a different capacity action.', 'CAPACITY_REQUEST_ID_REUSED', 409);
            }
            database_transaction_commit($pdo, $transaction);
            return ['ok' => true, 'idempotent' => true, 'capacity' => operational_capacity_projection($pdo)];
        }
        $actualRevision = max(1, (int)app_setting($pdo, OPERATIONAL_CAPACITY_REVISION_SETTING, '1'));
        if ($actualRevision !== $expectedRevision) {
            throw new OperationalCapacityException('Capacity settings changed in another context. Reload and review again.', 'CAPACITY_REVISION_STALE', 409, operational_capacity_projection($pdo));
        }
        operational_capacity_store_values_locked($pdo, $profile['values'], $profileId, $actualRevision + 1);
        set_app_setting($pdo, SETTINGS_REGISTRY_REVISION_SETTING, (string)(settings_registry_revision($pdo) + 1));
        $pdo->prepare('INSERT INTO operational_capacity_requests (public_id, request_hash, selected_profile, result_revision, actor_user_id) VALUES (?,?,?,?,?)')
            ->execute([$requestId, $requestHash, $profileId, $actualRevision + 1, $actorUserId > 0 ? $actorUserId : null]);
        log_tool(
            $pdo,
            $actorUserId > 0 ? $actorUserId : null,
            'admin_operational_capacity_profile_apply',
            null,
            null,
            'Applied capacity profile ' . $profileId . '; revision ' . $actualRevision . ' to ' . ($actualRevision + 1) . '; changed values ' . count($profile['values']) . '; measurement details retained.'
        );
        database_transaction_commit($pdo, $transaction);
        return ['ok' => true, 'idempotent' => false, 'capacity' => operational_capacity_projection($pdo)];
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
}
