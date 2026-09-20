<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/api_exception_handler.php';
api_install_exception_handler('profile-relationship','PROFILE_RELATIONSHIP_FAILED','Relationship requests are temporarily unavailable.');
require_once __DIR__.'/../includes/base.php';
$user=current_user();
if (!$user) json_out(['error'=>'Authentication required.'],401);
$pdo=db();$actor=(int)$user['id'];
header('Cache-Control: no-store');
try {
    if ($_SERVER['REQUEST_METHOD']==='GET') {
        if (isset($_GET['search'])) {
            flood_protection_consume($pdo,'member-profile-read',$actor);
            json_out(['members'=>profile_relationship_search($pdo,$actor,(string)$_GET['search'])]);
        }
        json_out(['relationship'=>profile_relationship_state($pdo,$actor)]);
    }
    if ($_SERVER['REQUEST_METHOD']!=='POST') json_out(['error'=>'Unsupported method.'],405);
    $input=json_decode(file_get_contents('php://input'),true);
    if (!is_array($input)) json_out(['error'=>'Invalid request.'],400);
    if (($input['action']??'')==='request') flood_protection_consume($pdo,'relationship-request',$actor);
    json_out(['relationship'=>profile_relationship_action($pdo,$actor,(string)($input['action']??''),$input)]);
} catch(MemberProfileException $e) {json_out(['error'=>$e->getMessage(),'code'=>$e->errorCode],$e->httpStatus);
} catch(FloodProtectionException $e) {json_out(['error'=>$e->getMessage(),'code'=>$e->errorCode],$e->httpStatus);}
