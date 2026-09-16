<?php
declare(strict_types=1);

require_once __DIR__ . '/ocx_game_extension_support.php';
require_once __DIR__ . '/battleship_bot_support.php';

const BATTLESHIP_EXTENSION_ID = 'battleship';
const BATTLESHIP_STATE_SCHEMA_VERSION = 2;

function battleship_recording_adapter(): array
{
    return ['schemaVersion' => 1,
        'stateKeys' => ['attackHistory', 'bots', 'completed', 'fleets', 'headToHeadRecord', 'headToHeadRoundRecorded', 'lastPlacement', 'meaningfulPlay', 'phase', 'resignedUserId', 'roundNumber', 'schemaVersion', 'settings', 'starterIndex', 'starterReason', 'starterUserId', 'terminalReason', 'turnIndex', 'turnOrder', 'winnerUserId'],
        'payloadKeys' => ['column', 'operation', 'row', 'shipId', 'ships']];
}

function battleship_extension_adapter(): array
{
    return [
        'recordingAdapter' => 'battleship_recording_adapter',
        'id' => BATTLESHIP_EXTENSION_ID,
        'initialState' => 'battleship_initial_state',
        'applyAction' => 'battleship_apply_action',
        'validateSettings' => 'battleship_validate_settings',
        'settingsProjection' => 'battleship_settings_projection',
        'rulesProjection' => 'battleship_rules_projection',
        'projectState' => 'battleship_project_state',
        'projectVirtualMembers' => 'battleship_project_virtual_members',
        'randomnessPurposes' => ['auto-place' => 'battleship-auto-placement'],
        'deriveRandomness' => 'battleship_derive_randomness',
        'presentationStatus' => 'battleship_presentation_status',
        'openingProcedure' => 'simultaneous-private-placement-then-rematch-first-attacker-rotation',
        'rematchSeatRotation' => true,
    ];
}

function battleship_settings_projection(array $settings, string $mode, array $definition = []): array
{
    $settings = battleship_validate_settings($settings, $mode, $definition);
    $projection = [
        'label' => 'Game Options',
        'description' => 'Every player must accept the exact ship-touching rule before placement begins.',
        'classification' => !empty($settings['shipsMayTouch']) ? 'allow-touching' : 'no-touching',
        'classificationLabel' => !empty($settings['shipsMayTouch']) ? 'Enabled — CoreChat' : 'Disabled — Default / Legacy OCX',
        'controls' => [[
            'key' => 'shipsMayTouch', 'type' => 'select', 'value' => !empty($settings['shipsMayTouch']),
            'defaultValue' => false,
            'label' => 'Ships may touch',
            'description' => 'Disabled by default. Disabled forbids shared edges and diagonal corners; Enabled permits contact but never overlap.',
            'options' => [
                ['value' => false, 'label' => 'Disabled — Default / Legacy OCX'],
                ['value' => true, 'label' => 'Enabled — CoreChat'],
            ],
        ]],
    ];
    if ($mode === 'practice') $projection['controls'][] = [
        'key' => 'botSeat2Difficulty', 'type' => 'select', 'value' => $settings['botSeat2Difficulty'],
        'defaultValue' => 'none', 'label' => 'Empty seat 2 bot',
        'description' => 'None keeps the seat open for a person. Normal hunts the largest unsunk ship; Expert also weighs the remaining fleet. Both finish hits and follow the touching rule.',
        'options' => [['value' => 'none', 'label' => 'None'], ['value' => 'normal', 'label' => 'Normal'], ['value' => 'expert', 'label' => 'Expert']],
    ];
    return $projection;
}

function battleship_presentation_status(PDO $pdo, ?string $requestedPack = null): array
{
    return ocx_game_presentation_status($pdo, BATTLESHIP_EXTENSION_ID, $requestedPack);
}

