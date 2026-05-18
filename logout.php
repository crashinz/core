<?php
require_once __DIR__ . '/includes/base.php';
$user = current_user();
if ($user) {
    db()->prepare('UPDATE participants SET last_seen_at = NULL, webcam_path = NULL WHERE user_id = ?')->execute([(int)$user['id']]);
    db()->prepare('UPDATE users SET current_room_id = NULL, last_seen_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int)$user['id']]);
}
session_destroy();
redirect_to('/login.php');
