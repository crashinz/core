<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/base.php';
require_once __DIR__ . '/../includes/admin_user_permissions.php';

$me = require_staff(['admin']);
$pdo = db();
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_out(['ok' => true, 'permissions' => admin_user_permissions_projection($pdo, (int)$me['id'], (int)($_GET['id'] ?? 0))]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Unsupported method'], 405);
    $body = input_json();
    if (!csrf_verify($body)) csrf_failure_response();
    security_require_recent_authentication_or_json();
    if (!is_array($body['changes'] ?? null) || !is_string($body['reason'] ?? '')) json_out(['error' => 'Invalid permission changes'], 422);
    $result = admin_user_permissions_save($pdo, (int)$me['id'], (int)($body['id'] ?? 0), $body['changes'], $body['reason'] ?? '');
    json_out(['ok' => true, 'permissions' => $result]);
} catch (AdminUserPermissionException|ModerationSafetyException|ModerationIdentityPolicyException|ModerationAccountWorkflowException $error) {
    json_out(['error' => $error->getMessage(), 'code' => $error->errorCode], $error->httpStatus);
} catch (PDOException $error) {
    $conflict = in_array((string)$error->getCode(), ['23000', '23505'], true);
    json_out(['error' => $conflict ? 'Permissions changed elsewhere. Reopen the permission controls.' : 'Permissions could not be saved safely.'], $conflict ? 409 : 500);
}
