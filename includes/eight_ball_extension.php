<?php
declare(strict_types=1);
require_once __DIR__.'/ocx_game_extension_support.php';
require_once __DIR__.'/eight_ball_physics.php';
require_once __DIR__.'/nine_ball_rules.php';
require_once __DIR__.'/pool_bot_support.php';

function eight_ball_extension_adapter(): array {return ['id'=>'eight-ball','initialState'=>'eight_ball_initial_state','applyAction'=>'eight_ball_apply_action','validateSettings'=>'eight_ball_validate_settings','settingsProjection'=>'eight_ball_settings_projection','rulesProjection'=>'eight_ball_rules_projection','projectState'=>'eight_ball_project_state','recordingAdapter'=>'eight_ball_recording_adapter','projectVirtualMembers'=>'pool_project_virtual_members','randomnessPurposes'=>['rack'=>'eight-ball-rack','bot-rack'=>'eight-ball-rack'],'deriveRandomness'=>'eight_ball_randomness','presentationStatus'=>'eight_ball_presentation_status','openingProcedure'=>'random-first-break-alternate-rematches','rematchSeatRotation'=>true];}
function eight_ball_fail(string $text,string $code='ACTION_INVALID',int $status=422): never {throw new MultiplayerGameException($text,'EIGHT_BALL_'.$code,$status);}
function eight_ball_presentation_status(PDO $pdo,?string $pack=null): array {return ['requestedPack'=>'built-in','effectivePack'=>'built-in','classicAvailable'=>false,'fallbackApplied'=>false,'presentationOnly'=>true,'mediaPack'=>['publicCodeNative'=>true,'remoteMedia'=>false]];}
function eight_ball_validate_settings(array $s,string $mode,array $definition=[]): array {
    if(array_diff(array_keys($s),['tableMode','pocketCalls','variant','botSeat2Difficulty']))eight_ball_fail('Unsupported pool option.','SETTINGS_INVALID');
    $difficulty=$s['botSeat2Difficulty']??'none';if(!in_array($difficulty,array_column(pool_bot_choices(),'value'),true))eight_ball_fail('Choose a listed bot difficulty.','SETTINGS_INVALID');
    if($difficulty!=='none'&&$mode!=='practice')eight_ball_fail('Bot games are Practice only.','PRACTICE_ONLY');
    $table=$s['tableMode']??'match';if(!in_array($table,['match','solo'],true))eight_ball_fail('Choose a two-player match or Solo free shooting.','SETTINGS_INVALID');
    if($table==='solo'&&$mode!=='practice')eight_ball_fail('Solo free shooting is Practice only.','PRACTICE_ONLY');if($table==='solo'&&$difficulty!=='none')eight_ball_fail('Choose Two-player match to play against a bot, or None for solo free shooting.','SETTINGS_INVALID');$calls=$s['pocketCalls']??'none';if(!in_array($calls,['all','eight','none'],true))eight_ball_fail('Choose a listed pocket-calling rule.','SETTINGS_INVALID');$variant=pool_validate_variant($s['variant']??'eight-ball');return ['tableMode'=>$table,'pocketCalls'=>$variant==='nine-ball'?'none':$calls,'variant'=>$variant,...($mode==='practice'&&$difficulty!=='none'?['botSeat2Difficulty'=>$difficulty]:[])];
}
function eight_ball_call_rule(array $s): string {return pool_variant($s)==='nine-ball'?'none':($s['settings']['pocketCalls']??'all');}
// A call is shared state, while legacy clients may still submit it with a shot.
function eight_ball_validate_call(array $s,int $actor,array $p,bool $partial=false): array {
    $ball=filter_var($p['calledBall']??0,FILTER_VALIDATE_INT);$pocket=filter_var($p['calledPocket']??-1,FILTER_VALIDATE_INT);$safety=!empty($p['safety']);
    if($ball===false||$pocket===false||$pocket< -1||$pocket>5)eight_ball_fail('Choose a listed ball and pocket.');
    if($safety){if($ball!==0||$pocket!==-1)eight_ball_fail('Safety does not call a ball or pocket.');return ['actor'=>$actor,'calledBall'=>0,'calledPocket'=>-1,'safety'=>true];}
    if($partial&&$ball===0&&$pocket===-1)return ['actor'=>$actor,'calledBall'=>0,'calledPocket'=>-1,'safety'=>false];
    $rule=eight_ball_call_rule($s);$g=$s['groups'][$actor]??null;$onEight=($g!==null&&!eight_ball_remaining($s,$actor))||($g===null&&$ball===8&&eight_ball_open_eight($s));$found=false;
    foreach($s['balls']as$b)if($b['n']===$ball&&!$b['pocket'])$found=true;
    if($rule==='none'||($rule==='eight'&&$ball!==8)||!$found||$ball===0||(!$partial&&$pocket<0)||($onEight?$ball!==8:($ball===8||($g!==null&&eight_ball_group($ball)!==$g))))eight_ball_fail('Call a remaining legal ball and pocket, or select Safety.');
    return ['actor'=>$actor,'calledBall'=>$ball,'calledPocket'=>$pocket,'safety'=>false];
}
function eight_ball_settings_projection(array $s,string $mode,array $definition=[]): array {
    $s=eight_ball_validate_settings($s,$mode);return ['label'=>'Game Options','description'=>'Play a two-player match, or practice on your own.','controls'=>[
        ['key'=>'variant','type'=>'button-choice','label'=>'Game','description'=>'Choose 8 Ball or lowest-ball-first 9 Ball.','value'=>$s['variant'],'defaultValue'=>'eight-ball','options'=>[['value'=>'eight-ball','label'=>'8 Ball'],['value'=>'nine-ball','label'=>'9 Ball']]],
        ['key'=>'tableMode','type'=>'button-choice','label'=>'Table','description'=>'Play a match against a person or Practice bot, or choose Solo free shooting.','value'=>$s['tableMode'],'defaultValue'=>'match','options'=>array_merge([['value'=>'match','label'=>'Two-player match']],$mode==='practice'?[['value'=>'solo','label'=>'Solo free shooting']]:[])],
        ...($mode==='practice'?[['key'=>'botSeat2Difficulty','type'=>'button-choice','label'=>'Practice opponent','description'=>'For Two-player match: Easy has imperfect aim and no position planning; Normal favors straightforward pots; Expert searches banks, combinations, spin and position. Choose None for a human opponent or solo.','value'=>$s['botSeat2Difficulty']??'none','defaultValue'=>'none','options'=>pool_bot_choices()]]:[]),
        ['key'=>'pocketCalls','type'=>'button-choice','label'=>'Call pockets','description'=>'8-ball match rule only. 9 Ball and Solo free shooting never require a call.','value'=>$s['pocketCalls'],'defaultValue'=>'none','options'=>[['value'=>'eight','label'=>'8 ball only'],['value'=>'all','label'=>'Every shot'],['value'=>'none','label'=>'No calls']]]
    ]];
}
function eight_ball_rules_projection(array $s,string $mode,array $definition=[]): array {
    if(($s['tableMode']??'match')==='solo')return ['label'=>'Solo Pool practice','description'=>'Free shooting with either rack. No opponent or foul penalties.','sections'=>[
        ['label'=>'Choose a rack','text'=>'Rack 8-ball uses the fifteen-ball triangle. Rack 9-ball uses the nine-ball diamond, with 1 at the front and 9 in the middle. You may switch racks at any time after the balls stop.'],
        ['label'=>'Practice shots','text'=>'Shoot any ball. Scratching lets you place the cue ball anywhere clear. Edit table moves, adds or removes balls; 9-ball layouts use numbers 1 through 9.'],
        ['label'=>'Repeat and save','text'=>'Rewind restores the previous shot with its exact power, aim and spin. Replay and Slow replay show that shot again. Name setups in Saved setups, load them later, or delete and restore them. Loading a setup also restores its 8-ball or 9-ball rack type. Administrators can publish shared setups for everyone.'],
        ['label'=>'Playing matches','text'=>'In an 8-ball match, clear your group before the 8. In a 9-ball match, hit the lowest remaining ball first and legally pocket the 9 to win. The selected match has its own complete Rules help.'],
        ['label'=>'Controls','text'=>'Use the mouse or arrow keys to aim and set power. Hold right mouse and pull back for power, then release to shoot. Cues have equal strength. The server uses the same physics for practice and matches.']]];
    if(($s['variant']??'eight-ball')==='nine-ball')return nine_ball_rules_projection();
    $calls=eight_ball_validate_settings($s,$mode)['pocketCalls'];
    $calling=match($calls){
        'all'=>'After the break, select the intended ball and pocket, or declare Safety. A legal called shot assigns solids or stripes on an open table. Keep shooting when your called ball drops in its called pocket without a foul.',
        'eight'=>'Only the 8 ball requires a pocket call. On other shots, a legal pot keeps your turn. After the break, the first legally pocketed solid or stripe assigns your group; if both groups drop, the first one pocketed decides. Safety passes the turn without assigning groups.',
        'none'=>'No pocket calls are required. A legal pot keeps your turn. After the break, the first legally pocketed solid or stripe assigns your group; if both groups drop, the first one pocketed decides. Safety passes the turn without assigning groups.'
    };
    $eight=$calls==='none'?'Clear your group before pocketing the 8 in any pocket. Pocketing it early or on a foul loses the game.':'Clear your group before calling the 8-ball pocket. Pocketing it early, in the wrong pocket, or on a foul loses the game.';
    return ['label'=>'8 Ball rules','description'=>'Digital 8 Ball with selectable pocket-calling rules. Solo free shooting has no opponent or foul penalties.','sections'=>[
        ['label'=>'Break','text'=>'The first breaker is drawn at random. Rematches alternate the first seat. Place the cue ball behind the head string. Pocket an object ball or drive four different object balls to cushions. Groups remain open after the break.'],
        ['label'=>'Calling shots','text'=>$calling],
        ['label'=>'Fouls','text'=>'Hitting no object ball, the wrong group first, or the 8 first on an open table is a foul. After first contact, a ball must reach a cushion or be pocketed. Scratches are fouls. The opponent receives ball in hand anywhere, except break scratches are behind the head string.'],
        ['label'=>'Break choices','text'=>'An illegal break lets the incoming player accept the table or request a new rack with either player breaking. The 8 on a legal break is spotted or re-racked at the breaker’s choice. If the break also scratches, the incoming player chooses to spot it and place behind the head string or re-rack.'],
        ['label'=>'Stalemate','text'=>'Either player may request a stalemate between shots. Both players must agree. The table is re-racked and the same player breaks again. Playing a shot cancels an outstanding request.'],
        ['label'=>'The 8 ball','text'=>$eight.' If the table is still open and a whole group is already pocketed, you may play the 8 under the selected calling rule. Scratching while aiming at the 8 is only a foul if the 8 remains on the table.'],
        ['label'=>'Digital table','text'=>'All shots and fouls are calculated by the server. Cues differ only in appearance. There are no jump, elevated-cue, or massé shots. Solo practice permits free placement, fresh racks and repeatable layouts.']]];
}
function eight_ball_initial_state(array $players,array $c=[]): array {
    $s=eight_ball_validate_settings($c['settings']??[],$c['mode']??'practice');$count=$s['tableMode']==='solo'||pool_bot_enabled($s)?1:2;
    if(count($players)!==$count||min($players)<=0||count(array_unique($players))!==$count)eight_ball_fail($count===1?'This Practice table needs one human player.':'A match needs two human players.','PLAYERS_INVALID',409);
    $bots=[];if(pool_bot_enabled($s)){$players[]=POOL_BOT_ID;$bots[POOL_BOT_ID]=['userId'=>POOL_BOT_ID,'seat'=>2,'difficulty'=>$s['botSeat2Difficulty'],'displayName'=>ucfirst($s['botSeat2Difficulty']).' Bot','engine'=>POOL_BOT_ENGINE];}
    $cues=array_fill_keys($players,6);
    if(!empty($c['roundContext']['rematchContinues']))foreach($players as$id){
        $cue=filter_var($c['roundContext']['previousState']['cues'][$id]??6,FILTER_VALIDATE_INT);
        if($cue!==false&&$cue>=0&&$cue<=6)$cues[$id]=$cue;
    }
    return ['schemaVersion'=>1,'ballRadius'=>15.5,'settings'=>$s,...($bots?['bots'=>$bots]:[]),'turnOrder'=>array_values($players),'turnIndex'=>0,'phase'=>'rack','balls'=>[],'groups'=>array_fill_keys($players,null),'cues'=>$cues,'sequence'=>0,'shotNumber'=>0,'breakShot'=>true,'placement'=>null,'lastShot'=>null,'calledShot'=>null,'statusText'=>'Rack the table to begin.','completed'=>false,'rematch'=>!empty($c['roundContext']['rematchContinues']),'previousBreaker'=>$c['roundContext']['previousState']['breakerUserId']??null,'stalemateRequests'=>[]];
}
function eight_ball_randomness(string $reveal,string $action,array $payload,array $context): array {return ['order'=>ocx_game_random_permutation($reveal,range(1,15),'eight-ball-rack'),'starter'=>hexdec(substr(hash('sha256',$reveal.'|starter'),0,6))%2];}
function eight_ball_rack(array &$s,array $random,?int $breaker=null): void {
    unset($s['botPendingShot'],$s['botReadyAt'],$s['botAim']);$s['calledShot']=null;$order=$random['order']??[];if(count($order)!==15||count(array_unique($order))!==15||array_diff($order,range(1,15)))eight_ball_fail('Verified rack is unavailable.','RANDOMNESS_INVALID',409);
    // 8 in the center; opposite rear corners are one solid and one stripe.
    $solid=current(array_filter($order,static fn($n)=>$n<8));$stripe=current(array_filter($order,static fn($n)=>$n>8));$rest=array_values(array_diff($order,[8,$solid,$stripe]));$numbers=[];for($i=0;$i<15;$i++)$numbers[]=$i===4?8:($i===10?$solid:($i===14?$stripe:array_shift($rest)));
    $s['ballRadius']=15.5;$s['practiceUndo']=null;$s['practiceSetup']=null;$s['practiceLayout']=null;$balls=[(array)EightBallPhysics::ball(0,330,337)];$i=0;for($r=0;$r<5;$r++)for($k=0;$k<=$r;$k++)$balls[]=(array)EightBallPhysics::ball($numbers[$i++],798+$r*(31.004*sqrt(3)/2),337+($k-$r/2)*31.004);
    if(pool_variant($s)==='nine-ball')$balls=nine_ball_rack_balls($order);
    $s['foulCounts']=array_fill_keys($s['turnOrder'],0);$s['pushOutAvailable']=false;$s['pushOutDeclared']=false;
    usort($balls,static fn($a,$b)=>$a['n']<=>$b['n']);$s['balls']=$balls;$s['turnIndex']=$breaker??(($s['rematch']??false)?0:((int)($random['starter']??0)%count($s['turnOrder'])));$s['breakerUserId']=$s['turnOrder'][$s['turnIndex']];if($breaker===null&&$s['rematch']&&$s['previousBreaker']!==null&&count($s['turnOrder'])===2){$s['turnIndex']=$s['turnOrder'][0]===$s['previousBreaker']?1:0;$s['breakerUserId']=$s['turnOrder'][$s['turnIndex']];}$s['breakShot']=true;$s['phase']='placement';$s['placement']='break';$s['groups']=array_fill_keys($s['turnOrder'],null);$s['statusText']='Place the cue ball behind the head string.';$s['lastShot']=null;$s['choice']=null;
}
function eight_ball_turn(array $s): int {return (int)$s['turnOrder'][$s['turnIndex']];}
function eight_ball_group(int $n): ?string {return $n>=1&&$n<=7?'solids':($n>=9&&$n<=15?'stripes':null);}
function eight_ball_remaining(array $s,int $id): array {$g=$s['groups'][$id]??null;return array_values(array_filter($s['balls'],static fn($b)=>!$b['pocket']&&eight_ball_group((int)$b['n'])===$g&&$b['n']!==0&&$b['n']!==8));}
function eight_ball_open_eight(array $s): bool {foreach(['solids','stripes']as$group){$found=false;foreach($s['balls']as$b)if(!$b['pocket']&&eight_ball_group((int)$b['n'])===$group){$found=true;break;}if(!$found)return true;}return false;}
function eight_ball_placement_valid(array $s,float $x,float $y,bool $behind): bool {
    $r=(float)($s['ballRadius']??12.5);if($x<83+$r||$x>1110-$r||$y<85+$r||$y>590-$r||($behind&&$x>335))return false;
    foreach(EightBallPhysics::HOLES as$h)if(hypot($x-$h[0],$y-$h[1])<$r+25)return false;
    foreach($s['balls']as$b)if($b['n']!==0&&!$b['pocket']&&hypot($x-$b['x'],$y-$b['y'])<2*$r+1)return false;return true;
}
function eight_ball_spot_eight(array &$s): void {for($d=0;$d<1000;$d++)foreach([798+$d,798-$d]as$x)if(eight_ball_placement_valid($s,$x,337,false)){foreach($s['balls']as&$b)if($b['n']===8)$b=(array)EightBallPhysics::ball(8,$x,337);return;}eight_ball_fail('Unable to spot the 8 ball.','STATE_INVALID',409);}
function eight_ball_numeric(array $p,string $key,float $min,float $max): float {if(!isset($p[$key])||!is_numeric($p[$key])||!is_finite((float)$p[$key])||(float)$p[$key]<$min||(float)$p[$key]>$max)eight_ball_fail('Invalid '.$key.'.');return (float)$p[$key];}
function eight_ball_result(array $s): array {$r=['state'=>$s,'turnUserId'=>eight_ball_turn($s)];if($s['completed']){$scores=[];foreach($s['turnOrder']as$id)$scores[$id]=$id===($s['winner']??0)?1:0;$r+=['terminal'=>true,'result'=>ocx_game_result_from_scores($scores)];$r['turnUserId']=null;}return $r;}
function eight_ball_resolve_shot(array &$s,int $actor,array $payload,array $result): void {
    if(pool_variant($s)==='nine-ball'){nine_ball_resolve_shot($s,$actor,$payload,$result);return;}
    $before=$s;$s['calledShot']=null;$calls=eight_ball_call_rule($s);$solo=$s['settings']['tableMode']==='solo';$other=1-$s['turnIndex'];$first=null;$rail=false;$railBalls=[];$pots=[];$scratch=false;
    foreach($result['events']as$e){if($e['type']==='ball'&&$first===null&&($e['a']===0||$e['b']===0))$first=$e['a']===0?$e['b']:$e['a'];
        if($e['type']==='rail'&&$first!==null){$rail=true;if($e['a']!==0)$railBalls[$e['a']]=true;}
        if($e['type']==='pocket'){if($e['a']===0)$scratch=true;else$pots[$e['a']]=$e['pocket'];}}
    $s['headStringRequired']=false;$s['balls']=$result['balls'];$s['shotNumber']++;$s['placement']=null;$s['phase']='aim';$s['statusText']='Ready for the next shot.';
    if($solo){if($scratch){$s['phase']='placement';$s['placement']='anywhere';$s['statusText']='Place the cue ball to continue practicing.';}return;}
    $group=$before['groups'][$actor]??null;$onEight=($group!==null&&!eight_ball_remaining($before,$actor))||($group===null&&eight_ball_open_eight($before)&&($calls==='none'?$first===8:(int)($payload['calledBall']??0)===8));
    $foul=$scratch?'Cue-ball scratch.':($first===null?'No object ball was hit.':'');
    if(!empty($before['headStringRequired'])&&empty($result['firstContactCrossedHeadString']))$foul='The cue ball must cross the head string before contact.';
    if(!$before['breakShot']&&$first!==null){$legal=$group===null?($first!==8||$onEight):($onEight?$first===8:eight_ball_group($first)===$group);if(!$legal)$foul='Wrong ball hit first.';if(!$rail&&!$pots)$foul='No cushion or pocket after contact.';}
    if($before['breakShot']){
        $s['breakShot']=false;$illegal=!$pots&&count($railBalls)<4;
        if(isset($pots[8])){$s['phase']='choice';$s['turnIndex']=$scratch?$other:$before['turnIndex'];$s['choice']=$scratch?'eight-scratch':'eight-break';$s['statusText']=$scratch?'8 and cue ball pocketed on break. Choose spot or re-rack.':'8 pocketed on break. Choose spot or re-rack.';return;}
        if($illegal){$s['turnIndex']=$other;$s['phase']='choice';$s['choice']=$scratch?'illegal-scratch':'illegal-break';$s['statusText']='Illegal break. Accept the table or choose a new break.';return;}
        if($scratch){$s['turnIndex']=$other;$s['phase']='placement';$s['placement']='break';$s['statusText']='Break scratch. Place behind the head string.';return;}
        if(!$pots)$s['turnIndex']=$other;$s['statusText']=$calls==='all'?'Table open. Call a ball and pocket.':'Table open. Pocket a solid or stripe to choose your group.';return;
    }
    if(isset($pots[8])){$win=$onEight&&$foul===''&&($calls==='none'||((int)($payload['calledBall']??0)===8&&(int)($payload['calledPocket']??-1)===$pots[8]))&&empty($payload['safety']);$s['completed']=true;$s['phase']='completed';$s['winner']=$win?$actor:$s['turnOrder'][$other];$s['statusText']=$win?'8 ball pocketed legally. Match won.':($foul?:($calls==='none'?'8 ball pocketed early.':'8 ball pocketed early or in the wrong pocket.')).' Opponent wins.';return;}
    if($foul!==''){$s['turnIndex']=$other;$s['phase']='placement';$s['placement']='anywhere';$s['statusText']=$foul.' Opponent has ball in hand.';return;}
    $called=(int)($payload['calledBall']??0);$made=isset($pots[$called])&&$pots[$called]===(int)($payload['calledPocket']??-1)&&empty($payload['safety']);
    if($calls!=='all'){$made=false;if(empty($payload['safety']))foreach($pots as$n=>$pocket){$potGroup=eight_ball_group((int)$n);if($potGroup!==null&&($group===null||$potGroup===$group)){$called=(int)$n;$made=true;break;}}}
    if($made&&$group===null){$group=eight_ball_group($called);$s['groups'][$actor]=$group;$s['groups'][$s['turnOrder'][$other]]=$group==='solids'?'stripes':'solids';}
    if(!$made)$s['turnIndex']=$other;$s['statusText']=$made?($calls==='all'?'Called shot made. Continue.':'Legal pot. Continue.'):(!empty($payload['safety'])?'Safety. Opponent to play.':'Turn passes to opponent.');
}
function eight_ball_apply_action_core(array $s,int $actor,string $action,array $p,array $c): array {
    if(($s['schemaVersion']??0)!==1||!empty($s['completed']))eight_ball_fail('This match is unavailable.','STATE_INVALID',409);
    if(!in_array($actor,$s['turnOrder'],true))eight_ball_fail('Only seated players may act.','PLAYER_INVALID',403);
    $solo=$s['settings']['tableMode']==='solo';$now=isset($c['nowUnixMs'])?(float)$c['nowUnixMs']/1000:microtime(true);
    if($action==='cue'){$cue=filter_var($p['cue']??null,FILTER_VALIDATE_INT);if($cue===false||$cue<0||$cue>6)eight_ball_fail('Choose a listed cue.');$s['cues'][$actor]=$cue;$s['sequence']++;return eight_ball_result($s);}
    if($action==='resign'&&$solo){$s['completed']=true;$s['phase']='completed';$s['statusText']='Solo practice ended.';$s['sequence']++;return ['state'=>$s,'terminal'=>true,'turnUserId'=>null,'result'=>['members'=>[(string)$actor=>['score'=>0,'outcome'=>'draw']]]];}
    if($action==='resign'&&!$solo){$s['completed']=true;$s['phase']='completed';$s['winner']=current(array_diff($s['turnOrder'],[$actor]));return eight_ball_result($s);}
    if($action==='stalemate'&&!$solo){if($s['phase']!=='aim'||$now<(float)($s['animationUntil']??0))eight_ball_fail('Wait until the shot finishes.');$requests=$s['stalemateRequests']??[];$requests=in_array($actor,$requests,true)?array_values(array_diff($requests,[$actor])):[...$requests,$actor];$s['stalemateRequests']=$requests;$s['statusText']=$requests?'Stalemate requested. The other player may agree below.':'Stalemate request withdrawn.';if(count($requests)===2){$s['phase']='rerack';$s['nextBreaker']=array_search($s['breakerUserId'],$s['turnOrder'],true);$s['turnIndex']=$s['nextBreaker'];$s['stalemateRequests']=[];$s['statusText']='Stalemate agreed. Re-rack with the same breaker.';}$s['sequence']++;return eight_ball_result($s);}
    if(eight_ball_turn($s)!==$actor)eight_ball_fail('Wait for your turn.','TURN_INVALID',409);
    if($now<(float)($s['animationUntil']??0))eight_ball_fail('Wait for the balls to stop.','IN_MOTION',409);
    if($action==='rack'&&($s['phase']==='rack'||$solo||$s['phase']==='rerack')){if(isset($p['variant'])){if(!$solo)eight_ball_fail('Only solo practice may switch rack types.');$s['settings']['variant']=pool_validate_variant($p['variant']);}eight_ball_rack($s,$c['authoritativeRandomness']??[],isset($s['nextBreaker'])?(int)$s['nextBreaker']:null);unset($s['nextBreaker']);}
    elseif($action==='push-out'&&!$solo&&pool_variant($s)==='nine-ball'&&$s['phase']==='aim'&&!empty($s['pushOutAvailable'])){$s['pushOutDeclared']=empty($s['pushOutDeclared']);$s['statusText']=$s['pushOutDeclared']?'Push out declared. Shoot, or cancel below.':'Push out cancelled. Hit the lowest ball first.';}
    elseif($action==='call'&&!$solo&&pool_variant($s)==='eight-ball'&&$s['phase']==='aim'&&!$s['breakShot']){$s['calledShot']=eight_ball_validate_call($s,$actor,$p,true);}
    elseif($action==='practice-edit'&&$solo){
        $s['settings']['variant']=pool_validate_variant($p['variant']??pool_variant($s));
        $s['balls']=eight_ball_practice_balls($p['balls']??null);if(pool_variant($s)==='nine-ball'&&max(array_column($s['balls'],'n'))>9)eight_ball_fail('A 9-ball setup uses balls 1 through 9.');$s['ballRadius']=15.5;
        $s['practiceSetup']=eight_ball_practice_setup($p['setup']??[]);$s['practiceLayout']=['balls'=>$s['balls'],'setup'=>$s['practiceSetup']];$s['practiceUndo']=null;
        $s['phase']='aim';$s['placement']=null;$s['breakShot']=false;$s['layout']='custom';$s['lastShot']=null;
        $s['animationUntil']=0;$s['practiceVersion']=($s['practiceVersion']??0)+1;$s['statusText']='Practice setup ready.';
    }
    elseif($action==='practice-rewind'&&$solo){
        $undo=$s['practiceUndo']??null;if(!is_array($undo))eight_ball_fail('There is no shot to rewind.');
        foreach($undo as$key=>$value)$s[$key]=$value;
        $s['lastShot']=null;$s['animationUntil']=0;$s['practiceUndo']=null;
        $s['practiceVersion']=($s['practiceVersion']??0)+1;$s['statusText']='Previous shot restored. Adjust it or try again.';
    }
    elseif($action==='layout'&&$solo){
        if(($p['layout']??'')==='custom'&&!empty($s['practiceLayout'])){
            $s['balls']=$s['practiceLayout']['balls'];$s['practiceSetup']=$s['practiceLayout']['setup'];$s['ballRadius']=15.5;$s['practiceUndo']=null;$s['lastShot']=null;$s['phase']='aim';$s['placement']=null;$s['breakShot']=false;$s['animationUntil']=0;$s['practiceVersion']=($s['practiceVersion']??0)+1;$s['statusText']='Custom practice layout restored.';
        }else eight_ball_solo_layout($s,(string)($p['layout']??'practice'));
    }
    elseif($action==='place'&&($s['phase']==='placement'||$solo)){$x=eight_ball_numeric($p,'x',95.5,1097.5);$y=eight_ball_numeric($p,'y',97.5,577.5);if(!eight_ball_placement_valid($s,$x,$y,!$solo&&$s['placement']==='break'))eight_ball_fail('Choose a clear legal cue-ball location.');foreach($s['balls']as&$b)if($b['n']===0)$b=(array)EightBallPhysics::ball(0,$x,$y);unset($b);$s['headStringRequired']=!$solo&&!$s['breakShot']&&$s['placement']==='break';$s['phase']='aim';$s['placement']=null;$s['statusText']='Aim and set power.';}
    elseif($action==='choice'&&$s['phase']==='choice'){
        $v=$p['choice']??'';$kind=$s['choice'];$illegal=str_starts_with($kind,'illegal');$scratch=str_ends_with($kind,'scratch');
        if($kind==='push-out'){if(!in_array($v,['take','return'],true))eight_ball_fail('Take the shot or return it.');if($v==='return')$s['turnIndex']=1-$s['turnIndex'];$s['phase']='aim';$s['statusText']='Hit the '.nine_ball_lowest($s).' ball first.';}
        elseif($v==='rerack-self'||($v==='rerack-other'&&$illegal)){$s['nextBreaker']=$v==='rerack-self'?$s['turnIndex']:1-$s['turnIndex'];$s['phase']='rerack';$s['statusText']='Rack the table for the selected breaker.';}
        elseif($v===($illegal?'accept':'spot')){if(!$illegal)eight_ball_spot_eight($s);$s['phase']=$scratch?'placement':'aim';$s['placement']=$scratch?'break':null;$s['statusText']=$scratch?'Place behind the head string.':(eight_ball_call_rule($s)==='all'?'Aim and call your shot.':'Ready for the next shot.');}else eight_ball_fail('Choose one of the offered break options.');$s['choice']=null;
    }
    elseif($action==='shot'&&$s['phase']==='aim'){
        $angle=eight_ball_numeric($p,'angle',-100,100);$power=eight_ball_numeric($p,'power',5,100);$spin=['x'=>eight_ball_numeric($p,'spinX',-1,1),'y'=>eight_ball_numeric($p,'spinY',-1,1)];if(hypot($spin['x'],$spin['y'])>1.000001)eight_ball_fail('Spin must remain inside the cue ball.');
        $shared=$s['calledShot']??null;
        if(is_array($shared)&&($shared['actor']!==$actor||(int)($p['calledBall']??0)!==$shared['calledBall']||(int)($p['calledPocket']??-1)!==$shared['calledPocket']||!empty($p['safety'])!==$shared['safety']))eight_ball_fail('Your shot must match the pocket selection shown to the table.');
        if(!$solo&&!$s['breakShot']&&empty($p['safety'])&&(eight_ball_call_rule($s)==='all'||(eight_ball_call_rule($s)==='eight'&&((($s['groups'][$actor]??null)!==null&&!eight_ball_remaining($s,$actor))||(int)($p['calledBall']??0)===8))))eight_ball_validate_call($s,$actor,$p);
        if($solo){$s['practiceUndo']=array_intersect_key($s,array_flip(['balls','ballRadius','phase','placement','breakShot','shotNumber','layout','settings']));$s['practiceUndo']['practiceSetup']=['angle'=>$angle,'power'=>$power,'spin'=>$spin];}
        eight_ball_restore_surface($s);
        if($solo)$s['practiceUndo']['balls']=$s['balls'];
        $s['stalemateRequests']=[];$before=$s['balls'];$r=EightBallPhysics::simulate($before,$angle,$power,$spin,(float)($s['ballRadius']??12.5));eight_ball_surface_orientations($r,$before,(float)($s['ballRadius']??12.5));eight_ball_resolve_shot($s,$actor,$p,$r);
        $packed='';foreach($r['frames']as$frame)foreach($frame as$b)$packed.=pack('vvv',(int)round($b[1]*32),(int)round($b[2]*32),($b[3]?32768:0)|((int)round(fmod($b[4],2*M_PI)*1000)&32767));
        $s['animationUntil']=$now+$r['duration'];$s['lastShot']=['calledShot'=>$shared,'id'=>$s['shotNumber'],'actor'=>$actor,'angle'=>$angle,'power'=>$power,'spin'=>$spin,'before'=>$before,'events'=>$r['events'],'frames'=>base64_encode($packed),'frameCount'=>count($r['frames']),'ballOrder'=>array_column($r['balls'],'n'),'duration'=>$r['duration'],'startedAt'=>$now,'version'=>1];
    }else eight_ball_fail('That action is not available.');
    $s['sequence']++;return eight_ball_result($s);
}
function eight_ball_project_state(array $s,int $viewer,array $c): array {
    if(!isset($s['schemaVersion']))return ['phase'=>'lobby','legalActions'=>[]];$p=$s;unset($p['_framework']);$p['legalActions']=[];
    if(in_array($viewer,$s['turnOrder'],true)&&!$s['completed']){$p['legalActions'][]='cue';if($s['settings']['tableMode']==='match'&&$s['phase']==='aim')$p['legalActions'][]='stalemate';if(eight_ball_turn($s)===$viewer){$p['legalActions']=array_merge($p['legalActions'],match($s['phase']){'rack','rerack'=>['rack'],'placement'=>['place'],'choice'=>['choice'],'aim'=>['shot'],default=>[]});if($s['settings']['tableMode']==='match'&&$s['phase']==='aim'&&!$s['breakShot']){if(pool_variant($s)==='nine-ball'){if(!empty($s['pushOutAvailable']))$p['legalActions'][]='push-out';}else $p['legalActions'][]='call';}if($s['settings']['tableMode']==='solo')$p['legalActions']=array_values(array_unique([...$p['legalActions'],'rack','place','layout','practice-edit',...(!empty($s['practiceUndo'])?['practice-rewind']:[])]));}}
    unset($p['botPendingShot'],$p['botReadyAt']);if(!empty($s['bots']))$p['botTask']=pool_bot_task($s,$viewer,$c);
    $p['serverNow']=microtime(true);return $p;
}
function eight_ball_recording_adapter(): array {return ['schemaVersion'=>1,'stateKeys'=>['bots','botPendingShot','botReadyAt','botAim','schemaVersion','settings','turnOrder','turnIndex','phase','balls','groups','cues','sequence','shotNumber','breakShot','placement','lastShot','calledShot','statusText','completed','winner','choice','headStringRequired','breakerUserId','layout','rematch','previousBreaker','nextBreaker','animationUntil','layoutVersion','stalemateRequests','ballRadius','practiceUndo','practiceSetup','practiceVersion','practiceLayout','foulCounts','pushOutAvailable','pushOutDeclared'],'payloadKeys'=>['angle','power','spinX','spinY','calledBall','calledPocket','safety','x','y','cue','choice','layout','balls','setup','variant']];}

