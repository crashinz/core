<?php
declare(strict_types=1);
require_once __DIR__ . '/paced_bot_support.php';

require_once __DIR__ . '/spades_bot_endgame_support.php';

const SPADES_BOT_ID_BASE = -6200;
const SPADES_BOT_EXPERT_CHAIN_BUDGET_MS = 3000;
const SPADES_BOT_EXPERT_DECISION_BUDGET_MS = 1000;

function spades_bot_deadline_reached(?int $deadlineNs): bool
{
    return $deadlineNs !== null && hrtime(true) >= $deadlineNs;
}

function spades_bot_fill_seats(array $humanPlayerIds, array $settings, string $mode, array $humanSeats = []): array
{
    if ($humanSeats === []) foreach (array_values($humanPlayerIds) as $index => $id) $humanSeats[$index + 1] = (int)$id;
    ksort($humanSeats, SORT_NUMERIC);
    $turnOrder = [];
    $bots = [];
    for ($seat = 1; $seat <= 4; $seat++) {
        if (isset($humanSeats[$seat])) { $turnOrder[] = (int)$humanSeats[$seat]; continue; }
        if ($mode !== 'practice') continue;
        $userId = SPADES_BOT_ID_BASE - $seat;
        $difficulty = (string)($settings['botSeat' . $seat . 'Difficulty'] ?? 'none');
        if ($difficulty === 'none') continue;
        $label = $difficulty === 'expert' ? 'Expert' : 'Normal';
        $turnOrder[] = $userId;
        $bots[(string)$userId] = [
            'userId' => $userId,
            'seat' => $seat,
            'difficulty' => $difficulty,
            'displayName' => $label . ' Bot ' . $seat,
        ];
    }
    return [$turnOrder, $bots];
}

function spades_bot_is_player(array $state, int $userId): bool
{
    return $userId < 0 && isset($state['bots'][(string)$userId]);
}

function spades_bot_first_human(array $state): int
{
    foreach ((array)($state['turnOrder'] ?? []) as $userId) {
        if ((int)$userId > 0) return (int)$userId;
    }
    throw new MultiplayerGameException('A Practice bot game requires a human player.', 'SPADES_BOT_HUMAN_REQUIRED', 500);
}

function spades_project_virtual_members(PDO $pdo, array $state, array $context): array
{
    unset($pdo);
    if ((string)($context['mode'] ?? '') !== 'practice') return [];
    $members = [];
    foreach ((array)($state['bots'] ?? []) as $bot) {
        $userId = (int)($bot['userId'] ?? 0);
        $seat = (int)($bot['seat'] ?? 0);
        if ($userId >= 0 || $seat < 1 || $seat > 4) continue;
        $members[] = [
            'userId' => $userId,
            'displayName' => (string)($bot['displayName'] ?? ('Normal Bot ' . $seat)),
            'seat' => $seat,
            'difficulty' => (string)($bot['difficulty'] ?? 'normal'),
        ];
    }
    return $members;
}

function spades_bot_card_value(string $card): int
{
    return (int)spades_card_parts($card)['rank'];
}

function spades_bot_public_observation(array $state, int $botUserId): array
{
    $playedCards = [];
    $publicTricks = [];
    $voidSuits = [];
    $playedByUser = [];
    foreach ((array)($state['turnOrder'] ?? []) as $userId) {
        $voidSuits[(string)(int)$userId] = [];
        $playedByUser[(string)(int)$userId] = 0;
    }
    $recordTrick = static function(array $cards) use (&$playedCards, &$publicTricks, &$voidSuits, &$playedByUser): void {
        if ($cards === []) return;
        $leadSuit = spades_card_parts((string)$cards[0]['card'])['suit'];
        $clean = [];
        foreach ($cards as $play) {
            $userId = (int)($play['userId'] ?? 0);
            $card = (string)($play['card'] ?? '');
            if ($userId === 0 || $card === '') continue;
            $parts = spades_card_parts($card);
            $playedCards[] = $card;
            $playedByUser[(string)$userId] = (int)($playedByUser[(string)$userId] ?? 0) + 1;
            $clean[] = ['userId' => $userId, 'card' => $card];
            if ($parts['suit'] !== $leadSuit) $voidSuits[(string)$userId][$leadSuit] = true;
        }
        if ($clean !== []) $publicTricks[] = $clean;
    };
    $currentHandNumber = (int)($state['handNumber'] ?? 0);
    foreach ((array)($state['history'] ?? []) as $entry) {
        if ((int)($entry['hand'] ?? 0) === $currentHandNumber && is_array($entry['cards'] ?? null)) {
            $recordTrick(array_values($entry['cards']));
        }
    }
    $currentTrick = array_values((array)($state['currentTrick'] ?? []));
    if ($currentTrick !== []) {
        $leadSuit = spades_card_parts((string)$currentTrick[0]['card'])['suit'];
        foreach ($currentTrick as $play) {
            $card = (string)($play['card'] ?? '');
            if ($card === '') continue;
            $playedCards[] = $card;
            $parts = spades_card_parts($card);
            $userId = (int)($play['userId'] ?? 0);
            $playedByUser[(string)$userId] = (int)($playedByUser[(string)$userId] ?? 0) + 1;
            if ($userId !== 0 && $parts['suit'] !== $leadSuit) $voidSuits[(string)$userId][$leadSuit] = true;
        }
    }
    foreach ($voidSuits as $userId => $suits) $voidSuits[$userId] = array_keys($suits);
    $ownHand = array_values(array_map('strval', (array)($state['hands'][(string)$botUserId] ?? [])));
    $remainingCounts = [];
    foreach ((array)($state['turnOrder'] ?? []) as $userId) {
        $remainingCounts[(string)(int)$userId] = max(0, 13 - (int)($playedByUser[(string)(int)$userId] ?? 0));
    }
    $remainingCounts[(string)$botUserId] = count($ownHand);
    $ownTeam = spades_team_index($state,$botUserId);
    $visibleBids = (array)($state['bids'] ?? []);
    foreach ($visibleBids as $userId => $bid) {
        $bidTeam = spades_team_index($state,(int)$userId);
        if ($bidTeam !== $ownTeam && !isset($state['teamBids'][(string)$bidTeam])
            && ($bid['role'] ?? '') === 'hint' && ($bid['kind'] ?? '') === 'standard') {
            $visibleBids[$userId] = ['private'=>true];
        }
    }
    $visibleHints = array_intersect_key((array)($state['teamBidHints'] ?? []),[(string)$ownTeam=>true]);
    return [
        'botUserId' => $botUserId,
        'difficulty' => (string)($state['bots'][(string)$botUserId]['difficulty'] ?? 'normal'),
        'turnOrder' => array_values(array_map('intval', (array)($state['turnOrder'] ?? []))),
        'ownHand' => $ownHand,
        'currentTrick' => $currentTrick,
        'publicTricks' => $publicTricks,
        'playedCards' => array_values(array_unique($playedCards)),
        'voidSuits' => $voidSuits,
        'remainingCounts' => $remainingCounts,
        'bids' => $visibleBids,
        'teamBids' => (array)($state['teamBids'] ?? []),
        'teamBidHints' => $visibleHints,
        'tricksWon' => (array)($state['tricksWon'] ?? []),
        'teamScores' => (array)($state['teamScores'] ?? []),
        'teamBags' => (array)($state['teamBags'] ?? []),
        'handNumber' => (int)($state['handNumber'] ?? 0),
        'playSequence' => (int)($state['playSequence'] ?? 0),
        'spadesBroken' => !empty($state['spadesBroken']),
        'settings' => (array)($state['settings'] ?? []),
    ];
}

function spades_bot_stable_number(array $observation, string $purpose): int
{
    $material = json_encode([
        $purpose,
        $observation['botUserId'],
        $observation['handNumber'],
        $observation['playSequence'],
        $observation['ownHand'],
        $observation['currentTrick'],
        $observation['bids'],
    ], JSON_UNESCAPED_SLASHES);
    return (int)hexdec(substr(hash('sha256', (string)$material), 0, 7));
}

function spades_bot_suit_groups(array $cards): array
{
    $groups = ['C' => [], 'D' => [], 'H' => [], 'S' => []];
    foreach ($cards as $card) {
        $parts = spades_card_parts((string)$card);
        $groups[$parts['suit']][] = (int)$parts['rank'];
    }
    foreach ($groups as &$ranks) rsort($ranks, SORT_NUMERIC);
    unset($ranks);
    return $groups;
}

function spades_bot_estimated_tricks(array $observation): int
{
    $groups = spades_bot_suit_groups($observation['ownHand']);
    $estimate = 0.0;
    foreach ($groups as $suit => $ranks) {
        $length = count($ranks);
        foreach ($ranks as $rank) if ($rank > 14) $estimate += 1.0;
        $hasAce = in_array(14, $ranks, true);
        $hasKing = in_array(13, $ranks, true);
        if ($hasAce) $estimate += 0.98;
        if ($hasKing) $estimate += $hasAce ? 0.92 : ($length <= 3 ? 0.64 : 0.48);
        if (in_array(12, $ranks, true)) $estimate += ($hasAce && $hasKing) ? 0.78 : ($length <= 3 ? 0.28 : 0.12);
        if (in_array(11, $ranks, true) && $hasAce && $hasKing) $estimate += 0.48;
        if ($suit === 'S') {
            foreach ($ranks as $rank) {
                if ($rank === 14) $estimate += 0.34;
                elseif ($rank === 13) $estimate += 0.30;
                elseif ($rank >= 11) $estimate += 0.22;
                elseif ($rank >= 9) $estimate += 0.10;
            }
            if ($length >= 5) $estimate += ($length - 4) * 0.46;
        } elseif ($length === 0 && count($groups['S']) >= 3) {
            $estimate += 0.72;
        } elseif ($length === 1 && count($groups['S']) >= 4) {
            $estimate += 0.38;
        }
    }
    $team = spades_team_index(['turnOrder' => $observation['turnOrder']], (int)$observation['botUserId']);
    $bags = (int)($observation['teamBags'][(string)$team] ?? 0);
    $scores = array_map('intval', $observation['teamScores']);
    $winningScore = (int)($observation['settings']['winningScore'] ?? 500);
    $scoreLead = ($scores[$team] ?? 0) - ($scores[1 - $team] ?? 0);
    if ($scoreLead > 0 && $bags >= 7) $estimate -= 0.65;
    if (($scores[1 - $team] ?? 0) >= $winningScore - 100 && $scoreLead < 0) $estimate += 0.45;
    if ($observation['difficulty'] === 'expert') {
        if ($bags <= 6) $estimate += 0.32;
        if (count($groups['S']) >= 5) $estimate += 0.12;
        if ($bags >= 8 && $scoreLead > 0) $estimate -= 0.22;
    } elseif ($observation['difficulty'] === 'normal') {
        $variation = spades_bot_stable_number($observation, 'bid') % 5;
        if ($variation === 0) $estimate -= 0.45;
        if ($variation === 4) $estimate += 0.45;
    }
    return max(0, min(13, (int)round($estimate)));
}

