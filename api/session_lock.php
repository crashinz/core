<?php
require_once __DIR__ . '/../includes/base.php';
require_once __DIR__ . '/../includes/auth_rate_limit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);

$user = require_user();
$pdo = db();
$body = input_json();
$password = (string)($body['password'] ?? '');

if ($password === '') json_out(['error' => 'Password required'], 400);
$identifier = (string)$user['id'];
$limit = auth_rate_limit_status($pdo, 'reauthentication', $identifier);
if (!$limit['allowed']) {
    json_out(['error' => $limit['message'], 'retry_after' => $limit['retry_after']], 429);
}
if (!password_verify($password, (string)$user['password_hash'])) {
    auth_rate_record_failure($pdo, 'reauthentication', $identifier);
    json_out(['error' => 'Incorrect password.'], 403);
}

auth_rate_clear_identifier($pdo, 'reauthentication', $identifier);
security_mark_recent_authentication();
json_out(['ok' => true]);
