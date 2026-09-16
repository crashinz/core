<?php
declare(strict_types=1);
const ACEY_DEUCY_BOT_ID = -7002;
const ACEY_DEUCY_BOT_ENGINE = 'acey-deucy-search-1';
function acey_deucy_bot_choices(): array {
    return [['value'=>'none','label'=>'None'],['value'=>'easy','label'=>'Easy'],['value'=>'normal','label'=>'Normal'],['value'=>'expert','label'=>'Expert']];
}
function acey_deucy_project_virtual_members(PDO $pdo, array $state, array $context): array {
    return ($context['mode'] ?? '') === 'practice' ? array_values($state['bots'] ?? []) : [];
}
function acey_deucy_bot_position_key(array $s): string {
    return hash('sha256',multiplayer_game_canonical_json(array_intersect_key($s,array_flip(['turnOrder','turnIndex','points','off','bar','borneOff','remainingDice','dice','aceyStage','europeanSequence','rulesProfile','bots','completed','roundNumber']))));
}
function acey_deucy_bot_task(array $s, int $viewer, array $c): ?array {
    if (($c['mode'] ?? '')!=='practice' || ($c['status'] ?? '')!=='active' || !in_array($c['viewerRole'] ?? '',['master','player'],true)
        || $viewer<=0 || !in_array($viewer,$s['turnOrder'] ?? [],true) || empty($s['bots'][(string)ACEY_DEUCY_BOT_ID]) || !empty($s['completed'])
        || ($s['aceyStage'] ?? '')==='opening-roll' || (int)($s['turnOrder'][(int)($s['turnIndex'] ?? -1)] ?? 0)!==ACEY_DEUCY_BOT_ID) return null;
    $level=$s['bots'][(string)ACEY_DEUCY_BOT_ID]['difficulty'];
    return ['engine'=>ACEY_DEUCY_BOT_ENGINE,'positionKey'=>acey_deucy_bot_position_key($s),'actor'=>ACEY_DEUCY_BOT_ID,'difficulty'=>$level,
        'moveTimeMs'=>['easy'=>150,'normal'=>700,'expert'=>1800][$level], 'presentationDelayMs'=>2000,
        'action'=>in_array($s['aceyStage'],['roll','roll-again'],true)?'roll':($s['aceyStage']==='choose-double'?'choose-double':'move'),
        'position'=>array_intersect_key($s,array_flip(['turnOrder','turnIndex','points','off','bar','borneOff','remainingDice','aceyStage','europeanSequence','rulesProfile']))];
}
function acey_deucy_apply_action(array $s, int $actor, string $action, array $payload, array $context): array {
    if ($actor<=0 || !in_array($actor,$s['turnOrder'] ?? [],true)) throw new MultiplayerGameException('Only an authenticated participant may act.','ACEY_DEUCY_PLAYER_INVALID',403);
    if (!empty($s['bots']) && ($context['mode'] ?? 'practice')!=='practice') throw new MultiplayerGameException('Games with bots are Practice only.','MULTIPLAYER_GAME_BOTS_PRACTICE_ONLY',422);
    $trace=null;
    if (in_array($action,['bot-step','bot-roll','bot-double'],true)) {
        if (($context['mode'] ?? '')!=='practice' || empty($s['bots'][(string)ACEY_DEUCY_BOT_ID])) throw new MultiplayerGameException('A Practice bot is not available.','ACEY_DEUCY_BOT_UNAVAILABLE',409);
        if (($payload['engine'] ?? '')!==ACEY_DEUCY_BOT_ENGINE) throw new MultiplayerGameException('The bot was updated. Reload the game.','ACEY_DEUCY_BOT_ENGINE_MISMATCH',409);
        if (!hash_equals(acey_deucy_bot_position_key($s),(string)($payload['positionKey'] ?? ''))) throw new MultiplayerGameException('The position changed. Refresh before retrying.','ACEY_DEUCY_BOT_POSITION_STALE',409);
        $actor=ACEY_DEUCY_BOT_ID; ocx_game_assert_turn($s,$actor);
        $action=['bot-step'=>'move','bot-roll'=>'roll','bot-double'=>'choose-double'][$action];
        if ($action==='move' && ((!is_int($payload['from'] ?? null) && !in_array($payload['from'] ?? null,['bar','off'],true)) || !is_int($payload['die'] ?? null))) throw new MultiplayerGameException('Choose a valid checker and die.','ACEY_DEUCY_MOVE_INVALID',422);
        if ($action==='choose-double' && !is_int($payload['value'] ?? null)) throw new MultiplayerGameException('Choose a valid double.','ACEY_DEUCY_DOUBLE_INVALID',422);
        $trace=['engine'=>ACEY_DEUCY_BOT_ENGINE,'difficulty'=>$s['bots'][(string)$actor]['difficulty'],'reason'=>'variant-legal-public-position-search','elapsedMs'=>max(0,min(60000,(int)($payload['elapsedMs'] ?? 0))),'replyRolls'=>max(0,min(21,(int)($payload['replyRolls'] ?? 0)))];
        $payload=$action==='roll'?[]:($action==='move'?['from'=>$payload['from'],'die'=>$payload['die']]:['value'=>$payload['value']]); $trace['selected']=$payload;
    }
    $r=acey_deucy_apply_action_core($s,$actor,$action,$payload,$context);
    if (function_exists('game_recording_observe')) game_recording_observe($context,'acey-deucy',$s,$actor,$action,$payload,$r['state'],$trace);
    return $r;
}
