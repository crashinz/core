<?php
declare(strict_types=1);

const BATTLESHIP_BOT_ID = -6302;

function battleship_project_virtual_members(PDO $pdo, array $state, array $context): array
{
    if (($context['mode'] ?? '') !== 'practice') return [];
    return array_values((array)($state['bots'] ?? []));
}

/** Build from shot results and confirmed wrecks only; never pass a private fleet. */
function battleship_bot_observation(array $state, int $actor): array
{
    $opponent = battleship_opponent($state, $actor);
    $fleet = (array)($state['fleets'][(string)$opponent] ?? []);
    $remaining = array_values((array)($state['settings']['fleetLengths'] ?? [1, 2, 3, 4, 5]));
    $wrecks = [];
    foreach ((array)($fleet['ships'] ?? []) as $ship) {
        $length = (int)($ship['length'] ?? 0);
        if ($length < 1 || count((array)($ship['hits'] ?? [])) !== $length) continue;
        $wrecks = array_merge($wrecks, (array)$ship['hits']);
        $index = array_search($length, $remaining, true);
        if ($index !== false) unset($remaining[$index]);
    }
    rsort($remaining, SORT_NUMERIC);
    sort($wrecks, SORT_STRING);
    $shots = (array)($fleet['attacksReceived'] ?? []);
    ksort($shots, SORT_STRING);
    return ['actorUserId' => $actor, 'shots' => $shots, 'wrecks' => $wrecks,
        'remainingLengths' => array_values($remaining),
        'shipsMayTouch' => !empty($state['settings']['shipsMayTouch']),
        'difficulty' => (string)($state['bots'][(string)$actor]['difficulty'] ?? 'normal')];
}

function battleship_bot_neighbors(string $cell): array
{
    [$row, $column] = array_map('intval', explode(':', $cell));
    $neighbors = [];
    for ($dr = -1; $dr <= 1; $dr++) for ($dc = -1; $dc <= 1; $dc++) {
        if (($dr || $dc) && $row + $dr >= 0 && $row + $dr < 10 && $column + $dc >= 0 && $column + $dc < 10) {
            $neighbors[] = ($row + $dr) . ':' . ($column + $dc);
        }
    }
    return $neighbors;
}

