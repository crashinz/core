<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/api_exception_handler.php';
api_install_exception_handler('chat-poke','CHAT_POKE_FAILED','Pokes are temporarily unavailable.');
require_once __DIR__.'/../includes/base.php';
require_once __DIR__.'/../includes/event_delivery.php';
require_once __DIR__.'/../includes/chat_pokes.php';
$user=require_user();$pdo=db();header('Cache-Control: no-store');
$post=($_SERVER['REQUEST_METHOD']??'GET')==='POST';
$input=$post?json_decode(request_raw_body(),true):$_GET;
if(!is_array($input))json_out(['error'=>'Invalid request.'],400);
if($post&&!csrf_verify($input))json_out(['error'=>'Refresh the page and try again.'],419);
if(!$post&&($_SERVER['REQUEST_METHOD']??'GET')!=='GET')json_out(['error'=>'Unsupported method.'],405);
try {
    $sid=resolve_session_id($pdo,$input['session_id']??'');
    $actor=event_delivery_authorized_viewer($pdo,$sid,(string)($input['join_token']??''));
}catch(EventDeliveryAuthorizationException $e){json_out(['error'=>$e->getMessage(),'code'=>$e->errorCode],$e->httpStatus);}
if((int)$actor['user_id']!==(int)$user['id'])json_out(['error'=>'Unauthorized.'],403);
session_write_close();
try {
    $uid=(int)$user['id'];$action=(string)($input['action']??'state');
    if(!$post&&$action!=='state')json_out(['error'=>'POST required.'],405);
    if($action==='state')json_out(['enabled'=>(chat_poke_preferences($uid)['enabled']??true)===true]);
    if($action==='preferences'){
        if(!is_bool($input['enabled']??null))json_out(['error'=>'Invalid preference.'],400);
        $value=$input['enabled'];chat_poke_preferences($uid,static fn($data)=>array_merge($data,['enabled'=>$value]));json_out(['enabled'=>$value]);
    }
    if($action==='send')json_out(chat_poke_send($pdo,$sid,$actor,(int)($input['target_participant_id']??0)));
    json_out(['error'=>'Unknown action.'],400);
}catch(DomainException $e){json_out(['error'=>$e->getMessage()],409);}
