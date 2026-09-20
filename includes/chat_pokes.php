<?php
declare(strict_types=1);

// Pokes reuse the existing private DM event ledger; no message content is created.
function chat_poke_preferences(int $userId, ?callable $change = null): array {
    $dir=security_private_storage_directory('chat-pokes');
    $path=$dir.'/user-'.$userId.'.json';$lockPath=$path.'.lock';
    if($userId<1||is_link($path)||is_link($lockPath))throw new RuntimeException('Poke preferences unavailable.');
    $read=static function()use($path):array {
        if(!is_file($path))return ['enabled'=>true];
        if(filesize($path)>65536)throw new RuntimeException('Poke preferences unavailable.');
        $data=json_decode((string)file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new RuntimeException('Poke preferences unavailable.');
        return $data;
    };
    if(!$change)return $read();
    $lock=fopen($lockPath,'c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Poke preferences busy.');
    try {
        $data=$change($read());$json=json_encode($data,JSON_THROW_ON_ERROR);
        $temp=$path.'.'.bin2hex(random_bytes(6)).'.tmp';
        if(file_put_contents($temp,$json,LOCK_EX)!==strlen($json))throw new RuntimeException('Could not save poke preferences.');
        @chmod($temp,0600);if(!rename($temp,$path)){@unlink($temp);throw new RuntimeException('Could not save poke preferences.');}
        return $data;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function chat_poke_allowed(PDO $pdo,int $sender,int $recipient):bool {
    if($sender<1||$recipient<1||$sender===$recipient||!(chat_poke_preferences($recipient)['enabled']??true))return false;
    if(profile_relationship_blocked($pdo,$sender,$recipient))return false;
    foreach(moderation_safety_mute_projection($pdo,$recipient)as $mute){
        if((int)$mute['muted_user_id']===$sender&&in_array('notices-unread',$mute['scopes'],true))return false;
    }
    return true;
}
function chat_poke_send(PDO $pdo,int $sessionId,array $actor,int $targetParticipant):array {
    $sender=(int)$actor['user_id'];
    $q=$pdo->prepare('SELECT p.user_id,u.username,u.display_name FROM participants p JOIN users u ON u.id=p.user_id WHERE p.id=? AND p.session_id=? AND p.last_seen_at>=?');
    $q->execute([$targetParticipant,$sessionId,gmdate('Y-m-d H:i:s',time()-90)]);$target=$q->fetch();
    $recipient=(int)($target['user_id']??0);
    if(!$target||!chat_poke_allowed($pdo,$sender,$recipient))throw new DomainException('This person is not available for pokes.');
    $now=time();
    chat_poke_preferences($sender,static function($data)use($now,$recipient){
        $sent=array_filter($data['sent']??[],static fn($t)=>is_int($t)&&$t>$now-60);
        if($now-(int)($data['lastSent']??0)<10||isset($sent[$recipient]))throw new DomainException('Please wait before poking again. You can poke the same person once per minute.');
        $sent[$recipient]=$now;$data['sent']=$sent;$data['lastSent']=$now;return $data;
    });
    // Serialize recipient throttling too, so several senders cannot create a burst.
    chat_poke_preferences($recipient,static function($data)use($now){
        if(!($data['enabled']??true)||$now-(int)($data['lastReceived']??0)<10)throw new DomainException('This person is not available for another poke yet.');
        $data['lastReceived']=$now;return $data;
    });
    $q=$pdo->prepare('SELECT username,display_name FROM users WHERE id=?');$q->execute([$sender]);$user=$q->fetch();
    $payload=['id'=>bin2hex(random_bytes(12)),'sender_id'=>$sender,'recipient_id'=>$recipient,'sender_name'=>member_profiles_effective_display_name((string)$user['username'],(string)$user['display_name']),'at'=>$now];
    emit_community_event($pdo,'dm',$sessionId,'dm:'.min($sender,$recipient).':'.max($sender,$recipient),'poke',$payload);
    return ['sent'=>true];
}
