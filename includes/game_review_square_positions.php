<?php
// Allowlisted review positions; moves are applied by the authoritative adapter.
function game_review_square_position(array $s,array $case): array {
 $game=$case['game'];$mode=$case['mode'];$black=$case['color']==='black';$moves=[];
 $s['turnOrder']=[1,2];$s['turnIndex']=0;$s['starterIndex']=0;$s['starterUserId']=1;
 $s['history']=[];$s['board']=array_fill(0,8,array_fill(0,8,null));$s['positionCounts']=[];
 $s['completed']=false;$s['terminalReason']='';$s['winnerUserId']=null;$s['meaningfulPlay']=true;
 if($game==='checkers') {
  // Native dark/blue is side a; white is side b, reflected on the logical board.
  $flip=!$black;$own=$black?'a':'b';$other=$black?'b':'a';
  $s['sideAssignments']=['1'=>$own,'2'=>$other];$s['forcedFrom']=null;
  $pieces=[[2,1,$own],[7,0,$other]];$moves=[[[2,1],[3,2]]];
  if(in_array($mode,['capture','final-capture','chain'],true)) {
   $pieces=[[2,1,$own],[3,2,$other]];$moves=[[[2,1],[4,3]]];
   if($mode!=='final-capture')$pieces[]=[7,0,$other];
   if($mode==='chain'){$pieces[]=[5,4,$other];$moves[]=[[4,3],[6,5]];}
  }elseif($mode==='promotion'){$pieces=[[6,1,$own],[2,7,$other]];$moves=[[[6,1],[7,2]]];}
  elseif($mode==='capture-promotion'){$pieces=[[5,0,$own],[6,1,$other],[6,3,$other]];$moves=[[[5,0],[7,2]]];}
  foreach($pieces as [$r,$c,$piece])$s['board'][$flip?7-$r:$r][$flip?7-$c:$c]=$piece;
  if($flip)foreach($moves as &$move)foreach($move as &$square)$square=[7-$square[0],7-$square[1]];
  unset($move,$square);
 }else{
  $own=$black?'b':'w';$other=$black?'w':'b';$s['colorAssignments']=['1'=>$own,'2'=>$other];
  $s['castling']=['wK'=>false,'wQ'=>false,'bK'=>false,'bQ'=>false];$s['enPassant']=null;$s['halfmoveClock']=0;
  $pieces=[[7,4,$own.'K'],[0,4,$other.'K'],[6,0,$own.'P']];$moves=[[[6,0],[5,0]]];
  if($mode==='capture'){$pieces=[[7,4,$own.'K'],[0,4,$other.'K'],[4,2,$own.'R'],[4,5,$other.'N']];$moves=[[[4,2],[4,5]]];}
  elseif(str_starts_with($mode,'castle-')){$king=$mode==='castle-king';$pieces=[[7,4,$own.'K'],[0,4,$other.'K'],[7,$king?7:0,$own.'R']];$moves=[[[7,4],[7,$king?6:2]]];$s['castling'][$own.($king?'K':'Q')]=true;}
  elseif($mode==='en-passant'){$pieces=[[7,4,$own.'K'],[0,4,$other.'K'],[3,4,$own.'P'],[3,5,$other.'P']];$moves=[[[3,4],[2,5]]];$s['enPassant']=[$black?5:2,5];}
  elseif(str_starts_with($mode,'promotion-')){$pieces=[[7,4,$own.'K'],[0,7,$other.'K'],[1,0,$own.'P']];$moves=[[[1,0],[0,0]]];}
  elseif(in_array($mode,['mate','stalemate'],true)){$pieces=[[0,0,$other.'K'],[2,2,$own.'K'],[2,1,$own.'Q']];$moves=[[[2,1],[1,$mode==='mate'?1:2]]];}
  foreach($pieces as [$r,$c,$piece])$s['board'][$black?7-$r:$r][$c]=$piece;
  if($black)foreach($moves as &$move)foreach($move as &$square)$square=[7-$square[0],$square[1]];
  unset($move,$square);
 }
 if($mode==='draw')$s['drawOfferBy']=2;
 if($mode==='timeout'){
  foreach($s['clocks'] as &$clock)$clock['turnStartedAt']=null;unset($clock);
  $s['clocks']['1']['remainingSeconds']=8;$s['clocks']['1']['turnStartedAt']=gmdate('c');
 }
 return [$s,$moves];
}