function battleship_validate_settings(array $settings, string $mode, array $definition = []): array
{
    $allowed = ['gridSize', 'fleetLengths', 'shipsMayTouch', 'botSeat2Difficulty'];
    if (array_diff(array_keys($settings), $allowed)) {
        throw new MultiplayerGameException('A Battleship setting is not supported.', 'BATTLESHIP_SETTINGS_INVALID', 422);
    }
    if (isset($settings['gridSize']) && (int)$settings['gridSize'] !== 10) {
        throw new MultiplayerGameException('Battleship uses a 10 by 10 grid.', 'BATTLESHIP_SETTINGS_INVALID', 422);
    }
    if (isset($settings['fleetLengths']) && array_values(array_map('intval', (array)$settings['fleetLengths'])) !== [1, 2, 3, 4, 5]) {
        throw new MultiplayerGameException('Battleship uses one ship of each length from 1 through 5.', 'BATTLESHIP_SETTINGS_INVALID', 422);
    }
    $touching = $settings['shipsMayTouch'] ?? false;
    if (!is_bool($touching) && !in_array($touching, [0, 1, '0', '1'], true)) {
        throw new MultiplayerGameException('Choose whether ships may touch.', 'BATTLESHIP_SETTINGS_INVALID', 422);
    }
    $result = ['gridSize' => 10, 'fleetLengths' => [1, 2, 3, 4, 5], 'shipsMayTouch' => filter_var($touching, FILTER_VALIDATE_BOOLEAN)];
    if ($mode === 'practice') {
        $difficulty = (string)($settings['botSeat2Difficulty'] ?? 'none');
        if (!in_array($difficulty, ['none', 'normal', 'expert'], true)) throw new MultiplayerGameException('Choose None, Normal, or Expert for the Practice bot.', 'BATTLESHIP_BOT_DIFFICULTY_INVALID', 422);
        $result['botSeat2Difficulty'] = $difficulty;
    } elseif (isset($settings['botSeat2Difficulty']) && $settings['botSeat2Difficulty'] !== 'none') {
        throw new MultiplayerGameException('Games with bots are Practice only.', 'MULTIPLAYER_GAME_BOTS_PRACTICE_ONLY', 422);
    }
    return $result;
}

function battleship_rules_projection(array $settings, string $mode, array $definition = []): array
{
    $settings = battleship_validate_settings($settings, $mode, $definition);
    $touching = !empty($settings['shipsMayTouch']);
    return [
        'label' => 'Battleship rules',
        'description' => 'Each player privately places one ship of lengths 1, 2, 3, 4, and 5 on a 10 by 10 grid. Ships may be horizontal or vertical and may never overlap. ' . ($touching ? 'The accepted option allows ships to touch.' : 'Ships cannot touch, including at an edge or diagonal corner.') . ' Players alternate attacks against untried squares. The first player to sink the entire opposing fleet wins.',
        'sections' => [
            ['label' => 'Placement', 'text' => 'Choose Manual Placement or Auto Placement. Your unhit ship locations remain private. The accepted rule is: ' . ($touching ? 'Allow ships to touch.' : 'Ships cannot touch, including edge or corner adjacency.')],
            ['label' => 'Mode', 'text' => $mode === 'recorded' ? 'Recorded Auto Placement uses server-authoritative randomness and a valid completion updates Battleship-only records.' : 'Practice Auto Placement uses committed browser randomness and Practice never changes Recorded records.'],
            ['label' => 'Turns', 'text' => 'Placement is simultaneous and private. After both fleets are ready, only the announced first attacker may attack one untried square; accepted rematches alternate that first attacker between the same two players.'],
            ['label' => 'Damage and wrecks', 'text' => 'Every confirmed hit remains marked on the exact attacked square. A ship stays visibly damaged until its final occupied square is hit; it then sinks and remains as its class-specific wreck. Misses remain marked by a broken missile. These effects present authoritative attack history and never reveal an unsunk opposing ship.'],
        ],
    ];
}

function battleship_empty_head_to_head_record(array $playerUserIds): array
{
    $players = array_values(array_unique(array_map('intval', $playerUserIds)));
    $wins = [];
    $losses = [];
    foreach ($players as $userId) {
        $wins[(string)$userId] = 0;
        $losses[(string)$userId] = 0;
    }
    return [
        'owner' => 'explicit-rematch-series',
        'playerUserIds' => $players,
        'winsByUserId' => $wins,
        'lossesByUserId' => $losses,
        'roundsCompleted' => 0,
    ];
}

