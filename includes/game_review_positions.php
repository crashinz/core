<?php
declare(strict_types=1);
require_once __DIR__.'/game_review_square_positions.php';
require_once __DIR__.'/game_review_native_positions.php';
require_once __DIR__.'/game_review_other_positions.php';
require_once __DIR__.'/game_review_pool_positions.php';

function game_review_step(int $actor, string $action, array $payload=[], ?array $random=null): array
{
    return ['actor'=>$actor,'action'=>$action,'payload'=>$payload,'random'=>$random];
}

function game_review_position(array $s,array $case): array
{
    if($case['game']==='eight-ball')return game_review_pool_position($s,$case);
    $game=$case['game'];$mode=$case['mode'];$steps=[];$instruction=$case['instruction'];$expected=$case['expected'];
    if(in_array($game,['chess','checkers'],true)) {
        [$s,$moves]=game_review_square_position($s,$case);
        if($mode==='draw')$steps[] = game_review_step(1,'accept-draw');
        elseif($mode==='resign')$steps[]=game_review_step(1,'resign');
        elseif($mode==='timeout'){$s['clock']=['kind'=>'sudden-death','label'=>'Review clock'];$s['clocks']['1']['remainingSeconds']=0;$s['clocks']['1']['turnStartedAt']=gmdate('c');$steps[]=game_review_step(1,'clock-sync');}
        else foreach($moves as [$from,$to]){$payload=['from'=>$from,'to'=>$to];if(str_starts_with($mode,'promotion-'))$payload['promotion']=strtoupper(substr($mode,-1));$steps[]=game_review_step(1,'move',$payload);}
        $instruction='Play example action to perform the prepared move, or select the piece and its destination on the board. Reset repeats it.';
        $expected=match($mode){'mate'=>'The opposing king is checkmated; the correct losing-king flag appears in Classic.','stalemate'=>'The game ends as a stalemate, with no winner.','draw'=>'The draw offer is accepted and the game ends drawn.','castle-king','castle-queen'=>'Both king and rook finish on their castled squares. Classic places both directly; Built-in moves both together.',default=>'The selected piece moves to the shown legal destination; captures and promotion use the current game presentation.'};
    } elseif(in_array($game,['backgammon-first-party','acey-deucy'],true)) {
        $black=$case['color']==='black';$origin=$black?13:10;$delta=$black?-1:1;$home=$black?0:23;$other=$black?18:5;
        $s['turnOrder']=$black?[2,1]:[1,2];$s['turnIndex']=$black?1:0;$s['starterUserId']=1;$s['openingCoordinatorUserId']=null;
        $stage=$game==='acey-deucy'?'aceyStage':'backgammonStage';
        if(str_starts_with($mode,'european-')){
            if($mode==='european-exact')$mode='final';
            else {$s[$stage]='roll';$dice=$mode==='european-acey'?[1,2]:[3,3];
                if($mode==='european-blocked'){for($i=0;$i<6;$i++)$s['points']['2'][$i]=2;$s['off']['2']=3;}
                $steps=[game_review_step(1,'roll',[] ,['dice'=>$dice])];
                if(in_array($mode,['european-double','european-acey'],true)){
                    $sim=acey_deucy_apply_action($s,1,'roll',[],['mode'=>'practice','status'=>'active','authoritativeRandomness'=>['dice'=>$dice]])['state'];
                    for($n=0;$n<12&&!in_array($sim['aceyStage'],['roll','roll-again'],true);$n++){
                        if($sim['aceyStage']==='choose-double')$next=game_review_step(1,'choose-double',['value'=>3]);
                        else {$projected=acey_deucy_project_state($sim,1,['viewerRole'=>'player','status'=>'active']);$next=null;
                            foreach($projected['interaction']['legalMovesByOrigin'] as $from=>$moves)foreach($moves as $move){$next=game_review_step(1,'move',['from'=>str_starts_with($from,'point:')?(int)substr($from,6):$from,'die'=>$move['die']]);break 2;}
                            if(!$next)throw new RuntimeException('European example has no legal continuation.');
                        }
                        $steps[]=$next;$sim=acey_deucy_apply_action($sim,1,$next['action'],$next['payload'],['mode'=>'practice','status'=>'active'])['state'];
                    }
                }
                return [$s,$steps,'Roll the prepared dice, then use the legal moves. Complementary doubles total seven. Reset repeats this sequence.', $mode==='european-blocked'?'The blocked sequence passes the turn without an extra roll.':'Complete four primary and four complementary moves before earning an extra roll.'];
            }
        }
        if($mode==='roll'){$steps=[game_review_step(1,'roll',[],['dice'=>[3,4]])];return [$s,$steps,'Roll the dice or Play example action.','Two dice appear using the installed game roll presentation.'];}
        $s['points']=['1'=>array_fill(0,24,0),'2'=>array_fill(0,24,0)];$s['bar']=['1'=>0,'2'=>0];$s['off']=['1'=>0,'2'=>0];$s['borneOff']=['1'=>0,'2'=>0];
        $s['points']['1'][$origin]=15;$s['points']['2'][$other]=15;$s['dice']=[1,2];$s['remainingDice']=[1,2];$s[$stage]='moving';
        if($mode==='blocked'){$s['points']['1'][$origin]=14;$s['bar']['1']=1;$s['points']['2']=array_fill(0,24,0);foreach(range(0,5) as $p)$s['points']['2'][$black?23-$p:$p]=2;$s['points']['2'][$black?0:23]=3;$s[$stage]='roll';$s['remainingDice']=[];$steps=[game_review_step(1,'roll',[],['dice'=>[3,4]])];}
        elseif(in_array($mode,['hit','stacked-hit'],true)){$s['points']['2'][$other]=$mode==='stacked-hit'?11:14;$s['points']['2'][$origin+2*$delta]=1;$s['bar']['2']=$mode==='stacked-hit'?3:0;$s['remainingDice']=[2];$steps=[game_review_step(1,'move',['from'=>$origin,'die'=>2])];}
        elseif($mode==='entry'){$s['points']['1'][$origin]=14;$s['bar']['1']=1;$steps=[game_review_step(1,'move',['from'=>'bar','die'=>2])];}
        elseif(in_array($mode,['bear-off','final'],true)){$n=$mode==='final'?1:2;$s['points']['1']=array_fill(0,24,0);$s['points']['1'][$home]=$n;$s['borneOff']['1']=15-$n;$s['dice']=[1,1];$s['remainingDice']=array_fill(0,$n,1);for($i=0;$i<$n;$i++)$steps[]=game_review_step(1,'move',['from'=>$home,'die'=>1]);}
        else $steps=[game_review_step(1,'move',['from'=>$origin,'die'=>2])];
        $instruction='Select your movable checker and its highlighted destination, or Play example action. For two bear-offs, repeat the action after the first animation finishes.';
        $expected=match($mode){'hit','stacked-hit'=>'The captured checker travels to the opponent’s bar; the moving checker lands on its destination.','entry'=>'The bar checker enters the board at the correct point.','bear-off','final'=>'The checker enters its correct winning slot. Remaining stack spacing stays consistent; the final checker is followed by the victory sequence.','blocked'=>'No checker moves, and the turn passes because bar entry is blocked.',default=>'The checker follows the correct direction and lands without disappearing.'};
    } elseif(in_array($game,['battleship','spades'],true)) {
        if($mode==='placement')return [$s,[],'Place, rotate and accept your fleet using the game controls.','Ships align with the grid; placement sounds and legal positioning follow the installed rules.'];
        if($game==='spades'&&$mode==='partner-pass'){
            spades_begin_hand($s,spades_deck(),3);foreach($s['turnOrder'] as $id)$s['bids'][(string)$id]=['kind'=>'standard','amount'=>2,'role'=>'team'];
            $s['bids']['3']=['kind'=>'blind-nil','amount'=>0,'role'=>'team'];$s['blindSelections']['3']='blind-nil';$s['handViewed']['3']=false;$s['teamScores']=['0'=>-100,'1'=>0];spades_prepare_partner_passes($s);
            $steps=[game_review_step(1,'offer-partner-pass',['cards'=>array_slice($s['hands']['1'],0,2)]),game_review_step(3,'respond-partner-pass',['accept'=>true,'returnCards'=>array_slice($s['hands']['3'],-2)])];
            return [$s,$steps,'Play the offer and return in order, or choose your two cards on the table. Reset repeats the exchange.','Partners exchange exactly two cards each way; neither hand loses cards.'];
        }
        $native=$case;$native['mode']=$case['nativeMode']??($mode==='opponent-play'?'play':$mode);
        [$s,$step,$instruction]=game_review_native_position($s,$native);if($step)$steps[]=$step+['random'=>null];
        if($mode==='deal')$steps=[game_review_step($s['turnOrder'][$s['turnIndex']],'deal')];
        $expected=$game==='spades'?'The card/trick moves and score updates follow the selected event. The viewer slide is retained; Classic opponents and collection use direct placement.':'The shot resolves to the prepared miss, hit, sunk ship or victory; the board retains the result.';
    } elseif($game==='five-dice') {
        if($mode==='roll'||$mode==='opponent'){$actor=$mode==='opponent'?2:1;$s['turnIndex']=$actor-1;$steps=[game_review_step($actor,'roll',[],['dice'=>[2,3,4,5,6]])];if($mode==='roll'){$steps[]=game_review_step(1,'toggle-hold',['index'=>0]);$steps[]=game_review_step(1,'roll',[],['dice'=>[1,1,3,3,5]]);}$instruction='Roll or Play example action; select dice to hold, then reroll.';}
        else {
            $s['rollsThisTurn']=1;$s['dice']=[6,6,6,6,6];$s['held']=array_fill(0,5,false);
            if($mode==='upper'){$s['dice']=[6,6,6,1,1];foreach(['ones'=>3,'twos'=>6,'threes'=>9,'fours'=>12,'fives'=>15] as $k=>$v)$s['players']['1']['scorecard'][$k]=$v;}
            if($mode==='repeat')$s['players']['1']['scorecard']['yahtzee']=50;
            $s['players']['1']=array_replace($s['players']['1'],five_dice_recalculate_player($s['players']['1']['scorecard'],0));
            $category=$mode==='yahtzee'?'yahtzee':'sixes';
            if($mode==='record'){
                $category='chance';$s['dice']=[1,2,3,4,5];
                $card=['ones'=>3,'twos'=>6,'threes'=>9,'fours'=>12,'fives'=>15,'sixes'=>18,'three-kind'=>30,'four-kind'=>30,'full-house'=>25,'small-straight'=>30,'large-straight'=>40,'chance'=>null,'yahtzee'=>50];
                foreach([1,2] as $id){$pc=$id===1?$card:array_fill_keys(array_keys($card),0);$s['players'][(string)$id]=array_replace($s['players'][(string)$id],five_dice_recalculate_player($pc,0));$s['players'][(string)$id]['scorecard']=$pc;}
            }
            $steps=[game_review_step(1,'score',['category'=>$category])];$instruction='Choose '.ucfirst($category).' or Play example action. Reset restores the scoring opportunity.';
        }
        $expected='Dice and scoring reactions use the real Five Dice renderer. Review scores are isolated and never change your records.';
    } else return game_review_other_position($s,$case);
    return [$s,$steps,$instruction,$expected];
}
