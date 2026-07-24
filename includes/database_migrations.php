<?php
declare(strict_types=1);

/**
 * Build 000048 Part 1 authoritative database migration owner.
 *
 * Runtime may inspect compatibility through this owner. Only the protected
 * Setup/database-update POST adapters may execute the ordered core manifest.
 */

const CORE_MIGRATION_STATE_KEY = 'core_migration_state';
const CORE_MIGRATION_REQUIRED_ID = '2026-07-23-001-versioned-migration-control-plane';
const CORE_MIGRATION_MAX_STATE_BYTES = 32768;
const CORE_MIGRATION_BACKUP_MAX_STDERR_BYTES = 32768;
const CORE_MIGRATION_MARIADB_BACKUP_FORMAT = 'corechat-mariadb-logical-backup';
const CORE_MIGRATION_MARIADB_BACKUP_FORMAT_VERSION = 1;
const CORE_MIGRATION_MARIADB_BACKUP_MAX_RECORD_BYTES = 67108864;

final class CoreMigrationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'MIGRATION_FAILED',
        public readonly int $httpStatus = 409,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}

function database_migrations_canonicalize(mixed $value): mixed
{
    if (!is_array($value)) return $value;
    if (array_is_list($value)) return array_map('database_migrations_canonicalize', $value);
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = database_migrations_canonicalize($item);
    return $value;
}

