<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/base.php';

$user = require_user();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_out([
        'preferences' => webcam_viewer_preferences($pdo, (int)$user['id']),
        'capability' => webcam_capability($pdo),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Unsupported method'], 405);
$body = input_json();
$result = webcam_viewer_preferences_update(
    $pdo,
    (int)$user['id'],
    $body['expected_version'] ?? null,
    $body['show_webcams'] ?? null,
    $body['receive_webcams'] ?? null
);
if (empty($result['ok'])) {
    $status = (int)($result['http_status'] ?? 400);
    unset($result['http_status']);
    json_out($result, $status);
}
json_out($result + ['capability' => webcam_capability($pdo)]);
