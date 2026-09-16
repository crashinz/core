<?php
declare(strict_types=1);

require_once __DIR__ . '/ocx_game_extension_support.php';
require_once __DIR__ . '/blackjack_bot_support.php';

const BLACKJACK_EXTENSION_ID = 'blackjack';
const BLACKJACK_STATE_SCHEMA_VERSION = 1;
const BLACKJACK_MINIMUM_BET = 10;
const BLACKJACK_MAXIMUM_BET = 500;

function blackjack_extension_adapter(): array
{
    return [
        'id' => BLACKJACK_EXTENSION_ID,
        'recordingAdapter' => 'blackjack_recording_adapter',
        'projectVirtualMembers' => 'blackjack_project_virtual_members',
        'initialState' => 'blackjack_initial_state',
        'applyAction' => 'blackjack_apply_action',
        'validateSettings' => 'blackjack_validate_settings',
        'settingsProjection' => 'blackjack_settings_projection',
        'rulesProjection' => 'blackjack_rules_projection',
        'projectState' => 'blackjack_project_state',
        'randomnessPurposes' => ['deal' => 'blackjack-shoe', 'bot-deal' => 'blackjack-shoe'],
        'deriveRandomness' => 'blackjack_derive_randomness',
        'openingProcedure' => 'viewer-centered-table-with-clockwise-player-order',
        'rematchSeatRotation' => false,
    ];
}

function blackjack_validate_settings(array $settings, string $mode, array $definition = []): array
{
    $allowed = ['startingChips', 'rounds'];
    for ($seat=1;$seat<=5;$seat++) $allowed[]='botSeat'.$seat.'Difficulty';
    if (array_diff(array_keys($settings), $allowed)) {
        throw new MultiplayerGameException('A Blackjack setting is not supported.', 'BLACKJACK_SETTINGS_INVALID', 422);
    }
    $startingChips = (int)($settings['startingChips'] ?? 1000);
    if ($startingChips < 250 || $startingChips > 10000 || $startingChips % 250 !== 0) {
        throw new MultiplayerGameException('Starting chips must be from 250 to 10000 in steps of 250.', 'BLACKJACK_STARTING_CHIPS_INVALID', 422);
    }
    $rounds = (int)($settings['rounds'] ?? 10);
    if (!in_array($rounds, [5, 10, 20], true)) {
        throw new MultiplayerGameException('Choose 5, 10, or 20 rounds.', 'BLACKJACK_ROUNDS_INVALID', 422);
    }
    $out=['startingChips'=>$startingChips,'rounds'=>$rounds];
    for($seat=1;$seat<=5;$seat++) {
        $key='botSeat'.$seat.'Difficulty';$level=$settings[$key]??'none';
        if(!is_string($level)||!in_array($level,['none','easy','normal','expert'],true))throw new MultiplayerGameException('Choose a listed bot difficulty.','BLACKJACK_SETTINGS_INVALID',422);
        if($mode!=='practice'&&$level!=='none')throw new MultiplayerGameException('Games with bots are Practice only.','MULTIPLAYER_GAME_BOTS_PRACTICE_ONLY',422);
        if($mode==='practice')$out[$key]=$level;
    }
    return $out;
}

function blackjack_settings_projection(array $settings, string $mode, array $definition = []): array
{
    $settings = blackjack_validate_settings($settings, $mode, $definition);
    return [
        'label' => 'Game Options',
        'description' => 'Every player accepts the shared starting bankroll and round count before the first deal. Chips are session-only play currency.',
        'classificationLabel' => 'Accepted Blackjack options',
        'controls' => [
            [
                'key' => 'startingChips', 'type' => 'stepper', 'value' => $settings['startingChips'], 'defaultValue' => 1000,
                'label' => 'Starting chips', 'description' => 'Use the minus and plus buttons to remove or add 250 chips. Every player starts with the same amount.',
                'minimum' => 250, 'maximum' => 10000, 'step' => 250,
                'shortcuts' => [['value' => 500, 'label' => '500'], ['value' => 1000, 'label' => '1,000'], ['value' => 2500, 'label' => '2,500']],
            ],
            [
                'key' => 'rounds', 'type' => 'select', 'value' => $settings['rounds'], 'defaultValue' => 10,
                'label' => 'Rounds', 'description' => 'The player with the most chips after the accepted number of rounds wins.',
                'options' => [['value' => 5, 'label' => '5'], ['value' => 10, 'label' => '10'], ['value' => 20, 'label' => '20']],
            ],
        ],
    ];
}