function battleship_head_to_head_record(array $state, array $playerUserIds): array
{
    $players = array_values(array_unique(array_map('intval', $playerUserIds)));
    $empty = battleship_empty_head_to_head_record($players);
    $candidate = (array)($state['headToHeadRecord'] ?? []);
    $candidatePlayers = array_values(array_unique(array_map('intval', (array)($candidate['playerUserIds'] ?? []))));
    $expected = $players;
    sort($candidatePlayers, SORT_NUMERIC);
    sort($expected, SORT_NUMERIC);
    if ($candidatePlayers !== $expected || (string)($candidate['owner'] ?? '') !== 'explicit-rematch-series') return $empty;
    foreach ($players as $userId) {
        $key = (string)$userId;
        $empty['winsByUserId'][$key] = max(0, (int)($candidate['winsByUserId'][$key] ?? 0));
        $empty['lossesByUserId'][$key] = max(0, (int)($candidate['lossesByUserId'][$key] ?? 0));
    }
    $empty['roundsCompleted'] = max(0, (int)($candidate['roundsCompleted'] ?? 0));
    return $empty;
}

function battleship_initial_state(array $playerUserIds, array $context = []): array
{
    $players = array_values(array_unique(array_map('intval', $playerUserIds)));
    if (count($players) < 1 || count($players) > 2 || min($players) < 1) throw new MultiplayerGameException('Battleship requires one or two authenticated players in Practice.', 'BATTLESHIP_PLAYER_SET_INVALID', 422);
    $settings = battleship_validate_settings((array)($context['settings'] ?? []), (string)($context['mode'] ?? 'practice'));
    $bots = [];
    if (count($players) === 1 && ($context['mode'] ?? 'practice') === 'practice' && ($settings['botSeat2Difficulty'] ?? 'none') !== 'none') {
        $difficulty = $settings['botSeat2Difficulty'];
        $players[] = BATTLESHIP_BOT_ID;
        $bots[(string)BATTLESHIP_BOT_ID] = ['userId' => BATTLESHIP_BOT_ID, 'seat' => 2,
            'difficulty' => $difficulty, 'displayName' => ($difficulty === 'expert' ? 'Expert' : 'Normal') . ' Bot 2'];
    }
    if (count($players) !== 2) throw new MultiplayerGameException('Another player must join, or choose a Practice bot.', 'MULTIPLAYER_GAME_MINIMUM_PLAYERS', 409);
    $roundContext = (array)($context['roundContext'] ?? []);
    $previousState = (array)($roundContext['previousState'] ?? []);
    $advance = !empty($roundContext['seriesContinues']) && !empty($roundContext['advancesSeries']);
    $roundNumber = !empty($roundContext['seriesContinues'])
        ? max(1, (int)($roundContext['previousRoundNumber'] ?? 1) + ($advance ? 1 : 0))
        : 1;
    $previousStarter = (int)($previousState['starterIndex'] ?? 0);
    $starterIndex = !empty($roundContext['seriesContinues'])
        ? ($advance ? 1 - ($previousStarter % 2) : $previousStarter % 2)
        : 0;
    $headToHeadRecord = !empty($roundContext['rematchContinues'])
        ? battleship_head_to_head_record($previousState, $players)
        : battleship_empty_head_to_head_record($players);
    $fleets = [];
    foreach ($players as $userId) $fleets[(string)$userId] = ['placed' => false, 'accepted' => false, 'ships' => [], 'attacksReceived' => []];
    if ($bots !== []) {
        $bytes = array_values(unpack('C*', random_bytes(512)));
        $ships = battleship_auto_fleet($bytes, !empty($settings['shipsMayTouch']));
        $fleets[(string)BATTLESHIP_BOT_ID] = ['placed' => true, 'accepted' => true, 'ships' => $ships, 'attacksReceived' => []];
    }
    return [
        'schemaVersion' => BATTLESHIP_STATE_SCHEMA_VERSION,
        'bots' => $bots,
        'turnOrder' => $players,
        'turnIndex' => $players[$starterIndex] < 0 ? null : $starterIndex,
        'roundNumber' => $roundNumber,
        'starterIndex' => $starterIndex,
        'starterUserId' => $players[$starterIndex],
        'starterReason' => $roundNumber === 1
            ? 'The first accepted round uses the first attacker assigned by the session.'
            : 'The first attacker alternates after an accepted rematch between the same two players.',
        'phase' => 'placement',
        'settings' => $settings,
        'headToHeadRecord' => $headToHeadRecord,
        'fleets' => $fleets,
        'attackHistory' => [],
        'meaningfulPlay' => false,
        'completed' => false,
    ];
}

