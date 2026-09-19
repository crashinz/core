<?php
declare(strict_types=1);
require_once __DIR__.'/eight_ball_extension.php';

function eight_ball_setup_prefix(array $actor, string $scope): string {
    if($scope==='shared')return 'pool-practice-setup:';
    if($scope!=='mine'||(int)($actor['id']??0)<1)eight_ball_fail('Invalid setup library.');
    return 'pool-practice-user:'.(int)$actor['id'].':';
}
function eight_ball_setup_list(PDO $pdo, string $prefix, bool $withDeleted=false): array {
    $q=$pdo->prepare('SELECT setting_key,value FROM app_settings WHERE setting_key LIKE ? ORDER BY setting_key');$q->execute([$prefix.'%']);
    $out=[];
    foreach($q->fetchAll()as$row){
        $v=json_decode((string)$row['value'],true);
        if(!is_array($v)||!isset($v['name'],$v['balls'],$v['setup'])||(!$withDeleted&&!empty($v['deletedAt'])))continue;
        $v['id']=substr($row['setting_key'],strlen($prefix));$v['revision']=hash('sha256',$row['value']);unset($v['version']);$out[]=$v;
    }
    usort($out,static fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));return $out;
}
function eight_ball_shared_setups(PDO $pdo): array {return eight_ball_setup_list($pdo,'pool-practice-setup:');}
function eight_ball_setup_catalog(PDO $pdo,array $actor): array {
    $admin=($actor['role']??'')==='admin';
    return ['mine'=>eight_ball_setup_list($pdo,eight_ball_setup_prefix($actor,'mine'),true),'setups'=>eight_ball_setup_list($pdo,'pool-practice-setup:',$admin),'canManage'=>$admin];
}
function eight_ball_setup_content(array $body): array {
    $variant=pool_validate_variant($body['variant']??'eight-ball');
    $balls=eight_ball_practice_balls($body['balls']??null);
    if($variant==='nine-ball'&&max(array_column($balls,'n'))>9)eight_ball_fail('A 9-ball setup uses balls 1 through 9.');
    return ['balls'=>array_map(static fn($b)=>array_intersect_key($b,array_flip(['n','x','y'])),$balls),
        'setup'=>eight_ball_practice_setup($body['setup']??[]),'ballRadius'=>15.5,'variant'=>$variant];
}