function spades_bot_nil_risk(array $observation): float
{
    $groups = spades_bot_suit_groups($observation['ownHand']);
    $risk = 0.0;
    foreach ($groups as $suit => $ranks) {
        $length = count($ranks);
        foreach ($ranks as $rank) {
            $lowCover = count(array_filter($ranks, static fn(int $r): bool => $r <= 7));
            $protected = $suit !== 'S' && $length >= 4 && $lowCover >= 2;
            if ($rank >= 14) $risk += $rank > 14 ? 8.0 : 5.0;
            elseif ($rank === 13) $risk += 3.4;
            elseif ($rank === 12) $risk += $protected ? 1.1 : 2.1;
            elseif ($rank === 11) $risk += $protected ? 0.5 : 1.1;
            elseif ($suit === 'S' && $rank >= 9) $risk += 0.75;
        }
        if ($suit === 'S' && $length >= 4) $risk += ($length - 3) * 0.9;
        if ($length >= 6) $risk += 0.8;
        if ($length <= 2 && $ranks !== [] && max($ranks) <= 8) $risk -= 0.3;
    }
    return $risk;
}

function spades_bot_bid(array $state, int $botUserId, array $observation): array
{
    $risk = spades_bot_nil_risk($observation);
    $estimate = spades_bot_estimated_tricks($observation);
    $team = spades_team_index($state, $botUserId);
    $scores = array_map('intval', $observation['teamScores']);
    $winningScore = (int)($observation['settings']['winningScore'] ?? 500);
    $scorePressure = ($scores[1 - $team] ?? 0) >= $winningScore - 100;
    $partner = spades_bot_public_partner_id($observation['turnOrder'], $botUserId);
    $partnerNil = in_array((string)($observation['bids'][(string)$partner]['kind'] ?? ''), ['nil','blind-nil'], true);
    $amount = max(spades_minimum_standard_bid_for_actor($state, $botUserId), $estimate);
    $partnerBid = (array)($observation['bids'][(string)$partner] ?? []);
    $support = (int)($partnerBid['amount'] ?? 0);
    $exchange = !$partnerNil && !empty($observation['settings']['regularNilOneCardExchange']);
    // A conservative rule estimate checked against separately played outcomes.
    // This is not a fitted/released model or a calibrated probability guarantee.
    $survival = 0.96 - max(0.0,$risk) * 0.17 + ($exchange ? 0.14 : 0.0);
    $survival += $partnerBid === [] ? -0.06 : min(0.04,max(-0.05,($support-2)*0.015));
    if ($partnerNil) $survival -= 0.25;
    $survival = max(0.05,min(0.98,$survival));
    $nilValue = (int)($observation['settings']['nilBidScore'] ?? 50);
    $nilUtility = (2*$survival-1)*$nilValue;
    $standardUtility = spades_contract_score($support+$amount,true,$observation['settings'])
        - spades_contract_score($support,true,$observation['settings']);
    $expert = $observation['difficulty'] === 'expert';
    $floor = $expert ? 0.75 : 0.79;
    if ($expert && $scorePressure && ($scores[$team]??0)<($scores[1-$team]??0)) $floor -= 0.02;
    $nearSafeWin = ($scores[$team]??0)+spades_contract_score($support+$amount,true,$observation['settings']) >= $winningScore
        && ($scores[$team]??0)>($scores[1-$team]??0);
    if ($nearSafeWin) $floor = max($floor,0.95);
    $hardTrump = array_filter($observation['ownHand'],static fn(string $c):bool=>spades_card_parts($c)['suit']==='S' && spades_bot_card_value($c)>=14);
    $nil = $hardTrump === [] && $estimate <= 2 && $risk <= 3.0 && $survival >= $floor
        && $nilUtility > $standardUtility + ($expert ? 4 : 8)
        && (!$partnerNil || $risk <= 0.25)
        && spades_team_bid_candidate_meets_minimum($state,$botUserId,'nil',0);
    $choice = $nil ? ['kind'=>'nil','amount'=>0] : ['kind'=>'standard','amount'=>$amount];
    $GLOBALS['spades_bot_last_bid_trace'] = ['selected'=>$choice,'risk'=>$risk,'estimatedTricks'=>$estimate,
        'publicPartnerBid'=>$partnerBid,'exchange'=>$exchange,'survivalEstimate'=>$survival,'requiredSurvival'=>$floor,
        'nilUtility'=>$nilUtility,'standardUtility'=>$standardUtility,'nearSafeWin'=>$nearSafeWin,
        'reason'=>$nil?'nil-risk-and-score-value':'standard-contract-or-nil-safety'];
    return $choice;
}

function spades_bot_should_blind_nil(array $state, int $botUserId, array $observation): bool
{
    if ($observation['difficulty'] !== 'expert' || !spades_blind_available($state, $botUserId)
        || !spades_team_bid_candidate_meets_minimum($state, $botUserId, 'blind-nil', 0)) return false;
    $team = spades_team_index($state, $botUserId);
    $own = (int)($observation['teamScores'][(string)$team] ?? 0);
    $other = (int)($observation['teamScores'][(string)(1 - $team)] ?? 0);
    $winningScore = (int)($observation['settings']['winningScore'] ?? 500);
    $blindValue = (int)($observation['settings']['blindNilScore'] ?? 100);
    $deficit = $other - $own;
    $opponentNearWin = $other >= $winningScore - max(100, $blindValue);
    $ordinaryRecoveryTooSlow = $own + 100 < $other && $other >= $winningScore - 150;
    return $deficit >= max(200, $blindValue * 2) && ($opponentNearWin || $ordinaryRecoveryTooSlow);
}

function spades_bot_trick_winner(array $trick): ?int
{
    if ($trick === []) return null;
    $leadSuit = spades_card_parts((string)$trick[0]['card'])['suit'];
    $best = $trick[0];
    foreach (array_slice($trick, 1) as $play) {
        $candidate = spades_card_parts((string)$play['card']);
        $current = spades_card_parts((string)$best['card']);
        $candidateTrump = $candidate['suit'] === 'S';
        $currentTrump = $current['suit'] === 'S';
        $beats = ($candidateTrump && !$currentTrump)
            || ($candidateTrump === $currentTrump && $candidate['suit'] === $current['suit'] && $candidate['rank'] > $current['rank'])
            || (!$candidateTrump && !$currentTrump && $candidate['suit'] === $leadSuit && $current['suit'] !== $leadSuit);
        if ($beats) $best = $play;
    }
    return (int)$best['userId'];
}

function spades_bot_unseen_cards(array $observation): array
{
    return array_values(array_diff(
        spades_deck((array)($observation['settings'] ?? [])),
        array_values(array_unique(array_merge($observation['ownHand'], $observation['playedCards'])))
    ));
}

function spades_bot_legal_cards_from_observation(array $observation): array
{
    $hand = $observation['ownHand'];
    if ($hand === []) return [];
    $tricksPlayed = array_sum(array_map('intval', $observation['tricksWon']));
    $eligible = array_values(array_filter($hand, static fn(string $card): bool => $tricksPlayed > 0 || spades_card_parts($card)['suit'] !== 'S'));
    if ($eligible === []) $eligible = $hand;
    $trick = $observation['currentTrick'];
    if ($trick !== []) {
        $leadSuit = spades_card_parts((string)$trick[0]['card'])['suit'];
        $following = array_values(array_filter($eligible, static fn(string $card): bool => spades_card_parts($card)['suit'] === $leadSuit));
        return $following !== [] ? $following : $eligible;
    }
    if (empty($observation['spadesBroken'])) {
        $nonSpades = array_values(array_filter($eligible, static fn(string $card): bool => spades_card_parts($card)['suit'] !== 'S'));
        if ($nonSpades !== []) return $nonSpades;
    }
    return $eligible;
}

function spades_bot_team_contract_tricks(array $observation, int $team, ?array $tricksWon = null): int
{
    $tricks = $tricksWon ?? $observation['tricksWon'];
    $nilCounts = !empty($observation['settings']['nilTricksCountTowardTeamBid']);
    $total = 0;
    foreach ($observation['turnOrder'] as $index => $userId) {
        if ($index % 2 !== $team) continue;
        $bid = (array)($observation['bids'][(string)$userId] ?? []);
        $isNil = in_array((string)($bid['kind'] ?? ''), ['nil', 'blind-nil'], true);
        if ($isNil && !$nilCounts) continue;
        $total += (int)($tricks[(string)$userId] ?? 0);
    }
    return $total;
}

function spades_bot_count_distributions(array $players, int $cardCount, array $capacities, int $index = 0, array $current = []): array
{
    if ($players === []) return $cardCount === 0 ? [$current] : [];
    $userId = (int)$players[$index];
    if ($index === count($players) - 1) {
        if ($cardCount < 0 || $cardCount > (int)($capacities[(string)$userId] ?? 0)) return [];
        $current[(string)$userId] = $cardCount;
        return [$current];
    }
    $results = [];
    $maximum = min($cardCount, (int)($capacities[(string)$userId] ?? 0));
    for ($count = 0; $count <= $maximum; $count++) {
        $next = $current;
        $next[(string)$userId] = $count;
        foreach (spades_bot_count_distributions($players, $cardCount - $count, $capacities, $index + 1, $next) as $distribution) {
            $results[] = $distribution;
        }
    }
    return $results;
}

function spades_bot_allocation_feasible(array $observation, array $plans, int $nextPlan, array $capacities): bool
{
    $remainingCards = 0;
    for ($index = $nextPlan; $index < count($plans); $index++) $remainingCards += (int)$plans[$index]['count'];
    if (array_sum(array_map('intval', $capacities)) !== $remainingCards) return false;
    foreach ($capacities as $userId => $capacity) {
        $available = 0;
        for ($index = $nextPlan; $index < count($plans); $index++) {
            if (!in_array((string)$plans[$index]['suit'], (array)($observation['voidSuits'][(string)$userId] ?? []), true)) $available += (int)$plans[$index]['count'];
        }
        if ((int)$capacity > $available) return false;
    }
    for ($index = $nextPlan; $index < count($plans); $index++) {
        $eligibleCapacity = 0;
        foreach ($capacities as $userId => $capacity) {
            if (!in_array((string)$plans[$index]['suit'], (array)($observation['voidSuits'][(string)$userId] ?? []), true)) $eligibleCapacity += (int)$capacity;
        }
        if ($eligibleCapacity < (int)$plans[$index]['count']) return false;
    }
    return true;
}

