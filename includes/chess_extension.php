<?php
declare(strict_types=1);

require_once __DIR__ . '/ocx_game_extension_support.php';
require_once __DIR__ . '/chess_bot_support.php';

const CHESS_EXTENSION_ID = 'chess';
const CHESS_STATE_SCHEMA_VERSION = 1;

function chess_recording_adapter(): array
{
    return ['schemaVersion' => 1,
        'stateKeys' => ['bots', 'botPosition', 'board', 'castling', 'clock', 'clocks', 'colorAssignments', 'completed', 'drawNoticeSequence', 'drawOfferBy', 'enPassant', 'expiredClockUserId', 'fullmoveNumber', 'halfmoveClock', 'history', 'lastDrawResponse', 'meaningfulPlay', 'movesByUser', 'positionCounts', 'resignedUserId', 'roundNumber', 'schemaVersion', 'settings', 'starterIndex', 'starterReason', 'starterUserId', 'terminalReason', 'turnIndex', 'turnOrder', 'winnerUserId'],
        'payloadKeys' => ['claim', 'from', 'promotion', 'to']];
}

function chess_extension_adapter(): array
{
    return [
        'recordingAdapter' => 'chess_recording_adapter',
        'id' => CHESS_EXTENSION_ID,
        'initialState' => 'chess_initial_state',
        'projectVirtualMembers' => 'chess_project_virtual_members',
        'applyAction' => 'chess_apply_action',
        'validateSettings' => 'chess_validate_settings',
        'settingsProjection' => 'chess_settings_projection',
        'rulesProjection' => 'chess_rules_projection',
        'projectState' => 'chess_project_state',
        'presentationStatus' => 'chess_presentation_status',
        'openingProcedure' => 'white-first-with-rematch-color-assignment-rotation',
        'rematchSeatRotation' => true,
    ];
}

function chess_presentation_status(PDO $pdo, ?string $requestedPack = null): array
{
    return ocx_game_presentation_status($pdo, CHESS_EXTENSION_ID, $requestedPack);
}

function chess_clock_profiles(): array
{
    return [
        'no-clock' => ['label' => 'No Clock', 'kind' => 'none'],
        'blitz-5' => ['label' => 'Blitz — 5+0', 'kind' => 'bank', 'seconds' => 300, 'increment' => 0],
        'rapid-15-10' => ['label' => 'Rapid — 15+10', 'kind' => 'bank', 'seconds' => 900, 'increment' => 10],
        'active-30' => ['label' => 'Active — 30+0', 'kind' => 'bank', 'seconds' => 1800, 'increment' => 0],
        'standard-60' => ['label' => 'Standard — 60+0', 'kind' => 'bank', 'seconds' => 3600, 'increment' => 0],
        'competition' => ['label' => 'Competition — 30 moves in 90 minutes, then 20 moves in 60 minutes', 'kind' => 'periods', 'periods' => [['moves' => 30, 'seconds' => 5400], ['moves' => 20, 'seconds' => 3600]]],
    ];
}

function chess_validate_settings(array $settings, string $mode, array $definition = []): array
{
    $allowed = ['botSeat2Difficulty', 'clockProfile', 'customInitialMinutes', 'customIncrementSeconds'];
    if (array_diff(array_keys($settings), $allowed)) throw new MultiplayerGameException('A Chess setting is not supported.', 'CHESS_SETTINGS_INVALID', 422);
    $profile = strtolower(trim((string)($settings['clockProfile'] ?? 'no-clock')));
    if ($profile !== 'custom' && !isset(chess_clock_profiles()[$profile])) throw new MultiplayerGameException('Choose a published Chess clock profile.', 'CHESS_CLOCK_PROFILE_INVALID', 422);
    $validated = ['clockProfile' => $profile];
    if ($profile === 'custom') {
        $initial = (int)($settings['customInitialMinutes'] ?? 30);
        $increment = (int)($settings['customIncrementSeconds'] ?? 0);
        if ($initial < 1 || $initial > 240 || $increment < 0 || $increment > 300) {
            throw new MultiplayerGameException('Custom Chess time must use a 1–240 minute bank with at most 300 seconds increment.', 'CHESS_CUSTOM_CLOCK_INVALID', 422);
        }
        $validated += ['customInitialMinutes' => $initial, 'customIncrementSeconds' => $increment];
    }
    $difficulty = (string)($settings['botSeat2Difficulty'] ?? 'none');
    if (!in_array($difficulty, array_column(chess_bot_choices(), 'value'), true)) throw new MultiplayerGameException('Choose a listed Chess bot strength.', 'CHESS_BOT_DIFFICULTY_INVALID', 422);
    if ($mode !== 'practice' && $difficulty !== 'none') throw new MultiplayerGameException('Games with bots are Practice only.', 'MULTIPLAYER_GAME_BOTS_PRACTICE_ONLY', 422);
    if ($mode === 'practice') $validated['botSeat2Difficulty'] = $difficulty;
    return $validated;
}

