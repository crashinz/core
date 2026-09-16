<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/api_exception_handler.php';
api_install_exception_handler('unused-images','UNUSED_IMAGE_REVIEW_FAILED','Image cleanup could not finish. No further files were changed.');
require_once __DIR__.'/../includes/base.php';
require_once __DIR__.'/../includes/unused_image_cleanup.php';
$actor=require_staff(['admin']);security_protect_private_response();security_require_recent_authentication_or_json();$pdo=db();
try {
    if($_SERVER['REQUEST_METHOD']==='GET') {
        $action=(string)($_GET['action']??'scan');
        if($action==='scan')json_out(unused_image_scan($pdo,$actor,(string)($_GET['after']??''),(string)($_GET['kind']??'all')));
        if($action==='trash')json_out(unused_image_trash_list($actor,(string)($_GET['after']??'')));
        if($action==='preview')unused_image_preview($pdo,$actor,(string)($_GET['path']??''),(string)($_GET['snapshot']??''),(string)($_GET['id']??''));
        json_out(['error'=>'Unknown review action.'],400);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')json_out(['error'=>'GET or POST required'],405);
    csrf_protect_post();$body=input_json();$action=(string)($body['action']??'');
    if($action==='trash')json_out(unused_image_move($pdo,$actor,(string)($body['path']??''),(string)($body['snapshot']??'')));
    if($action==='purge'&&($body['confirmation']??'')!=='permanently-delete-selected')json_out(['error'=>'Confirm permanent deletion first.'],400);
    json_out(unused_image_trash_action($pdo,$actor,(string)($body['id']??''),$action));
}catch(UnusedImageException $error){json_out(['error'=>$error->getMessage()],$error->httpStatus);}
