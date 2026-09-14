<?php
declare(strict_types=1);

require_once __DIR__ . '/ocx_game_extension_support.php';

const CHINESE_CHECKERS_EXTENSION_ID = 'chinese-checkers';
const CHINESE_CHECKERS_STATE_SCHEMA_VERSION = 1;
const CHINESE_CHECKERS_PIECES_PER_PLAYER = 10;

function chinese_checkers_recording_adapter(): array
{
    return ['schemaVersion' => 1,
        'stateKeys' => ['playerCount', 'homeByUser', 'actionSequence', 'board', 'completed', 'history', 'lastAction', 'moveNumber', 'phase', 'resignedUserId', 'roundNumber', 'schemaVersion', 'settings', 'starterIndex', 'starterReason', 'starterUserId', 'targetByUser', 'terminalReason', 'turnIndex', 'turnOrder', 'winnerUserId'],
        'payloadKeys' => ['from', 'to']];
}

function chinese_checkers_extension_adapter(): array
{
    return [
        'recordingAdapter' => 'chinese_checkers_recording_adapter',
        'id' => CHINESE_CHECKERS_EXTENSION_ID,
        'initialState' => 'chinese_checkers_initial_state',
        'applyAction' => 'chinese_checkers_apply_action',
        'validateSettings' => 'chinese_checkers_validate_settings',
        'settingsProjection' => 'chinese_checkers_settings_projection',
        'rulesProjection' => 'chinese_checkers_rules_projection',
        'projectState' => 'chinese_checkers_project_state',
        'presentationStatus' => 'chinese_checkers_presentation_status',
        'openingProcedure' => 'fixed-star-seats-first-player-starts',
        'rematchSeatRotation' => true,
    ];
}

function chinese_checkers_presentation_status(PDO $pdo, ?string $requestedPack = null): array
{
    $requested = strtolower(trim((string)$requestedPack));
    if (!in_array($requested, ['enamel', 'wood', 'glass'], true)) {
        $requested = 'enamel';
    }
    return [
        'requestedPack' => $requested,
        'effectivePack' => $requested,
        'classicAvailable' => false,
        'fallbackApplied' => false,
        'presentationOnly' => true,
        'mediaPack' => [
            'publicCodeNative' => true,
            'geometrySharedAcrossPacks' => true,
            'remoteMedia' => false,
        ],
    ];
}

function chinese_checkers_validate_settings(array $settings, string $mode, array $definition = []): array
{
    if ($settings === []) {
        return [];
    }

    $allowed = ['inactivityProfile', 'customInactivitySeconds'];
    if (array_diff(array_keys($settings), $allowed)) {
        throw new MultiplayerGameException(
            'A Chinese Checkers setting is not supported.',
            'CHINESE_CHECKERS_SETTINGS_INVALID',
            422
        );
    }

    $profile = strtolower(trim((string)($settings['inactivityProfile'] ?? 'unlimited')));
    if (!in_array($profile, ['quick', 'default', 'relaxed', 'custom', 'unlimited'], true)) {
        throw new MultiplayerGameException(
            'Choose a published inactivity protection profile.',
            'CHINESE_CHECKERS_SETTINGS_INVALID',
            422
        );
    }

    $customSeconds = filter_var(
        $settings['customInactivitySeconds'] ?? 120,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 15, 'max_range' => 3600]]
    );
    if ($customSeconds === false) {
        throw new MultiplayerGameException(
            'Custom inactivity protection must be from 15 through 3600 seconds.',
            'CHINESE_CHECKERS_SETTINGS_INVALID',
            422
        );
    }

    return [
        'inactivityProfile' => $profile,
        'customInactivitySeconds' => (int)$customSeconds,
    ];
}

function chinese_checkers_settings_projection(array $settings, string $mode, array $definition = []): array
{
    chinese_checkers_validate_settings($settings, $mode, $definition);
    return [
        'label' => 'Game Options',
        'description' => 'Standard Chinese Checkers for two through six authenticated human players.',
        'classificationLabel' => 'Accepted Chinese Checkers options',
        'controls' => [],
    ];
}

