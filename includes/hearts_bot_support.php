<?php
declare(strict_types=1);

require_once __DIR__ . '/hearts_expert_support.php';

const HEARTS_BOT_ENGINE = 'corechat-hearts-1';
const HEARTS_BOT_ID_BASE = -6800;

function hearts_bot_choices(): array
{
    return [['value'=>'none','label'=>'None'], ['value'=>'easy','label'=>'Easy'], ['value'=>'normal','label'=>'Normal'], ['value'=>'expert','label'=>'Expert']];
}

function hearts_bot_fill_seats(array $humans, array $settings, string $mode, array $seats): array
{
    if ($seats === []) foreach ($humans as $i => $id) $seats[$i + 1] = $id;
    $players = []; $bots = [];
    for ($seat = 1; $seat <= 4; $seat++) {
        if (isset($seats[$seat])) { $players[] = (int)$seats[$seat]; continue; }
        $level = $settings['botSeat'.$seat.'Difficulty'] ?? 'none';
        if ($mode !== 'practice' || $level === 'none') continue;
        $id = HEARTS_BOT_ID_BASE - $seat;
        $players[] = $id;
        $bots[(string)$id] = ['userId'=>$id,'seat'=>$seat,'difficulty'=>$level,'displayName'=>ucfirst($level).' Bot '.$seat,'engine'=>HEARTS_BOT_ENGINE];
    }
    return [$players, $bots];
}

function hearts_project_virtual_members(PDO $pdo, array $state, array $context): array
{
    return ($context['mode'] ?? '') === 'practice' ? array_values($state['bots'] ?? []) : [];
}

function hearts_bot_actor(array $state): int
{
    $actor = (int)($state['turnOrder'][$state['turnIndex'] ?? 0] ?? 0);
    return empty($state['completed']) && isset($state['bots'][(string)$actor]) ? $actor : 0;
}

function hearts_bot_position_key(array $state): string
{
    return hash('sha256', multiplayer_game_canonical_json(array_intersect_key($state, array_flip([
        'turnOrder','turnIndex','phase','playSequence','handNumber','trickNumber','bots'
    ]))));
}

function hearts_bot_task(array $state, int $viewer, array $context): ?array
{
    if (($context['mode'] ?? '') !== 'practice' || ($context['status'] ?? '') !== 'active'
        || $viewer <= 0 || !in_array($viewer, $state['turnOrder'] ?? [], true)
        || !in_array($context['viewerRole'] ?? '', ['master','player'], true)) return null;
    $actor = hearts_bot_actor($state);
    if (!$actor) return null;
    $delay = 850;
    if (($state['phase'] ?? '') === 'settling') $delay = max(50, (int)($state['settlement']['settleAfterUnixMs'] ?? 0) - (int)($context['nowUnixMs'] ?? floor(microtime(true)*1000)) + 80);
    return ['engine'=>HEARTS_BOT_ENGINE,'positionKey'=>hearts_bot_position_key($state),'actor'=>$actor,
        'action'=>$state['phase'] === 'deal' ? 'bot-deal' : 'bot-step',
        'delayMs'=>$delay,'displayName'=>$state['bots'][(string)$actor]['displayName']];
}

/** Only the actor's hand and public facts enter the decision function. */
function hearts_bot_observation(array $state, int $actor): array
{
    $counts = []; $points = [];
    foreach ($state['turnOrder'] as $id) {
        $counts[(string)$id] = count($state['hands'][(string)$id]);
        $points[(string)$id] = array_sum(array_map(static fn(string $card): int => hearts_point_value($card, (int)$state['playerCount']), $state['captured'][(string)$id]));
    }
    return ['actor'=>$actor,'hand'=>array_values($state['hands'][(string)$actor]),'legal'=>hearts_legal_cards($state,$actor),
        'phase'=>$state['phase'],'passCount'=>$state['passCount'],'passDirection'=>$state['passDirection'],
        'playerCount'=>$state['playerCount'],'turnOrder'=>$state['turnOrder'],'turnIndex'=>$state['turnIndex'],
        'trick'=>$state['currentTrick'],'played'=>$state['botPublicPlays'] ?? [],'trickNumber'=>$state['trickNumber'],
        'cardCounts'=>$counts,'points'=>$points,'scores'=>$state['scores'],'moonEnabled'=>!empty($state['settings']['shootTheMoon']),'heartsBroken'=>!empty($state['heartsBroken']),
        'difficulty'=>$state['bots'][(string)$actor]['difficulty'] ?? 'normal'];
}

function hearts_bot_unseen(array $o): array
{
    $known = array_fill_keys(array_merge($o['hand'], array_column($o['played'], 'card'), array_column($o['trick'], 'card')), true);
    $ranks = $o['playerCount'] === 2 ? [2,4,6,8,10,12,14] : range(2,14);
    $out = [];
    foreach (['C','D','H','S'] as $suit) foreach ($ranks as $rank) if (!isset($known[$suit.$rank])) $out[] = $suit.$rank;
    return $out; // In two-player play, this deliberately includes the unknown widow.
}

