<?php
declare(strict_types=1);

require_once __DIR__ . '/ocx_game_extension_support.php';
require_once __DIR__ . '/acey_deucy_bot_support.php';

const ACEY_DEUCY_EXTENSION_ID = 'acey-deucy';
const ACEY_DEUCY_STATE_SCHEMA_VERSION = 1;

function acey_deucy_recording_adapter(): array
{
    return ['schemaVersion' => 1,
        'stateKeys' => ['bots', 'starterMethod', 'usedRandomnessRequestIds', 'aceyStage', 'bar', 'borneOff', 'completed', 'dice', 'europeanSequence', 'history', 'lastNoLegalMove', 'meaningfulPlay', 'off', 'openingCoordinatorUserId', 'openingRollAttempts', 'points', 'remainingDice', 'resignedUserId', 'roundNumber', 'rulesProfile', 'schemaVersion', 'settings', 'starterIndex', 'starterReason', 'starterUserId', 'startingIndex', 'terminalClassification', 'terminalReason', 'turnIndex', 'turnOrder', 'winnerUserId'],
        'payloadKeys' => ['die', 'from', 'value']];
}

function acey_deucy_extension_adapter(): array
{
    return [
        'recordingAdapter' => 'acey_deucy_recording_adapter',
        'id' => ACEY_DEUCY_EXTENSION_ID,
        'initialState' => 'acey_deucy_initial_state',
        'applyAction' => 'acey_deucy_apply_action',
        'validateSettings' => 'acey_deucy_validate_settings',
        'settingsProjection' => 'acey_deucy_settings_projection',
        'rulesProjection' => 'acey_deucy_rules_projection',
        'projectState' => 'acey_deucy_project_state',
        'projectVirtualMembers' => 'acey_deucy_project_virtual_members',
        'randomnessPurposes' => ['roll' => 'acey-deucy-roll', 'bot-roll' => 'acey-deucy-roll'],
        'deriveRandomness' => 'acey_deucy_derive_randomness',
        'presentationStatus' => 'acey_deucy_presentation_status',
        'openingProcedure' => 'settings-owned-high-roll-or-legacy-rotating-starter',
        'rematchSeatRotation' => false,
    ];
}

function acey_deucy_settings_projection(array $settings, string $mode, array $definition = []): array
{
    $settings = acey_deucy_validate_settings($settings, $mode, $definition);
    $european = $settings['rulesProfile'] === 'european-double-double';
    return [
        'label' => 'Game Options',
        'description' => 'Every player must accept the shared rules profile and starter method before play begins.',
        'classification' => $settings['rulesProfile'] . ':' . $settings['starterMethod'],
        'classificationLabel' => ($european ? 'European Double-Double' : 'Current Acey Deucy')
            . ' · ' . ($settings['starterMethod'] === 'rotate-starter' ? 'Legacy OCX rotating starter' : 'CoreChat roll for first'),
        'controls' => [[
            'key' => 'rulesProfile', 'type' => 'button-choice', 'value' => $settings['rulesProfile'],
            'defaultValue' => 'current', 'label' => 'Rules profile',
            'description' => 'Current preserves the established CoreChat game. European Double-Double adds complementary doubles, exact bearing off, and authentic single-point scoring.',
            'options' => [
                ['value' => 'current', 'label' => 'Current Acey Deucy — Default'],
                ['value' => 'european-double-double', 'label' => 'European Double-Double — Authentic'],
            ],
        ], [
            'key' => 'starterMethod', 'type' => 'select', 'value' => $settings['starterMethod'],
            'defaultValue' => 'rotate-starter', 'label' => 'Starter method',
            'description' => 'Choose how the first player is established for this game series.',
            'options' => [
                ['value' => 'roll-for-first', 'label' => 'Roll for first — CoreChat'],
                ['value' => 'rotate-starter', 'label' => 'Rotate starter — Default / Legacy OCX'],
            ],
        ]],
    ];
}

function acey_deucy_presentation_status(PDO $pdo, ?string $requestedPack = null): array
{
    return ocx_game_presentation_status($pdo, ACEY_DEUCY_EXTENSION_ID, $requestedPack);
}