function chess_settings_projection(array $settings, string $mode, array $definition = []): array
{
    $settings = chess_validate_settings($settings, $mode, $definition);
    $profiles = [];
    foreach (chess_clock_profiles() as $value => $profile) {
        $profiles[] = ['value' => $value, 'label' => (string)$profile['label']];
    }
    $profiles[] = ['value' => 'custom', 'label' => 'Custom'];
    return [
        'label' => 'Game Options',
        'description' => 'Every player must accept the selected server-authoritative Chess clock before play begins.',
        'classification' => (string)$settings['clockProfile'],
        'classificationLabel' => (string)(chess_clock_profiles()[$settings['clockProfile']]['label'] ?? 'Custom clock'),
        'controls' => array_merge($mode === 'practice' ? [[
            'key' => 'botSeat2Difficulty', 'type' => 'select',
            'value' => $settings['botSeat2Difficulty'], 'defaultValue' => 'none',
            'label' => 'Empty seat 2 bot', 'description' => chess_bot_strength_note(),
            'options' => chess_bot_choices(),
        ]] : [], [[
            'key' => 'clockProfile',
            'type' => 'choice-grid',
            'value' => (string)$settings['clockProfile'],
            'defaultValue' => 'no-clock',
            'label' => 'Chess clock',
            'description' => 'Choose No Clock, Blitz 5+0, Rapid 15+10, Active 30+0, Standard 60+0, Competition, or a bounded Custom bank and increment. Only the current player’s clock runs.',
            'options' => $profiles,
        ], [
            'key' => 'customInitialMinutes', 'type' => 'stepper', 'value' => (int)($settings['customInitialMinutes'] ?? 30),
            'defaultValue' => 30, 'minimum' => 1, 'maximum' => 240, 'step' => 1,
            'label' => 'Custom bank minutes', 'description' => 'Used by the Custom time-bank method; 1 through 240 minutes.',
        ], [
            'key' => 'customIncrementSeconds', 'type' => 'stepper', 'value' => (int)($settings['customIncrementSeconds'] ?? 0),
            'defaultValue' => 0, 'minimum' => 0, 'maximum' => 300, 'step' => 1,
            'label' => 'Custom increment seconds', 'description' => 'Added after a completed move in the Custom time-bank method; 0 through 300 seconds.',
        ]]),
    ];
}

function chess_clock_descriptor(array $settings): array
{
    $profile = (string)$settings['clockProfile'];
    if ($profile !== 'custom') return chess_clock_profiles()[$profile];
    return ['label' => 'Custom — ' . $settings['customInitialMinutes'] . ' minutes with ' . $settings['customIncrementSeconds'] . ' second increment', 'kind' => 'bank', 'seconds' => (int)$settings['customInitialMinutes'] * 60, 'increment' => (int)$settings['customIncrementSeconds']];
}

function chess_rules_projection(array $settings, string $mode, array $definition = []): array
{
    $settings = chess_validate_settings($settings, $mode, $definition);
    $clock = chess_clock_descriptor($settings);
    return [
        'label' => 'Chess rules',
        'description' => 'The server validates every move, including check, castling, en passant, and promotion to Queen, Rook, Bishop, or Knight. A king is never captured: checkmate ends the game first. Checkmate wins. An eligible current player may claim a draw after the same position occurs three times or after 50 moves by each player without a pawn move or capture. CoreChat automatically draws after five occurrences or 75 moves by each player without a pawn move or capture; checkmate on the final move takes precedence. Stalemate, insufficient material, and mutual agreement also draw. The accepted clock profile is ' . $clock['label'] . '.',
        'sections' => [
            ['label' => 'Mode', 'text' => $mode === 'recorded' ? 'Ranked or Recorded Play updates Chess-only records after a valid completion.' : 'Practice Mode uses the same server move validation but never changes Recorded records.'],
            ['label' => 'Clock', 'text' => $clock['kind'] === 'none' ? 'No clock is active.' : 'The clock is server-authoritative. Only the current player’s accepted time bank runs. If time expires, the opponent wins only when a legal sequence could possibly produce checkmate; otherwise the game is a draw.'],
            ['label' => 'Turns', 'text' => 'Only the current player may move. Browser highlights are previews and never authorize a move.'],
            ['label' => 'Draw claims', 'text' => 'The current player may claim threefold repetition or the 50-move condition only when the current server-authoritative position and history qualify. A stale or replayed claim cannot change the game. Fivefold repetition and the 75-move condition are automatic draws, except that checkmate on the final move wins first.'],
            ['label' => 'Draw progress', 'text' => 'Current position appearances are shown with this guidance: A draw can be claimed after 3 appearances. It becomes automatic after 5. Each player’s moves since the last pawn move or capture are shown with this guidance: A draw can be claimed when both players reach 50 moves. It becomes automatic when both reach 75.'],
        ],
    ];
}

function chess_initial_board(): array
{
    $board = array_fill(0, 8, array_fill(0, 8, null));
    $order = ['R', 'N', 'B', 'Q', 'K', 'B', 'N', 'R'];
    for ($column = 0; $column < 8; $column++) {
        $board[0][$column] = 'b' . $order[$column];
        $board[1][$column] = 'bP';
        $board[6][$column] = 'wP';
        $board[7][$column] = 'w' . $order[$column];
    }
    return $board;
}

