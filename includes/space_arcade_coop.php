<?php
declare(strict_types=1);

// Cover a peer's request/reply cycle plus its next input batch. This is only
// a maximum wait for missing controls; complete frames still advance at once.
const SPACE_COOP_INPUT_GRACE_MS = 600;

/** Each actor queues controls on one shared, server-time-bounded timeline. */
function space_arcade_coop_action(array $state,int $actor,string $action,array $payload,array $context): array
{
    if(($state['schemaVersion'] ?? 0)!==1 || !isset($state['realtime']) || !empty($state['completed'])) throw new MultiplayerGameException('The run is not active.','ARCADE_STATE_INVALID',409);
    if(!in_array($actor,$state['turnOrder'],true) || !empty($state['_framework']['players'][$actor]['disconnected'])) throw new MultiplayerGameException('Only a connected player may send controls.','ARCADE_PLAYER_INVALID',403);
    if(!in_array($state['_framework']['pause']['mode'] ?? 'running',['running','proposed'],true) || $state['realtime']['frozenAtMs']!==null || multiplayer_game_shared_service_interruption_active($state)) throw new MultiplayerGameException('The game is paused.','MULTIPLAYER_GAME_PAUSED',409);
    $now=max((int)$state['realtime']['lastAtMs'],arcade_now_ms($context));
    $available=(int)$state['realtime']['pendingMs']+$now-(int)$state['realtime']['lastAtMs'];
    if($action==='arcade-input') {
        if(array_diff(array_keys($payload),['sequence','frameStart','frames']) || !is_int($payload['sequence'] ?? null) || $payload['sequence']<1 || !is_int($payload['frameStart'] ?? null) || $payload['frameStart']<0 || $payload['frameStart']%ARCADE_STEP_MS!==0 || !is_array($payload['frames'] ?? null) || !array_is_list($payload['frames']) || count($payload['frames'])<1 || count($payload['frames'])>25) throw new MultiplayerGameException('Invalid input frames.','ARCADE_INPUT_INVALID',422);
        $ticks=0;
        foreach($payload['frames'] as $run) {
            if(!is_array($run) || array_diff(array_keys($run),['ticks','left','right','fire']) || !is_int($run['ticks'] ?? null) || $run['ticks']<1 || $run['ticks']>25 || !is_bool($run['left'] ?? null) || !is_bool($run['right'] ?? null) || !is_bool($run['fire'] ?? null)) throw new MultiplayerGameException('Invalid input frames.','ARCADE_INPUT_INVALID',422);
            $ticks+=$run['ticks'];
        }
        if($ticks>25) throw new MultiplayerGameException('Too many input frames.','ARCADE_INPUT_INVALID',422);
        $hash=hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR));$last=(int)($state['inputSequences'][$actor] ?? 0);
        if($payload['sequence']===$last && hash_equals((string)($state['inputHashes'][$actor] ?? ''),$hash)) return ['state'=>$state,'turnUserId'=>null];
        if($payload['sequence']!==$last+1) throw new MultiplayerGameException('Refresh the input sequence.','ARCADE_INPUT_SEQUENCE',409);
        $through=(int)($state['spaceInputThrough'][$actor] ?? 0);
        if($payload['frameStart']<$through || $payload['frameStart']>max($state['elapsedMs'],$through)) throw new MultiplayerGameException('Refresh the input timeline.','ARCADE_FRAME_STALE',409);
        $end=$payload['frameStart']+$ticks*ARCADE_STEP_MS;
        if($end>$state['elapsedMs']+$available) throw new MultiplayerGameException('Input arrived ahead of server time.','ARCADE_INPUT_AHEAD',409);
        // Never replay controls into a long-disconnected, historical run.
        if($available<=1000){
            $at=$payload['frameStart'];
            foreach($payload['frames'] as $run)for($i=0;$i<$run['ticks'];$i++,$at+=ARCADE_STEP_MS) if($at>=$state['elapsedMs'])$state['spaceFrameInputs'][$actor][$at]=['left'=>$run['left'],'right'=>$run['right'],'fire'=>$run['fire']];
            $state['spaceInputThrough'][$actor]=$end;
            $state['inputSequences'][$actor]=$payload['sequence'];$state['inputHashes'][$actor]=$hash;
        }
    } elseif($action!=='arcade-tick' || $payload!==[]) throw new MultiplayerGameException('Invalid arcade action.','ARCADE_INPUT_INVALID',422);
    $state['realtime']['lastAtMs']=$now;$state['realtime']['pendingMs']=$available;
    if($available>1000)$state['spaceFrameInputs']=array_fill_keys($state['turnOrder'],[]);
    for($step=0;$step<ARCADE_MAX_CATCHUP_MS/ARCADE_STEP_MS && $state['realtime']['pendingMs']>=ARCADE_STEP_MS && !$state['completed'];$step++) {
        $ready=true;
        foreach($state['turnOrder'] as $id)if(empty($state['_framework']['players'][$id]['disconnected']) && !isset($state['spaceFrameInputs'][$id][$state['elapsedMs']]))$ready=false;
        // Wait briefly for a peer, not indefinitely. Missing frames become neutral.
        if(!$ready && $state['realtime']['pendingMs']<=SPACE_COOP_INPUT_GRACE_MS)break;
        $state['elapsedMs']+=ARCADE_STEP_MS;$state['realtime']['pendingMs']-=ARCADE_STEP_MS;
        space_arcade_coop_tick($state,ARCADE_STEP_MS);
    }
    return $state['completed']?arcade_finish($state,null,$state['terminalReason']):['state'=>$state,'turnUserId'=>null];
}