function blackjack_rules_projection(array $settings, string $mode, array $definition = []): array
{
    $settings = blackjack_validate_settings($settings, $mode, $definition);
    return [
        'label' => 'Blackjack rules',
        'description' => 'Two to five players play against one automated dealer. Blackjack pays 3 to 2. The dealer draws to 16 and stands on every 17.',
        'sections' => [
            ['label' => 'Six-deck shoe', 'text' => 'The dealer uses six standard decks: 312 uniquely tracked cards in one server-shuffled shoe. The shoe continues across rounds and is replaced with a newly verified six-deck shuffle when fewer than 80 cards remain. Bets are 10 through 500 chips in steps of 10 and can never exceed the player bankroll. Every player starts with ' . number_format($settings['startingChips']) . ' session-only chips.'],
            ['label' => 'Player choices', 'text' => 'Hit or stand. Double on any first two cards, including after a split. Split equal ranks up to four hands. Split aces receive one card each and cannot be resplit. A split 21 is not a natural Blackjack. Late surrender returns half the wager.'],
            ['label' => 'Dealer and payouts', 'text' => 'The dealer checks for Blackjack with an Ace or ten-value up card. Insurance is offered against an Ace for half the wager and pays 2 to 1 profit when the dealer has Blackjack. A natural Blackjack pays 3 to 2; ordinary wins pay 1 to 1; ties push.'],
            ['label' => 'Winner', 'text' => 'After ' . $settings['rounds'] . ' rounds, the highest bankroll wins. Equal highest bankrolls draw. Players below the 10-chip minimum sit out remaining deals.'],
            ['label' => 'Mode', 'text' => $mode === 'recorded' ? 'Recorded play uses server-authoritative randomness and updates Blackjack-only records.' : 'Practice play uses committed browser randomness and never updates Recorded records.'],
        ],
    ];
}

function blackjack_shoe(): array
{
    $cards = [];
    for ($deck = 0; $deck < 6; $deck++) {
        foreach (['C', 'D', 'H', 'S'] as $suit) {
            foreach (range(2, 14) as $rank) $cards[] = $suit . $rank . ':' . $deck;
        }
    }
    return $cards;
}

function blackjack_derive_randomness(string $canonicalReveal, string $actionType, array $payload, array $context): array
{
    return ['shoe' => ocx_game_random_permutation($canonicalReveal, blackjack_shoe(), 'blackjack-shoe')];
}

function blackjack_card_parts(string $card): array
{
    if (!preg_match('/^([CDHS])(\d{1,2}):([0-5])$/', $card, $matches)) {
        throw new MultiplayerGameException('The Blackjack shoe contains an invalid card.', 'BLACKJACK_CARD_INVALID', 500);
    }
    $rank = (int)$matches[2];
    if ($rank < 2 || $rank > 14) throw new MultiplayerGameException('The Blackjack shoe contains an invalid rank.', 'BLACKJACK_CARD_INVALID', 500);
    return ['suit' => $matches[1], 'rank' => $rank, 'public' => $matches[1] . $rank];
}

function blackjack_hand_value(array $cards): array
{
    $total = 0;
    $aces = 0;
    foreach ($cards as $card) {
        $rank = blackjack_card_parts((string)$card)['rank'];
        if ($rank === 14) { $total += 11; $aces++; }
        else $total += min(10, $rank);
    }
    while ($total > 21 && $aces > 0) { $total -= 10; $aces--; }
    return ['total' => $total, 'soft' => $aces > 0];
}