function chess_initial_clocks(array $players, array $clock, string $startedAt, int $starterUserId): array
{
    $clocks = [];
    foreach ($players as $userId) {
        $remaining = match ($clock['kind']) {
            'periods' => (int)$clock['periods'][0]['seconds'],
            'bank' => (int)$clock['seconds'],
            default => null,
        };
        $clocks[(string)$userId] = ['remainingSeconds' => $remaining, 'turnStartedAt' => null, 'movesInPeriod' => 0, 'periodIndex' => 0];
    }
    if ($clock['kind'] !== 'none') $clocks[(string)$starterUserId]['turnStartedAt'] = $startedAt;
    return $clocks;
}

function chess_initial_state(array $playerUserIds, array $context = []): array
{
    $players = array_values(array_unique(array_map('intval', $playerUserIds)));
    if (count($players) < 1 || count($players) > 2 || min($players) < 1) throw new MultiplayerGameException('Chess requires one or two authenticated players in Practice.', 'CHESS_PLAYER_SET_INVALID', 422);
    $settings = chess_validate_settings((array)($context['settings'] ?? []), (string)($context['mode'] ?? 'practice'));
    $bots = [];
    if (count($players) === 1 && ($context['mode'] ?? 'practice') === 'practice' && ($settings['botSeat2Difficulty'] ?? 'none') !== 'none') {
        $difficulty = $settings['botSeat2Difficulty'];
        $labels = array_column(chess_bot_choices(), 'label', 'value');
        $players[] = CHESS_BOT_ID;
        $bots[(string)CHESS_BOT_ID] = ['userId' => CHESS_BOT_ID, 'seat' => 2,
            'difficulty' => $difficulty, 'displayName' => $labels[$difficulty] . ' Bot', 'engine' => CHESS_BOT_ENGINE];
    }
    if (count($players) !== 2) throw new MultiplayerGameException('Another player must join, or choose a Practice bot.', 'MULTIPLAYER_GAME_MINIMUM_PLAYERS', 409);
    $clock = chess_clock_descriptor($settings);
    $startedAt = (string)($context['startedAt'] ?? gmdate('c'));
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
    $starterUserId = $players[$starterIndex];
    $state = [
        'schemaVersion' => CHESS_STATE_SCHEMA_VERSION,
        'turnOrder' => $players,
        'turnIndex' => $starterIndex,
        'roundNumber' => $roundNumber,
        'starterIndex' => $starterIndex,
        'starterUserId' => $starterUserId,
        'starterReason' => $roundNumber === 1
            ? 'White always moves first under the Chess rules.'
            : 'White always moves first, and player-to-color assignments rotate after an accepted rematch.',
        'colorAssignments' => [
            (string)$starterUserId => 'w',
            (string)$players[1 - $starterIndex] => 'b',
        ],
        'board' => chess_initial_board(),
        'castling' => ['wK' => true, 'wQ' => true, 'bK' => true, 'bQ' => true],
        'enPassant' => null,
        'halfmoveClock' => 0,
        'fullmoveNumber' => 1,
        'positionCounts' => [],
        'drawOfferBy' => null,
        'drawNoticeSequence' => 0,
        'lastDrawResponse' => null,
        'settings' => $settings,
        'clock' => $clock,
        'clocks' => chess_initial_clocks($players, $clock, $startedAt, $starterUserId),
        'movesByUser' => array_fill_keys(array_map('strval', $players), 0),
        'history' => [],
        'meaningfulPlay' => false,
        'completed' => false,
    ];
    if ($bots !== []) { $state['bots'] = $bots; $state['botPosition'] = ['fen' => chess_bot_fen($state), 'moves' => []]; }
    chess_record_position($state);
    return $state;
}

function chess_square(mixed $value): array
{
    if (!is_array($value) || count($value) !== 2 || !is_numeric($value[0] ?? null) || !is_numeric($value[1] ?? null)) throw new MultiplayerGameException('Choose a valid chess square.', 'CHESS_SQUARE_INVALID', 422);
    $row = (int)$value[0]; $column = (int)$value[1];
    if ($row < 0 || $row > 7 || $column < 0 || $column > 7) throw new MultiplayerGameException('Choose a valid chess square.', 'CHESS_SQUARE_INVALID', 422);
    return [$row, $column];
}

function chess_color_for_user(array $state, int $userId): string
{
    $assigned = strtolower((string)($state['colorAssignments'][(string)$userId] ?? ''));
    if (in_array($assigned, ['w', 'b'], true)) return $assigned;
    $seat = array_search($userId, array_map('intval', $state['turnOrder']), true);
    if ($seat === false) throw new MultiplayerGameException('Only a Chess player may act.', 'CHESS_PLAYER_INVALID', 403);
    return $seat === 0 ? 'w' : 'b';
}

function chess_opponent_user(array $state, int $userId): int
{
    foreach ($state['turnOrder'] as $candidate) if ((int)$candidate !== $userId) return (int)$candidate;
    throw new MultiplayerGameException('The opposing Chess player is unavailable.', 'CHESS_PLAYER_SET_INVALID', 409);
}

function chess_piece_color(?string $piece): ?string
{
    return is_string($piece) && preg_match('/^[wb][KQRBNP]$/', $piece) ? $piece[0] : null;
}

function chess_inside(int $row, int $column): bool
{
    return $row >= 0 && $row < 8 && $column >= 0 && $column < 8;
}

