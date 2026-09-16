<?php
declare(strict_types=1);

const CHINESE_CHECKERS_BOT_ENGINE = 'jumpstar-classical-b39d39e-corechat-1';
function chinese_checkers_bot_choices(): array
{
    return [['value'=>'none','label'=>'None'],['value'=>'easy','label'=>'Easy'],['value'=>'normal','label'=>'Normal'],['value'=>'expert','label'=>'Expert']];
}
function chinese_checkers_bot_fill_seats(array $humans, array $settings, string $mode, array $seats): array
{
    if ($seats === []) foreach ($humans as $i=>$id) $seats[$i+1]=$id;
    $players=[]; $bots=[];
    for ($seat=1;$seat<=6;$seat++) {
        if (isset($seats[$seat])) { $players[]=(int)$seats[$seat]; continue; }
        $level=$settings['botSeat'.$seat.'Difficulty'] ?? 'none';
        if ($mode!=='practice' || $level==='none') continue;
        $id=-6800-$seat; $players[]=$id;
        $bots[(string)$id]=['userId'=>$id,'seat'=>$seat,'difficulty'=>$level,'displayName'=>ucfirst($level).' Bot '.$seat,'engine'=>CHINESE_CHECKERS_BOT_ENGINE];
    }
    return [$players,$bots];
}
function chinese_checkers_project_virtual_members(PDO $pdo, array $state, array $context): array
{
    return ($context['mode'] ?? '')==='practice' ? array_values($state['bots'] ?? []) : [];
}
function chinese_checkers_bot_position_key(array $state): string
{
    return hash('sha256',multiplayer_game_canonical_json(array_intersect_key($state,array_flip(['board','turnOrder','turnIndex','targetByUser','homeByUser','moveNumber','completed','bots']))));
}
function chinese_checkers_bot_task(array $state, int $viewer, array $context): ?array
{
    $actor=chinese_checkers_turn_user($state);
    if (($context['mode'] ?? '')!=='practice' || ($context['status'] ?? '')!=='active' || !in_array($context['viewerRole'] ?? '',['master','player'],true)
        || $viewer<=0 || !in_array($viewer,$state['turnOrder'] ?? [],true) || empty($state['bots'][(string)$actor]) || !empty($state['completed'])) return null;
    $level=$state['bots'][(string)$actor]['difficulty'];
    return ['engine'=>CHINESE_CHECKERS_BOT_ENGINE,'positionKey'=>chinese_checkers_bot_position_key($state),'difficulty'=>$level,
        'moveTimeMs'=>$level==='expert'?1400:350,'position'=>array_intersect_key($state,array_flip(['board','turnOrder','turnIndex','homeByUser','targetByUser','history','moveNumber'])),
        'geometry'=>chinese_checkers_hole_geometry()['holes'],'arms'=>chinese_checkers_arm_holes()];
}
/** A browser proposes a move; only the existing server rules can apply it. */
function chinese_checkers_apply_action(array $state, int $actorUserId, string $action, array $payload, array $context): array
{
    if ($actorUserId<=0 || !in_array($actorUserId,$state['turnOrder'] ?? [],true)) throw new MultiplayerGameException('Only an authenticated participant may act.','CHINESE_CHECKERS_PLAYER_INVALID',403);
    if (!empty($state['bots']) && ($context['mode'] ?? 'practice')!=='practice') throw new MultiplayerGameException('Games with bots are Practice only.','MULTIPLAYER_GAME_BOTS_PRACTICE_ONLY',422);
    $trace=null;
    if ($action==='bot-step') {
        $bot=chinese_checkers_turn_user($state);
        if (($context['mode'] ?? '')!=='practice' || empty($state['bots'][(string)$bot])) throw new MultiplayerGameException('A Practice bot is not available.','CHINESE_CHECKERS_BOT_UNAVAILABLE',409);
        if (!hash_equals(chinese_checkers_bot_position_key($state),(string)($payload['positionKey'] ?? ''))) throw new MultiplayerGameException('The position changed. Refresh before retrying.','CHINESE_CHECKERS_BOT_POSITION_STALE',409);
        if (($payload['engine'] ?? '')!==CHINESE_CHECKERS_BOT_ENGINE) throw new MultiplayerGameException('The bot was updated. Reload the game.','CHINESE_CHECKERS_BOT_ENGINE_MISMATCH',409);
        $actorUserId=$bot; $action='move';
        $trace=['engine'=>CHINESE_CHECKERS_BOT_ENGINE,'difficulty'=>$state['bots'][(string)$bot]['difficulty'],'reason'=>'browser-classical-search-server-validated',
            'elapsedMs'=>max(0,min(60000,(int)($payload['elapsedMs'] ?? 0))),'publicObservation'=>['positionKey'=>chinese_checkers_bot_position_key($state)]];
        $payload=['from'=>$payload['from'] ?? '', 'to'=>$payload['to'] ?? '']; $trace['selected']=$payload;
    }
    $applied=chinese_checkers_apply_action_core($state,$actorUserId,$action,$payload,$context);
    if (function_exists('game_recording_observe')) game_recording_observe($context,'chinese-checkers',$state,$actorUserId,$action,$payload,$applied['state'],$trace);
    return $applied;
}
