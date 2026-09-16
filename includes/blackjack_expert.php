<?php
declare(strict_types=1);

/** Count only ranks actually exposed on the table. Physical card/deck IDs never enter memory. */
function blackjack_visible_rank_counts(array $s): array {
    $counts=array_fill(2,13,0);
    foreach($s['hands']??[]as$hands)foreach($hands as$hand)foreach($hand['cards']??[]as$card)$counts[blackjack_card_parts($card)['rank']]++;
    foreach($s['dealer']['cards']??[]as$i=>$card)if($i!==1||!empty($s['dealer']['revealed'])||in_array($s['phase']??'',['round-complete','completed'],true))$counts[blackjack_card_parts($card)['rank']]++;
    return $counts;
}
function blackjack_expert_memory(array $before,array $after,string $action): array {
    $old=blackjack_visible_rank_counts($before);$new=blackjack_visible_rank_counts($after);
    $m=$before['publicCardMemory']??['seen'=>$old,'complete'=>false,'wagers'=>[],'lastWagers'=>[]];
    if($action==='deal'&&count($before['shoe']??[])<80){$m['seen']=array_fill(2,13,0);$m['complete']=true;$old=array_fill(2,13,0);}
    for($r=2;$r<=14;$r++)$m['seen'][$r]=(int)($m['seen'][$r]??0)+max(0,$new[$r]-$old[$r]);
    if($action==='bet')foreach($after['hands']as$id=>$hands)if($hands)$m['wagers'][(string)$id]=(int)$hands[0]['bet'];
    if($action==='next-round'){$m['lastWagers']=$m['wagers'];$m['wagers']=[];}
    return $m;
}
/** Weighted draw approximation. Unknown hole is marginalized, never read from private state. */
function blackjack_expert_probabilities(array $o,bool $peekCleared=true): ?array {
    if(empty($o['memory']['complete']))return null;
    $counts=array_fill(2,10,0);
    for($r=2;$r<=14;$r++){
        $seen=$o['memory']['seen'][$r]??null;if(!is_int($seen)||$seen<0||$seen>24)return null;
        $value=$r===14?11:min(10,$r);$counts[$value]+=24-$seen;
    }
    $n=array_sum($counts);if($n<2)return null;
    $up=(int)substr((string)($o['dealerUpcard']??''),1);$up=$up===14?11:min(10,$up);
    $banned=$peekCleared?($up===11?10:($up===10?11:0)):0;
    $allowed=$n-($counts[$banned]??0);if($allowed<=0)return null;
    $hole=[];$draw=[];
    foreach($counts as$r=>$v){$hole[$r]=$r===$banned?0:$v/$allowed;$draw[$r]=($v-$hole[$r])/($n-1);}
    return ['counts'=>$counts,'unknown'=>$n,'hole'=>$hole,'draw'=>$draw,'up'=>$up];
}
function blackjack_expert_add(int $total,bool $soft,int $rank): array {
    $aces=$soft?1:0;if($rank===11)$aces++;$total+=$rank;
    while($total>21&&$aces>0){$total-=10;$aces--;}
    return [$total,$aces>0];
}
/** Bounded memoized weighted evaluation: future draws use fixed public rank proportions.
 * Split/resplit decisions retain the established S17 basic strategy; this is not an exact finite-shoe solver.
 */
