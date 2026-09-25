<?php
declare(strict_types=1);

/** Request-driven, bounded arcade simulation. No browser clock or score is trusted. */
const ARCADE_STEP_MS = 20;
const ARCADE_MAX_CATCHUP_MS = 5000;

function arcade_now_ms(array $context): int
{
    return (int)($context['nowUnixMs'] ?? floor(microtime(true) * 1000));
}

function arcade_adapter(string $id, string $prefix): array
{
    return [
        'id' => $id, 'initialState' => $prefix . '_initial_state',
        'applyAction' => 'arcade_apply_action', 'projectState' => 'arcade_project_state',
        'validateSettings' => 'arcade_validate_settings',
        'settingsProjection' => 'arcade_settings_projection',
        'rulesProjection' => $prefix . '_rules_projection',
        'presentationStatus' => 'arcade_presentation_status',
        'openingProcedure' => 'simultaneous-server-clock',
        'allowsConcurrentInputs' => true,
    ];
}

function arcade_validate_settings(array $settings, string $mode, array $definition = []): array
{
    if ($settings !== []) {
        throw new MultiplayerGameException('This arcade game has no additional match rules.', 'ARCADE_SETTINGS_INVALID', 422);
    }
    return [];
}

function arcade_settings_projection(array $settings, string $mode, array $definition = []): array
{
    arcade_validate_settings($settings, $mode, $definition);
    return ['label' => 'Game Options', 'description' => 'The original arcade rules are fixed. Display size is personal to your browser.', 'controls' => []];
}

function arcade_presentation_status(PDO $pdo, ?string $requestedPack = null): array
{
    return ['requestedPack' => 'built-in', 'effectivePack' => 'built-in',
        'classicAvailable' => false, 'fallbackApplied' => false, 'presentationOnly' => true];
}

function arcade_initial_state(string $kind, array $playerIds, int $count, array $context): array
{
    $players = array_values(array_unique(array_map('intval', $playerIds)));
    if (count($players) !== $count || min($players) < 1) {
        throw new MultiplayerGameException('The arcade player set is invalid.', 'ARCADE_PLAYERS_INVALID', 422);
    }
    arcade_validate_settings((array)($context['settings'] ?? []), (string)($context['mode'] ?? 'practice'));
    return [
        'schemaVersion' => 1, 'arcadeKind' => $kind, 'turnOrder' => $players,
        'turnIndex' => null, 'phase' => 'playing', 'completed' => false,
        'winnerUserId' => null, 'terminalReason' => null,
        'clock' => ['kind' => 'arcade-server'],
        'realtime' => ['lastAtMs' => arcade_now_ms($context), 'pendingMs' => 0, 'frozenAtMs' => null],
        'elapsedMs' => 0, 'inputSequences' => array_fill_keys($players, 0),
        'inputHashes' => [], 'inputBudgets' => array_fill_keys($players, 16),
    ];
}

function arcade_finish(array $state, ?int $winner, string $reason): array
{
    $state['completed'] = true;
    $state['phase'] = 'completed';
    $state['winnerUserId'] = $winner;
    $state['terminalReason'] = $reason;
    $members = [];
    foreach ($state['turnOrder'] as $id) {
        $members[(string)$id] = ['score' => $winner === $id ? 1 : 0,
            'outcome' => $winner === null ? 'draw' : ($winner === $id ? 'win' : 'loss')];
    }
    if ($state['arcadeKind'] === 'space') {
        $state['winnerUserId']=null;
        $state['runHistory'] = ['score' => $state['ship']['score'],
            'highestLevel' => $state['level'], 'elapsedMs' => $state['elapsedMs'],
            'terminalReason' => $reason, 'practiceOnly' => true];
        if(isset($state['ships'])){
            $scores=[];foreach($state['turnOrder'] as $id)$scores[$id]=$state['ships'][$id]['score'];
            $state['runHistory']['playerScores']=$scores;$state['runHistory']['score']=array_sum($scores);
        }
        $members = [];
    }
    return ['state' => $state, 'turnUserId' => null, 'terminal' => true, 'result' => ['members' => $members]];
}

