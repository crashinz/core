<?php
declare(strict_types=1);

define('CHATSPACE_RESTRICTED_ACCOUNT_ROUTE', true);
require_once __DIR__ . '/../includes/base.php';

$user = require_user();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $view = (string)($_GET['view'] ?? 'mine');
    if ($view === 'mine') {
        json_out([
            'reports' => moderation_safety_reporter_projection($pdo, (int)$user['id']),
            'mutes' => moderation_safety_mute_projection($pdo, (int)$user['id']),
        ]);
    }
    if ($view === 'users') {
        moderation_safety_require_staff_capability($pdo, (int)$user['id'], 'view-moderation-history');
        json_out(moderation_safety_admin_users(
            $pdo,
            (string)($_GET['search'] ?? ''),
            (string)($_GET['sort'] ?? 'name'),
            (int)($_GET['page'] ?? 1),
            (int)($_GET['per_page'] ?? 25)
        ));
    }
    json_out(['error' => 'Unknown moderation view.'], 400);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Unsupported method'], 405);
$body = input_json();
$action = (string)($body['action'] ?? '');

try {
    if (db_uses_mysql_syntax($pdo)) $pdo->beginTransaction();
    else $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
    $result = match ($action) {
        'report' => moderation_safety_submit_report($pdo, (int)$user['id'], $body),
        'mute' => moderation_safety_set_mute(
            $pdo,
            (int)$user['id'],
            (int)($body['target_user_id'] ?? 0),
            (string)($body['duration'] ?? 'until-unmute'),
            (array)($body['scopes'] ?? [])
        ),
        'unmute' => (function () use ($pdo, $user, $body): array {
            moderation_safety_unmute($pdo, (int)$user['id'], (int)($body['target_user_id'] ?? 0));
            return ['muted' => false];
        })(),
        'moderate' => moderation_safety_apply_action($pdo, (int)$user['id'], $body),
        'evidence' => [
            'evidence' => moderation_safety_evidence_access(
                $pdo,
                (int)$user['id'],
                (string)($body['evidence_public_id'] ?? ''),
                (string)($body['operation'] ?? ''),
                (string)($body['reason'] ?? '')
            ),
        ],
        default => throw new ModerationSafetyException('Unknown moderation action.', 'MODERATION_ACTION_UNKNOWN', 400),
    };
    $pdo->commit();
    json_out(['ok' => true] + $result);
} catch (ModerationSafetyException|ModerationAccountWorkflowException|ModerationIdentityPolicyException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_out(['error' => $error->getMessage(), 'code' => $error->errorCode] + $error->projection, $error->httpStatus);
} catch (Throwable) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_out(['error' => 'The moderation operation could not be completed safely.', 'code' => 'MODERATION_OPERATION_FAILED'], 500);
}