function spades_bot_allocate_suits(array $observation, array $plans, int $position, array $capacities, int $seed, array &$allocation): bool
{
    if ($position >= count($plans)) return array_sum(array_map('intval', $capacities)) === 0;
    $plan = $plans[$position];
    $eligible = array_values(array_filter(array_map('intval', array_keys($capacities)), static fn(int $userId): bool => !in_array((string)$plan['suit'], (array)($observation['voidSuits'][(string)$userId] ?? []), true)));
    $options = spades_bot_count_distributions($eligible, (int)$plan['count'], $capacities);
    $capacityTotal = max(1, array_sum(array_map(static fn(int $id): int => (int)($capacities[(string)$id] ?? 0), $eligible)));
    usort($options, static function(array $left, array $right) use ($eligible, $capacities, $capacityTotal, $plan, $seed): int {
        $leftDeviation = 0.0; $rightDeviation = 0.0;
        foreach ($eligible as $userId) {
            $expected = (int)$plan['count'] * (int)($capacities[(string)$userId] ?? 0) / $capacityTotal;
            $leftDeviation += abs((int)($left[(string)$userId] ?? 0) - $expected);
            $rightDeviation += abs((int)($right[(string)$userId] ?? 0) - $expected);
        }
        $leftKey = hash('sha256', $seed . ':' . $plan['suit'] . ':' . json_encode($left));
        $rightKey = hash('sha256', $seed . ':' . $plan['suit'] . ':' . json_encode($right));
        return [$leftDeviation, $leftKey] <=> [$rightDeviation, $rightKey];
    });
    foreach ($options as $option) {
        $nextCapacities = $capacities;
        foreach ($option as $userId => $count) $nextCapacities[(string)$userId] -= (int)$count;
        if (!spades_bot_allocation_feasible($observation, $plans, $position + 1, $nextCapacities)) continue;
        $allocation[(string)$plan['suit']] = $option;
        if (spades_bot_allocate_suits($observation, $plans, $position + 1, $nextCapacities, $seed, $allocation)) return true;
        unset($allocation[(string)$plan['suit']]);
    }
    return false;
}

function spades_bot_card_owner_weight(array $observation, int $userId, string $card): float
{
    $parts = spades_card_parts($card);
    $bid = (array)($observation['bids'][(string)$userId] ?? []);
    $kind = (string)($bid['kind'] ?? 'standard');
    $amount = (int)($bid['amount'] ?? 0);
    if (in_array($kind, ['nil', 'blind-nil'], true)) {
        $weight = $parts['rank'] >= 12 ? 0.22 : ($parts['rank'] <= 8 ? 1.55 : 0.75);
        if ($parts['suit'] === 'S' && $parts['rank'] >= 9) $weight *= 0.45;
        return max(0.05, $weight);
    }
    $weight = 1.0 + max(0, $amount - 3) * 0.08;
    if ($parts['rank'] >= 12) $weight += max(0, $amount - 3) * 0.06;
    if ($parts['suit'] === 'S' && $parts['rank'] >= 9) $weight += max(0, $amount - 2) * 0.08;
    return $weight;
}

function spades_bot_sample_world(array $observation, int $sampleIndex): ?array
{
    $botUserId = (int)$observation['botUserId'];
    $unseen = spades_bot_unseen_cards($observation);
    $recipients = array_values(array_filter($observation['turnOrder'], static fn(int $userId): bool => $userId !== $botUserId));
    $capacities = [];
    foreach ($recipients as $userId) $capacities[(string)$userId] = (int)($observation['remainingCounts'][(string)$userId] ?? 0);
    $cardsBySuit = ['C' => [], 'D' => [], 'H' => [], 'S' => []];
    foreach ($unseen as $card) $cardsBySuit[spades_card_parts((string)$card)['suit']][] = (string)$card;
    $plans = [];
    foreach ($cardsBySuit as $suit => $cards) {
        $eligibleCount = count(array_filter($recipients, static fn(int $id): bool => !in_array($suit, (array)($observation['voidSuits'][(string)$id] ?? []), true)));
        $plans[] = ['suit' => $suit, 'count' => count($cards), 'eligibleCount' => $eligibleCount];
    }
    usort($plans, static fn(array $left, array $right): int => [$left['eligibleCount'], -$left['count'], $left['suit']] <=> [$right['eligibleCount'], -$right['count'], $right['suit']]);
    $seed = spades_bot_stable_number($observation, 'balanced-world:' . $sampleIndex);
    $allocation = [];
    if (!spades_bot_allocate_suits($observation, $plans, 0, $capacities, $seed, $allocation)) return null;
    $hands = [(string)$botUserId => $observation['ownHand']];
    foreach ($recipients as $userId) $hands[(string)$userId] = [];
    foreach ($cardsBySuit as $suit => $cards) {
        $cardSortKeys = [];
        foreach ($cards as $card) $cardSortKeys[(string)$card] = hash('sha256', $seed . ':' . $card);
        usort($cards, static fn(string $left, string $right): int => $cardSortKeys[$left] <=> $cardSortKeys[$right]);
        $remaining = (array)($allocation[$suit] ?? []);
        foreach ($cards as $card) {
            $eligible = array_values(array_filter($recipients, static fn(int $id): bool => (int)($remaining[(string)$id] ?? 0) > 0));
            if ($eligible === []) return null;
            $ownerSortKeys = [];
            foreach ($eligible as $eligibleUserId) {
                $weight = spades_bot_card_owner_weight($observation, $eligibleUserId, $card);
                $noise = hexdec(substr(hash('sha256', $seed . ':' . $card . ':' . $eligibleUserId), 0, 6)) / 0xFFFFFF;
                $ownerSortKeys[(string)$eligibleUserId] = [-($weight + $noise), $eligibleUserId];
            }
            usort($eligible, static fn(int $left, int $right): int => $ownerSortKeys[(string)$left] <=> $ownerSortKeys[(string)$right]);
            $target = $eligible[0];
            $hands[(string)$target][] = $card;
            $remaining[(string)$target]--;
        }
    }
    return $hands;
}

function spades_bot_sample_worlds(array $observation, ?int $deadlineNs = null): array
{
    $lateHand = count($observation['ownHand']) <= 7;
    $target = $lateHand ? 48 : 32;
    $minimum = $lateHand ? 36 : 24;
    $worlds = [];
    $unique = [];
    for ($attempt = 0; $attempt < $target * 5 && count($worlds) < $target; $attempt++) {
        if (spades_bot_deadline_reached($deadlineNs)) break;
        $world = spades_bot_sample_world($observation, $attempt);
        if (!is_array($world)) continue;
        $signatureParts = [];
        foreach ($observation['turnOrder'] as $userId) {
            $cards = array_values((array)($world[(string)$userId] ?? [])); sort($cards, SORT_STRING);
            $signatureParts[] = $userId . ':' . implode(',', $cards);
        }
        $signature = hash('sha256', implode('|', $signatureParts));
        if (isset($unique[$signature]) && $attempt < $target * 3) continue;
        $unique[$signature] = true;
        $worlds[] = $world;
    }
    if (count($worlds) < $minimum && !spades_bot_deadline_reached($deadlineNs)) {
        throw new MultiplayerGameException('Expert could not construct enough public-consistent card distributions.', 'SPADES_BOT_WORLD_SAMPLE_INSUFFICIENT', 500, ['required' => $minimum, 'available' => count($worlds)]);
    }
    return $worlds;
}

function spades_bot_rollout_legal_cards(array $hand, array $trick, bool $spadesBroken, int $tricksPlayed): array
{
    if ($hand === []) return [];
    $eligible = array_values(array_filter($hand, static fn(string $card): bool => $tricksPlayed > 0 || spades_card_parts($card)['suit'] !== 'S'));
    if ($eligible === []) $eligible = $hand;
    if ($trick !== []) {
        $leadSuit = spades_card_parts((string)$trick[0]['card'])['suit'];
        $following = array_values(array_filter($eligible, static fn(string $card): bool => spades_card_parts($card)['suit'] === $leadSuit));
        return $following !== [] ? $following : $eligible;
    }
    if (!$spadesBroken) {
        $nonSpades = array_values(array_filter($eligible, static fn(string $card): bool => spades_card_parts($card)['suit'] !== 'S'));
        if ($nonSpades !== []) return $nonSpades;
    }
    return $eligible;
}

function spades_bot_public_partner_id(array $turnOrder, int $actorUserId): int
{
    $seat = array_search($actorUserId, array_map('intval', $turnOrder), true);
    if ($seat === false) return 0;
    return (int)$turnOrder[((int)$seat + 2) % 4];
}

/** True only while this player's declared Nil can still succeed. */
function spades_bot_nil_live(array $observation, int $userId, ?array $tricksWon = null): bool
{
    return in_array((string)($observation['bids'][(string)$userId]['kind'] ?? ''), ['nil','blind-nil'], true)
        && (int)(($tricksWon ?? $observation['tricksWon'])[(string)$userId] ?? 0) === 0;
}

/** Relative Nil liability; trump winners are harder to discard than side cards. */
function spades_bot_nil_card_danger(string $card): float
{
    $parts = spades_card_parts($card);
    return $parts['rank'] + ($parts['suit'] === 'S' ? 5.5 : 0.0)
        + ($parts['rank'] >= 12 ? 4.0 : 0.0)
        + ($parts['suit'] === 'S' && $parts['rank'] >= 12 ? 18.0 + ($parts['rank'] - 12) * 12.0 : 0.0);
}

/** Own-hand exposure: low cards shelter later side-suit honors; trumps do not discard. */
function spades_bot_nil_hand_exposure(array $cards): float
{
    $risk = 0.0;
    foreach (spades_bot_suit_groups($cards) as $suit => $ranks) {
        sort($ranks, SORT_NUMERIC);
        foreach ($ranks as $index => $rank) {
            $gap = max(0, $rank - ($suit === 'S' ? 4 + $index : 5 + 2 * $index));
            $risk += $gap * $gap * ($suit === 'S' ? 1.7 : 1.0) / ($index + 1);
            if ($suit === 'S' && $rank >= 14) $risk += 400 + ($rank - 14) * 200;
        }
    }
    return $risk;
}

