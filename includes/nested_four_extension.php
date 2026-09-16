<?php
declare(strict_types=1);

require_once __DIR__ . '/ocx_game_extension_support.php';
require_once __DIR__ . '/nested_four_bot_support.php';

const NESTED_FOUR_EXTENSION_ID = 'nested-four';
const NESTED_FOUR_STATE_SCHEMA_VERSION = 1;
const NESTED_FOUR_BOARD_CELLS = 16;
const NESTED_FOUR_RESERVE_STACKS = 3;
const NESTED_FOUR_SIZES = ['S', 'M', 'L', 'XL'];

function nested_four_extension_adapter(): array
{
    return [
        'id' => NESTED_FOUR_EXTENSION_ID,
        'initialState' => 'nested_four_initial_state',
        'applyAction' => 'nested_four_apply_action',
        'validateSettings' => 'nested_four_validate_settings',
        'settingsProjection' => 'nested_four_settings_projection',
        'rulesProjection' => 'nested_four_rules_projection',
        'projectState' => 'nested_four_project_state',
        'projectVirtualMembers' => 'nested_four_project_virtual_members',
        'presentationStatus' => 'nested_four_presentation_status',
        'openingProcedure' => 'fixed-seats-first-player-starts',
        'rematchSeatRotation' => true,
    ];
}

function nested_four_presentation_status(PDO $pdo, ?string $requestedPack = null): array
{
    return [
        'requestedPack' => 'built-in',
        'effectivePack' => 'built-in',
        'classicAvailable' => false,
        'fallbackApplied' => false,
        'presentationOnly' => true,
        'mediaPack' => ['publicCodeNative' => true, 'remoteMedia' => false],
    ];
}

const NESTED_FOUR_RESERVE_COVER_ORIGINAL = 'original';
const NESTED_FOUR_RESERVE_COVER_CUSTOM = 'custom';

function nested_four_validate_settings(array $settings, string $mode, array $definition = []): array
{
    $rule = (string)($settings['reserveCoveringRule'] ?? NESTED_FOUR_RESERVE_COVER_ORIGINAL);
    if (!in_array($rule, [NESTED_FOUR_RESERVE_COVER_ORIGINAL, NESTED_FOUR_RESERVE_COVER_CUSTOM], true)) {
        throw new MultiplayerGameException('Choose Original Gobblet rule or Custom covering rule.', 'NESTED_FOUR_SETTINGS_INVALID', 422);
    }
    $difficulty=$settings['botSeat2Difficulty'] ?? 'none';
    if (!in_array($difficulty,array_column(nested_four_bot_choices(),'value'),true)) throw new MultiplayerGameException('Choose a listed bot level.','NESTED_FOUR_SETTINGS_INVALID',422);
    if ($difficulty!=='none' && $mode!=='practice') throw new MultiplayerGameException('Games with bots are Practice only.','MULTIPLAYER_GAME_BOTS_PRACTICE_ONLY',422);
    unset($settings['reserveCoveringRule'], $settings['botSeat2Difficulty']);
    $validated = nested_four_validate_settings_legacy($settings, $mode, $definition);
    $validated['reserveCoveringRule'] = $rule;
    if ($mode==='practice') $validated['botSeat2Difficulty']=$difficulty;
    return $validated;
}
function nested_four_validate_settings_legacy(array $settings, string $mode, array $definition = []): array
{
    if ($settings === []) {
        return [];
    }
    if (array_diff(array_keys($settings), ['inactivityProfile', 'customInactivitySeconds'])) {
        throw new MultiplayerGameException('A Nested Four setting is not supported.', 'NESTED_FOUR_SETTINGS_INVALID', 422);
    }
    $profile = strtolower(trim((string)($settings['inactivityProfile'] ?? 'unlimited')));
    if (!in_array($profile, ['quick', 'default', 'relaxed', 'custom', 'unlimited'], true)) {
        throw new MultiplayerGameException('Choose a published inactivity protection profile.', 'NESTED_FOUR_SETTINGS_INVALID', 422);
    }
    $customSeconds = filter_var(
        $settings['customInactivitySeconds'] ?? 120,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 15, 'max_range' => 3600]]
    );
    if ($customSeconds === false) {
        throw new MultiplayerGameException('Custom inactivity protection must be from 15 through 3600 seconds.', 'NESTED_FOUR_SETTINGS_INVALID', 422);
    }
    return ['inactivityProfile' => $profile, 'customInactivitySeconds' => (int)$customSeconds];
}

