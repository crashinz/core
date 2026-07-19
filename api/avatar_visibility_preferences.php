<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/base.php';

header('Cache-Control: private, no-store, max-age=0');
$user = require_user();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_out(['preferences' => avatar_visibility_preferences($pdo, (int)$user['id'])]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Unsupported method'], 405);
$result = avatar_visibility_mutate($pdo, (int)$user['id'], input_json());
if (empty($result['ok'])) {
    $status = (int)($result['http_status'] ?? 400);
    unset($result['http_status']);
    json_out($result, $status);
}
json_out($result);
