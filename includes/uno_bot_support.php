<?php
declare(strict_types=1);

const UNO_BOT_ENGINE = 'corechat-uno-1';
const UNO_BOT_ID_BASE = -6700;

function uno_bot_choices(): array
{
    return [['value'=>'none','label'=>'None'], ['value'=>'easy','label'=>'Easy'],
        ['value'=>'normal','label'=>'Normal']];
}

/** New matches use supported levels; existing authoritative bot state is not rewritten. */
function uno_bot_lobby_settings(array $settings): array
{
    for ($seat = 1; $seat <= 10; $seat++) {
        $key = 'botSeat'.$seat.'Difficulty';
        if (($settings[$key] ?? '') === 'expert') $settings[$key] = 'normal';
    }
    return $settings;
}

function uno_bot_fill_seats(array $humans, array $settings, string $mode, array $seats): array
{
    if ($seats === []) foreach ($humans as $i => $id) $seats[$i + 1] = $id;
    $players = []; $bots = [];
    for ($seat = 1; $seat <= 10; $seat++) {
        if (isset($seats[$seat])) { $players[] = (int)$seats[$seat]; continue; }
        $level = $settings['botSeat'.$seat.'Difficulty'] ?? 'none';
        if ($mode !== 'practice' || $level === 'none') continue;
        $id = UNO_BOT_ID_BASE - $seat;
        $players[] = $id;
        $bots[(string)$id] = ['userId'=>$id, 'seat'=>$seat, 'difficulty'=>$level,
            'displayName'=>ucfirst($level).' Bot '.$seat, 'engine'=>UNO_BOT_ENGINE];
    }
    return [$players, $bots];
}

function uno_project_virtual_members(PDO $pdo, array $state, array $context): array
{
    return ($context['mode'] ?? '') === 'practice' ? array_values($state['bots'] ?? []) : [];
}

function uno_bot_actor(array $state): int
{
    if (!empty($state['completed'])) return 0;
    $turn = uno_turn_user($state);
    if (isset($state['bots'][(string)$turn])) return $turn;
    // An omission is public and can be caught by any other player.
    $target = (int)($state['unoCatchUserId'] ?? 0);
    if ($target !== 0 && in_array($state['phase'], ['playing','drawn','challenge'], true)) {
        foreach ($state['turnOrder'] as $id) if ($id !== $target && isset($state['bots'][(string)$id])) return $id;
    }
    return 0;
}

function uno_bot_position_key(array $state): string
{
    // Public identity only: no low-entropy hidden hand hash exposed to clients.
    return hash('sha256', multiplayer_game_canonical_json(array_intersect_key($state, array_flip([
        'turnOrder','turnIndex','phase','playSequence','handNumber','direction','currentColor','bots','unoCatchUserId'
    ]))));
}

function uno_bot_task(array $state, int $viewer, array $context): ?array
{
    if (($context['mode'] ?? '') !== 'practice' || ($context['status'] ?? '') !== 'active'
        || $viewer <= 0 || !in_array($viewer, $state['turnOrder'] ?? [], true)
        || !in_array($context['viewerRole'] ?? '', ['master','player'], true)) return null;
    $actor = uno_bot_actor($state);
    if (!$actor) return null;
    return ['engine'=>UNO_BOT_ENGINE, 'positionKey'=>uno_bot_position_key($state), 'actor'=>$actor,
        'action'=>$state['phase'] === 'deal' ? 'bot-deal' : 'bot-step',
        'delayMs'=>$state['phase'] === 'round-complete' ? 2500 : 700,
        'displayName'=>$state['bots'][(string)$actor]['displayName']];
}

/** Strict allowlist: strategy cannot inspect other hands, future cards or challenge verdict. */
function uno_bot_observation(array $state, int $actor): array
{
    $counts = [];
    foreach ($state['hands'] as $id => $hand) $counts[$id] = count($hand);
    $challenge = $state['pendingChallenge'] ?? null;
    return ['actor'=>$actor, 'hand'=>array_values($state['hands'][(string)$actor]),
        'legal'=>uno_legal_cards($state, $actor), 'cardCounts'=>$counts,
        'turnOrder'=>$state['turnOrder'], 'turnIndex'=>$state['turnIndex'], 'direction'=>$state['direction'],
        'currentColor'=>$state['currentColor'], 'phase'=>$state['phase'],
        'topDiscard'=>(string)(array_slice($state['discardPile'], -1)[0] ?? ''),
        'scores'=>$state['scores'], 'unoCatchUserId'=>$state['unoCatchUserId'],
        'declared'=>!empty($state['unoDeclared'][(string)$actor]),
        'challenge'=>$challenge === null ? null : ['offenderUserId'=>$challenge['offenderUserId'], 'targetUserId'=>$challenge['targetUserId']],
        'difficulty'=>$state['bots'][(string)$actor]['difficulty'] ?? 'normal'];
}

