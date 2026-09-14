<?php
declare(strict_types=1);

require_once __DIR__ . '/ocx_game_extension_support.php';

const CHECKERS_EXTENSION_ID = 'checkers';
const CHECKERS_STATE_SCHEMA_VERSION = 1;

function checkers_clock_profiles(): array
{
    return [
        'no-timer' => ['label' => 'No Timer', 'kind' => 'none'],
        'quick-5' => ['label' => 'Quick — 5+0', 'kind' => 'bank', 'seconds' => 300, 'increment' => 0],
        'wcdf-tournament' => [
            'label' => "WCDF Tournament — 30 moves in 60 minutes,\nthen 15 moves in each 30 minutes",
            'kind' => 'recurring-periods',
            'firstPeriod' => ['moves' => 30, 'seconds' => 3600],
            'recurringPeriod' => ['moves' => 15, 'seconds' => 1800],
        ],
    ];
}

function checkers_clock_descriptor(array $settings): array
{
    $profile = (string)($settings['clockProfile'] ?? 'no-timer');
    if ($profile !== 'custom') return checkers_clock_profiles()[$profile];
    return [
        'label' => 'Custom — ' . $settings['customInitialMinutes'] . ' minutes with ' . $settings['customIncrementSeconds'] . ' second increment',
        'kind' => 'bank',
        'seconds' => (int)$settings['customInitialMinutes'] * 60,
        'increment' => (int)$settings['customIncrementSeconds'],
    ];
}

function checkers_recording_adapter(): array
{
    return ['schemaVersion' => 1,
        'stateKeys' => ['sideLabels', 'board', 'clock', 'clocks', 'completed', 'drawNoticeSequence', 'drawOfferBy', 'forcedFrom', 'history', 'lastDrawResponse', 'meaningfulPlay', 'movesByUser', 'positionCounts', 'quietKingPlies', 'resignedUserId', 'roundNumber', 'rulesClassification', 'schemaVersion', 'settings', 'sideAssignments', 'starterIndex', 'starterReason', 'starterUserId', 'terminalReason', 'turnIndex', 'turnOrder', 'winnerUserId'],
        'payloadKeys' => ['from', 'to']];
}

function checkers_extension_adapter(): array
{
    return [
        'recordingAdapter' => 'checkers_recording_adapter',
        'id' => CHECKERS_EXTENSION_ID,
        'initialState' => 'checkers_initial_state',
        'applyAction' => 'checkers_apply_action',
        'validateSettings' => 'checkers_validate_settings',
        'settingsProjection' => 'checkers_settings_projection',
        'rulesProjection' => 'checkers_rules_projection',
        'projectState' => 'checkers_project_state',
        'presentationStatus' => 'checkers_presentation_status',
        'openingProcedure' => 'rules-profile-starting-side-with-independent-rematch-color-option',
        'rematchSeatRotation' => true,
    ];
}

