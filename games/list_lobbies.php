<?php
require_once __DIR__ . '/../includes/base.php';
/**
 * List/create lobbies for a game.
 * The game HTML files redirect here on leave so the game modal can close itself gracefully.
 */
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>Game ended</title></head>
<body>
<script src="<?= e(app_url('/games/game-ended.js')) ?>"></script>
<p style="font-family:sans-serif;text-align:center;padding:40px;color:#888;">
  Game ended. You can close this window.
</p>
</body>
</html>