function acey_deucy_validate_settings(array $settings, string $mode, array $definition = []): array
{
    $difficulty = $settings['botSeat2Difficulty'] ?? 'none';
    if (!in_array($difficulty, array_column(acey_deucy_bot_choices(), 'value'), true)) throw new MultiplayerGameException('Choose a listed bot level.', 'ACEY_DEUCY_SETTINGS_INVALID', 422);
    if ($difficulty !== 'none' && $mode !== 'practice') throw new MultiplayerGameException('Games with bots are Practice only.', 'MULTIPLAYER_GAME_BOTS_PRACTICE_ONLY', 422);
    unset($settings['botSeat2Difficulty']);
    $allowed = ['profile', 'rulesProfile', 'starterMethod'];
    if (array_diff(array_keys($settings), $allowed)
        || (isset($settings['profile']) && $settings['profile'] !== 'standard-acey-deucy')) {
        throw new MultiplayerGameException('An Acey Deucy setting is not supported.', 'ACEY_DEUCY_SETTINGS_INVALID', 422);
    }
    $rulesProfile = (string)($settings['rulesProfile'] ?? 'current');
    if (!in_array($rulesProfile, ['current', 'european-double-double'], true)) {
        throw new MultiplayerGameException('Choose a valid Acey Deucy rules profile.', 'ACEY_DEUCY_SETTINGS_INVALID', 422);
    }
    $starterMethod = (string)($settings['starterMethod'] ?? 'rotate-starter');
    if (!in_array($starterMethod, ['roll-for-first', 'rotate-starter'], true)) {
        throw new MultiplayerGameException('Choose a valid Acey Deucy starter method.', 'ACEY_DEUCY_SETTINGS_INVALID', 422);
    }
    return [
        ...($mode === 'practice' ? ['botSeat2Difficulty' => $difficulty] : []),
        'profile' => 'standard-acey-deucy',
        'rulesProfile' => $rulesProfile,
        'starterMethod' => $starterMethod,
    ];
}

function acey_deucy_rules_projection(array $settings, string $mode, array $definition = []): array
{
    $settings = acey_deucy_validate_settings($settings, $mode, $definition);
    $legacyStarter = $settings['starterMethod'] === 'rotate-starter';
    $european = $settings['rulesProfile'] === 'european-double-double';
    $diceRules = $european
        ? 'Ordinary doubles are played four times, followed by four moves of the complementary value (opposite die faces total seven). A completed eight-move sequence grants another roll. A 1–2 must first be played normally, then the player chooses any double and plays its four moves plus four complementary moves. If any required part becomes unusable, the remainder and extra roll are lost.'
        : 'Doubles are played four times and grant one additional roll after every legally usable move in that doubles sequence is complete. A 1–2 opening cannot advance to the chosen double while either 1 or 2 remains legally usable.';
    $finishingRules = $european
        ? 'Once all pieces are home, they may no longer move forward and may bear off only with the exact required number. Empty exact points lose that die. The first player to bear off all 15 wins one point; there are no Gammon or Backgammon multipliers and no doubling cube.'
        : 'After every piece reaches the home board, pieces may bear off. The first player to bear off all 15 wins; Gammon and Backgammon classifications are retained.';
    return [
        'label' => 'Acey Deucy rules',
        'description' => ($european ? 'European Double-Double is active. ' : 'Current Acey Deucy is active. ')
            . ($legacyStarter ? 'The accepted Legacy OCX setting rotates the starter without an opening contest. ' : 'A verified opening roll gives one die to each player; ties reroll and the higher roll starts. ')
            . 'Both players begin with all 15 pieces off the board. Enter and move pieces using each die; pieces on the bar must reenter first. A single opposing piece is hit and sent to the bar, while a point with two or more opposing pieces is blocked. A player may move pieces already on the board while other pieces remain off. The complete turn must use the maximum possible number of dice; if only one of two different dice can be played, the higher legal die is required.',
        'sections' => [
            ['label' => 'Rules profile', 'text' => $european ? 'European Double-Double — Authentic is the accepted shared profile for this game.' : 'Current Acey Deucy — Default is the accepted shared profile for this game.'],
            ['label' => 'Opening', 'text' => $legacyStarter ? 'The accepted Legacy OCX starter rotates on New Game without an opening dice contest. The starter and round identity survive reconnect and resume.' : ($mode === 'recorded' ? 'The server fairly rolls one die for each player. A tie is rerolled, and the higher roll starts. The recorded opening roll, starter, and round identity survive reconnect and resume.' : 'Practice uses the accepted committed browser-randomness receipt for the same one-die-each opening procedure. A tie is rerolled, and the higher roll starts.')],
            ['label' => 'Dice', 'text' => $diceRules . ' Across an ordinary two-die roll, use the maximum possible number of dice; if only one die can be played, use the larger legal die.'],
            ['label' => 'Finishing', 'text' => $finishingRules],
            ['label' => 'Mode', 'text' => $mode === 'recorded' ? 'Recorded rolls are server-authoritative and completed games update Acey Deucy-only records.' : 'Practice rolls use committed browser randomness and never update Recorded records.'],
        ],
    ];
}

function acey_deucy_derive_randomness(string $canonicalReveal, string $actionType, array $payload, array $context): array
{
    return ['dice' => ocx_game_random_dice($canonicalReveal, 2, 'acey-deucy-roll')];
}

