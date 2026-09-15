<?php
declare(strict_types=1);

const HEARTS_EXPERT_PILOT_VERSION = 'hearts-expert-pilot-1';
const HEARTS_EXPERT_BUDGET_MS = 100;

function hearts_expert_rand(int &$seed, int $limit): int
{
    $seed = ($seed * 1664525 + 1013904223) & 0x7fffffff;
    return $limit > 0 ? $seed % $limit : 0;
}

/** Capacity-constrained sampling; the widow is another unknown recipient, never inspected. */
function hearts_expert_world(array $o, int $index, ?int $deadline = null): ?array
{
    $seed = (int)hexdec(substr(hash('sha256', json_encode($o).':'.$index),0,7));
    $cards = hearts_bot_unseen($o); $voids = hearts_bot_voids($o); $capacity=[];
    foreach ($o['cardCounts'] as $id=>$count) if ((int)$id !== $o['actor']) $capacity[(int)$id]=(int)$count;
    if ($o['playerCount']===2) $capacity[0]=2;
    if (array_sum($capacity)!==count($cards)) return null;
    for ($i=count($cards)-1;$i>0;$i--) { $j=hearts_expert_rand($seed,$i+1); [$cards[$i],$cards[$j]]=[$cards[$j],$cards[$i]]; }
    $eligible=[];
    foreach ($cards as $card) $eligible[$card]=array_values(array_filter(array_keys($capacity),static fn($id)=>empty($voids[(string)$id][$card[0]])));
    usort($cards,static fn($a,$b)=>count($eligible[$a])<=>count($eligible[$b]));
    $hands=array_fill_keys(array_keys($capacity),[]);$nodes=0;
    $assign=function(int $at)use(&$assign,&$capacity,&$hands,&$seed,&$nodes,$cards,$eligible,$deadline):bool{
        if ($at===count($cards)) return true;
        if (++$nodes>4000 || ($deadline!==null && hrtime(true)>=$deadline)) return false;
        $card=$cards[$at];$bag=[];
        foreach ($eligible[$card] as $id) for($i=0;$i<$capacity[$id];$i++) $bag[]=$id;
        $choices=[];
        while ($bag) { $id=$bag[hearts_expert_rand($seed,count($bag))];$choices[]=$id;$bag=array_values(array_filter($bag,static fn($v)=>$v!==$id)); }
        foreach ($choices as $id) {
            $capacity[$id]--; $hands[$id][]=$card;
            if ($assign($at+1)) return true;
            array_pop($hands[$id]);$capacity[$id]++;
        }
        return false;
    };
    if (!$assign(0)) return null;
    $widow=$hands[0]??[];unset($hands[0]);$hands[$o['actor']]=$o['hand'];
    return ['hands'=>$hands,'widow'=>$widow];
}

/** Reconstruct a simulation only from the observation and a hypothetical allocation. */
function hearts_expert_state(array $o, array $world): array
{
    $s=hearts_initial_state(range(1,$o['playerCount']),['settings'=>['shootTheMoon'=>$o['moonEnabled']]]);
    $s['turnOrder']=$o['turnOrder'];$s['turnIndex']=$o['turnIndex'];$s['hands']=$world['hands'];$s['widow']=$world['widow'];
    $s['captured']=array_fill_keys($o['turnOrder'],[]);$s['tricksWon']=array_fill_keys($o['turnOrder'],0);$s['scores']=$o['scores'];
    $s['phase']=$o['phase'];$s['handNumber']=1;$s['trickNumber']=$o['trickNumber'];$s['currentTrick']=$o['trick'];
    $s['botPublicPlays']=$o['played'];$s['passDirection']=$o['passDirection'];$s['passCount']=$o['passCount'];
    $s['heartsBroken']=$o['heartsBroken'];$s['playSequence']=count($o['played']);
    foreach (array_chunk($o['played'],$o['playerCount']) as $trick) if (count($trick)===$o['playerCount']) {
        $winner=hearts_trick_winner($trick);$s['captured'][$winner]=array_merge($s['captured'][$winner],array_column($trick,'card'));$s['tricksWon'][$winner]++;
    }
    return $s;
}

function hearts_expert_normal(array $o): array
{
    $o['difficulty']='normal';return hearts_bot_choose($o);
}

function hearts_expert_pass_candidates(array $o, array $fallback): array
{
    $normal=$o;$normal['difficulty']='normal';$base=hearts_expert_normal($normal);$ranked=array_keys($base['candidateScores']);
    $pool=array_slice($ranked,0,min(count($ranked),6));$count=$o['passCount'];$sets=[$fallback['payload']['cards']];
    $groups=[];
    foreach (['C','D','H','S'] as $suit) {
        $group=array_values(array_filter($o['hand'],static fn($c)=>$c[0]===$suit));
        if ($group && count($group)<=$count) { foreach($ranked as $c)if(!in_array($c,$group,true)&&count($group)<$count)$group[]=$c;$groups[]=$group; }
    }
    // Keep a small diverse set: baseline, complete short-suit passes and one-card substitutions.
    foreach ($groups as $g) $sets[]=$g;
    foreach ($pool as $card) if (!in_array($card,$sets[0],true)) { $g=$sets[0];$g[count($g)-1]=$card;$sets[]=$g; }
    $out=[];foreach($sets as $g){sort($g);$out[implode(',',$g)]=['action'=>'pass','payload'=>['cards'=>$g]];}
    return array_slice(array_values($out),0,6);
}