function uno_bot_color(array $hand): string
{
    $scores = array_fill_keys(['R','Y','G','B'], 0);
    foreach ($hand as $card) {
        $p = uno_card_parts($card);
        if ($p['color'] !== 'W') $scores[$p['color']] += 100 + uno_card_points($card);
    }
    arsort($scores, SORT_NUMERIC);
    return (string)array_key_first($scores);
}

/** Evaluate only our remaining hand; at most 12 choices at each of two further plies. */
function uno_bot_continuation(array $hand, string $color, string $symbol, int $depth): int
{
    if (!$hand || $depth === 0) return 0;
    $best = 0; $seen = [];
    foreach ($hand as $i => $card) {
        $p = uno_card_parts($card); $key = $p['color'].':'.$p['symbol'];
        if (isset($seen[$key])) continue;
        $matching = array_filter($hand, static fn($c) => uno_card_parts($c)['color'] === $color);
        if ($p['symbol'] === 'D4' && $matching) continue;
        if ($p['color'] !== 'W' && $p['color'] !== $color && $p['symbol'] !== $symbol) continue;
        $seen[$key] = true;
        $rest = $hand; array_splice($rest, $i, 1);
        $nextColor = $p['color'] === 'W' ? uno_bot_color($rest) : $p['color'];
        $best = max($best, 1 + uno_bot_continuation($rest, $nextColor, $p['symbol'], $depth - 1));
        if (count($seen) >= 12) break;
    }
    return $best;
}

function uno_bot_choose(array $o): array
{
    $choice = static fn(string $action, array $payload, string $reason, array $scores = []) =>
        ['action'=>$action, 'payload'=>$payload, 'reason'=>$reason, 'candidateScores'=>$scores];
    if (in_array($o['phase'], ['playing','drawn','challenge'], true) && ($o['unoCatchUserId'] ?? 0) && $o['unoCatchUserId'] !== $o['actor']) return $choice('catch-uno', [], 'catch-public-omission');
    if ($o['phase'] === 'deal') return $choice('deal', [], 'framework-verified-deal');
    if ($o['phase'] === 'round-complete') return $choice('next-hand', [], 'continue-match');
    if ($o['phase'] === 'opening-color') return $choice('choose-color', ['color'=>uno_bot_color($o['hand'])], 'strongest-held-color');
    // No hidden verdict is available. Accept the known four-card penalty rather than
    // pretend to identify an illegal Wild from information opponents cannot see.
    if ($o['phase'] === 'challenge') return $choice('accept-draw-four', [], 'accept-without-private-verdict');
    $matching = array_filter($o['hand'], static fn($c) => uno_card_parts($c)['color'] === $o['currentColor']);
    $legal = array_values(array_filter($o['legal'], static fn($c) => uno_card_parts($c)['symbol'] !== 'D4' || !$matching));
    if (!$legal) return $choice($o['phase'] === 'drawn' ? 'pass' : 'draw', [], 'no-legal-card');
    if (count($o['hand']) === 2 && !$o['declared']) return $choice('call-uno', [], 'declare-before-last-card');
    if ($o['difficulty'] === 'easy') {
        $card = $legal[0]; $p = uno_card_parts($card);
        return $choice('play', $p['color'] === 'W' ? ['card'=>$card,'color'=>uno_bot_color($o['hand'])] : ['card'=>$card], 'simple-legal-play');
    }
    $count = count($o['turnOrder']); $actorIndex = array_search($o['actor'], $o['turnOrder'], true);
    $nextIndex = ($actorIndex + $o['direction'] + $count) % $count;
    $nextCount = $o['cardCounts'][(string)$o['turnOrder'][$nextIndex]];
    $danger = min(array_values(array_diff_key($o['cardCounts'], [$o['actor']=>true]))) <= 2;
    $scores = []; $payloads = [];
    foreach ($legal as $card) {
        $p = uno_card_parts($card); $rest = $o['hand']; array_splice($rest, array_search($card, $rest, true), 1);
        foreach ($p['color'] === 'W' ? ['R','Y','G','B'] : [$p['color']] as $color) {
            $key = $card.'/'.$color;
            $held = count(array_filter($rest, static fn($c) => uno_card_parts($c)['color'] === $color));
            $score = $held * 10 + uno_card_points($card) * ($danger ? 1.5 : .2);
            if ($p['color'] === 'W' && !$danger && count($rest) > 1) $score -= 35;
            $deniesNext = in_array($p['symbol'], ['S','D2','D4','R'], true);
            if ($deniesNext && $nextCount <= 2) $score += 65;
            if ($o['difficulty'] === 'expert') {
                $score += 18 * uno_bot_continuation($rest, $color, $p['symbol'], 2);
                $next = ($actorIndex + ($p['symbol'] === 'R' ? -$o['direction'] : $o['direction']) + $count) % $count;
                if (in_array($p['symbol'], ['S','D2','D4'], true) || ($p['symbol'] === 'R' && $count === 2)) $next = ($next + ($p['symbol'] === 'R' ? -$o['direction'] : $o['direction']) + $count) % $count;
                if ($next === $actorIndex) $score += 35 * uno_bot_continuation($rest, $color, $p['symbol'], 1);
                elseif ($o['cardCounts'][(string)$o['turnOrder'][$next]] <= 2) $score -= 45;
            }
            if (!$rest) $score += 10000;
            $scores[$key] = $score;
            $payloads[$key] = $p['color'] === 'W' ? ['card'=>$card,'color'=>$color] : ['card'=>$card];
        }
    }
    arsort($scores, SORT_NUMERIC);
    return $choice('play', $payloads[array_key_first($scores)], $o['difficulty'] === 'expert' ? 'hand-continuation-and-opponent-counts' : 'color-and-action-management', $scores);
}

