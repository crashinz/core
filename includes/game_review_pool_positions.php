<?php
declare(strict_types=1);

/** Reproducible Pool positions. All prepared actions use the live rules and physics. */
function game_review_pool_position(array $s,array $case): array
{
    $mode=$case['poolMode'];$nine=$case['variant']==='nine-ball';$steps=[];
    $ball=static fn(int $n,float $x,float $y):array=>(array)EightBallPhysics::ball($n,$x,$y);
    $shot=static fn(float $angle,float $power=50,float $x=0,float $y=0,array $extra=[]):array=>['angle'=>$angle,'power'=>$power,'spinX'=>$x,'spinY'=>$y]+$extra;
    $random=['order'=>range(1,15),'starter'=>0];
    eight_ball_rack($s,$random,0);
    $s['phase']='aim';$s['placement']=null;$s['breakShot']=false;$s['animationUntil']=0;
    $s['balls']=[$ball(0,596,420),$ball(1,596,180),$ball($nine?9:8,900,450)];
    $s['statusText']=$nine?'Hit the 1 ball first.':'Ready for the prepared shot.';
    $payload=$shot(-M_PI/2,40,0,.65);
    $expected='The prepared action follows the live Pool rules; compare ball paths and the resulting turn.';
    if($mode==='advanced'){
        $examples=json_decode((string)file_get_contents(__DIR__.'/../games/eight-ball/review-advanced-shots.json'),true,512,JSON_THROW_ON_ERROR);
        $example=current(array_filter($examples,static fn($e)=>$e['id']===$case['advancedId']));
        if(!$example)throw new RuntimeException('Unknown advanced Pool example.');
        $s['balls']=array_map(static fn($b)=>$ball($b['n'],$b['x'],$b['y']),$example['balls']);
        $q=$example['setup'];$payload=$shot($q['angle'],$q['power'],$q['spin']['x'],$q['spin']['y']);
        $steps=[game_review_step(1,'shot',$payload)];$s['practiceSetup']=$q;$s['practiceVersion']=1;$s['statusText']='Ready for the prepared practice shot.';
        $angle=fmod(rad2deg($q['angle'])+360,360);
        $expected=$example['expected'].' Exact saved inputs are applied by Play example action. Aim '.sprintf('%.6f',$angle).' degrees; power '.sprintf('%.6f',$q['power']).'%; spin X '.sprintf('%.6f',$q['spin']['x']).', Y '.sprintf('%.6f',$q['spin']['y']).'. Negative X is left; negative Y is follow.';
    }elseif($mode==='break'){

        eight_ball_rack($s,$random,0);
        $steps=[game_review_step(1,'place',['x'=>320,'y'=>337]),game_review_step(1,'shot',$shot(0,100))];
        $expected='Place behind the head string, then break the '.($nine?'diamond':'triangle').' rack. The balls disperse and settle.';
    }elseif($mode==='rack'){
        $steps=[game_review_step(1,'rack',['variant'=>$case['variant']],$random),game_review_step(1,'rack',['variant'=>$nine?'eight-ball':'nine-ball'],$random)];
        $expected='Solo practice switches between the diamond 9-ball rack and the triangle 8-ball rack.';
    }elseif($mode==='practice'){
        $layout=['balls'=>[['n'=>0,'x'=>596,'y'=>420],['n'=>1,'x'=>596,'y'=>180],['n'=>$nine?9:8,'x'=>900,'y'=>450]],'variant'=>$case['variant'],'setup'=>['angle'=>-M_PI/2,'power'=>40,'spin'=>['x'=>0,'y'=>.65]]];
        $steps=[game_review_step(1,'practice-edit',$layout),game_review_step(1,'shot',$payload),game_review_step(1,'practice-rewind')];
        $expected='Edit applies the setup, the shot plays, and rewind restores the exact pre-shot table and shot settings.';
    }elseif($mode==='bank'){
        $banks=json_decode((string)file_get_contents(__DIR__.'/../games/eight-ball/bank-setups.json'),true,512,JSON_THROW_ON_ERROR);$bank=$banks[$case['bank']-1];
        $s['balls']=array_map(static fn($b)=>$ball($b['n'],$b['x'],$b['y']),$bank['balls']);
        $setup=$bank['setup'];$payload=$shot($setup['angle'],$setup['power'],$setup['spin']['x'],$setup['spin']['y']);
        $steps=[game_review_step(1,'shot',$payload)];$s['practiceSetup']=$setup;$s['practiceVersion']=1;
        $expected=$bank['name'].': use the exact saved aim, power and spin. A banked ball is pocketed.';
    }elseif(str_starts_with($mode,'spin-')){
        $side=substr($mode,5);$s['balls']=[$ball(0,380,337),$ball(1,650,337),$ball(8,950,500)];
        $payload=$shot(0,55,0,$side==='draw'?.85:-.85);
        if(in_array($side,['left','right'],true)){$s['balls']=[$ball(0,380,337),$ball(8,950,500)];$payload=$shot(-M_PI/2,35,$side==='left'?-.8:.8,0);}
        $steps=[game_review_step(1,'shot',$payload)];$expected='Watch the cue ball '.(in_array($side,['left','right'],true)?'rebound from the cushion with sidespin.':($side==='draw'?'draw back after contact.':'follow forward after contact.'));
    }elseif(in_array($mode,['push-take','push-return'],true)){
        $s['pushOutAvailable']=true;$payload=$shot(M_PI,10);
        $steps=[game_review_step(1,'push-out'),game_review_step(1,'shot',$payload),game_review_step(2,'choice',['choice'=>$mode==='push-take'?'take':'return'])];
        $expected='A declared legal push-out does not require contact. The opponent '.($mode==='push-take'?'takes':'returns').' the next shot.';
    }elseif($mode==='combo'){
        $s['balls']=[$ball(0,596,420),$ball(1,596,300),$ball(9,596,150)];
        $payload=$shot(-M_PI/2,50);$steps=[game_review_step(1,'shot',$payload)];$expected='The cue contacts 1 first, which pockets the 9 for a legal win.';
    }elseif(in_array($mode,['win','called-win','wrong-pocket','early-eight'],true)){
        $s['balls']=[$ball(0,596,420),$ball($nine?9:8,596,180)];
        if(!$nine){$s['groups']=[1=>'solids',2=>'stripes'];$s['balls'][]=$ball(9,900,450);}
        if($mode==='early-eight')$s['balls'][]=$ball(1,800,450);
        if(in_array($mode,['called-win','wrong-pocket'],true)){
            $call=['calledBall'=>8,'calledPocket'=>$mode==='called-win'?1:0,'safety'=>false];$steps[]=game_review_step(1,'call',$call);$payload+=$call;
        }
        $steps[]=game_review_step(1,'shot',$payload);
        $expected=in_array($mode,['wrong-pocket','early-eight'],true)?'Pocketing the 8 under these conditions loses the match.':'The final ball is pocketed legally and the match ends with a win.';
    }elseif($mode==='foul-nine'){
        $fixture=json_decode((string)file_get_contents(__DIR__.'/game_review_pool_foul_nine.json'),true,512,JSON_THROW_ON_ERROR);
        $s['balls']=$fixture['balls'];$payload=$fixture['payload'];$steps=[game_review_step(1,'shot',$payload)];
        $expected='The cue ball and 9 are pocketed on the same shot. The 9 is re-spotted and the opponent gets ball in hand.';
    }elseif(in_array($mode,['scratch','no-rail','wrong-first','third-foul'],true)){
        if($mode==='scratch'){$s['balls']=[$ball(0,596,220),$ball(1,850,450),$ball($nine?9:8,950,450)];$payload=$shot(-M_PI/2,30);}
        elseif($mode==='wrong-first'){$s['balls']=[$ball(0,400,337),$ball(2,500,337),$ball(1,800,450),$ball(9,950,450)];$payload=$shot(0,30);}
        else {$s['balls']=[$ball(0,400,337),$ball(1,435,337),$ball($nine?9:8,950,450)];$payload=$shot(0,5);}
        if($mode==='third-foul')$s['foulCounts']=[1=>2,2=>0];
        $steps=[game_review_step(1,'shot',$payload)];
        if($mode==='scratch')$steps[]=game_review_step(2,'place',['x'=>400,'y'=>300]);
        $expected=$mode==='third-foul'?'The warned player commits a third consecutive foul and loses.':'The foul passes the turn and gives the opponent ball in hand.';
    }else $steps=[game_review_step(1,'shot',$payload)];
    foreach($steps as $prepared){if($prepared['action']!=='shot')continue;$p=$prepared['payload'];$s['practiceSetup']=['angle'=>$p['angle'],'power'=>$p['power'],'spin'=>['x'=>$p['spinX'],'y'=>$p['spinY']]];$s['practiceVersion']=1;break;}
    return [$s,$steps,'Use Play example action for each prepared step. Wait for the balls to stop before the next step. Reset repeats the same position, aim, power and spin.',$expected];
}
