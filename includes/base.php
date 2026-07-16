<?php
declare(strict_types=1);

const CHATSPACE_CONFIG = __DIR__ . '/config.php';
const CHATSPACE_DEFAULT_SQLITE = __DIR__ . '/../db/chatspace.sqlite';

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

if (is_file(CHATSPACE_CONFIG)) {
    require_once CHATSPACE_CONFIG;
}

require_once __DIR__ . '/avatar_size_policy.php';

function app_base_path(): string {
    static $base = null;
    if ($base !== null) return $base;

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if ($script === '' || $script === '/') {
        $base = '';
        return $base;
    }

    foreach (['/api/', '/games/'] as $marker) {
        $pos = strpos($script, $marker);
        if ($pos !== false) {
            $base = substr($script, 0, $pos);
            return $base === '/' ? '' : rtrim($base, '/');
        }
    }

    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    $base = $dir === '/' || $dir === '.' ? '' : $dir;
    return $base;
}

function app_url(string $path = ''): string {
    if ($path === '') return app_base_path() ?: '/';
    if (preg_match('#^(?:https?:)?//#', $path) || str_starts_with($path, 'data:') || str_starts_with($path, 'blob:')) return $path;
    $base = app_base_path();
    if ($base !== '' && str_starts_with($path, $base . '/')) return $path;
    return $base . '/' . ltrim($path, '/');
}

function media_url(?string $path): string {
    if (!$path) return '';
    return app_url($path);
}

function redirect_to(string $path): never {
    header('Location: ' . app_url($path));
    exit;
}

function chatspace_is_setup_request(): bool {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    return str_ends_with($path, '/setup.php')
        || str_contains($path, '/assets/')
        || (PHP_SAPI === 'cli');
}

function chatspace_configured(): bool {
    return defined('CHATSPACE_DB_DRIVER');
}

if (!chatspace_configured() && !chatspace_is_setup_request()) {
    redirect_to('/setup.php');
}

function db_driver(?PDO $pdo = null): string {
    if ($pdo instanceof PDO) return (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    return strtolower((string)(defined('CHATSPACE_DB_DRIVER') ? CHATSPACE_DB_DRIVER : 'sqlite'));
}

function db_uses_mysql_syntax(PDO $pdo): bool {
    if (db_driver($pdo) !== 'mysql') return false;
    try {
        return stripos((string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION), 'fakesql') === false;
    } catch (Throwable) {
        return true;
    }
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $driver = db_driver();
    if ($driver === 'mysql') {
        $host = defined('CHATSPACE_DB_HOST') ? CHATSPACE_DB_HOST : '127.0.0.1';
        $port = (int)(defined('CHATSPACE_DB_PORT') ? CHATSPACE_DB_PORT : 3306);
        $name = defined('CHATSPACE_DB_NAME') ? CHATSPACE_DB_NAME : 'chatspace_ce';
        $user = defined('CHATSPACE_DB_USER') ? CHATSPACE_DB_USER : 'root';
        $pass = defined('CHATSPACE_DB_PASS') ? CHATSPACE_DB_PASS : '';
        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } else {
        $sqlitePath = defined('CHATSPACE_SQLITE_PATH') ? CHATSPACE_SQLITE_PATH : CHATSPACE_DEFAULT_SQLITE;
        if (!is_dir(dirname($sqlitePath))) {
            mkdir(dirname($sqlitePath), 0775, true);
        }
        $pdo = new PDO('sqlite:' . $sqlitePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }

    migrate($pdo);
    return $pdo;
}

function sqlite_path(): string {
    return defined('CHATSPACE_SQLITE_PATH') ? CHATSPACE_SQLITE_PATH : CHATSPACE_DEFAULT_SQLITE;
}

function db_concat(PDO $pdo, array $parts): string {
    if (db_driver($pdo) === 'mysql') {
        return 'CONCAT(' . implode(', ', $parts) . ')';
    }
    return implode(' || ', $parts);
}

function split_sql_statements(string $sql): array {
    $sql = preg_replace('/--[^\n]*/', '', $sql) ?? $sql;
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql) ?? $sql;
    return array_values(array_filter(array_map('trim', preg_split("/;(?=(?:[^']*'[^']*')*[^']*$)/", $sql) ?: [])));
}

function mysqlize_schema(string $schema): string {
    $schema = str_replace('INTEGER PRIMARY KEY AUTOINCREMENT', 'INT AUTO_INCREMENT PRIMARY KEY', $schema);
    $schema = str_replace('INTEGER', 'INT', $schema);
    $schema = str_replace('REAL', 'DOUBLE', $schema);
    $schema = preg_replace('/\bTEXT\b/', 'VARCHAR(1024)', $schema) ?? $schema;
    $longTextColumns = ['payload', 'content', 'original_content', 'url_preview_json', 'reply_to_json', 'state_json', 'data', 'reason', 'metadata_json', 'anchors_json', 'options_json', 'anchor_json'];
    foreach ($longTextColumns as $column) {
        $schema = preg_replace('/\b' . $column . '\s+VARCHAR\(1024\)/', $column . ' LONGTEXT', $schema) ?? $schema;
    }
    $shortColumns = [
        'email' => 'VARCHAR(191)',
        'public_id' => 'VARCHAR(64)',
        'session_public_id' => 'VARCHAR(64)',
        'password_hash' => 'VARCHAR(255)',
        'recovery_code_hash' => 'VARCHAR(255)',
        'recovery_code_suffix' => 'VARCHAR(16)',
        'display_name' => 'VARCHAR(191)',
        'role' => 'VARCHAR(32)',
        'avatar_path' => 'VARCHAR(512)',
        'avatar_url' => 'VARCHAR(512)',
        'avatar_orientation' => 'VARCHAR(32)',
        'background_path' => 'VARCHAR(512)',
        'background_mime' => 'VARCHAR(128)',
        'background_thumb_path' => 'VARCHAR(512)',
        'room_name' => 'VARCHAR(191)',
        'webcam_path' => 'VARCHAR(512)',
        'join_token' => 'VARCHAR(96)',
        'sender_epoch' => 'VARCHAR(96)',
        'recipient_epoch' => 'VARCHAR(96)',
        'client_epoch' => 'VARCHAR(96)',
        'expires_at' => 'VARCHAR(32)',
        'message_type' => 'VARCHAR(32)',
        'mime_type' => 'VARCHAR(128)',
        'original_name' => 'VARCHAR(255)',
        'game_type' => 'VARCHAR(32)',
        'lobby_code' => 'VARCHAR(191)',
        'status' => 'VARCHAR(32)',
        'type' => 'VARCHAR(64)',
        'icon_name' => 'VARCHAR(64)',
        'label' => 'VARCHAR(191)',
        'file_path' => 'VARCHAR(512)',
        'link_key' => 'VARCHAR(191)',
        'relationship_public_id' => 'VARCHAR(191)',
        'request_public_id' => 'VARCHAR(191)',
        'conversation_public_id' => 'VARCHAR(191)',
        'legacy_link_key' => 'VARCHAR(191)',
        'mode' => 'VARCHAR(32)',
        'capability' => 'VARCHAR(64)',
        'geometry_strategy' => 'VARCHAR(64)',
        'member_role' => 'VARCHAR(64)',
        'relationship_role' => 'VARCHAR(32)',
        'permission_role' => 'VARCHAR(32)',
        'membership_status' => 'VARCHAR(32)',
        'join_policy' => 'VARCHAR(32)',
        'request_type' => 'VARCHAR(32)',
        'requested_relationship_role' => 'VARCHAR(32)',
        'lap_side' => 'VARCHAR(32)',
        'requested_lap_side' => 'VARCHAR(32)',
        'active_request_key' => 'VARCHAR(191)',
        'outcome' => 'VARCHAR(32)',
        'resolution_reason' => 'VARCHAR(512)',
        'divergence_status' => 'VARCHAR(32)',
        'scope' => 'VARCHAR(32)',
        'action' => 'VARCHAR(64)',
        'setting_key' => 'VARCHAR(191)',
        'key_hash' => 'VARCHAR(64)',
        'dimension' => 'VARCHAR(32)',
        'value' => 'VARCHAR(1024)',
    ];
    foreach ($shortColumns as $column => $type) {
        $schema = preg_replace('/\b' . $column . '\s+VARCHAR\(1024\)/', $column . ' ' . $type, $schema) ?? $schema;
    }
    foreach (['current_room_id'] as $intColumn) {
        $schema = preg_replace('/\b' . $intColumn . '\s+INT/', $intColumn . ' INT', $schema) ?? $schema;
    }
    foreach (['last_seen_at', 'created_at', 'started_at', 'joined_at', 'updated_at', 'sent_at', 'edited_at', 'deleted_at', 'expires_at', 'resolved_at', 'cleared_at', 'last_attempt_at', 'locked_until', 'dissolved_at', 'membership_effective_at', 'membership_ended_at'] as $dateColumn) {
        $schema = preg_replace('/\b' . $dateColumn . '\s+VARCHAR\(1024\)/', $dateColumn . ' DATETIME', $schema) ?? $schema;
    }
    $schema = preg_replace('/CREATE TABLE IF NOT EXISTS ([^(]+)\s*\((.*?)\);/s', 'CREATE TABLE IF NOT EXISTS $1 ($2) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;', $schema) ?? $schema;
    return $schema;
}

function migrate_avatar_relationship_group_schema(PDO $pdo): void {
    $relationshipDefinitions = [
        'version' => 'INTEGER NOT NULL DEFAULT 1',
        'status' => "TEXT NOT NULL DEFAULT 'active'",
        'creator_participant_id' => 'INTEGER DEFAULT NULL',
        'join_policy' => "TEXT NOT NULL DEFAULT 'approval-required'",
        'conversation_public_id' => 'TEXT DEFAULT NULL',
        'dissolved_at' => 'TEXT DEFAULT NULL',
    ];
    $memberDefinitions = [
        'relationship_role' => "TEXT NOT NULL DEFAULT 'normal'",
        'permission_role' => "TEXT NOT NULL DEFAULT 'member'",
        'membership_status' => "TEXT NOT NULL DEFAULT 'active'",
        'active_participant_id' => 'INTEGER DEFAULT NULL',
        'membership_effective_at' => 'TEXT DEFAULT NULL',
        'visible_after_message_id' => 'INTEGER NOT NULL DEFAULT 0',
        'lap_host_participant_id' => 'INTEGER DEFAULT NULL',
        'lap_side' => 'TEXT DEFAULT NULL',
    ];
    $historyDefinitions = [
        'lap_host_participant_id' => 'INTEGER DEFAULT NULL',
        'lap_side' => 'TEXT DEFAULT NULL',
    ];
    $requestDefinitions = [
        'requested_lap_side' => 'TEXT DEFAULT NULL',
    ];

    if (db_driver($pdo) === 'mysql') {
        $mysqlTypes = [
            'INTEGER' => 'INT',
            'TEXT' => 'VARCHAR(191)',
        ];
        foreach ([
            'avatar_relationships' => $relationshipDefinitions,
            'avatar_relationship_members' => $memberDefinitions,
            'avatar_relationship_membership_history' => $historyDefinitions,
            'avatar_relationship_requests' => $requestDefinitions,
        ] as $table => $definitions) {
            $columns = $pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll();
            $columnNames = array_map(fn(array $column): string => (string)($column['Field'] ?? ''), $columns);
            foreach ($definitions as $column => $definition) {
                if (in_array($column, $columnNames, true)) continue;
                $mysqlDefinition = strtr($definition, $mysqlTypes);
                if (in_array($column, ['status', 'relationship_role', 'permission_role', 'membership_status', 'join_policy', 'lap_side', 'requested_lap_side'], true)) {
                    $mysqlDefinition = str_replace('VARCHAR(191)', 'VARCHAR(32)', $mysqlDefinition);
                }
                if (in_array($column, ['dissolved_at', 'membership_effective_at'], true)) {
                    $mysqlDefinition = str_replace('VARCHAR(191)', 'DATETIME', $mysqlDefinition);
                }
                $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$mysqlDefinition}");
            }
        }
    } else {
        foreach ([
            'avatar_relationships' => $relationshipDefinitions,
            'avatar_relationship_members' => $memberDefinitions,
            'avatar_relationship_membership_history' => $historyDefinitions,
            'avatar_relationship_requests' => $requestDefinitions,
        ] as $table => $definitions) {
            $columns = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
            $columnNames = array_map(fn(array $column): string => (string)($column['name'] ?? ''), $columns);
            foreach ($definitions as $column => $definition) {
                if (!in_array($column, $columnNames, true)) {
                    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
                }
            }
        }
    }

    avatar_relationship_migrate_group_foundation($pdo);
    avatar_relationship_migrate_lap_seats($pdo);
    avatar_relationship_migrate_chat_foundation($pdo);
    avatar_relationship_migrate_options_v2($pdo);

    $indexes = [
        ['avatar_relationship_members', 'idx_avatar_relationship_members_active_participant', true, 'active_participant_id'],
        ['avatar_relationship_members', 'idx_avatar_relationship_members_lap_seat', true, 'relationship_id, lap_host_participant_id, lap_side'],
        ['avatar_relationships', 'idx_avatar_relationships_conversation', true, 'session_id, conversation_public_id'],
        ['avatar_relationships', 'idx_avatar_relationships_status', false, 'session_id, status'],
        ['avatar_relationship_membership_history', 'idx_avatar_relationship_history_relationship', false, 'relationship_id, id'],
        ['avatar_relationship_membership_history', 'idx_avatar_relationship_history_participant', false, 'participant_id, id'],
        ['avatar_relationship_requests', 'idx_avatar_relationship_requests_active_key', true, 'active_request_key'],
        ['avatar_relationship_requests', 'idx_avatar_relationship_requests_relationship', false, 'relationship_id, status, id'],
        ['avatar_relationship_requests', 'idx_avatar_relationship_requests_target', false, 'target_participant_id, status, id'],
    ];
    foreach ($indexes as [$table, $name, $unique, $columns]) {
        try {
            if (db_driver($pdo) === 'mysql') {
                $stmt = $pdo->prepare("SHOW INDEX FROM {$table} WHERE Key_name = ?");
                $stmt->execute([$name]);
                if ($stmt->fetch()) continue;
                $pdo->exec('CREATE ' . ($unique ? 'UNIQUE ' : '') . "INDEX {$name} ON {$table}({$columns})");
            } else {
                $pdo->exec('CREATE ' . ($unique ? 'UNIQUE ' : '') . "INDEX IF NOT EXISTS {$name} ON {$table}({$columns})");
            }
        } catch (Throwable $error) {
            if ($name === 'idx_avatar_relationship_members_lap_seat') throw $error;
            // Existing installs already have this index.
        }
    }
}

