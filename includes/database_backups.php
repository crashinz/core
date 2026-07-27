<?php
declare(strict_types=1);

const BACKUP_PORTABLE_FORMAT = 'chatspace-ce-portable-bundle';
const BACKUP_PORTABLE_CURRENT_VERSION = 2;
const BACKUP_PORTABLE_MAX_FILE_BYTES = 230686720;
const BACKUP_PORTABLE_MAX_TOTAL_BYTES = 536870912;

function backup_portable_custom_file_allowed(string $path): bool {
    return preg_match('~^/assets/uploads/(?:avatars|backgrounds|imported-rooms|gestures|link-icons|files|voice|branding)/[A-Za-z0-9][A-Za-z0-9._-]{0,190}$~', $path) === 1;
}

function backup_portable_builtin_file_identity(string $path): ?array {
    if (preg_match('~^/assets/images/cs-icons/([a-z0-9-]+)\\.png$~', $path, $match) !== 1) {
        return null;
    }
    $name = $match[1];
    $label = default_link_icons()[$name] ?? null;
    if (!is_string($label)) return null;
    return [
        'icon_name' => $name,
        'label' => $label,
        'path' => $path,
    ];
}

function backup_portable_file_allowed(string $path): bool {
    return backup_portable_custom_file_allowed($path)
        || backup_portable_builtin_file_identity($path) !== null;
}

function backup_portable_file_path(string $path): string {
    return __DIR__ . '/..' . $path;
}

function backup_portable_file_mime_policy(): array {
    return [
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'mp3' => ['audio/mpeg', 'audio/mp3'],
        'ogg' => ['audio/ogg', 'application/ogg'],
        'wav' => ['audio/wav', 'audio/x-wav'],
        'm4a' => ['audio/mp4'],
        'aac' => ['audio/aac'],
        'webm' => ['audio/webm', 'video/webm'],
        'mp4' => ['video/mp4'],
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'rtf' => ['application/rtf', 'text/rtf'],
        'txt' => ['text/plain'],
    ];
}

function backup_portable_validate_staged_file(string $path, string $destination): array {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)($finfo->file($destination) ?: '');
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $policy = backup_portable_file_mime_policy();
    $normalizedMime = $mime === 'application/zip' && $extension === 'docx'
        ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        : $mime;
    if (!isset($policy[$extension])
        || !in_array($mime, $policy[$extension], true)
        || !security_valid_uploaded_file_signature($destination, $normalizedMime, $extension)) {
        throw new RuntimeException('Portable bundle file content did not match its destination.');
    }
    $bytes = filesize($destination);
    $sha256 = hash_file('sha256', $destination);
    if ($bytes === false || !is_string($sha256)) {
        throw new RuntimeException('Portable bundle file identity could not be verified.');
    }
    return [
        'bytes' => (int)$bytes,
        'sha256' => strtoupper($sha256),
        'mime' => $mime,
    ];
}

function backup_portable_attempt_root(): string {
    return security_private_storage_directory('portable-import-attempts');
}

function backup_portable_attempt_write_manifest(string $directory, array $manifest): void {
    $path = $directory . DIRECTORY_SEPARATOR . 'attempt.json';
    $temporary = $directory . DIRECTORY_SEPARATOR . '.attempt-' . bin2hex(random_bytes(8)) . '.part';
    $content = database_migrations_canonical_json($manifest) . "\n";
    if (file_put_contents($temporary, $content, LOCK_EX) !== strlen($content)
        || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Portable import could not commit its private attempt manifest.');
    }
    @chmod($path, 0600);
}

function backup_portable_attempt_cleanup(array $attempt): void {
    $directory = (string)($attempt['directory'] ?? '');
    $root = realpath(backup_portable_attempt_root());
    $parent = $directory !== '' ? realpath(dirname($directory)) : false;
    if ($root === false
        || $parent === false
        || !hash_equals(strtolower($root), strtolower($parent))
        || preg_match('/^import-[a-f0-9]{32}$/', basename($directory)) !== 1) {
        throw new RuntimeException('Portable import refused an unowned cleanup target.');
    }
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (is_link($path) || !is_file($path) || !@unlink($path)) {
            throw new RuntimeException('Portable import could not clean its private attempt files.');
        }
    }
    if (!@rmdir($directory)) {
        throw new RuntimeException('Portable import could not clean its private attempt directory.');
    }
}

function backup_portable_path_is_referenced(PDO $pdo, string $path): bool {
    $queries = [
        ['SELECT 1 FROM users WHERE avatar_path = ? LIMIT 1', [$path]],
        ['SELECT 1 FROM rooms WHERE background_path = ? OR background_thumb_path = ? LIMIT 1', [$path, $path]],
        ['SELECT 1 FROM gestures WHERE gif_path = ? OR audio_path = ? LIMIT 1', [$path, $path]],
        ['SELECT 1 FROM link_icon_catalog WHERE file_path = ? LIMIT 1', [$path]],
    ];
    foreach ($queries as [$sql, $params]) {
        if (!database_migration_table_exists($pdo, strtok(substr($sql, strpos($sql, 'FROM ') + 5), ' '))) continue;
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        if ($statement->fetchColumn() !== false) return true;
    }
    if (database_migration_table_exists($pdo, 'rooms')) {
        foreach ($pdo->query('SELECT import_layout_json, music_playlist_json FROM rooms')->fetchAll() as $room) {
            if (in_array($path, room_import_file_paths(
                $room['import_layout_json'] ?? null,
                $room['music_playlist_json'] ?? null
            ), true)) return true;
        }
    }
    return false;
}

function backup_portable_reconcile_attempts(PDO $pdo): void {
    $root = backup_portable_attempt_root();
    foreach (scandir($root) ?: [] as $name) {
        if (preg_match('/^import-[a-f0-9]{32}$/', $name) !== 1) continue;
        $directory = $root . DIRECTORY_SEPARATOR . $name;
        $manifestPath = $directory . DIRECTORY_SEPARATOR . 'attempt.json';
        if (is_link($directory)
            || !is_dir($directory)
            || is_link($manifestPath)
            || !is_file($manifestPath)) {
            throw new RuntimeException('Portable import found unsafe interrupted private state.');
        }
        $manifest = json_decode((string)file_get_contents($manifestPath), true);
        if (!is_array($manifest)
            || ($manifest['schema'] ?? '') !== 'corechat-portable-import-attempt-v1'
            || ($manifest['attempt_id'] ?? '') !== substr($name, 7)) {
            throw new RuntimeException('Portable import found invalid interrupted private state.');
        }
        foreach ((array)($manifest['promoted'] ?? []) as $path) {
            if (!is_string($path) || !backup_portable_custom_file_allowed($path)) {
                throw new RuntimeException('Portable import found an invalid interrupted destination.');
            }
            $full = backup_portable_file_path($path);
            if (is_file($full) && !backup_portable_path_is_referenced($pdo, $path) && !@unlink($full)) {
                throw new RuntimeException('Portable import could not reconcile an interrupted promoted file.');
            }
        }
        backup_portable_attempt_cleanup(['directory' => $directory]);
    }
}

