<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/base.php';

$user = require_user();
$pdo = db();

try {
    $file = five_dice_media_pack_authorized_file(
        $pdo,
        trim((string)($_GET['game_session_id'] ?? '')),
        (int)$user['id'],
        trim((string)($_GET['slot'] ?? ''))
    );
    header('Content-Type: ' . (string)$file['mime']);
    header('Content-Length: ' . (string)$file['bytes']);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=300, no-transform');
    header('Content-Disposition: inline; filename="game-media-' . rawurlencode((string)$file['slot']) . '"');
    header('ETag: "' . strtolower((string)$file['sha256']) . '"');
    readfile((string)$file['path']);
    exit;
} catch (MultiplayerGameException $error) {
    json_out(['error' => $error->getMessage(), 'code' => $error->errorCode], $error->httpStatus);
}
