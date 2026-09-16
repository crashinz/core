<?php
declare(strict_types=1);

require_once __DIR__.'/five_dice_expert_support.php';

const FIVE_DICE_BOT_ENGINE = 'corechat-five-dice-2';

function five_dice_bot_choices(): array {
    return [['value'=>'none','label'=>'None'],['value'=>'easy','label'=>'Easy'],['value'=>'normal','label'=>'Normal'],['value'=>'expert','label'=>'Expert']];
}

function five_dice_bot_fill_seats(array $humans, array $settings, string $mode, array $seats): array {
    if (!$seats) foreach ($humans as $i=>$id) $seats[$i+1]=$id;
    $players=[]; $bots=[];
    for ($seat=1;$seat<=4;$seat++) {
        if (isset($seats[$seat]) && in_array((int)$seats[$seat],$humans,true)) {$players[]=(int)$seats[$seat];continue;}
        $level=$settings['botSeat'.$seat.'Difficulty']??'none';
        if ($mode!=='practice'||$level==='none') continue;
        $id=-7500-$seat; $players[]=$id;
        $bots[(string)$id]=['userId'=>$id,'seat'=>$seat,'difficulty'=>$level,'displayName'=>ucfirst($level).' Bot '.$seat,'engine'=>FIVE_DICE_BOT_ENGINE];
    }
    return [$players,$bots];
}

function five_dice_project_virtual_members(PDO $pdo,array $state,array $context): array {
    return ($context['mode']??'')==='practice'?array_values($state['bots']??[]):[];
}

function five_dice_bot_actor(array $state): int {
    $id=(int)($state['turnOrder'][(int)($state['turnIndex']??0)]??0);
    return empty($state['completed'])&&isset($state['bots'][(string)$id])?$id:0;
}

function five_dice_bot_position_key(array $state): string {
    return hash('sha256',json_encode(array_intersect_key($state,array_flip(['turnOrder','turnIndex','dice','held','rollsThisTurn','players','bots','botSequence','botRollPending','completed'])),JSON_THROW_ON_ERROR));
}

function five_dice_bot_task(array $state,int $viewer,array $context): ?array {
    if (($context['mode']??'')!=='practice'||($context['status']??'')!=='active'||$viewer<=0||!in_array($viewer,$state['turnOrder']??[],true)||!in_array($context['viewerRole']??'',['master','player'],true)) return null;
    $id=five_dice_bot_actor($state); if (!$id) return null;
    return ['actor'=>$id,'engine'=>FIVE_DICE_BOT_ENGINE,'positionKey'=>five_dice_bot_position_key($state),'displayName'=>$state['bots'][(string)$id]['displayName'],'action'=>(int)$state['rollsThisTurn']===0||!empty($state['botRollPending'])?'bot-roll':'bot-step','delayMs'=>2000];
}

/** Explicit public observation: no randomness receipt, nonce or future dice. */
function five_dice_bot_observation(array $state,int $actor): array {
    return ['dice'=>$state['dice'],'held'=>$state['held'],'rolls'=>(int)$state['rollsThisTurn'],'scorecard'=>$state['players'][(string)$actor]['scorecard'],'difficulty'=>$state['bots'][(string)$actor]['difficulty']??'normal'];
}

/** All unordered rolls, weighted by their ordered multiplicity. First-party probability enumeration. */
function five_dice_bot_graph(): array {
    static $graph=null;
    if ($graph!==null) return $graph;
    $counts=[];
    $visit=function(array $prefix,int $left,int $face)use(&$visit,&$counts):void {
        if ($face===6) {$prefix[]=$left;$counts[]=$prefix;return;}
        for($n=0;$n<=$left;$n++)$visit([...$prefix,$n],$left-$n,$face+1);
    };
    for($total=0;$total<=5;$total++)$visit([],$total,1);
    $full=array_values(array_filter($counts,static fn($c)=>array_sum($c)===5));
    $factorial=[1,1,2,6,24,120];$edges=[];$subsets=[];$dice=[];$keys=[];
    foreach($full as$i=>$c){$d=[];foreach($c as$f=>$n)for($k=0;$k<$n;$k++)$d[]=$f+1;$dice[$i]=$d;$keys[implode('',$c)]=$i;}
    foreach($counts as$h=>$keep){
        $n=5-array_sum($keep);$edges[$h]=[];
        foreach($full as$i=>$c){
            $den=1;$valid=true;
            for($f=0;$f<6;$f++){if($c[$f]<$keep[$f]){$valid=false;break;}$den*=$factorial[$c[$f]-$keep[$f]];}
            if(!$valid)continue;
            $edges[$h][]=[$i,$factorial[$n]/$den/(6**$n)];$subsets[$i][]=$h;
        }
    }
    return $graph=['holds'=>$counts,'dice'=>$dice,'edges'=>$edges,'subsets'=>$subsets,'keys'=>$keys];
}

