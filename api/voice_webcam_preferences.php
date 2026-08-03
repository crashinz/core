<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_exception_handler.php';
api_install_exception_handler('voice-webcam-preferences', 'VOICE_WEBCAM_PREFERENCES_FAILED', 'Voice and webcam settings are temporarily unavailable.');
require_once __DIR__ . '/../includes/base.php';

$pdo = db();
$user = require_user();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_out(['preferences' => voice_webcam_preferences($pdo, (int)$user['id'])]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);
    csrf_protect_post();
    $body = input_json();
    json_out([
        'ok' => true,
        'preferences' => voice_webcam_update_preferences($pdo, (int)$user['id'], $body),
    ]);
} catch (PrivateVoiceException $error) {
    json_out([
        'error' => $error->getMessage(),
        'code' => $error->errorCode,
        'context' => $error->context,
    ], $error->httpStatus);
}
