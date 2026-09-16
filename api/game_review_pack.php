<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/api_exception_handler.php';
api_install_exception_handler('game-review-pack','GAME_REVIEW_PACK_FAILED','Reference installation could not finish. Existing references were not replaced.');
require_once __DIR__.'/../includes/base.php';
require_once __DIR__.'/../includes/game_review.php';
require_once __DIR__.'/../includes/game_review_pack.php';
$actor=require_staff(['admin']);security_protect_private_response();
try{
    if($_SERVER['REQUEST_METHOD']==='GET')json_out(game_review_pack_status());
    if($_SERVER['REQUEST_METHOD']!=='POST')json_out(['error'=>'POST required.'],405);
    if(!csrf_verify())csrf_failure_response();
    $action=(string)($_POST['action']??'');
    if($action==='hydrate')json_out(game_review_pack_hydrate(db(),(string)($_POST['game']??'')));
    $input=$_POST;if(isset($input['size']))$input['size']=(int)$input['size'];
    json_out(game_review_pack_upload($actor,$action,$input,$_FILES['chunk']??[]));
}catch(Throwable $e){json_out(['error'=>$e->getMessage()],422);}
