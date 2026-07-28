<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/base.php';

$me = require_staff(['admin', 'developer']);
$pdo = db();
$userId = (int)$me['id'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        retention_lifecycle_require_owner($pdo, $userId);
        json_out([
            'retention' => retention_lifecycle_policy_projection($pdo),
            'lifecycle' => retention_lifecycle_ownership_safeguards($pdo, $userId),
        ]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Unsupported method'], 405);
    $body = input_json();
    $action = trim((string)($body['action'] ?? ''));
    if ($action === 'preview') {
        retention_lifecycle_require_owner($pdo, $userId);
        json_out([
            'preview' => retention_lifecycle_preview(
                $pdo,
                trim((string)($body['domain'] ?? '')),
                !empty($body['keepForever']) ? null : (int)($body['days'] ?? 0),
                !empty($body['keepForever'])
            ),
        ]);
    }
    if ($action === 'request_change') {
        json_out(['request' => retention_lifecycle_request_change($pdo, $userId, $body)]);
    }
    if ($action === 'continue') {
        json_out([
            'request' => retention_lifecycle_run_batch(
                $pdo,
                $userId,
                trim((string)($body['requestId'] ?? '')),
                (int)($body['batchSize'] ?? 100)
            ),
        ]);
    }
    if ($action === 'revoke_sessions') {
        retention_lifecycle_require_owner($pdo, $userId);
        security_require_recent_authentication();
        json_out([
            'lifecycle' => retention_lifecycle_revoke_sessions(
                $pdo,
                $userId,
                (int)($body['targetUserId'] ?? 0),
                trim((string)($body['requestId'] ?? '')),
                trim((string)($body['reason'] ?? ''))
            ),
        ]);
    }
    if ($action === 'hold') {
        json_out([
            'hold' => retention_lifecycle_set_hold(
                $pdo,
                $userId,
                trim((string)($body['subjectType'] ?? '')),
                trim((string)($body['subjectKey'] ?? '')),
                trim((string)($body['reason'] ?? '')),
                !empty($body['active'])
            ),
        ]);
    }
    if ($action === 'delete_account') {
        throw new RetentionLifecycleException(
            'Delete Account is unavailable in Build 000051. Build 000053 is the sole owner.',
            'BUILD_000053_DELETE_ACCOUNT_REQUIRED',
            409
        );
    }
    json_out(['error' => 'Unknown retention action'], 400);
} catch (RetentionLifecycleException|SecurityPolicyViolation $error) {
    $code = property_exists($error, 'errorCode')
        ? $error->errorCode
        : 'RECENT_AUTHENTICATION_REQUIRED';
    $status = property_exists($error, 'httpStatus') ? $error->httpStatus : 403;
    $projection = property_exists($error, 'projection') ? $error->projection : [];
    json_out(['error' => $error->getMessage(), 'code' => $code] + $projection, $status);
}
