<?php
declare(strict_types=1);

/**
 * Post-Build 000055 authenticated server-media business owner.
 *
 * New user-uploaded bytes live outside the public application tree and are
 * delivered only after source-authoritative authorization. P2P payloads are
 * deliberately outside this owner.
 */

const SERVER_MEDIA_ATTACHMENTS_ENABLED = 'server_chat_attachments_enabled';
const SERVER_MEDIA_VOICE_NOTES_ENABLED = 'server_voice_notes_enabled';
const SERVER_MEDIA_FILE_MODE = 'direct_file_delivery_mode';
const SERVER_MEDIA_GESTURE_MODE = 'send_gesture_delivery_mode';
const SERVER_MEDIA_IMAGE_MAX_MB = 'server_media_image_max_mb';
const SERVER_MEDIA_DOCUMENT_MAX_MB = 'server_media_document_max_mb';
const SERVER_MEDIA_VOICE_MAX_MB = 'server_media_voice_note_max_mb';
const SERVER_MEDIA_USER_DAILY_MB = 'server_media_user_daily_mb';
const SERVER_MEDIA_INSTALLATION_STORAGE_MB = 'server_media_installation_storage_mb';
const SERVER_MEDIA_MONTHLY_DELIVERY_MB = 'server_media_monthly_delivery_mb';
const SERVER_MEDIA_WARNING_LOW_PERCENT = 'server_media_warning_low_percent';
const SERVER_MEDIA_WARNING_HIGH_PERCENT = 'server_media_warning_high_percent';
const SERVER_MEDIA_HARD_STOP_PERCENT = 'server_media_hard_stop_percent';
const SERVER_MEDIA_RETENTION_HOURS = 'server_media_retention_hours';
const SERVER_MEDIA_REVIEW_SECONDS = 3600;

final class ServerMediaException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'SERVER_MEDIA_FAILED',
        public readonly int $httpStatus = 409,
        public readonly array $facts = []
    ) {
        parent::__construct($message);
    }
}

function server_media_setting_defaults(): array
{
    return [
        SERVER_MEDIA_ATTACHMENTS_ENABLED => '0',
        SERVER_MEDIA_VOICE_NOTES_ENABLED => '0',
        SERVER_MEDIA_FILE_MODE => 'p2p-only',
        SERVER_MEDIA_GESTURE_MODE => 'both',
        SERVER_MEDIA_IMAGE_MAX_MB => '10',
        SERVER_MEDIA_DOCUMENT_MAX_MB => '20',
        SERVER_MEDIA_VOICE_MAX_MB => '10',
        SERVER_MEDIA_USER_DAILY_MB => '100',
        SERVER_MEDIA_INSTALLATION_STORAGE_MB => '2048',
        SERVER_MEDIA_MONTHLY_DELIVERY_MB => '5120',
        SERVER_MEDIA_WARNING_LOW_PERCENT => '75',
        SERVER_MEDIA_WARNING_HIGH_PERCENT => '90',
        SERVER_MEDIA_HARD_STOP_PERCENT => '100',
        SERVER_MEDIA_RETENTION_HOURS => '24',
    ];
}

