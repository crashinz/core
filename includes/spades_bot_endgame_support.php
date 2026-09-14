<?php
declare(strict_types=1);

/** Enumerate small remaining hands from public facts, including historical legality. */
function spades_bot_endgame_public_worlds(array $observation, int &$work): ?array
{
    $order = $observation['turnOrder'];
    $actor = (int)$observation['botUserId'];
    if (count($order) !== 4 || count(array_unique($order)) !== 4 || !in_array($actor, $order, true)) return null;
    $tricks = $observation['publicTricks'];
    if (count($tricks) !== 11 || count($observation['currentTrick']) > 3) return null;
    $played = []; $counts = array_fill_keys($order, 0); $won = $counts;
    $leader = null;
    foreach (array_merge($tricks, [$observation['currentTrick']]) as $index => $trick) {
        if ($index < 11 && count($trick) !== 4) return null;
        if ($trick === []) {
            if ($leader !== $actor) return null;
            continue;
        }
        $start = array_search((int)$trick[0]['userId'], $order, true);
        if ($start === false || ($leader !== null && $trick[0]['userId'] !== $leader)) return null;
        foreach ($trick as $position => $play) {
            if (($play['userId'] ?? null) !== $order[($start + $position) % 4]) return null;
            $played[] = (string)$play['card'];
            $counts[$play['userId']]++;
        }
        if ($index < 11) {
            $leader = spades_bot_trick_winner($trick);
            $won[$leader]++;
        } elseif ($order[($start + count($trick)) % 4] !== $actor) return null;
    }
    if ($won != $observation['tricksWon']) return null;
    $recorded = $observation['playedCards']; $actual = $played;
    sort($recorded); sort($actual);
    if ($recorded !== $actual) return null;
    $deck = spades_deck($observation['settings']);
    $known = array_merge($played, $observation['ownHand']);
    if (count(array_unique($known)) !== count($known) || array_diff($known, $deck) !== []) return null;
    foreach ($order as $id) {
        $remaining = 13 - $counts[$id];
        if ($remaining < 1 || $remaining > 2 || ($observation['remainingCounts'][$id] ?? null) !== $remaining) return null;
    }
    if ($observation['remainingCounts'][$actor] !== count($observation['ownHand'])) return null;
    $unknown = array_values(array_diff($deck, $known)); sort($unknown);
    if (count($unknown) > 6) return null;
    $world = array_fill_keys($order, []); $world[$actor] = $observation['ownHand'];
    $others = array_values(array_filter($order, static fn(int $id): bool => $id !== $actor));
    $worlds = []; $complete = true;
    $visit = static function(int $index, array $hands) use (&$visit, &$work, &$complete, &$worlds, $unknown, $others, $observation): void {
        if (!$complete) return;
        if ($work-- <= 0) { $complete = false; return; }
        if ($index === count($unknown)) {
            if (spades_bot_endgame_history_valid($observation, $hands, $work)) $worlds[] = $hands;
            if ($work <= 0 || count($worlds) > 90) $complete = false;
            return;
        }
        $card = $unknown[$index]; $suit = spades_card_parts($card)['suit'];
        foreach ($others as $id) {
            if (count($hands[$id]) >= $observation['remainingCounts'][$id]
                || in_array($suit, $observation['voidSuits'][$id] ?? [], true)) continue;
            $next = $hands; $next[$id][] = $card;
            $visit($index + 1, $next);
        }
    };
    $visit(0, $world);
    return $complete && $worlds !== [] ? $worlds : null;
}

/** A capacity/void allocation can still contradict an earlier unbroken trump lead. */
function spades_bot_endgame_history_valid(array $observation, array $hands, int &$work): bool
{
    $tricks = array_merge($observation['publicTricks'], [$observation['currentTrick']]);
    foreach ($tricks as $trick) foreach ($trick as $play) $hands[$play['userId']][] = $play['card'];
    foreach ($hands as $hand) if (count($hand) !== 13) return false;
    $state = ['phase'=>'playing', 'turnOrder'=>$observation['turnOrder'], 'hands'=>$hands,
        'spadesBroken'=>false, 'tricksWon'=>array_fill_keys($observation['turnOrder'], 0), 'currentTrick'=>[]];
    foreach ($tricks as $trick) {
        $state['currentTrick'] = [];
        foreach ($trick as $play) {
            if ($work-- <= 0) return false;
            $id = (int)$play['userId']; $card = (string)$play['card'];
            $state['turnIndex'] = array_search($id, $state['turnOrder'], true);
            if (!in_array($card, spades_legal_cards($state, $id), true)) return false;
            $state['hands'][$id] = array_values(array_diff($state['hands'][$id], [$card]));
            $state['currentTrick'][] = $play;
            $state['spadesBroken'] = $state['spadesBroken'] || spades_card_parts($card)['suit'] === 'S';
        }
        if (count($trick) === 4) $state['tricksWon'][spades_bot_trick_winner($trick)]++;
    }
    return $state['spadesBroken'] === (bool)$observation['spadesBroken'];
}

