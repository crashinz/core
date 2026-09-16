<?php
declare(strict_types=1);

require_once __DIR__.'/blackjack_expert.php';
const BLACKJACK_BOT_ENGINE = 'corechat-blackjack-expert-1';
function blackjack_bot_choices(): array {
    return [['value'=>'none','label'=>'None'],['value'=>'easy','label'=>'Easy'],['value'=>'normal','label'=>'Normal'],['value'=>'expert','label'=>'Expert']];
}
function blackjack_bot_fill_seats(array $humans, array $settings, string $mode, array $seats): array {
    if (!$seats) foreach ($humans as $i=>$id) $seats[$i+1]=$id;
    $players=[]; $bots=[];
    for ($seat=1;$seat<=5;$seat++) {
        if (isset($seats[$seat]) && in_array((int)$seats[$seat],$humans,true)) {$players[]=(int)$seats[$seat];continue;}
        $level=$settings['botSeat'.$seat.'Difficulty']??'none';
        if ($mode!=='practice'||$level==='none') continue;
        $id=-7100-$seat;$players[]=$id;
        $bots[(string)$id]=['userId'=>$id,'seat'=>$seat,'difficulty'=>$level,'displayName'=>ucfirst($level).' Bot '.$seat,'engine'=>BLACKJACK_BOT_ENGINE];
    }
    return [$players,$bots];
}
function blackjack_project_virtual_members(PDO $pdo,array $s,array $c): array {
    return ($c['mode']??'')==='practice'?array_values($s['bots']??[]):[];
}
function blackjack_bot_actor(array $s): int {
    $id=(int)($s['turnOrder'][(int)($s['turnIndex']??0)]??0);
    return empty($s['completed'])&&isset($s['bots'][(string)$id])?$id:0;
}
/** The identity contains a monotonic action number and public turn facts, never a hidden-card hash. */
function blackjack_bot_position_key(array $s): string {
    return hash('sha256',multiplayer_game_canonical_json(array_intersect_key($s,array_flip(['turnOrder','turnIndex','phase','round','currentHandIndex','botSequence','bots','completed']))));
}
function blackjack_bot_task(array $s,int $viewer,array $c): ?array {
    if (($c['mode']??'')!=='practice'||($c['status']??'')!=='active'||$viewer<=0||!in_array($viewer,$s['turnOrder']??[],true)||!in_array($c['viewerRole']??'',['master','player'],true))return null;
    $id=blackjack_bot_actor($s);if(!$id)return null;
    return ['actor'=>$id,'engine'=>BLACKJACK_BOT_ENGINE,'positionKey'=>blackjack_bot_position_key($s),'displayName'=>$s['bots'][(string)$id]['displayName'],'action'=>$s['phase']==='deal'?'bot-deal':'bot-step','delayMs'=>$s['phase']==='round-complete'?3000:2000];
}
/** Explicit information boundary: only own cards, dealer upcard, bankroll and legal choices. */
function blackjack_bot_observation(array $s,int $actor): array {
    $p=blackjack_project_state_core($s,$actor,[]);
    $hand=$p['hands'][(string)$actor][(int)($s['currentHandIndex']??0)]??[];
    $standings=$s['bankrolls'];foreach($s['hands']as$id=>$hands)foreach($hands as$h)$standings[$id]+=(int)$h['bet'];
    return ['actor'=>$actor,'standings'=>$standings,'roundsLeft'=>(int)$s['settings']['rounds']-(int)$s['round']+1,'memory'=>$s['publicCardMemory']??['complete'=>false,'seen'=>blackjack_visible_rank_counts($s),'wagers'=>[],'lastWagers'=>[]],'insuranceCost'=>intdiv((int)($s['hands'][(string)$actor][0]['bet']??0),2),'phase'=>$s['phase'],'difficulty'=>$s['bots'][(string)$actor]['difficulty']??'normal','cards'=>$hand['cards']??[],
        'value'=>$hand['value']??['total'=>0,'soft'=>false],'dealerUpcard'=>$p['dealer']['cards'][0]??null,
        'legal'=>$p['legalActions'],'bankroll'=>(int)$s['bankrolls'][(string)$actor],'startingChips'=>(int)$s['settings']['startingChips']];
}
/** First-party implementation of six-deck S17/DAS/late-surrender basic strategy.
 * Reference: https://wizardofodds.com/games/blackjack/strategy/4-decks/
 * Only rule facts are used; no third-party engine or chart asset is distributed.
 */