function acey_deucy_initial_state(array $playerUserIds, array $context = []): array
{
    $players = array_values(array_unique(array_map('intval', $playerUserIds)));
    $roundContext = (array)($context['roundContext'] ?? []);
    $settings = acey_deucy_validate_settings((array)($context['settings'] ?? []), (string)($context['mode'] ?? 'practice'));
    $bots = [];
    if (count($players) === 1 && $players[0] > 0 && ($settings['botSeat2Difficulty'] ?? 'none') !== 'none') {
        $players[] = ACEY_DEUCY_BOT_ID; $level = $settings['botSeat2Difficulty'];
        $bots[(string)ACEY_DEUCY_BOT_ID] = ['userId'=>ACEY_DEUCY_BOT_ID, 'seat'=>2, 'difficulty'=>$level, 'displayName'=>ucfirst($level).' Bot', 'engine'=>ACEY_DEUCY_BOT_ENGINE];
    }
    if (count($players)!==2 || ($bots===[] && min($players)<1)) throw new MultiplayerGameException('Acey Deucy requires two players.', 'ACEY_DEUCY_PLAYER_SET_INVALID', 422);

    $rematchContinues = !empty($roundContext['rematchContinues']);
    $roundNumber = $rematchContinues
        ? max(1, (int)($roundContext['previousRoundNumber'] ?? 1) + (!empty($roundContext['advancesSeries']) ? 1 : 0))
        : 1;
    $points = [];
    $off = []; $bar = []; $borne = [];
    foreach ($players as $userId) {
        $points[(string)$userId] = array_fill(0, 24, 0);
        $off[(string)$userId] = 15; $bar[(string)$userId] = 0; $borne[(string)$userId] = 0;
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
        'bots' => $bots, 'settings' => $settings,
        'schemaVersion' => ACEY_DEUCY_STATE_SCHEMA_VERSION,
        'turnOrder' => $players,
        'turnIndex' => $startingIndex,
        'roundNumber' => $roundNumber,
        'startingIndex' => $startingIndex,
        'starterUserId' => $startingIndex === null ? null : $players[$startingIndex],
        'starterReason' => $startingIndex === null
            ? 'A verified one-die-per-player opening roll will choose the starter; ties reroll.'
            : 'The accepted Legacy OCX starter rotates on New Game without an opening dice contest.',
        'starterMethod' => $settings['starterMethod'],
        'rulesProfile' => $settings['rulesProfile'],
        'openingCoordinatorUserId' => $startingIndex === null ? $players[0] : null,
        'openingRollAttempts' => [],
        'points' => $points,
        'off' => $off,
        'bar' => $bar,
        'borneOff' => $borne,
        'dice' => [],
        'remainingDice' => [],
        'aceyStage' => $startingIndex === null ? 'opening-roll' : 'roll',
        'usedRandomnessRequestIds' => [],
        'history' => [],
        'lastNoLegalMove' => null,
        'europeanSequence' => null,
        'meaningfulPlay' => false,
        'completed' => false,
    ];
}

function acey_deucy_side(array $state, int $userId): int
{
    $seat = array_search($userId, array_map('intval', $state['turnOrder']), true);
    if ($seat === false) throw new MultiplayerGameException('Only a player may act.', 'ACEY_DEUCY_PLAYER_INVALID', 403);
    return (int)$seat;
}

function acey_deucy_opponent(array $state, int $userId): int
{
    foreach ($state['turnOrder'] as $candidate) if ((int)$candidate !== $userId) return (int)$candidate;
    throw new MultiplayerGameException('The opposing player is unavailable.', 'ACEY_DEUCY_PLAYER_SET_INVALID', 409);
}

function acey_deucy_rules_profile(array $state): string
{
    return (string)($state['rulesProfile'] ?? 'current') === 'european-double-double'
        ? 'european-double-double'
        : 'current';
}

function acey_deucy_uses_european_rules(array $state): bool
{
    return acey_deucy_rules_profile($state) === 'european-double-double';
}

function acey_deucy_destination(array $state, int $userId, string|int $from, int $die): string|int
{
    $side = acey_deucy_side($state, $userId);
    if ($from === 'bar' || $from === 'off') return $side === 0 ? $die - 1 : 24 - $die;
    $point = (int)$from;
    return $side === 0 ? $point + $die : $point - $die;
}

function acey_deucy_all_home(array $state, int $userId): bool
{
    if ((int)$state['off'][(string)$userId] > 0 || (int)$state['bar'][(string)$userId] > 0) return false;
    $side = acey_deucy_side($state, $userId);
    foreach ($state['points'][(string)$userId] as $point => $count) if ((int)$count > 0 && ($side === 0 ? $point < 18 : $point > 5)) return false;
    return true;
}

function acey_deucy_move_available(array $state, int $userId, int $die): bool
{
    foreach (acey_deucy_legal_moves_for_die($state, $userId, $die) as $_move) return true;
    return false;
}

