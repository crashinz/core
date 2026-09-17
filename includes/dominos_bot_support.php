<?php
declare(strict_types=1);
require_once __DIR__.'/dominos_bot_search.php';
const DOMINOS_BOT_ENGINE='corechat-dominos-1';
function dominos_bot_choices(): array {return [['value'=>'none','label'=>'None'],['value'=>'normal','label'=>'Normal'],['value'=>'expert','label'=>'Expert']];}
function dominos_project_virtual_members(PDO $pdo,array $s,array $c): array {return ($c['mode']??'')==='practice'?array_values($s['bots']??[]):[];}
function dominos_bot_key(array $s): string {return hash('sha256',json_encode([$s['sequence'],$s['roundNumber'],$s['phase'],$s['turnIndex'],$s['turnOrder']]));}
function dominos_bot_task(array $s,int $viewer,array $c): ?array {
    $actor=dominos_turn_user($s);
    if(($c['mode']??'')!=='practice'||($c['status']??'')!=='active'||!in_array($c['viewerRole']??'',['master','player'],true)||$viewer<=0||!in_array($viewer,$s['turnOrder'],true)||!isset($s['bots'][$actor])||!empty($s['completed']))return null;
    return ['actor'=>$actor,'engine'=>DOMINOS_BOT_ENGINE,'positionKey'=>dominos_bot_key($s),'action'=>$s['phase']==='deal'?'bot-deal':'bot-step','delayMs'=>$s['phase']==='round-complete'?4000:2200,'displayName'=>$s['bots'][$actor]['displayName']];
}
/** Deliberate allowlist: no other hand, draw order, seed or hidden-state fingerprint. */
function dominos_bot_observation(array $s,int $actor): array {
    return ['actor'=>$actor,'hand'=>$s['hands'][$actor],'counts'=>array_map('count',$s['hands']),'stockCount'=>count($s['drawPile'])]+array_intersect_key($s,array_flip(['turnOrder','teams','turnIndex','phase','root','spinner','branches','scores','targetScore','voids','passes']));
}
function dominos_bot_public_state(array $o): array {
    $s=$o;$s['hands']=array_fill_keys($o['turnOrder'],[]);$s['hands'][$o['actor']]=$o['hand'];$s['completed']=false;return $s;
}
function dominos_bot_move_score(array $s,int $actor,array $move,bool $greedy=false): float {
    dominos_place($s,$move['tile'],$move['end']);$rest=array_values(array_diff($s['hands'][$actor],[$move['tile']]));$sum=dominos_end_total($s);$points=$sum%5===0?$sum:0;
    if($s['scores'][dominos_group($s,$actor)]+$points>=$s['targetScore'])return 100000;
    if($greedy)return $points*100+array_sum(dominos_pips($move['tile']));
    $ends=array_values(dominos_ends($s));$mobility=0;foreach($rest as$t)if(array_intersect(dominos_pips($t),$ends))$mobility++;
    $v=dominos_pips($move['tile']);return $points*10+($rest?0:220)+array_sum($v)*1.4+($v[0]===$v[1]?3:0)+$mobility*2.5;
}
function dominos_bot_choose(array $o,string $policy='normal',array $knowledge=[]): array {
    if($policy==='expert')return dominos_search_choose($o,$knowledge,['worlds'=>64,'ms'=>500]);
    if($o['phase']==='deal')return ['action'=>'deal','payload'=>[],'reason'=>'verified-deal'];
    if($o['phase']==='round-complete')return ['action'=>'next-round','payload'=>[],'reason'=>'next-round'];
    $actor=$o['actor'];$s=dominos_bot_public_state($o);$moves=dominos_legal($s,$actor);
    if(!$moves)return ['action'=>$o['stockCount']?'draw':'pass','payload'=>[],'reason'=>'no-legal-tile'];
    $scores=[];foreach($moves as$i=>$m)$scores[$i]=dominos_bot_move_score($s,$actor,$m,$policy==='reference');
    if($policy==='candidate'&&count($moves)>1){
        // Sample possible hands using only own/public information. Compare one complete
        // circuit of public-policy replies; hard voids are retained only after passing.
        $seen=[$s['root']];foreach($s['branches']as$branch)foreach($branch as$n)$seen[]=$n['tile'];
        $unknown=array_values(array_diff(dominos_deck(),array_merge($seen,$o['hand'])));$accepted=0;$sampleKey=json_encode($o);
        for($sample=0;$sample<40&&$accepted<12;$sample++){
            $deck=$unknown;usort($deck,static fn($a,$b)=>strcmp(hash('sha256',$sampleKey.'|'.$sample.'|'.$a),hash('sha256',$sampleKey.'|'.$sample.'|'.$b)));
            $sim=$s;$valid=true;foreach($o['turnOrder']as$id)if($id!==$actor){$sim['hands'][$id]=array_splice($deck,0,$o['counts'][$id]);foreach($sim['hands'][$id]as$t)if(array_intersect(dominos_pips($t),$o['voids'][$id]??[]))$valid=false;}
            if(!$valid)continue;$accepted++;$sim['drawPile']=$deck;$sim['schemaVersion']=1;$sim['sequence']=0;$sim['history']=[];$sim['roundNumber']=1;$sim['settings']=['tableMode'=>count(array_unique($o['teams']))<count($o['turnOrder'])?'teams':'individual'];
            foreach($moves as$i=>$m){$trial=$sim;$before=$trial['scores'];$trial=dominos_apply_action_core($trial,$actor,'play',$m,[])['state'];
                for($j=0;$j<36&&$trial['phase']==='playing'&&dominos_turn_user($trial)!==$actor;$j++){$id=dominos_turn_user($trial);$reply=dominos_bot_choose(dominos_bot_observation($trial,$id),'normal');$trial=dominos_apply_action_core($trial,$id,$reply['action'],$reply['payload'],[])['state'];}
                $own=dominos_group($trial,$actor);$gain=$trial['scores'][$own]-$before[$own];$loss=0;foreach($trial['scores']as$g=>$score)if((string)$g!==$own)$loss=max($loss,$score-$before[$g]);
                $scores[$i]+=($gain-$loss)*10/12;
            }
        }
    }
    arsort($scores,SORT_NUMERIC);$pick=(int)array_key_first($scores);
    return ['action'=>'play','payload'=>$moves[$pick],'reason'=>$policy==='candidate'?'sampled-public-replies':($policy==='reference'?'immediate-score-reference':'score-pips-and-mobility'),'candidateScores'=>$scores];
}
function dominos_apply_action(array $s,int $actor,string $action,array $p,array $c): array {
    if($actor<=0||!in_array($actor,$s['turnOrder']??[],true))dominos_fail('Only an authenticated participant may act.','PLAYER_INVALID',403);
    if(!empty($s['bots'])&&($c['mode']??'')!=='practice')throw new MultiplayerGameException('Bot matches are Practice only.','MULTIPLAYER_GAME_BOTS_PRACTICE_ONLY',422);
    $trace=null;
    if(in_array($action,['bot-step','bot-deal'],true)){
        $bot=dominos_turn_user($s);if(!isset($s['bots'][$bot])||($c['mode']??'')!=='practice')dominos_fail('No bot turn is available.','BOT_UNAVAILABLE',409);
        if(!hash_equals(dominos_bot_key($s),(string)($p['positionKey']??''))||($p['engine']??'')!==DOMINOS_BOT_ENGINE||(($action==='bot-deal')!==($s['phase']==='deal')))dominos_fail('The position changed. Refresh before retrying.','BOT_STALE',409);
        $actor=$bot;$o=dominos_bot_observation($s,$actor);$start=hrtime(true);$level=$s['bots'][$actor]['difficulty']??'normal';$choice=dominos_bot_choose($o,$level,$s['botKnowledge']??[]);$action=$choice['action'];$p=$choice['payload'];
        $trace=['engine'=>$level==='expert'?DOMINOS_EXPERT_ENGINE:DOMINOS_BOT_ENGINE,'difficulty'=>$level,'reason'=>$choice['reason'],'selected'=>$choice,'publicObservation'=>$o,'elapsedMs'=>(hrtime(true)-$start)/1e6];
    }
    $r=dominos_apply_action_core($s,$actor,$action,$p,$c);if(function_exists('game_recording_observe'))game_recording_observe($c,'dominos',$s,$actor,$action,$p,$r['state'],$trace);return $r;
}
