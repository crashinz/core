<?php
declare(strict_types=1);

// Direct grants use the member-request catalog, not staff powers or consent.
final class AdminUserPermissionException extends RuntimeException
{
    public function __construct(string $message, public string $errorCode, public int $httpStatus)
    {
        parent::__construct($message);
    }
}

function admin_user_permissions_authorize(PDO $pdo, int $actorId): void
{
    $actor = $pdo->prepare('SELECT role FROM users WHERE id=?');
    $actor->execute([$actorId]);
    if ($actor->fetchColumn() !== 'admin') {
        throw new AdminUserPermissionException('Only an administrator can manage account permissions.', 'ADMIN_REQUIRED', 403);
    }
    moderation_safety_require_staff_capability($pdo, $actorId, 'review-reports');
}

function admin_user_permissions_projection(PDO $pdo, int $actorId, int $userId): array
{
    admin_user_permissions_authorize($pdo, $actorId);
    $user = $pdo->prepare('SELECT u.id FROM users u LEFT JOIN account_deletions d ON d.user_id=u.id WHERE u.id=? AND d.user_id IS NULL');
    $user->execute([$userId]);
    if (!$user->fetchColumn()) {
        throw new AdminUserPermissionException('The account is unavailable.', 'ACCOUNT_UNAVAILABLE', 404);
    }
    return moderation_identity_account_authorization($pdo, $userId);
}

function admin_user_permissions_save(PDO $pdo, int $actorId, int $userId, array $changes, string $reason): array
{
    admin_user_permissions_authorize($pdo, $actorId);
    if (!$changes || count($changes) > 100 || strlen($reason) > 500) {
        throw new AdminUserPermissionException('Select permissions to change and keep the reason under 500 bytes.', 'INVALID_PERMISSION_CHANGE', 422);
    }
    $transaction = database_transaction_begin($pdo, true);
    try {
        $lock = $pdo->prepare('SELECT id FROM users WHERE id=?' . (db_uses_mysql_syntax($pdo) ? ' FOR UPDATE' : ''));
        $lock->execute([$userId]);
        $projection = admin_user_permissions_projection($pdo, $actorId, $userId);
        if ($projection['isInstallationOwner']) {
            throw new AdminUserPermissionException('The Installation Owner already has all available permissions.', 'OWNER_PERMISSIONS_INHERITED', 409);
        }
        $catalog = array_column($projection['capabilities'], null, 'id');
        $seen = [];
        $audit = [];
        $changedIds = [];
        foreach ($changes as $change) {
            if (!is_array($change) || !is_string($change['id'] ?? null)
                || !is_bool($change['enabled'] ?? null) || !is_int($change['revision'] ?? null)) {
                throw new AdminUserPermissionException('A permission, boolean value and current revision are required.', 'INVALID_PERMISSION_CHANGE', 422);
            }
            $id = $change['id'];
            $entry = $catalog[$id] ?? null;
            if (!$entry || isset($seen[$id]) || ($change['enabled'] && !$entry['available'])) {
                throw new AdminUserPermissionException('An unknown, unavailable or duplicate permission was selected.', 'INVALID_PERMISSION_CHANGE', 422);
            }
            $seen[$id] = true;
            if ($change['revision'] !== $entry['revision']) {
                throw new AdminUserPermissionException('These permissions changed elsewhere. Close and reopen Access & Permissions before saving again.', 'PERMISSION_REVISION_CONFLICT', 409);
            }
            if ($change['enabled'] === $entry['storedEnabled']) continue;
            if ($entry['revision'] === 0) {
                $write = $pdo->prepare('INSERT INTO user_capability_grants (user_id,capability_id,enabled,revision,granted_by_user_id) VALUES (?,?,?,1,?)');
                $write->execute([$userId, $id, (int)$change['enabled'], $actorId]);
            } else {
                $write = $pdo->prepare('UPDATE user_capability_grants SET enabled=?,revision=revision+1,granted_by_user_id=? WHERE user_id=? AND capability_id=? AND revision=?');
                $write->execute([(int)$change['enabled'], $actorId, $userId, $id, $entry['revision']]);
                if ($write->rowCount() !== 1) {
                    throw new AdminUserPermissionException('These permissions changed elsewhere. Reopen the permission controls.', 'PERMISSION_REVISION_CONFLICT', 409);
                }
            }
            $audit[] = $id . '=' . ($change['enabled'] ? 'enabled' : 'disabled');
            $changedIds[] = $id;
        }
        if ($audit) {
            $reason = trim($reason) ?: 'Administrator updated account permissions.';
            log_tool($pdo, $actorId, 'admin_update_content_permissions', $userId, null, implode('; ', $audit) . '; reason: ' . $reason);
            moderation_account_create_notice($pdo, $userId, 'content-permissions-updated', $reason, $changedIds);
        }
        $result = admin_user_permissions_projection($pdo, $actorId, $userId);
        database_transaction_commit($pdo, $transaction);
        return $result;
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
}