/** Approximate future upper-bonus value; exact at a completed upper section. */
function five_dice_bot_upper_potential(array $card): float {
    $sum=0;$remaining=0;$variance=0;
    foreach(five_dice_upper_categories()as$c=>$face){if($card[$c]===null){$remaining+=3*$face;$variance+=$face*$face*1.4;}else $sum+=(int)$card[$c];}
    if($sum>=63)return 35.0;
    if($variance===0)return 0.0;
    return 35/(1+exp(-1.7*($sum+$remaining-62.5)/sqrt($variance)));
}

function five_dice_bot_score_choice(array $card,array $dice,string $level): array {
    $cost=array_combine(five_dice_categories(),[3,6,9,12,15,18,19,12,18,23,29,22,10]);
    $before=five_dice_bot_upper_potential($card);$best=null;
    foreach(five_dice_allowed_score_categories($card,$dice)as$c){
        $placement=five_dice_score_with_joker($c,$card,$dice);$next=$card;$next[$c]=$placement['score'];
        $value=$placement['score']+$placement['repeatBonus'];
        if($level!=='easy')$value-=$cost[$c];
        $value+=five_dice_bot_upper_potential($next)-$before;
        if($level!=='easy'&&$c==='yahtzee'&&$placement['score']===50)$value+=5;
        if($best===null||$value>$best['value']+1e-9)$best=['category'=>$c,'value'=>$value,'score'=>$placement['score'],'repeatBonus'=>$placement['repeatBonus']];
    }
    return $best??throw new LogicException('No unused score category');
}

/** Expert uses the qualified whole-scorecard table when available. Easy and
 * Normal retain their existing policy. Missing/invalid optional data must not
 * interrupt an existing game; the actual fallback is recorded in the trace.
 */
function five_dice_bot_choose(array $o): array {
    if (($o['difficulty'] ?? '') !== 'expert' || (int)$o['rolls'] === 0) return five_dice_bot_choose_bounded($o);
    return five_dice_expert_dispatch($o, five_dice_expert_table());
}

function five_dice_expert_dispatch(array $o, ?string $table): array {
    $fallback = 'table-unavailable';
    try {
        if ($table !== null) return five_dice_expert_choose($o, $table);
    } catch (UnexpectedValueException) { $fallback = 'table-value-invalid'; }
    $choice = five_dice_bot_choose_bounded($o);
    $choice['strategy'] = 'bounded-expert-fallback';
    $choice['fallbackReason'] = $fallback;
    return $choice;
}

/** Exact one/two-reroll expectation over a heuristic end-of-turn scorecard value.
 * This is not a full-game optimal solver or a model trained on player data.
 */
