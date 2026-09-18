<?php
define('CHATSPACE_DB_DRIVER','sqlite');define('CHATSPACE_SQLITE_PATH',':memory:');define('CHATSPACE_PRIVATE_STORAGE_PATH',sys_get_temp_dir().'/corechat-dominos-test-'.bin2hex(random_bytes(6)));
require dirname(__DIR__).'/includes/base.php';
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$pdo->exec('PRAGMA foreign_keys=ON');database_migrations_install_clean($pdo);
for($i=1;$i<=4;$i++)$pdo->prepare('INSERT INTO users(email,username,password_hash,display_name,role) VALUES(?,?,?,?,?)')->execute(['dominos'.$i.'@example.invalid','dominos'.$i,'unusable','Tester '.$i,'user']);
$pdo->prepare('INSERT INTO rooms(public_id,owner_id,name) VALUES(?,?,?)')->execute([uuid_v4(),1,'Dominos framework fixture']);$room=$pdo->lastInsertId();
$pdo->prepare('INSERT INTO room_sessions(public_id,room_id) VALUES(?,?)')->execute([uuid_v4(),$room]);$rs=(int)$pdo->lastInsertId();
$players=[];for($i=1;$i<=4;$i++){$pdo->prepare('INSERT INTO participants(session_id,user_id,display_name,join_token) VALUES(?,?,?,?)')->execute([$rs,$i,'Tester '.$i,bin2hex(random_bytes(16))]);$players[$i]=['id'=>(int)$pdo->lastInsertId(),'user_id'=>$i];}
$checks=0;function ck($ok,$why){global $checks;if(!$ok)throw new Exception($why);$checks++;}
$botLevel=$argv[1]??'normal';if(!in_array($botLevel,['normal','expert'],true))throw new Exception('Unknown test difficulty');
function row($id){global $pdo;$q=$pdo->prepare('SELECT * FROM multiplayer_game_sessions WHERE public_id=?');$q->execute([$id]);return $q->fetch();}
function state($id){return json_decode(row($id)['state_json'],true);}


function act($id,$action,$payload=[],$actor=1){global $pdo;$r=row($id);$random='';
 if(in_array($action,['deal','bot-deal','settle-random','bot-settle-random'],true)){$random=uuid_v4();$reveal=['seed'=>$random];multiplayer_game_randomness($pdo,$id,$actor,$random,$r['mode']==='practice'?strtoupper(hash('sha256',multiplayer_game_canonical_json($reveal))):null,'dominos-deal');if($r['mode']==='practice')multiplayer_game_reveal_practice_randomness($pdo,$id,$actor,$random,$reveal);}
 $request=uuid_v4();$result=multiplayer_game_extension_action($pdo,$id,$actor,$request,(int)$r['state_version'],$action,$payload,$random);
 static $retried=false;if(!$retried&&in_array($action,['play','bot-step'],true)){$retried=true;$after=state($id);multiplayer_game_extension_action($pdo,$id,$actor,$request,(int)$r['state_version'],$action,$payload,$random);ck(state($id)===$after,'Same request is idempotent');rejects(fn()=>multiplayer_game_extension_action($pdo,$id,$actor,uuid_v4(),(int)$r['state_version'],$action,$payload),'MULTIPLAYER_GAME_STATE_STALE');}
 return $result;
}

$matches=[];
foreach([[2,false],[3,false],[4,false],[4,true]]as[$n,$teams]){
 $s=multiplayer_game_create_session($pdo,$rs,$players[1],'g_dominos01','recorded',['tableMode'=>$teams?'teams':'individual','winningScore'=>50],uuid_v4());$id=$s['publicId'];
 ck(count($s['botSeats']['options'])===4,'four optional empty slots');
 for($seat=2;$seat<=$n;$seat++){$r=row($id);$ps=multiplayer_game_player_set($pdo,(int)$r['id']);$s=multiplayer_game_set_lobby_bot($pdo,$id,1,$seat,$botLevel,$r['settings_sha256'],$ps['sha256']);ck($s['mode']==='practice','Practice conversion');}
 $s=multiplayer_game_accept_settings($pdo,$id,1,$s['settingsSha256'],'practice');$s=multiplayer_game_start_session($pdo,$id,1);
 for($i=0;$i<1000&&!state($id)['completed'];$i++){
  $a=state($id);$actor=dominos_turn_user($a);$c=dominos_bot_choose(dominos_bot_observation($a,$actor));$action=$c['action'];$payload=$c['payload'];
  if($actor<0){$t=dominos_bot_task($a,1,['mode'=>'practice','status'=>'active','viewerRole'=>'master']);$action=$t['action'];$payload=['engine'=>$t['engine'],'positionKey'=>$t['positionKey']];}
  act($id,$action,$payload);
  if($i===5){$r=row($id);$before=state($id);$saved=multiplayer_game_save($pdo,$id,1,(int)$r['state_version']);ck($saved['saved'],'Save');multiplayer_game_resume($pdo,$id,1);multiplayer_game_restore_saved_game($pdo,$id,1,(int)$r['state_version']);$after=state($id);ck(multiplayer_game_canonical_json(game_recording_state('dominos',array_diff_key($before,['_framework'=>1])))===multiplayer_game_canonical_json(game_recording_state('dominos',array_diff_key($after,['_framework'=>1]))),'Save restored rules and starter cycle');}
 }
 ck(state($id)['completed'],'Full match completed');
 for($flush=0;$flush<50;$flush++)game_recording_flush($pdo,64);
 $q=$pdo->prepare('SELECT * FROM multiplayer_game_recordings WHERE session_public_id=?');$q->execute([$id]);$record=$q->fetch();ck(is_array($record)&&(int)$record['gap_count']===0,'Gap free recording');game_recording_verify_archive($record);$steps=0;
 foreach(game_recording_archive_lines($record)as$line){$event=json_decode($line,true);foreach($event['steps']as$step){$steps++;$replay=dominos_apply_action_core($step['before'],$step['actorId'],$step['action'],$step['payload'],['mode'=>'practice','authoritativeRandomness'=>$step['randomness']])['state'];ck(multiplayer_game_canonical_json(game_recording_state('dominos',$replay))===multiplayer_game_canonical_json($step['after']),'Recorded action replay');}}
 $rematch=multiplayer_game_request_rematch($pdo,$id,1);ck($rematch['status']==='started','Rematch starts');$new=state($rematch['session']['publicId']);ck(count($new['bots'])===$n-1&&$new['phase']==='deal'&&$new['sequence']===0,'Rematch fresh state and retained bots');
 foreach($new['bots'] as $bot)ck($bot['difficulty']===$botLevel,'Rematch keeps difficulty');
 if($botLevel==='expert')ck(($new['botKnowledge']??null)===[],'Expert rematch starts with fresh knowledge');
 $matches[]=['players'=>$n,'teams'=>$teams,'difficulty'=>$botLevel,'actions'=>$i,'replays'=>$steps];
}