function chinese_checkers_rules_projection(array $settings, string $mode, array $definition = []): array
{
    chinese_checkers_validate_settings($settings, $mode, $definition);
    return [
        'label' => 'Chinese Checkers rules',
        'description' => 'Move all ten marbles from your home triangle into the opposite destination triangle.',
        'sections' => [
            [
                'label' => 'Players and setup',
                'text' => 'Two through six authenticated human players receive ten marbles in fixed star points. There are no bots, captures, cards, dice, or hidden information.',
            ],
            [
                'label' => 'A turn',
                'text' => 'Move one marble to an adjacent empty hole, or jump over an adjacent occupied hole into the empty hole directly beyond it. A jump turn may continue through multiple legal jumps with the same marble.',
            ],
            [
                'label' => 'Legal routes',
                'text' => 'Select one of your marbles to review legal destinations. A route line previews a multi-jump. The server validates the complete route again before applying the move.',
            ],
            [
                'label' => 'Destination triangle',
                'text' => 'After a marble enters its opposite destination triangle, it may move within that triangle but may not leave it.',
            ],
            [
                'label' => 'Winning',
                'text' => 'The first player with all ten marbles in the opposite destination triangle wins the match.',
            ],
            [
                'label' => 'Display choices',
                'text' => 'Enamel, Wood, Glass, legal-move visibility, sound, visual effects, and board size are viewer-local. They never change shared rules or another player\'s view.',
            ],
        ],
    ];
}

function chinese_checkers_hole_geometry(): array
{
    static $geometry = null;
    if (is_array($geometry)) {
        return $geometry;
    }
    $rowCounts = [1, 2, 3, 4, 13, 12, 11, 10, 9, 10, 11, 12, 13, 4, 3, 2, 1];
    $holes = [];
    $byCoordinate = [];
    foreach ($rowCounts as $row => $count) {
        for ($index = 0; $index < $count; $index++) {
            $id = sprintf('r%02dc%02d', $row, $index);
            $x2 = -($count - 1) + (2 * $index);
            $holes[$id] = ['id' => $id, 'row' => $row, 'index' => $index, 'x2' => $x2];
            $byCoordinate[$row . ':' . $x2] = $id;
        }
    }
    $geometry = ['holes' => $holes, 'byCoordinate' => $byCoordinate];
    return $geometry;
}

function chinese_checkers_row_holes(int $row): array
{
    return array_values(array_filter(
        chinese_checkers_hole_geometry()['holes'],
        static fn(array $hole): bool => (int)$hole['row'] === $row
    ));
}

function chinese_checkers_arm_holes(): array
{
    static $arms = null;
    if (is_array($arms)) {
        return $arms;
    }
    $arms = [
        'top' => [],
        'upper-right' => [],
        'lower-right' => [],
        'bottom' => [],
        'lower-left' => [],
        'upper-left' => [],
    ];
    for ($row = 0; $row <= 3; $row++) {
        foreach (chinese_checkers_row_holes($row) as $hole) {
            $arms['top'][] = $hole['id'];
        }
    }
    for ($row = 13; $row <= 16; $row++) {
        foreach (chinese_checkers_row_holes($row) as $hole) {
            $arms['bottom'][] = $hole['id'];
        }
    }
    for ($row = 4; $row <= 7; $row++) {
        $rowHoles = chinese_checkers_row_holes($row);
        $take = 8 - $row;
        $arms['upper-left'] = array_merge($arms['upper-left'], array_column(array_slice($rowHoles, 0, $take), 'id'));
        $arms['upper-right'] = array_merge($arms['upper-right'], array_column(array_slice($rowHoles, -$take), 'id'));
    }
    for ($row = 9; $row <= 12; $row++) {
        $rowHoles = chinese_checkers_row_holes($row);
        $take = $row - 8;
        $arms['lower-left'] = array_merge($arms['lower-left'], array_column(array_slice($rowHoles, 0, $take), 'id'));
        $arms['lower-right'] = array_merge($arms['lower-right'], array_column(array_slice($rowHoles, -$take), 'id'));
    }
    return $arms;
}

function chinese_checkers_opposite_arm(string $arm): string
{
    $opposites = [
        'top' => 'bottom',
        'upper-right' => 'lower-left',
        'lower-right' => 'upper-left',
        'bottom' => 'top',
        'lower-left' => 'upper-right',
        'upper-left' => 'lower-right',
    ];
    if (!isset($opposites[$arm])) {
        throw new MultiplayerGameException('The Chinese Checkers seat is invalid.', 'CHINESE_CHECKERS_SEAT_INVALID', 500);
    }
    return $opposites[$arm];
}