function server_media_schema_statements(PDO $pdo): array
{
    if (db_uses_mysql_syntax($pdo)) {
        return [
            "CREATE TABLE IF NOT EXISTS server_media_assets (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(64) NOT NULL UNIQUE,
                uploader_user_id INT DEFAULT NULL,
                session_id INT DEFAULT NULL,
                category VARCHAR(32) NOT NULL,
                channel_scope VARCHAR(32) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                safe_name VARCHAR(255) NOT NULL,
                extension VARCHAR(32) NOT NULL DEFAULT '',
                declared_mime VARCHAR(191) NOT NULL,
                detected_mime VARCHAR(191) NOT NULL,
                risk_class VARCHAR(32) NOT NULL,
                risk_detail TEXT NOT NULL,
                byte_size BIGINT NOT NULL,
                storage_path VARCHAR(1024) NOT NULL,
                source_sha256 CHAR(64) NOT NULL,
                legacy_public_path VARCHAR(1024) DEFAULT NULL,
                audience_json LONGTEXT NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'active',
                persistent TINYINT(1) NOT NULL DEFAULT 0,
                grandfathered TINYINT(1) NOT NULL DEFAULT 0,
                pinned TINYINT(1) NOT NULL DEFAULT 0,
                expires_at DATETIME DEFAULT NULL,
                quarantine_reason TEXT DEFAULT NULL,
                preview_path VARCHAR(1024) DEFAULT NULL,
                preview_bytes BIGINT NOT NULL DEFAULT 0,
                source_owner VARCHAR(32) NOT NULL DEFAULT 'server-media',
                source_key VARCHAR(191) NOT NULL DEFAULT '',
                source_role VARCHAR(32) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_server_media_scope (channel_scope,session_id,status,created_at),
                INDEX idx_server_media_uploader (uploader_user_id,status,created_at),
                INDEX idx_server_media_lifecycle (status,pinned,expires_at),
                INDEX idx_server_media_type (category,extension,detected_mime),
                INDEX idx_server_media_source (source_owner,source_key,source_role),
                CONSTRAINT fk_server_media_uploader FOREIGN KEY (uploader_user_id) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_server_media_session FOREIGN KEY (session_id) REFERENCES room_sessions(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS server_media_references (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                asset_id BIGINT NOT NULL,
                message_table VARCHAR(32) NOT NULL,
                message_id BIGINT NOT NULL,
                channel_scope VARCHAR(32) NOT NULL,
                relationship_key VARCHAR(191) NOT NULL DEFAULT '',
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_server_media_reference (asset_id,message_table,message_id),
                INDEX idx_server_media_reference_message (message_table,message_id,active),
                CONSTRAINT fk_server_media_reference_asset FOREIGN KEY (asset_id) REFERENCES server_media_assets(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS server_media_delivery_usage (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                asset_id BIGINT NOT NULL,
                user_id INT NOT NULL,
                byte_count BIGINT NOT NULL,
                delivered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_server_media_delivery_month (delivered_at),
                INDEX idx_server_media_delivery_user (user_id,delivered_at),
                CONSTRAINT fk_server_media_delivery_asset FOREIGN KEY (asset_id) REFERENCES server_media_assets(id) ON DELETE CASCADE,
                CONSTRAINT fk_server_media_delivery_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS file_review_sessions (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(64) NOT NULL UNIQUE,
                reviewer_user_id INT NOT NULL,
                auth_session_hash CHAR(64) NOT NULL,
                role_snapshot VARCHAR(32) NOT NULL,
                reason TEXT NOT NULL,
                started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL,
                ended_at DATETIME DEFAULT NULL,
                end_reason VARCHAR(64) DEFAULT NULL,
                INDEX idx_file_review_actor (reviewer_user_id,expires_at,ended_at),
                CONSTRAINT fk_file_review_actor FOREIGN KEY (reviewer_user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS file_review_actions (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                review_session_id BIGINT NOT NULL,
                reviewer_user_id INT NOT NULL,
                asset_id BIGINT NOT NULL,
                action VARCHAR(64) NOT NULL,
                original_reason TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_file_review_action_session (review_session_id,created_at),
                CONSTRAINT fk_file_review_action_session FOREIGN KEY (review_session_id) REFERENCES file_review_sessions(id) ON DELETE CASCADE,
                CONSTRAINT fk_file_review_action_actor FOREIGN KEY (reviewer_user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_file_review_action_asset FOREIGN KEY (asset_id) REFERENCES server_media_assets(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }
    return [
        "CREATE TABLE IF NOT EXISTS server_media_assets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            uploader_user_id INTEGER DEFAULT NULL,
            session_id INTEGER DEFAULT NULL,
            category TEXT NOT NULL,
            channel_scope TEXT NOT NULL,
            original_name TEXT NOT NULL,
            safe_name TEXT NOT NULL,
            extension TEXT NOT NULL DEFAULT '',
            declared_mime TEXT NOT NULL,
            detected_mime TEXT NOT NULL,
            risk_class TEXT NOT NULL,
            risk_detail TEXT NOT NULL,
            byte_size INTEGER NOT NULL CHECK (byte_size >= 0),
            storage_path TEXT NOT NULL,
            source_sha256 TEXT NOT NULL,
            legacy_public_path TEXT DEFAULT NULL,
            audience_json TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'active',
            persistent INTEGER NOT NULL DEFAULT 0 CHECK (persistent IN (0,1)),
            grandfathered INTEGER NOT NULL DEFAULT 0 CHECK (grandfathered IN (0,1)),
            pinned INTEGER NOT NULL DEFAULT 0 CHECK (pinned IN (0,1)),
            expires_at TEXT DEFAULT NULL,
            quarantine_reason TEXT DEFAULT NULL,
            preview_path TEXT DEFAULT NULL,
            preview_bytes INTEGER NOT NULL DEFAULT 0 CHECK (preview_bytes >= 0),
            source_owner TEXT NOT NULL DEFAULT 'server-media',
            source_key TEXT NOT NULL DEFAULT '',
            source_role TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(uploader_user_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY(session_id) REFERENCES room_sessions(id) ON DELETE SET NULL
        )",
        'CREATE INDEX IF NOT EXISTS idx_server_media_scope ON server_media_assets(channel_scope,session_id,status,created_at)',
        'CREATE INDEX IF NOT EXISTS idx_server_media_uploader ON server_media_assets(uploader_user_id,status,created_at)',
        'CREATE INDEX IF NOT EXISTS idx_server_media_lifecycle ON server_media_assets(status,pinned,expires_at)',
        'CREATE INDEX IF NOT EXISTS idx_server_media_type ON server_media_assets(category,extension,detected_mime)',
        'CREATE INDEX IF NOT EXISTS idx_server_media_source ON server_media_assets(source_owner,source_key,source_role)',
        "CREATE TABLE IF NOT EXISTS server_media_references (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            asset_id INTEGER NOT NULL,
            message_table TEXT NOT NULL,
            message_id INTEGER NOT NULL,
            channel_scope TEXT NOT NULL,
            relationship_key TEXT NOT NULL DEFAULT '',
            active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0,1)),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(asset_id,message_table,message_id),
            FOREIGN KEY(asset_id) REFERENCES server_media_assets(id) ON DELETE CASCADE
        )",
        'CREATE INDEX IF NOT EXISTS idx_server_media_reference_message ON server_media_references(message_table,message_id,active)',
        "CREATE TABLE IF NOT EXISTS server_media_delivery_usage (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            asset_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            byte_count INTEGER NOT NULL CHECK (byte_count >= 0),
            delivered_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(asset_id) REFERENCES server_media_assets(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        'CREATE INDEX IF NOT EXISTS idx_server_media_delivery_month ON server_media_delivery_usage(delivered_at)',
        'CREATE INDEX IF NOT EXISTS idx_server_media_delivery_user ON server_media_delivery_usage(user_id,delivered_at)',
        "CREATE TABLE IF NOT EXISTS file_review_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            reviewer_user_id INTEGER NOT NULL,
            auth_session_hash TEXT NOT NULL,
            role_snapshot TEXT NOT NULL,
            reason TEXT NOT NULL,
            started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at TEXT NOT NULL,
            ended_at TEXT DEFAULT NULL,
            end_reason TEXT DEFAULT NULL,
            FOREIGN KEY(reviewer_user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        'CREATE INDEX IF NOT EXISTS idx_file_review_actor ON file_review_sessions(reviewer_user_id,expires_at,ended_at)',
        "CREATE TABLE IF NOT EXISTS file_review_actions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            review_session_id INTEGER NOT NULL,
            reviewer_user_id INTEGER NOT NULL,
            asset_id INTEGER NOT NULL,
            action TEXT NOT NULL,
            original_reason TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(review_session_id) REFERENCES file_review_sessions(id) ON DELETE CASCADE,
            FOREIGN KEY(reviewer_user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(asset_id) REFERENCES server_media_assets(id) ON DELETE CASCADE
        )",
        'CREATE INDEX IF NOT EXISTS idx_file_review_action_session ON file_review_actions(review_session_id,created_at)',
    ];
}

function server_media_install_schema(PDO $pdo): void
{
    foreach (server_media_schema_statements($pdo) as $sql) {
        if (str_starts_with(ltrim($sql), 'CREATE INDEX') && str_contains($sql, 'idx_server_media_source')) continue;
        $pdo->exec($sql);
    }
    $columns = database_migration_columns($pdo, 'server_media_assets');
    $definitions = db_uses_mysql_syntax($pdo)
        ? [
            'source_owner' => "VARCHAR(32) NOT NULL DEFAULT 'server-media'",
            'source_key' => "VARCHAR(191) NOT NULL DEFAULT ''",
            'source_role' => "VARCHAR(32) NOT NULL DEFAULT ''",
        ]
        : [
            'source_owner' => "TEXT NOT NULL DEFAULT 'server-media'",
            'source_key' => "TEXT NOT NULL DEFAULT ''",
            'source_role' => "TEXT NOT NULL DEFAULT ''",
        ];
    foreach ($definitions as $column => $definition) {
        if (!in_array($column, $columns, true)) {
            $pdo->exec("ALTER TABLE server_media_assets ADD COLUMN {$column} {$definition}");
        }
    }
    if (db_uses_mysql_syntax($pdo)) {
        try {
            $pdo->exec('CREATE INDEX idx_server_media_source ON server_media_assets(source_owner,source_key,source_role)');
        } catch (PDOException $error) {
            if (!in_array((string)$error->getCode(), ['42000','42S11'], true)) throw $error;
        }
    } else {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_server_media_source ON server_media_assets(source_owner,source_key,source_role)');
    }
}

function server_media_schema_valid(PDO $pdo): bool
{
    foreach (['server_media_assets','server_media_references','server_media_delivery_usage','file_review_sessions','file_review_actions'] as $table) {
        if (!database_migration_table_exists($pdo, $table)) return false;
    }
    return database_migration_has_columns($pdo, 'server_media_assets', [
        'source_sha256','legacy_public_path','preview_path','preview_bytes',
        'source_owner','source_key','source_role',
    ]);
}

function server_media_sidecar_directory(): string
{
    return security_private_storage_directory('server-media-index');
}

function server_media_write_sidecar(array $asset): void
{
    $publicId = (string)($asset['public_id'] ?? '');
    if (!preg_match('/^sm_[a-f0-9]{32}$/', $publicId)) {
        throw new ServerMediaException('The protected file identity is invalid.', 'SERVER_MEDIA_SIDECAR_INVALID', 500);
    }
    $storageName = basename((string)$asset['storage_path']);
    $previewName = is_string($asset['preview_path'] ?? null) && (string)$asset['preview_path'] !== ''
        ? basename((string)$asset['preview_path'])
        : null;
    foreach (array_filter([$storageName, $previewName]) as $name) {
        if (!preg_match('/^[A-Za-z0-9._-]{1,191}$/', $name)) {
            throw new ServerMediaException('The protected file storage identity is invalid.', 'SERVER_MEDIA_SIDECAR_INVALID', 500);
        }
    }
    $record = [
        'schema' => 'corechat-server-media-sidecar-v1',
        'public_id' => $publicId,
        'uploader_user_id' => $asset['uploader_user_id'] !== null ? (int)$asset['uploader_user_id'] : null,
        'session_id' => $asset['session_id'] !== null ? (int)$asset['session_id'] : null,
        'category' => (string)$asset['category'],
        'channel_scope' => (string)$asset['channel_scope'],
        'original_name' => (string)$asset['original_name'],
        'safe_name' => (string)$asset['safe_name'],
        'extension' => (string)$asset['extension'],
        'declared_mime' => (string)$asset['declared_mime'],
        'detected_mime' => (string)$asset['detected_mime'],
        'risk_class' => (string)$asset['risk_class'],
        'risk_detail' => (string)$asset['risk_detail'],
        'byte_size' => (int)$asset['byte_size'],
        'storage_name' => $storageName,
        'source_sha256' => (string)$asset['source_sha256'],
        'legacy_public_path' => is_string($asset['legacy_public_path'] ?? null) && (string)$asset['legacy_public_path'] !== ''
            ? str_replace('\\', '/', (string)$asset['legacy_public_path'])
            : null,
        'audience_json' => (string)$asset['audience_json'],
        'status' => (string)$asset['status'],
        'persistent' => (int)$asset['persistent'],
        'grandfathered' => (int)$asset['grandfathered'],
        'pinned' => (int)$asset['pinned'],
        'expires_at' => $asset['expires_at'],
        'quarantine_reason' => $asset['quarantine_reason'],
        'preview_name' => $previewName,
        'preview_bytes' => (int)$asset['preview_bytes'],
        'source_owner' => (string)($asset['source_owner'] ?? 'server-media'),
        'source_key' => (string)($asset['source_key'] ?? ''),
        'source_role' => (string)($asset['source_role'] ?? ''),
        'created_at' => (string)$asset['created_at'],
        'updated_at' => (string)$asset['updated_at'],
    ];
    $path = server_media_sidecar_directory() . DIRECTORY_SEPARATOR . $publicId . '.json';
    $temporary = dirname($path) . DIRECTORY_SEPARATOR . '.' . $publicId . '-' . bin2hex(random_bytes(6)) . '.part';
    $content = database_migrations_canonical_json($record) . "\n";
    if (file_put_contents($temporary, $content, LOCK_EX) !== strlen($content) || !@rename($temporary, $path)) {
        @unlink($temporary);
        throw new ServerMediaException('The protected file recovery record could not be committed.', 'SERVER_MEDIA_SIDECAR_WRITE_FAILED', 500);
    }
    @chmod($path, 0600);
}

function server_media_refresh_sidecar(PDO $pdo, int $assetId): void
{
    server_media_write_sidecar(server_media_asset_by_id($pdo, $assetId));
}

function server_media_refresh_all_sidecars(PDO $pdo, int $limit = 5000): int
{
    if (!server_media_schema_valid($pdo)) return 0;
    $limit = max(1, min(20000, $limit));
    $written = 0;
    foreach ($pdo->query("SELECT * FROM server_media_assets ORDER BY id LIMIT {$limit}")->fetchAll() as $asset) {
        server_media_write_sidecar($asset);
        $written++;
    }
    return $written;
}

function server_media_refresh_missing_sidecars(PDO $pdo, int $limit = 5000): int
{
    if (!server_media_schema_valid($pdo)) return 0;
    $limit = max(1, min(20000, $limit));
    $written = 0;
    $directory = server_media_sidecar_directory();
    foreach ($pdo->query("SELECT * FROM server_media_assets ORDER BY id LIMIT {$limit}")->fetchAll() as $asset) {
        $path = $directory . DIRECTORY_SEPARATOR . (string)$asset['public_id'] . '.json';
        if (is_file($path)) continue;
        server_media_write_sidecar($asset);
        $written++;
    }
    return $written;
}

function server_media_reconcile_sidecars(PDO $pdo): int
{
    if (!server_media_schema_valid($pdo)) return 0;
    $recovered = 0;
    $storageDirectory = security_private_storage_directory('server-media');
    foreach (glob(server_media_sidecar_directory() . DIRECTORY_SEPARATOR . 'sm_*.json') ?: [] as $path) {
        $record = json_decode((string)file_get_contents($path), true);
        if (!is_array($record) || ($record['schema'] ?? '') !== 'corechat-server-media-sidecar-v1') continue;
        $publicId = (string)($record['public_id'] ?? '');
        $storageName = (string)($record['storage_name'] ?? '');
        if (!preg_match('/^sm_[a-f0-9]{32}$/', $publicId) || !preg_match('/^[A-Za-z0-9._-]{1,191}$/', $storageName)) continue;
        $exists = $pdo->prepare('SELECT id FROM server_media_assets WHERE public_id=? LIMIT 1');
        $exists->execute([$publicId]);
        if ($exists->fetchColumn()) continue;
        $storagePath = $storageDirectory . DIRECTORY_SEPARATOR . $storageName;
        $status = (string)($record['status'] ?? 'missing');
        if (is_file($storagePath)) {
            $sha = strtoupper((string)hash_file('sha256', $storagePath));
            if (!hash_equals((string)($record['source_sha256'] ?? ''), $sha)
                || (int)filesize($storagePath) !== (int)($record['byte_size'] ?? -1)) continue;
        } elseif (!in_array($status, ['deleted','expired','missing'], true)) {
            $status = 'missing';
        }
        $userId = (int)($record['uploader_user_id'] ?? 0);
        if ($userId > 0) {
            $user = $pdo->prepare('SELECT 1 FROM users WHERE id=? LIMIT 1');
            $user->execute([$userId]);
            if (!$user->fetchColumn()) $userId = 0;
        }
        $sessionId = (int)($record['session_id'] ?? 0);
        if ($sessionId > 0) {
            $session = $pdo->prepare('SELECT 1 FROM room_sessions WHERE id=? LIMIT 1');
            $session->execute([$sessionId]);
            if (!$session->fetchColumn()) $sessionId = 0;
        }
        $previewName = is_string($record['preview_name'] ?? null) ? (string)$record['preview_name'] : '';
        $previewPath = preg_match('/^[A-Za-z0-9._-]{1,191}$/', $previewName)
            ? $storageDirectory . DIRECTORY_SEPARATOR . $previewName
            : null;
        $legacyPublicPath = is_string($record['legacy_public_path'] ?? null)
            ? (string)$record['legacy_public_path']
            : null;
        $sql = db_uses_mysql_syntax($pdo)
            ? 'INSERT IGNORE INTO server_media_assets (public_id,uploader_user_id,session_id,category,channel_scope,original_name,safe_name,extension,declared_mime,detected_mime,risk_class,risk_detail,byte_size,storage_path,source_sha256,legacy_public_path,audience_json,status,persistent,grandfathered,pinned,expires_at,quarantine_reason,preview_path,preview_bytes,source_owner,source_key,source_role,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            : 'INSERT OR IGNORE INTO server_media_assets (public_id,uploader_user_id,session_id,category,channel_scope,original_name,safe_name,extension,declared_mime,detected_mime,risk_class,risk_detail,byte_size,storage_path,source_sha256,legacy_public_path,audience_json,status,persistent,grandfathered,pinned,expires_at,quarantine_reason,preview_path,preview_bytes,source_owner,source_key,source_role,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
        $statement = $pdo->prepare($sql);
        $statement->execute([
            $publicId,$userId ?: null,$sessionId ?: null,(string)$record['category'],(string)$record['channel_scope'],
            (string)$record['original_name'],(string)$record['safe_name'],(string)$record['extension'],(string)$record['declared_mime'],
            (string)$record['detected_mime'],(string)$record['risk_class'],(string)$record['risk_detail'],(int)$record['byte_size'],$storagePath,
            (string)$record['source_sha256'],$legacyPublicPath,(string)$record['audience_json'],$status,(int)$record['persistent'],(int)$record['grandfathered'],
            (int)$record['pinned'],$record['expires_at'],$record['quarantine_reason'],$previewPath,(int)$record['preview_bytes'],
            (string)($record['source_owner'] ?? 'server-media'),(string)($record['source_key'] ?? ''),(string)($record['source_role'] ?? ''),
            (string)$record['created_at'],(string)$record['updated_at'],
        ]);
        if ($statement->rowCount() > 0) $recovered++;
    }
    foreach (['messages' => 'room','community_messages' => null,'game_chat_messages' => 'game'] as $table => $channel) {
        if (!database_migration_table_exists($pdo, $table)) continue;
        foreach ($pdo->query("SELECT * FROM {$table} WHERE content LIKE '%/api/server_media.php?action=download&id=sm_%'")->fetchAll() as $message) {
            if (!preg_match('/[?&]id=(sm_[a-f0-9]{32})$/', (string)$message['content'], $match)) continue;
            try {
                $asset = server_media_asset_by_public_id($pdo, $match[1]);
                server_media_add_reference($pdo, $match[1], $table, (int)$message['id'], $channel ?? (string)($message['scope'] ?? 'community'), (string)($message['link_key'] ?? $message['lobby_code'] ?? ''));
            } catch (ServerMediaException) {
            }
        }
    }
    return $recovered;
}

function server_media_runtime_reconcile(PDO $pdo, int $minimumIntervalSeconds = 300): array
{
    if (!server_media_schema_valid($pdo)) return ['ran' => false, 'reason' => 'schema-unavailable'];
    $directory = security_private_storage_directory('server-media');
    $lockPath = $directory . DIRECTORY_SEPARATOR . 'runtime-reconcile.lock';
    $hasCompletedRun = is_file($lockPath) && (int)filesize($lockPath) > 0;
    $handle = fopen($lockPath, 'c+b');
    if (!is_resource($handle)) return ['ran' => false, 'reason' => 'lock-unavailable'];
    try {
        if (!flock($handle, LOCK_EX | LOCK_NB)) return ['ran' => false, 'reason' => 'already-running'];
        clearstatcache(true, $lockPath);
        $last = filemtime($lockPath);
        if ($hasCompletedRun && is_int($last) && $last > 0 && time() - $last < max(30, $minimumIntervalSeconds)) {
            return ['ran' => false, 'reason' => 'interval'];
        }
        $sidecars = server_media_reconcile_sidecars($pdo);
        $availability = server_media_reconcile_availability($pdo);
        $retired = server_media_retire_migrated_public_files($pdo);
        $refreshed = server_media_refresh_missing_sidecars($pdo);
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, gmdate('c'));
        fflush($handle);
        touch($lockPath);
        @chmod($lockPath, 0600);
        return [
            'ran' => true,
            'sidecarsReconciled' => $sidecars,
            'availabilityReconciled' => $availability,
            'legacyPublicFilesRetired' => $retired,
            'sidecarsRefreshed' => $refreshed,
        ];
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function server_media_safe_name(string $name): string
{
    $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '');
    $name = preg_replace('#[/\\\\]+#', '_', $name) ?? '';
    $name = preg_replace('/\s+/u', ' ', $name) ?? '';
    if ($name === '' || $name === '.' || $name === '..') $name = 'attachment';
    if (function_exists('mb_substr')) return mb_substr($name, 0, 180, 'UTF-8');
    return substr($name, 0, 180);
}

function server_media_classify(string $path, string $declaredMime, string $originalName, bool $voice = false): array
{
    $safeName = server_media_safe_name($originalName);
    $extension = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
    $detected = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
    $blockedExtensions = ['exe','com','bat','cmd','ps1','vbs','js','mjs','cjs','jar','msi','scr','lnk','url','desktop','app','apk','html','htm','xhtml','svg','sh','bash','zsh','fish','php','phtml','py','pl','rb','reg','cpl','dll','dylib','so'];
    $activeMimes = ['text/html','application/xhtml+xml','image/svg+xml','application/x-msdownload','application/x-msdos-program','application/x-dosexec','application/x-executable','application/x-pie-executable','application/x-sharedlib','application/java-archive','application/javascript','text/javascript','application/x-httpd-php','text/x-shellscript'];
    $declaredMismatch = $declaredMime !== '' && $declaredMime !== 'application/octet-stream' && strcasecmp($declaredMime, $detected) !== 0;
    $expectedMimes = [
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'gif' => ['image/gif'], 'webp' => ['image/webp'],
        'pdf' => ['application/pdf'], 'zip' => ['application/zip','application/x-zip-compressed'],
        'rtf' => ['application/rtf','text/rtf','text/plain'], 'txt' => ['text/plain'], 'csv' => ['text/plain','text/csv'],
        'mp3' => ['audio/mpeg'], 'ogg' => ['audio/ogg','application/ogg'], 'wav' => ['audio/wav','audio/x-wav'],
        'mp4' => ['audio/mp4','video/mp4'], 'webm' => ['audio/webm','video/webm'],
    ];
    $extensionMismatch = isset($expectedMimes[$extension]) && !in_array(strtolower($detected), $expectedMimes[$extension], true);
    $mismatch = $declaredMismatch || $extensionMismatch;
    $doubleExtension = count(array_filter(explode('.', $safeName), static fn(string $part): bool => $part !== '')) > 2;
    $unicodeDeceptive = (bool)preg_match('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{2066}-\x{2069}\x{FEFF}]/u', $safeName);
    $blocked = in_array($extension, $blockedExtensions, true) || in_array(strtolower($detected), $activeMimes, true);
    $isArchive = in_array($extension, ['zip'], true) || strtolower($detected) === 'application/zip';
    $isImage = str_starts_with(strtolower($detected), 'image/') && !$blocked;
    $isAudioVideo = str_starts_with(strtolower($detected), 'audio/') || str_starts_with(strtolower($detected), 'video/');
    $isText = str_starts_with(strtolower($detected), 'text/') && !$blocked;
    $isDocument = in_array($extension, ['pdf','doc','docx','odt','rtf','txt','csv'], true);
    if ($blocked) {
        $risk = 'Blocked by policy';
        $detail = 'Standalone active or executable content is not accepted.';
    } elseif ($unicodeDeceptive || $doubleExtension || $mismatch) {
        $risk = 'Potentially dangerous';
        $detail = 'The filename or detected content does not safely match the declared file type.';
    } elseif ($isArchive) {
        $risk = 'Use caution';
        $detail = 'Archive contents are not extracted or executed. Not scanned for malware.';
    } elseif ($isImage || $isAudioVideo || $isText) {
        $risk = 'Low risk';
        $detail = 'The detected format can use a bounded controlled preview. Not scanned for malware.';
    } elseif ($isDocument) {
        $risk = 'Use caution';
        $detail = 'Documents can contain unsafe content. Not scanned for malware.';
    } else {
        $risk = 'Cannot be inspected';
        $detail = 'Only metadata is available. Not scanned for malware.';
    }
    return [
        'safeName' => $safeName,
        'extension' => $extension,
        'declaredMime' => $declaredMime !== '' ? $declaredMime : 'application/octet-stream',
        'detectedMime' => strtolower($detected),
        'category' => $voice ? 'voice-note' : ($isImage ? 'chat-attachment' : ($isArchive ? 'chat-attachment' : 'chat-attachment')),
        'riskClass' => $risk,
        'riskDetail' => $detail,
        'blocked' => $blocked,
        'mismatch' => $mismatch,
        'doubleExtension' => $doubleExtension,
        'unicodeDeceptive' => $unicodeDeceptive,
        'archive' => $isArchive,
        'previewKind' => $isImage ? 'image' : ($isText ? 'text' : ($isAudioVideo ? 'media' : 'metadata')),
    ];
}

function server_media_inspect_zip(string $path): array
{
    if (!class_exists('ZipArchive')) {
        return ['inspectable' => false, 'encrypted' => false, 'warning' => 'Archive metadata inspection is unavailable. Not scanned for malware.'];
    }
    $zip = new ZipArchive();
    $opened = $zip->open($path, ZipArchive::RDONLY);
    if ($opened !== true) {
        return ['inspectable' => false, 'encrypted' => false, 'warning' => 'Archive metadata is malformed or unavailable. Not scanned for malware.'];
    }
    $entries = [];
    $compressed = 0;
    $uncompressed = 0;
    $encrypted = false;
    $suspiciousPaths = false;
    $activeContent = false;
    $maxEntries = min($zip->numFiles, 1000);
    for ($i = 0; $i < $maxEntries; $i++) {
        $stat = $zip->statIndex($i, ZipArchive::FL_UNCHANGED);
        if (!is_array($stat)) continue;
        $rawName = str_replace('\\', '/', (string)($stat['name'] ?? ''));
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', $rawName) ?? '');
        if ($name === '') $name = 'entry';
        $name = function_exists('mb_substr') ? mb_substr($name, 0, 512, 'UTF-8') : substr($name, 0, 512);
        $extension = strtolower(pathinfo($rawName, PATHINFO_EXTENSION));
        $entryCompressed = max(0, (int)($stat['comp_size'] ?? 0));
        $entrySize = max(0, (int)($stat['size'] ?? 0));
        $compressed += $entryCompressed;
        $uncompressed += $entrySize;
        $entryEncrypted = !empty($stat['encryption_method']);
        $encrypted = $encrypted || $entryEncrypted;
        $pathSuspicious = str_starts_with($rawName, '/') || preg_match('#(^|/)\.\.(/|$)#', $rawName) || preg_match('/^[A-Za-z]:\//', $rawName);
        $suspiciousPaths = $suspiciousPaths || (bool)$pathSuspicious;
        $entryActive = in_array($extension, ['exe','com','bat','cmd','ps1','vbs','js','jar','msi','scr','lnk','html','htm','xhtml','svg'], true);
        $activeContent = $activeContent || $entryActive;
        if (count($entries) < 100) {
            $entries[] = [
                'name' => $name,
                'extension' => $extension,
                'detectedType' => 'Cannot be inspected from central-directory metadata',
                'signatureMismatch' => null,
                'compressedBytes' => $entryCompressed,
                'estimatedUncompressedBytes' => $entrySize,
                'encrypted' => $entryEncrypted,
                'suspiciousPath' => (bool)$pathSuspicious,
                'activeContent' => $entryActive,
            ];
        }
    }
    $fileCount = $zip->numFiles;
    $zip->close();
    $ratio = $compressed > 0 ? $uncompressed / $compressed : ($uncompressed > 0 ? INF : 1.0);
    return [
        'inspectable' => !$encrypted,
        'encrypted' => $encrypted,
        'warning' => $encrypted
            ? 'Encrypted archive — contents cannot be inspected.'
            : 'Archive contents are shown from the validated central directory only. Not scanned for malware.',
        'fileCount' => $fileCount,
        'displayedFileCount' => count($entries),
        'compressedBytes' => $compressed,
        'estimatedUncompressedBytes' => $uncompressed,
        'compressionRatio' => is_finite($ratio) ? round($ratio, 2) : null,
        'extremeRatio' => !is_finite($ratio) || $ratio > 100,
        'suspiciousPaths' => $suspiciousPaths,
        'activeContent' => $activeContent,
        'entries' => $entries,
    ];
}

function server_media_create_image_preview(string $sourcePath, string $targetPath): array
{
    if (!extension_loaded('gd')) return ['path' => null, 'bytes' => 0];
    $dimensions = @getimagesize($sourcePath);
    if (!is_array($dimensions)) return ['path' => null, 'bytes' => 0];
    $width = max(0, (int)($dimensions[0] ?? 0));
    $height = max(0, (int)($dimensions[1] ?? 0));
    if ($width <= 0 || $height <= 0 || ($width * $height) > 40000000) {
        return ['path' => null, 'bytes' => 0];
    }
    $raw = @file_get_contents($sourcePath);
    if (!is_string($raw) || $raw === '') return ['path' => null, 'bytes' => 0];
    $source = @imagecreatefromstring($raw);
    unset($raw);
    if ($source === false) return ['path' => null, 'bytes' => 0];
    $scale = min(1.0, 640 / $width, 640 / $height);
    $previewWidth = max(1, (int)floor($width * $scale));
    $previewHeight = max(1, (int)floor($height * $scale));
    $preview = imagecreatetruecolor($previewWidth, $previewHeight);
    if ($preview === false) {
        imagedestroy($source);
        return ['path' => null, 'bytes' => 0];
    }
    $background = imagecolorallocate($preview, 255, 255, 255);
    imagefill($preview, 0, 0, $background);
    $copied = imagecopyresampled($preview, $source, 0, 0, 0, 0, $previewWidth, $previewHeight, $width, $height);
    imagedestroy($source);
    $written = $copied && imagejpeg($preview, $targetPath, 82);
    imagedestroy($preview);
    if (!$written || !is_file($targetPath)) {
        @unlink($targetPath);
        return ['path' => null, 'bytes' => 0];
    }
    @chmod($targetPath, 0600);
    return ['path' => $targetPath, 'bytes' => max(0, (int)filesize($targetPath))];
}

function server_media_register_owned_asset(
    PDO $pdo,
    string $sourceOwner,
    string $sourceKey,
    string $sourceRole,
    string $category,
    string $path,
    string $originalName,
    string $declaredMime,
    ?int $uploaderUserId,
    bool $grandfathered = false
): string {
    if (!in_array($sourceOwner, ['avatar','gesture'], true)
        || !preg_match('/^[A-Za-z0-9._:\/-]{1,191}$/', $sourceKey)
        || !preg_match('/^[a-z][a-z0-9-]{0,31}$/', $sourceRole)
        || !in_array($category, ['avatar','gesture'], true)
        || !is_file($path)) {
        throw new ServerMediaException('The hosted-media source identity is invalid.', 'SERVER_MEDIA_SOURCE_INVALID', 500);
    }
    $hash = strtoupper((string)hash_file('sha256', $path));
    $bytes = max(0, (int)filesize($path));
    $classification = server_media_classify($path, $declaredMime, $originalName, false);
    $publicId = 'sm_' . substr(hash('sha256', implode('|', [$sourceOwner,$sourceKey,$sourceRole,$hash])), 0, 32);
    $existing = $pdo->prepare('SELECT id FROM server_media_assets WHERE public_id=? LIMIT 1');
    $existing->execute([$publicId]);
    $existingId = (int)$existing->fetchColumn();
    if ($existingId > 0) {
        server_media_refresh_sidecar($pdo, $existingId);
        return $publicId;
    }
    $preview = ['path' => null, 'bytes' => 0];
    if ($classification['previewKind'] === 'image') {
        $preview = server_media_create_image_preview(
            $path,
            security_private_storage_directory('server-media') . DIRECTORY_SEPARATOR . $publicId . '.preview.jpg'
        );
    }
    $sql = db_uses_mysql_syntax($pdo)
        ? 'INSERT IGNORE INTO server_media_assets (public_id,uploader_user_id,session_id,category,channel_scope,original_name,safe_name,extension,declared_mime,detected_mime,risk_class,risk_detail,byte_size,storage_path,source_sha256,legacy_public_path,audience_json,status,persistent,grandfathered,pinned,expires_at,quarantine_reason,preview_path,preview_bytes,source_owner,source_key,source_role) VALUES (?,?,NULL,?,?,?,?,?,?,?,?,?,?,?,?,NULL,\'[]\',\'active\',1,?,0,NULL,NULL,?,?,?,?,?)'
        : 'INSERT OR IGNORE INTO server_media_assets (public_id,uploader_user_id,session_id,category,channel_scope,original_name,safe_name,extension,declared_mime,detected_mime,risk_class,risk_detail,byte_size,storage_path,source_sha256,legacy_public_path,audience_json,status,persistent,grandfathered,pinned,expires_at,quarantine_reason,preview_path,preview_bytes,source_owner,source_key,source_role) VALUES (?,?,NULL,?,?,?,?,?,?,?,?,?,?,?,?,NULL,\'[]\',\'active\',1,?,0,NULL,NULL,?,?,?,?,?)';
    try {
        $statement = $pdo->prepare($sql);
        $statement->execute([
            $publicId,$uploaderUserId && $uploaderUserId > 0 ? $uploaderUserId : null,
            $category,$category,$originalName,$classification['safeName'],$classification['extension'],
            $classification['declaredMime'],$classification['detectedMime'],$classification['riskClass'],$classification['riskDetail'],
            $bytes,$path,$hash,$grandfathered ? 1 : 0,$preview['path'],(int)$preview['bytes'],$sourceOwner,$sourceKey,$sourceRole,
        ]);
        $assetId = (int)$pdo->lastInsertId();
        if ($assetId <= 0) {
            $existing->execute([$publicId]);
            $assetId = (int)$existing->fetchColumn();
        }
        if ($assetId <= 0) throw new ServerMediaException('The hosted-media inventory record could not be created.', 'SERVER_MEDIA_SOURCE_REGISTER_FAILED', 500);
        server_media_refresh_sidecar($pdo, $assetId);
    } catch (Throwable $error) {
        if (is_string($preview['path'])) @unlink($preview['path']);
        throw $error;
    }
    return $publicId;
}

function server_media_register_avatar(PDO $pdo, int $userId, string $publicPath, string $absolutePath, string $declaredMime, bool $grandfathered = false): string
{
    if (!preg_match('#^/assets/uploads/avatars/[A-Za-z0-9._-]+$#', $publicPath)) {
        throw new ServerMediaException('The avatar inventory path is invalid.', 'SERVER_MEDIA_AVATAR_SOURCE_INVALID', 500);
    }
    return server_media_register_owned_asset(
        $pdo,
        'avatar',
        $publicPath,
        'avatar',
        'avatar',
        $absolutePath,
        basename($publicPath),
        $declaredMime,
        $userId,
        $grandfathered
    );
}

function server_media_gesture_public_id_valid(string $publicId): bool
{
    return preg_match('/\A[A-Za-z0-9._:-]{1,64}\z/', $publicId) === 1;
}

function server_media_register_gesture_generation(PDO $pdo, string $publicId, int $generation, bool $grandfathered = false): int
{
    if (!server_media_gesture_public_id_valid($publicId) || $generation < 1) {
        throw new ServerMediaException('The gesture inventory identity is invalid.', 'SERVER_MEDIA_GESTURE_SOURCE_INVALID', 500);
    }
    $stmt = $pdo->prepare(
        'SELECT g.owner_user_id,pg.* FROM gestures g JOIN gesture_package_generations pg ON pg.gesture_id=g.id '
        . 'WHERE g.public_id=? AND pg.generation=? LIMIT 1'
    );
    $stmt->execute([$publicId,$generation]);
    $row = $stmt->fetch();
    if (!is_array($row)) return 0;
    $registered = 0;
    $roles = [
        'package' => ['mime' => 'application/zip', 'extension' => 'agst'],
        'animation' => ['mime' => (string)($row['animation_mime'] ?? 'image/gif'), 'extension' => 'gif'],
        'poster' => ['mime' => (string)($row['poster_mime'] ?? 'application/octet-stream'), 'extension' => 'bin'],
        'audio' => ['mime' => (string)($row['audio_mime'] ?? 'audio/mpeg'), 'extension' => 'mp3'],
    ];
    foreach ($roles as $role => $metadata) {
        $storageName = (string)($row[$role . '_storage_name'] ?? '');
        if ($storageName === '' || str_starts_with($storageName, 'legacy:') || basename($storageName) !== $storageName) continue;
        $path = gesture_package_storage_root() . DIRECTORY_SEPARATOR . $storageName;
        if (!is_file($path)) continue;
        server_media_register_owned_asset(
            $pdo,
            'gesture',
            $publicId . ':' . $generation,
            $role,
            'gesture',
            $path,
            $storageName,
            $metadata['mime'],
            (int)$row['owner_user_id'],
            $grandfathered
        );
        $registered++;
    }
    return $registered;
}

function server_media_inventory_owned_uploads(PDO $pdo): array
{
    $avatars = 0;
    if (database_migration_table_exists($pdo, 'users')) {
        foreach ($pdo->query("SELECT id,avatar_path FROM users WHERE avatar_path LIKE '/assets/uploads/avatars/%' ORDER BY id")->fetchAll() as $user) {
            $publicPath = (string)$user['avatar_path'];
            $path = avatar_source_file($publicPath);
            if ($path === null) continue;
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
            server_media_register_avatar($pdo, (int)$user['id'], $publicPath, $path, $mime, true);
            $avatars++;
        }
    }
    $gestureFiles = 0;
    if (database_migration_table_exists($pdo, 'gestures') && database_migration_table_exists($pdo, 'gesture_package_generations')) {
        foreach ($pdo->query('SELECT g.public_id,pg.generation FROM gestures g JOIN gesture_package_generations pg ON pg.gesture_id=g.id ORDER BY g.id,pg.generation')->fetchAll() as $row) {
            $gestureFiles += server_media_register_gesture_generation($pdo, (string)$row['public_id'], (int)$row['generation'], true);
        }
    }
    return ['inventoriedAvatars' => $avatars, 'inventoriedGestureFiles' => $gestureFiles];
}

function server_media_policy(PDO $pdo): array
{
    $modes = ['server-only','p2p-only','both','neither'];
    $fileMode = app_setting($pdo, SERVER_MEDIA_FILE_MODE, 'p2p-only');
    $gestureMode = app_setting($pdo, SERVER_MEDIA_GESTURE_MODE, 'both');
    if (!in_array($fileMode, $modes, true)) $fileMode = 'p2p-only';
    if (!in_array($gestureMode, $modes, true)) $gestureMode = 'both';
    return [
        'serverAttachmentsEnabled' => app_setting($pdo, SERVER_MEDIA_ATTACHMENTS_ENABLED, '0') === '1',
        'serverVoiceNotesEnabled' => app_setting($pdo, SERVER_MEDIA_VOICE_NOTES_ENABLED, '0') === '1',
        'fileMode' => $fileMode,
        'sendGestureMode' => $gestureMode,
        'p2pFilesEnabled' => in_array($fileMode, ['p2p-only','both'], true),
        'p2pSendGestureEnabled' => in_array($gestureMode, ['p2p-only','both'], true),
        'defaultDelivery' => 'p2p',
        'noSilentFallback' => true,
        'retentionHours' => max(1, min(8760, (int)app_setting($pdo, SERVER_MEDIA_RETENTION_HOURS, '24'))),
        'limits' => [
            'imageBytes' => app_setting_bytes($pdo, SERVER_MEDIA_IMAGE_MAX_MB, 10),
            'documentBytes' => app_setting_bytes($pdo, SERVER_MEDIA_DOCUMENT_MAX_MB, 20),
            'voiceNoteBytes' => app_setting_bytes($pdo, SERVER_MEDIA_VOICE_MAX_MB, 10),
            'userRolling24HoursBytes' => app_setting_bytes($pdo, SERVER_MEDIA_USER_DAILY_MB, 100),
            'installationStorageBytes' => app_setting_bytes($pdo, SERVER_MEDIA_INSTALLATION_STORAGE_MB, 2048),
            'monthlyDeliveryBytes' => app_setting_bytes($pdo, SERVER_MEDIA_MONTHLY_DELIVERY_MB, 5120),
            'warningLowPercent' => (int)app_setting($pdo, SERVER_MEDIA_WARNING_LOW_PERCENT, '75'),
            'warningHighPercent' => (int)app_setting($pdo, SERVER_MEDIA_WARNING_HIGH_PERCENT, '90'),
            'hardStopPercent' => (int)app_setting($pdo, SERVER_MEDIA_HARD_STOP_PERCENT, '100'),
        ],
        'uploadNotice' => 'Server upload notice: Files stored by this community may be accessed and reviewed by its administrators to investigate abuse, enforce community rules, and address safety or legal concerns. Use peer-to-peer transfer if you do not want the file stored on this server.',
    ];
}

function server_media_storage_usage(PDO $pdo): int
{
    return (int)$pdo->query("SELECT COALESCE(SUM(byte_size + preview_bytes),0) FROM server_media_assets WHERE status NOT IN ('deleted','expired')")->fetchColumn();
}

function server_media_user_rolling_usage(PDO $pdo, int $userId): int
{
    $threshold = gmdate('Y-m-d H:i:s', time() - 86400);
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(byte_size),0) FROM server_media_assets WHERE uploader_user_id=? AND created_at>=? AND grandfathered=0");
    $stmt->execute([$userId, $threshold]);
    return (int)$stmt->fetchColumn();
}

function server_media_monthly_delivery_usage(PDO $pdo): int
{
    $threshold = gmdate('Y-m-01 00:00:00');
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(byte_count),0) FROM server_media_delivery_usage WHERE delivered_at>=?');
    $stmt->execute([$threshold]);
    return (int)$stmt->fetchColumn();
}

function server_media_usage_summary(PDO $pdo): array
{
    $policy = server_media_policy($pdo);
    $storage = server_media_storage_usage($pdo);
    $delivery = server_media_monthly_delivery_usage($pdo);
    $classify = static function(int $used, int $limit) use ($policy): array {
        $percent = $limit > 0 ? round(($used / $limit) * 100, 2) : 100.0;
        $status = $percent >= $policy['limits']['hardStopPercent']
            ? 'hard-stop'
            : ($percent >= $policy['limits']['warningHighPercent']
                ? 'urgent-warning'
                : ($percent >= $policy['limits']['warningLowPercent'] ? 'warning' : 'ok'));
        return ['usedBytes' => $used, 'limitBytes' => $limit, 'percent' => $percent, 'status' => $status];
    };
    return [
        'storage' => $classify($storage, (int)$policy['limits']['installationStorageBytes']),
        'monthlyDelivery' => $classify($delivery, (int)$policy['limits']['monthlyDeliveryBytes']),
        'warningLowPercent' => $policy['limits']['warningLowPercent'],
        'warningHighPercent' => $policy['limits']['warningHighPercent'],
        'hardStopPercent' => $policy['limits']['hardStopPercent'],
    ];
}

function server_media_lock_quota_owner(PDO $pdo): void
{
    if (!db_uses_mysql_syntax($pdo)) return;
    $lock = $pdo->prepare('SELECT value FROM app_settings WHERE setting_key=? LIMIT 1 FOR UPDATE');
    $lock->execute([SERVER_MEDIA_INSTALLATION_STORAGE_MB]);
    if ($lock->fetchColumn() === false) {
        throw new ServerMediaException('The server-file allowance owner is unavailable.', 'SERVER_MEDIA_QUOTA_OWNER_MISSING', 500);
    }
}

function server_media_upload(PDO $pdo, array $file, array $actor, int $sessionId, string $channel, bool $voiceNote, array $audience = [], array $options = []): array
{
    $policy = server_media_policy($pdo);
    $evidence = ($options['category'] ?? '') === 'moderation-evidence';
    if (!$evidence && ($voiceNote ? !$policy['serverVoiceNotesEnabled'] : !$policy['serverAttachmentsEnabled'])) {
        throw new ServerMediaException(
            $voiceNote ? 'Server voice notes are disabled.' : 'Server chat attachments are disabled.',
            'SERVER_MEDIA_DISABLED',
            403
        );
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
        throw new ServerMediaException('A valid uploaded file is required.', 'SERVER_MEDIA_UPLOAD_INVALID', 400);
    }
    $path = (string)$file['tmp_name'];
    $declaredMime = trim((string)($file['type'] ?? 'application/octet-stream'));
    $classification = server_media_classify($path, $declaredMime, (string)($file['name'] ?? 'attachment'), $voiceNote);
    if ($voiceNote && !str_starts_with((string)$classification['detectedMime'], 'audio/')
        && !str_starts_with((string)$classification['detectedMime'], 'video/')) {
        throw new ServerMediaException('The voice note is not a supported audio format.', 'SERVER_MEDIA_VOICE_FORMAT_INVALID', 415, $classification);
    }
    if ($classification['blocked']) {
        throw new ServerMediaException('This file type is blocked by policy.', 'SERVER_MEDIA_BLOCKED_TYPE', 415, $classification);
    }
    $bytes = max(0, (int)($file['size'] ?? filesize($path) ?: 0));
    $maxBytes = isset($options['maxBytes'])
        ? max(1, (int)$options['maxBytes'])
        : ($voiceNote
        ? $policy['limits']['voiceNoteBytes']
        : (str_starts_with($classification['detectedMime'], 'image/') ? $policy['limits']['imageBytes'] : $policy['limits']['documentBytes']));
    if ($bytes <= 0 || $bytes > $maxBytes) {
        throw new ServerMediaException('The file exceeds the configured per-file limit.', 'SERVER_MEDIA_FILE_LIMIT', 413, ['maxBytes' => $maxBytes]);
    }
    $userId = (int)($actor['user_id'] ?? $actor['id'] ?? 0);
    if ($userId <= 0) throw new ServerMediaException('Authenticated uploader required.', 'SERVER_MEDIA_AUTH_REQUIRED', 403);
    if (server_media_user_rolling_usage($pdo, $userId) + $bytes > $policy['limits']['userRolling24HoursBytes']) {
        throw new ServerMediaException('The rolling 24-hour server upload allowance has been reached.', 'SERVER_MEDIA_USER_ALLOWANCE', 429);
    }
    $storageHardLimit = (int)floor($policy['limits']['installationStorageBytes'] * ($policy['limits']['hardStopPercent'] / 100));
    if (server_media_storage_usage($pdo) + $bytes > $storageHardLimit) {
        throw new ServerMediaException('The installation server-file allowance has been reached.', 'SERVER_MEDIA_STORAGE_ALLOWANCE', 507);
    }
    $publicId = 'sm_' . bin2hex(random_bytes(16));
    $extension = $classification['extension'] !== '' ? $classification['extension'] : 'bin';
    $directory = security_private_storage_directory('server-media');
    $target = $directory . DIRECTORY_SEPARATOR . $publicId . '.' . preg_replace('/[^a-z0-9]+/', '', $extension);
    if (!move_uploaded_file($path, $target)) {
        throw new ServerMediaException('The file could not be stored.', 'SERVER_MEDIA_STORE_FAILED', 500);
    }
    @chmod($target, 0600);
    $preview = ['path' => null, 'bytes' => 0];
    if ($classification['previewKind'] === 'image') {
        $preview = server_media_create_image_preview($target, $directory . DIRECTORY_SEPARATOR . $publicId . '.preview.jpg');
    }
    $hash = strtoupper(hash_file('sha256', $target));
    $persistent = $evidence ? 1 : 0;
    $expires = $persistent ? null : gmdate('Y-m-d H:i:s', time() + ($policy['retentionHours'] * 3600));
    $audienceJson = json_encode(array_values(array_unique(array_map('intval', $audience))), JSON_UNESCAPED_SLASHES);
    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) {
            if (db_uses_mysql_syntax($pdo)) $pdo->beginTransaction();
            else $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
        }
        server_media_lock_quota_owner($pdo);
        if (server_media_user_rolling_usage($pdo, $userId) + $bytes > $policy['limits']['userRolling24HoursBytes']) {
            throw new ServerMediaException('The rolling 24-hour server upload allowance has been reached.', 'SERVER_MEDIA_USER_ALLOWANCE', 429);
        }
        if (server_media_storage_usage($pdo) + $bytes + (int)$preview['bytes'] > $storageHardLimit) {
            throw new ServerMediaException('The installation server-file allowance has been reached.', 'SERVER_MEDIA_STORAGE_ALLOWANCE', 507);
        }
        $stmt = $pdo->prepare(
            'INSERT INTO server_media_assets
             (public_id,uploader_user_id,session_id,category,channel_scope,original_name,safe_name,extension,declared_mime,detected_mime,risk_class,risk_detail,byte_size,storage_path,source_sha256,audience_json,status,persistent,grandfathered,pinned,expires_at,preview_path,preview_bytes)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'active\',?,0,0,?,?,?)'
        );
        $stmt->execute([
            $publicId, $userId, $sessionId > 0 ? $sessionId : null,
            $evidence ? 'moderation-evidence' : ($voiceNote ? 'voice-note' : 'chat-attachment'), $channel,
            (string)($file['name'] ?? 'attachment'), $classification['safeName'], $extension,
            $classification['declaredMime'], $classification['detectedMime'], $classification['riskClass'],
            $classification['riskDetail'], $bytes, $target, $hash, $audienceJson ?: '[]', $persistent, $expires,
            $preview['path'], (int)$preview['bytes'],
        ]);
        $assetId = (int)$pdo->lastInsertId();
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        @unlink($target);
        if (is_string($preview['path'])) @unlink($preview['path']);
        throw $error;
    }
    server_media_refresh_sidecar($pdo, $assetId);
    return server_media_project_asset($pdo, server_media_asset_by_id($pdo, $assetId), $actor, false);
}

function server_media_asset_by_id(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM server_media_assets WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!is_array($row)) throw new ServerMediaException('The file record is unavailable.', 'SERVER_MEDIA_NOT_FOUND', 404);
    return $row;
}

function server_media_asset_by_public_id(PDO $pdo, string $publicId): array
{
    $stmt = $pdo->prepare('SELECT * FROM server_media_assets WHERE public_id=? LIMIT 1');
    $stmt->execute([$publicId]);
    $row = $stmt->fetch();
    if (!is_array($row)) throw new ServerMediaException('The file record is unavailable.', 'SERVER_MEDIA_NOT_FOUND', 404);
    return $row;
}

function server_media_discard_unreferenced(PDO $pdo, string $publicId): void
{
    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) {
            if (db_uses_mysql_syntax($pdo)) $pdo->beginTransaction();
            else $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
        }
        $assetSql = 'SELECT * FROM server_media_assets WHERE public_id=? LIMIT 1';
        if (db_uses_mysql_syntax($pdo)) $assetSql .= ' FOR UPDATE';
        $assetStatement = $pdo->prepare($assetSql);
        $assetStatement->execute([$publicId]);
        $asset = $assetStatement->fetch();
        if (!is_array($asset)) throw new ServerMediaException('The file record is unavailable.', 'SERVER_MEDIA_NOT_FOUND', 404);
        $references = $pdo->prepare('SELECT COUNT(*) FROM server_media_references WHERE asset_id=? AND active=1');
        $references->execute([(int)$asset['id']]);
        if ((int)$references->fetchColumn() > 0) {
            if ($ownsTransaction) $pdo->commit();
            return;
        }
        $pdo->prepare("UPDATE server_media_assets SET status='deleted',updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='active'")
            ->execute([(int)$asset['id']]);
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    if (is_file((string)$asset['storage_path'])) @unlink((string)$asset['storage_path']);
    if (is_string($asset['preview_path']) && is_file($asset['preview_path'])) @unlink($asset['preview_path']);
    server_media_refresh_sidecar($pdo, (int)$asset['id']);
}

function server_media_is_admin(array $actor): bool
{
    return (string)($actor['role'] ?? '') === 'admin';
}

function server_media_actor_can_access(PDO $pdo, array $asset, array $actor, bool $adminReview = false): bool
{
    $userId = (int)($actor['id'] ?? $actor['user_id'] ?? 0);
    if ($userId <= 0 || in_array((string)$asset['status'], ['deleted','expired','quarantined'], true) && !$adminReview) return false;
    if (server_media_is_admin($actor) && $adminReview) return true;
    if ((int)($asset['uploader_user_id'] ?? 0) === $userId) return true;
    $audience = json_decode((string)($asset['audience_json'] ?? '[]'), true);
    if (is_array($audience) && in_array($userId, array_map('intval', $audience), true)) {
        $uploaderUserId = (int)($asset['uploader_user_id'] ?? 0);
        if ($uploaderUserId > 0 && p2p_transfer_blocked($pdo, $uploaderUserId, $userId)) return false;
        if ((string)$asset['channel_scope'] === 'link') {
            $reference = $pdo->prepare("SELECT message_id FROM server_media_references WHERE asset_id=? AND message_table='community_messages' AND active=1 ORDER BY id LIMIT 1");
            $reference->execute([(int)$asset['id']]);
            $messageId = (int)$reference->fetchColumn();
            $message = $pdo->prepare('SELECT * FROM community_messages WHERE id=? AND COALESCE(is_deleted,0)=0 LIMIT 1');
            $message->execute([$messageId]);
            $messageRow = $message->fetch();
            $participant = $pdo->prepare('SELECT id FROM participants WHERE session_id=? AND user_id=? LIMIT 1');
            $participant->execute([(int)($asset['session_id'] ?? 0), $userId]);
            $participantId = (int)$participant->fetchColumn();
            if (!is_array($messageRow) || $participantId <= 0
                || avatar_relationship_chat_message_accessible($pdo, $messageRow, (int)$asset['session_id'], $participantId) === null) {
                return false;
            }
        }
        return true;
    }
    if ((string)$asset['channel_scope'] === 'community') return true;
    if ((string)$asset['channel_scope'] === 'room' && (int)($asset['session_id'] ?? 0) > 0) {
        $stmt = $pdo->prepare('SELECT 1 FROM participants WHERE session_id=? AND user_id=? LIMIT 1');
        $stmt->execute([(int)$asset['session_id'], $userId]);
        return (bool)$stmt->fetchColumn();
    }
    return false;
}

function server_media_project_asset(PDO $pdo, array $asset, array $actor, bool $adminReview): array
{
    if (!server_media_actor_can_access($pdo, $asset, $actor, $adminReview)) {
        throw new ServerMediaException('The file is unavailable.', 'SERVER_MEDIA_ACCESS_DENIED', 403);
    }
    $archive = null;
    if (strtolower((string)$asset['extension']) === 'zip' && is_file((string)$asset['storage_path'])) {
        $archive = server_media_inspect_zip((string)$asset['storage_path']);
    }
    $uploaderLabel = 'Unknown — legacy record';
    if ($asset['uploader_user_id'] !== null) {
        $uploader = $pdo->prepare('SELECT display_name FROM users WHERE id=? LIMIT 1');
        $uploader->execute([(int)$asset['uploader_user_id']]);
        $uploaderLabel = trim((string)$uploader->fetchColumn()) ?: 'Deleted account';
    }
    $referenceStatement = $pdo->prepare('SELECT message_table,message_id,channel_scope,relationship_key FROM server_media_references WHERE asset_id=? AND active=1 ORDER BY id LIMIT 1');
    $referenceStatement->execute([(int)$asset['id']]);
    $primaryReference = $referenceStatement->fetch() ?: null;
    $referenceCountStatement = $pdo->prepare('SELECT COUNT(*) FROM server_media_references WHERE asset_id=? AND active=1');
    $referenceCountStatement->execute([(int)$asset['id']]);
    $exists = is_file((string)$asset['storage_path']);
    $previewKind = 'metadata';
    if ($exists) {
        $previewKind = server_media_classify((string)$asset['storage_path'], (string)$asset['declared_mime'], (string)$asset['safe_name'], (string)$asset['category'] === 'voice-note')['previewKind'];
        if ($previewKind === 'image' && (!is_string($asset['preview_path']) || !is_file((string)$asset['preview_path']))) {
            $previewKind = 'metadata';
        }
    }
    return [
        'id' => (string)$asset['public_id'],
        'category' => (string)$asset['category'],
        'channel' => (string)$asset['channel_scope'],
        'safeName' => (string)$asset['safe_name'],
        'name' => (string)$asset['safe_name'],
        'extension' => (string)$asset['extension'],
        'declaredMime' => (string)$asset['declared_mime'],
        'detectedMime' => (string)$asset['detected_mime'],
        'size' => (int)$asset['byte_size'],
        'sizeBytes' => (int)$asset['byte_size'],
        'risk' => (string)$asset['risk_class'],
        'riskDetail' => (string)$asset['risk_detail'],
        'malwareStatus' => 'Not scanned for malware',
        'status' => (string)$asset['status'],
        'pinned' => (bool)$asset['pinned'],
        'persistent' => (bool)$asset['persistent'],
        'grandfathered' => (bool)$asset['grandfathered'],
        'createdAt' => (string)$asset['created_at'],
        'expiresAt' => $asset['expires_at'],
        'downloadUrl' => app_url('/api/server_media.php?action=download&id=' . rawurlencode((string)$asset['public_id'])),
        'previewUrl' => app_url('/api/server_media.php?action=preview&id=' . rawurlencode((string)$asset['public_id'])),
        'previewKind' => $previewKind,
        'archive' => $archive,
        'uploaderUserId' => $adminReview ? ($asset['uploader_user_id'] !== null ? (int)$asset['uploader_user_id'] : null) : null,
        'uploaderLabel' => $uploaderLabel,
        'referenceCount' => (int)$referenceCountStatement->fetchColumn(),
        'messageId' => is_array($primaryReference) ? (int)$primaryReference['message_id'] : null,
        'messageTable' => is_array($primaryReference) ? (string)$primaryReference['message_table'] : null,
        'reviewState' => (string)$asset['status'],
        'removeOwnAllowed' => (int)($asset['uploader_user_id'] ?? 0) === (int)($actor['id'] ?? $actor['user_id'] ?? 0) && (string)$asset['status'] === 'active',
        'available' => $exists && (string)$asset['status'] === 'active',
    ];
}

function server_media_add_reference(PDO $pdo, string $publicId, string $messageTable, int $messageId, string $channel, string $relationshipKey = ''): void
{
    if (!in_array($messageTable, ['messages','community_messages','game_chat_messages'], true) || $messageId <= 0) {
        throw new ServerMediaException('The file message reference is invalid.', 'SERVER_MEDIA_REFERENCE_INVALID', 500);
    }
    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) {
            if (db_uses_mysql_syntax($pdo)) $pdo->beginTransaction();
            else $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
        }
        $assetSql = 'SELECT * FROM server_media_assets WHERE public_id=? LIMIT 1';
        if (db_uses_mysql_syntax($pdo)) $assetSql .= ' FOR UPDATE';
        $assetStatement = $pdo->prepare($assetSql);
        $assetStatement->execute([$publicId]);
        $asset = $assetStatement->fetch();
        if (!is_array($asset)) throw new ServerMediaException('The file record is unavailable.', 'SERVER_MEDIA_NOT_FOUND', 404);
        if ((string)$asset['status'] !== 'active') {
            throw new ServerMediaException('The file can no longer be attached.', 'SERVER_MEDIA_REFERENCE_UNAVAILABLE', 409);
        }
        $sql = db_uses_mysql_syntax($pdo)
            ? 'INSERT IGNORE INTO server_media_references (asset_id,message_table,message_id,channel_scope,relationship_key,active) VALUES (?,?,?,?,?,1)'
            : 'INSERT OR IGNORE INTO server_media_references (asset_id,message_table,message_id,channel_scope,relationship_key,active) VALUES (?,?,?,?,?,1)';
        $pdo->prepare($sql)->execute([(int)$asset['id'],$messageTable,$messageId,$channel,$relationshipKey]);
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function server_media_list(PDO $pdo, array $actor, string $view, int $sessionId = 0, int $page = 1, int $pageSize = 30, array $filters = []): array
{
    $userId = (int)($actor['id'] ?? 0);
    $admin = server_media_is_admin($actor) && $view === 'admin';
    if ($view === 'admin' && !$admin) throw new ServerMediaException('Administrator authorization is required.', 'SERVER_MEDIA_ADMIN_REQUIRED', 403);
    $where = [];
    $params = [];
    if ($admin) {
        if (!empty($filters['section'])) {
            $sectionMap = [
                'chat-attachments' => ['chat-attachment','moderation-evidence'],
                'voice-notes' => ['voice-note'],
                'avatars' => ['avatar'],
                'gestures' => ['gesture'],
                'legacy' => ['legacy','unclassified'],
            ];
            if (($filters['section'] ?? '') === 'cleanup-needed') {
                $where[] = "(status IN ('missing','expired','quarantined') OR public_id IN (SELECT a.public_id FROM server_media_assets a LEFT JOIN server_media_references r ON r.asset_id=a.id AND r.active=1 WHERE r.id IS NULL AND a.grandfathered=1))";
            } elseif (isset($sectionMap[$filters['section']])) {
                $placeholders = implode(',', array_fill(0, count($sectionMap[$filters['section']]), '?'));
                $where[] = "category IN ({$placeholders})";
                $params = array_merge($params, $sectionMap[$filters['section']]);
            }
        }
    } elseif ($view === 'community') {
        $where[] = "channel_scope='community'";
        $where[] = "status='active'";
    } elseif ($view === 'my-uploads') {
        $where[] = 'uploader_user_id=?';
        $params[] = $userId;
        $where[] = "status='active'";
    } else {
        $where[] = "channel_scope='room'";
        $where[] = 'session_id=?';
        $params[] = $sessionId;
        $where[] = "status='active'";
    }
    if (!$admin) {
        $where[] = "category IN ('chat-attachment','voice-note')";
        $where[] = '(expires_at IS NULL OR expires_at>=CURRENT_TIMESTAMP OR pinned=1 OR grandfathered=1)';
    }
    $query = trim((string)($filters['query'] ?? ''));
    if ($query !== '') {
        $where[] = '(safe_name LIKE ? OR extension LIKE ? OR detected_mime LIKE ? OR risk_class LIKE ? OR channel_scope LIKE ? OR category LIKE ? OR COALESCE((SELECT display_name FROM users WHERE id=server_media_assets.uploader_user_id),\'\') LIKE ?)';
        $term = '%' . str_replace(['%','_'], ['\\%','\\_'], $query) . '%';
        array_push($params, $term, $term, $term, $term, $term, $term, $term);
    }
    foreach (['extension','detected_mime','channel_scope','status'] as $filterKey) {
        $filterValue = trim((string)($filters[$filterKey] ?? ''));
        if ($filterValue === '') continue;
        $where[] = "{$filterKey} LIKE ?";
        $params[] = '%' . str_replace(['%','_'], ['\\%','\\_'], $filterValue) . '%';
    }
    $pinnedFilter = (string)($filters['pinned'] ?? '');
    if (in_array($pinnedFilter, ['0','1'], true)) {
        $where[] = 'pinned=?';
        $params[] = (int)$pinnedFilter;
    }
    $referenceFilter = (string)($filters['references'] ?? '');
    if ($referenceFilter === 'referenced') $where[] = '(SELECT COUNT(*) FROM server_media_references WHERE asset_id=server_media_assets.id AND active=1)>0';
    elseif ($referenceFilter === 'unreferenced') $where[] = '(SELECT COUNT(*) FROM server_media_references WHERE asset_id=server_media_assets.id AND active=1)=0';
    $uploaderFilter = trim((string)($filters['uploader'] ?? ''));
    if ($uploaderFilter !== '') {
        $where[] = "COALESCE((SELECT display_name FROM users WHERE id=server_media_assets.uploader_user_id),'Unknown — legacy record') LIKE ?";
        $params[] = '%' . str_replace(['%','_'], ['\\%','\\_'], $uploaderFilter) . '%';
    }
    $sortMap = [
        'name' => 'safe_name', 'extension' => 'extension', 'detected-type' => 'detected_mime',
        'size' => 'byte_size', 'date' => 'created_at', 'expiry' => 'expires_at',
        'status' => 'status', 'risk' => 'risk_class', 'category' => 'category',
        'channel' => 'channel_scope', 'pinned' => 'pinned',
        'uploader' => '(SELECT display_name FROM users WHERE id=server_media_assets.uploader_user_id)',
        'references' => '(SELECT COUNT(*) FROM server_media_references WHERE asset_id=server_media_assets.id AND active=1)',
    ];
    $sort = $sortMap[(string)($filters['sort'] ?? 'date')] ?? 'created_at';
    $direction = strtolower((string)($filters['direction'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $pageSize = max(1, min(100, $pageSize));
    $page = max(1, $page);
    $offset = ($page - 1) * $pageSize;
    $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $count = $pdo->prepare('SELECT COUNT(*) FROM server_media_assets' . $sqlWhere);
    $count->execute($params);
    $statement = $pdo->prepare('SELECT * FROM server_media_assets' . $sqlWhere . " ORDER BY {$sort} {$direction},id DESC LIMIT {$pageSize} OFFSET {$offset}");
    $statement->execute($params);
    $items = [];
    foreach ($statement->fetchAll() as $asset) {
        if ($admin || server_media_actor_can_access($pdo, $asset, $actor, false)) {
            $items[] = server_media_project_asset($pdo, $asset, $actor, $admin);
        }
    }
    return [
        'view' => $view,
        'items' => $items,
        'page' => $page,
        'pageSize' => $pageSize,
        'total' => (int)$count->fetchColumn(),
        'sections' => ['Chat Attachments','Voice Notes','Avatars','Gestures','Legacy/Unclassified','Cleanup Needed'],
    ];
}

function server_media_reconcile_availability(PDO $pdo, int $limit = 500): int
{
    $changed = 0;
    $limit = max(1, min(2000, $limit));
    foreach ($pdo->query("SELECT id,storage_path,status FROM server_media_assets WHERE status='active' ORDER BY id LIMIT {$limit}")->fetchAll() as $asset) {
        if (is_file((string)$asset['storage_path'])) continue;
        $pdo->prepare("UPDATE server_media_assets SET status='missing',updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='active'")
            ->execute([(int)$asset['id']]);
        server_media_refresh_sidecar($pdo, (int)$asset['id']);
        $changed++;
    }
    return $changed;
}

function server_media_prepare_owned_quarantine(array $asset): ?array
{
    if (!in_array((string)($asset['source_owner'] ?? ''), ['avatar','gesture'], true)
        || !is_file((string)$asset['storage_path'])) return null;
    $extension = preg_replace('/[^a-z0-9]+/', '', strtolower((string)$asset['extension'])) ?: 'bin';
    $target = security_private_storage_directory('server-media-quarantine')
        . DIRECTORY_SEPARATOR . (string)$asset['public_id'] . '.' . $extension;
    if (is_file($target)) {
        throw new ServerMediaException(
            'The quarantine destination already exists and must be reconciled before this file can be moved.',
            'SERVER_MEDIA_QUARANTINE_CONFLICT',
            409
        );
    }
    if (!@rename((string)$asset['storage_path'], $target)) {
        throw new ServerMediaException('The file could not be moved into protected quarantine.', 'SERVER_MEDIA_QUARANTINE_MOVE_FAILED', 500);
    }
    @chmod($target, 0600);
    if (!hash_equals((string)$asset['source_sha256'], strtoupper((string)hash_file('sha256', $target)))) {
        @rename($target, (string)$asset['storage_path']);
        throw new ServerMediaException('The quarantined file did not preserve its exact bytes.', 'SERVER_MEDIA_QUARANTINE_HASH_FAILED', 500);
    }
    return ['source' => (string)$asset['storage_path'], 'target' => $target, 'moved' => true];
}

function server_media_restore_owned_quarantine(?array $move): void
{
    if (!$move || empty($move['moved']) || !is_file((string)$move['target'])) return;
    @rename((string)$move['target'], (string)$move['source']);
}

function server_media_disable_owned_source(PDO $pdo, array $asset): array
{
    $result = ['gestureCleanup' => null, 'avatarResetUserId' => null];
    $owner = (string)($asset['source_owner'] ?? 'server-media');
    if ($owner === 'avatar') {
        $userId = (int)($asset['uploader_user_id'] ?? 0);
        $path = (string)($asset['source_key'] ?? '');
        if ($userId > 0 && $path !== '') {
            $current = $pdo->prepare('SELECT avatar_path FROM users WHERE id=? LIMIT 1');
            $current->execute([$userId]);
            if ((string)$current->fetchColumn() === $path) {
                $preset = 'preset:Default';
                $dimensions = avatar_source_dimensions($preset);
                avatar_identity_apply($pdo, $userId, $preset, avatar_identity_for_source($preset), $dimensions['width'], $dimensions['height']);
                avatar_relationship_cancel_active_dances($pdo, $userId, 'administrator-avatar-removal');
                $result['avatarResetUserId'] = $userId;
            }
        }
        return $result;
    }
    if ($owner !== 'gesture') return $result;
    $sourceKey = (string)($asset['source_key'] ?? '');
    if (!preg_match('/^([a-f0-9-]{36}):(\d+)$/i', $sourceKey, $match)) return $result;
    $publicId = strtolower($match[1]);
    $generation = (int)$match[2];
    $gesture = $pdo->prepare('SELECT id,package_generation,deleted_at FROM gestures WHERE public_id=? LIMIT 1');
    $gesture->execute([$publicId]);
    $row = $gesture->fetch();
    if (!is_array($row)) return $result;
    if ($generation === (int)$row['package_generation'] && $row['deleted_at'] === null) {
        $pdo->prepare("UPDATE gestures SET deleted_at=CURRENT_TIMESTAMP,is_public=0,active_catalog_key=NULL,visibility_changed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP,version=version+1 WHERE id=?")
            ->execute([(int)$row['id']]);
        $pdo->prepare('DELETE FROM gesture_custom_order WHERE gesture_public_id=?')->execute([$publicId]);
        $pdo->prepare('DELETE FROM gesture_hidden WHERE gesture_public_id=?')->execute([$publicId]);
        $pdo->prepare('DELETE FROM gesture_downloads WHERE gesture_public_id=?')->execute([$publicId]);
        $pdo->prepare("UPDATE server_media_assets SET status='deleted',updated_at=CURRENT_TIMESTAMP WHERE source_owner='gesture' AND source_key LIKE ?")
            ->execute([$publicId . ':%']);
        $result['gestureCleanup'] = $publicId;
        return $result;
    }
    $column = match ((string)($asset['source_role'] ?? '')) {
        'package' => 'package_storage_name',
        'animation' => 'animation_storage_name',
        'poster' => 'poster_storage_name',
        'audio' => 'audio_storage_name',
        default => null,
    };
    if ($column !== null) {
        $status = in_array($column, ['package_storage_name','animation_storage_name'], true)
            ? ",validation_status='deleted'" : '';
        $pdo->prepare("UPDATE gesture_package_generations SET {$column}=NULL{$status} WHERE gesture_id=? AND generation=?")
            ->execute([(int)$row['id'],$generation]);
    }
    return $result;
}

function server_media_emit_avatar_reset(PDO $pdo, int $userId): void
{
    if ($userId <= 0) return;
    $participants = $pdo->prepare('SELECT * FROM participants WHERE user_id=?');
    $participants->execute([$userId]);
    foreach ($participants->fetchAll() as $participant) {
        emit_event($pdo, (int)$participant['session_id'], 'avatar', array_merge([
            'participant_id' => (int)$participant['id'],
            'avatar_path' => (string)$participant['avatar_path'],
            'avatar_url' => resolve_avatar((string)$participant['avatar_path']),
            'avatar_source_width_px' => (int)$participant['avatar_source_width_px'],
            'avatar_source_height_px' => (int)$participant['avatar_source_height_px'],
            'avatar_orientation' => avatar_orientation_normalize($participant['avatar_orientation'] ?? null),
            'avatar_orientation_version' => max(1, (int)($participant['avatar_orientation_version'] ?? 1)),
            'webcam_path' => null,
            'webcam_enabled' => false,
        ], avatar_size_participant_event_fields($pdo, $participant)));
    }
}

function server_media_bulk_mutate(PDO $pdo, array $actor, array $publicIds, string $action, string $reviewId, string $reason = ''): array
{
    $publicIds = array_values(array_unique(array_filter(array_map('strval', $publicIds), static fn(string $id): bool => preg_match('/^sm_[a-f0-9]{32}$/', $id) === 1)));
    if (!$publicIds || count($publicIds) > 50 || !in_array($action, ['pin','unpin','quarantine','delete'], true)) {
        throw new ServerMediaException('Choose up to 50 files and one supported bulk action.', 'SERVER_MEDIA_BULK_INVALID', 400);
    }
    if ($action === 'quarantine' && trim($reason) === '') {
        throw new ServerMediaException('A quarantine reason is required.', 'SERVER_MEDIA_REASON_REQUIRED', 422);
    }
    $review = server_media_require_review_session($pdo, $actor, $reviewId);
    $assets = [];
    foreach ($publicIds as $publicId) $assets[] = server_media_asset_by_public_id($pdo, $publicId);
    $deletePaths = [];
    $quarantineMoves = [];
    $gestureCleanups = [];
    $avatarResets = [];
    if ($action === 'quarantine') {
        try {
            foreach ($assets as $asset) {
                $move = server_media_prepare_owned_quarantine($asset);
                if ($move) $quarantineMoves[(int)$asset['id']] = $move;
            }
        } catch (Throwable $error) {
            foreach ($quarantineMoves as $move) server_media_restore_owned_quarantine($move);
            throw $error;
        }
    }
    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) {
            if (db_uses_mysql_syntax($pdo)) $pdo->beginTransaction();
            else $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
        }
        foreach ($assets as $asset) {
            server_media_log_review_action($pdo, $actor, $asset, $review, $action);
            if ($action === 'pin' || $action === 'unpin') {
                $pdo->prepare('UPDATE server_media_assets SET pinned=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')
                    ->execute([$action === 'pin' ? 1 : 0,(int)$asset['id']]);
            } elseif ($action === 'quarantine') {
                $effect = server_media_disable_owned_source($pdo, $asset);
                if ($effect['gestureCleanup']) $gestureCleanups[(string)$effect['gestureCleanup']] = true;
                if ($effect['avatarResetUserId']) $avatarResets[(int)$effect['avatarResetUserId']] = true;
                $move = $quarantineMoves[(int)$asset['id']] ?? null;
                $pdo->prepare("UPDATE server_media_assets SET status='quarantined',quarantine_reason=?,storage_path=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")
                    ->execute([trim($reason),$move['target'] ?? (string)$asset['storage_path'],(int)$asset['id']]);
            } else {
                $effect = server_media_disable_owned_source($pdo, $asset);
                if ($effect['gestureCleanup']) $gestureCleanups[(string)$effect['gestureCleanup']] = true;
                if ($effect['avatarResetUserId']) $avatarResets[(int)$effect['avatarResetUserId']] = true;
                $references = $pdo->prepare('SELECT message_table,message_id FROM server_media_references WHERE asset_id=? AND active=1');
                $references->execute([(int)$asset['id']]);
                foreach ($references->fetchAll() as $reference) {
                    $table = (string)$reference['message_table'];
                    if (!in_array($table, ['messages','community_messages','game_chat_messages'], true)) continue;
                    $pdo->prepare("UPDATE {$table} SET content=?,original_name=? WHERE id=?")
                        ->execute(['[Removed attachment]','Removed attachment',(int)$reference['message_id']]);
                }
                $pdo->prepare('UPDATE server_media_references SET active=0 WHERE asset_id=?')->execute([(int)$asset['id']]);
                $pdo->prepare("UPDATE server_media_assets SET status='deleted',updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([(int)$asset['id']]);
                $deletePaths[] = [(string)$asset['storage_path'], is_string($asset['preview_path']) ? $asset['preview_path'] : ''];
            }
        }
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        foreach ($quarantineMoves as $move) server_media_restore_owned_quarantine($move);
        throw $error;
    }
    foreach (array_keys($gestureCleanups) as $gesturePublicId) {
        gesture_package_cleanup_deleted($pdo, $gesturePublicId);
        $records = $pdo->prepare("SELECT id FROM server_media_assets WHERE source_owner='gesture' AND source_key LIKE ?");
        $records->execute([$gesturePublicId . ':%']);
        foreach ($records->fetchAll(PDO::FETCH_COLUMN) as $assetId) server_media_refresh_sidecar($pdo, (int)$assetId);
    }
    foreach ($deletePaths as [$path, $preview]) {
        @unlink($path);
        if ($preview !== '') @unlink($preview);
    }
    foreach ($assets as $asset) server_media_refresh_sidecar($pdo, (int)$asset['id']);
    foreach (array_keys($avatarResets) as $userId) server_media_emit_avatar_reset($pdo, (int)$userId);
    return ['action' => $action, 'processed' => count($assets), 'ids' => $publicIds];
}

function server_media_review_auth_hash(): string
{
    $sessionId = session_id();
    $recent = (string)($_SESSION['recent_authentication_at'] ?? $_SESSION['security_recent_authentication_at'] ?? '');
    return strtoupper(hash('sha256', $sessionId . '|' . $recent));
}

function server_media_start_review_session(PDO $pdo, array $actor, string $reason): array
{
    if (!server_media_is_admin($actor)) throw new ServerMediaException('Administrator authorization is required.', 'SERVER_MEDIA_ADMIN_REQUIRED', 403);
    security_require_recent_authentication();
    $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
    if (strlen($reason) < 8 || strlen($reason) > 500) throw new ServerMediaException('Enter a specific review reason.', 'SERVER_MEDIA_REVIEW_REASON_REQUIRED', 422);
    $publicId = 'frs_' . bin2hex(random_bytes(16));
    $expires = gmdate('Y-m-d H:i:s', time() + SERVER_MEDIA_REVIEW_SECONDS);
    $pdo->prepare('INSERT INTO file_review_sessions (public_id,reviewer_user_id,auth_session_hash,role_snapshot,reason,expires_at) VALUES (?,?,?,?,?,?)')
        ->execute([$publicId,(int)$actor['id'],server_media_review_auth_hash(),(string)$actor['role'],$reason,$expires]);
    log_tool($pdo, (int)$actor['id'], 'file_review_session_started', null, null, 'Review session ' . $publicId . '; reason recorded; fixed 60-minute expiry.');
    return ['id' => $publicId, 'startedAt' => gmdate('Y-m-d H:i:s'), 'expiresAt' => $expires, 'fixedDurationSeconds' => SERVER_MEDIA_REVIEW_SECONDS, 'nonSliding' => true];
}

function server_media_require_review_session(PDO $pdo, array $actor, string $publicId): array
{
    if (!server_media_is_admin($actor)) throw new ServerMediaException('Administrator authorization is required.', 'SERVER_MEDIA_ADMIN_REQUIRED', 403);
    $stmt = $pdo->prepare('SELECT * FROM file_review_sessions WHERE public_id=? AND reviewer_user_id=? LIMIT 1');
    $stmt->execute([$publicId,(int)$actor['id']]);
    $row = $stmt->fetch();
    $invalid = !is_array($row)
        || $row['ended_at'] !== null
        || strtotime((string)$row['expires_at']) < time()
        || (string)$row['role_snapshot'] !== 'admin'
        || !hash_equals((string)$row['auth_session_hash'], server_media_review_auth_hash())
        || (string)($actor['role'] ?? '') !== 'admin';
    if ($invalid) {
        if (is_array($row) && $row['ended_at'] === null) {
            $pdo->prepare('UPDATE file_review_sessions SET ended_at=CURRENT_TIMESTAMP,end_reason=? WHERE id=?')->execute(['authorization-changed',(int)$row['id']]);
        }
        throw new ServerMediaException('Start a new File Review Session.', 'SERVER_MEDIA_REVIEW_SESSION_REQUIRED', 403);
    }
    return $row;
}

function server_media_log_review_action(PDO $pdo, array $actor, array $asset, array $review, string $action): void
{
    $allowed = ['preview','inspect','open','download','quarantine','delete','pin','unpin','resolve-broken'];
    if (!in_array($action, $allowed, true)) throw new ServerMediaException('The review action is invalid.', 'SERVER_MEDIA_REVIEW_ACTION_INVALID', 400);
    $pdo->prepare('INSERT INTO file_review_actions (review_session_id,reviewer_user_id,asset_id,action,original_reason) VALUES (?,?,?,?,?)')
        ->execute([(int)$review['id'],(int)$actor['id'],(int)$asset['id'],$action,(string)$review['reason']]);
    log_tool($pdo, (int)$actor['id'], 'file_review_' . str_replace('-', '_', $action), null, null, 'Review session ' . $review['public_id'] . '; file ' . $asset['public_id'] . '; reason retained.');
}

function server_media_mutate(PDO $pdo, array $actor, string $publicId, string $action, string $reviewId, string $reason = ''): array
{
    if (in_array($action, ['pin','unpin','quarantine','delete'], true)) {
        server_media_bulk_mutate($pdo, $actor, [$publicId], $action, $reviewId, $reason);
    } elseif ($action === 'resolve-broken') {
        $review = server_media_require_review_session($pdo, $actor, $reviewId);
        $asset = server_media_asset_by_public_id($pdo, $publicId);
        server_media_log_review_action($pdo, $actor, $asset, $review, $action);
        $exists = is_file((string)$asset['storage_path']);
        $pdo->prepare('UPDATE server_media_assets SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')
            ->execute([$exists ? 'active' : 'missing',(int)$asset['id']]);
        server_media_refresh_sidecar($pdo, (int)$asset['id']);
    } else {
        throw new ServerMediaException('The file action is unsupported.', 'SERVER_MEDIA_ACTION_INVALID', 400);
    }
    return server_media_project_asset($pdo, server_media_asset_by_public_id($pdo, $publicId), $actor, true);
}

function server_media_remove_own(PDO $pdo, array $actor, string $publicId): array
{
    $asset = server_media_asset_by_public_id($pdo, $publicId);
    $userId = (int)($actor['id'] ?? 0);
    if ($userId <= 0 || (int)($asset['uploader_user_id'] ?? 0) !== $userId) {
        throw new ServerMediaException('Only the sender can remove their server copy.', 'SERVER_MEDIA_REMOVE_OWN_DENIED', 403);
    }
    if ((string)$asset['status'] !== 'active') return ['id' => $publicId, 'status' => (string)$asset['status'], 'idempotent' => true];
    $pdo->beginTransaction();
    try {
        $references = $pdo->prepare('SELECT message_table,message_id FROM server_media_references WHERE asset_id=? AND active=1');
        $references->execute([(int)$asset['id']]);
        foreach ($references->fetchAll() as $reference) {
            $table = (string)$reference['message_table'];
            if (!in_array($table, ['messages','community_messages','game_chat_messages'], true)) continue;
            $pdo->prepare("UPDATE {$table} SET content=?,original_name=? WHERE id=?")
                ->execute(['[Removed attachment]','Removed attachment',(int)$reference['message_id']]);
        }
        $pdo->prepare('UPDATE server_media_references SET active=0 WHERE asset_id=?')->execute([(int)$asset['id']]);
        $pdo->prepare("UPDATE server_media_assets SET status='deleted',updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([(int)$asset['id']]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    @unlink((string)$asset['storage_path']);
    if (!empty($asset['preview_path'])) @unlink((string)$asset['preview_path']);
    server_media_refresh_sidecar($pdo, (int)$asset['id']);
    log_tool($pdo, $userId, 'server_media_sender_removed', null, null, 'File ' . $publicId . '; delivery disabled before byte removal.');
    return ['id' => $publicId, 'status' => 'deleted', 'idempotent' => false];
}

function server_media_revoke_user_uploads(PDO $pdo, int $userId, string $reason): int
{
    if ($userId <= 0 || !database_migration_table_exists($pdo, 'server_media_assets')) return 0;
    $assets = $pdo->prepare(
        "SELECT * FROM server_media_assets WHERE uploader_user_id=? "
        . "AND category IN ('chat-attachment','voice-note') AND status='active' ORDER BY id"
    );
    $assets->execute([$userId]);
    $rows = $assets->fetchAll();
    foreach ($rows as $asset) {
        $references = $pdo->prepare('SELECT message_table,message_id FROM server_media_references WHERE asset_id=? AND active=1');
        $references->execute([(int)$asset['id']]);
        foreach ($references->fetchAll() as $reference) {
            $table = (string)$reference['message_table'];
            if (!in_array($table, ['messages','community_messages','game_chat_messages'], true)) continue;
            $pdo->prepare("UPDATE {$table} SET content=?,original_name=? WHERE id=?")
                ->execute(['[Removed attachment]','Removed attachment',(int)$reference['message_id']]);
        }
        $pdo->prepare('UPDATE server_media_references SET active=0 WHERE asset_id=?')->execute([(int)$asset['id']]);
        $pdo->prepare("UPDATE server_media_assets SET status='deleted',quarantine_reason=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([server_media_safe_name($reason),(int)$asset['id']]);
        server_media_refresh_sidecar($pdo, (int)$asset['id']);
    }
    return count($rows);
}

function server_media_expire(PDO $pdo): int
{
    $assets = $pdo->query("SELECT * FROM server_media_assets WHERE status='active' AND persistent=0 AND grandfathered=0 AND pinned=0 AND expires_at IS NOT NULL AND expires_at<CURRENT_TIMESTAMP ORDER BY id LIMIT 250")->fetchAll();
    if (!$assets) return 0;
    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) {
            if (db_uses_mysql_syntax($pdo)) $pdo->beginTransaction();
            else $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
        }
        foreach ($assets as $asset) {
            $references = $pdo->prepare('SELECT message_table,message_id FROM server_media_references WHERE asset_id=? AND active=1');
            $references->execute([(int)$asset['id']]);
            foreach ($references->fetchAll() as $reference) {
                $table = (string)$reference['message_table'];
                if (!in_array($table, ['messages','community_messages','game_chat_messages'], true)) continue;
                $pdo->prepare("UPDATE {$table} SET content=?,original_name=? WHERE id=?")
                    ->execute(['[Expired attachment]','Expired attachment',(int)$reference['message_id']]);
            }
            $pdo->prepare('UPDATE server_media_references SET active=0 WHERE asset_id=?')->execute([(int)$asset['id']]);
            $pdo->prepare("UPDATE server_media_assets SET status='expired',updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='active'")
                ->execute([(int)$asset['id']]);
        }
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    foreach ($assets as $asset) {
        @unlink((string)$asset['storage_path']);
        if (!empty($asset['preview_path'])) @unlink((string)$asset['preview_path']);
        server_media_refresh_sidecar($pdo, (int)$asset['id']);
    }
    return count($assets);
}

function server_media_record_delivery(PDO $pdo, array $asset, int $userId): void
{
    $policy = server_media_policy($pdo);
    $deliveryHardLimit = (int)floor($policy['limits']['monthlyDeliveryBytes'] * ($policy['limits']['hardStopPercent'] / 100));
    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) {
            if (db_uses_mysql_syntax($pdo)) $pdo->beginTransaction();
            else $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
        }
        server_media_lock_quota_owner($pdo);
        if (server_media_monthly_delivery_usage($pdo) + (int)$asset['byte_size'] > $deliveryHardLimit) {
            throw new ServerMediaException('The monthly server-delivery allowance has been reached.', 'SERVER_MEDIA_DELIVERY_ALLOWANCE', 429);
        }
        $pdo->prepare('INSERT INTO server_media_delivery_usage (asset_id,user_id,byte_count) VALUES (?,?,?)')
            ->execute([(int)$asset['id'],$userId,(int)$asset['byte_size']]);
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function server_media_migrate_legacy_messages(PDO $pdo): array
{
    $migrated = 0;
    $tables = [
        'messages' => ['channel' => 'room', 'sessionColumn' => 'session_id'],
        'community_messages' => ['channel' => null, 'sessionColumn' => 'session_id'],
        'game_chat_messages' => ['channel' => 'game', 'sessionColumn' => null],
    ];
    foreach ($tables as $table => $metadata) {
        if (!database_migration_table_exists($pdo, $table)) continue;
        $select = "SELECT * FROM {$table} WHERE message_type IN ('file','voice_note') AND content LIKE '/assets/uploads/%' ORDER BY id";
        foreach ($pdo->query($select)->fetchAll() as $message) {
            $legacyUrl = (string)$message['content'];
            if (!preg_match('#^/assets/uploads/(files|voice)/([A-Za-z0-9._-]+)$#', $legacyUrl, $match)) continue;
            $source = dirname(__DIR__) . str_replace('/', DIRECTORY_SEPARATOR, $legacyUrl);
            if (!is_file($source)) continue;
            $publicId = 'sm_' . substr(hash('sha256', $table . '|' . $message['id'] . '|' . $legacyUrl), 0, 32);
            $existing = $pdo->prepare('SELECT id,storage_path,source_sha256 FROM server_media_assets WHERE public_id=? LIMIT 1');
            $existing->execute([$publicId]);
            $asset = $existing->fetch();
            $hash = strtoupper(hash_file('sha256', $source));
            $extension = strtolower(pathinfo((string)($message['original_name'] ?? $match[2]), PATHINFO_EXTENSION));
            $targetDirectory = security_private_storage_directory('server-media');
            $target = $targetDirectory . DIRECTORY_SEPARATOR . $publicId . '.' . ($extension !== '' ? preg_replace('/[^a-z0-9]/', '', $extension) : 'bin');
            if (!is_file($target)) {
                if (!copy($source, $target) || strtoupper(hash_file('sha256', $target)) !== $hash) {
                    @unlink($target);
                    throw new ServerMediaException('A legacy file could not be copied without byte drift.', 'SERVER_MEDIA_LEGACY_COPY_FAILED', 500);
                }
                @chmod($target, 0600);
            }
            $channel = $metadata['channel'] ?? (string)($message['scope'] ?? 'community');
            $sessionId = $metadata['sessionColumn'] ? (int)($message[$metadata['sessionColumn']] ?? 0) : 0;
            if (!is_array($asset)) {
                $classification = server_media_classify($target, (string)($message['mime_type'] ?? 'application/octet-stream'), (string)($message['original_name'] ?? $match[2]), (string)$message['message_type'] === 'voice_note');
                $pdo->prepare(
                    'INSERT INTO server_media_assets
                     (public_id,uploader_user_id,session_id,category,channel_scope,original_name,safe_name,extension,declared_mime,detected_mime,risk_class,risk_detail,byte_size,storage_path,source_sha256,legacy_public_path,audience_json,status,persistent,grandfathered,pinned,expires_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'active\',0,1,0,NULL)'
                )->execute([
                    $publicId,(int)($message['user_id'] ?? 0) ?: null,$sessionId ?: null,
                    (string)$message['message_type'] === 'voice_note' ? 'voice-note' : 'chat-attachment',$channel,
                    (string)($message['original_name'] ?? $match[2]),$classification['safeName'],$classification['extension'],
                    (string)($message['mime_type'] ?? 'application/octet-stream'),$classification['detectedMime'],$classification['riskClass'],$classification['riskDetail'],
                    (int)filesize($target),$target,$hash,$source,'[]',
                ]);
            }
            $pdo->prepare("UPDATE {$table} SET content=? WHERE id=? AND content=?")
                ->execute(['/api/server_media.php?action=download&id=' . $publicId,(int)$message['id'],$legacyUrl]);
            server_media_add_reference($pdo, $publicId, $table, (int)$message['id'], $channel, (string)($message['link_key'] ?? $message['lobby_code'] ?? ''));
            $migrated++;
        }
    }
    return ['migratedReferences' => $migrated];
}

function server_media_migrate_legacy_unreferenced_files(PDO $pdo): array
{
    $inventoried = 0;
    foreach ([
        ['path' => dirname(__DIR__) . '/assets/uploads/files', 'voice' => false],
        ['path' => dirname(__DIR__) . '/assets/uploads/voice', 'voice' => true],
    ] as $sourceOwner) {
        if (!is_dir($sourceOwner['path'])) continue;
        foreach (new DirectoryIterator($sourceOwner['path']) as $file) {
            if (!$file->isFile()) continue;
            $source = $file->getPathname();
            $existing = $pdo->prepare('SELECT 1 FROM server_media_assets WHERE legacy_public_path=? LIMIT 1');
            $existing->execute([$source]);
            if ($existing->fetchColumn()) continue;
            $hash = strtoupper(hash_file('sha256', $source));
            $publicId = 'sm_' . substr(hash('sha256', 'legacy-unreferenced|' . str_replace('\\', '/', $source) . '|' . $hash), 0, 32);
            $classification = server_media_classify($source, 'application/octet-stream', $file->getFilename(), (bool)$sourceOwner['voice']);
            $targetDirectory = security_private_storage_directory('server-media');
            $extension = $classification['extension'] !== '' ? preg_replace('/[^a-z0-9]/', '', $classification['extension']) : 'bin';
            $target = $targetDirectory . DIRECTORY_SEPARATOR . $publicId . '.' . $extension;
            if (!is_file($target)) {
                if (!copy($source, $target) || strtoupper(hash_file('sha256', $target)) !== $hash) {
                    @unlink($target);
                    throw new ServerMediaException('A legacy file could not be inventoried without byte drift.', 'SERVER_MEDIA_LEGACY_INVENTORY_FAILED', 500);
                }
                @chmod($target, 0600);
            }
            $sql = db_uses_mysql_syntax($pdo)
                ? 'INSERT IGNORE INTO server_media_assets (public_id,uploader_user_id,session_id,category,channel_scope,original_name,safe_name,extension,declared_mime,detected_mime,risk_class,risk_detail,byte_size,storage_path,source_sha256,legacy_public_path,audience_json,status,persistent,grandfathered,pinned,expires_at) VALUES (?,NULL,NULL,\'legacy\',\'legacy\',?,?,?,?,?,?,?,?,?,?,?,\'[]\',\'active\',1,1,0,NULL)'
                : 'INSERT OR IGNORE INTO server_media_assets (public_id,uploader_user_id,session_id,category,channel_scope,original_name,safe_name,extension,declared_mime,detected_mime,risk_class,risk_detail,byte_size,storage_path,source_sha256,legacy_public_path,audience_json,status,persistent,grandfathered,pinned,expires_at) VALUES (?,NULL,NULL,\'legacy\',\'legacy\',?,?,?,?,?,?,?,?,?,?,?,\'[]\',\'active\',1,1,0,NULL)';
            $statement = $pdo->prepare($sql);
            $statement->execute([
                $publicId,$file->getFilename(),$classification['safeName'],$classification['extension'],
                'application/octet-stream',$classification['detectedMime'],$classification['riskClass'],$classification['riskDetail'],
                $file->getSize(),$target,$hash,$source,
            ]);
            if ($statement->rowCount() > 0) $inventoried++;
        }
    }
    return ['inventoriedUnreferencedFiles' => $inventoried];
}

function server_media_retire_migrated_public_files(PDO $pdo): int
{
    $retired = 0;
    foreach ($pdo->query('SELECT public_id,storage_path,source_sha256,legacy_public_path FROM server_media_assets WHERE grandfathered=1 AND legacy_public_path IS NOT NULL')->fetchAll() as $asset) {
        $legacy = (string)$asset['legacy_public_path'];
        $resolved = realpath($legacy);
        $filesRoot = realpath(dirname(__DIR__) . '/assets/uploads/files');
        $voiceRoot = realpath(dirname(__DIR__) . '/assets/uploads/voice');
        $allowed = $resolved !== false && (($filesRoot !== false && str_starts_with($resolved, $filesRoot . DIRECTORY_SEPARATOR)) || ($voiceRoot !== false && str_starts_with($resolved, $voiceRoot . DIRECTORY_SEPARATOR)));
        if (!$allowed || !is_file((string)$asset['storage_path']) || !is_file($resolved)) continue;
        if (strtoupper(hash_file('sha256', (string)$asset['storage_path'])) !== (string)$asset['source_sha256']
            || strtoupper(hash_file('sha256', $resolved)) !== (string)$asset['source_sha256']) continue;
        $legacyUrl = str_replace('\\', '/', substr($resolved, strlen(dirname(__DIR__))));
        $remaining = 0;
        foreach (['messages','community_messages','game_chat_messages'] as $table) {
            if (!database_migration_table_exists($pdo, $table)) continue;
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE content=?");
            $stmt->execute([$legacyUrl]);
            $remaining += (int)$stmt->fetchColumn();
        }
        if ($remaining === 0 && @unlink($resolved)) {
            $pdo->prepare('UPDATE server_media_assets SET legacy_public_path=NULL,updated_at=CURRENT_TIMESTAMP WHERE public_id=?')->execute([(string)$asset['public_id']]);
            server_media_refresh_sidecar($pdo, (int)server_media_asset_by_public_id($pdo, (string)$asset['public_id'])['id']);
            $retired++;
        }
    }
    return $retired;
}

function database_migration_apply_post_build_000055_direct_file_sharing(PDO $pdo, array $context = []): array
{
    $existingInstallation = !operational_capacity_detect_fresh_install($pdo);
    $existingSettings = [];
    foreach ([SERVER_MEDIA_ATTACHMENTS_ENABLED,SERVER_MEDIA_VOICE_NOTES_ENABLED,SERVER_MEDIA_FILE_MODE,SERVER_MEDIA_GESTURE_MODE] as $key) {
        $stmt = $pdo->prepare('SELECT value FROM app_settings WHERE setting_key=? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        $existingSettings[$key] = $value === false ? null : (string)$value;
    }
    server_media_install_schema($pdo);
    p2p_transfer_install_schema($pdo);
    moderation_identity_install_schema($pdo);
    $pdo->prepare('UPDATE moderation_capability_catalog SET available=1,implementation_owner=?,revision=revision+1 WHERE capability_id=?')
        ->execute(['p2p-transfer','send-direct-p2p-files']);
    foreach (array_merge(server_media_setting_defaults(), p2p_transfer_setting_defaults()) as $key => $value) {
        $sql = db_uses_mysql_syntax($pdo)
            ? 'INSERT IGNORE INTO app_settings (setting_key,value) VALUES (?,?)'
            : 'INSERT OR IGNORE INTO app_settings (setting_key,value) VALUES (?,?)';
        $pdo->prepare($sql)->execute([$key,$value]);
    }
    if ($existingInstallation) {
        if ($existingSettings[SERVER_MEDIA_ATTACHMENTS_ENABLED] === null) set_app_setting($pdo, SERVER_MEDIA_ATTACHMENTS_ENABLED, '1');
        if ($existingSettings[SERVER_MEDIA_VOICE_NOTES_ENABLED] === null) set_app_setting($pdo, SERVER_MEDIA_VOICE_NOTES_ENABLED, '1');
        if ($existingSettings[SERVER_MEDIA_FILE_MODE] === null) set_app_setting($pdo, SERVER_MEDIA_FILE_MODE, 'both');
        if ($existingSettings[SERVER_MEDIA_GESTURE_MODE] === null) set_app_setting($pdo, SERVER_MEDIA_GESTURE_MODE, 'both');
    }
    $legacy = server_media_migrate_legacy_messages($pdo);
    $unreferenced = server_media_migrate_legacy_unreferenced_files($pdo);
    $owned = server_media_inventory_owned_uploads($pdo);
    return ['schemaVersion' => CHATSPACE_SCHEMA_VERSION] + $legacy + $unreferenced + $owned;
}

function database_migration_validate_post_build_000055_direct_file_sharing(PDO $pdo, array $context = []): bool
{
    if (!server_media_schema_valid($pdo) || !p2p_transfer_schema_valid($pdo)) return false;
    $catalog = $pdo->prepare('SELECT available,implementation_owner FROM moderation_capability_catalog WHERE capability_id=? LIMIT 1');
    $catalog->execute(['send-direct-p2p-files']);
    $capability = $catalog->fetch();
    if (!is_array($capability) || empty($capability['available']) || (string)$capability['implementation_owner'] !== 'p2p-transfer') return false;
    foreach (array_keys(array_merge(server_media_setting_defaults(), p2p_transfer_setting_defaults())) as $key) {
        $stmt = $pdo->prepare('SELECT 1 FROM app_settings WHERE setting_key=? LIMIT 1');
        $stmt->execute([$key]);
        if (!$stmt->fetchColumn()) return false;
    }
    return (int)$pdo->query("SELECT COUNT(*) FROM server_media_assets WHERE source_sha256='' OR storage_path='' OR audience_json='' ")->fetchColumn() === 0;
}