function battleship_cell(int $row, int $column): string
{
    if ($row < 0 || $row > 9 || $column < 0 || $column > 9) throw new MultiplayerGameException('Choose a square on the 10 by 10 grid.', 'BATTLESHIP_CELL_INVALID', 422);
    return $row . ':' . $column;
}

function battleship_validate_fleet(array $ships, bool $shipsMayTouch = false): array
{
    if (count($ships) !== 5) throw new MultiplayerGameException('Place exactly five ships.', 'BATTLESHIP_FLEET_INVALID', 422);
    $lengths = [];
    $occupied = [];
    $validated = [];
    foreach ($ships as $index => $ship) {
        if (!is_array($ship)) throw new MultiplayerGameException('A ship placement is invalid.', 'BATTLESHIP_FLEET_INVALID', 422);
        $length = (int)($ship['length'] ?? 0);
        $row = (int)($ship['row'] ?? -1);
        $column = (int)($ship['column'] ?? -1);
        $orientation = strtolower(trim((string)($ship['orientation'] ?? '')));
        if (!in_array($length, [1, 2, 3, 4, 5], true) || isset($lengths[$length]) || !in_array($orientation, ['horizontal', 'vertical'], true)) {
            throw new MultiplayerGameException('Use one ship of each length from 1 through 5.', 'BATTLESHIP_FLEET_INVALID', 422);
        }
        $cells = [];
        for ($offset = 0; $offset < $length; $offset++) {
            $cellRow = $row + ($orientation === 'vertical' ? $offset : 0);
            $cellColumn = $column + ($orientation === 'horizontal' ? $offset : 0);
            $key = battleship_cell($cellRow, $cellColumn);
            if (isset($occupied[$key])) throw new MultiplayerGameException('Ships may not overlap.', 'BATTLESHIP_FLEET_OVERLAP', 422);
            $cells[] = $key;
        }
        if (!$shipsMayTouch) {
            foreach ($cells as $key) {
                [$cellRow, $cellColumn] = array_map('intval', explode(':', $key));
                for ($rowOffset = -1; $rowOffset <= 1; $rowOffset++) for ($columnOffset = -1; $columnOffset <= 1; $columnOffset++) {
                    if ($rowOffset === 0 && $columnOffset === 0) continue;
                    if (isset($occupied[($cellRow + $rowOffset) . ':' . ($cellColumn + $columnOffset)])) {
                        throw new MultiplayerGameException('Ships cannot touch at an edge or diagonal corner under the accepted rules.', 'BATTLESHIP_FLEET_ADJACENT', 422);
                    }
                }
            }
        }
        foreach ($cells as $key) $occupied[$key] = true;
        $lengths[$length] = true;
        $validated[] = ['id' => 'ship-' . $length, 'length' => $length, 'orientation' => $orientation, 'cells' => $cells, 'hits' => []];
    }
    usort($validated, static fn(array $a, array $b): int => $a['length'] <=> $b['length']);
    return $validated;
}

function battleship_derive_randomness(string $canonicalReveal, string $actionType, array $payload, array $context): array
{
    return ['bytes' => ocx_game_random_bytes($canonicalReveal, 'battleship-auto-placement', 512)];
}

function battleship_auto_fleet(array $bytes, bool $shipsMayTouch = false): array
{
    $ships = [];
    $occupied = [];
    $cursor = 0;
    foreach ([5, 4, 3, 2, 1] as $length) {
        $placed = false;
        for ($attempt = 0; $attempt < 2000 && !$placed; $attempt++) {
            $row = $bytes[$cursor++ % count($bytes)] % 10;
            $column = $bytes[$cursor++ % count($bytes)] % 10;
            $orientation = ($bytes[$cursor++ % count($bytes)] % 2) === 0 ? 'horizontal' : 'vertical';
            $cells = [];
            for ($offset = 0; $offset < $length; $offset++) {
                $r = $row + ($orientation === 'vertical' ? $offset : 0);
                $c = $column + ($orientation === 'horizontal' ? $offset : 0);
                if ($r > 9 || $c > 9 || isset($occupied[$r . ':' . $c])) { $cells = []; break; }
                if (!$shipsMayTouch) {
                    for ($rowOffset = -1; $rowOffset <= 1; $rowOffset++) for ($columnOffset = -1; $columnOffset <= 1; $columnOffset++) {
                        if (isset($occupied[($r + $rowOffset) . ':' . ($c + $columnOffset)])) { $cells = []; break 3; }
                    }
                }
                $cells[] = $r . ':' . $c;
            }
            if ($cells === []) continue;
            foreach ($cells as $cell) $occupied[$cell] = true;
            $ships[] = ['length' => $length, 'row' => $row, 'column' => $column, 'orientation' => $orientation];
            $placed = true;
        }
        if (!$placed) throw new MultiplayerGameException('Auto Placement could not produce a valid fleet.', 'BATTLESHIP_AUTO_PLACEMENT_FAILED', 500);
    }
    return battleship_validate_fleet($ships, $shipsMayTouch);
}