function database_migrations_canonical_json(array $value): string
{
    $json = json_encode(
        database_migrations_canonicalize($value),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if (!is_string($json)) throw new CoreMigrationException('Migration metadata could not be encoded.', 'MIGRATION_METADATA_ENCODING_FAILED', 500);
    return $json;
}

function database_migrations_function_source_checksum(string $function): string
{
    if (!function_exists($function)) return '';
    $reflection = new ReflectionFunction($function);
    $file = $reflection->getFileName();
    if (!is_string($file) || !is_file($file)) return '';
    $lines = file($file);
    if (!is_array($lines)) return '';
    $source = implode('', array_slice(
        $lines,
        max(0, $reflection->getStartLine() - 1),
        max(0, $reflection->getEndLine() - $reflection->getStartLine() + 1)
    ));
    return strtoupper(hash('sha256', str_replace("\r\n", "\n", $source)));
}

function database_migrations_manifest(): array
{
    $definitions = [
        [
            'id' => '2026-07-19-001-core-published-baseline',
            'title' => 'Core published baseline',
            'owner' => 'core',
            'atomicity' => 'engine-specific',
            'revision' => 1,
            'up' => 'database_migration_apply_published_baseline',
            'validate' => 'database_migration_validate_published_baseline',
            'source_functions' => [
                'core_schema_install_published_baseline',
                'database_migration_ensure_participant_profile_compatibility',
                'migrate_avatar_relationship_group_schema',
                'runtime_issue_install_schema',
                'gesture_catalog_install_base_schema',
                'gesture_catalog_add_columns',
                'gesture_catalog_create_tables',
                'gesture_catalog_backfill',
                'gesture_catalog_index',
            ],
            'expected_checksum' => 'B2868EDA1E0114EC9F5AC04D5207D9F83D996545E72DD61E7AC661AB4206FB75',
        ],
        [
            'id' => '2026-07-21-001-gesture-presentation-preferences',
            'title' => 'Gesture presentation preferences',
            'owner' => 'core',
            'atomicity' => 'transactional-sqlite',
            'revision' => 1,
            'up' => 'database_migration_apply_gesture_part3',
            'validate' => 'database_migration_validate_gesture_part3',
            'source_functions' => [
                'gesture_catalog_install_part3_schema',
                'gesture_catalog_add_part3_preference_columns',
            ],
            'expected_checksum' => '4DF98B1FEE0AE2D0A0117727290D7C2ACC633B1A37CE49646F517296C39CE335',
        ],
        [
            'id' => '2026-07-21-002-gesture-protected-packages',
            'title' => 'Gesture protected packages',
            'owner' => 'core',
            'atomicity' => 'transactional-sqlite-forward-mariadb',
            'revision' => 1,
            'up' => 'database_migration_apply_gesture_part4',
            'validate' => 'database_migration_validate_gesture_part4',
            'source_functions' => [
                'gesture_package_install_schema',
                'gesture_package_add_columns',
                'gesture_package_create_tables',
                'gesture_package_backfill',
                'gesture_package_legacy_manifest',
            ],
            'expected_checksum' => 'B2B72E9BD3265B859E5BC85000BF2A539D47DCC475093C22D00AD41C35EB0567',
        ],
        [
            'id' => '2026-07-22-001-gesture-sender-visibility',
            'title' => 'Gesture sender visibility',
            'owner' => 'core',
            'atomicity' => 'transactional-sqlite',
            'revision' => 1,
            'up' => 'database_migration_apply_gesture_part5',
            'validate' => 'database_migration_validate_gesture_part5',
            'source_functions' => [
                'gesture_catalog_install_part5_schema',
                'gesture_catalog_create_sender_visibility_table',
                'gesture_catalog_add_part5_preference_columns',
                'gesture_catalog_index',
            ],
            'expected_checksum' => 'B718F92944F87F8ACBDEDD0A7DAAE5CB249537242AE31E13A2682837F3A33576',
        ],
        [
            'id' => CORE_MIGRATION_REQUIRED_ID,
            'title' => 'Versioned migration control plane',
            'owner' => 'core',
            'atomicity' => 'transactional-sqlite-forward-mariadb',
            'revision' => 1,
            'up' => 'database_migrations_bootstrap_control_tables',
            'validate' => 'database_migration_validate_control_plane',
            'source_functions' => [
                'database_migrations_bootstrap_control_tables',
            ],
            'expected_checksum' => '1702070EA47601A1DDE162528F88A5AF39912681EDF03F301AAB86B70BDC13C8',
        ],
    ];
    foreach ($definitions as &$definition) {
        $material = $definition;
        unset($material['expected_checksum']);
        $material['source_functions'] = array_values(array_unique(array_merge(
            [$material['up'], $material['validate']],
            $material['source_functions']
        )));
        $material['source_sha256'] = [];
        foreach ($material['source_functions'] as $function) {
            $material['source_sha256'][$function] = database_migrations_function_source_checksum($function);
        }
        $definition['checksum'] = strtoupper(hash('sha256', database_migrations_canonical_json($material)));
    }
    unset($definition);
    return $definitions;
}

function database_migrations_release_preflight(): array
{
    $manifest = database_migrations_manifest();
    $ids = [];
    $defects = [];
    foreach ($manifest as $migration) {
        $id = (string)$migration['id'];
        if (isset($ids[$id])) $defects[] = "Duplicate migration ID: {$id}.";
        $ids[$id] = true;
        if (($migration['owner'] ?? '') !== 'core') $defects[] = "Unsupported migration owner for {$id}.";
        if (!is_callable($migration['up'] ?? null)) $defects[] = "Missing migration operation for {$id}.";
        if (!is_callable($migration['validate'] ?? null)) $defects[] = "Missing migration validator for {$id}.";
        if (!hash_equals((string)$migration['expected_checksum'], (string)$migration['checksum'])) {
            $defects[] = "Migration definition checksum mismatch for {$id}.";
        }
    }
    $requiredFiles = [
        __FILE__,
        __DIR__ . '/base.php',
        __DIR__ . '/gesture_catalog_service.php',
        __DIR__ . '/gesture_package_service.php',
        dirname(__DIR__) . '/database-update.php',
        dirname(__DIR__) . '/setup.php',
    ];
    foreach ($requiredFiles as $path) {
        if (!is_file($path) || !is_readable($path)) $defects[] = 'Required migration release file is missing: ' . basename($path) . '.';
    }
    if (PHP_SAPI === 'cli-server'
        && defined('CHATSPACE_RUNTIME_VERIFICATION_CONTROLS_ENABLED')
        && CHATSPACE_RUNTIME_VERIFICATION_CONTROLS_ENABLED === true
        && defined('CHATSPACE_MIGRATION_VERIFICATION_FORCE_INCOMPLETE_RELEASE')
        && CHATSPACE_MIGRATION_VERIFICATION_FORCE_INCOMPLETE_RELEASE === true) {
        $defects[] = 'Verification fixture forced an incomplete migration release.';
    }
    return [
        'ok' => $defects === [],
        'defects' => $defects,
        'required_schema_version' => CHATSPACE_SCHEMA_VERSION,
        'required_migration_id' => CORE_MIGRATION_REQUIRED_ID,
        'migration_count' => count($manifest),
        'ids' => array_keys($ids),
    ];
}

function database_migration_table_exists(PDO $pdo, string $table): bool
{
    if (!preg_match('/^[a-z][a-z0-9_]*$/', $table)) return false;
    if (db_driver($pdo) === 'mysql') {
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? AND table_type = ? LIMIT 1');
        $stmt->execute([$table, 'BASE TABLE']);
        return (bool)$stmt->fetchColumn();
    }
    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ? LIMIT 1");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function database_migration_columns(PDO $pdo, string $table): array
{
    if (!database_migration_table_exists($pdo, $table)) return [];
    if (db_driver($pdo) === 'mysql') {
        $stmt = $pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position');
        $stmt->execute([$table]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    return array_map(
        static fn(array $column): string => (string)$column['name'],
        $pdo->query("PRAGMA table_info({$table})")->fetchAll()
    );
}

function database_migration_has_columns(PDO $pdo, string $table, array $required): bool
{
    $columns = array_fill_keys(database_migration_columns($pdo, $table), true);
    foreach ($required as $column) {
        if (!isset($columns[$column])) return false;
    }
    return true;
}

function database_migration_read_setting(PDO $pdo, string $key): ?string
{
    if (!database_migration_table_exists($pdo, 'app_settings')) return null;
    try {
        $stmt = $pdo->prepare('SELECT value FROM app_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string)$value;
    } catch (Throwable) {
        return null;
    }
}

function database_migration_write_setting(PDO $pdo, string $key, string $value): void
{
    if (strlen($value) > CORE_MIGRATION_MAX_STATE_BYTES) {
        throw new CoreMigrationException('Migration state exceeded its bounded size.', 'MIGRATION_STATE_TOO_LARGE', 500);
    }
    if (db_driver($pdo) === 'mysql') {
        $pdo->prepare(
            'INSERT INTO app_settings (setting_key, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)'
        )->execute([$key, $value]);
        return;
    }
    $pdo->prepare(
        'INSERT INTO app_settings (setting_key, value) VALUES (?, ?) ON CONFLICT(setting_key) DO UPDATE SET value = excluded.value'
    )->execute([$key, $value]);
}

function database_migration_state(PDO $pdo): array
{
    $raw = database_migration_read_setting($pdo, CORE_MIGRATION_STATE_KEY);
    if ($raw === null || $raw === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['status' => 'invalid', 'phase' => 'state-decode-failed'];
}

function database_migration_write_state(PDO $pdo, array $state): void
{
    $state['updated_at'] = gmdate('c');
    database_migration_write_setting($pdo, CORE_MIGRATION_STATE_KEY, database_migrations_canonical_json($state));
}

function database_migration_baseline_tables(): array
{
    return [
        'app_settings', 'auth_attempts', 'avatar_hidden_preferences', 'avatar_relationship_members',
        'avatar_relationship_membership_history', 'avatar_relationship_requests', 'avatar_relationships',
        'community_ejections', 'community_events', 'community_message_reactions', 'community_messages',
        'events', 'game_chat_messages', 'game_lobbies', 'game_moves', 'game_sessions', 'game_state',
        'gesture_custom_order', 'gesture_downloads', 'gesture_hidden', 'gesture_operation_requests',
        'gesture_preferences', 'gestures', 'link_icon_catalog', 'link_icons', 'media_signal_clients',
        'media_signals', 'message_reactions', 'messages', 'participants', 'private_message_clears',
        'room_deletion_notices', 'room_effects', 'room_ejections', 'room_sessions', 'rooms',
        'runtime_issue_occurrences', 'runtime_issue_screenshots', 'runtime_issue_status_history',
        'runtime_issues', 'runtime_maintenance_leases', 'tool_logs', 'user_blocks', 'users', 'voice_sessions',
    ];
}

function database_migration_table_names(PDO $pdo): array
{
    if (db_driver($pdo) === 'mysql') {
        return array_map(
            'strval',
            $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN)
        );
    }
    return array_map(
        'strval',
        $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN)
    );
}

function database_migration_bundled_seed_tables(): array
{
    return [
        'app_settings', 'auth_attempts', 'community_ejections', 'community_events',
        'community_message_reactions', 'community_messages', 'events', 'game_chat_messages',
        'game_lobbies', 'game_moves', 'game_sessions', 'game_state', 'gestures',
        'link_icon_catalog', 'link_icons', 'media_signals', 'message_reactions', 'messages',
        'participants', 'private_message_clears', 'room_deletion_notices', 'room_effects',
        'room_ejections', 'room_sessions', 'rooms', 'tool_logs', 'user_blocks', 'users',
        'voice_sessions',
    ];
}

function database_migration_validate_legacy_application_baseline(PDO $pdo): bool
{
    foreach (database_migration_baseline_tables() as $table) {
        if (!database_migration_table_exists($pdo, $table)) return false;
    }
    $requiredColumns = [
        'users' => ['id', 'email', 'password_hash', 'display_name', 'role', 'avatar_visibility_version'],
        'rooms' => ['id', 'public_id', 'owner_id', 'name'],
        'room_sessions' => ['id', 'public_id', 'room_id'],
        'participants' => ['id', 'session_id', 'user_id', 'join_token'],
        'messages' => ['id', 'session_id', 'content', 'message_type', 'sent_at'],
        'gestures' => ['id', 'public_id', 'owner_user_id', 'gesture_text', 'version'],
        'gesture_preferences' => ['user_id', 'preference_version', 'server_order_version', 'personal_order_version', 'hidden_version'],
        'app_settings' => ['setting_key', 'value'],
        'tool_logs' => ['id', 'actor_user_id', 'action', 'detail'],
    ];
    foreach ($requiredColumns as $table => $columns) {
        if (!database_migration_has_columns($pdo, $table, $columns)) return false;
    }
    return true;
}

function database_migration_validate_published_baseline(PDO $pdo): bool
{
    return database_migration_validate_legacy_application_baseline($pdo)
        && database_migration_has_columns($pdo, 'participants', [
            'profile_location', 'profile_about', 'profile_visibility', 'email_changed_at', 'password_changed_at',
        ]);
}

function database_migration_validate_gesture_part3(PDO $pdo): bool
{
    if (!database_migration_has_columns($pdo, 'gesture_preferences', ['show_animations', 'show_text', 'play_sounds'])) return false;
    return (int)$pdo->query(
        'SELECT COUNT(*) FROM gesture_preferences WHERE show_animations NOT IN (0,1) OR show_text NOT IN (0,1) OR play_sounds NOT IN (0,1)'
    )->fetchColumn() === 0;
}

function database_migration_validate_gesture_part4(PDO $pdo): bool
{
    if (!database_migration_has_columns($pdo, 'gestures', [
        'package_generation', 'package_has_poster', 'package_status', 'package_version',
        'package_sha256', 'content_sha256', 'media_access_token', 'package_updated_at',
    ]) && database_migration_has_columns($pdo, 'gesture_package_generations', [
        'id', 'gesture_id', 'generation', 'package_version', 'manifest_json',
        'media_access_token', 'validation_status', 'created_by_user_id',
    ])) return false;
    return (int)$pdo->query(
        'SELECT COUNT(*) FROM gestures g LEFT JOIN gesture_package_generations pg ON pg.gesture_id = g.id AND pg.generation = g.package_generation '
        . 'WHERE g.package_generation < 1 OR pg.id IS NULL'
    )->fetchColumn() === 0;
}

function database_migration_validate_gesture_part5(PDO $pdo): bool
{
    return database_migration_has_columns($pdo, 'gesture_preferences', ['sender_visibility_version'])
        && database_migration_has_columns($pdo, 'gesture_sender_media_hidden', ['viewer_user_id', 'target_user_id', 'hidden_at']);
}

function database_migration_control_tables(): array
{
    return ['core_migration_ledger', 'core_migration_attempts', 'core_migration_backups'];
}

function database_migration_validate_control_plane(PDO $pdo): bool
{
    foreach (database_migration_control_tables() as $table) {
        if (!database_migration_table_exists($pdo, $table)) return false;
    }
    return database_migration_has_columns($pdo, 'core_migration_ledger', ['migration_id', 'checksum', 'attempt_public_id', 'result'])
        && database_migration_has_columns($pdo, 'core_migration_attempts', ['public_id', 'status', 'phase', 'current_migration_id', 'backup_public_id'])
        && database_migration_has_columns($pdo, 'core_migration_backups', ['public_id', 'engine', 'storage_name', 'byte_size', 'sha256', 'verification_json']);
}

function database_migration_apply_published_baseline(PDO $pdo): void
{
    core_schema_install_published_baseline($pdo);
    database_migration_ensure_participant_profile_compatibility($pdo);
}

function database_migration_ensure_participant_profile_compatibility(PDO $pdo): void
{
    $columns = database_migration_columns($pdo, 'participants');
    $definitions = db_driver($pdo) === 'mysql'
        ? [
            'profile_location' => "VARCHAR(80) NOT NULL DEFAULT ''",
            'profile_about' => "VARCHAR(500) NOT NULL DEFAULT ''",
            'profile_visibility' => "VARCHAR(24) NOT NULL DEFAULT 'community'",
            'email_changed_at' => 'DATETIME DEFAULT NULL',
            'password_changed_at' => 'DATETIME DEFAULT NULL',
        ]
        : [
            'profile_location' => "TEXT NOT NULL DEFAULT ''",
            'profile_about' => "TEXT NOT NULL DEFAULT ''",
            'profile_visibility' => "TEXT NOT NULL DEFAULT 'community'",
            'email_changed_at' => 'TEXT DEFAULT NULL',
            'password_changed_at' => 'TEXT DEFAULT NULL',
        ];
    foreach ($definitions as $column => $definition) {
        if (!in_array($column, $columns, true)) {
            $pdo->exec("ALTER TABLE participants ADD COLUMN {$column} {$definition}");
        }
    }
}

function database_migration_apply_gesture_part3(PDO $pdo): void
{
    gesture_catalog_install_part3_schema($pdo);
}

function database_migration_apply_gesture_part4(PDO $pdo): void
{
    gesture_package_install_schema($pdo);
}

function database_migration_apply_gesture_part5(PDO $pdo): void
{
    gesture_catalog_install_part5_schema($pdo);
}

function database_migrations_bootstrap_control_tables(PDO $pdo): void
{
    if (db_driver($pdo) === 'mysql') {
        $statements = [
            "CREATE TABLE IF NOT EXISTS core_migration_attempts (
                public_id VARCHAR(64) PRIMARY KEY,
                owner_token VARCHAR(96) NOT NULL,
                actor_user_id INT DEFAULT NULL,
                engine VARCHAR(16) NOT NULL,
                source_variant VARCHAR(64) NOT NULL,
                source_schema_version VARCHAR(96) DEFAULT NULL,
                target_schema_version VARCHAR(96) NOT NULL,
                status VARCHAR(32) NOT NULL,
                phase VARCHAR(64) NOT NULL,
                current_migration_id VARCHAR(96) DEFAULT NULL,
                backup_public_id VARCHAR(64) DEFAULT NULL,
                error_code VARCHAR(96) DEFAULT NULL,
                error_message VARCHAR(512) DEFAULT NULL,
                started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME DEFAULT NULL,
                INDEX idx_core_migration_attempt_status (status, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS core_migration_backups (
                public_id VARCHAR(64) PRIMARY KEY,
                attempt_public_id VARCHAR(64) NOT NULL,
                engine VARCHAR(16) NOT NULL,
                storage_name VARCHAR(191) NOT NULL UNIQUE,
                byte_size BIGINT NOT NULL,
                sha256 VARCHAR(64) NOT NULL,
                source_schema_version VARCHAR(96) DEFAULT NULL,
                verification_json LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_core_migration_backup_attempt (attempt_public_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS core_migration_ledger (
                migration_id VARCHAR(96) PRIMARY KEY,
                title VARCHAR(191) NOT NULL,
                owner_namespace VARCHAR(64) NOT NULL DEFAULT 'core',
                checksum VARCHAR(64) NOT NULL,
                result VARCHAR(32) NOT NULL,
                attempt_public_id VARCHAR(64) NOT NULL,
                applied_by_user_id INT DEFAULT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_core_migration_ledger_attempt (attempt_public_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    } else {
        $statements = [
            "CREATE TABLE IF NOT EXISTS core_migration_attempts (
                public_id TEXT PRIMARY KEY,
                owner_token TEXT NOT NULL,
                actor_user_id INTEGER DEFAULT NULL,
                engine TEXT NOT NULL,
                source_variant TEXT NOT NULL,
                source_schema_version TEXT DEFAULT NULL,
                target_schema_version TEXT NOT NULL,
                status TEXT NOT NULL,
                phase TEXT NOT NULL,
                current_migration_id TEXT DEFAULT NULL,
                backup_public_id TEXT DEFAULT NULL,
                error_code TEXT DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at TEXT DEFAULT NULL
            )",
            "CREATE TABLE IF NOT EXISTS core_migration_backups (
                public_id TEXT PRIMARY KEY,
                attempt_public_id TEXT NOT NULL,
                engine TEXT NOT NULL,
                storage_name TEXT NOT NULL UNIQUE,
                byte_size INTEGER NOT NULL,
                sha256 TEXT NOT NULL,
                source_schema_version TEXT DEFAULT NULL,
                verification_json TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS core_migration_ledger (
                migration_id TEXT PRIMARY KEY,
                title TEXT NOT NULL,
                owner_namespace TEXT NOT NULL DEFAULT 'core',
                checksum TEXT NOT NULL,
                result TEXT NOT NULL,
                attempt_public_id TEXT NOT NULL,
                applied_by_user_id INTEGER DEFAULT NULL,
                applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )",
            'CREATE INDEX IF NOT EXISTS idx_core_migration_attempt_status ON core_migration_attempts(status, updated_at)',
            'CREATE INDEX IF NOT EXISTS idx_core_migration_backup_attempt ON core_migration_backups(attempt_public_id)',
            'CREATE INDEX IF NOT EXISTS idx_core_migration_ledger_attempt ON core_migration_ledger(attempt_public_id)',
        ];
    }
    foreach ($statements as $statement) $pdo->exec($statement);
}

function database_migration_variant(PDO $pdo): array
{
    if (!database_migration_table_exists($pdo, 'users') && !database_migration_table_exists($pdo, 'app_settings')) {
        return ['id' => 'empty', 'rank' => -1, 'recognized' => true];
    }
    if (!database_migration_validate_legacy_application_baseline($pdo)) {
        $actualTables = database_migration_table_names($pdo);
        $seedTables = database_migration_bundled_seed_tables();
        sort($actualTables, SORT_STRING);
        sort($seedTables, SORT_STRING);
        $seedUserCount = database_migration_table_exists($pdo, 'users')
            ? (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()
            : -1;
        if ($actualTables === $seedTables && $seedUserCount === 0 && database_migration_read_setting($pdo, 'schema_version') === null) {
            return ['id' => 'bundled-seed', 'rank' => -1, 'recognized' => true];
        }
        return ['id' => 'unknown-or-partial', 'rank' => -1, 'recognized' => false];
    }
    $part3Columns = array_map(
        static fn(string $column): bool => in_array($column, database_migration_columns($pdo, 'gesture_preferences'), true),
        ['show_animations', 'show_text', 'play_sounds']
    );
    $part3Count = count(array_filter($part3Columns));
    $part4Columns = array_map(
        static fn(string $column): bool => in_array($column, database_migration_columns($pdo, 'gestures'), true),
        ['package_generation', 'package_has_poster', 'package_status', 'package_version', 'package_sha256', 'content_sha256', 'media_access_token', 'package_updated_at']
    );
    $part4Count = count(array_filter($part4Columns));
    $part4Table = database_migration_table_exists($pdo, 'gesture_package_generations');
    $part5Column = in_array('sender_visibility_version', database_migration_columns($pdo, 'gesture_preferences'), true);
    $part5Table = database_migration_table_exists($pdo, 'gesture_sender_media_hidden');
    $partial = ($part3Count !== 0 && $part3Count !== 3)
        || ($part4Count !== 0 && $part4Count !== 8)
        || ($part4Count === 8) !== $part4Table
        || $part5Column !== $part5Table
        || (($part4Count > 0 || $part5Column) && $part3Count !== 3)
        || ($part5Column && $part4Count !== 8);
    if ($partial) return ['id' => 'unknown-or-partial', 'rank' => -1, 'recognized' => false];
    if ($part5Column) return ['id' => 'published-part5', 'rank' => 3, 'recognized' => true];
    if ($part4Count === 8) return ['id' => 'published-part4', 'rank' => 2, 'recognized' => true];
    if ($part3Count === 3) return ['id' => 'published-part3', 'rank' => 1, 'recognized' => true];
    return ['id' => 'published-avatar-baseline', 'rank' => 0, 'recognized' => true];
}

function database_migration_ledger_rows(PDO $pdo): array
{
    if (!database_migration_table_exists($pdo, 'core_migration_ledger')) return [];
    return $pdo->query('SELECT migration_id, title, owner_namespace, checksum, result, attempt_public_id, applied_at FROM core_migration_ledger ORDER BY applied_at ASC, migration_id ASC')->fetchAll();
}

function database_migration_validator_passes(PDO $pdo, array $migration): bool
{
    try {
        $validator = $migration['validate'];
        return $validator($pdo) === true;
    } catch (Throwable) {
        return false;
    }
}

function database_migration_status(PDO $pdo): array
{
    $preflight = database_migrations_release_preflight();
    $state = database_migration_state($pdo);
    $variant = database_migration_variant($pdo);
    $manifest = database_migrations_manifest();
    $ledger = database_migration_ledger_rows($pdo);
    $byId = [];
    foreach ($ledger as $row) $byId[(string)$row['migration_id']] = $row;
    $manifestById = [];
    foreach ($manifest as $migration) $manifestById[(string)$migration['id']] = $migration;
    $releaseDefects = $preflight['defects'];
    $stateDefects = [];
    $newer = false;
    foreach ($byId as $id => $row) {
        if (!isset($manifestById[$id])) {
            $newer = true;
            $stateDefects[] = "Unknown applied migration: {$id}.";
            continue;
        }
        if (!hash_equals((string)$manifestById[$id]['checksum'], (string)$row['checksum'])) {
            $stateDefects[] = "Completed migration checksum mismatch: {$id}.";
            continue;
        }
        if (!database_migration_validator_passes($pdo, $manifestById[$id])) {
            $stateDefects[] = "Completed migration schema mismatch: {$id}.";
        }
    }
    $pending = [];
    foreach ($manifest as $migration) {
        if (!isset($byId[$migration['id']])) {
            $pending[] = [
                'id' => $migration['id'],
                'title' => $migration['title'],
                'checksum' => $migration['checksum'],
            ];
        }
    }
    $storedVersion = database_migration_read_setting($pdo, 'schema_version');
    $stateStatus = (string)($state['status'] ?? '');
    $kind = 'older';
    if ($stateStatus === 'active') $kind = 'active';
    elseif ($stateStatus === 'failed' || $stateStatus === 'recovery-required') $kind = 'failed';
    elseif ($newer) $kind = 'newer';
    elseif (!$variant['recognized']) $kind = 'unknown';
    elseif ($storedVersion !== null && $storedVersion !== CHATSPACE_LEGACY_SCHEMA_VERSION && $storedVersion !== CHATSPACE_SCHEMA_VERSION) {
        $kind = strcmp($storedVersion, CHATSPACE_SCHEMA_VERSION) > 0 ? 'newer' : 'unknown';
    } elseif ($releaseDefects !== []) $kind = 'incomplete-release';
    elseif ($stateDefects !== []) $kind = 'inconsistent';
    elseif ($pending !== [] && ($ledger !== [] || $storedVersion === CHATSPACE_SCHEMA_VERSION)) $kind = 'inconsistent';
    elseif ($pending === [] && $storedVersion === CHATSPACE_SCHEMA_VERSION && database_migration_validate_control_plane($pdo)) $kind = 'current';
    $backupReadiness = $kind === 'current'
        ? ['ok' => true, 'method' => 'not-required']
        : (in_array($kind, ['failed'], true) && is_array($state['backup'] ?? null)
            ? ['ok' => true, 'method' => 'recovery-backup-revalidation']
            : database_migration_backup_readiness($pdo));
    return [
        'kind' => $kind,
        'current' => $kind === 'current',
        'engine' => db_driver($pdo) === 'mysql' ? 'mariadb' : 'sqlite',
        'stored_schema_version' => $storedVersion,
        'required_schema_version' => CHATSPACE_SCHEMA_VERSION,
        'variant' => $variant,
        'pending' => $pending,
        'pending_count' => count($pending),
        'release_complete' => $preflight['ok'],
        'defects' => array_values(array_unique(array_merge($releaseDefects, $stateDefects))),
        'migration_state' => $state,
        'lock_status' => $stateStatus === 'active' ? 'owned' : ($stateStatus === 'failed' ? 'recovery-required' : 'available'),
        'backup_readiness' => $backupReadiness,
    ];
}

function database_migration_mariadb_quote_identifier(string $identifier): string
{
    if ($identifier === '' || str_contains($identifier, "\0")) {
        throw new CoreMigrationException('MariaDB schema contains an invalid identifier.', 'MARIADB_BACKUP_IDENTIFIER_INVALID', 503);
    }
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function database_migration_mariadb_fetch_all(PDO $pdo, string $sql, array $params = []): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $rows = [];
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) $rows[] = $row;
    $statement->closeCursor();
    return $rows;
}

function database_migration_mariadb_inventory(PDO $pdo): array
{
    if (db_driver($pdo) !== 'mysql') {
        throw new CoreMigrationException('MariaDB inventory requires the certified MariaDB connection.', 'MARIADB_BACKUP_ENGINE_REQUIRED', 503);
    }
    $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($database === '') {
        throw new CoreMigrationException('The MariaDB connection has no selected database.', 'MARIADB_BACKUP_DATABASE_REQUIRED', 503);
    }
    $schemaRows = database_migration_mariadb_fetch_all(
        $pdo,
        'SELECT default_character_set_name, default_collation_name
           FROM information_schema.schemata
          WHERE schema_name = ?',
        [$database]
    );
    if (count($schemaRows) !== 1) {
        throw new CoreMigrationException('The selected MariaDB schema could not be inventoried.', 'MARIADB_BACKUP_SCHEMA_INVENTORY_FAILED', 503);
    }
    $tableRows = database_migration_mariadb_fetch_all(
        $pdo,
        'SELECT table_name, table_type, engine, row_format, table_collation, create_options, table_comment
           FROM information_schema.tables
          WHERE table_schema = ?
          ORDER BY table_type, table_name',
        [$database]
    );
    $columns = database_migration_mariadb_fetch_all(
        $pdo,
        'SELECT table_name, ordinal_position, column_name, column_type, is_nullable,
                column_default, extra, generation_expression, character_set_name, collation_name
           FROM information_schema.columns
          WHERE table_schema = ?
          ORDER BY table_name, ordinal_position',
        [$database]
    );
    $indexes = database_migration_mariadb_fetch_all(
        $pdo,
        'SELECT table_name, index_name, non_unique, seq_in_index, column_name,
                collation, sub_part, packed, nullable, index_type, index_comment, ignored
           FROM information_schema.statistics
          WHERE table_schema = ?
          ORDER BY table_name, index_name, seq_in_index',
        [$database]
    );
    $constraints = database_migration_mariadb_fetch_all(
        $pdo,
        'SELECT table_name, constraint_name, constraint_type
           FROM information_schema.table_constraints
          WHERE table_schema = ?
          ORDER BY table_name, constraint_name',
        [$database]
    );
    $keyColumns = database_migration_mariadb_fetch_all(
        $pdo,
        'SELECT table_name, constraint_name, ordinal_position, position_in_unique_constraint,
                column_name, referenced_table_schema, referenced_table_name, referenced_column_name
           FROM information_schema.key_column_usage
          WHERE table_schema = ?
          ORDER BY table_name, constraint_name, ordinal_position',
        [$database]
    );
    foreach ($keyColumns as &$keyColumn) {
        if (($keyColumn['referenced_table_schema'] ?? null) === $database) {
            $keyColumn['referenced_table_schema'] = '@database';
        }
    }
    unset($keyColumn);
    $referential = database_migration_mariadb_fetch_all(
        $pdo,
        'SELECT constraint_name, table_name, unique_constraint_name,
                referenced_table_name, match_option, update_rule, delete_rule
           FROM information_schema.referential_constraints
          WHERE constraint_schema = ?
          ORDER BY table_name, constraint_name',
        [$database]
    );
    $objectCounts = [];
    foreach ([
        'views' => 'SELECT COUNT(*) FROM information_schema.views WHERE table_schema = ?',
        'triggers' => 'SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = ?',
        'routines' => 'SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = ?',
        'events' => 'SELECT COUNT(*) FROM information_schema.events WHERE event_schema = ?',
    ] as $name => $sql) {
        $statement = $pdo->prepare($sql);
        $statement->execute([$database]);
        $objectCounts[$name] = (int)$statement->fetchColumn();
        $statement->closeCursor();
    }

    $group = static function (array $rows, string $table): array {
        $result = [];
        foreach ($rows as $row) {
            $name = (string)($row['table_name'] ?? '');
            unset($row['table_name']);
            $result[$name][] = $row;
        }
        return $result[$table] ?? [];
    };
    $tables = [];
    foreach ($tableRows as $tableRow) {
        $name = (string)$tableRow['table_name'];
        $show = $pdo->query('SHOW CREATE TABLE ' . database_migration_mariadb_quote_identifier($name));
        $create = $show->fetch(PDO::FETCH_NUM);
        $show->closeCursor();
        if (!is_array($create) || !isset($create[1]) || !is_string($create[1])) {
            throw new CoreMigrationException(
                'MariaDB table DDL could not be read: ' . $name . '.',
                'MARIADB_BACKUP_TABLE_DDL_FAILED',
                503
            );
        }
        $tableColumns = $group($columns, $name);
        $tableKeys = $group($keyColumns, $name);
        $primary = [];
        foreach ($tableKeys as $key) {
            if ((string)$key['constraint_name'] === 'PRIMARY') $primary[] = (string)$key['column_name'];
        }
        $tables[] = [
            'name' => $name,
            'table' => $tableRow,
            'columns' => $tableColumns,
            'indexes' => $group($indexes, $name),
            'constraints' => $group($constraints, $name),
            'key_columns' => $tableKeys,
            'referential_constraints' => $group($referential, $name),
            'primary_key' => $primary,
            'create_sql_base64' => base64_encode($create[1]),
        ];
    }
    return [
        'database_identity_sha256' => strtoupper(hash('sha256', $database)),
        'schema' => $schemaRows[0],
        'object_counts' => $objectCounts,
        'tables' => $tables,
    ];
}

function database_migration_mariadb_inventory_fingerprint(array $inventory): string
{
    return strtoupper(hash('sha256', database_migrations_canonical_json([
        'schema' => $inventory['schema'] ?? [],
        'object_counts' => $inventory['object_counts'] ?? [],
        'tables' => $inventory['tables'] ?? [],
    ])));
}

function database_migration_mariadb_assert_supported_inventory(array $inventory): void
{
    $objects = (array)($inventory['object_counts'] ?? []);
    foreach (['views', 'triggers', 'routines', 'events'] as $type) {
        if ((int)($objects[$type] ?? -1) !== 0) {
            throw new CoreMigrationException(
                'Repository-owned MariaDB backup cannot yet reproduce detected ' . $type . '; migration is blocked before mutation.',
                'MARIADB_BACKUP_UNSUPPORTED_OBJECT',
                503
            );
        }
    }
    $tables = (array)($inventory['tables'] ?? []);
    if ($tables === []) {
        throw new CoreMigrationException('MariaDB backup found no authoritative base tables.', 'MARIADB_BACKUP_SCHEMA_EMPTY', 503);
    }
    foreach ($tables as $table) {
        $name = (string)($table['name'] ?? '');
        $metadata = (array)($table['table'] ?? []);
        if ((string)($metadata['table_type'] ?? '') !== 'BASE TABLE'
            || strcasecmp((string)($metadata['engine'] ?? ''), 'InnoDB') !== 0) {
            throw new CoreMigrationException(
                'MariaDB table is not a supported transactional InnoDB base table: ' . $name . '.',
                'MARIADB_BACKUP_NONTRANSACTIONAL_TABLE',
                503
            );
        }
        if ((array)($table['primary_key'] ?? []) === []) {
            throw new CoreMigrationException(
                'MariaDB table has no deterministic primary-key order: ' . $name . '.',
                'MARIADB_BACKUP_PRIMARY_KEY_REQUIRED',
                503
            );
        }
        foreach ((array)($table['columns'] ?? []) as $column) {
            if (stripos((string)($column['extra'] ?? ''), 'GENERATED') !== false
                || trim((string)($column['generation_expression'] ?? '')) !== '') {
                throw new CoreMigrationException(
                    'MariaDB generated columns require a separately certified restore adapter: ' . $name . '.',
                    'MARIADB_BACKUP_GENERATED_COLUMN_UNSUPPORTED',
                    503
                );
            }
        }
    }
}

function database_migration_backup_write_record($handle, $contentHash, array $record): string
{
    $line = database_migrations_canonical_json($record) . "\n";
    $length = strlen($line);
    if ($length < 2 || $length > CORE_MIGRATION_MARIADB_BACKUP_MAX_RECORD_BYTES) {
        throw new CoreMigrationException(
            'A MariaDB backup record exceeds the certified bounded-record ceiling.',
            'MARIADB_BACKUP_RECORD_LIMIT',
            503
        );
    }
    $offset = 0;
    while ($offset < $length) {
        $written = fwrite($handle, substr($line, $offset));
        if ($written === false || $written === 0) {
            throw new CoreMigrationException('MariaDB backup stream write failed.', 'MARIADB_BACKUP_WRITE_FAILED', 500);
        }
        $offset += $written;
    }
    hash_update($contentHash, $line);
    return $line;
}

function database_migration_backup_readiness(PDO $pdo): array
{
    $mariaDb = db_driver($pdo) === 'mysql';
    try {
        $directory = security_private_storage_directory('migration-backups');
        if (!is_writable($directory)) return ['ok' => false, 'message' => 'Private migration backup storage is not writable.'];
        if (!$mariaDb) return ['ok' => true, 'method' => 'sqlite-vacuum-into'];
        $inventory = database_migration_mariadb_inventory($pdo);
        database_migration_mariadb_assert_supported_inventory($inventory);
        return [
            'ok' => true,
            'method' => CORE_MIGRATION_MARIADB_BACKUP_FORMAT,
            'table_count' => count((array)$inventory['tables']),
            'schema_inventory_sha256' => database_migration_mariadb_inventory_fingerprint($inventory),
        ];
    } catch (Throwable $error) {
        return [
            'ok' => false,
            'message' => $error->getMessage() . ($mariaDb
                ? ' Migration is blocked before mutation; a complete verified manual MariaDB backup is required.'
                : ''),
            'manual_backup_required' => $mariaDb,
            'error_code' => $error instanceof CoreMigrationException ? $error->errorCode : 'BACKUP_PREFLIGHT_FAILED',
        ];
    }
}

function database_migrations_require_runtime_compatible(PDO $pdo): void
{
    if (!database_migration_acquire_runtime_read_lock()) {
        $status = [
            'current' => false,
            'kind' => 'active',
        ];
    } else {
        $status = database_migration_status($pdo);
    }
    if ($status['current']) return;
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    if (PHP_SAPI === 'cli') {
        throw new CoreMigrationException(
            'CoreChat database update is required before runtime access.',
            'DATABASE_UPDATE_REQUIRED',
            503
        );
    }
    if (str_contains($path, '/api/') || str_contains($path, '/games/api/')) {
        json_out([
            'error' => 'CoreChat is temporarily unavailable while a database update is required.',
            'code' => 'DATABASE_UPDATE_REQUIRED',
            'state' => $status['kind'],
        ], 503);
    }
    redirect_to('/database-update.php');
}

function database_migration_acquire_runtime_read_lock(): bool
{
    static $handle = null;
    if (is_resource($handle)) return true;
    $directory = security_private_storage_directory('migration-state');
    $path = $directory . DIRECTORY_SEPARATOR . 'core-migration.lock';
    $candidate = fopen($path, 'c+b');
    if (!is_resource($candidate)) return false;
    if (!flock($candidate, LOCK_SH | LOCK_NB)) {
        fclose($candidate);
        return false;
    }
    $handle = $candidate;
    return true;
}

function database_migration_private_lock(): array
{
    $directory = security_private_storage_directory('migration-state');
    $path = $directory . DIRECTORY_SEPARATOR . 'core-migration.lock';
    $handle = fopen($path, 'c+b');
    if (!is_resource($handle) || !flock($handle, LOCK_EX | LOCK_NB)) {
        if (is_resource($handle)) fclose($handle);
        throw new CoreMigrationException('Another migration process owns the private migration lock.', 'MIGRATION_PROCESS_LOCKED', 409);
    }
    ftruncate($handle, 0);
    fwrite($handle, database_migrations_canonical_json(['pid' => getmypid(), 'acquired_at' => gmdate('c')]));
    fflush($handle);
    return ['handle' => $handle, 'path' => $path];
}

function database_migration_release_private_lock(array $lock): void
{
    $handle = $lock['handle'] ?? null;
    if (!is_resource($handle)) return;
    flock($handle, LOCK_UN);
    fclose($handle);
}

function database_migration_claim(PDO $pdo, string $attemptId, string $ownerToken, ?int $actorUserId): array
{
    $privateLock = database_migration_private_lock();
    $mysqlLock = false;
    try {
        if (db_driver($pdo) === 'mysql') {
            $stmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
            $stmt->execute(['corechat:migration:' . (defined('CHATSPACE_DB_NAME') ? CHATSPACE_DB_NAME : 'configured')]);
            $mysqlLock = (int)$stmt->fetchColumn() === 1;
            if (!$mysqlLock) throw new CoreMigrationException('Another database process owns the migration lock.', 'MIGRATION_DATABASE_LOCKED', 409);
        } else {
            $pdo->exec('BEGIN IMMEDIATE');
        }
        $existing = database_migration_state($pdo);
        if (($existing['status'] ?? '') === 'active') {
            throw new CoreMigrationException('A migration attempt is already active.', 'MIGRATION_ALREADY_ACTIVE', 409);
        }
        database_migration_write_state($pdo, [
            'status' => 'active',
            'phase' => 'maintenance-acquired',
            'attempt_public_id' => $attemptId,
            'owner_token_hash' => hash('sha256', $ownerToken),
            'actor_user_id' => $actorUserId,
            'started_at' => gmdate('c'),
            'heartbeat_at' => gmdate('c'),
            'required_schema_version' => CHATSPACE_SCHEMA_VERSION,
        ]);
        if (db_driver($pdo) !== 'mysql') $pdo->commit();
        return ['private' => $privateLock, 'mysql' => $mysqlLock];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($mysqlLock) {
            $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute(['corechat:migration:' . (defined('CHATSPACE_DB_NAME') ? CHATSPACE_DB_NAME : 'configured')]);
        }
        database_migration_release_private_lock($privateLock);
        throw $error;
    }
}

function database_migration_release_claim(PDO $pdo, array $claim): void
{
    if (!empty($claim['mysql'])) {
        try {
            $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute(['corechat:migration:' . (defined('CHATSPACE_DB_NAME') ? CHATSPACE_DB_NAME : 'configured')]);
        } catch (Throwable) {
        }
    }
    database_migration_release_private_lock((array)($claim['private'] ?? []));
}

function database_migration_prepare_interrupted_recovery(PDO $pdo, string $expectedAttemptId, int $actorUserId): array
{
    if (!preg_match('/^[a-f0-9-]{36}$/i', $expectedAttemptId)) {
        throw new CoreMigrationException('The interrupted migration identity is invalid.', 'MIGRATION_RECOVERY_ID_INVALID', 400);
    }
    $privateLock = database_migration_private_lock();
    $mysqlLock = false;
    try {
        if (db_driver($pdo) === 'mysql') {
            $stmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
            $stmt->execute(['corechat:migration:' . (defined('CHATSPACE_DB_NAME') ? CHATSPACE_DB_NAME : 'configured')]);
            $mysqlLock = (int)$stmt->fetchColumn() === 1;
            if (!$mysqlLock) {
                throw new CoreMigrationException('The interrupted migration still has a database owner.', 'MIGRATION_DATABASE_LOCKED', 409);
            }
        } else {
            $pdo->exec('BEGIN IMMEDIATE');
        }
        $state = database_migration_state($pdo);
        if (($state['status'] ?? '') !== 'active' || !hash_equals((string)($state['attempt_public_id'] ?? ''), $expectedAttemptId)) {
            throw new CoreMigrationException('The interrupted migration state changed. Refresh before recovery.', 'MIGRATION_RECOVERY_STATE_CHANGED', 409);
        }
        $state['status'] = 'recovery-required';
        $state['phase'] = 'owner-confirmed-recovery';
        $state['recovery_confirmed_by_user_id'] = $actorUserId;
        $state['recovery_confirmed_at'] = gmdate('c');
        $state['heartbeat_at'] = gmdate('c');
        database_migration_write_state($pdo, $state);
        log_tool(
            $pdo,
            $actorUserId,
            'database_migration_recovery_confirmed',
            null,
            null,
            database_migrations_canonical_json(['attempt_public_id' => $expectedAttemptId])
        );
        if (db_driver($pdo) !== 'mysql') $pdo->commit();
        return $state;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    } finally {
        if ($mysqlLock) {
            try {
                $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $stmt->execute(['corechat:migration:' . (defined('CHATSPACE_DB_NAME') ? CHATSPACE_DB_NAME : 'configured')]);
            } catch (Throwable) {
            }
        }
        database_migration_release_private_lock($privateLock);
    }
}

function database_migration_set_phase(PDO $pdo, array $baseState, string $phase, ?string $migrationId = null): array
{
    $baseState['status'] = 'active';
    $baseState['phase'] = $phase;
    $baseState['current_migration_id'] = $migrationId;
    $baseState['heartbeat_at'] = gmdate('c');
    database_migration_write_state($pdo, $baseState);
    if (database_migration_table_exists($pdo, 'core_migration_attempts')) {
        $pdo->prepare(
            'UPDATE core_migration_attempts SET status = ?, phase = ?, current_migration_id = ?, updated_at = CURRENT_TIMESTAMP WHERE public_id = ?'
        )->execute(['active', $phase, $migrationId, $baseState['attempt_public_id']]);
    }
    return $baseState;
}

function database_migration_sqlite_backup(PDO $pdo, string $attemptId, ?string $sourceVersion): array
{
    $directory = security_private_storage_directory('migration-backups');
    $publicId = uuid_v4();
    $name = 'corechat-pre-migration-' . gmdate('Ymd-His') . '-' . substr(str_replace('-', '', $publicId), 0, 12) . '.sqlite';
    $path = $directory . DIRECTORY_SEPARATOR . $name;
    if (file_exists($path)) throw new CoreMigrationException('Migration backup destination already exists.', 'BACKUP_DESTINATION_EXISTS', 500);
    $pdo->exec('VACUUM INTO ' . $pdo->quote($path));
    clearstatcache(true, $path);
    $size = is_file($path) ? filesize($path) : false;
    if ($size === false || $size < 1) throw new CoreMigrationException('SQLite migration backup was not created.', 'BACKUP_CREATION_FAILED', 500);
    $sha = hash_file('sha256', $path);
    if (!is_string($sha)) throw new CoreMigrationException('SQLite migration backup checksum failed.', 'BACKUP_CHECKSUM_FAILED', 500);
    $check = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $check->exec('PRAGMA query_only = ON');
    $integrity = (string)$check->query('PRAGMA integrity_check')->fetchColumn();
    $tables = $check->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $check = null;
    if ($integrity !== 'ok' || !in_array('users', $tables, true) || !in_array('app_settings', $tables, true)) {
        throw new CoreMigrationException('SQLite migration backup verification failed.', 'BACKUP_VERIFICATION_FAILED', 500);
    }
    return [
        'public_id' => $publicId,
        'attempt_public_id' => $attemptId,
        'engine' => 'sqlite',
        'storage_name' => $name,
        'byte_size' => (int)$size,
        'sha256' => strtoupper($sha),
        'source_schema_version' => $sourceVersion,
        'verification' => ['integrity_check' => $integrity, 'table_count' => count($tables), 'readable' => true],
    ];
}

function database_migration_find_dump_executable(): ?string
{
    if (defined('CHATSPACE_MARIADB_DUMP_PATH')) {
        $configured = (string)CHATSPACE_MARIADB_DUMP_PATH;
        return is_file($configured) ? $configured : null;
    }
    $names = DIRECTORY_SEPARATOR === '\\'
        ? ['mariadb-dump.exe', 'mysqldump.exe', 'mariadb-dump', 'mysqldump']
        : ['mariadb-dump', 'mysqldump'];
    $directories = array_filter(explode(PATH_SEPARATOR, (string)getenv('PATH')));
    foreach ($directories as $directory) {
        foreach ($names as $name) {
            $candidate = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name;
            if (is_file($candidate)) return $candidate;
        }
    }
    return null;
}

function database_migration_mariadb_native_backup(PDO $pdo, string $attemptId, ?string $sourceVersion): array
{
    $dump = database_migration_find_dump_executable();
    if ($dump === null || !function_exists('proc_open')) {
        throw new CoreMigrationException(
            'The optional native MariaDB backup adapter is unavailable.',
            'MARIADB_NATIVE_BACKUP_ADAPTER_UNAVAILABLE',
            503
        );
    }
    $directory = security_private_storage_directory('migration-backups');
    $publicId = uuid_v4();
    $name = 'corechat-pre-migration-' . gmdate('Ymd-His') . '-' . substr(str_replace('-', '', $publicId), 0, 12) . '.sql';
    $path = $directory . DIRECTORY_SEPARATOR . $name;
    $defaults = $directory . DIRECTORY_SEPARATOR . '.dump-options-' . bin2hex(random_bytes(12)) . '.cnf';
    $escape = static fn(string $value): string => '"' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '', ''], $value) . '"';
    $options = "[client]\n"
        . 'host=' . $escape((string)(defined('CHATSPACE_DB_HOST') ? CHATSPACE_DB_HOST : '127.0.0.1')) . "\n"
        . 'port=' . (int)(defined('CHATSPACE_DB_PORT') ? CHATSPACE_DB_PORT : 3306) . "\n"
        . 'user=' . $escape((string)(defined('CHATSPACE_DB_USER') ? CHATSPACE_DB_USER : 'root')) . "\n"
        . 'password=' . $escape((string)(defined('CHATSPACE_DB_PASS') ? CHATSPACE_DB_PASS : '')) . "\n";
    if (file_put_contents($defaults, $options, LOCK_EX) === false) {
        throw new CoreMigrationException('Could not create private MariaDB backup credentials.', 'MARIADB_BACKUP_CONFIG_FAILED', 500);
    }
    @chmod($defaults, 0600);
    $stderr = '';
    try {
        $command = [
            $dump,
            '--defaults-extra-file=' . $defaults,
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--events',
            '--hex-blob',
            '--default-character-set=utf8mb4',
            '--result-file=' . $path,
            (string)(defined('CHATSPACE_DB_NAME') ? CHATSPACE_DB_NAME : 'chatspace_ce'),
        ];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new CoreMigrationException('MariaDB backup process could not start.', 'MARIADB_BACKUP_START_FAILED', 500);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], true);
        stream_set_blocking($pipes[2], true);
        $stdout = stream_get_contents($pipes[1], CORE_MIGRATION_BACKUP_MAX_STDERR_BYTES);
        $stderr = stream_get_contents($pipes[2], CORE_MIGRATION_BACKUP_MAX_STDERR_BYTES);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            throw new CoreMigrationException(
                'MariaDB backup failed before migration: ' . trim((string)$stderr),
                'MARIADB_BACKUP_FAILED',
                500
            );
        }
        unset($stdout);
    } finally {
        if (is_file($defaults)) @unlink($defaults);
    }
    clearstatcache(true, $path);
    $size = is_file($path) ? filesize($path) : false;
    if ($size === false || $size < 1) throw new CoreMigrationException('MariaDB backup file is empty.', 'BACKUP_CREATION_FAILED', 500);
    $sha = hash_file('sha256', $path);
    $tail = file_get_contents($path, false, null, max(0, (int)$size - 8192), min(8192, (int)$size));
    if (!is_string($sha) || !is_string($tail) || stripos($tail, 'Dump completed') === false) {
        throw new CoreMigrationException('MariaDB backup completion verification failed.', 'BACKUP_VERIFICATION_FAILED', 500);
    }
    $tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
    return [
        'public_id' => $publicId,
        'attempt_public_id' => $attemptId,
        'engine' => 'mariadb',
        'storage_name' => $name,
        'byte_size' => (int)$size,
        'sha256' => strtoupper($sha),
        'source_schema_version' => $sourceVersion,
        'verification' => ['dump_completed' => true, 'table_count' => count($tables), 'tool' => basename($dump)],
    ];
}

function database_migration_mariadb_backup(PDO $pdo, string $attemptId, ?string $sourceVersion): array
{
    $before = database_migration_mariadb_inventory($pdo);
    database_migration_mariadb_assert_supported_inventory($before);
    $beforeFingerprint = database_migration_mariadb_inventory_fingerprint($before);
    $directory = security_private_storage_directory('migration-backups');
    $publicId = uuid_v4();
    $name = 'corechat-pre-migration-' . gmdate('Ymd-His') . '-' . substr(str_replace('-', '', $publicId), 0, 12) . '.corechat-mariadb-backup';
    $path = $directory . DIRECTORY_SEPARATOR . $name;
    $partial = $path . '.partial-' . bin2hex(random_bytes(8));
    if (file_exists($path) || file_exists($partial)) {
        throw new CoreMigrationException('Migration backup destination already exists.', 'BACKUP_DESTINATION_EXISTS', 500);
    }
    $handle = fopen($partial, 'x+b');
    if (!is_resource($handle)) {
        throw new CoreMigrationException('Private MariaDB backup file could not be created.', 'BACKUP_CREATION_FAILED', 500);
    }
    @chmod($partial, 0600);
    $contentHash = hash_init('sha256');
    $schemaHash = hash_init('sha256');
    $rowCounts = [];
    $snapshotFingerprint = '';
    $committed = false;
    $priorBuffered = null;
    try {
        try {
            $priorBuffered = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        } catch (Throwable) {
        }
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        if (!$pdo->inTransaction()) {
            throw new CoreMigrationException(
                'MariaDB did not establish the required consistent snapshot.',
                'MARIADB_BACKUP_SNAPSHOT_FAILED',
                503
            );
        }
        $snapshot = database_migration_mariadb_inventory($pdo);
        database_migration_mariadb_assert_supported_inventory($snapshot);
        $snapshotFingerprint = database_migration_mariadb_inventory_fingerprint($snapshot);
        if (!hash_equals($beforeFingerprint, $snapshotFingerprint)) {
            throw new CoreMigrationException(
                'MariaDB schema changed before the consistent snapshot began.',
                'MARIADB_BACKUP_SCHEMA_CHANGED',
                409
            );
        }
        database_migration_backup_write_record($handle, $contentHash, [
            'record' => 'header',
            'format' => CORE_MIGRATION_MARIADB_BACKUP_FORMAT,
            'format_version' => CORE_MIGRATION_MARIADB_BACKUP_FORMAT_VERSION,
            'created_at' => gmdate('c'),
            'attempt_public_id' => $attemptId,
            'source_schema_version' => $sourceVersion,
            'database_identity_sha256' => $snapshot['database_identity_sha256'],
            'database_schema' => $snapshot['schema'],
            'object_counts' => $snapshot['object_counts'],
            'table_count' => count((array)$snapshot['tables']),
            'transaction' => [
                'engine' => 'InnoDB',
                'isolation' => 'REPEATABLE READ',
                'consistent_snapshot' => true,
                'read_only' => true,
            ],
        ]);
        foreach ((array)$snapshot['tables'] as $table) {
            $schemaLine = database_migration_backup_write_record($handle, $contentHash, [
                'record' => 'schema',
                'table' => $table['name'],
                'engine' => $table['table']['engine'],
                'create_sql_base64' => $table['create_sql_base64'],
                'columns' => array_map(
                    static fn(array $column): string => (string)$column['column_name'],
                    (array)$table['columns']
                ),
                'primary_key' => array_values((array)$table['primary_key']),
                'inventory' => [
                    'table' => $table['table'],
                    'columns' => $table['columns'],
                    'indexes' => $table['indexes'],
                    'constraints' => $table['constraints'],
                    'key_columns' => $table['key_columns'],
                    'referential_constraints' => $table['referential_constraints'],
                ],
            ]);
            hash_update($schemaHash, $schemaLine);
        }
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        foreach ((array)$snapshot['tables'] as $table) {
            $nameValue = (string)$table['name'];
            $columnNames = array_map(
                static fn(array $column): string => (string)$column['column_name'],
                (array)$table['columns']
            );
            $primary = array_values((array)$table['primary_key']);
            database_migration_backup_write_record($handle, $contentHash, [
                'record' => 'table_start',
                'table' => $nameValue,
                'column_count' => count($columnNames),
            ]);
            $select = 'SELECT '
                . implode(', ', array_map('database_migration_mariadb_quote_identifier', $columnNames))
                . ' FROM ' . database_migration_mariadb_quote_identifier($nameValue)
                . ' ORDER BY ' . implode(', ', array_map('database_migration_mariadb_quote_identifier', $primary));
            $statement = $pdo->query($select);
            $count = 0;
            while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
                $values = [];
                foreach ($row as $value) $values[] = $value === null ? null : base64_encode((string)$value);
                database_migration_backup_write_record($handle, $contentHash, [
                    'record' => 'row',
                    'table' => $nameValue,
                    'values' => $values,
                ]);
                $count++;
            }
            $statement->closeCursor();
            $rowCounts[$nameValue] = $count;
            database_migration_backup_write_record($handle, $contentHash, [
                'record' => 'table_end',
                'table' => $nameValue,
                'row_count' => $count,
            ]);
        }
        if ($priorBuffered !== null) $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, (bool)$priorBuffered);
        $pdo->commit();
        $committed = true;
        $after = database_migration_mariadb_inventory($pdo);
        database_migration_mariadb_assert_supported_inventory($after);
        if (!hash_equals($snapshotFingerprint, database_migration_mariadb_inventory_fingerprint($after))) {
            throw new CoreMigrationException(
                'MariaDB schema changed while the logical backup was streaming.',
                'MARIADB_BACKUP_SCHEMA_CHANGED',
                409
            );
        }
        $contentSha = strtoupper(hash_final(hash_copy($contentHash)));
        $schemaSha = strtoupper(hash_final(hash_copy($schemaHash)));
        $trailer = database_migrations_canonical_json([
            'record' => 'trailer',
            'complete' => true,
            'content_sha256' => $contentSha,
            'schema_inventory_sha256' => $snapshotFingerprint,
            'schema_records_sha256' => $schemaSha,
            'table_count' => count($rowCounts),
            'row_counts' => $rowCounts,
        ]) . "\n";
        $offset = 0;
        while ($offset < strlen($trailer)) {
            $written = fwrite($handle, substr($trailer, $offset));
            if ($written === false || $written === 0) {
                throw new CoreMigrationException('MariaDB backup trailer write failed.', 'MARIADB_BACKUP_WRITE_FAILED', 500);
            }
            $offset += $written;
        }
        if (!fflush($handle)) {
            throw new CoreMigrationException('MariaDB backup stream could not be flushed.', 'MARIADB_BACKUP_FLUSH_FAILED', 500);
        }
        if (function_exists('fsync') && !fsync($handle)) {
            throw new CoreMigrationException('MariaDB backup stream could not be synchronized.', 'MARIADB_BACKUP_FLUSH_FAILED', 500);
        }
        fclose($handle);
        $handle = null;
        if (!rename($partial, $path)) {
            throw new CoreMigrationException('MariaDB backup could not be finalized atomically.', 'MARIADB_BACKUP_FINALIZE_FAILED', 500);
        }
        @chmod($path, 0600);
        clearstatcache(true, $path);
        $size = filesize($path);
        $sha = hash_file('sha256', $path);
        if ($size === false || $size < 1 || !is_string($sha)) {
            throw new CoreMigrationException('MariaDB backup identity could not be recorded.', 'BACKUP_CHECKSUM_FAILED', 500);
        }
        $verified = database_migration_verify_mariadb_logical_backup_file($path);
        if (!hash_equals($snapshotFingerprint, (string)$verified['schema_inventory_sha256'])
            || $rowCounts != $verified['row_counts']) {
            throw new CoreMigrationException('MariaDB logical backup verification did not reproduce its inventory.', 'BACKUP_VERIFICATION_FAILED', 500);
        }
        return [
            'public_id' => $publicId,
            'attempt_public_id' => $attemptId,
            'engine' => 'mariadb',
            'storage_name' => $name,
            'byte_size' => (int)$size,
            'sha256' => strtoupper($sha),
            'source_schema_version' => $sourceVersion,
            'verification' => [
                'format' => CORE_MIGRATION_MARIADB_BACKUP_FORMAT,
                'format_version' => CORE_MIGRATION_MARIADB_BACKUP_FORMAT_VERSION,
                'logical_completed' => true,
                'transaction_consistent' => true,
                'schema_inventory_sha256' => $snapshotFingerprint,
                'schema_records_sha256' => $verified['schema_records_sha256'],
                'content_sha256' => $verified['content_sha256'],
                'table_count' => count($rowCounts),
                'table_list' => array_keys($rowCounts),
                'row_counts' => $rowCounts,
                'object_counts' => $snapshot['object_counts'],
            ],
        ];
    } catch (Throwable $error) {
        if (!$committed && $pdo->inTransaction()) {
            try {
                $pdo->rollBack();
            } catch (Throwable) {
            }
        }
        if ($priorBuffered !== null) {
            try {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, (bool)$priorBuffered);
            } catch (Throwable) {
            }
        }
        if (is_resource($handle)) fclose($handle);
        if (is_file($partial)) @unlink($partial);
        if (is_file($path)) @unlink($path);
        throw $error;
    }
}

function database_migration_verify_mariadb_logical_backup_file(string $path): array
{
    $handle = fopen($path, 'rb');
    if (!is_resource($handle)) {
        throw new CoreMigrationException('MariaDB logical backup is not readable.', 'BACKUP_VERIFICATION_FAILED', 500);
    }
    $contentHash = hash_init('sha256');
    $schemaHash = hash_init('sha256');
    $header = null;
    $schemas = [];
    $schemaOrder = [];
    $rowCounts = [];
    $currentTable = null;
    $currentRows = 0;
    $nextTable = 0;
    $trailer = null;
    $recordNumber = 0;
    try {
        while (($line = fgets($handle, CORE_MIGRATION_MARIADB_BACKUP_MAX_RECORD_BYTES + 1)) !== false) {
            $recordNumber++;
            if ($line === '' || !str_ends_with($line, "\n")) {
                throw new CoreMigrationException(
                    'MariaDB logical backup contains an incomplete or oversized record.',
                    'BACKUP_VERIFICATION_FAILED',
                    500
                );
            }
            try {
                $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new CoreMigrationException(
                    'MariaDB logical backup record is not valid JSON.',
                    'BACKUP_VERIFICATION_FAILED',
                    500,
                    $error
                );
            }
            if (!is_array($record) || !is_string($record['record'] ?? null)) {
                throw new CoreMigrationException('MariaDB logical backup record identity is invalid.', 'BACKUP_VERIFICATION_FAILED', 500);
            }
            $type = (string)$record['record'];
            if ($type === 'trailer') {
                if ($trailer !== null || $currentTable !== null) {
                    throw new CoreMigrationException('MariaDB logical backup trailer is out of order.', 'BACKUP_VERIFICATION_FAILED', 500);
                }
                $trailer = $record;
                continue;
            }
            if ($trailer !== null) {
                throw new CoreMigrationException('MariaDB logical backup contains data after its trailer.', 'BACKUP_VERIFICATION_FAILED', 500);
            }
            hash_update($contentHash, $line);
            if ($type === 'header') {
                if ($recordNumber !== 1 || $header !== null
                    || ($record['format'] ?? '') !== CORE_MIGRATION_MARIADB_BACKUP_FORMAT
                    || (int)($record['format_version'] ?? 0) !== CORE_MIGRATION_MARIADB_BACKUP_FORMAT_VERSION
                    || empty($record['transaction']['consistent_snapshot'])) {
                    throw new CoreMigrationException('MariaDB logical backup header is invalid.', 'BACKUP_VERIFICATION_FAILED', 500);
                }
                $header = $record;
                continue;
            }
            if ($header === null) {
                throw new CoreMigrationException('MariaDB logical backup has no valid header.', 'BACKUP_VERIFICATION_FAILED', 500);
            }
            if ($type === 'schema') {
                if ($currentTable !== null || $nextTable !== 0) {
                    throw new CoreMigrationException('MariaDB logical backup schema records are out of order.', 'BACKUP_VERIFICATION_FAILED', 500);
                }
                $table = (string)($record['table'] ?? '');
                $columns = $record['columns'] ?? null;
                $primary = $record['primary_key'] ?? null;
                $create = $record['create_sql_base64'] ?? null;
                if ($table === '' || isset($schemas[$table]) || !is_array($columns) || $columns === []
                    || !is_array($primary) || $primary === [] || !is_string($create)
                    || base64_decode($create, true) === false) {
                    throw new CoreMigrationException('MariaDB logical backup schema record is invalid.', 'BACKUP_VERIFICATION_FAILED', 500);
                }
                $schemas[$table] = [
                    'columns' => array_values(array_map('strval', $columns)),
                    'primary_key' => array_values(array_map('strval', $primary)),
                ];
                $schemaOrder[] = $table;
                hash_update($schemaHash, $line);
                continue;
            }
            if ($type === 'table_start') {
                $table = (string)($record['table'] ?? '');
                if ($currentTable !== null || !isset($schemaOrder[$nextTable])
                    || !hash_equals($schemaOrder[$nextTable], $table)
                    || (int)($record['column_count'] ?? -1) !== count($schemas[$table]['columns'])) {
                    throw new CoreMigrationException('MariaDB logical backup table stream is out of order.', 'BACKUP_VERIFICATION_FAILED', 500);
                }
                $currentTable = $table;
                $currentRows = 0;
                continue;
            }
            if ($type === 'row') {
                $table = (string)($record['table'] ?? '');
                $values = $record['values'] ?? null;
                if ($currentTable === null || !hash_equals($currentTable, $table)
                    || !is_array($values) || count($values) !== count($schemas[$table]['columns'])) {
                    throw new CoreMigrationException('MariaDB logical backup row framing is invalid.', 'BACKUP_VERIFICATION_FAILED', 500);
                }
                foreach ($values as $value) {
                    if ($value !== null && (!is_string($value) || base64_decode($value, true) === false)) {
                        throw new CoreMigrationException('MariaDB logical backup row encoding is invalid.', 'BACKUP_VERIFICATION_FAILED', 500);
                    }
                }
                $currentRows++;
                continue;
            }
            if ($type === 'table_end') {
                $table = (string)($record['table'] ?? '');
                if ($currentTable === null || !hash_equals($currentTable, $table)
                    || (int)($record['row_count'] ?? -1) !== $currentRows) {
                    throw new CoreMigrationException('MariaDB logical backup table count is invalid.', 'BACKUP_VERIFICATION_FAILED', 500);
                }
                $rowCounts[$table] = $currentRows;
                $currentTable = null;
                $currentRows = 0;
                $nextTable++;
                continue;
            }
            throw new CoreMigrationException('MariaDB logical backup record type is unsupported.', 'BACKUP_VERIFICATION_FAILED', 500);
        }
    } finally {
        fclose($handle);
    }
    $completionDefects = [];
    if ($header === null) $completionDefects[] = 'header';
    if ($trailer === null) $completionDefects[] = 'trailer';
    if ($currentTable !== null) $completionDefects[] = 'open-table';
    if ($schemaOrder === []) $completionDefects[] = 'schema';
    if ($nextTable !== count($schemaOrder)) $completionDefects[] = 'table-stream';
    if (empty($trailer['complete'])) $completionDefects[] = 'complete-marker';
    if ((int)($header['table_count'] ?? -1) !== count($schemaOrder)) $completionDefects[] = 'header-table-count';
    if ((int)($trailer['table_count'] ?? -1) !== count($schemaOrder)) $completionDefects[] = 'trailer-table-count';
    if (!is_array($trailer['row_counts'] ?? null)) $completionDefects[] = 'row-count-map';
    elseif ($rowCounts != $trailer['row_counts']) $completionDefects[] = 'row-count-values';
    if ($completionDefects !== []) {
        throw new CoreMigrationException(
            'MariaDB logical backup is incomplete: ' . implode(', ', $completionDefects) . '.',
            'BACKUP_VERIFICATION_FAILED',
            500
        );
    }
    $contentSha = strtoupper(hash_final(hash_copy($contentHash)));
    $schemaSha = strtoupper(hash_final(hash_copy($schemaHash)));
    if (!hash_equals($contentSha, strtoupper((string)($trailer['content_sha256'] ?? '')))
        || !hash_equals($schemaSha, strtoupper((string)($trailer['schema_records_sha256'] ?? '')))
        || !preg_match('/^[A-F0-9]{64}$/', strtoupper((string)($trailer['schema_inventory_sha256'] ?? '')))) {
        throw new CoreMigrationException('MariaDB logical backup checksums are invalid.', 'BACKUP_VERIFICATION_FAILED', 500);
    }
    return [
        'format' => CORE_MIGRATION_MARIADB_BACKUP_FORMAT,
        'format_version' => CORE_MIGRATION_MARIADB_BACKUP_FORMAT_VERSION,
        'table_count' => count($schemaOrder),
        'table_list' => $schemaOrder,
        'row_counts' => $rowCounts,
        'content_sha256' => $contentSha,
        'schema_records_sha256' => $schemaSha,
        'schema_inventory_sha256' => strtoupper((string)$trailer['schema_inventory_sha256']),
        'object_counts' => (array)($header['object_counts'] ?? []),
        'transaction_consistent' => true,
    ];
}

function database_migration_mariadb_verify_foreign_keys(PDO $pdo, array $inventory): void
{
    foreach ((array)($inventory['tables'] ?? []) as $table) {
        $groups = [];
        foreach ((array)($table['key_columns'] ?? []) as $column) {
            if (($column['referenced_table_name'] ?? null) === null) continue;
            $groups[(string)$column['constraint_name']][] = $column;
        }
        foreach ($groups as $constraint => $columns) {
            usort(
                $columns,
                static fn(array $a, array $b): int => (int)$a['ordinal_position'] <=> (int)$b['ordinal_position']
            );
            $childTable = (string)$table['name'];
            $parentTable = (string)$columns[0]['referenced_table_name'];
            $joins = [];
            $nonNull = [];
            foreach ($columns as $column) {
                $child = database_migration_mariadb_quote_identifier((string)$column['column_name']);
                $parent = database_migration_mariadb_quote_identifier((string)$column['referenced_column_name']);
                $joins[] = 'c.' . $child . ' = p.' . $parent;
                $nonNull[] = 'c.' . $child . ' IS NOT NULL';
            }
            $firstParent = database_migration_mariadb_quote_identifier((string)$columns[0]['referenced_column_name']);
            $sql = 'SELECT COUNT(*) FROM ' . database_migration_mariadb_quote_identifier($childTable) . ' c'
                . ' LEFT JOIN ' . database_migration_mariadb_quote_identifier($parentTable) . ' p ON ' . implode(' AND ', $joins)
                . ' WHERE ' . implode(' AND ', $nonNull) . ' AND p.' . $firstParent . ' IS NULL';
            if ((int)$pdo->query($sql)->fetchColumn() !== 0) {
                throw new CoreMigrationException(
                    'Restored MariaDB foreign-key verification failed: ' . $constraint . '.',
                    'MARIADB_BACKUP_RESTORE_FOREIGN_KEY_FAILED',
                    500
                );
            }
        }
    }
}

function database_migration_restore_mariadb_logical_backup(
    PDO $pdo,
    string $path,
    string $expectedSha256,
    string $targetDatabase
): array {
    if (db_driver($pdo) !== 'mysql') {
        throw new CoreMigrationException('MariaDB restore certification requires MariaDB.', 'MARIADB_BACKUP_RESTORE_ENGINE_REQUIRED', 500);
    }
    if (!preg_match('/^(?=.{1,64}$)(?=[a-z0-9_]*(?:test|verification|ci))[a-z0-9_]+_restore_[a-f0-9]{8,24}$/', $targetDatabase)) {
        throw new CoreMigrationException(
            'MariaDB restore target is not an isolated disposable test database.',
            'MARIADB_BACKUP_RESTORE_TARGET_INVALID',
            500
        );
    }
    $actualSha = hash_file('sha256', $path);
    if (!is_string($actualSha) || !hash_equals(strtoupper($expectedSha256), strtoupper($actualSha))) {
        throw new CoreMigrationException('MariaDB restore source checksum does not match.', 'MARIADB_BACKUP_RESTORE_CHECKSUM_FAILED', 500);
    }
    $verified = database_migration_verify_mariadb_logical_backup_file($path);
    $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?');
    $exists->execute([$targetDatabase]);
    $alreadyExists = (int)$exists->fetchColumn() !== 0;
    $exists->closeCursor();
    if ($alreadyExists) {
        throw new CoreMigrationException('MariaDB restore target already exists.', 'MARIADB_BACKUP_RESTORE_TARGET_EXISTS', 409);
    }
    $handle = fopen($path, 'rb');
    if (!is_resource($handle)) {
        throw new CoreMigrationException('MariaDB restore source is not readable.', 'MARIADB_BACKUP_RESTORE_READ_FAILED', 500);
    }
    $created = false;
    $dataTransaction = false;
    $schemas = [];
    $sourceTables = [];
    $currentTable = null;
    $insert = null;
    $restoredCounts = [];
    try {
        $headerLine = fgets($handle, CORE_MIGRATION_MARIADB_BACKUP_MAX_RECORD_BYTES + 1);
        $header = is_string($headerLine) ? json_decode($headerLine, true, 512, JSON_THROW_ON_ERROR) : null;
        if (!is_array($header) || ($header['record'] ?? '') !== 'header') {
            throw new CoreMigrationException('MariaDB restore source header is invalid.', 'MARIADB_BACKUP_RESTORE_FORMAT_FAILED', 500);
        }
        $characterSet = (string)($header['database_schema']['default_character_set_name'] ?? '');
        $collation = (string)($header['database_schema']['default_collation_name'] ?? '');
        if (!preg_match('/^[a-z0-9_]+$/i', $characterSet) || !preg_match('/^[a-z0-9_]+$/i', $collation)) {
            throw new CoreMigrationException('MariaDB restore source collation is invalid.', 'MARIADB_BACKUP_RESTORE_FORMAT_FAILED', 500);
        }
        $pdo->exec(
            'CREATE DATABASE ' . database_migration_mariadb_quote_identifier($targetDatabase)
            . ' CHARACTER SET ' . $characterSet . ' COLLATE ' . $collation
        );
        $created = true;
        $pdo->exec('USE ' . database_migration_mariadb_quote_identifier($targetDatabase));
        $pdo->exec('SET SESSION FOREIGN_KEY_CHECKS = 0');
        while (($line = fgets($handle, CORE_MIGRATION_MARIADB_BACKUP_MAX_RECORD_BYTES + 1)) !== false) {
            if ($line === '' || !str_ends_with($line, "\n")) {
                throw new CoreMigrationException('MariaDB restore source record is incomplete.', 'MARIADB_BACKUP_RESTORE_FORMAT_FAILED', 500);
            }
            $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($record) || !is_string($record['record'] ?? null)) {
                throw new CoreMigrationException('MariaDB restore source record is invalid.', 'MARIADB_BACKUP_RESTORE_FORMAT_FAILED', 500);
            }
            $type = (string)$record['record'];
            if ($type === 'schema') {
                if ($dataTransaction) {
                    throw new CoreMigrationException('MariaDB restore schema record is out of order.', 'MARIADB_BACKUP_RESTORE_FORMAT_FAILED', 500);
                }
                $table = (string)($record['table'] ?? '');
                $columns = array_values(array_map('strval', (array)($record['columns'] ?? [])));
                $ddl = base64_decode((string)($record['create_sql_base64'] ?? ''), true);
                $prefix = 'CREATE TABLE ' . database_migration_mariadb_quote_identifier($table);
                if ($table === '' || $columns === [] || !is_string($ddl) || !str_starts_with($ddl, $prefix)) {
                    throw new CoreMigrationException('MariaDB restore table DDL is invalid.', 'MARIADB_BACKUP_RESTORE_DDL_FAILED', 500);
                }
                $pdo->exec($ddl);
                $schemas[$table] = $columns;
                $recordInventory = (array)($record['inventory'] ?? []);
                $sourceTables[$table] = [
                    'name' => $table,
                    'table' => (array)($recordInventory['table'] ?? []),
                    'columns' => (array)($recordInventory['columns'] ?? []),
                    'indexes' => (array)($recordInventory['indexes'] ?? []),
                    'constraints' => (array)($recordInventory['constraints'] ?? []),
                    'key_columns' => (array)($recordInventory['key_columns'] ?? []),
                    'referential_constraints' => (array)($recordInventory['referential_constraints'] ?? []),
                    'primary_key' => array_values(array_map('strval', (array)($record['primary_key'] ?? []))),
                    'create_sql_base64' => (string)$record['create_sql_base64'],
                ];
                continue;
            }
            if ($type === 'table_start') {
                $table = (string)($record['table'] ?? '');
                if ($currentTable !== null || !isset($schemas[$table])) {
                    throw new CoreMigrationException('MariaDB restore table stream is invalid.', 'MARIADB_BACKUP_RESTORE_FORMAT_FAILED', 500);
                }
                if (!$dataTransaction) {
                    $pdo->beginTransaction();
                    $dataTransaction = true;
                }
                $columns = $schemas[$table];
                $insert = $pdo->prepare(
                    'INSERT INTO ' . database_migration_mariadb_quote_identifier($table)
                    . ' (' . implode(', ', array_map('database_migration_mariadb_quote_identifier', $columns)) . ')'
                    . ' VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')'
                );
                $currentTable = $table;
                $restoredCounts[$table] = 0;
                continue;
            }
            if ($type === 'row') {
                $table = (string)($record['table'] ?? '');
                $values = (array)($record['values'] ?? []);
                if ($currentTable === null || !hash_equals($currentTable, $table)
                    || !$insert instanceof PDOStatement || count($values) !== count($schemas[$table])) {
                    throw new CoreMigrationException('MariaDB restore row framing is invalid.', 'MARIADB_BACKUP_RESTORE_FORMAT_FAILED', 500);
                }
                foreach ($values as $index => $value) {
                    if ($value === null) {
                        $insert->bindValue($index + 1, null, PDO::PARAM_NULL);
                    } else {
                        $decoded = is_string($value) ? base64_decode($value, true) : false;
                        if (!is_string($decoded)) {
                            throw new CoreMigrationException('MariaDB restore row encoding is invalid.', 'MARIADB_BACKUP_RESTORE_FORMAT_FAILED', 500);
                        }
                        $insert->bindValue($index + 1, $decoded, PDO::PARAM_LOB);
                    }
                }
                $insert->execute();
                $restoredCounts[$table]++;
                continue;
            }
            if ($type === 'table_end') {
                $table = (string)($record['table'] ?? '');
                if ($currentTable === null || !hash_equals($currentTable, $table)
                    || (int)($record['row_count'] ?? -1) !== $restoredCounts[$table]) {
                    throw new CoreMigrationException('MariaDB restore table count is invalid.', 'MARIADB_BACKUP_RESTORE_FORMAT_FAILED', 500);
                }
                $insert = null;
                $currentTable = null;
                continue;
            }
            if ($type === 'trailer') break;
            throw new CoreMigrationException('MariaDB restore record type is unsupported.', 'MARIADB_BACKUP_RESTORE_FORMAT_FAILED', 500);
        }
        if ($currentTable !== null || $restoredCounts != $verified['row_counts']) {
            throw new CoreMigrationException('MariaDB restore stream is incomplete.', 'MARIADB_BACKUP_RESTORE_FORMAT_FAILED', 500);
        }
        if ($dataTransaction) {
            $pdo->commit();
            $dataTransaction = false;
        }
        $pdo->exec('SET SESSION FOREIGN_KEY_CHECKS = 1');
        $restoredInventory = database_migration_mariadb_inventory($pdo);
        database_migration_mariadb_assert_supported_inventory($restoredInventory);
        if (!hash_equals(
            (string)$verified['schema_inventory_sha256'],
            database_migration_mariadb_inventory_fingerprint($restoredInventory)
        )) {
            $differences = [];
            if ((array)($header['database_schema'] ?? []) != (array)($restoredInventory['schema'] ?? [])) {
                $differences[] = 'database-schema';
            }
            if ((array)($header['object_counts'] ?? []) != (array)($restoredInventory['object_counts'] ?? [])) {
                $differences[] = 'database-objects';
            }
            $restoredTables = [];
            foreach ((array)$restoredInventory['tables'] as $table) $restoredTables[(string)$table['name']] = $table;
            foreach ($sourceTables as $tableName => $sourceTable) {
                if (!isset($restoredTables[$tableName])) {
                    $differences[] = $tableName . '[missing]';
                    continue;
                }
                $components = [];
                foreach ([
                    'table', 'columns', 'indexes', 'constraints', 'key_columns',
                    'referential_constraints', 'primary_key', 'create_sql_base64',
                ] as $component) {
                    if (($sourceTable[$component] ?? null) != ($restoredTables[$tableName][$component] ?? null)) {
                        $components[] = $component;
                    }
                }
                if ($components !== []) $differences[] = $tableName . '[' . implode('+', $components) . ']';
                if (count($differences) >= 12) break;
            }
            throw new CoreMigrationException(
                'MariaDB restored schema inventory differs from the backup: ' . implode(', ', $differences) . '.',
                'MARIADB_BACKUP_RESTORE_SCHEMA_FAILED',
                500
            );
        }
        foreach ($verified['row_counts'] as $table => $expectedCount) {
            $actualCount = (int)$pdo->query(
                'SELECT COUNT(*) FROM ' . database_migration_mariadb_quote_identifier((string)$table)
            )->fetchColumn();
            if ($actualCount !== (int)$expectedCount) {
                throw new CoreMigrationException(
                    'MariaDB restored row count differs for table: ' . $table . '.',
                    'MARIADB_BACKUP_RESTORE_ROW_COUNT_FAILED',
                    500
                );
            }
            $check = $pdo->query('CHECK TABLE ' . database_migration_mariadb_quote_identifier((string)$table))->fetchAll(PDO::FETCH_ASSOC);
            foreach ($check as $result) {
                if (($result['Msg_type'] ?? '') === 'error' || ($result['Msg_type'] ?? '') === 'warning') {
                    throw new CoreMigrationException(
                        'MariaDB restored table check failed: ' . $table . '.',
                        'MARIADB_BACKUP_RESTORE_TABLE_CHECK_FAILED',
                        500
                    );
                }
            }
        }
        database_migration_mariadb_verify_foreign_keys($pdo, $restoredInventory);
        return [
            'database' => $targetDatabase,
            'schema_inventory_sha256' => $verified['schema_inventory_sha256'],
            'table_count' => $verified['table_count'],
            'row_counts' => $verified['row_counts'],
            'foreign_keys_valid' => true,
            'table_checks_valid' => true,
        ];
    } catch (Throwable $error) {
        if ($dataTransaction && $pdo->inTransaction()) {
            try {
                $pdo->rollBack();
            } catch (Throwable) {
            }
        }
        if ($created) {
            try {
                $pdo->exec('USE information_schema');
                $pdo->exec('DROP DATABASE ' . database_migration_mariadb_quote_identifier($targetDatabase));
            } catch (Throwable) {
            }
        }
        throw $error;
    } finally {
        fclose($handle);
    }
}

