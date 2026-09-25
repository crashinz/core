<?php
declare(strict_types=1);
require __DIR__.'/includes/base.php';require_once __DIR__.'/includes/game_review.php';require_once __DIR__.'/includes/game_review_snapshots.php';
$user=require_user();security_protect_private_response();
try {
    game_review_assert_admin($user);$path=(string)($_SERVER['PATH_INFO']??'');
    if(!str_starts_with($path,'/v5/'))throw new MultiplayerGameException('Reference version not found.','GAME_REVIEW_REFERENCE_MISSING',404);
    $key=substr($path,4);
    // Pool has an independent frozen dependency tree; its framework API remains isolated.
    if(in_array($key,['games/pool-reference/api/game_framework.php','games/pool-advanced-reference/api/game_framework.php'],true))$key='api/game_framework.php';
    if($key==='api/game_framework.php')json_out(game_review_snapshot_dispatch($user,$_SERVER['REQUEST_METHOD']==='POST'?input_json():$_GET));
    if(in_array($key,['api/game_media.php','api/five_dice_media.php'],true)){
        $r=game_review_get($user,(string)($_GET['game_session_id']??''));$game=$key==='api/five_dice_media.php'?'five-dice':(string)($_GET['game']??'');
        if($game!==$r['case']['game']||$r['pack']!=='classic')throw new MultiplayerGameException('Reference media is unavailable.','GAME_REVIEW_MEDIA_DENIED',403);
        $key=game_review_snapshot_manifest()['media'][$game][(string)($_GET['slot']??'')]??'';
    }elseif(!str_starts_with($key,'games/')&&!str_starts_with($key,'assets/'))throw new MultiplayerGameException('Reference resource not found.','GAME_REVIEW_REFERENCE_MISSING',404);
    $file=game_review_snapshot_file($key);session_write_close();header('Content-Type: '.$file['mime']);header('Content-Length: '.$file['bytes']);header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-cache, must-revalidate');header('ETag: "'.$file['sha256'].'"');
    if($file['mime']==='text/html')header("Content-Security-Policy: default-src 'self' data: blob:; script-src 'self'; style-src 'self' 'unsafe-inline'; connect-src 'self'; frame-ancestors 'self'; form-action 'none'; object-src 'none'; base-uri 'none'");
    readfile($file['path']);
}catch(MultiplayerGameException $e){json_out(['error'=>$e->getMessage(),'code'=>$e->errorCode],$e->httpStatus);}