function blackjack_bot_choose(array $o): array {
    $basic=blackjack_bot_choose_basic($o);
    return ($o['difficulty']??'normal')==='expert'?blackjack_expert_choose($o,$basic):$basic;
}
function blackjack_bot_choose_basic(array $o): array {
    $choose=static fn($a,$p=[],$r='six-deck-basic-strategy')=>['action'=>$a,'payload'=>$p,'reason'=>$r];
    $phase=$o['phase'];
    if($phase==='betting') {
        $amount=min(500,(int)(floor($o['bankroll']/10)*10),max(10,(int)(floor($o['startingChips']/500)*10)));
        return $choose('bet',['amount'=>$amount],'fixed-modest-wager');
    }
    if($phase==='insurance')return $choose('insurance',['take'=>false],'decline-insurance-without-card-count');
    if($phase==='deal')return $choose('deal',[],'framework-verified-shoe');
    if($phase==='dealer')return $choose('dealer-play',[],'automatic-dealer-rules');
    if($phase==='round-complete')return $choose('next-round',[],'continue-match');
    $total=(int)$o['value']['total'];$soft=!empty($o['value']['soft']);$legal=$o['legal'];
    $has=static fn($a)=>in_array($a,$legal,true);
    if($o['difficulty']==='easy')return $choose($total<17?'hit':'stand',[],'simple-seventeen-policy');
    $rank=static fn($c)=>(int)substr($c,1);$up=$rank($o['dealerUpcard']);$up=$up===14?11:min(10,$up);
    $cards=$o['cards'];$pair=count($cards)===2&&$rank($cards[0])===$rank($cards[1])?$rank($cards[0]):0;
    // Pair eights take priority over surrender under S17; ten-value mixed ranks cannot split.
    if($has('split')&&($pair===14||$pair===8||($pair<=3&&$pair>=2&&$up<=7)||($pair===4&&in_array($up,[5,6],true))||($pair===6&&$up<=6)||($pair===7&&$up<=7)||($pair===9&&in_array($up,[2,3,4,5,6,8,9],true))))return $choose('split');
    if(!$soft&&$has('surrender')&&(($total===16&&$up>=9)||($total===15&&$up===10)))return $choose('surrender');
    if($soft) {
        if($total>=19)return $choose('stand');
        if($total===18)return $choose($up>=3&&$up<=6&&$has('double')?'double':($up<=8?'stand':'hit'));
        $double=($total===17&&$up>=3&&$up<=6)||($total>=15&&$total<=16&&$up>=4&&$up<=6)||($total>=13&&$total<=14&&$up>=5&&$up<=6);
        return $choose($double&&$has('double')?'double':'hit');
    }
    if($total>=17)return $choose('stand');
    if($has('double')&&(($total===11&&$up<=10)||($total===10&&$up<=9)||($total===9&&$up>=3&&$up<=6)))return $choose('double');
    return $choose(($total>=13&&$up<=6)||($total===12&&$up>=4&&$up<=6)?'stand':'hit');
}
function blackjack_project_state(array $s,int $viewer,array $c): array {
    $p=blackjack_project_state_core($s,$viewer,$c);$p['botTask']=blackjack_bot_task($s,$viewer,$c);return $p;
}
function blackjack_apply_action(array $s,int $actor,string $action,array $payload,array $c): array {
    if($actor<=0||!in_array($actor,$s['turnOrder']??[],true))throw new MultiplayerGameException('Only an authenticated participant may act.','BLACKJACK_PLAYER_INVALID',403);
    if(!empty($s['bots'])&&($c['mode']??'')!=='practice')throw new MultiplayerGameException('Games with bots are Practice only.','MULTIPLAYER_GAME_BOTS_PRACTICE_ONLY',422);
    $trace=null;
    if(in_array($action,['bot-step','bot-deal'],true)) {
        $bot=blackjack_bot_actor($s);
        if(!$bot||($c['mode']??'')!=='practice')throw new MultiplayerGameException('A Practice bot is not available.','BLACKJACK_BOT_UNAVAILABLE',409);
        if(($payload['engine']??'')!==BLACKJACK_BOT_ENGINE)throw new MultiplayerGameException('The bot was updated. Reload the game.','BLACKJACK_BOT_ENGINE_MISMATCH',409);
        if(!hash_equals(blackjack_bot_position_key($s),(string)($payload['positionKey']??'')))throw new MultiplayerGameException('The position changed. Refresh before retrying.','BLACKJACK_BOT_POSITION_STALE',409);
        if(($action==='bot-deal')!==($s['phase']==='deal'))throw new MultiplayerGameException('The bot action changed.','BLACKJACK_BOT_ACTION_INVALID',409);
        $actor=$bot;$start=hrtime(true);$o=blackjack_bot_observation($s,$actor);$choice=blackjack_bot_choose($o);$action=$choice['action'];$payload=$choice['payload'];
        if(!in_array($action,$o['legal'],true))throw new MultiplayerGameException('The bot selected an unavailable action.','BLACKJACK_BOT_ACTION_INVALID',409);
        $trace=['engine'=>BLACKJACK_BOT_ENGINE,'difficulty'=>$o['difficulty'],'reason'=>$choice['reason'],'legal'=>$o['legal'],'selected'=>$choice,'candidateScores'=>$choice['candidateScores']??[],'publicObservation'=>$o,'elapsedMs'=>(hrtime(true)-$start)/1000000];
    }
    $r=blackjack_apply_action_core($s,$actor,$action,$payload,$c);
    if(function_exists('game_recording_observe'))game_recording_observe($c,'blackjack',$s,$actor,$action,$payload,$r['state'],$trace);
    return $r;
}
function blackjack_apply_action_core(array $s,int $actor,string $action,array $payload,array $c): array {
    $r=blackjack_apply_action_rules($s,$actor,$action,$payload,$c);
    $r['state']['botSequence']=(int)($s['botSequence']??0)+1;
    $r['state']['publicCardMemory']=blackjack_expert_memory($s,$r['state'],$action);
    return $r;
}