function arcade_advance(array $state, int $now): array
{
    $clock = &$state['realtime'];
    if ($clock['frozenAtMs'] !== null) return $state;
    $delta = max(0, $now - (int)$clock['lastAtMs']);
    $clock['lastAtMs'] = max($now, (int)$clock['lastAtMs']);
    $clock['pendingMs'] += $delta;
    $steps = min(intdiv((int)$clock['pendingMs'], ARCADE_STEP_MS), intdiv(ARCADE_MAX_CATCHUP_MS, ARCADE_STEP_MS));
    for ($i = 0; $i < $steps && !$state['completed']; $i++) {
        $clock['pendingMs'] -= ARCADE_STEP_MS;
        $state['elapsedMs'] += ARCADE_STEP_MS;
        foreach ($state['inputBudgets'] as &$budget) $budget = min(16, $budget + 1);
        unset($budget);
        if ($state['arcadeKind'] === 'tetris') {
            foreach ($state['boards'] as &$board) tetris_arcade_tick($board, ARCADE_STEP_MS);
            unset($board);
            tetris_bot_tick($state, ARCADE_STEP_MS);
            $dead = array_keys(array_filter($state['boards'], static fn(array $board): bool => !$board['alive']));
            if ($dead !== []) {
                $living = array_values(array_diff($state['turnOrder'], array_map('intval', $dead)));
                $state['completed'] = true;
                $state['winnerUserId'] = count($living) === 1 ? (int)$living[0] : null;
                $state['terminalReason'] = count($dead) > 1 ? 'simultaneous-top-out' : 'top-out';
            }
        } else {
            space_arcade_tick($state, ARCADE_STEP_MS);
        }
    }
    return $state;
}

