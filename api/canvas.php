<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/base.php';

$user = require_user();
$pdo = db();
if (active_community_ejection($pdo, (int)$user['id'])) {
    json_out(['error' => 'community_ejected', 'redirect_url' => app_url('/community_ejected.php')], 403);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_out(['ok' => true, 'canvas' => canvas_projection(
            $pdo,
            $user,
            (string)($_GET['scope'] ?? 'community'),
            trim((string)($_GET['room_public_id'] ?? ''))
        )]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);
    csrf_protect_post();
    $body = input_json();
    $action = (string)($body['action'] ?? '');
    if ($action === 'save_draft') json_out(canvas_save_draft($pdo, $user, $body));
    if ($action === 'publish') json_out(canvas_publish($pdo, $user, $body));
    if ($action === 'add_comment') json_out(canvas_add_comment($pdo, $user, $body));
    if ($action === 'remove_comment') json_out(canvas_remove_comment($pdo, $user, $body));
    if ($action === 'set_permission') json_out(canvas_set_permission($pdo, $user, $body));
    json_out(['error' => 'Unknown Canvas action'], 400);
} catch (CanvasException $error) {
    json_out(['error' => $error->getMessage(), 'code' => $error->errorCode] + $error->projection, $error->httpStatus);
} catch (RuntimeException $error) {
    json_out(['error' => 'Canvas is currently unavailable.', 'code' => 'CANVAS_EXTENSION_UNAVAILABLE'], 503);
}

