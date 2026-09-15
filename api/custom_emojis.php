<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_exception_handler.php';
api_install_exception_handler('custom-emojis', 'CUSTOM_EMOJI_FAILED', 'Custom emojis are temporarily unavailable.');
require_once __DIR__ . '/../includes/base.php';
require_once __DIR__ . '/../includes/custom_emoji.php';

$user = require_user();
$pdo = db();
security_protect_private_response();
$method = $_SERVER['REQUEST_METHOD'];
try {
    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'list';
        if ($action === 'image') custom_emoji_serve_image($pdo, $_GET['id'] ?? null);
        if ($action !== 'list') json_out(['error' => 'Unknown custom emoji action.'], 400);
        json_out(custom_emoji_snapshot($pdo, $user));
    }
    if ($method !== 'POST') {
        header('Allow: GET, POST');
        json_out(['error' => 'Method not allowed.'], 405);
    }
    csrf_protect_post();
    if (!custom_emoji_can_manage($pdo, $user)) json_out(['error' => 'Administrator or installation owner required.'], 403);
    security_require_recent_authentication_or_json();
    if (($_POST['action'] ?? '') === 'rename') {
        $emoji = custom_emoji_rename($pdo, (int)$user['id'], $_POST['id'] ?? null, $_POST['name'] ?? null, (int)($_POST['expected_version'] ?? 0));
        json_out(['ok' => true, 'emoji' => $emoji] + custom_emoji_snapshot($pdo, $user));
    }
    if (($_POST['action'] ?? '') === 'delete') {
        $guard = null;
        if (isset($_POST['duplicate_review'])) {
            require_once __DIR__ . '/../includes/library_duplicate_review.php';
            $guard = static fn() => library_duplicate_delete_guard($pdo, $user, 'emoji', (string)($_POST['id'] ?? ''), $_POST['duplicate_review']);
        }
        custom_emoji_delete($pdo, $_POST['id'] ?? null, $guard);
        json_out(['ok' => true] + custom_emoji_snapshot($pdo, $user));
    }
    if (($_POST['action'] ?? '') !== 'upload') json_out(['error' => 'Unknown custom emoji action.'], 400);
    security_authorize_outside_content_or_json($pdo, $user, 'custom_emoji_upload', [
        'source' => 'admin-custom-emojis', 'becomes_public' => true,
    ]);
    $emoji = custom_emoji_upload($pdo, (int)$user['id'], $_POST['name'] ?? null, $_FILES['file'] ?? null);
    json_out(['emoji' => $emoji] + custom_emoji_snapshot($pdo, $user));
} catch (CustomEmojiException $error) {
    json_out(['error' => $error->getMessage()], $error->httpStatus);
}
