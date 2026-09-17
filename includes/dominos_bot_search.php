<?php
declare(strict_types=1);

/** Bounded fair-information search. See THIRD_PARTY_NOTICES.md for sources. */
const DOMINOS_EXPERT_ENGINE='corechat-dominos-expert-1';
function dominos_search_observe(array &$memory, array $event): void {
    if ($event['type']==='deal') { $memory=[]; return; }
    $id=$event['actor'];
    if (in_array($event['type'],['draw','pass'],true)) {
        foreach ($event['ends'] as $pip) $memory[$id][$pip]=0;
        $mask=0;foreach($event['ends'] as $pip)$mask|=1<<$pip;
        if($mask)$memory['_joint'][$id][$mask]=0;
        // A newly drawn hidden tile can contain any pip. Earlier constraints survive
        // as upper bounds rather than incorrectly claiming the hand is still void.
        if ($event['type']==='draw') {
            foreach ($memory[$id]??[] as $pip=>$cap) $memory[$id][$pip]=$cap+1;
            foreach ($memory['_joint'][$id]??[] as $mask=>$cap) $memory['_joint'][$id][$mask]=$cap+1;
        }
    } elseif ($event['type']==='play') {
        foreach (array_unique(dominos_pips($event['tile'])) as $pip)
            if (isset($memory[$id][$pip])) $memory[$id][$pip]=max(0,$memory[$id][$pip]-1);
        foreach($memory['_joint'][$id]??[] as $mask=>$cap)if(dominos_search_tile_mask($event['tile'])&$mask)$memory['_joint'][$id][$mask]=max(0,$cap-1);
    }
}
function dominos_search_tile_mask(string $tile): int {[$a,$b]=dominos_pips($tile);return (1<<$a)|(1<<$b);}
function dominos_search_seen(array $o): array {
    $seen=$o['root']?[$o['root']]:[];
    foreach($o['branches'] as $branch)foreach($branch as $n)$seen[]=$n['tile'];
    return $seen;
}
function dominos_search_rng(int &$rng,int $n): int {
    $rng=($rng*48271)%2147483647;return $rng%$n;
}
function dominos_search_shuffle(array $a,int &$rng): array {
    for($i=count($a)-1;$i>0;$i--){$j=dominos_search_rng($rng,$i+1);[$a[$i],$a[$j]]=[$a[$j],$a[$i]];}return $a;
}
/** Uniform random allocations conditioned on public count bounds. */
function dominos_search_world(array $o,array $memory,int &$rng): ?array {
    $unknown=array_values(array_diff(dominos_deck(),array_merge(dominos_search_seen($o),$o['hand'])));
    $deck=dominos_search_shuffle($unknown,$rng);$s=dominos_bot_public_state($o);
    foreach($o['turnOrder'] as $id)if($id!==$o['actor']){
        $hand=array_splice($deck,0,$o['counts'][$id]);$s['hands'][$id]=$hand;
        foreach($memory[$id]??[] as $pip=>$cap){$count=0;foreach($hand as $t)if(in_array((int)$pip,dominos_pips($t),true))$count++;if($count>$cap)return null;}
        foreach($memory['_joint'][$id]??[] as $mask=>$cap){$count=0;foreach($hand as $t)if(dominos_search_tile_mask($t)&$mask)$count++;if($count>$cap)return null;}
        foreach($o['voids'][$id]??[] as $pip)foreach($hand as $t)if(in_array($pip,dominos_pips($t),true))return null;
    }
    $s['drawPile']=$deck;$s['schemaVersion']=1;$s['sequence']=0;$s['history']=[];$s['roundNumber']=1;
    $s['settings']=['tableMode'=>count(array_unique($o['teams']))<count($o['turnOrder'])?'teams':'individual'];return $s;
}
/** Adapted highest-score / blocked-end / lowest-board-total priority from
 * Press-Play-On-Tape/Dominoes (BSD-3-Clause; central THIRD_PARTY_NOTICES.md).
 * Uses our rules, own hand and publicly played tiles; never actual hidden hands.
 */