/** Two tricks of public-consistent forecast; sampled hands never leave this evaluator. */
function spades_bot_nil_forecast(array $observation, array $world, string $firstCard): array
{
    $actor = (int)$observation['botUserId'];
    $partner = spades_bot_public_partner_id($observation['turnOrder'], $actor);
    $turn = (int)array_search($actor, $observation['turnOrder'], true);
    $trick = $observation['currentTrick']; $won = $observation['tricksWon'];
    $broken = !empty($observation['spadesBroken']); $finished = 0; $failed = [false,false];
    for ($step = 0; $step < 8; $step++) {
        $id = (int)$observation['turnOrder'][$turn];
        $legal = spades_bot_rollout_legal_cards($world[(string)$id], $trick, $broken, array_sum($won));
        if ($legal === []) break;
        if ($step === 0) $card = $firstCard;
        else {
            $view = $observation;
            $view['botUserId'] = $id; $view['ownHand'] = $world[(string)$id];
            $view['currentTrick'] = $trick; $view['tricksWon'] = $won;
            $view['difficulty'] = 'normal';
            $tactic = spades_bot_partnership_tactic($view,$legal);
            $card = $tactic['card'] ?? spades_bot_rollout_policy($view,$id,$legal,$trick,$won,$step);
        }
        $index = array_search($card,$world[(string)$id],true);
        if ($index === false) break;
        array_splice($world[(string)$id],(int)$index,1);
        if ($trick !== []) {
            $lead = spades_card_parts($trick[0]['card'])['suit'];
            if (spades_card_parts($card)['suit'] !== $lead) $observation['voidSuits'][(string)$id][] = $lead;
        }
        $observation['playedCards'][] = $card;
        $trick[] = ['userId'=>$id,'card'=>$card];
        if (spades_card_parts($card)['suit'] === 'S') $broken = true;
        $turn = ($turn + 1) % 4;
        if (count($trick) === 4) {
            $winner = (int)spades_bot_trick_winner($trick); $won[(string)$winner]++;
            $failed[$finished] = (int)$won[(string)$partner] > 0;
            $finished++; $trick = []; $turn = (int)array_search($winner,$observation['turnOrder'],true);
            if ($finished === 2) break;
        }
    }
    return $failed;
}

function spades_bot_normal_nil_lookahead(array $observation, array $legal, array $fallback): array
{
    if (count($observation['ownHand']) < 2) return $fallback;
    $worlds = [];
    for ($sample = 0; $sample < 6; $sample++) {
        $world = spades_bot_sample_world($observation, 900 + $sample);
        if ($world === null) return $fallback;
        $worlds[] = $world;
    }
    $failures = []; $scores = [];
    foreach ($legal as $card) {
        $first = 0; $later = 0;
        foreach ($worlds as $world) {
            [$now,$next] = spades_bot_nil_forecast($observation,$world,$card);
            $first += (int)$now; $later += (int)$next;
        }
        $failures[$card] = [$first,$later];
        $scores[$card] = -$first * 200 - $later * 100;
    }
    $best = $fallback['card']; $original = $failures[$best];
    foreach ($legal as $card) {
        // Require a clear survival improvement; never trade a current failure
        // for a hypothetical future benefit or change a tied forecast.
        if ($failures[$card][0] <= $original[0] && $scores[$card] > $scores[$best]) $best = $card;
    }
    return ['card'=>$best,'reason'=>$best===$fallback['card']?$fallback['reason']:'normal-two-trick-nil-cover',
        'candidateScores'=>$scores,'forecastFailures'=>$failures,'worlds'=>count($worlds),'fallback'=>$fallback['card']];
}

/** Public tactical choices shared by Normal and Expert; null leaves search in charge. */
function spades_bot_partnership_tactic(array $observation, array $legal): ?array
{
    $actor = (int)$observation['botUserId'];
    $partner = spades_bot_public_partner_id($observation['turnOrder'], $actor);
    $trick = $observation['currentTrick'];
    $winner = spades_bot_trick_winner($trick);
    $ownNil = spades_bot_nil_live($observation, $actor);
    $partnerNil = spades_bot_nil_live($observation, $partner);
    $sorted = array_values($legal);
    usort($sorted, static fn(string $a, string $b): int =>
        [spades_bot_card_value($a), $a] <=> [spades_bot_card_value($b), $b]);
    $winning = []; $losing = [];
    foreach ($sorted as $card) {
        if (spades_bot_trick_winner(array_merge($trick, [['userId'=>$actor,'card'=>$card]])) === $actor) $winning[]=$card;
        else $losing[]=$card;
    }
    $choice = static fn(string $card, string $reason): array => ['card'=>$card,'reason'=>$reason];
    if ($ownNil && $losing !== []) {
        usort($losing, static fn(string $a, string $b): int =>
            [spades_bot_nil_card_danger($a), $a] <=> [spades_bot_nil_card_danger($b), $b]);
        return $choice((string)end($losing), 'own-live-nil-highest-safe-discard');
    }
    if (!$ownNil && $partnerNil && $winner === $partner && $winning !== []) {
        return $choice($winning[0], 'cover-partner-live-nil');
    }
    if (!$ownNil && count($trick) === 3 && $winner !== null && $winner !== $partner
        && spades_bot_nil_live($observation, $winner) && $losing !== []) {
        return $choice($losing[0], 'leave-opponent-live-nil-winning');
    }
    if (!$ownNil && !$partnerNil && $winner === $partner && count($trick) === 3 && $losing !== []) {
        return $choice($losing[0], 'preserve-partner-winner');
    }

    // Partner has not played: measure which still-unseen legal cards our cover
    // would stop from winning. Never consult their real hand or a sampled hand.
    $partnerPlayed = in_array($partner, array_map(static fn(array $p): int => (int)$p['userId'], $trick), true);
    if (!$ownNil && $partnerNil && !$partnerPlayed) {
        $voids = (array)($observation['voidSuits'][(string)$partner] ?? []);
        // With an unproven partner void, high trump can be needless cover and
        // exhaust the cards needed later. Expert retains its multi-trick search
        // for this uncertain discard/ruff decision; following suit and known
        // partner voids still receive immediate protection.
        if ($observation['difficulty'] === 'expert' && $trick !== []) {
            $ledSuit = spades_card_parts((string)$trick[0]['card'])['suit'];
            $canFollow = array_filter($sorted, static fn(string $c): bool => spades_card_parts($c)['suit'] === $ledSuit);
            if ($canFollow === [] && !in_array($ledSuit, $voids, true)) return null;
        }
        $unseen = spades_bot_unseen_cards($observation);
        $scores = [];
        foreach ($sorted as $card) {
            $parts = spades_card_parts($card);
            $lead = $trick === [] ? $parts['suit'] : spades_card_parts((string)$trick[0]['card'])['suit'];
            $after = array_merge($trick, [['userId'=>$actor,'card'=>$card]]);
            $risk = 0.0;
            foreach ($unseen as $unknown) {
                $other = spades_card_parts($unknown);
                if (in_array($other['suit'], $voids, true)) continue;
                // Known voids are facts; unknown voids are possible, not certain.
                if ($other['suit'] !== $lead && $other['suit'] !== 'S') continue;
                $weight = $other['suit'] === $lead ? 1.0 : (in_array($lead,$voids,true) ? 1.0 : 0.15);
                if (spades_bot_trick_winner(array_merge($after,[['userId'=>$partner,'card'=>$unknown]])) === $partner) $risk += $weight;
            }
            // Retain a high card when an existing opponent card already covers
            // the same possibilities. Avoid leading a suit partner is known void in.
            $cost = $parts['rank'] * 0.12 + ($parts['suit']==='S' ? 0.5 : 0.0);
            $scores[$card] = -$risk * 10.0 - $cost;
            if ($trick === [] && in_array($lead,$voids,true) && !in_array('S',$voids,true)) $scores[$card] -= 100.0;
        }
        arsort($scores, SORT_NUMERIC);
        $best = (string)array_key_first($scores);
        return $choice($best, 'proactive-partner-nil-public-cover') + ['candidateScores'=>$scores];
    }
    // If every legal card follows trump and none can beat the current winner,
    // a higher trump cannot help this trick. Own Nil shedding took priority above.
    if (!$ownNil && $winning === [] && $sorted !== []
        && count(array_filter($sorted, static fn(string $c): bool => spades_card_parts($c)['suit']==='S')) === count($sorted)) {
        return $choice($sorted[0], 'unwinnable-trump-conservation');
    }
    return null;
}

function spades_bot_public_third_seat_high(array $trick, int $actorUserId, array $legal): ?string
{
    if (count($trick) !== 2) return null;
    $leadSuit = spades_card_parts((string)$trick[0]['card'])['suit'];
    $best = null;
    $bestPower = PHP_INT_MIN;
    foreach ($legal as $card) {
        $card = (string)$card;
        if (spades_trick_winner(array_merge($trick, [['userId' => $actorUserId, 'card' => $card]])) !== $actorUserId) continue;
        $parts = spades_card_parts($card);
        $power = ($parts['suit'] === 'S' && $leadSuit !== 'S' ? 100 : ($parts['suit'] === $leadSuit ? 50 : 0)) + $parts['rank'];
        if ($best === null || $power > $bestPower) {
            $best = $card;
            $bestPower = $power;
        }
    }
    return $best;
}