/** At most 1,000 placements on the fixed board. Both levels finish hits first. */
function battleship_bot_choose_attack(array $observation): array
{
    $shots = (array)$observation['shots'];
    $wrecks = array_fill_keys((array)$observation['wrecks'], true);
    $blocked = $wrecks;
    $hits = [];
    foreach ($shots as $cell => $result) {
        if ($result === 'miss') $blocked[$cell] = true;
        elseif (!isset($wrecks[$cell])) $hits[$cell] = true;
    }
    if (empty($observation['shipsMayTouch'])) {
        foreach (array_keys($wrecks) as $cell) foreach (battleship_bot_neighbors($cell) as $neighbor) $blocked[$neighbor] = true;
    }
    $placements = [];
    $maximumHits = 0;
    foreach ((array)$observation['remainingLengths'] as $length) {
        $length = (int)$length;
        foreach ($length === 1 ? [[0, 1]] : [[0, 1], [1, 0]] as [$dr, $dc]) {
            for ($r = 0; $r < 10; $r++) for ($c = 0; $c < 10; $c++) {
                if ($r + ($length - 1) * $dr > 9 || $c + ($length - 1) * $dc > 9) continue;
                $cells = [];
                for ($i = 0; $i < $length; $i++) $cells[] = ($r + $i * $dr) . ':' . ($c + $i * $dc);
                $set = array_fill_keys($cells, true);
                if (array_intersect_key($set, $blocked)) continue;
                if (empty($observation['shipsMayTouch'])) {
                    // A hit touching this candidate must belong to the same ship.
                    foreach ($cells as $cell) foreach (battleship_bot_neighbors($cell) as $neighbor) {
                        if (isset($hits[$neighbor]) && !isset($set[$neighbor])) continue 3;
                    }
                }
                $untried = array_diff_key($set, $shots);
                if ($untried === []) continue;
                $covered = count(array_intersect_key($set, $hits));
                $maximumHits = max($maximumHits, $covered);
                $placements[] = ['cells' => $untried, 'length' => $length, 'hits' => $covered];
            }
        }
    }
    $targeting = $maximumHits > 0;
    $largest = max(array_merge([0], (array)$observation['remainingLengths']));
    $scores = [];
    $expert = ($observation['difficulty'] ?? '') === 'expert';
    foreach ($placements as $placement) {
        if ($targeting && $placement['hits'] !== $maximumHits) continue;
        if (!$targeting && !$expert && $placement['length'] !== $largest) continue;
        foreach ($placement['cells'] as $cell => $_) {
            $scores[$cell] ??= [0, 0];
            // Largest-ship coverage is the leading hunt criterion for both levels.
            $scores[$cell][0] += $targeting ? 1 : (int)($placement['length'] === $largest);
            if ($expert) $scores[$cell][1] += $placement['length'];
        }
    }
    if ($scores === []) {
        // The length-one ship requires complete coverage, including isolated cells.
        for ($r = 0; $r < 10; $r++) for ($c = 0; $c < 10; $c++) {
            $cell = "$r:$c";
            if (!isset($shots[$cell]) && !isset($blocked[$cell])) $scores[$cell] = [0, 0];
        }
    }
    if ($scores === []) throw new MultiplayerGameException('No legal Battleship target remains.', 'BATTLESHIP_BOT_TARGET_INVALID', 409);
    $seed = hash('sha256', json_encode($observation, JSON_THROW_ON_ERROR));
    uksort($scores, static function(string $a, string $b) use ($scores, $seed): int {
        return ($scores[$b] <=> $scores[$a]) ?: strcmp(hash('sha256', $seed . $a), hash('sha256', $seed . $b));
    });
    $cell = array_key_first($scores);
    [$row, $column] = array_map('intval', explode(':', $cell));
    $legal = [];
    for ($r = 0; $r < 10; $r++) for ($c = 0; $c < 10; $c++) if (!isset($shots["$r:$c"])) $legal[] = "$r:$c";
    return ['payload' => ['row' => $row, 'column' => $column],
        'trace' => ['reason' => $targeting ? 'finish-hit-ship' : 'hunt-largest-unsunk',
            'publicObservation' => $observation, 'legal' => $legal, 'candidates' => array_keys($scores),
            'difficulty' => $observation['difficulty'], 'candidateScores' => $scores, 'selected' => $cell]];
}

function battleship_apply_action(array $state, int $actorUserId, string $action, array $payload, array $context): array
{
    if (!empty($state['bots']) && ($context['mode'] ?? 'practice') !== 'practice') {
        throw new MultiplayerGameException('Games with bots are Practice only.', 'MULTIPLAYER_GAME_BOTS_PRACTICE_ONLY', 422);
    }
    $applied = battleship_apply_action_core($state, $actorUserId, $action, $payload, $context);
    if (function_exists('game_recording_observe')) game_recording_observe($context, 'battleship', $state, $actorUserId, $action, $payload, $applied['state']);
    $next = (int)($applied['turnUserId'] ?? 0);
    if (empty($applied['terminal']) && ($applied['state']['phase'] ?? '') === 'battle' && isset($applied['state']['bots'][(string)$next])) {
        $before = $applied['state'];
        $started = hrtime(true);
        $choice = battleship_bot_choose_attack(battleship_bot_observation($before, $next));
        $applied = battleship_apply_action_core($before, $next, 'attack', $choice['payload'], $context);
        $trace = $choice['trace'] + ['elapsedMs' => (hrtime(true) - $started) / 1000000];
        if (function_exists('game_recording_observe')) game_recording_observe($context, 'battleship', $before, $next, 'attack', $choice['payload'], $applied['state'], $trace);
    }
    return $applied;
}