function backup_portable_prepare_files(array $files, int $version): array {
    $attemptId = bin2hex(random_bytes(16));
    $directory = backup_portable_attempt_root() . DIRECTORY_SEPARATOR . 'import-' . $attemptId;
    if (!mkdir($directory, 0700) && !is_dir($directory)) {
        throw new RuntimeException('Portable import could not reserve private staging.');
    }
    $attempt = [
        'attempt_id' => $attemptId,
        'directory' => $directory,
        'files' => [],
        'promoted' => [],
    ];
    $totalBytes = 0;
    try {
        foreach ($files as $index => $file) {
            if (!is_array($file)) throw new RuntimeException('Portable bundle contains an invalid file record.');
            $path = (string)($file['path'] ?? '');
            if (!backup_portable_file_allowed($path) || isset($attempt['files'][$path])) {
                throw new RuntimeException('Portable bundle contains an invalid or duplicate file destination.');
            }
            $data = base64_decode((string)($file['data'] ?? ''), true);
            if ($data === false || $data === '') throw new RuntimeException('Portable bundle contains invalid file data.');
            $bytes = strlen($data);
            if ($bytes > BACKUP_PORTABLE_MAX_FILE_BYTES) throw new RuntimeException('Portable bundle contains an oversized file.');
            $totalBytes += $bytes;
            if ($totalBytes > BACKUP_PORTABLE_MAX_TOTAL_BYTES) throw new RuntimeException('Portable bundle expands beyond the allowed size.');
            $staged = $directory . DIRECTORY_SEPARATOR . 'file-' . $index . '.part';
            if (file_put_contents($staged, $data, LOCK_EX) !== $bytes) {
                throw new RuntimeException('Portable bundle file could not be staged.');
            }
            $identity = backup_portable_validate_staged_file($path, $staged);
            if (array_key_exists('bytes', $file) && (int)$file['bytes'] !== $identity['bytes']) {
                throw new RuntimeException('Portable bundle file size did not match its declared identity.');
            }
            if (array_key_exists('mime', $file)
                && (!is_string($file['mime']) || !hash_equals($identity['mime'], (string)$file['mime']))) {
                throw new RuntimeException('Portable bundle file MIME did not match its declared identity.');
            }
            if ($version >= 2) {
                if (!array_key_exists('bytes', $file) || !array_key_exists('mime', $file)) {
                    throw new RuntimeException('Portable bundle file identity is incomplete.');
                }
                $declaredSha = strtoupper((string)($file['sha256'] ?? ''));
                if (!preg_match('/^[A-F0-9]{64}$/', $declaredSha)
                    || !hash_equals($identity['sha256'], $declaredSha)) {
                    throw new RuntimeException('Portable bundle file checksum did not match its declared identity.');
                }
            }
            $installed = backup_portable_file_path($path);
            $builtin = backup_portable_builtin_file_identity($path);
            if ($builtin !== null) {
                if (!is_file($installed)
                    || !hash_equals((string)hash_file('sha256', $installed), (string)hash_file('sha256', $staged))) {
                    throw new RuntimeException('Portable bundle built-in asset did not match the installed release asset.');
                }
                @unlink($staged);
                $staged = null;
            } elseif (is_file($installed)) {
                if (!hash_equals(strtoupper((string)hash_file('sha256', $installed)), $identity['sha256'])) {
                    throw new RuntimeException('Portable bundle would overwrite an existing file with different content.');
                }
                @unlink($staged);
                $staged = null;
            }
            $attempt['files'][$path] = [
                ...$identity,
                'path' => $path,
                'staged' => $staged,
                'builtin' => $builtin !== null,
            ];
        }
        backup_portable_attempt_write_manifest($directory, [
            'schema' => 'corechat-portable-import-attempt-v1',
            'attempt_id' => $attemptId,
            'created_at' => gmdate('c'),
            'promoted' => [],
        ]);
        return $attempt;
    } catch (Throwable $error) {
        try {
            backup_portable_attempt_cleanup($attempt);
        } catch (Throwable) {
        }
        throw $error;
    }
}

function backup_portable_promote_files(array &$attempt): void {
    foreach ($attempt['files'] as $path => &$file) {
        if (!is_string($file['staged'] ?? null)) continue;
        $destination = backup_portable_file_path($path);
        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Portable bundle destination directory could not be created.');
        }
        if (is_file($destination)) {
            if (!hash_equals(strtoupper((string)hash_file('sha256', $destination)), (string)$file['sha256'])) {
                throw new RuntimeException('Portable bundle destination changed during import.');
            }
            @unlink($file['staged']);
            $file['staged'] = null;
            continue;
        }
        if (!rename($file['staged'], $destination)) {
            throw new RuntimeException('Portable bundle file could not be finalized.');
        }
        $file['staged'] = null;
        $attempt['promoted'][] = $path;
        backup_portable_attempt_write_manifest($attempt['directory'], [
            'schema' => 'corechat-portable-import-attempt-v1',
            'attempt_id' => $attempt['attempt_id'],
            'created_at' => gmdate('c'),
            'promoted' => $attempt['promoted'],
        ]);
    }
    unset($file);
}

function backup_portable_rollback_promoted(array $attempt): void {
    foreach (array_reverse((array)$attempt['promoted']) as $path) {
        if (!is_string($path) || !backup_portable_custom_file_allowed($path)) continue;
        $destination = backup_portable_file_path($path);
        if (is_file($destination) && !is_link($destination)) @unlink($destination);
    }
}

