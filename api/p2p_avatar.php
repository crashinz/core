<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_exception_handler.php';
api_install_exception_handler('p2p-avatar', 'P2P_AVATAR_FAILED', 'Avatar synchronization is temporarily unavailable.');
require_once __DIR__ . '/../includes/base.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);
csrf_protect_post();
$pdo = db();
$body = input_json();
$sessionId = resolve_session_id($pdo, $body['session_id'] ?? '');
$participantId = (int)($body['participant_id'] ?? 0);
$participant = auth_participant($pdo, $sessionId, (string)($body['join_token'] ?? ''));
if ($participantId <= 0 || (int)$participant['id'] !== $participantId) json_out(['error' => 'Unauthorized'], 403);

try {
    $action = trim((string)($body['action'] ?? 'authorize_source'));
    if ($action === 'authorize_viewer') {
        $targetParticipantId = (int)($body['target_participant_id'] ?? 0);
        $claims = p2p_avatar_pair_claims($pdo, $sessionId, (int)$participant['id'], $targetParticipantId);
        if (!p2p_avatar_pair_allowed($pdo, $claims)) {
            throw new P2PAvatarPolicyException(
                'Avatar synchronization is unavailable.',
                'P2P_AVATAR_PAIR_DENIED',
                403
            );
        }
        header('Cache-Control: no-store');
        json_out([
            'ok' => true,
            'p2pAvatar' => [
                'identity' => (string)$claims['avatar_identity'],
                'width' => (int)$claims['width'],
                'height' => (int)$claims['height'],
                'authorization' => p2p_avatar_issue_token($pdo, $claims),
                'expiresInSeconds' => P2P_AVATAR_TOKEN_SECONDS,
            ],
        ]);
    }
    if ($action !== 'authorize_source') {
        throw new P2PAvatarPolicyException(
            'Avatar synchronization action is invalid.',
            'P2P_AVATAR_ACTION_INVALID',
            400
        );
    }
    $result = p2p_avatar_authorize_source(
        $pdo,
        $participant,
        $sessionId,
        trim((string)($body['authorization'] ?? ''))
    );
    header('Cache-Control: no-store');
    json_out($result);
} catch (P2PAvatarPolicyException $error) {
    json_out(['error' => $error->getMessage(), 'code' => $error->errorCode], $error->httpStatus);
}