function uno_apply_action(array $state, int $actor, string $action, array $payload, array $context): array
{
    if ($actor <= 0 || !in_array($actor, $state['turnOrder'] ?? [], true)) throw new MultiplayerGameException('Only an authenticated UNO participant may act.', 'UNO_PLAYER_INVALID', 403);
    if (!empty($state['bots']) && ($context['mode'] ?? '') !== 'practice') throw new MultiplayerGameException('Games with bots are Practice only.', 'MULTIPLAYER_GAME_BOTS_PRACTICE_ONLY', 422);
    $trace = null;
    if (in_array($action, ['bot-step','bot-deal'], true)) {
        $bot = uno_bot_actor($state);
        if (!$bot || ($context['mode'] ?? '') !== 'practice') throw new MultiplayerGameException('An UNO bot is not available.', 'UNO_BOT_UNAVAILABLE', 409);
        if (!hash_equals(uno_bot_position_key($state), (string)($payload['positionKey'] ?? ''))) throw new MultiplayerGameException('The UNO position changed. Refresh before retrying.', 'UNO_BOT_POSITION_STALE', 409);
        if (($payload['engine'] ?? '') !== UNO_BOT_ENGINE) throw new MultiplayerGameException('The UNO bot was updated. Reload the game.', 'UNO_BOT_ENGINE_MISMATCH', 409);
        if (($action === 'bot-deal') !== ($state['phase'] === 'deal')) throw new MultiplayerGameException('The UNO bot action changed.', 'UNO_BOT_ACTION_INVALID', 409);
        $actor = $bot; $start = hrtime(true); $observation = uno_bot_observation($state, $actor);
        $choice = uno_bot_choose($observation); $action = $choice['action']; $payload = $choice['payload'];
        $trace = ['engine'=>UNO_BOT_ENGINE, 'difficulty'=>$observation['difficulty'], 'reason'=>$choice['reason'],
            'legal'=>$observation['legal'], 'selected'=>['action'=>$action,'payload'=>$payload],
            'candidateScores'=>$choice['candidateScores'], 'publicObservation'=>$observation, 'elapsedMs'=>(hrtime(true)-$start)/1000000];
    }
    $result = uno_apply_action_core($state, $actor, $action, $payload, $context);
    if (function_exists('game_recording_observe')) game_recording_observe($context, 'uno', $state, $actor, $action, $payload, $result['state'], $trace);
    return $result;
}