function space_arcade_coop_tick(array &$state,int $milliseconds): void
{
    $at=$state['elapsedMs']-$milliseconds;$neutral=['left'=>false,'right'=>false,'fire'=>false];
    foreach($state['turnOrder'] as $id) {
        $ship=&$state['ships'][$id];
        $controls=empty($state['_framework']['players'][$id]['disconnected'])?($state['spaceFrameInputs'][$id][$at] ?? $neutral):$neutral;
        unset($state['spaceFrameInputs'][$id][$at]);
        $ship['controls']=$controls;$ship['inputLeaseMs']=1180;
        $ship['cooldownMs']=max(0,$ship['cooldownMs']-$milliseconds);
        $ship['x']=max(30,min(930,$ship['x']+((int)$controls['right']-(int)$controls['left'])*380*$milliseconds/1000));
        unset($ship);
    }
    $state['levelElapsedMs']+=$milliseconds;
    if($state['levelElapsedMs']>=0) {
        foreach($state['turnOrder'] as $id){$ship=&$state['ships'][$id];if($ship['controls']['fire'] && $ship['cooldownMs']===0){$state['orbs'][]=['x'=>$ship['x'],'y'=>550.0,'by'=>$id];$ship['cooldownMs']=280;}unset($ship);}
        $aliens=space_arcade_aliens($state);
        foreach($aliens as $alien)if($alien['y']+32>=562){$state['completed']=true;$state['terminalReason']='invaded';break;}
        if(!$state['completed']){
            $remaining=[];
            foreach($state['orbs'] as $orb){
                $orb['y']-=520*$milliseconds/1000;if($orb['y'] < -8)continue;$hit=false;
                foreach($aliens as $alien){
                    if(isset($state['kills'][$alien['id']]))continue;
                    if($orb['x']>$alien['x'] && $orb['x']<$alien['x']+44 && $orb['y']>$alien['y'] && $orb['y']<$alien['y']+32){$state['kills'][$alien['id']]=true;$state['ships'][$orb['by']]['score']+=10;$hit=true;break;}
                }
                if(!$hit)$remaining[]=$orb;
            }
            $state['orbs']=$remaining;
            if(count($state['kills'])===min(7,5+intdiv($state['level']-1,2))*11){$state['level']++;$state['kills']=[];$state['orbs']=[];$state['levelElapsedMs']=-850;}
        }
    }
    // Keep the old first-ship projection for saved-session/client compatibility.
    $state['ship']=$state['ships'][$state['turnOrder'][0]];
}