function blackjack_is_natural(array $hand): bool
{
    return empty($hand['split']) && count((array)($hand['cards'] ?? [])) === 2 && blackjack_hand_value((array)$hand['cards'])['total'] === 21;
}

function blackjack_new_hand(int $bet, array $cards = [], bool $split = false): array
{
    return [
        'cards' => array_values($cards), 'bet' => $bet, 'status' => 'active', 'split' => $split,
        'splitAces' => false, 'doubled' => false, 'surrendered' => false,
        'result' => null, 'returned' => 0,
    ];
}

function blackjack_initial_state(array $playerUserIds, array $context = []): array
{
    $players = array_values(array_unique(array_map('intval', $playerUserIds)));
    if (count($players) < 1 || count($players) > 5 || min($players) < 1) {
        throw new MultiplayerGameException('Blackjack requires two to five authenticated players.', 'BLACKJACK_PLAYER_SET_INVALID', 422);
    }
    $settings = blackjack_validate_settings((array)($context['settings'] ?? []), (string)($context['mode'] ?? 'practice'));
    [$players,$bots]=blackjack_bot_fill_seats($players,$settings,(string)($context['mode']??'practice'),(array)($context['humanSeats']??[]));
    if(count($players)<2||count($players)>5)throw new MultiplayerGameException('Blackjack needs two through five players or Practice bots.','BLACKJACK_PLAYER_SET_INVALID',422);
    $bankrolls = [];
    $hands = [];
    foreach ($players as $userId) {
        $bankrolls[(string)$userId] = $settings['startingChips'];
        $hands[(string)$userId] = [];
    }
    return [
        'schemaVersion' => BLACKJACK_STATE_SCHEMA_VERSION,
        'bots'=>$bots, 'botSequence'=>0,
        'publicCardMemory'=>['seen'=>array_fill(2,13,0),'complete'=>true,'wagers'=>[],'lastWagers'=>[]],
        'settings' => $settings,
        'turnOrder' => $players,
        'turnIndex' => 0,
        'phase' => 'betting',
        'round' => 1,
        'bankrolls' => $bankrolls,
        'hands' => $hands,
        'dealer' => ['cards' => [], 'revealed' => false],
        'shoe' => [],
        'shoeCount' => 0,
        'currentHandIndex' => 0,
        'insuranceDecisions' => [],
        'insuranceBets' => array_fill_keys(array_map('strval', $players), 0),
        'completed' => false,
        'terminalReason' => null,
        'lastSettlement' => [],
    ];
}

function blackjack_draw_card(array &$state): string
{
    if (count((array)$state['shoe']) < 1) throw new MultiplayerGameException('The verified shoe has no remaining card.', 'BLACKJACK_SHOE_EMPTY', 500);
    $card = (string)array_shift($state['shoe']);
    blackjack_card_parts($card);
    $state['shoeCount'] = count($state['shoe']);
    return $card;
}

function blackjack_begin_round(array &$state, array $verifiedShoe): void
{
    if (count((array)$state['shoe']) < 80) {
        if (count($verifiedShoe) !== 312 || count(array_unique($verifiedShoe)) !== 312) {
            throw new MultiplayerGameException('The verified six-deck shoe is invalid.', 'BLACKJACK_SHOE_INVALID', 500);
        }
        foreach ($verifiedShoe as $card) blackjack_card_parts((string)$card);
        $state['shoe'] = array_values(array_map('strval', $verifiedShoe));
    }
    $activeUserIds = [];
    foreach ($state['turnOrder'] as $userId) {
        if (!empty($state['hands'][(string)$userId])) $activeUserIds[] = (int)$userId;
    }
    if ($activeUserIds === []) throw new MultiplayerGameException('At least one wager is required before dealing.', 'BLACKJACK_DEAL_INVALID', 409);
    foreach ($activeUserIds as $userId) $state['hands'][(string)$userId][0]['cards'][] = blackjack_draw_card($state);
    $state['dealer']['cards'] = [blackjack_draw_card($state)];
    foreach ($activeUserIds as $userId) $state['hands'][(string)$userId][0]['cards'][] = blackjack_draw_card($state);
    $state['dealer']['cards'][] = blackjack_draw_card($state);
    $state['dealer']['revealed'] = false;
    foreach ($activeUserIds as $userId) {
        if (blackjack_is_natural($state['hands'][(string)$userId][0])) $state['hands'][(string)$userId][0]['status'] = 'blackjack';
    }
    $upRank = blackjack_card_parts((string)$state['dealer']['cards'][0])['rank'];
    if ($upRank === 14) {
        $state['phase'] = 'insurance';
        $state['insuranceDecisions'] = [];
        $state['turnIndex'] = (int)array_search($activeUserIds[0], array_map('intval', $state['turnOrder']), true);
        return;
    }
    if (blackjack_is_natural(['cards' => $state['dealer']['cards'], 'split' => false])) {
        blackjack_settle_round($state);
        return;
    }
    blackjack_activate_first_hand($state);
}