function spades_bot_rollout_policy(array $observation, int $actorUserId, array $legal, array $trick, array $tricksWon, int $step): string
{
    $baseline = spades_bot_rollout_policy_baseline($observation, $actorUserId, $legal, $trick, $tricksWon, $step);
    if (count($trick) !== 2 || count($legal) < 2) return $baseline;
    $bid = (array)($observation['bids'][(string)$actorUserId] ?? []);
    if (spades_bot_nil_live($observation, $actorUserId, $tricksWon)) return $baseline;
    $partner = spades_bot_public_partner_id($observation['turnOrder'], $actorUserId);
    $winnerId = spades_bot_trick_winner($trick);
    if (spades_bot_nil_live($observation, $partner, $tricksWon)
        || ($winnerId !== null && spades_bot_nil_live($observation, $winnerId, $tricksWon))) return $baseline;
    $team = spades_team_index(['turnOrder' => $observation['turnOrder']], $actorUserId);
    $teamBid = (int)($observation['teamBids'][(string)$team]['amount'] ?? 0);
    if ($teamBid > 0 && spades_bot_team_contract_tricks($observation, $team) >= $teamBid) return $baseline;
    if (spades_trick_winner($trick) === spades_bot_public_partner_id($observation['turnOrder'], $actorUserId)) return $baseline;
    $winner = spades_bot_public_third_seat_high($trick, $actorUserId, array_values(array_map('strval', $legal)));
    return $winner ?? $baseline;
}
function spades_bot_rollout_policy_baseline(array $observation, int $actorUserId, array $legal, array $trick, array $tricksWon, int $step): string
{
    $team = spades_team_index(['turnOrder' => $observation['turnOrder']], $actorUserId);
    $partner = (int)$observation['turnOrder'][(array_search($actorUserId, $observation['turnOrder'], true) + 2) % 4];
    $currentWinner = spades_bot_trick_winner($trick);
    $teamBid = (int)($observation['teamBids'][(string)$team]['amount'] ?? 0);
    $need = max(0, $teamBid - spades_bot_team_contract_tricks($observation, $team, $tricksWon));
    $actorBid = (array)($observation['bids'][(string)$actorUserId] ?? []);
    $actorNil = spades_bot_nil_live($observation, $actorUserId, $tricksWon);
    $partnerBid = (array)($observation['bids'][(string)$partner] ?? []);
    $partnerNil = spades_bot_nil_live($observation, $partner, $tricksWon);
    $profile = intdiv($step, 100) % 3;
    $stableSeed = spades_bot_stable_number($observation, 'rollout:' . $step);
    $opponentNilWinning = false;
    foreach ($observation['bids'] as $userId => $bid) {
        if (spades_team_index(['turnOrder' => $observation['turnOrder']], (int)$userId) === $team) continue;
        if (spades_bot_nil_live($observation, (int)$userId, $tricksWon) && $currentWinner === (int)$userId) {
            $opponentNilWinning = true;
            break;
        }
    }
    $bestCard = null;
    $bestScore = -INF;
    $bestTie = '';
    foreach ($legal as $card) {
        $winner = spades_bot_trick_winner(array_merge($trick, [['userId' => $actorUserId, 'card' => $card]]));
        $wins = $winner === $actorUserId;
        $rank = spades_bot_card_value((string)$card);
        $score = -$rank * 0.4 - (spades_card_parts((string)$card)['suit'] === 'S' ? 1.5 : 0.0);
        if ($actorNil) $score += $wins ? -180.0 : 55.0 + $rank;
        elseif ($partnerNil && $currentWinner === $partner) $score += $wins ? 100.0 : -90.0;
        elseif ($currentWinner === $partner) $score += $wins ? -24.0 : 30.0;
        elseif ($need > 0) $score += $wins ? 48.0 : -10.0;
        else $score += $wins ? -14.0 : 20.0;
        if ($opponentNilWinning && !$actorNil) $score += $wins ? -100.0 : 90.0;
        if ($profile === 0 && $need > 0 && $wins) $score += 12.0;
        if ($profile === 1 && $actorNil) $score += $wins ? -36.0 : 18.0;
        if ($profile === 1 && !$actorNil && $currentWinner !== null && spades_team_index(['turnOrder' => $observation['turnOrder']], $currentWinner) !== $team) $score += $wins ? 10.0 : -4.0;
        if ($profile === 2) {
            $bags = (int)($observation['teamBags'][(string)$team] ?? 0);
            if ($bags >= 7 && $need === 0) $score += $wins ? -30.0 : 20.0;
        }
        $tie = hash('sha256', $stableSeed . ':' . $actorUserId . ':' . $card);
        if ($bestCard === null || $score > $bestScore || ($score === $bestScore && strcmp($tie, $bestTie) > 0)) {
            $bestCard = (string)$card;
            $bestScore = $score;
            $bestTie = $tie;
        }
    }
    return (string)$bestCard;
}

function spades_bot_rollout_utility(array $observation, array $tricksWon): float
{
    $botTeam = spades_team_index(['turnOrder' => $observation['turnOrder']], (int)$observation['botUserId']);
    $handScores = [0.0, 0.0];
    foreach ([0, 1] as $team) {
        $teamBid = (array)($observation['teamBids'][(string)$team] ?? []);
        $bidAmount = (int)($teamBid['amount'] ?? 0);
        $contractTricks = spades_bot_team_contract_tricks($observation, $team, $tricksWon);
        $totalTeamTricks = 0;
        foreach ($observation['turnOrder'] as $index => $userId) {
            if ($index % 2 === $team) $totalTeamTricks += (int)($tricksWon[(string)$userId] ?? 0);
        }
        $contractMade = $contractTricks >= $bidAmount;
        $overtricks = max(0, $totalTeamTricks - $bidAmount);
        $contractScore = spades_contract_score($bidAmount, $contractMade, (array)$observation['settings']);
        $bagScore = spades_bag_score((int)($observation['teamBags'][(string)$team] ?? 0), $overtricks, $contractMade, (array)$observation['settings']);
        $score = $contractScore + (int)$bagScore['bagPoints'] + (int)$bagScore['bagPenalty'];
        if (!empty($observation['settings']['bostonBonus']) && $totalTeamTricks === 13) $score += 200;
        $classification = (string)($teamBid['nilClassification'] ?? '');
        $teamUsers = array_values(array_filter($observation['turnOrder'], static fn(int $id, int $index): bool => $index % 2 === $team, ARRAY_FILTER_USE_BOTH));
        if (in_array($classification, ['double-nil', 'double-blind-nil'], true)) {
            $made = true;
            foreach ($teamUsers as $userId) if ((int)($tricksWon[(string)$userId] ?? 0) !== 0) $made = false;
            $value = $classification === 'double-blind-nil' ? (int)($observation['settings']['doubleBlindNilScore'] ?? 400) : (int)($observation['settings']['doubleNilScore'] ?? 200);
            $score += $made ? $value : -$value;
        } else {
            foreach ($teamUsers as $userId) {
                $bid = (array)($observation['bids'][(string)$userId] ?? []);
                $kind = (string)($bid['kind'] ?? 'standard');
                if (!in_array($kind, ['nil', 'blind-nil'], true)) continue;
                $value = $kind === 'blind-nil' ? (int)($observation['settings']['blindNilScore'] ?? 100) : (int)($observation['settings']['nilBidScore'] ?? 50);
                $score += (int)($tricksWon[(string)$userId] ?? 0) === 0 ? $value : -$value;
            }
        }
        $handScores[$team] = $score;
    }
    $ownProjected = (int)($observation['teamScores'][(string)$botTeam] ?? 0) + $handScores[$botTeam];
    $otherProjected = (int)($observation['teamScores'][(string)(1 - $botTeam)] ?? 0) + $handScores[1 - $botTeam];
    $winningScore = (int)($observation['settings']['winningScore'] ?? 500);
    $matchSwing = 0.0;
    if ($ownProjected >= $winningScore && $ownProjected > $otherProjected) $matchSwing += 1000.0;
    if ($otherProjected >= $winningScore && $otherProjected > $ownProjected) $matchSwing -= 1000.0;
    if (!empty($observation['settings']['legacyBackDoorEnding'])) {
        if ($otherProjected < -$winningScore && $ownProjected > $otherProjected) $matchSwing += 1000.0;
        if ($ownProjected < -$winningScore && $otherProjected > $ownProjected) $matchSwing -= 1000.0;
    }
    return $handScores[$botTeam] - $handScores[1 - $botTeam] + $matchSwing;
}

function spades_bot_ismcts_node_key(int $actorUserId, array $publicHistory): string
{
    return $actorUserId . ':' . hash('sha256', implode('|', $publicHistory));
}

function spades_bot_ismcts_normalized_utility(float $utility): float
{
    return max(-1.0, min(1.0, tanh($utility / 250.0)));
}

function spades_bot_ismcts_select_card(
    array $observation,
    int $actorUserId,
    array $legal,
    array $trick,
    array $tricksWon,
    array $node,
    int $policyStep
): string {
    $preferred = spades_bot_rollout_policy($observation, $actorUserId, $legal, $trick, $tricksWon, $policyStep);
    $botTeam = spades_team_index(['turnOrder' => $observation['turnOrder']], (int)$observation['botUserId']);
    $actorTeam = spades_team_index(['turnOrder' => $observation['turnOrder']], $actorUserId);
    $stableSeed = spades_bot_stable_number($observation, 'ismcts-select:' . $policyStep);
    $bestCard = null;
    $bestScore = -INF;
    $bestTie = '';
    foreach ($legal as $card) {
        $card = (string)$card;
        $edge = (array)($node['actions'][$card] ?? []);
        $visits = (int)($edge['visits'] ?? 0);
        $availability = max(1, (int)($edge['availability'] ?? 1));
        $prior = $card === $preferred ? 1.0 : 0.0;
        if ($visits === 0) {
            $score = 1000.0 + $prior * 10.0;
        } else {
            $mean = (float)($edge['reward'] ?? 0.0) / $visits;
            $exploitation = $actorTeam === $botTeam ? $mean : -$mean;
            $exploration = 0.72 * sqrt(log($availability + 1.0) / $visits);
            $progressiveBias = 0.18 * $prior / (1.0 + $visits);
            $score = $exploitation + $exploration + $progressiveBias;
        }
        $tie = hash('sha256', $stableSeed . ':' . $actorUserId . ':' . $card);
        if ($bestCard === null || $score > $bestScore || ($score === $bestScore && strcmp($tie, $bestTie) > 0)) {
            $bestCard = $card;
            $bestScore = $score;
            $bestTie = $tie;
        }
    }
    return (string)$bestCard;
}

