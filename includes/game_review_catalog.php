<?php
declare(strict_types=1);

/** Curated, executable examples. Identities are local review seats, not accounts. */
function game_review_catalog(): array
{
    $cases = [];
    $add = static function(string $game, string $name, string $mode, array $extra = []) use (&$cases): void {
        $colors = $extra['colors'] ?? [''];
        unset($extra['colors']);
        foreach ($colors as $color) {
            $key = $game . '-' . $mode . ($color !== '' ? '-' . $color : '');
            $cases[$key] = ['id'=>$key, 'game'=>$game, 'mode'=>$mode, 'color'=>$color,
                'label'=>$name . ($color !== '' ? ', ' . ucfirst($color) : ''),
                'instruction'=>'Use the game controls below, or Play example action. Reset repeats the same starting position.',
                'expected'=>'The legal action completes without losing or duplicating pieces.'] + $extra;
        }
    };
    foreach (['backgammon-first-party'=>'Backgammon','acey-deucy'=>'Acey Deucy'] as $game=>$name) {
        foreach (['move'=>'Move','hit'=>'Capture','stacked-hit'=>'Capture into occupied bar','entry'=>'Bar entry','bear-off'=>'Two consecutive bear-offs','final'=>'Final bear-off and victory','roll'=>'Roll dice','blocked'=>'Blocked bar entry'] as $mode=>$label) {
            $add($game, "$name — $label", $mode, ['colors'=>['white','black']]);
        }
        if ($game === 'acey-deucy') foreach (['european-double'=>'Complementary doubles','european-acey'=>'1–2 and chosen double','european-exact'=>'Exact bear-off','european-blocked'=>'Blocked double sequence'] as $mode=>$label) {
            $add($game,"$name — European Double-Double: $label",$mode,['rulesProfile'=>'european-double-double']);
        }
    }
    foreach (['checkers'=>['move'=>'Move','capture'=>'Capture','chain'=>'Multiple capture','promotion'=>'Promotion','capture-promotion'=>'Capture and promotion','final-capture'=>'Final capture','draw'=>'Accept draw','resign'=>'Resignation','timeout'=>'Clock expires'],
        'chess'=>['move'=>'Move','capture'=>'Capture','castle-king'=>'Kingside castling','castle-queen'=>'Queenside castling','en-passant'=>'En passant','promotion-q'=>'Promote to queen','promotion-r'=>'Promote to rook','promotion-b'=>'Promote to bishop','promotion-n'=>'Promote to knight','mate'=>'Checkmate','stalemate'=>'Stalemate','draw'=>'Accept draw','resign'=>'Resignation','timeout'=>'Clock expires']] as $game=>$modes) {
        foreach ($modes as $mode=>$label) $add($game,ucfirst($game)." — $label",$mode,['colors'=>['white','black']]);
    }
    foreach (['own','opponent'] as $view) {
        foreach (['miss','hit','win'] as $mode) $add('battleship','Battleship — '.ucfirst($mode).($view==='opponent'?' received':''),$mode.'-'.$view,['nativeMode'=>$mode,'view'=>$view]);
        foreach(range(1,5) as $length) foreach(['horizontal','vertical'] as $orientation) $add('battleship',"Battleship — Sink $length-cell ship, $orientation".($view==='opponent'?' received':''),"sunk-$length-$orientation-$view",['nativeMode'=>"sunk-$length-$orientation",'view'=>$view]);
    }
    $add('battleship','Battleship — Place and rotate fleet','placement');
    foreach(['deal'=>'Deal and bid','partner-pass'=>'Blind Nil partner exchange','play'=>'Play card','opponent-play'=>'Opponent plays card','ordinary-trick'=>'Collect trick','nil-set'=>'Nil fails','last-trick'=>'Last trick and score'] as $mode=>$label) $add('spades',"Spades — $label",$mode,['view'=>$mode==='opponent-play'?'opponent':'own']);
    foreach(['roll'=>'Roll and hold dice','opponent'=>'Opponent rolls','yahtzee'=>'Yahtzee reaction','upper'=>'Upper bonus','repeat'=>'Repeat Yahtzee','record'=>'Finish scorecard and record reaction'] as $mode=>$label) $add('five-dice',"Five Dice — $label",$mode);
    foreach(['hearts'=>['deal'=>'Deal','pass'=>'Pass three cards','play'=>'Play and collect trick','queen'=>'Queen of spades','moon'=>'Shoot the moon','finish'=>'End game'],
        'uno'=>['deal'=>'Deal','play'=>'Play card','draw'=>'Draw card','reverse'=>'Reverse','skip'=>'Skip','wild'=>'Wild color','draw-four'=>'Wild draw four','finish'=>'UNO and finish hand'],
        'chinese-checkers'=>['move'=>'Move','jump'=>'Jump','chain'=>'Multiple jumps','finish'=>'Final marble and victory'],
        'nested-four'=>['place'=>'Place piece','move'=>'Move and uncover','finish'=>'Four-in-a-row'],
        'blackjack'=>['deal'=>'Bet and deal','hit'=>'Hit','double'=>'Double down','split'=>'Split pair','finish'=>'Stand and settle'],
        'puppy-panic'=>['deal'=>'Deal','draw'=>'Draw card','power'=>'Power card','finish'=>'Last player'],
        'tetris-versus'=>['move'=>'Move and rotate','clear'=>'Clear line','finish'=>'Top out'],
        'space-invasion'=>['move'=>'Move and shoot','hit'=>'Enemy hit','finish'=>'Invasion ending']] as $game=>$modes) {
        $name=['uno'=>'UNO','tetris-versus'=>'Tetris Versus'][$game]??ucwords(str_replace('-',' ',$game));
        foreach($modes as $mode=>$label) $add($game,"$name — $label",$mode);
    }
    return $cases;
}
