<?php
require_once __DIR__ . '/../includes/base.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);

$user = require_user();
$body = input_json();
$action = (string)($body['action'] ?? '');

if ($action !== 'update_password') json_out(['error' => 'Unknown action'], 400);

$oldPassword = (string)($body['old_password'] ?? '');
$newPassword = (string)($body['new_password'] ?? '');
$confirmPassword = (string)($body['confirm_password'] ?? '');

if ($oldPassword === '' || $newPassword === '' || $confirmPassword === '') {
    json_out(['error' => 'All password fields are required.'], 400);
}
if (!password_verify($oldPassword, (string)$user['password_hash'])) {
    json_out(['error' => 'Old password is not correct.'], 403);
}
if (strlen($newPassword) < 8) {
    json_out(['error' => 'New password must be at least 8 characters.'], 400);
}
if ($newPassword !== $confirmPassword) {
    json_out(['error' => 'New password and confirmation do not match.'], 400);
}
if (password_verify($newPassword, (string)$user['password_hash'])) {
    json_out(['error' => 'New password must be different from the old password.'], 400);
}

$pdo = db();
$stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
$stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int)$user['id']]);

json_out(['ok' => true]);
