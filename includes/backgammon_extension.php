<?php
declare(strict_types=1);

require_once __DIR__ . '/ocx_game_extension_support.php';
require_once __DIR__ . '/backgammon_bot_support.php';

const BACKGAMMON_EXTENSION_ID = 'backgammon-first-party';
const BACKGAMMON_STATE_SCHEMA_VERSION = 1;

function backgammon_recording_adapter(): array
{
    return ['schemaVersion' => 1,
        'stateKeys' => ['bots', 'starterMethod', 'backgammonStage', 'bar', 'borneOff', 'completed', 'dice', 'history', 'lastBlockedRoll', 'lastNoLegalMove', 'meaningfulPlay', 'moveUseRule', 'openingCoordinatorUserId', 'openingRollAttempts', 'points', 'remainingDice', 'resignedUserId', 'roundNumber', 'schemaVersion', 'settings', 'starterIndex', 'starterReason', 'starterUserId', 'terminalCause', 'terminalClassification', 'terminalReason', 'turnIndex', 'turnOrder', 'winnerUserId'],
        'payloadKeys' => ['die', 'from']];
}

function backgammon_extension_adapter(): array
{
    return [
        'recordingAdapter' => 'backgammon_recording_adapter',
        'id' => BACKGAMMON_EXTENSION_ID,
        'initialState' => 'backgammon_initial_state',
        'projectVirtualMembers' => 'backgammon_project_virtual_members',
        'applyAction' => 'backgammon_apply_action',
        'validateSettings' => 'backgammon_validate_settings',
        'settingsProjection' => 'backgammon_settings_projection',
        'rulesProjection' => 'backgammon_rules_projection',
        'projectState' => 'backgammon_project_state',
        'randomnessPurposes' => ['roll' => 'backgammon-roll', 'bot-roll' => 'backgammon-roll'],
        'deriveRandomness' => 'backgammon_derive_randomness',
        'presentationStatus' => 'backgammon_presentation_status',
        'openingProcedure' => 'settings-owned-high-roll-or-legacy-rotating-starter',
        'rematchSeatRotation' => false,
    ];
}

function backgammon_settings_projection(array $settings, string $mode, array $definition = []): array
{
    $settings = backgammon_validate_settings($settings, $mode, $definition);
    return [
        'label' => 'Game Options',
        'description' => 'Starter method and move-use rules are independent shared choices. Every player must accept both before play begins.',
        'classification' => $settings['starterMethod'] . ':' . $settings['moveUseRule'],
        'classificationLabel' => ($settings['starterMethod'] === 'rotate-starter' ? 'Legacy OCX starter' : 'CoreChat roll for first')
            . ' · ' . ($settings['moveUseRule'] === 'legacy-ocx' ? 'Legacy OCX move use' : 'Standard move use'),
        'controls' => [
            [
                'key' => 'starterMethod', 'type' => 'select', 'value' => $settings['starterMethod'],
                'defaultValue' => 'rotate-starter', 'label' => 'Starter method',
                'description' => 'Choose the opening starter independently from move-use rules.',
                'options' => [
                    ['value' => 'roll-for-first', 'label' => 'Roll for first — CoreChat'],
                    ['value' => 'rotate-starter', 'label' => 'Rotate starter — Default / Legacy OCX'],
                ],
            ],
            [
                'key' => 'moveUseRule', 'type' => 'select', 'value' => $settings['moveUseRule'],
                'defaultValue' => 'standard', 'label' => 'Move-use rules',
                'description' => 'Choose maximum-use enforcement independently from the starter method.',
                'options' => [
                    ['value' => 'standard', 'label' => 'Standard move use — Default / CoreChat'],
                    ['value' => 'legacy-ocx', 'label' => 'Legacy OCX move use'],
                ],
            ],
        ],
    ];
}

function backgammon_presentation_status(PDO $pdo, ?string $requestedPack = null): array
{
    return ocx_game_presentation_status($pdo, BACKGAMMON_EXTENSION_ID, $requestedPack);
}

