<?php
declare(strict_types=1);

/** Remove clock origins and randomness bookkeeping, not game outcomes or positions. */
function game_review_comparable(array $state): array
{
    $omit=['_framework','realtime','seed','queue','bag','bagNumber','inputHashes','usedRandomnessRequestIds','readySeed','reshuffleSeed','animationUntil','serverNow'];
    foreach($state as $key=>$value){
        if(in_array((string)$key,$omit,true)||preg_match('/(?:At|AtMs|UnixMs|Deadline)$/',(string)$key)){unset($state[$key]);continue;}
        if(is_array($value))$state[$key]=game_review_comparable($value);
    }
    if(!array_is_list($state))ksort($state);
    return $state;
}

function game_review_rule_check(PDO $pdo,array $user,string $caseId,string $pack): array
{
    $path=__DIR__.'/game_review_baseline_v5.json';
    $baseline=is_file($path)?json_decode((string)file_get_contents($path),true):[];
    $saved=$baseline['cases'][$caseId]??null;
    if(!$saved)return ['status'=>'not-saved','message'=>'No frozen rules reference is available for this example.'];
    $r=game_review_create($pdo,$user,$caseId,$pack);
    $initial=game_review_comparable($r['state']);
    foreach($r['steps'] as $i=>$step)game_review_apply($pdo,$r,$step['actor'],$step['action'],$step['payload'],$step['random']??null,$r['case']['game']==='eight-ball'?1000000+$i*60000:null);
    game_review_settle_reference($pdo,$r);
    $final=game_review_comparable($r['state']);
    $same=json_encode($initial)===json_encode($saved['initial'])&&json_encode($final)===json_encode($saved['final']);
    return ['status'=>$same?'match':'different','message'=>$same?'Prepared actions match the frozen rules reference.':'The current example differs from its frozen rules reference. Review before accepting a replacement.',
        'createdAt'=>$baseline['createdAt'],'source'=>$baseline['source'],'visualLimit'=>'This checks game states and outcomes. Compare the frozen replay or saved media separately for appearance, sound and animation.'];
}

/** Advance a rules-only comparison to the authoritative trick display deadline. */
function game_review_settle_reference(PDO $pdo,array &$review): void
{
    if(in_array($review['case']['game'],['spades','hearts'],true)&&($review['state']['phase']??'')==='settling'){
        $actor=$review['state']['turnOrder'][$review['state']['turnIndex']];
        game_review_apply($pdo,$review,$actor,'settle-trick',[],null,(int)$review['state']['settlement']['settleAfterUnixMs']);
    }
}
