<?php
require_once __DIR__ . '/_auth.php';

$pdo = db();
$source = $_SERVER['REQUEST_METHOD'] === 'POST' ? input_json() : $_GET;
try {
    $auth = game_compatibility_auth($pdo, $source);
    game_compatibility_record($pdo, $auth, 'legacy-result-notice', [
        'result' => substr(trim((string)($auth['source']['result'] ?? '')), 0, 32),
        'reason' => substr(trim((string)($auth['source']['reason'] ?? '')), 0, 96),
    ]);
    json_out([
        'ok' => true,
        'recorded' => false,
        'reason' => 'Compatibility games do not create authoritative records until their extension migration.',
    ]);
} catch (MultiplayerGameException $error) {
    json_out(['error' => $error->getMessage(), 'code' => $error->errorCode], $error->httpStatus);
}