function migrate(PDO $pdo): void {
    $schema = "
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            recovery_code_hash TEXT DEFAULT NULL,
            recovery_code_suffix TEXT DEFAULT NULL,
            display_name TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'user',
            avatar_path TEXT DEFAULT 'preset:Default',
            avatar_orientation TEXT NOT NULL DEFAULT 'original',
            avatar_display_size_px INTEGER DEFAULT NULL,
            webcam_display_width_px INTEGER DEFAULT NULL,
            webcam_display_height_px INTEGER DEFAULT NULL,
            avatar_size_version INTEGER NOT NULL DEFAULT 1,
            aura_effect TEXT DEFAULT NULL,
            current_room_id INTEGER DEFAULT NULL,
            last_seen_at TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS rooms (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT DEFAULT NULL UNIQUE,
            owner_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            background_path TEXT DEFAULT NULL,
            background_mime TEXT DEFAULT NULL,
            background_thumb_path TEXT DEFAULT NULL,
            import_url TEXT DEFAULT NULL,
            import_layout_json TEXT DEFAULT NULL,
            music_playlist_json TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(owner_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS room_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT DEFAULT NULL UNIQUE,
            room_id INTEGER NOT NULL UNIQUE,
            started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(room_id) REFERENCES rooms(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS room_deletion_notices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_public_id TEXT NOT NULL,
            join_token TEXT NOT NULL,
            user_id INTEGER DEFAULT NULL,
            room_name TEXT DEFAULT NULL,
            payload TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS participants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            display_name TEXT NOT NULL,
            avatar_path TEXT DEFAULT 'preset:Default',
            avatar_orientation TEXT NOT NULL DEFAULT 'original',
            avatar_display_size_px INTEGER DEFAULT NULL,
            webcam_display_width_px INTEGER DEFAULT NULL,
            webcam_display_height_px INTEGER DEFAULT NULL,
            avatar_size_version INTEGER NOT NULL DEFAULT 1,
            aura_effect TEXT DEFAULT NULL,
            join_token TEXT NOT NULL UNIQUE,
            position_x REAL NOT NULL DEFAULT 0.15,
            position_y REAL NOT NULL DEFAULT 0.25,
            webcam_path TEXT DEFAULT NULL,
            webcam_enabled INTEGER NOT NULL DEFAULT 0,
            linked_to_participant_id INTEGER DEFAULT NULL,
            link_mode TEXT NOT NULL DEFAULT 'normal',
            last_seen_at TEXT DEFAULT NULL,
            joined_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(session_id, user_id),
            FOREIGN KEY(session_id) REFERENCES room_sessions(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(linked_to_participant_id) REFERENCES participants(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id INTEGER NOT NULL,
            participant_id INTEGER,
            user_id INTEGER DEFAULT NULL,
            display_name TEXT DEFAULT NULL,
            avatar_path TEXT DEFAULT NULL,
            avatar_url TEXT DEFAULT NULL,
            content TEXT NOT NULL,
            original_content TEXT DEFAULT NULL,
            url_preview_json TEXT DEFAULT NULL,
            reply_to_json TEXT DEFAULT NULL,
            message_type TEXT NOT NULL DEFAULT 'text',
            file_size INTEGER DEFAULT NULL,
            mime_type TEXT DEFAULT NULL,
            original_name TEXT DEFAULT NULL,
            edited_at TEXT DEFAULT NULL,
            deleted_at TEXT DEFAULT NULL,
            deleted_by_user_id INTEGER DEFAULT NULL,
            is_deleted INTEGER NOT NULL DEFAULT 0,
            sent_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(session_id) REFERENCES room_sessions(id) ON DELETE CASCADE,
            FOREIGN KEY(participant_id) REFERENCES participants(id) ON DELETE SET NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS message_reactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            message_id INTEGER NOT NULL,
            participant_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            emoji TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(message_id, participant_id),
            FOREIGN KEY(message_id) REFERENCES messages(id) ON DELETE CASCADE,
            FOREIGN KEY(participant_id) REFERENCES participants(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id INTEGER NOT NULL,
            type TEXT NOT NULL,
            payload TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(session_id) REFERENCES room_sessions(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS room_effects (
            session_id INTEGER PRIMARY KEY,
            effect_key TEXT NOT NULL,
            started_by_participant_id INTEGER DEFAULT NULL,
            started_by_user_id INTEGER DEFAULT NULL,
            duration_minutes INTEGER DEFAULT NULL,
            started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at TEXT DEFAULT NULL,
            FOREIGN KEY(session_id) REFERENCES room_sessions(id) ON DELETE CASCADE,
            FOREIGN KEY(started_by_participant_id) REFERENCES participants(id) ON DELETE SET NULL,
            FOREIGN KEY(started_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS game_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            room_session_id INTEGER NOT NULL,
            game_type TEXT NOT NULL,
            lobby_code TEXT NOT NULL UNIQUE,
            started_by_participant_id INTEGER NOT NULL,
            started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ended_at TEXT DEFAULT NULL
        );

        CREATE TABLE IF NOT EXISTS game_moves (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            lobby_code TEXT NOT NULL,
            user_id INTEGER NOT NULL,
            payload TEXT NOT NULL,
            sequence INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS game_state (
            lobby_code TEXT PRIMARY KEY,
            state_json TEXT NOT NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS game_lobbies (
            lobby_code TEXT PRIMARY KEY,
            game_id INTEGER NOT NULL DEFAULT 0,
            user1_id INTEGER DEFAULT NULL,
            user2_id INTEGER DEFAULT NULL,
            round_number INTEGER NOT NULL DEFAULT 1,
            status TEXT NOT NULL DEFAULT 'waiting',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS game_chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            lobby_code TEXT NOT NULL,
            participant_id INTEGER NOT NULL,
            content TEXT NOT NULL,
            message_type TEXT NOT NULL DEFAULT 'text',
            file_size INTEGER DEFAULT NULL,
            mime_type TEXT DEFAULT NULL,
            original_name TEXT DEFAULT NULL,
            sent_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS voice_sessions (
            participant_id INTEGER PRIMARY KEY,
            session_id INTEGER NOT NULL,
            muted INTEGER NOT NULL DEFAULT 0,
            deafened INTEGER NOT NULL DEFAULT 0,
            speaking INTEGER NOT NULL DEFAULT 0,
            joined_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS media_signals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id INTEGER NOT NULL,
            media TEXT NOT NULL DEFAULT 'voice',
            from_participant_id INTEGER NOT NULL,
            to_participant_id INTEGER NOT NULL,
            sender_epoch TEXT DEFAULT NULL,
            recipient_epoch TEXT DEFAULT NULL,
            type TEXT NOT NULL,
            data TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at TEXT DEFAULT NULL
        );

        CREATE TABLE IF NOT EXISTS media_signal_clients (
            participant_id INTEGER PRIMARY KEY,
            session_id INTEGER NOT NULL,
            client_epoch TEXT NOT NULL,
            started_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS link_icons (
            session_id INTEGER NOT NULL,
            link_key TEXT NOT NULL,
            icon_name TEXT NOT NULL DEFAULT 'plus',
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(session_id, link_key),
            FOREIGN KEY(session_id) REFERENCES room_sessions(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS avatar_relationships (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id INTEGER NOT NULL,
            relationship_public_id TEXT NOT NULL,
            version INTEGER NOT NULL DEFAULT 1,
            status TEXT NOT NULL DEFAULT 'active',
            creator_participant_id INTEGER DEFAULT NULL,
            join_policy TEXT NOT NULL DEFAULT 'approval-required',
            conversation_public_id TEXT DEFAULT NULL,
            legacy_link_key TEXT DEFAULT NULL,
            mode TEXT NOT NULL DEFAULT 'normal',
            capability TEXT NOT NULL DEFAULT 'normal',
            geometry_strategy TEXT DEFAULT NULL,
            metadata_json TEXT DEFAULT NULL,
            anchors_json TEXT DEFAULT NULL,
            options_json TEXT DEFAULT NULL,
            legacy_initiator_participant_id INTEGER DEFAULT NULL,
            legacy_target_participant_id INTEGER DEFAULT NULL,
            last_synced_from_legacy_at TEXT DEFAULT NULL,
            divergence_status TEXT NOT NULL DEFAULT 'synced',
            dissolved_at TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(session_id, relationship_public_id),
            UNIQUE(session_id, legacy_link_key),
            FOREIGN KEY(session_id) REFERENCES room_sessions(id) ON DELETE CASCADE,
            FOREIGN KEY(creator_participant_id) REFERENCES participants(id) ON DELETE SET NULL,
            FOREIGN KEY(legacy_initiator_participant_id) REFERENCES participants(id) ON DELETE SET NULL,
            FOREIGN KEY(legacy_target_participant_id) REFERENCES participants(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS avatar_relationship_members (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            relationship_id INTEGER NOT NULL,
            participant_id INTEGER NOT NULL,
            member_role TEXT NOT NULL DEFAULT 'member',
            relationship_role TEXT NOT NULL DEFAULT 'normal',
            permission_role TEXT NOT NULL DEFAULT 'member',
            membership_status TEXT NOT NULL DEFAULT 'active',
            active_participant_id INTEGER DEFAULT NULL,
            member_order INTEGER NOT NULL DEFAULT 0,
            membership_effective_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            visible_after_message_id INTEGER NOT NULL DEFAULT 0,
            lap_host_participant_id INTEGER DEFAULT NULL,
            lap_side TEXT DEFAULT NULL,
            anchor_json TEXT DEFAULT NULL,
            options_json TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(relationship_id, participant_id),
            UNIQUE(relationship_id, member_order),
            UNIQUE(active_participant_id),
            FOREIGN KEY(relationship_id) REFERENCES avatar_relationships(id) ON DELETE CASCADE,
            FOREIGN KEY(participant_id) REFERENCES participants(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS avatar_relationship_membership_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            relationship_id INTEGER NOT NULL,
            participant_id INTEGER DEFAULT NULL,
            user_id INTEGER DEFAULT NULL,
            actor_participant_id INTEGER DEFAULT NULL,
            action TEXT NOT NULL,
            outcome TEXT NOT NULL DEFAULT 'applied',
            relationship_role TEXT NOT NULL DEFAULT 'normal',
            permission_role TEXT NOT NULL DEFAULT 'member',
            member_order INTEGER NOT NULL DEFAULT 0,
            membership_effective_at TEXT NOT NULL,
            membership_ended_at TEXT DEFAULT NULL,
            visible_after_message_id INTEGER NOT NULL DEFAULT 0,
            lap_host_participant_id INTEGER DEFAULT NULL,
            lap_side TEXT DEFAULT NULL,
            relationship_version INTEGER NOT NULL DEFAULT 1,
            reason TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(relationship_id) REFERENCES avatar_relationships(id) ON DELETE CASCADE,
            FOREIGN KEY(participant_id) REFERENCES participants(id) ON DELETE SET NULL,
            FOREIGN KEY(actor_participant_id) REFERENCES participants(id) ON DELETE SET NULL,
            FOREIGN KEY(lap_host_participant_id) REFERENCES participants(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS avatar_relationship_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_public_id TEXT NOT NULL UNIQUE,
            relationship_id INTEGER NOT NULL,
            relationship_version INTEGER NOT NULL,
            requester_participant_id INTEGER NOT NULL,
            target_participant_id INTEGER NOT NULL,
            request_type TEXT NOT NULL,
            requested_relationship_role TEXT NOT NULL DEFAULT 'normal',
            requested_lap_host_participant_id INTEGER DEFAULT NULL,
            requested_lap_side TEXT DEFAULT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            active_request_key TEXT DEFAULT NULL UNIQUE,
            resolution_actor_participant_id INTEGER DEFAULT NULL,
            resolution_reason TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at TEXT DEFAULT NULL,
            expires_at TEXT DEFAULT NULL,
            FOREIGN KEY(relationship_id) REFERENCES avatar_relationships(id) ON DELETE CASCADE,
            FOREIGN KEY(requester_participant_id) REFERENCES participants(id) ON DELETE CASCADE,
            FOREIGN KEY(target_participant_id) REFERENCES participants(id) ON DELETE CASCADE,
            FOREIGN KEY(requested_lap_host_participant_id) REFERENCES participants(id) ON DELETE SET NULL,
            FOREIGN KEY(resolution_actor_participant_id) REFERENCES participants(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS link_icon_catalog (
            icon_name TEXT PRIMARY KEY,
            label TEXT NOT NULL,
            file_path TEXT NOT NULL,
            built_in INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS user_blocks (
            blocker_user_id INTEGER NOT NULL,
            blocked_user_id INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(blocker_user_id, blocked_user_id),
            FOREIGN KEY(blocker_user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(blocked_user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS room_ejections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            room_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            ejected_by_user_id INTEGER NOT NULL,
            duration_minutes INTEGER DEFAULT NULL,
            permanent INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at TEXT DEFAULT NULL,
            FOREIGN KEY(room_id) REFERENCES rooms(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(ejected_by_user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS community_ejections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            ejected_by_user_id INTEGER NOT NULL,
            duration_minutes INTEGER DEFAULT NULL,
            permanent INTEGER NOT NULL DEFAULT 0,
            reason TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at TEXT DEFAULT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(ejected_by_user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS app_settings (
            setting_key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS auth_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            scope TEXT NOT NULL,
            dimension TEXT NOT NULL,
            key_hash TEXT NOT NULL,
            attempts INTEGER NOT NULL DEFAULT 0,
            last_attempt_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            locked_until TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(scope, dimension, key_hash)
        );

        CREATE TABLE IF NOT EXISTS gestures (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            owner_user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            gesture_text TEXT NOT NULL,
            gif_path TEXT NOT NULL,
            audio_path TEXT DEFAULT NULL,
            audio_is_silent INTEGER NOT NULL DEFAULT 1,
            is_public INTEGER NOT NULL DEFAULT 0,
            file_size INTEGER DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_at TEXT DEFAULT NULL,
            FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS tool_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_user_id INTEGER DEFAULT NULL,
            target_user_id INTEGER DEFAULT NULL,
            room_id INTEGER DEFAULT NULL,
            action TEXT NOT NULL,
            detail TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY(target_user_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY(room_id) REFERENCES rooms(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS community_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            scope TEXT NOT NULL,
            session_id INTEGER DEFAULT NULL,
            link_key TEXT DEFAULT NULL,
            participant_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            display_name TEXT NOT NULL,
            avatar_path TEXT DEFAULT NULL,
            avatar_url TEXT DEFAULT NULL,
            content TEXT NOT NULL,
            original_content TEXT DEFAULT NULL,
            url_preview_json TEXT DEFAULT NULL,
            reply_to_json TEXT DEFAULT NULL,
            message_type TEXT NOT NULL DEFAULT 'text',
            file_size INTEGER DEFAULT NULL,
            mime_type TEXT DEFAULT NULL,
            original_name TEXT DEFAULT NULL,
            edited_at TEXT DEFAULT NULL,
            deleted_at TEXT DEFAULT NULL,
            deleted_by_user_id INTEGER DEFAULT NULL,
            is_deleted INTEGER NOT NULL DEFAULT 0,
            sent_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS community_message_reactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            message_id INTEGER NOT NULL,
            participant_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            emoji TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(message_id, participant_id),
            FOREIGN KEY(message_id) REFERENCES community_messages(id) ON DELETE CASCADE,
            FOREIGN KEY(participant_id) REFERENCES participants(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS private_message_clears (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            scope TEXT NOT NULL,
            session_id INTEGER NOT NULL DEFAULT 0,
            link_key TEXT NOT NULL,
            cleared_at TEXT NOT NULL,
            UNIQUE(user_id, scope, session_id, link_key),
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS community_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            scope TEXT NOT NULL,
            session_id INTEGER DEFAULT NULL,
            link_key TEXT DEFAULT NULL,
            type TEXT NOT NULL,
            payload TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
    ";

    if (db_driver($pdo) === 'mysql') {
        $driverSchema = db_uses_mysql_syntax($pdo) ? mysqlize_schema($schema) : $schema;
        foreach (split_sql_statements($driverSchema) as $statement) {
            if ($statement !== '') $pdo->exec($statement);
        }
        try {
            $pdo->exec('ALTER TABLE rooms ADD COLUMN background_thumb_path VARCHAR(512) DEFAULT NULL');
        } catch (Throwable $e) {
            // Existing installs already have this column.
        }
        foreach ([
            'import_url VARCHAR(1024) DEFAULT NULL',
            'import_layout_json LONGTEXT DEFAULT NULL',
            'music_playlist_json LONGTEXT DEFAULT NULL',
        ] as $definition) {
            try {
                $pdo->exec('ALTER TABLE rooms ADD COLUMN ' . $definition);
            } catch (Throwable $e) {
                // Existing installs already have this column.
            }
        }
        foreach ([
            'user_id INTEGER DEFAULT NULL',
            'display_name VARCHAR(191) DEFAULT NULL',
            'avatar_path VARCHAR(512) DEFAULT NULL',
            'avatar_url VARCHAR(512) DEFAULT NULL',
            'url_preview_json LONGTEXT DEFAULT NULL',
            'reply_to_json LONGTEXT DEFAULT NULL',
        ] as $definition) {
            try {
                $pdo->exec('ALTER TABLE messages ADD COLUMN ' . $definition);
            } catch (Throwable $e) {
                // Existing installs already have this column.
            }
        }
    } else {
        $pdo->exec($schema);
    }

    if (db_driver($pdo) !== 'sqlite') {
        $mysqlUserCols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll();
        $mysqlUserColNames = array_map(fn(array $col): string => (string)($col['Field'] ?? ''), $mysqlUserCols);
        if (!in_array('recovery_code_hash', $mysqlUserColNames, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN recovery_code_hash VARCHAR(255) DEFAULT NULL');
        }
        if (!in_array('recovery_code_suffix', $mysqlUserColNames, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN recovery_code_suffix VARCHAR(16) DEFAULT NULL');
        }
        if (!in_array('aura_effect', $mysqlUserColNames, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN aura_effect VARCHAR(128) DEFAULT NULL');
        }
        if (!in_array('avatar_orientation', $mysqlUserColNames, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN avatar_orientation VARCHAR(32) NOT NULL DEFAULT 'original'");
        }
        foreach ([
            'avatar_display_size_px' => 'INTEGER DEFAULT NULL',
            'webcam_display_width_px' => 'INTEGER DEFAULT NULL',
            'webcam_display_height_px' => 'INTEGER DEFAULT NULL',
            'avatar_size_version' => 'INTEGER NOT NULL DEFAULT 1',
        ] as $column => $definition) {
            if (!in_array($column, $mysqlUserColNames, true)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN {$column} {$definition}");
            }
        }
        $mysqlParticipantCols = $pdo->query('SHOW COLUMNS FROM participants')->fetchAll();
        $mysqlParticipantColNames = array_map(fn(array $col): string => (string)($col['Field'] ?? ''), $mysqlParticipantCols);
        if (!in_array('webcam_enabled', $mysqlParticipantColNames, true)) {
            $pdo->exec('ALTER TABLE participants ADD COLUMN webcam_enabled INTEGER NOT NULL DEFAULT 0');
        }
        if (!in_array('aura_effect', $mysqlParticipantColNames, true)) {
            $pdo->exec('ALTER TABLE participants ADD COLUMN aura_effect VARCHAR(128) DEFAULT NULL');
        }
        if (!in_array('avatar_orientation', $mysqlParticipantColNames, true)) {
            $pdo->exec("ALTER TABLE participants ADD COLUMN avatar_orientation VARCHAR(32) NOT NULL DEFAULT 'original'");
        }
        foreach ([
            'avatar_display_size_px' => 'INTEGER DEFAULT NULL',
            'webcam_display_width_px' => 'INTEGER DEFAULT NULL',
            'webcam_display_height_px' => 'INTEGER DEFAULT NULL',
            'avatar_size_version' => 'INTEGER NOT NULL DEFAULT 1',
        ] as $column => $definition) {
            if (!in_array($column, $mysqlParticipantColNames, true)) {
                $pdo->exec("ALTER TABLE participants ADD COLUMN {$column} {$definition}");
            }
        }
        if (!in_array('link_mode', $mysqlParticipantColNames, true)) {
            $pdo->exec("ALTER TABLE participants ADD COLUMN link_mode VARCHAR(24) NOT NULL DEFAULT 'normal'");
        }
        foreach (['users', 'participants'] as $orientationTable) {
            $pdo->exec("UPDATE {$orientationTable} SET avatar_orientation = 'original' WHERE avatar_orientation IS NULL OR avatar_orientation NOT IN ('original', 'flip-horizontal', 'flip-vertical', 'flip-both')");
        }
        $mysqlVoiceCols = $pdo->query('SHOW COLUMNS FROM voice_sessions')->fetchAll();
        $mysqlVoiceColNames = array_map(fn(array $col): string => (string)($col['Field'] ?? ''), $mysqlVoiceCols);
        foreach (['muted', 'deafened', 'speaking'] as $voiceCol) {
            if (!in_array($voiceCol, $mysqlVoiceColNames, true)) {
                $pdo->exec("ALTER TABLE voice_sessions ADD COLUMN {$voiceCol} INTEGER NOT NULL DEFAULT 0");
            }
        }
        $mysqlMediaSignalCols = $pdo->query('SHOW COLUMNS FROM media_signals')->fetchAll();
        $mysqlMediaSignalColNames = array_map(fn(array $col): string => (string)($col['Field'] ?? ''), $mysqlMediaSignalCols);
        foreach ([
            'sender_epoch' => 'VARCHAR(96) DEFAULT NULL',
            'recipient_epoch' => 'VARCHAR(96) DEFAULT NULL',
            'expires_at' => 'VARCHAR(32) DEFAULT NULL',
        ] as $column => $definition) {
            if (!in_array($column, $mysqlMediaSignalColNames, true)) {
                $pdo->exec("ALTER TABLE media_signals ADD COLUMN {$column} {$definition}");
            }
        }
        foreach ([
            'CREATE INDEX idx_media_signals_delivery ON media_signals(session_id, to_participant_id, recipient_epoch, id)',
            'CREATE INDEX idx_media_signals_expiry ON media_signals(expires_at)',
            'CREATE INDEX idx_media_signal_clients_session ON media_signal_clients(session_id, client_epoch)',
        ] as $indexSql) {
            try {
                $pdo->exec($indexSql);
            } catch (Throwable $e) {
                // Existing installs already have this index.
            }
        }
        foreach (['messages', 'community_messages'] as $previewTable) {
            $previewCols = $pdo->query('SHOW COLUMNS FROM ' . $previewTable)->fetchAll();
            $previewColNames = array_map(fn(array $col): string => (string)($col['Field'] ?? ''), $previewCols);
            if (!in_array('url_preview_json', $previewColNames, true)) {
                $pdo->exec('ALTER TABLE ' . $previewTable . ' ADD COLUMN url_preview_json LONGTEXT DEFAULT NULL');
            }
            if (!in_array('reply_to_json', $previewColNames, true)) {
                $pdo->exec('ALTER TABLE ' . $previewTable . ' ADD COLUMN reply_to_json LONGTEXT DEFAULT NULL');
            }
        }
        $mysqlGameLobbyCols = $pdo->query('SHOW COLUMNS FROM game_lobbies')->fetchAll();
        $mysqlGameLobbyColNames = array_map(fn(array $col): string => (string)($col['Field'] ?? ''), $mysqlGameLobbyCols);
        if (!in_array('round_number', $mysqlGameLobbyColNames, true)) {
            $pdo->exec('ALTER TABLE game_lobbies ADD COLUMN round_number INTEGER NOT NULL DEFAULT 1');
        }
        migrate_avatar_relationship_group_schema($pdo);
        seed_app_settings($pdo);
        seed_link_icon_catalog($pdo);
        return;
    }

    $userCols = $pdo->query('PRAGMA table_info(users)')->fetchAll();
    $userColNames = array_map(fn(array $col): string => (string)$col['name'], $userCols);
    if (!in_array('role', $userColNames, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'user'");
    }
    if (!in_array('recovery_code_hash', $userColNames, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN recovery_code_hash TEXT DEFAULT NULL');
    }
    if (!in_array('recovery_code_suffix', $userColNames, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN recovery_code_suffix TEXT DEFAULT NULL');
    }
    if (!in_array('aura_effect', $userColNames, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN aura_effect TEXT DEFAULT NULL');
    }
    if (!in_array('avatar_orientation', $userColNames, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN avatar_orientation TEXT NOT NULL DEFAULT 'original'");
    }
    foreach ([
        'avatar_display_size_px' => 'INTEGER DEFAULT NULL',
        'webcam_display_width_px' => 'INTEGER DEFAULT NULL',
        'webcam_display_height_px' => 'INTEGER DEFAULT NULL',
        'avatar_size_version' => 'INTEGER NOT NULL DEFAULT 1',
    ] as $column => $definition) {
        if (!in_array($column, $userColNames, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN {$column} {$definition}");
        }
    }
    $settingsCols = $pdo->query('PRAGMA table_info(app_settings)')->fetchAll();
    $settingsColNames = array_map(fn(array $col): string => (string)$col['name'], $settingsCols);
    if (in_array('key', $settingsColNames, true) && !in_array('setting_key', $settingsColNames, true)) {
        $pdo->exec('ALTER TABLE app_settings RENAME COLUMN key TO setting_key');
    }
    seed_app_settings($pdo);
    seed_link_icon_catalog($pdo);

    $cols = $pdo->query('PRAGMA table_info(rooms)')->fetchAll();
    $hasPublicId = false;
    foreach ($cols as $col) {
        if (($col['name'] ?? '') === 'public_id') $hasPublicId = true;
    }
    if (!$hasPublicId) {
        $pdo->exec('ALTER TABLE rooms ADD COLUMN public_id TEXT DEFAULT NULL');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_rooms_public_id ON rooms(public_id)');
    }
    $roomColNames = array_map(fn(array $col): string => (string)$col['name'], $cols);
    if (!in_array('background_thumb_path', $roomColNames, true)) {
        $pdo->exec('ALTER TABLE rooms ADD COLUMN background_thumb_path TEXT DEFAULT NULL');
    }
    if (!in_array('import_url', $roomColNames, true)) {
        $pdo->exec('ALTER TABLE rooms ADD COLUMN import_url TEXT DEFAULT NULL');
    }
    if (!in_array('import_layout_json', $roomColNames, true)) {
        $pdo->exec('ALTER TABLE rooms ADD COLUMN import_layout_json TEXT DEFAULT NULL');
    }
    if (!in_array('music_playlist_json', $roomColNames, true)) {
        $pdo->exec('ALTER TABLE rooms ADD COLUMN music_playlist_json TEXT DEFAULT NULL');
    }
    $sessionCols = $pdo->query('PRAGMA table_info(room_sessions)')->fetchAll();
    $hasSessionPublicId = false;
    foreach ($sessionCols as $col) {
        if (($col['name'] ?? '') === 'public_id') $hasSessionPublicId = true;
    }
    if (!$hasSessionPublicId) {
        $pdo->exec('ALTER TABLE room_sessions ADD COLUMN public_id TEXT DEFAULT NULL');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_room_sessions_public_id ON room_sessions(public_id)');
    }
    $participantCols = $pdo->query('PRAGMA table_info(participants)')->fetchAll();
    $participantColNames = array_map(fn(array $col): string => (string)$col['name'], $participantCols);
    $hasLinkedTo = false;
    foreach ($participantCols as $col) {
        if (($col['name'] ?? '') === 'linked_to_participant_id') $hasLinkedTo = true;
    }
    if (!in_array('webcam_enabled', $participantColNames, true)) {
        $pdo->exec('ALTER TABLE participants ADD COLUMN webcam_enabled INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('aura_effect', $participantColNames, true)) {
        $pdo->exec('ALTER TABLE participants ADD COLUMN aura_effect TEXT DEFAULT NULL');
    }
    if (!in_array('avatar_orientation', $participantColNames, true)) {
        $pdo->exec("ALTER TABLE participants ADD COLUMN avatar_orientation TEXT NOT NULL DEFAULT 'original'");
    }
    foreach ([
        'avatar_display_size_px' => 'INTEGER DEFAULT NULL',
        'webcam_display_width_px' => 'INTEGER DEFAULT NULL',
        'webcam_display_height_px' => 'INTEGER DEFAULT NULL',
        'avatar_size_version' => 'INTEGER NOT NULL DEFAULT 1',
    ] as $column => $definition) {
        if (!in_array($column, $participantColNames, true)) {
            $pdo->exec("ALTER TABLE participants ADD COLUMN {$column} {$definition}");
        }
    }
    if (!$hasLinkedTo) {
        $pdo->exec('ALTER TABLE participants ADD COLUMN linked_to_participant_id INTEGER DEFAULT NULL');
    }
    if (!in_array('link_mode', $participantColNames, true)) {
        $pdo->exec("ALTER TABLE participants ADD COLUMN link_mode TEXT NOT NULL DEFAULT 'normal'");
    }
    foreach (['users', 'participants'] as $orientationTable) {
        $pdo->exec("UPDATE {$orientationTable} SET avatar_orientation = 'original' WHERE avatar_orientation IS NULL OR avatar_orientation NOT IN ('original', 'flip-horizontal', 'flip-vertical', 'flip-both')");
    }
    $voiceCols = $pdo->query('PRAGMA table_info(voice_sessions)')->fetchAll();
    $voiceColNames = array_map(fn(array $col): string => (string)$col['name'], $voiceCols);
    foreach (['muted', 'deafened', 'speaking'] as $voiceCol) {
        if (!in_array($voiceCol, $voiceColNames, true)) {
            $pdo->exec("ALTER TABLE voice_sessions ADD COLUMN {$voiceCol} INTEGER NOT NULL DEFAULT 0");
        }
    }
    $mediaSignalCols = $pdo->query('PRAGMA table_info(media_signals)')->fetchAll();
    $mediaSignalColNames = array_map(fn(array $col): string => (string)$col['name'], $mediaSignalCols);
    foreach (['sender_epoch', 'recipient_epoch', 'expires_at'] as $mediaSignalCol) {
        if (!in_array($mediaSignalCol, $mediaSignalColNames, true)) {
            $pdo->exec("ALTER TABLE media_signals ADD COLUMN {$mediaSignalCol} TEXT DEFAULT NULL");
        }
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_media_signals_delivery ON media_signals(session_id, to_participant_id, recipient_epoch, id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_media_signals_expiry ON media_signals(expires_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_media_signal_clients_session ON media_signal_clients(session_id, client_epoch)');
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS room_effects (
            session_id INTEGER PRIMARY KEY,
            effect_key TEXT NOT NULL,
            started_by_participant_id INTEGER DEFAULT NULL,
            started_by_user_id INTEGER DEFAULT NULL,
            duration_minutes INTEGER DEFAULT NULL,
            started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at TEXT DEFAULT NULL,
            FOREIGN KEY(session_id) REFERENCES room_sessions(id) ON DELETE CASCADE,
            FOREIGN KEY(started_by_participant_id) REFERENCES participants(id) ON DELETE SET NULL,
            FOREIGN KEY(started_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        )"
    );
    $messageCols = $pdo->query('PRAGMA table_info(messages)')->fetchAll();
    $messageColNames = array_map(fn(array $col): string => (string)$col['name'], $messageCols);
    if (!in_array('message_type', $messageColNames, true)) {
        $pdo->exec("ALTER TABLE messages ADD COLUMN message_type TEXT NOT NULL DEFAULT 'text'");
    }
    if (!in_array('user_id', $messageColNames, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN user_id INTEGER DEFAULT NULL');
    }
    if (!in_array('display_name', $messageColNames, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN display_name TEXT DEFAULT NULL');
    }
    if (!in_array('avatar_path', $messageColNames, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN avatar_path TEXT DEFAULT NULL');
    }
    if (!in_array('avatar_url', $messageColNames, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN avatar_url TEXT DEFAULT NULL');
    }
    if (!in_array('original_content', $messageColNames, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN original_content TEXT DEFAULT NULL');
    }
    if (!in_array('url_preview_json', $messageColNames, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN url_preview_json TEXT DEFAULT NULL');
    }
    if (!in_array('reply_to_json', $messageColNames, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN reply_to_json TEXT DEFAULT NULL');
    }
    if (!in_array('file_size', $messageColNames, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN file_size INTEGER DEFAULT NULL');
    }
    if (!in_array('mime_type', $messageColNames, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN mime_type TEXT DEFAULT NULL');
    }
    if (!in_array('original_name', $messageColNames, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN original_name TEXT DEFAULT NULL');
    }
    if (!in_array('edited_at', $messageColNames, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN edited_at TEXT DEFAULT NULL');
    }
    if (!in_array('deleted_at', $messageColNames, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN deleted_at TEXT DEFAULT NULL');
    }
    if (!in_array('deleted_by_user_id', $messageColNames, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN deleted_by_user_id INTEGER DEFAULT NULL');
    }
    if (!in_array('is_deleted', $messageColNames, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN is_deleted INTEGER NOT NULL DEFAULT 0');
    }
    $communityMessageCols = $pdo->query('PRAGMA table_info(community_messages)')->fetchAll();
    $communityMessageColNames = array_map(fn(array $col): string => (string)$col['name'], $communityMessageCols);
    if (!in_array('original_content', $communityMessageColNames, true)) {
        $pdo->exec('ALTER TABLE community_messages ADD COLUMN original_content TEXT DEFAULT NULL');
    }
    if (!in_array('url_preview_json', $communityMessageColNames, true)) {
        $pdo->exec('ALTER TABLE community_messages ADD COLUMN url_preview_json TEXT DEFAULT NULL');
    }
    if (!in_array('reply_to_json', $communityMessageColNames, true)) {
        $pdo->exec('ALTER TABLE community_messages ADD COLUMN reply_to_json TEXT DEFAULT NULL');
    }
    if (!in_array('message_type', $communityMessageColNames, true)) {
        $pdo->exec("ALTER TABLE community_messages ADD COLUMN message_type TEXT NOT NULL DEFAULT 'text'");
    }
    if (!in_array('file_size', $communityMessageColNames, true)) {
        $pdo->exec('ALTER TABLE community_messages ADD COLUMN file_size INTEGER DEFAULT NULL');
    }
    if (!in_array('mime_type', $communityMessageColNames, true)) {
        $pdo->exec('ALTER TABLE community_messages ADD COLUMN mime_type TEXT DEFAULT NULL');
    }
    if (!in_array('original_name', $communityMessageColNames, true)) {
        $pdo->exec('ALTER TABLE community_messages ADD COLUMN original_name TEXT DEFAULT NULL');
    }
    if (!in_array('edited_at', $communityMessageColNames, true)) {
        $pdo->exec('ALTER TABLE community_messages ADD COLUMN edited_at TEXT DEFAULT NULL');
    }
    if (!in_array('deleted_at', $communityMessageColNames, true)) {
        $pdo->exec('ALTER TABLE community_messages ADD COLUMN deleted_at TEXT DEFAULT NULL');
    }
    if (!in_array('deleted_by_user_id', $communityMessageColNames, true)) {
        $pdo->exec('ALTER TABLE community_messages ADD COLUMN deleted_by_user_id INTEGER DEFAULT NULL');
    }
    if (!in_array('is_deleted', $communityMessageColNames, true)) {
        $pdo->exec('ALTER TABLE community_messages ADD COLUMN is_deleted INTEGER NOT NULL DEFAULT 0');
    }
    $gameChatCols = $pdo->query('PRAGMA table_info(game_chat_messages)')->fetchAll();
    $gameChatColNames = array_map(fn(array $col): string => (string)$col['name'], $gameChatCols);
    if (!in_array('message_type', $gameChatColNames, true)) {
        $pdo->exec("ALTER TABLE game_chat_messages ADD COLUMN message_type TEXT NOT NULL DEFAULT 'text'");
    }
    if (!in_array('file_size', $gameChatColNames, true)) {
        $pdo->exec('ALTER TABLE game_chat_messages ADD COLUMN file_size INTEGER DEFAULT NULL');
    }
    if (!in_array('mime_type', $gameChatColNames, true)) {
        $pdo->exec('ALTER TABLE game_chat_messages ADD COLUMN mime_type TEXT DEFAULT NULL');
    }
    if (!in_array('original_name', $gameChatColNames, true)) {
        $pdo->exec('ALTER TABLE game_chat_messages ADD COLUMN original_name TEXT DEFAULT NULL');
    }
    $gameLobbyCols = $pdo->query('PRAGMA table_info(game_lobbies)')->fetchAll();
    $gameLobbyColNames = array_map(fn(array $col): string => (string)$col['name'], $gameLobbyCols);
    if (!in_array('round_number', $gameLobbyColNames, true)) {
        $pdo->exec('ALTER TABLE game_lobbies ADD COLUMN round_number INTEGER NOT NULL DEFAULT 1');
    }
    $stmt = $pdo->query('SELECT id FROM rooms WHERE public_id IS NULL OR public_id = ""');
    foreach ($stmt->fetchAll() as $row) {
        $update = $pdo->prepare('UPDATE rooms SET public_id = ? WHERE id = ?');
        $update->execute([uuid_v4(), (int)$row['id']]);
    }
    $stmt = $pdo->query('SELECT id FROM room_sessions WHERE public_id IS NULL OR public_id = ""');
    foreach ($stmt->fetchAll() as $row) {
        $update = $pdo->prepare('UPDATE room_sessions SET public_id = ? WHERE id = ?');
        $update->execute([uuid_v4(), (int)$row['id']]);
    }
    migrate_avatar_relationship_group_schema($pdo);
}

function default_link_icons(): array {
    return [
        'plus' => 'Plus',
        'heart' => 'Heart',
        'wedding-rings' => 'Wedding Rings',
        'wedding-rings-lesbian' => 'Wedding Rings Lesbian',
        'wedding-rings-gay' => 'Wedding Rings Gay',
        'help' => 'Help',
        'archer' => 'Archer',
        'cross-swords' => 'Cross Swords',
        'lips' => 'Lips',
        'lotus' => 'Lotus',
        'handcuffs' => 'Handcuffs',
    ];
}

function seed_link_icon_catalog(PDO $pdo): void {
    $stmt = $pdo->prepare(db_uses_mysql_syntax($pdo)
        ? 'INSERT IGNORE INTO link_icon_catalog (icon_name, label, file_path, built_in) VALUES (?,?,?,1)'
        : 'INSERT OR IGNORE INTO link_icon_catalog (icon_name, label, file_path, built_in) VALUES (?,?,?,1)'
    );
    foreach (default_link_icons() as $name => $label) {
        $stmt->execute([$name, $label, '/assets/images/cs-icons/' . $name . '.png']);
    }
}

function link_icon_catalog(PDO $pdo): array {
    seed_link_icon_catalog($pdo);
    $rows = $pdo->query('SELECT icon_name, label, file_path, built_in, created_at, updated_at FROM link_icon_catalog ORDER BY built_in DESC, label ASC')->fetchAll();
    return array_map(fn(array $row): array => [
        'icon_name' => $row['icon_name'],
        'label' => $row['label'],
        'file_path' => $row['file_path'],
        'built_in' => !empty($row['built_in']),
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ], $rows);
}

function allowed_link_icon_names(PDO $pdo): array {
    return array_merge(['none'], array_map(fn(array $row): string => (string)$row['icon_name'], link_icon_catalog($pdo)));
}

function upsert_link_icon_catalog(PDO $pdo, string $iconName, string $label, string $filePath, bool $builtIn = false): void {
    $stmt = $pdo->prepare(db_uses_mysql_syntax($pdo)
        ? 'INSERT INTO link_icon_catalog (icon_name, label, file_path, built_in, updated_at) VALUES (?,?,?,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE label = VALUES(label), file_path = VALUES(file_path), built_in = VALUES(built_in), updated_at = CURRENT_TIMESTAMP'
        : 'INSERT INTO link_icon_catalog (icon_name, label, file_path, built_in, updated_at) VALUES (?,?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(icon_name) DO UPDATE SET label = excluded.label, file_path = excluded.file_path, built_in = excluded.built_in, updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([$iconName, $label, $filePath, $builtIn ? 1 : 0]);
}

function seed_app_settings(PDO $pdo): void {
    $defaults = array_merge([
        'chat_posts_per_second' => '3',
        'room_chat_history_limit' => '100',
        'avatar_movements_per_second' => '12',
        'avatar_max_size_mb' => '5',
        'gesture_upload_limit' => '50',
        'room_image_max_size_mb' => '10',
        'room_video_max_size_mb' => '200',
        'participant_idle_timeout_minutes' => '2',
        'auth_login_max_attempts' => '5',
        'auth_recovery_max_attempts' => '5',
        'auth_ip_max_attempts' => '30',
        'auth_attempt_window_minutes' => '15',
        'auth_lockout_minutes' => '15',
        'gif_giphy_api_key' => '',
        'gif_tenor_api_key' => '',
        'gif_klipy_api_key' => '',
        'gif_default_provider' => 'giphy',
        'age_gate_enabled' => '0',
        'age_gate_min_age' => '13',
        'community_name' => '',
        'community_logo_path' => '',
    ], avatar_size_policy_setting_defaults());
    $stmt = $pdo->prepare(db_uses_mysql_syntax($pdo)
        ? 'INSERT IGNORE INTO app_settings (setting_key, value) VALUES (?,?)'
        : 'INSERT OR IGNORE INTO app_settings (setting_key, value) VALUES (?,?)'
    );
    foreach ($defaults as $key => $value) {
        $stmt->execute([$key, $value]);
    }
}

function app_setting(PDO $pdo, string $key, string $default = ''): string {
    $stmt = $pdo->prepare('SELECT value FROM app_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string)$value;
}

function app_setting_float(PDO $pdo, string $key, float $default): float {
    $value = (float)app_setting($pdo, $key, (string)$default);
    return $value > 0 ? $value : $default;
}

function app_setting_bytes(PDO $pdo, string $key, float $defaultMb): int {
    return (int)round(app_setting_float($pdo, $key, $defaultMb) * 1024 * 1024);
}

function set_app_setting(PDO $pdo, string $key, string $value): void {
    $stmt = $pdo->prepare(db_uses_mysql_syntax($pdo)
        ? 'INSERT INTO app_settings (setting_key, value) VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)'
        : 'INSERT INTO app_settings (setting_key, value) VALUES (?,?) ON CONFLICT(setting_key) DO UPDATE SET value = excluded.value'
    );
    $stmt->execute([$key, $value]);
}

function client_ip_address(): string {
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
    $key = $scope === 'recovery' ? 'auth_recovery_max_attempts' : 'auth_login_max_attempts';
    return max(1, (int)app_setting_float($pdo, $key, $scope === 'recovery' ? 5 : 5));
}

function auth_rate_ip_max(PDO $pdo): int {
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

function auth_rate_cleanup(PDO $pdo): void {
    $windowMinutes = auth_rate_window_minutes($pdo);
    $cutoff = gmdate('Y-m-d H:i:s', time() - (int)ceil($windowMinutes * 60));
    $now = gmdate('Y-m-d H:i:s');
    $stmt = $pdo->prepare('DELETE FROM auth_attempts WHERE last_attempt_at < ? AND (locked_until IS NULL OR locked_until < ?)');
    $stmt->execute([$cutoff, $now]);
}

function auth_rate_limit_status(PDO $pdo, string $scope, string $identifier): array {
    auth_rate_cleanup($pdo);
    $now = time();
    $blockedUntil = 0;
    $stmt = $pdo->prepare('SELECT dimension, locked_until FROM auth_attempts WHERE scope = ? AND dimension = ? AND key_hash = ? LIMIT 1');
    foreach (auth_rate_keys($scope, $identifier) as $key) {
        $stmt->execute([$scope, $key['dimension'], $key['hash']]);
        $row = $stmt->fetch();
        if (!$row || empty($row['locked_until'])) continue;
        $until = strtotime((string)$row['locked_until']) ?: 0;
        if ($until > $blockedUntil) $blockedUntil = $until;
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

function auth_rate_record_failure(PDO $pdo, string $scope, string $identifier): void {
    $now = gmdate('Y-m-d H:i:s');
    $windowCutoff = gmdate('Y-m-d H:i:s', time() - (int)ceil(auth_rate_window_minutes($pdo) * 60));
    $lockedUntil = gmdate('Y-m-d H:i:s', time() + (int)ceil(auth_rate_lockout_minutes($pdo) * 60));
    $select = $pdo->prepare('SELECT attempts, last_attempt_at FROM auth_attempts WHERE scope = ? AND dimension = ? AND key_hash = ? LIMIT 1');
    $write = $pdo->prepare(db_uses_mysql_syntax($pdo)
        ? 'INSERT INTO auth_attempts (scope, dimension, key_hash, attempts, last_attempt_at, locked_until) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE attempts = VALUES(attempts), last_attempt_at = VALUES(last_attempt_at), locked_until = VALUES(locked_until)'
        : 'INSERT INTO auth_attempts (scope, dimension, key_hash, attempts, last_attempt_at, locked_until) VALUES (?,?,?,?,?,?) ON CONFLICT(scope, dimension, key_hash) DO UPDATE SET attempts = excluded.attempts, last_attempt_at = excluded.last_attempt_at, locked_until = excluded.locked_until'
    );
    foreach (auth_rate_keys($scope, $identifier) as $key) {
        $select->execute([$scope, $key['dimension'], $key['hash']]);
        $row = $select->fetch();
        $attempts = 1;
        if ($row && (string)($row['last_attempt_at'] ?? '') >= $windowCutoff) {
            $attempts = ((int)$row['attempts']) + 1;
        }
        $max = $key['dimension'] === 'ip' ? auth_rate_ip_max($pdo) : auth_rate_scope_max($pdo, $scope);
        $lock = $attempts >= $max ? $lockedUntil : null;
        $write->execute([$scope, $key['dimension'], $key['hash'], $attempts, $now, $lock]);
    }
}

function auth_rate_clear_identifier(PDO $pdo, string $scope, string $identifier): void {
    $hash = auth_rate_key_hash($scope, 'identifier', trim($identifier) !== '' ? trim($identifier) : '(blank)');
    $stmt = $pdo->prepare("DELETE FROM auth_attempts WHERE scope = ? AND dimension = 'identifier' AND key_hash = ?");
    $stmt->execute([$scope, $hash]);
}

function install_branding(?PDO $pdo = null): array {
    $defaults = [
        'community_name' => '',
        'logo_path' => '/assets/images/logos/chatspace-ce-full-logo.png',
        'powered_logo_path' => '/assets/images/logos/chatspace-ce-full-logo.png',
        'has_custom_logo' => false,
    ];
    if (!chatspace_configured()) return $defaults;
    try {
        $pdo = $pdo ?: db();
        $name = trim(app_setting($pdo, 'community_name', ''));
        $logo = trim(app_setting($pdo, 'community_logo_path', ''));
        return [
            'community_name' => $name,
            'logo_path' => $logo !== '' ? $logo : $defaults['logo_path'],
            'powered_logo_path' => $defaults['powered_logo_path'],
            'has_custom_logo' => $logo !== '',
        ];
    } catch (Throwable) {
        return $defaults;
    }
}

function branded_page_title(string $page, ?PDO $pdo = null): string {
    $brand = install_branding($pdo);
    $prefix = $brand['community_name'] !== '' ? $brand['community_name'] . ' - ' : '';
    return $prefix . $page . ' - ChatSpace CE';
}

function gesture_snapshot(array $gesture): array {
    return [
        'id' => (int)$gesture['id'],
        'public_id' => $gesture['public_id'],
        'name' => $gesture['name'],
        'text' => $gesture['gesture_text'],
        'gif_path' => $gesture['gif_path'],
        'audio_path' => $gesture['audio_path'] ?? null,
        'audio_is_silent' => !empty($gesture['audio_is_silent']),
        'is_public' => !empty($gesture['is_public']),
        'owner_user_id' => (int)$gesture['owner_user_id'],
    ];
}

function message_gesture(?string $content): ?array {
    if (!$content) return null;
    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : null;
}

function message_url_preview(?string $content): ?array {
    if (!$content) return null;
    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : null;
}

function first_url_in_text(string $text): ?string {
    if (!preg_match('~https?://[^\s<>"\']+~i', $text, $m)) return null;
    return rtrim($m[0], ".,!?)]}");
}

function preview_host_is_safe(string $host): bool {
    $host = strtolower(trim($host, "[] \t\r\n"));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
        return false;
    }
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) return false;
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    }
    return true;
}

function host_matches_domain(string $host, string $domain): bool {
    $host = strtolower($host);
    $domain = strtolower($domain);
    return $host === $domain || str_ends_with($host, '.' . $domain);
}

function youtube_embed_url(array $parts): ?string {
    $host = strtolower((string)($parts['host'] ?? ''));
    $path = trim((string)($parts['path'] ?? ''), '/');
    parse_str((string)($parts['query'] ?? ''), $query);
    $id = '';
    if (host_matches_domain($host, 'youtu.be')) {
        $id = explode('/', $path)[0] ?? '';
    } elseif (($query['v'] ?? '') !== '') {
        $id = (string)$query['v'];
    } elseif (preg_match('~(?:embed|shorts)/([A-Za-z0-9_-]{6,})~', $path, $m)) {
        $id = $m[1];
    }
    return preg_match('/^[A-Za-z0-9_-]{6,}$/', $id) ? 'https://www.youtube-nocookie.com/embed/' . rawurlencode($id) : null;
}

function spotify_embed_url(array $parts): ?string {
    $host = strtolower((string)($parts['host'] ?? ''));
    if (!host_matches_domain($host, 'spotify.com')) return null;
    $segments = array_values(array_filter(explode('/', trim((string)($parts['path'] ?? ''), '/'))));
    $allowed = ['album', 'artist', 'episode', 'playlist', 'show', 'track'];
    $type = $segments[0] ?? '';
    $id = $segments[1] ?? '';
    if (!in_array($type, $allowed, true) || !preg_match('/^[A-Za-z0-9]+$/', $id)) return null;
    return 'https://open.spotify.com/embed/' . rawurlencode($type) . '/' . rawurlencode($id);
}

function soundcloud_embed_url(string $url, array $parts): ?string {
    $host = strtolower((string)($parts['host'] ?? ''));
    if (!host_matches_domain($host, 'soundcloud.com')) return null;
    return 'https://w.soundcloud.com/player/?url=' . rawurlencode($url) . '&auto_play=false&hide_related=true&show_comments=false&show_user=true&show_reposts=false&visual=false';
}

function html_meta_value(string $html, string $name): ?string {
    $quoted = preg_quote($name, '~');
    $patterns = [
        '~<meta[^>]+(?:property|name)=["\']' . $quoted . '["\'][^>]+content=["\']([^"\']+)["\'][^>]*>~i',
        '~<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']' . $quoted . '["\'][^>]*>~i',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
    }
    return null;
}

function absolutize_preview_url(string $assetUrl, string $pageUrl): string {
    if ($assetUrl === '' || preg_match('#^https?://#i', $assetUrl)) return $assetUrl;
    $parts = parse_url($pageUrl);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return '';
    $base = $parts['scheme'] . '://' . $parts['host'] . (!empty($parts['port']) ? ':' . $parts['port'] : '');
    if (str_starts_with($assetUrl, '//')) return $parts['scheme'] . ':' . $assetUrl;
    if (str_starts_with($assetUrl, '/')) return $base . $assetUrl;
    $dir = rtrim(dirname((string)($parts['path'] ?? '/')), '/');
    return $base . ($dir ? $dir . '/' : '/') . $assetUrl;
}

function fetch_url_metadata(string $url): array {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 2.5,
            'follow_location' => 1,
            'max_redirects' => 3,
            'ignore_errors' => true,
            'header' => "User-Agent: ChatSpaceCE-LinkPreview/1.0\r\nAccept: text/html,application/xhtml+xml\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $handle = @fopen($url, 'rb', false, $context);
    if (!$handle) return [];
    $html = stream_get_contents($handle, 131072);
    fclose($handle);
    if (!is_string($html) || $html === '') return [];
    $title = html_meta_value($html, 'og:title') ?: html_meta_value($html, 'twitter:title');
    if (!$title && preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m)) {
        $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    $description = html_meta_value($html, 'og:description') ?: html_meta_value($html, 'description') ?: html_meta_value($html, 'twitter:description');
    $image = html_meta_value($html, 'og:image') ?: html_meta_value($html, 'twitter:image');
    if ($image) $image = absolutize_preview_url($image, $url);
    return [
        'title' => $title ? (function_exists('mb_substr') ? mb_substr($title, 0, 140, 'UTF-8') : substr($title, 0, 140)) : '',
        'description' => $description ? (function_exists('mb_substr') ? mb_substr($description, 0, 220, 'UTF-8') : substr($description, 0, 220)) : '',
        'image_url' => $image ?: '',
    ];
}

function url_preview_for_text(string $text): ?array {
    $url = first_url_in_text($text);
    if (!$url) return null;
    $parts = parse_url($url);
    if (!$parts || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) {
        return null;
    }
    $host = strtolower((string)$parts['host']);
    $provider = '';
    $embedUrl = null;
    if (host_matches_domain($host, 'youtube.com') || host_matches_domain($host, 'youtu.be')) {
        $provider = 'YouTube';
        $embedUrl = youtube_embed_url($parts);
    } elseif (host_matches_domain($host, 'spotify.com')) {
        $provider = 'Spotify';
        $embedUrl = spotify_embed_url($parts);
    } elseif (host_matches_domain($host, 'soundcloud.com')) {
        $provider = 'SoundCloud';
        $embedUrl = soundcloud_embed_url($url, $parts);
    }
    if ($embedUrl === null && !preview_host_is_safe($host)) return null;

    $meta = preview_host_is_safe($host) ? fetch_url_metadata($url) : [];
    $preview = [
        'url' => $url,
        'host' => preg_replace('/^www\./', '', $host),
        'provider' => $provider,
        'type' => $embedUrl ? 'player' : 'summary',
        'embed_url' => $embedUrl ?: '',
        'title' => $meta['title'] ?? '',
        'description' => $meta['description'] ?? '',
        'image_url' => $meta['image_url'] ?? '',
    ];
    if ($preview['type'] === 'summary' && $preview['title'] === '' && $preview['description'] === '' && $preview['image_url'] === '') {
        return null;
    }
    return $preview;
}

function save_room_background_upload(array $upload, ?array $thumbUpload = null): array {
    if (empty($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
        return ['path' => null, 'mime' => null, 'thumb_path' => null];
    }
    $pdo = db();
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($upload['tmp_name']) ?: '';
    $allowed = ['image/jpeg','image/png','image/webp','image/gif','video/mp4','video/webm'];
    if (!in_array($mime, $allowed, true)) {
        throw new RuntimeException('Unsupported background type');
    }
    $isVideo = str_starts_with($mime, 'video/');
    $maxBytes = $isVideo ? app_setting_bytes($pdo, 'room_video_max_size_mb', 200) : app_setting_bytes($pdo, 'room_image_max_size_mb', 10);
    if ((int)($upload['size'] ?? 0) > $maxBytes) {
        throw new RuntimeException('Background file is too large');
    }
    $ext = match ($mime) {
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        default => 'jpg',
    };
    $dir = __DIR__ . '/../assets/uploads/backgrounds';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $base = bin2hex(random_bytes(12));
    $file = $base . '.' . $ext;
    $dest = $dir . '/' . $file;
    if (!move_uploaded_file($upload['tmp_name'], $dest)) {
        throw new RuntimeException('Could not save background');
    }
    $path = '/assets/uploads/backgrounds/' . $file;
    $thumbPath = null;
    if ($isVideo && $thumbUpload && !empty($thumbUpload['tmp_name']) && is_uploaded_file($thumbUpload['tmp_name'])) {
        $thumbInfo = new finfo(FILEINFO_MIME_TYPE);
        $thumbMime = $thumbInfo->file($thumbUpload['tmp_name']) ?: '';
        if (in_array($thumbMime, ['image/jpeg', 'image/png', 'image/webp'], true) && (int)($thumbUpload['size'] ?? 0) <= 2 * 1024 * 1024) {
            $thumbFile = $base . '-thumb.jpg';
            $thumbDest = $dir . '/' . $thumbFile;
            if (move_uploaded_file($thumbUpload['tmp_name'], $thumbDest)) {
                $thumbPath = '/assets/uploads/backgrounds/' . $thumbFile;
            }
        }
    }
    if ($isVideo && !$thumbPath && function_exists('shell_exec')) {
        $thumbFile = $base . '-thumb.jpg';
        $thumbDest = $dir . '/' . $thumbFile;
        $cmd = 'ffmpeg -y -i ' . escapeshellarg($dest) . ' -ss 00:00:01 -frames:v 1 -vf ' . escapeshellarg('scale=720:-1') . ' ' . escapeshellarg($thumbDest) . ' 2>/dev/null';
        @shell_exec($cmd);
        if (is_file($thumbDest) && filesize($thumbDest) > 0) {
            $thumbPath = '/assets/uploads/backgrounds/' . $thumbFile;
        }
    }
    return ['path' => $path, 'mime' => $mime, 'thumb_path' => $thumbPath];
}

function log_tool(PDO $pdo, ?int $actorUserId, string $action, ?int $targetUserId = null, ?int $roomId = null, ?string $detail = null): void {
    $stmt = $pdo->prepare('INSERT INTO tool_logs (actor_user_id, target_user_id, room_id, action, detail) VALUES (?,?,?,?,?)');
    $stmt->execute([$actorUserId, $targetUserId, $roomId, $action, $detail]);
}

function uuid_v4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function current_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function authenticate_user(int $userId): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    unset($_SESSION['_csrf_token']);
}

function require_user(): array {
    $user = current_user();
    if (!$user) {
        redirect_to('/login.php');
    }
    return $user;
}

function require_staff(array $roles = ['admin', 'developer']): array {
    $user = require_user();
    if (!in_array($user['role'] ?? 'user', $roles, true)) {
        json_out(['error' => 'Admin required'], 403);
    }
    return $user;
}

function json_out(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function request_raw_body(): string {
    static $raw = null;
    if ($raw !== null) return $raw;
    $body = file_get_contents('php://input');
    $raw = is_string($body) ? $body : '';
    return $raw;
}

function input_json(): array {
    $raw = request_raw_body();
    if ($raw !== false && trim($raw) !== '') {
        $data = json_decode($raw, true);
        if (is_array($data)) return $data;
    }
    return $_POST;
}

function csrf_token(): string {
    if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

function csrf_request_token(?array $jsonBody = null): string {
    $header = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($header !== '') return $header;
    $posted = (string)($_POST['_csrf'] ?? '');
    if ($posted !== '') return $posted;
    if ($jsonBody === null) {
        $raw = request_raw_body();
        $decoded = trim($raw) !== '' ? json_decode($raw, true) : null;
        $jsonBody = is_array($decoded) ? $decoded : [];
    }
    return (string)($jsonBody['_csrf'] ?? '');
}

function csrf_verify(?array $jsonBody = null): bool {
    $token = csrf_request_token($jsonBody);
    return $token !== '' && hash_equals(csrf_token(), $token);
}

function csrf_failure_response(): never {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($path, '/api/') || str_contains($path, '/games/api/') || str_contains($accept, 'application/json') || str_contains($contentType, 'application/json')) {
        json_out(['error' => 'Invalid or missing CSRF token. Refresh and try again.'], 419);
    }
    http_response_code(419);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid or missing CSRF token. Refresh and try again.';
    exit;
}

function csrf_protect_post(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
    if (PHP_SAPI === 'cli') return;
    if (!csrf_verify()) csrf_failure_response();
}

function csrf_input(): string {
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

csrf_protect_post();

function emit_event(PDO $pdo, int $sessionId, string $type, array $payload): void {
    $stmt = $pdo->prepare('INSERT INTO events (session_id, type, payload) VALUES (?,?,?)');
    $stmt->execute([$sessionId, $type, json_encode($payload, JSON_UNESCAPED_SLASHES)]);
}

function emit_community_event(PDO $pdo, string $scope, ?int $sessionId, ?string $linkKey, string $type, array $payload): void {
    $stmt = $pdo->prepare('INSERT INTO community_events (scope, session_id, link_key, type, payload) VALUES (?,?,?,?,?)');
    $stmt->execute([$scope, $sessionId, $linkKey, $type, json_encode($payload, JSON_UNESCAPED_SLASHES)]);
}

function avatar_relationship_migrate_group_foundation(PDO $pdo): void {
    $relationshipCount = (int)$pdo->query('SELECT COUNT(*) FROM avatar_relationships')->fetchColumn();
    if ($relationshipCount === 0) return;

    $needsMigration = (bool)$pdo->query(
        "SELECT 1
           FROM avatar_relationships ar
           LEFT JOIN avatar_relationship_members arm ON arm.relationship_id = ar.id
          WHERE ar.status = 'active'
            AND (
              ar.version < 1
              OR ar.creator_participant_id IS NULL
              OR ar.conversation_public_id IS NULL
              OR ar.conversation_public_id = ''
              OR ar.join_policy NOT IN ('approval-required', 'open')
              OR arm.membership_effective_at IS NULL
              OR arm.membership_effective_at = ''
              OR arm.active_participant_id IS NULL
              OR arm.membership_status <> 'active'
              OR arm.relationship_role NOT IN ('normal', 'lap')
              OR arm.permission_role NOT IN ('creator', 'manager', 'member')
            )
          LIMIT 1"
    )->fetchColumn();
    if (!$needsMigration) return;

    $duplicateStmt = $pdo->query(
        "SELECT arm.participant_id
           FROM avatar_relationship_members arm
           JOIN avatar_relationships ar ON ar.id = arm.relationship_id
          WHERE ar.status = 'active'
            AND COALESCE(arm.membership_status, 'active') = 'active'
          GROUP BY arm.participant_id
         HAVING COUNT(DISTINCT arm.relationship_id) > 1"
    );
    $duplicateParticipantIds = array_map('intval', $duplicateStmt->fetchAll(PDO::FETCH_COLUMN));
    if ($duplicateParticipantIds) {
        $placeholders = implode(',', array_fill(0, count($duplicateParticipantIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT DISTINCT relationship_id
               FROM avatar_relationship_members
              WHERE participant_id IN ($placeholders)"
        );
        $stmt->execute($duplicateParticipantIds);
        $conflictedRelationshipIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if ($conflictedRelationshipIds) {
            $relationshipPlaceholders = implode(',', array_fill(0, count($conflictedRelationshipIds), '?'));
            $pdo->prepare(
                "UPDATE avatar_relationships
                    SET status = 'conflicted', divergence_status = 'operator-review', updated_at = CURRENT_TIMESTAMP
                  WHERE id IN ($relationshipPlaceholders)"
            )->execute($conflictedRelationshipIds);
            $pdo->prepare(
                "UPDATE avatar_relationship_members
                    SET membership_status = 'conflicted', active_participant_id = NULL, updated_at = CURRENT_TIMESTAMP
                  WHERE relationship_id IN ($relationshipPlaceholders)"
            )->execute($conflictedRelationshipIds);
        }
    }

    $relationships = $pdo->query(
        "SELECT * FROM avatar_relationships WHERE status = 'active' ORDER BY id ASC"
    )->fetchAll();
    foreach ($relationships as $relationship) {
        $relationshipDbId = (int)$relationship['id'];
        $memberStmt = $pdo->prepare(
            'SELECT arm.*, p.user_id
               FROM avatar_relationship_members arm
               LEFT JOIN participants p ON p.id = arm.participant_id
              WHERE arm.relationship_id = ?
              ORDER BY arm.member_order ASC, arm.id ASC'
        );
        $memberStmt->execute([$relationshipDbId]);
        $members = $memberStmt->fetchAll();
        if (!$members) continue;

        $creatorId = (int)($relationship['creator_participant_id'] ?? 0);
        if ($creatorId <= 0) {
            $creatorId = (int)($relationship['legacy_initiator_participant_id'] ?? 0);
        }
        if ($creatorId <= 0) {
            $creatorId = (int)$members[0]['participant_id'];
        }
        $publicId = (string)$relationship['relationship_public_id'];
        $conversationId = trim((string)($relationship['conversation_public_id'] ?? '')) ?: $publicId;
        $version = max(1, (int)($relationship['version'] ?? 1));
        $mode = avatar_relationship_mode((string)($relationship['mode'] ?? 'normal'));
        $legacyInitiatorId = (int)($relationship['legacy_initiator_participant_id'] ?? 0);
        $legacyTargetId = (int)($relationship['legacy_target_participant_id'] ?? 0);

        $pdo->prepare(
            "UPDATE avatar_relationships
                SET version = ?, status = 'active', creator_participant_id = ?,
                    join_policy = CASE WHEN join_policy IN ('approval-required', 'open') THEN join_policy ELSE 'approval-required' END,
                    conversation_public_id = ?, updated_at = updated_at
              WHERE id = ?"
        )->execute([$version, $creatorId, $conversationId, $relationshipDbId]);

        foreach ($members as $member) {
            $participantId = (int)$member['participant_id'];
            $relationshipRole = (string)($member['relationship_role'] ?? 'normal');
            if (!in_array($relationshipRole, ['normal', 'lap'], true)) {
                $relationshipRole = 'normal';
            }
            if ($mode === 'lap' && $participantId === $legacyInitiatorId) {
                $relationshipRole = 'lap';
            }
            $permissionRole = (string)($member['permission_role'] ?? 'member');
            if (!in_array($permissionRole, ['creator', 'manager', 'member'], true)) {
                $permissionRole = 'member';
            }
            if ($participantId === $creatorId) {
                $permissionRole = 'creator';
            } elseif ($permissionRole === 'creator') {
                $permissionRole = 'member';
            }
            $effectiveAt = trim((string)($member['membership_effective_at'] ?? ''))
                ?: (string)($member['created_at'] ?? $relationship['created_at'] ?? gmdate('Y-m-d H:i:s'));
            $lapHostId = $relationshipRole === 'lap' && $legacyTargetId > 0 ? $legacyTargetId : null;
            $lapSide = $relationshipRole === 'lap' ? 'bottom-right' : null;

            $pdo->prepare(
                "UPDATE avatar_relationship_members
                    SET relationship_role = ?, permission_role = ?, membership_status = 'active',
                        active_participant_id = ?, membership_effective_at = ?, lap_host_participant_id = ?, lap_side = ?,
                        updated_at = updated_at
                  WHERE id = ?"
            )->execute([
                $relationshipRole,
                $permissionRole,
                $participantId,
                $effectiveAt,
                $lapHostId,
                $lapSide,
                (int)$member['id'],
            ]);

            $historyStmt = $pdo->prepare(
                "SELECT 1 FROM avatar_relationship_membership_history
                  WHERE relationship_id = ? AND participant_id = ?
                    AND action IN ('founding-created', 'founding-migrated')
                  LIMIT 1"
            );
            $historyStmt->execute([$relationshipDbId, $participantId]);
            if (!$historyStmt->fetchColumn()) {
                $pdo->prepare(
                    'INSERT INTO avatar_relationship_membership_history
                        (relationship_id, participant_id, user_id, actor_participant_id, action, outcome,
                         relationship_role, permission_role, member_order, membership_effective_at,
                         visible_after_message_id, lap_host_participant_id, lap_side,
                         relationship_version, reason)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $relationshipDbId,
                    $participantId,
                    $member['user_id'] !== null ? (int)$member['user_id'] : null,
                    $creatorId,
                    'founding-migrated',
                    'applied',
                    $relationshipRole,
                    $permissionRole,
                    (int)$member['member_order'],
                    $effectiveAt,
                    max(0, (int)($member['visible_after_message_id'] ?? 0)),
                    $lapHostId,
                    $lapSide,
                    $version,
                    'legacy-pair-compatibility',
                ]);
            }
        }
    }
}

function avatar_relationship_migrate_lap_seats(PDO $pdo): void {
    $pdo->exec(
        "UPDATE avatar_relationship_members
            SET lap_side = NULL
          WHERE relationship_id IN (
                SELECT id FROM avatar_relationships WHERE status = 'conflicted'
          )"
    );
    $relationships = $pdo->query(
        "SELECT id FROM avatar_relationships WHERE status = 'active' ORDER BY id ASC"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach (array_map('intval', $relationships) as $relationshipDbId) {
        $stmt = $pdo->prepare(
            "SELECT id, participant_id, relationship_role, membership_status,
                    lap_host_participant_id, lap_side
               FROM avatar_relationship_members
              WHERE relationship_id = ?
              ORDER BY id ASC"
        );
        $stmt->execute([$relationshipDbId]);
        $members = $stmt->fetchAll();
        $activeNormals = [];
        foreach ($members as $member) {
            if ((string)$member['membership_status'] === 'active'
                && (string)$member['relationship_role'] === 'normal') {
                $activeNormals[(int)$member['participant_id']] = true;
            }
        }

        $conflicted = false;
        $occupied = [];
        $updates = [];
        foreach ($members as $member) {
            $memberId = (int)$member['id'];
            if ((string)$member['relationship_role'] !== 'lap') {
                $updates[$memberId] = [null, null];
                continue;
            }
            $hostId = (int)($member['lap_host_participant_id'] ?? 0);
            $rawSide = $member['lap_side'] ?? null;
            $side = avatar_relationship_normalize_lap_side($rawSide);
            if ((string)$member['membership_status'] !== 'active') {
                $updates[$memberId] = [$hostId > 0 ? $hostId : null, $side];
                continue;
            }
            if ($hostId <= 0 || empty($activeNormals[$hostId])) {
                $conflicted = true;
                break;
            }
            if ($side === null && $rawSide !== null && trim((string)$rawSide) !== '') {
                $conflicted = true;
                break;
            }
            if ($side === null) {
                $side = 'bottom-right';
            }
            $seatKey = $hostId . ':' . $side;
            if (isset($occupied[$seatKey])) {
                $conflicted = true;
                break;
            }
            $occupied[$seatKey] = $memberId;
            $updates[$memberId] = [$hostId, $side];
        }

        if ($conflicted) {
            $pdo->prepare(
                "UPDATE avatar_relationships
                    SET status = 'conflicted', divergence_status = 'operator-review',
                        updated_at = CURRENT_TIMESTAMP
                  WHERE id = ?"
            )->execute([$relationshipDbId]);
            $pdo->prepare(
                "UPDATE avatar_relationship_members
                    SET membership_status = 'conflicted', active_participant_id = NULL,
                        lap_side = NULL, updated_at = CURRENT_TIMESTAMP
                  WHERE relationship_id = ?"
            )->execute([$relationshipDbId]);
            continue;
        }

        $update = $pdo->prepare(
            'UPDATE avatar_relationship_members
                SET lap_host_participant_id = ?, lap_side = ?, updated_at = updated_at
              WHERE id = ?'
        );
        foreach ($updates as $memberId => [$hostId, $side]) {
            $update->execute([$hostId, $side, $memberId]);
        }
    }

    $pdo->exec(
        "UPDATE avatar_relationship_requests
            SET requested_lap_side = NULL
          WHERE requested_relationship_role <> 'lap'"
    );
    $pdo->exec(
        "UPDATE avatar_relationship_requests
            SET requested_lap_side = 'bottom-right'
          WHERE requested_relationship_role = 'lap'
            AND requested_lap_side IS NULL
            AND (request_type = 'join-request' OR status = 'accepted')"
    );
}

function avatar_relationship_mode(string $mode): string {
    return in_array($mode, ['normal', 'lap'], true) ? $mode : 'normal';
}

function avatar_relationship_lap_sides(): array {
    return ['bottom-left', 'bottom-right'];
}

function avatar_relationship_normalize_lap_side(mixed $side): ?string {
    $side = is_string($side) ? trim($side) : '';
    return in_array($side, avatar_relationship_lap_sides(), true) ? $side : null;
}

function avatar_relationship_public_options(mixed $options = []): array {
    if (!is_array($options)) $options = [];
    $rowSpacing = $options['rowSpacing'] ?? 0;
    if (!is_int($rowSpacing) && !(is_string($rowSpacing) && preg_match('/^-?\d+$/', $rowSpacing))) {
        $rowSpacing = 0;
    }
    return [
        'schemaVersion' => 2,
        'rowSpacing' => max(0, min(64, (int)$rowSpacing)),
        'formation' => in_array(($options['formation'] ?? ''), avatar_relationship_formation_ids(), true)
            ? (string)$options['formation']
            : 'horizontal-row',
        'transition' => in_array(($options['transition'] ?? ''), avatar_relationship_transition_ids(), true)
            ? (string)$options['transition']
            : 'snap',
    ];
}

function avatar_relationship_formation_ids(): array {
    return ['horizontal-row', 'bottom-center-trio', 'top-center-trio', 'grid'];
}

function avatar_relationship_trio_formation_ids(): array {
    return ['bottom-center-trio', 'top-center-trio'];
}

function avatar_relationship_normalize_permanently_invalid_formation_locked(
    PDO $pdo,
    array &$relationship,
    array $members
): ?array {
    $storedOptions = [];
    if (!empty($relationship['options_json'])) {
        $storedOptions = json_decode((string)$relationship['options_json'], true);
        if (!is_array($storedOptions) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Relationship options require repair before membership mutation.');
        }
    }
    $publicOptions = avatar_relationship_public_options($storedOptions);
    $normalMemberCount = count(array_filter(
        $members,
        fn(array $member): bool => (string)($member['membership_status'] ?? 'active') === 'active'
            && (string)($member['relationship_role'] ?? 'normal') === 'normal'
    ));
    if (!in_array($publicOptions['formation'], avatar_relationship_trio_formation_ids(), true)
        || $normalMemberCount === 3) {
        return null;
    }

    $previousFormation = $publicOptions['formation'];
    $publicOptions['formation'] = 'horizontal-row';
    $storedOptions = array_merge($storedOptions, $publicOptions);
    $encoded = json_encode($storedOptions, JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        throw new RuntimeException('Relationship options could not be normalized.');
    }
    $pdo->prepare(
        'UPDATE avatar_relationships SET options_json = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
    )->execute([$encoded, (int)$relationship['id']]);
    $relationship['options_json'] = $encoded;

    return [
        'from' => $previousFormation,
        'to' => 'horizontal-row',
        'reason' => 'permanent-normal-membership-change',
        'normal_member_count' => $normalMemberCount,
    ];
}

function avatar_relationship_transition_ids(): array {
    return ['snap', 'glide', 'fade-reposition'];
}

function avatar_relationship_dance_ids(): array {
    return ['synchronized-sway', 'synchronized-bounce'];
}

function avatar_relationship_dance_playback(mixed $playback): array {
    if (!is_array($playback)) $playback = [];
    $state = (string)($playback['state'] ?? 'stopped');
    $danceId = (string)($playback['danceId'] ?? '');
    $generation = trim((string)($playback['generation'] ?? ''));
    $startedAtMs = (int)($playback['startedAtMs'] ?? 0);
    $initiatorParticipantId = (int)($playback['initiatorParticipantId'] ?? 0);
    $validPlaying = $state === 'playing'
        && in_array($danceId, avatar_relationship_dance_ids(), true)
        && preg_match('/^[A-Za-z0-9:_-]{8,160}$/', $generation)
        && $startedAtMs > 0
        && $initiatorParticipantId > 0;

    return [
        'schemaVersion' => 1,
        'danceId' => $validPlaying ? $danceId : null,
        'state' => $validPlaying ? 'playing' : 'stopped',
        'startedAtMs' => $validPlaying ? $startedAtMs : null,
        'generation' => $validPlaying ? $generation : ($generation !== '' ? $generation : null),
        'initiatorParticipantId' => $validPlaying ? $initiatorParticipantId : null,
    ];
}

function avatar_relationship_dance_playback_from_row(array $relationship): array {
    $options = [];
    if (!empty($relationship['options_json'])) {
        $options = json_decode((string)$relationship['options_json'], true);
        if (!is_array($options) || json_last_error() !== JSON_ERROR_NONE) $options = [];
    }
    return avatar_relationship_dance_playback($options['dancePlayback'] ?? null);
}

function avatar_relationship_cancel_dance_playback_options(
    array &$storedOptions,
    string $generation
): ?array {
    $current = avatar_relationship_dance_playback($storedOptions['dancePlayback'] ?? null);
    if ($current['state'] !== 'playing') return null;
    $stopped = avatar_relationship_dance_playback([
        'state' => 'stopped',
        'generation' => $generation,
    ]);
    $storedOptions['dancePlayback'] = $stopped;
    return $stopped;
}

function avatar_relationship_cancel_active_dances(
    PDO $pdo,
    ?int $userId,
    string $reason
): int {
    $parameters = [];
    $sql = "SELECT DISTINCT ar.id, ar.session_id, ar.relationship_public_id
              FROM avatar_relationships ar";
    if ($userId !== null) {
        $sql .= " JOIN avatar_relationship_members arm
                    ON arm.relationship_id = ar.id AND arm.membership_status = 'active'
                  JOIN participants p ON p.id = arm.participant_id";
    }
    $sql .= " WHERE ar.status = 'active'";
    if ($userId !== null) {
        $sql .= ' AND p.user_id = ?';
        $parameters[] = $userId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($parameters);
    $cancelled = 0;
    foreach ($stmt->fetchAll() as $candidate) {
        $relationship = avatar_relationship_locked_row(
            $pdo,
            (int)$candidate['session_id'],
            (string)$candidate['relationship_public_id']
        );
        if (!$relationship) continue;
        $storedOptions = !empty($relationship['options_json'])
            ? (json_decode((string)$relationship['options_json'], true) ?: [])
            : [];
        $nextVersion = max(1, (int)$relationship['version']) + 1;
        $generation = 'safety-geometry-' . (int)$relationship['id'] . '-' . $nextVersion;
        $playback = avatar_relationship_cancel_dance_playback_options($storedOptions, $generation);
        if (!$playback) continue;
        $pdo->prepare(
            'UPDATE avatar_relationships
                SET options_json = ?, version = ?, updated_at = CURRENT_TIMESTAMP
              WHERE id = ?'
        )->execute([
            json_encode($storedOptions, JSON_UNESCAPED_SLASHES),
            $nextVersion,
            (int)$relationship['id'],
        ]);
        $eventRelationship = avatar_relationship_public_event_snapshot($pdo, (int)$relationship['id']);
        avatar_relationship_emit_lifecycle_event(
            $pdo,
            (int)$relationship['session_id'],
            'dance-playback-updated',
            $eventRelationship,
            [
                'operation_id' => $generation,
                'actor_participant_id' => 0,
                'dancePlayback' => $playback,
                'resolution_reason' => $reason,
            ]
        );
        $cancelled++;
    }
    return $cancelled;
}

function avatar_relationship_validate_public_options(mixed $options): array {
    if (!is_array($options)) {
        return avatar_relationship_operation_error(
            'RELATIONSHIP_CONFIGURATION_INVALID',
            'Invalid relationship configuration.',
            'malformed-relationship-options',
            400
        );
    }
    $schemaVersion = $options['schemaVersion'] ?? null;
    $legacy = (int)$schemaVersion === 1;
    $allowed = $legacy
        ? ['schemaVersion', 'rowSpacing']
        : ['schemaVersion', 'rowSpacing', 'formation', 'transition'];
    foreach (array_keys($options) as $key) {
        if (!in_array((string)$key, $allowed, true)) {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_CONFIGURATION_INVALID',
                'Invalid relationship configuration.',
                'unknown-relationship-option',
                400
            );
        }
    }
    $rowSpacing = $options['rowSpacing'] ?? null;
    if (!in_array((int)$schemaVersion, [1, 2], true)
        || (!is_int($rowSpacing) && !(is_string($rowSpacing) && preg_match('/^\d+$/', $rowSpacing)))
        || (int)$rowSpacing < 0
        || (int)$rowSpacing > 64
        || (!$legacy && (
            !array_key_exists('formation', $options)
            || !in_array($options['formation'], avatar_relationship_formation_ids(), true)
            || !array_key_exists('transition', $options)
            || !in_array($options['transition'], avatar_relationship_transition_ids(), true)
        ))) {
        return avatar_relationship_operation_error(
            'RELATIONSHIP_CONFIGURATION_INVALID',
            'Invalid relationship configuration.',
            'unsupported-relationship-options',
            400
        );
    }
    return [
        'ok' => true,
        'legacy' => $legacy,
        'options' => avatar_relationship_public_options($options),
    ];
}

function avatar_relationship_migrate_options_v2(PDO $pdo): void {
    $rows = $pdo->query(
        'SELECT id, status, options_json FROM avatar_relationships ORDER BY id ASC'
    )->fetchAll();
    $update = $pdo->prepare(
        'UPDATE avatar_relationships SET options_json = ?, updated_at = updated_at WHERE id = ?'
    );
    $conflict = $pdo->prepare(
        "UPDATE avatar_relationships
            SET status = 'conflicted', divergence_status = 'operator-review',
                updated_at = CURRENT_TIMESTAMP
          WHERE id = ? AND status = 'active'"
    );

    foreach ($rows as $row) {
        $raw = trim((string)($row['options_json'] ?? ''));
        $options = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($options) || ($raw !== '' && json_last_error() !== JSON_ERROR_NONE)) {
            $conflict->execute([(int)$row['id']]);
            continue;
        }

        $schemaVersion = (int)($options['schemaVersion'] ?? 1);
        $rowSpacing = $options['rowSpacing'] ?? 0;
        $validSpacing = is_int($rowSpacing)
            || (is_string($rowSpacing) && preg_match('/^\d+$/', $rowSpacing));
        $validV2 = $schemaVersion !== 2 || (
            in_array(($options['formation'] ?? ''), avatar_relationship_formation_ids(), true)
            && in_array(($options['transition'] ?? ''), avatar_relationship_transition_ids(), true)
        );
        if (!in_array($schemaVersion, [1, 2], true)
            || !$validSpacing
            || (int)$rowSpacing < 0
            || (int)$rowSpacing > 64
            || !$validV2) {
            $conflict->execute([(int)$row['id']]);
            continue;
        }

        $normalized = array_merge($options, avatar_relationship_public_options($options));
        $encoded = json_encode($normalized, JSON_UNESCAPED_SLASHES);
        if ($encoded !== $raw) {
            $update->execute([$encoded, (int)$row['id']]);
        }
    }
}

function avatar_relationship_capability(string $mode): array {
    $mode = avatar_relationship_mode($mode);
    if ($mode === 'lap') {
        return [
            'mode' => 'lap',
            'capability' => 'lap',
            'geometry_strategy' => 'anchorPair',
            'orientation' => 'bottom-right',
            'rendering' => 'lap-pair',
        ];
    }
    return [
        'mode' => 'normal',
        'capability' => 'normal',
        'geometry_strategy' => 'sideBySide',
        'orientation' => 'right',
        'rendering' => 'stage-link-icon',
    ];
}

function avatar_relationship_public_id_for(int $a, int $b): string {
    return 'legacy-edge:' . link_key_for($a, $b);
}

function avatar_relationship_metadata(int $initiatorId, int $targetId, string $mode, ?string $relationshipId = null): array {
    $capability = avatar_relationship_capability($mode);
    $relationshipId = $relationshipId ?: avatar_relationship_public_id_for($initiatorId, $targetId);
    return [
        'schemaVersion' => 1,
        'relationshipId' => $relationshipId,
        'groupId' => $relationshipId,
        'mode' => $capability['mode'],
        'capability' => $capability['capability'],
        'geometryStrategy' => $capability['geometry_strategy'],
        'metadataSource' => 'legacy',
        'members' => [
            ['participantId' => $initiatorId, 'role' => 'initiator', 'order' => 0],
            ['participantId' => $targetId, 'role' => 'target', 'order' => 1],
        ],
        'orientation' => $capability['orientation'],
        'order' => [$initiatorId, $targetId],
        'anchors' => ['relationship' => null, 'members' => [], 'mode' => []],
        'options' => avatar_relationship_public_options(),
        'movement' => 'group',
        'drag' => 'breakable',
        'rendering' => $capability['rendering'],
        'persistence' => ['supported' => true, 'legacyDirectedEdge' => true, 'futureMetadata' => true],
        'reconciliation' => ['supported' => true, 'eventPayload' => 'link'],
        'behavior' => ['static' => true, 'animated' => false],
    ];
}

function avatar_relationship_clear_for_participants(PDO $pdo, int $sessionId, array $participantIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $participantIds), fn(int $id): bool => $id > 0)));
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT DISTINCT ar.id, ar.version
           FROM avatar_relationships ar
           LEFT JOIN avatar_relationship_members arm ON arm.relationship_id = ar.id
          WHERE ar.session_id = ?
            AND ar.status IN ('active', 'conflicted')
            AND (
              ar.legacy_initiator_participant_id IN ($placeholders)
              OR ar.legacy_target_participant_id IN ($placeholders)
              OR arm.participant_id IN ($placeholders)
            )"
    );
    $stmt->execute(array_merge([$sessionId], $ids, $ids, $ids));
    $relationships = $stmt->fetchAll();
    if (!$relationships) return [];
    $dissolved = [];
    foreach ($relationships as $relationship) {
        $relationshipDbId = (int)$relationship['id'];
        $nextVersion = max(1, (int)$relationship['version']) + 1;
        $memberStmt = $pdo->prepare(
            'SELECT arm.*, p.user_id
               FROM avatar_relationship_members arm
               LEFT JOIN participants p ON p.id = arm.participant_id
              WHERE arm.relationship_id = ?'
        );
        $memberStmt->execute([$relationshipDbId]);
        $members = $memberStmt->fetchAll();
        $endedAt = gmdate('Y-m-d H:i:s');
        $historyInsert = $pdo->prepare(
            'INSERT INTO avatar_relationship_membership_history
                (relationship_id, participant_id, user_id, actor_participant_id, action, outcome,
                 relationship_role, permission_role, member_order, membership_effective_at,
                 membership_ended_at, visible_after_message_id, lap_host_participant_id, lap_side,
                 relationship_version, reason)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ($members as $member) {
            $historyInsert->execute([
                $relationshipDbId,
                (int)$member['participant_id'],
                $member['user_id'] !== null ? (int)$member['user_id'] : null,
                null,
                'group-dissolved',
                'applied',
                (string)($member['relationship_role'] ?? 'normal'),
                (string)($member['permission_role'] ?? 'member'),
                (int)$member['member_order'],
                (string)($member['membership_effective_at'] ?: $member['created_at']),
                $endedAt,
                max(0, (int)($member['visible_after_message_id'] ?? 0)),
                $member['lap_host_participant_id'] !== null ? (int)$member['lap_host_participant_id'] : null,
                avatar_relationship_normalize_lap_side($member['lap_side'] ?? null),
                $nextVersion,
                'legacy-compatibility-clear',
            ]);
        }
        $pdo->prepare('DELETE FROM avatar_relationship_members WHERE relationship_id = ?')
            ->execute([$relationshipDbId]);
        $pdo->prepare(
            "UPDATE avatar_relationships
                SET version = ?, status = 'dissolved', legacy_link_key = NULL,
                    divergence_status = 'synced', dissolved_at = ?, updated_at = CURRENT_TIMESTAMP
              WHERE id = ?"
        )->execute([$nextVersion, $endedAt, $relationshipDbId]);
        $identityStmt = $pdo->prepare('SELECT relationship_public_id, conversation_public_id FROM avatar_relationships WHERE id = ?');
        $identityStmt->execute([$relationshipDbId]);
        $identity = $identityStmt->fetch() ?: [];
        $dissolved[] = [
            'id' => (string)($identity['relationship_public_id'] ?? ''),
            'relationship_id' => (string)($identity['relationship_public_id'] ?? ''),
            'conversationId' => (string)($identity['conversation_public_id'] ?? $identity['relationship_public_id'] ?? ''),
            'version' => $nextVersion,
            'status' => 'dissolved',
            'members' => [],
        ];
    }
    return $dissolved;
}

function avatar_relationship_emit_dissolved_events(PDO $pdo, int $sessionId, int $participantId, array $relationships): void {
    foreach ($relationships as $relationship) {
        emit_event($pdo, $sessionId, 'link', [
            'participant_id' => $participantId,
            'linked_to' => null,
            'link_mode' => 'normal',
            'relationship_removed' => true,
            'relationship_id' => $relationship['id'] ?? null,
            'relationship_version' => $relationship['version'] ?? null,
            'relationship_status' => 'dissolved',
            'relationship' => $relationship,
        ]);
    }
}

function avatar_relationship_sync_legacy(
    PDO $pdo,
    int $sessionId,
    int $initiatorId,
    int $targetId,
    string $mode,
    bool $clearExisting = true,
    ?string $lapSide = 'bottom-right'
): ?array {
    if ($initiatorId <= 0 || $targetId <= 0 || $initiatorId === $targetId) return null;
    $mode = avatar_relationship_mode($mode);
    $lapSide = $mode === 'lap' ? avatar_relationship_normalize_lap_side($lapSide) : null;
    if ($mode === 'lap' && $lapSide === null) return null;
    $stmt = $pdo->prepare('SELECT id FROM participants WHERE session_id = ? AND id IN (?,?)');
    $stmt->execute([$sessionId, $initiatorId, $targetId]);
    $found = array_map(fn(array $row): int => (int)$row['id'], $stmt->fetchAll());
    if (count(array_unique($found)) !== 2) return null;

    $capability = avatar_relationship_capability($mode);
    $legacyKey = link_key_for($initiatorId, $targetId);
    $existingStmt = $pdo->prepare(
        "SELECT * FROM avatar_relationships
          WHERE session_id = ? AND legacy_link_key = ? AND status = 'active'
          LIMIT 1"
    );
    $existingStmt->execute([$sessionId, $legacyKey]);
    $existing = $existingStmt->fetch() ?: null;

    if ($clearExisting && !$existing) {
        avatar_relationship_clear_for_participants($pdo, $sessionId, [$initiatorId, $targetId]);
    }

    $relationshipId = $existing
        ? (string)$existing['relationship_public_id']
        : avatar_relationship_public_id_for($initiatorId, $targetId);
    if (!$existing) {
        $identityStmt = $pdo->prepare(
            'SELECT 1 FROM avatar_relationships WHERE session_id = ? AND relationship_public_id = ? LIMIT 1'
        );
        $identityStmt->execute([$sessionId, $relationshipId]);
        if ($identityStmt->fetchColumn()) {
            $relationshipId = 'relationship:' . uuid_v4();
        }
    }
    $metadata = avatar_relationship_metadata($initiatorId, $targetId, $mode, $relationshipId);
    $metadataJson = json_encode($metadata, JSON_UNESCAPED_SLASHES);
    $anchorsJson = json_encode($metadata['anchors'], JSON_UNESCAPED_SLASHES);
    $optionsJson = json_encode($metadata['options'], JSON_UNESCAPED_SLASHES);
    $syncedAt = gmdate('Y-m-d H:i:s');
    if (!$existing) {
        $pdo->prepare(
            "INSERT INTO avatar_relationships
                (session_id, relationship_public_id, version, status, creator_participant_id,
                 join_policy, conversation_public_id, legacy_link_key, mode, capability,
                 geometry_strategy, metadata_json, anchors_json, options_json,
                 legacy_initiator_participant_id, legacy_target_participant_id,
                 last_synced_from_legacy_at, divergence_status, updated_at)
             VALUES (?,?,1,'active',?,'approval-required',?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)"
        )->execute([
            $sessionId,
            $relationshipId,
            $initiatorId,
            $relationshipId,
            $legacyKey,
            $mode,
            $capability['capability'],
            $capability['geometry_strategy'],
            $metadataJson,
            $anchorsJson,
            $optionsJson,
            $initiatorId,
            $targetId,
            $syncedAt,
            'synced',
        ]);
    } else {
        $modeChanged = avatar_relationship_mode((string)$existing['mode']) !== $mode;
        $nextVersion = max(1, (int)$existing['version']) + ($modeChanged ? 1 : 0);
        $pdo->prepare(
            "UPDATE avatar_relationships
                SET version = ?, status = 'active', creator_participant_id = COALESCE(creator_participant_id, ?),
                    conversation_public_id = COALESCE(conversation_public_id, relationship_public_id),
                    legacy_link_key = ?, mode = ?, capability = ?, geometry_strategy = ?,
                    metadata_json = ?, anchors_json = ?, options_json = ?,
                    legacy_initiator_participant_id = ?, legacy_target_participant_id = ?,
                    last_synced_from_legacy_at = ?, divergence_status = 'synced',
                    dissolved_at = NULL, updated_at = CURRENT_TIMESTAMP
              WHERE id = ?"
        )->execute([
            $nextVersion,
            $initiatorId,
            $legacyKey,
            $mode,
            $capability['capability'],
            $capability['geometry_strategy'],
            $metadataJson,
            $anchorsJson,
            $optionsJson,
            $initiatorId,
            $targetId,
            $syncedAt,
            (int)$existing['id'],
        ]);
    }
    $stmt = $pdo->prepare('SELECT id FROM avatar_relationships WHERE session_id = ? AND relationship_public_id = ? LIMIT 1');
    $stmt->execute([$sessionId, $relationshipId]);
    $dbRelationshipId = (int)($stmt->fetchColumn() ?: 0);
    if (!$dbRelationshipId) return null;
    if ($existing) {
        $memberCountStmt = $pdo->prepare('SELECT COUNT(*) FROM avatar_relationship_members WHERE relationship_id = ?');
        $memberCountStmt->execute([$dbRelationshipId]);
        if ((int)$memberCountStmt->fetchColumn() <= 2) {
            $pdo->prepare(
                'DELETE FROM avatar_relationship_members
                  WHERE relationship_id = ? AND participant_id NOT IN (?,?)'
            )->execute([$dbRelationshipId, $initiatorId, $targetId]);
        }
    }
    $relationshipStateStmt = $pdo->prepare('SELECT version, creator_participant_id FROM avatar_relationships WHERE id = ?');
    $relationshipStateStmt->execute([$dbRelationshipId]);
    $relationshipState = $relationshipStateStmt->fetch() ?: [];
    $relationshipVersion = max(1, (int)($relationshipState['version'] ?? 1));
    $relationshipCreatorId = (int)($relationshipState['creator_participant_id'] ?? $initiatorId);
    $memberSelect = $pdo->prepare(
        'SELECT * FROM avatar_relationship_members WHERE relationship_id = ? AND participant_id = ? LIMIT 1'
    );
    $memberInsert = $pdo->prepare(
        "INSERT INTO avatar_relationship_members
            (relationship_id, participant_id, member_role, relationship_role, permission_role,
             membership_status, active_participant_id, member_order, membership_effective_at,
             visible_after_message_id, lap_host_participant_id, lap_side, anchor_json, options_json)
         VALUES (?,?,?,?,?,'active',?,?,?,?,?,?,?,?)"
    );
    $memberUpdate = $pdo->prepare(
        "UPDATE avatar_relationship_members
            SET member_role = ?, relationship_role = ?, permission_role = ?, membership_status = 'active',
                active_participant_id = ?, member_order = ?,
                lap_host_participant_id = ?, lap_side = ?, updated_at = CURRENT_TIMESTAMP
          WHERE id = ?"
    );
    foreach ($metadata['members'] as $member) {
        $participantId = (int)$member['participantId'];
        $relationshipRole = $mode === 'lap' && $participantId === $initiatorId ? 'lap' : 'normal';
        $lapHostId = $relationshipRole === 'lap' ? $targetId : null;
        $memberLapSide = $relationshipRole === 'lap' ? $lapSide : null;
        $memberSelect->execute([$dbRelationshipId, $participantId]);
        $existingMember = $memberSelect->fetch() ?: null;
        $permissionRole = $participantId === $relationshipCreatorId
            ? 'creator'
            : (
                $existingMember && in_array((string)$existingMember['permission_role'], ['manager', 'member'], true)
                    ? (string)$existingMember['permission_role']
                    : 'member'
            );
        if ($existingMember) {
            $memberUpdate->execute([
                (string)$member['role'],
                $relationshipRole,
                $permissionRole,
                $participantId,
                (int)$member['order'],
                $lapHostId,
                $memberLapSide,
                (int)$existingMember['id'],
            ]);
            continue;
        }
        $effectiveAt = gmdate('Y-m-d H:i:s');
        $memberInsert->execute([
            $dbRelationshipId,
            $participantId,
            (string)$member['role'],
            $relationshipRole,
            $permissionRole,
            $participantId,
            (int)$member['order'],
            $effectiveAt,
            0,
            $lapHostId,
            $memberLapSide,
            null,
            json_encode([], JSON_UNESCAPED_SLASHES),
        ]);
        $userStmt = $pdo->prepare('SELECT user_id FROM participants WHERE id = ? LIMIT 1');
        $userStmt->execute([$participantId]);
        $pdo->prepare(
            'INSERT INTO avatar_relationship_membership_history
                (relationship_id, participant_id, user_id, actor_participant_id, action, outcome,
                 relationship_role, permission_role, member_order, membership_effective_at,
                 visible_after_message_id, lap_host_participant_id, lap_side, relationship_version, reason)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $dbRelationshipId,
            $participantId,
            ($userId = $userStmt->fetchColumn()) !== false ? (int)$userId : null,
            $relationshipCreatorId,
            'founding-created',
            'applied',
            $relationshipRole,
            $permissionRole,
            (int)$member['order'],
            $effectiveAt,
            0,
            $lapHostId,
            $memberLapSide,
            max(1, $relationshipVersion),
            'legacy-pair-compatibility',
        ]);
    }
    return avatar_relationship_payload($pdo, $dbRelationshipId);
}

function avatar_relationship_creation_eligibility(
    PDO $pdo,
    int $sessionId,
    int $initiatorId,
    int $targetId,
    bool $lockParticipants = false
): array {
    $decision = static function(string $reason, bool $allowed = false) use ($initiatorId, $targetId): array {
        return [
            'allowed' => $allowed,
            'reason' => $reason,
            'initiator_participant_id' => $initiatorId > 0 ? $initiatorId : null,
            'target_participant_id' => $targetId > 0 ? $targetId : null,
            'allowed_modes' => $allowed ? ['normal', 'lap'] : [],
        ];
    };

    if ($initiatorId <= 0) return $decision('missing-initiator');
    if ($targetId <= 0) return $decision('missing-target');
    if ($initiatorId === $targetId) return $decision('self');

    $orderedIds = [$initiatorId, $targetId];
    sort($orderedIds, SORT_NUMERIC);
    $participantSql = 'SELECT id, user_id, linked_to_participant_id, link_mode, last_seen_at
                         FROM participants
                        WHERE session_id = ? AND id IN (?,?)
                        ORDER BY id ASC';
    if ($lockParticipants && db_uses_mysql_syntax($pdo)) {
        $participantSql .= ' FOR UPDATE';
    }
    $stmt = $pdo->prepare($participantSql);
    $stmt->execute([$sessionId, $orderedIds[0], $orderedIds[1]]);
    $participants = [];
    foreach ($stmt->fetchAll() as $row) {
        $participants[(int)$row['id']] = $row;
    }

    $initiator = $participants[$initiatorId] ?? null;
    $target = $participants[$targetId] ?? null;
    if (!$initiator) return $decision('missing-initiator');
    if (!$target) return $decision('missing-target');
    if (empty($initiator['last_seen_at'])) return $decision('initiator-unavailable');
    if (empty($target['last_seen_at'])) return $decision('target-unavailable');

    $stmt = $pdo->prepare(
        'SELECT 1 FROM user_blocks
          WHERE (blocker_user_id = ? AND blocked_user_id = ?)
             OR (blocker_user_id = ? AND blocked_user_id = ?)
          LIMIT 1'
    );
    $stmt->execute([
        (int)$initiator['user_id'],
        (int)$target['user_id'],
        (int)$target['user_id'],
        (int)$initiator['user_id'],
    ]);
    if ($stmt->fetchColumn()) return $decision('blocked');

    $legacyMembership = static function(PDO $pdo, int $sessionId, int $participantId): bool {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM participants
              WHERE session_id = ?
                AND ((id = ? AND linked_to_participant_id IS NOT NULL)
                  OR linked_to_participant_id = ?)
              LIMIT 1'
        );
        $stmt->execute([$sessionId, $participantId, $participantId]);
        return (bool)$stmt->fetchColumn();
    };

    $persistedMembership = static function(PDO $pdo, int $sessionId, int $participantId): bool {
        $stmt = $pdo->prepare(
            'SELECT 1
               FROM avatar_relationships ar
               JOIN avatar_relationship_members arm ON arm.relationship_id = ar.id
              WHERE ar.session_id = ?
                AND ar.status IN (\'active\', \'conflicted\')
                AND arm.membership_status IN (\'active\', \'conflicted\')
                AND arm.participant_id = ?
              LIMIT 1'
        );
        $stmt->execute([$sessionId, $participantId]);
        return (bool)$stmt->fetchColumn();
    };

    $initiatorOccupied = $legacyMembership($pdo, $sessionId, $initiatorId)
        || $persistedMembership($pdo, $sessionId, $initiatorId);
    $targetOccupied = $legacyMembership($pdo, $sessionId, $targetId)
        || $persistedMembership($pdo, $sessionId, $targetId);

    if ($initiatorOccupied && $targetOccupied) return $decision('already-related');
    if ($initiatorOccupied) return $decision('initiator-relationship');
    if ($targetOccupied) return $decision('target-relationship');

    return $decision('eligible', true);
}

function avatar_relationship_create_pair_atomic(
    PDO $pdo,
    int $sessionId,
    int $initiatorId,
    int $targetId,
    string $mode,
    array $positions = [],
    ?string $lapSide = null
): array {
    $ownsTransaction = !$pdo->inTransaction();

    try {
        if ($ownsTransaction) {
            if (db_uses_mysql_syntax($pdo)) {
                $pdo->beginTransaction();
            } else {
                $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
            }
        }

        $eligibility = avatar_relationship_creation_eligibility(
            $pdo,
            $sessionId,
            $initiatorId,
            $targetId,
            true
        );

        if (empty($eligibility['allowed'])) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'code' => 'RELATIONSHIP_CONFLICT'] + $eligibility;
        }

        $mode = avatar_relationship_mode($mode);
        $lapSide = $mode === 'lap' ? avatar_relationship_normalize_lap_side($lapSide) : null;
        if ($mode === 'lap' && $lapSide === null) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            return avatar_relationship_operation_error(
                'RELATIONSHIP_CONFLICT',
                'Choose an available lap side.',
                'lap-side-required',
                400
            );
        }
        $pdo->prepare(
            'UPDATE participants
                SET linked_to_participant_id = ?, link_mode = ?
              WHERE id = ? AND session_id = ?'
        )->execute([$targetId, $mode, $initiatorId, $sessionId]);

        $relationship = avatar_relationship_sync_legacy(
            $pdo,
            $sessionId,
            $initiatorId,
            $targetId,
            $mode,
            false,
            $lapSide
        );

        if (!$relationship) {
            throw new RuntimeException('Relationship persistence failed.');
        }

        $hasPositions = isset(
            $positions['initiator_x'],
            $positions['initiator_y'],
            $positions['target_x'],
            $positions['target_y']
        );

        if ($hasPositions) {
            $update = $pdo->prepare(
                'UPDATE participants SET position_x = ?, position_y = ?
                  WHERE id = ? AND session_id = ?'
            );
            $update->execute([
                max(0, min(1, (float)$positions['initiator_x'])),
                max(0, min(1, (float)$positions['initiator_y'])),
                $initiatorId,
                $sessionId,
            ]);
            $update->execute([
                max(0, min(1, (float)$positions['target_x'])),
                max(0, min(1, (float)$positions['target_y'])),
                $targetId,
                $sessionId,
            ]);
        }

        $payload = [
            'participant_id' => $initiatorId,
            'linked_to' => $targetId,
            'link_mode' => $mode,
            'relationship_id' => $relationship['id'] ?? null,
            'relationship_version' => $relationship['version'] ?? 1,
            'relationship_status' => $relationship['status'] ?? 'active',
            'relationship' => $relationship,
        ];
        if ($hasPositions) {
            $payload['initiator_position'] = [
                'x' => max(0, min(1, (float)$positions['initiator_x'])),
                'y' => max(0, min(1, (float)$positions['initiator_y'])),
            ];
            $payload['target_position'] = [
                'x' => max(0, min(1, (float)$positions['target_x'])),
                'y' => max(0, min(1, (float)$positions['target_y'])),
            ];
        }

        emit_event($pdo, $sessionId, 'link', $payload);

        if ($ownsTransaction && $pdo->inTransaction()) $pdo->commit();

        return [
            'ok' => true,
            'link_key' => link_key_for($initiatorId, $targetId),
            'relationship_id' => $relationship['id'] ?? null,
            'relationship' => $relationship,
        ];
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function avatar_relationship_payload(PDO $pdo, int $relationshipDbId, ?int $viewerParticipantId = null): ?array {
    $stmt = $pdo->prepare('SELECT * FROM avatar_relationships WHERE id = ? LIMIT 1');
    $stmt->execute([$relationshipDbId]);
    $relationship = $stmt->fetch();
    if (!$relationship) return null;
    $membersStmt = $pdo->prepare(
        "SELECT arm.participant_id, arm.member_role, arm.relationship_role, arm.permission_role,
                arm.membership_status, arm.member_order, arm.membership_effective_at,
                arm.visible_after_message_id, arm.lap_host_participant_id, arm.lap_side,
                arm.anchor_json, arm.options_json, p.user_id
           FROM avatar_relationship_members arm
           LEFT JOIN participants p ON p.id = arm.participant_id
          WHERE arm.relationship_id = ? AND arm.membership_status = 'active'
          ORDER BY arm.member_order ASC, arm.id ASC"
    );
    $membersStmt->execute([$relationshipDbId]);
    $memberRows = $membersStmt->fetchAll();
    $viewerIsMember = $viewerParticipantId === null;
    if (!$viewerIsMember) {
        foreach ($memberRows as $memberRow) {
            if ((int)$memberRow['participant_id'] === $viewerParticipantId) {
                $viewerIsMember = true;
                break;
            }
        }
    }
    $members = array_map(function(array $row) use ($viewerParticipantId, $viewerIsMember): array {
        $isViewer = $viewerParticipantId === null || (int)$row['participant_id'] === $viewerParticipantId;
        return [
        'participantId' => (int)$row['participant_id'],
        'role' => (string)$row['member_role'],
        'relationshipRole' => (string)$row['relationship_role'],
        'permissionRole' => $viewerIsMember ? (string)$row['permission_role'] : null,
        'status' => (string)$row['membership_status'],
        'order' => (int)$row['member_order'],
        'userId' => $row['user_id'] !== null ? (int)$row['user_id'] : null,
        'effectiveAt' => $isViewer ? ($row['membership_effective_at'] ?? null) : null,
        'visibleAfterMessageId' => $isViewer ? max(0, (int)($row['visible_after_message_id'] ?? 0)) : null,
        'lapHostParticipantId' => $row['lap_host_participant_id'] !== null ? (int)$row['lap_host_participant_id'] : null,
        'lapSide' => avatar_relationship_normalize_lap_side($row['lap_side'] ?? null),
        'anchor' => !empty($row['anchor_json']) ? json_decode((string)$row['anchor_json'], true) : null,
        'options' => !empty($row['options_json']) ? (json_decode((string)$row['options_json'], true) ?: []) : [],
        ];
    }, $memberRows);
    $viewerMembership = null;
    if ($viewerParticipantId !== null) {
        foreach ($memberRows as $memberRow) {
            if ((int)$memberRow['participant_id'] !== $viewerParticipantId) continue;
            $viewerMembership = [
                'participantId' => $viewerParticipantId,
                'status' => (string)$memberRow['membership_status'],
                'permissionRole' => (string)$memberRow['permission_role'],
                'effectiveAt' => $memberRow['membership_effective_at'] ?? null,
                'visibleAfterMessageId' => max(0, (int)($memberRow['visible_after_message_id'] ?? 0)),
            ];
            break;
        }
    }
    $metadata = !empty($relationship['metadata_json']) ? (json_decode((string)$relationship['metadata_json'], true) ?: []) : [];
    $storedRelationshipOptions = !empty($relationship['options_json'])
        ? (json_decode((string)$relationship['options_json'], true) ?: [])
        : ($metadata['options'] ?? []);
    $conversationId = (string)($relationship['conversation_public_id'] ?: $relationship['relationship_public_id']);
    $chatAccessActive = (string)($relationship['status'] ?? '') === 'active'
        && (string)($relationship['divergence_status'] ?? 'synced') === 'synced'
        && ($viewerParticipantId === null || $viewerMembership !== null);
    return [
        'id' => (string)$relationship['relationship_public_id'],
        'relationship_id' => (string)$relationship['relationship_public_id'],
        'source' => 'persisted',
        'version' => max(1, (int)($relationship['version'] ?? 1)),
        'status' => (string)($relationship['status'] ?? 'active'),
        'creatorParticipantId' => $relationship['creator_participant_id'] !== null ? (int)$relationship['creator_participant_id'] : null,
        'joinPolicy' => (string)($relationship['join_policy'] ?? 'approval-required'),
        'conversationId' => $conversationId,
        'viewerMembership' => $viewerMembership,
        'chatAccess' => [
            'active' => $chatAccessActive,
            'conversationId' => $chatAccessActive ? $conversationId : null,
            'visibleAfterMessageId' => $viewerMembership !== null
                ? $viewerMembership['visibleAfterMessageId']
                : null,
        ],
        'legacy_link_key' => $relationship['legacy_link_key'] ?? null,
        'mode' => avatar_relationship_mode((string)$relationship['mode']),
        'capability' => (string)($relationship['capability'] ?: avatar_relationship_mode((string)$relationship['mode'])),
        'geometryStrategy' => $relationship['geometry_strategy'] ?: avatar_relationship_capability((string)$relationship['mode'])['geometry_strategy'],
        'members' => $members,
        'metadata' => $metadata,
        'anchors' => !empty($relationship['anchors_json']) ? (json_decode((string)$relationship['anchors_json'], true) ?: []) : ($metadata['anchors'] ?? []),
        'options' => avatar_relationship_public_options(
            $storedRelationshipOptions
        ),
        'dancePlayback' => avatar_relationship_dance_playback(
            $storedRelationshipOptions['dancePlayback'] ?? null
        ),
        'persistence' => ['supported' => true, 'legacyDirectedEdge' => true, 'futureMetadata' => true],
        'reconciliation' => ['supported' => true, 'eventPayload' => 'link'],
        'divergence_status' => $relationship['divergence_status'] ?? 'synced',
        'legacyProjection' => [
            'initiatorParticipantId' => $relationship['legacy_initiator_participant_id'] !== null ? (int)$relationship['legacy_initiator_participant_id'] : null,
            'targetParticipantId' => $relationship['legacy_target_participant_id'] !== null ? (int)$relationship['legacy_target_participant_id'] : null,
            'linkKey' => $relationship['legacy_link_key'] ?? null,
            'mode' => avatar_relationship_mode((string)$relationship['mode']),
        ],
        'created_at' => $relationship['created_at'] ?? null,
        'updated_at' => $relationship['updated_at'] ?? null,
    ];
}

function avatar_relationship_payloads_for_session(PDO $pdo, int $sessionId, ?int $viewerParticipantId = null): array {
    $stmt = $pdo->prepare("SELECT id FROM avatar_relationships WHERE session_id = ? AND status = 'active' ORDER BY id ASC");
    $stmt->execute([$sessionId]);
    return array_values(array_filter(array_map(
        fn(array $row): ?array => avatar_relationship_payload($pdo, (int)$row['id'], $viewerParticipantId),
        $stmt->fetchAll()
    )));
}

function avatar_relationship_active_for_participant(PDO $pdo, int $sessionId, int $participantId, ?int $viewerParticipantId = null): ?array {
    $stmt = $pdo->prepare(
        "SELECT ar.id
           FROM avatar_relationships ar
           JOIN avatar_relationship_members arm ON arm.relationship_id = ar.id
          WHERE ar.session_id = ? AND ar.status = 'active'
            AND arm.participant_id = ? AND arm.membership_status = 'active'
          LIMIT 1"
    );
    $stmt->execute([$sessionId, $participantId]);
    $relationshipDbId = (int)($stmt->fetchColumn() ?: 0);
    return $relationshipDbId > 0
        ? avatar_relationship_payload($pdo, $relationshipDbId, $viewerParticipantId)
        : null;
}

function avatar_relationship_for_viewer_by_public_id(
    PDO $pdo,
    int $sessionId,
    string $relationshipPublicId,
    int $viewerParticipantId
): ?array {
    $relationshipPublicId = trim($relationshipPublicId);
    if ($relationshipPublicId === '') return null;
    $stmt = $pdo->prepare(
        "SELECT id FROM avatar_relationships
          WHERE session_id = ? AND relationship_public_id = ? AND status = 'active'
          LIMIT 1"
    );
    $stmt->execute([$sessionId, $relationshipPublicId]);
    $relationshipDbId = (int)($stmt->fetchColumn() ?: 0);
    return $relationshipDbId > 0
        ? avatar_relationship_payload($pdo, $relationshipDbId, $viewerParticipantId)
        : null;
}

function avatar_relationship_operation_error(string $code, string $message, string $reason, int $httpStatus = 409): array {
    return [
        'ok' => false,
        'code' => $code,
        'error' => $message,
        'reason' => $reason,
        'http_status' => $httpStatus,
    ];
}

function avatar_relationship_transaction(PDO $pdo, callable $operation): array {
    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) {
            if (db_uses_mysql_syntax($pdo)) {
                $pdo->beginTransaction();
            } else {
                $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
            }
        }
        $result = $operation();
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function avatar_relationship_chat_legacy_keys(PDO $pdo, array $relationship): array {
    $keys = [];
    $storedKey = trim((string)($relationship['legacy_link_key'] ?? ''));
    if ($storedKey !== '') $keys[] = $storedKey;

    $legacyParticipantIds = array_values(array_filter([
        (int)($relationship['legacy_initiator_participant_id'] ?? 0),
        (int)($relationship['legacy_target_participant_id'] ?? 0),
    ], fn(int $participantId): bool => $participantId > 0));
    if (count(array_unique($legacyParticipantIds)) === 2) {
        $keys[] = link_key_for($legacyParticipantIds[0], $legacyParticipantIds[1]);
    }

    $historyStmt = $pdo->prepare(
        "SELECT participant_id
           FROM avatar_relationship_membership_history
          WHERE relationship_id = ?
            AND action IN ('founding-created', 'founding-migrated')
            AND participant_id IS NOT NULL
          ORDER BY member_order ASC, id ASC"
    );
    $historyStmt->execute([(int)$relationship['id']]);
    $foundingIds = array_values(array_unique(array_map('intval', $historyStmt->fetchAll(PDO::FETCH_COLUMN))));
    if (count($foundingIds) >= 2) {
        $keys[] = link_key_for($foundingIds[0], $foundingIds[1]);
    }

    return array_values(array_unique(array_filter(array_map('trim', $keys))));
}

function avatar_relationship_chat_migrate_clear_key(
    PDO $pdo,
    int $sessionId,
    string $legacyKey,
    string $conversationId
): void {
    $stmt = $pdo->prepare(
        "SELECT user_id, cleared_at
           FROM private_message_clears
          WHERE scope = 'link' AND session_id = ? AND link_key = ?"
    );
    $stmt->execute([$sessionId, $legacyKey]);
    foreach ($stmt->fetchAll() as $legacyClear) {
        $userId = (int)$legacyClear['user_id'];
        $legacyClearedAt = (string)$legacyClear['cleared_at'];
        $currentStmt = $pdo->prepare(
            "SELECT id, cleared_at
               FROM private_message_clears
              WHERE user_id = ? AND scope = 'link' AND session_id = ? AND link_key = ?
              LIMIT 1"
        );
        $currentStmt->execute([$userId, $sessionId, $conversationId]);
        $current = $currentStmt->fetch() ?: null;
        if ($current) {
            if (strcmp($legacyClearedAt, (string)$current['cleared_at']) > 0) {
                $pdo->prepare('UPDATE private_message_clears SET cleared_at = ? WHERE id = ?')
                    ->execute([$legacyClearedAt, (int)$current['id']]);
            }
            continue;
        }
        try {
            $pdo->prepare(
                "INSERT INTO private_message_clears (user_id, scope, session_id, link_key, cleared_at)
                 VALUES (?, 'link', ?, ?, ?)"
            )->execute([$userId, $sessionId, $conversationId, $legacyClearedAt]);
        } catch (PDOException $error) {
            if ($error->getCode() !== '23000' && !str_contains(strtoupper($error->getMessage()), 'UNIQUE')) {
                throw $error;
            }
            $pdo->prepare(
                "UPDATE private_message_clears
                    SET cleared_at = CASE WHEN cleared_at < ? THEN ? ELSE cleared_at END
                  WHERE user_id = ? AND scope = 'link' AND session_id = ? AND link_key = ?"
            )->execute([$legacyClearedAt, $legacyClearedAt, $userId, $sessionId, $conversationId]);
        }
    }
    $pdo->prepare(
        "DELETE FROM private_message_clears
          WHERE scope = 'link' AND session_id = ? AND link_key = ?"
    )->execute([$sessionId, $legacyKey]);
}

function avatar_relationship_chat_migrate_relationship(PDO $pdo, array $relationship): array {
    $conversationId = trim((string)($relationship['conversation_public_id'] ?? ''));
    if ($conversationId === '') {
        $conversationId = trim((string)($relationship['relationship_public_id'] ?? ''));
    }
    if ($conversationId === '') return ['messages' => 0, 'clear_keys' => 0];

    $messageCount = 0;
    $clearKeyCount = 0;
    foreach (avatar_relationship_chat_legacy_keys($pdo, $relationship) as $legacyKey) {
        if ($legacyKey === $conversationId) continue;
        $stmt = $pdo->prepare(
            "UPDATE community_messages
                SET link_key = ?
              WHERE scope = 'link' AND session_id = ? AND link_key = ?"
        );
        $stmt->execute([$conversationId, (int)$relationship['session_id'], $legacyKey]);
        $messageCount += $stmt->rowCount();
        avatar_relationship_chat_migrate_clear_key(
            $pdo,
            (int)$relationship['session_id'],
            $legacyKey,
            $conversationId
        );
        $clearKeyCount++;
    }
    return ['messages' => $messageCount, 'clear_keys' => $clearKeyCount];
}

function avatar_relationship_migrate_chat_foundation(PDO $pdo): void {
    $relationships = $pdo->query(
        "SELECT *
           FROM avatar_relationships
          WHERE conversation_public_id IS NOT NULL AND conversation_public_id <> ''
          ORDER BY id ASC"
    )->fetchAll();
    foreach ($relationships as $relationship) {
        avatar_relationship_transaction($pdo, function() use ($pdo, $relationship): array {
            $locked = avatar_relationship_locked_row(
                $pdo,
                (int)$relationship['session_id'],
                (string)$relationship['relationship_public_id']
            );
            return $locked
                ? avatar_relationship_chat_migrate_relationship($pdo, $locked)
                : ['messages' => 0, 'clear_keys' => 0];
        });
    }
}

function avatar_relationship_chat_access(
    PDO $pdo,
    int $sessionId,
    int $participantId,
    string $conversationOrRelationshipId = '',
    int $targetParticipantId = 0,
    bool $lock = false
): ?array {
    $requestedId = trim($conversationOrRelationshipId);
    if ($lock) {
        if ($requestedId === '' && $targetParticipantId <= 0) return null;
        if ($requestedId !== '') {
            $sql = "SELECT * FROM avatar_relationships
                     WHERE session_id = ?
                       AND (conversation_public_id = ? OR relationship_public_id = ?)
                     LIMIT 1";
            $parameters = [$sessionId, $requestedId, $requestedId];
        } else {
            $sql = "SELECT * FROM avatar_relationships
                     WHERE session_id = ? AND legacy_link_key = ?
                     LIMIT 1";
            $parameters = [$sessionId, link_key_for($participantId, $targetParticipantId)];
        }
        if (db_uses_mysql_syntax($pdo)) $sql .= ' FOR UPDATE';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($parameters);
        $relationship = $stmt->fetch() ?: null;
        if (!$relationship
            || (string)$relationship['status'] !== 'active'
            || (string)($relationship['divergence_status'] ?? '') !== 'synced') {
            return null;
        }
        $members = avatar_relationship_locked_members($pdo, (int)$relationship['id']);
    } else {
        $stmt = $pdo->prepare(
            "SELECT ar.*, arm.visible_after_message_id, arm.membership_effective_at,
                    arm.permission_role AS viewer_permission_role
               FROM avatar_relationship_members arm
               JOIN avatar_relationships ar ON ar.id = arm.relationship_id
              WHERE ar.session_id = ? AND ar.status = 'active'
                AND ar.divergence_status = 'synced'
                AND arm.participant_id = ? AND arm.membership_status = 'active'
                AND arm.active_participant_id = ?
              LIMIT 1"
        );
        $stmt->execute([$sessionId, $participantId, $participantId]);
        $relationship = $stmt->fetch() ?: null;
        if (!$relationship) return null;
        $membersStmt = $pdo->prepare(
            "SELECT arm.*, p.user_id, p.session_id, p.last_seen_at
               FROM avatar_relationship_members arm
               JOIN participants p ON p.id = arm.participant_id
              WHERE arm.relationship_id = ? AND arm.membership_status = 'active'
              ORDER BY arm.member_order ASC, arm.membership_effective_at ASC, arm.id ASC"
        );
        $membersStmt->execute([(int)$relationship['id']]);
        $members = $membersStmt->fetchAll();
    }
    $conversationId = trim((string)($relationship['conversation_public_id'] ?? ''));
    $relationshipPublicId = trim((string)($relationship['relationship_public_id'] ?? ''));
    if ($conversationId === '' || $relationshipPublicId === '') return null;
    if ($requestedId !== '' && $requestedId !== $conversationId && $requestedId !== $relationshipPublicId) {
        return null;
    }
    $viewerMember = avatar_relationship_member_from_rows($members, $participantId);
    if (!$viewerMember || (int)($viewerMember['active_participant_id'] ?? 0) !== $participantId) return null;
    if ($targetParticipantId > 0 && !avatar_relationship_member_from_rows($members, $targetParticipantId)) return null;

    if ($lock) {
        $viewerParticipant = avatar_relationship_locked_participant($pdo, $sessionId, $participantId);
    } else {
        $participantStmt = $pdo->prepare(
            'SELECT * FROM participants WHERE session_id = ? AND id = ? LIMIT 1'
        );
        $participantStmt->execute([$sessionId, $participantId]);
        $viewerParticipant = $participantStmt->fetch() ?: null;
    }
    if (!$viewerParticipant || avatar_relationship_blocked_for_members($pdo, $viewerParticipant, $members)) return null;

    return [
        'relationship_db_id' => (int)$relationship['id'],
        'relationship_id' => $relationshipPublicId,
        'relationship_version' => max(1, (int)($relationship['version'] ?? 1)),
        'conversation_id' => $conversationId,
        'visible_after_message_id' => max(0, (int)($viewerMember['visible_after_message_id'] ?? 0)),
        'membership_effective_at' => $viewerMember['membership_effective_at'] ?? null,
        'permission_role' => (string)($viewerMember['permission_role'] ?? 'member'),
        'legacy_link_key' => $relationship['legacy_link_key'] ?? null,
        'members' => $members,
    ];
}

function avatar_relationship_chat_message_accessible(
    PDO $pdo,
    array $message,
    int $sessionId,
    int $participantId,
    bool $lock = false
): ?array {
    if ((string)($message['scope'] ?? '') !== 'link'
        || (int)($message['session_id'] ?? 0) !== $sessionId
        || (int)($message['id'] ?? 0) <= 0) {
        return null;
    }
    $access = avatar_relationship_chat_access(
        $pdo,
        $sessionId,
        $participantId,
        (string)($message['link_key'] ?? ''),
        0,
        $lock
    );
    if (!$access || (int)$message['id'] <= (int)$access['visible_after_message_id']) return null;
    return $access;
}

function avatar_relationship_locked_row(PDO $pdo, int $sessionId, string $relationshipPublicId): ?array {
    $sql = 'SELECT * FROM avatar_relationships
             WHERE session_id = ? AND relationship_public_id = ? LIMIT 1';
    if (db_uses_mysql_syntax($pdo)) $sql .= ' FOR UPDATE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$sessionId, $relationshipPublicId]);
    return $stmt->fetch() ?: null;
}

function avatar_relationship_locked_members(PDO $pdo, int $relationshipDbId): array {
    $sql = "SELECT arm.*, p.user_id, p.session_id, p.last_seen_at
              FROM avatar_relationship_members arm
              JOIN participants p ON p.id = arm.participant_id
             WHERE arm.relationship_id = ? AND arm.membership_status = 'active'
             ORDER BY arm.member_order ASC, arm.membership_effective_at ASC, arm.id ASC";
    if (db_uses_mysql_syntax($pdo)) $sql .= ' FOR UPDATE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$relationshipDbId]);
    return $stmt->fetchAll();
}

function avatar_relationship_locked_participant(PDO $pdo, int $sessionId, int $participantId): ?array {
    $sql = 'SELECT * FROM participants WHERE session_id = ? AND id = ? LIMIT 1';
    if (db_uses_mysql_syntax($pdo)) $sql .= ' FOR UPDATE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$sessionId, $participantId]);
    return $stmt->fetch() ?: null;
}

function avatar_relationship_member_from_rows(array $members, int $participantId): ?array {
    foreach ($members as $member) {
        if ((int)$member['participant_id'] === $participantId) return $member;
    }
    return null;
}

function avatar_relationship_permission_allows(?array $member, array $roles = ['creator', 'manager']): bool {
    return $member !== null && in_array((string)($member['permission_role'] ?? ''), $roles, true);
}

function avatar_relationship_version_error(array $relationship, int $expectedVersion): ?array {
    $currentVersion = max(1, (int)($relationship['version'] ?? 1));
    if ($expectedVersion === $currentVersion) return null;
    return avatar_relationship_operation_error(
        'RELATIONSHIP_VERSION_STALE',
        'The relationship changed. Refresh and try again.',
        'relationship-version-stale',
        409
    ) + ['relationship_version' => $currentVersion];
}

function avatar_relationship_move_group(
    PDO $pdo,
    int $sessionId,
    int $actorParticipantId,
    string $relationshipPublicId,
    int $expectedVersion,
    string $operationId,
    array $positions
): array {
    return avatar_relationship_transaction($pdo, function() use (
        $pdo,
        $sessionId,
        $actorParticipantId,
        $relationshipPublicId,
        $expectedVersion,
        $operationId,
        $positions
    ): array {
        if ($relationshipPublicId === '' || !preg_match('/^[A-Za-z0-9:_-]{1,160}$/', $relationshipPublicId)) {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_MOVEMENT_INVALID',
                'A valid relationship is required.',
                'invalid-relationship-id',
                400
            );
        }
        if (!preg_match('/^[A-Za-z0-9:_-]{8,160}$/', $operationId)) {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_MOVEMENT_INVALID',
                'A valid movement operation is required.',
                'invalid-operation-id',
                400
            );
        }

        $relationship = avatar_relationship_locked_row($pdo, $sessionId, $relationshipPublicId);
        if (!$relationship || (string)$relationship['status'] !== 'active') {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_MOVEMENT_CONFLICT',
                'That relationship is no longer active.',
                'relationship-unavailable',
                409
            );
        }
        if ($versionError = avatar_relationship_version_error($relationship, $expectedVersion)) {
            return $versionError;
        }

        $members = avatar_relationship_locked_members($pdo, (int)$relationship['id']);
        $actor = avatar_relationship_member_from_rows($members, $actorParticipantId);
        if (!$actor || (string)($actor['relationship_role'] ?? '') !== 'normal' || empty($actor['last_seen_at'])) {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_MOVEMENT_FORBIDDEN',
                'This participant cannot move that relationship.',
                'actor-not-active-normal-member',
                403
            );
        }

        $priorStmt = $pdo->prepare(
            "SELECT id, payload FROM events
              WHERE session_id = ? AND type = 'relationship_position'
                AND payload LIKE ? ORDER BY id DESC LIMIT 10"
        );
        $priorStmt->execute([$sessionId, '%"operation_id":"' . $operationId . '"%']);
        foreach ($priorStmt->fetchAll() as $prior) {
            $priorPayload = json_decode((string)$prior['payload'], true) ?: [];
            if ((string)($priorPayload['operation_id'] ?? '') !== $operationId
                || (string)($priorPayload['relationship_id'] ?? '') !== $relationshipPublicId
                || (int)($priorPayload['actor_participant_id'] ?? 0) !== $actorParticipantId) {
                continue;
            }
            return [
                'ok' => true,
                'idempotent' => true,
                'event_id' => (int)$prior['id'],
                'relationship_id' => $relationshipPublicId,
                'relationship_version' => $expectedVersion,
                'operation_id' => $operationId,
                'positions' => array_values($priorPayload['positions'] ?? []),
            ];
        }

        $presentMemberIds = [];
        foreach ($members as $member) {
            if (empty($member['last_seen_at'])) continue;
            if ((string)($member['relationship_role'] ?? 'normal') === 'lap') {
                $hostId = (int)($member['lap_host_participant_id'] ?? 0);
                $host = avatar_relationship_member_from_rows($members, $hostId);
                if (!$host || empty($host['last_seen_at'])) continue;
            }
            $presentMemberIds[] = (int)$member['participant_id'];
        }
        sort($presentMemberIds, SORT_NUMERIC);

        if (!$positions || count($positions) !== count($presentMemberIds) || count($positions) > 100) {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_MOVEMENT_CONFLICT',
                'The visible relationship group changed. Refresh and try again.',
                'visible-member-set-changed',
                409
            );
        }

        $normalized = [];
        $positionIds = [];
        foreach ($positions as $position) {
            if (!is_array($position)) {
                return avatar_relationship_operation_error('RELATIONSHIP_MOVEMENT_INVALID', 'Invalid group position.', 'malformed-position', 400);
            }
            $participantId = (int)($position['participant_id'] ?? 0);
            $x = filter_var($position['x'] ?? null, FILTER_VALIDATE_FLOAT);
            $y = filter_var($position['y'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($participantId <= 0 || $x === false || $y === false || !is_finite((float)$x) || !is_finite((float)$y) || isset($positionIds[$participantId])) {
                return avatar_relationship_operation_error('RELATIONSHIP_MOVEMENT_INVALID', 'Invalid group position.', 'malformed-position', 400);
            }
            $positionIds[$participantId] = true;
            $normalized[] = [
                'participant_id' => $participantId,
                'position_x' => max(0, min(1, (float)$x)),
                'position_y' => max(0, min(1, (float)$y)),
            ];
        }
        $submittedIds = array_keys($positionIds);
        sort($submittedIds, SORT_NUMERIC);
        if ($submittedIds !== $presentMemberIds) {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_MOVEMENT_CONFLICT',
                'The visible relationship group changed. Refresh and try again.',
                'visible-member-set-changed',
                409
            );
        }

        $storedOptions = [];
        if (!empty($relationship['options_json'])) {
            $storedOptions = json_decode((string)$relationship['options_json'], true);
            if (!is_array($storedOptions) || json_last_error() !== JSON_ERROR_NONE) {
                return avatar_relationship_operation_error(
                    'RELATIONSHIP_MOVEMENT_CONFLICT',
                    'The relationship configuration requires repair.',
                    'malformed-stored-options',
                    409
                );
            }
        }
        $dancePlayback = avatar_relationship_cancel_dance_playback_options(
            $storedOptions,
            $operationId
        );
        if ($dancePlayback) {
            $pdo->prepare(
                'UPDATE avatar_relationships SET options_json = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
            )->execute([
                json_encode($storedOptions, JSON_UNESCAPED_SLASHES),
                (int)$relationship['id'],
            ]);
        }

        $update = $pdo->prepare(
            'UPDATE participants SET position_x = ?, position_y = ? WHERE id = ? AND session_id = ?'
        );
        foreach ($normalized as $position) {
            $update->execute([
                $position['position_x'],
                $position['position_y'],
                $position['participant_id'],
                $sessionId,
            ]);
            if ($update->rowCount() > 1) {
                throw new RuntimeException('Relationship group movement updated an invalid participant set.');
            }
        }

        $eventPayload = [
            'relationship_id' => $relationshipPublicId,
            'relationship_version' => $expectedVersion,
            'operation_id' => $operationId,
            'actor_participant_id' => $actorParticipantId,
            'positions' => $normalized,
            'final' => true,
        ];
        if ($dancePlayback) $eventPayload['dancePlayback'] = $dancePlayback;
        emit_event($pdo, $sessionId, 'relationship_position', $eventPayload);
        $eventId = (int)$pdo->lastInsertId();

        return ['ok' => true, 'idempotent' => false, 'event_id' => $eventId] + $eventPayload;
    });
}

function avatar_relationship_configure(
    PDO $pdo,
    int $sessionId,
    int $actorParticipantId,
    string $relationshipPublicId,
    int $expectedVersion,
    string $operationId,
    array $normalMemberOrder,
    mixed $options,
    array $positions
): array {
    return avatar_relationship_transaction($pdo, function() use (
        $pdo,
        $sessionId,
        $actorParticipantId,
        $relationshipPublicId,
        $expectedVersion,
        $operationId,
        $normalMemberOrder,
        $options,
        $positions
    ): array {
        if ($relationshipPublicId === '' || !preg_match('/^[A-Za-z0-9:_-]{1,160}$/', $relationshipPublicId)) {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_CONFIGURATION_INVALID',
                'A valid relationship is required.',
                'invalid-relationship-id',
                400
            );
        }
        if (!preg_match('/^[A-Za-z0-9:_-]{8,160}$/', $operationId)) {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_CONFIGURATION_INVALID',
                'A valid configuration operation is required.',
                'invalid-operation-id',
                400
            );
        }
        $optionDecision = avatar_relationship_validate_public_options($options);
        if (empty($optionDecision['ok'])) return $optionDecision;
        $configuration = $optionDecision['options'];

        $relationship = avatar_relationship_locked_row($pdo, $sessionId, $relationshipPublicId);
        if (!$relationship || (string)$relationship['status'] !== 'active') {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_CONFIGURATION_CONFLICT',
                'That relationship is no longer active.',
                'relationship-unavailable',
                409
            );
        }

        $priorStmt = $pdo->prepare(
            "SELECT id, payload FROM events
              WHERE session_id = ? AND type = 'relationship'
                AND payload LIKE ? ORDER BY id DESC LIMIT 20"
        );
        $priorStmt->execute([$sessionId, '%"operation_id":"' . $operationId . '"%']);
        foreach ($priorStmt->fetchAll() as $prior) {
            $priorPayload = json_decode((string)$prior['payload'], true) ?: [];
            if ((string)($priorPayload['operation_id'] ?? '') !== $operationId
                || (string)($priorPayload['relationship_id'] ?? '') !== $relationshipPublicId
                || (int)($priorPayload['actor_participant_id'] ?? 0) !== $actorParticipantId
                || (string)($priorPayload['action'] ?? '') !== 'configuration-updated') {
                continue;
            }
            return [
                'ok' => true,
                'idempotent' => true,
                'event_id' => (int)$prior['id'],
                'relationship_id' => $relationshipPublicId,
                'relationship_version' => (int)($priorPayload['relationship_version'] ?? $expectedVersion),
                'operation_id' => $operationId,
                'configuration' => $priorPayload['configuration'] ?? $configuration,
                'positions' => array_values($priorPayload['positions'] ?? []),
                'relationship' => avatar_relationship_payload($pdo, (int)$relationship['id'], $actorParticipantId),
            ];
        }

        if ($versionError = avatar_relationship_version_error($relationship, $expectedVersion)) return $versionError;
        $members = avatar_relationship_locked_members($pdo, (int)$relationship['id']);
        $currentMemberOrders = array_map(fn(array $member): int => (int)$member['member_order'], $members);
        $expectedMemberOrders = $members ? range(0, count($members) - 1) : [];
        if ($currentMemberOrders !== $expectedMemberOrders) {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_CONFIGURATION_CONFLICT',
                'The relationship configuration requires repair.',
                'invalid-member-ordering',
                409
            );
        }
        $actor = avatar_relationship_member_from_rows($members, $actorParticipantId);
        if (!avatar_relationship_permission_allows($actor)) {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_CONFIGURATION_FORBIDDEN',
                'You cannot configure this relationship.',
                'relationship-configuration-permission-denied',
                403
            );
        }

        $normalMembers = array_values(array_filter(
            $members,
            fn(array $member): bool => (string)($member['relationship_role'] ?? 'normal') === 'normal'
        ));
        $lapMembers = array_values(array_filter(
            $members,
            fn(array $member): bool => (string)($member['relationship_role'] ?? 'normal') === 'lap'
        ));
        $expectedNormalIds = array_map(fn(array $member): int => (int)$member['participant_id'], $normalMembers);
        sort($expectedNormalIds, SORT_NUMERIC);
        $submittedNormalIds = array_map('intval', array_values($normalMemberOrder));
        $sortedSubmittedNormalIds = $submittedNormalIds;
        sort($sortedSubmittedNormalIds, SORT_NUMERIC);
        if (!$submittedNormalIds
            || count($submittedNormalIds) !== count(array_unique($submittedNormalIds))
            || $sortedSubmittedNormalIds !== $expectedNormalIds) {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_CONFIGURATION_CONFLICT',
                'The relationship membership changed. Refresh and try again.',
                'normal-member-set-changed',
                409
            );
        }

        $storedOptions = [];
        if (!empty($relationship['options_json'])) {
            $storedOptions = json_decode((string)$relationship['options_json'], true);
            if (!is_array($storedOptions) || json_last_error() !== JSON_ERROR_NONE) {
                return avatar_relationship_operation_error(
                    'RELATIONSHIP_CONFIGURATION_CONFLICT',
                    'The relationship configuration requires repair.',
                    'malformed-stored-options',
                    409
                );
            }
        }
        if (!empty($optionDecision['legacy'])) {
            $storedPublicOptions = avatar_relationship_public_options($storedOptions);
            $configuration['formation'] = $storedPublicOptions['formation'];
            $configuration['transition'] = $storedPublicOptions['transition'];
        }
        $storedPublicOptions = avatar_relationship_public_options($storedOptions);
        $normalCount = count($normalMembers);
        if ((in_array($configuration['formation'], avatar_relationship_trio_formation_ids(), true) && $normalCount !== 3)
            || ($configuration['formation'] === 'grid' && $normalCount < 2)) {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_CONFIGURATION_INVALID',
                'That formation is unavailable for the current relationship.',
                'formation-inapplicable',
                400
            );
        }

        $presentMemberIds = [];
        foreach ($members as $member) {
            if (empty($member['last_seen_at'])) continue;
            if ((string)($member['relationship_role'] ?? 'normal') === 'lap') {
                $host = avatar_relationship_member_from_rows($members, (int)($member['lap_host_participant_id'] ?? 0));
                if (!$host || empty($host['last_seen_at'])) continue;
            }
            $presentMemberIds[] = (int)$member['participant_id'];
        }
        sort($presentMemberIds, SORT_NUMERIC);
        if (!$positions || count($positions) !== count($presentMemberIds) || count($positions) > 100) {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_CONFIGURATION_CONFLICT',
                'The visible relationship group changed. Refresh and try again.',
                'visible-member-set-changed',
                409
            );
        }
        $normalizedPositions = [];
        $positionIds = [];
        foreach ($positions as $position) {
            if (!is_array($position)) {
                return avatar_relationship_operation_error('RELATIONSHIP_CONFIGURATION_INVALID', 'Invalid group position.', 'malformed-position', 400);
            }
            $participantId = (int)($position['participant_id'] ?? 0);
            $x = filter_var($position['x'] ?? null, FILTER_VALIDATE_FLOAT);
            $y = filter_var($position['y'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($participantId <= 0 || $x === false || $y === false
                || !is_finite((float)$x) || !is_finite((float)$y)
                || isset($positionIds[$participantId])) {
                return avatar_relationship_operation_error('RELATIONSHIP_CONFIGURATION_INVALID', 'Invalid group position.', 'malformed-position', 400);
            }
            $positionIds[$participantId] = true;
            $normalizedPositions[] = [
                'participant_id' => $participantId,
                'position_x' => max(0, min(1, (float)$x)),
                'position_y' => max(0, min(1, (float)$y)),
            ];
        }
        $submittedPositionIds = array_keys($positionIds);
        sort($submittedPositionIds, SORT_NUMERIC);
        if ($submittedPositionIds !== $presentMemberIds) {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_CONFIGURATION_CONFLICT',
                'The visible relationship group changed. Refresh and try again.',
                'visible-member-set-changed',
                409
            );
        }

        $memberByParticipantId = [];
        foreach ($members as $member) $memberByParticipantId[(int)$member['participant_id']] = $member;
        $orderedMembers = [];
        foreach ($submittedNormalIds as $participantId) $orderedMembers[] = $memberByParticipantId[$participantId];
        usort($lapMembers, fn(array $first, array $second): int =>
            [(int)$first['member_order'], (int)$first['id']] <=> [(int)$second['member_order'], (int)$second['id']]
        );
        $orderedMembers = array_merge($orderedMembers, $lapMembers);

        $pdo->prepare(
            'UPDATE avatar_relationship_members SET member_order = member_order + 1000000 WHERE relationship_id = ?'
        )->execute([(int)$relationship['id']]);
        $orderUpdate = $pdo->prepare(
            'UPDATE avatar_relationship_members SET member_order = ?, updated_at = CURRENT_TIMESTAMP
              WHERE relationship_id = ? AND participant_id = ?'
        );
        foreach ($orderedMembers as $order => &$member) {
            $orderUpdate->execute([$order, (int)$relationship['id'], (int)$member['participant_id']]);
            if ($orderUpdate->rowCount() > 1) {
                throw new RuntimeException('Relationship ordering updated an invalid member set.');
            }
            $member['member_order'] = $order;
        }
        unset($member);

        $positionUpdate = $pdo->prepare(
            'UPDATE participants SET position_x = ?, position_y = ? WHERE id = ? AND session_id = ?'
        );
        foreach ($normalizedPositions as $position) {
            $positionUpdate->execute([
                $position['position_x'],
                $position['position_y'],
                $position['participant_id'],
                $sessionId,
            ]);
            if ($positionUpdate->rowCount() > 1) {
                throw new RuntimeException('Relationship configuration updated an invalid participant set.');
            }
        }

        $nextVersion = max(1, (int)$relationship['version']) + 1;
        avatar_relationship_cancel_dance_playback_options($storedOptions, $operationId);
        $storedOptions = array_merge($storedOptions, $configuration);
        $pdo->prepare(
            'UPDATE avatar_relationships
                SET options_json = ?, version = ?, updated_at = CURRENT_TIMESTAMP
              WHERE id = ?'
        )->execute([
            json_encode($storedOptions, JSON_UNESCAPED_SLASHES),
            $nextVersion,
            (int)$relationship['id'],
        ]);
        $relationship['version'] = $nextVersion;
        avatar_relationship_refresh_legacy_projection_locked($pdo, $relationship, $orderedMembers);

        $viewerPayload = avatar_relationship_payload($pdo, (int)$relationship['id'], $actorParticipantId);
        $eventPayload = avatar_relationship_public_event_snapshot($pdo, (int)$relationship['id']);
        avatar_relationship_emit_lifecycle_event(
            $pdo,
            $sessionId,
            'configuration-updated',
            $eventPayload,
            [
                'operation_id' => $operationId,
                'actor_participant_id' => $actorParticipantId,
                'configuration' => $configuration,
                'positions' => $normalizedPositions,
            ]
        );
        $eventId = (int)$pdo->lastInsertId();

        return [
            'ok' => true,
            'idempotent' => false,
            'event_id' => $eventId,
            'relationship_id' => $relationshipPublicId,
            'relationship_version' => $nextVersion,
            'operation_id' => $operationId,
            'configuration' => $configuration,
            'positions' => $normalizedPositions,
            'relationship' => $viewerPayload,
        ];
    });
}

function avatar_relationship_set_dance_playback(
    PDO $pdo,
    int $sessionId,
    int $actorParticipantId,
    string $relationshipPublicId,
    int $expectedVersion,
    string $operationId,
    string $playbackState,
    ?string $danceId = null
): array {
    return avatar_relationship_transaction($pdo, function() use (
        $pdo,
        $sessionId,
        $actorParticipantId,
        $relationshipPublicId,
        $expectedVersion,
        $operationId,
        $playbackState,
        $danceId
    ): array {
        if ($relationshipPublicId === '' || !preg_match('/^[A-Za-z0-9:_-]{1,160}$/', $relationshipPublicId)
            || !preg_match('/^[A-Za-z0-9:_-]{8,160}$/', $operationId)
            || !in_array($playbackState, ['playing', 'stopped'], true)
            || ($playbackState === 'playing' && !in_array($danceId, avatar_relationship_dance_ids(), true))) {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_DANCE_INVALID',
                'Invalid relationship dance operation.',
                'invalid-dance-operation',
                400
            );
        }

        $relationship = avatar_relationship_locked_row($pdo, $sessionId, $relationshipPublicId);
        if (!$relationship || (string)$relationship['status'] !== 'active') {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_DANCE_CONFLICT',
                'That relationship is no longer active.',
                'relationship-unavailable',
                409
            );
        }

        $priorStmt = $pdo->prepare(
            "SELECT id, payload FROM events
              WHERE session_id = ? AND type = 'relationship' AND payload LIKE ?
              ORDER BY id DESC LIMIT 20"
        );
        $priorStmt->execute([$sessionId, '%"operation_id":"' . $operationId . '"%']);
        foreach ($priorStmt->fetchAll() as $prior) {
            $priorPayload = json_decode((string)$prior['payload'], true) ?: [];
            if ((string)($priorPayload['operation_id'] ?? '') !== $operationId
                || (string)($priorPayload['relationship_id'] ?? '') !== $relationshipPublicId
                || (int)($priorPayload['actor_participant_id'] ?? 0) !== $actorParticipantId
                || (string)($priorPayload['action'] ?? '') !== 'dance-playback-updated') {
                continue;
            }
            return [
                'ok' => true,
                'idempotent' => true,
                'event_id' => (int)$prior['id'],
                'relationship_id' => $relationshipPublicId,
                'relationship_version' => (int)($priorPayload['relationship_version'] ?? $expectedVersion),
                'operation_id' => $operationId,
                'dancePlayback' => avatar_relationship_dance_playback($priorPayload['dancePlayback'] ?? null),
                'relationship' => avatar_relationship_payload($pdo, (int)$relationship['id'], $actorParticipantId),
            ];
        }

        if ($versionError = avatar_relationship_version_error($relationship, $expectedVersion)) return $versionError;
        $members = avatar_relationship_locked_members($pdo, (int)$relationship['id']);
        $actor = avatar_relationship_member_from_rows($members, $actorParticipantId);
        if (!avatar_relationship_permission_allows($actor)) {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_DANCE_FORBIDDEN',
                'You cannot control this relationship dance.',
                'relationship-dance-permission-denied',
                403
            );
        }

        if ($playbackState === 'playing') {
            $activeMembers = array_values(array_filter(
                $members,
                fn(array $member): bool => (string)($member['membership_status'] ?? 'active') === 'active'
            ));
            $visibleNormalHosts = array_values(array_filter(
                $activeMembers,
                fn(array $member): bool => (string)($member['relationship_role'] ?? 'normal') === 'normal'
                    && !empty($member['last_seen_at'])
            ));
            if (count($activeMembers) < 2 || !$visibleNormalHosts) {
                return avatar_relationship_operation_error(
                    'RELATIONSHIP_DANCE_UNAVAILABLE',
                    'That dance is unavailable for the current relationship.',
                    'dance-inapplicable',
                    409
                );
            }
        }

        $storedOptions = [];
        if (!empty($relationship['options_json'])) {
            $storedOptions = json_decode((string)$relationship['options_json'], true);
            if (!is_array($storedOptions) || json_last_error() !== JSON_ERROR_NONE) {
                return avatar_relationship_operation_error(
                    'RELATIONSHIP_DANCE_CONFLICT',
                    'The relationship configuration requires repair.',
                    'malformed-stored-options',
                    409
                );
            }
        }
        $playback = $playbackState === 'playing'
            ? avatar_relationship_dance_playback([
                'state' => 'playing',
                'danceId' => $danceId,
                'startedAtMs' => (int)floor(microtime(true) * 1000),
                'generation' => $operationId,
                'initiatorParticipantId' => $actorParticipantId,
            ])
            : avatar_relationship_dance_playback([
                'state' => 'stopped',
                'generation' => $operationId,
            ]);
        $storedOptions['dancePlayback'] = $playback;
        $nextVersion = max(1, (int)$relationship['version']) + 1;
        $pdo->prepare(
            'UPDATE avatar_relationships
                SET options_json = ?, version = ?, updated_at = CURRENT_TIMESTAMP
              WHERE id = ?'
        )->execute([
            json_encode($storedOptions, JSON_UNESCAPED_SLASHES),
            $nextVersion,
            (int)$relationship['id'],
        ]);
        $relationship['version'] = $nextVersion;

        $viewerPayload = avatar_relationship_payload($pdo, (int)$relationship['id'], $actorParticipantId);
        $eventPayload = avatar_relationship_public_event_snapshot($pdo, (int)$relationship['id']);
        avatar_relationship_emit_lifecycle_event(
            $pdo,
            $sessionId,
            'dance-playback-updated',
            $eventPayload,
            [
                'operation_id' => $operationId,
                'actor_participant_id' => $actorParticipantId,
                'dancePlayback' => $playback,
            ]
        );
        $eventId = (int)$pdo->lastInsertId();

        return [
            'ok' => true,
            'idempotent' => false,
            'event_id' => $eventId,
            'relationship_id' => $relationshipPublicId,
            'relationship_version' => $nextVersion,
            'operation_id' => $operationId,
            'dancePlayback' => $playback,
            'relationship' => $viewerPayload,
        ];
    });
}

function avatar_relationship_blocked_for_members(PDO $pdo, array $target, array $members): bool {
    $targetUserId = (int)($target['user_id'] ?? 0);
    $memberUserIds = array_values(array_unique(array_filter(array_map(
        fn(array $member): int => (int)($member['user_id'] ?? 0),
        $members
    ), fn(int $id): bool => $id > 0 && $id !== $targetUserId)));
    if ($targetUserId <= 0 || !$memberUserIds) return false;
    $placeholders = implode(',', array_fill(0, count($memberUserIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT 1 FROM user_blocks
          WHERE (blocker_user_id = ? AND blocked_user_id IN ($placeholders))
             OR (blocked_user_id = ? AND blocker_user_id IN ($placeholders))
          LIMIT 1"
    );
    $stmt->execute(array_merge([$targetUserId], $memberUserIds, [$targetUserId], $memberUserIds));
    return (bool)$stmt->fetchColumn();
}

function avatar_relationship_request_payload(array $request): array {
    return [
        'id' => (string)$request['request_public_id'],
        'relationshipId' => (string)($request['relationship_public_id'] ?? ''),
        'relationshipVersion' => max(1, (int)$request['relationship_version']),
        'requesterParticipantId' => (int)$request['requester_participant_id'],
        'targetParticipantId' => (int)$request['target_participant_id'],
        'type' => (string)$request['request_type'],
        'requestedRelationshipRole' => (string)$request['requested_relationship_role'],
        'requestedLapHostParticipantId' => $request['requested_lap_host_participant_id'] !== null
            ? (int)$request['requested_lap_host_participant_id']
            : null,
        'requestedLapSide' => avatar_relationship_normalize_lap_side($request['requested_lap_side'] ?? null),
        'status' => (string)$request['status'],
        'resolutionActorParticipantId' => $request['resolution_actor_participant_id'] !== null
            ? (int)$request['resolution_actor_participant_id']
            : null,
        'resolutionReason' => $request['resolution_reason'] ?? null,
        'createdAt' => $request['created_at'] ?? null,
        'updatedAt' => $request['updated_at'] ?? null,
        'resolvedAt' => $request['resolved_at'] ?? null,
        'expiresAt' => $request['expires_at'] ?? null,
    ];
}

function avatar_relationship_locked_request(PDO $pdo, string $requestPublicId): ?array {
    $sql = 'SELECT arr.*, ar.relationship_public_id, ar.session_id, ar.version AS current_relationship_version,
                   ar.status AS relationship_status
              FROM avatar_relationship_requests arr
              JOIN avatar_relationships ar ON ar.id = arr.relationship_id
             WHERE arr.request_public_id = ? LIMIT 1';
    if (db_uses_mysql_syntax($pdo)) $sql .= ' FOR UPDATE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$requestPublicId]);
    return $stmt->fetch() ?: null;
}

function avatar_relationship_public_event_snapshot(PDO $pdo, int $relationshipDbId): ?array {
    return avatar_relationship_payload($pdo, $relationshipDbId, 0);
}

function avatar_relationship_emit_lifecycle_event(
    PDO $pdo,
    int $sessionId,
    string $action,
    array $relationship,
    array $extra = []
): void {
    $safetyCancellationActions = [
        'configuration-updated',
        'member-added',
        'lap-side-changed',
        'member-left',
        'member-removed',
        'relationship-dissolved',
    ];
    if (in_array($action, $safetyCancellationActions, true)
        && avatar_relationship_dance_playback($relationship['dancePlayback'] ?? null)['state'] === 'playing') {
        $relationshipPublicId = (string)($relationship['id'] ?? $relationship['relationship_id'] ?? '');
        $stmt = $pdo->prepare(
            'SELECT id, options_json FROM avatar_relationships
              WHERE session_id = ? AND relationship_public_id = ? LIMIT 1'
        );
        $stmt->execute([$sessionId, $relationshipPublicId]);
        $row = $stmt->fetch() ?: null;
        if ($row) {
            $storedOptions = !empty($row['options_json'])
                ? (json_decode((string)$row['options_json'], true) ?: [])
                : [];
            $generation = 'safety-' . $action . '-' . max(1, (int)($relationship['version'] ?? 1));
            $stopped = avatar_relationship_cancel_dance_playback_options($storedOptions, $generation);
            if ($stopped) {
                $pdo->prepare(
                    'UPDATE avatar_relationships SET options_json = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
                )->execute([
                    json_encode($storedOptions, JSON_UNESCAPED_SLASHES),
                    (int)$row['id'],
                ]);
                $relationship['dancePlayback'] = $stopped;
                $extra['dancePlayback'] = $stopped;
            }
        }
    }
    $payload = [
        'action' => $action,
        'relationship_id' => (string)($relationship['id'] ?? $relationship['relationship_id'] ?? ''),
        'relationship_version' => max(1, (int)($relationship['version'] ?? 1)),
        'relationship_status' => (string)($relationship['status'] ?? 'active'),
    ] + $extra;
    if (isset($relationship['members'])) $payload['relationship'] = $relationship;
    emit_event($pdo, $sessionId, 'relationship', $payload);
}

function avatar_relationship_validate_requested_role(
    array $members,
    string $role,
    ?int $lapHostParticipantId,
    ?string $lapSide = null,
    ?int $occupantParticipantId = null,
    bool $allowDeferredSide = false
): array {
    if (!in_array($role, ['normal', 'lap'], true)) {
        return avatar_relationship_operation_error(
            'RELATIONSHIP_CONFLICT',
            'That relationship role is not available.',
            'unsupported-relationship-role',
            400
        );
    }
    if ($role === 'normal') {
        if ($lapSide !== null && trim($lapSide) !== '') {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_CONFLICT',
                'Normal relationship members do not use lap seats.',
                'lap-side-not-applicable',
                400
            );
        }
        return ['ok' => true, 'lap_host_participant_id' => null, 'lap_side' => null];
    }
    if (!$lapHostParticipantId) {
        return avatar_relationship_operation_error(
            'RELATIONSHIP_CONFLICT',
            'A normal lap host is required.',
            'lap-host-required',
            400
        );
    }
    $host = avatar_relationship_member_from_rows($members, $lapHostParticipantId);
    if (!$host || (string)$host['relationship_role'] !== 'normal' || (string)$host['membership_status'] !== 'active') {
        return avatar_relationship_operation_error(
            'RELATIONSHIP_CONFLICT',
            'That lap host is not available.',
            'lap-host-unavailable',
            409
        );
    }
    if ($occupantParticipantId !== null && $lapHostParticipantId === $occupantParticipantId) {
        return avatar_relationship_operation_error(
            'RELATIONSHIP_CONFLICT',
            'A lap occupant cannot host themselves.',
            'lap-host-self',
            409
        );
    }
    if (empty($host['last_seen_at'])) {
        return avatar_relationship_operation_error(
            'RELATIONSHIP_CONFLICT',
            'That lap host is not present.',
            'lap-host-unavailable',
            409
        );
    }
    $normalizedSide = avatar_relationship_normalize_lap_side($lapSide);
    if ($normalizedSide === null && $lapSide !== null && trim($lapSide) !== '') {
        return avatar_relationship_operation_error(
            'RELATIONSHIP_CONFLICT',
            'That lap side is not available.',
            'invalid-lap-side',
            400
        );
    }
    if ($normalizedSide === null && !$allowDeferredSide) {
        return avatar_relationship_operation_error(
            'RELATIONSHIP_CONFLICT',
            'Choose an available lap side.',
            'lap-side-required',
            400
        );
    }
    $occupiedSides = [];
    foreach ($members as $member) {
        if ((string)$member['relationship_role'] !== 'lap'
            || (int)($member['lap_host_participant_id'] ?? 0) !== $lapHostParticipantId
            || ($occupantParticipantId !== null && (int)$member['participant_id'] === $occupantParticipantId)) {
            continue;
        }
        $occupiedSide = avatar_relationship_normalize_lap_side($member['lap_side'] ?? null);
        if ($occupiedSide !== null) $occupiedSides[$occupiedSide] = true;
    }
    if ($normalizedSide !== null && isset($occupiedSides[$normalizedSide])) {
        return avatar_relationship_operation_error(
            'RELATIONSHIP_LAP_SEAT_OCCUPIED',
            'That lap side is already occupied.',
            'lap-seat-occupied',
            409
        );
    }
    return [
        'ok' => true,
        'lap_host_participant_id' => $lapHostParticipantId,
        'lap_side' => $normalizedSide,
        'available_lap_sides' => array_values(array_filter(
            avatar_relationship_lap_sides(),
            fn(string $side): bool => !isset($occupiedSides[$side])
        )),
    ];
}

function avatar_relationship_target_membership(PDO $pdo, int $participantId): ?array {
    $sql = "SELECT ar.id, ar.relationship_public_id, ar.status
              FROM avatar_relationship_members arm
              JOIN avatar_relationships ar ON ar.id = arm.relationship_id
             WHERE arm.participant_id = ?
               AND arm.membership_status IN ('active', 'conflicted')
               AND ar.status IN ('active', 'conflicted')
             LIMIT 1";
    if (db_uses_mysql_syntax($pdo)) $sql .= ' FOR UPDATE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$participantId]);
    return $stmt->fetch() ?: null;
}

function avatar_relationship_lap_seat_eligibility(
    PDO $pdo,
    int $sessionId,
    int $actorParticipantId,
    string $relationshipPublicId,
    int $expectedVersion,
    int $occupantParticipantId,
    int $hostParticipantId,
    ?string $lapSide = null
): array {
    return avatar_relationship_transaction($pdo, function() use (
        $pdo,
        $sessionId,
        $actorParticipantId,
        $relationshipPublicId,
        $expectedVersion,
        $occupantParticipantId,
        $hostParticipantId,
        $lapSide
    ): array {
        $relationship = avatar_relationship_locked_row($pdo, $sessionId, $relationshipPublicId);
        if (!$relationship || (string)$relationship['status'] !== 'active'
            || (string)($relationship['divergence_status'] ?? 'synced') !== 'synced') {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_LAP_SEAT_CONFLICT',
                'That lap seat is not available.',
                'relationship-unavailable',
                409
            );
        }
        if ($versionError = avatar_relationship_version_error($relationship, $expectedVersion)) return $versionError;
        $members = avatar_relationship_locked_members($pdo, (int)$relationship['id']);
        $actorMember = avatar_relationship_member_from_rows($members, $actorParticipantId);
        if ($actorParticipantId !== $occupantParticipantId && !avatar_relationship_permission_allows($actorMember)) {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_PERMISSION_DENIED',
                'You cannot inspect that lap seat.',
                'lap-seat-permission-denied',
                403
            );
        }
        $occupant = avatar_relationship_locked_participant($pdo, $sessionId, $occupantParticipantId);
        if (!$occupant || empty($occupant['last_seen_at'])) {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_LAP_SEAT_CONFLICT',
                'That lap seat is not available.',
                'occupant-unavailable',
                409
            );
        }
        $occupantMember = avatar_relationship_member_from_rows($members, $occupantParticipantId);
        if ($occupantMember && (string)$occupantMember['relationship_role'] !== 'lap') {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_LAP_SEAT_CONFLICT',
                'That lap seat is not available.',
                'occupant-role-unavailable',
                409
            );
        }
        if (!$occupantMember && avatar_relationship_target_membership($pdo, $occupantParticipantId)) {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_MEMBER_ELSEWHERE',
                'That participant belongs to another relationship.',
                'relationship-member-elsewhere',
                409
            );
        }
        if (avatar_relationship_blocked_for_members($pdo, $occupant, $members)) {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_PERMISSION_DENIED',
                'That lap seat is not available.',
                'blocked',
                403
            );
        }

        $availability = avatar_relationship_validate_requested_role(
            $members,
            'lap',
            $hostParticipantId,
            null,
            $occupantParticipantId,
            true
        );
        if (empty($availability['ok'])) return $availability;
        $normalizedSide = avatar_relationship_normalize_lap_side($lapSide);
        if ($normalizedSide === null && $lapSide !== null && trim($lapSide) !== '') {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_CONFLICT',
                'That lap side is not available.',
                'invalid-lap-side',
                400
            );
        }
        $allowed = $normalizedSide === null
            || in_array($normalizedSide, $availability['available_lap_sides'], true);
        $reason = $allowed ? 'eligible' : 'lap-seat-occupied';
        $fingerprintState = [
            'relationship_id' => $relationshipPublicId,
            'relationship_version' => (int)$relationship['version'],
            'occupant_participant_id' => $occupantParticipantId,
            'host_participant_id' => $hostParticipantId,
            'lap_side' => $normalizedSide,
            'available_lap_sides' => $availability['available_lap_sides'],
        ];
        return [
            'ok' => true,
            'allowed' => $allowed,
            'reason' => $reason,
            'relationship_id' => $relationshipPublicId,
            'relationship_version' => (int)$relationship['version'],
            'host_participant_id' => $hostParticipantId,
            'lap_side' => $normalizedSide,
            'available_lap_sides' => $availability['available_lap_sides'],
            'state_fingerprint' => hash('sha256', json_encode($fingerprintState, JSON_UNESCAPED_SLASHES)),
        ];
    });
}

function avatar_relationship_add_member_locked(
    PDO $pdo,
    array $relationship,
    array $members,
    array $target,
    int $actorParticipantId,
    string $relationshipRole,
    ?int $lapHostParticipantId,
    ?string $lapSide,
    string $reason
): array {
    $relationshipDbId = (int)$relationship['id'];
    $targetParticipantId = (int)$target['id'];
    $occupied = avatar_relationship_target_membership($pdo, $targetParticipantId);
    if ($occupied) {
        $code = (int)$occupied['id'] === $relationshipDbId
            ? 'RELATIONSHIP_ALREADY_MEMBER'
            : 'RELATIONSHIP_MEMBER_ELSEWHERE';
        return avatar_relationship_operation_error(
            $code,
            $code === 'RELATIONSHIP_ALREADY_MEMBER'
                ? 'That participant is already a member.'
                : 'That participant belongs to another relationship.',
            strtolower(str_replace('_', '-', $code)),
            409
        );
    }
    if (avatar_relationship_blocked_for_members($pdo, $target, $members)) {
        return avatar_relationship_operation_error(
            'RELATIONSHIP_PERMISSION_DENIED',
            'That participant cannot join this relationship.',
            'blocked',
            403
        );
    }
    $roleDecision = avatar_relationship_validate_requested_role(
        $members,
        $relationshipRole,
        $lapHostParticipantId,
        $lapSide,
        $targetParticipantId
    );
    if (empty($roleDecision['ok'])) return $roleDecision;

    $nextOrder = $members
        ? max(array_map(fn(array $member): int => (int)$member['member_order'], $members)) + 1
        : 0;
    $effectiveAt = gmdate('Y-m-d H:i:s');
    $visibleAfterMessageId = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM community_messages')->fetchColumn();
    try {
        $pdo->prepare(
            "INSERT INTO avatar_relationship_members
                (relationship_id, participant_id, member_role, relationship_role, permission_role,
                 membership_status, active_participant_id, member_order, membership_effective_at,
                 visible_after_message_id, lap_host_participant_id, lap_side, options_json)
             VALUES (?,?, 'member', ?, 'member', 'active', ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $relationshipDbId,
            $targetParticipantId,
            $relationshipRole,
            $targetParticipantId,
            $nextOrder,
            $effectiveAt,
            $visibleAfterMessageId,
            $roleDecision['lap_host_participant_id'],
            $roleDecision['lap_side'],
            json_encode([], JSON_UNESCAPED_SLASHES),
        ]);
    } catch (PDOException $error) {
        if ($error->getCode() !== '23000' && !str_contains(strtoupper($error->getMessage()), 'UNIQUE')) throw $error;
        if ($relationshipRole === 'lap' && !avatar_relationship_target_membership($pdo, $targetParticipantId)) {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_LAP_SEAT_OCCUPIED',
                'That lap side is already occupied.',
                'lap-seat-occupied',
                409
            );
        }
        return avatar_relationship_operation_error(
            'RELATIONSHIP_MEMBER_ELSEWHERE',
            'That participant belongs to another relationship.',
            'relationship-member-elsewhere',
            409
        );
    }

    $formationNormalization = null;
    if ($relationshipRole === 'normal') {
        $formationNormalization = avatar_relationship_normalize_permanently_invalid_formation_locked(
            $pdo,
            $relationship,
            avatar_relationship_locked_members($pdo, $relationshipDbId)
        );
    }
    $nextVersion = max(1, (int)$relationship['version']) + 1;
    $pdo->prepare(
        'UPDATE avatar_relationships SET version = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
    )->execute([$nextVersion, $relationshipDbId]);
    $pdo->prepare(
        'INSERT INTO avatar_relationship_membership_history
            (relationship_id, participant_id, user_id, actor_participant_id, action, outcome,
             relationship_role, permission_role, member_order, membership_effective_at,
             visible_after_message_id, lap_host_participant_id, lap_side, relationship_version, reason)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $relationshipDbId,
        $targetParticipantId,
        (int)$target['user_id'],
        $actorParticipantId,
        'member-added',
        'applied',
        $relationshipRole,
        'member',
        $nextOrder,
        $effectiveAt,
        $visibleAfterMessageId,
        $roleDecision['lap_host_participant_id'],
        $roleDecision['lap_side'],
        $nextVersion,
        $reason,
    ]);
    return [
        'ok' => true,
        'relationship_version' => $nextVersion,
        'configuration_normalized' => $formationNormalization,
        'relationship' => avatar_relationship_payload($pdo, $relationshipDbId, $actorParticipantId),
        'event_relationship' => avatar_relationship_public_event_snapshot($pdo, $relationshipDbId),
    ];
}

function avatar_relationship_create_request(
    PDO $pdo,
    int $sessionId,
    int $actorParticipantId,
    string $relationshipPublicId,
    int $expectedVersion,
    string $requestType,
    int $targetParticipantId,
    string $requestedRelationshipRole = 'normal',
    ?int $lapHostParticipantId = null,
    ?string $requestedLapSide = null
): array {
    return avatar_relationship_transaction($pdo, function() use (
        $pdo, $sessionId, $actorParticipantId, $relationshipPublicId, $expectedVersion,
        $requestType, $targetParticipantId, $requestedRelationshipRole, $lapHostParticipantId,
        $requestedLapSide
    ): array {
        $relationship = avatar_relationship_locked_row($pdo, $sessionId, $relationshipPublicId);
        if (!$relationship) return avatar_relationship_operation_error('RELATIONSHIP_NOT_FOUND', 'Relationship not found.', 'relationship-not-found', 404);
        if ((string)$relationship['status'] !== 'active') return avatar_relationship_operation_error('RELATIONSHIP_NOT_ACTIVE', 'Relationship is not active.', 'relationship-not-active', 409);
        if ($versionError = avatar_relationship_version_error($relationship, $expectedVersion)) return $versionError;
        if (!in_array($requestType, ['join-request', 'invitation'], true)) {
            return avatar_relationship_operation_error('RELATIONSHIP_CONFLICT', 'Unknown request type.', 'unknown-request-type', 400);
        }

        $members = avatar_relationship_locked_members($pdo, (int)$relationship['id']);
        $actor = avatar_relationship_locked_participant($pdo, $sessionId, $actorParticipantId);
        $target = avatar_relationship_locked_participant($pdo, $sessionId, $targetParticipantId);
        if (!$actor) return avatar_relationship_operation_error('RELATIONSHIP_PERMISSION_DENIED', 'Relationship action unavailable.', 'actor-unavailable', 403);
        if (!$target || empty($target['last_seen_at'])) return avatar_relationship_operation_error('RELATIONSHIP_CONFLICT', 'That participant is unavailable.', 'target-unavailable', 409);
        $actorMember = avatar_relationship_member_from_rows($members, $actorParticipantId);
        if ($requestType === 'join-request' && $actorParticipantId !== $targetParticipantId) {
            return avatar_relationship_operation_error('RELATIONSHIP_PERMISSION_DENIED', 'You may only request membership for yourself.', 'join-request-target-mismatch', 403);
        }
        if ($requestType === 'invitation' && !avatar_relationship_permission_allows($actorMember)) {
            return avatar_relationship_operation_error('RELATIONSHIP_PERMISSION_DENIED', 'You cannot invite members to this relationship.', 'invite-permission-denied', 403);
        }
        if (avatar_relationship_member_from_rows($members, $targetParticipantId)) {
            return avatar_relationship_operation_error('RELATIONSHIP_ALREADY_MEMBER', 'That participant is already a member.', 'relationship-already-member', 409);
        }
        if ($occupied = avatar_relationship_target_membership($pdo, $targetParticipantId)) {
            return avatar_relationship_operation_error('RELATIONSHIP_MEMBER_ELSEWHERE', 'That participant belongs to another relationship.', 'relationship-member-elsewhere', 409);
        }
        if (avatar_relationship_blocked_for_members($pdo, $target, $members)) {
            return avatar_relationship_operation_error('RELATIONSHIP_PERMISSION_DENIED', 'That participant cannot join this relationship.', 'blocked', 403);
        }
        $roleDecision = avatar_relationship_validate_requested_role(
            $members,
            $requestedRelationshipRole,
            $lapHostParticipantId,
            $requestedLapSide,
            $targetParticipantId,
            $requestType === 'invitation'
        );
        if (empty($roleDecision['ok'])) return $roleDecision;
        if ($requestType === 'invitation' && $requestedRelationshipRole === 'lap'
            && $roleDecision['lap_side'] !== null) {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_CONFLICT',
                'The invited participant chooses their lap side when accepting.',
                'invitation-lap-side-deferred',
                400
            );
        }

        $activeRequestKey = (int)$relationship['id'] . ':' . $targetParticipantId;
        $existingStmt = $pdo->prepare(
            "SELECT arr.*, ar.relationship_public_id
               FROM avatar_relationship_requests arr
               JOIN avatar_relationships ar ON ar.id = arr.relationship_id
              WHERE arr.active_request_key = ? AND arr.status = 'pending' LIMIT 1"
        );
        $existingStmt->execute([$activeRequestKey]);
        $existing = $existingStmt->fetch() ?: null;
        if ($existing) {
            $same = (string)$existing['request_type'] === $requestType
                && (int)$existing['requester_participant_id'] === $actorParticipantId
                && (string)$existing['requested_relationship_role'] === $requestedRelationshipRole
                && (int)($existing['requested_lap_host_participant_id'] ?? 0) === (int)($roleDecision['lap_host_participant_id'] ?? 0)
                && (string)($existing['requested_lap_side'] ?? '') === (string)($roleDecision['lap_side'] ?? '')
                && (int)$existing['relationship_version'] === $expectedVersion;
            if (!$same) return avatar_relationship_operation_error('RELATIONSHIP_CONFLICT', 'A membership request is already pending.', 'relationship-request-conflict', 409);
            return ['ok' => true, 'idempotent' => true, 'request' => avatar_relationship_request_payload($existing)];
        }

        $requestPublicId = uuid_v4();
        $pdo->prepare(
            'INSERT INTO avatar_relationship_requests
                (request_public_id, relationship_id, relationship_version, requester_participant_id,
                 target_participant_id, request_type, requested_relationship_role,
                 requested_lap_host_participant_id, requested_lap_side, status, active_request_key)
             VALUES (?,?,?,?,?,?,?,?,?,\'pending\',?)'
        )->execute([
            $requestPublicId,
            (int)$relationship['id'],
            $expectedVersion,
            $actorParticipantId,
            $targetParticipantId,
            $requestType,
            $requestedRelationshipRole,
            $roleDecision['lap_host_participant_id'],
            $roleDecision['lap_side'],
            $activeRequestKey,
        ]);

        $request = avatar_relationship_locked_request($pdo, $requestPublicId);
        avatar_relationship_emit_lifecycle_event(
            $pdo,
            $sessionId,
            $requestType === 'invitation' ? 'invitation-created' : 'request-created',
            [
                'id' => $relationshipPublicId,
                'version' => $expectedVersion,
                'status' => 'active',
            ]
        );

        if ($requestType === 'join-request' && (string)$relationship['join_policy'] === 'open') {
            $add = avatar_relationship_add_member_locked(
                $pdo,
                $relationship,
                $members,
                $target,
                $actorParticipantId,
                $requestedRelationshipRole,
                $roleDecision['lap_host_participant_id'],
                $roleDecision['lap_side'],
                'open-join-policy'
            );
            if (empty($add['ok'])) return $add;
            $pdo->prepare(
                "UPDATE avatar_relationship_requests
                    SET status = 'accepted', active_request_key = NULL,
                        resolution_actor_participant_id = ?, resolution_reason = 'open-join-policy',
                        resolved_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                  WHERE id = ?"
            )->execute([$actorParticipantId, (int)$request['id']]);
            avatar_relationship_emit_lifecycle_event(
                $pdo,
                $sessionId,
                'request-accepted',
                [
                    'id' => $relationshipPublicId,
                    'version' => (int)$add['relationship_version'],
                    'status' => 'active',
                ]
            );
            avatar_relationship_emit_lifecycle_event(
                $pdo,
                $sessionId,
                'member-added',
                $add['event_relationship'],
                array_filter([
                    'request_outcome' => 'accepted',
                    'configuration_normalized' => $add['configuration_normalized'] ?? null,
                ], fn(mixed $value): bool => $value !== null)
            );
            $accepted = avatar_relationship_locked_request($pdo, $requestPublicId);
            return [
                'ok' => true,
                'request' => avatar_relationship_request_payload($accepted),
                'relationship' => $add['relationship'],
            ];
        }

        return ['ok' => true, 'request' => avatar_relationship_request_payload($request)];
    });
}

function avatar_relationship_resolve_request(
    PDO $pdo,
    int $sessionId,
    int $actorParticipantId,
    string $requestPublicId,
    int $expectedVersion,
    string $resolution,
    ?string $acceptedLapSide = null
): array {
    return avatar_relationship_transaction($pdo, function() use (
        $pdo, $sessionId, $actorParticipantId, $requestPublicId, $expectedVersion, $resolution,
        $acceptedLapSide
    ): array {
        $request = avatar_relationship_locked_request($pdo, $requestPublicId);
        if (!$request || (int)$request['session_id'] !== $sessionId) {
            return avatar_relationship_operation_error('RELATIONSHIP_REQUEST_NOT_FOUND', 'Membership request not found.', 'relationship-request-not-found', 404);
        }
        $relationship = avatar_relationship_locked_row($pdo, $sessionId, (string)$request['relationship_public_id']);
        if (!$relationship || (string)$relationship['status'] !== 'active') {
            return avatar_relationship_operation_error('RELATIONSHIP_NOT_ACTIVE', 'Relationship is not active.', 'relationship-not-active', 409);
        }
        if ((string)$request['status'] === 'accepted' && $resolution === 'accept') {
            $active = avatar_relationship_target_membership($pdo, (int)$request['target_participant_id']);
            if ($active && (int)$active['id'] === (int)$relationship['id']) {
                return [
                    'ok' => true,
                    'idempotent' => true,
                    'request' => avatar_relationship_request_payload($request),
                    'relationship' => avatar_relationship_payload($pdo, (int)$relationship['id'], $actorParticipantId),
                ];
            }
        }
        if ((string)$request['status'] !== 'pending') {
            return avatar_relationship_operation_error('RELATIONSHIP_REQUEST_ALREADY_RESOLVED', 'Membership request is already resolved.', 'relationship-request-already-resolved', 409)
                + ['request_status' => (string)$request['status']];
        }
        if ($versionError = avatar_relationship_version_error($relationship, $expectedVersion)) return $versionError;
        if ((int)$request['relationship_version'] !== (int)$relationship['version']) {
            $pdo->prepare(
                "UPDATE avatar_relationship_requests
                    SET status = 'expired', active_request_key = NULL,
                        resolution_reason = 'relationship-version-stale', resolved_at = CURRENT_TIMESTAMP,
                        updated_at = CURRENT_TIMESTAMP
                  WHERE id = ?"
            )->execute([(int)$request['id']]);
            avatar_relationship_emit_lifecycle_event($pdo, $sessionId, 'request-expired', [
                'id' => (string)$relationship['relationship_public_id'],
                'version' => (int)$relationship['version'],
                'status' => 'active',
            ]);
            return avatar_relationship_operation_error('RELATIONSHIP_REQUEST_STALE', 'Membership request is stale.', 'relationship-request-stale', 409);
        }

        $members = avatar_relationship_locked_members($pdo, (int)$relationship['id']);
        $actorMember = avatar_relationship_member_from_rows($members, $actorParticipantId);
        $type = (string)$request['request_type'];
        $isTarget = $actorParticipantId === (int)$request['target_participant_id'];
        $isRequester = $actorParticipantId === (int)$request['requester_participant_id'];
        $isManager = avatar_relationship_permission_allows($actorMember);
        $allowed = match ($resolution) {
            'accept', 'reject' => $type === 'invitation' ? $isTarget : $isManager,
            'cancel' => $isRequester || ($type === 'invitation' && $isManager),
            default => false,
        };
        if (!$allowed) return avatar_relationship_operation_error('RELATIONSHIP_PERMISSION_DENIED', 'You cannot resolve this membership request.', 'request-resolution-permission-denied', 403);

        if ($resolution === 'accept') {
            $target = avatar_relationship_locked_participant($pdo, $sessionId, (int)$request['target_participant_id']);
            if (!$target || empty($target['last_seen_at'])) return avatar_relationship_operation_error('RELATIONSHIP_CONFLICT', 'That participant is unavailable.', 'target-unavailable', 409);
            $requestRole = (string)$request['requested_relationship_role'];
            $resolvedLapSide = $request['requested_lap_side'] ?? null;
            if ($type === 'invitation' && $requestRole === 'lap') {
                $resolvedLapSide = $acceptedLapSide;
            }
            $add = avatar_relationship_add_member_locked(
                $pdo,
                $relationship,
                $members,
                $target,
                $actorParticipantId,
                $requestRole,
                $request['requested_lap_host_participant_id'] !== null ? (int)$request['requested_lap_host_participant_id'] : null,
                $resolvedLapSide !== null ? (string)$resolvedLapSide : null,
                $type === 'invitation' ? 'invitation-accepted' : 'join-request-approved'
            );
            if (empty($add['ok'])) return $add;
            $pdo->prepare(
                "UPDATE avatar_relationship_requests
                    SET status = 'accepted', active_request_key = NULL,
                        resolution_actor_participant_id = ?, resolution_reason = ?,
                        resolved_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                  WHERE id = ?"
            )->execute([
                $actorParticipantId,
                $type === 'invitation' ? 'invitation-accepted' : 'join-request-approved',
                (int)$request['id'],
            ]);
            avatar_relationship_emit_lifecycle_event(
                $pdo,
                $sessionId,
                'request-accepted',
                [
                    'id' => (string)$relationship['relationship_public_id'],
                    'version' => (int)$add['relationship_version'],
                    'status' => 'active',
                ]
            );
            avatar_relationship_emit_lifecycle_event(
                $pdo,
                $sessionId,
                'member-added',
                $add['event_relationship'],
                array_filter([
                    'request_outcome' => 'accepted',
                    'configuration_normalized' => $add['configuration_normalized'] ?? null,
                ], fn(mixed $value): bool => $value !== null)
            );
            $resolved = avatar_relationship_locked_request($pdo, $requestPublicId);
            return [
                'ok' => true,
                'request' => avatar_relationship_request_payload($resolved),
                'relationship' => $add['relationship'],
            ];
        }

        $status = $resolution === 'reject' ? 'rejected' : 'cancelled';
        $pdo->prepare(
            'UPDATE avatar_relationship_requests
                SET status = ?, active_request_key = NULL,
                    resolution_actor_participant_id = ?, resolution_reason = ?,
                    resolved_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
              WHERE id = ?'
        )->execute([$status, $actorParticipantId, 'request-' . $status, (int)$request['id']]);
        avatar_relationship_emit_lifecycle_event($pdo, $sessionId, 'request-' . $status, [
            'id' => (string)$relationship['relationship_public_id'],
            'version' => (int)$relationship['version'],
            'status' => 'active',
        ]);
        $resolved = avatar_relationship_locked_request($pdo, $requestPublicId);
        return ['ok' => true, 'request' => avatar_relationship_request_payload($resolved)];
    });
}

function avatar_relationship_set_join_policy(
    PDO $pdo,
    int $sessionId,
    int $actorParticipantId,
    string $relationshipPublicId,
    int $expectedVersion,
    string $joinPolicy
): array {
    return avatar_relationship_transaction($pdo, function() use (
        $pdo, $sessionId, $actorParticipantId, $relationshipPublicId, $expectedVersion, $joinPolicy
    ): array {
        if (!in_array($joinPolicy, ['approval-required', 'open'], true)) {
            return avatar_relationship_operation_error('RELATIONSHIP_JOIN_POLICY_REJECTED', 'Unknown join policy.', 'invalid-join-policy', 400);
        }
        $relationship = avatar_relationship_locked_row($pdo, $sessionId, $relationshipPublicId);
        if (!$relationship) return avatar_relationship_operation_error('RELATIONSHIP_NOT_FOUND', 'Relationship not found.', 'relationship-not-found', 404);
        if ((string)$relationship['status'] !== 'active') return avatar_relationship_operation_error('RELATIONSHIP_NOT_ACTIVE', 'Relationship is not active.', 'relationship-not-active', 409);
        if ($versionError = avatar_relationship_version_error($relationship, $expectedVersion)) return $versionError;
        $members = avatar_relationship_locked_members($pdo, (int)$relationship['id']);
        if (!avatar_relationship_permission_allows(avatar_relationship_member_from_rows($members, $actorParticipantId))) {
            return avatar_relationship_operation_error('RELATIONSHIP_PERMISSION_DENIED', 'You cannot change this join policy.', 'join-policy-permission-denied', 403);
        }
        if ((string)$relationship['join_policy'] === $joinPolicy) {
            return [
                'ok' => true,
                'idempotent' => true,
                'relationship' => avatar_relationship_payload($pdo, (int)$relationship['id'], $actorParticipantId),
            ];
        }
        $nextVersion = max(1, (int)$relationship['version']) + 1;
        $pdo->prepare(
            'UPDATE avatar_relationships
                SET join_policy = ?, version = ?, updated_at = CURRENT_TIMESTAMP
              WHERE id = ?'
        )->execute([$joinPolicy, $nextVersion, (int)$relationship['id']]);
        $viewerPayload = avatar_relationship_payload($pdo, (int)$relationship['id'], $actorParticipantId);
        $eventPayload = avatar_relationship_public_event_snapshot($pdo, (int)$relationship['id']);
        avatar_relationship_emit_lifecycle_event($pdo, $sessionId, 'join-policy-changed', $eventPayload);
        return ['ok' => true, 'relationship' => $viewerPayload];
    });
}

function avatar_relationship_set_lap_side(
    PDO $pdo,
    int $sessionId,
    int $actorParticipantId,
    string $relationshipPublicId,
    int $expectedVersion,
    string $operationId,
    string $lapSide
): array {
    return avatar_relationship_transaction($pdo, function() use (
        $pdo,
        $sessionId,
        $actorParticipantId,
        $relationshipPublicId,
        $expectedVersion,
        $operationId,
        $lapSide
    ): array {
        if (!preg_match('/^[A-Za-z0-9:_-]{8,160}$/', $operationId)) {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_LAP_SIDE_INVALID',
                'A valid lap-side operation is required.',
                'invalid-operation-id',
                400
            );
        }
        $normalizedSide = avatar_relationship_normalize_lap_side($lapSide);
        if ($normalizedSide === null) {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_LAP_SIDE_INVALID',
                'Choose an available lap side.',
                'invalid-lap-side',
                400
            );
        }
        $relationship = avatar_relationship_locked_row($pdo, $sessionId, $relationshipPublicId);
        if (!$relationship || (string)$relationship['status'] !== 'active'
            || (string)($relationship['divergence_status'] ?? 'synced') !== 'synced') {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_LAP_SIDE_CONFLICT',
                'That relationship is no longer available.',
                'relationship-unavailable',
                409
            );
        }

        $priorStmt = $pdo->prepare(
            "SELECT id, payload FROM events
              WHERE session_id = ? AND type = 'relationship'
                AND payload LIKE ? ORDER BY id DESC LIMIT 20"
        );
        $priorStmt->execute([$sessionId, '%"operation_id":"' . $operationId . '"%']);
        foreach ($priorStmt->fetchAll() as $prior) {
            $payload = json_decode((string)$prior['payload'], true) ?: [];
            if ((string)($payload['operation_id'] ?? '') !== $operationId
                || (string)($payload['relationship_id'] ?? '') !== $relationshipPublicId
                || (int)($payload['actor_participant_id'] ?? 0) !== $actorParticipantId
                || (string)($payload['action'] ?? '') !== 'lap-side-changed') {
                continue;
            }
            return [
                'ok' => true,
                'idempotent' => true,
                'event_id' => (int)$prior['id'],
                'operation_id' => $operationId,
                'relationship' => avatar_relationship_payload($pdo, (int)$relationship['id'], $actorParticipantId),
            ];
        }

        if ($versionError = avatar_relationship_version_error($relationship, $expectedVersion)) return $versionError;
        $members = avatar_relationship_locked_members($pdo, (int)$relationship['id']);
        $actor = avatar_relationship_member_from_rows($members, $actorParticipantId);
        if (!$actor || (string)$actor['relationship_role'] !== 'lap') {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_PERMISSION_DENIED',
                'Only the lap occupant can change this seat.',
                'lap-side-occupant-required',
                403
            );
        }
        $currentSide = avatar_relationship_normalize_lap_side($actor['lap_side'] ?? null);
        if ($currentSide === null || $currentSide === $normalizedSide) {
            return avatar_relationship_operation_error(
                'RELATIONSHIP_LAP_SIDE_CONFLICT',
                'Choose the other available lap side.',
                'lap-side-unchanged',
                409
            );
        }
        $hostId = (int)($actor['lap_host_participant_id'] ?? 0);
        $decision = avatar_relationship_validate_requested_role(
            $members,
            'lap',
            $hostId,
            $normalizedSide,
            $actorParticipantId
        );
        if (empty($decision['ok'])) return $decision;

        $nextVersion = max(1, (int)$relationship['version']) + 1;
        $update = $pdo->prepare(
            'UPDATE avatar_relationship_members
                SET lap_side = ?, updated_at = CURRENT_TIMESTAMP
              WHERE relationship_id = ? AND participant_id = ?
                AND membership_status = \'active\''
        );
        $update->execute([$normalizedSide, (int)$relationship['id'], $actorParticipantId]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Lap-side mutation updated an invalid member set.');
        }
        $actor['lap_side'] = $normalizedSide;
        $pdo->prepare(
            'UPDATE avatar_relationships SET version = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        )->execute([$nextVersion, (int)$relationship['id']]);
        avatar_relationship_insert_history(
            $pdo,
            (int)$relationship['id'],
            $actor,
            $actorParticipantId,
            'lap-side-changed',
            $nextVersion,
            'occupant-side-switch'
        );
        $relationship['version'] = $nextVersion;
        $members = avatar_relationship_locked_members($pdo, (int)$relationship['id']);
        avatar_relationship_refresh_legacy_projection_locked($pdo, $relationship, $members);
        $viewerPayload = avatar_relationship_payload($pdo, (int)$relationship['id'], $actorParticipantId);
        $eventPayload = avatar_relationship_public_event_snapshot($pdo, (int)$relationship['id']);
        avatar_relationship_emit_lifecycle_event(
            $pdo,
            $sessionId,
            'lap-side-changed',
            $eventPayload,
            [
                'operation_id' => $operationId,
                'actor_participant_id' => $actorParticipantId,
                'lap_host_participant_id' => $hostId,
                'lap_side' => $normalizedSide,
            ]
        );
        return [
            'ok' => true,
            'idempotent' => false,
            'event_id' => (int)$pdo->lastInsertId(),
            'operation_id' => $operationId,
            'relationship' => $viewerPayload,
        ];
    });
}

function avatar_relationship_insert_history(
    PDO $pdo,
    int $relationshipDbId,
    array $member,
    ?int $actorParticipantId,
    string $action,
    int $relationshipVersion,
    string $reason,
    ?string $membershipEndedAt = null
): void {
    $pdo->prepare(
        'INSERT INTO avatar_relationship_membership_history
            (relationship_id, participant_id, user_id, actor_participant_id, action, outcome,
             relationship_role, permission_role, member_order, membership_effective_at,
             membership_ended_at, visible_after_message_id, lap_host_participant_id, lap_side,
             relationship_version, reason)
         VALUES (?,?,?,?,?,\'applied\',?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $relationshipDbId,
        (int)$member['participant_id'],
        isset($member['user_id']) ? (int)$member['user_id'] : null,
        $actorParticipantId,
        $action,
        (string)($member['relationship_role'] ?? 'normal'),
        (string)($member['permission_role'] ?? 'member'),
        (int)($member['member_order'] ?? 0),
        (string)($member['membership_effective_at'] ?? $member['created_at'] ?? gmdate('Y-m-d H:i:s')),
        $membershipEndedAt,
        max(0, (int)($member['visible_after_message_id'] ?? 0)),
        isset($member['lap_host_participant_id']) ? (int)$member['lap_host_participant_id'] : null,
        avatar_relationship_normalize_lap_side($member['lap_side'] ?? null),
        $relationshipVersion,
        $reason,
    ]);
}

function avatar_relationship_close_pending_requests_locked(
    PDO $pdo,
    int $sessionId,
    array $relationship,
    string $reason
): int {
    $sql = "SELECT id FROM avatar_relationship_requests
             WHERE relationship_id = ? AND status = 'pending'
             ORDER BY id ASC";
    if (db_uses_mysql_syntax($pdo)) $sql .= ' FOR UPDATE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([(int)$relationship['id']]);
    $requestIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (!$requestIds) return 0;

    $update = $pdo->prepare(
        "UPDATE avatar_relationship_requests
            SET status = 'expired', active_request_key = NULL,
                resolution_reason = ?, resolved_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
          WHERE id = ? AND status = 'pending'"
    );
    $closed = 0;
    foreach ($requestIds as $requestId) {
        $update->execute([$reason, $requestId]);
        if ($update->rowCount() !== 1) continue;
        $closed++;
        avatar_relationship_emit_lifecycle_event($pdo, $sessionId, 'request-expired', [
            'id' => (string)$relationship['relationship_public_id'],
            'version' => max(1, (int)$relationship['version']),
            'status' => (string)$relationship['status'],
        ], ['resolution_reason' => $reason]);
    }
    return $closed;
}

function avatar_relationship_refresh_legacy_projection_locked(
    PDO $pdo,
    array $relationship,
    array $members
): void {
    $relationshipDbId = (int)$relationship['id'];
    $sessionId = (int)$relationship['session_id'];
    $memberIds = array_values(array_map(fn(array $member): int => (int)$member['participant_id'], $members));
    $memberById = [];
    foreach ($members as $member) $memberById[(int)$member['participant_id']] = $member;

    $legacyInitiatorId = (int)($relationship['legacy_initiator_participant_id'] ?? 0);
    $legacyTargetId = (int)($relationship['legacy_target_participant_id'] ?? 0);
    if (count($members) > 2 && isset($memberById[$legacyInitiatorId], $memberById[$legacyTargetId])) {
        return;
    }

    $clearIds = array_values(array_unique(array_filter(
        array_merge($memberIds, [$legacyInitiatorId, $legacyTargetId]),
        fn(int $participantId): bool => $participantId > 0
    )));
    if ($clearIds) {
        $placeholders = implode(',', array_fill(0, count($clearIds), '?'));
        $pdo->prepare(
            "UPDATE participants
                SET linked_to_participant_id = NULL, link_mode = 'normal'
              WHERE session_id = ?
                AND (id IN ($placeholders) OR linked_to_participant_id IN ($placeholders))"
        )->execute(array_merge([$sessionId], $clearIds, $clearIds));
    }

    if (count($members) !== 2) {
        $pdo->prepare(
            'UPDATE avatar_relationships
                SET legacy_initiator_participant_id = NULL,
                    legacy_target_participant_id = NULL, legacy_link_key = NULL,
                    updated_at = CURRENT_TIMESTAMP
              WHERE id = ?'
        )->execute([$relationshipDbId]);
        return;
    }

    $orderedMembers = array_values($members);
    usort($orderedMembers, fn(array $first, array $second): int =>
        [(int)$first['member_order'], (int)$first['id']] <=> [(int)$second['member_order'], (int)$second['id']]
    );
    $lapMembers = array_values(array_filter(
        $orderedMembers,
        fn(array $member): bool => (string)$member['relationship_role'] === 'lap'
    ));
    $normalMembers = array_values(array_filter(
        $orderedMembers,
        fn(array $member): bool => (string)$member['relationship_role'] === 'normal'
    ));

    $mode = count($lapMembers) === 1 && count($normalMembers) === 1 ? 'lap' : 'normal';
    if ($mode === 'lap') {
        $initiator = $lapMembers[0];
        $target = $normalMembers[0];
        $pdo->prepare(
            'UPDATE avatar_relationship_members
                SET lap_host_participant_id = CASE WHEN participant_id = ? THEN ? ELSE NULL END,
                    lap_side = CASE WHEN participant_id = ? THEN COALESCE(lap_side, \'bottom-right\') ELSE NULL END,
                    member_role = CASE WHEN participant_id = ? THEN \'initiator\' ELSE \'target\' END,
                    updated_at = CURRENT_TIMESTAMP
              WHERE relationship_id = ?'
        )->execute([
            (int)$initiator['participant_id'],
            (int)$target['participant_id'],
            (int)$initiator['participant_id'],
            $relationshipDbId,
        ]);
    } else {
        $creatorId = (int)($relationship['creator_participant_id'] ?? 0);
        $initiator = $memberById[$creatorId] ?? $orderedMembers[0];
        $target = (int)$orderedMembers[0]['participant_id'] === (int)$initiator['participant_id']
            ? $orderedMembers[1]
            : $orderedMembers[0];
        $pdo->prepare(
            "UPDATE avatar_relationship_members
                SET lap_host_participant_id = NULL, lap_side = NULL,
                    member_role = CASE WHEN participant_id = ? THEN 'initiator' ELSE 'target' END,
                    updated_at = CURRENT_TIMESTAMP
              WHERE relationship_id = ?"
        )->execute([(int)$initiator['participant_id'], $relationshipDbId]);
    }

    $initiatorId = (int)$initiator['participant_id'];
    $targetId = (int)$target['participant_id'];
    $capability = avatar_relationship_capability($mode);
    $metadata = avatar_relationship_metadata(
        $initiatorId,
        $targetId,
        $mode,
        (string)$relationship['relationship_public_id']
    );
    $pdo->prepare(
        "UPDATE participants
            SET linked_to_participant_id = ?, link_mode = ?
          WHERE id = ? AND session_id = ?"
    )->execute([$targetId, $mode, $initiatorId, $sessionId]);
    $pdo->prepare(
        'UPDATE avatar_relationships
            SET legacy_initiator_participant_id = ?, legacy_target_participant_id = ?,
                legacy_link_key = ?, mode = ?, capability = ?, geometry_strategy = ?,
                metadata_json = ?, updated_at = CURRENT_TIMESTAMP
          WHERE id = ?'
    )->execute([
        $initiatorId,
        $targetId,
        link_key_for($initiatorId, $targetId),
        $mode,
        $capability['capability'],
        $capability['geometry_strategy'],
        json_encode($metadata, JSON_UNESCAPED_SLASHES),
        $relationshipDbId,
    ]);
}

function avatar_relationship_emit_legacy_projection_events(
    PDO $pdo,
    int $sessionId,
    array $participantIds,
    array $relationship
): void {
    $participantIds = array_values(array_unique(array_filter(
        array_map('intval', $participantIds),
        fn(int $participantId): bool => $participantId > 0
    )));
    if (!$participantIds) return;
    $placeholders = implode(',', array_fill(0, count($participantIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, linked_to_participant_id, link_mode
           FROM participants
          WHERE session_id = ? AND id IN ($placeholders)
          ORDER BY id ASC"
    );
    $stmt->execute(array_merge([$sessionId], $participantIds));
    foreach ($stmt->fetchAll() as $participant) {
        emit_event($pdo, $sessionId, 'link', [
            'participant_id' => (int)$participant['id'],
            'linked_to' => $participant['linked_to_participant_id'] !== null
                ? (int)$participant['linked_to_participant_id']
                : null,
            'link_mode' => avatar_relationship_mode((string)$participant['link_mode']),
            'relationship_removed' => false,
            'relationship_id' => (string)$relationship['id'],
            'relationship_version' => (int)$relationship['version'],
            'relationship_status' => (string)$relationship['status'],
            'relationship' => $relationship,
        ]);
    }
}

function avatar_relationship_lifecycle_idempotent(
    PDO $pdo,
    int $relationshipDbId,
    int $actorParticipantId,
    int $targetParticipantId,
    array $actions
): bool {
    $placeholders = implode(',', array_fill(0, count($actions), '?'));
    $stmt = $pdo->prepare(
        "SELECT 1 FROM avatar_relationship_membership_history
          WHERE relationship_id = ? AND participant_id = ?
            AND actor_participant_id = ? AND action IN ($placeholders)
          LIMIT 1"
    );
    $stmt->execute(array_merge(
        [$relationshipDbId, $targetParticipantId, $actorParticipantId],
        $actions
    ));
    return (bool)$stmt->fetchColumn();
}

function avatar_relationship_oldest_normal_member(array $members, ?string $permissionRole = null): ?array {
    $eligible = array_values(array_filter(
        $members,
        fn(array $member): bool => (string)$member['relationship_role'] === 'normal'
            && ($permissionRole === null || (string)$member['permission_role'] === $permissionRole)
    ));
    if (!$eligible) return null;
    usort($eligible, fn(array $first, array $second): int =>
        [
            (string)($first['membership_effective_at'] ?? $first['created_at'] ?? ''),
            (int)$first['member_order'],
            (int)$first['id'],
        ] <=> [
            (string)($second['membership_effective_at'] ?? $second['created_at'] ?? ''),
            (int)$second['member_order'],
            (int)$second['id'],
        ]
    );
    return $eligible[0];
}

function avatar_relationship_dissolve_locked(
    PDO $pdo,
    int $sessionId,
    array $relationship,
    array $members,
    int $actorParticipantId,
    string $reason,
    array $memberOutcomes = [],
    array $dependentRemovalEvents = []
): array {
    $nextVersion = max(1, (int)$relationship['version']) + 1;
    $endedAt = gmdate('Y-m-d H:i:s');
    foreach ($members as $member) {
        $memberOutcome = $memberOutcomes[(int)$member['participant_id']] ?? [];
        avatar_relationship_insert_history(
            $pdo,
            (int)$relationship['id'],
            $member,
            $actorParticipantId,
            (string)($memberOutcome['action'] ?? 'group-dissolved'),
            $nextVersion,
            (string)($memberOutcome['reason'] ?? $reason),
            $endedAt
        );
    }
    avatar_relationship_refresh_legacy_projection_locked($pdo, $relationship, []);
    $pdo->prepare('DELETE FROM avatar_relationship_members WHERE relationship_id = ?')
        ->execute([(int)$relationship['id']]);
    $pdo->prepare(
        "UPDATE avatar_relationships
            SET version = ?, status = 'dissolved', creator_participant_id = NULL,
                legacy_initiator_participant_id = NULL,
                legacy_target_participant_id = NULL, legacy_link_key = NULL,
                divergence_status = 'synced', dissolved_at = ?,
                updated_at = CURRENT_TIMESTAMP
          WHERE id = ?"
    )->execute([$nextVersion, $endedAt, (int)$relationship['id']]);
    $relationship['version'] = $nextVersion;
    $relationship['status'] = 'dissolved';
    avatar_relationship_close_pending_requests_locked($pdo, $sessionId, $relationship, 'relationship-dissolved');
    $tombstone = avatar_relationship_payload($pdo, (int)$relationship['id'], $actorParticipantId);
    $eventTombstone = avatar_relationship_public_event_snapshot($pdo, (int)$relationship['id']);
    avatar_relationship_emit_lifecycle_event(
        $pdo,
        $sessionId,
        'relationship-dissolved',
        $eventTombstone,
        ['resolution_reason' => $reason]
    );
    foreach ($dependentRemovalEvents as $removalEvent) {
        avatar_relationship_emit_lifecycle_event(
            $pdo,
            $sessionId,
            'member-removed',
            $eventTombstone,
            [
                'target_participant_id' => (int)$removalEvent['participant_id'],
                'prior_relationship_role' => 'lap',
                'resolution_reason' => (string)$removalEvent['reason'],
            ]
        );
    }
    foreach ($members as $member) {
        emit_event($pdo, $sessionId, 'link', [
            'participant_id' => (int)$member['participant_id'],
            'linked_to' => null,
            'link_mode' => 'normal',
            'relationship_removed' => true,
            'relationship_id' => (string)$relationship['relationship_public_id'],
            'relationship_version' => $nextVersion,
            'relationship_status' => 'dissolved',
            'relationship' => $eventTombstone,
        ]);
    }
    return [
        'ok' => true,
        'dissolved' => true,
        'relationship' => $tombstone,
    ];
}

function avatar_relationship_dissolve(
    PDO $pdo,
    int $sessionId,
    int $actorParticipantId,
    string $relationshipPublicId,
    int $expectedVersion
): array {
    return avatar_relationship_transaction($pdo, function() use (
        $pdo, $sessionId, $actorParticipantId, $relationshipPublicId, $expectedVersion
    ): array {
        $relationship = avatar_relationship_locked_row($pdo, $sessionId, $relationshipPublicId);
        if (!$relationship) return avatar_relationship_operation_error('RELATIONSHIP_NOT_FOUND', 'Relationship not found.', 'relationship-not-found', 404);
        if ((string)$relationship['status'] === 'dissolved') {
            $stmt = $pdo->prepare(
                "SELECT 1 FROM avatar_relationship_membership_history
                  WHERE relationship_id = ? AND actor_participant_id = ?
                    AND action = 'group-dissolved' LIMIT 1"
            );
            $stmt->execute([(int)$relationship['id'], $actorParticipantId]);
            if ($stmt->fetchColumn()) {
                return [
                    'ok' => true,
                    'idempotent' => true,
                    'dissolved' => true,
                    'relationship' => avatar_relationship_payload($pdo, (int)$relationship['id'], $actorParticipantId),
                ];
            }
            return avatar_relationship_operation_error('RELATIONSHIP_DISSOLVED', 'Relationship is dissolved.', 'relationship-dissolved', 409);
        }
        if ((string)$relationship['status'] !== 'active') return avatar_relationship_operation_error('RELATIONSHIP_NOT_ACTIVE', 'Relationship is not active.', 'relationship-not-active', 409);
        if ($versionError = avatar_relationship_version_error($relationship, $expectedVersion)) return $versionError;
        $members = avatar_relationship_locked_members($pdo, (int)$relationship['id']);
        $actorMember = avatar_relationship_member_from_rows($members, $actorParticipantId);
        if (!avatar_relationship_permission_allows($actorMember)) {
            return avatar_relationship_operation_error('RELATIONSHIP_PERMISSION_DENIED', 'You cannot dissolve this relationship.', 'relationship-dissolve-permission-denied', 403);
        }
        return avatar_relationship_dissolve_locked(
            $pdo,
            $sessionId,
            $relationship,
            $members,
            $actorParticipantId,
            'relationship-dissolved-by-member'
        );
    });
}

function avatar_relationship_force_participant_departure(
    PDO $pdo,
    int $sessionId,
    int $participantId,
    string $reason
): array {
    $active = avatar_relationship_active_for_participant($pdo, $sessionId, $participantId, $participantId);
    if (!$active) return ['ok' => true, 'idempotent' => true, 'relationship' => null];
    return avatar_relationship_mutate_member(
        $pdo,
        $sessionId,
        $participantId,
        (string)$active['id'],
        (int)$active['version'],
        'leave',
        0,
        $reason
    );
}

function avatar_relationship_mutate_member(
    PDO $pdo,
    int $sessionId,
    int $actorParticipantId,
    string $relationshipPublicId,
    int $expectedVersion,
    string $action,
    int $targetParticipantId = 0,
    ?string $departureReason = null
): array {
    return avatar_relationship_transaction($pdo, function() use (
        $pdo, $sessionId, $actorParticipantId, $relationshipPublicId,
        $expectedVersion, $action, $targetParticipantId, $departureReason
    ): array {
        if (!in_array($action, ['leave', 'remove', 'promote', 'demote'], true)) {
            return avatar_relationship_operation_error('RELATIONSHIP_CONFLICT', 'Unknown membership action.', 'unknown-membership-action', 400);
        }
        $relationship = avatar_relationship_locked_row($pdo, $sessionId, $relationshipPublicId);
        if (!$relationship) return avatar_relationship_operation_error('RELATIONSHIP_NOT_FOUND', 'Relationship not found.', 'relationship-not-found', 404);
        if ((string)$relationship['status'] === 'dissolved') {
            $terminalTargetId = $action === 'leave' ? $actorParticipantId : $targetParticipantId;
            $terminalActions = $action === 'leave'
                ? ['member-left', 'group-dissolved']
                : ['member-removed', 'group-dissolved'];
            if (in_array($action, ['leave', 'remove'], true)
                && avatar_relationship_lifecycle_idempotent(
                    $pdo,
                    (int)$relationship['id'],
                    $actorParticipantId,
                    $terminalTargetId,
                    $terminalActions
                )) {
                return [
                    'ok' => true,
                    'idempotent' => true,
                    'dissolved' => true,
                    'relationship' => avatar_relationship_payload($pdo, (int)$relationship['id'], $actorParticipantId),
                ];
            }
            return avatar_relationship_operation_error('RELATIONSHIP_DISSOLVED', 'Relationship is dissolved.', 'relationship-dissolved', 409);
        }
        if ((string)$relationship['status'] !== 'active') return avatar_relationship_operation_error('RELATIONSHIP_NOT_ACTIVE', 'Relationship is not active.', 'relationship-not-active', 409);
        if ($versionError = avatar_relationship_version_error($relationship, $expectedVersion)) return $versionError;

        $members = avatar_relationship_locked_members($pdo, (int)$relationship['id']);
        $actorMember = avatar_relationship_member_from_rows($members, $actorParticipantId);
        $targetParticipantId = $action === 'leave' ? $actorParticipantId : $targetParticipantId;
        $targetMember = avatar_relationship_member_from_rows($members, $targetParticipantId);
        if (!$targetMember) {
            $idempotentActions = $action === 'leave' ? ['member-left'] : ['member-removed'];
            if (in_array($action, ['leave', 'remove'], true)
                && avatar_relationship_lifecycle_idempotent(
                    $pdo,
                    (int)$relationship['id'],
                    $actorParticipantId,
                    $targetParticipantId,
                    $idempotentActions
                )) {
                return [
                    'ok' => true,
                    'idempotent' => true,
                    'relationship' => avatar_relationship_payload($pdo, (int)$relationship['id'], $actorParticipantId),
                ];
            }
            return avatar_relationship_operation_error('RELATIONSHIP_MEMBER_NOT_FOUND', 'Relationship member not found.', 'relationship-member-not-found', 404);
        }
        if (!$actorMember) {
            return avatar_relationship_operation_error('RELATIONSHIP_PERMISSION_DENIED', 'You cannot change this relationship.', 'relationship-actor-not-member', 403);
        }

        if ($action !== 'leave' && !avatar_relationship_permission_allows($actorMember)) {
            return avatar_relationship_operation_error('RELATIONSHIP_PERMISSION_DENIED', 'You cannot change relationship members.', 'membership-permission-denied', 403);
        }

        if ($action !== 'leave' && (string)$targetMember['permission_role'] === 'creator') {
            return avatar_relationship_operation_error('RELATIONSHIP_CREATOR_PROTECTED', 'The relationship creator is protected.', 'relationship-creator-protected', 409);
        }
        if ($action === 'promote' && (string)$targetMember['permission_role'] === 'manager') {
            return [
                'ok' => true,
                'idempotent' => true,
                'relationship' => avatar_relationship_payload($pdo, (int)$relationship['id'], $actorParticipantId),
            ];
        }
        if ($action === 'demote' && (string)$targetMember['permission_role'] === 'member') {
            return [
                'ok' => true,
                'idempotent' => true,
                'relationship' => avatar_relationship_payload($pdo, (int)$relationship['id'], $actorParticipantId),
            ];
        }
        if ($action === 'promote' && (string)$targetMember['permission_role'] !== 'member') {
            return avatar_relationship_operation_error('RELATIONSHIP_MEMBER_ALREADY_MANAGER', 'That member cannot be promoted.', 'relationship-member-already-manager', 409);
        }
        if ($action === 'demote' && (string)$targetMember['permission_role'] !== 'manager') {
            return avatar_relationship_operation_error('RELATIONSHIP_MEMBER_NOT_MANAGER', 'That member is not a manager.', 'relationship-member-not-manager', 409);
        }

        $nextVersion = max(1, (int)$relationship['version']) + 1;
        if (in_array($action, ['promote', 'demote'], true)) {
            $nextPermissionRole = $action === 'promote' ? 'manager' : 'member';
            $pdo->prepare(
                'UPDATE avatar_relationship_members
                    SET permission_role = ?, updated_at = CURRENT_TIMESTAMP
                  WHERE id = ?'
            )->execute([$nextPermissionRole, (int)$targetMember['id']]);
            $pdo->prepare(
                'UPDATE avatar_relationships SET version = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
            )->execute([$nextVersion, (int)$relationship['id']]);
            avatar_relationship_insert_history(
                $pdo,
                (int)$relationship['id'],
                $targetMember,
                $actorParticipantId,
                'permission-' . ($action === 'promote' ? 'promoted' : 'demoted'),
                $nextVersion,
                'permission-role:' . (string)$targetMember['permission_role'] . '->' . $nextPermissionRole
            );
            $relationship['version'] = $nextVersion;
            avatar_relationship_close_pending_requests_locked($pdo, $sessionId, $relationship, 'relationship-permission-changed');
            $viewerPayload = avatar_relationship_payload($pdo, (int)$relationship['id'], $actorParticipantId);
            $eventPayload = avatar_relationship_public_event_snapshot($pdo, (int)$relationship['id']);
            avatar_relationship_emit_lifecycle_event($pdo, $sessionId, 'permission-changed', $eventPayload, [
                'target_participant_id' => $targetParticipantId,
            ]);
            return ['ok' => true, 'relationship' => $viewerPayload];
        }

        $hostedLapMembers = array_values(array_filter(
            $members,
            fn(array $member): bool => (int)($member['lap_host_participant_id'] ?? 0) === $targetParticipantId
                && (int)$member['participant_id'] !== $targetParticipantId
        ));
        $departingMembers = array_merge([$targetMember], $hostedLapMembers);
        $departingIds = array_map(
            fn(array $member): int => (int)$member['participant_id'],
            $departingMembers
        );
        $remainingMembers = array_values(array_filter(
            $members,
            fn(array $member): bool => !in_array((int)$member['participant_id'], $departingIds, true)
        ));
        $targetHistoryAction = $action === 'leave' ? 'member-left' : 'member-removed';
        $targetHistoryReason = $departureReason ?: $targetHistoryAction;
        $dependentRemovalReason = $action === 'leave' ? 'lap-host-left' : 'lap-host-removed';
        $dissolutionMemberOutcomes = [];
        $dependentRemovalEvents = [];
        if ($hostedLapMembers) {
            $dissolutionMemberOutcomes[$targetParticipantId] = [
                'action' => $targetHistoryAction,
                'reason' => $targetHistoryReason,
            ];
            foreach ($hostedLapMembers as $hostedLapMember) {
                $dependentParticipantId = (int)$hostedLapMember['participant_id'];
                $dissolutionMemberOutcomes[$dependentParticipantId] = [
                    'action' => 'member-removed',
                    'reason' => $dependentRemovalReason,
                ];
                $dependentRemovalEvents[] = [
                    'participant_id' => $dependentParticipantId,
                    'reason' => $dependentRemovalReason,
                ];
            }
        }
        if (count($remainingMembers) < 2) {
            return avatar_relationship_dissolve_locked(
                $pdo,
                $sessionId,
                $relationship,
                $members,
                $actorParticipantId,
                $departureReason ?: ($action === 'leave' ? 'minimum-members-after-leave' : 'minimum-members-after-removal'),
                $dissolutionMemberOutcomes,
                $dependentRemovalEvents
            );
        }

        $successor = null;
        if ((string)$targetMember['permission_role'] === 'creator') {
            $successor = avatar_relationship_oldest_normal_member($remainingMembers, 'manager')
                ?: avatar_relationship_oldest_normal_member($remainingMembers);
            if (!$successor) {
                return avatar_relationship_dissolve_locked(
                    $pdo,
                    $sessionId,
                    $relationship,
                    $members,
                    $actorParticipantId,
                    $departureReason ?: 'creator-departed-without-successor',
                    $dissolutionMemberOutcomes,
                    $dependentRemovalEvents
                );
            }
        }

        $endedAt = gmdate('Y-m-d H:i:s');
        avatar_relationship_insert_history(
            $pdo,
            (int)$relationship['id'],
            $targetMember,
            $actorParticipantId,
            $targetHistoryAction,
            $nextVersion,
            $targetHistoryReason,
            $endedAt
        );
        foreach ($hostedLapMembers as $hostedLapMember) {
            avatar_relationship_insert_history(
                $pdo,
                (int)$relationship['id'],
                $hostedLapMember,
                $actorParticipantId,
                'member-removed',
                $nextVersion,
                $dependentRemovalReason,
                $endedAt
            );
        }
        if ($successor) {
            avatar_relationship_insert_history(
                $pdo,
                (int)$relationship['id'],
                $successor,
                $actorParticipantId,
                'creator-transferred',
                $nextVersion,
                'creator-succession:' . (string)$successor['permission_role'] . '->creator'
            );
            $pdo->prepare(
                "UPDATE avatar_relationship_members
                    SET permission_role = 'creator', updated_at = CURRENT_TIMESTAMP
                  WHERE id = ?"
            )->execute([(int)$successor['id']]);
        }
        $departingPlaceholders = implode(',', array_fill(0, count($departingIds), '?'));
        $pdo->prepare(
            "UPDATE participants
                SET linked_to_participant_id = NULL, link_mode = 'normal'
              WHERE session_id = ?
                AND (id IN ($departingPlaceholders) OR linked_to_participant_id IN ($departingPlaceholders))"
        )->execute(array_merge([$sessionId], $departingIds, $departingIds));
        $pdo->prepare(
            "DELETE FROM avatar_relationship_members
              WHERE relationship_id = ? AND participant_id IN ($departingPlaceholders)"
        )->execute(array_merge([(int)$relationship['id']], $departingIds));
        $formationNormalization = (string)$targetMember['relationship_role'] === 'normal'
            ? avatar_relationship_normalize_permanently_invalid_formation_locked(
                $pdo,
                $relationship,
                $remainingMembers
            )
            : null;
        $pdo->prepare(
            'UPDATE avatar_relationships
                SET version = ?, creator_participant_id = ?, updated_at = CURRENT_TIMESTAMP
              WHERE id = ?'
        )->execute([
            $nextVersion,
            $successor ? (int)$successor['participant_id'] : (int)$relationship['creator_participant_id'],
            (int)$relationship['id'],
        ]);
        $relationship['version'] = $nextVersion;
        if ($successor) $relationship['creator_participant_id'] = (int)$successor['participant_id'];
        avatar_relationship_refresh_legacy_projection_locked($pdo, $relationship, $remainingMembers);
        avatar_relationship_close_pending_requests_locked($pdo, $sessionId, $relationship, 'relationship-membership-changed');
        $viewerPayload = avatar_relationship_payload($pdo, (int)$relationship['id'], $actorParticipantId);
        $eventPayload = avatar_relationship_public_event_snapshot($pdo, (int)$relationship['id']);
        avatar_relationship_emit_lifecycle_event(
            $pdo,
            $sessionId,
            $action === 'leave' ? 'member-left' : 'member-removed',
            $eventPayload,
            [
                'target_participant_id' => $targetParticipantId,
                'prior_relationship_role' => (string)$targetMember['relationship_role'],
                'resolution_reason' => $targetHistoryReason,
                'configuration_normalized' => $formationNormalization,
            ]
        );
        foreach ($hostedLapMembers as $hostedLapMember) {
            avatar_relationship_emit_lifecycle_event(
                $pdo,
                $sessionId,
                'member-removed',
                $eventPayload,
                [
                    'target_participant_id' => (int)$hostedLapMember['participant_id'],
                    'prior_relationship_role' => 'lap',
                    'resolution_reason' => $dependentRemovalReason,
                ]
            );
        }
        avatar_relationship_emit_legacy_projection_events(
            $pdo,
            $sessionId,
            array_merge($departingIds, array_map(
                fn(array $member): int => (int)$member['participant_id'],
                $remainingMembers
            )),
            $eventPayload
        );
        if ($successor) {
            avatar_relationship_emit_lifecycle_event(
                $pdo,
                $sessionId,
                'creator-transferred',
                $eventPayload,
                ['creator_participant_id' => (int)$successor['participant_id']]
            );
        }
        return ['ok' => true, 'relationship' => $viewerPayload];
    });
}

function avatar_relationship_requests_for_actor(PDO $pdo, int $sessionId, int $actorParticipantId): array {
    $stmt = $pdo->prepare(
        "SELECT arr.*, ar.relationship_public_id
           FROM avatar_relationship_requests arr
           JOIN avatar_relationships ar ON ar.id = arr.relationship_id
          WHERE ar.session_id = ?
            AND (
              arr.requester_participant_id = ?
              OR arr.target_participant_id = ?
              OR arr.relationship_id IN (
                SELECT arm.relationship_id
                  FROM avatar_relationship_members arm
                 WHERE arm.participant_id = ? AND arm.membership_status = 'active'
                   AND arm.permission_role IN ('creator', 'manager')
              )
            )
          ORDER BY arr.id DESC
          LIMIT 200"
    );
    $stmt->execute([$sessionId, $actorParticipantId, $actorParticipantId, $actorParticipantId]);
    return array_map('avatar_relationship_request_payload', $stmt->fetchAll());
}

function avatar_relationship_count(PDO $pdo, ?int $sessionId = null): array {
    $suffix = " WHERE status = 'active'" . ($sessionId !== null ? ' AND session_id = ?' : '');
    $params = $sessionId !== null ? [$sessionId] : [];
    $relationshipStmt = $pdo->prepare('SELECT COUNT(*) FROM avatar_relationships' . $suffix);
    $relationshipStmt->execute($params);
    $memberSql = "SELECT COUNT(*)
                    FROM avatar_relationship_members arm
                    JOIN avatar_relationships ar ON ar.id = arm.relationship_id
                   WHERE ar.status = 'active' AND arm.membership_status = 'active'" . ($sessionId !== null ? ' AND ar.session_id = ?' : '');
    $memberStmt = $pdo->prepare($memberSql);
    $memberStmt->execute($params);
    $legacyStmt = $pdo->prepare('SELECT COUNT(*) FROM participants WHERE linked_to_participant_id IS NOT NULL' . ($sessionId !== null ? ' AND session_id = ?' : ''));
    $legacyStmt->execute($params);
    return [
        'relationships' => (int)$relationshipStmt->fetchColumn(),
        'members' => (int)$memberStmt->fetchColumn(),
        'legacy_edges' => (int)$legacyStmt->fetchColumn(),
    ];
}

function avatar_relationship_decode_json(?string $json): array {
    if ($json === null || trim($json) === '') return ['valid' => true, 'value' => null];
    $decoded = json_decode($json, true);
    return [
        'valid' => json_last_error() === JSON_ERROR_NONE,
        'value' => $decoded,
        'error' => json_last_error_msg(),
    ];
}

function avatar_relationship_repair_result(bool $apply, string $reason, array $context, string $policy, string $status = 'repairable'): array {
    return [
        'reason' => $reason,
        'policy' => $policy,
        'status' => $status,
        'repair_mode' => $apply ? 'repair' : 'dry_run',
    ] + $context;
}

function avatar_relationship_session_ids(PDO $pdo, ?int $sessionId = null): array {
    if ($sessionId !== null) {
        $stmt = $pdo->prepare('SELECT id FROM room_sessions WHERE id = ? LIMIT 1');
        $stmt->execute([$sessionId]);
        return $stmt->fetchColumn() ? [$sessionId] : [];
    }
    return array_map(fn(array $row): int => (int)$row['id'], $pdo->query('SELECT id FROM room_sessions ORDER BY id ASC')->fetchAll());
}

function avatar_relationship_legacy_audit(PDO $pdo, int $sessionId): array {
    $participantsStmt = $pdo->prepare('SELECT id, linked_to_participant_id, link_mode FROM participants WHERE session_id = ? ORDER BY id ASC');
    $participantsStmt->execute([$sessionId]);
    $participants = [];
    foreach ($participantsStmt->fetchAll() as $row) {
        $participants[(int)$row['id']] = [
            'id' => (int)$row['id'],
            'linked_to' => $row['linked_to_participant_id'] !== null ? (int)$row['linked_to_participant_id'] : null,
            'mode' => (string)($row['link_mode'] ?? 'normal'),
        ];
    }

    $edges = [];
    $pairCounts = [];
    foreach ($participants as $participant) {
        if (!$participant['linked_to']) continue;
        $legacyKey = link_key_for((int)$participant['id'], (int)$participant['linked_to']);
        $edge = [
            'participant_id' => (int)$participant['id'],
            'target_participant_id' => (int)$participant['linked_to'],
            'mode' => (string)$participant['mode'],
            'normalized_mode' => avatar_relationship_mode((string)$participant['mode']),
            'legacy_link_key' => $legacyKey,
            'relationship_id' => avatar_relationship_public_id_for((int)$participant['id'], (int)$participant['linked_to']),
        ];
        $edges[] = $edge;
        $pairCounts[$legacyKey] = ($pairCounts[$legacyKey] ?? 0) + 1;
    }

    $valid = [];
    $invalid = [];
    foreach ($edges as $edge) {
        $initiator = (int)$edge['participant_id'];
        $target = (int)$edge['target_participant_id'];
        $context = ['session_id' => $sessionId] + $edge;
        if ($target === $initiator) {
            $invalid[] = avatar_relationship_repair_result(false, 'invalid_self_legacy_link', $context, 'clear invalid self-link legacy projection');
            continue;
        }
        if (empty($participants[$target])) {
            $invalid[] = avatar_relationship_repair_result(false, 'missing_legacy_target_participant', $context, 'clear invalid legacy edge referencing a missing participant');
            continue;
        }
        if (($pairCounts[$edge['legacy_link_key']] ?? 0) > 1) {
            $invalid[] = avatar_relationship_repair_result(false, 'ambiguous_duplicate_legacy_pair', $context, 'operator review required; duplicate directed legacy pair is not guessed', 'skipped');
            continue;
        }
        $targetLinkedTo = $participants[$target]['linked_to'] ?? null;
        if ($targetLinkedTo !== null) {
            $invalid[] = avatar_relationship_repair_result(false, 'conflicting_legacy_edge', $context + ['target_linked_to_participant_id' => $targetLinkedTo], 'operator review required; conflicting legacy graph is not guessed', 'skipped');
            continue;
        }
        $valid[] = $edge;
    }

    return [
        'participants' => $participants,
        'valid_edges' => $valid,
        'invalid_edges' => $invalid,
    ];
}

function avatar_relationship_persisted_rows(PDO $pdo, ?int $sessionId = null): array {
    $sql = 'SELECT ar.*, rs.id AS relationship_session_exists
              FROM avatar_relationships ar
              LEFT JOIN room_sessions rs ON rs.id = ar.session_id';
    $params = [];
    if ($sessionId !== null) {
        $sql .= ' WHERE ar.session_id = ?';
        $params[] = $sessionId;
    }
    $sql .= ' ORDER BY ar.session_id ASC, ar.id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $memberStmt = $pdo->prepare(
            'SELECT arm.*, p.session_id AS participant_session_id
               FROM avatar_relationship_members arm
               LEFT JOIN participants p ON p.id = arm.participant_id
              WHERE arm.relationship_id = ?
              ORDER BY arm.member_order ASC, arm.id ASC'
        );
        $memberStmt->execute([(int)$row['id']]);
        $row['members'] = $memberStmt->fetchAll();
        $rows[] = $row;
    }
    return $rows;
}

function avatar_relationship_record_context(array $relationship): array {
    return [
        'session_id' => isset($relationship['session_id']) ? (int)$relationship['session_id'] : null,
        'relationship_db_id' => isset($relationship['id']) ? (int)$relationship['id'] : null,
        'relationship_id' => (string)($relationship['relationship_public_id'] ?? ''),
        'legacy_link_key' => $relationship['legacy_link_key'] ?? null,
    ];
}

function avatar_relationship_delete_records(PDO $pdo, array $relationshipIds): int {
    $ids = array_values(array_unique(array_filter(array_map('intval', $relationshipIds), fn(int $id): bool => $id > 0)));
    if (!$ids) return 0;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("DELETE FROM avatar_relationship_members WHERE relationship_id IN ($placeholders)")->execute($ids);
    $pdo->prepare("DELETE FROM avatar_relationships WHERE id IN ($placeholders)")->execute($ids);
    return count($ids);
}

function avatar_relationship_orphaned_members(PDO $pdo, ?int $sessionId = null): array {
    $sql = 'SELECT arm.id, arm.relationship_id, arm.participant_id, arm.member_role, arm.member_order
              FROM avatar_relationship_members arm
              LEFT JOIN avatar_relationships ar ON ar.id = arm.relationship_id
             WHERE ar.id IS NULL';
    $params = [];
    if ($sessionId !== null) {
        $sql .= ' AND 1 = 0';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function avatar_relationship_delete_orphaned_members(PDO $pdo, array $memberIds): int {
    $ids = array_values(array_unique(array_filter(array_map('intval', $memberIds), fn(int $id): bool => $id > 0)));
    if (!$ids) return 0;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("DELETE FROM avatar_relationship_members WHERE id IN ($placeholders)")->execute($ids);
    return count($ids);
}

function avatar_relationship_repair(PDO $pdo, array $options = []): array {
    $apply = !empty($options['apply']);
    $sessionId = isset($options['session_id']) && $options['session_id'] !== null && $options['session_id'] !== ''
        ? (int)$options['session_id']
        : null;
    $beforeCounts = avatar_relationship_count($pdo, $sessionId);
    $actions = [];
    $reasonCounts = [];
    $repaired = 0;
    $skipped = 0;
    $removed = 0;
    $createdOrSynced = 0;
    $normalized = 0;
    $sessions = avatar_relationship_session_ids($pdo, $sessionId);
    $validEdgesBySession = [];
    $validLegacyKeys = [];
    $validEdgesByKey = [];

    $recordAction = function(array $action) use (&$actions, &$reasonCounts, &$repaired, &$skipped): void {
        $actions[] = $action;
        $reason = (string)($action['reason'] ?? 'unknown');
        $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
        if (($action['status'] ?? '') === 'skipped') $skipped++;
        if (in_array(($action['status'] ?? ''), ['repaired', 'would_repair'], true)) $repaired++;
    };

    if ($apply && !$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $ownTransaction = true;
    } else {
        $ownTransaction = false;
    }

    try {
        foreach ($sessions as $sid) {
            $legacyAudit = avatar_relationship_legacy_audit($pdo, $sid);
            $validEdgesBySession[$sid] = $legacyAudit['valid_edges'];
            foreach ($legacyAudit['valid_edges'] as $edge) {
                $validLegacyKeys[$sid . ':' . $edge['legacy_link_key']] = true;
                $validEdgesByKey[$sid . ':' . $edge['legacy_link_key']] = $edge;
                if ($edge['mode'] !== $edge['normalized_mode']) {
                    $action = avatar_relationship_repair_result(
                        $apply,
                        'unsupported_legacy_mode',
                        ['session_id' => $sid] + $edge,
                        'normalize legacy participant link_mode to the current compatibility mode',
                        $apply ? 'repaired' : 'would_repair'
                    );
                    $recordAction($action);
                    if ($apply) {
                        $pdo->prepare('UPDATE participants SET link_mode = ? WHERE id = ? AND session_id = ?')
                            ->execute([$edge['normalized_mode'], (int)$edge['participant_id'], $sid]);
                        $normalized++;
                    }
                }
            }
            foreach ($legacyAudit['invalid_edges'] as $invalid) {
                $status = $invalid['status'] === 'skipped' ? 'skipped' : ($apply ? 'repaired' : 'would_repair');
                $action = array_merge($invalid, ['repair_mode' => $apply ? 'repair' : 'dry_run', 'status' => $status]);
                $recordAction($action);
                if ($apply && $status === 'repaired') {
                    $participantId = (int)($invalid['participant_id'] ?? 0);
                    if ($participantId > 0) {
                        $pdo->prepare("UPDATE participants SET linked_to_participant_id = NULL, link_mode = 'normal' WHERE id = ? AND session_id = ?")
                            ->execute([$participantId, $sid]);
                        avatar_relationship_clear_for_participants($pdo, $sid, [$participantId]);
                    }
                }
            }
        }

        $records = avatar_relationship_persisted_rows($pdo, $sessionId);
        $recordsByPair = [];
        $deleteIds = [];
        $orphanedMemberIds = [];
        foreach (avatar_relationship_orphaned_members($pdo, $sessionId) as $member) {
            $recordAction(avatar_relationship_repair_result(
                $apply,
                'orphaned_relationship_member',
                [
                    'relationship_member_id' => (int)$member['id'],
                    'relationship_db_id' => (int)$member['relationship_id'],
                    'participant_id' => (int)$member['participant_id'],
                ],
                'remove normalized member rows whose relationship record is missing',
                $apply ? 'repaired' : 'would_repair'
            ));
            $orphanedMemberIds[] = (int)$member['id'];
        }
        foreach ($records as $relationship) {
            $ctx = avatar_relationship_record_context($relationship);
            $sid = (int)$relationship['session_id'];
            $members = $relationship['members'] ?? [];
            $relationshipStatus = (string)($relationship['status'] ?? 'active');
            if ($relationshipStatus === 'dissolved') {
                if ($members) {
                    foreach ($members as $member) {
                        $orphanedMemberIds[] = (int)$member['id'];
                    }
                    $recordAction(avatar_relationship_repair_result(
                        $apply,
                        'dissolved_relationship_has_active_members',
                        $ctx,
                        'remove active membership rows from the dissolved relationship',
                        $apply ? 'repaired' : 'would_repair'
                    ));
                }
                continue;
            }
            if ($relationshipStatus === 'conflicted') {
                $recordAction(avatar_relationship_repair_result(
                    false,
                    'relationship_membership_conflict',
                    $ctx,
                    'operator review required; conflicting active group membership is not guessed',
                    'skipped'
                ));
                continue;
            }
            $mode = (string)($relationship['mode'] ?? 'normal');
            $normalizedMode = avatar_relationship_mode($mode);
            $legacyKey = (string)($relationship['legacy_link_key'] ?? '');
            if ($legacyKey === '' && count($members) >= 2) {
                $legacyKey = link_key_for((int)$members[0]['participant_id'], (int)$members[1]['participant_id']);
            }
            if ($legacyKey !== '') {
                $recordsByPair[$sid . ':' . $legacyKey][] = (int)$relationship['id'];
            }

            $metadataJson = avatar_relationship_decode_json($relationship['metadata_json'] ?? null);
            $anchorsJson = avatar_relationship_decode_json($relationship['anchors_json'] ?? null);
            $optionsJson = avatar_relationship_decode_json($relationship['options_json'] ?? null);
            $memberParticipantIds = array_map(fn(array $member): int => (int)$member['participant_id'], $members);
            $membersByParticipantId = [];
            foreach ($members as $member) $membersByParticipantId[(int)$member['participant_id']] = $member;
            $memberOrders = array_map(fn(array $member): int => (int)$member['member_order'], $members);
            $memberSessions = array_values(array_unique(array_filter(array_map(fn(array $member): int => (int)($member['participant_session_id'] ?? 0), $members))));
            $missingMemberParticipant = count(array_filter($members, fn(array $member): bool => empty($member['participant_session_id']))) > 0;
            $relationshipPublicId = (string)($relationship['relationship_public_id'] ?? '');
            $validLegacyBacked = $legacyKey !== '' && !empty($validLegacyKeys[$sid . ':' . $legacyKey]);
            $validEdge = $validLegacyBacked ? ($validEdgesByKey[$sid . ':' . $legacyKey] ?? null) : null;
            $validStoredGroup = count($members) > 2 && !$missingMemberParticipant && (!$memberSessions || $memberSessions === [$sid]);
            $expectedPublicId = null;
            if ($validEdge) {
                $expectedPublicId = avatar_relationship_public_id_for(
                    (int)$validEdge['participant_id'],
                    (int)$validEdge['target_participant_id']
                );
            }

            $recordReasons = [];
            if (empty($relationship['relationship_session_exists'])) $recordReasons[] = 'relationship_session_missing';
            if (!$metadataJson['valid']) $recordReasons[] = 'malformed_metadata_json';
            if (!$anchorsJson['valid']) $recordReasons[] = 'malformed_anchors_json';
            if (!$optionsJson['valid']) $recordReasons[] = 'malformed_options_json';
            if ($normalizedMode !== $mode) $recordReasons[] = 'unsupported_persisted_mode';
            if (count($members) < 2) $recordReasons[] = 'missing_relationship_members';
            if (count($memberParticipantIds) !== count(array_unique($memberParticipantIds))) $recordReasons[] = 'duplicate_relationship_member';
            if (count($memberOrders) !== count(array_unique($memberOrders))) $recordReasons[] = 'duplicate_member_order';
            $expectedOrders = count($memberOrders) >= 2 ? range(0, count($memberOrders) - 1) : $memberOrders;
            if ($memberOrders !== $expectedOrders && count($memberOrders) >= 2) $recordReasons[] = 'invalid_member_ordering';
            $normalMemberIds = [];
            foreach ($members as $member) {
                if ((string)($member['membership_status'] ?? 'active') === 'active'
                    && (string)($member['relationship_role'] ?? '') === 'normal') {
                    $normalMemberIds[(int)$member['participant_id']] = true;
                }
            }
            $lapSeatKeys = [];
            foreach ($members as $member) {
                $role = (string)($member['relationship_role'] ?? '');
                $hostId = (int)($member['lap_host_participant_id'] ?? 0);
                $rawSide = $member['lap_side'] ?? null;
                $side = avatar_relationship_normalize_lap_side($rawSide);
                if ($role === 'normal') {
                    if ($hostId > 0 || $rawSide !== null) $recordReasons[] = 'invalid_normal_lap_seat_state';
                    continue;
                }
                if ($role !== 'lap' || $hostId <= 0 || empty($normalMemberIds[$hostId]) || $side === null) {
                    $recordReasons[] = 'invalid_lap_seat_state';
                    continue;
                }
                $seatKey = $hostId . ':' . $side;
                if (isset($lapSeatKeys[$seatKey])) $recordReasons[] = 'duplicate_lap_seat';
                $lapSeatKeys[$seatKey] = true;
            }
            if (count($members) === 2 && $validEdge) {
                $legacyInitiatorMember = $membersByParticipantId[(int)$validEdge['participant_id']] ?? null;
                $legacyTargetMember = $membersByParticipantId[(int)$validEdge['target_participant_id']] ?? null;
                if (($legacyInitiatorMember['member_role'] ?? null) !== 'initiator'
                    || ($legacyTargetMember['member_role'] ?? null) !== 'target') {
                    $recordReasons[] = 'invalid_member_roles';
                }
            }
            if ($memberSessions && ($memberSessions !== [$sid])) $recordReasons[] = 'member_session_mismatch';
            if ($missingMemberParticipant && count($members) > 0) $recordReasons[] = 'member_participant_missing';
            if ($relationshipPublicId === '') $recordReasons[] = 'invalid_relationship_identity_namespace';
            if (!$validLegacyBacked && !$validStoredGroup) $recordReasons[] = 'orphaned_normalized_relationship';
            if ($validLegacyBacked) {
                if ($validEdge && (
                    !isset($membersByParticipantId[(int)$validEdge['participant_id']])
                    || !isset($membersByParticipantId[(int)$validEdge['target_participant_id']])
                    || (int)($relationship['legacy_initiator_participant_id'] ?? 0) !== (int)$validEdge['participant_id']
                    || (int)($relationship['legacy_target_participant_id'] ?? 0) !== (int)$validEdge['target_participant_id']
                )) {
                    $recordReasons[] = 'legacy_projection_mismatch';
                }
            }
            if ($expectedPublicId && str_starts_with($relationshipPublicId, 'legacy-edge:') && $relationshipPublicId !== $expectedPublicId) $recordReasons[] = 'relationship_identity_projection_mismatch';

            foreach (array_values(array_unique($recordReasons)) as $reason) {
                $repairable = $validLegacyBacked || in_array($reason, ['orphaned_normalized_relationship', 'member_participant_missing', 'member_session_mismatch', 'relationship_session_missing'], true);
                $status = $repairable ? ($apply ? 'repaired' : 'would_repair') : 'skipped';
                $recordAction(avatar_relationship_repair_result(
                    $apply,
                    $reason,
                    $ctx,
                    $validLegacyBacked
                        ? 'reconstruct normalized relationship from valid legacy participant edge'
                        : 'remove malformed or orphaned normalized relationship when explicit repair is requested',
                    $status
                ));
            }
            if ($recordReasons && !$validLegacyBacked && !$validStoredGroup) {
                $deleteIds[] = (int)$relationship['id'];
            }
        }

        foreach ($recordsByPair as $pairKey => $ids) {
            if (count($ids) <= 1) continue;
            sort($ids);
            $extras = array_slice($ids, 1);
            foreach ($extras as $extraId) {
                $recordAction(avatar_relationship_repair_result(
                    $apply,
                    'duplicate_persisted_relationship_for_pair',
                    ['relationship_db_id' => $extraId, 'legacy_pair_key' => $pairKey],
                    'retain the first deterministic record and remove duplicate normalized records before legacy resync',
                    $apply ? 'repaired' : 'would_repair'
                ));
                $deleteIds[] = $extraId;
            }
        }

        foreach ($validEdgesBySession as $sid => $edges) {
            foreach ($edges as $edge) {
                $pairKey = $sid . ':' . $edge['legacy_link_key'];
                if (!empty($recordsByPair[$pairKey])) continue;
                $recordAction(avatar_relationship_repair_result(
                    $apply,
                    'missing_relationship_record',
                    ['session_id' => $sid] + $edge,
                    'reconstruct missing normalized relationship from valid legacy participant edge',
                    $apply ? 'repaired' : 'would_repair'
                ));
            }
        }

        if ($apply) {
            $removed += avatar_relationship_delete_orphaned_members($pdo, $orphanedMemberIds);
            $removed += avatar_relationship_delete_records($pdo, $deleteIds);
            foreach ($validEdgesBySession as $sid => $edges) {
                foreach ($edges as $edge) {
                    $relationship = avatar_relationship_sync_legacy($pdo, $sid, (int)$edge['participant_id'], (int)$edge['target_participant_id'], (string)$edge['normalized_mode']);
                    if ($relationship) $createdOrSynced++;
                }
            }
        } else {
            $removed = count(array_values(array_unique($deleteIds))) + count(array_values(array_unique($orphanedMemberIds)));
            foreach ($validEdgesBySession as $edges) $createdOrSynced += count($edges);
        }

        if ($ownTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $afterCounts = $apply ? avatar_relationship_count($pdo, $sessionId) : $beforeCounts;
    return [
        'ok' => true,
        'mode' => $apply ? 'repair' : 'dry_run',
        'scope' => $sessionId !== null ? 'session' : 'database',
        'session_id' => $sessionId,
        'sessions_checked' => count($sessions),
        'counts' => [
            'before' => $beforeCounts,
            'after' => $afterCounts,
        ],
        'summary' => [
            'divergence_count' => count($actions),
            'repairable_count' => count(array_filter($actions, fn(array $action): bool => in_array(($action['status'] ?? ''), ['repaired', 'would_repair'], true))),
            'skipped_count' => $skipped,
            'repaired_or_would_repair_count' => $repaired,
            'removed_or_would_remove_count' => $removed,
            'created_or_synced_count' => $createdOrSynced,
            'normalized_legacy_modes' => $normalized,
            'reason_counts' => $reasonCounts,
        ],
        'actions' => $actions,
    ];
}

function avatar_relationship_divergence_report(PDO $pdo, int $sessionId): array {
    return avatar_relationship_repair($pdo, ['session_id' => $sessionId, 'apply' => false])['actions'];
}

function room_effect_catalog(): array {
    $dir = __DIR__ . '/../assets/room-effects';
    $catalog = [];
    if (!is_dir($dir)) return $catalog;
    foreach (glob($dir . '/*.js') ?: [] as $path) {
        $file = basename($path);
        $source = (string)file_get_contents($path, false, null, 0, 4096);
        $key = preg_match('/^\s*\/\/\s*@effect-key\s+([a-z0-9_-]+)/mi', $source, $m)
            ? $m[1]
            : preg_replace('/[^a-z0-9_-]+/', '_', strtolower(pathinfo($file, PATHINFO_FILENAME)));
        if (!$key || !preg_match('/^[a-z0-9_-]+$/', $key)) continue;
        $label = preg_match('/^\s*\/\/\s*@effect-label\s+(.+)$/mi', $source, $m)
            ? trim($m[1])
            : ucwords(str_replace(['-', '_'], ' ', $key));
        $description = preg_match('/^\s*\/\/\s*@effect-description\s+(.+)$/mi', $source, $m)
            ? trim($m[1])
            : '';
        $catalog[$key] = [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'script' => app_url('/assets/room-effects/' . $file . '?v=' . filemtime($path)),
        ];
    }
    uasort($catalog, fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));
    return $catalog;
}

function room_effect_label(?string $key): string {
    $catalog = room_effect_catalog();
    return $key && isset($catalog[$key]) ? $catalog[$key]['label'] : 'Room Effect';
}

function room_effect_payload(array $row): array {
    $catalog = room_effect_catalog();
    $effect = $catalog[$row['effect_key']] ?? null;
    return [
        'active' => true,
        'effect_key' => $row['effect_key'],
        'label' => $effect['label'] ?? room_effect_label($row['effect_key'] ?? null),
        'description' => $effect['description'] ?? '',
        'script' => $effect['script'] ?? null,
        'started_by_participant_id' => isset($row['started_by_participant_id']) ? (int)$row['started_by_participant_id'] : null,
        'started_by_user_id' => isset($row['started_by_user_id']) ? (int)$row['started_by_user_id'] : null,
        'started_by_name' => $row['started_by_name'] ?? 'Someone',
        'duration_minutes' => $row['duration_minutes'] !== null ? (int)$row['duration_minutes'] : null,
        'started_at' => $row['started_at'] ?? null,
        'expires_at' => $row['expires_at'] ?? null,
    ];
}

function cleanup_room_effects(PDO $pdo, ?int $sessionId = null): void {
    $sql = 'SELECT re.*, COALESCE(u.display_name, p.display_name, "Someone") AS started_by_name
              FROM room_effects re
              LEFT JOIN users u ON u.id = re.started_by_user_id
              LEFT JOIN participants p ON p.id = re.started_by_participant_id
             WHERE re.expires_at IS NOT NULL AND re.expires_at <= CURRENT_TIMESTAMP';
    $params = [];
    if ($sessionId !== null) {
        $sql .= ' AND re.session_id = ?';
        $params[] = $sessionId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $expired = $stmt->fetchAll();
    if (!$expired) return;

    $delete = $pdo->prepare('DELETE FROM room_effects WHERE session_id = ?');
    foreach ($expired as $row) {
        $sid = (int)$row['session_id'];
        $delete->execute([$sid]);
        emit_event($pdo, $sid, 'room_effect', [
            'active' => false,
            'effect_key' => $row['effect_key'],
            'label' => room_effect_label($row['effect_key'] ?? null),
            'expired' => true,
            'stopped_by_name' => 'ChatSpace',
        ]);
    }
}

function active_room_effect(PDO $pdo, int $sessionId): ?array {
    cleanup_room_effects($pdo, $sessionId);
    $stmt = $pdo->prepare(
        'SELECT re.*, COALESCE(u.display_name, p.display_name, "Someone") AS started_by_name
           FROM room_effects re
           LEFT JOIN users u ON u.id = re.started_by_user_id
           LEFT JOIN participants p ON p.id = re.started_by_participant_id
          WHERE re.session_id = ?
          LIMIT 1'
    );
    $stmt->execute([$sessionId]);
    $row = $stmt->fetch();
    return $row ? room_effect_payload($row) : null;
}

function active_ejection_sql(string $alias = 're'): string {
    return "($alias.permanent = 1 OR $alias.expires_at IS NULL OR $alias.expires_at > CURRENT_TIMESTAMP)";
}

function stale_cutoff(PDO $pdo, ?float $minutes = null): string {
    $minutes = $minutes ?? app_setting_float($pdo, 'participant_idle_timeout_minutes', 2);
    $minutes = max(0.5, min(120, $minutes));
    return gmdate('Y-m-d H:i:s', time() - (int)round($minutes * 60));
}

function cleanup_stale_participants(PDO $pdo, ?int $sessionId = null): void {
    $cutoff = stale_cutoff($pdo);
    $sql = 'SELECT p.id, p.session_id, p.user_id, u.current_room_id
              FROM participants p
              JOIN room_sessions rs ON rs.id = p.session_id
              JOIN users u ON u.id = p.user_id
             WHERE p.last_seen_at IS NOT NULL
               AND p.last_seen_at < ?';
    $params = [$cutoff];
    if ($sessionId !== null) {
        $sql .= ' AND p.session_id = ?';
        $params[] = $sessionId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $stale = $stmt->fetchAll();
    if (!$stale) return;

    $clearParticipant = $pdo->prepare("UPDATE participants SET last_seen_at = NULL, webcam_path = NULL, webcam_enabled = 0, linked_to_participant_id = NULL, link_mode = 'normal' WHERE id = ? OR linked_to_participant_id = ?");
    $clearUser = $pdo->prepare('UPDATE users SET current_room_id = NULL, last_seen_at = CURRENT_TIMESTAMP WHERE id = ?');
    $clearVoice = $pdo->prepare('DELETE FROM voice_sessions WHERE participant_id = ?');
    foreach ($stale as $row) {
        $participantId = (int)$row['id'];
        avatar_relationship_force_participant_departure(
            $pdo,
            (int)$row['session_id'],
            $participantId,
            'stale-participant-cleanup'
        );
        $clearParticipant->execute([$participantId, $participantId]);
        $clearVoice->execute([$participantId]);
        $clearUser->execute([(int)$row['user_id']]);
        emit_event($pdo, (int)$row['session_id'], 'participant_leave', ['id' => $participantId, 'participant_id' => $participantId]);
    }
}

function active_room_ejection(PDO $pdo, int $roomId, int $userId): ?array {
    $stmt = $pdo->prepare(
        'SELECT * FROM room_ejections re
         WHERE re.room_id = ? AND re.user_id = ? AND ' . active_ejection_sql('re') . '
         ORDER BY re.created_at DESC LIMIT 1'
    );
    $stmt->execute([$roomId, $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function active_community_ejection(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare(
        'SELECT ce.*, by_user.display_name AS ejected_by_name
           FROM community_ejections ce
           LEFT JOIN users by_user ON by_user.id = ce.ejected_by_user_id
          WHERE ce.user_id = ? AND ' . active_ejection_sql('ce') . '
          ORDER BY ce.created_at DESC LIMIT 1'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function can_use_host_tools(array $user, array $room): bool {
    $role = $user['role'] ?? 'user';
    return (int)$room['owner_id'] === (int)$user['id'] || in_array($role, ['guide', 'developer', 'admin'], true);
}

function can_community_eject(array $user): bool {
    return in_array($user['role'] ?? 'user', ['developer', 'admin'], true);
}

function author_context_for_participant(PDO $pdo, int $sessionId, array $participant): array {
    $stmt = $pdo->prepare(
        'SELECT u.role, r.owner_id
           FROM users u
           JOIN room_sessions rs ON rs.id = ?
           JOIN rooms r ON r.id = rs.room_id
          WHERE u.id = ?
          LIMIT 1'
    );
    $stmt->execute([$sessionId, (int)($participant['user_id'] ?? 0)]);
    $row = $stmt->fetch() ?: [];
    return [
        'role' => $row['role'] ?? 'user',
        'is_owner' => !empty($row['owner_id']) && (int)$row['owner_id'] === (int)($participant['user_id'] ?? 0),
    ];
}

function link_key_for(int $a, int $b): string {
    $ids = [$a, $b];
    sort($ids, SORT_NUMERIC);
    return $ids[0] . ':' . $ids[1];
}

function dm_key_for(int $a, int $b): string {
    $ids = [$a, $b];
    sort($ids, SORT_NUMERIC);
    return 'dm:' . $ids[0] . ':' . $ids[1];
}

function avatar_presets(): array {
    return [
        'Default' => 'data:image/svg+xml;utf8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#7c6af7"/><stop offset="1" stop-color="#27d3c3"/></linearGradient></defs><rect width="120" height="120" rx="60" fill="url(#g)"/><circle cx="60" cy="46" r="22" fill="#f7f2ff"/><path d="M24 105c8-25 28-36 36-36s28 11 36 36" fill="#f7f2ff"/></svg>'),
        'Nova' => 'data:image/svg+xml;utf8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120"><rect width="120" height="120" rx="60" fill="#111827"/><path d="M60 17l9 28 29-1-24 17 10 28-24-17-24 17 10-28-24-17 29 1z" fill="#f6d365"/></svg>'),
        'Comet' => 'data:image/svg+xml;utf8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120"><rect width="120" height="120" rx="60" fill="#12201d"/><circle cx="68" cy="50" r="24" fill="#27d3c3"/><path d="M18 82c30-3 52-14 74-50-8 34-25 58-74 50z" fill="#f7f2ff" opacity=".8"/></svg>'),
        'Velvet' => 'data:image/svg+xml;utf8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120"><rect width="120" height="120" rx="60" fill="#251326"/><circle cx="60" cy="60" r="34" fill="#d946ef"/><circle cx="48" cy="54" r="6" fill="#fff"/><circle cx="72" cy="54" r="6" fill="#fff"/><path d="M44 76c10 8 22 8 32 0" stroke="#fff" stroke-width="7" fill="none" stroke-linecap="round"/></svg>'),
    ];
}

function resolve_avatar(?string $path): string {
    $path = $path ?: 'preset:Default';
    if (str_starts_with($path, 'preset:')) {
        $key = substr($path, 7);
        $presets = avatar_presets();
        return $presets[$key] ?? $presets['Default'];
    }
    return $path;
}

function avatar_orientation_values(): array {
    return ['original', 'flip-horizontal', 'flip-vertical', 'flip-both'];
}

function avatar_orientation_normalize(mixed $orientation): string {
    $orientation = is_string($orientation) ? trim($orientation) : '';
    return in_array($orientation, avatar_orientation_values(), true)
        ? $orientation
        : 'original';
}

function avatar_orientation_update(
    PDO $pdo,
    int $userId,
    mixed $expectedOrientation,
    mixed $requestedOrientation
): array {
    if (!is_string($expectedOrientation)
        || !in_array(trim($expectedOrientation), avatar_orientation_values(), true)
        || !is_string($requestedOrientation)
        || !in_array(trim($requestedOrientation), avatar_orientation_values(), true)) {
        return [
            'ok' => false,
            'code' => 'AVATAR_ORIENTATION_INVALID',
            'error' => 'That avatar orientation is not available.',
            'http_status' => 400,
        ];
    }
    $expectedOrientation = trim($expectedOrientation);
    $requestedOrientation = trim($requestedOrientation);
    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) {
            if (db_uses_mysql_syntax($pdo)) $pdo->beginTransaction();
            else $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
        }
        $sql = 'SELECT avatar_path, avatar_orientation FROM users WHERE id = ? LIMIT 1';
        if (db_uses_mysql_syntax($pdo)) $sql .= ' FOR UPDATE';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            return [
                'ok' => false,
                'code' => 'AVATAR_ORIENTATION_USER_NOT_FOUND',
                'error' => 'Avatar settings are unavailable.',
                'http_status' => 404,
            ];
        }
        $currentOrientation = avatar_orientation_normalize($user['avatar_orientation'] ?? null);
        if ($currentOrientation !== $expectedOrientation) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            return [
                'ok' => false,
                'code' => 'AVATAR_ORIENTATION_STALE',
                'error' => 'Avatar orientation changed. Refresh and try again.',
                'current_orientation' => $currentOrientation,
                'http_status' => 409,
            ];
        }
        $pdo->prepare('UPDATE users SET avatar_orientation = ? WHERE id = ?')
            ->execute([$requestedOrientation, $userId]);
        $pdo->prepare('UPDATE participants SET avatar_orientation = ? WHERE user_id = ?')
            ->execute([$requestedOrientation, $userId]);
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->commit();
        return [
            'ok' => true,
            'idempotent' => $currentOrientation === $requestedOrientation,
            'avatar_orientation' => $requestedOrientation,
            'avatar_path' => (string)($user['avatar_path'] ?? 'preset:Default'),
        ];
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function aura_catalog(): array {
    $dir = __DIR__ . '/../assets/auras';
    if (!is_dir($dir)) return [];
    $items = [];
    foreach (glob($dir . '/*.js') ?: [] as $file) {
        $key = pathinfo($file, PATHINFO_FILENAME);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 _&.\'-]{0,80}$/', $key)) continue;
        $label = $key;
        $contents = @file_get_contents($file);
        if (is_string($contents) && preg_match('/\bname\s*:\s*["\']([^"\']{1,80})["\']/', $contents, $match)) {
            $label = trim($match[1]) ?: $label;
        }
        $items[] = [
            'key' => $key,
            'label' => $label,
            'script' => '/assets/auras/' . rawurlencode($key) . '.js',
        ];
    }
    usort($items, fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));
    return $items;
}

function normalize_aura_key(?string $key): ?string {
    $key = trim((string)$key);
    if ($key === '' || strtolower($key) === 'none') return null;
    foreach (aura_catalog() as $aura) {
        if (hash_equals((string)$aura['key'], $key)) return (string)$aura['key'];
    }
    return null;
}

function active_session_for_room(PDO $pdo, int $roomId): array {
    $stmt = $pdo->prepare('SELECT * FROM room_sessions WHERE room_id = ? LIMIT 1');
    $stmt->execute([$roomId]);
    $session = $stmt->fetch();
    if ($session) return $session;

    $pdo->prepare('INSERT INTO room_sessions (public_id, room_id) VALUES (?,?)')->execute([uuid_v4(), $roomId]);
    $stmt->execute([$roomId]);
    return $stmt->fetch();
}

function resolve_session_id(PDO $pdo, mixed $sessionKey): int {
    $key = trim((string)$sessionKey);
    if ($key === '') json_out(['error' => 'Session required'], 400);
    if (ctype_digit($key)) json_out(['error' => 'Session UUID required'], 400);
    $stmt = $pdo->prepare('SELECT id FROM room_sessions WHERE public_id = ? LIMIT 1');
    $stmt->execute([$key]);
    $id = (int)($stmt->fetchColumn() ?: 0);
    if (!$id) json_out(['error' => 'Session not found'], 404);
    return $id;
}

function participant_for_user(PDO $pdo, int $sessionId, array $user): array {
    $stmt = $pdo->prepare('SELECT * FROM participants WHERE session_id = ? AND user_id = ? ORDER BY id ASC');
    $stmt->execute([$sessionId, (int)$user['id']]);
    $matches = $stmt->fetchAll();
    if ($matches) {
        $participant = $matches[0];
        $userOrientation = avatar_orientation_normalize($user['avatar_orientation'] ?? null);
        $userSize = avatar_size_preferences_from_row($user);
        $participantSize = avatar_size_preferences_from_row($participant);
        if (avatar_orientation_normalize($participant['avatar_orientation'] ?? null) !== $userOrientation
            || $participantSize !== $userSize) {
            $pdo->prepare(
                'UPDATE participants SET avatar_orientation = ?, avatar_display_size_px = ?, webcam_display_width_px = ?, webcam_display_height_px = ?, avatar_size_version = ? WHERE id = ?'
            )->execute([
                $userOrientation,
                $userSize['avatarDisplayPreferencePx'],
                $userSize['webcamDisplayWidthPreferencePx'],
                $userSize['webcamDisplayHeightPreferencePx'],
                $userSize['displayPreferenceVersion'],
                (int)$participant['id'],
            ]);
            $participant['avatar_orientation'] = $userOrientation;
            $participant['avatar_display_size_px'] = $userSize['avatarDisplayPreferencePx'];
            $participant['webcam_display_width_px'] = $userSize['webcamDisplayWidthPreferencePx'];
            $participant['webcam_display_height_px'] = $userSize['webcamDisplayHeightPreferencePx'];
            $participant['avatar_size_version'] = $userSize['displayPreferenceVersion'];
        }
        if (count($matches) > 1) {
            $extraIds = array_map(fn($row) => (int)$row['id'], array_slice($matches, 1));
            $placeholders = implode(',', array_fill(0, count($extraIds), '?'));
            foreach ($extraIds as $extraId) {
                avatar_relationship_force_participant_departure(
                    $pdo,
                    $sessionId,
                    $extraId,
                    'duplicate-participant-cleanup'
                );
            }
            $pdo->prepare("DELETE FROM participants WHERE id IN ($placeholders)")->execute($extraIds);
        }
        return $participant;
    }

    $token = bin2hex(random_bytes(24));
    $x = random_int(12, 68) / 100;
    $y = random_int(18, 58) / 100;
    $orientation = avatar_orientation_normalize($user['avatar_orientation'] ?? null);
    $size = avatar_size_preferences_from_row($user);
    $pdo->prepare(
        'INSERT INTO participants (session_id, user_id, display_name, avatar_path, avatar_orientation, avatar_display_size_px, webcam_display_width_px, webcam_display_height_px, avatar_size_version, aura_effect, join_token, position_x, position_y, last_seen_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)'
    )->execute([
        $sessionId,
        (int)$user['id'],
        $user['display_name'],
        $user['avatar_path'] ?: 'preset:Default',
        $orientation,
        $size['avatarDisplayPreferencePx'],
        $size['webcamDisplayWidthPreferencePx'],
        $size['webcamDisplayHeightPreferencePx'],
        $size['displayPreferenceVersion'],
        $user['aura_effect'] ?? null,
        $token,
        $x,
        $y,
    ]);
    $stmt->execute([$sessionId, (int)$user['id']]);
    return $stmt->fetch();
}

function auth_participant(PDO $pdo, int|string $sessionId, ?string $joinToken = null): array {
    $sessionId = is_int($sessionId) ? $sessionId : resolve_session_id($pdo, $sessionId);
    $joinToken = $joinToken ?: ($_GET['join_token'] ?? '');
    $stmt = $pdo->prepare('SELECT * FROM participants WHERE session_id = ? AND join_token = ? LIMIT 1');
    $stmt->execute([$sessionId, $joinToken]);
    $p = $stmt->fetch();
    if (!$p) json_out(['error' => 'Unauthorized'], 403);
    $pdo->prepare('UPDATE participants SET last_seen_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int)$p['id']]);
    $pdo->prepare('UPDATE users SET last_seen_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int)$p['user_id']]);
    return $p;
}

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
