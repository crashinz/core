<?php
declare(strict_types=1);
require_once __DIR__.'/ocx_game_extension_support.php';
require_once __DIR__.'/dominos_bot_support.php';

function dominos_extension_adapter(): array {
    return ['id'=>'dominos','initialState'=>'dominos_initial_state','applyAction'=>'dominos_apply_action',
        'validateSettings'=>'dominos_validate_settings','settingsProjection'=>'dominos_settings_projection',
        'rulesProjection'=>'dominos_rules_projection','projectState'=>'dominos_project_state',
        'projectVirtualMembers'=>'dominos_project_virtual_members','recordingAdapter'=>'dominos_recording_adapter',
        'randomnessPurposes'=>['deal'=>'dominos-deal','bot-deal'=>'dominos-deal'],
        'deriveRandomness'=>'dominos_derive_randomness','presentationStatus'=>'dominos_presentation_status',
        'openingProcedure'=>'random-without-replacement-alternating-teams','rematchSeatRotation'=>true];
}
function dominos_presentation_status(PDO $pdo,?string $pack=null): array {
    return ['requestedPack'=>'built-in','effectivePack'=>'built-in','classicAvailable'=>false,'fallbackApplied'=>false,'presentationOnly'=>true,'mediaPack'=>['publicCodeNative'=>true,'remoteMedia'=>false]];
}
function dominos_fail(string $text,string $code='ACTION_INVALID',int $status=422): never {throw new MultiplayerGameException($text,'DOMINOS_'.$code,$status);}
function dominos_validate_settings(array $s,string $mode,array $definition=[]): array {
    $allowed=['tableMode','winningScore']; for($i=1;$i<=4;$i++)$allowed[]='botSeat'.$i.'Difficulty';
    if(array_diff(array_keys($s),$allowed))dominos_fail('An unsupported game option was supplied.','SETTINGS_INVALID');
    $table=$s['tableMode']??'individual';if(!in_array($table,['individual','teams'],true))dominos_fail('Choose individual play or teams.','SETTINGS_INVALID');
    // Zero means the documented player-count default, resolved once on start.
    $target=filter_var($s['winningScore']??0,FILTER_VALIDATE_INT);
    if($target===false||$target<0||$target>100000||$target%50!==0)dominos_fail('Winning score must be in steps of 50.','SETTINGS_INVALID');
    $out=['tableMode'=>$table,'winningScore'=>$target];
    for($i=1;$i<=4;$i++){$k='botSeat'.$i.'Difficulty';$v=$s[$k]??'none';if(!in_array($v,array_column(dominos_bot_choices(),'value'),true))dominos_fail('Choose a listed bot level.','SETTINGS_INVALID');if($v!=='none'&&$mode!=='practice')throw new MultiplayerGameException('Games with bots are Practice only.','MULTIPLAYER_GAME_BOTS_PRACTICE_ONLY',422);if($mode==='practice')$out[$k]=$v;}
    return $out;
}
function dominos_settings_projection(array $s,string $mode,array $definition=[]): array {
    $s=dominos_validate_settings($s,$mode);
    return ['label'=>'Game Options','description'=>'All Fives for two to four players, or four players in opposite partnerships. Add optional bots to empty lobby seats.','controls'=>[
        ['key'=>'tableMode','type'=>'button-choice','label'=>'Table','description'=>'Individual scoring or opposite-seat partnerships.','value'=>$s['tableMode'],'defaultValue'=>'individual','options'=>[['value'=>'individual','label'=>'Individual'],['value'=>'teams','label'=>'Teams (four players)']]],
        ['key'=>'winningScore','type'=>'stepper','label'=>'Winning score','value'=>$s['winningScore'],'defaultValue'=>0,'minimum'=>0,'maximum'=>100000,'step'=>50,'shortcuts'=>[['value'=>0,'label'=>'Auto'],['value'=>200,'label'=>'200'],['value'=>250,'label'=>'250']],'description'=>'Auto chooses 250 for two players, 200 for three/four players. Choose 50 or more in steps of 50 for a custom target.']]];
}
function dominos_rules_projection(array $s,string $mode,array $definition=[]): array {
    return ['label'=>'All Fives rules','description'=>'Match tile ends and score multiples of five.','sections'=>[
        ['label'=>'Deal','text'=>'A double-six set has 28 tiles. Deal 9 each for two players, 7 each for three, or 5 each for four. Remaining tiles form the draw pile. Partners occupy opposite seats.'],
        ['label'=>'Starting','text'=>'Each player starts once in a shuffled cycle. Team games alternate starting teams, choosing an unused member. The starter may play any tile. Turns then follow seat order.'],
        ['label'=>'Play and draw','text'=>'Play if you can. Otherwise draw one tile at a time until you can play, or pass when the draw pile is empty. Select a tile and choose a highlighted end, or drag it there.'],
        ['label'=>'Spinner','text'=>'Only the first double is a spinner, even if played later. Both long sides must have a tile before its top and bottom arms open. Other doubles extend only their existing arm.'],
        ['label'=>'Scoring','text'=>'Exposed ends that total a positive multiple of five score that total. An exposed double counts both halves. A spinner counts both halves until both long sides are used; unused top/bottom arms add nothing.'],
        ['label'=>'Round end','text'=>'Empty your hand to win the round. If everyone passes, the smallest remaining pip total wins (combined for teams). Score opposing pips rounded to the nearest five. A blocked tie scores nothing, except two tied players in a three-player game each receive half the third hand, rounded to the nearest five.'],
        ['label'=>'Match end','text'=>'Reaching the target ends the match immediately. Remaining tiles are not added after a mid-round win. Bot games are always Practice.']]];
}
function dominos_deck(): array {$d=[];for($a=0;$a<=6;$a++)for($b=$a;$b<=6;$b++)$d[]="$a-$b";return $d;}
function dominos_pips(string $tile): array {return array_map('intval',explode('-',$tile));}
function dominos_pip_sum(array $hand): int {return array_sum(array_map(static fn($t)=>array_sum(dominos_pips($t)),$hand));}
function dominos_derive_randomness(string $reveal,string $action,array $payload,array $context): array {
    return ['deck'=>ocx_game_random_permutation($reveal,dominos_deck(),'dominos-deal'),'seed'=>hash('sha256',$reveal.'|dominos-starter')];
}
function dominos_initial_state(array $humans,array $context=[]): array {
    $mode=$context['mode']??'practice';$s=dominos_validate_settings($context['settings']??[],$mode);$seats=$context['humanSeats']??[];
    if(!$humans||count(array_unique($humans))!==count($humans)||min($humans)<=0)dominos_fail('Authenticated players are required.','PLAYERS_INVALID');
    if(!$seats)foreach($humans as$i=>$id)$seats[$i+1]=(int)$id;
    $players=[];$bots=[];$seatMap=[];
    for($seat=1;$seat<=4;$seat++){
        $id=(int)($seats[$seat]??0);$level=$s['botSeat'.$seat.'Difficulty']??'none';
        if($id>0&&in_array($id,$humans,true)){$players[]=$id;$seatMap[$id]=$seat;}
        elseif($mode==='practice'&&$level!=='none'){$id=-7400-$seat;$players[]=$id;$seatMap[$id]=$seat;$bots[$id]=['userId'=>$id,'seat'=>$seat,'difficulty'=>$level,'displayName'=>ucfirst($level).' Bot '.$seat,'engine'=>DOMINOS_BOT_ENGINE];}
    }
    if(count($players)<2||count($players)>4||($s['tableMode']==='teams'&&count($players)!==4))dominos_fail('Use two to four players, or exactly four for teams.','PLAYERS_INVALID',409);
    $teams=[];foreach($players as$id)$teams[$id]=$s['tableMode']==='teams'?($seatMap[$id]-1)%2:(string)$id;
    return ['schemaVersion'=>1,'settings'=>$s,'turnOrder'=>$players,'seatMap'=>$seatMap,'teams'=>$teams,'bots'=>$bots,'turnIndex'=>0,'phase'=>'deal','roundNumber'=>0,'starterPool'=>[],'lastStartingTeam'=>null,
        'targetScore'=>$s['winningScore']?: (count($players)===2?250:200),'scores'=>array_fill_keys(array_values($teams),0),'hands'=>array_fill_keys($players,[]),'drawPile'=>[],
        'root'=>null,'spinner'=>false,'branches'=>['west'=>[],'east'=>[],'north'=>[],'south'=>[]],'passes'=>0,'voids'=>array_fill_keys($players,[]),'sequence'=>0,'lastAction'=>null,'lastRound'=>null,'history'=>[],'completed'=>false]+(array_filter($bots,static fn($bot)=>$bot['difficulty']==='expert')?['botKnowledge'=>[]]:[]);
}
function dominos_turn_user(array $s): int {return (int)($s['turnOrder'][$s['turnIndex']??0]??0);}
function dominos_group(array $s,int $id): string {return (string)$s['teams'][$id];}
function dominos_begin_round(array &$s,array $random): void {
    if(isset($s['botKnowledge']))$s['botKnowledge']=[];
    $deck=$random['deck']??[];$seed=$random['seed']??'';
    if(count($deck)!==28||count(array_unique($deck))!==28||array_diff($deck,dominos_deck())||!is_string($seed)||!preg_match('/^[a-f0-9]{64}$/',$seed))dominos_fail('Verified deal unavailable.','RANDOMNESS_INVALID',409);
    if(!$s['starterPool'])$s['starterPool']=$s['turnOrder'];$eligible=$s['starterPool'];
    if($s['settings']['tableMode']==='teams'&&$s['lastStartingTeam']!==null)$eligible=array_values(array_filter($eligible,fn($id)=>dominos_group($s,$id)!==(string)$s['lastStartingTeam']));
    $starter=$eligible[hexdec(substr($seed,0,7))%count($eligible)];$s['starterPool']=array_values(array_diff($s['starterPool'],[$starter]));$s['lastStartingTeam']=dominos_group($s,$starter);
    $s['turnIndex']=array_search($starter,$s['turnOrder'],true);$s['starterUserId']=$starter;$s['roundNumber']++;$s['hands']=array_fill_keys($s['turnOrder'],[]);
    $count=count($s['turnOrder']);$size=[2=>9,3=>7,4=>5][$count];
    for($i=0;$i<$size;$i++)foreach($s['turnOrder']as$id)$s['hands'][$id][]=array_shift($deck);
    $s['drawPile']=$deck;$s['root']=null;$s['spinner']=false;$s['branches']=['west'=>[],'east'=>[],'north'=>[],'south'=>[]];$s['passes']=0;$s['voids']=array_fill_keys($s['turnOrder'],[]);$s['phase']='playing';$s['lastRound']=null;
}
/** Outward-oriented branch nodes carry tile identity and near/far values. */
function dominos_ends(array $s): array {
    if(!$s['root'])return [];$r=dominos_pips($s['root']);$out=[];
    foreach(['west','east','north','south']as$side){
        if(in_array($side,['north','south'],true)&&(!$s['spinner']||!$s['branches']['west']||!$s['branches']['east']))continue;
        $branch=$s['branches'][$side];$out[$side]=$branch?end($branch)['far']:($side==='west'?$r[0]:$r[1]);
    }return $out;
}
function dominos_legal(array $s,int $id): array {
    if($s['phase']!=='playing'||!empty($s['completed'])||dominos_turn_user($s)!==$id)return [];
    $moves=[];foreach($s['hands'][$id]??[] as$t){if(!$s['root']){$moves[]=['tile'=>$t,'end'=>'start'];continue;}$v=dominos_pips($t);foreach(dominos_ends($s)as$side=>$pip)if(in_array($pip,$v,true))$moves[]=['tile'=>$t,'end'=>$side];}return $moves;
}
function dominos_place(array &$s,string $tile,string $side): void {
    [$a,$b]=dominos_pips($tile);
    if(!$s['root']){$s['root']=$tile;$s['spinner']=$a===$b;return;}
    $near=dominos_ends($s)[$side];$node=['tile'=>$tile,'near'=>$near,'far'=>$a===$near?$b:$a];
    if(!$s['spinner']&&$a===$b){
        // Re-root the complete existing line at the first double, preserving orientations.
        $r=dominos_pips($s['root']);$line=[];
        foreach(array_reverse($s['branches']['west'])as$n)$line[]=['tile'=>$n['tile'],'near'=>$n['far'],'far'=>$n['near']];
        $line[]=['tile'=>$s['root'],'near'=>$r[0],'far'=>$r[1]];$line=array_merge($line,$s['branches']['east']);
        $opposite=$side==='west'?'east':'west';
        if($side==='east')$line=array_map(static fn($n)=>['tile'=>$n['tile'],'near'=>$n['far'],'far'=>$n['near']],array_reverse($line));
        $s['root']=$tile;$s['spinner']=true;$s['branches']=['west'=>[],'east'=>[],'north'=>[],'south'=>[]];$s['branches'][$opposite]=$line;
    }else $s['branches'][$side][]=$node;
}
function dominos_end_total(array $s): int {
    if(!$s['root'])return 0;$r=dominos_pips($s['root']);$sum=0;
    foreach($s['branches']as$side=>$branch)if($branch){$last=end($branch);$v=dominos_pips($last['tile']);$sum+=$v[0]===$v[1]?array_sum($v):$last['far'];}
    if($s['spinner']){if(!$s['branches']['west']||!$s['branches']['east'])$sum+=array_sum($r);}
    else {if(!$s['branches']['west'])$sum+=$r[0];if(!$s['branches']['east'])$sum+=$r[1];}
    return $sum;
}
function dominos_award(array &$s,int $id,int $points): void {$key=dominos_group($s,$id);$s['scores'][$key]+=$points;}
function dominos_check_win(array &$s): bool {
    $wins=[];foreach($s['turnOrder']as$id)if($s['scores'][dominos_group($s,$id)]>=$s['targetScore'])$wins[]=$id;
    if(!$wins)return false;$s['completed']=true;$s['phase']='completed';$s['winners']=$wins;$s['terminalReason']='target-score';return true;
}
function dominos_end_round(array &$s,?int $empty): void {
    $totals=[];foreach($s['hands']as$id=>$h){$key=dominos_group($s,(int)$id);$totals[$key]=($totals[$key]??0)+dominos_pip_sum($h);}
    $groups=$empty!==null?[dominos_group($s,$empty)]:array_map('strval',array_keys($totals,min($totals),true));$awards=[];
    if(count($groups)===1){$key=$groups[0];$awards[$key]=(int)(round((array_sum($totals)-$totals[$key])/5)*5);}
    elseif(count($s['turnOrder'])===3&&count($groups)===2){$other=array_diff_key($totals,array_fill_keys($groups,true));$each=(int)(round(array_sum($other)/2/5)*5);foreach($groups as$key)$awards[$key]=$each;}
    foreach($awards as$key=>$points)$s['scores'][$key]+=$points;
    $s['lastRound']=['roundNumber'=>$s['roundNumber'],'blocked'=>$empty===null,'winners'=>$groups,'awards'=>$awards,'remainingPips'=>$totals,'hands'=>$s['hands'],'scores'=>$s['scores']];
    $s['history'][]=$s['lastRound'];$s['history']=array_slice($s['history'],-20);$s['phase']='round-complete';dominos_check_win($s);
}
function dominos_result(array $s): array {
    $r=['state'=>$s,'turnUserId'=>dominos_turn_user($s)];if(!empty($s['completed'])){$scores=[];foreach($s['turnOrder']as$id)$scores[$id]=in_array($id,$s['winners']??[],true)?1:0;$result=ocx_game_result_from_scores($scores);if($s['settings']['tableMode']==='teams')foreach($scores as$id=>$score)$result['members'][(string)$id]['outcome']=$score?'win':'loss';$r+=['terminal'=>true,'result'=>$result];$r['turnUserId']=null;}return $r;
}
function dominos_apply_action_core(array $s,int $actor,string $action,array $p,array $c): array {
    if(($s['schemaVersion']??0)!==1||!empty($s['completed']))dominos_fail('This match is unavailable.','STATE_INVALID',409);
    if(!in_array($actor,$s['turnOrder'],true))dominos_fail('Only a seated player may act.','PLAYER_INVALID',403);
    if($action==='resign'){$s['completed']=true;$s['phase']='completed';$s['winners']=array_values(array_filter($s['turnOrder'],fn($id)=>dominos_group($s,$id)!==dominos_group($s,$actor)));$s['terminalReason']='resignation';return dominos_result($s);}
    if(dominos_turn_user($s)!==$actor)dominos_fail('Wait for your turn.','TURN_INVALID',409);
    $last=['type'=>$action,'userId'=>$actor];
    // Optional state exists only for new Expert matches. Old recordings and Normal
    // matches retain their exact state shape; no historical knowledge is invented.
    $knowledgeEvent=null;
    if(isset($s['botKnowledge'])&&$s['phase']==='playing'&&in_array($action,['play','draw','pass'],true)){
        $knowledgeEvent=['type'=>$action,'actor'=>$actor,'ends'=>array_values(dominos_ends($s))];
        if($action==='play')$knowledgeEvent['tile']=$p['tile']??'';
    }
    if($action==='deal'&&$s['phase']==='deal')dominos_begin_round($s,$c['authoritativeRandomness']??[]);
    elseif($action==='next-round'&&$s['phase']==='round-complete')$s['phase']='deal';
    elseif($s['phase']==='playing'){
        $legal=dominos_legal($s,$actor);
        if($action==='play'){
            $move=['tile'=>$p['tile']??null,'end'=>$p['end']??null];if(!in_array($move,$legal,true))dominos_fail('Choose a tile and a matching open end.');
            dominos_place($s,$move['tile'],$move['end']);$s['hands'][$actor]=array_values(array_diff($s['hands'][$actor],[$move['tile']]));$s['passes']=0;
            $total=dominos_end_total($s);$points=$total>0&&$total%5===0?$total:0;dominos_award($s,$actor,$points);$last+=$move+['points'=>$points];
            if(!dominos_check_win($s)){if(!$s['hands'][$actor])dominos_end_round($s,$actor);else$s['turnIndex']=($s['turnIndex']+1)%count($s['turnOrder']);}
        }elseif($action==='draw'&&!$legal&&$s['drawPile']){
            $s['voids'][$actor]=array_values(array_unique(array_merge($s['voids'][$actor],array_values(dominos_ends($s)))));
            $tile=array_shift($s['drawPile']);$s['hands'][$actor][]=$tile;
            // A draw may supply a formerly missing suit. Never preserve an invalid hard void.
            $s['voids'][$actor]=[];$last['tile']=$tile;
        }elseif($action==='pass'&&!$legal&&!$s['drawPile']){
            $s['voids'][$actor]=array_values(array_unique(array_merge($s['voids'][$actor],array_values(dominos_ends($s)))));$s['passes']++;
            if($s['passes']>=count($s['turnOrder']))dominos_end_round($s,null);else$s['turnIndex']=($s['turnIndex']+1)%count($s['turnOrder']);
        }else dominos_fail('That action is not available.');
    }else dominos_fail('That action is not available in this phase.');
    if($knowledgeEvent!==null)dominos_search_observe($s['botKnowledge'],$knowledgeEvent);
    $s['sequence']++;$last['sequence']=$s['sequence'];$s['lastAction']=$last;return dominos_result($s);
}
function dominos_project_state(array $s,int $viewer,array $c): array {
    if(!isset($s['schemaVersion']))return ['phase'=>'lobby','turnOrder'=>[],'bots'=>[],'legalActions'=>[],'legalMoves'=>[],'hand'=>[]];
    $p=array_intersect_key($s,array_flip(['schemaVersion','settings','turnOrder','seatMap','teams','bots','turnIndex','phase','roundNumber','targetScore','scores','root','spinner','branches','sequence','lastRound','history','completed','winners','terminalReason','starterUserId','lastAction']));
    $p['hand']=$s['hands'][$viewer]??[];$p['handCounts']=array_map('count',$s['hands']);$p['stockCount']=count($s['drawPile']);$p['legalMoves']=dominos_legal($s,$viewer);$p['legalActions']=[];
    if(($p['lastAction']['type']??'')==='draw'&&($p['lastAction']['userId']??0)!==$viewer)unset($p['lastAction']['tile']);
    if(dominos_turn_user($s)===$viewer&&empty($s['completed'])){
        if($s['phase']==='deal')$p['legalActions']=['deal'];elseif($s['phase']==='round-complete')$p['legalActions']=['next-round'];elseif($p['legalMoves'])$p['legalActions']=['play'];else$p['legalActions']=[$s['drawPile']?'draw':'pass'];
    }
    $p['botTask']=dominos_bot_task($s,$viewer,$c);return $p;
}
function dominos_recording_adapter(): array {return ['schemaVersion'=>1,'stateKeys'=>['schemaVersion','settings','turnOrder','seatMap','teams','bots','turnIndex','phase','roundNumber','starterPool','lastStartingTeam','starterUserId','targetScore','scores','hands','drawPile','root','spinner','branches','passes','voids','botKnowledge','sequence','lastAction','lastRound','history','completed','winners','terminalReason'],'payloadKeys'=>['tile','end']];}