function nested_four_settings_projection(array $settings, string $mode, array $definition = []): array
{
    $settings = nested_four_validate_settings($settings, $mode, $definition);
    nested_four_validate_settings($settings, $mode, $definition);
    return [
        'label' => 'Game Options',
        'description' => 'Nested Four for two players. Add an optional Practice bot to the empty second seat in the waiting lobby.',
        'classificationLabel' => 'Accepted Nested Four options',
        'controls' => [[
            'key' => 'reserveCoveringRule',
            'type' => 'button-choice',
            'value' => $settings['reserveCoveringRule'],
            'defaultValue' => NESTED_FOUR_RESERVE_COVER_ORIGINAL,
            'label' => 'Reserve covering rule',
            'description' => 'This authoritative match rule is selected before play and remains locked for the active game.',
            'options' => [
                ['value' => NESTED_FOUR_RESERVE_COVER_ORIGINAL, 'label' => 'Original Gobblet rule - Default', 'description' => 'A piece entering directly from a reserve normally goes on an empty cell. If the opponent has three visible pieces in one winning line, a larger reserve piece may cover one smaller opposing piece in that line.'],
                ['value' => NESTED_FOUR_RESERVE_COVER_CUSTOM, 'label' => 'Custom covering rule', 'description' => 'A larger reserve piece may cover any smaller visible opposing piece at any time.'],
            ],
        ]],];
}

function nested_four_rules_projection(array $settings, string $mode, array $definition = []): array
{
    $settings = nested_four_validate_settings($settings, $mode, $definition);
    nested_four_validate_settings($settings, $mode, $definition);
    return [
        'label' => 'Nested Four rules',
        'description' => 'Cover. Reveal. Connect. Make four visible pieces in a row while remembering what is hidden.',
        'sections' => [
            ['label' => 'Players and setup', 'text' => 'Two players each receive twelve pieces: three nested reserve stacks containing S, M, L, and XL. The outer XL piece is exposed first.'],
            ['label' => 'Committed selection', 'text' => 'Selecting an exposed reserve piece or one of your visible board pieces commits it. It must be played and cannot be exchanged for another piece.'],
            ['label' => 'A turn', 'text' => 'Play the exposed piece from one reserve stack to an empty cell, or move one of your visible board pieces to an empty cell or over any smaller visible piece.'],
            ['label' => 'Reserve covering rule', 'text' => $settings['reserveCoveringRule'] === NESTED_FOUR_RESERVE_COVER_CUSTOM
                ? 'Custom covering rule is active: a larger reserve piece may cover any smaller visible opposing piece at any time. Board-origin pieces may still cover any smaller visible piece.'
                : 'Original Gobblet rule is active: a reserve piece normally enters on an empty cell, except a larger reserve piece may cover one smaller opposing piece when the opponent has three visible pieces in a winning line. Board-origin pieces may still cover any smaller visible piece.'],
            ['label' => 'Memory and revealing', 'text' => 'Covered pieces are completely hidden. Moving a covering piece reveals the next piece underneath. A revealed opposing four must be blocked with the committed piece or the opponent wins.'],
            ['label' => 'Winning and draws', 'text' => 'Four visible pieces in a horizontal, vertical, or diagonal line wins. A full position repeated three times is a draw; the shared controls also support an agreed ending.'],
            ['label' => 'Display choices', 'text' => 'Piece labels, legal-move visibility, Sound FX, Visual FX, and board size are presentation aids only and never reveal a covered piece.'],
        ],
    ];
}

function nested_four_piece(int $userId, int $reserveIndex, int $size): array
{
    return [
        'id' => sprintf('p%d-r%d-s%d', $userId, $reserveIndex, $size),
        'ownerUserId' => $userId,
        'size' => $size,
        'label' => NESTED_FOUR_SIZES[$size],
    ];
}