function backup_portable_validate_bundle(PDO $pdo, array $bundle): array {
    require_once __DIR__ . '/room_importer.php';
    if (($bundle['format'] ?? '') !== BACKUP_PORTABLE_FORMAT) {
        throw new RuntimeException('Uploaded file is not a ChatSpace portable bundle.');
    }
    $version = filter_var($bundle['version'] ?? null, FILTER_VALIDATE_INT);
    if (!in_array($version, [1, BACKUP_PORTABLE_CURRENT_VERSION], true)) {
        throw new RuntimeException('Portable bundle version is not supported by this release.');
    }
    $allowedRoot = $version === 1
        ? ['exported_at', 'files', 'format', 'includes', 'sections', 'version']
        : ['exported_at', 'files', 'format', 'includes', 'producer', 'sections', 'version'];
    $rootKeys = array_keys($bundle);
    sort($rootKeys, SORT_STRING);
    sort($allowedRoot, SORT_STRING);
    if ($rootKeys !== $allowedRoot) {
        throw new RuntimeException('Portable bundle contains missing or unknown top-level fields.');
    }
    if (!is_string($bundle['exported_at'] ?? null)
        || strtotime((string)$bundle['exported_at']) === false
        || !is_array($bundle['includes'] ?? null)
        || !is_array($bundle['sections'] ?? null)
        || !is_array($bundle['files'] ?? null)) {
        throw new RuntimeException('Portable bundle envelope is invalid.');
    }
    if ($version >= 2) {
        $producer = $bundle['producer'] ?? null;
        $producerKeys = is_array($producer) ? array_keys($producer) : [];
        sort($producerKeys, SORT_STRING);
        if (!is_array($producer)
            || $producerKeys !== ['application', 'format_version', 'schema_version']
            || ($producer['application'] ?? '') !== 'CoreChat'
            || (int)($producer['format_version'] ?? 0) !== BACKUP_PORTABLE_CURRENT_VERSION
            || !is_string($producer['schema_version'] ?? null)
            || (string)$producer['schema_version'] === '') {
            throw new RuntimeException('Portable bundle producer identity is invalid.');
        }
    }

    $allowedSections = ['gestures', 'link_icons', 'rooms', 'settings', 'users'];
    foreach (array_keys($bundle['sections']) as $section) {
        if (!in_array($section, $allowedSections, true)
            || !is_array($bundle['sections'][$section])) {
            throw new RuntimeException('Portable bundle contains an unknown or invalid section.');
        }
    }
    $includeMap = [
        'users' => 'users',
        'gestures' => 'gestures',
        'rooms' => 'rooms',
        'settings' => 'settings',
    ];
    $includeKeys = array_keys($bundle['includes']);
    sort($includeKeys, SORT_STRING);
    $expectedIncludeKeys = array_keys($includeMap);
    sort($expectedIncludeKeys, SORT_STRING);
    if ($includeKeys !== $expectedIncludeKeys) {
        throw new RuntimeException('Portable bundle includes declaration is invalid.');
    }
    foreach ($includeMap as $include => $section) {
        if (!is_bool($bundle['includes'][$include])) {
            throw new RuntimeException('Portable bundle includes declaration must use booleans.');
        }
        $sectionPresent = array_key_exists($section, $bundle['sections']);
        if ($bundle['includes'][$include] !== $sectionPresent) {
            throw new RuntimeException('Portable bundle includes declaration does not match its sections.');
        }
    }
    if (array_key_exists('link_icons', $bundle['sections'])
        !== (bool)$bundle['includes']['settings']) {
        throw new RuntimeException('Portable bundle link-icon section does not match settings inclusion.');
    }

    $sections = $bundle['sections'];
    $filePaths = [];
    foreach ($bundle['files'] as $file) {
        if (!is_array($file)) throw new RuntimeException('Portable bundle contains an invalid file record.');
        $path = (string)($file['path'] ?? '');
        if (!backup_portable_file_allowed($path) || isset($filePaths[$path])) {
            throw new RuntimeException('Portable bundle contains an invalid or duplicate file destination.');
        }
        $filePaths[$path] = true;
    }
    $requireFile = static function (?string $path, bool $optional = false) use (&$filePaths): void {
        $path = (string)($path ?? '');
        if ($path === '' && $optional) return;
        if ($path === '' || str_starts_with($path, 'data:')) {
            throw new RuntimeException('Portable bundle contains an invalid file reference.');
        }
        if (str_starts_with($path, 'preset:')) return;
        if (!backup_portable_file_allowed($path)) {
            throw new RuntimeException('Portable bundle contains an unsupported file reference.');
        }
        $installed = backup_portable_file_path($path);
        if (!isset($filePaths[$path]) && !is_file($installed)) {
            throw new RuntimeException('Portable bundle is missing a referenced file.');
        }
    };
    $requireCustomFile = static function (
        ?string $path,
        array $directories,
        bool $optional = false
    ) use ($requireFile): void {
        $path = (string)($path ?? '');
        if ($path === '' && $optional) return;
        if (str_starts_with($path, 'preset:')) {
            if ($directories !== ['avatars']) {
                throw new RuntimeException('Portable bundle preset reference is not valid in this field.');
            }
            return;
        }
        $requireFile($path, $optional);
        $matched = false;
        foreach ($directories as $directory) {
            if (str_starts_with($path, '/assets/uploads/' . $directory . '/')) {
                $matched = true;
                break;
            }
        }
        if (!$matched || !backup_portable_custom_file_allowed($path)) {
            throw new RuntimeException('Portable bundle file reference is outside its source-backed destination class.');
        }
    };

    $sourceIds = [];
    $emails = [];
    foreach (($sections['users'] ?? []) as $user) {
        if (!is_array($user)) throw new RuntimeException('Portable bundle contains an invalid user.');
        $sourceId = filter_var($user['source_id'] ?? null, FILTER_VALIDATE_INT);
        $email = strtolower(trim((string)($user['email'] ?? '')));
        $username = trim((string)($user['username'] ?? ''));
        $displayName = trim((string)($user['display_name'] ?? ''));
        $passwordHash = (string)($user['password_hash'] ?? '');
        if ($sourceId === false
            || $sourceId < 1
            || isset($sourceIds[$sourceId])
            || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || isset($emails[$email])
            || ($version >= 2 && $username === '')
            || $displayName === ''
            || password_get_info($passwordHash)['algoName'] === 'unknown'
            || !in_array((string)($user['role'] ?? 'user'), ['user', 'guide', 'developer', 'admin'], true)) {
            throw new RuntimeException('Portable bundle contains an invalid or duplicate user identity.');
        }
        $sourceIds[$sourceId] = true;
        $emails[$email] = true;
        $requireCustomFile((string)($user['avatar_path'] ?? 'preset:Default'), ['avatars']);
    }

    foreach (($sections['settings'] ?? []) as $setting) {
        if (!is_array($setting)
            || trim((string)($setting['key'] ?? '')) === ''
            || !array_key_exists('value', $setting)
            || !(is_string($setting['value']) || is_numeric($setting['value']) || is_bool($setting['value']))) {
            throw new RuntimeException('Portable bundle contains an invalid setting.');
        }
    }
    foreach (($sections['link_icons'] ?? []) as $icon) {
        if (!is_array($icon)) throw new RuntimeException('Portable bundle contains an invalid link icon.');
        $name = (string)($icon['icon_name'] ?? '');
        $label = trim((string)($icon['label'] ?? ''));
        $path = (string)($icon['file_path'] ?? '');
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $name) !== 1 || $label === '') {
            throw new RuntimeException('Portable bundle contains an invalid link-icon identity.');
        }
        $builtin = backup_portable_builtin_file_identity($path);
        if (!empty($icon['built_in'])) {
            if ($builtin === null
                || $builtin['icon_name'] !== $name
                || $builtin['label'] !== $label) {
                throw new RuntimeException('Portable bundle built-in link icon does not match release authority.');
            }
        } elseif (!backup_portable_custom_file_allowed($path)
            || !str_starts_with($path, '/assets/uploads/link-icons/')) {
            throw new RuntimeException('Portable bundle custom link icon has an invalid destination.');
        }
        $requireFile($path);
    }

    foreach (($sections['gestures'] ?? []) as $gesture) {
        if (!is_array($gesture)
            || trim((string)($gesture['name'] ?? '')) === ''
            || trim((string)($gesture['gesture_text'] ?? $gesture['text'] ?? '')) === ''
            || (int)($gesture['owner_source_id'] ?? 0) < 1
            || trim((string)($gesture['owner_email'] ?? '')) === '') {
            throw new RuntimeException('Portable bundle contains an invalid gesture.');
        }
        $requireCustomFile((string)($gesture['gif_path'] ?? ''), ['gestures']);
        $requireCustomFile((string)($gesture['audio_path'] ?? ''), ['gestures'], true);
    }
    foreach (($sections['rooms'] ?? []) as $room) {
        if (!is_array($room)
            || trim((string)($room['name'] ?? '')) === ''
            || (int)($room['owner_source_id'] ?? 0) < 1
            || trim((string)($room['owner_email'] ?? '')) === '') {
            throw new RuntimeException('Portable bundle contains an invalid room.');
        }
        $requireCustomFile((string)($room['background_path'] ?? ''), ['backgrounds'], true);
        $requireCustomFile((string)($room['background_thumb_path'] ?? ''), ['backgrounds'], true);
        foreach (room_import_file_paths(
            $room['import_layout_json'] ?? null,
            $room['music_playlist_json'] ?? null
        ) as $path) {
            $requireCustomFile($path, ['imported-rooms', 'files', 'voice']);
        }
    }

    return [
        'version' => $version,
        'sections' => $sections,
        'files' => $bundle['files'],
    ];
}