/** Exhaust every legal continuation; an unfinished proof must never change a move. */
function spades_bot_endgame_outcomes(array $observation, array $worlds, string $card, array $nilTargets, int &$work): ?array
{
    $range = ['minimum'=>INF, 'maximum'=>-INF, 'nilAlwaysSet'=>true, 'leaves'=>0];
    $complete = true;
    $walk = static function(array $hands, array $trick, array $won, bool $broken, int $next) use (&$walk, &$work, &$complete, &$range, $observation, $nilTargets): void {
        if (!$complete) return;
        if ($work-- <= 0) { $complete = false; return; }
        if (count($trick) === 4) {
            $winner = spades_bot_trick_winner($trick); $won[$winner]++;
            $next = (int)array_search($winner, $observation['turnOrder'], true); $trick = [];
        }
        if (array_sum($won) === 13) {
            $value = spades_bot_rollout_utility($observation, $won);
            $range['minimum'] = min($range['minimum'], $value);
            $range['maximum'] = max($range['maximum'], $value);
            $range['nilAlwaysSet'] = $range['nilAlwaysSet'] && array_filter($nilTargets, static fn(int $id): bool => $won[$id] > 0) !== [];
            $range['leaves']++;
            return;
        }
        $id = $observation['turnOrder'][$next];
        $legal = spades_bot_rollout_legal_cards($hands[$id], $trick, $broken, array_sum($won));
        if ($legal === []) { $complete = false; return; }
        foreach ($legal as $choice) {
            $remaining = $hands; $remaining[$id] = array_values(array_diff($remaining[$id], [$choice]));
            $after = $trick; $after[] = ['userId'=>$id, 'card'=>$choice];
            $walk($remaining, $after, $won, $broken || spades_card_parts($choice)['suit'] === 'S', ($next + 1) % 4);
        }
    };
    $actor = (int)$observation['botUserId'];
    foreach ($worlds as $world) {
        $world[$actor] = array_values(array_diff($world[$actor], [$card]));
        $trick = $observation['currentTrick']; $trick[] = ['userId'=>$actor, 'card'=>$card];
        $walk($world, $trick, $observation['tricksWon'], (bool)$observation['spadesBroken'] || spades_card_parts($card)['suit'] === 'S',
            ((int)array_search($actor, $observation['turnOrder'], true) + 1) % 4);
    }
    return $complete && $range['leaves'] > 0 ? $range : null;
}

/** Normal may take the lead from a winning partner to force the opposing Nil next. */
function spades_bot_normal_endgame_nil_attack(array $observation, array $legal, string $fallback, int $workLimit = 8192): ?array
{
    if (($observation['difficulty'] ?? '') !== 'normal' || count($observation['ownHand']) !== 2
        || count($legal) !== 2 || !in_array($fallback, $legal, true) || array_sum($observation['tricksWon']) !== 11) return null;
    $actor = (int)$observation['botUserId'];
    $partner = spades_bot_public_partner_id($observation['turnOrder'], $actor);
    foreach ([$actor, $partner] as $id) if (($observation['bids'][$id]['kind'] ?? '') !== 'standard') return null;
    $team = spades_team_index(['turnOrder'=>$observation['turnOrder']], $actor);
    if (spades_bot_team_contract_tricks($observation, $team) < (int)($observation['teamBids'][$team]['amount'] ?? 0)) return null;
    $opponents = array_values(array_filter($observation['turnOrder'], static fn(int $id): bool => $id !== $actor && $id !== $partner));
    $combined = in_array($observation['teamBids'][1 - $team]['nilClassification'] ?? '', ['double-nil', 'double-blind-nil'], true);
    if ($combined && array_sum(array_map(static fn(int $id): int => (int)$observation['tricksWon'][$id], $opponents)) > 0) return null;
    $targets = array_values(array_filter($opponents, static fn(int $id): bool => spades_bot_nil_live($observation, $id)));
    if ($targets === []) return null;
    $limit = max(0, min(8192, $workLimit)); $work = $limit;
    $worlds = spades_bot_endgame_public_worlds($observation, $work);
    if ($worlds === null) return null;
    $ranges = [];
    foreach ($legal as $card) {
        $range = spades_bot_endgame_outcomes($observation, $worlds, $card, $targets, $work);
        if ($range === null) return null;
        $ranges[$card] = $range;
    }
    if ($ranges[$fallback]['nilAlwaysSet']) return null;
    foreach ($legal as $card) {
        if ($card !== $fallback && $ranges[$card]['nilAlwaysSet'] && $ranges[$card]['minimum'] > $ranges[$fallback]['maximum']) {
            return ['card'=>$card, 'reason'=>'normal-proven-two-trick-nil-attack', 'worlds'=>count($worlds),
                'workUnits'=>$limit - $work, 'fallback'=>$fallback, 'candidateScores'=>$ranges];
        }
    }
    return null;
}
