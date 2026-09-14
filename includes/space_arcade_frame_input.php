<?php
declare(strict_types=1);

/** Ordered input frames, not client positions, scores, hits or wall-clock time. */
function space_arcade_frame_action(array $state, int $actor, string $action, array $payload, array $context): array
{
    if (($state['schemaVersion'] ?? 0) !== 1 || !isset($state['realtime']) || !empty($state['completed'])) {
        throw new MultiplayerGameException('This arcade game is not active.', 'ARCADE_STATE_INVALID', 409);
    }
    if (!in_array($actor, $state['turnOrder'], true)) {
        throw new MultiplayerGameException('Only the player can send controls.', 'ARCADE_PLAYER_INVALID', 403);
    }
    if (!in_array($state['_framework']['pause']['mode'] ?? 'running', ['running', 'proposed'], true)
        || $state['realtime']['frozenAtMs'] !== null
        || multiplayer_game_shared_service_interruption_active($state)) {
        throw new MultiplayerGameException('The game is paused.', 'MULTIPLAYER_GAME_PAUSED', 409);
    }
    $sequence = 0;
    $hash = '';
    $ticks = 0;
    if ($action === 'arcade-input') {
        if (array_diff(array_keys($payload), ['sequence', 'frameStart', 'frames'])
            || !is_int($payload['sequence'] ?? null) || $payload['sequence'] < 1
            || !is_int($payload['frameStart'] ?? null) || $payload['frameStart'] < 0
            || $payload['frameStart'] % ARCADE_STEP_MS !== 0
            || !is_array($payload['frames'] ?? null) || !array_is_list($payload['frames'])
            || count($payload['frames']) < 1 || count($payload['frames']) > 25) {
            throw new MultiplayerGameException('Invalid arcade input frames.', 'ARCADE_INPUT_INVALID', 422);
        }
        foreach ($payload['frames'] as $run) {
            if (!is_array($run) || array_diff(array_keys($run), ['ticks', 'left', 'right', 'fire'])
                || !is_int($run['ticks'] ?? null) || $run['ticks'] < 1 || $run['ticks'] > 25
                || !is_bool($run['left'] ?? null) || !is_bool($run['right'] ?? null) || !is_bool($run['fire'] ?? null)) {
                throw new MultiplayerGameException('Invalid arcade input frames.', 'ARCADE_INPUT_INVALID', 422);
            }
            $ticks += $run['ticks'];
        }
        if ($ticks > 25) throw new MultiplayerGameException('Too many arcade input frames.', 'ARCADE_INPUT_INVALID', 422);
        $sequence = $payload['sequence'];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $last = (int)($state['inputSequences'][$actor] ?? 0);
        if ($sequence === $last && hash_equals((string)($state['inputHashes'][$actor] ?? ''), $hash)) {
            return ['state' => $state, 'turnUserId' => null];
        }
        if ($sequence !== $last + 1) throw new MultiplayerGameException('The input sequence changed.', 'ARCADE_INPUT_SEQUENCE', 409);
        if ($payload['frameStart'] !== $state['elapsedMs']) {
            throw new MultiplayerGameException('Refresh the arcade input timeline.', 'ARCADE_FRAME_STALE', 409);
        }
    } elseif ($action !== 'arcade-tick' || $payload !== []) {
        throw new MultiplayerGameException('Invalid arcade action.', 'ARCADE_INPUT_INVALID', 422);
    }

    $now = max((int)$state['realtime']['lastAtMs'], arcade_now_ms($context));
    $available = (int)$state['realtime']['pendingMs'] + $now - (int)$state['realtime']['lastAtMs'];
    // At most one second of input may be in transit. Missing input does not
    // pause the authoritative clock or permit an arbitrarily slow client game.
    if ($available > 1000) {
        $state['ship']['controls'] = ['left' => false, 'right' => false, 'fire' => false];
        $state['ship']['inputLeaseMs'] = 0;
        $state = arcade_advance($state, $now);
        return $state['completed'] ? arcade_finish($state, null, $state['terminalReason']) : ['state' => $state, 'turnUserId' => null];
    }
    if ($ticks * ARCADE_STEP_MS > $available) {
        throw new MultiplayerGameException('Input arrived ahead of server time.', 'ARCADE_INPUT_AHEAD', 409);
    }
    $state['spaceInputMode'] = 'frames';
    $state['realtime']['lastAtMs'] = $now;
    $state['realtime']['pendingMs'] = $available;
    foreach ($payload['frames'] ?? [] as $run) {
        for ($i = 0; $i < $run['ticks'] && !$state['completed']; $i++) {
            $state['ship']['controls'] = ['left' => $run['left'], 'right' => $run['right'], 'fire' => $run['fire']];
            $state['ship']['inputLeaseMs'] = 1200;
            $state['elapsedMs'] += ARCADE_STEP_MS;
            $state['realtime']['pendingMs'] -= ARCADE_STEP_MS;
            foreach ($state['inputBudgets'] as &$budget) $budget = min(16, $budget + 1);
            unset($budget);
            space_arcade_tick($state, ARCADE_STEP_MS);
        }
    }
    if ($action === 'arcade-input') {
        $state['inputSequences'][$actor] = $sequence;
        $state['inputHashes'][$actor] = $hash;
    }
    return $state['completed'] ? arcade_finish($state, null, $state['terminalReason']) : ['state' => $state, 'turnUserId' => null];
}