function hearts_bot_voids(array $o): array
{
    $voids = [];
    foreach (array_chunk($o['played'], $o['playerCount']) as $trick) {
        $suit = hearts_card_parts($trick[0]['card'])['suit'];
        foreach ($trick as $play) if (hearts_card_parts($play['card'])['suit'] !== $suit) $voids[(string)$play['userId']][$suit] = true;
    }
    return $voids;
}

function hearts_bot_moon_plan(array $o, array $unseen): bool
{
    if (!$o['moonEnabled'] || $o['difficulty'] !== 'normal') return false;
    foreach ($o['points'] as $id=>$points) if ((int)$id !== $o['actor'] && $points > 0) return false;
    $hearts = array_values(array_filter($o['hand'], static fn($c)=>$c[0]==='H'));
    $ownPoints = array_sum(array_map(static fn($c)=>hearts_point_value($c,$o['playerCount']),$o['hand']));
    // A late plan requires every remaining penalty card to be accounted for in our own hand.
    $total = $o['playerCount'] === 2 ? 14 : 26;
    if ($ownPoints + $o['points'][(string)$o['actor']] === $total && $hearts !== []) {
        $lowest = min(array_map(static fn($c)=>hearts_card_parts($c)['rank'],$hearts));
        $higher = array_filter($unseen,static fn($c)=>$c[0]==='H' && hearts_card_parts($c)['rank']>$lowest);
        if (!$higher && count($o['hand']) <= 6) return true;
    }
    // Opening attempts are deliberately rare: strong hearts, queen control and side-suit winners.
    if ($o['playerCount'] !== 4 || count($hearts) < 6 || !in_array('H14',$hearts,true) || !in_array('H13',$hearts,true)
        || !in_array('H12',$hearts,true) || !in_array('S12',$o['hand'],true) || !in_array('S14',$o['hand'],true)) return false;
    foreach (['C','D'] as $suit) {
        $cards = array_values(array_filter($o['hand'],static fn($c)=>$c[0]===$suit));
        if ($cards && (!in_array($suit.'14',$cards,true) || count($cards)>2)) return false;
    }
    return true;
}

function hearts_bot_choose(array $o): array
{
    if (($o['difficulty'] ?? '') === 'expert') return hearts_expert_choose($o);
    $choice = static fn($action,$payload=[],$reason='phase-progression',$scores=[])=>['action'=>$action,'payload'=>$payload,'reason'=>$reason,'candidateScores'=>$scores];
    if ($o['phase'] === 'deal') return $choice('deal');
    if ($o['phase'] === 'settling') return $choice('settle-trick');
    $normal = $o['difficulty'] === 'normal'; $unseen = hearts_bot_unseen($o); $moon = hearts_bot_moon_plan($o,$unseen);
    $lengths = array_fill_keys(['C','D','H','S'],0);
    foreach ($o['hand'] as $card) $lengths[$card[0]]++;
    if ($o['phase'] === 'passing') {
        $scores = [];
        foreach ($o['hand'] as $card) {
            $p = hearts_card_parts($card); $score = $p['rank'];
            if ($normal) {
                $score += hearts_point_value($card,$o['playerCount'])*1.5;
                if ($p['suit']==='S') $score += $p['rank']>=12 ? (5-$lengths['S'])*8 : -18;
                if (in_array($p['suit'],['C','D'],true)) $score += max(0,4-$lengths[$p['suit']])*5;
                if ($moon) $score = -$p['rank'] - ($p['suit']==='H' || $card==='S12' ? 60 : 0);
            }
            $scores[$card] = $score;
        }
        arsort($scores,SORT_NUMERIC);
        return $choice('pass',['cards'=>array_slice(array_keys($scores),0,$o['passCount'])],$moon?'pass-to-preserve-moon-control':($normal?'pass-risk-and-short-suits':'pass-high-cards'),$scores);
    }
    if ($o['phase'] !== 'playing' || !$o['legal']) throw new MultiplayerGameException('No Hearts bot move is available.','HEARTS_BOT_MOVE_UNAVAILABLE',409);
    $trick = $o['trick']; $lead = $trick ? $trick[0]['card'][0] : null;
    $winner = $trick ? hearts_trick_winner($trick) : 0;
    $winningRank = 0;
    foreach ($trick as $play) if ($play['card'][0]===$lead) $winningRank=max($winningRank,hearts_card_parts($play['card'])['rank']);
    $pointsInTrick = array_sum(array_map(static fn($p)=>hearts_point_value($p['card'],$o['playerCount']),$trick));
    $collectors = array_keys(array_filter($o['points'],static fn($p)=>$p>0));
    $threat = $normal && $o['moonEnabled'] && count($collectors)===1 && (int)$collectors[0]!==$o['actor']
        && $o['points'][$collectors[0]] >= ($o['playerCount']===2?3:6) ? (int)$collectors[0] : 0;
    $last = count($trick)===$o['playerCount']-1; $voids=hearts_bot_voids($o); $scores=[];
    foreach ($o['legal'] as $card) {
        $p=hearts_card_parts($card); $points=hearts_point_value($card,$o['playerCount']);
        $wins = !$trick || ($p['suit']===$lead && $p['rank']>$winningRank);
        if (!$normal) { $scores[$card] = !$trick ? -$p['rank'] : ($wins ? -40-$p['rank']-$points*5 : $points*10-$p['rank']); continue; }
        $higher=count(array_filter($unseen,static fn($c)=>$c[0]===$p['suit'] && hearts_card_parts($c)['rank']>$p['rank']));
        $lower=count(array_filter($unseen,static fn($c)=>$c[0]===$p['suit'] && hearts_card_parts($c)['rank']<$p['rank']));
        $risk=$wins ? ($last ? 1.0 : ($lower+1)/($lower+$higher+1)) : 0.0;
        if (!$trick) {
            $score=-$risk*20-$p['rank']*.2-$points*5;
            foreach ($voids as $id=>$suits) if ((int)$id!==$o['actor'] && !empty($suits[$p['suit']])) $score-=10*$risk;
            if ($p['suit']==='S' && $p['rank']<12 && in_array('S12',$unseen,true)) $score+=4;
        } elseif (!$wins) {
            $score=30+$points*12+$p['rank']; // Safely shed the highest losing card, keeping escape cards.
            if ($lead!==$p['suit']) $score+=max(0,4-$lengths[$p['suit']])*3;
        } else {
            $score=-$risk*(20+($pointsInTrick+$points)*18)-$p['rank']*.3;
            if ($last && $pointsInTrick+$points===0) $score=$p['rank']+5; // Cash a dangerous card on a known clean trick.
        }
        if ($threat) {
            if ($winner===$threat && !$wins) $score-=$points*45;
            if ($wins && $pointsInTrick+$points>0 && ($last || $higher===0)) $score+=600;
        }
        if ($moon) $score=$wins ? 250+$points*5-$p['rank']*.2 : -200-$points*30+$p['rank'];
        $scores[$card]=$score;
    }
    arsort($scores,SORT_NUMERIC);
    return $choice('play',['card'=>array_key_first($scores)],$moon?'controlled-moon-attempt':($threat?'prevent-opponent-moon':($normal?'public-card-risk-and-safe-disposal':'simple-penalty-avoidance')),$scores);
}

