<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/base.php';
require_once __DIR__.'/../includes/eight_ball_practice_library.php';
$actor=require_user();$pdo=db();header('Cache-Control: no-store');
eight_ball_install_bundled_setups($pdo);
if($_SERVER['REQUEST_METHOD']==='GET')json_out(eight_ball_setup_catalog($pdo,$actor)+['csrf'=>csrf_token()]);
if($_SERVER['REQUEST_METHOD']!=='POST')json_out(['error'=>'POST required'],405);
csrf_protect_post();
try{json_out(eight_ball_setup_change($pdo,$actor,input_json()));}
catch(MultiplayerGameException $error){json_out(['error'=>$error->getMessage(),'code'=>$error->errorCode],$error->httpStatus);}