function blackjack_activate_first_hand(array &$state): ?int
{
    $state['phase'] = 'player-turns';
    return blackjack_activate_next_hand($state, 0, -1);
}

function blackjack_activate_next_hand(array &$state, int $fromPlayerIndex, int $fromHandIndex): ?int
{
    $players = array_values(array_map('intval', $state['turnOrder']));
    for ($playerIndex = max(0, $fromPlayerIndex); $playerIndex < count($players); $playerIndex++) {
        $userId = $players[$playerIndex];
        $startHand = $playerIndex === $fromPlayerIndex ? $fromHandIndex + 1 : 0;
        foreach ((array)($state['hands'][(string)$userId] ?? []) as $handIndex => $hand) {
            if ($handIndex < $startHand || (string)($hand['status'] ?? '') !== 'active') continue;
            $value = blackjack_hand_value((array)$hand['cards']);
            if ($value['total'] >= 21) {
                $state['hands'][(string)$userId][$handIndex]['status'] = $value['total'] === 21 ? 'stood' : 'bust';
                continue;
            }
            $state['turnIndex'] = $playerIndex;
            $state['currentHandIndex'] = (int)$handIndex;
            return $userId;
        }
    }
    $state['phase'] = 'dealer';
    $state['turnIndex'] = 0;
    $state['currentHandIndex'] = 0;
    return $players[0] ?? null;
}

function blackjack_next_insurance_player(array &$state): ?int
{
    foreach (array_values(array_map('intval', $state['turnOrder'])) as $index => $userId) {
        if (empty($state['hands'][(string)$userId]) || array_key_exists((string)$userId, $state['insuranceDecisions'])) continue;
        $state['turnIndex'] = $index;
        return $userId;
    }
    if (blackjack_is_natural(['cards' => $state['dealer']['cards'], 'split' => false])) {
        return blackjack_settle_round($state);
    }
    return blackjack_activate_first_hand($state);
}

function blackjack_terminal_result(array $state): array
{
    $scores = [];
    foreach ($state['turnOrder'] as $userId) $scores[(string)(int)$userId] = (int)($state['bankrolls'][(string)$userId] ?? 0);
    $result = ocx_game_result_from_scores($scores);
    if ($scores !== []) {
        $highest = max($scores);
        $leaders = array_keys(array_filter($scores, static fn(int $score): bool => $score === $highest));
        // Blackjack ties draw; other games retain their own shared-winner rules.
        if (count($leaders) > 1) {
            foreach ($leaders as $userId) $result['members'][(string)$userId]['outcome'] = 'draw';
        }
    }
    return $result;
}

