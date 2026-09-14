<?php
declare(strict_types=1);
require_once __DIR__ . '/arcade_extension_support.php';

const TETRIS_ARCADE_SPEEDS = [1200,1100,1000,900,820,760,700,650,610,580,550,520,490,460,430,400,380,360,340,320];
const TETRIS_ARCADE_SHAPES = [
    'I' => [[0,0,0,0],[1,1,1,1],[0,0,0,0],[0,0,0,0]],
    'J' => [[1,0,0],[1,1,1],[0,0,0]], 'L' => [[0,0,1],[1,1,1],[0,0,0]],
    'O' => [[1,1],[1,1]], 'S' => [[0,1,1],[1,1,0],[0,0,0]],
    'T' => [[0,1,0],[1,1,1],[0,0,0]], 'Z' => [[1,1,0],[0,1,1],[0,0,0]],
];

function tetris_arcade_extension_adapter(): array { return arcade_adapter('tetris-versus', 'tetris_arcade'); }

function tetris_arcade_rules_projection(array $settings, string $mode, array $definition = []): array
{
    return ['label' => 'Tetris Versus rules', 'description' => 'Two independent boards. Keep your stack below the top.', 'sections' => [
        ['label' => 'Controls', 'text' => 'Left/Right move, Down soft-drops, Up rotates clockwise, Z rotates counterclockwise, and Space hard-drops. Touch controls are below the boards. Rotation does not kick a piece through a wall.'],
        ['label' => 'Pieces and score', 'text' => 'Each player has an independent seven-bag containing one of each shape. Clear one, two, three or four lines for 100, 300, 500 or 800 points times your level. There are no garbage attacks.'],
        ['label' => 'Level', 'text' => 'Level 1 drops one automatic row every 1.2 seconds. Every 10 cleared lines increases your level. Level 20 is the maximum, at one automatic row every 0.32 seconds.'],
        ['label' => 'Ending', 'text' => 'The first player unable to spawn the next piece loses. Resignation also loses. Top-outs in the same simulation step are a draw. Score does not override this race result.'],
        ['label' => 'Pause and reconnect', 'text' => 'Use the shared pause and reconnect controls. Play continues until a pause takes effect; closing a browser does not silently restart the board.'],
    ]];
}

function tetris_arcade_initial_state(array $players, array $context = []): array
{
    $state = arcade_initial_state('tetris', $players, 2, $context);
    $state['boards'] = [];
    foreach ($state['turnOrder'] as $id) {
        $board = ['cells' => array_fill(0, 20, array_fill(0, 10, '')),
            'seed' => bin2hex(random_bytes(32)), 'bagNumber' => 0, 'bag' => [], 'queue' => [],
            'score' => 0, 'lines' => 0, 'level' => 1, 'gravityMs' => 0, 'alive' => true, 'active' => null];
        tetris_arcade_spawn($board);
        $state['boards'][$id] = $board;
    }
    return $state;
}

function tetris_arcade_refill(array &$board): void
{
    while (count($board['queue']) < 6) {
        if ($board['bag'] === []) {
            $bag = array_keys(TETRIS_ARCADE_SHAPES);
            $number = $board['bagNumber']++;
            usort($bag, static fn(string $a, string $b): int => strcmp(
                hash_hmac('sha256', $number . ':' . $a, $board['seed']),
                hash_hmac('sha256', $number . ':' . $b, $board['seed'])
            ));
            $board['bag'] = $bag;
        }
        $board['queue'][] = array_shift($board['bag']);
    }
}

function tetris_arcade_matrix(array $piece): array
{
    $matrix = TETRIS_ARCADE_SHAPES[$piece['kind']];
    for ($i = 0; $i < $piece['rotation']; $i++) {
        $rotated = array_fill(0, count($matrix), array_fill(0, count($matrix), 0));
        foreach ($matrix as $r => $row) foreach ($row as $c => $cell) $rotated[$c][count($matrix)-1-$r] = $cell;
        $matrix = $rotated;
    }
    return $matrix;
}

function tetris_arcade_fits(array $board, array $piece): bool
{
    foreach (tetris_arcade_matrix($piece) as $r => $row) foreach ($row as $c => $cell) {
        if (!$cell) continue;
        $y = $piece['row'] + $r; $x = $piece['col'] + $c;
        if ($y < 0 || $y >= 20 || $x < 0 || $x >= 10 || $board['cells'][$y][$x] !== '') return false;
    }
    return true;
}

function tetris_arcade_spawn(array &$board): void
{
    tetris_arcade_refill($board);
    $board['active'] = ['kind' => array_shift($board['queue']), 'rotation' => 0, 'row' => 0, 'col' => 3];
    $board['alive'] = tetris_arcade_fits($board, $board['active']);
}

function tetris_arcade_lock(array &$board): void
{
    $piece = $board['active'];
    foreach (tetris_arcade_matrix($piece) as $r => $row) foreach ($row as $c => $cell) {
        if ($cell) $board['cells'][$piece['row']+$r][$piece['col']+$c] = $piece['kind'];
    }
    $kept = array_values(array_filter($board['cells'], static fn(array $row): bool => in_array('', $row, true)));
    $cleared = 20-count($kept);
    $board['cells'] = array_merge(array_fill(0, $cleared, array_fill(0, 10, '')), $kept);
    $board['score'] += [0,100,300,500,800][$cleared] * $board['level'];
    $board['lines'] += $cleared;
    $board['level'] = min(20, 1 + intdiv($board['lines'], 10));
    $board['gravityMs'] = 0;
    tetris_arcade_spawn($board);
}

function tetris_arcade_command(array &$board, string $command): void
{
    if (!$board['alive']) return;
    $candidate = $board['active'];
    if ($command === 'drop') {
        do { $candidate['row']++; } while (tetris_arcade_fits($board, $candidate));
        $candidate['row']--;
        $board['active'] = $candidate;
        tetris_arcade_lock($board);
        return;
    }
    if ($command === 'left') $candidate['col']--;
    if ($command === 'right') $candidate['col']++;
    if ($command === 'down') $candidate['row']++;
    if ($command === 'cw') $candidate['rotation'] = ($candidate['rotation'] + 1) % 4;
    if ($command === 'ccw') $candidate['rotation'] = ($candidate['rotation'] + 3) % 4;
    if (tetris_arcade_fits($board, $candidate)) $board['active'] = $candidate;
    elseif ($command === 'down') tetris_arcade_lock($board);
}

function tetris_arcade_tick(array &$board, int $milliseconds): void
{
    if (!$board['alive']) return;
    $board['gravityMs'] += $milliseconds;
    $speed = TETRIS_ARCADE_SPEEDS[$board['level']-1];
    if ($board['gravityMs'] >= $speed) {
        $board['gravityMs'] -= $speed;
        tetris_arcade_command($board, 'down');
    }
}
