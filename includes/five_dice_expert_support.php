<?php
declare(strict_types=1);

/** First-party full-scorecard expected-value strategy. See
 * games/five-dice/strategy/BUILD.md for the format, rules and generation recipe.
 * Actual legal scoring remains owned by five_dice_extension.php.
 */
function five_dice_expert_read_table(string $path): ?string {
    $bytes = 8388616;
    if (!is_file($path) || !is_readable($path) || is_link($path)) return null;
    // Leave room for the game framework and probability graph on small hosts.
    $limit = trim((string)ini_get('memory_limit'));
    if ($limit !== '' && $limit !== '-1') {
        $unit = strtolower(substr($limit, -1));
        $ceiling = (float)$limit * match ($unit) { 'g'=>1073741824, 'm'=>1048576, 'k'=>1024, default=>1 };
        if ($ceiling > 0 && memory_get_usage(true) + $bytes + 4194304 > $ceiling) return null;
    }
    $stream = @fopen($path, 'rb');
    if ($stream === false) return null;
    try { $data = @stream_get_contents($stream, $bytes + 1); }
    finally { fclose($stream); }
    if (!is_string($data) || strlen($data) !== $bytes || substr($data, 0, 8) !== 'FDV1LE64') return null;
    // Structural validation, not an enforced released-file hash. Values used
    // in decisions are checked individually below. Edited valid tables work.
    foreach ([0, 1, 124, 125] as $i) if (unpack('e', $data, 8+8*$i)[1] !== 0.0) return null;
    foreach ([126, 127] as $i) if (unpack('e', $data, 8+8*$i)[1] !== 35.0) return null;
    return $data;
}

function five_dice_expert_table(): ?string {
    static $loaded = false, $data = null;
    if (!$loaded) { $loaded = true; $data = five_dice_expert_read_table(dirname(__DIR__).'/games/five-dice/strategy/values.bin'); }
    return $data;
}

function five_dice_expert_value(string $bytes,int $mask,int $upper,int $positive): float {
    $value=unpack('e',$bytes,8+8*($mask*128+min(63,$upper)*2+$positive))[1];
    if(!is_finite($value)||$value<0||$value>1575)throw new UnexpectedValueException('Invalid Five Dice strategy value');
    return $value;
}
function five_dice_expert_state(array $card): array {
    $mask=0;$upper=0;foreach(five_dice_categories()as$i=>$c){if($card[$c]===null)$mask|=1<<$i;elseif($i<6)$upper+=(int)$card[$c];}
    return [$mask,min(63,$upper),($card['yahtzee']??0)===50?1:0];
}
function five_dice_expert_score(array $card,array $dice,string $bytes): array {
    [$mask,$upper,$positive]=five_dice_expert_state($card);$best=null;
    foreach(five_dice_allowed_score_categories($card,$dice)as$c){
        $p=five_dice_score_with_joker($c,$card,$dice);$i=array_search($c,five_dice_categories(),true);
        $value=$p['score']+$p['repeatBonus']+five_dice_expert_value($bytes,$mask^(1<<$i),$upper+($i<6?$p['score']:0),$i===12?($p['score']===50?1:0):$positive);
        if($best===null||$value>$best['value']+1e-9)$best=['category'=>$c,'score'=>$p['score'],'repeatBonus'=>$p['repeatBonus'],'value'=>$value];
    }
    return $best??throw new LogicException('No score category');
}
function five_dice_expert_choose(array $o,string $bytes): array {
    if($o['rolls']===0)return ['action'=>'roll','payload'=>[],'reason'=>'fresh-verified-roll'];
    $dice=$o['dice'];$card=$o['scorecard'];$score=five_dice_expert_score($card,$dice,$bytes);
    $finish=static fn()=>['action'=>'score','payload'=>['category'=>$score['category']],'reason'=>'full-scorecard-value','strategy'=>'full-scorecard-v1','value'=>$score['value']];
    if($o['rolls']>=3)return $finish();
    $g=five_dice_bot_graph();$counts=array_fill(0,6,0);foreach($dice as$f)$counts[$f-1]++;
    $index=$g['keys'][implode('',$counts)];$terminal=[];
    foreach($g['dice']as$i=>$d)$terminal[$i]=five_dice_expert_score($card,$d,$bytes)['value'];
    $values=$terminal;$choice=[];$depth=3-$o['rolls'];
    for($step=0;$step<$depth;$step++){
        $expected=[];foreach($g['edges']as$h=>$edges){$v=0;foreach($edges as[$i,$p])$v+=$p*$values[$i];$expected[$h]=$v;}
        $next=$terminal;
        foreach($g['subsets']as$i=>$holds){$choice[$i]=null;foreach($holds as$h)if($expected[$h]>$next[$i]+1e-9){$next[$i]=$expected[$h];$choice[$i]=$h;}}
        $values=$next;
    }
    if($choice[$index]===null)return $finish();
    $keep=$g['holds'][$choice[$index]];$held=[];
    foreach($dice as$f){$held[]=$keep[$f-1]>0;if($keep[$f-1]>0)$keep[$f-1]--;}
    return ['action'=>'set-holds','payload'=>['held'=>$held],'reason'=>'full-scorecard-expectation','strategy'=>'full-scorecard-v1','value'=>$values[$index],'scoreNowValue'=>$score['value'],'depth'=>$depth];
}