function database_migration_create_backup(PDO $pdo, string $attemptId, ?string $sourceVersion): array
{
    return db_driver($pdo) === 'mysql'
        ? database_migration_mariadb_backup($pdo, $attemptId, $sourceVersion)
        : database_migration_sqlite_backup($pdo, $attemptId, $sourceVersion);
}

function database_migration_backup_recovery_metadata(array $backup): array
{
    if (($backup['verification']['format'] ?? '') !== CORE_MIGRATION_MARIADB_BACKUP_FORMAT) {
        return $backup;
    }
    return [
        'public_id' => $backup['public_id'],
        'attempt_public_id' => $backup['attempt_public_id'],
        'engine' => $backup['engine'],
        'storage_name' => $backup['storage_name'],
        'byte_size' => $backup['byte_size'],
        'sha256' => $backup['sha256'],
        'verification' => [
            'format' => CORE_MIGRATION_MARIADB_BACKUP_FORMAT,
            'schema_inventory_sha256' => $backup['verification']['schema_inventory_sha256'],
        ],
    ];
}

function database_migration_verify_existing_backup(PDO $pdo, array $backup): array
{
    $required = ['public_id', 'attempt_public_id', 'engine', 'storage_name', 'byte_size', 'sha256', 'verification'];
    foreach ($required as $key) {
        if (!array_key_exists($key, $backup)) {
            throw new CoreMigrationException('Interrupted migration backup metadata is incomplete.', 'RECOVERY_BACKUP_METADATA_INCOMPLETE', 409);
        }
    }
    $name = (string)$backup['storage_name'];
    if ($name === '' || basename($name) !== $name) {
        throw new CoreMigrationException('Interrupted migration backup identity is invalid.', 'RECOVERY_BACKUP_IDENTITY_INVALID', 409);
    }
    $directory = security_private_storage_directory('migration-backups');
    $path = $directory . DIRECTORY_SEPARATOR . $name;
    clearstatcache(true, $path);
    $size = is_file($path) ? filesize($path) : false;
    $sha = $size === false ? false : hash_file('sha256', $path);
    if ($size === false
        || (int)$size !== (int)$backup['byte_size']
        || !is_string($sha)
        || !hash_equals(strtoupper((string)$backup['sha256']), strtoupper($sha))) {
        throw new CoreMigrationException('Interrupted migration backup no longer matches its verified identity.', 'RECOVERY_BACKUP_MISMATCH', 409);
    }
    if (($backup['engine'] ?? '') === 'sqlite') {
        $check = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $check->exec('PRAGMA query_only = ON');
        $integrity = (string)$check->query('PRAGMA integrity_check')->fetchColumn();
        $check = null;
        if ($integrity !== 'ok') {
            throw new CoreMigrationException('Interrupted SQLite backup failed integrity verification.', 'RECOVERY_BACKUP_INTEGRITY_FAILED', 409);
        }
    } elseif (($backup['verification']['format'] ?? '') === CORE_MIGRATION_MARIADB_BACKUP_FORMAT) {
        $verified = database_migration_verify_mariadb_logical_backup_file($path);
        $expectedRowCounts = $backup['verification']['row_counts'] ?? null;
        if (!hash_equals(
            strtoupper((string)($backup['verification']['schema_inventory_sha256'] ?? '')),
            (string)$verified['schema_inventory_sha256']
        ) || (is_array($expectedRowCounts) && $expectedRowCounts != $verified['row_counts'])) {
            throw new CoreMigrationException(
                'Interrupted MariaDB logical backup failed inventory verification.',
                'RECOVERY_BACKUP_INTEGRITY_FAILED',
                409
            );
        }
        $backup['verification'] = array_merge($verified, [
            'logical_completed' => true,
            'transaction_consistent' => true,
        ]);
    } else {
        $tail = file_get_contents($path, false, null, max(0, (int)$size - 8192), min(8192, (int)$size));
        if (!is_string($tail) || stripos($tail, 'Dump completed') === false) {
            throw new CoreMigrationException('Interrupted MariaDB backup failed completion verification.', 'RECOVERY_BACKUP_INTEGRITY_FAILED', 409);
        }
    }
    return $backup;
}