function battleship_safe_manual_fleet(bool $shipsMayTouch = false): array
{
    $ships = [];
    foreach ([1, 2, 3, 4, 5] as $index => $length) {
        $ships[] = [
            'length' => $length,
            'row' => $index * 2,
            'column' => 0,
            'orientation' => 'horizontal',
        ];
    }
    return battleship_validate_fleet($ships, $shipsMayTouch);
}

function battleship_fleet_placement_inputs(array $fleet): array
{
    $inputs = [];
    foreach ($fleet as $ship) {
        $cells = array_values(array_map('strval', (array)($ship['cells'] ?? [])));
        $first = isset($cells[0]) ? explode(':', $cells[0]) : [];
        if (count($first) !== 2) {
            throw new MultiplayerGameException('The current fleet cannot be edited safely.', 'BATTLESHIP_MANUAL_FLEET_INVALID', 409);
        }
        $inputs[] = [
            'length' => (int)($ship['length'] ?? 0),
            'row' => (int)$first[0],
            'column' => (int)$first[1],
            'orientation' => (string)($ship['orientation'] ?? ''),
        ];
    }
    return $inputs;
}

function battleship_try_manual_fleet(array $ships, bool $shipsMayTouch): ?array
{
    try {
        return battleship_validate_fleet($ships, $shipsMayTouch);
    } catch (MultiplayerGameException $exception) {
        return null;
    }
}

function battleship_manual_fleet_operation(
    array $fleet,
    string $operation,
    string $shipId,
    mixed $row,
    mixed $column,
    bool $shipsMayTouch
): array {
    if ($operation === 'seed') {
        return ['ships' => battleship_safe_manual_fleet($shipsMayTouch), 'relocated' => false];
    }
    if (!preg_match('/^ship-([1-5])$/', $shipId, $matches)) {
        throw new MultiplayerGameException('Choose one ship from the current fleet.', 'BATTLESHIP_MANUAL_SHIP_INVALID', 422);
    }
    $length = (int)$matches[1];
    $baseFleet = $fleet === [] ? battleship_safe_manual_fleet($shipsMayTouch) : $fleet;
    $inputs = battleship_fleet_placement_inputs($baseFleet);
    $shipIndex = null;
    foreach ($inputs as $index => $ship) {
        if ((int)$ship['length'] === $length) {
            $shipIndex = $index;
            break;
        }
    }
    if ($shipIndex === null) {
        throw new MultiplayerGameException('The selected ship is unavailable.', 'BATTLESHIP_MANUAL_SHIP_INVALID', 409);
    }

    if ($operation === 'relocate') {
        $targetRow = filter_var($row, FILTER_VALIDATE_INT);
        $targetColumn = filter_var($column, FILTER_VALIDATE_INT);
        if ($targetRow === false || $targetColumn === false || $targetRow < 0 || $targetRow > 9 || $targetColumn < 0 || $targetColumn > 9) {
            throw new MultiplayerGameException('Choose a valid destination square.', 'BATTLESHIP_MANUAL_DESTINATION_INVALID', 422);
        }
        $inputs[$shipIndex]['row'] = $targetRow;
        $inputs[$shipIndex]['column'] = $targetColumn;
        $validated = battleship_try_manual_fleet($inputs, $shipsMayTouch);
        if ($validated === null) {
            throw new MultiplayerGameException('That ship cannot be placed there under the accepted rules.', 'BATTLESHIP_MANUAL_RELOCATION_INVALID', 422);
        }
        return ['ships' => $validated, 'relocated' => true];
    }

    if ($operation !== 'rotate') {
        throw new MultiplayerGameException('This manual placement operation is not supported.', 'BATTLESHIP_MANUAL_OPERATION_INVALID', 422);
    }
    $inputs[$shipIndex]['orientation'] = $inputs[$shipIndex]['orientation'] === 'horizontal' ? 'vertical' : 'horizontal';
    $rotatedAtSource = battleship_try_manual_fleet($inputs, $shipsMayTouch);
    if ($rotatedAtSource !== null) return ['ships' => $rotatedAtSource, 'relocated' => false];

    // A blocked source rotation is still one authoritative operation. Search
    // deterministically for the first legal anchor in the requested rotated
    // orientation instead of accepting client-authored fallback geometry.
    for ($candidateRow = 0; $candidateRow < 10; $candidateRow++) {
        for ($candidateColumn = 0; $candidateColumn < 10; $candidateColumn++) {
            $candidate = $inputs;
            $candidate[$shipIndex]['row'] = $candidateRow;
            $candidate[$shipIndex]['column'] = $candidateColumn;
            $validated = battleship_try_manual_fleet($candidate, $shipsMayTouch);
            if ($validated !== null) return ['ships' => $validated, 'relocated' => true];
        }
    }
    throw new MultiplayerGameException('The ship cannot be rotated into a legal position.', 'BATTLESHIP_MANUAL_ROTATION_BLOCKED', 409);
}