function blackjack_settle_round(array &$state): ?int
{
    $state['dealer']['revealed'] = true;
    $dealerValue = blackjack_hand_value((array)$state['dealer']['cards']);
    $dealerNatural = blackjack_is_natural(['cards' => $state['dealer']['cards'], 'split' => false]);
    $settlement = [];
    foreach ($state['turnOrder'] as $userId) {
        $key = (string)(int)$userId;
        $insurance = (int)($state['insuranceBets'][$key] ?? 0);
        if ($insurance > 0 && $dealerNatural) $state['bankrolls'][$key] += $insurance * 3;
        foreach ((array)($state['hands'][$key] ?? []) as $handIndex => $hand) {
            $bet = (int)$hand['bet'];
            $value = blackjack_hand_value((array)$hand['cards']);
            $returned = 0;
            $result = 'loss';
            if (!empty($hand['surrendered'])) { $returned = intdiv($bet, 2); $result = 'surrender'; }
            elseif ($value['total'] > 21) { $result = 'bust'; }
            elseif (blackjack_is_natural($hand) && !$dealerNatural) { $returned = $bet + intdiv($bet * 3, 2); $result = 'blackjack'; }
            elseif ($dealerNatural && blackjack_is_natural($hand)) { $returned = $bet; $result = 'push'; }
            elseif ($dealerNatural) { $result = 'dealer-blackjack'; }
            elseif ($dealerValue['total'] > 21 || $value['total'] > $dealerValue['total']) { $returned = $bet * 2; $result = 'win'; }
            elseif ($value['total'] === $dealerValue['total']) { $returned = $bet; $result = 'push'; }
            $state['bankrolls'][$key] += $returned;
            $state['hands'][$key][$handIndex]['status'] = 'settled';
            $state['hands'][$key][$handIndex]['result'] = $result;
            $state['hands'][$key][$handIndex]['returned'] = $returned;
            $settlement[] = ['userId' => (int)$userId, 'handIndex' => (int)$handIndex, 'result' => $result, 'returned' => $returned];
        }
    }
    $state['lastSettlement'] = $settlement;
    $state['shoeCount'] = count((array)$state['shoe']);
    $hasEligiblePlayer = false;
    foreach ($state['bankrolls'] as $bankroll) if ((int)$bankroll >= BLACKJACK_MINIMUM_BET) $hasEligiblePlayer = true;
    if ((int)$state['round'] >= (int)$state['settings']['rounds'] || !$hasEligiblePlayer) {
        $state['completed'] = true;
        $state['phase'] = 'completed';
        $state['terminalReason'] = !$hasEligiblePlayer ? 'all-players-below-minimum-bet' : 'round-limit';
        return null;
    }
    $state['phase'] = 'round-complete';
    foreach ($state['turnOrder'] as $index => $userId) {
        if ((int)$state['bankrolls'][(string)$userId] >= BLACKJACK_MINIMUM_BET) {
            $state['turnIndex'] = $index;
            return (int)$userId;
        }
    }
    return null;
}

function blackjack_public_card(string $card): string
{
    return blackjack_card_parts($card)['public'];
}