function acey_deucy_legal_moves_for_die(array $state, int $userId, int $die): array
{
    if ($die < 1 || $die > 6) return [];
    $opponentId = acey_deucy_opponent($state, $userId);
    $side = acey_deucy_side($state, $userId);
    $europeanExactBearing = acey_deucy_uses_european_rules($state)
        && acey_deucy_all_home($state, $userId);
    $sources = [];
    if ((int)$state['bar'][(string)$userId] > 0) $sources = ['bar'];
    else {
        if ((int)$state['off'][(string)$userId] > 0) $sources[] = 'off';
        foreach ($state['points'][(string)$userId] as $point => $count) if ((int)$count > 0) $sources[] = (int)$point;
    }
    $moves = [];
    foreach ($sources as $from) {
        if ($europeanExactBearing) {
            if (!is_int($from)) continue;
            $exact = $side === 0 ? 24 - $from : $from + 1;
            if ($die === $exact) $moves[] = ['from' => $from, 'to' => 'borne-off', 'die' => $die];
            continue;
        }
        $to = acey_deucy_destination($state, $userId, $from, $die);
        if (is_int($to) && $to >= 0 && $to < 24) {
            if ((int)$state['points'][(string)$opponentId][$to] >= 2) continue;
            $moves[] = ['from' => $from, 'to' => $to, 'die' => $die];
            continue;
        }
        if (!is_int($from) || !acey_deucy_all_home($state, $userId)) continue;
        $exact = $side === 0 ? 24 - $from : $from + 1;
        if ($die === $exact) { $moves[] = ['from' => $from, 'to' => 'borne-off', 'die' => $die]; continue; }
        if ($die < $exact) continue;
        $behind = false;
        foreach ($state['points'][(string)$userId] as $point => $count) {
            if ((int)$count < 1) continue;
            if ($side === 0 ? $point < $from : $point > $from) $behind = true;
        }
        if (!$behind) $moves[] = ['from' => $from, 'to' => 'borne-off', 'die' => $die];
    }
    return $moves;
}

function acey_deucy_apply_legal_move_to_state(array $state, int $userId, array $move): array
{
    $from = $move['from'];
    $opponentId = acey_deucy_opponent($state, $userId);
    if ($from === 'off') $state['off'][(string)$userId]--;
    elseif ($from === 'bar') $state['bar'][(string)$userId]--;
    else $state['points'][(string)$userId][(int)$from]--;
    if ($move['to'] === 'borne-off') $state['borneOff'][(string)$userId]++;
    else {
        $to = (int)$move['to'];
        if ((int)$state['points'][(string)$opponentId][$to] === 1) {
            $state['points'][(string)$opponentId][$to] = 0;
            $state['bar'][(string)$opponentId]++;
        }
        $state['points'][(string)$userId][$to]++;
    }
    return $state;
}

function acey_deucy_maximum_usable_dice(array $state, int $userId, array $remainingDice): int
{
    if ($remainingDice === []) return 0;
    $maximum = 0;
    foreach (array_unique(array_map('intval', $remainingDice)) as $die) {
        foreach (acey_deucy_legal_moves_for_die($state, $userId, $die) as $move) {
            $next = acey_deucy_apply_legal_move_to_state($state, $userId, $move);
            $nextDice = array_values(array_map('intval', $remainingDice));
            $index = array_search($die, $nextDice, true);
            if ($index === false) continue;
            array_splice($nextDice, (int)$index, 1);
            $maximum = max($maximum, 1 + acey_deucy_maximum_usable_dice($next, $userId, $nextDice));
            if ($maximum === count($remainingDice)) return $maximum;
        }
    }
    return $maximum;
}

function acey_deucy_required_larger_die(array $state, int $userId, array $remainingDice, int $maximumUsable): ?int
{
    $dice = array_values(array_map('intval', $remainingDice));
    if ($maximumUsable !== 1 || count($dice) !== 2 || $dice[0] === $dice[1]) return null;
    $higher = max($dice);
    return acey_deucy_move_available($state, $userId, $higher) ? $higher : null;
}

function acey_deucy_project_state(array $state, int $viewerUserId, array $context): array
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
        $remainingDice = array_values(array_map('intval', (array)$state['remainingDice']));
        $maximumBefore = acey_deucy_maximum_usable_dice($state, $viewerUserId, $remainingDice);
        $requiredDie = acey_deucy_required_larger_die($state, $viewerUserId, $remainingDice, $maximumBefore);
        foreach (array_unique($remainingDice) as $die) {
            if ($requiredDie !== null && $die !== $requiredDie) continue;
            $nextDice = $remainingDice;
            $dieIndex = array_search($die, $nextDice, true);
            array_splice($nextDice, (int)$dieIndex, 1);
            foreach (acey_deucy_legal_moves_for_die($state, $viewerUserId, $die) as $move) {
                $preview = acey_deucy_apply_legal_move_to_state($state, $viewerUserId, $move);
                if (1 + acey_deucy_maximum_usable_dice($preview, $viewerUserId, $nextDice) < $maximumBefore) {
                    continue;
                }
                $origin = is_string($move['from']) ? (string)$move['from'] : 'point:' . (int)$move['from'];
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
        }
        $interaction['selectableOrigins'] = array_keys($interaction['legalDestinationsByOrigin']);
        sort($interaction['selectableOrigins'], SORT_NATURAL);
        foreach ($interaction['legalDestinationsByOrigin'] as &$destinations) sort($destinations, SORT_NATURAL);
        unset($destinations);
    }
    $projection['interaction'] = $interaction;
    $projection['botTask'] = acey_deucy_bot_task($state, $viewerUserId, $context);
    return $projection;
}