function hearts_expert_simulate(array $s, int $actor, array $candidate, ?int $deadline): ?array
{
    if ($s['phase']==='passing') {
        // Every simulated player chooses from their pre-exchange hand; real pending passes are absent.
        $passes=[];
        foreach($s['turnOrder'] as $id){$s['turnIndex']=array_search($id,$s['turnOrder'],true);$passes[$id]=$id===$actor?$candidate['payload']['cards']:hearts_expert_normal(hearts_bot_observation($s,$id))['payload']['cards'];}
        $s['pendingPasses']=$passes;hearts_apply_passes($s);
    } else $s=hearts_apply_action_core($s,$actor,'play',$candidate['payload'],['nowUnixMs'=>0])['state'];
    while (!in_array($s['phase'],['deal','completed'],true)) {
        if ($deadline!==null && hrtime(true)>=$deadline) return null;
        $id=$s['turnOrder'][$s['turnIndex']];
        if ($s['phase']==='settling') $c=['action'=>'settle-trick','payload'=>[]];
        else $c=hearts_expert_normal(hearts_bot_observation($s,$id));
        $s=hearts_apply_action_core($s,$id,$c['action'],$c['payload'],['nowUnixMs'=>$s['settlement']['settleAfterUnixMs']??0])['state'];
    }
    return $s['scores'];
}

function hearts_expert_utility(array $scores, array $o): float
{
    $own=$scores[$o['actor']];$others=$scores;unset($others[$o['actor']]);
    $cost=$own-$o['scores'][$o['actor']];
    // Penalty control first; near the target account for winning the actual match.
    $target=$o['playerCount']===2?50:100;
    if (max($scores)>=$target) {
        $best=min($scores);$ties=count(array_filter($scores,static fn($v)=>$v===$best));
        if ($ties===1) $cost+=($own===$best?-30:30);
    }
    return $cost-0.12*(array_sum($others)-array_sum(array_diff_key($o['scores'],[$o['actor']=>true])))/count($others);
}

function hearts_expert_choose(array $o, ?int $budgetMs = HEARTS_EXPERT_BUDGET_MS, int $worldLimit = 8): array
{
    $start=hrtime(true);$deadline=$budgetMs===null?null:$start+max(0,$budgetMs)*1000000;$fallback=hearts_expert_normal($o);
    if (!in_array($o['phase'],['passing','playing'],true) || ($o['phase']==='playing'&&count($o['legal'])<=1)) return $fallback+['expert'=>['version'=>HEARTS_EXPERT_PILOT_VERSION,'worlds'=>0,'fallback'=>true,'reason'=>'phase-or-forced']];
    $candidates=$o['phase']==='passing'?hearts_expert_pass_candidates($o,$fallback):array_map(static fn($c)=>['action'=>'play','payload'=>['card'=>$c]],array_slice(array_keys($fallback['candidateScores']),0,6));
    $outcomes=array_fill(0,count($candidates),[]);$completed=0;
    for ($i=0;$i<$worldLimit;$i++) {
        if ($deadline!==null&&hrtime(true)>=$deadline) break;
        $world=hearts_expert_world($o,$i,$deadline);if($world===null)break;$state=hearts_expert_state($o,$world);$batch=[];
        foreach($candidates as $key=>$candidate){$scores=hearts_expert_simulate($state,$o['actor'],$candidate,$deadline);if($scores===null)break;$batch[$key]=hearts_expert_utility($scores,$o);}
        if(count($batch)!==count($candidates))break; // Never compare candidates with unequal samples.
        foreach($batch as $key=>$value)$outcomes[$key][]=$value;$completed++;
    }
    $best=0;$means=[];
    if($completed>=3)foreach($outcomes as $key=>$values){$means[$key]=array_sum($values)/$completed;if($means[$key]<$means[$best]-0.5)$best=$key;}
    $choice=$best===0?$fallback:$candidates[$best]+['reason'=>'expert-public-hand-rollouts','candidateScores'=>[]];
    $choice['expert']=['version'=>HEARTS_EXPERT_PILOT_VERSION,'worlds'=>$completed,'candidates'=>$candidates,'expectedCosts'=>$means,'fallback'=>$best===0,'elapsedMs'=>(hrtime(true)-$start)/1000000,'budgetMs'=>$budgetMs];
    return $choice;
}
