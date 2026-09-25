<?php
declare(strict_types=1);
require_once __DIR__ . '/arcade_extension_support.php';
require_once __DIR__ . '/space_arcade_frame_input.php';
require_once __DIR__ . '/space_arcade_coop.php';

function space_arcade_extension_adapter(): array {
    return array_replace(arcade_adapter('space-invasion', 'space_arcade'), [
        'validateSettings'=>'space_arcade_validate_settings', 'settingsProjection'=>'space_arcade_settings_projection']);
}

function space_arcade_validate_settings(array $settings, string $mode, array $definition = []): array
{
    if ($mode !== 'practice') throw new MultiplayerGameException('Space Invasion supports solo or cooperative Practice.', 'ARCADE_PRACTICE_ONLY', 422);
    if (array_diff(array_keys($settings), ['playerCount']) || !in_array($settings['playerCount'] ?? 1, [1,2], true)) {
        throw new MultiplayerGameException('Choose Solo or Two-player co-op.', 'ARCADE_SETTINGS_INVALID', 422);
    }
    return $settings === [] ? [] : ['playerCount'=>$settings['playerCount']];
}

function space_arcade_settings_projection(array $settings, string $mode, array $definition = []): array
{
    $settings=space_arcade_validate_settings($settings,$mode);
    return ['label'=>'Game Options','description'=>'Choose the number of players before starting. Both ships defend the same waves; scores remain separate. Practice only.',
        'controls'=>[['key'=>'playerCount','type'=>'select','value'=>$settings['playerCount'] ?? 1,'defaultValue'=>1,
            'label'=>'Players','description'=>'Solo starts immediately. Two-player co-op waits for the second player. Seats are fixed after the run starts; leaving ends the shared run.',
            'options'=>[['value'=>1,'label'=>'Solo'],['value'=>2,'label'=>'Two-player co-op']]]]];
}

function space_arcade_rules_projection(array $settings, string $mode, array $definition = []): array
{
    return ['label' => 'Space Invasion rules', 'description' => 'Defend the planet in solo or two-player cooperative Practice.', 'sections' => [
        ['label'=>'Players','text'=>'Choose Solo or Two-player co-op before starting. Co-op shares the alien waves, with separate ships and scores. Player 1 fires blue shots; Player 2 fires red shots. Each alien can award points only once. Leaving or resigning ends the shared run; reconnecting restores it while it remains active.'],
        ['label' => 'Controls', 'text' => 'Left/Right or A/D move. Space, Up, W or Enter fires. You can also hold the on-screen controls.'],
        ['label' => 'Score and levels', 'text' => 'Each alien is worth 10 points. Clear a wave to reach the next level. Later waves move faster and grow from five to at most seven rows.'],
        ['label' => 'Ending and history', 'text' => 'Your run ends when an alien reaches the defence line or you resign. Final score, highest reached level, gameplay duration and ending reason are retained with this Practice session, not competitive rankings.'],
        ['label' => 'Pause', 'text' => 'Use the shared Pause control before taking a break. Reloading restores the server-owned run, not a new game.'],
    ]];
}

function space_arcade_initial_state(array $players, array $context = []): array
{
    if (($context['mode'] ?? 'practice') !== 'practice') {
        throw new MultiplayerGameException('Space Invasion supports solo or cooperative Practice.', 'ARCADE_PRACTICE_ONLY', 422);
    }
    $settings=space_arcade_validate_settings((array)($context['settings'] ?? []), 'practice');
    $count=$settings['playerCount'] ?? 1;
    $context['settings']=[];
    $state = arcade_initial_state('space', $players, $count, $context);
    $state += ['spaceInputMode' => 'frames', 'level' => 1, 'levelElapsedMs' => -300, 'kills' => [], 'orbs' => [],
        'ship' => ['x' => 268.8, 'score' => 0, 'cooldownMs' => 0, 'inputLeaseMs' => 0,
            'controls' => ['left' => false, 'right' => false, 'fire' => false]]];
    if ($count===2) {
        $state['ships']=[$state['turnOrder'][0]=>$state['ship'],$state['turnOrder'][1]=>array_replace($state['ship'],['x'=>691.2])];
        $state['spaceInputMode']='coop-frames';
        $state['spaceInputThrough']=array_fill_keys($state['turnOrder'],0);
        $state['spaceFrameInputs']=array_fill_keys($state['turnOrder'],[]);
    }
    return $state;
}

function space_arcade_aliens(array $state): array
{
    $level = $state['level'];
    $rows = min(7, 5 + intdiv($level-1, 2));
    $stepMs = max(150, 480-($level-1)*42);
    $step = intdiv(max(0, $state['levelElapsedMs']), $stepMs);
    $cycle = intdiv($step, 4); $phase = $step % 4;
    $offsetX = ($cycle % 2 === 0 ? $phase : 3-$phase)*44;
    $offsetY = $cycle*(16+min(14, ($level-1)*2));
    $startY = max(48, 80-min(22, ($level-1)*4));
    $aliens = [];
    for ($r = 0; $r < $rows; $r++) for ($c = 0; $c < 11; $c++) {
        $id = $r*11+$c;
        if (!isset($state['kills'][$id])) $aliens[] = ['id' => $id, 'x' => 80+$c*62+$offsetX, 'y' => $startY+$r*48+$offsetY];
    }
    return $aliens;
}

function space_arcade_tick(array &$state, int $milliseconds): void
{
    if(isset($state['ships'])){space_arcade_coop_tick($state,$milliseconds);return;}
    $ship = &$state['ship'];
    $ship['cooldownMs'] = max(0, $ship['cooldownMs']-$milliseconds);
    $ship['inputLeaseMs'] = max(0, $ship['inputLeaseMs']-$milliseconds);
    if ($ship['inputLeaseMs'] === 0) $ship['controls'] = ['left' => false, 'right' => false, 'fire' => false];
    $controls = $ship['controls'];
    $ship['x'] = max(30, min(930, $ship['x'] + ((int)$controls['right']-(int)$controls['left'])*380*$milliseconds/1000));
    $state['levelElapsedMs'] += $milliseconds;
    if ($state['levelElapsedMs'] < 0) return;
    if ($controls['fire'] && $ship['cooldownMs'] === 0) {
        $state['orbs'][] = ['x' => $ship['x'], 'y' => 550.0];
        $ship['cooldownMs'] = 280;
    }
    $aliens = space_arcade_aliens($state);
    foreach ($aliens as $alien) {
        if ($alien['y']+32 >= 562) {
            $state['completed'] = true;
            $state['terminalReason'] = 'invaded';
            return;
        }
    }
    $remaining = [];
    foreach ($state['orbs'] as $orb) {
        $orb['y'] -= 520*$milliseconds/1000;
        if ($orb['y'] < -8) continue;
        $hit = false;
        foreach ($aliens as $alien) {
            if (isset($state['kills'][$alien['id']])) continue;
            if ($orb['x'] > $alien['x'] && $orb['x'] < $alien['x']+44
                && $orb['y'] > $alien['y'] && $orb['y'] < $alien['y']+32) {
                $state['kills'][$alien['id']] = true;
                $ship['score'] += 10;
                $hit = true;
                break;
            }
        }
        if (!$hit) $remaining[] = $orb;
    }
    $state['orbs'] = $remaining;
    if (count($state['kills']) === min(7, 5+intdiv($state['level']-1, 2))*11) {
        $state['level']++;
        $state['kills'] = []; $state['orbs'] = []; $state['levelElapsedMs'] = -850;
    }
}