function eight_ball_solo_layout(array &$s,string $layout): void {
    if(!in_array($layout,['practice','pocket','center','follow','draw','left','right'],true))eight_ball_fail('Choose a listed practice layout.');
    $positions=match($layout){
        'pocket'=>[[0,590,335],[3,596,155],[8,860,400]],
        'center','follow','draw'=>[[0,450,337],[1,550,337]],
        'left','right'=>[[0,820,337]],
        default=>[[0,420,317],[1,980,139],[2,290,252],[3,408,448],[4,887,478],[5,812,541],[6,704,211],[7,235,425],[8,898,326],[9,243,505],[10,750,151],[11,733,433],[12,572,384],[13,957,471],[14,982,242],[15,490,158]]};
    if(pool_variant($s)==='nine-ball')$positions=array_values(array_filter($positions,static fn($b)=>$b[0]<=9));
    $s['ballRadius']=15.5;$s['practiceUndo']=null;$s['practiceSetup']=null;$s['practiceLayout']=null;$s['balls']=array_map(static fn($b)=>(array)EightBallPhysics::ball(...$b),$positions);$s['phase']='aim';$s['placement']=null;$s['lastShot']=null;$s['breakShot']=false;$s['layout']=$layout;$s['layoutVersion']=($s['layoutVersion']??0)+1;$s['statusText']='Solo free shooting. Choose any shot.';
}