function blackjack_project_state_core(array $state, int $viewerUserId, array $context): array
{
    $projection = $state;
    $projection['shoeCount'] = count((array)($state['shoe'] ?? []));
    unset($projection['shoe']);
    foreach ((array)($state['hands'] ?? []) as $userId => $hands) {
        foreach ((array)$hands as $handIndex => $hand) {
            $cards = array_values(array_map('blackjack_public_card', (array)($hand['cards'] ?? [])));
            $projection['hands'][$userId][$handIndex]['cards'] = $cards;
            $projection['hands'][$userId][$handIndex]['value'] = blackjack_hand_value((array)$hand['cards']);
        }
    }
    $dealerCards = (array)($state['dealer']['cards'] ?? []);
    $revealDealer = !empty($state['dealer']['revealed']) || in_array((string)($state['phase'] ?? ''), ['round-complete', 'completed'], true);
    $projection['dealer']['cards'] = [];
    foreach ($dealerCards as $index => $card) {
        $projection['dealer']['cards'][] = $index === 1 && !$revealDealer ? ['hidden' => true] : blackjack_public_card((string)$card);
    }
    $projection['dealer']['value'] = $revealDealer ? blackjack_hand_value($dealerCards) : null;
    $projection['viewerHandIndex'] = (int)($state['currentHandIndex'] ?? 0);
    $projection['legalActions'] = [];
    $currentUserId = (int)($state['turnOrder'][(int)($state['turnIndex'] ?? 0)] ?? 0);
    if ($currentUserId === $viewerUserId && empty($state['completed'])) {
        $phase = (string)($state['phase'] ?? '');
        if ($phase === 'betting') $projection['legalActions'] = ['bet'];
        elseif ($phase === 'insurance') $projection['legalActions'] = ['insurance'];
        elseif ($phase === 'deal') $projection['legalActions'] = ['deal'];
        elseif ($phase === 'dealer') $projection['legalActions'] = ['dealer-play'];
        elseif ($phase === 'round-complete') $projection['legalActions'] = ['next-round'];
        elseif ($phase === 'player-turns') {
            $hand = (array)($state['hands'][(string)$viewerUserId][(int)$state['currentHandIndex']] ?? []);
            $cards = (array)($hand['cards'] ?? []);
            $bankroll = (int)($state['bankrolls'][(string)$viewerUserId] ?? 0);
            $bet = (int)($hand['bet'] ?? 0);
            $projection['legalActions'] = ['hit', 'stand'];
            if (count($cards) === 2 && $bankroll >= $bet) $projection['legalActions'][] = 'double';
            if (count($cards) === 2 && count((array)$state['hands'][(string)$viewerUserId]) < 4 && $bankroll >= $bet) {
                $firstRank = blackjack_card_parts((string)$cards[0])['rank'];
                $secondRank = blackjack_card_parts((string)$cards[1])['rank'];
                if ($firstRank === $secondRank && empty($hand['splitAces'])) $projection['legalActions'][] = 'split';
            }
            if (count($cards) === 2 && empty($hand['split']) && empty($hand['doubled'])) $projection['legalActions'][] = 'surrender';
        }
    }
    return $projection;
}