function acey_deucy_has_legal_double(array $state, int $userId): bool
{
    foreach (range(1, 6) as $value) if (acey_deucy_move_available($state, $userId, $value)) return true;
    return false;
}

function acey_deucy_record_no_legal_move(
    array &$state,
    int $actorUserId,
    string $kind,
    string $stage,
    array $dice,
    array $remainingDice,
    string $turnResolution,
    bool $boardStateUnchanged
): void {
    $previousSequence = (int)($state['lastNoLegalMove']['sequence'] ?? 0);
    $state['lastNoLegalMove'] = [
        'sequence' => $previousSequence + 1,
        'actorUserId' => $actorUserId,
        'kind' => $kind,
        'stage' => $stage,
        'dice' => array_values(array_map('intval', $dice)),
        'remainingDice' => array_values(array_map('intval', $remainingDice)),
        'presentation' => 'source-immediate',
        'ticks' => 0,
        'tickMs' => 0,
        'durationMs' => 0,
        'turnResolution' => $turnResolution,
        'boardStateUnchanged' => $boardStateUnchanged,
    ];
}

function acey_deucy_european_sequence_stage(string $stage): bool
{
    return in_array($stage, [
        'european-double-primary',
        'european-double-complement',
        'european-acey-primary',
        'european-acey-complement',
    ], true);
}

function acey_deucy_european_fail_sequence(
    array &$state,
    int $actorUserId,
    string $kind,
    string $stage,
    array $remainingDice,
    bool $boardStateUnchanged
): array {
    acey_deucy_record_no_legal_move(
        $state,
        $actorUserId,
        $kind,
        $stage,
        (array)($state['dice'] ?? []),
        $remainingDice,
        'pass-turn-no-extra-roll',
        $boardStateUnchanged
    );
    $state['remainingDice'] = [];
    $state['europeanSequence'] = null;
    $state['aceyStage'] = 'roll';
    $nextTurnUserId = ocx_game_advance_turn($state);
    return ['state' => $state, 'turnUserId' => $nextTurnUserId];
}

function acey_deucy_european_begin_primary(
    array &$state,
    int $actorUserId,
    int $primary,
    string $origin,
    bool $boardStateUnchanged
): array {
    $state['dice'] = [$primary, $primary];
    $state['remainingDice'] = array_fill(0, 4, $primary);
    $state['aceyStage'] = $origin === 'acey-deucey'
        ? 'european-acey-primary'
        : 'european-double-primary';
    $state['europeanSequence'] = [
        'origin' => $origin,
        'primary' => $primary,
        'complement' => 7 - $primary,
        'phase' => 'primary',
    ];
    if (!acey_deucy_move_available($state, $actorUserId, $primary)) {
        return acey_deucy_european_fail_sequence(
            $state,
            $actorUserId,
            'whole-sequence',
            (string)$state['aceyStage'],
            $state['remainingDice'],
            $boardStateUnchanged
        );
    }
    return ['state' => $state, 'turnUserId' => $actorUserId];
}

function acey_deucy_european_begin_complement(array &$state, int $actorUserId): array
{
    $sequence = (array)($state['europeanSequence'] ?? []);
    $origin = (string)($sequence['origin'] ?? 'rolled-double');
    $complement = (int)($sequence['complement'] ?? 0);
    if ($complement < 1 || $complement > 6) {
        throw new MultiplayerGameException('The European double sequence is unavailable.', 'ACEY_DEUCY_STATE_INVALID', 409);
    }
    $state['dice'] = [$complement, $complement];
    $state['remainingDice'] = array_fill(0, 4, $complement);
    $state['aceyStage'] = $origin === 'acey-deucey'
        ? 'european-acey-complement'
        : 'european-double-complement';
    $state['europeanSequence']['phase'] = 'complement';
    if (!acey_deucy_move_available($state, $actorUserId, $complement)) {
        return acey_deucy_european_fail_sequence(
            $state,
            $actorUserId,
            'complement-unavailable',
            (string)$state['aceyStage'],
            $state['remainingDice'],
            false
        );
    }
    return ['state' => $state, 'turnUserId' => $actorUserId];
}

function acey_deucy_finish_acey_initial_without_legal_double(array &$state, int $actorUserId): array
{
    if (acey_deucy_has_legal_double($state, $actorUserId)) {
        $state['aceyStage'] = 'choose-double';
        return ['state' => $state, 'turnUserId' => $actorUserId];
    }
    $state['aceyStage'] = 'roll';
    $nextTurnUserId = ocx_game_advance_turn($state);
    return ['state' => $state, 'turnUserId' => $nextTurnUserId];
}

