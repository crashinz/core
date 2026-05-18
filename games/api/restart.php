<?php
require_once __DIR__ . '/../../includes/base.php';
$body = input_json();
$lobby = (string)($body['lobby_id'] ?? '');
if ($lobby !== '') {
    db()->prepare('DELETE FROM game_moves WHERE lobby_code = ?')->execute([$lobby]);
    db()->prepare('DELETE FROM game_state WHERE lobby_code = ?')->execute([$lobby]);
}
json_out(['ok' => true]);