function nested_four_stack_top(array $stack): ?array
{
    if ($stack === []) {
        return null;
    }
    $piece = $stack[array_key_last($stack)];
    return is_array($piece) ? $piece : null;
}

function nested_four_sanitize_piece(?array $piece): ?array
{
    if ($piece === null) {
        return null;
    }
    $size = (int)($piece['size'] ?? -1);
    if ($size < 0 || $size >= count(NESTED_FOUR_SIZES)) {
        return null;
    }
    return [
        'ownerUserId' => (int)($piece['ownerUserId'] ?? 0),
        'size' => $size,
        'label' => NESTED_FOUR_SIZES[$size],
    ];
}

function nested_four_lines(): array
{
    return [
        [0, 1, 2, 3], [4, 5, 6, 7], [8, 9, 10, 11], [12, 13, 14, 15],
        [0, 4, 8, 12], [1, 5, 9, 13], [2, 6, 10, 14], [3, 7, 11, 15],
        [0, 5, 10, 15], [3, 6, 9, 12],
    ];
}

function nested_four_visible_piece(array $state, int $cell): ?array
{
    return nested_four_stack_top((array)($state['board'][$cell] ?? []));
}

function nested_four_visible_owner(array $state, int $cell): int
{
    return (int)(nested_four_visible_piece($state, $cell)['ownerUserId'] ?? 0);
}

function nested_four_winning_lines_for_user(array $state, int $userId): array
{
    $wins = [];
    foreach (nested_four_lines() as $line) {
        $complete = true;
        foreach ($line as $cell) {
            if (nested_four_visible_owner($state, $cell) !== $userId) {
                $complete = false;
                break;
            }
        }
        if ($complete) {
            $wins[] = $line;
        }
    }
    return $wins;
}

function nested_four_other_user(array $state, int $userId): int
{
    foreach (array_map('intval', (array)($state['turnOrder'] ?? [])) as $candidate) {
        if ($candidate !== $userId) {
            return $candidate;
        }
    }
    return 0;
}

function nested_four_reserve_covering_rule(array $state): string
{
    $rule = (string)($state['reserveCoveringRule'] ?? (($state['settings'] ?? [])['reserveCoveringRule'] ?? NESTED_FOUR_RESERVE_COVER_ORIGINAL));
    return $rule === NESTED_FOUR_RESERVE_COVER_CUSTOM ? NESTED_FOUR_RESERVE_COVER_CUSTOM : NESTED_FOUR_RESERVE_COVER_ORIGINAL;
}

function nested_four_cover_allowed(array $state, string $sourceKind, int $actorUserId, int $sourceSize, int $targetOwnerUserId, int $targetSize, int $destination, array $originalReserveThreatTargets): bool
{
    if ($targetSize >= $sourceSize) return false;
    if ($sourceKind === 'board') return true;
    if ($sourceKind !== 'reserve' || $targetOwnerUserId === $actorUserId) return false;
    if (nested_four_reserve_covering_rule($state) === NESTED_FOUR_RESERVE_COVER_CUSTOM) return true;
    return isset($originalReserveThreatTargets[$destination]) || in_array($destination, $originalReserveThreatTargets, true);
}
function nested_four_threat_cover_targets(array $state, int $actorUserId): array
{
    if (nested_four_reserve_covering_rule($state) === NESTED_FOUR_RESERVE_COVER_CUSTOM) {
        return array_fill(0, NESTED_FOUR_BOARD_CELLS, true);
    }
    $opponentUserId = nested_four_other_user($state, $actorUserId);
    $targets = [];
    foreach (nested_four_lines() as $line) {
        $opponentCells = [];
        foreach ($line as $cell) {
            if (nested_four_visible_owner($state, $cell) === $opponentUserId) {
                $opponentCells[] = $cell;
            }
        }
        if (count($opponentCells) === 3) {
            foreach ($opponentCells as $cell) {
                $targets[$cell] = true;
            }
        }
    }
    return $targets;
}