// Solo practice requests pass the same authoritative boundary as ordinary shots.
function eight_ball_practice_setup(mixed $input): array {
    if(!is_array($input))eight_ball_fail('Invalid shot setup.');
    $angle=eight_ball_numeric($input+['angle'=>0],'angle',-100,100);
    $power=eight_ball_numeric($input+['power'=>25],'power',0,100);
    $spin=$input['spin']??['x'=>0,'y'=>0];if(!is_array($spin))eight_ball_fail('Invalid spin.');
    $x=eight_ball_numeric($spin,'x',-1,1);$y=eight_ball_numeric($spin,'y',-1,1);
    if(hypot($x,$y)>1.000001) eight_ball_fail('Spin must remain inside the cue ball.');
    return ['angle'=>$angle,'power'=>$power,'spin'=>['x'=>$x,'y'=>$y]];
}
function eight_ball_practice_balls(mixed $input): array {
    if(!is_array($input)||!array_is_list($input)||count($input)<1||count($input)>16)eight_ball_fail('Use the cue ball and up to 15 object balls.');
    $balls=[];$seen=[];$r=15.5;
    foreach($input as$b){
        if(!is_array($b)||!isset($b['n'])||!is_int($b['n'])||$b['n']<0||$b['n']>15||isset($seen[$b['n']]))eight_ball_fail('Each numbered ball may appear once.');
        $x=eight_ball_numeric($b,'x',83+$r,1110-$r);$y=eight_ball_numeric($b,'y',85+$r,590-$r);
        foreach(EightBallPhysics::HOLES as$h)if(hypot($x-$h[0],$y-$h[1])<$r+25)eight_ball_fail('Place balls clear of the pockets.');
        foreach($balls as$other)if(hypot($x-$other['x'],$y-$other['y'])<2*$r+.003)eight_ball_fail('Balls cannot overlap.');
        $balls[]=(array)EightBallPhysics::ball($b['n'],$x,$y);$seen[$b['n']]=true;
    }
    if(!isset($seen[0]))eight_ball_fail('Keep the cue ball on the table.');
    usort($balls,static fn($a,$b)=>$a['n']<=>$b['n']);return $balls;
}

