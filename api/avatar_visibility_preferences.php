<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/base.php';

header('Cache-Control: private, no-store, max-age=0');
$user = require_user();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_out(['preferences' => avatar_visibility_preferences($pdo, (int)$user['id'])]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Unsupported method'], 405);
$input = input_json();
$result = avatar_visibility_mutate($pdo, (int)$user['id'], $input);
if (empty($result['ok'])) {
    $status = (int)($result['http_status'] ?? 400);
    unset($result['http_status']);
    json_out($result, $status);
}
$policy = p2p_avatar_policy($pdo);
if ((string)$policy['deliveryMode'] === 'p2p-plus-built-in-generated'
    && !empty($result['revealedAvatars'])) {
    $sessionId = 0;
    $viewer = null;
    try {
        $sessionId = resolve_session_id($pdo, $input['session_id'] ?? '');
        $viewer = auth_participant($pdo, $sessionId, (string)($input['join_token'] ?? ''));
        if ((int)$viewer['user_id'] !== (int)$user['id']) $viewer = null;
    } catch (Throwable) {
        $viewer = null;
    }
    $projected = [];
    foreach ($result['revealedAvatars'] as $revealed) {
        $base = [
            'user_id' => (int)($revealed['user_id'] ?? 0),
            'display_name' => (string)($revealed['display_name'] ?? 'Member'),
            'avatar_path' => null,
            'avatar_url' => null,
            'avatar_hidden' => false,
            'avatar_hidden_scope' => null,
            'avatar_hidden_notice' => null,
            'avatar_delivery' => 'built-in-generated-fallback',
        ];
        if ($viewer) {
            $targetStmt = $pdo->prepare(
                'SELECT id,user_id,display_name,avatar_path,avatar_identity,avatar_source_width_px,avatar_source_height_px
                   FROM participants WHERE session_id=? AND user_id=? LIMIT 1'
            );
            $targetStmt->execute([$sessionId, (int)$base['user_id']]);
            $target = $targetStmt->fetch();
            if ($target) {
                $base = p2p_avatar_project_participant($pdo, $sessionId, $viewer, array_merge($base, $target));
            }
        }
        $projected[] = $base;
    }
    $result['revealedAvatars'] = $projected;
}
json_out($result);