function nested_four_position_key(array $state): string
{
    $board = [];
    foreach ((array)($state['board'] ?? []) as $cell => $stack) {
        $board[(string)$cell] = array_values(array_map(
            static fn(array $piece): string => (string)($piece['id'] ?? ''),
            (array)$stack
        ));
    }
    $reserves = [];
    foreach ((array)($state['reserves'] ?? []) as $userId => $groups) {
        $reserves[(string)$userId] = [];
        foreach ((array)$groups as $group) {
            $reserves[(string)$userId][] = array_values(array_map(
                static fn(array $piece): string => (string)($piece['id'] ?? ''),
                (array)$group
            ));
        }
    }
    return hash('sha256', json_encode([
        'board' => $board,
        'reserves' => $reserves,
        'turnOrder' => array_values((array)($state['turnOrder'] ?? [])),
        'turnIndex' => (int)($state['turnIndex'] ?? 0),
    ], JSON_UNESCAPED_SLASHES));
}

function nested_four_initial_state(array $playerUserIds, array $context = []): array
{
    $players = array_values(array_unique(array_map('intval', $playerUserIds)));
    $mode=(string)($context['mode'] ?? 'practice');
    $settings=nested_four_validate_settings((array)($context['settings'] ?? []),$mode);
    $bots=[];
    if (count($players)===1 && $players[0]>0 && ($settings['botSeat2Difficulty'] ?? 'none')!=='none' && $mode==='practice') {
        $level=$settings['botSeat2Difficulty'];$players[]=-6902;
        $bots['-6902']=['userId'=>-6902,'seat'=>2,'difficulty'=>$level,'displayName'=>ucfirst($level).' Bot','engine'=>NESTED_FOUR_BOT_ENGINE];
    }
    if (count($players) !== 2 || ($bots===[] && min($players) < 1)) {
        throw new MultiplayerGameException('Nested Four requires exactly two authenticated players.', 'NESTED_FOUR_PLAYER_SET_INVALID', 422);
    }
    nested_four_validate_settings((array)($context['settings'] ?? []), (string)($context['mode'] ?? 'practice'));
    $reserves = [];
    foreach ($players as $userId) {
        $reserves[(string)$userId] = [];
        for ($reserveIndex = 0; $reserveIndex < NESTED_FOUR_RESERVE_STACKS; $reserveIndex++) {
            $stack = [];
            for ($size = 0; $size < count(NESTED_FOUR_SIZES); $size++) {
                $stack[] = nested_four_piece($userId, $reserveIndex, $size);
            }
            $reserves[(string)$userId][] = $stack;
        }
    }
    $state = [
        'schemaVersion' => NESTED_FOUR_STATE_SCHEMA_VERSION,
        'bots'=>$bots, 'settings'=>$settings,
        'reserveCoveringRule' => ((string)(($context['settings'] ?? [])['reserveCoveringRule'] ?? NESTED_FOUR_RESERVE_COVER_ORIGINAL) === NESTED_FOUR_RESERVE_COVER_CUSTOM ? NESTED_FOUR_RESERVE_COVER_CUSTOM : NESTED_FOUR_RESERVE_COVER_ORIGINAL),
        'turnOrder' => $players,
        'turnIndex' => 0,
        'phase' => 'playing',
        'board' => array_fill(0, NESTED_FOUR_BOARD_CELLS, []),
        'reserves' => $reserves,
        'selected' => null,
        'moveNumber' => 0,
        'actionSequence' => 0,
        'lastAction' => null,
        'positionCounts' => [],
        'starterUserId' => $players[0],
        'starterReason' => 'The first accepted seat begins; rematches rotate the starter.',
        'completed' => false,
        'winnerUserId' => null,
        'terminalReason' => null,
    ];
    $state['positionCounts'][nested_four_position_key($state)] = 1;
    if ($bots!==[]) $state['botMemory']=nested_four_memory_initial($players);
    return $state;
}

function nested_four_turn_user(array $state): int
{
    $order = array_values(array_map('intval', (array)($state['turnOrder'] ?? [])));
    $index = (int)($state['turnIndex'] ?? -1);
    return isset($order[$index]) ? $order[$index] : 0;
}