function database_migration_record_backup(PDO $pdo, array $backup): void
{
    $stmt = $pdo->prepare('SELECT sha256, byte_size FROM core_migration_backups WHERE public_id = ? LIMIT 1');
    $stmt->execute([$backup['public_id']]);
    $existing = $stmt->fetch();
    if ($existing) {
        if (!hash_equals((string)$existing['sha256'], (string)$backup['sha256'])
            || (int)$existing['byte_size'] !== (int)$backup['byte_size']) {
            throw new CoreMigrationException('Recorded migration backup identity changed.', 'BACKUP_LEDGER_MISMATCH', 409);
        }
        return;
    }
    $pdo->prepare(
        'INSERT INTO core_migration_backups (public_id, attempt_public_id, engine, storage_name, byte_size, sha256, source_schema_version, verification_json) VALUES (?,?,?,?,?,?,?,?)'
    )->execute([
        $backup['public_id'], $backup['attempt_public_id'], $backup['engine'], $backup['storage_name'],
        $backup['byte_size'], $backup['sha256'], $backup['source_schema_version'],
        database_migrations_canonical_json($backup['verification']),
    ]);
}

function database_migration_record_ledger(PDO $pdo, array $migration, string $attemptId, ?int $actorUserId, string $result): void
{
    $stmt = $pdo->prepare('SELECT checksum FROM core_migration_ledger WHERE migration_id = ? LIMIT 1');
    $stmt->execute([$migration['id']]);
    $existing = $stmt->fetchColumn();
    if ($existing !== false) {
        if (!hash_equals((string)$migration['checksum'], (string)$existing)) {
            throw new CoreMigrationException('Completed migration checksum changed: ' . $migration['id'] . '.', 'MIGRATION_CHECKSUM_MISMATCH', 409);
        }
        return;
    }
    $pdo->prepare(
        'INSERT INTO core_migration_ledger (migration_id, title, owner_namespace, checksum, result, attempt_public_id, applied_by_user_id) VALUES (?,?,?,?,?,?,?)'
    )->execute([
        $migration['id'], $migration['title'], $migration['owner'], $migration['checksum'],
        $result, $attemptId, $actorUserId,
    ]);
}

