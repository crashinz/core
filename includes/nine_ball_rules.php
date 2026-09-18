<?php
declare(strict_types=1);

// Pool shares one physics engine. Only rack construction and adjudication vary.
function pool_variant(array $state): string {return $state['settings']['variant']??'eight-ball';}
function pool_validate_variant(mixed $variant): string {
    if(!in_array($variant,['eight-ball','nine-ball'],true))eight_ball_fail('Choose 8 Ball or 9 Ball.','SETTINGS_INVALID');
    return $variant;
}
function nine_ball_lowest(array $state): ?int {
    $numbers=array_column(array_filter($state['balls'],static fn($b)=>!$b['pocket']&&$b['n']>0),'n');
    return $numbers?min($numbers):null;
}
function nine_ball_rack_balls(array $order): array {
    $rest=array_values(array_filter($order,static fn($n)=>$n>=2&&$n<=8));
    $balls=[(array)EightBallPhysics::ball(0,330,337)];$i=0;$spacing=31.004;
    foreach([1,2,3,2,1]as$row=>$count)for($k=0;$k<$count;$k++){
        $n=$i===0?1:($i===4?9:array_shift($rest));$i++;
        $balls[]=(array)EightBallPhysics::ball($n,854+($row-2)*$spacing*sqrt(3)/2,337+($k-($count-1)/2)*$spacing);
    }
    return $balls;
}
function nine_ball_spot(array &$s): void {
    // Foot spot, then towards the foot cushion; use the head side only if full.
    // Ignore the 9 itself while finding a clear replacement location.
    foreach($s['balls']as&$ball)if($ball['n']===9)$ball['pocket']=true;unset($ball);
    $r=(float)$s['ballRadius'];$clear=static function(float $x)use($s,$r):bool{
        if($x<83+$r||$x>1110-$r)return false;
        foreach($s['balls']as$b)if(!$b['pocket']&&hypot($x-$b['x'],337-$b['y'])<2*$r+.003)return false;
        return true;
    };
    foreach([1,-1]as$direction)for($d=0;$d<1000;$d+=.25){$x=854+$d*$direction;if(!$clear($x))continue;
        foreach($s['balls']as&$b)if($b['n']===9)$b=(array)EightBallPhysics::ball(9,$x,337);unset($b);return;
    }
    eight_ball_fail('Unable to spot the 9 ball.','STATE_INVALID',409);
}
function nine_ball_rules_projection(): array {
    return ['label'=>'9 Ball rules','description'=>'Casual digital 9 Ball. No pocket calls. Solo practice has no foul penalties.','sections'=>[
        ['label'=>'Rack and break','text'=>'Nine balls form a diamond: 1 at the front, 9 in the middle on the foot spot. The first breaker is random; rematches alternate. Place behind the head string, hit 1 first, and pocket a ball or send four different object balls to cushions. The professional three-ball head-string crossing restriction is not used.'],
        ['label'=>'Play and win','text'=>'Hit the lowest numbered ball still on the table first. Any legal pot keeps your turn. Pocketing the 9 legally wins, including a combination or on the break. Pockets do not need to be called.'],
        ['label'=>'Fouls','text'=>'Scratching, missing every ball, hitting the wrong ball first, or failing to reach a cushion or pocket a ball after contact is a foul. The opponent places the cue ball anywhere. A 9 pocketed on a foul is spotted; other potted balls stay down.'],
        ['label'=>'Push out','text'=>'Only the first shot after a foul-free break may be declared a push-out. Use the Push out button before shooting. Contacting a ball or cushion is then optional. A scratch is still a foul, and a potted 9 is spotted. After a legal push-out the opponent chooses Take shot or Return shot.'],
        ['label'=>'Three fouls','text'=>'Three consecutive fouls by the same player lose the rack. A legal shot resets that player’s count. After two, the player display warns that the next foul loses.'],
        ['label'=>'Stalemate and practice','text'=>'Both players may agree to a stalemate; the same breaker then starts a fresh rack. Solo practice supports 8-ball and 9-ball racks, editing, replay, rewind and saved setups.'],
        ['label'=>'Digital table','text'=>'Server-calculated shots use the same physics as 8 Ball. Cues are cosmetic. Jump, elevated-cue and massé shots are not supported.']]];
}
function nine_ball_resolve_shot(array &$s,int $actor,array $payload,array $result): void {
    $before=$s;$other=1-$s['turnIndex'];$first=null;$rail=false;$railBalls=[];$pots=[];$scratch=false;
    $push=!empty($s['pushOutDeclared']);$breaking=!empty($s['breakShot']);
    foreach($result['events']as$e){
        if($e['type']==='ball'&&$first===null&&($e['a']===0||$e['b']===0))$first=$e['a']===0?$e['b']:$e['a'];
        if($e['type']==='rail'&&$first!==null){$rail=true;if($e['a']!==0)$railBalls[$e['a']]=true;}
        if($e['type']==='pocket'){if($e['a']===0)$scratch=true;else$pots[$e['a']]=true;}
    }
    $s['balls']=$result['balls'];$s['shotNumber']++;$s['placement']=null;$s['phase']='aim';
    $s['breakShot']=false;$s['headStringRequired']=false;$s['calledShot']=null;
    $s['pushOutAvailable']=false;$s['pushOutDeclared']=false;$s['choice']=null;
    if($s['settings']['tableMode']==='solo'){
        $s['statusText']='Ready for the next practice shot.';
        if($scratch){$s['phase']='placement';$s['placement']='anywhere';$s['statusText']='Place the cue ball to continue practicing.';}
        return;
    }
    $foul=$scratch?'Cue-ball scratch.':'';
    if(!$push){
        if($first===null)$foul=$foul?:'No object ball was hit.';
        elseif($first!==nine_ball_lowest($before))$foul=$foul?:'The lowest numbered ball must be hit first.';
        if($breaking){if(!$pots&&count($railBalls)<4)$foul=$foul?:'Break foul: pocket a ball or reach four object-ball cushions.';}
        elseif(!$rail&&!$pots)$foul=$foul?:'No cushion or pocket after contact.';
    }
    if(isset($pots[9])&&($foul!==''||$push))nine_ball_spot($s);
    $s['foulCounts']=$s['foulCounts']??array_fill_keys($s['turnOrder'],0);
    if($foul!==''){
        $count=++$s['foulCounts'][$actor];$s['turnIndex']=$other;
        if($count>=3){$s['completed']=true;$s['phase']='completed';$s['winner']=$s['turnOrder'][$other];$s['statusText']='Three consecutive fouls. Opponent wins.';return;}
        $s['phase']='placement';$s['placement']='anywhere';$s['statusText']=$foul.' Opponent has ball in hand.'.($count===2?' Warning: the fouling player has two consecutive fouls.':'');return;
    }
    $s['foulCounts'][$actor]=0;
    if($push){$s['turnIndex']=$other;$s['phase']='choice';$s['choice']='push-out';$s['statusText']='Push-out complete. Take this shot or return it to the opponent.';return;}
    if(isset($pots[9])){$s['completed']=true;$s['phase']='completed';$s['winner']=$actor;$s['statusText']='9 ball pocketed legally. Match won.';return;}
    if(!$pots)$s['turnIndex']=$other;
    $s['pushOutAvailable']=$breaking;
    $s['statusText']=($pots?'Legal pot. Continue.':'Turn passes to opponent.').' Hit the '.nine_ball_lowest($s);
    $s['statusText'].=' ball first.'.($breaking?' Push out is available for this shot.':'');
}
