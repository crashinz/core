<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/base.php';

$user = require_user();
$pdo = db();

function account_projection(PDO $pdo, array $user): array
{
    $restriction = $pdo->prepare('SELECT expires_at, permanent, reason FROM community_ejections WHERE user_id = ? AND ' . active_ejection_sql('community_ejections') . ' ORDER BY id DESC LIMIT 1');
    $restriction->execute([(int)$user['id']]);
    $activeRestriction = $restriction->fetch() ?: null;
    $role = (string)($user['role'] ?? 'user');
    $capabilities = ['room_chat', 'community_chat', 'private_messages', 'avatar', 'relationships', 'voice', 'webcam', 'games'];
    if (in_array($role, ['admin', 'developer'], true)) $capabilities[] = 'diagnostic_issues';
    if ($role === 'admin') $capabilities[] = 'community_administration';
    return [
        'profile' => [
            'username' => (string)($user['username'] ?: ('user' . (int)$user['id'])),
            'usernameConfigured' => !empty($user['username']),
            'displayName' => (string)$user['display_name'],
            'location' => (string)($user['profile_location'] ?? ''),
            'about' => (string)($user['profile_about'] ?? ''),
            'visibility' => (string)($user['profile_visibility'] ?? 'community'),
            'avatarPath' => (string)($user['avatar_path'] ?? 'preset:Default'),
        ],
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
            'trustState' => 'Current standard access',
            'trustPolicyNote' => 'Expanded trust and moderation policy is reserved for Build 000051.',
            'temporaryRestriction' => $activeRestriction ? [
                'permanent' => (bool)$activeRestriction['permanent'],
                'expiresAt' => $activeRestriction['expires_at'],
                'reason' => (string)($activeRestriction['reason'] ?? ''),
            ] : null,
            'capabilities' => $capabilities,
        ],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') json_out(account_projection($pdo, $user));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Unsupported method'], 405);

$body = input_json();
$action = (string)($body['action'] ?? '');

if ($action === 'update_profile') {
    $username = strtolower(trim((string)($body['username'] ?? '')));
    $displayName = trim((string)($body['display_name'] ?? ''));
    $location = trim((string)($body['location'] ?? ''));
    $about = trim((string)($body['about'] ?? ''));
    $visibility = (string)($body['visibility'] ?? 'community');
    if (!preg_match('/^[a-z0-9][a-z0-9_.-]{2,31}$/', $username)) json_out(['error' => 'Username must be 3-32 lowercase letters, numbers, dots, dashes, or underscores.'], 400);
    $textLength = static fn(string $value): int => function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : (preg_match_all('/./us', $value, $matches) === false ? strlen($value) : count($matches[0]));
    if ($displayName === '' || $textLength($displayName) > 80) json_out(['error' => 'Display name must be 1-80 characters.'], 400);
    if ($textLength($location) > 80 || $textLength($about) > 500) json_out(['error' => 'Profile text is too long.'], 400);
    if (!in_array($visibility, ['private', 'community', 'public'], true)) json_out(['error' => 'Invalid profile visibility.'], 400);
    $duplicate = $pdo->prepare('SELECT id FROM users WHERE LOWER(username) = LOWER(?) AND id <> ? LIMIT 1');
    $duplicate->execute([$username, (int)$user['id']]);
    if ($duplicate->fetchColumn()) json_out(['error' => 'That username is already in use.'], 409);
    $pdo->prepare('UPDATE users SET username = ?, display_name = ?, profile_location = ?, profile_about = ?, profile_visibility = ? WHERE id = ?')->execute([$username, $displayName, $location, $about, $visibility, (int)$user['id']]);
    $pdo->prepare('UPDATE participants SET display_name = ? WHERE user_id = ?')->execute([$displayName, (int)$user['id']]);
    json_out(['ok' => true] + account_projection($pdo, current_user() ?: $user));
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
