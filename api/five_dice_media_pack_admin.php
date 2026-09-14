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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'Unsupported method.', 'code' => 'METHOD_NOT_ALLOWED'], 405);
}

$body = input_json();
$action = trim((string)($body['action'] ?? $_POST['action'] ?? ''));

try {
    if ($action === 'begin') {
        $result = five_dice_media_pack_begin_attempt($pdo, (int)$user['id']);
    } elseif ($action === 'stage') {
        $result = five_dice_media_pack_stage_attempt($pdo, (int)$user['id'], trim((string)($_POST['attemptId'] ?? '')));
    } elseif ($action === 'activate') {
        $result = five_dice_media_pack_activate_attempt($pdo, (int)$user['id'], trim((string)($_POST['attemptId'] ?? '')));
    } elseif ($action === 'abort') {
        $result = five_dice_media_pack_abort_attempt((int)$user['id'], trim((string)($_POST['attemptId'] ?? '')));
    } elseif ($action === 'install' || $action === 'replace') {
        $result = five_dice_media_pack_install($pdo, (int)$user['id']);
    } elseif ($action === 'verify') {
        $status = five_dice_media_pack_status($pdo);
        log_tool($pdo, (int)$user['id'], 'five_dice_classic_pack_verify', null, null,
            sprintf('Verified %d/%d installation-private media slots.', (int)$status['installedCount'], (int)$status['requiredCount']));
        $result = ['status' => $status, 'operation' => 'verified'];
    } elseif ($action === 'remove') {
        if (empty($body['confirmed'])) {
            json_out(['error' => 'Confirm removal before continuing.', 'code' => 'CONFIRMATION_REQUIRED'], 409);
        }
        $result = five_dice_media_pack_remove($pdo, (int)$user['id']);
    } else {
        json_out(['error' => 'Choose an allowed Classic artwork and sound action.', 'code' => 'ACTION_INVALID'], 400);
    }
    $status = $result['status'] ?? five_dice_media_pack_status($pdo);
    $status['displayName'] = multiplayer_game_effective_display_name(
        $pdo,
        multiplayer_game_registry()[FIVE_DICE_GAME_KEY]
    );
    $status['presentation'] = five_dice_presentation_status($pdo);
    $status['surface'] = 'admin';
    $status['installationOwnerExists'] = true;
    $status['canManage'] = true;
    $status['actionPath'] = '/api/five_dice_media_pack_admin.php';
    $response = ['ok' => true, 'operation' => $result['operation'], 'fiveDiceMediaPack' => $status];
    if (isset($result['attemptId'])) $response['attemptId'] = $result['attemptId'];
    if (isset($result['progress'])) $response['progress'] = $result['progress'];
    json_out($response);
} catch (SecurityPolicyViolation $error) {
    json_out(['error' => $error->getMessage(), 'code' => 'SECURITY_POLICY_REQUIRED'], $error->httpStatus);
} catch (Throwable $error) {
    log_tool($pdo, (int)$user['id'], 'five_dice_classic_pack_rejected', null, null, 'A private pack attempt failed validation and was not activated.');
    json_out(['error' => $error->getMessage(), 'code' => 'FIVE_DICE_MEDIA_PACK_REJECTED'], 422);
}
