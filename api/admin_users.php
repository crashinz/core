<?php
require_once __DIR__ . '/../includes/base.php';

$me = require_staff();
$pdo = db();
$roles = ['admin', 'developer', 'guide', 'user'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = $pdo->query('SELECT id, username, email, display_name, role, created_at FROM users ORDER BY display_name ASC')->fetchAll();
    json_out(['users' => array_map(fn(array $u): array => [
        'id' => (int)$u['id'],
        'username' => (string)$u['username'],
        'email' => $u['email'],
        'display_name' => $u['display_name'],
        'role' => $u['role'] ?: 'user',
        'created_at' => $u['created_at'],
    ], $rows)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Unsupported method'], 405);
security_require_recent_authentication_or_json();
$body = input_json();
$action = (string)($body['action'] ?? '');

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
        $identity = member_profiles_validate_identity($pdo, $username, $name);
        $stmt = $pdo->prepare('INSERT INTO users (email, username, password_hash, display_name, role, avatar_path) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$email, $identity['username'], password_hash($password, PASSWORD_DEFAULT), $identity['display_name'], $role, 'preset:Default']);
        $userId = (int)$pdo->lastInsertId();
        member_profiles_initialize_user($pdo, $userId);
        log_tool($pdo, (int)$me['id'], 'admin_create_user', $userId, null, json_encode(['role' => $role, 'identity_fields' => ['username', 'display_name']]));
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
if ($userId === (int)$me['id'] && $action === 'delete') json_out(['error' => 'You cannot delete yourself'], 400);

if ($action === 'update') {
    $role = (string)($body['role'] ?? 'user');
    $password = (string)($body['password'] ?? '');
    if (!in_array($role, $roles, true)) json_out(['error' => 'Invalid role'], 400);
    $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $userId]);
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

if ($action === 'delete') {
    try {
        if (db_uses_mysql_syntax($pdo)) $pdo->beginTransaction();
        else $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
        member_profiles_record_deleted_username_use($pdo, $userId);
        log_tool(
            $pdo,
            (int)$me['id'],
            'admin_delete_user',
            $userId,
            null,
            'Deleted member account; retained only the username reuse warning record.'
        );
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_out(['error' => 'The member account could not be deleted safely.'], 500);
    }
    json_out(['ok' => true]);
}

json_out(['error' => 'Unknown action'], 400);
