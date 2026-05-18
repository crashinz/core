<?php
require_once __DIR__ . '/../includes/base.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);

$user = require_user();
$body = input_json();
$password = (string)($body['password'] ?? '');

if ($password === '') json_out(['error' => 'Password required'], 400);
if (!password_verify($password, (string)$user['password_hash'])) {
    json_out(['error' => 'Incorrect password.'], 403);
}

json_out(['ok' => true]);
