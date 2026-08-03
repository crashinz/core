<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_exception_handler.php';
api_install_exception_handler('private-voice', 'PRIVATE_VOICE_FAILED', 'Private voice is temporarily unavailable.');
require_once __DIR__ . '/../includes/base.php';

$pdo = db();

function private_voice_api_auth(PDO $pdo, array $input): array
{
    $sessionId = resolve_session_id($pdo, $input['session_id'] ?? '');
    $participant = auth_participant($pdo, $sessionId, (string)($input['join_token'] ?? ''));
    $providedParticipantId = (int)($input['participant_id'] ?? 0);
    if ($providedParticipantId > 0 && $providedParticipantId !== (int)$participant['id']) {
        json_out(['error' => 'Unauthorized'], 403);
    }
    return [$sessionId, $participant];
}

function private_voice_api_error(PrivateVoiceException $error): never
{
    json_out([
        'error' => $error->getMessage(),
        'code' => $error->errorCode,
        'context' => $error->context,
    ], $error->httpStatus);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        [$sessionId, $participant] = private_voice_api_auth($pdo, $_GET);
        json_out(private_voice_snapshot($pdo, $sessionId, (int)$participant['user_id']));
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);
    csrf_protect_post();
    $body = input_json();
    [$sessionId, $participant] = private_voice_api_auth($pdo, $body);
    $userId = (int)$participant['user_id'];
    $action = (string)($body['action'] ?? '');
    $result = match ($action) {
        'create_chat' => private_voice_create_chat(
            $pdo,
            $sessionId,
            $userId,
            private_voice_request_id($body['request_id'] ?? '')
        ),
        'invite' => private_voice_create_invitation(
            $pdo,
            $sessionId,
            $userId,
            (int)($body['recipient_user_id'] ?? 0),
            (string)($body['chat_id'] ?? ''),
            private_voice_request_id($body['request_id'] ?? '')
        ),
        'accept_invitation' => private_voice_respond_invitation(
            $pdo,
            $sessionId,
            $userId,
            (string)($body['invitation_id'] ?? ''),
            'accepted'
        ),
        'reject_invitation' => private_voice_respond_invitation(
            $pdo,
            $sessionId,
            $userId,
            (string)($body['invitation_id'] ?? ''),
            'rejected'
        ),
        'revoke_invitation' => private_voice_revoke_invitation(
            $pdo,
            $sessionId,
            $userId,
            (string)($body['invitation_id'] ?? '')
        ),
        'request_join' => private_voice_create_join_request(
            $pdo,
            $sessionId,
            $userId,
            (string)($body['chat_id'] ?? ''),
            private_voice_request_id($body['request_id'] ?? '')
        ),
        'approve_request', 'reject_request', 'dismiss_request' => private_voice_decide_join_request(
            $pdo,
            $sessionId,
            $userId,
            (string)($body['join_request_id'] ?? ''),
            match ($action) {
                'approve_request' => 'approved',
                'dismiss_request' => 'dismissed',
                default => 'rejected',
            }
        ),
        'leave' => private_voice_leave($pdo, $sessionId, $userId),
        default => throw new PrivateVoiceException('Private voice action is invalid.', 'PRIVATE_VOICE_ACTION_INVALID', 400),
    };
    json_out([
        'ok' => true,
        'result' => $result,
        'snapshot' => private_voice_snapshot($pdo, $sessionId, $userId),
    ]);
} catch (PrivateVoiceException $error) {
    private_voice_api_error($error);
}