function rejects(callable $fn,string $code){try{$fn();}catch(MultiplayerGameException $e){ck($e->errorCode===$code,'Rejection '.$e->errorCode.' expected '.$code);return;}throw new Exception('Expected '.$code);}
foreach([[2,false],[3,false],[4,false],[4,true]]as[$n,$teams]){
 $s=multiplayer_game_create_session($pdo,$rs,$players[1],'g_dominos01','recorded',['tableMode'=>$teams?'teams':'individual','winningScore'=>50],uuid_v4());$id=$s['publicId'];
 for($u=2;$u<=$n;$u++)$s=multiplayer_game_join_session($pdo,$id,$players[$u],'player',uuid_v4());
 if($n===3){$s=multiplayer_game_choose_seat($pdo,$id,3,4);ck($s['seating']['currentSeat']===4,'Nonconsecutive seat');}
 ck(count($s['seating']['options'])===4,'Four physical seats');
 for($u=1;$u<=$n;$u++)$s=multiplayer_game_accept_settings($pdo,$id,$u,$s['settingsSha256']);
 $s=multiplayer_game_start_session($pdo,$id,1);ck(count(state($id)['bots'])===0,'Recorded no bots');
 for($i=0;$i<1000&&!state($id)['completed'];$i++){$st=state($id);$actor=dominos_turn_user($st);$choice=dominos_bot_choose(dominos_bot_observation($st,$actor));act($id,$choice['action'],$choice['payload'],$actor);}
 ck(state($id)['completed'],'Human Recorded match completed');
 $q=$pdo->prepare('SELECT COUNT(*) FROM multiplayer_game_result_pairs WHERE result_id IN (SELECT id FROM multiplayer_game_results WHERE game_session_public_id=?)');$q->execute([$id]);$pairs=(int)$q->fetchColumn();ck($pairs===($teams?8:2*(count(state($id)['winners']))*($n-count(state($id)['winners']))),'Result pairs '.$pairs.' n='.$n.' teams='.(int)$teams.' mode='.row($id)['mode']);
 if($teams){$q=$pdo->prepare('SELECT outcome,COUNT(*) AS n FROM multiplayer_game_result_pairs WHERE result_id IN (SELECT id FROM multiplayer_game_results WHERE game_session_public_id=?) GROUP BY outcome');$q->execute([$id]);$results=$q->fetchAll();ck(array_sum(array_column($results,'n'))===8,'Team results persisted');}
 $matches[]=['players'=>$n,'teams'=>$teams,'mode'=>'recorded','actions'=>$i];
}
$st=dominos_initial_state([1],['mode'=>'practice','settings'=>['botSeat2Difficulty'=>'normal']]);$st['turnIndex']=1;$c=['mode'=>'practice'];$key=dominos_bot_key($st);
rejects(fn()=>dominos_apply_action($st,2,'bot-deal',['engine'=>DOMINOS_BOT_ENGINE,'positionKey'=>$key],$c),'DOMINOS_PLAYER_INVALID');
rejects(fn()=>dominos_apply_action($st,1,'bot-deal',['engine'=>DOMINOS_BOT_ENGINE,'positionKey'=>'stale'],$c),'DOMINOS_BOT_STALE');
rejects(fn()=>dominos_apply_action($st,1,'play',['tile'=>'6-6','end'=>'start'],$c),'DOMINOS_TURN_INVALID');
ck(dominos_bot_task($st,1,['mode'=>'practice','status'=>'active','viewerRole'=>'spectator'])===null,'Spectator no bot task');
echo json_encode(['status'=>'PASS','checks'=>$checks,'matches'=>$matches],JSON_PRETTY_PRINT);