function hearts_apply_action(array $state, int $actor, string $action, array $payload, array $context): array
{
    if ($actor<=0 || !in_array($actor,$state['turnOrder']??[],true)) throw new MultiplayerGameException('Only an authenticated Hearts participant may act.','HEARTS_PLAYER_INVALID',403);
    if (!empty($state['bots']) && ($context['mode']??'')!=='practice') throw new MultiplayerGameException('Games with bots are Practice only.','MULTIPLAYER_GAME_BOTS_PRACTICE_ONLY',422);
    $trace=null;
    if (in_array($action,['bot-step','bot-deal'],true)) {
        $bot=hearts_bot_actor($state);
        if (!$bot || ($context['mode']??'')!=='practice') throw new MultiplayerGameException('A Hearts bot is not available.','HEARTS_BOT_UNAVAILABLE',409);
        if (!hash_equals(hearts_bot_position_key($state),(string)($payload['positionKey']??''))) throw new MultiplayerGameException('The Hearts position changed. Refresh before retrying.','HEARTS_BOT_POSITION_STALE',409);
        if (($payload['engine']??'')!==HEARTS_BOT_ENGINE) throw new MultiplayerGameException('The Hearts bot was updated. Reload the game.','HEARTS_BOT_ENGINE_MISMATCH',409);
        if (($action==='bot-deal')!==($state['phase']==='deal')) throw new MultiplayerGameException('The Hearts bot action changed.','HEARTS_BOT_ACTION_INVALID',409);
        $actor=$bot;$start=hrtime(true);$o=hearts_bot_observation($state,$actor);$c=hearts_bot_choose($o);$action=$c['action'];$payload=$c['payload'];
        $trace=['engine'=>HEARTS_BOT_ENGINE,'difficulty'=>$o['difficulty'],'reason'=>$c['reason'],'legal'=>$o['legal'],
            'selected'=>['action'=>$action,'payload'=>$payload],'candidateScores'=>$c['candidateScores'],'candidates'=>$c['expert']??[],'publicObservation'=>$o,'elapsedMs'=>(hrtime(true)-$start)/1000000];
    }
    $result=hearts_apply_action_core($state,$actor,$action,$payload,$context);
    if (function_exists('game_recording_observe')) game_recording_observe($context,'hearts',$state,$actor,$action,$payload,$result['state'],$trace);
    return $result;
}