function nested_four_prepare_selection(array $state, int $actorUserId, string $sourceType, int $sourceIndex): array
{
    if ($sourceType === 'reserve') {
        if ($sourceIndex < 0 || $sourceIndex >= NESTED_FOUR_RESERVE_STACKS) {
            throw new MultiplayerGameException('Choose a valid reserve stack.', 'NESTED_FOUR_SOURCE_INVALID', 422);
        }
        $reserves = (array)($state['reserves'] ?? []);
        $groups = (array)($reserves[(string)$actorUserId] ?? []);
        $stack = (array)($groups[$sourceIndex] ?? []);
        $piece = array_pop($stack);
        if (!is_array($piece)) {
            throw new MultiplayerGameException('That reserve stack is empty.', 'NESTED_FOUR_SOURCE_INVALID', 422);
        }
        $groups[$sourceIndex] = $stack;
        $reserves[(string)$actorUserId] = $groups;
        $state['reserves'] = $reserves;
    } elseif ($sourceType === 'board') {
        if ($sourceIndex < 0 || $sourceIndex >= NESTED_FOUR_BOARD_CELLS) {
            throw new MultiplayerGameException('Choose a valid board piece.', 'NESTED_FOUR_SOURCE_INVALID', 422);
        }
        $board = (array)($state['board'] ?? []);
        $stack = (array)($board[$sourceIndex] ?? []);
        $piece = nested_four_stack_top($stack);
        if ($piece === null || (int)($piece['ownerUserId'] ?? 0) !== $actorUserId) {
            throw new MultiplayerGameException('Choose one of your visible pieces.', 'NESTED_FOUR_SOURCE_INVALID', 422);
        }
        array_pop($stack);
        $board[$sourceIndex] = $stack;
        $state['board'] = $board;
    } else {
        throw new MultiplayerGameException('Choose a reserve or board piece.', 'NESTED_FOUR_SOURCE_INVALID', 422);
    }
    $state['selected'] = [
        'userId' => $actorUserId,
        'sourceType' => $sourceType,
        'sourceIndex' => $sourceIndex,
        'piece' => $piece,
    ];
    return [
        'state' => $state,
        'legalDestinations' => nested_four_legal_destinations_for_selected($state, $actorUserId),
    ];
}

function nested_four_legal_destinations_for_selected(array $state, int $actorUserId): array
{
    $selected = (array)($state['selected'] ?? []);
    $piece = (array)($selected['piece'] ?? []);
    if ((int)($selected['userId'] ?? 0) !== $actorUserId || $piece === []) {
        return [];
    }
    $pieceSize = (int)($piece['size'] ?? -1);
    $sourceType = (string)($selected['sourceType'] ?? '');
    $sourceIndex = (int)($selected['sourceIndex'] ?? -1);
    $opponentUserId = nested_four_other_user($state, $actorUserId);
    $opponentAlreadyWinning = nested_four_winning_lines_for_user($state, $opponentUserId) !== [];
    $reserveThreatTargets = $sourceType === 'reserve'
        ? nested_four_threat_cover_targets($state, $actorUserId)
        : [];
    $legal = [];
    for ($cell = 0; $cell < NESTED_FOUR_BOARD_CELLS; $cell++) {
        if ($sourceType === 'board' && $cell === $sourceIndex) {
            continue;
        }
        $top = nested_four_visible_piece($state, $cell);
        if ($top === null) {
            $allowed = true;
        } elseif ((int)($top['size'] ?? 99) >= $pieceSize) {
            $allowed = false;
        } else {
            $allowed = nested_four_cover_allowed(
                $state,
                $sourceType,
                $actorUserId,
                $pieceSize,
                (int)($top['ownerUserId'] ?? 0),
                (int)($top['size'] ?? 99),
                $cell,
                $reserveThreatTargets
            );
        }
        if (!$allowed) {
            continue;
        }
        if ($opponentAlreadyWinning) {
            $simulated = $state;
            $simulated['board'][$cell][] = $piece;
            if (nested_four_winning_lines_for_user($simulated, $opponentUserId) !== []) {
                continue;
            }
        }
        $legal[$cell] = true;
    }
    return $legal;
}

