<?php
require_once __DIR__ . '/../includes/room_importer.php';

$user = require_user();
$pdo = db();
$communityEjection = active_community_ejection($pdo, (int)$user['id']);
if ($communityEjection) {
    json_out(['error' => 'community_ejected', 'redirect_url' => app_url('/community_ejected.php')], 403);
}

function import_lobby_room_payload(array $room, array $user): array {
    $backgroundPath = (string)($room['background_path'] ?? '');
    $backgroundMime = (string)($room['background_mime'] ?? '');
    $thumbPath = (string)($room['background_thumb_path'] ?? '');
    $tileBg = room_import_tile_image_from_layout($room['import_layout_json'] ?? null);
    if ($tileBg === '') {
        $tileBg = str_starts_with($backgroundMime, 'video/') ? $thumbPath : $backgroundPath;
    }
    return [
        'id' => (int)$room['id'],
        'public_id' => (string)$room['public_id'],
        'name' => (string)$room['name'],
        'owner_id' => (int)$room['owner_id'],
        'owner_name' => (string)$room['owner_name'],
        'online_count' => (int)($room['online_count'] ?? 0),
        'background_path' => $backgroundPath,
        'background_mime' => $backgroundMime,
        'background_thumb_path' => $thumbPath,
        'tile_background' => $tileBg,
        'tile_background_url' => $tileBg !== '' ? media_url($tileBg) : '',
        'background_url' => $backgroundPath !== '' ? media_url($backgroundPath) : '',
        'thumb_url' => $thumbPath !== '' ? media_url($thumbPath) : '',
        'video_without_thumb' => $tileBg === '' && $backgroundPath !== '' && str_starts_with($backgroundMime, 'video/'),
        'is_private' => room_access_is_private($room),
        'can_delete' => room_access_can_delete($user, $room),
        'can_edit' => (int)$room['owner_id'] === (int)$user['id'] || in_array($user['role'] ?? 'user', ['admin', 'developer'], true),
        'enter_url' => app_url('/chatroom.php?id=' . rawurlencode((string)$room['public_id'])),
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST required'], 405);
}

$body = input_json();
$action = (string)($body['action'] ?? '');
$url = trim((string)($body['url'] ?? ''));

try {
    if ($action === 'refresh_text') {
        csrf_protect_post();
        $statement = $pdo->prepare('SELECT id,owner_id,import_url,import_layout_json FROM rooms WHERE public_id=? LIMIT 1');
        $statement->execute([trim((string)($body['room_public_id'] ?? ''))]);
        $room = $statement->fetch();
        if (!is_array($room)) json_out(['error' => 'Room not found.'], 404);
        if ((int)$room['owner_id'] !== (int)$user['id'] && !in_array((string)($user['role'] ?? ''), ['admin', 'developer'], true)) {
            json_out(['error' => 'Only the room owner or an administrator can refresh imported text.'], 403);
        }
        if (empty($room['import_url'])) json_out(['error' => 'This is not an imported room.'], 400);
        security_authorize_outside_content_or_json($pdo, $user, 'room_import_preview', ['source' => 'room_import_text_refresh']);
        $original = (string)$room['import_layout_json'];
        $layout = json_decode($original, true);
        if (!is_array($layout)) json_out(['error' => 'The saved import layout is unavailable.'], 409);
        // Re-read only the saved source. Keep image files, room identity,
        // player configuration and unrelated visible text unchanged.
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        $preview = room_import_preview_from_url((string)$room['import_url']);
        $normalize = static fn(string $text): string => trim(preg_replace('~[\s\x{00A0}]+~u', ' ', $text) ?? $text);
        $hidden = array_fill_keys(array_map($normalize, (array)($preview['hidden_text'] ?? [])), true);
        foreach ((array)($preview['sections'] ?? []) as $section) {
            if (($section['type'] ?? '') === 'text') unset($hidden[$normalize((string)($section['text'] ?? ''))]);
        }
        $removed = 0;
        $layout['sections'] = array_values(array_filter((array)($layout['sections'] ?? []), static function(array $section) use ($hidden, $normalize, &$removed): bool {
            if (($section['type'] ?? '') === 'text' && isset($hidden[$normalize((string)($section['text'] ?? ''))])) {
                $removed++;
                return false;
            }
            return true;
        }));
        $layout['accessible_text'] = (array)($preview['accessible_text'] ?? []);
        $sourceTextStyles = [];
        foreach ((array)($preview['sections'] ?? []) as $section) {
            if (($section['type'] ?? '') === 'text') {
                $sourceTextStyles[$normalize((string)($section['text'] ?? ''))][] = (array)($section['style'] ?? []);
            }
        }
        foreach ($layout['sections'] as &$section) {
            if (($section['type'] ?? '') !== 'text') continue;
            $key = $normalize((string)($section['text'] ?? ''));
            if (!empty($sourceTextStyles[$key])) $section['style'] = array_shift($sourceTextStyles[$key]);
        }
        unset($section);
        foreach (['text_size', 'text_color', 'audio_player_bg', 'audio_player_text_buttons', 'player_style', 'hide_audio_iframe', 'poem_image_width', 'poem_image_max_width', 'mobile_poem_image_width'] as $key) {
            if (array_key_exists($key, $preview)) $layout[$key] = $preview[$key];
        }
        $sourceImages = array_values(array_filter((array)($preview['sections'] ?? []), static fn(array $item): bool => ($item['type'] ?? '') === 'image'));
        $savedRoleCounts = [];
        foreach ($layout['sections'] as $item) {
            if (($item['type'] ?? '') === 'image') {
                $role = (string)($item['role'] ?? '');
                $savedRoleCounts[$role] = ($savedRoleCounts[$role] ?? 0) + 1;
            }
        }
        foreach ($layout['sections'] as &$section) {
            if (($section['type'] ?? '') !== 'image') continue;
            $source = (string)($section['source_src'] ?? '');
            $role = (string)($section['role'] ?? '');
            $matches = array_values(array_filter($sourceImages, static fn(array $item): bool => $source !== ''
                ? (string)($item['src'] ?? '') === $source
                : ($role !== '' && ($savedRoleCounts[$role] ?? 0) === 1 && (string)($item['role'] ?? '') === $role)));
            // Older saved imports have only local paths. Match a unique role,
            // never guess across ambiguous images or replace their local files.
            if (count($matches) === 1) {
                $section['image_style'] = (array)($matches[0]['image_style'] ?? []);
                $section['source_src'] = (string)($matches[0]['src'] ?? '');
            }
        }
        unset($section);
        $layout['text_visibility_version'] = 1;
        $json = json_encode($layout, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($json !== $original) {
            $update = $pdo->prepare('UPDATE rooms SET import_layout_json=? WHERE id=? AND import_url=? AND import_layout_json=?');
            $update->execute([$json, (int)$room['id'], (string)$room['import_url'], $original]);
            if ($update->rowCount() !== 1) json_out(['error' => 'The room changed during refresh. Please try again.'], 409);
        }
        json_out(['ok' => true, 'layout' => $layout, 'removedHiddenTextSections' => $removed]);
    }

    if ($action === 'preview') {
        security_authorize_outside_content_or_json($pdo, $user, 'room_import_preview', ['source' => 'room_import']);
        $preview = room_import_preview_from_url($url);
        json_out(['ok' => true, 'preview' => $preview]);
    }

    if ($action === 'create') {
        security_authorize_outside_content_or_json($pdo, $user, 'room_import_create', ['source' => 'room_import']);
        live_website_rooms_require_import_capacity($pdo, $user);
        $passwordHash = room_access_hash($body['room_password'] ?? '');
        $preview = room_import_preview_from_url($url);
        $localized = room_import_localize($preview);
        $sourceName = trim((string)($body['name'] ?? ''));
        if ($sourceName === '') $sourceName = trim((string)($preview['title'] ?? ''));
        if ($sourceName === '') {
            $host = parse_url((string)$preview['source_url'], PHP_URL_HOST);
            $sourceName = $host ? preg_replace('/^www\./', '', $host) : 'Imported Room';
        }
        if (function_exists('mb_substr')) $sourceName = mb_substr($sourceName, 0, 90, 'UTF-8');
        else $sourceName = substr($sourceName, 0, 90);

        $publicId = uuid_v4();
        $backgroundPath = $localized['background_path'] ?: null;
        $backgroundMime = null;
        if ($backgroundPath) {
            $full = __DIR__ . '/..' . $backgroundPath;
            if (is_file($full)) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $backgroundMime = $finfo->file($full) ?: null;
            }
        }
        $stmt = $pdo->prepare(
            'INSERT INTO rooms (public_id, owner_id, name, background_path, background_mime, background_thumb_path, import_url, import_layout_json, music_playlist_json, room_password_hash)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $publicId,
            (int)$user['id'],
            $sourceName,
            $backgroundPath,
            $backgroundMime,
            null,
            $preview['source_url'] ?? $url,
            json_encode($localized['layout'], JSON_UNESCAPED_SLASHES),
            json_encode($localized['music'], JSON_UNESCAPED_SLASHES),
            $passwordHash,
        ]);
        $roomId = (int)$pdo->lastInsertId();
        active_session_for_room($pdo, $roomId);
        $roomStmt = $pdo->prepare('SELECT r.*, u.display_name AS owner_name, 0 AS online_count FROM rooms r JOIN users u ON u.id = r.owner_id WHERE r.id = ? LIMIT 1');
        $roomStmt->execute([$roomId]);
        json_out(['ok' => true, 'room' => import_lobby_room_payload($roomStmt->fetch(), $user)]);
    }
} catch (RuntimeException $e) {
    json_out(['error' => $e->getMessage()], 400);
}

json_out(['error' => 'Unknown action'], 400);
