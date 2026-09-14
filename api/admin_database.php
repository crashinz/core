<?php
require_once __DIR__ . '/../includes/database_backups.php';
try {
    $adminRestoreActivation = backup_sqlite_prebootstrap_activate(
        isset($_COOKIE['corechat_restore_activation'])
            ? (string)$_COOKIE['corechat_restore_activation']
            : null
    );
} catch (Throwable) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    @error_log('CoreChat DATABASE_RESTORE_ACTIVATION_FAILED');
    echo json_encode(['error' => 'Database restore activation failed.', 'code' => 'DATABASE_RESTORE_ACTIVATION_FAILED']);
    exit;
}
if (is_array($adminRestoreActivation) && ($_GET['action'] ?? '') === 'complete_restore') {
    setcookie('corechat_restore_activation', '', [
        'expires' => time() - 3600,
        'path' => (string)($_SERVER['SCRIPT_NAME'] ?? '/api/admin_database.php'),
        'secure' => !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($adminRestoreActivation, JSON_UNESCAPED_SLASHES);
    exit;
}
require_once __DIR__ . '/../includes/base.php';
require_once __DIR__ . '/../includes/room_importer.php';

$me = require_staff();
security_require_recent_authentication_or_json();

function portable_file_allowed(string $path): bool {
    return backup_portable_file_allowed($path);
}

function portable_file_path(string $path): string {
    return __DIR__ . '/..' . $path;
}

function add_portable_file(array &$files, ?string $path): void {
    $path = (string)($path ?? '');
    if ($path === '' || str_starts_with($path, 'preset:') || str_starts_with($path, 'data:') || !portable_file_allowed($path)) return;
    $full = portable_file_path($path);
    if (!is_file($full)) return;
    $mime = function_exists('mime_content_type') ? (mime_content_type($full) ?: 'application/octet-stream') : 'application/octet-stream';
    $files[$path] = [
        'path' => $path,
        'mime' => $mime,
        'bytes' => filesize($full),
        'sha256' => strtoupper((string)hash_file('sha256', $full)),
        'data' => base64_encode((string)file_get_contents($full)),
    ];
}

function export_core_bundle(PDO $pdo, int $actorId, array $options = []): void {
    $includeUsers = array_key_exists('users', $options) ? (bool)$options['users'] : true;
    $includeGestures = array_key_exists('gestures', $options) ? (bool)$options['gestures'] : false;
    $includeRooms = array_key_exists('rooms', $options) ? (bool)$options['rooms'] : true;
    $includeSettings = array_key_exists('settings', $options) ? (bool)$options['settings'] : true;
    $users = $pdo->query(
        'SELECT u.id, u.email, u.username, u.password_hash, u.display_name, '
        . 'u.role, u.avatar_path, u.aura_effect, u.created_at, '
        . 'p.discord_username, p.discord_visible, p.public_profile_id '
        . 'FROM users u JOIN member_profiles p ON p.user_id = u.id '
        . 'ORDER BY u.id ASC'
    )->fetchAll();
    $rooms = $pdo->query(
        'SELECT r.id, r.public_id, r.owner_id, u.email AS owner_email, r.name, r.background_path, r.background_mime, r.background_thumb_path, r.import_url, r.import_layout_json, r.music_playlist_json, r.created_at
           FROM rooms r
           JOIN users u ON u.id = r.owner_id
          ORDER BY r.id ASC'
    )->fetchAll();
    $settings = $pdo->query('SELECT setting_key, value FROM app_settings ORDER BY setting_key ASC')->fetchAll();
    $gestures = $pdo->query(
        'SELECT g.*, u.email AS owner_email
           FROM gestures g
           JOIN users u ON u.id = g.owner_user_id
          WHERE g.deleted_at IS NULL
          ORDER BY g.id ASC'
    )->fetchAll();
    $linkIcons = link_icon_catalog($pdo);
    $files = [];
    if ($includeUsers) {
        foreach ($users as $user) add_portable_file($files, $user['avatar_path'] ?? null);
    }
    if ($includeRooms) {
        foreach ($rooms as $room) {
            add_portable_file($files, $room['background_path'] ?? null);
            add_portable_file($files, $room['background_thumb_path'] ?? null);
            foreach (room_import_file_paths($room['import_layout_json'] ?? null, $room['music_playlist_json'] ?? null) as $path) {
                add_portable_file($files, $path);
            }
        }
    }
    if ($includeGestures) {
        foreach ($gestures as $gesture) {
            add_portable_file($files, $gesture['gif_path'] ?? null);
            add_portable_file($files, $gesture['audio_path'] ?? null);
        }
    }
    if ($includeSettings) {
        foreach ($linkIcons as $icon) add_portable_file($files, $icon['file_path'] ?? null);
    }

    $bundle = [
        'format' => BACKUP_PORTABLE_FORMAT,
        'version' => BACKUP_PORTABLE_CURRENT_VERSION,
        'producer' => [
            'application' => 'CoreChat',
            'format_version' => BACKUP_PORTABLE_CURRENT_VERSION,
            'schema_version' => CHATSPACE_SCHEMA_VERSION,
        ],
        'exported_at' => gmdate('c'),
        'includes' => [
            'users' => $includeUsers,
            'gestures' => $includeGestures,
            'rooms' => $includeRooms,
            'settings' => $includeSettings,
        ],
        'sections' => [],
        'files' => array_values($files),
    ];

    if ($includeUsers) {
        $bundle['sections']['users'] = array_map(fn(array $row): array => [
                'source_id' => (int)$row['id'],
                'email' => $row['email'],
                'password_hash' => $row['password_hash'],
                'username' => $row['username'],
                'display_name' => $row['display_name'],
                'discord_username' => $row['discord_username'] ?: null,
                'discord_visible' => !empty($row['discord_visible']),
                'public_profile_id' => member_profiles_validate_public_profile_id(
                    $row['public_profile_id'] ?? ''
                ),
                'role' => $row['role'] ?: 'user',
                'avatar_path' => $row['avatar_path'] ?: 'preset:Default',
                'aura_effect' => $row['aura_effect'] ?: null,
                'created_at' => $row['created_at'],
        ], $users);
    }

    if ($includeGestures) {
        $bundle['sections']['gestures'] = array_map(fn(array $row): array => [
            'source_id' => (int)$row['id'],
            'public_id' => $row['public_id'] ?: uuid_v4(),
            'owner_source_id' => (int)$row['owner_user_id'],
            'owner_email' => $row['owner_email'],
            'name' => $row['name'],
            'gesture_text' => $row['gesture_text'],
            'gif_path' => $row['gif_path'],
            'audio_path' => $row['audio_path'],
            'audio_is_silent' => !empty($row['audio_is_silent']),
            'is_public' => !empty($row['is_public']),
            'file_size' => $row['file_size'] !== null ? (int)$row['file_size'] : null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ], $gestures);
    }

    if ($includeRooms) {
        $bundle['sections']['rooms'] = array_map(fn(array $row): array => [
                'source_id' => (int)$row['id'],
                'public_id' => $row['public_id'] ?: uuid_v4(),
                'owner_source_id' => (int)$row['owner_id'],
                'owner_email' => $row['owner_email'],
                'name' => $row['name'],
                'background_path' => $row['background_path'],
                'background_mime' => $row['background_mime'],
                'background_thumb_path' => $row['background_thumb_path'],
                'import_url' => $row['import_url'] ?? null,
                'import_layout_json' => $row['import_layout_json'] ?? null,
                'music_playlist_json' => $row['music_playlist_json'] ?? null,
                'created_at' => $row['created_at'],
        ], $rooms);
    }

    if ($includeSettings) {
        $bundle['sections']['settings'] = array_map(fn(array $row): array => [
                'key' => $row['setting_key'],
                'value' => $row['value'],
        ], $settings);
        $bundle['sections']['link_icons'] = $linkIcons;
    }

    $labels = [];
    if ($includeUsers) $labels[] = $includeGestures ? 'users and gestures' : 'users';
    if ($includeRooms) $labels[] = 'rooms';
    if ($includeSettings) $labels[] = 'settings';
    log_tool($pdo, $actorId, 'admin_portable_export', null, null, 'Exported ' . ($labels ? implode(', ', $labels) : 'empty portable bundle') . ' and files');
    security_protect_private_response();
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="chatspace-core-' . gmdate('Ymd-His') . '.json"');
    echo json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_bool(string $key, bool $default = false): bool {
    if (!array_key_exists($key, $_GET)) return $default;
    return in_array(strtolower((string)$_GET[$key]), ['1', 'true', 'yes', 'on'], true);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'download') {
    if (db_driver() !== 'sqlite') json_out(['error' => 'Database download is available for SQLite installs. Use your MySQL/MariaDB backup tool for server databases.'], 400);
    $dbPath = sqlite_path();
    if (!is_file($dbPath)) json_out(['error' => 'Database not found'], 404);
    $snapshotDirectory = null;
    $snapshotPath = null;
    $snapshotCheck = null;
    $snapshotStream = null;
    $backupFailed = false;
    $responseStarted = false;
    $cleanupSnapshot = static function () use (&$snapshotDirectory, &$snapshotPath, &$snapshotCheck, &$snapshotStream): void {
        $snapshotCheck = null;
        if (is_resource($snapshotStream)) @fclose($snapshotStream);
        $snapshotStream = null;
        if (is_string($snapshotPath) && (@file_exists($snapshotPath) || @is_link($snapshotPath)) && !@unlink($snapshotPath)) {
            @error_log('CoreChat DATABASE_BACKUP_CLEANUP_FAILED');
        }
        if (is_string($snapshotDirectory) && @is_dir($snapshotDirectory) && !@rmdir($snapshotDirectory)) {
            @error_log('CoreChat DATABASE_BACKUP_CLEANUP_FAILED');
        }
    };
    register_shutdown_function($cleanupSnapshot);
    $previousIgnoreAbort = ignore_user_abort(true);
    set_error_handler(static function (int $severity): bool {
        if (!(error_reporting() & $severity)) return false;
        throw new ErrorException('Database backup operation failed.', 0, $severity);
    });
    try {
        $directory = realpath(security_private_storage_directory('database-downloads'));
        $publicRoot = realpath(dirname(__DIR__));
        if ($directory === false || $publicRoot === false) throw new RuntimeException('Private backup storage unavailable.');
        $directoryCompare = strtolower(str_replace('\\', '/', $directory));
        $publicCompare = strtolower(str_replace('\\', '/', $publicRoot));
        if ($directoryCompare === $publicCompare || str_starts_with($directoryCompare . '/', $publicCompare . '/')) {
            throw new RuntimeException('Private backup storage unavailable.');
        }
        $ownedDirectory = $directory . DIRECTORY_SEPARATOR . 'download-' . bin2hex(random_bytes(16));
        if (!mkdir($ownedDirectory, 0700)) throw new RuntimeException('Private backup storage unavailable.');
        $snapshotDirectory = $ownedDirectory;
        $snapshotPath = $snapshotDirectory . DIRECTORY_SEPARATOR . 'database.sqlite';
        $pdo = db();
        $pdo->exec('VACUUM INTO ' . $pdo->quote($snapshotPath));
        clearstatcache(true, $snapshotPath);
        $snapshotSize = is_file($snapshotPath) && !is_link($snapshotPath) ? filesize($snapshotPath) : false;
        if ($snapshotSize === false || $snapshotSize < 1) throw new RuntimeException('Database snapshot unavailable.');
        @chmod($snapshotPath, 0600);
        $snapshotCheck = new PDO('sqlite:' . $snapshotPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $snapshotCheck->exec('PRAGMA query_only = ON');
        if ($snapshotCheck->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') throw new RuntimeException('Database snapshot failed verification.');
        $snapshotCheck = null;
        $snapshotStream = fopen($snapshotPath, 'rb');
        if ($snapshotStream === false) throw new RuntimeException('Database snapshot unavailable.');
        log_tool($pdo, (int)$me['id'], 'admin_database_download', null, null, 'Downloaded database backup');
        security_protect_private_response();
        header('Content-Type: application/vnd.sqlite3');
        header('Content-Disposition: attachment; filename="chatspace-' . gmdate('Ymd-His') . '.sqlite"');
        header('Content-Length: ' . $snapshotSize);
        $responseStarted = true;
        if (fpassthru($snapshotStream) === false) throw new RuntimeException('Database backup transfer failed.');
    } catch (Throwable) {
        $backupFailed = true;
        @error_log($responseStarted ? 'CoreChat DATABASE_BACKUP_TRANSFER_FAILED' : 'CoreChat DATABASE_BACKUP_FAILED');
    } finally {
        $cleanupSnapshot();
        restore_error_handler();
        ignore_user_abort((bool)$previousIgnoreAbort);
    }
    if ($backupFailed && !$responseStarted) {
        header_remove('Content-Disposition');
        header_remove('Content-Length');
        security_protect_private_response();
        json_out(['error' => 'Database backup could not be created.', 'code' => 'DATABASE_BACKUP_FAILED'], 500);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && in_array(($_GET['action'] ?? ''), ['export_core', 'export_bundle'], true)) {
    export_core_bundle(db(), (int)$me['id'], [
        'users' => request_bool('users', true),
        'gestures' => request_bool('gestures', false),
        'rooms' => request_bool('rooms', true),
        'settings' => request_bool('settings', true),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Unsupported method'], 405);
security_authorize_outside_content_or_json(db(), $me, 'database_import', ['source' => 'admin_database']);

if (empty($_FILES['database']['tmp_name']) || !is_uploaded_file($_FILES['database']['tmp_name'])) {
    json_out(['error' => 'Import file required'], 400);
}

$tmp = $_FILES['database']['tmp_name'];
$actualBytes = filesize((string)$tmp);
if ($actualBytes === false || $actualBytes < 1 || $actualBytes > app_setting_bytes(db(), 'database_import_max_size_mb', 512)) {
    json_out(['error' => 'Import file exceeds the configured backup size limit.'], 400);
}
$decoded = json_decode((string)file_get_contents($tmp), true);
if (is_array($decoded) && ($decoded['format'] ?? '') === 'chatspace-ce-portable-bundle') {
    try {
        json_out(backup_import_core_bundle(db(), $decoded, (int)$me['id']));
    } catch (Throwable $e) {
        // Portable import wraps validation failures; preserve the typed cause.
        $profileError = $e;
        while ($profileError !== null) {
            if ($profileError instanceof MemberProfileException
                && $profileError->errorCode === 'MEMBER_PROFILE_FIELD_TOO_LONG'
                && $profileError->httpStatus === 400) {
                json_out(['error' => $e->getMessage(), 'code' => $profileError->errorCode], 400);
            }
            $profileError = $profileError->getPrevious();
        }
        json_out(['error' => $e->getMessage()], 500);
    }
}

try {
    $restoreResult = backup_restore_sqlite_upload($tmp, true, (int)$me['id']);
    if (!empty($restoreResult['pending_activation'])) {
        setcookie('corechat_restore_activation', (string)$restoreResult['activation_token'], [
            'expires' => time() + 300,
            'path' => (string)($_SERVER['SCRIPT_NAME'] ?? '/api/admin_database.php'),
            'secure' => !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        header('Location: ' . app_url('/api/admin_database.php?action=complete_restore'), true, 303);
        exit;
    }
    json_out($restoreResult);
} catch (Throwable $e) {
    json_out(['error' => $e->getMessage()], 400);
}