function battleship_project_state(array $state, int $viewerUserId, array $context): array
{
    $projected = $state;
    if ((int)($projected['lastPlacement']['actorUserId'] ?? 0) !== $viewerUserId) unset($projected['lastPlacement']);
    $recordPlayers = array_values(array_unique(array_map('intval', (array)($projected['turnOrder'] ?? []))));
    $activePlayers = array_values(array_unique(array_map(
        static fn(array $member): int => (int)($member['userId'] ?? 0),
        array_filter((array)($context['members'] ?? []), static fn(array $member): bool =>
            in_array((string)($member['role'] ?? ''), ['master', 'player'], true)
            && (string)($member['membershipStatus'] ?? '') === 'active'
        )
    )));
    $expected = $recordPlayers;
    sort($activePlayers, SORT_NUMERIC);
    sort($expected, SORT_NUMERIC);
    if ($activePlayers !== $expected) {
        $projected['headToHeadRecord'] = battleship_empty_head_to_head_record($recordPlayers);
        $projected['headToHeadRecord']['resetReason'] = 'player-departed';
    } else {
        $projected['headToHeadRecord'] = battleship_head_to_head_record($projected, $recordPlayers);
    }
    if (!is_array($projected['fleets'] ?? null)) return $projected;
    foreach ($projected['fleets'] as $userId => &$fleet) {
        if ((int)$userId === $viewerUserId) continue;
        if (!is_array($fleet['ships'] ?? null)) continue;
        foreach ($fleet['ships'] as &$ship) {
            $sunk = (int)($ship['length'] ?? 0) > 0
                && count((array)($ship['hits'] ?? [])) === (int)($ship['length'] ?? 0);
            if (!$sunk) $ship['cells'] = [];
            $ship['private'] = !$sunk;
            $ship['revealedSunk'] = $sunk;
        }
        unset($ship);
    }
    unset($fleet);
    $projected['botTask'] = ($state['phase'] ?? '') === 'battle' ? paced_bot_task($state, $viewerUserId, $context, 'battleship-paced-1') : null;
    return $projected;
}

function battleship_opponent(array $state, int $userId): int
{
    foreach ((array)$state['turnOrder'] as $candidate) if ((int)$candidate !== $userId) return (int)$candidate;
    throw new MultiplayerGameException('The opposing fleet is unavailable.', 'BATTLESHIP_PLAYER_SET_INVALID', 409);
}

function battleship_fleet_sunk(array $fleet): bool
{
    if (empty($fleet['placed'])) return false;
    foreach ((array)$fleet['ships'] as $ship) if (count((array)$ship['hits']) < (int)$ship['length']) return false;
    return true;
}