function five_dice_bot_choose_bounded(array $o): array {
    $dice=$o['dice'];$card=$o['scorecard'];$level=$o['difficulty'];
    if($o['rolls']===0)return ['action'=>'roll','payload'=>[],'reason'=>'fresh-verified-roll'];
    $score=five_dice_bot_score_choice($card,$dice,$level);
    $finish=static fn()=>['action'=>'score','payload'=>['category'=>$score['category']],'reason'=>'best-available-score','value'=>$score['value']];
    if($o['rolls']>=3)return $finish();
    if($level==='easy'){
        if($score['score']>=25)return $finish();
        $counts=array_count_values($dice);$face=1;$n=0;
        foreach($counts as$f=>$count)if($count>$n||($count===$n&&$f>$face)){$face=$f;$n=$count;}
        if($n===5)return $finish();
        return ['action'=>'set-holds','payload'=>['held'=>array_map(static fn($v)=>$v===$face,$dice)],'reason'=>'keep-most-common-face'];
    }
    $g=five_dice_bot_graph();$counts=array_fill(0,6,0);foreach($dice as$f)$counts[$f-1]++;
    $index=$g['keys'][implode('',$counts)];$terminal=[];
    foreach($g['dice']as$i=>$d)$terminal[$i]=five_dice_bot_score_choice($card,$d,$level)['value'];
    $values=$terminal;$choice=[];$depth=$level==='expert'?3-$o['rolls']:1;
    for($step=0;$step<$depth;$step++){
        $expected=[];foreach($g['edges']as$h=>$edges){$v=0;foreach($edges as[$i,$p])$v+=$p*$values[$i];$expected[$h]=$v;}
        $next=$terminal;
        foreach($g['subsets']as$i=>$holds){$choice[$i]=null;foreach($holds as$h)if($expected[$h]>$next[$i]+1e-9){$next[$i]=$expected[$h];$choice[$i]=$h;}}
        $values=$next;
    }
    if($choice[$index]===null)return $finish();
    $keep=$g['holds'][$choice[$index]];$held=[];
    foreach($dice as$f){$held[]=$keep[$f-1]>0;if($keep[$f-1]>0)$keep[$f-1]--;}
    return ['action'=>'set-holds','payload'=>['held'=>$held],'reason'=>$level==='expert'?'two-reroll-expectation':'one-reroll-expectation','value'=>$values[$index],'scoreNowValue'=>$score['value'],'depth'=>$depth];
}

function five_dice_apply_action(array $state,int $actor,string $action,array $payload,array $context): array {
    if($actor<=0||!in_array($actor,$state['turnOrder']??[],true))throw new MultiplayerGameException('Only a participant may act.','FIVE_DICE_PLAYER_INVALID',403);
    if(!empty($state['bots'])&&($context['mode']??'')!=='practice')throw new MultiplayerGameException('Games with bots are Practice only.','MULTIPLAYER_GAME_BOTS_PRACTICE_ONLY',422);
    $trace=null;
    if(in_array($action,['bot-step','bot-roll'],true)){
        $task=five_dice_bot_task($state,$actor,['status'=>'active','viewerRole'=>'player']+$context);
        if(!$task)throw new MultiplayerGameException('A Practice bot is not available.','FIVE_DICE_BOT_UNAVAILABLE',409);
        if(($payload['engine']??'')!==FIVE_DICE_BOT_ENGINE||!hash_equals($task['positionKey'],(string)($payload['positionKey']??''))||$task['action']!==$action)throw new MultiplayerGameException('The bot position changed. Refresh before retrying.','FIVE_DICE_BOT_STALE',409);
        $actor=$task['actor'];$start=hrtime(true);$observation=five_dice_bot_observation($state,$actor);
        $choice=$action==='bot-roll'?['action'=>'roll','payload'=>[],'reason'=>'fresh-verified-roll']:five_dice_bot_choose($observation);
        $action=$choice['action'];$payload=$choice['payload'];
        $trace=['engine'=>FIVE_DICE_BOT_ENGINE,'difficulty'=>$observation['difficulty'],'selected'=>$choice,'publicObservation'=>$observation,'elapsedMs'=>(hrtime(true)-$start)/1000000];
    }
    $result=five_dice_apply_action_core($state,$actor,$action,$payload,$context);
    if(function_exists('game_recording_observe'))game_recording_observe($context,'five-dice',$state,$actor,$action,$payload,$result['state'],$trace);
    return $result;
}

function five_dice_apply_action_core(array $state,int $actor,string $action,array $payload,array $context): array {
    $result=five_dice_apply_action_rules($state,$actor,$action,$payload,$context);
    $result['state']['botSequence']=(int)($state['botSequence']??0)+1;
    $result['state']['botRollPending']=$action==='set-holds';
    $result['state']['lastBotAction']=isset($state['bots'][(string)$actor])?['actor'=>$actor,'action'=>$action,'category'=>$payload['category']??null]:null;
    return $result;
}

function five_dice_recording_adapter(): array {
    return ['schemaVersion'=>1,'stateKeys'=>['schemaVersion','turnOrder','turnIndex','dice','held','rollsThisTurn','players','completed','terminalReason','resignedUserId','bots','botSequence','botRollPending','lastBotAction','usedRandomnessRequestIds'],'payloadKeys'=>['index','held','category']];
}
