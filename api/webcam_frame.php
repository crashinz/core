<?php
require_once __DIR__ . '/../includes/base.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);
$body = input_json();
$pdo = db();
$sessionId = resolve_session_id($pdo, $body['session_id'] ?? '');
$p = auth_participant($pdo, $sessionId, $body['join_token'] ?? '');
$action = $body['action'] ?? 'frame';

if ($action === 'on') {
    $pdo->prepare('UPDATE participants SET webcam_path = NULL, webcam_enabled = 1 WHERE id = ?')->execute([(int)$p['id']]);
    emit_event($pdo, $sessionId, 'webcam', [
        'participant_id' => (int)$p['id'],
        'webcam_path' => null,
        'webcam_enabled' => true,
        'avatar_path' => $p['avatar_path'],
        'avatar_url' => resolve_avatar($p['avatar_path']),
    ]);
    json_out(['ok' => true, 'webcam_path' => null, 'webcam_enabled' => true]);
}

if ($action === 'off') {
    $pdo->prepare('UPDATE participants SET webcam_path = NULL, webcam_enabled = 0 WHERE id = ?')->execute([(int)$p['id']]);
    emit_event($pdo, $sessionId, 'webcam', [
        'participant_id' => (int)$p['id'],
        'webcam_path' => null,
        'webcam_enabled' => false,
        'avatar_path' => $p['avatar_path'],
        'avatar_url' => resolve_avatar($p['avatar_path']),
    ]);
    json_out(['ok' => true, 'webcam_path' => null, 'webcam_enabled' => false, 'avatar_path' => $p['avatar_path'], 'avatar_url' => resolve_avatar($p['avatar_path'])]);
}

$image = (string)($body['image'] ?? '');
if (!preg_match('/^data:image\/jpeg;base64,/', $image)) json_out(['error' => 'JPEG frame required'], 400);
$bytes = base64_decode(substr($image, strpos($image, ',') + 1), true);
if ($bytes === false || strlen($bytes) > 900000) json_out(['error' => 'Bad frame'], 400);
$file = 'webcam_' . (int)$p['id'] . '.jpg';
$path = __DIR__ . '/../assets/uploads/webcam/' . $file;
file_put_contents($path, $bytes);
$public = '/assets/uploads/webcam/' . $file;
$pdo->prepare('UPDATE participants SET webcam_path = ?, webcam_enabled = 1 WHERE id = ?')->execute([$public, (int)$p['id']]);
emit_event($pdo, $sessionId, 'webcam', ['participant_id' => (int)$p['id'], 'webcam_path' => $public, 'webcam_enabled' => true]);
json_out(['ok' => true, 'webcam_path' => $public, 'webcam_enabled' => true]);
