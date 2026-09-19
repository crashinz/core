<?php
declare(strict_types=1);

function pool_clock_now(array $context): float {
    return isset($context['nowUnixMs']) ? (float)$context['nowUnixMs']/1000 : microtime(true);
}
function pool_clock_enabled(array $state): bool {
    return ($state['settings']['tableMode']??'match')==='match' && empty($state['completed'])
        && isset($state['settings']['turnSeconds']) && in_array($state['phase']??'', ['aim','placement','choice'], true);
}
function pool_clock_start(array &$state, float $now): void {
    if (!pool_clock_enabled($state)) {unset($state['turnClock']);return;}
    $start=max($now,(float)($state['animationUntil']??0));
    $seconds=(int)$state['settings']['turnSeconds'];
    $state['turnClock']=['id'=>$state['sequence'].':'.sprintf('%.3f',$start), 'actor'=>eight_ball_turn($state),
        'seconds'=>$seconds, 'startsAt'=>$start, 'expiresAt'=>$start+$seconds];
}
function pool_clock_expired(array $state, float $now): bool {
    return pool_clock_enabled($state) && isset($state['turnClock']) && !isset($state['turnClock']['frozenAt']) && $now >= $state['turnClock']['expiresAt'];
}
function pool_clock_after(array $before, array &$after, string $action, float $now): void {
    if (!pool_clock_enabled($after)) {unset($after['turnClock']);return;}
    // Placement, pocket calls, cue cosmetics and a prepared bot shot share the
    // existing allowance. A new shot or a different player gets a fresh one.
    if ($action==='shot' || $action==='rack' || !isset($after['turnClock']) || eight_ball_turn($before)!==eight_ball_turn($after))
        pool_clock_start($after,$now);
}
function pool_clock_timeout(array $state, float $now): array {
    $actor=eight_ball_turn($state);
    $state['turnIndex']=1-$state['turnIndex'];
    if(!empty($state['breakShot']))$state['breakerUserId']=eight_ball_turn($state);
    $state['phase']='placement';$state['placement']=!empty($state['breakShot'])?'break':'anywhere';
    $state['calledShot']=null;$state['choice']=null;$state['headStringRequired']=false;
    $state['pushOutAvailable']=false;$state['pushOutDeclared']=false;$state['stalemateRequests']=[];
    unset($state['botPendingShot'],$state['botReadyAt'],$state['botAim']);
    // Resolve the unusual pre-shot 8-on-break choice before granting the table.
    if (pool_variant($state)==='eight-ball' && !empty($state['lastShot']) && ($state['lastShot']['id']??0)===1)
        foreach($state['balls']as$ball)if($ball['n']===8&&!empty($ball['pocket'])){eight_ball_spot_eight($state);break;}
    $state['statusText']='Time expired. Opponent has ball in hand.'.(!empty($state['breakShot'])?' Place behind the head string.':'');
    if (pool_variant($state)==='nine-ball') {
        $count=($state['foulCounts'][$actor]??0)+1;$state['foulCounts'][$actor]=$count;
        if ($count>=3) {$state['completed']=true;$state['phase']='completed';$state['winner']=eight_ball_turn($state);$state['statusText']='Time expired: three consecutive fouls. Opponent wins.';}
        elseif($count===2)$state['statusText'].=' Warning: the fouling player has two consecutive fouls.';
    }
    $state['sequence']++;pool_clock_start($state,$now);
    return eight_ball_result($state);
}
