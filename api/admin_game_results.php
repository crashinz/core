<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/base.php';

$actor = require_staff(['admin']);
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $gameKey = trim((string)($_GET['game_key'] ?? ''));
    multiplayer_game_definition($pdo, $gameKey, false);
    $stmt = $pdo->prepare(
        'SELECT r.public_id,r.game_session_public_id,r.game_key,r.adaptation_version,r.mode,r.result_sha256,
                r.recorded_at,r.expires_at,COUNT(DISTINCT m.user_id) AS member_count,
                COUNT(DISTINCT c.id) AS correction_count
           FROM multiplayer_game_results r
           LEFT JOIN multiplayer_game_result_members m ON m.result_id=r.id
           LEFT JOIN multiplayer_game_result_corrections c ON c.result_id=r.id
          WHERE r.game_key=?
          GROUP BY r.id ORDER BY r.recorded_at DESC LIMIT 100'
    );
    $stmt->execute([$gameKey]);
    json_out(['gameKey' => $gameKey, 'results' => $stmt->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);
$body = input_json();
try {
    if ((string)($body['action'] ?? '') !== 'correct') json_out(['error' => 'Unknown result action'], 400);
    json_out(multiplayer_game_correct_result(
        $pdo,
        $actor,
        trim((string)($body['result_public_id'] ?? '')),
        trim((string)($body['reason'] ?? '')),
        is_array($body['replacement'] ?? null) ? $body['replacement'] : []
    ));
} catch (MultiplayerGameException $error) {
    json_out(['error' => $error->getMessage(), 'code' => $error->errorCode] + $error->facts, $error->httpStatus);
}