function blackjack_apply_action_rules(array $state, int $actorUserId, string $action, array $payload, array $context): array
{
    if ((int)($state['schemaVersion'] ?? 0) !== BLACKJACK_STATE_SCHEMA_VERSION || !empty($state['completed'])) {
        throw new MultiplayerGameException('The Blackjack state is unavailable.', 'BLACKJACK_STATE_INVALID', 409);
    }
    if (!in_array($actorUserId, array_map('intval', (array)$state['turnOrder']), true)) {
        throw new MultiplayerGameException('Only a player may act.', 'BLACKJACK_PLAYER_INVALID', 403);
    }
    if ($action === 'resign') {
        $state['bankrolls'][(string)$actorUserId] = -1;
        $state['completed'] = true;
        $state['phase'] = 'completed';
        $state['terminalReason'] = 'resignation';
        return ['state' => $state, 'turnUserId' => null, 'terminal' => true, 'result' => blackjack_terminal_result($state)];
    }
    ocx_game_assert_turn($state, $actorUserId);
    $phase = (string)$state['phase'];
    if ($action === 'bet') {
        if ($phase !== 'betting' || !empty($state['hands'][(string)$actorUserId])) throw new MultiplayerGameException('A wager is not available now.', 'BLACKJACK_BET_INVALID', 409);
        $bankroll = (int)$state['bankrolls'][(string)$actorUserId];
        $amount = (int)($payload['amount'] ?? 0);
        $maximum = min(BLACKJACK_MAXIMUM_BET, $bankroll);
        if ($amount < BLACKJACK_MINIMUM_BET || $amount > $maximum || $amount % 10 !== 0) {
            throw new MultiplayerGameException('Choose an available wager from 10 through 500 in steps of 10.', 'BLACKJACK_BET_INVALID', 422);
        }
        $state['bankrolls'][(string)$actorUserId] -= $amount;
        $state['hands'][(string)$actorUserId] = [blackjack_new_hand($amount)];
        $next = null;
        foreach ($state['turnOrder'] as $index => $userId) {
            if (!empty($state['hands'][(string)$userId]) || (int)$state['bankrolls'][(string)$userId] < BLACKJACK_MINIMUM_BET) continue;
            $state['turnIndex'] = $index;
            $next = (int)$userId;
            break;
        }
        if ($next === null) {
            $state['phase'] = 'deal';
            $state['turnIndex'] = 0;
            $next = (int)$state['turnOrder'][0];
        }
        return ['state' => $state, 'turnUserId' => $next];
    }
    if ($action === 'deal') {
        if ($phase !== 'deal') throw new MultiplayerGameException('A deal is not available now.', 'BLACKJACK_DEAL_INVALID', 409);
        blackjack_begin_round($state, (array)($context['authoritativeRandomness']['shoe'] ?? []));
        if (!empty($state['completed'])) return ['state' => $state, 'turnUserId' => null, 'terminal' => true, 'result' => blackjack_terminal_result($state)];
        $turnUserId = (int)($state['turnOrder'][(int)$state['turnIndex']] ?? 0);
        return ['state' => $state, 'turnUserId' => $turnUserId ?: null];
    }
    if ($action === 'insurance') {
        if ($phase !== 'insurance') throw new MultiplayerGameException('The dealer is not offering insurance.', 'BLACKJACK_INSURANCE_INVALID', 409);
        $take = filter_var($payload['take'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $bet = (int)($state['hands'][(string)$actorUserId][0]['bet'] ?? 0);
        $insurance = intdiv($bet, 2);
        if ($take && (int)$state['bankrolls'][(string)$actorUserId] < $insurance) throw new MultiplayerGameException('There are not enough chips for insurance.', 'BLACKJACK_INSURANCE_INVALID', 422);
        if ($take) {
            $state['bankrolls'][(string)$actorUserId] -= $insurance;
            $state['insuranceBets'][(string)$actorUserId] = $insurance;
        }
        $state['insuranceDecisions'][(string)$actorUserId] = $take;
        $next = blackjack_next_insurance_player($state);
        if (!empty($state['completed'])) return ['state' => $state, 'turnUserId' => null, 'terminal' => true, 'result' => blackjack_terminal_result($state)];
        return ['state' => $state, 'turnUserId' => $next];
    }
    if ($action === 'next-round') {
        if ($phase !== 'round-complete') throw new MultiplayerGameException('The next round is not available now.', 'BLACKJACK_NEXT_ROUND_INVALID', 409);
        $state['round'] = (int)$state['round'] + 1;
        $state['phase'] = 'betting';
        $state['dealer'] = ['cards' => [], 'revealed' => false];
        $state['insuranceDecisions'] = [];
        $state['lastSettlement'] = [];
        foreach ($state['turnOrder'] as $userId) {
            $state['hands'][(string)$userId] = [];
            $state['insuranceBets'][(string)$userId] = 0;
        }
        foreach ($state['turnOrder'] as $index => $userId) {
            if ((int)$state['bankrolls'][(string)$userId] >= BLACKJACK_MINIMUM_BET) {
                $state['turnIndex'] = $index;
                return ['state' => $state, 'turnUserId' => (int)$userId];
            }
        }
        throw new MultiplayerGameException('No player can place the minimum wager.', 'BLACKJACK_NO_ELIGIBLE_PLAYER', 409);
    }
    if ($action === 'dealer-play') {
        if ($phase !== 'dealer') throw new MultiplayerGameException('Dealer play is not available now.', 'BLACKJACK_DEALER_INVALID', 409);
        while (true) {
            $value = blackjack_hand_value((array)$state['dealer']['cards']);
            if ($value['total'] >= 17) break;
            $state['dealer']['cards'][] = blackjack_draw_card($state);
        }
        blackjack_settle_round($state);
        if (!empty($state['completed'])) return ['state' => $state, 'turnUserId' => null, 'terminal' => true, 'result' => blackjack_terminal_result($state)];
        return ['state' => $state, 'turnUserId' => (int)$state['turnOrder'][(int)$state['turnIndex']]];
    }
    if ($phase !== 'player-turns') throw new MultiplayerGameException('A player action is not available now.', 'BLACKJACK_ACTION_INVALID', 409);
    $playerIndex = (int)$state['turnIndex'];
    $handIndex = (int)$state['currentHandIndex'];
    $hand = &$state['hands'][(string)$actorUserId][$handIndex];
    if (!is_array($hand) || (string)($hand['status'] ?? '') !== 'active') throw new MultiplayerGameException('The active hand is unavailable.', 'BLACKJACK_HAND_INVALID', 409);
    if ($action === 'hit') {
        $hand['cards'][] = blackjack_draw_card($state);
        $value = blackjack_hand_value((array)$hand['cards']);
        if ($value['total'] < 21) return ['state' => $state, 'turnUserId' => $actorUserId];
        $hand['status'] = $value['total'] === 21 ? 'stood' : 'bust';
    } elseif ($action === 'stand') {
        $hand['status'] = 'stood';
    } elseif ($action === 'double') {
        $bet = (int)$hand['bet'];
        if (count((array)$hand['cards']) !== 2 || (int)$state['bankrolls'][(string)$actorUserId] < $bet) throw new MultiplayerGameException('This hand cannot double.', 'BLACKJACK_DOUBLE_INVALID', 422);
        $state['bankrolls'][(string)$actorUserId] -= $bet;
        $hand['bet'] = $bet * 2;
        $hand['doubled'] = true;
        $hand['cards'][] = blackjack_draw_card($state);
        $hand['status'] = blackjack_hand_value((array)$hand['cards'])['total'] > 21 ? 'bust' : 'stood';
    } elseif ($action === 'split') {
        $cards = array_values((array)$hand['cards']);
        $bet = (int)$hand['bet'];
        $hands = (array)$state['hands'][(string)$actorUserId];
        if (count($cards) !== 2 || count($hands) >= 4 || (int)$state['bankrolls'][(string)$actorUserId] < $bet) throw new MultiplayerGameException('This hand cannot split.', 'BLACKJACK_SPLIT_INVALID', 422);
        $rankOne = blackjack_card_parts((string)$cards[0])['rank'];
        $rankTwo = blackjack_card_parts((string)$cards[1])['rank'];
        if ($rankOne !== $rankTwo || !empty($hand['splitAces'])) throw new MultiplayerGameException('Only equal ranks can split.', 'BLACKJACK_SPLIT_INVALID', 422);
        $state['bankrolls'][(string)$actorUserId] -= $bet;
        $splitAces = $rankOne === 14;
        $first = blackjack_new_hand($bet, [$cards[0], blackjack_draw_card($state)], true);
        $second = blackjack_new_hand($bet, [$cards[1], blackjack_draw_card($state)], true);
        $first['splitAces'] = $splitAces;
        $second['splitAces'] = $splitAces;
        if ($splitAces) { $first['status'] = 'stood'; $second['status'] = 'stood'; }
        array_splice($state['hands'][(string)$actorUserId], $handIndex, 1, [$first, $second]);
        if (!$splitAces) return ['state' => $state, 'turnUserId' => $actorUserId];
    } elseif ($action === 'surrender') {
        if (count((array)$hand['cards']) !== 2 || !empty($hand['split']) || !empty($hand['doubled'])) throw new MultiplayerGameException('This hand cannot surrender.', 'BLACKJACK_SURRENDER_INVALID', 422);
        $hand['surrendered'] = true;
        $hand['status'] = 'surrendered';
    } else {
        throw new MultiplayerGameException('This Blackjack action is unavailable.', 'BLACKJACK_ACTION_INVALID', 422);
    }
    unset($hand);
    $next = blackjack_activate_next_hand($state, $playerIndex, $handIndex);
    return ['state' => $state, 'turnUserId' => $next];
}

function blackjack_recording_adapter(): array {
    return ['schemaVersion'=>1,'stateKeys'=>['schemaVersion','settings','turnOrder','turnIndex','phase','round','bankrolls','hands','dealer','shoe','shoeCount','currentHandIndex','insuranceDecisions','insuranceBets','completed','terminalReason','lastSettlement','bots','botSequence','publicCardMemory'],'payloadKeys'=>['amount','take']];
}