// First-party examples are portable data, installed once into the ordinary
// shared library. Soft deletes, renamed examples and later edits stay intact.
function eight_ball_install_bundled_setups(PDO $pdo): void {
    eight_ball_install_setup_bundle($pdo,'banks-v1','bank-setups.json',10);
    eight_ball_install_setup_bundle($pdo,'advanced-v1','advanced-setups.json',23);
    eight_ball_install_setup_bundle($pdo,'showcase-v1','showcase-setups.json',13);
}
function eight_ball_install_setup_bundle(PDO $pdo,string $bundle,string $filename,int $expected): void {
    $marker='pool-practice-bundle:'.$bundle;
    if(app_setting($pdo,$marker,'')!=='')return;
    $path=__DIR__.'/../games/eight-ball/'.$filename;
    if(!is_file($path))return;
    $examples=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    if(!is_array($examples)||count($examples)!==$expected)throw new RuntimeException('Incomplete Pool setup bundle.');
    $owns=db_begin_write_transaction($pdo);
    try{
        if(app_setting($pdo,$marker,'')!==''){db_commit_write_transaction($pdo,$owns);return;}
        // The unique marker serializes concurrent first requests on every DB.
        $insert=$pdo->prepare('INSERT INTO app_settings(setting_key,value) VALUES(?,?)');
        $insert->execute([$marker,'1']);
        $existing=eight_ball_setup_list($pdo,'pool-practice-setup:',true);
        $names=array_map(static fn($v)=>mb_strtolower($v['name']),$existing);
        foreach($examples as$example){
            if(!preg_match('/^[a-f0-9]{32}$/D',$example['id']??''))throw new RuntimeException('Invalid bundled Pool setup.');
            $key='pool-practice-setup:'.$example['id'];
            if(app_setting($pdo,$key,'')!==''||in_array(mb_strtolower($example['name']),$names,true))continue;
            $value=['name'=>$example['name']]+eight_ball_setup_content($example);
            $value['version']='bundled-'.$bundle;
            $insert->execute([$key,json_encode($value,JSON_THROW_ON_ERROR)]);
        }
        db_commit_write_transaction($pdo,$owns);
    }catch(Throwable $error){
        db_rollback_write_transaction($pdo,$owns);
        if($owns&&$error instanceof PDOException&&app_setting($pdo,$marker,'')!=='')return;
        throw $error;
    }
}
function eight_ball_setup_change(PDO $pdo,array $actor,array $body): array {
    $scope=$body['scope']??'mine';if(!is_string($scope))eight_ball_fail('Invalid setup library.');
    $prefix=eight_ball_setup_prefix($actor,$scope);
    if($scope==='shared'&&($actor['role']??'')!=='admin')eight_ball_fail('Only administrators can change shared setups.','ADMIN_REQUIRED',403);
    $action=$body['action']??'';if(!in_array($action,['save','import','update','rename','delete','restore'],true))eight_ball_fail('Unknown setup action.');
    if($action==='import'&&$scope!=='mine')eight_ball_fail('Browser setups can only be imported into your account.');
    $name=$body['name']??'';if(!is_string($name))eight_ball_fail('Invalid setup name.');$name=trim($name);
    if(in_array($action,['save','import','update','rename'],true)&&($name===''||mb_strlen($name)>60))eight_ball_fail('Use a setup name of 1 to 60 characters.');
    $owns=db_begin_write_transaction($pdo);
    try{
        $create=in_array($action,['save','import'],true);$raw='';$alreadyImported=false;
        if($create){
            if($action==='import'){
                $legacy=$body['legacyId']??null;if(!is_string($legacy)||!preg_match('/^[a-zA-Z0-9-]{1,80}$/D',$legacy))eight_ball_fail('Invalid browser setup.');
                $id=substr(hash('sha256','browser-v1:'.$legacy),0,32);$raw=app_setting($pdo,$prefix.$id,'');$alreadyImported=$raw!=='';
            }else $id=bin2hex(random_bytes(16));
            if(!$alreadyImported){$value=['name'=>$name]+eight_ball_setup_content($body);if($action==='import')$value['legacyId']=$legacy;}
        }else{
            $id=$body['id']??null;$revision=$body['revision']??null;
            if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)||!is_string($revision))eight_ball_fail('Invalid setup.');
            $raw=app_setting($pdo,$prefix.$id,'');
            if($raw===''||!hash_equals(hash('sha256',$raw),$revision))eight_ball_fail('This setup changed. Reload the list and try again.','SETUP_CONFLICT',409);
            $value=json_decode($raw,true);
            if($action!=='restore'&&!empty($value['deletedAt']))eight_ball_fail('Restore this deleted setup first.','SETUP_CONFLICT',409);
            if($action==='restore')unset($value['deletedAt']);
            elseif($action==='delete')$value['deletedAt']=gmdate('c');
            elseif($action==='rename')$value['name']=$name;
            elseif($action==='update')$value=['name'=>$name]+eight_ball_setup_content($body)+array_intersect_key($value,['legacyId'=>true]);
        }
        if(!$alreadyImported){
            $value['version']=bin2hex(random_bytes(16));$encoded=json_encode($value,JSON_THROW_ON_ERROR);
            if($raw!==''){
                // Compare the exact prior value so concurrent edits cannot silently overwrite one another.
                $q=$pdo->prepare('UPDATE app_settings SET value=? WHERE setting_key=? AND value=?');$q->execute([$encoded,$prefix.$id,$raw]);
                if($q->rowCount()!==1)eight_ball_fail('This setup changed. Reload the list and try again.','SETUP_CONFLICT',409);
            }else{
                $q=$pdo->prepare('INSERT INTO app_settings(setting_key,value) VALUES(?,?)');$q->execute([$prefix.$id,$encoded]);
            }
        }
        db_commit_write_transaction($pdo,$owns);
    }catch(Throwable $error){db_rollback_write_transaction($pdo,$owns);throw $error;}
    return ['id'=>$id,'scope'=>$scope]+eight_ball_setup_catalog($pdo,$actor);
}
// Compatibility for the initial shared-library callers and regression fixtures.
function eight_ball_shared_change(PDO $pdo,array $actor,array $body): array {
    $result=eight_ball_setup_change($pdo,$actor,['scope'=>'shared']+$body);
    $result['setups']=eight_ball_shared_setups($pdo);return $result;
}