function chinese_checkers_player_arms(int $playerCount): array
{
    $layouts = [
        2 => ['top', 'bottom'],
        3 => ['top', 'lower-right', 'lower-left'],
        4 => ['upper-right', 'lower-right', 'lower-left', 'upper-left'],
        5 => ['top', 'upper-right', 'lower-right', 'lower-left', 'upper-left'],
        6 => ['top', 'upper-right', 'lower-right', 'bottom', 'lower-left', 'upper-left'],
    ];
    if (!isset($layouts[$playerCount])) {
        throw new MultiplayerGameException(
            'Chinese Checkers requires two through six authenticated players.',
            'CHINESE_CHECKERS_PLAYER_SET_INVALID',
            422
        );
    }
    return $layouts[$playerCount];
}

function chinese_checkers_initial_state(array $playerUserIds, array $context = []): array
{
    $players = array_values(array_unique(array_map('intval', $playerUserIds)));
    if (count($players) < 2 || count($players) > 6 || min($players) < 1) {
        throw new MultiplayerGameException(
            'Chinese Checkers requires two through six authenticated players.',
            'CHINESE_CHECKERS_PLAYER_SET_INVALID',
            422
        );
    }
    chinese_checkers_validate_settings((array)($context['settings'] ?? []), (string)($context['mode'] ?? 'practice'));
    $arms = chinese_checkers_player_arms(count($players));
    $armHoles = chinese_checkers_arm_holes();
    $board = [];
    $homeByUser = [];
    $targetByUser = [];
    foreach ($players as $index => $userId) {
        $arm = $arms[$index];
        $homeByUser[(string)$userId] = $arm;
        $targetByUser[(string)$userId] = chinese_checkers_opposite_arm($arm);
        foreach ($armHoles[$arm] as $holeId) {
            $board[$holeId] = $userId;
        }
    }
    return [
        'schemaVersion' => CHINESE_CHECKERS_STATE_SCHEMA_VERSION,
        'playerCount' => count($players),
        'turnOrder' => $players,
        'turnIndex' => 0,
        'phase' => 'playing',
        'board' => $board,
        'homeByUser' => $homeByUser,
        'targetByUser' => $targetByUser,
        'moveNumber' => 0,
        'actionSequence' => 0,
        'lastAction' => null,
        'history' => [],
        'starterUserId' => $players[0],
        'starterReason' => 'The first accepted seat begins; rematches rotate the starter.',
        'completed' => false,
        'winnerUserId' => null,
        'terminalReason' => null,
    ];
}

function chinese_checkers_turn_user(array $state): int
{
    $order = array_values(array_map('intval', (array)($state['turnOrder'] ?? [])));
    $index = (int)($state['turnIndex'] ?? -1);
    return isset($order[$index]) ? $order[$index] : 0;
}

function chinese_checkers_target_holes(array $state, int $userId): array
{
    $arm = (string)($state['targetByUser'][(string)$userId] ?? '');
    return array_values((array)(chinese_checkers_arm_holes()[$arm] ?? []));
}

function chinese_checkers_coordinate_neighbor(string $holeId, int $rowDelta, int $x2Delta): ?string
{
    $geometry = chinese_checkers_hole_geometry();
    $hole = $geometry['holes'][$holeId] ?? null;
    if (!is_array($hole)) {
        return null;
    }
    $key = ((int)$hole['row'] + $rowDelta) . ':' . ((int)$hole['x2'] + $x2Delta);
    return isset($geometry['byCoordinate'][$key]) ? (string)$geometry['byCoordinate'][$key] : null;
}

function chinese_checkers_legal_moves_for_piece(array $state, int $actorUserId, string $from): array
{
    $board = (array)($state['board'] ?? []);
    if ((int)($board[$from] ?? 0) !== $actorUserId) {
        return [];
    }
    $targetLookup = array_fill_keys(chinese_checkers_target_holes($state, $actorUserId), true);
    $startsInTarget = isset($targetLookup[$from]);
    $directions = [[0, 2], [0, -2], [1, 1], [1, -1], [-1, 1], [-1, -1]];
    $moves = [];

    foreach ($directions as [$rowDelta, $x2Delta]) {
        $destination = chinese_checkers_coordinate_neighbor($from, $rowDelta, $x2Delta);
        if ($destination === null || isset($board[$destination])) {
            continue;
        }
        if ($startsInTarget && !isset($targetLookup[$destination])) {
            continue;
        }
        $moves[$destination] = ['kind' => 'step', 'path' => [$destination]];
    }

    $queue = [['current' => $from, 'path' => [], 'enteredTarget' => $startsInTarget]];
    $seen = [$from => true];
    while ($queue !== []) {
        $node = array_shift($queue);
        $current = (string)$node['current'];
        foreach ($directions as [$rowDelta, $x2Delta]) {
            $middle = chinese_checkers_coordinate_neighbor($current, $rowDelta, $x2Delta);
            $landing = chinese_checkers_coordinate_neighbor($current, $rowDelta * 2, $x2Delta * 2);
            if ($middle === null || $landing === null || isset($seen[$landing])) {
                continue;
            }
            $middleOccupied = $middle === $current
                || ($middle !== $from && isset($board[$middle]))
                || ($current === $from && $middle === $from);
            $landingOccupied = $landing === $current
                || ($landing !== $from && isset($board[$landing]));
            if (!$middleOccupied || $landingOccupied) {
                continue;
            }
            $enteredTarget = (bool)$node['enteredTarget'] || isset($targetLookup[$landing]);
            if (!empty($node['enteredTarget']) && !isset($targetLookup[$landing])) {
                continue;
            }
            $path = array_merge((array)$node['path'], [$landing]);
            $seen[$landing] = true;
            if (!isset($moves[$landing])) {
                $moves[$landing] = ['kind' => 'jump', 'path' => $path];
            }
            $queue[] = ['current' => $landing, 'path' => $path, 'enteredTarget' => $enteredTarget];
        }
    }
    ksort($moves);
    return $moves;
}