function backup_import_core_bundle(PDO $pdo, array $bundle, int $actorId = 0): array {
    backup_portable_reconcile_attempts($pdo);
    $preflight = backup_portable_validate_bundle($pdo, $bundle);
    $sections = $preflight['sections'];
    $attempt = backup_portable_prepare_files($preflight['files'], $preflight['version']);

    $userMap = [];
    $transaction = database_transaction_begin($pdo, db_driver($pdo) === 'sqlite');
    try {
        foreach (($sections['settings'] ?? []) as $setting) {
            $key = trim((string)($setting['key'] ?? ''));
            set_app_setting($pdo, $key, (string)($setting['value'] ?? ''));
        }

        foreach (($sections['link_icons'] ?? []) as $icon) {
            $iconName = preg_replace('/[^a-z0-9-]/', '', (string)($icon['icon_name'] ?? '')) ?: '';
            $label = trim((string)($icon['label'] ?? ''));
            $filePath = (string)($icon['file_path'] ?? '');
            upsert_link_icon_catalog($pdo, $iconName, $label, $filePath, !empty($icon['built_in']));
        }

        foreach (($sections['users'] ?? []) as $user) {
            $email = strtolower(trim((string)($user['email'] ?? '')));
            $username = trim((string)($user['username'] ?? ''));
            $displayName = trim((string)($user['display_name'] ?? ''));
            $hash = (string)($user['password_hash'] ?? '');
            $role = in_array(($user['role'] ?? 'user'), ['user', 'guide', 'developer', 'admin'], true) ? (string)$user['role'] : 'user';
            $avatarPath = (string)($user['avatar_path'] ?? 'preset:Default');
            $requestedAura = trim((string)($user['aura_effect'] ?? ''));
            $auraEffect = $requestedAura !== '' ? normalize_aura_key($requestedAura) : null;
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $id = (int)($stmt->fetchColumn() ?: 0);
            if ($id) {
                member_profiles_import_identity($pdo, $id, $username, $displayName);
                $pdo->prepare(
                    'UPDATE users SET password_hash = ?, role = ?, avatar_path = ?, '
                    . 'aura_effect = ? WHERE id = ?'
                )->execute([$hash, $role, $avatarPath, $auraEffect, $id]);
            } else {
                if ($username !== '') {
                    $identity = member_profiles_validate_identity($pdo, $username, $displayName);
                    $pdo->prepare(
                        'INSERT INTO users '
                        . '(email, username, password_hash, display_name, role, avatar_path, aura_effect) '
                        . 'VALUES (?,?,?,?,?,?,?)'
                    )->execute([
                        $email, $identity['username'], $hash, $identity['display_name'],
                        $role, $avatarPath, $auraEffect,
                    ]);
                } else {
                    $displayName = member_profiles_validate_display_name($displayName);
                    if (!member_profiles_namespace_available($pdo, $displayName)) {
                        throw new MemberProfileException(
                            'Display name is already in use as a Username or Display name.',
                            'MEMBER_PROFILE_IDENTITY_NAME_TAKEN',
                            409
                        );
                    }
                    $pdo->prepare('INSERT INTO users (email, password_hash, display_name, role, avatar_path, aura_effect) VALUES (?,?,?,?,?,?)')
                        ->execute([$email, $hash, $displayName, $role, $avatarPath, $auraEffect]);
                }
                $id = (int)$pdo->lastInsertId();
                member_profiles_initialize_user($pdo, $id);
            }
            $userMap[(int)($user['source_id'] ?? 0)] = $id;
            $userMap[$email] = $id;
        }

        foreach (($sections['gestures'] ?? []) as $gesture) {
            $ownerEmail = strtolower(trim((string)($gesture['owner_email'] ?? '')));
            $ownerId = $userMap[(int)($gesture['owner_source_id'] ?? 0)] ?? $userMap[$ownerEmail] ?? 0;
            if (!$ownerId && $ownerEmail !== '') {
                $ownerStmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
                $ownerStmt->execute([$ownerEmail]);
                $ownerId = (int)($ownerStmt->fetchColumn() ?: 0);
            }
            $publicId = trim((string)($gesture['public_id'] ?? '')) ?: uuid_v4();
            $name = trim((string)($gesture['name'] ?? ''));
            $gestureText = trim((string)($gesture['gesture_text'] ?? $gesture['text'] ?? ''));
            $gifPath = (string)($gesture['gif_path'] ?? '');
            if (!$ownerId) throw new RuntimeException('Portable bundle gesture owner could not be resolved.');
            $audioPath = (string)($gesture['audio_path'] ?? '');
            $values = [
                $ownerId,
                $name,
                $gestureText,
                $gifPath,
                $audioPath !== '' ? $audioPath : null,
                !empty($gesture['audio_is_silent']) ? 1 : 0,
                !empty($gesture['is_public']) ? 1 : 0,
                array_key_exists('file_size', $gesture) && $gesture['file_size'] !== null ? (int)$gesture['file_size'] : null,
            ];
            $stmt = $pdo->prepare('SELECT id FROM gestures WHERE public_id = ? LIMIT 1');
            $stmt->execute([$publicId]);
            $gestureId = (int)($stmt->fetchColumn() ?: 0);
            if ($gestureId) {
                $pdo->prepare('UPDATE gestures SET owner_user_id = ?, name = ?, gesture_text = ?, gif_path = ?, audio_path = ?, audio_is_silent = ?, is_public = ?, file_size = ?, deleted_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
                    ->execute([...$values, $gestureId]);
            } else {
                $pdo->prepare('INSERT INTO gestures (public_id, owner_user_id, name, gesture_text, gif_path, audio_path, audio_is_silent, is_public, file_size) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$publicId, ...$values]);
            }
        }

        foreach (($sections['rooms'] ?? []) as $room) {
            $ownerId = $userMap[(int)($room['owner_source_id'] ?? 0)] ?? $userMap[strtolower((string)($room['owner_email'] ?? ''))] ?? 0;
            if (!$ownerId && !empty($room['owner_email'])) {
                $ownerStmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
                $ownerStmt->execute([strtolower((string)$room['owner_email'])]);
                $ownerId = (int)($ownerStmt->fetchColumn() ?: 0);
            }
            $name = trim((string)($room['name'] ?? ''));
            if (!$ownerId) throw new RuntimeException('Portable bundle room owner could not be resolved.');
            $publicId = trim((string)($room['public_id'] ?? '')) ?: uuid_v4();
            $stmt = $pdo->prepare('SELECT id FROM rooms WHERE public_id = ? LIMIT 1');
            $stmt->execute([$publicId]);
            $roomId = (int)($stmt->fetchColumn() ?: 0);
            $values = [
                $ownerId,
                $name,
                $room['background_path'] ?? null,
                $room['background_mime'] ?? null,
                $room['background_thumb_path'] ?? null,
                $room['import_url'] ?? null,
                $room['import_layout_json'] ?? null,
                $room['music_playlist_json'] ?? null,
            ];
            if ($roomId) {
                $pdo->prepare('UPDATE rooms SET owner_id = ?, name = ?, background_path = ?, background_mime = ?, background_thumb_path = ?, import_url = ?, import_layout_json = ?, music_playlist_json = ? WHERE id = ?')
                    ->execute([...$values, $roomId]);
            } else {
                $pdo->prepare('INSERT INTO rooms (public_id, owner_id, name, background_path, background_mime, background_thumb_path, import_url, import_layout_json, music_playlist_json) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$publicId, ...$values]);
                $roomId = (int)$pdo->lastInsertId();
            }
            active_session_for_room($pdo, $roomId);
        }

        backup_portable_promote_files($attempt);
        $imported = [];
        foreach (['users', 'gestures', 'rooms', 'settings', 'link_icons'] as $sectionName) {
            if (array_key_exists($sectionName, $sections)) $imported[] = $sectionName;
        }
        if ($actorId > 0) {
            log_tool(
                $pdo,
                $actorId,
                'admin_portable_import',
                null,
                null,
                database_migrations_canonical_json([
                    'format_version' => $preflight['version'],
                    'imported' => $imported,
                    'files' => count($attempt['files']),
                ])
            );
        }
        database_transaction_commit($pdo, $transaction);
    } catch (Throwable $e) {
        database_transaction_rollback($pdo, $transaction);
        backup_portable_rollback_promoted($attempt);
        try {
            backup_portable_attempt_cleanup($attempt);
        } catch (Throwable $cleanupError) {
            throw new RuntimeException(
                'Portable import failed and its exact private attempt requires recovery: '
                . $cleanupError->getMessage(),
                0,
                $e
            );
        }
        throw new RuntimeException('Portable import failed: ' . $e->getMessage(), 0, $e);
    }
    backup_portable_attempt_cleanup($attempt);
    return [
        'ok' => true,
        'type' => 'portable',
        'format_version' => $preflight['version'],
        'imported' => $imported,
        'files' => count($attempt['files']),
    ];
}

function backup_sqlite_open(string $path, bool $queryOnly = false): PDO {
    $pdo = new PDO(
        'sqlite:' . $path,
        null,
        null,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = ' . CHATSPACE_SQLITE_BUSY_TIMEOUT_MS);
    if ($queryOnly) $pdo->exec('PRAGMA query_only = ON');
    return $pdo;
}

function backup_sqlite_validate_integrity(PDO $pdo): array {
    $integrity = (string)$pdo->query('PRAGMA integrity_check')->fetchColumn();
    $quick = (string)$pdo->query('PRAGMA quick_check')->fetchColumn();
    $foreignKeyFinding = $pdo->query('PRAGMA foreign_key_check')->fetch();
    if ($integrity !== 'ok' || $quick !== 'ok' || $foreignKeyFinding !== false) {
        throw new RuntimeException('Uploaded SQLite backup failed integrity or foreign-key validation.');
    }
    return [
        'integrity_check' => $integrity,
        'quick_check' => $quick,
        'foreign_key_check' => 'ok',
    ];
}

function backup_sqlite_validate_current(PDO $pdo): array {
    $integrity = backup_sqlite_validate_integrity($pdo);
    $status = database_migration_status($pdo);
    $manifest = database_migrations_manifest();
    $ledger = database_migration_ledger_rows($pdo);
    $ledgerComplete = count($manifest) === count($ledger);
    if ($ledgerComplete) {
        foreach ($manifest as $index => $migration) {
            $row = $ledger[$index] ?? [];
            if (($row['migration_id'] ?? null) !== $migration['id']
                || !hash_equals((string)$migration['checksum'], (string)($row['checksum'] ?? ''))
                || !in_array((string)($row['result'] ?? ''), ['applied', 'adopted'], true)) {
                $ledgerComplete = false;
                break;
            }
        }
    }
    $users = database_migration_table_exists($pdo, 'users')
        ? (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()
        : 0;
    $admins = database_migration_table_exists($pdo, 'users')
        ? (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn()
        : 0;
    if (!$status['current']
        || !$status['release_complete']
        || (int)$status['pending_count'] !== 0
        || $status['defects'] !== []
        || !$ledgerComplete
        || $users < 1
        || $admins < 1) {
        throw new RuntimeException('Uploaded SQLite backup did not migrate to a complete current database with an administrator.');
    }
    return [
        ...$integrity,
        'schema_version' => CHATSPACE_SCHEMA_VERSION,
        'ledger_count' => count($ledger),
        'users' => $users,
        'admins' => $admins,
    ];
}

function backup_sqlite_copy_verified(string $source, string $destination): array {
    if (file_exists($destination) || is_link($destination)) {
        throw new RuntimeException('A private SQLite restore destination already exists.');
    }
    $input = fopen($source, 'rb');
    $output = fopen($destination, 'xb');
    if (!is_resource($input) || !is_resource($output)) {
        if (is_resource($input)) fclose($input);
        if (is_resource($output)) fclose($output);
        throw new RuntimeException('Could not reserve private SQLite restore storage.');
    }
    try {
        $bytes = stream_copy_to_stream($input, $output);
        if ($bytes === false
            || !fflush($output)
            || (function_exists('fsync') && !fsync($output))) {
            throw new RuntimeException('Could not synchronize the private SQLite restore copy.');
        }
    } finally {
        fclose($input);
        fclose($output);
    }
    clearstatcache(true, $source);
    clearstatcache(true, $destination);
    $sourceBytes = filesize($source);
    $destinationBytes = filesize($destination);
    $sourceSha = hash_file('sha256', $source);
    $destinationSha = hash_file('sha256', $destination);
    if ($sourceBytes === false
        || $destinationBytes === false
        || $sourceBytes !== $destinationBytes
        || !is_string($sourceSha)
        || !is_string($destinationSha)
        || !hash_equals($sourceSha, $destinationSha)) {
        @unlink($destination);
        throw new RuntimeException('The private SQLite restore copy did not match its source.');
    }
    @chmod($destination, 0600);
    return [
        'bytes' => (int)$destinationBytes,
        'sha256' => strtoupper($destinationSha),
    ];
}

function backup_sqlite_remove_attempt_file(string $path, string $expectedDirectory): void {
    $directory = realpath(dirname($path));
    $expected = realpath($expectedDirectory);
    if ($directory === false
        || $expected === false
        || !hash_equals(strtolower($expected), strtolower($directory))
        || !preg_match('/^\\.restore-[a-f0-9]{32}\\.(?:sqlite|part|old|rejected)$/', basename($path))) {
        throw new RuntimeException('SQLite restore refused an unowned cleanup target.');
    }
    foreach ([$path, $path . '-wal', $path . '-shm'] as $candidate) {
        if (!file_exists($candidate) && !is_link($candidate)) continue;
        if (is_link($candidate) || !is_file($candidate) || !@unlink($candidate)) {
            throw new RuntimeException('SQLite restore could not clean its exact attempt-owned file.');
        }
    }
}

function backup_sqlite_activation_directory(): string {
    if (function_exists('security_private_storage_directory')) {
        return security_private_storage_directory('database-restore-activation');
    }
    $publicRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
    $base = defined('CHATSPACE_PRIVATE_STORAGE_PATH')
        ? (string)CHATSPACE_PRIVATE_STORAGE_PATH
        : dirname($publicRoot) . DIRECTORY_SEPARATOR . 'chatspace-private';
    $base = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $base), DIRECTORY_SEPARATOR);
    $isAbsolute = str_starts_with($base, DIRECTORY_SEPARATOR)
        || preg_match('/^[A-Za-z]:\\\\/', $base) === 1;
    if ($base === '' || !$isAbsolute) {
        throw new RuntimeException('Private restore activation storage must use an absolute path.');
    }
    $directory = $base . DIRECTORY_SEPARATOR . 'database-restore-activation';
    if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create private restore activation storage.');
    }
    return $directory;
}

function backup_sqlite_activation_record_path(): string {
    return backup_sqlite_activation_directory() . DIRECTORY_SEPARATOR . 'pending.json';
}

function backup_sqlite_schedule_activation(
    string $stagedPath,
    string $databasePath,
    string $recoveryPath,
    array $final
): array {
    $recordPath = backup_sqlite_activation_record_path();
    if (file_exists($recordPath) || is_link($recordPath)) {
        throw new RuntimeException('A verified SQLite restore is already awaiting activation.');
    }
    $active = db();
    try {
        $active->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    } catch (Throwable) {
    }
    try {
        $active->exec('PRAGMA journal_mode = DELETE');
    } catch (Throwable) {
    }
    foreach ([$databasePath . '-wal', $databasePath . '-shm'] as $sidecar) {
        if (file_exists($sidecar) || is_link($sidecar)) {
            throw new RuntimeException('SQLite restore cannot schedule activation while database sidecars remain active.');
        }
    }

    $token = bin2hex(random_bytes(32));
    $publicId = bin2hex(random_bytes(16));
    $databaseDirectory = dirname($databasePath);
    $activationPath = $databaseDirectory . DIRECTORY_SEPARATOR . '.restore-' . $publicId . '.part';
    $oldPath = $databaseDirectory . DIRECTORY_SEPARATOR . '.restore-' . $publicId . '.old';
    $activationIdentity = backup_sqlite_copy_verified($stagedPath, $activationPath);
    clearstatcache(true, $databasePath);
    $activeBytes = filesize($databasePath);
    $activeSha = hash_file('sha256', $databasePath);
    $recoverySha = hash_file('sha256', $recoveryPath);
    if ($activeBytes === false || !is_string($activeSha) || !is_string($recoverySha)) {
        backup_sqlite_remove_attempt_file($activationPath, $databaseDirectory);
        throw new RuntimeException('SQLite restore could not freeze activation identities.');
    }
    $record = [
        'schema' => 'corechat-sqlite-restore-activation-v1',
        'public_id' => $publicId,
        'created_at' => gmdate('c'),
        'token_sha256' => strtoupper(hash('sha256', $token)),
        'database_path' => $databasePath,
        'activation_path' => $activationPath,
        'old_path' => $oldPath,
        'active_before_bytes' => (int)$activeBytes,
        'active_before_sha256' => strtoupper($activeSha),
        'activation_bytes' => $activationIdentity['bytes'],
        'activation_sha256' => $activationIdentity['sha256'],
        'recovery_path' => $recoveryPath,
        'recovery_sha256' => strtoupper($recoverySha),
        'schema_version' => (string)($final['schema_version'] ?? ''),
        'ledger_count' => (int)($final['ledger_count'] ?? 0),
        'admins' => (int)($final['admins'] ?? 0),
    ];
    $temporary = dirname($recordPath) . DIRECTORY_SEPARATOR . '.pending-' . $publicId . '.part';
    $content = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($temporary, $content, LOCK_EX) !== strlen($content)
        || !rename($temporary, $recordPath)) {
        @unlink($temporary);
        backup_sqlite_remove_attempt_file($activationPath, $databaseDirectory);
        throw new RuntimeException('SQLite restore could not commit durable activation state.');
    }
    @chmod($recordPath, 0600);
    return [
        'pending_activation' => true,
        'activation_public_id' => $publicId,
        'activation_token' => $token,
    ];
}

function backup_sqlite_prebootstrap_activate(?string $presentedToken = null): ?array {
    $configPath = __DIR__ . '/config.php';
    if (!defined('CHATSPACE_DB_DRIVER') && is_file($configPath) && !is_link($configPath)) {
        require_once $configPath;
    }
    if (!defined('CHATSPACE_DB_DRIVER') || CHATSPACE_DB_DRIVER !== 'sqlite') return null;
    $recordPath = backup_sqlite_activation_record_path();
    if (!file_exists($recordPath) && !is_link($recordPath)) return null;
    if (is_link($recordPath) || !is_file($recordPath)) {
        throw new RuntimeException('SQLite restore activation state is unsafe.');
    }
    try {
        $record = json_decode((string)file_get_contents($recordPath), true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        throw new RuntimeException('SQLite restore activation state is unreadable.', 0, $error);
    }
    $required = [
        'schema', 'public_id', 'created_at', 'token_sha256', 'database_path',
        'activation_path', 'old_path', 'active_before_bytes',
        'active_before_sha256', 'activation_bytes', 'activation_sha256',
        'recovery_path', 'recovery_sha256', 'schema_version', 'ledger_count',
        'admins',
    ];
    if (!is_array($record) || array_diff($required, array_keys($record)) !== []) {
        throw new RuntimeException('SQLite restore activation state is incomplete.');
    }
    if (($record['schema'] ?? '') !== 'corechat-sqlite-restore-activation-v1'
        || preg_match('/^[a-f0-9]{32}$/', (string)$record['public_id']) !== 1
        || preg_match('/^[A-F0-9]{64}$/', (string)$record['token_sha256']) !== 1
        || ($presentedToken !== null
            && !hash_equals((string)$record['token_sha256'], strtoupper(hash('sha256', $presentedToken))))) {
        throw new RuntimeException('SQLite restore activation authority is invalid.');
    }

    $databasePath = (string)$record['database_path'];
    $activationPath = (string)$record['activation_path'];
    $oldPath = (string)$record['old_path'];
    $databaseDirectory = realpath(dirname($databasePath));
    $configuredPath = defined('CHATSPACE_SQLITE_PATH') ? (string)CHATSPACE_SQLITE_PATH : '';
    $configuredDirectory = $configuredPath !== '' ? realpath(dirname($configuredPath)) : false;
    $publicId = (string)$record['public_id'];
    if ($databaseDirectory === false
        || $configuredDirectory === false
        || !hash_equals(strtolower($databaseDirectory), strtolower($configuredDirectory))
        || !hash_equals(strtolower($databasePath), strtolower($configuredPath))
        || dirname($activationPath) !== dirname($databasePath)
        || dirname($oldPath) !== dirname($databasePath)
        || basename($activationPath) !== '.restore-' . $publicId . '.part'
        || basename($oldPath) !== '.restore-' . $publicId . '.old') {
        throw new RuntimeException('SQLite restore activation paths are invalid.');
    }
    $recoveryPath = (string)$record['recovery_path'];
    if (!is_file($recoveryPath)
        || !hash_equals((string)$record['recovery_sha256'], strtoupper((string)hash_file('sha256', $recoveryPath)))) {
        throw new RuntimeException('SQLite restore activation recovery authority changed.');
    }

    $validateActive = static function (string $path) use ($record): void {
        if (!is_file($path)
            || (int)filesize($path) !== (int)$record['activation_bytes']
            || !hash_equals((string)$record['activation_sha256'], strtoupper((string)hash_file('sha256', $path)))) {
            throw new RuntimeException('Activated SQLite database identity did not match the verified candidate.');
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA query_only = ON');
        $integrity = (string)$pdo->query('PRAGMA integrity_check')->fetchColumn();
        $quick = (string)$pdo->query('PRAGMA quick_check')->fetchColumn();
        $foreignKey = $pdo->query('PRAGMA foreign_key_check')->fetch();
        $version = (string)$pdo->query(
            "SELECT value FROM app_settings WHERE setting_key = 'schema_version' LIMIT 1"
        )->fetchColumn();
        $ledger = (int)$pdo->query('SELECT COUNT(*) FROM core_migration_ledger')->fetchColumn();
        $admins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        $pdo = null;
        if ($integrity !== 'ok'
            || $quick !== 'ok'
            || $foreignKey !== false
            || !hash_equals((string)$record['schema_version'], $version)
            || $ledger !== (int)$record['ledger_count']
            || $admins !== (int)$record['admins']
            || $admins < 1) {
            throw new RuntimeException('Activated SQLite database failed final schema, ledger, or administrator validation.');
        }
    };

    $activeSha = is_file($databasePath)
        ? strtoupper((string)hash_file('sha256', $databasePath))
        : '';
    $activationSha = is_file($activationPath)
        ? strtoupper((string)hash_file('sha256', $activationPath))
        : '';
    $oldSha = is_file($oldPath) ? strtoupper((string)hash_file('sha256', $oldPath)) : '';
    $candidateActive = hash_equals((string)$record['activation_sha256'], $activeSha);
    try {
        if (!$candidateActive) {
            if ($activeSha !== ''
                && hash_equals((string)$record['active_before_sha256'], $activeSha)
                && $oldSha === ''
                && hash_equals((string)$record['activation_sha256'], $activationSha)) {
                if (!@rename($databasePath, $oldPath)) {
                    throw new RuntimeException('SQLite restore could not atomically reserve the active database path.');
                }
                $oldSha = (string)$record['active_before_sha256'];
                $activeSha = '';
            }
            if ($activeSha === ''
                && hash_equals((string)$record['active_before_sha256'], $oldSha)
                && hash_equals((string)$record['activation_sha256'], $activationSha)) {
                if (!@rename($activationPath, $databasePath)) {
                    @rename($oldPath, $databasePath);
                    throw new RuntimeException('SQLite restore activation failed before the candidate became active.');
                }
                $candidateActive = true;
            }
        }
        if (!$candidateActive) {
            throw new RuntimeException('SQLite restore activation state does not match a recoverable transition.');
        }
        $validateActive($databasePath);
    } catch (Throwable $error) {
        if (is_file($databasePath)
            && hash_equals((string)$record['activation_sha256'], strtoupper((string)hash_file('sha256', $databasePath)))
            && is_file($oldPath)
            && hash_equals((string)$record['active_before_sha256'], strtoupper((string)hash_file('sha256', $oldPath)))) {
            $rejected = dirname($databasePath) . DIRECTORY_SEPARATOR . '.restore-' . $publicId . '.rejected';
            if (@rename($databasePath, $rejected) && @rename($oldPath, $databasePath)) {
                @unlink($rejected);
            }
        }
        throw $error;
    }
    if (is_file($oldPath) && !@unlink($oldPath)) {
        throw new RuntimeException('SQLite restore activated but could not clean its exact prior active file.');
    }
    if (is_file($activationPath) && !@unlink($activationPath)) {
        throw new RuntimeException('SQLite restore activated but could not clean its exact activation file.');
    }
    if (!@unlink($recordPath)) {
        throw new RuntimeException('SQLite restore activated but could not clear durable activation state.');
    }
    return [
        'ok' => true,
        'type' => 'sqlite',
        'activation_complete' => true,
        'activation_public_id' => $publicId,
        'backup' => basename($recoveryPath),
    ];
}

function backup_restore_sqlite_upload(string $tmpPath, bool $uploadedFile, int $actorId = 0): array {
    if (db_driver() !== 'sqlite') {
        throw new RuntimeException('Full database restore is available for SQLite installs. Use portable import or your MySQL/MariaDB restore process.');
    }
    clearstatcache(true, $tmpPath);
    $bytes = is_file($tmpPath) && !is_link($tmpPath) ? filesize($tmpPath) : false;
    if ($bytes === false || $bytes < 100 || $bytes > app_setting_bytes(db(), 'database_import_max_size_mb', 512)) {
        throw new RuntimeException('Uploaded SQLite backup is missing or exceeds the configured backup size limit.');
    }
    $handle = fopen($tmpPath, 'rb');
    $header = is_resource($handle) ? fread($handle, 16) : false;
    if (is_resource($handle)) fclose($handle);
    if ($header !== "SQLite format 3\000") {
        throw new RuntimeException('Uploaded file is not a SQLite database.');
    }

    $attemptRoot = security_private_storage_directory('database-restore-attempts');
    $attemptId = uuid_v4();
    $attemptDirectory = $attemptRoot . DIRECTORY_SEPARATOR . 'restore-' . str_replace('-', '', $attemptId);
    if (!mkdir($attemptDirectory, 0700) && !is_dir($attemptDirectory)) {
        throw new RuntimeException('Could not reserve installation-private restore staging.');
    }
    $sourcePath = $attemptDirectory . DIRECTORY_SEPARATOR . 'source.sqlite';
    $stagedPath = $attemptDirectory . DIRECTORY_SEPARATOR . 'migrated.sqlite';
    $databasePath = sqlite_path();
    $recoveryDirectory = security_private_storage_directory('database-backups');
    $recoveryPath = $recoveryDirectory . DIRECTORY_SEPARATOR
        . 'chatspace-pre-restore-' . gmdate('Ymd-His') . '-' . str_replace('-', '', $attemptId) . '.sqlite';
    try {
        $sourceIdentity = backup_sqlite_copy_verified($tmpPath, $sourcePath);
        $source = backup_sqlite_open($sourcePath, true);
        try {
            $sourceIntegrity = backup_sqlite_validate_integrity($source);
            $variant = database_migration_variant($source);
            $sourceStatus = database_migration_status($source);
            if (!empty($sourceStatus['current'])
                && !empty($sourceStatus['release_complete'])
                && (int)($sourceStatus['pending_count'] ?? -1) === 0
                && ($sourceStatus['defects'] ?? []) === []) {
                $variant = [
                    'id' => 'current',
                    'rank' => PHP_INT_MAX,
                    'recognized' => true,
                ];
            }
        } finally {
            $source = null;
        }
        $allowedVariants = array_column(database_migration_supported_predecessor_registry(), 'id');
        $allowedVariants[] = 'current';
        if (!$variant['recognized']
            || !in_array((string)$variant['id'], $allowedVariants, true)) {
            throw new RuntimeException('Uploaded SQLite backup schema is unknown, partial, or newer than this release. No mutation was attempted.');
        }

        backup_sqlite_copy_verified($sourcePath, $stagedPath);
        $staged = backup_sqlite_open($stagedPath);
        try {
            $before = database_migration_variant($staged);
            $migration = database_migrations_run($staged, null, false);
            $final = backup_sqlite_validate_current($staged);
            if ($actorId > 0) {
                log_tool(
                    $staged,
                    $actorId,
                    'admin_database_restore',
                    null,
                    null,
                    database_migrations_canonical_json([
                        'attempt_public_id' => $attemptId,
                        'source_variant' => $before['id'],
                        'source_sha256' => $sourceIdentity['sha256'],
                        'migration_no_op' => !empty($migration['no_op']),
                    ])
                );
            }
            $staged->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            $staged->exec('PRAGMA journal_mode = DELETE');
        } finally {
            $staged = null;
        }

        $active = db();
        $active->exec('VACUUM INTO ' . $active->quote($recoveryPath));
        $recovery = backup_sqlite_open($recoveryPath, true);
        try {
            backup_sqlite_validate_integrity($recovery);
        } finally {
            $recovery = null;
        }
        $activation = backup_sqlite_schedule_activation(
            $stagedPath,
            $databasePath,
            $recoveryPath,
            $final
        );
        return [
            'ok' => true,
            'type' => 'sqlite',
            'backup' => basename($recoveryPath),
            'source_variant' => $variant['id'],
            'source_sha256' => $sourceIdentity['sha256'],
            'migration_no_op' => !empty($migration['no_op']),
            'final' => $final,
            ...$activation,
        ];
    } catch (Throwable $error) {
        $failureMessage = $error->getMessage();
        unset($error);
        if (function_exists('gc_collect_cycles')) gc_collect_cycles();
        foreach ([$sourcePath, $stagedPath] as $owned) {
            foreach ([$owned, $owned . '-wal', $owned . '-shm', $owned . '-journal'] as $candidate) {
                if (is_file($candidate) && !is_link($candidate)) @unlink($candidate);
            }
        }
        @rmdir($attemptDirectory);
        if (is_file($recoveryPath) && filesize($recoveryPath) === 0) @unlink($recoveryPath);
        throw new RuntimeException('Full SQLite restore failed: ' . $failureMessage);
    } finally {
        foreach ([$sourcePath, $stagedPath] as $owned) {
            foreach ([$owned, $owned . '-wal', $owned . '-shm', $owned . '-journal'] as $candidate) {
                if (is_file($candidate) && !is_link($candidate)) @unlink($candidate);
            }
        }
        @rmdir($attemptDirectory);
    }
}

function backup_import_uploaded_file(PDO $pdo, array $upload, int $actorId = 0): array {
    if (empty($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
        throw new RuntimeException('Import file required.');
    }
    $maxBytes = app_setting_bytes($pdo, 'database_import_max_size_mb', 512);
    $actualBytes = filesize((string)$upload['tmp_name']);
    if ($actualBytes === false || $actualBytes < 1 || $actualBytes > $maxBytes) {
        throw new RuntimeException('Import file exceeds the configured backup size limit.');
    }
    $tmp = $upload['tmp_name'];
    $decoded = json_decode((string)file_get_contents($tmp), true);
    if (is_array($decoded) && ($decoded['format'] ?? '') === 'chatspace-ce-portable-bundle') {
        return backup_import_core_bundle($pdo, $decoded, $actorId);
    }
    return backup_restore_sqlite_upload($tmp, true, $actorId);
}