function chess_square_attacked(array $board, int $row, int $column, string $byColor): bool
{
    $pawnRow = $row + ($byColor === 'w' ? 1 : -1);
    foreach ([-1, 1] as $dc) if (chess_inside($pawnRow, $column + $dc) && ($board[$pawnRow][$column + $dc] ?? null) === $byColor . 'P') return true;
    foreach ([[-2,-1],[-2,1],[-1,-2],[-1,2],[1,-2],[1,2],[2,-1],[2,1]] as [$dr,$dc]) if (chess_inside($row+$dr,$column+$dc) && ($board[$row+$dr][$column+$dc] ?? null) === $byColor . 'N') return true;
    foreach ([[-1,-1],[-1,1],[1,-1],[1,1]] as [$dr,$dc]) {
        for ($step=1;$step<8;$step++) { $r=$row+$dr*$step; $c=$column+$dc*$step; if(!chess_inside($r,$c)) break; $piece=$board[$r][$c]; if($piece===null) continue; if($piece===$byColor.'B'||$piece===$byColor.'Q'||($step===1&&$piece===$byColor.'K')) return true; break; }
    }
    foreach ([[-1,0],[1,0],[0,-1],[0,1]] as [$dr,$dc]) {
        for ($step=1;$step<8;$step++) { $r=$row+$dr*$step; $c=$column+$dc*$step; if(!chess_inside($r,$c)) break; $piece=$board[$r][$c]; if($piece===null) continue; if($piece===$byColor.'R'||$piece===$byColor.'Q'||($step===1&&$piece===$byColor.'K')) return true; break; }
    }
    return false;
}

function chess_king_square(array $board, string $color): ?array
{
    for ($row=0;$row<8;$row++) for ($column=0;$column<8;$column++) if (($board[$row][$column] ?? null)===$color.'K') return [$row,$column];
    return null;
}

function chess_in_check(array $board, string $color): bool
{
    $king = chess_king_square($board, $color);
    if ($king === null) return true;
    return chess_square_attacked($board, $king[0], $king[1], $color === 'w' ? 'b' : 'w');
}

function chess_pseudo_moves(array $state, int $row, int $column): array
{
    $board = $state['board']; $piece = $board[$row][$column] ?? null; $color = chess_piece_color($piece);
    if ($color === null) return [];
    $type = $piece[1]; $moves = [];
    $add = static function(int $r,int $c,array $extra=[]) use (&$moves,$row,$column,$board,$color): void {
        if (!chess_inside($r,$c)) return;
        $target = $board[$r][$c] ?? null;
        if (is_string($target) && chess_piece_color($target) !== $color && ($target[1] ?? '') === 'K') return;
        $moves[]=['from'=>[$row,$column],'to'=>[$r,$c]]+$extra;
    };
    if ($type === 'P') {
        $direction = $color === 'w' ? -1 : 1; $start = $color === 'w' ? 6 : 1; $promotionRow = $color === 'w' ? 0 : 7;
        if (chess_inside($row+$direction,$column) && $board[$row+$direction][$column]===null) {
            $add($row+$direction,$column,['promotionRequired'=>$row+$direction===$promotionRow]);
            if ($row===$start && $board[$row+2*$direction][$column]===null) $add($row+2*$direction,$column,['doublePawn'=>true]);
        }
        foreach([-1,1] as $dc){$r=$row+$direction;$c=$column+$dc;if(!chess_inside($r,$c))continue;$target=$board[$r][$c];if($target!==null&&chess_piece_color($target)!==$color)$add($r,$c,['promotionRequired'=>$r===$promotionRow]);elseif(($state['enPassant']??null)===[$r,$c])$add($r,$c,['enPassant'=>true]);}
    } elseif ($type === 'N') {
        foreach([[-2,-1],[-2,1],[-1,-2],[-1,2],[1,-2],[1,2],[2,-1],[2,1]] as [$dr,$dc]){$r=$row+$dr;$c=$column+$dc;if(chess_inside($r,$c)&&chess_piece_color($board[$r][$c]??null)!==$color)$add($r,$c);}
    } elseif (in_array($type,['B','R','Q'],true)) {
        $directions = $type==='B'?[[-1,-1],[-1,1],[1,-1],[1,1]]:($type==='R'?[[-1,0],[1,0],[0,-1],[0,1]]:[[-1,-1],[-1,1],[1,-1],[1,1],[-1,0],[1,0],[0,-1],[0,1]]);
        foreach($directions as [$dr,$dc])for($step=1;$step<8;$step++){$r=$row+$dr*$step;$c=$column+$dc*$step;if(!chess_inside($r,$c))break;$target=$board[$r][$c];if(chess_piece_color($target)===$color)break;$add($r,$c);if($target!==null)break;}
    } elseif ($type === 'K') {
        foreach([[-1,-1],[-1,0],[-1,1],[0,-1],[0,1],[1,-1],[1,0],[1,1]] as [$dr,$dc]){$r=$row+$dr;$c=$column+$dc;if(chess_inside($r,$c)&&chess_piece_color($board[$r][$c]??null)!==$color)$add($r,$c);}
        $homeRow=$color==='w'?7:0;$opponent=$color==='w'?'b':'w';
        if($row===$homeRow&&$column===4&&!chess_in_check($board,$color)){
            if(!empty($state['castling'][$color.'K'])&&$board[$homeRow][7]===$color.'R'&&$board[$homeRow][5]===null&&$board[$homeRow][6]===null&&!chess_square_attacked($board,$homeRow,5,$opponent)&&!chess_square_attacked($board,$homeRow,6,$opponent))$add($homeRow,6,['castle'=>'king']);
            if(!empty($state['castling'][$color.'Q'])&&$board[$homeRow][0]===$color.'R'&&$board[$homeRow][1]===null&&$board[$homeRow][2]===null&&$board[$homeRow][3]===null&&!chess_square_attacked($board,$homeRow,3,$opponent)&&!chess_square_attacked($board,$homeRow,2,$opponent))$add($homeRow,2,['castle'=>'queen']);
        }
    }
    return $moves;
}

