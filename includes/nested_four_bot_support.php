<?php
declare(strict_types=1);

const NESTED_FOUR_BOT_ENGINE = 'nested-four-search-1';
function nested_four_bot_choices(): array {
    return [['value'=>'none','label'=>'None'],['value'=>'easy','label'=>'Easy'],['value'=>'normal','label'=>'Normal'],['value'=>'expert','label'=>'Expert']];
}
function nested_four_project_virtual_members(PDO $pdo, array $state, array $context): array {
    return ($context['mode'] ?? '')==='practice' ? array_values($state['bots'] ?? []) : [];
}
/** Known starting composition, not a copy of authoritative hidden stacks. */
function nested_four_memory_initial(array $players): array {
    $m=['board'=>array_fill(0,16,[]),'reserves'=>[[[1,2,3,4],[5,6,7,8],[9,10,11,12]],[[-1,-2,-3,-4],[-5,-6,-7,-8],[-9,-10,-11,-12]]],
        'selected'=>null,'turn'=>0,'seen'=>[],'valid'=>true];
    $m['seen'][json_encode([$m['board'],$m['reserves'],$m['turn']])]=1;return $m;
}
function nested_four_memory_symbol(int $code): int { return $code===0?0:(($code>0?1:-1)*((abs($code)-1)%4+1)); }
function nested_four_memory_code(array $piece, array $order): int {
    return ((int)($piece['size'] ?? -1)+1) * ((int)($piece['ownerUserId'] ?? 0)===(int)$order[0]?1:-1);
}
/** Update exclusively from the remembered position and the publicly visible action/reveal. */
function nested_four_memory_observe(array $before, array $after): array {
    $m=$before['botMemory'] ?? null;
    if (!is_array($m)) return ['valid'=>false];
    $a=$after['lastAction'] ?? []; $order=$after['turnOrder'];
    if (in_array($a['type'] ?? '',['select','select-loss'],true)) {
        $p=(int)$a['userId']===(int)$order[0]?0:1;$i=(int)$a['sourceIndex'];
        if ($a['sourceType']==='reserve') $code=array_pop($m['reserves'][$p][$i]);
        else $code=array_pop($m['board'][$i]);
        $visible=nested_four_memory_code((array)$after['selected']['piece'],$order);
        if (nested_four_memory_symbol((int)$code)!==$visible) $m['valid']=false;
        $m['selected']=['piece'=>(int)$code,'sourceType'=>$a['sourceType'],'sourceIndex'=>$i];
        // The newly exposed top is public. An inconsistent memory fails closed instead of peeking below it.
        if ($a['sourceType']==='board') {
            $revealed=nested_four_visible_piece($after,$i);
            if (nested_four_memory_symbol($m['board'][$i]===[]?0:$m['board'][$i][array_key_last($m['board'][$i])])!==($revealed?nested_four_memory_code($revealed,$order):0)) $m['valid']=false;
        }
    } elseif (($a['type'] ?? '')==='move') {
        $m['board'][(int)$a['destination']][]=(int)($m['selected']['piece'] ?? 0);$m['selected']=null;
    }
    $m['turn']=(int)$after['turnIndex'];
    if (($a['type'] ?? '')==='move') {
        $key=json_encode([$m['board'],$m['reserves'],$m['turn']]);
        $m['seen'][$key]=(int)($m['seen'][$key] ?? 0)+1;
    }
    return $m;
}
function nested_four_bot_position_key(array $state, array $context): string {
    // Public identity only: never expose a digest of hidden board contents.
    return hash('sha256',(string)($context['sessionPublicId'] ?? '').':'.(int)($context['stateVersion'] ?? -1).':'.(int)($state['actionSequence'] ?? 0));
}
function nested_four_bot_task(array $state, int $viewer, array $context): ?array {
    $actor=nested_four_turn_user($state);
    if (($context['mode'] ?? '')!=='practice'||($context['status'] ?? '')!=='active'||!in_array($context['viewerRole'] ?? '',['master','player'],true)
        ||$viewer<=0||!in_array($viewer,$state['turnOrder'] ?? [],true)||empty($state['bots'][(string)$actor])||!empty($state['completed'])||empty($state['botMemory']['valid'])) return null;
    $level=$state['bots'][(string)$actor]['difficulty'];
    return ['engine'=>NESTED_FOUR_BOT_ENGINE,'positionKey'=>nested_four_bot_position_key($state,$context),'difficulty'=>$level,
        'moveTimeMs'=>['easy'=>100,'normal'=>700,'expert'=>1700][$level],
        'presentationDelayMs'=>empty($state['selected'])?2000:900,
        'position'=>$state['botMemory'],'reserveCoveringRule'=>nested_four_reserve_covering_rule($state)];
}
function nested_four_apply_action(array $state, int $actorUserId, string $action, array $payload, array $context): array {
    if ($actorUserId<=0||!in_array($actorUserId,$state['turnOrder'] ?? [],true)) throw new MultiplayerGameException('Only an authenticated participant may act.','NESTED_FOUR_PLAYER_INVALID',403);
    if (!empty($state['bots'])&&($context['mode'] ?? 'practice')!=='practice') throw new MultiplayerGameException('Games with bots are Practice only.','MULTIPLAYER_GAME_BOTS_PRACTICE_ONLY',422);
    $trace=null;
    if ($action==='bot-step') {
        $bot=nested_four_turn_user($state);
        if (($context['mode'] ?? '')!=='practice'||empty($state['bots'][(string)$bot])||empty($state['botMemory']['valid'])) throw new MultiplayerGameException('A Practice bot turn is unavailable.','NESTED_FOUR_BOT_UNAVAILABLE',409);
        if (($payload['engine'] ?? '')!==NESTED_FOUR_BOT_ENGINE||!hash_equals(nested_four_bot_position_key($state,$context),(string)($payload['positionKey'] ?? ''))) throw new MultiplayerGameException('The bot position changed. Refresh before retrying.','NESTED_FOUR_BOT_POSITION_STALE',409);
        $actorUserId=$bot;$action=empty($state['selected'])?'select':'move';
        $trace=['engine'=>NESTED_FOUR_BOT_ENGINE,'difficulty'=>$state['bots'][(string)$bot]['difficulty'],'reason'=>'observed-move-memory-search',
            'elapsedMs'=>max(0,min(60000,(int)($payload['elapsedMs'] ?? 0)))];
        $payload=$action==='select'?['sourceType'=>$payload['sourceType'] ?? '', 'sourceIndex'=>$payload['sourceIndex'] ?? null]:['destination'=>$payload['destination'] ?? null];
        $trace['selected']=$payload;
    }
    $applied=nested_four_apply_action_core($state,$actorUserId,$action,$payload,$context);
    if (!empty($state['bots'])) $applied['state']['botMemory']=nested_four_memory_observe($state,$applied['state']);
    if (function_exists('game_recording_observe')) game_recording_observe($context,'nested-four',$state,$actorUserId,$action,$payload,$applied['state'],$trace);
    return $applied;
}
function nested_four_recording_adapter(): array {
    return ['schemaVersion'=>1,'stateKeys'=>['schemaVersion','reserveCoveringRule','settings','bots','botMemory','turnOrder','turnIndex','phase','board','reserves','selected','moveNumber','actionSequence','lastAction','positionCounts','starterUserId','starterReason','completed','winnerUserId','terminalReason'],
        'payloadKeys'=>['sourceType','sourceIndex','destination']];
}
