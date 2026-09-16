<?php
declare(strict_types=1);
const BACKGAMMON_BOT_ID = -6602;
const BACKGAMMON_BOT_ENGINE = 'gnubg-95d0ffc-corechat-1';
function backgammon_bot_levels(): array { return ['easy'=>['label'=>'Easy','moveTimeMs'=>1200], 'normal'=>['label'=>'Normal','moveTimeMs'=>1200], 'expert'=>['label'=>'Expert','moveTimeMs'=>1200]]; }
function backgammon_bot_choices(): array { $out=[['value'=>'none','label'=>'None']];foreach(backgammon_bot_levels() as $key=>$v)$out[]=['value'=>$key,'label'=>$v['label']];return $out; }
function backgammon_bot_strength_note(): string { return 'Easy is more forgiving; Normal evaluates complete moves; Expert also considers possible replies. Practice only. These are relative levels, not Elo ratings.'; }
function backgammon_project_virtual_members(PDO $pdo,array $state,array $context):array { return ($context['mode']??'')==='practice'?array_values((array)($state['bots']??[])):[]; }
function backgammon_bot_position_key(array $s):string { return hash('sha256',multiplayer_game_canonical_json(array_intersect_key($s,array_flip(['turnOrder','turnIndex','points','bar','borneOff','remainingDice','dice','backgammonStage','starterMethod','moveUseRule','bots','completed','roundNumber'])))); }
function backgammon_bot_task(array $s,int $viewer,array $c):?array {
 if(($c['mode']??'')!=='practice'||($c['status']??'')!=='active'||!in_array($c['viewerRole']??'',['master','player'],true)||$viewer<=0||!in_array($viewer,$s['turnOrder']??[],true)||empty($s['bots'][(string)BACKGAMMON_BOT_ID])||!empty($s['completed']))return null;
 if(($s['backgammonStage']??'')==='opening-roll'||(int)($s['turnOrder'][(int)($s['turnIndex']??-1)]??0)!==BACKGAMMON_BOT_ID)return null;
 $difficulty=$s['bots'][(string)BACKGAMMON_BOT_ID]['difficulty'];if(!isset(backgammon_bot_levels()[$difficulty]))return null;
 return ['engine'=>BACKGAMMON_BOT_ENGINE,'positionKey'=>backgammon_bot_position_key($s),'actor'=>BACKGAMMON_BOT_ID,'difficulty'=>$difficulty,'moveTimeMs'=>$difficulty==='expert'?1800:1200,'action'=>$s['backgammonStage']==='roll'?'roll':'move','position'=>array_intersect_key($s,array_flip(['turnOrder','points','bar','borneOff','remainingDice','moveUseRule']))];
}
function backgammon_apply_action(array $s,int $actor,string $action,array $payload,array $context):array {
 if($actor<=0||!in_array($actor,$s['turnOrder']??[],true))throw new MultiplayerGameException('Only an authenticated Backgammon participant may act.','BACKGAMMON_PLAYER_INVALID',403);
 if(!empty($s['bots'])&&($context['mode']??'practice')!=='practice')throw new MultiplayerGameException('Games with bots are Practice only.','MULTIPLAYER_GAME_BOTS_PRACTICE_ONLY',422);
 $trace=null;
 if(in_array($action,['bot-step','bot-roll'],true)){
  if(($context['mode']??'')!=='practice'||empty($s['bots'][(string)BACKGAMMON_BOT_ID]))throw new MultiplayerGameException('A Practice Backgammon bot is not available.','BACKGAMMON_BOT_UNAVAILABLE',409);
  if(!hash_equals(backgammon_bot_position_key($s),(string)($payload['positionKey']??'')))throw new MultiplayerGameException('The Backgammon position changed. Refresh before retrying.','BACKGAMMON_BOT_POSITION_STALE',409);
  if(($payload['engine']??'')!==BACKGAMMON_BOT_ENGINE)throw new MultiplayerGameException('The Backgammon bot was updated. Reload the game.','BACKGAMMON_BOT_ENGINE_MISMATCH',409);
  $actor=BACKGAMMON_BOT_ID;ocx_game_assert_turn($s,$actor);
  $action=$action==='bot-roll'?'roll':'move';
  $trace=['reason'=>$action==='roll'?'framework-verified-bot-roll':'browser-gnubg-proposal-server-validated','engine'=>BACKGAMMON_BOT_ENGINE,'difficulty'=>$s['bots'][(string)$actor]['difficulty'],'elapsedMs'=>max(0,min(60000,(int)($payload['elapsedMs']??0))),'publicObservation'=>['starterMethod'=>$s['starterMethod'],'moveUseRule'=>$s['moveUseRule'],'replyRolls'=>(int)($payload['replyRolls']??0),'positionKey'=>backgammon_bot_position_key($s)]];
  if($action==='move'&&(!array_key_exists('from',$payload)||(!is_int($payload['from'])&&$payload['from']!=='bar')||!is_int($payload['die']??null)))throw new MultiplayerGameException('Choose a valid checker and die.','BACKGAMMON_MOVE_INVALID',422);
  $payload=$action==='roll'?[]:['from'=>$payload['from'],'die'=>$payload['die']];$trace['selected']=$payload;
 }
 $r=backgammon_apply_action_core($s,$actor,$action,$payload,$context);
 if(function_exists('game_recording_observe'))game_recording_observe($context,'backgammon',$s,$actor,$action,$payload,$r['state'],$trace);
 return $r;
}