function chess_apply_board_move(array $state, array $move, ?string $promotion = null): array
{
    $board=$state['board'];[$fr,$fc]=$move['from'];[$tr,$tc]=$move['to'];$piece=$board[$fr][$fc];$captured=$board[$tr][$tc];$board[$fr][$fc]=null;
    if (is_string($captured) && ($captured[1] ?? '') === 'K') throw new MultiplayerGameException('A king cannot be captured; checkmate ends the game first.','CHESS_KING_CAPTURE_PROHIBITED',409);
    if(!empty($move['enPassant'])){$captureRow=$tr+($piece[0]==='w'?1:-1);$captured=$board[$captureRow][$tc];$board[$captureRow][$tc]=null;}
    if(!empty($move['promotionRequired'])){$promotion=strtoupper((string)$promotion);if(!in_array($promotion,['Q','R','B','N'],true))throw new MultiplayerGameException('Choose Queen, Rook, Bishop, or Knight for promotion.','CHESS_PROMOTION_REQUIRED',422);$piece=$piece[0].$promotion;}
    $board[$tr][$tc]=$piece;
    if(($move['castle']??'')==='king'){$board[$tr][$tc-1]=$board[$tr][7];$board[$tr][7]=null;}
    if(($move['castle']??'')==='queen'){$board[$tr][$tc+1]=$board[$tr][0];$board[$tr][0]=null;}
    return ['board'=>$board,'captured'=>$captured,'piece'=>$piece];
}

function chess_legal_moves(array $state, string $color): array
{
    $moves=[];
    for($row=0;$row<8;$row++)for($column=0;$column<8;$column++){
        if(chess_piece_color($state['board'][$row][$column]??null)!==$color)continue;
        foreach(chess_pseudo_moves($state,$row,$column) as $move){
            $promotions=!empty($move['promotionRequired'])?['Q','R','B','N']:[null];
            foreach($promotions as $promotion){$applied=chess_apply_board_move($state,$move,$promotion);if(!chess_in_check($applied['board'],$color))$moves[]=$move+['promotion'=>$promotion];}
        }
    }
    return $moves;
}

function chess_project_state(array $state, int $viewerUserId, array $context): array
{
    $projection = $state;
    if ((int)($projection['lastDrawResponse']['forUserId'] ?? 0) !== $viewerUserId) {
        $projection['lastDrawResponse'] = null;
    }
    $interaction = [
        'authority' => 'server-legal-move-projection',
        'selectableOrigins' => [],
        'legalDestinationsByOrigin' => [],
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
        $moves = chess_legal_moves($state, chess_color_for_user($state, $viewerUserId));
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
    }
    $projection['botTask'] = chess_bot_task($state, $viewerUserId, $context);
    $projection['interaction'] = $interaction;
    $projection['drawClaims'] = chess_draw_claim_eligibility($state, $viewerUserId);
    $projection['drawProgress'] = chess_draw_progress_projection($state);
    return $projection;
}

function chess_draw_progress_projection(array $state): array
{
    $turnIndex = (int)($state['turnIndex'] ?? -1);
    $currentUserId = (int)($state['turnOrder'][$turnIndex] ?? 0);
    $positionOccurrences = 0;
    if ($currentUserId !== 0) {
        $key = chess_position_key($state);
        $positionOccurrences = (int)($state['positionCounts'][$key] ?? 0);
    }
    $consecutiveMoves = max(0, (int)($state['halfmoveClock'] ?? 0));
    $sideToMove = $currentUserId !== 0 ? chess_color_for_user($state, $currentUserId) : 'w';
    $lastMover = $sideToMove === 'w' ? 'b' : 'w';
    $movesByColor = [
        $lastMover => intdiv($consecutiveMoves + 1, 2),
        $sideToMove => intdiv($consecutiveMoves, 2),
    ];
    $movesByUser = [];
    foreach ((array)($state['turnOrder'] ?? []) as $userId) {
        $userId = (int)$userId;
        if ($userId !== 0) $movesByUser[(string)$userId] = (int)($movesByColor[chess_color_for_user($state, $userId)] ?? 0);
    }
    return [
        'positionAppearances' => $positionOccurrences,
        'movesByUser' => $movesByUser,
        'claimAfterAppearances' => 3,
        'automaticAfterAppearances' => 5,
        'claimAfterMovesPerPlayer' => 50,
        'automaticAfterMovesPerPlayer' => 75,
        'completed' => !empty($state['completed']),
        'terminalReason' => (string)($state['terminalReason'] ?? ''),
    ];
}