function chinese_checkers_legal_moves(array $state, int $actorUserId): array
{
    if (!empty($state['completed'])
        || (string)($state['phase'] ?? '') !== 'playing'
        || chinese_checkers_turn_user($state) !== $actorUserId) {
        return [];
    }
    $moves = [];
    foreach ((array)($state['board'] ?? []) as $holeId => $ownerUserId) {
        if ((int)$ownerUserId !== $actorUserId) {
            continue;
        }
        $pieceMoves = chinese_checkers_legal_moves_for_piece($state, $actorUserId, (string)$holeId);
        if ($pieceMoves !== []) {
            $moves[(string)$holeId] = $pieceMoves;
        }
    }
    return $moves;
}

function chinese_checkers_home_progress(array $state): array
{
    $progress = [];
    $board = (array)($state['board'] ?? []);
    foreach (array_map('intval', (array)($state['turnOrder'] ?? [])) as $userId) {
        $count = 0;
        foreach (chinese_checkers_target_holes($state, $userId) as $holeId) {
            if ((int)($board[$holeId] ?? 0) === $userId) {
                $count++;
            }
        }
        $progress[(string)$userId] = $count;
    }
    return $progress;
}

function chinese_checkers_project_state(array $state, int $viewerUserId, array $context): array
{
    $members = (array)($context['members'] ?? []);
    $players = array_values(array_unique(array_filter(array_map(
        static fn(array $member): int => in_array((string)($member['role'] ?? ''), ['master', 'player'], true)
            && (string)($member['membershipStatus'] ?? '') === 'active'
                ? (int)($member['userId'] ?? 0)
                : 0,
        $members
    ))));
    if ((int)($state['schemaVersion'] ?? 0) !== CHINESE_CHECKERS_STATE_SCHEMA_VERSION) {
        if (count($players) >= 2 && count($players) <= 6) {
            $state = chinese_checkers_initial_state($players, $context);
            $state['phase'] = 'lobby';
        } else {
            $state = [
                'schemaVersion' => CHINESE_CHECKERS_STATE_SCHEMA_VERSION,
                'playerCount' => count($players),
                'turnOrder' => $players,
                'turnIndex' => 0,
                'phase' => 'lobby',
                'board' => [],
                'homeByUser' => [],
                'targetByUser' => [],
                'moveNumber' => 0,
                'actionSequence' => 0,
                'lastAction' => null,
                'history' => [],
                'starterUserId' => (int)($players[0] ?? 0),
                'starterReason' => 'Waiting for two through six players and final acceptance.',
                'completed' => false,
                'winnerUserId' => null,
                'terminalReason' => null,
            ];
        }
    }
    $projection = $state;
    $projection['legalMoves'] = chinese_checkers_legal_moves($state, $viewerUserId);
    $projection['legalActions'] = [];
    if (in_array($viewerUserId, array_map('intval', (array)$state['turnOrder']), true)
        && empty($state['completed'])
        && (string)($state['phase'] ?? '') === 'playing') {
        $projection['legalActions'][] = 'resign';
        if (chinese_checkers_turn_user($state) === $viewerUserId
            && $projection['legalMoves'] !== []) {
            $projection['legalActions'][] = 'move';
        }
    }
    $projection['homeProgress'] = chinese_checkers_home_progress($state);
    $projection['piecesPerPlayer'] = CHINESE_CHECKERS_PIECES_PER_PLAYER;
    $projection['geometry'] = [
        'name' => 'standard-121-hole-star',
        'rows' => [1, 2, 3, 4, 13, 12, 11, 10, 9, 10, 11, 12, 13, 4, 3, 2, 1],
    ];
    return $projection;
}

