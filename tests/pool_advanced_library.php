<?php
declare(strict_types=1);
class MultiplayerGameException extends Exception {}
function app_setting(PDO $pdo,string $key,string $fallback=''): string {$q=$pdo->prepare('SELECT value FROM app_settings WHERE setting_key=?');$q->execute([$key]);$v=$q->fetchColumn();return $v===false?$fallback:(string)$v;}
function db_begin_write_transaction(PDO $pdo): bool {if($pdo->inTransaction())return false;$pdo->beginTransaction();return true;}
function db_commit_write_transaction(PDO $pdo,bool $owns): void {if($owns)$pdo->commit();}
function db_rollback_write_transaction(PDO $pdo,bool $owns): void {if($owns&&$pdo->inTransaction())$pdo->rollBack();}
require __DIR__.'/../includes/eight_ball_practice_library.php';
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE app_settings(setting_key TEXT PRIMARY KEY,value TEXT NOT NULL)');
function ck(bool $value,string $name): void {if(!$value)throw new Exception($name);}
eight_ball_install_bundled_setups($pdo);$all=eight_ball_shared_setups($pdo);ck(count($all)===46,'10 banks plus 36 advanced examples');
foreach($all as$f){ck(mb_strlen($f['name'])<=60,'Name limit');eight_ball_setup_content($f);}
$admin=['id'=>1,'role'=>'admin'];$one=$all[0];eight_ball_setup_change($pdo,$admin,['scope'=>'shared','action'=>'delete','id'=>$one['id'],'revision'=>$one['revision']]);
$two=$all[1];eight_ball_setup_change($pdo,$admin,['scope'=>'shared','action'=>'rename','id'=>$two['id'],'revision'=>$two['revision'],'name'=>'My custom name']);
eight_ball_install_bundled_setups($pdo);$after=eight_ball_setup_list($pdo,'pool-practice-setup:',true);ck(count($after)===46,'No duplicate or resurrection');
ck(count(array_filter($after,fn($x)=>!empty($x['deletedAt'])))===1,'Soft deletion preserved');ck(count(array_filter($after,fn($x)=>$x['name']==='My custom name'))===1,'Owner rename preserved');
ck(count(eight_ball_shared_setups($pdo))===45,'Deleted entry hidden');
// Upgrade from banks-only must add the new bundle, keeping the old marker/data.
$pdo->exec("DELETE FROM app_settings WHERE setting_key='pool-practice-bundle:advanced-v1'");eight_ball_install_bundled_setups($pdo);ck(count(eight_ball_setup_list($pdo,'pool-practice-setup:',true))===46,'Marker repair does not overwrite existing entries');
// New bundle marker repair also preserves all existing edits and deletions.
$pdo->exec("DELETE FROM app_settings WHERE setting_key='pool-practice-bundle:showcase-v1'");eight_ball_install_bundled_setups($pdo);ck(count(eight_ball_setup_list($pdo,'pool-practice-setup:',true))===46,'Showcase marker repair preserves entries');
echo "PASS 46 validated bundled layouts and preservation checks\n";
