<?php
declare(strict_types=1);

const PUPPY_PANIC_BOT_ENGINE = 'corechat-puppy-panic-1';
function puppy_panic_bot_choices(): array {
    return array_map(static fn($v)=>['value'=>$v,'label'=>ucfirst($v)],['none','easy','normal','expert']);
}
function puppy_panic_bot_fill_seats(array $humans,array $settings,string $mode,array $seats): array {
    if(!$seats)foreach($humans as$i=>$id)$seats[$i+1]=$id;
    $players=[];$bots=[];
    for($seat=1;$seat<=5;$seat++){
        if(isset($seats[$seat])&&in_array((int)$seats[$seat],$humans,true)){$players[]=(int)$seats[$seat];continue;}
        $level=$settings['botSeat'.$seat.'Difficulty']??'none';
        if($mode!=='practice'||$level==='none')continue;
        $id=-7300-$seat;$players[]=$id;$bots[(string)$id]=['userId'=>$id,'seat'=>$seat,'difficulty'=>$level,'displayName'=>ucfirst($level).' Bot '.$seat,'engine'=>PUPPY_PANIC_BOT_ENGINE];
    }
    return [$players,$bots];
}
function puppy_panic_project_virtual_members(PDO $pdo,array $s,array $c): array {return ($c['mode']??'')==='practice'?array_values($s['bots']??[]):[];}
function puppy_panic_bot_position_key(array $s): string {
    return hash('sha256',multiplayer_game_canonical_json(array_intersect_key($s,array_flip(['turnOrder','turnIndex','phase','actionSequence','botSequence','completed']))));
}
function puppy_panic_bot_turn_actor(array $s): int {
    return (int)(($s['phase']==='favor'?$s['pendingChoice']['targetUserId']:null)??puppy_panic_turn_user($s));
}
/** Only the same information available to this player. Never hand arrays or actual draw-pile order. */
function puppy_panic_bot_observation(array $s,int $actor,array $c=[]): array {
    $p=puppy_panic_project_state_core($s,$actor,['turnUserId'=>puppy_panic_bot_turn_actor($s)]+$c);
    return ['actor'=>$actor,'difficulty'=>$s['bots'][(string)$actor]['difficulty']??'normal']+array_intersect_key($p,array_flip(['settings','turnOrder','turnIndex','phase','hand','cardCounts','discardPile','drawPile','eliminated','owedTurns','pendingAction','pendingChoice','privatePeek','legalActions','actionSequence']));
}
function puppy_panic_bot_card_value(array $o,string $id): float {
    $c=puppy_panic_card($o,$id);
    return match($c['effect']){'calm'=>30,'pile-on','target-attack'=>8,'counter'=>6,'nap'=>6,'reorder'=>5,'bottom-draw'=>4,'shuffle'=>3,'flip'=>2,'favor'=>3,'peek'=>2,default=>1};
}
/** First-party survival heuristics; no private-state sampling or shared opponent hand knowledge. */
function puppy_panic_bot_choose(array $o): ?array {
    $pick=static fn($a,$p=[],$r='survival-policy')=>['action'=>$a,'payload'=>$p,'reason'=>$r];
    $actor=$o['actor'];$phase=$o['phase'];$hand=$o['hand'];$level=$o['difficulty'];$expert=$level==='expert';$easy=$level==='easy';
    $by=[];$families=[];$calm=[];
    foreach($hand as$id){$c=puppy_panic_card($o,$id);$by[$c['effect']][]=$id;if($c['kind']==='calm')$calm[]=$id;if($c['kind']==='matching')$families[$c['title']][]=$id;}
    $active=array_values(array_filter($o['turnOrder'],fn($id)=>empty($o['eliminated'][(string)$id])));
    $others=array_values(array_filter($active,fn($id)=>$id!==$actor));
    $targets=array_values(array_filter($others,fn($id)=>($o['cardCounts'][(string)$id]??0)>0));
    usort($targets,fn($a,$b)=>($o['cardCounts'][(string)$a]<=>$o['cardCounts'][(string)$b])?:($a<=>$b));
    $next=$others[0]??$actor;$index=array_search($actor,$active,true);if($index!==false)$next=$active[($index+1)%count($active)];
    $n=(int)$o['drawPile']['count'];$peek=$o['privatePeek']['cards']??[];
    $risk=$n>0?min(1,(count($active)-1)/$n):1;
    if($peek)$risk=puppy_panic_card($o,$peek[0])['kind']==='chaos'?1:0;
    $play=static fn($effect,$extra=[])=>$pick('play',['card'=>$by[$effect][0]]+$extra,'survival-'.$effect);
    if($phase==='deal')return $pick('deal',[],'verified-deal');
    if($phase==='chaos'){
        if(!$calm)return $pick('eliminate',[],'no-calm-available');
        $position=$easy?'middle':min($n,max(0,(int)$o['owedTurns']-1));
        return $pick('calm',['card'=>$calm[0],'position'=>$position],'survive-and-avoid-own-turn-debt');
    }
    if($phase==='favor'){
        if(!$hand)return null;
        usort($hand,fn($a,$b)=>(puppy_panic_bot_card_value($o,$a)<=>puppy_panic_bot_card_value($o,$b))?:strcmp($a,$b));
        return $pick('give-card',['card'=>$easy?$o['hand'][0]:$hand[0]],'give-least-useful-card');
    }
    if($phase==='reorder'){
        $cards=$o['pendingChoice']['cards'];
        if(!$easy)usort($cards,fn($a,$b)=>(int)(puppy_panic_card($o,$a)['kind']==='chaos')<=>(int)(puppy_panic_card($o,$b)['kind']==='chaos'));
        if($expert){
            // Protect all owed draws in the visible prefix, then put danger on the next player.
            $safe=array_values(array_filter($cards,fn($id)=>puppy_panic_card($o,$id)['kind']!=='chaos'));$bad=array_values(array_diff($cards,$safe));$own=min(count($safe),max(1,(int)$o['owedTurns']));
            $cards=array_merge(array_slice($safe,0,$own),$bad,array_slice($safe,$own));
        }
        return $pick('reorder',['cards'=>$cards],'protect-visible-required-draws');
    }
    if($phase==='pending-action'){
        $p=$o['pendingAction'];$owner=(int)$p['actorUserId'];$even=(int)$p['counterDepth']%2===0;
        $victim=(int)($p['targetUserId']??0);
        if($p['effect']==='pile-on'){$ix=array_search($owner,$active,true);$victim=$active[($ix+1)%count($active)];}
        $harm=$victim===$actor&&in_array($p['effect'],['pile-on','target-attack','favor','pair-steal','trio-request'],true);
        $restore=$owner===$actor&&!$even;
        if(!$easy&&!empty($by['counter'])&&(($even&&$harm)||$restore))return $pick('counter',['card'=>$by['counter'][0]],'counter-harm-or-restore-own-action');
        foreach(['settle-random','settle-action']as$a)if(in_array($a,$o['legalActions'],true))return $pick($a,[],'resolve-after-counter-window');
        return null;
    }
    if($phase!=='playing')return null;
    if($easy)return $pick('draw',[],'simple-draw-and-calm');
    // Build resources without spending Calm Down or counters in combinations.
    if($targets){
        foreach($families as$ids)if(count($ids)>=2)return $pick('combo',['cards'=>array_slice($ids,0,2),'targetUserId'=>$expert?$targets[0]:$targets[count($targets)-1]],'matching-pair-resource');
        if(!empty($by['favor']))return $play('favor',['targetUserId'=>$targets[0]]);
    }
    $danger=$risk>=($calm?($expert?.48:.75):($expert?.16:.4))||(int)$o['owedTurns']>1;
    if($danger){
        if(!empty($by['target-attack']))return $play('target-attack',['targetUserId'=>$targets[0]??$next]);
        if(!empty($by['pile-on']))return $play('pile-on');
        if(!empty($by['nap']))return $play('nap');
        if($risk>=1){
            foreach(['bottom-draw','flip','shuffle']as$effect)if(!empty($by[$effect]))return $play($effect);
        }
        if(!$peek){foreach(['reorder','peek']as$effect)if(!empty($by[$effect]))return $play($effect);}
        if($expert&&!$calm&&$risk>=.3){
            foreach(['bottom-draw','shuffle']as$effect)if(!empty($by[$effect]))return $play($effect);
        }
    }
    return $pick('draw',[],'draw-with-public-risk-estimate');
}
/** All players see the same opaque task; eliminated humans can still coordinate surviving bots. */
function puppy_panic_bot_actor(array $s,array $c): int {
    if(!empty($s['completed']))return 0;
    if($s['phase']==='pending-action'){
        foreach(puppy_panic_active_users($s)as$id)if(isset($s['bots'][(string)$id])){
            $choice=puppy_panic_bot_choose(puppy_panic_bot_observation($s,$id,$c));
            if(($choice['action']??'')==='counter')return $id;
        }
        $owner=(int)$s['pendingAction']['actorUserId'];return isset($s['bots'][(string)$owner])?$owner:0;
    }
    $id=puppy_panic_bot_turn_actor($s);return isset($s['bots'][(string)$id])?$id:0;
}
function puppy_panic_bot_task(array $s,int $viewer,array $c): ?array {
    $c['nowUnixMs']??=(int)floor(microtime(true)*1000);
    if(($c['mode']??'')!=='practice'||($c['status']??'')!=='active'||$viewer<=0||!in_array($viewer,$s['turnOrder']??[],true)||!in_array($c['viewerRole']??'',['master','player'],true))return null;
    $actor=puppy_panic_bot_actor($s,$c);if(!$actor)return null;
    $choice=puppy_panic_bot_choose(puppy_panic_bot_observation($s,$actor,$c));$delay=2000;$action='bot-step';
    if($s['phase']==='deal')$action='bot-deal';
    if($s['phase']==='pending-action'&&($choice['action']??'')!=='counter'){
        $action=!empty($s['pendingAction']['requiresRandomness'])?'bot-settle-random':'bot-settle';
        $delay=max(2000,(int)$s['pendingAction']['settleAfterUnixMs']-(int)($c['nowUnixMs']??0)+150);
    }
    return ['actor'=>$actor,'engine'=>PUPPY_PANIC_BOT_ENGINE,'positionKey'=>puppy_panic_bot_position_key($s),'action'=>$action,'delayMs'=>$delay,'displayName'=>$s['bots'][(string)$actor]['displayName']];
}
function puppy_panic_project_state(array $s,int $viewer,array $c): array {
    $c['nowUnixMs']??=(int)floor(microtime(true)*1000);
    $p=puppy_panic_project_state_core($s,$viewer,$c);$p['botTask']=puppy_panic_bot_task($s,$viewer,$c);
    if($p['botTask']&&$s['phase']==='pending-action')$p['legalActions']=array_values(array_diff($p['legalActions'],['settle-action','settle-random']));
    return $p;
}
function puppy_panic_apply_action(array $s,int $actor,string $action,array $payload,array $c): array {
    if($actor<=0||!in_array($actor,$s['turnOrder']??[],true))throw new MultiplayerGameException('Only an authenticated participant may act.','PUPPY_PANIC_PLAYER_INVALID',403);
    if(!empty($s['bots'])&&($c['mode']??'')!=='practice')throw new MultiplayerGameException('Games with bots are Practice only.','MULTIPLAYER_GAME_BOTS_PRACTICE_ONLY',422);
    $trace=null;
    if(str_starts_with($action,'bot-')){
        $task=puppy_panic_bot_task($s,$actor,['status'=>'active','viewerRole'=>'player']+$c);
        if(!$task)throw new MultiplayerGameException('No bot action is available.','PUPPY_PANIC_BOT_UNAVAILABLE',409);
        if(($payload['engine']??'')!==PUPPY_PANIC_BOT_ENGINE)throw new MultiplayerGameException('The bot changed. Reload the game.','PUPPY_PANIC_BOT_ENGINE_MISMATCH',409);
        if(!hash_equals($task['positionKey'],(string)($payload['positionKey']??'')))throw new MultiplayerGameException('The position changed. Refresh before retrying.','PUPPY_PANIC_BOT_POSITION_STALE',409);
        if($action!==$task['action'])throw new MultiplayerGameException('The bot action changed.','PUPPY_PANIC_BOT_ACTION_INVALID',409);
        $actor=$task['actor'];$o=puppy_panic_bot_observation($s,$actor,$c);$start=hrtime(true);$choice=puppy_panic_bot_choose($o);
        if(!$choice||!in_array($choice['action'],$o['legalActions'],true))throw new MultiplayerGameException('Wait for the counter window.','PUPPY_PANIC_COUNTER_OPEN',409);
        $action=$choice['action'];$payload=$choice['payload'];$trace=['engine'=>PUPPY_PANIC_BOT_ENGINE,'difficulty'=>$o['difficulty'],'reason'=>$choice['reason'],'legal'=>$o['legalActions'],'selected'=>$choice,'publicObservation'=>$o,'elapsedMs'=>(hrtime(true)-$start)/1000000];
    }elseif(in_array($action,['settle-action','settle-random'],true)&&puppy_panic_bot_actor($s,$c)){
        throw new MultiplayerGameException('A bot response is pending.','PUPPY_PANIC_BOT_RESPONSE_PENDING',409);
    }
    $r=puppy_panic_apply_action_core($s,$actor,$action,$payload,$c);
    if(function_exists('game_recording_observe'))game_recording_observe($c,'puppy-panic',$s,$actor,$action,$payload,$r['state'],$trace);
    return $r;
}
function puppy_panic_apply_action_core(array $s,int $actor,string $action,array $payload,array $c): array {
    $r=puppy_panic_apply_action_rules($s,$actor,$action,$payload,$c);$r['state']['botSequence']=(int)($s['botSequence']??0)+1;return $r;
}
function puppy_panic_recording_adapter(): array {
    return ['schemaVersion'=>1,'stateKeys'=>['schemaVersion','settings','turnOrder','turnIndex','phase','hands','drawPile','discardPile','eliminated','owedTurns','pendingAction','pendingChoice','privatePeek','actionSequence','history','lastAction','starterUserId','starterReason','completed','winnerUserId','terminalReason','bots','botSequence'],'payloadKeys'=>['card','cards','targetUserId','requestedTitle','retrieveCardId','position']];
}