function chinese_checkers_result(array $state, int $winnerUserId): array
{
    $scores = [];
    foreach (array_map('intval', (array)$state['turnOrder']) as $userId) {
        $scores[(string)$userId] = $userId === $winnerUserId ? 1 : 0;
    }
    return ocx_game_result_from_scores($scores);
}

function chinese_checkers_apply_action(
    array $state,
    int $actorUserId,
    string $action,
    array $payload,
    array $context
): array {
    if ((int)($state['schemaVersion'] ?? 0) !== CHINESE_CHECKERS_STATE_SCHEMA_VERSION
        || !empty($state['completed'])
        || (string)($state['phase'] ?? '') !== 'playing') {
        throw new MultiplayerGameException('The Chinese Checkers state is unavailable.', 'CHINESE_CHECKERS_STATE_INVALID', 409);
    }
    $turnOrder = array_map('intval', (array)($state['turnOrder'] ?? []));
    if (!in_array($actorUserId, $turnOrder, true)) {
        throw new MultiplayerGameException('Only a player may act.', 'CHINESE_CHECKERS_PLAYER_INVALID', 403);
    }
    if ($action === 'resign') {
        $scores = [];
        foreach ($turnOrder as $userId) {
            $scores[(string)$userId] = $userId === $actorUserId ? 0 : 1;
        }
        $state['completed'] = true;
        $state['phase'] = 'completed';
        $state['terminalReason'] = 'resignation';
        $state['actionSequence'] = (int)$state['actionSequence'] + 1;
        $state['lastAction'] = [
            'sequence' => $state['actionSequence'],
            'type' => 'resign',
            'userId' => $actorUserId,
        ];
        return [
            'state' => $state,
            'turnUserId' => null,
            'terminal' => true,
            'result' => ocx_game_result_from_scores($scores),
        ];
    }
    if ($action !== 'move') {
        throw new MultiplayerGameException('This Chinese Checkers action is not supported.', 'CHINESE_CHECKERS_ACTION_INVALID', 422);
    }
    ocx_game_assert_turn($state, $actorUserId);
    $from = trim((string)($payload['from'] ?? ''));
    $to = trim((string)($payload['to'] ?? ''));
    $geometry = chinese_checkers_hole_geometry()['holes'];
    if (!isset($geometry[$from], $geometry[$to]) || $from === $to) {
        throw new MultiplayerGameException('Choose a valid source and destination.', 'CHINESE_CHECKERS_MOVE_INVALID', 422);
    }
    $legal = chinese_checkers_legal_moves_for_piece($state, $actorUserId, $from);
    if (!isset($legal[$to])) {
        throw new MultiplayerGameException('That is not a legal Chinese Checkers move.', 'CHINESE_CHECKERS_MOVE_INVALID', 422);
    }
    $route = $legal[$to];
    unset($state['board'][$from]);
    $state['board'][$to] = $actorUserId;
    $state['moveNumber'] = (int)$state['moveNumber'] + 1;
    $state['actionSequence'] = (int)$state['actionSequence'] + 1;
    $state['lastAction'] = [
        'sequence' => $state['actionSequence'],
        'type' => 'move',
        'userId' => $actorUserId,
        'from' => $from,
        'to' => $to,
        'kind' => (string)$route['kind'],
        'path' => array_values((array)$route['path']),
        'jumpCount' => (string)$route['kind'] === 'jump' ? count((array)$route['path']) : 0,
    ];
    $state['history'][] = $state['lastAction'];
    $state['history'] = array_slice((array)$state['history'], -80);

    $progress = chinese_checkers_home_progress($state);
    if ((int)($progress[(string)$actorUserId] ?? 0) === CHINESE_CHECKERS_PIECES_PER_PLAYER) {
        $state['completed'] = true;
        $state['phase'] = 'completed';
        $state['winnerUserId'] = $actorUserId;
        $state['terminalReason'] = 'destination-complete';
        return [
            'state' => $state,
            'turnUserId' => null,
            'terminal' => true,
            'result' => chinese_checkers_result($state, $actorUserId),
        ];
    }
    $nextUserId = ocx_game_advance_turn($state);
    return ['state' => $state, 'turnUserId' => $nextUserId];
}
