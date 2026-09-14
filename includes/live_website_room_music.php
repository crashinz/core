<?php
declare(strict_types=1);

require_once __DIR__ . '/room_importer.php';

function live_website_room_youtube_tracks(array $parsed): array
{
    $tracks = [];
    foreach ((array)($parsed['music'] ?? []) as $track) {
        $url = (string)($track['url'] ?? '');
        if (!room_import_is_youtube_url($url)) continue;
        $embed = room_import_youtube_embed($url);
        if ($embed === '') continue;
        $tracks[$url] = ['label' => (string)($track['label'] ?? 'YouTube Music'), 'url' => $url,
            'local' => false, 'type' => 'youtube', 'provider' => 'YouTube', 'embed_url' => $embed];
        if (count($tracks) >= 16) break;
    }
    return array_values($tracks);
}

function live_website_room_store_music(PDO $pdo, int $roomId, string $targetUrl, array $tracks): void
{
    $pdo->prepare('UPDATE rooms SET music_playlist_json=? WHERE id=? AND EXISTS (SELECT 1 FROM live_website_rooms l WHERE l.room_id=rooms.id AND l.target_url=?)')
        ->execute([json_encode($tracks, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $roomId, $targetUrl]);
}

function live_website_room_music(PDO $pdo, array $user, string $publicId, array $body): array
{
    $room = live_website_room_row($pdo, $publicId);
    $sessionId = resolve_session_id($pdo, (string)($body['session_id'] ?? ''));
    $participant = auth_participant($pdo, $sessionId, (string)($body['join_token'] ?? ''));
    $sessionRoom = $pdo->prepare('SELECT room_id FROM room_sessions WHERE id=?');
    $sessionRoom->execute([$sessionId]);
    if ((int)$participant['user_id'] !== (int)$user['id'] || (int)$sessionRoom->fetchColumn() !== (int)$room['room_id']) {
        throw new LiveWebsiteRoomException('Join this room before loading its music controls.', 'LIVE_WEBSITE_MEMBERSHIP_REQUIRED', 403);
    }
    $owns = db_begin_write_transaction($pdo);
    try {
        live_website_room_row($pdo, $publicId, true);
        $cached = $pdo->prepare('SELECT music_playlist_json FROM rooms WHERE id=?');
        $cached->execute([(int)$room['room_id']]);
        $json = $cached->fetchColumn();
        if (is_string($json) && $json !== '') {
            db_commit_write_transaction($pdo, $owns);
            return ['playlist' => json_decode($json, true) ?: [], 'pending' => false];
        }
        $recent = $pdo->prepare('SELECT COUNT(*) FROM live_website_room_accounting WHERE room_id=? AND action=? AND created_at>=?');
        $recent->execute([(int)$room['room_id'], 'music-preview', gmdate('Y-m-d H:i:s', time() - 120)]);
        if ((int)$recent->fetchColumn() > 0) {
            db_commit_write_transaction($pdo, $owns);
            return ['playlist' => [], 'pending' => true];
        }
        $pdo->prepare('INSERT INTO live_website_room_accounting (user_id,action,room_id) VALUES (?,?,?)')
            ->execute([(int)$user['id'], 'music-preview', (int)$room['room_id']]);
        db_commit_write_transaction($pdo, $owns);
    } catch (Throwable $error) {
        db_rollback_write_transaction($pdo, $owns);
        throw $error;
    }
    // Never hold a database or PHP session lock during the bounded page fetch.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $target = live_website_rooms_validate_target((string)$room['target_url']);
    $tracks = live_website_room_youtube_tracks(room_import_parse((string)$target['previewHtml'], (string)$target['url']));
    live_website_room_store_music($pdo, (int)$room['room_id'], (string)$room['target_url'], $tracks);
    return ['playlist' => $tracks, 'pending' => false];
}
