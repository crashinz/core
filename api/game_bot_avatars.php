<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/base.php';
require_once __DIR__.'/../includes/game_bot_avatars.php';
$user=require_user();$pdo=db();security_protect_private_response();
if(($user['role']??'')!=='admin')json_out(['canManage'=>false],403);
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_verify())csrf_failure_response();
    $seat=filter_var($_POST['seat']??null,FILTER_VALIDATE_INT);$id=(string)($_POST['avatar']??'');
    if(!$seat||$seat<1||$seat>8)json_out(['error'=>'Choose a bot seat from 1 to 8.'],422);
    if($id!==''&&!game_bot_avatar_available($pdo,$id))json_out(['error'=>'Choose an available community avatar.'],422);
    set_app_setting($pdo,'game.bot_avatar.seat.'.$seat,$id);
}elseif($_SERVER['REQUEST_METHOD']!=='GET')json_out(['error'=>'Method not allowed.'],405);
$seats=[];for($i=1;$i<=8;$i++)$seats[]=['seat'=>$i,'avatar'=>app_setting($pdo,'game.bot_avatar.seat.'.$i),'url'=>game_bot_avatar_url($pdo,$i)];
json_out(['canManage'=>true,'seats'=>$seats]);