function database_migration_apply_one(PDO $pdo, array $migration, string $attemptId, ?int $actorUserId): string
{
    if (database_migration_validator_passes($pdo, $migration)) {
        database_migration_record_ledger($pdo, $migration, $attemptId, $actorUserId, 'adopted');
        return 'adopted';
    }
    $sqliteTransaction = db_driver($pdo) === 'sqlite';
    if ($sqliteTransaction) $pdo->exec('BEGIN IMMEDIATE');
    try {
        $operation = $migration['up'];
        $operation($pdo);
        if (!database_migration_validator_passes($pdo, $migration)) {
            throw new CoreMigrationException('Migration validation failed: ' . $migration['id'] . '.', 'MIGRATION_VALIDATION_FAILED', 500);
        }
        database_migration_record_ledger($pdo, $migration, $attemptId, $actorUserId, 'applied');
        if ($sqliteTransaction) $pdo->commit();
        return 'applied';
    } catch (Throwable $error) {
        if ($sqliteTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function database_migration_create_attempt_row(PDO $pdo, array $state, array $variant, ?int $actorUserId, ?array $backup): void
{
    $pdo->prepare(
        'INSERT INTO core_migration_attempts (public_id, owner_token, actor_user_id, engine, source_variant, source_schema_version, target_schema_version, status, phase, current_migration_id, backup_public_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $state['attempt_public_id'], (string)$state['owner_token_hash'], $actorUserId,
        db_driver($pdo) === 'mysql' ? 'mariadb' : 'sqlite',
        $variant['id'], database_migration_read_setting($pdo, 'schema_version'), CHATSPACE_SCHEMA_VERSION,
        'active', $state['phase'], null, $backup['public_id'] ?? null,
    ]);
}

function database_migration_finish_attempt(PDO $pdo, string $attemptId, string $status, string $phase, ?CoreMigrationException $failure = null): void
{
    if (!database_migration_table_exists($pdo, 'core_migration_attempts')) return;
    $pdo->prepare(
        'UPDATE core_migration_attempts SET status = ?, phase = ?, current_migration_id = NULL, error_code = ?, error_message = ?, updated_at = CURRENT_TIMESTAMP, completed_at = CURRENT_TIMESTAMP WHERE public_id = ?'
    )->execute([
        $status, $phase, $failure?->errorCode, $failure?->getMessage(), $attemptId,
    ]);
}

function database_migrations_run(
    PDO $pdo,
    ?int $actorUserId,
    bool $cleanInstall = false,
    ?string $requestPublicId = null
): array
{
    if ($requestPublicId !== null && !preg_match('/^[a-f0-9-]{36}$/i', $requestPublicId)) {
        throw new CoreMigrationException(
            'The database-update request identity is invalid.',
            'MIGRATION_REQUEST_ID_INVALID',
            400
        );
    }
    $preflight = database_migrations_release_preflight();
    if (!$preflight['ok']) {
        throw new CoreMigrationException('Migration release is incomplete: ' . implode(' ', $preflight['defects']), 'MIGRATION_RELEASE_INCOMPLETE', 503);
    }
    $variant = database_migration_variant($pdo);
    if (!$variant['recognized']) {
        throw new CoreMigrationException('The database schema is unknown or partially upgraded. No mutation was attempted.', 'MIGRATION_SCHEMA_UNRECOGNIZED', 409);
    }
    if ($cleanInstall && $variant['id'] !== 'empty') {
        $userCount = database_migration_table_exists($pdo, 'users') ? (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() : 0;
        if ($userCount > 0) throw new CoreMigrationException('Clean installation cannot replace an existing database.', 'CLEAN_INSTALL_DATABASE_NOT_EMPTY', 409);
    }
    if ($cleanInstall && in_array($variant['id'], ['empty', 'bundled-seed'], true)) {
        database_migration_apply_published_baseline($pdo);
        $variant = database_migration_variant($pdo);
        if (!$variant['recognized']) throw new CoreMigrationException('Clean baseline validation failed.', 'CLEAN_BASELINE_INVALID', 500);
    }
    $status = database_migration_status($pdo);
    $priorState = is_array($status['migration_state'] ?? null) ? $status['migration_state'] : [];
    if ($requestPublicId !== null && database_migration_table_exists($pdo, 'core_migration_attempts')) {
        $request = $pdo->prepare(
            'SELECT public_id, status, phase, backup_public_id
             FROM core_migration_attempts
             WHERE public_id = ?
             LIMIT 1'
        );
        $request->execute([$requestPublicId]);
        $existingRequest = $request->fetch();
        if (is_array($existingRequest)) {
            if (($existingRequest['status'] ?? '') === 'completed' && $status['current']) {
                return [
                    'ok' => true,
                    'no_op' => true,
                    'idempotent_replay' => true,
                    'attempt_public_id' => (string)$existingRequest['public_id'],
                    'backup_public_id' => $existingRequest['backup_public_id'] ?: null,
                    'results' => [],
                    'status' => $status,
                ];
            }
            throw new CoreMigrationException(
                'This database-update request already has a durable attempt. Refresh the protected update page.',
                'MIGRATION_REQUEST_ALREADY_RECORDED',
                409
            );
        }
    }
    if ($status['current']) return ['ok' => true, 'no_op' => true, 'status' => $status];
    if (in_array($status['kind'], ['newer', 'unknown', 'incomplete-release', 'inconsistent'], true)) {
        throw new CoreMigrationException('The database cannot be updated from state: ' . $status['kind'] . '.', 'MIGRATION_STATE_BLOCKED', 409);
    }
    $backupReadiness = $status['backup_readiness'];
    $hasRecoveryBackup = !$cleanInstall && is_array($priorState['backup'] ?? null);
    if (!$cleanInstall && !$hasRecoveryBackup && empty($backupReadiness['ok'])) {
        throw new CoreMigrationException((string)($backupReadiness['message'] ?? 'Migration backup is not ready.'), 'MIGRATION_BACKUP_NOT_READY', 503);
    }
    $attemptId = $requestPublicId ?? uuid_v4();
    $ownerToken = bin2hex(random_bytes(32));
    $claim = database_migration_claim($pdo, $attemptId, $ownerToken, $actorUserId);
    $state = database_migration_state($pdo);
    $backup = null;
    $results = [];
    try {
        if (!$cleanInstall) {
            if ($hasRecoveryBackup) {
                $backup = database_migration_verify_existing_backup($pdo, (array)$priorState['backup']);
                $state['recovered_from_attempt_public_id'] = $priorState['attempt_public_id'] ?? null;
                $state['backup'] = database_migration_backup_recovery_metadata($backup);
                $state['backup_public_id'] = $backup['public_id'];
                $state = database_migration_set_phase($pdo, $state, 'recovery-backup-verified');
            } else {
                if (!empty($priorState['backup_public_id'])) {
                    throw new CoreMigrationException(
                        'Interrupted migration backup metadata is unavailable. Recovery cannot continue.',
                        'RECOVERY_BACKUP_METADATA_INCOMPLETE',
                        409
                    );
                }
                $state = database_migration_set_phase($pdo, $state, 'backup-started');
                $backup = database_migration_create_backup($pdo, $attemptId, database_migration_read_setting($pdo, 'schema_version'));
                $state['backup'] = database_migration_backup_recovery_metadata($backup);
                $state['backup_public_id'] = $backup['public_id'];
                $state = database_migration_set_phase($pdo, $state, 'backup-verified');
            }
        }
        database_migrations_bootstrap_control_tables($pdo);
        if (!empty($priorState['attempt_public_id'])
            && !hash_equals((string)$priorState['attempt_public_id'], $attemptId)
            && database_migration_table_exists($pdo, 'core_migration_attempts')) {
            database_migration_finish_attempt(
                $pdo,
                (string)$priorState['attempt_public_id'],
                'failed',
                'recovered-by-new-attempt',
                new CoreMigrationException('A protected recovery attempt superseded this interrupted attempt.', 'MIGRATION_ATTEMPT_RECOVERED')
            );
        }
        database_migration_create_attempt_row($pdo, $state, $variant, $actorUserId, $backup);
        if ($backup !== null) database_migration_record_backup($pdo, $backup);
        $state = database_migration_set_phase($pdo, $state, 'migration-preflight-complete');
        foreach (database_migrations_manifest() as $migration) {
            $state = database_migration_set_phase($pdo, $state, 'migration-started', $migration['id']);
            $results[$migration['id']] = database_migration_apply_one($pdo, $migration, $attemptId, $actorUserId);
            $state = database_migration_set_phase($pdo, $state, 'migration-validated', $migration['id']);
        }
        database_migration_write_setting($pdo, 'schema_version', CHATSPACE_SCHEMA_VERSION);
        database_migration_write_state($pdo, [
            'status' => 'current',
            'phase' => 'final-verification',
            'attempt_public_id' => $attemptId,
            'backup_public_id' => $backup['public_id'] ?? null,
            'required_schema_version' => CHATSPACE_SCHEMA_VERSION,
        ]);
        $finalStatus = database_migration_status($pdo);
        if (!$finalStatus['current']) {
            throw new CoreMigrationException('Final schema and ledger verification did not reach current state.', 'MIGRATION_FINAL_VERIFICATION_FAILED', 500);
        }
        if ($actorUserId !== null) {
            log_tool(
                $pdo,
                $actorUserId,
                $cleanInstall ? 'database_migration_clean_install' : 'database_migration_update',
                null,
                null,
                database_migrations_canonical_json([
                    'attempt_public_id' => $attemptId,
                    'from_variant' => $variant['id'],
                    'to_schema_version' => CHATSPACE_SCHEMA_VERSION,
                    'backup_public_id' => $backup['public_id'] ?? null,
                    'results' => $results,
                ])
            );
        }
        database_migration_finish_attempt($pdo, $attemptId, 'completed', 'maintenance-released');
        database_migration_write_state($pdo, [
            'status' => 'current',
            'phase' => 'maintenance-released',
            'attempt_public_id' => $attemptId,
            'completed_at' => gmdate('c'),
            'required_schema_version' => CHATSPACE_SCHEMA_VERSION,
        ]);
        return [
            'ok' => true,
            'no_op' => false,
            'attempt_public_id' => $attemptId,
            'backup_public_id' => $backup['public_id'] ?? null,
            'results' => $results,
            'status' => database_migration_status($pdo),
        ];
    } catch (Throwable $error) {
        $failure = $error instanceof CoreMigrationException
            ? $error
            : new CoreMigrationException($error->getMessage(), 'MIGRATION_UNEXPECTED_FAILURE', 500, $error);
        try {
            database_migration_finish_attempt($pdo, $attemptId, 'failed', 'recovery-required', $failure);
            database_migration_write_state($pdo, [
                'status' => 'failed',
                'phase' => 'recovery-required',
                'attempt_public_id' => $attemptId,
                'backup_public_id' => $backup['public_id'] ?? null,
                'backup' => $backup === null ? null : database_migration_backup_recovery_metadata($backup),
                'error_code' => $failure->errorCode,
                'error_message' => $failure->getMessage(),
                'failed_at' => gmdate('c'),
                'required_schema_version' => CHATSPACE_SCHEMA_VERSION,
            ]);
        } catch (Throwable) {
        }
        throw $failure;
    } finally {
        database_migration_release_claim($pdo, $claim);
    }
}

function database_migrations_install_clean(PDO $pdo): void
{
    database_migrations_run($pdo, null, true);
}
