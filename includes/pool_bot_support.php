<?php
declare(strict_types=1);
const POOL_BOT_ID = -7102;
const POOL_BOT_ENGINE = 'corechat-pool-search-1';

function pool_bot_choices(): array {return [['value'=>'none','label'=>'None'],['value'=>'easy','label'=>'Easy'],['value'=>'normal','label'=>'Normal'],['value'=>'expert','label'=>'Expert']];}
function pool_bot_enabled(array $settings): bool {return ($settings['botSeat2Difficulty']??'none')!=='none';}
function pool_project_virtual_members(PDO $pdo,array $state,array $context): array {return ($context['mode']??'')==='practice'?array_values($state['bots']??[]):[];}
function pool_bot_key(array $s): string {
    return hash('sha256',multiplayer_game_canonical_json(array_intersect_key($s,array_flip(['sequence','turnOrder','turnIndex','phase','balls','settings','bots','completed','botPendingShot']))));
}
function pool_bot_task(array $s,int $viewer,array $c): ?array {
    if(($c['mode']??'')!=='practice'||($c['status']??'')!=='active'||!in_array($c['viewerRole']??'',['master','player'],true)||$viewer<=0||!in_array($viewer,$s['turnOrder']??[],true)||empty($s['bots'][POOL_BOT_ID])||!empty($s['completed'])||eight_ball_turn($s)!==POOL_BOT_ID)return null;
    $now=isset($c['nowUnixMs'])?(float)$c['nowUnixMs']/1000:microtime(true);
    if(pool_clock_expired($s,$now))return null;
    $remaining=isset($s['turnClock'])?max(0,(int)(($s['turnClock']['expiresAt']-$now)*1000)):null;
    return ['remainingMs'=>$remaining,'searchBudgetMs'=>$remaining===null?30000:max(100,min(30000,$remaining-6500)),'engine'=>POOL_BOT_ENGINE,'positionKey'=>pool_bot_key($s),'actor'=>POOL_BOT_ID,'difficulty'=>$s['bots'][POOL_BOT_ID]['difficulty'],
        'presentationDelayMs'=>2000,'waitForMotionMs'=>max(0,(int)ceil(((float)($s['animationUntil']??0)-$now)*1000)),
        'pendingShot'=>$s['botPendingShot']??null,
        'position'=>array_intersect_key($s,array_flip(['balls','ballRadius','settings','turnOrder','turnIndex','groups','phase','placement','breakShot','headStringRequired','choice','foulCounts','pushOutAvailable','pushOutDeclared','stalemateRequests']))];
}
/** Positive authenticated participant submits a proposal, never an outcome. */
function eight_ball_apply_action(array $s,int $actor,string $action,array $p,array $c): array {
    if($actor<=0||!in_array($actor,$s['turnOrder']??[],true))eight_ball_fail('Only an authenticated participant may act.','PLAYER_INVALID',403);
    if(!empty($s['bots'])&&($c['mode']??'')!=='practice')eight_ball_fail('Bot games are Practice only.','PRACTICE_ONLY',422);
    $before=$s;$trace=null;$clockNow=pool_clock_now($c);
    if($action==='practice-opponent'){
        $humans=array_values(array_filter($s['turnOrder'],static fn($id)=>$id>0));
        $difficulty=$p['difficulty']??'';
        if(($c['mode']??'')!=='practice'||$humans!==[$actor]||!in_array($difficulty,['none','easy','normal','expert'],true))eight_ball_fail('Only the player at a one-person Practice table may change its opponent.','PRACTICE_ONLY',403);
        if($clockNow<(float)($s['animationUntil']??0))eight_ball_fail('Wait for the shot to finish.','IN_MOTION',409);
        $settings=array_replace($s['settings'],['tableMode'=>$difficulty==='none'?'solo':'match','botSeat2Difficulty'=>$difficulty]);
        $next=eight_ball_initial_state([$actor],array_replace($c,['settings'=>$settings]));
        $next['sequence']=($s['sequence']??0)+1;
        $next['cues'][$actor]=$s['cues'][$actor]??6;
        if(isset($s['_framework']))$next['_framework']=$s['_framework'];
        $r=eight_ball_result($next);
        if(function_exists('game_recording_observe'))game_recording_observe($c,'eight-ball',$before,$actor,$action,$p,$r['state'],null);
        return $r;
    }
    if($action==='timeout'&&(!is_string($p['clockId']??null)||($s['turnClock']['id']??null)!==$p['clockId']))eight_ball_fail('The turn has changed.','CLOCK_STALE',409);
    if($action!=='resign'&&pool_clock_expired($s,$clockNow)){
        $r=pool_clock_timeout($s,$clockNow);
        if(function_exists('game_recording_observe'))game_recording_observe($c,'eight-ball',$before,eight_ball_turn($before),'timeout',[],$r['state'],null);
        return $r;
    }
    if($action==='timeout')eight_ball_fail('The turn has not expired.','CLOCK_EARLY',409);
    if(in_array($action,['bot-step','bot-rack'],true)){
        if(($c['mode']??'')!=='practice'||empty($s['bots'][POOL_BOT_ID])||!empty($s['completed'])||eight_ball_turn($s)!==POOL_BOT_ID)eight_ball_fail('A Practice bot turn is not available.','BOT_UNAVAILABLE',409);
        if(($p['engine']??'')!==POOL_BOT_ENGINE||!is_string($p['positionKey']??null)||!hash_equals(pool_bot_key($s),$p['positionKey']))eight_ball_fail('The bot position changed. Refresh before retrying.','BOT_STALE',409);
        $now=isset($c['nowUnixMs'])?(float)$c['nowUnixMs']/1000:microtime(true);
        if($now<(float)($s['animationUntil']??0)||$now<(float)($s['botReadyAt']??0))eight_ball_fail('Wait for the shot animation and aiming to finish.','IN_MOTION',409);
        $actor=POOL_BOT_ID;$requested=$p['action']??'';
        if(!in_array($requested,['rack','place','choice','shot','stalemate'],true)||(($action==='bot-rack')!==($requested==='rack')))eight_ball_fail('Invalid bot action.','BOT_ACTION_INVALID');
        $trace=['engine'=>POOL_BOT_ENGINE,'difficulty'=>$s['bots'][POOL_BOT_ID]['difficulty'],'reason'=>substr(is_string($p['reason']??null)?$p['reason']:'public-board-search',0,48),'elapsedMs'=>max(0,min(60000,is_numeric($p['elapsedMs']??null)?(float)$p['elapsedMs']:0)),'simulations'=>max(0,min(5000,is_numeric($p['simulations']??null)?(int)$p['simulations']:0))];
        $action=$requested;$p=is_array($p['payload']??null)?$p['payload']:[];
        if($action==='shot'){
            if($s['phase']!=='aim')eight_ball_fail('No shot is available.');
            if(!empty($s['botPendingShot'])){
                $p=$s['botPendingShot'];unset($s['botPendingShot'],$s['botReadyAt'],$s['botAim']);
            }else{
                $plan=['angle'=>eight_ball_numeric($p,'angle',-100,100),'power'=>eight_ball_numeric($p,'power',5,100),'spinX'=>eight_ball_numeric($p,'spinX',-1,1),'spinY'=>eight_ball_numeric($p,'spinY',-1,1),'calledBall'=>(int)($p['calledBall']??0),'calledPocket'=>(int)($p['calledPocket']??-1),'safety'=>!empty($p['safety'])];
                if(hypot($plan['spinX'],$plan['spinY'])>1.000001)eight_ball_fail('Spin must stay inside the cue ball.');
                $calls=eight_ball_call_rule($s);
                if(!$s['breakShot']&&($calls==='all'||$calls==='eight'&&$plan['calledBall']===8))$s['calledShot']=eight_ball_validate_call($s,$actor,$plan);
                else $s['calledShot']=null;
                $s['botPendingShot']=$plan;$s['botReadyAt']=$now+1.5;$s['botAim']=['actor'=>$actor]+$plan;
                $s['sequence']++;$s['statusText']=$s['bots'][$actor]['displayName'].' is lining up the shot.';
                $r=eight_ball_result($s);$trace['selected']=$plan;
                if(function_exists('game_recording_observe'))game_recording_observe($c,'eight-ball',$before,$actor,'bot-plan',$plan,$s,$trace);
                return $r;
            }
        }elseif(!empty($s['botPendingShot']))eight_ball_fail('Finish the announced bot shot first.','BOT_ACTION_INVALID');
    }
    $r=eight_ball_apply_action_core($s,$actor,$action,$p,$c);
    pool_clock_after($before,$r['state'],$action,$clockNow);
    if(function_exists('game_recording_observe'))game_recording_observe($c,'eight-ball',$before,$actor,$action,$p,$r['state'],$trace);
    return $r;
}