function battleship_ready_fleet_is_valid(array $fleet, bool $shipsMayTouch): bool
{
    if (empty($fleet['placed']) || count((array)($fleet['ships'] ?? [])) !== 5) return false;
    $sourceShips = [];
    foreach ((array)$fleet['ships'] as $ship) {
        $length = (int)($ship['length'] ?? 0);
        $orientation = (string)($ship['orientation'] ?? '');
        $cells = array_values(array_map('strval', (array)($ship['cells'] ?? [])));
        if ($length < 1 || count($cells) !== $length || !in_array($orientation, ['horizontal', 'vertical'], true)) return false;
        $first = explode(':', $cells[0]);
        if (count($first) !== 2) return false;
        $row = filter_var($first[0], FILTER_VALIDATE_INT);
        $column = filter_var($first[1], FILTER_VALIDATE_INT);
        if ($row === false || $column === false) return false;
        $expected = [];
        for ($offset = 0; $offset < $length; $offset++) {
            $expected[] = battleship_cell(
                $row + ($orientation === 'vertical' ? $offset : 0),
                $column + ($orientation === 'horizontal' ? $offset : 0)
            );
        }
        if ($cells !== $expected) return false;
        $sourceShips[] = compact('length', 'row', 'column', 'orientation');
    }
    try {
        battleship_validate_fleet($sourceShips, $shipsMayTouch);
    } catch (MultiplayerGameException $exception) {
        return false;
    }
    return true;
}

function battleship_terminal(array &$state, int $winner, string $reason): array
{
    $players = array_values(array_unique(array_map('intval', (array)($state['turnOrder'] ?? []))));
    $record = battleship_head_to_head_record($state, $players);
    if (empty($state['headToHeadRoundRecorded'])) {
        foreach ($players as $userId) {
            $key = (string)$userId;
            if ($userId === $winner) $record['winsByUserId'][$key]++;
            else $record['lossesByUserId'][$key]++;
        }
        $record['roundsCompleted']++;
        $state['headToHeadRoundRecorded'] = true;
    }
    $state['headToHeadRecord'] = $record;
    $state['completed'] = true;
    $state['phase'] = 'completed';
    $state['winnerUserId'] = $winner;
    $state['terminalReason'] = $reason;
    $scores = [];
    foreach ($state['turnOrder'] as $userId) $scores[(string)(int)$userId] = (int)$userId === $winner ? 1 : 0;
    return ['state' => $state, 'turnUserId' => null, 'terminal' => true, 'result' => ocx_game_result_from_scores($scores)];
}