function dominos_search_public(array $o): array {
    $s=dominos_bot_public_state($o);$moves=dominos_legal($s,$o['actor']);
    if(!$moves)return dominos_bot_choose($o);
    $known=array_merge(dominos_search_seen($o),$o['hand']);$unseen=array_diff(dominos_deck(),$known);
    $best=-INF;$pick=$moves[0];
    foreach($moves as $m){$t=$s;dominos_place($t,$m['tile'],$m['end']);$sum=dominos_end_total($t);$pts=$sum%5===0?$sum:0;
        $blocked=false;foreach(dominos_ends($t) as $pip){$possible=false;foreach($unseen as $u)if(in_array($pip,dominos_pips($u),true)){$possible=true;break;}if(!$possible)$blocked=true;}
        $value=$pts>0?10000+$pts*100:($blocked?1000:0)-$sum;
        if($value>$best){$best=$value;$pick=$m;}
    }return ['action'=>'play','payload'=>$pick,'reason'=>'adapted-public-all-fives'];
}
function dominos_search_value(array $s): array {
    $result=[];$groups=array_unique(array_map('strval',$s['teams']));
    foreach($groups as $g){$others=$s['scores'];unset($others[$g]);$v=$s['scores'][$g]-max($others);
        if(!empty($s['completed'])){$won=false;foreach($s['winners']??[] as $id)if(dominos_group($s,$id)===$g)$won=true;$v+=$won?500:-500;}
        if($s['phase']==='playing'){$own=0;$opp=[];foreach($s['hands'] as $id=>$h){$group=dominos_group($s,(int)$id);$p=dominos_pip_sum($h);if($group===$g)$own+=$p;else$opp[$group]=($opp[$group]??0)+$p;}$v+=0.3*(min($opp)-$own);}
        $result[$g]=$v;
    }return $result;
}
function dominos_search_apply(array $s,array $move): array {return dominos_apply_action_core($s,dominos_turn_user($s),$move['action'],$move['payload'],[])['state'];}
/** Max-n for individuals, minimax-equivalent group utilities for two sides.
 * Only solves sampled worlds, never presented as an exact hidden-information solution.
 */
function dominos_search_endgame(array $s,int &$nodes,int $deadline): array {
    if($s['phase']!=='playing')return dominos_search_value($s);
    if(++$nodes>1800||hrtime(true)>=$deadline)throw new RuntimeException('search-budget');
    $id=dominos_turn_user($s);$g=dominos_group($s,$id);$moves=dominos_legal($s,$id);
    if(!$moves)return dominos_search_endgame(dominos_search_apply($s,['action'=>'pass','payload'=>[]]),$nodes,$deadline);
    $best=null;
    foreach($moves as $m){$v=dominos_search_endgame(dominos_search_apply($s,['action'=>'play','payload'=>$m]),$nodes,$deadline);if($best===null||$v[$g]>$best[$g])$best=$v;}
    return $best;
}
function dominos_search_rollout(array $s,int $variant,int $deadline): array {
    for($step=0;$step<150&&$s['phase']==='playing';$step++){
        if(hrtime(true)>=$deadline)throw new RuntimeException('search-budget');
        if(!$s['drawPile']&&array_sum(array_map('count',$s['hands']))<=7){$nodes=0;return dominos_search_endgame($s,$nodes,$deadline);}
        $o=dominos_bot_observation($s,dominos_turn_user($s));
        $choice=$variant%4===3?dominos_search_public($o):dominos_bot_choose($o,'normal');
        $s=dominos_search_apply($s,$choice);
    }return dominos_search_value($s);
}
function dominos_search_choose(array $o,array $memory=[],array $config=[]): array {
    $start=hrtime(true);$deadline=$start+(int)(($config['ms']??450)*1e6);
    $normal=dominos_bot_choose($o);$moves=dominos_legal(dominos_bot_public_state($o),$o['actor']);
    if(count($moves)<2)return $normal+['trace'=>['worlds'=>0,'ms'=>(hrtime(true)-$start)/1e6]];
    // Do not trade an immediate match victory for speculative rollout scores.
    foreach($moves as $m)if(dominos_bot_move_score(dominos_bot_public_state($o),$o['actor'],$m)>=100000)return ['action'=>'play','payload'=>$m,'reason'=>'immediate-match-win'];
    $rng=1+(int)hexdec(substr(hash('sha256',json_encode([$o,$memory])),0,7));
    $totals=array_fill(0,count($moves),0.0);$squares=$totals;$accepted=0;$attempts=0;$wanted=$config['worlds']??24;
    while($accepted<$wanted&&$attempts<4000&&hrtime(true)<$deadline){$attempts++;$world=dominos_search_world($o,$memory,$rng);if($world===null)continue;$values=[];
        try{foreach($moves as $i=>$m){$s=dominos_search_apply($world,['action'=>'play','payload'=>$m]);$v=dominos_search_rollout($s,$accepted,$deadline);$values[$i]=$v[dominos_group($s,$o['actor'])];}}
        catch(RuntimeException $e){if($e->getMessage()!=='search-budget')throw $e;break;}
        // Only complete shared worlds count: each root move gets identical samples.
        foreach($values as $i=>$v){$totals[$i]+=$v;$squares[$i]+=$v*$v;}$accepted++;
    }
    $pick=array_search($normal['payload'],$moves,true);$pick=$pick===false?0:$pick;
    if($accepted>=4){$best=max($totals);foreach($totals as $i=>$value)if($value>$totals[$pick]+1e-8&&$value===$best)$pick=$i;}
    return ['action'=>'play','payload'=>$moves[$pick],'reason'=>$accepted>=4?'public-memory-full-round-search':'normal-budget-fallback',
        'trace'=>['worlds'=>$accepted,'attempts'=>$attempts,'ms'=>(hrtime(true)-$start)/1e6,'means'=>array_map(fn($v)=>$accepted?$v/$accepted:null,$totals),'normal'=>$normal['payload']]];
}