function acey_deucy_finish_turn(array &$state, int $actorUserId): array
{
    if ((string)$state['aceyStage'] === 'choose-double') return ['state' => $state, 'turnUserId' => $actorUserId];
    if ((string)$state['aceyStage'] === 'roll-again') {
        $state['dice'] = []; $state['remainingDice'] = [];
        return ['state' => $state, 'turnUserId' => $actorUserId];
    }
    $state['dice'] = []; $state['remainingDice'] = []; $state['aceyStage'] = 'roll';
    $nextTurnUserId = ocx_game_advance_turn($state);
    return ['state' => $state, 'turnUserId' => $nextTurnUserId];
}

function acey_deucy_terminal(array &$state, int $winner): array
{
    $opponent = acey_deucy_opponent($state, $winner);
    $classification = 'single';
    if (!acey_deucy_uses_european_rules($state)) {
        $classification = (int)$state['borneOff'][(string)$opponent] > 0 ? 'single' : 'gammon';
        if ((int)$state['borneOff'][(string)$opponent] === 0) {
            $side = acey_deucy_side($state, $winner); $opponentInWinnerHome = false;
            foreach ($state['points'][(string)$opponent] as $point => $count) if ((int)$count > 0 && ($side === 0 ? $point >= 18 : $point <= 5)) $opponentInWinnerHome = true;
            if ((int)$state['bar'][(string)$opponent] > 0 || $opponentInWinnerHome) $classification = 'backgammon';
        }
    }
    $multiplier = ['single' => 1, 'gammon' => 2, 'backgammon' => 3][$classification];
    $state['completed'] = true; $state['winnerUserId'] = $winner; $state['terminalClassification'] = $classification;
    return ['state' => $state, 'turnUserId' => null, 'terminal' => true, 'result' => ocx_game_result_from_scores([(string)$winner => $multiplier, (string)$opponent => 0])];
}