function spades_bot_ismcts_iteration(array $observation, array $world, array &$tree, int $ensemble, int $iteration): void
{
    $turnOrder = $observation['turnOrder'];
    $botUserId = (int)$observation['botUserId'];
    $hands = $world;
    $hands[(string)$botUserId] = $observation['ownHand'];
    $trick = $observation['currentTrick'];
    $tricksWon = $observation['tricksWon'];
    $spadesBroken = !empty($observation['spadesBroken']);
    $turnIndex = (int)array_search($botUserId, $turnOrder, true);
    $publicHistoryKey = '';
    $path = [];
    $rollout = false;
    $remaining = 0;
    foreach ($hands as $hand) $remaining += count($hand);
    $tricksPlayed = array_sum(array_map('intval', $tricksWon));

    for ($step = 0; $step < 64; $step++) {
        if (count($trick) === 4) {
            $winner = (int)spades_bot_trick_winner($trick);
            $tricksWon[(string)$winner] = (int)($tricksWon[(string)$winner] ?? 0) + 1;
            $tricksPlayed++;
            $trick = [];
            $turnIndex = (int)array_search($winner, $turnOrder, true);
        }
        if ($remaining === 0) break;

        $actorUserId = (int)$turnOrder[$turnIndex];
        $hand = array_values((array)($hands[(string)$actorUserId] ?? []));
        $legal = spades_bot_rollout_legal_cards($hand, $trick, $spadesBroken, $tricksPlayed);
        if ($legal === []) return;
        $policyStep = $ensemble * 100000 + $iteration * 100 + $step;

        if ($rollout) {
            $card = spades_bot_rollout_policy($observation, $actorUserId, $legal, $trick, $tricksWon, $policyStep);
        } else {
            $nodeKey = $actorUserId . ':' . hash('sha256', $publicHistoryKey);
            if (!isset($tree[$nodeKey])) $tree[$nodeKey] = ['visits' => 0, 'actions' => []];
            foreach ($legal as $legalCard) {
                $legalCard = (string)$legalCard;
                if (!isset($tree[$nodeKey]['actions'][$legalCard])) {
                    $tree[$nodeKey]['actions'][$legalCard] = ['visits' => 0, 'availability' => 0, 'reward' => 0.0];
                }
                $tree[$nodeKey]['actions'][$legalCard]['availability']++;
            }
            $tree[$nodeKey]['visits']++;
            $card = spades_bot_ismcts_select_card($observation, $actorUserId, $legal, $trick, $tricksWon, $tree[$nodeKey], $policyStep);
            $path[] = [$nodeKey, $card];
            if ((int)$tree[$nodeKey]['actions'][$card]['visits'] === 0) $rollout = true;
        }

        $index = array_search($card, $hands[(string)$actorUserId], true);
        if ($index === false) return;
        array_splice($hands[(string)$actorUserId], (int)$index, 1);
        $remaining--;
        $leadSuit = $trick === [] ? null : spades_card_parts((string)$trick[0]['card'])['suit'];
        if (spades_card_parts($card)['suit'] === 'S' && $leadSuit !== 'S') $spadesBroken = true;
        $trick[] = ['userId' => $actorUserId, 'card' => $card];
        $historyEntry = $actorUserId . ':' . $card;
        $publicHistoryKey .= $publicHistoryKey === '' ? $historyEntry : '|' . $historyEntry;
        $turnIndex = ($turnIndex + 1) % 4;
    }

    if (count($trick) === 4) {
        $winner = (int)spades_bot_trick_winner($trick);
        $tricksWon[(string)$winner] = (int)($tricksWon[(string)$winner] ?? 0) + 1;
    }
    $reward = spades_bot_ismcts_normalized_utility(spades_bot_rollout_utility($observation, $tricksWon));
    foreach ($path as [$nodeKey, $card]) {
        $tree[$nodeKey]['actions'][$card]['visits']++;
        $tree[$nodeKey]['actions'][$card]['reward'] += $reward;
    }
}

function spades_bot_expert_ismcts_scores(array $observation, array $worlds, array $legal, ?int $deadlineNs = null): array
{
    $startedNs = hrtime(true);
    $ensembleCount = 3;
    $iterationsPerEnsemble = count($observation['ownHand']) <= 7 ? 448 : 320;
    $rootKey = spades_bot_ismcts_node_key((int)$observation['botUserId'], []);
    $summary = [];
    foreach ($legal as $card) $summary[(string)$card] = ['visits' => 0, 'availability' => 0, 'means' => [], 'winnerVotes' => 0];
    $ensembleWinners = [];
    $completedEnsembles = 0;
    $completedIterations = 0;
    $budgetExhausted = spades_bot_deadline_reached($deadlineNs);

    for ($ensemble = 0; $ensemble < $ensembleCount && $worlds !== []; $ensemble++) {
        if (spades_bot_deadline_reached($deadlineNs)) {
            $budgetExhausted = true;
            break;
        }
        $tree = [];
        $ensembleIterations = 0;
        for ($iteration = 0; $iteration < $iterationsPerEnsemble; $iteration++) {
            if (spades_bot_deadline_reached($deadlineNs)) {
                $budgetExhausted = true;
                break;
            }
            $worldIndex = ($iteration * $ensembleCount + $ensemble) % count($worlds);
            spades_bot_ismcts_iteration($observation, $worlds[$worldIndex], $tree, $ensemble, $iteration);
            $ensembleIterations++;
            $completedIterations++;
        }
        if ($ensembleIterations === 0) break;
        $completedEnsembles++;
        $ensembleWinner = null;
        $ensembleWinnerVisits = -1;
        $ensembleWinnerMean = -INF;
        foreach ($legal as $card) {
            $card = (string)$card;
            $edge = (array)($tree[$rootKey]['actions'][$card] ?? []);
            $visits = (int)($edge['visits'] ?? 0);
            $availability = (int)($edge['availability'] ?? 0);
            $edgeMean = $visits > 0 ? (float)($edge['reward'] ?? 0.0) / $visits : -1.0;
            $summary[$card]['visits'] += $visits;
            $summary[$card]['availability'] += $availability;
            $summary[$card]['means'][] = $edgeMean;
            if ($ensembleWinner === null || $visits > $ensembleWinnerVisits
                || ($visits === $ensembleWinnerVisits && $edgeMean > $ensembleWinnerMean)
                || ($visits === $ensembleWinnerVisits && $edgeMean === $ensembleWinnerMean && strcmp($card, $ensembleWinner) > 0)) {
                $ensembleWinner = $card;
                $ensembleWinnerVisits = $visits;
                $ensembleWinnerMean = $edgeMean;
            }
        }
        $ensembleWinners[] = $ensembleWinner;
        if (is_string($ensembleWinner) && isset($summary[$ensembleWinner])) $summary[$ensembleWinner]['winnerVotes']++;
        if ($budgetExhausted) break;
    }

    $scores = [];
    foreach ($summary as $card => $result) {
        $means = array_values(array_map('floatval', $result['means']));
        sort($means, SORT_NUMERIC);
        $mean = array_sum($means) / max(1, count($means));
        $lowerQuartile = $means[(int)floor((count($means) - 1) * 0.25)] ?? -1.0;
        $variance = 0.0;
        foreach ($means as $value) $variance += ($value - $mean) ** 2;
        $standardDeviation = sqrt($variance / max(1, count($means)));
        // Independent determinization ensembles are a guard against strategy
        // fusion. Prefer their consensus, then robust reward and visit support.
        $scores[$card] = (int)$result['winnerVotes'] * 10000.0
            + (int)$result['visits'] * 100.0
            + $mean * 1000.0
            + $lowerQuartile * 300.0
            - $standardDeviation * 200.0;
    }
    $visitedEdges = count(array_filter($summary, static fn(array $row): bool => (int)$row['visits'] > 0));
    $availableEdges = count(array_filter($summary, static fn(array $row): bool => (int)$row['availability'] > 0));
    $hasFullCoverage = $legal !== [] && $visitedEdges === count($legal);
    $GLOBALS['spades_bot_last_expert_diagnostics'] = [
        'worlds' => count($worlds),
        'legalCards' => count($legal),
        'configuredEnsembleCount' => $ensembleCount,
        'ensembleCount' => $completedEnsembles,
        'iterationsPerEnsemble' => $iterationsPerEnsemble,
        'iterationsCompleted' => $completedIterations,
        'rootVisits' => array_sum(array_column($summary, 'visits')),
        'rootAvailability' => array_sum(array_column($summary, 'availability')),
        'visitedRootEdges' => $visitedEdges,
        'availableRootEdges' => $availableEdges,
        'rootVisitCoverage' => count($legal) > 0 ? $visitedEdges / count($legal) : 1.0,
        'rootAvailabilityCoverage' => count($legal) > 0 ? $availableEdges / count($legal) : 1.0,
        'ensembleWinners' => $ensembleWinners,
        'ensembleDisagreement' => count(array_unique(array_filter($ensembleWinners, 'is_string'))) > 1,
        'elapsedMs' => round((hrtime(true) - $startedNs) / 1000000, 3),
        'budgetExhausted' => $budgetExhausted || spades_bot_deadline_reached($deadlineNs),
        'usedHeuristicFallback' => !$hasFullCoverage,
    ];
    return $hasFullCoverage ? $scores : [];
}

function spades_bot_expert_expected_adjustment(array $observation, string $card, bool $wins): float
{
    $unseen = spades_bot_unseen_cards($observation);
    $parts = spades_card_parts($card);
    $current = $observation['currentTrick'];
    $leadSuit = $current === [] ? $parts['suit'] : spades_card_parts((string)$current[0]['card'])['suit'];
    $playersAfter = max(0, 3 - count($current));
    $higher = 0;
    foreach ($unseen as $unknown) {
        $other = spades_card_parts((string)$unknown);
        if ($parts['suit'] === 'S') {
            if ($other['suit'] === 'S' && $other['rank'] > $parts['rank']) $higher++;
        } elseif (($other['suit'] === $parts['suit'] && $other['rank'] > $parts['rank']) || $other['suit'] === 'S') {
            $higher++;
        }
    }
    $survival = $playersAfter === 0 ? 1.0 : pow(max(0.0, 1.0 - ($higher / max(1, count($unseen)))), $playersAfter);
    $adjustment = $wins ? 12.0 * $survival : -4.0 * $survival;
    foreach ($observation['voidSuits'] as $userId => $voids) {
        if (in_array($leadSuit, $voids, true) && spades_team_index(['turnOrder' => $observation['turnOrder']], (int)$userId) !== spades_team_index(['turnOrder' => $observation['turnOrder']], (int)$observation['botUserId'])) {
            $adjustment -= $parts['suit'] !== 'S' ? 2.5 : 0.0;
        }
    }
    return $adjustment;
}

