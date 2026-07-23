<?php
declare(strict_types=1);

final class GestureCatalogException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 400,
        public readonly string $errorCode = 'GESTURE_CATALOG_ERROR',
        public readonly array $context = []
    ) {
        parent::__construct($message);
    }
}

function gesture_catalog_setting_defaults(): array
{
    return [
        'gesture_part3_enhanced_picker' => '1',
        'gesture_part3_gifs_tab' => '1',
        'gesture_part3_server_tab' => '1',
        'gesture_part3_personal_tab' => '1',
        'gesture_part3_emojis_tab' => '1',
        'gesture_part3_search' => '1',
        'gesture_part3_sorting' => '1',
        'gesture_part3_pagination' => '1',
        'gesture_part3_custom_order' => '1',
        'gesture_part3_hide_unhide' => '1',
        'gesture_part3_context_menus' => '1',
        'gesture_part3_message_hide_unhide' => '1',
        'gesture_part3_admin_catalog' => '1',
        'gesture_part4_editor' => '1',
        'gesture_part4_user_package_import' => '1',
        'gesture_part4_user_package_download' => '1',
        'gesture_part4_animation_media' => '1',
        'gesture_part4_audio_media' => '1',
        'gesture_part4_legacy_agst' => '1',
        'gesture_part4_admin_package_inspection' => '1',
        'gesture_part4_admin_media_replacement' => '1',
        'gesture_mutation_rate_limit' => '120',
        'gesture_mutation_ip_rate_limit' => '600',
        'gesture_download_cooldown_seconds' => '30',
        'gesture_download_daily_limit' => '20',
        'gesture_download_emergency_daily_limit' => '1000',
        'gesture_download_active_timeout_seconds' => '300',
        'gesture_download_history_days' => '90',
    ];
}

function gesture_part3_feature_flags(PDO $pdo): array
{
    $flags = [];
    foreach (array_keys(gesture_catalog_setting_defaults()) as $key) {
        if (!str_starts_with($key, 'gesture_part3_')) continue;
        $flags[substr($key, strlen('gesture_part3_'))] = app_setting($pdo, $key, '1') === '1';
    }
    return $flags;
}

function gesture_part4_feature_flags(PDO $pdo): array
{
    $flags = [];
    foreach (array_keys(gesture_catalog_setting_defaults()) as $key) {
        if (!str_starts_with($key, 'gesture_part4_')) continue;
        $flags[substr($key, strlen('gesture_part4_'))] = app_setting($pdo, $key, '1') === '1';
    }
    return $flags;
}

function gesture_catalog_columns(PDO $pdo, string $table): array
{
    if (db_driver($pdo) === 'mysql') {
        return array_map(
            static fn(array $column): string => (string)($column['Field'] ?? ''),
            $pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll()
        );
    }
    return array_map(
        static fn(array $column): string => (string)($column['name'] ?? ''),
        $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll()
    );
}

function gesture_catalog_add_columns(PDO $pdo): void
{
    $mysql = [
        'original_filename' => 'VARCHAR(255) DEFAULT NULL',
        'catalog_filename' => 'VARCHAR(120) DEFAULT NULL',
        'catalog_filename_key' => 'VARCHAR(120) DEFAULT NULL',
        'active_catalog_key' => "VARCHAR(16) DEFAULT 'active'",
        'title' => 'VARCHAR(191) DEFAULT NULL',
        'creator_credit' => 'VARCHAR(191) DEFAULT NULL',
        'uploaded_by_user_id' => 'INT DEFAULT NULL',
        'original_uploaded_at' => 'DATETIME DEFAULT NULL',
        'content_updated_at' => 'DATETIME DEFAULT NULL',
        'published_at' => 'DATETIME DEFAULT NULL',
        'metadata_updated_at' => 'DATETIME DEFAULT NULL',
        'visibility_changed_at' => 'DATETIME DEFAULT NULL',
        'version' => 'INT NOT NULL DEFAULT 1',
        'legacy_metadata' => 'INT NOT NULL DEFAULT 0',
    ];
    $sqlite = [
        'original_filename' => 'TEXT DEFAULT NULL',
        'catalog_filename' => 'TEXT DEFAULT NULL',
        'catalog_filename_key' => 'TEXT DEFAULT NULL',
        'active_catalog_key' => "TEXT DEFAULT 'active'",
        'title' => 'TEXT DEFAULT NULL',
        'creator_credit' => 'TEXT DEFAULT NULL',
        'uploaded_by_user_id' => 'INTEGER DEFAULT NULL',
        'original_uploaded_at' => 'TEXT DEFAULT NULL',
        'content_updated_at' => 'TEXT DEFAULT NULL',
        'published_at' => 'TEXT DEFAULT NULL',
        'metadata_updated_at' => 'TEXT DEFAULT NULL',
        'visibility_changed_at' => 'TEXT DEFAULT NULL',
        'version' => 'INTEGER NOT NULL DEFAULT 1',
        'legacy_metadata' => 'INTEGER NOT NULL DEFAULT 0',
    ];
    $columns = gesture_catalog_columns($pdo, 'gestures');
    foreach (db_driver($pdo) === 'mysql' ? $mysql : $sqlite as $column => $definition) {
        if (!in_array($column, $columns, true)) {
            $pdo->exec("ALTER TABLE gestures ADD COLUMN {$column} {$definition}");
        }
    }
}

