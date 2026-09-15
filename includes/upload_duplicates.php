<?php
declare(strict_types=1);

/** Content identity is separate from names, ownership and public media IDs. */
function upload_duplicate_fingerprint(string $kind, array $parts): string
{
    ksort($parts);
    return hash('sha256', json_encode([$kind, $parts], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

/** Caller holds an immediate SQLite / PDO MySQL transaction through publication. */
function upload_duplicate_lock(PDO $pdo, string $kind, int $userId = 0): void
{
    if (!in_array($kind, ['avatar', 'nameplate', 'gesture', 'emoji'], true)) throw new InvalidArgumentException('Unknown upload kind.');
    if ($userId > 0) {
        // Same ordering as explicit library selection: user, then catalog/asset.
        $user = $pdo->prepare('SELECT id FROM users WHERE id=?' . (db_uses_mysql_syntax($pdo) ? ' FOR UPDATE' : ''));
        $user->execute([$userId]);
        if (!$user->fetchColumn()) throw new RuntimeException('The upload identity is unavailable.');
    }
    $key = 'upload_duplicate.lock.' . $kind;
    $pdo->prepare(db_uses_mysql_syntax($pdo)
        ? 'INSERT IGNORE INTO app_settings (setting_key,value) VALUES (?,?)'
        : 'INSERT OR IGNORE INTO app_settings (setting_key,value) VALUES (?,?)')->execute([$key, '1']);
    $lock = $pdo->prepare('SELECT value FROM app_settings WHERE setting_key=?' . (db_uses_mysql_syntax($pdo) ? ' FOR UPDATE' : ''));
    $lock->execute([$key]);
    $lock->fetchColumn();
}

function upload_duplicate_result(string $kind, string $id, string $name, string $scope): array
{
    return ['ok' => true, 'duplicate' => true, 'message' => 'Already uploaded.',
        'existing' => ['kind' => $kind, 'id' => $id, 'name' => $name, 'scope' => $scope]];
}

/** Never hash a database path outside its media owner's storage boundary. */
function upload_duplicate_image_path(string $kind, string $public, ?string $stored = null): ?string
{
    $pattern = $kind === 'nameplate'
        ? '#\A/assets/uploads/(?:avatars|nameplates)/nameplate-[A-Za-z0-9._-]+\z#'
        : '#\A/assets/uploads/avatars/[A-Za-z0-9._-]+\z#';
    if (!preg_match($pattern, $public) || str_contains($public, '..')) return null;
    $path = realpath(dirname(__DIR__) . $public);
    $directory = realpath(dirname(__DIR__) . dirname($public));
    if (!$path || !$directory || dirname($path) !== $directory || !is_file($path)) return null;
    if ($stored !== null && realpath($stored) !== $path) return null;
    return $path;
}

/** Reconcile pre-library current images for this owner only; never revive removed records. */
function upload_duplicate_inventory_current_image(PDO $pdo, int $userId, string $kind): void
{
    $query = $pdo->prepare('SELECT ' . $kind . '_path FROM users WHERE id=?');
    $query->execute([$userId]);
    $public = (string)$query->fetchColumn();
    $path = upload_duplicate_image_path($kind, $public);
    if (!$path) return;
    $known = $pdo->prepare("SELECT id FROM server_media_assets WHERE source_owner='avatar' AND source_role=? AND source_key=? LIMIT 1");
    $known->execute([$kind, $public]);
    if ($known->fetchColumn()) return;
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
    if ($kind === 'nameplate') server_media_register_nameplate($pdo, $userId, $public, $path, $mime);
    else server_media_register_avatar($pdo, $userId, $public, $path, $mime, true);
}

function upload_duplicate_find_image(PDO $pdo, int $userId, string $kind, string $temporary): ?array
{
    if ($userId < 1 || !in_array($kind, ['avatar', 'nameplate'], true)) throw new InvalidArgumentException('Invalid image library.');
    $hash = hash_file('sha256', $temporary);
    if (!is_string($hash)) throw new RuntimeException('The image could not be read.');
    upload_duplicate_inventory_current_image($pdo, $userId, $kind);
    $key = static fn(string $field): string => db_uses_mysql_syntax($pdo)
        ? "CONCAT('{$kind}_library.$field.',a.public_id)" : "'{$kind}_library.$field.' || a.public_id";
    $shared = "EXISTS (SELECT 1 FROM app_settings s WHERE s.setting_key=" . $key('shared') . " AND s.value='1')";
    $sql = "SELECT a.* FROM server_media_assets a WHERE a.source_owner='avatar' AND a.category='avatar' AND a.source_role=? AND a.status='active' AND (a.expires_at IS NULL OR a.expires_at>CURRENT_TIMESTAMP) AND (a.uploader_user_id=? OR $shared) AND NOT EXISTS (SELECT 1 FROM app_settings d WHERE d.setting_key=" . $key('deleted') . " AND d.value='1') ORDER BY CASE WHEN a.uploader_user_id=? THEN 0 ELSE 1 END,a.id";
    $query = $pdo->prepare($sql . (db_uses_mysql_syntax($pdo) ? ' FOR UPDATE' : ''));
    $query->execute([$kind, $userId, $userId]);
    while ($asset = $query->fetch()) {
        // Inventory hashes also protect integrity. Legacy missing hashes are repaired only from owned bytes.
        $knownHash = strtolower((string)$asset['source_sha256']);
        if ($knownHash !== '' && !hash_equals($knownHash, $hash)) continue;
        $path = upload_duplicate_image_path($kind, (string)$asset['source_key'], (string)$asset['storage_path']);
        if (!$path || filesize($path) !== filesize($temporary)) continue;
        $actual = hash_file('sha256', $path);
        if (!is_string($actual) || !hash_equals($hash, $actual)) continue;
        if ($knownHash === '') {
            $pdo->prepare('UPDATE server_media_assets SET source_sha256=? WHERE id=?')->execute([strtoupper($actual), (int)$asset['id']]);
            server_media_refresh_sidecar($pdo, (int)$asset['id']);
        }
        return upload_duplicate_result($kind, (string)$asset['public_id'],
            app_setting($pdo, $kind . '_library.name.' . $asset['public_id'], (string)$asset['original_name']),
            (int)$asset['uploader_user_id'] === $userId ? 'mine' : 'community');
    }
    return null;
}

function upload_duplicate_gesture_parts(array $prepared): array
{
    return ['text' => (string)$prepared['metadata']['text'],
        'animation' => $prepared['animation']['sha256'],
        'poster' => $prepared['poster']['sha256'] ?? null,
        'audio' => $prepared['audio']['sha256'] ?? null];
}

function upload_duplicate_find_gesture(PDO $pdo, int $userId, array $prepared, int $excludeId = 0): ?array
{
    $parts = upload_duplicate_gesture_parts($prepared);
    $fingerprint = upload_duplicate_fingerprint('gesture', $parts);
    $policy = gesture_capability_policy($pdo);
    $serverEnabled = !empty($policy['effective']['allow_server_gestures']);
    $query = $pdo->prepare("SELECT g.* FROM gestures g WHERE g.deleted_at IS NULL AND g.id<>? AND g.gesture_text=? AND (g.owner_user_id=? OR (g.is_public=1 AND ?='1' AND NOT EXISTS (SELECT 1 FROM gesture_hidden h WHERE h.user_id=? AND h.gesture_public_id=g.public_id))) ORDER BY CASE WHEN g.owner_user_id=? THEN 0 ELSE 1 END,g.id" . (db_uses_mysql_syntax($pdo) ? ' FOR UPDATE' : ''));
    $query->execute([$excludeId, $parts['text'], $userId, $serverEnabled ? 1 : 0, $userId, $userId]);
    while ($row = $query->fetch()) {
        // SQL collation can be case-insensitive; fingerprint comparison remains byte-exact.
        $generation = gesture_package_generation($pdo, (int)$row['id'], max(1, (int)$row['package_generation']));
        // Canonical generations already contain validated media hashes. Reject nonmatches
        // before opening media; legacy generations without these hashes fall through.
        if ($generation && ($generation['validation_status'] ?? '') === 'valid'
            && strlen((string)$generation['manifest_json']) <= 65536) {
            $manifest = json_decode((string)$generation['manifest_json'], true);
            $hints = ['text' => (string)$row['gesture_text']];
            $complete = true;
            foreach (['animation', 'poster', 'audio'] as $role) {
                $hint = $manifest['media'][$role]['sha256'] ?? null;
                if (!empty($generation[$role . '_storage_name']) && (!is_string($hint) || !preg_match('/\A[a-f0-9]{64}\z/', $hint))) $complete = false;
                $hints[$role] = $hint;
            }
            if ($complete && !hash_equals($fingerprint, upload_duplicate_fingerprint('gesture', $hints))) continue;
        }
        if (!$generation) $generation = ['animation_storage_name' => 'legacy:' . $row['gif_path'], 'audio_storage_name' => $row['audio_path'] ? 'legacy:' . $row['audio_path'] : null];
        $stored = ['text' => (string)$row['gesture_text']];
        foreach (['animation', 'poster', 'audio'] as $role) {
            if (empty($generation[$role . '_storage_name'])) { $stored[$role] = null; continue; }
            // Bounded owner resolver includes old pre-package uploads. Missing media never becomes a match.
            $bytes = gesture_package_asset_bytes($generation, $role);
            if ($bytes === null) continue 2;
            $stored[$role] = hash('sha256', $bytes);
            unset($bytes);
        }
        if (empty($stored['animation']) || !hash_equals($fingerprint, upload_duplicate_fingerprint('gesture', $stored))) continue;
        return upload_duplicate_result('gesture', (string)$row['public_id'], (string)($row['title'] ?: $row['name']),
            !empty($row['is_public']) ? 'server' : 'personal');
    }
    return null;
}