// Cosmetic quaternion rotations along the quantized playback path. Persist in balls
// so refresh, reconnect, framework save/restore and practice rewind agree.
function eight_ball_surface_orientations(array &$result,array $before,float $radius): void {
    $states=[];foreach($before as$b)$states[$b['n']]=['x'=>$b['x'],'y'=>$b['y'],'q'=>$b['orientation']??[0.,0.,0.,1.]];
    $rotate=static function(array $q,float $dx,float $dy)use($radius):array {
        $d=hypot($dx,$dy);if($d<1e-9)return $q;$s=sin($d/(2*$radius))/$d;
        [$x,$y,$z,$w]=[-$dy*$s,$dx*$s,0.,cos($d/(2*$radius))];[$a,$b,$c,$e]=$q;
        $next=[$w*$a+$x*$e+$y*$c-$z*$b,$w*$b-$x*$c+$y*$e+$z*$a,$w*$c+$x*$b-$y*$a+$z*$e,$w*$e-$x*$a-$y*$b-$z*$c];
        $length=sqrt(array_sum(array_map(static fn($v)=>$v*$v,$next)));return array_map(static fn($v)=>$v/$length,$next);
    };
    foreach($result['frames']as$frame)foreach($frame as$b){$v=&$states[$b[0]];$x=round($b[1]*32)/32;$y=round($b[2]*32)/32;$v['q']=$rotate($v['q'],$x-$v['x'],$y-$v['y']);$v['x']=$x;$v['y']=$y;unset($v);}
    foreach($result['balls']as&$b){$v=$states[$b['n']];$b['orientation']=$rotate($v['q'],$b['x']-$v['x'],$b['y']-$v['y']);}unset($b);
}

// Backward-compatible recovery from an old saved shot; no position/rule changes.
function eight_ball_restore_surface(array &$s): void {
    $last=$s['lastShot']??null;if(!$last)return;
    $missing=array_filter($s['balls'],static fn($b)=>!isset($b['orientation']));if(!$missing)return;
    $raw=base64_decode($last['frames'],true);$count=count($last['ballOrder']);$frames=[];
    if($raw===false||strlen($raw)!==$last['frameCount']*$count*6)return;
    for($f=0;$f<$last['frameCount'];$f++){$frame=[];foreach($last['ballOrder']as$i=>$n){$v=unpack('vx/vy/vflags',substr($raw,($f*$count+$i)*6,6));$frame[]=[$n,$v['x']/32,$v['y']/32,($v['flags']&32768)?1:0,0];}$frames[]=$frame;}
    $r=['balls'=>$s['balls'],'frames'=>$frames];eight_ball_surface_orientations($r,$last['before'],(float)($s['ballRadius']??12.5));
    foreach($s['balls']as$i=>&$b)if(!isset($b['orientation']))$b['orientation']=$r['balls'][$i]['orientation'];unset($b);
}
