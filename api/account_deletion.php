<?php
declare(strict_types=1);

define('CHATSPACE_RESTRICTED_ACCOUNT_ROUTE', true);
require_once __DIR__ . '/../includes/base.php';

security_protect_private_response();
$user = require_user();
$pdo = db();
$userId = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        json_out(['ok' => true, 'deletion' => account_deletion_preview($pdo, $userId)]);
    } catch (AccountDeletionException $error) {
        json_out(['error' => $error->getMessage(), 'code' => $error->errorCode] + $error->context, $error->httpStatus);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Unsupported method'], 405);
csrf_protect_post();
$body = input_json();

try {
    security_require_recent_authentication();
    $password = (string)($body['current_password'] ?? '');
    $successor = isset($body['room_successor_user_id']) && (int)$body['room_successor_user_id'] > 0
        ? (int)$body['room_successor_user_id']
        : null;
    $result = account_deletion_execute(
        $pdo,
        $userId,
        (string)($body['confirmation'] ?? ''),
        (string)($body['request_id'] ?? ''),
        $successor,
        $password
    );
    security_destroy_session();
    json_out([
        'ok' => true,
        'deleted' => true,
        'redirect' => app_url('/login.php?account=deleted'),
        'result' => $result,
    ]);
} catch (SecurityPolicyViolation $error) {
    json_out([
        'error' => $error->getMessage(),
        'code' => 'ACCOUNT_DELETION_RECENT_AUTH_REQUIRED',
        'reauthentication_required' => true,
    ], $error->httpStatus);
} catch (AccountDeletionException $error) {
    json_out(['error' => $error->getMessage(), 'code' => $error->errorCode] + $error->context, $error->httpStatus);
} catch (Throwable) {
    json_out([
        'error' => 'The account could not be deleted safely.',
        'code' => 'ACCOUNT_DELETION_FAILED',
    ], 500);
}
