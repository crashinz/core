<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/base.php';

$user = require_user();
$pdo = db();
security_protect_private_response();
if (!moderation_identity_is_owner($pdo, (int)$user['id'])) {
    json_out(['error' => 'Only the Installation Owner can manage Classic artwork and sound.', 'code' => 'INSTALLATION_OWNER_REQUIRED'], 403);
}
security_require_recent_authentication_or_json();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Unsupported method.', 'code' => 'METHOD_NOT_ALLOWED'], 405);

$body = input_json();
$extensionId = strtolower(trim((string)($body['game'] ?? $_POST['game'] ?? '')));
$action = strtolower(trim((string)($body['action'] ?? $_POST['action'] ?? '')));
try {
    ocx_game_extension_identity($extensionId);
    $actorUserId = (int)$user['id'];
    $result = ocx_game_media_with_lock($extensionId, static function () use ($action, $pdo, $extensionId, $actorUserId, $body): array {
        return match ($action) {
            'begin' => ocx_game_media_begin_attempt($pdo, $extensionId, $actorUserId),
            'stage' => ocx_game_media_stage_attempt($pdo, $extensionId, $actorUserId, trim((string)($_POST['attemptId'] ?? ''))),
            'activate' => ocx_game_media_activate_attempt($pdo, $extensionId, $actorUserId, trim((string)($_POST['attemptId'] ?? ''))),
            'abort' => ocx_game_media_abort_attempt($extensionId, $actorUserId, trim((string)($body['attemptId'] ?? $_POST['attemptId'] ?? ''))),
            'verify' => ['operation' => 'verified', 'status' => ocx_game_media_pack_status($pdo, $extensionId)],
            'remove' => !empty($body['confirmed'])
                ? ocx_game_media_remove($pdo, $extensionId, $actorUserId)
                : throw new MultiplayerGameException('Confirm removal before continuing.', 'CONFIRMATION_REQUIRED', 409),
            default => throw new MultiplayerGameException('Choose an allowed Classic artwork and sound action.', 'ACTION_INVALID', 400),
        };
    });
    if ($action === 'verify') {
        $verified = $result['status'];
        log_tool($pdo, $actorUserId, 'ocx_game_classic_pack_verify', null, null, $extensionId . ': verified ' . $verified['installedCount'] . '/' . $verified['requiredCount'] . ' installation-private media slots.');
    }
    $status = $result['status'] ?? ocx_game_media_pack_status($pdo, $extensionId);
    $status['acceptedFilenameSlots'] = ocx_game_media_pack_name_map($extensionId);
    $definition = multiplayer_game_registry()[ocx_game_extension_identity($extensionId)['key']];
    unset($status['acceptedOriginalNames']);
    $status += [
        'displayName' => multiplayer_game_effective_display_name($pdo, $definition),
        'presentation' => multiplayer_game_presentation_projection($pdo, $definition, $actorUserId),
        'surface' => 'admin', 'installationOwnerExists' => true, 'canManage' => true,
        'actionPath' => '/api/game_media_pack_admin.php',
    ];
    $response = ['ok' => true, 'operation' => $result['operation'], 'gameMediaPack' => $status];
    foreach (['attemptId', 'progress'] as $key) if (isset($result[$key])) $response[$key] = $result[$key];
    json_out($response);
} catch (SecurityPolicyViolation $error) {
    json_out(['error' => $error->getMessage(), 'code' => 'SECURITY_POLICY_REQUIRED'], $error->httpStatus);
} catch (MultiplayerGameException $error) {
    log_tool($pdo, (int)$user['id'], 'ocx_game_classic_pack_rejected', null, null, $extensionId . ': private pack action rejected.');
    json_out(['error' => $error->getMessage(), 'code' => $error->errorCode], $error->httpStatus);
} catch (Throwable $error) {
    log_tool($pdo, (int)$user['id'], 'ocx_game_classic_pack_rejected', null, null, $extensionId . ': private pack action rejected.');
    json_out(['error' => $error->getMessage(), 'code' => 'OCX_GAME_MEDIA_PACK_REJECTED'], 422);
}