function chess_effective_en_passant_target(array $state): ?array
{
    $target = $state['enPassant'] ?? null;
    if (!is_array($target) || count($target) !== 2) return null;
    $turnIndex = (int)($state['turnIndex'] ?? -1);
    $userId = (int)($state['turnOrder'][$turnIndex] ?? 0);
    if ($userId === 0) return null;
    try {
        $color = chess_color_for_user($state, $userId);
        foreach (chess_legal_moves($state, $color) as $move) {
            if (!empty($move['enPassant']) && ($move['to'] ?? null) === $target) return [(int)$target[0], (int)$target[1]];
        }
    } catch (MultiplayerGameException) {
        return null;
    }
    return null;
}

function chess_position_key(array $state): string
{
    $turnIndex = (int)($state['turnIndex'] ?? -1);
    $userId = (int)($state['turnOrder'][$turnIndex] ?? 0);
    $sideToMove = $userId !== 0 ? chess_color_for_user($state, $userId) : null;
    return strtoupper(hash('sha256',multiplayer_game_canonical_json([
        'board'=>$state['board'],
        'sideToMove'=>$sideToMove,
        'castling'=>$state['castling'],
        'effectiveEnPassant'=>chess_effective_en_passant_target($state),
    ])));
}

function chess_record_position(array &$state): int
{
    $key=chess_position_key($state);$state['positionCounts'][$key]=(int)($state['positionCounts'][$key]??0)+1;
    return (int)$state['positionCounts'][$key];
}

function chess_draw_claim_eligibility(array $state, int $actorUserId): array
{
    $turnIndex = (int)($state['turnIndex'] ?? -1);
    $currentUserId = (int)($state['turnOrder'][$turnIndex] ?? 0);
    $positionOccurrences = 0;
    if ($currentUserId !== 0) {
        $key = chess_position_key($state);
        $positionOccurrences = (int)($state['positionCounts'][$key] ?? 0);
    }
    $eligibleCurrentPlayer = empty($state['completed']) && $actorUserId !== 0 && $actorUserId === $currentUserId;
    return [
        'eligibleCurrentPlayer' => $eligibleCurrentPlayer,
        'positionOccurrences' => $positionOccurrences,
        'halfmovesWithoutPawnMoveOrCapture' => (int)($state['halfmoveClock'] ?? 0),
        'threefold' => $eligibleCurrentPlayer && $positionOccurrences >= 3,
        'fiftyMove' => $eligibleCurrentPlayer && (int)($state['halfmoveClock'] ?? 0) >= 100,
    ];
}

function chess_insufficient_material(array $board): bool
{
    $pieces=[];for($r=0;$r<8;$r++)for($c=0;$c<8;$c++)if(($board[$r][$c]??null)!==null)$pieces[]=['piece'=>$board[$r][$c],'square'=>[$r,$c]];
    $nonKings=array_values(array_filter($pieces,static fn(array $entry):bool=>$entry['piece'][1]!=='K'));
    if($nonKings===[])return true;
    if(count($nonKings)===1&&in_array($nonKings[0]['piece'][1],['B','N'],true))return true;
    if(array_filter($nonKings,static fn(array $entry):bool=>$entry['piece'][1]!=='B')===[]){$colors=[];foreach($nonKings as $entry)$colors[]=(array_sum($entry['square'])%2);return count(array_unique($colors))===1;}
    return false;
}

function chess_can_possibly_checkmate(array $board, string $color): bool
{
    $attackers = [];
    $defenders = [];
    for ($row = 0; $row < 8; $row++) for ($column = 0; $column < 8; $column++) {
        $piece = $board[$row][$column] ?? null;
        if (!is_string($piece) || ($piece[1] ?? '') === 'K') continue;
        $entry = ['type' => $piece[1], 'squareColor' => ($row + $column) % 2];
        if ($piece[0] === $color) $attackers[] = $entry; else $defenders[] = $entry;
    }
    if ($attackers === []) return false;
    foreach ($attackers as $entry) if (in_array($entry['type'], ['P', 'R', 'Q'], true)) return true;
    if (count($attackers) >= 2) {
        $onlyBishops = array_filter($attackers, static fn(array $entry): bool => $entry['type'] !== 'B') === [];
        if (!$onlyBishops || $defenders !== []) return true;
        return count(array_unique(array_column($attackers, 'squareColor'))) > 1;
    }
    return $defenders !== [];
}

function chess_now(array $context): int
{
    $parsed=strtotime((string)($context['now']??''));return $parsed===false?time():$parsed;
}

function chess_settle_active_clock(array &$state,array $context): ?int
{
    if(($state['clock']['kind']??'none')==='none')return null;
    $active=(int)$state['turnOrder'][(int)$state['turnIndex']];$clock=&$state['clocks'][(string)$active];$now=chess_now($context);
    if($clock['turnStartedAt']!==null){$started=strtotime((string)$clock['turnStartedAt']);if($started!==false){$elapsed=max(0,$now-$started);$clock['remainingSeconds']=max(0,(int)$clock['remainingSeconds']-$elapsed);$clock['turnStartedAt']=gmdate('c',$now);}}
    return (int)$clock['remainingSeconds']<=0?$active:null;
}