function checkers_settings_projection(array $settings, string $mode, array $definition = []): array
{
    $settings = checkers_validate_settings($settings, $mode, $definition);
    $custom = $settings['backwardMovement'] || $settings['backwardCapture'] || $settings['flyingKings']
        || $settings['drawHandling'] !== 'automatic' || $settings['rematchColors'] !== 'alternate'
        || $settings['clockProfile'] === 'custom';
    $clockOptions = [];
    foreach (checkers_clock_profiles() as $value => $profile) {
        $clockOptions[] = ['value' => $value, 'label' => (string)$profile['label']];
    }
    $clockOptions[] = ['value' => 'custom', 'label' => 'Custom'];
    return [
        'label' => 'Game Options',
        'description' => ($custom ? 'Custom Rules' : 'Standard Rules') . '. Every player must accept this exact rule set before play begins.',
        'classification' => $custom ? 'custom' : 'standard',
        'classificationLabel' => $custom ? 'Custom Rules' : 'Standard Rules',
        'defaultClassificationLabel' => 'Standard Rules',
        'customClassificationLabel' => 'Custom Rules',
        'controls' => [
            ['key' => 'backwardMovement', 'type' => 'checkbox', 'value' => $settings['backwardMovement'], 'defaultValue' => false, 'label' => 'Regular pieces may move backward', 'description' => 'Off in Standard Rules.'],
            ['key' => 'backwardCapture', 'type' => 'checkbox', 'value' => $settings['backwardCapture'], 'defaultValue' => false, 'label' => 'Regular pieces may capture backward', 'description' => 'Off in Standard Rules.'],
            ['key' => 'flyingKings', 'type' => 'checkbox', 'value' => $settings['flyingKings'], 'defaultValue' => false, 'label' => 'Kings may move multiple open squares', 'description' => 'Off in Standard Rules; ordinary kings move one diagonal square.'],
            [
                'key' => 'drawHandling', 'type' => 'select', 'value' => $settings['drawHandling'],
                'defaultValue' => 'automatic', 'label' => 'Draw handling',
                'description' => 'This shared choice is independent of movement variants and rematch colors.',
                'options' => [
                    ['value' => 'automatic', 'label' => 'Automatic draw protection — Default / CoreChat'],
                    ['value' => 'proposal-only', 'label' => 'Proposal-only draws — Legacy OCX'],
                ],
            ],
            [
                'key' => 'rematchColors', 'type' => 'select', 'value' => $settings['rematchColors'],
                'defaultValue' => 'alternate', 'label' => 'Rematch starter',
                'description' => 'Rotate starter gives the other player the first move after each accepted rematch. Keep starter leaves the same player moving first.',
                'options' => [
                    ['value' => 'alternate', 'label' => 'Rotate starter — Default / CoreChat'],
                    ['value' => 'keep', 'label' => 'Keep starter — Legacy OCX'],
                ],
            ],
            [
                'key' => 'clockProfile', 'type' => 'choice-grid', 'value' => $settings['clockProfile'],
                'defaultValue' => 'no-timer', 'label' => 'Checkers timer',
                'description' => 'No Timer is the default. Quick is 5+0. WCDF Tournament gives each player 60 minutes for 30 moves, then 30 more minutes for each recurring 15-move period. Only the current player’s timer runs, and a complete chain capture is one move.',
                'options' => $clockOptions,
            ],
            [
                'key' => 'customInitialMinutes', 'type' => 'stepper', 'value' => (int)($settings['customInitialMinutes'] ?? 30),
                'defaultValue' => 30, 'minimum' => 1, 'maximum' => 240, 'step' => 1,
                'label' => 'Custom bank minutes', 'description' => 'Used only by Custom; 1 through 240 minutes per player.',
            ],
            [
                'key' => 'customIncrementSeconds', 'type' => 'stepper', 'value' => (int)($settings['customIncrementSeconds'] ?? 0),
                'defaultValue' => 0, 'minimum' => 0, 'maximum' => 300, 'step' => 1,
                'label' => 'Custom increment seconds', 'description' => 'Added after a complete Checkers turn; 0 through 300 seconds.',
            ],
        ],
    ];
}

function checkers_presentation_status(PDO $pdo, ?string $requestedPack = null): array
{
    return ocx_game_presentation_status($pdo, CHECKERS_EXTENSION_ID, $requestedPack);
}

