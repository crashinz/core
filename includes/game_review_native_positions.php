<?php
function game_review_native_position(array $s,array $case): array {
 $mode=$case['mode'];$actor=$case['view']==='opponent'?2:1;
 $s['completed']=false;$s['meaningfulPlay']=true;$s['turnIndex']=$actor-1;
 if($case['game']==='battleship') {
  $s['phase']='battle';$s['turnOrder']=[1,2];$s['starterIndex']=0;$s['starterUserId']=1;$s['attackHistory']=[];
  $vertical=str_contains($mode,'vertical');$length=(int)(explode('-',$mode)[1]??5);if(!$length)$length=5;
  $input=[];for($i=1;$i<=5;$i++)$input[]=['length'=>$i,'row'=>$vertical?0:($i-1)*2,'column'=>$vertical?($i-1)*2:0,'orientation'=>$vertical?'vertical':'horizontal'];
  $ships=battleship_validate_fleet($input,false);
  foreach([1,2] as $id)$s['fleets'][(string)$id]=['placed'=>true,'accepted'=>true,'ships'=>$ships,'attacksReceived'=>[]];
  $target=3-$actor;$cell=$mode==='miss'?'9:9':$ships[$length-1]['cells'][$mode==='hit'?0:$length-1];
  foreach($s['fleets'][(string)$target]['ships'] as &$ship) {
   if(str_starts_with($mode,'win')||str_starts_with($mode,'sunk')&&$ship['length']===$length) {
    foreach($ship['cells'] as $key)if($key!==$cell){$ship['hits'][]=$key;$s['fleets'][(string)$target]['attacksReceived'][$key]='hit';}
   }
  }unset($ship);
  [$row,$column]=array_map('intval',explode(':',$cell));
  return [$s,['actor'=>$actor,'action'=>'attack','payload'=>['row'=>$row,'column'=>$column]],'Attack row '.($row+1).', column '.($column+1).'.'];
 }
 if($mode==='deal')return [$s,null,'Start deals a hand through the real game. Review the hand appearing and the bidding phase.'];
 spades_begin_hand($s,spades_deck(),3);$s['phase']='playing';$s['turnIndex']=$actor-1;$s['leaderIndex']=$actor-1;$s['spadesBroken']=true;
 $s['bids']=[];foreach([1,2,3,4] as $id){$s['bids'][(string)$id]=['kind'=>'standard','amount'=>1,'role'=>'team'];$s['handViewed'][(string)$id]=true;}
 $s['teamBids']=['0'=>2,'1'=>2];$s['tricksWon']=['1'=>0,'2'=>0,'3'=>0,'4'=>0];
 $s['hands']=['1'=>['C5','D2','S2'],'2'=>['C2','D3','S3'],'3'=>['C3','D4','S4'],'4'=>['C4','D5','S5']];
 $s['currentTrick']=[];$s['lastCompletedTrick']=null;$s['settlement']=null;$s['history']=[];
 $card=$actor===1?'C5':'C2';
 if(in_array($mode,['ordinary-trick','nil-set','last-trick'],true)) {
  $s['currentTrick']=[['userId'=>2,'card'=>'C2'],['userId'=>3,'card'=>'C3'],['userId'=>4,'card'=>'C4']];
  foreach([2,3,4] as $id)array_shift($s['hands'][(string)$id]);
  $s['playSequence']=3;$s['leaderIndex']=1;$s['turnIndex']=0;
  if($mode==='nil-set')$s['bids']['1']=['kind'=>'nil','amount'=>0,'role'=>'team'];
  if($mode==='last-trick'){$s['hands']=['1'=>['C5'],'2'=>[],'3'=>[],'4'=>[]];$s['tricksWon']=['1'=>3,'2'=>3,'3'=>3,'4'=>3];}
 }
 spades_finalize_team_bid($s,0);spades_finalize_team_bid($s,1);
 return [$s,['actor'=>$actor,'action'=>'play','payload'=>['card'=>$card]],'Play '.$card.' to review '.str_replace('-',' ',$mode).'.'];
}
