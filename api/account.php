<?php
declare(strict_types=1);

define('CHATSPACE_RESTRICTED_ACCOUNT_ROUTE', true);
require_once __DIR__ . '/../includes/base.php';

$user = require_user();
$pdo = db();

function account_projection(PDO $pdo, array $user): array
{
    $profile = member_profiles_editor_projection($pdo, (int)$user['id']);
    $restriction = $pdo->prepare('SELECT expires_at, permanent, reason FROM community_ejections WHERE user_id = ? AND ' . active_ejection_sql('community_ejections') . ' ORDER BY id DESC LIMIT 1');
    $restriction->execute([(int)$user['id']]);
    $activeRestriction = $restriction->fetch() ?: null;
    $role = (string)($user['role'] ?? 'user');
    $moderation = moderation_account_full_projection($pdo, (int)$user['id']);
    $authorization = $moderation['authorization'];
    $capabilities = ['room_chat', 'community_chat', 'private_messages', 'avatar', 'relationships', 'voice', 'webcam', 'games'];
    if (in_array($role, ['admin', 'developer'], true)) $capabilities[] = 'diagnostic_issues';
    if ($role === 'admin') $capabilities[] = 'community_administration';
    return [
        'profile' => $profile,
        'security' => [
            'email' => (string)$user['email'],
            'emailChangedAt' => $user['email_changed_at'] ?? null,
            'passwordChangedAt' => $user['password_changed_at'] ?? null,
            'hasRecoveryCode' => !empty($user['recovery_code_hash']),
            'recoveryCodeSuffix' => $user['recovery_code_suffix'] ?? null,
        ],
        'status' => [
            'registeredAt' => $user['created_at'],
            'role' => $role,
            'trustState' => $authorization['trustState'],
            'trustRevision' => $authorization['trustRevision'],
            'isInstallationOwner' => $authorization['isInstallationOwner'],
            'trustPolicyNote' => $authorization['trustState'] === 'trusted'
                ? 'Trusted status does not itself grant content capabilities.'
                : match ($authorization['trustState']) {
                    'pending-approval' => 'Your account is awaiting approval. A Trusted account is required for protected capabilities.',
                    'restricted' => 'Capabilities remain restricted until the current action expires or is changed.',
                    'suspended' => 'Ordinary access is unavailable while this account is suspended.',
                    default => 'Account policy is unavailable.',
                },
            'temporaryRestriction' => $activeRestriction ? [
                'permanent' => (bool)$activeRestriction['permanent'],
                'expiresAt' => $activeRestriction['expires_at'],
                'reason' => (string)($activeRestriction['reason'] ?? ''),
            ] : null,
            'capabilities' => $authorization['capabilities'],
            'publicReason' => $authorization['publicReason'],
            'restrictionExpiresAt' => $authorization['restrictionExpiresAt'],
        ],
        'moderation' => $moderation,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') json_out(account_projection($pdo, $user));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Unsupported method'], 405);

$body = input_json();
$action = (string)($body['action'] ?? '');

if (in_array($action, ['request_trusted_review', 'request_capabilities', 'submit_appeal'], true)) {
    $caseType = match ($action) {
        'request_trusted_review' => 'trusted-review',
        'request_capabilities' => 'capability-request',
        'submit_appeal' => 'appeal',
    };
    try {
        if (db_uses_mysql_syntax($pdo)) $pdo->beginTransaction();
        else $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
        $result = moderation_account_submit_case($pdo, (int)$user['id'], $caseType, $body);
        $pdo->commit();
        json_out(['ok' => true, 'case' => $result] + account_projection($pdo, current_user() ?: $user));
    } catch (ModerationAccountWorkflowException|ModerationTrustPolicyException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $code = property_exists($error, 'errorCode') ? $error->errorCode : 'MODERATION_REQUEST_FAILED';
        $status = property_exists($error, 'httpStatus') ? $error->httpStatus : 409;
        json_out(['error' => $error->getMessage(), 'code' => $code], $status);
    } catch (Throwable) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_out(['error' => 'The request could not be stored safely.', 'code' => 'MODERATION_REQUEST_FAILED'], 500);
    }
}

if ($action === 'update_profile') {
    $fields = [
        'display_name' => $body['display_name'] ?? null,
        'name' => $body['name'] ?? null,
        'location' => $body['location'] ?? null,
        'about_me' => $body['about_me'] ?? null,
        'public_contact_email' => $body['public_contact_email'] ?? null,
        'website' => $body['website'] ?? null,
        'interests' => $body['interests'] ?? null,
        'discord_username' => $body['discord_username'] ?? null,
        'discord_visible' => $body['discord_visible'] ?? null,
    ];
    try {
        $result = member_profiles_update(
            $pdo,
            (int)$user['id'],
            (int)$user['id'],
            $fields,
            $body['expected_version'] ?? null,
            $body['request_id'] ?? null
        );
        $projection = account_projection($pdo, current_user() ?: $user);
        $projection['profile'] = $result['profile'];
        $projection['profileUpdate'] = [
            'changedFields' => $result['changedFields'] ?? [],
            'noOp' => !empty($result['noOp']),
            'idempotentReplay' => !empty($result['idempotentReplay']),
        ];
        json_out(['ok' => true] + $projection);
    } catch (MemberProfileException $error) {
        $payload = ['error' => $error->getMessage(), 'code' => $error->errorCode];
        if ($error->errorCode === 'MEMBER_PROFILE_STALE_WRITE') {
            $payload['currentProfile'] = member_profiles_editor_projection($pdo, (int)$user['id']);
        }
        json_out($payload, $error->httpStatus);
    }
}

if ($action === 'update_email') {
    $email = strtolower(trim((string)($body['email'] ?? '')));
    $password = (string)($body['current_password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['error' => 'Enter a valid email address.'], 400);
    if (!password_verify($password, (string)$user['password_hash'])) json_out(['error' => 'Current password is not correct.'], 403);
    security_mark_recent_authentication();
    $duplicate = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id <> ? LIMIT 1');
    $duplicate->execute([$email, (int)$user['id']]);
    if ($duplicate->fetchColumn()) json_out(['error' => 'That email is already in use.'], 409);
    $pdo->prepare('UPDATE users SET email = ?, email_changed_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$email, (int)$user['id']]);
    json_out(['ok' => true] + account_projection($pdo, current_user() ?: $user));
}

if ($action !== 'update_password') json_out(['error' => 'Unknown action'], 400);
$oldPassword = (string)($body['old_password'] ?? '');
$newPassword = (string)($body['new_password'] ?? '');
$confirmPassword = (string)($body['confirm_password'] ?? '');
if ($oldPassword === '' || $newPassword === '' || $confirmPassword === '') json_out(['error' => 'All password fields are required.'], 400);
if (!password_verify($oldPassword, (string)$user['password_hash'])) json_out(['error' => 'Old password is not correct.'], 403);
security_mark_recent_authentication();
if (strlen($newPassword) < 8) json_out(['error' => 'New password must be at least 8 characters.'], 400);
if ($newPassword !== $confirmPassword) json_out(['error' => 'New password and confirmation do not match.'], 400);
if (password_verify($newPassword, (string)$user['password_hash'])) json_out(['error' => 'New password must be different from the old password.'], 400);
$pdo->prepare('UPDATE users SET password_hash = ?, password_changed_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int)$user['id']]);
json_out(['ok' => true]);