function acey_deucy_apply_action_core(array $state, int $actorUserId, string $action, array $payload, array $context): array
{
    if ((int)($state['schemaVersion'] ?? 0) !== ACEY_DEUCY_STATE_SCHEMA_VERSION || !empty($state['completed'])) throw new MultiplayerGameException('The Acey Deucy state is unavailable.', 'ACEY_DEUCY_STATE_INVALID', 409);
    if ($action === 'resign') {
        $players = array_values(array_map('intval', (array)($state['turnOrder'] ?? [])));
        if (count($players) !== 2 || count(array_unique($players)) !== 2 || (min($players) < 1 && empty($state['bots']))) {
            throw new MultiplayerGameException('Acey Deucy requires two authenticated players.', 'ACEY_DEUCY_PLAYER_SET_INVALID', 422);
        }
        if ($actorUserId < 1 || !in_array($actorUserId, $players, true)) {
            throw new MultiplayerGameException('Only a seated player can resign.', 'MULTIPLAYER_GAME_ACCESS_DENIED', 403);
        }
        return acey_deucy_terminal($state, acey_deucy_opponent($state, $actorUserId));
    }
    if ((string)($state['aceyStage'] ?? '') === 'opening-roll') {
        if ($action !== 'roll') throw new MultiplayerGameException('Complete the opening roll before moving.', 'ACEY_DEUCY_OPENING_ROLL_REQUIRED', 409);
        if ($actorUserId !== (int)($state['openingCoordinatorUserId'] ?? 0)) throw new MultiplayerGameException('The opening-roll coordinator must request the verified roll.', 'ACEY_DEUCY_OPENING_ROLL_ACTOR_INVALID', 403);
        $dice = array_values(array_map('intval', (array)($context['authoritativeRandomness']['dice'] ?? [])));
        if (count($dice) !== 2 || min($dice) < 1 || max($dice) > 6) throw new MultiplayerGameException('Verified opening dice are required.', 'ACEY_DEUCY_RANDOMNESS_INVALID', 409);
        $attempt = [
            'attempt' => count((array)($state['openingRollAttempts'] ?? [])) + 1,
            'rolls' => [
                (string)(int)$state['turnOrder'][0] => $dice[0],
                (string)(int)$state['turnOrder'][1] => $dice[1],
            ],
            'tie' => $dice[0] === $dice[1],
        ];
        $state['openingRollAttempts'][] = $attempt;
        $state['dice'] = [];
        $state['remainingDice'] = [];
        if ($attempt['tie']) {
            $state['starterReason'] = 'The verified opening roll was tied, so both players roll again.';
            return ['state' => $state, 'turnUserId' => null];
        }
        $startingIndex = $dice[0] > $dice[1] ? 0 : 1;
        $starterUserId = (int)$state['turnOrder'][$startingIndex];
        $state['startingIndex'] = $startingIndex;
        $state['turnIndex'] = $startingIndex;
        $state['starterUserId'] = $starterUserId;
        $state['starterReason'] = 'The higher verified opening roll starts; tied opening rolls are rerolled.';
        $state['aceyStage'] = 'roll';
        return ['state' => $state, 'turnUserId' => $starterUserId];
    }
    ocx_game_assert_turn($state, $actorUserId);
    $european = acey_deucy_uses_european_rules($state);
    if ($action === 'roll') {
        if (!in_array((string)$state['aceyStage'], ['roll', 'roll-again'], true) || $state['remainingDice'] !== []) throw new MultiplayerGameException('Finish the available dice before rolling again.', 'ACEY_DEUCY_ROLL_INVALID', 409);
        $dice = array_values(array_map('intval', (array)($context['authoritativeRandomness']['dice'] ?? [])));
        if (count($dice) !== 2 || min($dice) < 1 || max($dice) > 6) throw new MultiplayerGameException('Verified dice are required.', 'ACEY_DEUCY_RANDOMNESS_INVALID', 409);
        $state['dice'] = $dice;
        $isDouble = $dice[0] === $dice[1];
        if ($european && $isDouble) {
            return acey_deucy_european_begin_primary(
                $state,
                $actorUserId,
                $dice[0],
                'rolled-double',
                true
            );
        }
        $state['remainingDice'] = $isDouble ? array_fill(0, 4, $dice[0]) : $dice;
        $state['aceyStage'] = $dice === [1, 2] || $dice === [2, 1] ? 'acey-initial' : ($isDouble ? 'ordinary-double' : 'moving');
        $hasMove = false; foreach (array_unique($state['remainingDice']) as $die) if (acey_deucy_move_available($state, $actorUserId, (int)$die)) $hasMove = true;
        if (!$hasMove) {
            $noLegalStage = (string)$state['aceyStage'];
            if ($european && $noLegalStage === 'acey-initial') {
                return acey_deucy_european_fail_sequence(
                    $state,
                    $actorUserId,
                    'whole-turn',
                    $noLegalStage,
                    $state['remainingDice'],
                    true
                );
            }
            $turnResolution = $noLegalStage === 'ordinary-double'
                ? 'extra-roll'
                : ($noLegalStage === 'acey-initial' && acey_deucy_has_legal_double($state, $actorUserId)
                    ? 'choose-double'
                    : 'pass-turn');
            acey_deucy_record_no_legal_move(
                $state,
                $actorUserId,
                'whole-turn',
                $noLegalStage,
                $dice,
                $state['remainingDice'],
                $turnResolution,
                true
            );
            $state['remainingDice'] = [];
            if ((string)$state['aceyStage'] === 'ordinary-double') { $state['aceyStage'] = 'roll-again'; return acey_deucy_finish_turn($state, $actorUserId); }
            if ((string)$state['aceyStage'] === 'acey-initial') return acey_deucy_finish_acey_initial_without_legal_double($state, $actorUserId);
            $state['aceyStage'] = 'roll';
            $nextTurnUserId = ocx_game_advance_turn($state);
            return ['state' => $state, 'turnUserId' => $nextTurnUserId];
        }
        return ['state' => $state, 'turnUserId' => $actorUserId];
    }
    if ($action === 'choose-double') {
        if ((string)$state['aceyStage'] !== 'choose-double' || $state['remainingDice'] !== []) throw new MultiplayerGameException('A double cannot be chosen now.', 'ACEY_DEUCY_DOUBLE_INVALID', 409);
        $value = (int)($payload['value'] ?? 0);
        if ($value < 1 || $value > 6) throw new MultiplayerGameException('Choose a double from one through six.', 'ACEY_DEUCY_DOUBLE_INVALID', 422);
        if ($european) {
            return acey_deucy_european_begin_primary(
                $state,
                $actorUserId,
                $value,
                'acey-deucey',
                false
            );
        }
        if (!acey_deucy_move_available($state, $actorUserId, $value)) throw new MultiplayerGameException('Choose a double that has a legal move.', 'ACEY_DEUCY_DOUBLE_INVALID', 422);
        $state['dice'] = [$value, $value]; $state['remainingDice'] = array_fill(0, 4, $value); $state['aceyStage'] = 'acey-double';
        return ['state' => $state, 'turnUserId' => $actorUserId];
    }
    if ($action !== 'move') throw new MultiplayerGameException('This Acey Deucy action is not supported.', 'ACEY_DEUCY_ACTION_INVALID', 422);
    if ($state['remainingDice'] === []) throw new MultiplayerGameException('Roll before moving.', 'ACEY_DEUCY_MOVE_INVALID', 409);
    $fromRaw = $payload['from'] ?? null;
    $from = is_string($fromRaw) ? strtolower(trim($fromRaw)) : (int)$fromRaw;
    if (!in_array($from, ['off', 'bar'], true) && (!is_int($from) || $from < 0 || $from > 23)) throw new MultiplayerGameException('Choose a valid piece source.', 'ACEY_DEUCY_MOVE_INVALID', 422);
    $die = (int)($payload['die'] ?? 0);
    $dieIndex = array_search($die, array_map('intval', $state['remainingDice']), true);
    if ($dieIndex === false) throw new MultiplayerGameException('That die is not available.', 'ACEY_DEUCY_DIE_UNAVAILABLE', 422);
    $maximumBefore = acey_deucy_maximum_usable_dice($state, $actorUserId, $state['remainingDice']);
    $requiredDie = acey_deucy_required_larger_die($state, $actorUserId, $state['remainingDice'], $maximumBefore);
    if ($requiredDie !== null && $die !== $requiredDie) throw new MultiplayerGameException('When only one die can be played, use the larger legal die.', 'ACEY_DEUCY_LARGER_DIE_REQUIRED', 422);
    $legal = acey_deucy_legal_moves_for_die($state, $actorUserId, $die);
    $selected = current(array_filter($legal, static fn(array $move): bool => $move['from'] === $from));
    if (!is_array($selected)) throw new MultiplayerGameException('That move is not legal.', 'ACEY_DEUCY_MOVE_ILLEGAL', 422);
    $state['meaningfulPlay'] = true;
    $preview = acey_deucy_apply_legal_move_to_state($state, $actorUserId, $selected);
    $previewDice = array_values(array_map('intval', $state['remainingDice']));
    array_splice($previewDice, (int)$dieIndex, 1);
    if (1 + acey_deucy_maximum_usable_dice($preview, $actorUserId, $previewDice) < $maximumBefore) {
        throw new MultiplayerGameException('Choose a move that preserves the maximum possible number of usable dice for this turn.', 'ACEY_DEUCY_MAXIMUM_DICE_REQUIRED', 422);
    }
    $state = $preview;
    array_splice($state['remainingDice'], (int)$dieIndex, 1);
    $state['history'][] = [
        'actorUserId' => $actorUserId,
        'from' => $from,
        'to' => $selected['to'],
        'die' => $die,
        'rulesProfile' => acey_deucy_rules_profile($state),
        'stage' => (string)$state['aceyStage'],
    ];
    if ((int)$state['borneOff'][(string)$actorUserId] === 15) return acey_deucy_terminal($state, $actorUserId);
    if ($state['remainingDice'] === []) {
        if ($european) {
            $completedStage = (string)$state['aceyStage'];
            if ($completedStage === 'acey-initial') {
                $state['aceyStage'] = 'choose-double';
                return ['state' => $state, 'turnUserId' => $actorUserId];
            }
            if (in_array($completedStage, ['european-double-primary', 'european-acey-primary'], true)) {
                return acey_deucy_european_begin_complement($state, $actorUserId);
            }
            if (in_array($completedStage, ['european-double-complement', 'european-acey-complement'], true)) {
                $state['europeanSequence'] = null;
                $state['aceyStage'] = 'roll-again';
                return acey_deucy_finish_turn($state, $actorUserId);
            }
        }
        if ((string)$state['aceyStage'] === 'acey-initial') return acey_deucy_finish_acey_initial_without_legal_double($state, $actorUserId);
        elseif (in_array((string)$state['aceyStage'], ['acey-double', 'ordinary-double'], true)) $state['aceyStage'] = 'roll-again';
        else $state['aceyStage'] = 'roll';
        return acey_deucy_finish_turn($state, $actorUserId);
    }
    $hasMove = false; foreach (array_unique(array_map('intval', $state['remainingDice'])) as $candidate) if (acey_deucy_move_available($state, $actorUserId, $candidate)) $hasMove = true;
    if (!$hasMove) {
        $noLegalStage = (string)$state['aceyStage'];
        $remainingBeforeResolution = $state['remainingDice'];
        if ($european && ($noLegalStage === 'acey-initial' || acey_deucy_european_sequence_stage($noLegalStage))) {
            return acey_deucy_european_fail_sequence(
                $state,
                $actorUserId,
                'partial-turn',
                $noLegalStage,
                $remainingBeforeResolution,
                false
            );
        }
        $turnResolution = $noLegalStage === 'acey-initial'
            ? (acey_deucy_has_legal_double($state, $actorUserId) ? 'choose-double' : 'pass-turn')
            : (in_array($noLegalStage, ['acey-double', 'ordinary-double'], true) ? 'extra-roll' : 'pass-turn');
        acey_deucy_record_no_legal_move(
            $state,
            $actorUserId,
            'partial-turn',
            $noLegalStage,
            $state['dice'],
            $remainingBeforeResolution,
            $turnResolution,
            false
        );
        $state['remainingDice'] = [];
        if ($noLegalStage === 'acey-initial') return acey_deucy_finish_acey_initial_without_legal_double($state, $actorUserId);
        if (in_array($noLegalStage, ['acey-double', 'ordinary-double'], true)) $state['aceyStage'] = 'roll-again';
        else $state['aceyStage'] = 'roll';
        return acey_deucy_finish_turn($state, $actorUserId);
    }
    return ['state' => $state, 'turnUserId' => $actorUserId];
}
