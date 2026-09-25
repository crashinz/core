<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/base.php';
require_once __DIR__.'/../includes/site_backup.php';
require_once __DIR__.'/../includes/database_backups.php';
$actor=require_staff();
security_require_recent_authentication_or_json();
security_protect_private_response();
if($_SERVER['REQUEST_METHOD']!=='POST')json_out(['error'=>'Use the backup controls to submit this request.'],405);
if(!csrf_verify())csrf_failure_response();
$pdo=db();$action=(string)($_POST['action']??'');
try {
    $password=(string)($_POST['password']??'');
    if($action==='export') {
        $lock=site_backup_exclusive_lock();
        try{$result=site_backup_export($pdo,$password,(string)($_POST['mode']??'selective'),(array)($_POST['sections']??[]));}
        finally{flock($lock,LOCK_UN);}
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="CoreChat-'.($_POST['mode']==='complete'?'complete':'selected').'-'.gmdate('Ymd-His').'.corechat"');
        header('Content-Length: '.filesize($result['path']));
        readfile($result['path']);unlink($result['path']);rmdir($result['directory']);exit;
    }
    if($action==='recovery_list') {
        $items=[]; foreach(glob(site_backup_work_root().'/export-*/site.corechat')?:[] as $file) {
            if(is_link($file))continue;
            $items[]=['id'=>basename(dirname($file)).'/site.corechat','createdAt'=>gmdate('Y-m-d H:i:s \U\T\C',filemtime($file)),'bytes'=>filesize($file)];
        }
        usort($items,static fn($a,$b)=>strcmp($b['createdAt'],$a['createdAt']));json_out(['items'=>$items]);
    }
    if($action==='recovery_download') {
        $id=(string)($_POST['recovery']??'');
        if(!preg_match('~^export-[a-f0-9]{32}/site\.corechat$~D',$id))throw new RuntimeException('Unknown recovery archive.');
        $path=site_backup_work_root().'/'.$id;
        if(!is_file($path)||is_link($path))throw new RuntimeException('Recovery archive is unavailable.');
        header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="CoreChat-before-import.corechat"');header('Content-Length: '.filesize($path));readfile($path);exit;
    }
    if(!in_array($action,['preview','apply'],true))throw new RuntimeException('Unknown backup action.');
    security_authorize_outside_content_or_json($pdo,$actor,'database_import',['source'=>'site_backup']);
    $upload=$_FILES['archive']??[];$path=(string)($upload['tmp_name']??'');
    if(($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($path))throw new RuntimeException('Choose a backup file.');
    if(filesize($path)>site_backup_limit($pdo))throw new RuntimeException('Backup exceeds the configured import size limit.');
    $archive=site_backup_open($path,$password,site_backup_limit($pdo));
    try {
        if(isset($_POST['select_sections']) && empty($_POST['sections']))throw new RuntimeException('Select at least one section.');
        try { $plan=site_backup_plan($pdo,$archive,(array)($_POST['sections']??[]),(string)($_POST['conflicts']??'keep')); }
        catch(Throwable $error) {
            if($action==='preview')json_out(['error'=>$error->getMessage(),'groups'=>$archive['manifest']['groups'],'mode'=>$archive['manifest']['mode']],400);
            throw $error;
        }
        if($action==='preview') {
            $token=bin2hex(random_bytes(24));
            $_SESSION['site_backup_preview']=['token'=>$token,'fingerprint'=>$plan['fingerprint'],'expires'=>time()+1800,'actor'=>(int)$actor['id']];
            json_out(['ok'=>true,'summary'=>$plan['summary'],'token'=>$token,'groups'=>$archive['manifest']['groups'],'mode'=>$archive['manifest']['mode']]);
        }
        $preview=$_SESSION['site_backup_preview']??[];
        if(($preview['expires']??0)<time()||($preview['actor']??0)!==(int)$actor['id']||!hash_equals((string)($preview['token']??''),(string)($_POST['token']??'')))throw new RuntimeException('Preview this archive again before applying it.');
        $result=site_backup_apply($pdo,$plan,$password,(string)$preview['fingerprint']);
        unset($_SESSION['site_backup_preview']);json_out($result);
    }finally{$archive['zip']->close();}
}catch(Throwable $error){json_out(['error'=>$error->getMessage()],400);}
