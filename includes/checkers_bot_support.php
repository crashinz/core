<?php
declare(strict_types=1);

const CHECKERS_BOT_ID = -6502;
const CHECKERS_BOT_ENGINE = 'marcher-1fa785ed-corechat-2';

function checkers_bot_levels(): array
{
    return [
        'easy' => ['label' => 'Easy', 'depth' => 1, 'moveTimeMs' => 100],
        'normal' => ['label' => 'Normal', 'depth' => 6, 'moveTimeMs' => 400],
        'expert' => ['label' => 'Expert', 'depth' => 12, 'moveTimeMs' => 1000],
    ];
}
function checkers_bot_choices(): array
{
    $choices = [['value' => 'none', 'label' => 'None']];
    foreach (checkers_bot_levels() as $key => $level) $choices[] = ['value' => $key, 'label' => $level['label']];
    return $choices;
}
function checkers_bot_strength_note(): string
{
    return 'Easy is the most forgiving; Normal and Expert think further ahead. Bots follow your selected movement rules. Practice only. Difficulty names are relative bot levels, not Elo ratings.';
}
function checkers_project_virtual_members(PDO $pdo, array $state, array $context): array
{
    return ($context['mode'] ?? '') === 'practice' ? array_values((array)($state['bots'] ?? [])) : [];
}
function checkers_bot_position_key(array $state): string
{
    return hash('sha256', multiplayer_game_canonical_json(array_intersect_key($state, array_flip([
        'board', 'turnOrder', 'turnIndex', 'sideAssignments', 'forcedFrom', 'quietKingPlies', 'positionCounts',
        'positionSnapshots', 'positionHistoryComplete', 'settings', 'bots', 'drawOfferBy', 'completed', 'roundNumber',
    ]))));
}
function checkers_bot_task(array $state, int $viewer, array $context): ?array
{
    if (($context['mode'] ?? '') !== 'practice' || ($context['status'] ?? '') !== 'active'
        || !in_array($context['viewerRole'] ?? '', ['master', 'player'], true)
        || $viewer <= 0 || !in_array($viewer, $state['turnOrder'] ?? [], true)
        || empty($state['bots'][(string)CHECKERS_BOT_ID]) || !empty($state['completed'])) return null;
    $turn = (int)($state['turnOrder'][(int)$state['turnIndex']] ?? 0);
    $offer = (int)($state['drawOfferBy'] ?? 0);
    if ($turn !== CHECKERS_BOT_ID && $offer !== $viewer) return null;
    $difficulty = (string)$state['bots'][(string)CHECKERS_BOT_ID]['difficulty'];
    $level = checkers_bot_levels()[$difficulty] ?? null;
    if ($level === null) return null;
    $budget = $level['moveTimeMs'];
    if (($state['clock']['kind'] ?? 'none') !== 'none') {
        $clock = $state['clocks'][(string)CHECKERS_BOT_ID];
        $remaining = (int)$clock['remainingSeconds'];
        if (!empty($clock['turnStartedAt'])) $remaining -= max(0, time() - (int)strtotime($clock['turnStartedAt']));
        $budget = max(20, min($budget, $remaining * 100));
    }
    return ['positionKey' => checkers_bot_position_key($state), 'engine' => CHECKERS_BOT_ENGINE,
        'difficulty' => $difficulty, 'moveTimeMs' => $budget, 'respondToDraw' => $offer === $viewer,
        'position' => array_intersect_key($state, array_flip(['board', 'turnOrder', 'turnIndex', 'sideAssignments',
            'forcedFrom', 'quietKingPlies', 'positionCounts', 'positionSnapshots', 'positionHistoryComplete', 'settings', 'drawOfferBy', 'completed']))];
}
/** Only the authoritative reducer applies a proposed bot move. */
function checkers_apply_action(array $state, int $actorUserId, string $action, array $payload, array $context): array
{
    if ($actorUserId <= 0 || !in_array($actorUserId, $state['turnOrder'] ?? [], true)) {
        throw new MultiplayerGameException('Only an authenticated Checkers participant may act.', 'CHECKERS_PLAYER_INVALID', 403);
    }
    if (!empty($state['bots']) && ($context['mode'] ?? 'practice') !== 'practice') {
        throw new MultiplayerGameException('Games with bots are Practice only.', 'MULTIPLAYER_GAME_BOTS_PRACTICE_ONLY', 422);
    }
    $trace = null;
    if ($action === 'bot-step') {
        if (($context['mode'] ?? '') !== 'practice' || empty($state['bots'][(string)CHECKERS_BOT_ID])) {
            throw new MultiplayerGameException('A Practice Checkers bot is not available.', 'CHECKERS_BOT_UNAVAILABLE', 409);
        }
        if (!hash_equals(checkers_bot_position_key($state), (string)($payload['positionKey'] ?? ''))) {
            throw new MultiplayerGameException('The Checkers position changed. Refresh before retrying.', 'CHECKERS_BOT_POSITION_STALE', 409);
        }
        if (($payload['engine'] ?? '') !== CHECKERS_BOT_ENGINE) {
            throw new MultiplayerGameException('The Checkers bot was updated. Reload the game to use the current engine.', 'CHECKERS_BOT_ENGINE_MISMATCH', 409);
        }
        $actorUserId = CHECKERS_BOT_ID;
        $trace = ['reason' => 'browser-marcher-proposal-server-validated', 'engine' => CHECKERS_BOT_ENGINE,
            'difficulty' => $state['bots'][(string)CHECKERS_BOT_ID]['difficulty'],
            'elapsedMs' => max(0, min(60000, (int)($payload['elapsedMs'] ?? 0))),
            'publicObservation' => ['positionKey' => checkers_bot_position_key($state),
                'movementRules' => array_intersect_key($state['settings'], array_flip(['backwardMovement', 'backwardCapture', 'flyingKings'])),
                'searchMode' => !empty($state['settings']['backwardMovement']) || !empty($state['settings']['backwardCapture']) || !empty($state['settings']['flyingKings']) ? 'optional-rules' : 'standard']];
        if ((int)($state['drawOfferBy'] ?? 0) > 0) {
            // As with Chess, proposals are declined; automatic draw rules remain active.
            $action = 'decline-draw'; $payload = [];
        } else {
            ocx_game_assert_turn($state, CHECKERS_BOT_ID);
            $action = 'move'; $payload = ['from' => $payload['from'] ?? null, 'to' => $payload['to'] ?? null];
            $trace['selected'] = $payload;
        }
    }
    $applied = checkers_apply_action_core($state, $actorUserId, $action, $payload, $context);
    if (function_exists('game_recording_observe')) game_recording_observe($context, 'checkers', $state, $actorUserId, $action, $payload, $applied['state'], $trace);
    return $applied;
}
