<?php
declare(strict_types=1);

require_once __DIR__ . '/room_importer.php';
require_once __DIR__ . '/live_website_room_music.php';

function live_website_preview_capture(PDO $pdo, int $roomId, array $target): bool
{
    try {
        $html = (string)($target['previewHtml'] ?? '');
        if ($html === '') return false;
        $parsed = room_import_parse($html, (string)$target['url']);
        live_website_room_store_music($pdo, $roomId, (string)$target['originalUrl'], live_website_room_youtube_tracks($parsed));
        $candidate = '';
        foreach (($parsed['sections'] ?? []) as $section) {
            if (($section['type'] ?? '') !== 'image' || empty($section['src'])) continue;
            $role = (string)($section['role'] ?? '');
            if (str_starts_with($role, 'avatar') || $role === 'background-piece') continue;
            if ($candidate === '' || in_array($role, ['main', 'header'], true)) $candidate = (string)$section['src'];
            if (in_array($role, ['main', 'header'], true)) break;
        }
        if ($candidate === '') $candidate = (string)($parsed['background_image'] ?? '');
        if ($candidate === '') return false;
        $path = room_import_download_asset($candidate, 'image', (string)$target['url']);
        if (!$path) return false;
        $before = $pdo->prepare('SELECT background_thumb_path FROM rooms WHERE id=?');
        $before->execute([$roomId]);
        $oldPath = (string)($before->fetchColumn() ?: '');
        $update = $pdo->prepare('UPDATE rooms SET background_thumb_path=? WHERE id=? AND EXISTS (SELECT 1 FROM live_website_rooms l WHERE l.room_id=rooms.id AND l.target_url=?)');
        $update->execute([$path, $roomId, (string)$target['originalUrl']]);
        if ($update->rowCount() === 0) {
            live_website_preview_remove_unused($pdo, $path);
            return false;
        }
        if ($oldPath !== '') live_website_preview_remove_unused($pdo, $oldPath);
        return true;
    } catch (Throwable $error) {
        // Preview failures must not prevent a successfully created live room
        // from opening. Do not log private page HTML or URL query strings.
        error_log('Live Website preview unavailable: ' . get_class($error));
        return false;
    }
}

function live_website_preview_remove_unused(PDO $pdo, string $path): void
{
    if (!preg_match('~/assets/uploads/imported-rooms/([a-f0-9]{24}\.(?:jpg|png|webp|gif))$~D', $path, $match)) return;
    $used = $pdo->prepare('SELECT COUNT(*) FROM rooms WHERE background_thumb_path=? OR background_path=?');
    $used->execute([$path, $path]);
    if ((int)$used->fetchColumn() !== 0) return;
    $file = __DIR__ . '/../assets/uploads/imported-rooms/' . $match[1];
    if (is_file($file)) @unlink($file);
}

function live_website_preview_refresh(PDO $pdo, array $user, string $publicId): bool
{
    $room = live_website_room_row($pdo, $publicId);
    if ((int)$room['owner_id'] !== (int)$user['id'] && !live_website_rooms_is_admin($user)) {
        throw new LiveWebsiteRoomException('Only the room owner or an administrator may refresh its preview.', 'LIVE_WEBSITE_OWNER_REQUIRED', 403);
    }
    security_authorize_outside_content_or_json($pdo, $user, 'live_website_room_create', ['source' => 'live_website_preview']);
    $owns = db_begin_write_transaction($pdo);
    try {
        live_website_room_row($pdo, $publicId, true);
        $recent = $pdo->prepare('SELECT COUNT(*) FROM live_website_room_accounting WHERE room_id=? AND action=? AND created_at>=?');
        $recent->execute([(int)$room['room_id'], 'preview', gmdate('Y-m-d H:i:s', time() - 60)]);
        if ((int)$recent->fetchColumn() > 0) {
            throw new LiveWebsiteRoomException('Wait one minute before refreshing this preview again.', 'LIVE_WEBSITE_PREVIEW_COOLDOWN', 429);
        }
        $pdo->prepare('INSERT INTO live_website_room_accounting (user_id,action,room_id) VALUES (?,?,?)')->execute([(int)$user['id'], 'preview', (int)$room['room_id']]);
        db_commit_write_transaction($pdo, $owns);
    } catch (Throwable $error) {
        db_rollback_write_transaction($pdo, $owns);
        throw $error;
    }
    // All remote requests occur after releasing the database write lock.
    $target = live_website_rooms_validate_target((string)$room['target_url']);
    $target['originalUrl'] = (string)$room['target_url'];
    return live_website_preview_capture($pdo, (int)$room['room_id'], $target);
}