function checkers_validate_settings(array $settings, string $mode, array $definition = []): array
{
    if (!in_array($mode, ['practice', 'recorded'], true)) {
        throw new MultiplayerGameException('Choose Practice or Recorded Play.', 'MULTIPLAYER_GAME_MODE_INVALID', 422);
    }
    $allowed = ['backwardMovement', 'backwardCapture', 'flyingKings', 'mandatoryCapture', 'drawHandling', 'rematchColors', 'clockProfile', 'customInitialMinutes', 'customIncrementSeconds'];
    if (array_diff(array_keys($settings), $allowed)) {
        throw new MultiplayerGameException('A Checkers setting is not supported.', 'CHECKERS_SETTINGS_INVALID', 422);
    }
    $validated = [];
    foreach (['backwardMovement', 'backwardCapture', 'flyingKings'] as $key) {
        $value = $settings[$key] ?? false;
        if (!is_bool($value) && !in_array($value, [0, 1, '0', '1'], true)) {
            throw new MultiplayerGameException('Checkers variants must be enabled or disabled.', 'CHECKERS_SETTINGS_INVALID', 422);
        }
        $validated[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
    if (array_key_exists('mandatoryCapture', $settings)
        && !in_array($settings['mandatoryCapture'], [true, 1, '1'], true)) {
        throw new MultiplayerGameException('Mandatory capture remains enabled in this profile.', 'CHECKERS_SETTINGS_INVALID', 422);
    }
    $validated['mandatoryCapture'] = true;
    $drawHandling = (string)($settings['drawHandling'] ?? 'automatic');
    if (!in_array($drawHandling, ['automatic', 'proposal-only'], true)) {
        throw new MultiplayerGameException('Choose Automatic draw protection or Proposal-only draws.', 'CHECKERS_SETTINGS_INVALID', 422);
    }
    $validated['drawHandling'] = $drawHandling;
    $rematchColors = (string)($settings['rematchColors'] ?? 'alternate');
    if (!in_array($rematchColors, ['alternate', 'keep'], true)) {
        throw new MultiplayerGameException('Choose Alternate colors or Keep colors.', 'CHECKERS_SETTINGS_INVALID', 422);
    }
    $validated['rematchColors'] = $rematchColors;
    $clockProfile = strtolower(trim((string)($settings['clockProfile'] ?? 'no-timer')));
    if ($clockProfile !== 'custom' && !isset(checkers_clock_profiles()[$clockProfile])) {
        throw new MultiplayerGameException('Choose a published Checkers timer profile.', 'CHECKERS_CLOCK_PROFILE_INVALID', 422);
    }
    $validated['clockProfile'] = $clockProfile;
    if ($clockProfile === 'custom') {
        $initial = (int)($settings['customInitialMinutes'] ?? 30);
        $increment = (int)($settings['customIncrementSeconds'] ?? 0);
        if ($initial < 1 || $initial > 240 || $increment < 0 || $increment > 300) {
            throw new MultiplayerGameException('Custom Checkers time must use a 1–240 minute bank with at most 300 seconds increment.', 'CHECKERS_CUSTOM_CLOCK_INVALID', 422);
        }
        $validated['customInitialMinutes'] = $initial;
        $validated['customIncrementSeconds'] = $increment;
    }
    return $validated;
}

function checkers_rules_projection(array $settings, string $mode, array $definition = []): array
{
    $settings = checkers_validate_settings($settings, $mode, $definition);
    $clock = checkers_clock_descriptor($settings);
    $variants = [];
    if ($settings['backwardMovement']) $variants[] = 'regular pieces may move backward';
    if ($settings['backwardCapture']) $variants[] = 'regular pieces may capture backward';
    if ($settings['flyingKings']) $variants[] = 'kings may move and capture across multiple empty squares';
    $variantText = $variants === []
        ? 'No nonstandard movement variants are active.'
        : 'Active variants: ' . implode('; ', $variants) . '.';
    $classification = $variants === [] && $settings['drawHandling'] === 'automatic' && $settings['rematchColors'] === 'alternate'
        ? 'Standard Rules' : 'Custom Rules';
    $drawText = $settings['drawHandling'] === 'automatic'
        ? 'Agreed draws, third-position repetition, and the 40-moves-each protection are active.'
        : 'Only mutually agreed draw proposals are active; automatic repetition and 40-move completion are disabled.';
    $rematchText = $settings['rematchColors'] === 'alternate'
        ? 'Accepted rematches alternate the two color assignments; Black/blue starts.'
        : 'Accepted rematches keep both color assignments; Black/blue starts.';
    return [
        'label' => 'Checkers rules',
        'description' => $classification . '. Standard play uses an 8 by 8 board and 12 pieces per side. Captures are mandatory; when several captures begin a turn, the player may choose any one, then the same piece must finish every available jump. Promotion ends that turn. Kings move one diagonal square in either direction unless the accepted flying-kings variant is active. ' . $variantText,
        'sections' => [
            ['label' => 'Mode', 'text' => $mode === 'recorded' ? 'Ranked or Recorded Play updates Checkers-only records after a valid completion. A non-default option is labeled Custom Rules and is counted separately from Standard Rules results.' : 'Practice Mode permits any accepted option combination and never changes Recorded records.'],
            ['label' => 'Winning', 'text' => 'A player loses when all pieces are captured, no legal move remains, or the player resigns or forfeits.'],
            ['label' => 'Draws', 'text' => $drawText],
            ['label' => 'Rematches', 'text' => $rematchText],
            ['label' => 'Timer', 'text' => $clock['kind'] === 'none' ? 'No timer is active.' : 'The accepted server-owned timer is ' . $clock['label'] . '. Only the current player’s time runs. A complete chain capture is one move and one clock transition.'],
            ['label' => 'Variants', 'text' => $variantText],
        ],
    ];
}

function checkers_initial_state(array $playerUserIds, array $context = []): array
{
    $players = array_values(array_unique(array_map('intval', $playerUserIds)));
    if (count($players) !== 2 || min($players) < 1) {
        throw new MultiplayerGameException('Checkers requires two authenticated players.', 'CHECKERS_PLAYER_SET_INVALID', 422);
    }
    $settings = checkers_validate_settings((array)($context['settings'] ?? []), (string)($context['mode'] ?? 'practice'));
    $clock = checkers_clock_descriptor($settings);
    $startedAt = (string)($context['startedAt'] ?? gmdate('c'));
    $rulesClassification = $settings['backwardMovement'] || $settings['backwardCapture'] || $settings['flyingKings']
        || $settings['drawHandling'] !== 'automatic' || $settings['rematchColors'] !== 'alternate'
        ? 'custom' : 'standard';
    $board = array_fill(0, 8, array_fill(0, 8, null));
    for ($row = 0; $row < 3; $row++) {
        for ($column = 0; $column < 8; $column++) if (($row + $column) % 2 === 1) $board[$row][$column] = 'a';
    }
    for ($row = 5; $row < 8; $row++) {
        for ($column = 0; $column < 8; $column++) if (($row + $column) % 2 === 1) $board[$row][$column] = 'b';
    }
    $roundContext = (array)($context['roundContext'] ?? []);
    $previousState = (array)($roundContext['previousState'] ?? []);
    $advance = !empty($roundContext['seriesContinues']) && !empty($roundContext['advancesSeries']);
    $roundNumber = !empty($roundContext['seriesContinues'])
        ? max(1, (int)($roundContext['previousRoundNumber'] ?? 1) + ($advance ? 1 : 0))
        : 1;
    $previousStarter = (int)($previousState['starterIndex'] ?? 0);
    $starterIndex = !empty($roundContext['seriesContinues'])
        ? ($advance && $settings['rematchColors'] === 'alternate' ? 1 - ($previousStarter % 2) : $previousStarter % 2)
        : 0;
    $sideAssignments = [
        (string)$players[$starterIndex] => 'a',
        (string)$players[1 - $starterIndex] => 'b',
    ];
    $state = [
        'schemaVersion' => CHECKERS_STATE_SCHEMA_VERSION,
        'turnOrder' => $players,
        'turnIndex' => $starterIndex,
        'roundNumber' => $roundNumber,
        'starterIndex' => $starterIndex,
        'starterUserId' => $players[$starterIndex],
        'starterReason' => $roundNumber === 1
            ? 'The rules-profile Black/blue starting side moves first in the first round.'
            : ($settings['rematchColors'] === 'alternate'
                ? 'The accepted rematch alternates the two color assignments; Black/blue starts.'
                : 'The accepted rematch keeps both color assignments; Black/blue starts.'),
        'sideAssignments' => $sideAssignments,
        'sideLabels' => ['a' => 'Starting side', 'b' => 'Opposing side'],
        'board' => $board,
        'settings' => $settings,
        'clock' => $clock,
        'clocks' => checkers_initial_clocks($players, $clock, $startedAt, $players[$starterIndex]),
        'movesByUser' => array_fill_keys(array_map('strval', $players), 0),
        'rulesClassification' => $rulesClassification,
        'forcedFrom' => null,
        'quietKingPlies' => 0,
        'positionCounts' => [],
        'drawOfferBy' => null,
        'drawNoticeSequence' => 0,
        'lastDrawResponse' => null,
        'history' => [],
        'meaningfulPlay' => false,
        'completed' => false,
    ];
    checkers_record_position($state);
    return $state;
}

function checkers_piece_side(?string $piece): ?string
{
    if (!is_string($piece) || !in_array(strtolower($piece), ['a', 'b'], true)) return null;
    return strtolower($piece);
}

function checkers_inside(int $row, int $column): bool
{
    return $row >= 0 && $row < 8 && $column >= 0 && $column < 8;
}

function checkers_square(mixed $value): array
{
    if (!is_array($value) || count($value) !== 2 || !is_numeric($value[0] ?? null) || !is_numeric($value[1] ?? null)) {
        throw new MultiplayerGameException('Choose a valid board square.', 'CHECKERS_SQUARE_INVALID', 422);
    }
    $row = (int)$value[0];
    $column = (int)$value[1];
    if (!checkers_inside($row, $column)) throw new MultiplayerGameException('Choose a valid board square.', 'CHECKERS_SQUARE_INVALID', 422);
    return [$row, $column];
}

function checkers_side_for_user(array $state, int $userId): string
{
    $assigned = strtolower((string)($state['sideAssignments'][(string)$userId] ?? ''));
    if (in_array($assigned, ['a', 'b'], true)) return $assigned;
    $index = array_search($userId, array_map('intval', (array)$state['turnOrder']), true);
    if ($index === false) throw new MultiplayerGameException('The player is not in this game.', 'CHECKERS_PLAYER_INVALID', 403);
    return $index === 0 ? 'a' : 'b';
}

function checkers_directions(string $piece, bool $capture, array $settings): array
{
    if (ctype_upper($piece)) return [[-1, -1], [-1, 1], [1, -1], [1, 1]];
    $side = strtolower($piece);
    $forward = $side === 'a' ? 1 : -1;
    $backwardAllowed = $capture ? !empty($settings['backwardCapture']) : !empty($settings['backwardMovement']);
    $directions = [[$forward, -1], [$forward, 1]];
    if ($backwardAllowed) $directions = array_merge($directions, [[-$forward, -1], [-$forward, 1]]);
    return $directions;
}

function checkers_moves_from(array $state, int $row, int $column, bool $capturesOnly = false): array
{
    $board = $state['board'];
    $piece = $board[$row][$column] ?? null;
    if (!is_string($piece)) return [];
    $side = strtolower($piece);
    $settings = (array)$state['settings'];
    $moves = [];
    $flying = ctype_upper($piece) && !empty($settings['flyingKings']);
    foreach (checkers_directions($piece, true, $settings) as [$dr, $dc]) {
        if ($flying) {
            $seenEnemy = null;
            for ($step = 1; $step < 8; $step++) {
                $toRow = $row + $dr * $step;
                $toColumn = $column + $dc * $step;
                if (!checkers_inside($toRow, $toColumn)) break;
                $target = $board[$toRow][$toColumn];
                if ($target === null) {
                    if ($seenEnemy !== null) $moves[] = ['from' => [$row, $column], 'to' => [$toRow, $toColumn], 'capture' => $seenEnemy];
                    continue;
                }
                if (strtolower((string)$target) === $side || $seenEnemy !== null) break;
                $seenEnemy = [$toRow, $toColumn];
            }
        } else {
            $middleRow = $row + $dr;
            $middleColumn = $column + $dc;
            $toRow = $row + 2 * $dr;
            $toColumn = $column + 2 * $dc;
            if (checkers_inside($toRow, $toColumn)
                && $board[$toRow][$toColumn] === null
                && checkers_piece_side($board[$middleRow][$middleColumn] ?? null) !== null
                && checkers_piece_side($board[$middleRow][$middleColumn]) !== $side) {
                $moves[] = ['from' => [$row, $column], 'to' => [$toRow, $toColumn], 'capture' => [$middleRow, $middleColumn]];
            }
        }
    }
    if ($moves !== [] || $capturesOnly) return $moves;
    foreach (checkers_directions($piece, false, $settings) as [$dr, $dc]) {
        $limit = $flying ? 7 : 1;
        for ($step = 1; $step <= $limit; $step++) {
            $toRow = $row + $dr * $step;
            $toColumn = $column + $dc * $step;
            if (!checkers_inside($toRow, $toColumn) || $board[$toRow][$toColumn] !== null) break;
            $moves[] = ['from' => [$row, $column], 'to' => [$toRow, $toColumn], 'capture' => null];
        }
    }
    return $moves;
}

function checkers_legal_moves(array $state, string $side): array
{
    if (is_array($state['forcedFrom'] ?? null)) {
        [$row, $column] = $state['forcedFrom'];
        return checkers_moves_from($state, (int)$row, (int)$column, true);
    }
    $captures = [];
    $ordinary = [];
    for ($row = 0; $row < 8; $row++) {
        for ($column = 0; $column < 8; $column++) {
            if (checkers_piece_side($state['board'][$row][$column] ?? null) !== $side) continue;
            $moves = checkers_moves_from($state, $row, $column);
            foreach ($moves as $move) {
                if ($move['capture'] !== null) $captures[] = $move;
                else $ordinary[] = $move;
            }
        }
    }
    return $captures !== [] ? $captures : $ordinary;
}

function checkers_project_state(array $state, int $viewerUserId, array $context): array
{
    $projection = $state;
    if ((int)($projection['lastDrawResponse']['forUserId'] ?? 0) !== $viewerUserId) {
        $projection['lastDrawResponse'] = null;
    }
    $interaction = [
        'authority' => 'server-legal-move-projection',
        'selectableOrigins' => [],
        'legalDestinationsByOrigin' => [],
        'forcedOrigin' => null,
        'legalMoveCount' => 0,
    ];
    $currentUserId = (int)($state['turnOrder'][(int)($state['turnIndex'] ?? -1)] ?? 0);
    $viewerCanAct = in_array((string)($context['viewerRole'] ?? ''), ['master', 'player'], true)
        && (string)($context['status'] ?? '') === 'active'
        && empty($state['completed'])
        && (int)($state['drawOfferBy'] ?? 0) < 1
        && $viewerUserId > 0
        && $viewerUserId === $currentUserId;
    if ($viewerCanAct) {
        $moves = checkers_legal_moves($state, checkers_side_for_user($state, $viewerUserId));
        foreach ($moves as $move) {
            $origin = (int)$move['from'][0] . ':' . (int)$move['from'][1];
            $destination = (int)$move['to'][0] . ':' . (int)$move['to'][1];
            $interaction['legalDestinationsByOrigin'][$origin] ??= [];
            if (!in_array($destination, $interaction['legalDestinationsByOrigin'][$origin], true)) {
                $interaction['legalDestinationsByOrigin'][$origin][] = $destination;
            }
        }
        $interaction['selectableOrigins'] = array_keys($interaction['legalDestinationsByOrigin']);
        sort($interaction['selectableOrigins'], SORT_NATURAL);
        foreach ($interaction['legalDestinationsByOrigin'] as &$destinations) sort($destinations, SORT_NATURAL);
        unset($destinations);
        $interaction['legalMoveCount'] = count($moves);
        if (is_array($state['forcedFrom'] ?? null)) {
            $interaction['forcedOrigin'] = (int)$state['forcedFrom'][0] . ':' . (int)$state['forcedFrom'][1];
        }
    }
    $projection['interaction'] = $interaction;
    $projection['drawProgress'] = checkers_draw_progress_projection($state);
    return $projection;
}

function checkers_draw_progress_projection(array $state): array
{
    $turnOrder = array_values(array_map('intval', (array)($state['turnOrder'] ?? [])));
    $turnIndex = (int)($state['turnIndex'] ?? -1);
    $currentUserId = (int)($turnOrder[$turnIndex] ?? 0);
    $positionAppearances = 0;
    if ($currentUserId > 0) {
        $key = checkers_position_key($state);
        $positionAppearances = (int)($state['positionCounts'][$key] ?? 0);
    }

    $quietKingPlies = max(0, (int)($state['quietKingPlies'] ?? 0));
    $quietKingMovesByUser = [];
    foreach ($turnOrder as $userId) {
        if ($userId > 0) $quietKingMovesByUser[(string)$userId] = 0;
    }
    if (count($turnOrder) === 2 && $currentUserId > 0) {
        $lastMoverIndex = 1 - $turnIndex;
        $lastMoverUserId = (int)($turnOrder[$lastMoverIndex] ?? 0);
        $quietKingMovesByUser[(string)$currentUserId] = intdiv($quietKingPlies, 2);
        if ($lastMoverUserId > 0) {
            $quietKingMovesByUser[(string)$lastMoverUserId] = intdiv($quietKingPlies + 1, 2);
        }
    }

    $drawHandling = (string)($state['settings']['drawHandling'] ?? 'automatic');
    return [
        'drawHandling' => $drawHandling,
        'automaticProtectionEnabled' => $drawHandling === 'automatic',
        'positionAppearances' => $positionAppearances,
        'quietKingMovesByUser' => $quietKingMovesByUser,
        'automaticAfterAppearances' => 3,
        'automaticAfterQuietKingMovesPerPlayer' => 40,
        'quietKingSequenceLength' => $quietKingPlies,
        'completed' => !empty($state['completed']),
        'terminalReason' => (string)($state['terminalReason'] ?? ''),
    ];
}

function checkers_position_key(array $state): string
{
    return strtoupper(hash('sha256', multiplayer_game_canonical_json([
        'board' => $state['board'], 'turnIndex' => $state['turnIndex'], 'forcedFrom' => $state['forcedFrom'],
    ])));
}

function checkers_record_position(array &$state): int
{
    $key = checkers_position_key($state);
    $state['positionCounts'][$key] = (int)($state['positionCounts'][$key] ?? 0) + 1;
    if (count($state['positionCounts']) > 256) $state['positionCounts'] = array_slice($state['positionCounts'], -256, null, true);
    return (int)$state['positionCounts'][$key];
}

function checkers_scores(array $state, ?int $winner = null): array
{
    $scores = [];
    foreach ((array)$state['turnOrder'] as $userId) {
        $side = checkers_side_for_user($state, (int)$userId);
        $pieces = 0;
        foreach ((array)$state['board'] as $row) foreach ((array)$row as $piece) if (checkers_piece_side($piece) === $side) $pieces++;
        $scores[(string)(int)$userId] = $winner === null ? $pieces : ((int)$userId === $winner ? 1 : 0);
    }
    return $scores;
}

function checkers_terminal(array &$state, ?int $winner, string $reason): array
{
    $state['completed'] = true;
    $state['terminalReason'] = $reason;
    $state['winnerUserId'] = $winner;
    return [
        'state' => $state,
        'turnUserId' => null,
        'terminal' => true,
        'result' => ocx_game_result_from_scores(checkers_scores($state, $winner)) + [
            'recordClass' => (string)($state['rulesClassification'] ?? 'standard'),
        ],
    ];
}

function checkers_now(array $context): int
{
    $parsed = strtotime((string)($context['now'] ?? ''));
    return $parsed === false ? time() : $parsed;
}

function checkers_initial_clocks(array $players, array $clock, string $startedAt, int $starterUserId): array
{
    $clocks = [];
    foreach ($players as $userId) {
        $remaining = match ((string)$clock['kind']) {
            'bank' => (int)$clock['seconds'],
            'recurring-periods' => (int)$clock['firstPeriod']['seconds'],
            default => null,
        };
        $clocks[(string)$userId] = [
            'remainingSeconds' => $remaining,
            'turnStartedAt' => null,
            'movesInPeriod' => 0,
            'periodIndex' => 0,
        ];
    }
    if (($clock['kind'] ?? 'none') !== 'none') $clocks[(string)$starterUserId]['turnStartedAt'] = $startedAt;
    return $clocks;
}

function checkers_settle_active_clock(array &$state, array $context): ?int
{
    if (($state['clock']['kind'] ?? 'none') === 'none') return null;
    $active = (int)$state['turnOrder'][(int)$state['turnIndex']];
    $clock = &$state['clocks'][(string)$active];
    if ($clock['turnStartedAt'] !== null) {
        $started = strtotime((string)$clock['turnStartedAt']);
        $now = checkers_now($context);
        if ($started !== false) $clock['remainingSeconds'] = max(0, (int)$clock['remainingSeconds'] - max(0, $now - $started));
        $clock['turnStartedAt'] = gmdate('c', $now);
    }
    return (int)$clock['remainingSeconds'] <= 0 ? $active : null;
}

function checkers_start_next_clock(array &$state, int $movedUserId, array $context): void
{
    $kind = (string)($state['clock']['kind'] ?? 'none');
    if ($kind === 'none') return;
    $moved = &$state['clocks'][(string)$movedUserId];
    if ($kind === 'bank') {
        $moved['remainingSeconds'] = (int)$moved['remainingSeconds'] + (int)($state['clock']['increment'] ?? 0);
    } elseif ($kind === 'recurring-periods') {
        $moved['movesInPeriod'] = (int)$moved['movesInPeriod'] + 1;
        $periodIndex = (int)$moved['periodIndex'];
        $required = $periodIndex === 0
            ? (int)$state['clock']['firstPeriod']['moves']
            : (int)$state['clock']['recurringPeriod']['moves'];
        if ($moved['movesInPeriod'] >= $required) {
            $moved['periodIndex'] = $periodIndex + 1;
            $moved['movesInPeriod'] = 0;
            $moved['remainingSeconds'] = (int)$moved['remainingSeconds'] + (int)$state['clock']['recurringPeriod']['seconds'];
        }
    }
    $moved['turnStartedAt'] = null;
    $nextUserId = (int)$state['turnOrder'][(int)$state['turnIndex']];
    $state['clocks'][(string)$nextUserId]['turnStartedAt'] = gmdate('c', checkers_now($context));
}

function checkers_apply_action(array $state, int $actorUserId, string $action, array $payload, array $context): array
{
    if ((int)($state['schemaVersion'] ?? 0) !== CHECKERS_STATE_SCHEMA_VERSION || !empty($state['completed'])) {
        throw new MultiplayerGameException('The Checkers state is unavailable.', 'CHECKERS_STATE_INVALID', 409);
    }
    $expired = checkers_settle_active_clock($state, $context);
    if ($expired !== null) {
        $winner = current(array_filter(array_map('intval', $state['turnOrder']), static fn(int $id): bool => $id !== $expired));
        return checkers_terminal($state, (int)$winner, 'clock-expiration');
    }
    $pendingDrawOfferBy = (int)($state['drawOfferBy'] ?? 0);
    if ($pendingDrawOfferBy > 0 && !in_array($action, ['accept-draw', 'decline-draw'], true)) {
        throw new MultiplayerGameException('Accept or decline the pending draw proposal before continuing.', 'CHECKERS_DRAW_RESPONSE_REQUIRED', 409);
    }
    if ($action === 'resign') {
        if (!in_array($actorUserId, array_map('intval', $state['turnOrder']), true)) throw new MultiplayerGameException('Only a player may resign.', 'CHECKERS_PLAYER_INVALID', 403);
        $winner = current(array_filter(array_map('intval', $state['turnOrder']), static fn(int $id): bool => $id !== $actorUserId));
        return checkers_terminal($state, (int)$winner, 'resignation');
    }
    if ($action === 'offer-draw') {
        if (!in_array($actorUserId, array_map('intval', $state['turnOrder']), true)) throw new MultiplayerGameException('Only a player may offer a draw.', 'CHECKERS_PLAYER_INVALID', 403);
        if ((int)($state['drawOfferBy'] ?? 0) > 0) throw new MultiplayerGameException('A draw proposal is already pending.', 'CHECKERS_DRAW_OFFER_PENDING', 409);
        $state['drawOfferBy'] = $actorUserId;
        $state['lastDrawResponse'] = null;
        return ['state' => $state, 'turnUserId' => (int)$state['turnOrder'][(int)$state['turnIndex']]];
    }
    if ($action === 'accept-draw') {
        if (!in_array($actorUserId, array_map('intval', $state['turnOrder']), true)) {
            throw new MultiplayerGameException('Only a player may accept a draw.', 'CHECKERS_PLAYER_INVALID', 403);
        }
        if ((int)($state['drawOfferBy'] ?? 0) < 1 || (int)$state['drawOfferBy'] === $actorUserId) {
            throw new MultiplayerGameException('There is no opponent draw offer to accept.', 'CHECKERS_DRAW_OFFER_INVALID', 409);
        }
        $state['drawOfferBy'] = null;
        return checkers_terminal($state, null, 'mutual-agreement');
    }
    if ($action === 'decline-draw') {
        if (!in_array($actorUserId, array_map('intval', $state['turnOrder']), true)) {
            throw new MultiplayerGameException('Only a player may decline a draw.', 'CHECKERS_PLAYER_INVALID', 403);
        }
        $proposer = (int)($state['drawOfferBy'] ?? 0);
        if ($proposer < 1 || $proposer === $actorUserId) {
            throw new MultiplayerGameException('There is no opponent draw offer to decline.', 'CHECKERS_DRAW_OFFER_INVALID', 409);
        }
        $state['drawOfferBy'] = null;
        $state['drawNoticeSequence'] = (int)($state['drawNoticeSequence'] ?? 0) + 1;
        $state['lastDrawResponse'] = [
            'type' => 'declined', 'forUserId' => $proposer, 'byUserId' => $actorUserId,
            'sequence' => (int)$state['drawNoticeSequence'],
        ];
        return ['state' => $state, 'turnUserId' => (int)$state['turnOrder'][(int)$state['turnIndex']]];
    }
    if ($action !== 'move') throw new MultiplayerGameException('This Checkers action is not supported.', 'CHECKERS_ACTION_INVALID', 422);
    ocx_game_assert_turn($state, $actorUserId);
    [$fromRow, $fromColumn] = checkers_square($payload['from'] ?? null);
    [$toRow, $toColumn] = checkers_square($payload['to'] ?? null);
    $side = checkers_side_for_user($state, $actorUserId);
    if (checkers_piece_side($state['board'][$fromRow][$fromColumn] ?? null) !== $side) {
        throw new MultiplayerGameException('Choose one of your pieces.', 'CHECKERS_PIECE_INVALID', 422);
    }
    $legal = checkers_legal_moves($state, $side);
    $selected = current(array_filter($legal, static fn(array $move): bool => $move['from'] === [$fromRow, $fromColumn] && $move['to'] === [$toRow, $toColumn]));
    if (!is_array($selected)) throw new MultiplayerGameException('That move is not legal.', 'CHECKERS_MOVE_ILLEGAL', 422);
    $state['meaningfulPlay'] = true;
    $piece = (string)$state['board'][$fromRow][$fromColumn];
    $state['board'][$fromRow][$fromColumn] = null;
    $state['board'][$toRow][$toColumn] = $piece;
    $captured = $selected['capture'] !== null;
    if ($captured) {
        [$captureRow, $captureColumn] = $selected['capture'];
        $state['board'][$captureRow][$captureColumn] = null;
    }
    $promoted = false;
    if (!ctype_upper($piece) && (($side === 'a' && $toRow === 7) || ($side === 'b' && $toRow === 0))) {
        $state['board'][$toRow][$toColumn] = strtoupper($piece);
        $promoted = true;
    }
    $state['history'][] = ['actorUserId' => $actorUserId, 'from' => [$fromRow, $fromColumn], 'to' => [$toRow, $toColumn], 'captured' => $captured, 'promoted' => $promoted];
    if (count($state['history']) > 256) $state['history'] = array_slice($state['history'], -256);
    if ($captured || !ctype_upper($piece)) $state['quietKingPlies'] = 0;
    else $state['quietKingPlies'] = (int)$state['quietKingPlies'] + 1;
    if ($captured && !$promoted) {
        $state['forcedFrom'] = [$toRow, $toColumn];
        if (checkers_moves_from($state, $toRow, $toColumn, true) !== []) {
            return ['state' => $state, 'turnUserId' => $actorUserId];
        }
    }
    $state['forcedFrom'] = null;
    $nextUserId = ocx_game_advance_turn($state);
    $state['movesByUser'][(string)$actorUserId] = (int)($state['movesByUser'][(string)$actorUserId] ?? 0) + 1;
    checkers_start_next_clock($state, $actorUserId, $context);
    $nextSide = checkers_side_for_user($state, $nextUserId);
    $hasNextPiece = false;
    foreach ($state['board'] as $row) foreach ($row as $boardPiece) if (checkers_piece_side($boardPiece) === $nextSide) $hasNextPiece = true;
    if (!$hasNextPiece) return checkers_terminal($state, $actorUserId, 'all-pieces-captured');
    if (checkers_legal_moves($state, $nextSide) === []) return checkers_terminal($state, $actorUserId, 'no-legal-move');
    $positionCount = checkers_record_position($state);
    if (($state['settings']['drawHandling'] ?? 'automatic') === 'automatic') {
        if ($positionCount >= 3) return checkers_terminal($state, null, 'third-repetition');
        if ((int)$state['quietKingPlies'] >= 80) return checkers_terminal($state, null, 'forty-move-rule');
    }
    return ['state' => $state, 'turnUserId' => $nextUserId];
}