function nested_four_legal_sources(array $state, int $actorUserId): array
{
    if (!empty($state['completed'])
        || (string)($state['phase'] ?? '') !== 'playing'
        || nested_four_turn_user($state) !== $actorUserId
        || !empty($state['selected'])) {
        return [];
    }
    $sources = [];
    foreach ((array)($state['reserves'][(string)$actorUserId] ?? []) as $index => $stack) {
        $piece = nested_four_stack_top((array)$stack);
        if ($piece === null) {
            continue;
        }
        $prepared = nested_four_prepare_selection($state, $actorUserId, 'reserve', (int)$index);
        $sources['reserve:' . $index] = [
            'sourceType' => 'reserve',
            'sourceIndex' => (int)$index,
            'piece' => nested_four_sanitize_piece($piece),
            'legalDestinationCount' => count((array)$prepared['legalDestinations']),
        ];
    }
    for ($cell = 0; $cell < NESTED_FOUR_BOARD_CELLS; $cell++) {
        $piece = nested_four_visible_piece($state, $cell);
        if ($piece === null || (int)($piece['ownerUserId'] ?? 0) !== $actorUserId) {
            continue;
        }
        $prepared = nested_four_prepare_selection($state, $actorUserId, 'board', $cell);
        $sources['board:' . $cell] = [
            'sourceType' => 'board',
            'sourceIndex' => $cell,
            'piece' => nested_four_sanitize_piece($piece),
            'legalDestinationCount' => count((array)$prepared['legalDestinations']),
        ];
    }
    return $sources;
}

function nested_four_context_players(array $context): array
{
    return array_values(array_unique(array_filter(array_map(
        static fn(array $member): int => in_array((string)($member['role'] ?? ''), ['master', 'player'], true)
            && (string)($member['membershipStatus'] ?? '') === 'active'
                ? (int)($member['userId'] ?? 0)
                : 0,
        (array)($context['members'] ?? [])
    ))));
}

