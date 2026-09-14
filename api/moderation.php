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
    $result = db_with_sqlite_lock_retry(
        $pdo,
        static function () use ($pdo, $user, $body, $action): array {
            $ownsTransaction = db_begin_write_transaction($pdo);
            try {
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
                if (in_array($action, ['mute', 'unmute'], true)) {
                    // Return the complete authoritative projection so every client surface
                    // can replace local state instead of guessing at the result of a mute.
                    $result['mutes'] = moderation_safety_mute_projection($pdo, (int)$user['id']);
                }
                db_commit_write_transaction($pdo, $ownsTransaction);
                return $result;
            } catch (Throwable $error) {
                db_rollback_write_transaction($pdo, $ownsTransaction);
                throw $error;
            }
        },
        'moderation state mutation'
    );
    json_out(['ok' => true] + $result);
} catch (ModerationSafetyException|ModerationAccountWorkflowException|ModerationIdentityPolicyException $error) {
    json_out(['error' => $error->getMessage(), 'code' => $error->errorCode] + $error->projection, $error->httpStatus);
} catch (Throwable $error) {
    $retryable = db_is_transient_lock_error($error);
    error_log('Moderation operation failed: ' . $error::class . ': ' . $error->getMessage());
    json_out([
        'error' => $retryable
            ? 'The moderation operation is briefly busy. Please try again.'
            : 'The moderation operation could not be completed safely.',
        'code' => $retryable ? 'MODERATION_DATABASE_BUSY' : 'MODERATION_OPERATION_FAILED',
        'retryable' => $retryable,
        'retryAfterMs' => $retryable ? 500 : null,
    ], $retryable ? 503 : 500);
}
