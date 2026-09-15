<?php
declare(strict_types=1);
require_once __DIR__ . '/upload_duplicates.php';
require_once __DIR__ . '/custom_emoji.php';

/** Admin catalog maintenance, never a way to enumerate other members' private libraries. */
function library_duplicate_require_admin(array $actor): void
{
    if (($actor['role'] ?? '') !== 'admin' || (int)($actor['id'] ?? 0) < 1) {
        throw new CustomEmojiException('Administrator required.', 403);
    }
}

function library_duplicate_kind(string $kind): void
{
    if (!in_array($kind, ['avatar', 'nameplate', 'gesture', 'emoji'], true)) throw new CustomEmojiException('Unknown library.');
}

/** Hash actual bytes in constant memory, bounded to avoid monopolizing a web request. */
function library_duplicate_hash(?string $path): ?string
{
    if (!$path || !is_file($path) || !is_readable($path)) return null;
    $size = filesize($path);
    if ($size === false || $size > 64 * 1024 * 1024) return null;
    $hash = @hash_file('sha256', $path);
    return is_string($hash) ? $hash : null;
}

function library_duplicate_rows(PDO $pdo, array $actor, string $kind, string $after = '', ?string $onlyId = null, bool $locked = false): array
{
    library_duplicate_require_admin($actor);
    library_duplicate_kind($kind);
    $uid = (int)$actor['id'];
    if ($kind === 'emoji') {
        $ids = custom_emoji_index($pdo);
        sort($ids, SORT_STRING);
        $ids = array_values(array_filter($ids, static fn(string $id): bool => $onlyId !== null ? $id === $onlyId : strcmp($id, $after) > 0));
        $rows = [];
        foreach (array_slice($ids, 0, 10) as $id) {
            $row = custom_emoji_record($pdo, $id);
            if ($row) $rows[] = $row;
        }
        return $rows;
    }
    $args = [];
    if ($kind === 'gesture') {
        $sql = "SELECT g.*,u.display_name AS owner_label FROM gestures g LEFT JOIN users u ON u.id=g.owner_user_id WHERE g.deleted_at IS NULL AND (g.is_public=1 OR g.owner_user_id=?)";
        $args[] = $uid;
        $alias = 'g';
    } else {
        $key = static fn(string $field): string => db_uses_mysql_syntax($pdo)
            ? "CONCAT('{$kind}_library.$field.',a.public_id)" : "'{$kind}_library.$field.' || a.public_id";
        $shared = "EXISTS (SELECT 1 FROM app_settings s WHERE s.setting_key=" . $key('shared') . " AND s.value='1')";
        $sql = "SELECT a.*,u.display_name AS owner_label FROM server_media_assets a LEFT JOIN users u ON u.id=a.uploader_user_id WHERE a.source_owner='avatar' AND a.source_role=? AND a.category='avatar' AND a.status='active' AND (a.expires_at IS NULL OR a.expires_at>CURRENT_TIMESTAMP) AND (a.uploader_user_id=? OR $shared) AND NOT EXISTS (SELECT 1 FROM app_settings d WHERE d.setting_key=" . $key('deleted') . " AND d.value='1')";
        $args = [$kind, $uid];
        $alias = 'a';
    }
    if ($onlyId !== null) { $sql .= " AND $alias.public_id=?"; $args[] = $onlyId; }
    else { $sql .= " AND $alias.id>?"; $args[] = max(0, (int)$after); }
    $sql .= " ORDER BY $alias.id LIMIT 10" . ($locked && db_uses_mysql_syntax($pdo) ? " FOR UPDATE" : "");
    $q = $pdo->prepare($sql); $q->execute($args);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function library_duplicate_item(PDO $pdo, array $actor, string $kind, array $row): ?array
{
    $uid = (int)$actor['id'];
    $id = (string)($row['public_id'] ?? $row['id']);
    if ($kind === 'emoji') {
        $root = custom_emoji_storage_directory();
        $path = $root ? realpath($root . DIRECTORY_SEPARATOR . $row['file']) : false;
        $hash = $path && dirname($path) === $root ? library_duplicate_hash($path) : null;
        if (!$hash) return null;
        $ownerQuery = $pdo->prepare('SELECT display_name FROM users WHERE id=?');
        $ownerQuery->execute([(int)$row['createdBy']]);
        $item = ['id'=>$id, 'name'=>$row['name'], 'owner'=>(string)($ownerQuery->fetchColumn() ?: 'Former member'), 'scope'=>'Shared',
            'preview'=>custom_emoji_public($row)['url'], 'version'=>max(1, (int)($row['version'] ?? 1)), 'action'=>'delete', 'text'=>''];
    } elseif ($kind === 'gesture') {
        $generation = gesture_package_generation($pdo, (int)$row['id'], max(1, (int)$row['package_generation']));
        if (!$generation) $generation = ['animation_storage_name'=>'legacy:'.$row['gif_path'], 'audio_storage_name'=>$row['audio_path'] ? 'legacy:'.$row['audio_path'] : null];
        $parts = ['text'=>(string)$row['gesture_text']];
        foreach (['animation','poster','audio'] as $role) {
            if (empty($generation[$role.'_storage_name'])) { $parts[$role] = null; continue; }
            $bytes = gesture_package_asset_bytes($generation, $role);
            if ($bytes === null) return null;
            $parts[$role] = hash('sha256', $bytes); unset($bytes);
        }
        if (empty($parts['animation'])) return null;
        $hash = upload_duplicate_fingerprint('gesture', $parts);
        $item = ['id'=>$id, 'name'=>(string)($row['title'] ?: $row['name']), 'owner'=>(string)($row['owner_label'] ?: 'Former member'),
            'scope'=>!empty($row['is_public']) ? 'Server' : 'Mine', 'preview'=>gesture_package_media_url($row, 'animation', 'admin'),
            'audio'=>gesture_package_media_url($row, 'audio', 'admin'), 'text'=>(string)$row['gesture_text'],
            'version'=>(int)$row['version'], 'action'=>!empty($row['is_public']) ? 'admin-delete' : 'delete'];
    } else {
        $path = upload_duplicate_image_path($kind, (string)$row['source_key'], (string)$row['storage_path']);
        $hash = library_duplicate_hash($path);
        if (!$hash) return null;
        $mine = (int)$row['uploader_user_id'] === $uid;
        $item = ['id'=>$id, 'name'=>app_setting($pdo, $kind.'_library.name.'.$id, (string)$row['original_name']),
            'owner'=>(string)($row['owner_label'] ?: 'Former member'), 'scope'=>$mine ? 'Mine' : 'Community',
            'preview'=>app_url('/api/avatar_library.php?action=image&kind='.$kind.'&id='.rawurlencode($id)),
            'version'=>(string)$row['updated_at'], 'action'=>$mine ? 'delete' : 'remove_community', 'text'=>''];
    }
    $item['kind'] = $kind;
    $item['fingerprint'] = $hash;
    // This is a freshness value, not an authorization credential; every request rechecks access.
    $item['snapshot'] = hash('sha256', json_encode($item, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    return $item;
}

function library_duplicate_scan(PDO $pdo, array $actor, string $kind, string $after = ''): array
{
    $rows = library_duplicate_rows($pdo, $actor, $kind, $after);
    $items = []; $skipped = 0; $scanned = 0; $start = microtime(true);
    foreach ($rows as $row) {
        $after = (string)$row['id']; $scanned++;
        $item = library_duplicate_item($pdo, $actor, $kind, $row);
        if ($item === null) $skipped++; else $items[] = $item;
        if (microtime(true) - $start > 2) break;
    }
    return ['ok'=>true, 'items'=>$items, 'after'=>$after, 'done'=>count($rows)<10 && $scanned===count($rows), 'scanned'=>$scanned, 'skipped'=>$skipped];
}

/** Recheck both reviewed entries, scope, metadata and actual content before the existing delete owner runs. */
function library_duplicate_verify(PDO $pdo, array $actor, string $kind, array $target, array $keep, bool $locked = false): array
{
    library_duplicate_require_admin($actor); library_duplicate_kind($kind);
    if (empty($target['id']) || empty($keep['id']) || $target['id'] === $keep['id']) throw new CustomEmojiException('Keep a different copy.', 409);
    $fresh = [];
    foreach ([$target, $keep] as $old) {
        $rows = library_duplicate_rows($pdo, $actor, $kind, '', (string)$old['id'], $locked);
        $item = $rows ? library_duplicate_item($pdo, $actor, $kind, $rows[0]) : null;
        if (!$item || !is_string($old['snapshot'] ?? null) || !hash_equals($item['snapshot'], $old['snapshot'])) {
            throw new CustomEmojiException('An entry changed or is no longer available. Scan again before deleting.', 409);
        }
        $fresh[] = $item;
    }
    if (!hash_equals($fresh[0]['fingerprint'], $fresh[1]['fingerprint'])) throw new CustomEmojiException('These entries no longer match. Scan again.', 409);
    return ['ok'=>true, 'target'=>$fresh[0]];
}


/** Existing deletion owners call this inside their transaction, before taking asset locks. */
function library_duplicate_delete_guard(PDO $pdo, array $actor, string $kind, string $id, mixed $review): void
{
    library_duplicate_require_admin($actor);
    if (is_string($review) && strlen($review) <= 4096) $review = json_decode($review, true);
    if (!is_array($review) || !is_array($review['target'] ?? null) || !is_array($review['keep'] ?? null) || ($review['target']['id'] ?? null) !== $id) {
        throw new CustomEmojiException('Invalid duplicate review. Scan again.', 409);
    }
    upload_duplicate_lock($pdo, $kind);
    library_duplicate_verify($pdo, $actor, $kind, $review['target'], $review['keep'], true);
}