function nested_four_project_state(...$arguments): array
{
    $projection = nested_four_project_state_legacy(...$arguments);
    $state = is_array($arguments[0] ?? null) ? $arguments[0] : [];
    $projection['reserveCoveringRule'] = nested_four_reserve_covering_rule($state);
    $projection['botTask']=nested_four_bot_task($state,(int)($arguments[1] ?? 0),(array)($arguments[2] ?? []));
    return $projection;
}
function nested_four_project_state_legacy(array $state, int $viewerUserId, array $context): array
{
    $players = nested_four_context_players($context);
    if ((int)($state['schemaVersion'] ?? 0) !== NESTED_FOUR_STATE_SCHEMA_VERSION) {
        if (count($players) === 2) {
            $state = nested_four_initial_state($players, $context);
            $state['phase'] = 'lobby';
        } else {
            $state = [
                'schemaVersion' => NESTED_FOUR_STATE_SCHEMA_VERSION,
                'turnOrder' => $players,
                'turnIndex' => 0,
                'phase' => 'lobby',
                'board' => array_fill(0, NESTED_FOUR_BOARD_CELLS, []),
                'reserves' => [],
                'selected' => null,
                'moveNumber' => 0,
                'actionSequence' => 0,
                'lastAction' => null,
                'completed' => false,
                'winnerUserId' => null,
                'terminalReason' => null,
            ];
        }
    }
    $projection = $state;
    unset($projection['board'], $projection['reserves'], $projection['positionCounts'], $projection['botMemory']);
    $projection['board'] = [];
    for ($cell = 0; $cell < NESTED_FOUR_BOARD_CELLS; $cell++) {
        $piece = nested_four_sanitize_piece(nested_four_visible_piece($state, $cell));
        if ($piece !== null) {
            $projection['board'][(string)$cell] = $piece;
        }
    }
    $projection['reserves'] = [];
    foreach (array_map('intval', (array)($state['turnOrder'] ?? [])) as $userId) {
        $projection['reserves'][(string)$userId] = [];
        foreach ((array)($state['reserves'][(string)$userId] ?? []) as $index => $stack) {
            $projection['reserves'][(string)$userId][] = [
                'stackIndex' => (int)$index,
                'remaining' => count((array)$stack),
                'piece' => nested_four_sanitize_piece(nested_four_stack_top((array)$stack)),
            ];
        }
    }
    $selected = (array)($state['selected'] ?? []);
    $projection['selected'] = $selected === [] ? null : [
        'userId' => (int)($selected['userId'] ?? 0),
        'sourceType' => (string)($selected['sourceType'] ?? ''),
        'sourceIndex' => (int)($selected['sourceIndex'] ?? -1),
        'piece' => nested_four_sanitize_piece((array)($selected['piece'] ?? [])),
        'committed' => true,
    ];
    $projection['legalSources'] = nested_four_legal_sources($state, $viewerUserId);
    $projection['legalDestinations'] = [];
    if ((int)($selected['userId'] ?? 0) === $viewerUserId) {
        $projection['legalDestinations'] = array_map(
            'intval',
            array_keys(nested_four_legal_destinations_for_selected($state, $viewerUserId))
        );
    }
    $projection['pieceSizes'] = NESTED_FOUR_SIZES;
    $projection['boardDimensions'] = ['rows' => 4, 'columns' => 4];
    $projection['hiddenProjection'] = true;
    $projection['legalActions'] = [];
    if (in_array($viewerUserId, array_map('intval', (array)($state['turnOrder'] ?? [])), true)
        && empty($state['completed'])
        && (string)($state['phase'] ?? '') === 'playing') {
        $projection['legalActions'][] = 'resign';
        if (nested_four_turn_user($state) === $viewerUserId) {
            $projection['legalActions'][] = empty($state['selected']) ? 'select' : 'move';
        }
    }
    return $projection;
}

function nested_four_result(array $state, ?int $winnerUserId): array
{
    $scores = [];
    foreach (array_map('intval', (array)($state['turnOrder'] ?? [])) as $userId) {
        $scores[(string)$userId] = $winnerUserId !== null && $userId === $winnerUserId ? 1 : 0;
    }
    return ocx_game_result_from_scores($scores);
}

function nested_four_finish(array $state, ?int $winnerUserId, string $reason): array
{
    $state['completed'] = true;
    $state['phase'] = 'completed';
    $state['winnerUserId'] = $winnerUserId;
    $state['terminalReason'] = $reason;
    return [
        'state' => $state,
        'turnUserId' => null,
        'terminal' => true,
        'result' => nested_four_result($state, $winnerUserId),
    ];
}