function gesture_catalog_create_tables(PDO $pdo): void
{
    if (db_driver($pdo) === 'mysql') {
        $statements = [
            "CREATE TABLE IF NOT EXISTS gesture_preferences (
                user_id INT PRIMARY KEY,
                server_sort VARCHAR(32) NOT NULL DEFAULT 'last_uploaded',
                personal_sort VARCHAR(32) NOT NULL DEFAULT 'last_uploaded',
                preference_version INT NOT NULL DEFAULT 1,
                server_order_version INT NOT NULL DEFAULT 0,
                personal_order_version INT NOT NULL DEFAULT 0,
                hidden_version INT NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS gesture_custom_order (
                user_id INT NOT NULL,
                catalog_scope VARCHAR(16) NOT NULL,
                gesture_public_id VARCHAR(64) NOT NULL,
                position_index INT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(user_id, catalog_scope, gesture_public_id),
                UNIQUE KEY idx_gesture_order_position (user_id, catalog_scope, position_index),
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY(gesture_public_id) REFERENCES gestures(public_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS gesture_hidden (
                user_id INT NOT NULL,
                gesture_public_id VARCHAR(64) NOT NULL,
                previous_position INT DEFAULT NULL,
                hidden_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(user_id, gesture_public_id),
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY(gesture_public_id) REFERENCES gestures(public_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS gesture_sender_media_hidden (
                viewer_user_id INT NOT NULL,
                target_user_id INT NOT NULL,
                hidden_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(viewer_user_id, target_user_id),
                FOREIGN KEY(viewer_user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY(target_user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS gesture_operation_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                operation VARCHAR(48) NOT NULL,
                request_key VARCHAR(96) NOT NULL,
                request_hash VARCHAR(64) NOT NULL,
                response_json LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY idx_gesture_operation_request (user_id, operation, request_key),
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS gesture_downloads (
                id INT AUTO_INCREMENT PRIMARY KEY,
                request_public_id VARCHAR(64) NOT NULL UNIQUE,
                user_id INT NOT NULL,
                gesture_public_id VARCHAR(64) NOT NULL,
                status VARCHAR(16) NOT NULL,
                active_user_id INT DEFAULT NULL UNIQUE,
                bytes_delivered INT NOT NULL DEFAULT 0,
                started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME DEFAULT NULL,
                expires_at DATETIME NOT NULL,
                failure_code VARCHAR(64) DEFAULT NULL,
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY(gesture_public_id) REFERENCES gestures(public_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    } else {
        $statements = [
            "CREATE TABLE IF NOT EXISTS gesture_preferences (
                user_id INTEGER PRIMARY KEY,
                server_sort TEXT NOT NULL DEFAULT 'last_uploaded',
                personal_sort TEXT NOT NULL DEFAULT 'last_uploaded',
                preference_version INTEGER NOT NULL DEFAULT 1,
                server_order_version INTEGER NOT NULL DEFAULT 0,
                personal_order_version INTEGER NOT NULL DEFAULT 0,
                hidden_version INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
            )",
            "CREATE TABLE IF NOT EXISTS gesture_custom_order (
                user_id INTEGER NOT NULL,
                catalog_scope TEXT NOT NULL,
                gesture_public_id TEXT NOT NULL,
                position_index INTEGER NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(user_id, catalog_scope, gesture_public_id),
                UNIQUE(user_id, catalog_scope, position_index),
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY(gesture_public_id) REFERENCES gestures(public_id) ON DELETE CASCADE
            )",
            "CREATE TABLE IF NOT EXISTS gesture_hidden (
                user_id INTEGER NOT NULL,
                gesture_public_id TEXT NOT NULL,
                previous_position INTEGER DEFAULT NULL,
                hidden_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(user_id, gesture_public_id),
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY(gesture_public_id) REFERENCES gestures(public_id) ON DELETE CASCADE
            )",
            "CREATE TABLE IF NOT EXISTS gesture_sender_media_hidden (
                viewer_user_id INTEGER NOT NULL,
                target_user_id INTEGER NOT NULL,
                hidden_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(viewer_user_id, target_user_id),
                FOREIGN KEY(viewer_user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY(target_user_id) REFERENCES users(id) ON DELETE CASCADE
            )",
            "CREATE TABLE IF NOT EXISTS gesture_operation_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                operation TEXT NOT NULL,
                request_key TEXT NOT NULL,
                request_hash TEXT NOT NULL,
                response_json TEXT DEFAULT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(user_id, operation, request_key),
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
            )",
            "CREATE TABLE IF NOT EXISTS gesture_downloads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                request_public_id TEXT NOT NULL UNIQUE,
                user_id INTEGER NOT NULL,
                gesture_public_id TEXT NOT NULL,
                status TEXT NOT NULL,
                active_user_id INTEGER DEFAULT NULL UNIQUE,
                bytes_delivered INTEGER NOT NULL DEFAULT 0,
                started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at TEXT DEFAULT NULL,
                expires_at TEXT NOT NULL,
                failure_code TEXT DEFAULT NULL,
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY(gesture_public_id) REFERENCES gestures(public_id) ON DELETE CASCADE
            )",
        ];
    }
    foreach ($statements as $statement) $pdo->exec($statement);
}

function gesture_catalog_add_preference_columns(PDO $pdo): void
{
    $columns = gesture_catalog_columns($pdo, 'gesture_preferences');
    $definitions = db_driver($pdo) === 'mysql'
        ? [
            'show_animations' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'show_text' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'play_sounds' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'sender_visibility_version' => 'INT NOT NULL DEFAULT 0',
        ]
        : [
            'show_animations' => 'INTEGER NOT NULL DEFAULT 1',
            'show_text' => 'INTEGER NOT NULL DEFAULT 1',
            'play_sounds' => 'INTEGER NOT NULL DEFAULT 1',
            'sender_visibility_version' => 'INTEGER NOT NULL DEFAULT 0',
        ];
    foreach ($definitions as $column => $definition) {
        if (!in_array($column, $columns, true)) {
            $pdo->exec("ALTER TABLE gesture_preferences ADD COLUMN {$column} {$definition}");
        }
    }
}

function gesture_catalog_index(PDO $pdo, string $table, string $name, string $columns, bool $unique = false): void
{
    if (db_driver($pdo) === 'mysql') {
        $stmt = $pdo->prepare("SHOW INDEX FROM {$table} WHERE Key_name = ?");
        $stmt->execute([$name]);
        if ($stmt->fetch()) return;
        $pdo->exec('CREATE ' . ($unique ? 'UNIQUE ' : '') . "INDEX {$name} ON {$table}({$columns})");
        return;
    }
    $pdo->exec('CREATE ' . ($unique ? 'UNIQUE ' : '') . "INDEX IF NOT EXISTS {$name} ON {$table}({$columns})");
}

function gesture_catalog_filename_stem(string $value, string $fallback = 'gesture'): string
{
    $value = basename(str_replace('\\', '/', trim($value)));
    $value = preg_replace('/\.agst$/i', '', $value) ?? $value;
    $value = preg_replace('/[^\p{L}\p{N} _.-]+/u', '-', $value) ?? '';
    $value = trim(preg_replace('/[\s._-]+/u', '-', $value) ?? '', '.-_ ');
    if ($value === '') $value = $fallback;
    return function_exists('mb_substr') ? mb_substr($value, 0, 80, 'UTF-8') : substr($value, 0, 80);
}

function gesture_catalog_filename_key(string $value): string
{
    $value = gesture_catalog_filename_stem($value);
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function gesture_catalog_original_filename(string $value, string $fallback): string
{
    $stem = gesture_catalog_filename_stem($value, $fallback);
    return $stem . '.agst';
}

function gesture_catalog_clean_text(string $value, int $limit, string $fallback = ''): string
{
    $value = preg_replace('/[\r\n\t]+/', ' ', $value) ?? $value;
    $value = preg_replace('/[^\p{L}\p{N}\p{P}\p{S}\p{Zs}]/u', '', $value) ?? $value;
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    if ($value === '') $value = $fallback;
    return function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
}

function gesture_presentation_canonical_text(array $gesture): string
{
    $text = trim((string)($gesture['text'] ?? $gesture['gesture_text'] ?? ''));
    return $text === '' ? '(Gesture)' : '(Gesture) ' . $text;
}

function gesture_catalog_backfill(PDO $pdo): void
{
    $rows = $pdo->query(
        'SELECT g.*, u.display_name AS owner_display_name FROM gestures g '
        . 'LEFT JOIN users u ON u.id = g.owner_user_id ORDER BY g.owner_user_id ASC, g.id ASC'
    )->fetchAll();
    $used = [];
    foreach ($rows as $row) {
        if (!empty($row['catalog_filename'])) {
            $used[(int)$row['owner_user_id']][gesture_catalog_filename_key((string)$row['catalog_filename'])] = (int)$row['id'];
        }
    }
    $update = $pdo->prepare(
        'UPDATE gestures SET original_filename = ?, catalog_filename = ?, catalog_filename_key = ?, '
        . 'active_catalog_key = ?, title = ?, creator_credit = ?, uploaded_by_user_id = ?, '
        . 'original_uploaded_at = ?, content_updated_at = ?, published_at = ?, metadata_updated_at = ?, '
        . 'visibility_changed_at = ?, version = CASE WHEN version < 1 THEN 1 ELSE version END, legacy_metadata = ? '
        . 'WHERE id = ?'
    );
    foreach ($rows as $row) {
        $owner = (int)$row['owner_user_id'];
        $activeMismatch = empty($row['deleted_at']) ? (string)$row['active_catalog_key'] !== 'active' : $row['active_catalog_key'] !== null;
        $needsBackfill = $activeMismatch || (int)$row['version'] < 1
            || empty($row['original_filename']) || empty($row['catalog_filename']) || empty($row['catalog_filename_key'])
            || empty($row['title']) || empty($row['creator_credit']) || empty($row['uploaded_by_user_id'])
            || empty($row['original_uploaded_at']) || empty($row['content_updated_at'])
            || empty($row['metadata_updated_at']) || empty($row['visibility_changed_at'])
            || (!empty($row['is_public']) && empty($row['published_at']));
        if (!$needsBackfill) continue;
        if (!empty($row['catalog_filename'])) {
            $currentKey = gesture_catalog_filename_key((string)$row['catalog_filename']);
            if (($used[$owner][$currentKey] ?? null) === (int)$row['id']) unset($used[$owner][$currentKey]);
        }
        $base = gesture_catalog_filename_stem((string)($row['catalog_filename'] ?: $row['name']), 'gesture-' . $row['id']);
        $candidate = $base;
        $suffix = 2;
        while (isset($used[$owner][gesture_catalog_filename_key($candidate)])) {
            $candidate = gesture_catalog_filename_stem($base . '-' . $suffix, 'gesture-' . $row['id']);
            $suffix++;
        }
        $used[$owner][gesture_catalog_filename_key($candidate)] = (int)$row['id'];
        $created = (string)($row['created_at'] ?: gmdate('Y-m-d H:i:s'));
        $updated = (string)($row['updated_at'] ?: $created);
        $legacy = empty($row['original_filename']) || empty($row['catalog_filename']) || empty($row['title']) || empty($row['creator_credit']);
        $original = (string)($row['original_filename'] ?: gesture_catalog_original_filename((string)$row['name'], 'gesture-' . $row['id']));
        $title = gesture_catalog_clean_text((string)($row['title'] ?: $row['name']), 120, 'Gesture');
        $creator = gesture_catalog_clean_text((string)($row['creator_credit'] ?: $row['owner_display_name']), 120, 'Unknown creator');
        $update->execute([
            $original,
            $candidate,
            gesture_catalog_filename_key($candidate),
            empty($row['deleted_at']) ? 'active' : null,
            $title,
            $creator,
            (int)($row['uploaded_by_user_id'] ?: $owner),
            (string)($row['original_uploaded_at'] ?: $created),
            (string)($row['content_updated_at'] ?: $updated),
            !empty($row['is_public']) ? (string)($row['published_at'] ?: $updated) : ($row['published_at'] ?: null),
            (string)($row['metadata_updated_at'] ?: $updated),
            (string)($row['visibility_changed_at'] ?: $updated),
            $legacy ? 1 : (int)$row['legacy_metadata'],
            (int)$row['id'],
        ]);
    }
}

function gesture_catalog_install_schema(PDO $pdo): void
{
    gesture_catalog_add_columns($pdo);
    gesture_catalog_create_tables($pdo);
    gesture_catalog_add_preference_columns($pdo);
    gesture_catalog_backfill($pdo);
    gesture_catalog_index($pdo, 'gestures', 'idx_gestures_owner_catalog_active', 'owner_user_id, catalog_filename_key, active_catalog_key', true);
    gesture_catalog_index($pdo, 'gestures', 'idx_gestures_server_catalog', 'is_public, deleted_at, content_updated_at, id');
    gesture_catalog_index($pdo, 'gestures', 'idx_gestures_owner_catalog', 'owner_user_id, deleted_at, content_updated_at, id');
    gesture_catalog_index($pdo, 'gesture_hidden', 'idx_gesture_hidden_lookup', 'gesture_public_id, user_id');
    gesture_catalog_index($pdo, 'gesture_sender_media_hidden', 'idx_gesture_sender_media_target', 'target_user_id, viewer_user_id');
    gesture_catalog_index($pdo, 'gesture_downloads', 'idx_gesture_download_user_status', 'user_id, status, completed_at');
    gesture_catalog_index($pdo, 'gesture_downloads', 'idx_gesture_download_status_time', 'status, started_at');
    if (function_exists('gesture_package_install_schema')) gesture_package_install_schema($pdo);
}

function gesture_catalog_transaction(PDO $pdo, callable $callback): mixed
{
    $owned = !$pdo->inTransaction();
    if ($owned) {
        if (db_driver($pdo) === 'sqlite') $pdo->exec('BEGIN IMMEDIATE');
        else $pdo->beginTransaction();
    }
    try {
        $result = $callback();
        if ($owned) $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($owned && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function gesture_catalog_canonicalize(mixed $value): mixed
{
    if (!is_array($value)) return $value;
    if (array_is_list($value)) return array_map('gesture_catalog_canonicalize', $value);
    ksort($value);
    foreach ($value as $key => $item) $value[$key] = gesture_catalog_canonicalize($item);
    return $value;
}

function gesture_catalog_idempotent(
    PDO $pdo,
    int $userId,
    string $operation,
    string $requestKey,
    array $payload,
    callable $callback
): array {
    if (!preg_match('/^[A-Za-z0-9._:-]{8,96}$/', $requestKey)) {
        throw new GestureCatalogException('A valid idempotency key is required.', 400, 'INVALID_IDEMPOTENCY_KEY');
    }
    $hash = hash('sha256', json_encode(gesture_catalog_canonicalize($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return gesture_catalog_transaction($pdo, function () use ($pdo, $userId, $operation, $requestKey, $hash, $callback): array {
        gesture_catalog_lock_user($pdo, $userId);
        $reserve = $pdo->prepare(db_driver($pdo) === 'mysql'
            ? 'INSERT IGNORE INTO gesture_operation_requests (user_id, operation, request_key, request_hash, response_json) VALUES (?,?,?,?,NULL)'
            : 'INSERT OR IGNORE INTO gesture_operation_requests (user_id, operation, request_key, request_hash, response_json) VALUES (?,?,?,?,NULL)');
        $reserve->execute([$userId, $operation, $requestKey, $hash]);
        $created = $reserve->rowCount() === 1;
        $sql = 'SELECT request_hash, response_json FROM gesture_operation_requests WHERE user_id = ? AND operation = ? AND request_key = ? LIMIT 1';
        if (db_driver($pdo) === 'mysql') $sql .= ' FOR UPDATE';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $operation, $requestKey]);
        $existing = $stmt->fetch();
        if (!$created && $existing) {
            if (!hash_equals((string)$existing['request_hash'], $hash)) {
                throw new GestureCatalogException('That request key was already used for different data.', 409, 'IDEMPOTENCY_CONFLICT');
            }
            $response = json_decode((string)$existing['response_json'], true);
            if (!is_array($response)) throw new GestureCatalogException('That operation is still being processed.', 409, 'OPERATION_IN_PROGRESS');
            $response['idempotent'] = true;
            return $response;
        }
        $response = $callback();
        $response['idempotent'] = false;
        $pdo->prepare('UPDATE gesture_operation_requests SET response_json = ? WHERE user_id = ? AND operation = ? AND request_key = ?')
            ->execute([json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $userId, $operation, $requestKey]);
        return $response;
    });
}

function gesture_catalog_lock_user(PDO $pdo, int $userId): void
{
    $sql = 'SELECT id FROM users WHERE id = ? LIMIT 1';
    if (db_driver($pdo) === 'mysql') $sql .= ' FOR UPDATE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    if (!$stmt->fetchColumn()) throw new GestureCatalogException('User not found.', 404, 'USER_NOT_FOUND');
}

function gesture_catalog_scope(string $scope): string
{
    if (!in_array($scope, ['server', 'personal'], true)) {
        throw new GestureCatalogException('Gesture catalog scope is invalid.', 400, 'INVALID_SCOPE');
    }
    return $scope;
}

function gesture_catalog_require_scope_policy(PDO $pdo, string $scope, bool $lock = false): array
{
    $scope = gesture_catalog_scope($scope);
    $policy = $lock ? gesture_capability_lock($pdo) : gesture_capability_policy($pdo);
    gesture_capability_require_scope($policy, $scope);
    return $policy;
}

function gesture_catalog_require_user_mutation(PDO $pdo, bool $lock = false): array
{
    $policy = $lock ? gesture_capability_lock($pdo) : gesture_capability_policy($pdo);
    gesture_capability_require($policy, 'allow_user_gesture_mutation');
    return $policy;
}

function gesture_catalog_sort(string $sort): string
{
    if (!in_array($sort, ['last_uploaded', 'file_name', 'custom'], true)) {
        throw new GestureCatalogException('Gesture sort is invalid.', 400, 'INVALID_SORT');
    }
    return $sort;
}

function gesture_catalog_preferences(PDO $pdo, int $userId, bool $lock = false): array
{
    $sql = 'SELECT * FROM gesture_preferences WHERE user_id = ? LIMIT 1';
    if ($lock && db_driver($pdo) === 'mysql') $sql .= ' FOR UPDATE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return [
            '_exists' => false,
            'user_id' => $userId,
            'server_sort' => 'last_uploaded',
            'personal_sort' => 'last_uploaded',
            'show_animations' => 1,
            'show_text' => 1,
            'play_sounds' => 1,
            'preference_version' => 0,
            'server_order_version' => 0,
            'personal_order_version' => 0,
            'hidden_version' => 0,
            'sender_visibility_version' => 0,
        ];
    }
    foreach (['show_animations', 'show_text', 'play_sounds', 'preference_version', 'server_order_version', 'personal_order_version', 'hidden_version', 'sender_visibility_version'] as $column) {
        $row[$column] = (int)$row[$column];
    }
    $row['_exists'] = true;
    return $row;
}

function gesture_catalog_hidden_ids(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT gesture_public_id FROM gesture_hidden WHERE user_id = ? ORDER BY gesture_public_id ASC LIMIT 5001');
    $stmt->execute([$userId]);
    $ids = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (count($ids) > 5000) throw new GestureCatalogException('Hidden gesture preferences exceed the bounded limit.', 503, 'HIDDEN_QUERY_LIMIT');
    return $ids;
}

function gesture_catalog_hidden_sender_user_ids(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT target_user_id FROM gesture_sender_media_hidden '
        . 'WHERE viewer_user_id = ? ORDER BY target_user_id ASC LIMIT 5001'
    );
    $stmt->execute([$userId]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (count($ids) > 5000) {
        throw new GestureCatalogException(
            'Gesture sender visibility preferences exceed the bounded limit.',
            503,
            'SENDER_VISIBILITY_QUERY_LIMIT'
        );
    }
    return $ids;
}

function gesture_catalog_preferences_payload(PDO $pdo, int $userId, ?array $preferences = null): array
{
    $preferences ??= gesture_catalog_preferences($pdo, $userId);
    return [
        'server_sort' => (string)$preferences['server_sort'],
        'personal_sort' => (string)$preferences['personal_sort'],
        'show_animations' => !empty($preferences['show_animations']),
        'show_text' => !empty($preferences['show_text']),
        'play_sounds' => !empty($preferences['play_sounds']),
        'preference_version' => (int)$preferences['preference_version'],
        'server_order_version' => (int)$preferences['server_order_version'],
        'personal_order_version' => (int)$preferences['personal_order_version'],
        'hidden_version' => (int)$preferences['hidden_version'],
        'hidden_ids' => gesture_catalog_hidden_ids($pdo, $userId),
        'sender_visibility_version' => (int)$preferences['sender_visibility_version'],
        'hidden_sender_user_ids' => gesture_catalog_hidden_sender_user_ids($pdo, $userId),
    ];
}

function gesture_catalog_boolean(mixed $value, string $label): bool
{
    if (is_bool($value)) return $value;
    if ($value === 1 || $value === 0 || $value === '1' || $value === '0') return (bool)$value;
    throw new GestureCatalogException($label . ' must be enabled or disabled.', 400, 'PREFERENCE_VALUE_INVALID');
}

function gesture_catalog_set_presentation_preferences(
    PDO $pdo,
    int $userId,
    array $values,
    int $expectedVersion,
    string $requestKey
): array {
    $normalized = [
        'show_animations' => gesture_catalog_boolean($values['show_animations'] ?? null, 'Show gesture animations'),
        'show_text' => gesture_catalog_boolean($values['show_text'] ?? null, 'Show gesture text'),
        'play_sounds' => gesture_catalog_boolean($values['play_sounds'] ?? null, 'Play gesture sounds'),
    ];
    $result = gesture_catalog_idempotent(
        $pdo,
        $userId,
        'set-presentation-preferences',
        $requestKey,
        compact('normalized', 'expectedVersion'),
        function () use ($pdo, $userId, $normalized, $expectedVersion): array {
            gesture_catalog_lock_user($pdo, $userId);
            $preferences = gesture_catalog_preferences($pdo, $userId, true);
            gesture_catalog_require_version(
                (int)$preferences['preference_version'],
                $expectedVersion,
                'PREFERENCE_VERSION_CONFLICT',
                gesture_catalog_preferences_payload($pdo, $userId, $preferences)
            );
            $nextVersion = $expectedVersion + 1;
            $values = [
                $normalized['show_animations'] ? 1 : 0,
                $normalized['show_text'] ? 1 : 0,
                $normalized['play_sounds'] ? 1 : 0,
                $nextVersion,
            ];
            if (empty($preferences['_exists'])) {
                $pdo->prepare(
                    'INSERT INTO gesture_preferences (user_id, show_animations, show_text, play_sounds, preference_version) VALUES (?,?,?,?,?)'
                )->execute([$userId, ...$values]);
            } else {
                $pdo->prepare(
                    'UPDATE gesture_preferences SET show_animations = ?, show_text = ?, play_sounds = ?, preference_version = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?'
                )->execute([...$values, $userId]);
            }
            return ['ok' => true, 'preferences' => gesture_catalog_preferences_payload($pdo, $userId)];
        }
    );
    gesture_capability_reset_viewer_state_cache();
    return $result;
}

function gesture_catalog_set_sender_media_hidden(
    PDO $pdo,
    int $viewerUserId,
    int $targetUserId,
    bool $hidden,
    int $expectedVersion,
    string $requestKey
): array {
    if ($targetUserId < 1 || $targetUserId === $viewerUserId) {
        throw new GestureCatalogException(
            'Choose another account for gesture-media visibility.',
            400,
            'SENDER_VISIBILITY_TARGET_INVALID'
        );
    }
    $result = gesture_catalog_idempotent(
        $pdo,
        $viewerUserId,
        $hidden ? 'hide-sender-media' : 'show-sender-media',
        $requestKey,
        compact('targetUserId', 'hidden', 'expectedVersion'),
        function () use ($pdo, $viewerUserId, $targetUserId, $hidden, $expectedVersion): array {
            gesture_catalog_lock_user($pdo, $viewerUserId);
            $target = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
            $target->execute([$targetUserId]);
            if (!$target->fetchColumn()) {
                throw new GestureCatalogException(
                    'Gesture sender account is unavailable.',
                    404,
                    'SENDER_VISIBILITY_TARGET_NOT_FOUND'
                );
            }
            $preferences = gesture_catalog_preferences($pdo, $viewerUserId, true);
            gesture_catalog_require_version(
                (int)$preferences['sender_visibility_version'],
                $expectedVersion,
                'SENDER_VISIBILITY_VERSION_CONFLICT',
                gesture_catalog_preferences_payload($pdo, $viewerUserId, $preferences)
            );
            $exists = $pdo->prepare(
                'SELECT 1 FROM gesture_sender_media_hidden '
                . 'WHERE viewer_user_id = ? AND target_user_id = ? LIMIT 1'
            );
            $exists->execute([$viewerUserId, $targetUserId]);
            $currentHidden = (bool)$exists->fetchColumn();
            if ($currentHidden === $hidden) {
                return [
                    'ok' => true,
                    'changed' => false,
                    'target_user_id' => $targetUserId,
                    'hidden' => $hidden,
                    'version' => $expectedVersion,
                    'preferences' => gesture_catalog_preferences_payload($pdo, $viewerUserId, $preferences),
                ];
            }
            if ($hidden) {
                $pdo->prepare(
                    'INSERT INTO gesture_sender_media_hidden '
                    . '(viewer_user_id, target_user_id) VALUES (?,?)'
                )->execute([$viewerUserId, $targetUserId]);
            } else {
                $pdo->prepare(
                    'DELETE FROM gesture_sender_media_hidden '
                    . 'WHERE viewer_user_id = ? AND target_user_id = ?'
                )->execute([$viewerUserId, $targetUserId]);
            }
            $nextVersion = $expectedVersion + 1;
            if (empty($preferences['_exists'])) {
                $pdo->prepare(
                    'INSERT INTO gesture_preferences (user_id, sender_visibility_version, preference_version) VALUES (?,?,0)'
                )->execute([$viewerUserId, $nextVersion]);
            } else {
                $pdo->prepare(
                    'UPDATE gesture_preferences SET sender_visibility_version = ?, '
                    . 'updated_at = CURRENT_TIMESTAMP WHERE user_id = ?'
                )->execute([$nextVersion, $viewerUserId]);
            }
            return [
                'ok' => true,
                'changed' => true,
                'target_user_id' => $targetUserId,
                'hidden' => $hidden,
                'version' => $nextVersion,
                'preferences' => gesture_catalog_preferences_payload($pdo, $viewerUserId),
            ];
        }
    );
    gesture_capability_reset_viewer_state_cache();
    return $result;
}

function gesture_catalog_require_version(int $actual, int $expected, string $code, array $projection): void
{
    if ($actual !== $expected) {
        throw new GestureCatalogException('Gesture catalog state changed. Refresh and try again.', 409, $code, ['authoritative' => $projection]);
    }
}

function gesture_catalog_set_sort(PDO $pdo, int $userId, string $scope, string $sort, int $expectedVersion, string $requestKey): array
{
    $scope = gesture_catalog_scope($scope);
    $sort = gesture_catalog_sort($sort);
    return gesture_catalog_idempotent($pdo, $userId, 'set-sort-' . $scope, $requestKey, compact('scope', 'sort', 'expectedVersion'), function () use ($pdo, $userId, $scope, $sort, $expectedVersion): array {
        gesture_catalog_lock_user($pdo, $userId);
        gesture_catalog_require_scope_policy($pdo, $scope, true);
        $preferences = gesture_catalog_preferences($pdo, $userId, true);
        gesture_catalog_require_version((int)$preferences['preference_version'], $expectedVersion, 'PREFERENCE_VERSION_CONFLICT', $preferences);
        $column = $scope . '_sort';
        $nextVersion = $expectedVersion + 1;
        if (empty($preferences['_exists'])) {
            $stmt = $pdo->prepare('INSERT INTO gesture_preferences (user_id, server_sort, personal_sort, preference_version) VALUES (?,?,?,?)');
            $stmt->execute([$userId, $scope === 'server' ? $sort : 'last_uploaded', $scope === 'personal' ? $sort : 'last_uploaded', $nextVersion]);
        } else {
            $pdo->prepare("UPDATE gesture_preferences SET {$column} = ?, preference_version = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?")
                ->execute([$sort, $nextVersion, $userId]);
        }
        return ['ok' => true, 'preferences' => gesture_catalog_preferences_payload($pdo, $userId)];
    });
}

function gesture_catalog_order_rows(PDO $pdo, int $userId, string $scope): array
{
    $stmt = $pdo->prepare('SELECT gesture_public_id, position_index FROM gesture_custom_order WHERE user_id = ? AND catalog_scope = ? ORDER BY position_index ASC');
    $stmt->execute([$userId, gesture_catalog_scope($scope)]);
    return $stmt->fetchAll();
}

function gesture_catalog_eligible_ids(PDO $pdo, int $userId, string $scope, bool $includeHidden = false): array
{
    $scope = gesture_catalog_scope($scope);
    if ($scope === 'personal') {
        $stmt = $pdo->prepare('SELECT public_id FROM gestures WHERE owner_user_id = ? AND deleted_at IS NULL ORDER BY id ASC');
        $stmt->execute([$userId]);
    } else {
        $sql = 'SELECT g.public_id FROM gestures g WHERE g.is_public = 1 AND g.owner_user_id <> ? AND g.deleted_at IS NULL';
        if (!$includeHidden) $sql .= ' AND NOT EXISTS (SELECT 1 FROM gesture_hidden h WHERE h.user_id = ? AND h.gesture_public_id = g.public_id)';
        $sql .= ' ORDER BY g.id ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($includeHidden ? [$userId] : [$userId, $userId]);
    }
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function gesture_catalog_set_order(
    PDO $pdo,
    int $userId,
    string $scope,
    array $orderedIds,
    int $expectedVersion,
    string $requestKey,
    string $search = ''
): array {
    $scope = gesture_catalog_scope($scope);
    if (trim($search) !== '') throw new GestureCatalogException('Clear the search to rearrange gestures.', 409, 'SEARCH_ACTIVE');
    $orderedIds = array_values(array_map('strval', $orderedIds));
    if (count($orderedIds) !== count(array_unique($orderedIds))) {
        throw new GestureCatalogException('Gesture order contains duplicate stable IDs.', 400, 'DUPLICATE_ORDER_ID');
    }
    return gesture_catalog_idempotent($pdo, $userId, 'set-order-' . $scope, $requestKey, compact('scope', 'orderedIds', 'expectedVersion'), function () use ($pdo, $userId, $scope, $orderedIds, $expectedVersion): array {
        gesture_catalog_lock_user($pdo, $userId);
        gesture_catalog_require_scope_policy($pdo, $scope, true);
        $preferences = gesture_catalog_preferences($pdo, $userId, true);
        $versionColumn = $scope . '_order_version';
        gesture_catalog_require_version((int)$preferences[$versionColumn], $expectedVersion, 'ORDER_VERSION_CONFLICT', $preferences);
        $eligible = gesture_catalog_eligible_ids($pdo, $userId, $scope, false);
        $expected = $eligible;
        sort($expected);
        $provided = $orderedIds;
        sort($provided);
        if ($expected !== $provided) {
            throw new GestureCatalogException('Gesture order must contain every visible stable ID exactly once.', 409, 'ORDER_MEMBERSHIP_CONFLICT', ['authoritative_ids' => $eligible]);
        }

        $fullOrder = $orderedIds;
        if ($scope === 'server') {
            $visible = array_fill_keys($orderedIds, true);
            $hiddenRows = array_values(array_filter(gesture_catalog_order_rows($pdo, $userId, $scope), static fn(array $row): bool => !isset($visible[(string)$row['gesture_public_id']])));
            foreach ($hiddenRows as $row) {
                $position = max(0, min((int)$row['position_index'], count($fullOrder)));
                array_splice($fullOrder, $position, 0, [(string)$row['gesture_public_id']]);
            }
        }
        $pdo->prepare('DELETE FROM gesture_custom_order WHERE user_id = ? AND catalog_scope = ?')->execute([$userId, $scope]);
        $insert = $pdo->prepare('INSERT INTO gesture_custom_order (user_id, catalog_scope, gesture_public_id, position_index) VALUES (?,?,?,?)');
        foreach ($fullOrder as $position => $publicId) $insert->execute([$userId, $scope, $publicId, $position]);
        $nextVersion = $expectedVersion + 1;
        if (empty($preferences['_exists'])) {
            $sortColumn = $scope . '_sort';
            $pdo->prepare("INSERT INTO gesture_preferences (user_id, {$sortColumn}, {$versionColumn}) VALUES (?,'custom',?)")
                ->execute([$userId, $nextVersion]);
        } else {
            $pdo->prepare("UPDATE gesture_preferences SET {$versionColumn} = ?, {$scope}_sort = 'custom', updated_at = CURRENT_TIMESTAMP WHERE user_id = ?")
                ->execute([$nextVersion, $userId]);
        }
        return ['ok' => true, 'scope' => $scope, 'order' => $orderedIds, 'version' => $nextVersion];
    });
}

function gesture_catalog_move_before(array $order, string $movingId, ?string $beforeId): array
{
    if (!in_array($movingId, $order, true)) throw new GestureCatalogException('Moving gesture is not in the catalog.', 409, 'ORDER_MEMBER_MISSING');
    $order = array_values(array_filter($order, static fn(string $id): bool => $id !== $movingId));
    $position = $beforeId === null ? count($order) : array_search($beforeId, $order, true);
    if ($position === false) throw new GestureCatalogException('Target gesture is not in the catalog.', 409, 'ORDER_TARGET_MISSING');
    array_splice($order, (int)$position, 0, [$movingId]);
    return $order;
}

function gesture_catalog_move_to_page(array $order, string $movingId, int $page, int $pageSize = 20): array
{
    $page = max(1, $page);
    $without = array_values(array_filter($order, static fn(string $id): bool => $id !== $movingId));
    if (count($without) === count($order)) throw new GestureCatalogException('Moving gesture is not in the catalog.', 409, 'ORDER_MEMBER_MISSING');
    $position = min(count($without), ($page - 1) * $pageSize);
    array_splice($without, $position, 0, [$movingId]);
    return $without;
}

function gesture_catalog_move(
    PDO $pdo,
    int $userId,
    string $scope,
    string $publicId,
    string $operation,
    mixed $target,
    int $expectedVersion,
    string $requestKey,
    string $search = ''
): array {
    $scope = gesture_catalog_scope($scope);
    if (trim($search) !== '') throw new GestureCatalogException('Clear the search to rearrange gestures.', 409, 'SEARCH_ACTIVE');
    $catalog = gesture_catalog_query($pdo, $userId, $scope, ['sort' => 'custom', 'page' => 1]);
    $order = array_values(array_map('strval', $catalog['ordered_ids'] ?? []));
    if ($operation === 'move_before') {
        $order = gesture_catalog_move_before($order, $publicId, $target === null || $target === '' ? null : (string)$target);
    } elseif ($operation === 'move_top') {
        $order = array_values(array_filter($order, static fn(string $id): bool => $id !== $publicId));
        array_unshift($order, $publicId);
    } elseif ($operation === 'move_page') {
        $page = filter_var($target, FILTER_VALIDATE_INT);
        $pages = max(1, (int)ceil(count($order) / 20));
        if ($page === false || (int)$page < 1 || (int)$page > $pages) {
            throw new GestureCatalogException('Choose an available destination page.', 400, 'MOVE_PAGE_INVALID', ['pages' => $pages]);
        }
        $order = gesture_catalog_move_to_page($order, $publicId, (int)$page, 20);
    } else {
        throw new GestureCatalogException('Gesture move action is invalid.', 400, 'MOVE_ACTION_INVALID');
    }
    return gesture_catalog_set_order($pdo, $userId, $scope, $order, $expectedVersion, $requestKey, '');
}

function gesture_catalog_reset_position(
    PDO $pdo,
    int $userId,
    string $scope,
    string $publicId,
    int $expectedVersion,
    string $requestKey,
    string $search = ''
): array {
    $scope = gesture_catalog_scope($scope);
    if (trim($search) !== '') throw new GestureCatalogException('Clear the search to rearrange gestures.', 409, 'SEARCH_ACTIVE');
    return gesture_catalog_idempotent($pdo, $userId, 'reset-position-' . $scope, $requestKey, compact('scope', 'publicId', 'expectedVersion'), function () use ($pdo, $userId, $scope, $publicId, $expectedVersion): array {
        gesture_catalog_lock_user($pdo, $userId);
        gesture_catalog_require_scope_policy($pdo, $scope, true);
        if (!in_array($publicId, gesture_catalog_eligible_ids($pdo, $userId, $scope, false), true)) {
            throw new GestureCatalogException('Gesture is not in the visible catalog.', 409, 'ORDER_MEMBER_MISSING');
        }
        $preferences = gesture_catalog_preferences($pdo, $userId, true);
        $versionColumn = $scope . '_order_version';
        gesture_catalog_require_version((int)$preferences[$versionColumn], $expectedVersion, 'ORDER_VERSION_CONFLICT', gesture_catalog_preferences_payload($pdo, $userId, $preferences));
        $pdo->prepare('DELETE FROM gesture_custom_order WHERE user_id = ? AND catalog_scope = ? AND gesture_public_id = ?')
            ->execute([$userId, $scope, $publicId]);
        $nextVersion = $expectedVersion + 1;
        if (empty($preferences['_exists'])) {
            $pdo->prepare("INSERT INTO gesture_preferences (user_id, {$scope}_sort, {$versionColumn}) VALUES (?,'custom',?)")
                ->execute([$userId, $nextVersion]);
        } else {
            $pdo->prepare("UPDATE gesture_preferences SET {$scope}_sort = 'custom', {$versionColumn} = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?")
                ->execute([$nextVersion, $userId]);
        }
        return ['ok' => true, 'scope' => $scope, 'gesture_public_id' => $publicId, 'version' => $nextVersion];
    });
}

function gesture_catalog_hide(PDO $pdo, int $userId, string $publicId, bool $hidden, int $expectedVersion, string $requestKey): array
{
    return gesture_catalog_idempotent($pdo, $userId, $hidden ? 'hide' : 'unhide', $requestKey, compact('publicId', 'hidden', 'expectedVersion'), function () use ($pdo, $userId, $publicId, $hidden, $expectedVersion): array {
        gesture_catalog_lock_user($pdo, $userId);
        gesture_catalog_require_scope_policy($pdo, 'server', true);
        $stmt = $pdo->prepare('SELECT public_id FROM gestures WHERE public_id = ? AND owner_user_id <> ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$publicId, $userId]);
        if (!$stmt->fetch()) throw new GestureCatalogException('Server Gesture not found.', 404, 'GESTURE_NOT_FOUND');
        $preferences = gesture_catalog_preferences($pdo, $userId, true);
        gesture_catalog_require_version((int)$preferences['hidden_version'], $expectedVersion, 'HIDDEN_VERSION_CONFLICT', $preferences);
        $exists = $pdo->prepare('SELECT previous_position FROM gesture_hidden WHERE user_id = ? AND gesture_public_id = ? LIMIT 1');
        $exists->execute([$userId, $publicId]);
        $row = $exists->fetch();
        if ($hidden && !$row) {
            $position = $pdo->prepare("SELECT position_index FROM gesture_custom_order WHERE user_id = ? AND catalog_scope = 'server' AND gesture_public_id = ? LIMIT 1");
            $position->execute([$userId, $publicId]);
            $value = $position->fetchColumn();
            $pdo->prepare('INSERT INTO gesture_hidden (user_id, gesture_public_id, previous_position) VALUES (?,?,?)')
                ->execute([$userId, $publicId, $value === false ? null : (int)$value]);
        } elseif (!$hidden && $row) {
            $pdo->prepare('DELETE FROM gesture_hidden WHERE user_id = ? AND gesture_public_id = ?')->execute([$userId, $publicId]);
        }
        $nextVersion = $expectedVersion + 1;
        if (empty($preferences['_exists'])) {
            $pdo->prepare('INSERT INTO gesture_preferences (user_id, hidden_version) VALUES (?,?)')->execute([$userId, $nextVersion]);
        } else {
            $pdo->prepare('UPDATE gesture_preferences SET hidden_version = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?')->execute([$nextVersion, $userId]);
        }
        return ['ok' => true, 'gesture_public_id' => $publicId, 'hidden' => $hidden, 'version' => $nextVersion];
    });
}

function gesture_catalog_unhide_many(
    PDO $pdo,
    int $userId,
    array $publicIds,
    int $expectedVersion,
    string $requestKey
): array {
    $publicIds = array_values(array_unique(array_filter(array_map('strval', $publicIds), static fn(string $id): bool => $id !== '')));
    if (count($publicIds) > 5000) throw new GestureCatalogException('Too many hidden gestures were selected.', 400, 'HIDDEN_SELECTION_LIMIT');
    return gesture_catalog_idempotent($pdo, $userId, 'unhide-many', $requestKey, compact('publicIds', 'expectedVersion'), function () use ($pdo, $userId, $publicIds, $expectedVersion): array {
        gesture_catalog_lock_user($pdo, $userId);
        gesture_catalog_require_scope_policy($pdo, 'server', true);
        $preferences = gesture_catalog_preferences($pdo, $userId, true);
        gesture_catalog_require_version((int)$preferences['hidden_version'], $expectedVersion, 'HIDDEN_VERSION_CONFLICT', gesture_catalog_preferences_payload($pdo, $userId, $preferences));
        $current = gesture_catalog_hidden_ids($pdo, $userId);
        $targets = $publicIds ?: $current;
        if (array_diff($targets, $current)) throw new GestureCatalogException('Hidden gesture selection changed. Refresh and try again.', 409, 'HIDDEN_MEMBERSHIP_CONFLICT');
        if ($targets) {
            $placeholders = implode(',', array_fill(0, count($targets), '?'));
            $pdo->prepare("DELETE FROM gesture_hidden WHERE user_id = ? AND gesture_public_id IN ({$placeholders})")
                ->execute([$userId, ...$targets]);
        }
        $nextVersion = $expectedVersion + 1;
        if (empty($preferences['_exists'])) {
            $pdo->prepare('INSERT INTO gesture_preferences (user_id, hidden_version) VALUES (?,?)')->execute([$userId, $nextVersion]);
        } else {
            $pdo->prepare('UPDATE gesture_preferences SET hidden_version = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?')->execute([$nextVersion, $userId]);
        }
        return ['ok' => true, 'unhidden_ids' => $targets, 'version' => $nextVersion];
    });
}

function gesture_catalog_row_payload(array $row, int $viewerUserId, bool $admin = false): array
{
    $mine = (int)$row['owner_user_id'] === $viewerUserId;
    $mediaPurpose = $admin ? 'admin' : 'catalog';
    $animationUrl = function_exists('gesture_package_media_url')
        ? gesture_package_media_url($row, 'animation', $mediaPurpose)
        : media_url((string)$row['gif_path']);
    $posterUrl = function_exists('gesture_package_media_url')
        ? gesture_package_media_url($row, 'poster', $mediaPurpose)
        : null;
    $audioUrl = !empty($row['audio_path'])
        ? (function_exists('gesture_package_media_url') ? gesture_package_media_url($row, 'audio', $mediaPurpose) : media_url((string)$row['audio_path']))
        : null;
    $payload = [
        'id' => (int)$row['id'],
        'public_id' => (string)$row['public_id'],
        'name' => (string)$row['name'],
        'catalog_filename' => (string)($row['catalog_filename'] ?: $row['name']),
        'title' => (string)($row['title'] ?: $row['name']),
        'text' => (string)$row['gesture_text'],
        'creator_credit' => (string)($row['creator_credit'] ?: ''),
        'uploaded_by' => (string)($row['uploader_display_name'] ?? ''),
        'gif_path' => $animationUrl,
        'gif_url' => $animationUrl,
        'poster_path' => $posterUrl,
        'poster_url' => $posterUrl,
        'audio_path' => $audioUrl,
        'audio_url' => $audioUrl,
        'audio_is_silent' => !empty($row['audio_is_silent']),
        'is_public' => !empty($row['is_public']),
        'mine' => $mine,
        'owner_user_id' => (int)$row['owner_user_id'],
        'version' => max(1, (int)($row['version'] ?? 1)),
        'package_generation' => max(1, (int)($row['package_generation'] ?? 1)),
        'package_version' => max(0, (int)($row['package_version'] ?? 0)),
        'package_status' => (string)($row['package_status'] ?? 'legacy-unverified'),
        'content_sha256' => (string)($row['content_sha256'] ?? ''),
        'created_at' => $row['created_at'] ?? null,
        'original_uploaded_at' => $row['original_uploaded_at'] ?? $row['created_at'] ?? null,
        'content_updated_at' => $row['content_updated_at'] ?? $row['updated_at'] ?? null,
        'published_at' => $row['published_at'] ?? null,
    ];
    if ($admin) {
        $payload['original_filename'] = (string)($row['original_filename'] ?: '');
        $payload['legacy_metadata'] = !empty($row['legacy_metadata']);
    }
    return $payload;
}

function gesture_catalog_query(PDO $pdo, int $userId, string $scope, array $options = [], bool $admin = false): array
{
    $scope = $admin ? 'server' : ($scope === 'hidden' ? 'hidden' : gesture_catalog_scope($scope));
    $memberCapability = null;
    if (!$admin) {
        $memberCapability = gesture_catalog_require_scope_policy(
            $pdo,
            $scope === 'hidden' ? 'server' : $scope
        );
    }
    $query = trim((string)($options['q'] ?? ''));
    if ((function_exists('mb_strlen') ? mb_strlen($query, 'UTF-8') : strlen($query)) > 120) {
        throw new GestureCatalogException('Gesture search is too long.', 400, 'SEARCH_TOO_LONG');
    }
    $page = max(1, (int)($options['page'] ?? 1));
    $perPage = $admin ? 50 : 20;
    $preferences = gesture_catalog_preferences($pdo, $userId);
    $sort = $admin ? (string)($options['sort'] ?? 'last_uploaded') : (string)($options['sort'] ?? $preferences[($scope === 'personal' ? 'personal' : 'server') . '_sort']);
    if ($admin && !in_array($sort, ['last_uploaded', 'file_name'], true)) throw new GestureCatalogException('Admin gesture sort is invalid.', 400, 'INVALID_SORT');
    if (!$admin) $sort = gesture_catalog_sort($sort);

    $params = [];
    if ($admin) {
        $where = 'g.deleted_at IS NULL AND g.is_public = 1';
    } elseif ($scope === 'personal') {
        $where = 'g.deleted_at IS NULL AND g.owner_user_id = ?';
        $params[] = $userId;
    } elseif ($scope === 'hidden') {
        $where = 'g.deleted_at IS NULL AND g.is_public = 1 AND g.owner_user_id <> ? AND EXISTS (SELECT 1 FROM gesture_hidden h WHERE h.user_id = ? AND h.gesture_public_id = g.public_id)';
        array_push($params, $userId, $userId);
    } else {
        $where = 'g.deleted_at IS NULL AND g.is_public = 1 AND g.owner_user_id <> ? AND NOT EXISTS (SELECT 1 FROM gesture_hidden h WHERE h.user_id = ? AND h.gesture_public_id = g.public_id)';
        array_push($params, $userId, $userId);
    }
    if ($query !== '') {
        $columns = ['g.catalog_filename', 'g.title', 'g.gesture_text'];
        $where .= ' AND (' . implode(' OR ', array_map(static fn(string $column): string => "LOWER({$column}) LIKE ? ESCAPE '!'", $columns)) . ')';
        $lowered = function_exists('mb_strtolower') ? mb_strtolower($query, 'UTF-8') : strtolower($query);
        $needle = '%' . strtr($lowered, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        foreach ($columns as $_) $params[] = $needle;
    }
    $stmt = $pdo->prepare(
        'SELECT g.*, uploader.display_name AS uploader_display_name FROM gestures g '
        . 'LEFT JOIN users uploader ON uploader.id = g.uploaded_by_user_id WHERE ' . $where . ' LIMIT 5001'
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    if (count($rows) > 5000) throw new GestureCatalogException('Gesture catalog exceeds the bounded query limit.', 503, 'CATALOG_QUERY_LIMIT');

    $orderMap = [];
    if (!$admin && $sort === 'custom') {
        foreach (gesture_catalog_order_rows($pdo, $userId, $scope === 'personal' ? 'personal' : 'server') as $row) {
            $orderMap[(string)$row['gesture_public_id']] = (int)$row['position_index'];
        }
    }
    $serverTimestamps = $admin || $scope !== 'personal';
    usort($rows, static function (array $left, array $right) use ($sort, $orderMap, $serverTimestamps): int {
        if ($sort === 'file_name') {
            $result = strnatcasecmp((string)$left['catalog_filename'], (string)$right['catalog_filename']);
            return $result !== 0 ? $result : strcmp((string)$left['public_id'], (string)$right['public_id']);
        }
        if ($sort === 'custom') {
            $leftKnown = array_key_exists((string)$left['public_id'], $orderMap);
            $rightKnown = array_key_exists((string)$right['public_id'], $orderMap);
            if ($leftKnown !== $rightKnown) return $leftKnown ? 1 : -1;
            if ($leftKnown) {
                $result = $orderMap[(string)$left['public_id']] <=> $orderMap[(string)$right['public_id']];
                if ($result !== 0) return $result;
            }
        }
        $leftContent = (string)($left['content_updated_at'] ?: $left['created_at']);
        $rightContent = (string)($right['content_updated_at'] ?: $right['created_at']);
        $leftTime = $serverTimestamps ? max($leftContent, (string)($left['published_at'] ?: '')) : $leftContent;
        $rightTime = $serverTimestamps ? max($rightContent, (string)($right['published_at'] ?: '')) : $rightContent;
        $result = strcmp($rightTime, $leftTime);
        return $result !== 0 ? $result : ((int)$right['id'] <=> (int)$left['id']);
    });
    $total = count($rows);
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    $items = array_map(static fn(array $row): array => gesture_catalog_row_payload($row, $userId, $admin), array_slice($rows, $offset, $perPage));
    if (!$admin) {
        $part4 = gesture_part4_feature_flags($pdo);
        foreach ($items as &$item) {
            $item = gesture_capability_project_catalog_payload(
                $pdo,
                $item,
                false,
                $memberCapability,
                $part4
            );
        }
        unset($item);
    }
    return [
        'catalog' => $admin ? 'admin' : $scope,
        'items' => $items,
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'pages' => $pages,
        'has_more' => $offset + $perPage < $total,
        'query' => $query,
        'sort' => $sort,
        'preferences' => gesture_catalog_preferences_payload($pdo, $userId, $preferences),
        'ordered_ids' => !$admin && $query === ''
            ? array_values(array_map(static fn(array $row): string => (string)$row['public_id'], $rows))
            : [],
        'reorder_allowed' => $query === '' && !$admin,
    ];
}

function gesture_catalog_lock_row(PDO $pdo, string $publicId, ?int $ownerUserId = null): array
{
    $sql = 'SELECT * FROM gestures WHERE public_id = ? AND deleted_at IS NULL';
    $params = [$publicId];
    if ($ownerUserId !== null) {
        $sql .= ' AND owner_user_id = ?';
        $params[] = $ownerUserId;
    }
    $sql .= ' LIMIT 1';
    if (db_driver($pdo) === 'mysql') $sql .= ' FOR UPDATE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    if (!$row) throw new GestureCatalogException('Gesture not found.', 404, 'GESTURE_NOT_FOUND');
    return $row;
}

function gesture_catalog_assert_filename_available(PDO $pdo, int $ownerUserId, string $stem, int $excludeId = 0): void
{
    $stmt = $pdo->prepare('SELECT id FROM gestures WHERE owner_user_id = ? AND catalog_filename_key = ? AND active_catalog_key = ? AND id <> ? LIMIT 1');
    $stmt->execute([$ownerUserId, gesture_catalog_filename_key($stem), 'active', $excludeId]);
    if ($stmt->fetch()) throw new GestureCatalogException('That catalog filename is already used in this Personal catalog.', 409, 'CATALOG_FILENAME_CONFLICT');
}

function gesture_catalog_toggle_public(PDO $pdo, int $userId, string $publicId, bool $isPublic, int $expectedVersion, string $requestKey): array
{
    return gesture_catalog_idempotent($pdo, $userId, 'toggle-public', $requestKey, compact('publicId', 'isPublic', 'expectedVersion'), function () use ($pdo, $userId, $publicId, $isPublic, $expectedVersion): array {
        $policy = gesture_catalog_require_user_mutation($pdo, true);
        gesture_capability_require_scope($policy, 'personal');
        $row = gesture_catalog_lock_row($pdo, $publicId, $userId);
        gesture_catalog_require_version((int)$row['version'], $expectedVersion, 'GESTURE_VERSION_CONFLICT', gesture_catalog_row_payload($row, $userId));
        if ($isPublic && !in_array((string)($row['package_status'] ?? 'legacy-unverified'), ['valid', 'legacy-unverified'], true)) {
            throw new GestureCatalogException('Gesture package must be valid before publication.', 409, 'PACKAGE_VALIDATION_REQUIRED');
        }
        $firstPublication = $isPublic && empty($row['published_at']);
        $publishedAt = $firstPublication ? gmdate('Y-m-d H:i:s') : ($row['published_at'] ?: null);
        $pdo->prepare(
            'UPDATE gestures SET is_public = ?, published_at = ?, '
            . 'visibility_changed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP, version = version + 1 WHERE id = ?'
        )->execute([$isPublic ? 1 : 0, $publishedAt, (int)$row['id']]);
        if (!$isPublic && function_exists('gesture_package_rotate_media_token')) {
            gesture_package_rotate_media_token($pdo, (int)$row['id'], max(1, (int)($row['package_generation'] ?? 1)));
        }
        $updated = gesture_catalog_lock_row($pdo, $publicId, $userId);
        return ['ok' => true, 'gesture' => gesture_catalog_row_payload($updated, $userId)];
    });
}

function gesture_catalog_update_personal_metadata(PDO $pdo, int $userId, string $publicId, array $changes, int $expectedVersion, string $requestKey): array
{
    return gesture_catalog_idempotent($pdo, $userId, 'update-personal-metadata', $requestKey, compact('publicId', 'changes', 'expectedVersion'), function () use ($pdo, $userId, $publicId, $changes, $expectedVersion): array {
        $policy = gesture_catalog_require_user_mutation($pdo, true);
        gesture_capability_require_scope($policy, 'personal');
        $row = gesture_catalog_lock_row($pdo, $publicId, $userId);
        if (!empty($row['is_public'])) throw new GestureCatalogException('Make this gesture Personal before editing it.', 403, 'PUBLIC_OWNER_EDIT_REFUSED');
        gesture_catalog_require_version((int)$row['version'], $expectedVersion, 'GESTURE_VERSION_CONFLICT', gesture_catalog_row_payload($row, $userId));
        $catalog = gesture_catalog_filename_stem((string)($changes['catalog_filename'] ?? $row['catalog_filename']), 'gesture-' . $row['id']);
        gesture_catalog_assert_filename_available($pdo, $userId, $catalog, (int)$row['id']);
        $title = gesture_catalog_clean_text((string)($changes['title'] ?? $row['title']), 120, 'Gesture');
        $text = gesture_catalog_clean_text((string)($changes['text'] ?? $row['gesture_text']), 180, $title);
        $creator = gesture_catalog_clean_text((string)($changes['creator_credit'] ?? $row['creator_credit']), 120, 'Unknown creator');
        $contentChanged = $title !== (string)$row['title'] || $text !== (string)$row['gesture_text'] || $creator !== (string)$row['creator_credit'];
        $contentUpdatedAt = $contentChanged ? gmdate('Y-m-d H:i:s') : (string)$row['content_updated_at'];
        $pdo->prepare(
            'UPDATE gestures SET catalog_filename = ?, catalog_filename_key = ?, title = ?, name = ?, gesture_text = ?, creator_credit = ?, '
            . 'content_updated_at = ?, metadata_updated_at = CURRENT_TIMESTAMP, '
            . 'updated_at = CURRENT_TIMESTAMP, version = version + 1 WHERE id = ?'
        )->execute([$catalog, gesture_catalog_filename_key($catalog), $title, $title, $text, $creator, $contentUpdatedAt, (int)$row['id']]);
        $updated = gesture_catalog_lock_row($pdo, $publicId, $userId);
        return ['ok' => true, 'content_changed' => $contentChanged, 'gesture' => gesture_catalog_row_payload($updated, $userId)];
    });
}

function gesture_catalog_admin_update(PDO $pdo, array $actor, string $publicId, array $changes, int $expectedVersion, string $requestKey): array
{
    if (($actor['role'] ?? '') !== 'admin') throw new GestureCatalogException('Administrator authorization is required.', 403, 'ADMIN_REQUIRED');
    $actorId = (int)$actor['id'];
    return gesture_catalog_idempotent($pdo, $actorId, 'admin-update-metadata', $requestKey, compact('publicId', 'changes', 'expectedVersion'), function () use ($pdo, $actorId, $publicId, $changes, $expectedVersion): array {
        $row = gesture_catalog_lock_row($pdo, $publicId);
        if (empty($row['is_public'])) throw new GestureCatalogException('Only a Server Gesture can be edited from Admin.', 409, 'SERVER_GESTURE_REQUIRED');
        gesture_catalog_require_version((int)$row['version'], $expectedVersion, 'GESTURE_VERSION_CONFLICT', gesture_catalog_row_payload($row, $actorId, true));
        $catalog = gesture_catalog_filename_stem((string)($changes['catalog_filename'] ?? $row['catalog_filename']), 'gesture-' . $row['id']);
        gesture_catalog_assert_filename_available($pdo, (int)$row['owner_user_id'], $catalog, (int)$row['id']);
        $title = gesture_catalog_clean_text((string)($changes['title'] ?? $row['title']), 120, 'Gesture');
        $text = gesture_catalog_clean_text((string)($changes['text'] ?? $row['gesture_text']), 180, $title);
        $creator = gesture_catalog_clean_text((string)($changes['creator_credit'] ?? $row['creator_credit']), 120, 'Unknown creator');
        $contentChanged = $title !== (string)$row['title'] || $text !== (string)$row['gesture_text'] || $creator !== (string)$row['creator_credit'];
        $contentUpdatedAt = $contentChanged ? gmdate('Y-m-d H:i:s') : (string)$row['content_updated_at'];
        $old = ['catalog_filename' => $row['catalog_filename'], 'title' => $row['title'], 'text' => $row['gesture_text'], 'creator_credit' => $row['creator_credit']];
        $pdo->prepare(
            'UPDATE gestures SET catalog_filename = ?, catalog_filename_key = ?, title = ?, name = ?, gesture_text = ?, creator_credit = ?, '
            . 'content_updated_at = ?, metadata_updated_at = CURRENT_TIMESTAMP, '
            . 'updated_at = CURRENT_TIMESTAMP, version = version + 1 WHERE id = ?'
        )->execute([$catalog, gesture_catalog_filename_key($catalog), $title, $title, $text, $creator, $contentUpdatedAt, (int)$row['id']]);
        $new = ['catalog_filename' => $catalog, 'title' => $title, 'text' => $text, 'creator_credit' => $creator];
        $changedFields = array_values(array_filter(array_keys($new), static fn(string $field): bool => (string)$old[$field] !== (string)$new[$field]));
        log_tool($pdo, $actorId, 'gesture_admin_metadata_update', (int)$row['owner_user_id'], null, json_encode([
            'gesture_public_id' => $publicId,
            'changed_fields' => $changedFields,
            'version_from' => (int)$row['version'],
            'version_to' => (int)$row['version'] + 1,
        ], JSON_UNESCAPED_SLASHES));
        $updated = gesture_catalog_lock_row($pdo, $publicId);
        return ['ok' => true, 'content_changed' => $contentChanged, 'gesture' => gesture_catalog_row_payload($updated, $actorId, true)];
    });
}

function gesture_catalog_delete(PDO $pdo, int $userId, string $publicId, int $expectedVersion, string $requestKey): array
{
    $result = gesture_catalog_idempotent($pdo, $userId, 'delete', $requestKey, compact('publicId', 'expectedVersion'), function () use ($pdo, $userId, $publicId, $expectedVersion): array {
        $policy = gesture_catalog_require_user_mutation($pdo, true);
        gesture_capability_require_scope($policy, 'personal');
        $row = gesture_catalog_lock_row($pdo, $publicId, $userId);
        gesture_catalog_require_version((int)$row['version'], $expectedVersion, 'GESTURE_VERSION_CONFLICT', gesture_catalog_row_payload($row, $userId));
        $pdo->prepare("UPDATE gestures SET deleted_at = CURRENT_TIMESTAMP, is_public = 0, active_catalog_key = NULL, visibility_changed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP, version = version + 1 WHERE id = ?")
            ->execute([(int)$row['id']]);
        $pdo->prepare('DELETE FROM gesture_custom_order WHERE gesture_public_id = ?')->execute([$publicId]);
        $pdo->prepare('DELETE FROM gesture_hidden WHERE gesture_public_id = ?')->execute([$publicId]);
        $pdo->prepare('DELETE FROM gesture_downloads WHERE gesture_public_id = ?')->execute([$publicId]);
        return ['ok' => true, 'gesture_public_id' => $publicId];
    });
    if (function_exists('gesture_package_cleanup_deleted')) gesture_package_cleanup_deleted($pdo, $publicId);
    return $result;
}

function gesture_catalog_download_policy(PDO $pdo): array
{
    return [
        'cooldown_seconds' => max(1, (int)app_setting($pdo, 'gesture_download_cooldown_seconds', '30')),
        'daily_limit' => max(1, (int)app_setting($pdo, 'gesture_download_daily_limit', '20')),
        'emergency_daily_limit' => max(1, (int)app_setting($pdo, 'gesture_download_emergency_daily_limit', '1000')),
        'active_timeout_seconds' => max(30, (int)app_setting($pdo, 'gesture_download_active_timeout_seconds', '300')),
        'history_days' => max(1, (int)app_setting($pdo, 'gesture_download_history_days', '90')),
    ];
}

function gesture_catalog_begin_download(PDO $pdo, int $userId, string $gesturePublicId, string $requestPublicId): array
{
    if (!preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $requestPublicId)) throw new GestureCatalogException('Download request ID is invalid.', 400, 'INVALID_DOWNLOAD_REQUEST');
    return gesture_catalog_transaction($pdo, function () use ($pdo, $userId, $gesturePublicId, $requestPublicId): array {
        gesture_catalog_lock_user($pdo, $userId);
        $capability = gesture_capability_lock($pdo);
        gesture_capability_require_scope($capability, 'server');
        $existing = $pdo->prepare('SELECT * FROM gesture_downloads WHERE request_public_id = ? LIMIT 1');
        $existing->execute([$requestPublicId]);
        if ($row = $existing->fetch()) {
            if ((int)$row['user_id'] !== $userId || (string)$row['gesture_public_id'] !== $gesturePublicId) {
                throw new GestureCatalogException('Download request ID was already used.', 409, 'DOWNLOAD_REQUEST_CONFLICT');
            }
            return ['ok' => true, 'idempotent' => true, 'download' => $row];
        }
        $gesture = $pdo->prepare('SELECT public_id FROM gestures WHERE public_id = ? AND is_public = 1 AND owner_user_id <> ? AND deleted_at IS NULL LIMIT 1');
        $gesture->execute([$gesturePublicId, $userId]);
        if (!$gesture->fetch()) throw new GestureCatalogException('Server Gesture is not eligible for download.', 403, 'DOWNLOAD_NOT_AUTHORIZED');
        $policy = gesture_catalog_download_policy($pdo);
        $now = gmdate('Y-m-d H:i:s');
        $pdo->prepare("UPDATE gesture_downloads SET status = 'expired', active_user_id = NULL, failure_code = 'timeout' WHERE status = 'active' AND expires_at <= ?")
            ->execute([$now]);
        $active = $pdo->prepare("SELECT 1 FROM gesture_downloads WHERE active_user_id = ? AND status = 'active' LIMIT 1");
        $active->execute([$userId]);
        if ($active->fetch()) throw new GestureCatalogException('One gesture-package download is already active.', 429, 'DOWNLOAD_ALREADY_ACTIVE');
        $last = $pdo->prepare("SELECT completed_at FROM gesture_downloads WHERE user_id = ? AND status = 'completed' ORDER BY completed_at DESC LIMIT 1");
        $last->execute([$userId]);
        $completedAt = $last->fetchColumn();
        if ($completedAt !== false && time() - (strtotime((string)$completedAt) ?: 0) < $policy['cooldown_seconds']) {
            throw new GestureCatalogException('Wait before downloading another gesture package.', 429, 'DOWNLOAD_COOLDOWN');
        }
        $day = gmdate('Y-m-d 00:00:00');
        $count = $pdo->prepare("SELECT COUNT(*) FROM gesture_downloads WHERE user_id = ? AND status = 'completed' AND completed_at >= ?");
        $count->execute([$userId, $day]);
        if ((int)$count->fetchColumn() >= $policy['daily_limit']) throw new GestureCatalogException('Daily gesture-package download limit reached.', 429, 'DOWNLOAD_DAILY_LIMIT');
        $global = $pdo->prepare("SELECT COUNT(*) FROM gesture_downloads WHERE status = 'completed' AND completed_at >= ?");
        $global->execute([$day]);
        if ((int)$global->fetchColumn() >= $policy['emergency_daily_limit']) throw new GestureCatalogException('Gesture-package downloads are temporarily unavailable.', 503, 'DOWNLOAD_EMERGENCY_LIMIT');
        $expires = gmdate('Y-m-d H:i:s', time() + $policy['active_timeout_seconds']);
        $pdo->prepare("INSERT INTO gesture_downloads (request_public_id, user_id, gesture_public_id, status, active_user_id, expires_at) VALUES (?,?,?,'active',?,?)")
            ->execute([$requestPublicId, $userId, $gesturePublicId, $userId, $expires]);
        return ['ok' => true, 'idempotent' => false, 'download' => ['request_public_id' => $requestPublicId, 'gesture_public_id' => $gesturePublicId, 'status' => 'active', 'expires_at' => $expires], 'policy' => $policy];
    });
}

function gesture_catalog_finish_download(PDO $pdo, int $userId, string $requestPublicId, string $status, int $bytesDelivered = 0, string $failureCode = ''): array
{
    if (!in_array($status, ['completed', 'failed', 'canceled'], true)) throw new GestureCatalogException('Download completion status is invalid.', 400, 'INVALID_DOWNLOAD_STATUS');
    return gesture_catalog_transaction($pdo, function () use ($pdo, $userId, $requestPublicId, $status, $bytesDelivered, $failureCode): array {
        $sql = 'SELECT * FROM gesture_downloads WHERE request_public_id = ? AND user_id = ? LIMIT 1';
        if (db_driver($pdo) === 'mysql') $sql .= ' FOR UPDATE';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$requestPublicId, $userId]);
        $row = $stmt->fetch();
        if (!$row) throw new GestureCatalogException('Download reservation not found.', 404, 'DOWNLOAD_NOT_FOUND');
        if ((string)$row['status'] !== 'active') return ['ok' => true, 'idempotent' => true, 'status' => $row['status']];
        $completed = $status === 'completed' ? gmdate('Y-m-d H:i:s') : null;
        $pdo->prepare('UPDATE gesture_downloads SET status = ?, active_user_id = NULL, bytes_delivered = ?, completed_at = ?, failure_code = ? WHERE id = ?')
            ->execute([$status, $status === 'completed' ? max(0, $bytesDelivered) : 0, $completed, $status === 'completed' ? null : gesture_catalog_clean_text($failureCode, 64, $status), (int)$row['id']]);
        return ['ok' => true, 'idempotent' => false, 'status' => $status, 'counts_toward_quota' => $status === 'completed'];
    });
}

function gesture_catalog_cleanup_download_history(PDO $pdo): int
{
    $cutoff = gmdate('Y-m-d H:i:s', time() - gesture_catalog_download_policy($pdo)['history_days'] * 86400);
    $stmt = $pdo->prepare("DELETE FROM gesture_downloads WHERE status <> 'active' AND started_at < ?");
    $stmt->execute([$cutoff]);
    return $stmt->rowCount();
}

function gesture_catalog_exception_payload(GestureCatalogException $error): array
{
    return ['error' => $error->getMessage(), 'error_code' => $error->errorCode] + $error->context;
}