function spades_bot_expert_last_seat_card(array $observation, array $legal): ?string
{
    $current = array_values((array)$observation['currentTrick']);
    if (count($current) !== 3 || count($legal) < 2) return null;
    $botUserId = (int)$observation['botUserId'];
    $publicState = ['turnOrder' => $observation['turnOrder']];
    $team = spades_team_index($publicState, $botUserId);
    $currentWinner = (int)spades_bot_trick_winner($current);
    $currentWinnerTeam = spades_team_index($publicState, $currentWinner);
    $ownBid = (array)($observation['bids'][(string)$botUserId] ?? []);
    $ownNil = spades_bot_nil_live($observation, $botUserId);
    $winnerBid = (array)($observation['bids'][(string)$currentWinner] ?? []);
    $winnerNil = spades_bot_nil_live($observation, $currentWinner);
    $winning = [];
    $losing = [];
    foreach ($legal as $card) {
        $card = (string)$card;
        $winner = (int)spades_bot_trick_winner(array_merge($current, [['userId' => $botUserId, 'card' => $card]]));
        if ($winner === $botUserId) $winning[] = $card;
        else $losing[] = $card;
    }
    $candidates = null;
    if ($ownNil && $losing !== []) {
        $candidates = $losing;
    } elseif ($winnerNil && $currentWinnerTeam === $team && $winning !== []) {
        $candidates = $winning;
    } elseif ($winnerNil && $currentWinnerTeam !== $team && $losing !== []) {
        $candidates = $losing;
    } else {
        $teamBid = (int)($observation['teamBids'][(string)$team]['amount'] ?? 0);
        $teamTricks = spades_bot_team_contract_tricks($observation, $team);
        if ($currentWinnerTeam !== $team && $teamTricks < $teamBid && $winning !== []) {
            $candidates = $winning;
        } elseif ($currentWinnerTeam === $team && $losing !== []) {
            $candidates = $losing;
        } elseif ($currentWinnerTeam !== $team && $teamTricks >= $teamBid
            && (int)($observation['teamBags'][(string)$team] ?? 0) >= 7 && $losing !== []) {
            $candidates = $losing;
        }
    }
    if ($candidates === null) return null;
    $bestCard = null;
    $bestScore = -INF;
    $bestTie = PHP_INT_MIN;
    foreach ($candidates as $card) {
        $score = spades_bot_play_score($publicState, $observation, $card);
        $tie = spades_bot_stable_number($observation, 'expert-tactical:' . $card);
        if ($bestCard === null || $score > $bestScore || ($score === $bestScore && $tie > $bestTie)) {
            $bestCard = $card;
            $bestScore = $score;
            $bestTie = $tie;
        }
    }
    return $bestCard;
}

function spades_bot_play_score(array $state, array $observation, string $card): float
{
    $botUserId = (int)$observation['botUserId'];
    $team = spades_team_index($state, $botUserId);
    $partnerIds = array_values(array_filter(spades_team_users($state, $team), static fn(int $id): bool => $id !== $botUserId));
    $partnerId = (int)($partnerIds[0] ?? 0);
    $trick = array_merge($observation['currentTrick'], [['userId' => $botUserId, 'card' => $card]]);
    $winner = spades_bot_trick_winner($trick);
    $wins = $winner === $botUserId;
    $currentWinner = spades_bot_trick_winner($observation['currentTrick']);
    $partnerWinning = $currentWinner === $partnerId;
    $rank = spades_bot_card_value($card);
    $parts = spades_card_parts($card);
    $teamBid = (int)($observation['teamBids'][(string)$team]['amount'] ?? 0);
    $teamTricks = spades_bot_team_contract_tricks($observation, $team);
    $need = max(0, $teamBid - $teamTricks);
    $ownBid = (array)($observation['bids'][(string)$botUserId] ?? []);
    $partnerBid = (array)($observation['bids'][(string)$partnerId] ?? []);
    $ownNil = spades_bot_nil_live($observation, $botUserId);
    $partnerNil = spades_bot_nil_live($observation, $partnerId);
    $playersAfter = max(0, 3 - count($observation['currentTrick']));
    $score = -$rank * 0.45 - ($parts['suit'] === 'S' ? 2.0 : 0.0);
    if ($ownNil) $score += $wins ? -160.0 : 38.0 + $rank;
    elseif ($need > 0) $score += $wins ? 42.0 : -8.0;
    else $score += $wins ? -10.0 : 18.0;
    if ($partnerWinning && !$partnerNil) $score += $wins ? -20.0 : 24.0;
    if ($partnerNil && $currentWinner === $partnerId) $score += $wins ? 90.0 : -65.0;
    foreach ($observation['bids'] as $userId => $bid) {
        $opponentId = (int)$userId;
        if (spades_team_index($state, $opponentId) === $team) continue;
        if (!spades_bot_nil_live($observation, $opponentId)) continue;
        if (!$ownNil && $currentWinner === $opponentId) {
            $score += $wins ? -78.0 + $playersAfter * 10.0 : 45.0;
        }
    }
    if ($observation['currentTrick'] === []) {
        $groups = spades_bot_suit_groups($observation['ownHand']);
        $score += (5 - count($groups[$parts['suit']])) * 1.8;
        if ($need > max(1, count($observation['ownHand']) / 3) && $rank >= 12) $score += 16.0;
    }
    $bags = (int)($observation['teamBags'][(string)$team] ?? 0);
    if (!$ownNil && $need === 0 && $wins) {
        if ($bags >= 8) $score -= 34.0;
        elseif ($bags >= 6) $score -= 18.0;
        elseif ($bags >= 4) $score -= 8.0;
    }
    $remainingTricks = count($observation['ownHand']);
    if (!$ownNil && $need >= max(1, $remainingTricks - 1) && !$wins) $score -= 28.0;
    if ($observation['difficulty'] === 'expert') {
        $score += spades_bot_expert_expected_adjustment($observation, $card, $wins);
        if ($need > $remainingTricks && !$wins) $score -= 35.0;
    } else {
        $score += (spades_bot_stable_number($observation, 'play:' . $card) % 13 - 6) * 0.65;
    }
    return $score;
}

function spades_bot_choose_card(array $state, int $botUserId, array $observation, ?int $chainDeadlineNs = null): string
{
    $started = hrtime(true);
    $legal = spades_bot_legal_cards_from_observation($observation);
    if ($legal === []) throw new MultiplayerGameException('The Practice bot has no legal card.', 'SPADES_BOT_NO_LEGAL_CARD', 500);
    $tactic = count($legal) === 1 ? ['card'=>$legal[0],'reason'=>'only-legal-card']
        : spades_bot_partnership_tactic($observation, $legal);
    if ($observation['difficulty'] === 'normal' && ($tactic['reason'] ?? '') === 'proactive-partner-nil-public-cover') {
        $tactic = spades_bot_normal_nil_lookahead($observation,$legal,$tactic);
    }
    if ($tactic !== null) {
        if ($observation['difficulty'] === 'expert') {
            $GLOBALS['spades_bot_last_expert_diagnostics'] = [
                'worlds'=>0,'legalCards'=>count($legal),'forcedMove'=>count($legal)===1,
                'tacticalMove'=>true,'tacticalReason'=>$tactic['reason'],
                'rootVisitCoverage'=>1.0,'rootAvailabilityCoverage'=>1.0,
                'decisionBudgetMs'=>SPADES_BOT_EXPERT_DECISION_BUDGET_MS,
                'decisionElapsedMs'=>round((hrtime(true)-$started)/1000000,3),
                'budgetExhausted'=>false,'usedHeuristicFallback'=>false,
            ];
        }
        $card = $tactic['card'];
    } else {
        $card = spades_bot_choose_card_search($state,$botUserId,$observation,$chainDeadlineNs);
    }
    if ($observation['difficulty'] === 'normal' && count($legal) > 1) {
        $endgame = spades_bot_normal_endgame_nil_attack($observation, $legal, $card);
        if ($endgame !== null) { $card = $endgame['card']; $tactic = $endgame; }
    }
    // Keep one bounded trace; the recorder copies allowed fields to a private archive.
    $GLOBALS['spades_bot_last_decision_trace'] = [
        'actor'=>$botUserId,'difficulty'=>$observation['difficulty'],'legal'=>$legal,
        'selected'=>$card,'reason'=>$tactic['reason']??($observation['difficulty']==='expert'?'bounded-search':'normal-evaluation'),
        'candidateScores'=>$tactic['candidateScores']??[],
        'forecast'=>$tactic===null?null:array_intersect_key($tactic,array_flip(['forecastFailures','worlds','fallback'])),
        'publicObservation'=>$observation,
        'elapsedMs'=>round((hrtime(true)-$started)/1000000,3),
    ];
    return $card;
}

function spades_bot_choose_card_search(array $state, int $botUserId, array $observation, ?int $chainDeadlineNs = null): string
{
    unset($state);
    $decisionStartedNs = hrtime(true);
    $decisionDeadlineNs = $decisionStartedNs + SPADES_BOT_EXPERT_DECISION_BUDGET_MS * 1000000;
    $searchDeadlineNs = $chainDeadlineNs === null ? $decisionDeadlineNs : min($chainDeadlineNs, $decisionDeadlineNs);
    $legal = spades_bot_legal_cards_from_observation($observation);
    if ($legal === []) throw new MultiplayerGameException('The Practice bot has no legal card.', 'SPADES_BOT_NO_LEGAL_CARD', 500);
    if (count($legal) === 1) {
        if ($observation['difficulty'] === 'expert') {
            $GLOBALS['spades_bot_last_expert_diagnostics'] = [
                'worlds' => 0, 'legalCards' => 1, 'ensembleCount' => 0, 'iterationsPerEnsemble' => 0,
                'rootVisits' => 0, 'rootAvailability' => 0, 'visitedRootEdges' => 1, 'availableRootEdges' => 1,
                'rootVisitCoverage' => 1.0, 'rootAvailabilityCoverage' => 1.0,
                'ensembleWinners' => [(string)$legal[0]], 'ensembleDisagreement' => false, 'forcedMove' => true,
                'decisionBudgetMs' => SPADES_BOT_EXPERT_DECISION_BUDGET_MS,
                'decisionElapsedMs' => round((hrtime(true) - $decisionStartedNs) / 1000000, 3),
                'budgetExhausted' => false, 'usedHeuristicFallback' => false,
            ];
        }
        return (string)$legal[0];
    }
    if ($observation['difficulty'] === 'expert') {
        $tactical = spades_bot_expert_last_seat_card($observation, $legal);
        if (is_string($tactical)) {
            $GLOBALS['spades_bot_last_expert_diagnostics'] = [
                'worlds' => 0, 'legalCards' => count($legal), 'ensembleCount' => 0, 'iterationsPerEnsemble' => 0,
                'rootVisits' => 0, 'rootAvailability' => 0, 'visitedRootEdges' => count($legal), 'availableRootEdges' => count($legal),
                'rootVisitCoverage' => 1.0, 'rootAvailabilityCoverage' => 1.0,
                'ensembleWinners' => [$tactical], 'ensembleDisagreement' => false,
                'forcedMove' => true, 'tacticalMove' => true,
                'decisionBudgetMs' => SPADES_BOT_EXPERT_DECISION_BUDGET_MS,
                'decisionElapsedMs' => round((hrtime(true) - $decisionStartedNs) / 1000000, 3),
                'budgetExhausted' => false, 'usedHeuristicFallback' => false,
            ];
            return $tactical;
        }
    }
    $publicState = ['turnOrder' => $observation['turnOrder']];
    $worlds = $observation['difficulty'] === 'expert' ? spades_bot_sample_worlds($observation, $searchDeadlineNs) : [];
    $searchScores = $observation['difficulty'] === 'expert' ? spades_bot_expert_ismcts_scores($observation, $worlds, $legal, $searchDeadlineNs) : [];
    $bestCard = null;
    $bestScore = -INF;
    $bestTie = PHP_INT_MIN;
    foreach ($legal as $card) {
        $heuristic = spades_bot_play_score($publicState, $observation, (string)$card);
        $score = $observation['difficulty'] === 'expert'
            ? (float)($searchScores[(string)$card] ?? -1000000.0) + $heuristic * 25.0
            : $heuristic;
        $tie = spades_bot_stable_number($observation, 'tie:' . $card);
        if ($bestCard === null || $score > $bestScore || ($score === $bestScore && $tie > $bestTie)) {
            $bestCard = (string)$card;
            $bestScore = $score;
            $bestTie = $tie;
        }
    }
    if ($observation['difficulty'] === 'expert') {
        $GLOBALS['spades_bot_last_expert_diagnostics']['decisionBudgetMs'] = SPADES_BOT_EXPERT_DECISION_BUDGET_MS;
        $GLOBALS['spades_bot_last_expert_diagnostics']['decisionElapsedMs'] = round((hrtime(true) - $decisionStartedNs) / 1000000, 3);
        $GLOBALS['spades_bot_last_expert_diagnostics']['usedHeuristicFallback'] = $searchScores === [];
    }
    return (string)$bestCard;
}