function nested_four_apply_action_core(array $state, int $actorUserId, string $action, array $payload, array $context): array
{
    if ((int)($state['schemaVersion'] ?? 0) !== NESTED_FOUR_STATE_SCHEMA_VERSION
        || !empty($state['completed'])
        || (string)($state['phase'] ?? '') !== 'playing') {
        throw new MultiplayerGameException('The Nested Four state is unavailable.', 'NESTED_FOUR_STATE_INVALID', 409);
    }
    $turnOrder = array_map('intval', (array)($state['turnOrder'] ?? []));
    if (!in_array($actorUserId, $turnOrder, true)) {
        throw new MultiplayerGameException('Only a player may act.', 'NESTED_FOUR_PLAYER_INVALID', 403);
    }
    if ($action === 'resign') {
        $winnerUserId = nested_four_other_user($state, $actorUserId);
        $state['actionSequence'] = (int)($state['actionSequence'] ?? 0) + 1;
        $state['lastAction'] = ['sequence' => $state['actionSequence'], 'type' => 'resign', 'userId' => $actorUserId];
        return nested_four_finish($state, $winnerUserId, 'resignation');
    }
    ocx_game_assert_turn($state, $actorUserId);
    if ($action === 'select') {
        if (!empty($state['selected'])) {
            throw new MultiplayerGameException('The selected piece is committed and must be played.', 'NESTED_FOUR_SELECTION_COMMITTED', 409);
        }
        $sourceType = strtolower(trim((string)($payload['sourceType'] ?? '')));
        $sourceIndex = filter_var($payload['sourceIndex'] ?? null, FILTER_VALIDATE_INT);
        if ($sourceIndex === false) {
            throw new MultiplayerGameException('Choose a valid piece.', 'NESTED_FOUR_SOURCE_INVALID', 422);
        }
        $prepared = nested_four_prepare_selection($state, $actorUserId, $sourceType, (int)$sourceIndex);
        $state = (array)$prepared['state'];
        $piece = (array)($state['selected']['piece'] ?? []);
        $state['actionSequence'] = (int)($state['actionSequence'] ?? 0) + 1;
        $state['lastAction'] = [
            'sequence' => $state['actionSequence'],
            'type' => 'select',
            'userId' => $actorUserId,
            'sourceType' => $sourceType,
            'sourceIndex' => (int)$sourceIndex,
            'pieceSize' => (int)($piece['size'] ?? -1),
            'pieceLabel' => (string)($piece['label'] ?? ''),
        ];
        if ((array)$prepared['legalDestinations'] === []) {
            $state['lastAction']['type'] = 'select-loss';
            return nested_four_finish($state, nested_four_other_user($state, $actorUserId), 'touched-piece-unplayable');
        }
        return ['state' => $state, 'turnUserId' => $actorUserId];
    }
    if ($action !== 'move') {
        throw new MultiplayerGameException('This Nested Four action is not supported.', 'NESTED_FOUR_ACTION_INVALID', 422);
    }
    $selected = (array)($state['selected'] ?? []);
    if ((int)($selected['userId'] ?? 0) !== $actorUserId) {
        throw new MultiplayerGameException('Select a piece before choosing its destination.', 'NESTED_FOUR_SELECTION_REQUIRED', 409);
    }
    $destination = filter_var($payload['destination'] ?? null, FILTER_VALIDATE_INT);
    $legalDestinations = nested_four_legal_destinations_for_selected($state, $actorUserId);
    if ($destination === false || !isset($legalDestinations[(int)$destination])) {
        throw new MultiplayerGameException('The committed piece must be played on a highlighted legal cell.', 'NESTED_FOUR_MOVE_INVALID', 422);
    }
    $destination = (int)$destination;
    $piece = (array)($selected['piece'] ?? []);
    $covered = nested_four_visible_piece($state, $destination) !== null;
    $state['board'][$destination][] = $piece;
    $state['selected'] = null;
    $state['moveNumber'] = (int)($state['moveNumber'] ?? 0) + 1;
    $state['actionSequence'] = (int)($state['actionSequence'] ?? 0) + 1;
    $state['lastAction'] = [
        'sequence' => $state['actionSequence'],
        'type' => 'move',
        'userId' => $actorUserId,
        'sourceType' => (string)($selected['sourceType'] ?? ''),
        'sourceIndex' => (int)($selected['sourceIndex'] ?? -1),
        'destination' => $destination,
        'pieceSize' => (int)($piece['size'] ?? -1),
        'pieceLabel' => (string)($piece['label'] ?? ''),
        'covered' => $covered,
    ];
    if (nested_four_winning_lines_for_user($state, $actorUserId) !== []) {
        return nested_four_finish($state, $actorUserId, 'four-in-a-row');
    }
    $opponentUserId = nested_four_other_user($state, $actorUserId);
    if (nested_four_winning_lines_for_user($state, $opponentUserId) !== []) {
        return nested_four_finish($state, $opponentUserId, 'uncovered-four-in-a-row');
    }
    $nextUserId = ocx_game_advance_turn($state);
    $positionKey = nested_four_position_key($state);
    $state['positionCounts'][$positionKey] = (int)($state['positionCounts'][$positionKey] ?? 0) + 1;
    if ((int)$state['positionCounts'][$positionKey] >= 3) {
        return nested_four_finish($state, null, 'threefold-repetition');
    }
    return ['state' => $state, 'turnUserId' => $nextUserId];
}