function backgammon_validate_settings(array $settings, string $mode, array $definition = []): array
{
    if (!in_array($mode, ['practice', 'recorded'], true)) {
        throw new MultiplayerGameException('Choose Practice or Recorded Play.', 'MULTIPLAYER_GAME_MODE_INVALID', 422);
    }
    $allowed = ['profile', 'starterMethod', 'moveUseRule', 'botSeat2Difficulty'];
    if (array_diff(array_keys($settings), $allowed)
        || (isset($settings['profile']) && $settings['profile'] !== 'standard-backgammon')) {
        throw new MultiplayerGameException('A Backgammon setting is not supported.', 'BACKGAMMON_SETTINGS_INVALID', 422);
    }
    $starterMethod = (string)($settings['starterMethod'] ?? 'roll-for-first');
    $moveUseRule = (string)($settings['moveUseRule'] ?? 'standard');
    if (!in_array($starterMethod, ['roll-for-first', 'rotate-starter'], true)
        || !in_array($moveUseRule, ['standard', 'legacy-ocx'], true)) {
        throw new MultiplayerGameException('Choose valid independent Backgammon starter and move-use rules.', 'BACKGAMMON_SETTINGS_INVALID', 422);
    }
    $validated = ['profile' => 'standard-backgammon', 'starterMethod' => $starterMethod, 'moveUseRule' => $moveUseRule];
    $level = (string)($settings['botSeat2Difficulty'] ?? 'none');
    if (!in_array($level, array_column(backgammon_bot_choices(), 'value'), true)) throw new MultiplayerGameException('Choose a listed Backgammon difficulty.', 'BACKGAMMON_BOT_DIFFICULTY_INVALID', 422);
    if ($level !== 'none' && $mode !== 'practice') throw new MultiplayerGameException('Games with bots are Practice only.', 'MULTIPLAYER_GAME_BOTS_PRACTICE_ONLY', 422);
    if ($mode === 'practice') $validated['botSeat2Difficulty'] = $level;
    return $validated;
}

function backgammon_rules_projection(array $settings, string $mode, array $definition = []): array
{
    $settings = backgammon_validate_settings($settings, $mode, $definition);
    $legacyStarter = $settings['starterMethod'] === 'rotate-starter';
    $legacyMoveUse = $settings['moveUseRule'] === 'legacy-ocx';
    return [
        'label' => 'Backgammon rules',
        'description' => 'Two players move 15 checkers around 24 points in opposite directions. ' . ($legacyStarter ? 'The accepted Legacy OCX setting rotates the starter without an opening contest.' : 'The accepted CoreChat setting rolls one die per player; ties reroll and the higher roller starts using both opening values.') . ' Enter checkers from the bar before any other move. A point with two opposing checkers is blocked; landing on one opposing checker sends it to the bar. ' . ($legacyMoveUse ? 'Legacy OCX move use evaluates each move without maximum-use or higher-die look-ahead.' : 'Standard move use requires the maximum possible number of dice and the higher die when only one different die is usable.'),
        'sections' => [
            ['label' => 'Opening and dice', 'text' => ($legacyStarter ? 'The accepted Legacy OCX starter rotates on New Game without an opening dice contest. ' : ($mode === 'recorded' ? 'Recorded opening and turn dice are server-authoritative. Ties reroll; the higher opening roller starts and uses both opening values. ' : 'Practice uses committed browser randomness through the server. Ties reroll; the higher opening roller starts and uses both opening values. ')) . 'Doubles provide four uses of the rolled number.'],
            ['label' => 'Moving', 'text' => 'A checker on the bar must enter first. A blot may be hit and a point with two or more opposing checkers is blocked. ' . ($legacyMoveUse ? 'The accepted Legacy OCX setting evaluates legal moves one at a time without maximum-use or higher-die look-ahead.' : 'The accepted Standard setting preserves the maximum number of usable dice and requires the higher die when only one different die is usable.') . ' If no legal move exists, play passes truthfully.'],
            ['label' => 'Bearing off', 'text' => 'After all 15 checkers reach the home board, bear off with an exact die. An oversized die may remove the farthest checker only when no checker occupies a higher home point.'],
            ['label' => 'Results', 'text' => 'A normal win is 1 point. A Gammon is 2 when the loser has borne off no checker. A Backgammon is 3 when that loser also has a checker on the bar or in the winner\'s home board. No doubling cube or match-stakes variant is active.'],
            ['label' => 'Mode and recovery', 'text' => $mode === 'recorded' ? 'Recorded completion updates Backgammon-only opponent and lifetime records. Opening dice, starter, board, bar, remaining dice, and round survive save, reconnect, and resume.' : 'Practice completion never changes Recorded records. Opening dice, starter, board, bar, remaining dice, and round survive save, reconnect, and resume.'],
        ],
    ];
}