function battleship_apply_action_core(array $state, int $actorUserId, string $action, array $payload, array $context): array
{
    if ((int)($state['schemaVersion'] ?? 0) !== BATTLESHIP_STATE_SCHEMA_VERSION || !empty($state['completed'])) throw new MultiplayerGameException('The Battleship state is unavailable.', 'BATTLESHIP_STATE_INVALID', 409);
    if (!isset($state['fleets'][(string)$actorUserId])) throw new MultiplayerGameException('Only a player may act.', 'BATTLESHIP_PLAYER_INVALID', 403);
    if ($action === 'resign') return battleship_terminal($state, battleship_opponent($state, $actorUserId), 'resignation');
    if ($action === 'manual-place') {
        if ((string)$state['phase'] !== 'placement') throw new MultiplayerGameException('This fleet is already ready.', 'BATTLESHIP_PLACEMENT_LOCKED', 409);
        $shipsMayTouch = !empty($state['settings']['shipsMayTouch']);
        $currentFleet = (array)($state['fleets'][(string)$actorUserId] ?? []);
        $operation = strtolower(trim((string)($payload['operation'] ?? '')));
        $result = battleship_manual_fleet_operation(
            (array)($currentFleet['ships'] ?? []),
            $operation,
            trim((string)($payload['shipId'] ?? '')),
            $payload['row'] ?? null,
            $payload['column'] ?? null,
            $shipsMayTouch
        );
        $state['fleets'][(string)$actorUserId] = ['placed' => true, 'accepted' => false, 'ships' => $result['ships'], 'attacksReceived' => []];
        $state['lastPlacement'] = [
            'actorUserId' => $actorUserId,
            'kind' => 'manual',
            'operation' => $operation,
            'shipId' => trim((string)($payload['shipId'] ?? '')),
            'relocated' => !empty($result['relocated']),
        ];
        return ['state' => $state, 'turnUserId' => null];
    }
    if (in_array($action, ['place', 'auto-place'], true)) {
        if ((string)$state['phase'] !== 'placement') throw new MultiplayerGameException('This fleet is already ready.', 'BATTLESHIP_PLACEMENT_LOCKED', 409);
        $shipsMayTouch = !empty($state['settings']['shipsMayTouch']);
        $ships = $action === 'place'
            ? battleship_validate_fleet((array)($payload['ships'] ?? []), $shipsMayTouch)
            : battleship_auto_fleet((array)($context['authoritativeRandomness']['bytes'] ?? []), $shipsMayTouch);
        $state['fleets'][(string)$actorUserId] = ['placed' => true, 'accepted' => false, 'ships' => $ships, 'attacksReceived' => []];
        $state['lastPlacement'] = [
            'actorUserId' => $actorUserId,
            'kind' => $action === 'auto-place' ? 'auto' : 'manual',
            'operation' => $action === 'auto-place' ? 'auto-place' : 'replace',
            'shipId' => '',
            'relocated' => false,
        ];
        return ['state' => $state, 'turnUserId' => null];
    }
    if ($action === 'start') {
        if ((string)$state['phase'] !== 'placement') throw new MultiplayerGameException('The battle has already started.', 'BATTLESHIP_START_LOCKED', 409);
        if ($payload !== []) throw new MultiplayerGameException('Start acceptance applies only to the authenticated player.', 'BATTLESHIP_START_PAYLOAD_INVALID', 422);
        $shipsMayTouch = !empty($state['settings']['shipsMayTouch']);
        $fleet =& $state['fleets'][(string)$actorUserId];
        if (!battleship_ready_fleet_is_valid($fleet, $shipsMayTouch)) {
            throw new MultiplayerGameException('Place a complete valid fleet before accepting the start.', 'BATTLESHIP_START_FLEET_INVALID', 409);
        }
        $fleet['accepted'] = true;
        unset($fleet);
        $accepted = count(array_filter(
            $state['fleets'],
            static fn(array $candidate): bool => !empty($candidate['placed']) && !empty($candidate['accepted'])
        )) === 2;
        if ($accepted) {
            foreach ($state['fleets'] as $candidate) {
                if (!battleship_ready_fleet_is_valid((array)$candidate, $shipsMayTouch)) {
                    throw new MultiplayerGameException('Both fleets must remain valid before the battle starts.', 'BATTLESHIP_START_FLEET_INVALID', 409);
                }
            }
            $state['phase'] = 'battle';
            $state['turnIndex'] = (int)$state['starterIndex'];
            $state['meaningfulPlay'] = true;
        }
        return ['state' => $state, 'turnUserId' => $accepted ? (int)$state['starterUserId'] : null];
    }
    if ($action !== 'attack') throw new MultiplayerGameException('This Battleship action is not supported.', 'BATTLESHIP_ACTION_INVALID', 422);
    if ((string)$state['phase'] !== 'battle') throw new MultiplayerGameException('Both fleets must be ready.', 'BATTLESHIP_FLEETS_NOT_READY', 409);
    ocx_game_assert_turn($state, $actorUserId);
    $row = (int)($payload['row'] ?? -1);
    $column = (int)($payload['column'] ?? -1);
    $cell = battleship_cell($row, $column);
    $opponentId = battleship_opponent($state, $actorUserId);
    $fleet =& $state['fleets'][(string)$opponentId];
    if (isset($fleet['attacksReceived'][$cell])) throw new MultiplayerGameException('That square was already tried.', 'BATTLESHIP_ATTACK_DUPLICATE', 409);
    $hitShip = null;
    foreach ($fleet['ships'] as $index => &$ship) {
        if (!in_array($cell, $ship['cells'], true)) continue;
        $ship['hits'][] = $cell;
        $ship['hits'] = array_values(array_unique($ship['hits']));
        $hitShip = (int)$index;
        break;
    }
    unset($ship);
    $result = $hitShip === null ? 'miss' : (count($fleet['ships'][$hitShip]['hits']) === (int)$fleet['ships'][$hitShip]['length'] ? 'sunk' : 'hit');
    $fleet['attacksReceived'][$cell] = $result;
    $state['attackHistory'][] = ['actorUserId' => $actorUserId, 'targetUserId' => $opponentId, 'cell' => $cell, 'result' => $result];
    if (battleship_fleet_sunk($fleet)) return battleship_terminal($state, $actorUserId, 'fleet-sunk');
    $next = ocx_game_advance_turn($state);
    return ['state' => $state, 'turnUserId' => $next];
}
