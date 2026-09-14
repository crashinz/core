<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/base.php';
header('Cache-Control: private, no-store, max-age=0');
$user=require_user();$pdo=db();
if($_SERVER['REQUEST_METHOD']==='GET')json_out(['preferences'=>nameplate_visibility_preferences($pdo,(int)$user['id'])]);
if($_SERVER['REQUEST_METHOD']!=='POST')json_out(['error'=>'Unsupported method'],405);
$input=input_json();$result=nameplate_visibility_mutate($pdo,(int)$user['id'],$input);
if(empty($result['ok'])){$status=(int)($result['http_status']??400);unset($result['http_status']);json_out($result,$status);}
$result['avatarVisibilityPreferences']=avatar_visibility_preferences($pdo,(int)$user['id']);
if((string)p2p_avatar_policy($pdo)['deliveryMode']==='p2p-plus-built-in-generated'&&!empty($result['revealedNameplates'])){
    try{$sessionId=resolve_session_id($pdo,$input['session_id']??'');$viewer=auth_participant($pdo,$sessionId,(string)($input['join_token']??''));
        if((int)$viewer['user_id']===(int)$user['id'])foreach($result['revealedNameplates'] as &$revealed){$stmt=$pdo->prepare('SELECT * FROM participants WHERE session_id=? AND user_id=? LIMIT 1');$stmt->execute([$sessionId,(int)$revealed['user_id']]);$target=$stmt->fetch();if($target)$revealed=p2p_avatar_project_participant($pdo,$sessionId,$viewer,array_merge($revealed,$target));}unset($revealed);
    }catch(Throwable){$result['revealedNameplates']=[];}
}
json_out($result);