function spades_bot_pass_cards(array $observation, bool $returning, int $cardCount = 2): array
{
    $cards = $observation['ownHand'];
    $groups = spades_bot_suit_groups($cards);
    $cardCount = in_array($cardCount, [1, 2], true) ? $cardCount : 2;
    $candidates = [];
    $choices = [];
    if ($cardCount === 1) {
        foreach ($cards as $card) $choices[] = [(string)$card];
    } else {
        for ($left = 0; $left < count($cards); $left++) for ($right = $left + 1; $right < count($cards); $right++) {
            $choices[] = [(string)$cards[$left], (string)$cards[$right]];
        }
    }
    foreach ($choices as $choice) {
        $danger = 0.0;
        $removedBySuit = [];
        foreach ($choice as $card) {
            $parts = spades_card_parts((string)$card);
            $danger += spades_bot_nil_card_danger((string)$card);
            $removedBySuit[$parts['suit']] = (int)($removedBySuit[$parts['suit']] ?? 0) + 1;
        }
        $voidBonus = 0.0;
        foreach ($removedBySuit as $suit => $removed) if (count($groups[$suit]) === $removed) $voidBonus += $suit === 'S' ? -4.0 : 8.0;
        // Reducer offer: partner -> Nil bidder; response: Nil bidder -> partner.
        $remaining = array_values(array_diff($cards, $choice));
        $cover = 0.0;
        $retained = spades_bot_suit_groups($remaining);
        foreach ($choice as $card) {
            $parts = spades_card_parts($card);
            $high = $retained[$parts['suit']][0] ?? 0;
            $cover += max(0, $high - max(9, $parts['rank']));
        }
        // Held-out games did not justify changing the established exchange
        // policy. Retain whole-hand diagnostics for reproducible future review.
        $score = $returning ? $danger + $voidBonus : -$danger;
        $candidates[] = ['cards'=>$choice,'score'=>$score,'remainingExposure'=>spades_bot_nil_hand_exposure($remaining),
            'retainedCover'=>$cover,'tie'=>spades_bot_stable_number($observation,'pass:'.implode(',',$choice))];
    }
    usort($candidates, static fn(array $left, array $right): int => [$right['score'], $right['tie']] <=> [$left['score'], $left['tie']]);
    $GLOBALS['spades_bot_last_pass_trace'] = ['returning'=>$returning,'cardCount'=>$cardCount,
        'selected'=>$candidates[0]['cards']??[], 'candidates'=>$candidates,
        'reason'=>$returning?'danger-and-void-return':'lowest-danger-offer','wholeHandPolicyPromoted'=>false];
    return $candidates[0]['cards'] ?? array_slice($cards, 0, $cardCount);
}

function spades_bot_choose_action(array $state, int $botUserId, ?int $chainDeadlineNs = null): array
{
    $observation = spades_bot_public_observation($state, $botUserId);
    $phase = (string)($state['phase'] ?? '');
    if ($phase === 'bidding') {
        if (empty($state['handViewed'][(string)$botUserId])) {
            if (spades_bot_should_blind_nil($state, $botUserId, $observation)) {
                return ['action' => 'bid', 'payload' => ['kind' => 'blind-nil', 'amount' => 0]];
            }
            return ['action' => 'view-hand', 'payload' => []];
        }
        return ['action' => 'bid', 'payload' => spades_bot_bid($state, $botUserId, $observation)];
    }
    if ($phase === 'partner-pass') {
        foreach ((array)($state['partnerPasses'] ?? []) as $pass) {
            $cardCount = in_array((int)($pass['cardCount'] ?? 2), [1, 2], true) ? (int)($pass['cardCount'] ?? 2) : 2;
            if ((int)($pass['fromUserId'] ?? 0) === $botUserId && (string)($pass['status'] ?? '') === 'awaiting-offer') {
                return ['action' => 'offer-partner-pass', 'payload' => ['cards' => spades_bot_pass_cards($observation, false, $cardCount)]];
            }
            if ((int)($pass['toUserId'] ?? 0) === $botUserId && (string)($pass['status'] ?? '') === 'awaiting-response') {
                return ['action' => 'respond-partner-pass', 'payload' => ['accept' => true, 'returnCards' => spades_bot_pass_cards($observation, true, $cardCount)]];
            }
        }
    }
    if ($phase === 'playing') {
        return ['action' => 'play', 'payload' => ['card' => spades_bot_choose_card($state, $botUserId, $observation, $chainDeadlineNs)]];
    }
    throw new MultiplayerGameException('The Practice bot cannot resolve this Spades phase.', 'SPADES_BOT_PHASE_INVALID', 500);
}

function spades_bot_advance(array $result, array $context): array
{
    $deadlineNs = hrtime(true) + SPADES_BOT_EXPERT_CHAIN_BUDGET_MS * 1000000;
    for ($step = 0; $step < 256; $step++) {
        $state = (array)($result['state'] ?? []);
        if (!empty($result['terminal']) || !empty($state['completed'])) {
            $result['turnUserId'] = null;
            return $result;
        }
        $turnOrder = array_values(array_map('intval', (array)($state['turnOrder'] ?? [])));
        $turnIndex = (int)($state['turnIndex'] ?? -1);
        $logicalUserId = (int)($turnOrder[$turnIndex] ?? 0);
        if (!spades_bot_is_player($state, $logicalUserId)) {
            $result['turnUserId'] = $logicalUserId > 0 ? $logicalUserId : null;
            return $result;
        }
        $phase = (string)($state['phase'] ?? '');
        if (in_array($phase, ['deal', 'settling'], true)) {
            $result['turnUserId'] = spades_bot_first_human($state);
            return $result;
        }
        if (!empty($context['deferBotActions'])) { $result['turnUserId'] = $logicalUserId; return $result; }
        $result = spades_bot_step($state, $logicalUserId, $context, $deadlineNs);
    }
    throw new MultiplayerGameException('The Practice bots exceeded the bounded decision limit.', 'SPADES_BOT_DECISION_LIMIT', 500);
}

function spades_apply_action(array $state, int $actorUserId, string $action, array $payload, array $context): array
{
    if ($action === 'bot-step') {
        $bot = paced_bot_validate($state, $actorUserId, $payload, $context, 'spades-paced-1');
        if (in_array($state['phase'] ?? '', ['deal','settling'], true))
            throw new MultiplayerGameException('Wait for the current trick.', 'SPADES_BOT_PHASE_INVALID', 409);
        $result = spades_bot_step($state, $bot, $context, hrtime(true) + SPADES_BOT_EXPERT_CHAIN_BUDGET_MS * 1000000);
        return spades_bot_advance($result, array_replace($context, ['deferBotActions'=>true]));
    }
    $coreActor = $actorUserId;
    $turnOrder = array_values(array_map('intval', (array)($state['turnOrder'] ?? [])));
    $logicalUserId = (int)($turnOrder[(int)($state['turnIndex'] ?? -1)] ?? 0);
    if (in_array($action, ['deal', 'settle-trick'], true)
        && spades_bot_is_player($state, $logicalUserId)
        && $actorUserId > 0
        && in_array($actorUserId, $turnOrder, true)) {
        $coreActor = $logicalUserId;
    }
    $result = spades_apply_action_core($state, $coreActor, $action, $payload, $context);
    if (isset($context['recordingCollector'])) {
        game_recording_observe($context, 'spades', $state, $coreActor, $action, $payload, $result['state']);
    }
    return spades_bot_advance($result, $context);
}

function spades_bot_step(array $state, int $logicalUserId, array $context, int $deadlineNs): array
{
        unset($GLOBALS['spades_bot_last_decision_trace'], $GLOBALS['spades_bot_last_bid_trace'], $GLOBALS['spades_bot_last_pass_trace']);
        $decisionStarted = hrtime(true);
        $decision = spades_bot_choose_action($state, $logicalUserId, $deadlineNs);
        $result = spades_apply_action_core(
            $state,
            $logicalUserId,
            (string)$decision['action'],
            (array)$decision['payload'],
            $context
        );
        if (isset($context['recordingCollector'])) {
            $trace = $GLOBALS['spades_bot_last_decision_trace'] ?? $GLOBALS['spades_bot_last_bid_trace'] ?? $GLOBALS['spades_bot_last_pass_trace'] ?? [];
            $trace['difficulty'] = spades_bot_public_observation($state, $logicalUserId)['difficulty'];
            $trace['selected'] = $decision;
            $trace['elapsedMs'] = (hrtime(true) - $decisionStarted) / 1000000;
            $trace['reason'] ??= 'phase-policy';
            game_recording_observe($context, 'spades', $state, $logicalUserId, (string)$decision['action'], (array)$decision['payload'], $result['state'], $trace);
        }
    return $result;
}