function backgammon_derive_randomness(string $canonicalReveal, string $actionType, array $payload, array $context): array
{
    return ['dice' => ocx_game_random_dice($canonicalReveal, 2, 'backgammon-roll')];
}

function backgammon_initial_state(array $playerUserIds, array $context = []): array
{
    $players = array_values(array_unique(array_map('intval', $playerUserIds)));
    if (count($players) < 1 || count($players) > 2 || min($players) < 1) {
        throw new MultiplayerGameException('Backgammon requires two authenticated players.', 'BACKGAMMON_PLAYER_SET_INVALID', 422);
    }
    $roundContext = (array)($context['roundContext'] ?? []);
    $settings = backgammon_validate_settings((array)($context['settings'] ?? []), (string)($context['mode'] ?? 'practice'));
    $bots = [];
    if (count($players) === 1 && ($context['mode'] ?? 'practice') === 'practice' && ($settings['botSeat2Difficulty'] ?? 'none') !== 'none') {
        $difficulty = $settings['botSeat2Difficulty']; $players[] = BACKGAMMON_BOT_ID;
        $bots[(string)BACKGAMMON_BOT_ID] = ['userId' => BACKGAMMON_BOT_ID, 'seat' => 2, 'difficulty' => $difficulty,
            'displayName' => backgammon_bot_levels()[$difficulty]['label'] . ' Bot', 'engine' => BACKGAMMON_BOT_ENGINE];
    }
    if (count($players) !== 2) throw new MultiplayerGameException('Another player must join, or choose a Practice bot.', 'MULTIPLAYER_GAME_MINIMUM_PLAYERS', 409);
    $rematchContinues = !empty($roundContext['rematchContinues']);
    $roundNumber = $rematchContinues
        ? max(1, (int)($roundContext['previousRoundNumber'] ?? 1) + (!empty($roundContext['advancesSeries']) ? 1 : 0))
        : 1;
    $points = [];
    $bar = [];
    $borneOff = [];
    foreach ($players as $index => $userId) {
        $row = array_fill(0, 24, 0);
        foreach (($index === 0 ? [0 => 2, 11 => 5, 16 => 3, 18 => 5] : [23 => 2, 12 => 5, 7 => 3, 5 => 5]) as $point => $count) {
            $row[$point] = $count;
        }
        $points[(string)$userId] = $row;
        $bar[(string)$userId] = 0;
        $borneOff[(string)$userId] = 0;
    }
    $legacyStarter = $settings['starterMethod'] === 'rotate-starter';
    $startingIndex = null;
    if ($legacyStarter) {
        // Only an explicitly accepted unanimous rematch owns starter
        // continuity. An unrelated later game with the same two accounts is a
        // fresh series and must not inherit a prior starter.
        $previousStarter = $rematchContinues
            ? (int)($roundContext['previousState']['starterUserId'] ?? 0)
            : 0;
        $previousIndex = array_search($previousStarter, $players, true);
        $startingIndex = $previousIndex === false ? 0 : (1 - (int)$previousIndex);
    }
    return [
        'bots' => $bots,
        'schemaVersion' => BACKGAMMON_STATE_SCHEMA_VERSION,
        'turnOrder' => $players,
        'turnIndex' => $startingIndex,
        'roundNumber' => $roundNumber,
        'starterUserId' => $startingIndex === null ? null : $players[$startingIndex],
        'starterReason' => $startingIndex === null
            ? 'A verified one-die-per-player opening roll will choose the starter; ties reroll and the higher roller uses both values.'
            : 'The accepted Legacy OCX starter rotates on New Game without an opening dice contest.',
        'starterMethod' => $settings['starterMethod'],
        'moveUseRule' => $settings['moveUseRule'],
        'openingCoordinatorUserId' => $startingIndex === null ? $players[0] : null,
        'openingRollAttempts' => [],
        'points' => $points,
        'bar' => $bar,
        'borneOff' => $borneOff,
        'dice' => [],
        'remainingDice' => [],
        'lastBlockedRoll' => null,
        'lastNoLegalMove' => null,
        'backgammonStage' => $startingIndex === null ? 'opening-roll' : 'roll',
        'history' => [],
        'meaningfulPlay' => false,
        'completed' => false,
    ];
}

