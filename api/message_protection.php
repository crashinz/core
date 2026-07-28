<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/base.php';

$me = require_user();
$pdo = db();
$userId = (int)$me['id'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $kind = trim((string)($_GET['conversation_kind'] ?? ''));
        $key = trim((string)($_GET['conversation_key'] ?? ''));
        $deviceId = trim((string)($_GET['device_id'] ?? ''));
        $payload = ['account' => message_protection_device_projection($pdo, $userId)];
        if ($kind !== '' && $key !== '') {
            $payload['conversation'] = message_protection_transition_projection($pdo, $userId, $kind, $key);
            $payload['conversationDevices'] = message_protection_conversation_devices(
                $pdo,
                $userId,
                $kind,
                $key
            );
            if ($deviceId !== '') {
                $payload['keyEnvelopes'] = message_protection_key_envelopes(
                    $pdo,
                    $userId,
                    $deviceId,
                    $kind,
                    $key
                );
            }
        }
        json_out($payload);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Unsupported method'], 405);
    $body = input_json();
    $action = trim((string)($body['action'] ?? ''));
    if ($action === 'register_device') {
        json_out(['ok' => true, 'device' => message_protection_register_device($pdo, $userId, $body)]);
    }
    if ($action === 'approve_device') {
        json_out(['ok' => true, 'device' => message_protection_approve_device($pdo, $userId, $body)]);
    }
    if ($action === 'store_recovery') {
        json_out(['ok' => true, 'recovery' => message_protection_store_recovery($pdo, $userId, $body)]);
    }
    if ($action === 'store_key_envelope') {
        json_out(['ok' => true, 'keyEnvelope' => message_protection_store_key_envelope($pdo, $userId, $body)]);
    }
    if ($action === 'request_transition') {
        json_out(['ok' => true, 'transition' => message_protection_request_transition($pdo, $userId, $body)]);
    }
    if ($action === 'continue_transition') {
        json_out([
            'ok' => true,
            'conversation' => message_protection_run_transition_batch(
                $pdo,
                $userId,
                trim((string)($body['requestId'] ?? '')),
                (int)($body['batchSize'] ?? 100)
            ),
        ]);
    }
    json_out(['error' => 'Unknown action'], 400);
} catch (MessageProtectionException|SecurityPolicyViolation $error) {
    $code = property_exists($error, 'errorCode')
        ? $error->errorCode
        : 'RECENT_AUTHENTICATION_REQUIRED';
    $status = property_exists($error, 'httpStatus') ? $error->httpStatus : 403;
    $projection = property_exists($error, 'projection') ? $error->projection : [];
    json_out(['error' => $error->getMessage(), 'code' => $code] + $projection, $status);
}