function blackjack_expert_scores(array $o): ?array {
    $belief=blackjack_expert_probabilities($o);if(!$belief)return null;$p=$belief['draw'];$dealerMemo=[];
    $dealer=function(int $total,bool $soft)use(&$dealer,&$dealerMemo,$p):array{
        if($total>21)return [22=>1.0];if($total>=17)return [$total=>1.0];$key=$total.':'.(int)$soft;if(isset($dealerMemo[$key]))return $dealerMemo[$key];
        $out=[];foreach($p as$r=>$prob)if($prob>0)foreach($dealer(...blackjack_expert_add($total,$soft,$r))as$end=>$chance)$out[$end]=($out[$end]??0)+$prob*$chance;
        return $dealerMemo[$key]=$out;
    };
    $distribution=[];foreach($belief['hole']as$r=>$prob)if($prob>0){[$t,$soft]=blackjack_expert_add($belief['up'],$belief['up']===11,$r);foreach($dealer($t,$soft)as$end=>$chance)$distribution[$end]=($distribution[$end]??0)+$prob*$chance;}
    $stand=static function(int $t)use($distribution):float{if($t>21)return -1;$v=0;foreach($distribution as$d=>$chance)$v+=$chance*($d>21||$t>$d?1:($t===$d?0:-1));return $v;};
    $memo=[];$play=function(int $total,bool $soft)use(&$play,&$memo,$p,$stand):float{
        if($total>=21)return $stand($total);$key=$total.':'.(int)$soft;if(isset($memo[$key]))return $memo[$key];$hit=0;
        foreach($p as$r=>$prob)if($prob>0)$hit+=$prob*$play(...blackjack_expert_add($total,$soft,$r));
        return $memo[$key]=max($stand($total),$hit);
    };
    $t=(int)$o['value']['total'];$soft=(bool)$o['value']['soft'];$hit=0;$double=0;
    foreach($p as$r=>$prob)if($prob>0){[$nt,$ns]=blackjack_expert_add($t,$soft,$r);$hit+=$prob*$play($nt,$ns);$double+=2*$prob*$stand($nt);}
    return ['stand'=>$stand($t),'hit'=>$hit,'double'=>$double,'surrender'=>-0.5];
}
/** Public standings and wager arithmetic, bounded by table rules. No opponent future decisions are assumed known. */
function blackjack_expert_bet(array $o): int {
    $maximum=min(500,(int)(floor($o['bankroll']/10)*10));$base=max(10,(int)(floor($o['startingChips']/500)*10));
    $own=(int)$o['bankroll'];$leader=0;$threatBet=$base;
    foreach($o['standings']as$id=>$chips)if((int)$id!==(int)$o['actor']){
        if($chips>$leader){$leader=$chips;$threatBet=(int)($o['memory']['wagers'][$id]??$o['memory']['lastWagers'][$id]??$base);}
    }
    $left=max(1,(int)$o['roundsLeft']);$gap=$leader-$own;
    if($gap<0){$lead=-$gap;$target=$lead>2*$threatBet+10?10:$base;}
    elseif($gap===0)$target=$base;
    else $target=$left<=3?$gap+$threatBet+10:$base+$gap/min(4,$left);
    // A favorable visible-card count may justify a modest increase early, never chasing losses by doubling.
    if($left>3&&$gap>=0&&!empty($o['memory']['complete'])){
        $seen=$o['memory']['seen'];$running=0;for($r=2;$r<=14;$r++)$running+=(($r<=6)?1:($r>=10?-1:0))*(int)($seen[$r]??0);
        $decks=max(1,(312-array_sum($seen))/52);$trueCount=$running/$decks;
        if($trueCount>=2)$target=max($target,$base*min(4,floor($trueCount)));
    }
    return max(10,min($maximum,(int)(ceil($target/10)*10)));
}
function blackjack_expert_choose(array $o,array $basic): array {
    if($o['phase']==='betting')return ['action'=>'bet','payload'=>['amount'=>blackjack_expert_bet($o)],'reason'=>'public-standings-and-rounds'];
    if($o['phase']==='insurance'){
        $p=blackjack_expert_probabilities($o,false);$take=$p&&$p['counts'][10]/$p['unknown']>1/3&&$o['bankroll']>=(int)($o['insuranceCost']??PHP_INT_MAX);
        return ['action'=>'insurance','payload'=>['take'=>(bool)$take],'reason'=>'visible-count-insurance'];
    }
    if($o['phase']!=='player-turns'||$basic['action']==='split')return $basic;
    $scores=blackjack_expert_scores($o);if(!$scores)return $basic;
    $scores=array_intersect_key($scores,array_flip($o['legal']));arsort($scores,SORT_NUMERIC);$best=(string)array_key_first($scores);
    // Avoid claiming precision near the approximation's numerical boundaries.
    if(isset($scores[$basic['action']])&&$scores[$best]-$scores[$basic['action']]<0.015)return $basic;
    return ['action'=>$best,'payload'=>[],'reason'=>'public-rank-weighted-value','candidateScores'=>$scores];
}