function backgammon_side(array $state, int $userId): int
{
    $seat = array_search($userId, array_map('intval', (array)$state['turnOrder']), true);
    if ($seat === false) throw new MultiplayerGameException('Only a player may act.', 'BACKGAMMON_PLAYER_INVALID', 403);
    return (int)$seat;
}

function backgammon_opponent(array $state, int $userId): int
{
    foreach ((array)$state['turnOrder'] as $candidate) {
        if ((int)$candidate !== $userId) return (int)$candidate;
    }
    throw new MultiplayerGameException('The opposing player is unavailable.', 'BACKGAMMON_PLAYER_SET_INVALID', 409);
}

function backgammon_destination(array $state, int $userId, string|int $from, int $die): int
{
    $side = backgammon_side($state, $userId);
    if ($from === 'bar') return $side === 0 ? $die - 1 : 24 - $die;
    return $side === 0 ? (int)$from + $die : (int)$from - $die;
}

function backgammon_all_home(array $state, int $userId): bool
{
    if ((int)($state['bar'][(string)$userId] ?? 0) > 0) return false;
    $side = backgammon_side($state, $userId);
    foreach ((array)$state['points'][(string)$userId] as $point => $count) {
        if ((int)$count > 0 && ($side === 0 ? (int)$point < 18 : (int)$point > 5)) return false;
    }
    return true;
}

function backgammon_legal_moves_for_die(array $state, int $userId, int $die): array
{
    if ($die < 1 || $die > 6) return [];
    $opponent = backgammon_opponent($state, $userId);
    $side = backgammon_side($state, $userId);
    $sources = [];
    if ((int)($state['bar'][(string)$userId] ?? 0) > 0) {
        $sources = ['bar'];
    } else {
        foreach ((array)$state['points'][(string)$userId] as $point => $count) {
            if ((int)$count > 0) $sources[] = (int)$point;
        }
    }
    $moves = [];
    foreach ($sources as $from) {
        $to = backgammon_destination($state, $userId, $from, $die);
        if ($to >= 0 && $to < 24) {
            if ((int)$state['points'][(string)$opponent][$to] >= 2) continue;
            $moves[] = ['from' => $from, 'to' => $to, 'die' => $die];
            continue;
        }
        if (!is_int($from) || !backgammon_all_home($state, $userId)) continue;
        $exact = $side === 0 ? 24 - $from : $from + 1;
        if ($die === $exact) {
            $moves[] = ['from' => $from, 'to' => 'borne-off', 'die' => $die];
            continue;
        }
        if ($die < $exact) continue;
        $fartherChecker = false;
        foreach ((array)$state['points'][(string)$userId] as $point => $count) {
            if ((int)$count < 1) continue;
            if ($side === 0 ? (int)$point < $from : (int)$point > $from) $fartherChecker = true;
        }
        if (!$fartherChecker) $moves[] = ['from' => $from, 'to' => 'borne-off', 'die' => $die];
    }
    return $moves;
}

function backgammon_move_available(array $state, int $userId, int $die): bool
{
    return backgammon_legal_moves_for_die($state, $userId, $die) !== [];
}

