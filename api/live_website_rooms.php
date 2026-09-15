<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/base.php';

$user = require_user();
$pdo = db();
if (active_community_ejection($pdo, (int)$user['id'])) {
    json_out(['error' => 'community_ejected', 'redirect_url' => app_url('/community_ejected.php')], 403);
}

try {
    live_website_rooms_cleanup($pdo);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $publicId = trim((string)($_GET['room_public_id'] ?? ''));
        $room = live_website_room_row($pdo, $publicId);
        room_access_require($pdo, $room, $user);
        json_out(['ok' => true, 'liveWebsiteRoom' => live_website_room_projection($pdo, (int)$room['room_id'], (int)$user['id'])]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);
    csrf_protect_post();
    $body = input_json();
    $action = (string)($body['action'] ?? '');
    if ($action === 'create') {
        json_out(['ok' => true, 'room' => live_website_rooms_create($pdo, $user, (string)($body['url'] ?? ''), (string)($body['name'] ?? ''), $body['room_password'] ?? '')]);
    }
    $roomPublicId = trim((string)($body['room_public_id'] ?? ''));
    room_access_require($pdo, live_website_room_row($pdo, $roomPublicId), $user);
    if ($action === 'music') {
        require_once __DIR__ . '/../includes/live_website_room_music.php';
        json_out(['ok' => true] + live_website_room_music($pdo, $user, $roomPublicId, $body));
    }
    if ($action === 'refresh_preview') {
        require_once __DIR__ . '/../includes/live_website_room_previews.php';
        $updated = live_website_preview_refresh($pdo, $user, $roomPublicId);
        json_out(['ok' => true, 'updated' => $updated, 'message' => $updated ? 'Website preview updated.' : 'No usable preview image was found. The previous preview was kept.']);
    }
    if ($action === 'navigate') {
        json_out(['ok' => true, 'navigation' => live_website_rooms_navigate($pdo, $user, $roomPublicId, (string)($body['url'] ?? ''), (int)($body['expected_version'] ?? 0), (string)($body['request_id'] ?? ''))]);
    }
    if ($action === 'choose_navigation') {
        json_out(['ok' => true, 'navigation' => live_website_rooms_choose_navigation($pdo, $user, $roomPublicId, (int)($body['offer_id'] ?? 0), (string)($body['choice'] ?? ''))]);
    }
    if ($action === 'make_official') {
        json_out(['ok' => true, 'successor' => live_website_rooms_make_official($pdo, $user, $roomPublicId, (int)($body['expected_version'] ?? 0))]);
    }
    json_out(['error' => 'Unknown action'], 400);
} catch (LiveWebsiteRoomException $error) {
    json_out(['error' => $error->getMessage(), 'code' => $error->errorCode] + $error->projection, $error->httpStatus);
} catch (RoomPasswordException $error) {
    json_out(['error'=>$error->getMessage()], 400);
} catch (SecurityPolicyViolation $error) {
    json_out(['error' => $error->getMessage(), 'code' => 'LIVE_WEBSITE_SECURITY_REJECTED'], $error->getCode() >= 400 ? $error->getCode() : 400);
}
