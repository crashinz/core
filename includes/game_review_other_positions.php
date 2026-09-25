<?php
declare(strict_types=1);

function game_review_other_position(array $s,array $case): array
{
    $game=$case['game'];$mode=$case['mode'];$steps=[];$instruction='Use the game controls or Play example action, then Reset to repeat.';
    $expected=$case['label'].' resolves using the real game rules and renderer.';
    if($game==='dominos') {
        $actor=$mode==='loss'?2:1;
        $s['phase']='playing';$s['roundNumber']=1;$s['turnIndex']=$actor-1;
        $s['root']='5-5';$s['spinner']=true;
        $s['hands']=[1=>['0-5','1-2'],2=>['2-5','1-3']];
        $s['hands'][$actor]=['0-5','1-2'];$s['hands'][$actor===1?2:1]=['2-5','1-3'];
        $s['drawPile']=array_values(array_diff(dominos_deck(),array_merge(...array_values($s['hands'])),[$s['root']]));
        if($mode!=='play')$s['scores'][dominos_group($s,$actor)]=$s['targetScore']-10;
        $steps=[game_review_step($actor,'play',['tile'=>'0-5','end'=>'east'])];
        $instruction=$mode==='loss'?'The opponent plays the winning tile automatically after the board appears. You can also click Play opponent winning tile. Reset repeats the position.':'Play example action to place the prepared tile. Reset repeats the position.';
        $expected=$mode==='play'?'The tile connects to the double five and scores ten points.':($mode==='loss'?'The opponent reaches the target score. You lose is shown.':'You reach the target score. You win and the celebration appear.');
    } elseif($game==='hearts') {
        if($mode==='deal')$steps=[game_review_step(1,'deal')];
        elseif($mode==='pass'){hearts_begin_hand($s,hearts_deck(4));foreach($s['turnOrder'] as $id)$steps[]=game_review_step($id,'pass',['cards'=>array_slice($s['hands'][(string)$id],0,3)]);$expected='Each player passes three cards; every hand retains thirteen cards and play opens with the two of clubs.';}
        else {
            hearts_begin_hand($s,hearts_deck(4));$s['phase']='playing';$s['turnIndex']=0;$s['leaderIndex']=1;$s['trickNumber']=12;$s['heartsBroken']=true;
            $s['hands']=['1'=>['S14'],'2'=>[],'3'=>[],'4'=>[]];$s['currentTrick']=[['userId'=>2,'card'=>'S2'],['userId'=>3,'card'=>$mode==='queen'?'S12':'S3'],['userId'=>4,'card'=>'S4']];
            if($mode==='play')$s['hands']['1'][]='D2';
            if($mode==='moon')$s['captured']['1']=array_merge(array_map(static fn($n)=>'H'.$n,range(2,14)),['S12']);
            if($mode==='finish'){$s['scores']=['1'=>99,'2'=>10,'3'=>20,'4'=>30];$s['currentTrick'][1]['card']='H2';}
            $steps=[game_review_step(1,'play',['card'=>'S14'])];$expected='The completed trick is collected, penalties are assigned, and the hand/game result appears when appropriate.';
        }
    } elseif($game==='uno') {
        if($mode==='deal')$steps=[game_review_step(2,'deal')];
        else {
            uno_begin_hand($s,uno_derive_randomness('{"nonce":"review"}','deal',[],[]));$s['phase']='playing';$s['turnIndex']=0;$s['currentColor']='R';$s['discardPile']=['R:5:1'];$s['drawnCardId']=null;
            $card=match($mode){'reverse'=>'R:R:1','skip'=>'R:S:1','wild'=>'W:W:1','draw-four'=>'W:D4:1',default=>'R:7:1'};
            $s['hands']['1']=$mode==='finish'?[$card]:[$card,'B:2:1'];$s['hands']['2']=['Y:8:1','G:9:1'];$s['drawPile']=array_values(array_diff(uno_deck(),array_merge(array_merge(...array_values($s['hands'])),$s['discardPile'])));
            $s['unoDeclared']['1']=$mode==='finish';if($mode==='finish')$s['scores']['1']=$s['targetScore']-1;$steps=[game_review_step(1,$mode==='draw'?'draw':'play',$mode==='draw'?[]:['card'=>$card,'color'=>'G'])];
            $expected='The selected card effect is applied to turn direction, color or draws; a final card scores the hand.';
        }
    } elseif($game==='chinese-checkers') {
        if($mode==='finish'){
            $targets=chinese_checkers_target_holes($s,1);$geometry=chinese_checkers_hole_geometry();$found=false;
            foreach($targets as $target)foreach($geometry['holes'] as $from=>$unused){
                if(in_array($from,$targets,true))continue;$try=$s;$try['board']=array_fill_keys($targets,1);unset($try['board'][$target]);$try['board'][$from]=1;
                foreach(chinese_checkers_target_holes($s,2) as $hole)if(!isset($try['board'][$hole]))$try['board'][$hole]=2;
                if(isset(chinese_checkers_legal_moves_for_piece($try,1,$from)[$target])){$s=$try;$steps=[game_review_step(1,'move',['from'=>$from,'to'=>$target])];$found=true;break 2;}
            }
            if(!$found)throw new RuntimeException('Final marble position unavailable.');
        } else {
            if(in_array($mode,['jump','chain'],true)){
                $geometry=chinese_checkers_hole_geometry();
                foreach($geometry['holes'] as $from=>$unused){$p1=chinese_checkers_coordinate_neighbor($from,0,2);$p2=chinese_checkers_coordinate_neighbor($from,0,4);$p3=chinese_checkers_coordinate_neighbor($from,0,6);$p4=chinese_checkers_coordinate_neighbor($from,0,8);if(!$p4||!$p1||!$p2||!$p3)continue;$s['board']=[$from=>1,$p1=>2,$p3=>2];$to=$mode==='chain'?$p4:$p2;$reserved=[$from,$p1,$p2,$p3,$p4];
                foreach([1=>9,2=>8] as $owner=>$remaining)foreach(array_reverse(array_keys($geometry['holes'])) as $hole){if($remaining===0)break;if(isset($s['board'][$hole])||in_array($hole,$reserved,true))continue;$s['board'][$hole]=$owner;$remaining--;}
                if(isset(chinese_checkers_legal_moves_for_piece($s,1,$from)[$to])){$steps=[game_review_step(1,'move',compact('from','to'))];break;}}
            } else foreach(chinese_checkers_legal_moves($s,1) as $from=>$moves)foreach($moves as $to=>$move)if($move['kind']==='step'){$steps=[game_review_step(1,'move',compact('from','to'))];break 2;}
        }
        $expected='The marble follows a legal step or jump path without removing jumped marbles; filling the destination triangle wins.';
    } elseif($game==='nested-four') {
        if($mode==='finish'){for($i=0;$i<3;$i++)$s['board'][$i]=[array_pop($s['reserves']['1'][$i])];}
        if($mode==='move'){$s['board'][0]=[array_pop($s['reserves']['2'][0]),array_pop($s['reserves']['1'][0])];$steps[] = game_review_step(1,'select',['sourceType'=>'board','sourceIndex'=>0]);}
        else $steps[]=game_review_step(1,'select',['sourceType'=>'reserve','sourceIndex'=>0]);
        $steps[]=game_review_step(1,'move',['destination'=>$mode==='finish'?3:5]);$expected='The selected piece covers or uncovers the correct stack; four visible pieces in a row wins.';
    } elseif($game==='blackjack') {
        if($mode==='deal'){$steps=[game_review_step(1,'bet',['amount'=>10]),game_review_step(2,'bet',['amount'=>10]),game_review_step(1,'deal')];}
        else {
            $s['phase']='player-turns';$s['turnIndex']=0;$s['hands']['1']=[blackjack_new_hand(10,$mode==='split'?['C8:0','D8:0']:['C5:0','D6:0'])];$s['hands']['2']=[];$s['dealer']=['cards'=>['H10:0','S7:0'],'revealed'=>false];$s['shoe']=array_values(array_diff(blackjack_shoe(),array_merge($s['hands']['1'][0]['cards'],$s['dealer']['cards'])));$s['shoeCount']=count($s['shoe']);
            $settle=in_array($mode,['finish','win','loss'],true);
            if(in_array($mode,['win','loss'],true)){$s['round']=$s['settings']['rounds'];$s['bankrolls']['1']=$mode==='win'?1200:800;}
            $steps=[game_review_step(1,$settle?'stand':$mode)];if($settle)$steps[]=game_review_step(1,'dealer-play');$expected='The prepared player choice deals or settles the correct hands and chips, without writing normal records.';
            if(in_array($mode,['win','loss'],true))$expected=$mode==='win'?'The final round settles. You win and the celebration appear.':'The final round settles. The opponent wins and You lose appears.';
        }
    } elseif($game==='puppy-panic') {
        if($mode==='deal')$steps=[game_review_step(1,'deal')];
        else {
            puppy_panic_begin_deal($s,puppy_panic_derive_randomness('{"nonce":"review"}','deal',[],[]));$s['turnIndex']=0;$s['phase']='playing';
            if($mode==='finish'){$s['hands']['1']=[];$s['phase']='chaos';$s['pendingChoice']=['kind'=>'chaos','actorUserId'=>1,'calmCards'=>[]];$steps=[game_review_step(1,'eliminate')];}
            elseif($mode==='power'){foreach(puppy_panic_catalog() as $id=>$card)if($card['effect']==='nap'){$s['hands']['1'][]=$id;$steps=[game_review_step(1,'play',['card'=>$id])];break;}}
            else $steps=[game_review_step(1,'draw')];
            $expected='The selected action updates the hand and turn; the last remaining player wins after elimination.';
        }
    } elseif($game==='tetris-versus') {
        foreach($s['boards'] as &$b){$b['seed']=str_repeat('a',64);$b['queue']=['O','I','T','S','Z','J','L'];$b['active']=['kind'=>'T','rotation'=>0,'col'=>3,'row'=>0];}unset($b);
        $s['realtime']['lastAtMs']=(int)floor(microtime(true)*1000);
        if($mode==='clear'){$s['boards']['1']['cells'][19]=array_fill(0,10,'J');for($x=3;$x<7;$x++)$s['boards']['1']['cells'][19][$x]='';$s['boards']['1']['active']=['kind'=>'I','rotation'=>0,'col'=>3,'row'=>16];}
        if($mode==='finish'){$s['boards']['1']['cells'][0][4]='J';$s['boards']['1']['active']=['kind'=>'O','rotation'=>0,'col'=>3,'row'=>16];}
        $steps=[game_review_step(1,'arcade-input',['sequence'=>1,'commands'=>$mode==='move'?['left','cw']:['drop']])];$expected='The active piece moves/rotates, clears a line, or tops out through the actual arcade simulation.';
    } elseif($game==='space-invasion') {
        $s['realtime']['lastAtMs']=(int)floor(microtime(true)*1000)-100;
        if($mode==='hit'){$s['levelElapsedMs']=0;$s['orbs']=[['x'=>100.0,'y'=>500.0]];}
        if($mode==='finish')$s['levelElapsedMs']=35000;
        $steps=[game_review_step(1,'arcade-input',['sequence'=>1,'frameStart'=>0,'frames'=>[['ticks'=>50,'left'=>false,'right'=>true,'fire'=>true]]])];
        $expected='The ship and shot move using the current arcade simulation.';
    }
    if(!$steps)throw new RuntimeException('No prepared action for '.$case['id']);
    return [$s,$steps,$instruction,$expected];
}