function backgammon_apply_legal_move_to_state(array $state, int $userId, array $move): array
{
    $from = $move['from'];
    $opponent = backgammon_opponent($state, $userId);
    if ($from === 'bar') $state['bar'][(string)$userId]--;
    else $state['points'][(string)$userId][(int)$from]--;
    if ($move['to'] === 'borne-off') {
        $state['borneOff'][(string)$userId]++;
    } else {
        $to = (int)$move['to'];
        if ((int)$state['points'][(string)$opponent][$to] === 1) {
            $state['points'][(string)$opponent][$to] = 0;
            $state['bar'][(string)$opponent]++;
        }
        $state['points'][(string)$userId][$to]++;
    }
    return $state;
}

function backgammon_maximum_usable_dice(array $state, int $userId, array $remainingDice): int
{
    if ($remainingDice === []) return 0;
    $maximum = 0;
    foreach (array_unique(array_map('intval', $remainingDice)) as $die) {
        foreach (backgammon_legal_moves_for_die($state, $userId, $die) as $move) {
            $next = backgammon_apply_legal_move_to_state($state, $userId, $move);
            $nextDice = array_values(array_map('intval', $remainingDice));
            $index = array_search($die, $nextDice, true);
            if ($index === false) continue;
            array_splice($nextDice, (int)$index, 1);
            $maximum = max($maximum, 1 + backgammon_maximum_usable_dice($next, $userId, $nextDice));
        }
    }
    return $maximum;
}

function backgammon_required_higher_die(array $state, int $userId, array $remainingDice): ?int
{
    $dice = array_values(array_map('intval', $remainingDice));
    if (count($dice) !== 2 || $dice[0] === $dice[1]
        || backgammon_maximum_usable_dice($state, $userId, $dice) !== 1) return null;
    $available = [];
    foreach (array_unique($dice) as $die) {
        if (backgammon_legal_moves_for_die($state, $userId, $die) !== []) $available[] = $die;
    }
    return count($available) === 2 ? max($available) : null;
}

function backgammon_authoritative_moves(array $state, int $userId, array $remainingDice): array
{
    $dice = array_values(array_map('intval', $remainingDice));
    if ((string)($state['moveUseRule'] ?? 'standard') === 'legacy-ocx') {
        $moves = [];
        foreach (array_unique($dice) as $die) {
            foreach (backgammon_legal_moves_for_die($state, $userId, $die) as $move) $moves[] = $move;
        }
        return $moves;
    }
    $maximum = backgammon_maximum_usable_dice($state, $userId, $dice);
    if ($maximum < 1) return [];
    $requiredHigher = backgammon_required_higher_die($state, $userId, $dice);
    $moves = [];
    foreach (array_unique($dice) as $die) {
        if ($requiredHigher !== null && $die !== $requiredHigher) continue;
        foreach (backgammon_legal_moves_for_die($state, $userId, $die) as $move) {
            $next = backgammon_apply_legal_move_to_state($state, $userId, $move);
            $nextDice = $dice;
            $index = array_search($die, $nextDice, true);
            if ($index === false) continue;
            array_splice($nextDice, (int)$index, 1);
            if (1 + backgammon_maximum_usable_dice($next, $userId, $nextDice) !== $maximum) continue;
            $moves[] = $move;
        }
    }
    return $moves;
}