function chess_start_next_clock(array &$state,int $movedUserId,array $context): void
{
    if(($state['clock']['kind']??'none')==='none')return;
    $now=chess_now($context);$moved=&$state['clocks'][(string)$movedUserId];$kind=(string)$state['clock']['kind'];
    if($kind==='bank')$moved['remainingSeconds']=(int)$moved['remainingSeconds']+(int)($state['clock']['increment']??0);
    elseif($kind==='periods'){$moved['movesInPeriod']=(int)$moved['movesInPeriod']+1;$period=(int)$moved['periodIndex'];$required=(int)$state['clock']['periods'][$period]['moves'];if($moved['movesInPeriod']>=$required&&isset($state['clock']['periods'][$period+1])){$moved['periodIndex']=$period+1;$moved['movesInPeriod']=0;$moved['remainingSeconds']+=(int)$state['clock']['periods'][$period+1]['seconds'];}}
    $moved['turnStartedAt']=null;
    $next=(int)$state['turnOrder'][(int)$state['turnIndex']];$state['clocks'][(string)$next]['turnStartedAt']=gmdate('c',$now);
}

function chess_terminal(array &$state,?int $winner,string $reason): array
{
    $state['completed']=true;$state['terminalReason']=$reason;$state['winnerUserId']=$winner;
    $scores=[];foreach($state['turnOrder'] as $userId)$scores[(string)(int)$userId]=$winner===null?0.5:((int)$userId===$winner?1:0);
    return ['state'=>$state,'turnUserId'=>null,'terminal'=>true,'result'=>ocx_game_result_from_scores($scores)];
}

function chess_update_castling(array &$state,string $movedPiece,array $from,array $to,?string $captured): void
{
    if($movedPiece==='wK'){$state['castling']['wK']=false;$state['castling']['wQ']=false;}
    if($movedPiece==='bK'){$state['castling']['bK']=false;$state['castling']['bQ']=false;}
    foreach([['piece'=>'wR','square'=>[7,0],'right'=>'wQ'],['piece'=>'wR','square'=>[7,7],'right'=>'wK'],['piece'=>'bR','square'=>[0,0],'right'=>'bQ'],['piece'=>'bR','square'=>[0,7],'right'=>'bK']] as $rook){if($movedPiece===$rook['piece']&&$from===$rook['square'])$state['castling'][$rook['right']]=false;if($captured===$rook['piece']&&$to===$rook['square'])$state['castling'][$rook['right']]=false;}
}

