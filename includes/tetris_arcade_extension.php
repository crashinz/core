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

function tetris_arcade_extension_adapter(): array { return array_replace(arcade_adapter('tetris-versus', 'tetris_arcade'), ['validateSettings'=>'tetris_arcade_validate_settings','settingsProjection'=>'tetris_arcade_settings_projection','projectVirtualMembers'=>'tetris_arcade_virtual_members']); }
function tetris_arcade_validate_settings(array $settings,string $mode,array $definition=[]): array {
    $opponent=$settings['opponent']??'human';
    if(array_diff(array_keys($settings),['opponent'])||!in_array($opponent,['human','solo','easy','normal','expert'],true)||($mode!=='practice'&&$opponent!=='human'))throw new MultiplayerGameException('Solo and bots are available in Practice only.','ARCADE_SETTINGS_INVALID',422);
    return $settings===[]?[]:['opponent'=>$opponent];
}
function tetris_arcade_settings_projection(array $settings,string $mode,array $definition=[]): array {
    $settings=tetris_arcade_validate_settings($settings,$mode);
    return ['label'=>'Game Options','description'=>'Choose solo practice, a bot, or another player.','controls'=>$mode==='practice'?[['key'=>'opponent','type'=>'select','label'=>'Opponent','description'=>'Play alone, against another person, or against the selected bot.','value'=>$settings['opponent']??'human','defaultValue'=>'human','options'=>[['value'=>'human','label'=>'Another player'],['value'=>'solo','label'=>'Solo practice'],['value'=>'easy','label'=>'Easy bot'],['value'=>'normal','label'=>'Normal bot'],['value'=>'expert','label'=>'Expert bot']]]]:[]];
}
function tetris_arcade_virtual_members(PDO $pdo,array $state,array $context): array {return empty($state['tetrisBot'])?[]:[['userId'=>-7202,'seat'=>2,'displayName'=>ucfirst($state['tetrisBot']['difficulty']).' Bot','difficulty'=>$state['tetrisBot']['difficulty']]];}


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
    $settings=tetris_arcade_validate_settings((array)($context['settings']??[]),(string)($context['mode']??'practice'));
    $opponent=$settings['opponent']??'human';$context['settings']=[];
    $state = arcade_initial_state('tetris', $players, $opponent==='human'?2:1, $context);
    $state['settings']=$settings;
    if(in_array($opponent,['easy','normal','expert'],true)){
        $state['turnOrder'][]=-7202;$state['inputSequences'][-7202]=0;$state['inputBudgets'][-7202]=16;
        $state['tetrisBot']=['difficulty'=>$opponent,'waitMs'=>0,'plan'=>[]];
        $state['bots']=[-7202=>['userId'=>-7202,'seat'=>2,'difficulty'=>$opponent,'displayName'=>ucfirst($opponent).' Bot']];
    }
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

/** Reachable placements only: every path uses the same commands as a person. */
function tetris_bot_candidates(array $board): array {
    $queue=[[$board['active'],[]]];$head=0;$seen=[];$landings=[];
    while($head<count($queue)&&$head<900){
        [$piece,$path]=$queue[$head++];$key=$piece['rotation'].':'.$piece['col'].':'.$piece['row'];
        if(isset($seen[$key]))continue;$seen[$key]=true;
        $drop=$piece;while(tetris_arcade_fits($board,array_replace($drop,['row'=>$drop['row']+1])))$drop['row']++;
        $landing=$drop['rotation'].':'.$drop['col'].':'.$drop['row'];
        if(!isset($landings[$landing])){
            $cells=$board['cells'];foreach(tetris_arcade_matrix($drop)as$r=>$row)foreach($row as$c=>$filled)if($filled)$cells[$drop['row']+$r][$drop['col']+$c]=$drop['kind'];
            $kept=array_values(array_filter($cells,static fn($row)=>in_array('',$row,true)));$lines=20-count($kept);
            $cells=array_merge(array_fill(0,$lines,array_fill(0,10,'')),$kept);
            $heights=[];$holes=0;
            for($c=0;$c<10;$c++){$height=0;$found=false;for($r=0;$r<20;$r++){if($cells[$r][$c]!==''){if(!$found)$height=20-$r;$found=true;}elseif($found)$holes++;}$heights[]=$height;}
            $bump=0;for($c=1;$c<10;$c++)$bump+=abs($heights[$c]-$heights[$c-1]);
            $score=8*$lines-.52*array_sum($heights)-5*$holes-.32*$bump-.25*max($heights);
            $landings[$landing]=['path'=>[...$path,'drop'],'score'=>$score,'cells'=>$cells,'height'=>max($heights)];
        }
        // Descending paths permit tucks; never teleport through occupied cells.
        foreach(['left','right','cw','ccw','down']as$command){$next=$piece;if($command==='left')$next['col']--;if($command==='right')$next['col']++;if($command==='down')$next['row']++;if($command==='cw')$next['rotation']=($next['rotation']+1)%4;if($command==='ccw')$next['rotation']=($next['rotation']+3)%4;
            if(tetris_arcade_fits($board,$next)&&!isset($seen[$next['rotation'].':'.$next['col'].':'.$next['row']]))$queue[]=[$next,[...$path,$command]];}
    }
    return array_values($landings);
}
function tetris_bot_plan(array $board,string $difficulty): array {
    $choices=tetris_bot_candidates($board);if(!$choices)return ['drop'];
    foreach($choices as&$choice){
        if($difficulty==='easy')$choice['score']=-$choice['height']-.2*count($choice['path']);
        if($difficulty==='expert'&&!empty($board['queue'][0])){
            $next=$board;$next['cells']=$choice['cells'];$next['active']=['kind'=>$board['queue'][0],'rotation'=>0,'row'=>0,'col'=>3];
            $follow=tetris_arcade_fits($next,$next['active'])?tetris_bot_candidates($next):[];
            $choice['score']+=.65*($follow?max(array_column($follow,'score')):-10000);
        }
    }unset($choice);
    usort($choices,static fn($a,$b)=>$b['score']<=>$a['score']);return $choices[0]['path'];
}
function tetris_bot_tick(array &$state,int $milliseconds): void {
    if(empty($state['tetrisBot'])||empty($state['boards'][-7202]['alive']))return;
    $bot=&$state['tetrisBot'];$bot['waitMs']+=$milliseconds;
    $delay=['easy'=>1500,'normal'=>1000,'expert'=>800][$bot['difficulty']];
    if($bot['waitMs']<$delay)return;$bot['waitMs']-= $delay;
    // Recompute from the actual current board after gravity, then execute legal
    // controls. No hidden bag, seed, opponent board, or future pieces are used.
    foreach(tetris_bot_plan($state['boards'][-7202],$bot['difficulty'])as$command)tetris_arcade_command($state['boards'][-7202],$command);
}