function backgammon_project_state(array $state, int $viewerUserId, array $context): array
{
    $projection = $state;
    $interaction = [
        'authority' => 'server-legal-move-projection',
        'selectableOrigins' => [],
        'legalDestinationsByOrigin' => [],
        'legalMovesByOrigin' => [],
        'legalMoveCount' => 0,
    ];
    $current = (int)($state['turnOrder'][(int)($state['turnIndex'] ?? -1)] ?? 0);
    $canAct = in_array((string)($context['viewerRole'] ?? ''), ['master', 'player'], true)
        && (string)($context['status'] ?? '') === 'active'
        && empty($state['completed'])
        && $viewerUserId > 0
        && $viewerUserId === $current
        && (array)($state['remainingDice'] ?? []) !== [];
    if ($canAct) {
        foreach (backgammon_authoritative_moves($state, $viewerUserId, (array)$state['remainingDice']) as $move) {
            $origin = $move['from'] === 'bar' ? 'bar' : 'point:' . (int)$move['from'];
            $destination = $move['to'] === 'borne-off' ? 'borne-off' : 'point:' . (int)$move['to'];
            $interaction['legalDestinationsByOrigin'][$origin] ??= [];
            if (!in_array($destination, $interaction['legalDestinationsByOrigin'][$origin], true)) {
                $interaction['legalDestinationsByOrigin'][$origin][] = $destination;
            }
            $interaction['legalMovesByOrigin'][$origin] ??= [];
            $interaction['legalMovesByOrigin'][$origin][] = [
                'destination' => $destination,
                'die' => (int)$move['die'],
            ];
            $interaction['legalMoveCount']++;
        }
        $interaction['selectableOrigins'] = array_keys($interaction['legalDestinationsByOrigin']);
        sort($interaction['selectableOrigins'], SORT_NATURAL);
        foreach ($interaction['legalDestinationsByOrigin'] as &$destinations) sort($destinations, SORT_NATURAL);
        unset($destinations);
    }
    $projection['interaction'] = $interaction;
    $projection['botTask'] = backgammon_bot_task($state, $viewerUserId, $context);
    return $projection;
}

function backgammon_finish_turn(array &$state): array
{
    $state['dice'] = [];
    $state['remainingDice'] = [];
    $state['backgammonStage'] = 'roll';
    $nextTurnUserId = ocx_game_advance_turn($state);
    return ['state' => $state, 'turnUserId' => $nextTurnUserId];
}

function backgammon_terminal(array &$state, int $winner, string $cause = 'bear-off'): array
{
    $loser = backgammon_opponent($state, $winner);
    $classification = (int)$state['borneOff'][(string)$loser] > 0 ? 'single' : 'gammon';
    if ((int)$state['borneOff'][(string)$loser] === 0) {
        $winnerSide = backgammon_side($state, $winner);
        $loserInWinnerHome = false;
        foreach ((array)$state['points'][(string)$loser] as $point => $count) {
            if ((int)$count > 0 && ($winnerSide === 0 ? (int)$point >= 18 : (int)$point <= 5)) $loserInWinnerHome = true;
        }
        if ((int)$state['bar'][(string)$loser] > 0 || $loserInWinnerHome) $classification = 'backgammon';
    }
    $points = ['single' => 1, 'gammon' => 2, 'backgammon' => 3][$classification];
    $state['completed'] = true;
    $state['winnerUserId'] = $winner;
    $state['terminalClassification'] = $classification;
    $state['terminalReason'] = $classification;
    // The score classification is intentionally separate from the action that
    // ended the game. Classic winning motion belongs only to a real completed
    // bear-off, never resignation or another administrative terminal path.
    $state['terminalCause'] = $cause;
    return [
        'state' => $state,
        'turnUserId' => null,
        'terminal' => true,
        'result' => ocx_game_result_from_scores([(string)$winner => $points, (string)$loser => 0]),
    ];
}

