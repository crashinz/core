<?php
declare(strict_types=1);

/** Bot portraits refer only to community images, never a private library item. */
function game_bot_avatar_available(PDO $pdo,string $id): bool {
    if(!preg_match('/^[a-zA-Z0-9-]{16,80}$/',$id))return false;
    if(app_setting($pdo,'avatar_library.shared.'.$id)!=='1'||app_setting($pdo,'avatar_library.deleted.'.$id)==='1')return false;
    $q=$pdo->prepare("SELECT 1 FROM server_media_assets WHERE public_id=? AND source_owner='avatar' AND source_role='avatar' AND category='avatar' AND status='active' AND (expires_at IS NULL OR expires_at>CURRENT_TIMESTAMP)");
    $q->execute([$id]);return (bool)$q->fetchColumn();
}
function game_bot_avatar_url(PDO $pdo,int $seat): string {
    $id=app_setting($pdo,'game.bot_avatar.seat.'.$seat);
    return $id!==''&&game_bot_avatar_available($pdo,$id)?app_url('/api/avatar_library.php?action=image&kind=avatar&id='.rawurlencode($id)):resolve_avatar('preset:Default');
}
