<?php
require_once __DIR__ . '/../includes/base.php';

$me = require_staff(['admin', 'moderator', 'developer']);
$pdo = db();
$roles = ['admin', 'moderator', 'developer', 'guide', 'user'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = $pdo->query(
        'SELECT u.id,u.username,u.email,u.display_name,u.role,u.created_at,
                COALESCE(t.trust_state,\'pending-approval\') AS trust_state,
                COALESCE(t.revision,1) AS trust_revision
         FROM users u LEFT JOIN user_trust t ON t.user_id=u.id
         ORDER BY u.display_name ASC'
    )->fetchAll();
    $payload = ['users' => array_map(fn(array $u): array => [
        'id' => (int)$u['id'],
        'username' => (string)$u['username'],
        'email' => $u['email'],
        'display_name' => $u['display_name'],
        'role' => $u['role'] ?: 'user',
        'trust_state' => (string)$u['trust_state'],
        'trust_revision' => (int)$u['trust_revision'],
        'created_at' => $u['created_at'],
    ], $rows)];
    $payload['moderationCases'] = moderation_safety_has_staff_capability($pdo, (int)$me['id'], 'review-reports')
        ? moderation_account_staff_cases($pdo)
        : [];
    $payload['installationOwner'] = moderation_identity_is_owner($pdo, (int)$me['id'])
        ? moderation_identity_owner($pdo)
        : null;
    json_out($payload);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Unsupported method'], 405);
security_require_recent_authentication_or_json();
$body = input_json();
$action = (string)($body['action'] ?? '');

if ($action === 'transfer_installation_owner') {
    try {
        if (empty($body['confirmed'])) {
            json_out([
                'error' => 'Inline review and explicit confirmation are required.',
                'code' => 'INSTALLATION_OWNER_CONFIRMATION_REQUIRED',
            ], 422);
        }
        $result = moderation_identity_transfer_owner(
            $pdo,
            (int)$me['id'],
            (int)($body['new_owner_id'] ?? 0),
            (int)($body['expected_revision'] ?? 0),
            trim((string)($body['request_id'] ?? '')),
            trim((string)($body['reason'] ?? ''))
        );
        json_out(['ok' => true, 'ownership' => $result]);
    } catch (ModerationIdentityPolicyException|SecurityPolicyViolation $error) {
        $code = property_exists($error, 'errorCode')
            ? $error->errorCode
            : 'RECENT_AUTHENTICATION_REQUIRED';
        $status = property_exists($error, 'httpStatus') ? $error->httpStatus : 403;
        $projection = property_exists($error, 'projection') ? $error->projection : [];
        json_out(['error' => $error->getMessage(), 'code' => $code] + $projection, $status);
    }
}

if ($action === 'decide_moderation_case') {
    try {
        moderation_safety_require_staff_capability($pdo, (int)$me['id'], 'review-reports');
        if (db_uses_mysql_syntax($pdo)) $pdo->beginTransaction();
        else $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
        $result = moderation_account_decide_case($pdo, (int)$me['id'], $body);
        $pdo->commit();
        json_out(['ok' => true, 'decision' => $result, 'moderationCases' => moderation_account_staff_cases($pdo)]);
    } catch (ModerationAccountWorkflowException|ModerationTrustPolicyException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $code = property_exists($error, 'errorCode') ? $error->errorCode : 'MODERATION_CASE_DECISION_FAILED';
        $status = property_exists($error, 'httpStatus') ? $error->httpStatus : 409;
        json_out(['error' => $error->getMessage(), 'code' => $code], $status);
    } catch (Throwable) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_out(['error' => 'The case decision could not be stored safely.', 'code' => 'MODERATION_CASE_DECISION_FAILED'], 500);
    }
}

if ($action === 'create') {
    $username = trim((string)($body['username'] ?? ''));
    $email = trim((string)($body['email'] ?? ''));
    $name = trim((string)($body['display_name'] ?? ''));
    $password = (string)($body['password'] ?? '');
    $role = (string)($body['role'] ?? 'user');
    if ($username === '' || $email === '' || $password === '') {
        json_out(['error' => 'Username, email, and password are required'], 400);
    }
    if (!in_array($role, $roles, true)) json_out(['error' => 'Invalid role'], 400);
    try {
        if (db_uses_mysql_syntax($pdo)) $pdo->beginTransaction();
        else $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
        $created = moderation_identity_register_account($pdo, [
            'username' => $username,
            'email' => $email,
            'display_name' => $name,
            'password' => $password,
            'role' => $role,
            'avatar_path' => 'preset:Default',
        ], 'administrator-created', (int)$me['id']);
        $userId = (int)$created['userId'];
        $pdo->commit();
        json_out(['ok' => true]);
    } catch (MemberProfileException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_out(['error' => $error->getMessage(), 'code' => $error->errorCode], $error->httpStatus);
    } catch (PDOException) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_out(['error' => 'That email, Username, or Display name is already in use.'], 409);
    }
}

$userId = (int)($body['id'] ?? 0);
if (!$userId) json_out(['error' => 'User required'], 400);
if ($action === 'delete') {
    json_out([
        'error' => 'Direct member deletion is disabled. Use non-destructive suspension when necessary. Build 000053 is the sole Delete Account owner.',
        'code' => 'BUILD_000053_DELETE_ACCOUNT_REQUIRED',
    ], 409);
}

if ($action === 'update') {
    $role = (string)($body['role'] ?? 'user');
    $password = (string)($body['password'] ?? '');
    if (!in_array($role, $roles, true)) json_out(['error' => 'Invalid role'], 400);
    $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $userId]);
    $pdo->prepare('DELETE FROM user_staff_capability_grants WHERE user_id=?')->execute([$userId]);
    moderation_safety_project_default_staff_grants($pdo, $userId);
    if ($password !== '') {
        $pdo->prepare('UPDATE users SET password_hash = ?, password_changed_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
    }
    log_tool($pdo, (int)$me['id'], 'admin_update_user', $userId, null, 'Role: ' . $role . ($password !== '' ? '; password reset' : ''));

    $stmt = $pdo->prepare(
        'SELECT p.id AS participant_id, p.session_id, p.user_id, r.owner_id
           FROM participants p
           JOIN room_sessions rs ON rs.id = p.session_id
           JOIN rooms r ON r.id = rs.room_id
          WHERE p.user_id = ?'
    );
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $row) {
        $isOwner = (int)$row['owner_id'] === $userId;
        $canUseHostTools = $isOwner || in_array($role, ['guide', 'developer', 'admin'], true);
        emit_event($pdo, (int)$row['session_id'], 'user_role_update', [
            'participant_id' => (int)$row['participant_id'],
            'user_id' => $userId,
            'role' => $role,
            'is_owner' => $isOwner,
            'can_edit_room' => $isOwner || in_array($role, ['developer', 'admin'], true),
            'can_use_host_tools' => $canUseHostTools,
            'can_moderate_messages' => $canUseHostTools,
            'can_community_eject' => in_array($role, ['developer', 'admin'], true),
        ]);
    }
    json_out(['ok' => true]);
}

json_out(['error' => 'Unknown action'], 400);