function chess_apply_action_core(array $state,int $actorUserId,string $action,array $payload,array $context): array
{
    if((int)($state['schemaVersion']??0)!==CHESS_STATE_SCHEMA_VERSION||!empty($state['completed']))throw new MultiplayerGameException('The Chess state is unavailable.','CHESS_STATE_INVALID',409);
    $expired=chess_settle_active_clock($state,$context);
    if($expired!==null){
        // Preserve the server-confirmed expired-clock owner even when
        // insufficient mating material makes the result a draw. The Classic
        // viewer uses this identity for the original viewer-relative cue.
        $state['expiredClockUserId']=$expired;
        $opponent=chess_opponent_user($state,$expired);
        $opponentColor=chess_color_for_user($state,$opponent);
        return chess_can_possibly_checkmate($state['board'],$opponentColor)
            ? chess_terminal($state,$opponent,'clock-expiration')
            : chess_terminal($state,null,'clock-expiration-insufficient-mating-material');
    }
    $players = array_map('intval', (array)$state['turnOrder']);
    $pendingDrawOfferBy = (int)($state['drawOfferBy'] ?? 0);
    if ($pendingDrawOfferBy > 0 && !in_array($action, ['accept-draw', 'decline-draw', 'settle-clock'], true)) {
        throw new MultiplayerGameException('Accept or decline the pending draw proposal before continuing.', 'CHESS_DRAW_RESPONSE_REQUIRED', 409);
    }
    if ($action === 'settle-clock') {
        throw new MultiplayerGameException('The active Chess clock has not expired.', 'CHESS_CLOCK_NOT_EXPIRED', 409);
    }
    if($action==='resign')return chess_terminal($state,chess_opponent_user($state,$actorUserId),'resignation');
    if($action==='offer-draw'){
        if(!in_array($actorUserId,$players,true))throw new MultiplayerGameException('Only a player may offer a draw.','CHESS_PLAYER_INVALID',403);
        if($pendingDrawOfferBy>0)throw new MultiplayerGameException('A draw proposal is already pending.','CHESS_DRAW_OFFER_PENDING',409);
        $state['drawOfferBy']=$actorUserId;$state['lastDrawResponse']=null;
        return ['state'=>$state,'turnUserId'=>(int)$state['turnOrder'][(int)$state['turnIndex']]];
    }
    if($action==='accept-draw'){
        if(!in_array($actorUserId,$players,true))throw new MultiplayerGameException('Only a player may accept a draw.','CHESS_PLAYER_INVALID',403);
        if($pendingDrawOfferBy<1||$pendingDrawOfferBy===$actorUserId)throw new MultiplayerGameException('There is no opponent draw offer to accept.','CHESS_DRAW_OFFER_INVALID',409);
        $state['drawOfferBy']=null;
        return chess_terminal($state,null,'mutual-agreement');
    }
    if($action==='decline-draw'){
        if(!in_array($actorUserId,$players,true))throw new MultiplayerGameException('Only a player may decline a draw.','CHESS_PLAYER_INVALID',403);
        if($pendingDrawOfferBy<1||$pendingDrawOfferBy===$actorUserId)throw new MultiplayerGameException('There is no opponent draw offer to decline.','CHESS_DRAW_OFFER_INVALID',409);
        $state['drawOfferBy']=null;
        $state['drawNoticeSequence']=(int)($state['drawNoticeSequence']??0)+1;
        $state['lastDrawResponse']=['type'=>'declined','forUserId'=>$pendingDrawOfferBy,'byUserId'=>$actorUserId,'sequence'=>(int)$state['drawNoticeSequence']];
        return ['state'=>$state,'turnUserId'=>(int)$state['turnOrder'][(int)$state['turnIndex']]];
    }
    if($action==='claim-draw'){
        ocx_game_assert_turn($state,$actorUserId);
        $claim=strtolower(trim((string)($payload['claim']??'')));
        $eligibility=chess_draw_claim_eligibility($state,$actorUserId);
        if($claim==='threefold'&&!empty($eligibility['threefold']))return chess_terminal($state,null,'claimed-threefold-repetition');
        if($claim==='fifty-move'&&!empty($eligibility['fiftyMove']))return chess_terminal($state,null,'claimed-fifty-move-rule');
        if(!in_array($claim,['threefold','fifty-move'],true))throw new MultiplayerGameException('Choose a supported Chess draw claim.','CHESS_DRAW_CLAIM_INVALID',422);
        throw new MultiplayerGameException('The current server-authoritative position does not qualify for that draw claim.','CHESS_DRAW_CLAIM_PREMATURE',409);
    }
    if($action!=='move')throw new MultiplayerGameException('This Chess action is not supported.','CHESS_ACTION_INVALID',422);
    ocx_game_assert_turn($state,$actorUserId);$color=chess_color_for_user($state,$actorUserId);[$fr,$fc]=chess_square($payload['from']??null);[$tr,$tc]=chess_square($payload['to']??null);
    if(chess_piece_color($state['board'][$fr][$fc]??null)!==$color)throw new MultiplayerGameException('Choose one of your Chess pieces.','CHESS_PIECE_INVALID',422);
    $promotion=strtoupper(trim((string)($payload['promotion']??'')));$legal=chess_legal_moves($state,$color);
    $selected=current(array_filter($legal,static fn(array $move):bool=>$move['from']===[$fr,$fc]&&$move['to']===[$tr,$tc]&&((string)($move['promotion']??'')===$promotion||empty($move['promotion']))));
    if(!is_array($selected))throw new MultiplayerGameException('That Chess move is not legal.','CHESS_MOVE_ILLEGAL',422);
    $state['meaningfulPlay']=true;
    $movedPiece=(string)$state['board'][$fr][$fc];$applied=chess_apply_board_move($state,$selected,$promotion);$captured=$applied['captured'];$state['board']=$applied['board'];chess_update_castling($state,$movedPiece,[$fr,$fc],[$tr,$tc],$captured);
    $state['enPassant']=!empty($selected['doublePawn'])?[($fr+$tr)/2,$fc]:null;if(is_array($state['enPassant']))$state['enPassant']=[(int)$state['enPassant'][0],(int)$state['enPassant'][1]];
    $irreversible=$movedPiece[1]==='P'||$captured!==null;
    $state['halfmoveClock']=$irreversible?0:(int)$state['halfmoveClock']+1;
    if($irreversible)$state['positionCounts']=[];
    $state['history'][]=['actorUserId'=>$actorUserId,'from'=>[$fr,$fc],'to'=>[$tr,$tc],'piece'=>$movedPiece,'promotion'=>$promotion?:null,'capture'=>$captured!==null,'check'=>false];
    $state['movesByUser'][(string)$actorUserId]=(int)($state['movesByUser'][(string)$actorUserId]??0)+1;
    if(count($state['history'])>512)$state['history']=array_slice($state['history'],-512);if($color==='b')$state['fullmoveNumber']=(int)$state['fullmoveNumber']+1;
    $next=ocx_game_advance_turn($state);chess_start_next_clock($state,$actorUserId,$context);chess_bot_track_move($state,$payload);$nextColor=$color==='w'?'b':'w';$inCheck=chess_in_check($state['board'],$nextColor);$state['history'][array_key_last($state['history'])]['check']=$inCheck;$nextMoves=chess_legal_moves($state,$nextColor);
    if($nextMoves===[])return $inCheck?chess_terminal($state,$actorUserId,'checkmate'):chess_terminal($state,null,'stalemate');
    $positionOccurrences=chess_record_position($state);
    if($positionOccurrences>=5)return chess_terminal($state,null,'automatic-fivefold-repetition');
    if((int)$state['halfmoveClock']>=150)return chess_terminal($state,null,'automatic-seventy-five-move-rule');
    if(chess_insufficient_material($state['board']))return chess_terminal($state,null,'insufficient-material');
    return ['state'=>$state,'turnUserId'=>$next];
}