function arcade_apply_action(array $state, int $actorUserId, string $action, array $payload, array $context): array
{
    if(isset($state['ships']) && in_array($action,['arcade-input','arcade-tick'],true)) return space_arcade_coop_action($state,$actorUserId,$action,$payload,$context);
    if (($state['arcadeKind'] ?? '') === 'space'
        && (($action === 'arcade-input' && array_key_exists('frames', $payload))
            || ($action === 'arcade-tick' && ($state['spaceInputMode'] ?? '') === 'frames'))) {
        return space_arcade_frame_action($state, $actorUserId, $action, $payload, $context);
    }
    if (($state['schemaVersion'] ?? 0) !== 1 || !isset($state['realtime']) || !empty($state['completed'])) {
        throw new MultiplayerGameException('This arcade game is not active.', 'ARCADE_STATE_INVALID', 409);
    }
    if (!in_array($actorUserId, $state['turnOrder'], true)) {
        throw new MultiplayerGameException('Only a seated player may act.', 'ARCADE_PLAYER_INVALID', 403);
    }
    if (!($action === 'resign' && $state['arcadeKind'] === 'space')
        && (in_array($state['_framework']['pause']['mode'] ?? 'running', ['paused', 'resuming', 'completed'], true)
        || !empty($state['_framework']['serviceInterruption']['active']))) {
        throw new MultiplayerGameException('The game is paused.', 'MULTIPLAYER_GAME_PAUSED', 409);
    }
    if (!in_array($action, ['arcade-input', 'arcade-tick', 'resign'], true)) {
        throw new MultiplayerGameException('Unsupported arcade action.', 'ARCADE_ACTION_INVALID', 422);
    }
    $sequence = null;
    $hash = '';
    if ($action === 'arcade-input') {
        $allowed = $state['arcadeKind'] === 'tetris' ? ['sequence', 'commands'] : ['sequence', 'left', 'right', 'fire'];
        if (array_diff(array_keys($payload), $allowed) !== [] || !is_int($payload['sequence'] ?? null)) {
            throw new MultiplayerGameException('Invalid arcade input envelope.', 'ARCADE_INPUT_INVALID', 422);
        }
        $sequence = $payload['sequence'];
        $last = (int)$state['inputSequences'][$actorUserId];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        if ($sequence !== $last + 1 && !($sequence === $last && ($state['inputHashes'][$actorUserId] ?? '') === $hash)) {
            throw new MultiplayerGameException('Synchronize the input sequence before continuing.', 'ARCADE_INPUT_SEQUENCE', 409);
        }
        if ($state['arcadeKind'] === 'tetris') {
            $commands = $payload['commands'] ?? null;
            if (!is_array($commands) || !array_is_list($commands) || count($commands) > 12 || $commands === []) {
                throw new MultiplayerGameException('Invalid Tetris command batch.', 'ARCADE_INPUT_INVALID', 422);
            }
            foreach ($commands as $command) {
                if (!is_string($command) || !in_array($command, ['left', 'right', 'down', 'cw', 'ccw', 'drop'], true)) {
                    throw new MultiplayerGameException('Invalid Tetris command.', 'ARCADE_INPUT_INVALID', 422);
                }
            }
        } else {
            foreach (['left', 'right', 'fire'] as $key) {
                if (!is_bool($payload[$key] ?? null)) {
                    throw new MultiplayerGameException('Invalid ship controls.', 'ARCADE_INPUT_INVALID', 422);
                }
            }
        }
    } elseif ($payload !== []) {
        throw new MultiplayerGameException('This action does not accept a state or score.', 'ARCADE_INPUT_INVALID', 422);
    }
    $state = arcade_advance($state, arcade_now_ms($context));
    if ($state['completed']) return arcade_finish($state, $state['winnerUserId'], $state['terminalReason']);
    if ($action === 'resign') {
        $others = array_values(array_diff($state['turnOrder'], [$actorUserId]));
        return arcade_finish($state, $others === [] ? null : (int)$others[0], 'resignation');
    }
    // Catch up after a long absence before accepting new inputs into old time.
    if ($action === 'arcade-input' && $state['realtime']['pendingMs'] < ARCADE_STEP_MS
        && $sequence === (int)$state['inputSequences'][$actorUserId] + 1) {
        if ($state['arcadeKind'] === 'tetris') {
            if (count($payload['commands']) > $state['inputBudgets'][$actorUserId]) {
                throw new MultiplayerGameException('Input is arriving too quickly.', 'ARCADE_INPUT_RATE', 429);
            }
            $state['inputBudgets'][$actorUserId] -= count($payload['commands']);
            foreach ($payload['commands'] as $command) {
                tetris_arcade_command($state['boards'][$actorUserId], $command);
                if (!$state['boards'][$actorUserId]['alive']) {
                    $others = array_values(array_diff($state['turnOrder'], [$actorUserId]));
                    return arcade_finish($state, $others===[]?null:(int)$others[0], 'top-out');
                }
            }
        } else {
            $state['ship']['controls'] = array_intersect_key($payload, array_flip(['left', 'right', 'fire']));
            $state['ship']['inputLeaseMs'] = 1200;
        }
        $state['inputSequences'][$actorUserId] = $sequence;
        $state['inputHashes'][$actorUserId] = $hash;
    }
    return ['state' => $state, 'turnUserId' => null];
}

function arcade_project_state(array $state, int $viewerUserId, array $context): array
{
    if (!isset($state['arcadeKind'])) return ['phase' => 'lobby', 'completed' => false, 'legalActions' => []];
    if (isset($state['spaceFrameInputs'])) {
        // Restore only this viewer's accepted, not-yet-simulated controls.
        $state['spacePendingInputs'] = $state['spaceFrameInputs'][$viewerUserId] ?? [];
    }
    unset($state['inputHashes'], $state['inputBudgets'], $state['spaceFrameInputs']);
    foreach ($state['boards'] ?? [] as $id => $board) {
        unset($board['seed'], $board['bag'], $board['bagNumber']);
        $state['boards'][$id] = $board;
    }
    $state['legalActions'] = !$state['completed'] && in_array($viewerUserId, $state['turnOrder'], true)
        ? ['arcade-input', 'arcade-tick', 'resign'] : [];
    return $state;
}