function backgammon_apply_action_core(array $state, int $actorUserId, string $action, array $payload, array $context): array
{
    if ((int)($state['schemaVersion'] ?? 0) !== BACKGAMMON_STATE_SCHEMA_VERSION || !empty($state['completed'])) {
        throw new MultiplayerGameException('The Backgammon state is unavailable.', 'BACKGAMMON_STATE_INVALID', 409);
    }
    if ($action === 'resign') {
        $players = array_values(array_map('intval', (array)($state['turnOrder'] ?? [])));
        if (count($players) !== 2 || count(array_unique($players)) !== 2 || count(array_filter($players, static fn(int $id): bool => $id > 0 || ($id === BACKGAMMON_BOT_ID && isset($state['bots'][(string)$id])))) !== 2) {
            throw new MultiplayerGameException('Backgammon requires two authenticated players.', 'BACKGAMMON_PLAYER_SET_INVALID', 422);
        }
        if ($actorUserId < 1 || !in_array($actorUserId, $players, true)) {
            throw new MultiplayerGameException('Only a seated player can resign.', 'MULTIPLAYER_GAME_ACCESS_DENIED', 403);
        }
        return backgammon_terminal($state, backgammon_opponent($state, $actorUserId), 'resignation');
    }
    if ((string)($state['backgammonStage'] ?? '') === 'opening-roll') {
        if ($action !== 'roll') throw new MultiplayerGameException('Complete the opening roll before moving.', 'BACKGAMMON_OPENING_ROLL_REQUIRED', 409);
        if ($actorUserId !== (int)($state['openingCoordinatorUserId'] ?? 0)) throw new MultiplayerGameException('The opening-roll coordinator must request the verified roll.', 'BACKGAMMON_OPENING_ROLL_ACTOR_INVALID', 403);
        $dice = array_values(array_map('intval', (array)($context['authoritativeRandomness']['dice'] ?? [])));
        if (count($dice) !== 2 || min($dice) < 1 || max($dice) > 6) throw new MultiplayerGameException('Verified opening dice are required.', 'BACKGAMMON_RANDOMNESS_INVALID', 409);
        $attempt = [
            'attempt' => count((array)$state['openingRollAttempts']) + 1,
            'rolls' => [(string)(int)$state['turnOrder'][0] => $dice[0], (string)(int)$state['turnOrder'][1] => $dice[1]],
            'tie' => $dice[0] === $dice[1],
        ];
        $state['openingRollAttempts'][] = $attempt;
        if ($attempt['tie']) {
            $state['starterReason'] = 'The verified opening roll was tied, so both players roll again.';
            return ['state' => $state, 'turnUserId' => null];
        }
        $startingIndex = $dice[0] > $dice[1] ? 0 : 1;
        $starter = (int)$state['turnOrder'][$startingIndex];
        $state['turnIndex'] = $startingIndex;
        $state['starterUserId'] = $starter;
        $state['starterReason'] = 'The higher verified opening roller starts and uses both opening values; tied openings are rerolled.';
        $state['dice'] = $dice;
        $state['remainingDice'] = $dice;
        $state['backgammonStage'] = 'moving';
        return ['state' => $state, 'turnUserId' => $starter];
    }
    ocx_game_assert_turn($state, $actorUserId);
    if ($action === 'roll') {
        if ((string)$state['backgammonStage'] !== 'roll' || (array)$state['remainingDice'] !== []) {
            throw new MultiplayerGameException('Finish the available dice before rolling again.', 'BACKGAMMON_ROLL_INVALID', 409);
        }
        $dice = array_values(array_map('intval', (array)($context['authoritativeRandomness']['dice'] ?? [])));
        if (count($dice) !== 2 || min($dice) < 1 || max($dice) > 6) throw new MultiplayerGameException('Verified dice are required.', 'BACKGAMMON_RANDOMNESS_INVALID', 409);
        $state['dice'] = $dice;
        $state['remainingDice'] = $dice[0] === $dice[1] ? array_fill(0, 4, $dice[0]) : $dice;
        $state['lastBlockedRoll'] = null;
        $state['lastNoLegalMove'] = null;
        $state['backgammonStage'] = 'moving';
        $hasMove = false;
        foreach (array_unique($state['remainingDice']) as $die) if (backgammon_move_available($state, $actorUserId, (int)$die)) $hasMove = true;
        if (!$hasMove) {
            $state['lastNoLegalMove'] = [
                'actorUserId' => $actorUserId,
                'dice' => $dice,
                'reason' => 'no-legal-move',
                'kind' => 'whole-turn',
                'ticks' => 30,
                'tickMs' => 80,
                'durationMs' => 2400,
                'boardStateUnchanged' => true,
                'sequence' => count((array)$state['history']),
            ];
            // Retained only for active/saved state compatibility. Presentation
            // and verification own the generic lastNoLegalMove event.
            $state['lastBlockedRoll'] = $state['lastNoLegalMove'];
            return backgammon_finish_turn($state);
        }
        return ['state' => $state, 'turnUserId' => $actorUserId];
    }
    if ($action !== 'move') throw new MultiplayerGameException('This Backgammon action is not supported.', 'BACKGAMMON_ACTION_INVALID', 422);
    if ((array)$state['remainingDice'] === []) throw new MultiplayerGameException('Roll before moving.', 'BACKGAMMON_MOVE_INVALID', 409);
    $fromRaw = $payload['from'] ?? null;
    $from = is_string($fromRaw) && strtolower(trim($fromRaw)) === 'bar' ? 'bar' : (int)$fromRaw;
    if ($from !== 'bar' && (!is_int($from) || $from < 0 || $from > 23)) throw new MultiplayerGameException('Choose a valid checker source.', 'BACKGAMMON_MOVE_INVALID', 422);
    $die = (int)($payload['die'] ?? 0);
    $dieIndex = array_search($die, array_map('intval', (array)$state['remainingDice']), true);
    if ($dieIndex === false) throw new MultiplayerGameException('That die is not available.', 'BACKGAMMON_DIE_UNAVAILABLE', 422);
    $rawSelected = current(array_filter(
        backgammon_legal_moves_for_die($state, $actorUserId, $die),
        static fn(array $move): bool => $move['from'] === $from
    ));
    $legacyMoveUse = (string)($state['moveUseRule'] ?? 'standard') === 'legacy-ocx';
    $requiredHigher = $legacyMoveUse ? null : backgammon_required_higher_die($state, $actorUserId, (array)$state['remainingDice']);
    if ($requiredHigher !== null && $die !== $requiredHigher && is_array($rawSelected)) {
        throw new MultiplayerGameException('When only one die can be played, use the higher legal die.', 'BACKGAMMON_HIGHER_DIE_REQUIRED', 422);
    }
    $selected = current(array_filter(
        backgammon_authoritative_moves($state, $actorUserId, (array)$state['remainingDice']),
        static fn(array $move): bool => $move['from'] === $from && (int)$move['die'] === $die
    ));
    if (!is_array($selected) && is_array($rawSelected)) {
        throw new MultiplayerGameException('Choose a move that preserves the maximum possible number of usable dice for this turn.', 'BACKGAMMON_MAXIMUM_DICE_REQUIRED', 422);
    }
    if (!is_array($selected)) throw new MultiplayerGameException('That move is not legal.', 'BACKGAMMON_MOVE_ILLEGAL', 422);
    $state = backgammon_apply_legal_move_to_state($state, $actorUserId, $selected);
    array_splice($state['remainingDice'], (int)$dieIndex, 1);
    $state['meaningfulPlay'] = true;
    $state['history'][] = ['actorUserId' => $actorUserId, 'from' => $from, 'to' => $selected['to'], 'die' => $die];
    if ((int)$state['borneOff'][(string)$actorUserId] === 15) return backgammon_terminal($state, $actorUserId);
    $hasMove = false;
    foreach (array_unique(array_map('intval', (array)$state['remainingDice'])) as $candidate) {
        if (backgammon_move_available($state, $actorUserId, $candidate)) $hasMove = true;
    }
    if ($state['remainingDice'] === []) return backgammon_finish_turn($state);
    if (!$hasMove) {
        $state['lastNoLegalMove'] = [
            'actorUserId' => $actorUserId,
            'dice' => array_values(array_map('intval', (array)$state['dice'])),
            'remainingDice' => array_values(array_map('intval', (array)$state['remainingDice'])),
            'reason' => 'remaining-dice-unusable',
            'kind' => 'partial-turn',
            'ticks' => 7,
            'tickMs' => 80,
            'durationMs' => 560,
            'boardStateUnchanged' => false,
            'sequence' => count((array)$state['history']),
        ];
        $state['lastBlockedRoll'] = null;
        return backgammon_finish_turn($state);
    }
    return ['state' => $state, 'turnUserId' => $actorUserId];
}
