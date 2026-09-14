<?php
declare(strict_types=1);

require_once __DIR__ . '/ocx_game_extension_support.php';

const HEARTS_EXTENSION_ID = 'hearts';
const HEARTS_STATE_SCHEMA_VERSION = 1;

function hearts_recording_adapter(): array
{
    return ['schemaVersion' => 1,
        'stateKeys' => ['ruleset', 'captured', 'completed', 'currentTrick', 'handNumber', 'hands', 'heartsBroken', 'history', 'lastCompletedTrick', 'lastHandResult', 'passCount', 'passDirection', 'pendingPasses', 'phase', 'playSequence', 'playerCount', 'resignedUserId', 'roundNumber', 'schemaVersion', 'scores', 'settings', 'settlement', 'starterIndex', 'starterReason', 'starterUserId', 'terminalReason', 'trickNumber', 'tricksWon', 'turnIndex', 'turnOrder', 'widow', 'widowCount', 'winnerUserId'],
        'payloadKeys' => ['card', 'cards']];
}

function hearts_extension_adapter(): array
{
    return [
        'recordingAdapter' => 'hearts_recording_adapter',
        'id' => HEARTS_EXTENSION_ID,
        'initialState' => 'hearts_initial_state',
        'applyAction' => 'hearts_apply_action',
        'validateSettings' => 'hearts_validate_settings',
        'settingsProjection' => 'hearts_settings_projection',
        'rulesProjection' => 'hearts_rules_projection',
        'projectState' => 'hearts_project_state',
        'randomnessPurposes' => ['deal' => 'hearts-deal'],
        'deriveRandomness' => 'hearts_derive_randomness',
        'presentationStatus' => 'hearts_presentation_status',
        'openingProcedure' => 'two-or-four-player-source-backed-hearts',
        'rematchSeatRotation' => true,
    ];
}

function hearts_presentation_status(PDO $pdo, ?string $requestedPack = null): array
{
    $requested = $requestedPack ?? 'built-in';
    return [
        'requestedPack' => $requested,
        'effectivePack' => 'built-in',
        'classicAvailable' => false,
        'fallbackApplied' => $requested !== 'built-in',
        'presentationOnly' => true,
        'mediaPack' => [
            'extensionId' => HEARTS_EXTENSION_ID,
            'installedCount' => 0,
            'requiredCount' => 0,
            'classicComplete' => false,
        ],
    ];
}

function hearts_validate_settings(array $settings, string $mode, array $definition = []): array
{
    if (array_diff(array_keys($settings), ['shootTheMoon'])) {
        throw new MultiplayerGameException('A Hearts setting is not supported.', 'HEARTS_SETTINGS_INVALID', 422);
    }
    $moon = $settings['shootTheMoon'] ?? true;
    if (!is_bool($moon) && !in_array($moon, [0, 1, '0', '1'], true)) {
        throw new MultiplayerGameException('Choose whether shooting the moon is enabled.', 'HEARTS_SETTINGS_INVALID', 422);
    }
    return ['shootTheMoon' => filter_var($moon, FILTER_VALIDATE_BOOLEAN)];
}

function hearts_settings_projection(array $settings, string $mode, array $definition = []): array
{
    $settings = hearts_validate_settings($settings, $mode, $definition);
    return [
        'label' => 'Game Options',
        'description' => 'The player count selects the approved ruleset. Two players use the 28-card variant; four players use standard Hearts.',
        'classificationLabel' => 'Accepted Hearts options',
        'controls' => [[
            'key' => 'shootTheMoon',
            'type' => 'checkbox',
            'value' => $settings['shootTheMoon'],
            'defaultValue' => true,
            'label' => 'Shoot the Moon',
            'description' => 'Enabled by default for both approved rulesets.',
        ]],
    ];
}

function hearts_rules_projection(array $settings, string $mode, array $definition = []): array
{
    hearts_validate_settings($settings, $mode, $definition);
    return [
        'label' => 'Hearts rules',
        'description' => 'Avoid penalty cards, follow suit, and finish with the lowest score.',
        'sections' => [
            ['label' => 'Four-player standard Hearts', 'text' => 'Use all 52 cards. Pass three cards left, right, across, then hold. The 2 of Clubs opens. Hearts are one point each, the Queen of Spades is 13, a moon is 26, and the game target is 100.'],
            ['label' => 'Two-player 28-card Hearts', 'text' => 'Remove every 3, 5, 7, 9, Jack, and King. Deal 13 cards to each player and two cards face down to the widow. Alternate passing one card and holding. The Queen of Spades is 7, each remaining heart is 1, a valid moon is 14, and the game target is 50.'],
            ['label' => 'Play', 'text' => 'There is no trump. Follow the led suit when able. Point cards cannot be discarded on the first trick. Hearts cannot be led until broken unless the leader holds only hearts.'],
            ['label' => 'Moon and widow', 'text' => 'Four-player moon scoring requires all 26 penalty points. In two-player play a moon requires all 14 penalty points and cannot occur when a point card is in the widow.'],
        ],
    ];
}

function hearts_deck(): array
{
    $cards = [];
    foreach (['C', 'D', 'H', 'S'] as $suit) {
        for ($rank = 2; $rank <= 14; $rank++) $cards[] = $suit . $rank;
    }
    return $cards;
}

function hearts_derive_randomness(string $canonicalReveal, string $actionType, array $payload, array $context): array
{
    return ['deck' => ocx_game_random_permutation($canonicalReveal, hearts_deck(), 'hearts-deal')];
}

function hearts_initial_state(array $playerUserIds, array $context = []): array
{
    $players = array_values(array_unique(array_map('intval', $playerUserIds)));
    if (!in_array(count($players), [2, 4], true) || min($players) < 1) {
        throw new MultiplayerGameException('Hearts requires exactly two or four authenticated players.', 'HEARTS_PLAYER_SET_INVALID', 422);
    }
    $settings = hearts_validate_settings((array)($context['settings'] ?? []), (string)($context['mode'] ?? 'practice'));
    $playerCount = count($players);
    $settings['targetScore'] = $playerCount === 2 ? 50 : 100;
    return [
        'schemaVersion' => HEARTS_STATE_SCHEMA_VERSION,
        'ruleset' => $playerCount === 2 ? 'two-player-28-card' : 'standard-four-player',
        'playerCount' => $playerCount,
        'turnOrder' => $players,
        'turnIndex' => 0,
        'roundNumber' => 1,
        'handNumber' => 0,
        'starterUserId' => null,
        'starterReason' => 'The lowest available Club opens each hand.',
        'phase' => 'deal',
        'settings' => $settings,
        'hands' => array_fill_keys(array_map('strval', $players), []),
        'captured' => array_fill_keys(array_map('strval', $players), []),
        'scores' => array_fill_keys(array_map('strval', $players), 0),
        'tricksWon' => array_fill_keys(array_map('strval', $players), 0),
        'pendingPasses' => [],
        'passDirection' => 'pending',
        'passCount' => 0,
        'widow' => [],
        'widowCount' => 0,
        'currentTrick' => [],
        'lastCompletedTrick' => null,
        'lastHandResult' => null,
        'settlement' => null,
        'trickNumber' => 0,
        'playSequence' => 0,
        'heartsBroken' => false,
        'history' => [],
        'completed' => false,
    ];
}

function hearts_card_parts(string $card): array
{
    if (!preg_match('/^([CDHS])(2|3|4|5|6|7|8|9|10|11|12|13|14)$/', $card, $match)) {
        throw new MultiplayerGameException('Choose a valid card.', 'HEARTS_CARD_INVALID', 422);
    }
    return ['suit' => $match[1], 'rank' => (int)$match[2]];
}

function hearts_sort_hand(array $cards): array
{
    usort($cards, static function (string $left, string $right): int {
        $suits = ['C' => 0, 'D' => 1, 'H' => 2, 'S' => 3];
        $a = hearts_card_parts($left); $b = hearts_card_parts($right);
        return [$suits[$a['suit']], $a['rank']] <=> [$suits[$b['suit']], $b['rank']];
    });
    return array_values($cards);
}

function hearts_point_value(string $card, int $playerCount): int
{
    $parts = hearts_card_parts($card);
    if ($parts['suit'] === 'H') return 1;
    if ($parts['suit'] === 'S' && $parts['rank'] === 12) return $playerCount === 2 ? 7 : 13;
    return 0;
}

function hearts_lowest_club_owner(array $state): int
{
    $candidate = null; $owner = 0;
    foreach ($state['hands'] as $userId => $cards) {
        foreach ((array)$cards as $card) {
            $parts = hearts_card_parts((string)$card);
            if ($parts['suit'] === 'C' && ($candidate === null || $parts['rank'] < $candidate)) {
                $candidate = $parts['rank']; $owner = (int)$userId;
            }
        }
    }
    if ($owner < 1) throw new MultiplayerGameException('The opening Club is unavailable.', 'HEARTS_OPENING_CARD_INVALID', 409);
    return $owner;
}

function hearts_set_turn(array &$state, int $userId): void
{
    $index = array_search($userId, array_map('intval', $state['turnOrder']), true);
    if ($index === false) throw new MultiplayerGameException('The next Hearts player is unavailable.', 'HEARTS_TURN_INVALID', 409);
    $state['turnIndex'] = (int)$index;
}

function hearts_begin_play(array &$state): void
{
    $starter = hearts_lowest_club_owner($state);
    hearts_set_turn($state, $starter);
    $state['starterUserId'] = $starter;
    $state['starterReason'] = 'Lowest available Club opens the hand.';
    $state['phase'] = 'playing';
}

function hearts_begin_hand(array &$state, array $randomDeck): void
{
    if (count($randomDeck) !== 52 || count(array_unique($randomDeck)) !== 52) {
        throw new MultiplayerGameException('The verified Hearts deal is unavailable.', 'HEARTS_RANDOMNESS_INVALID', 409);
    }
    $players = array_values(array_map('intval', $state['turnOrder']));
    $playerCount = count($players);
    $deck = array_values(array_map('strval', $randomDeck));
    if ($playerCount === 2) {
        $allowed = [2, 4, 6, 8, 10, 12, 14];
        $deck = array_values(array_filter($deck, static fn(string $card): bool => in_array(hearts_card_parts($card)['rank'], $allowed, true)));
    }
    $state['hands'] = array_fill_keys(array_map('strval', $players), []);
    $state['captured'] = array_fill_keys(array_map('strval', $players), []);
    $state['tricksWon'] = array_fill_keys(array_map('strval', $players), 0);
    $state['widow'] = [];
    if ($playerCount === 2) {
        $state['widow'][] = array_shift($deck);
        $state['widow'][] = array_pop($deck);
    }
    foreach ($deck as $index => $card) $state['hands'][(string)$players[$index % $playerCount]][] = $card;
    foreach ($players as $userId) $state['hands'][(string)$userId] = hearts_sort_hand($state['hands'][(string)$userId]);
    $state['handNumber'] = (int)$state['handNumber'] + 1;
    $state['roundNumber'] = (int)$state['handNumber'];
    $state['widowCount'] = count($state['widow']);
    $state['pendingPasses'] = [];
    $state['currentTrick'] = [];
    $state['lastCompletedTrick'] = null;
    $state['lastHandResult'] = null;
    $state['settlement'] = null;
    $state['trickNumber'] = 0;
    $state['playSequence'] = 0;
    $state['heartsBroken'] = false;
    if ($playerCount === 2) {
        $state['passDirection'] = (int)$state['handNumber'] % 2 === 1 ? 'across' : 'hold';
        $state['passCount'] = $state['passDirection'] === 'hold' ? 0 : 1;
    } else {
        $directions = ['left', 'right', 'across', 'hold'];
        $state['passDirection'] = $directions[((int)$state['handNumber'] - 1) % 4];
        $state['passCount'] = $state['passDirection'] === 'hold' ? 0 : 3;
    }
    if ((int)$state['passCount'] === 0) hearts_begin_play($state);
    else { $state['phase'] = 'passing'; $state['turnIndex'] = 0; }
}

function hearts_legal_cards(array $state, int $actorUserId): array
{
    if ((string)($state['phase'] ?? '') !== 'playing') return [];
    $turnOrder = array_values(array_map('intval', (array)$state['turnOrder']));
    $turnIndex = (int)($state['turnIndex'] ?? -1);
    if (!isset($turnOrder[$turnIndex]) || $turnOrder[$turnIndex] !== $actorUserId) return [];
    $hand = array_values(array_map('strval', (array)($state['hands'][(string)$actorUserId] ?? [])));
    if ($hand === []) return [];
    $trick = array_values((array)($state['currentTrick'] ?? []));
    if ($trick !== []) {
        $leadSuit = hearts_card_parts((string)$trick[0]['card'])['suit'];
        $follow = array_values(array_filter($hand, static fn(string $card): bool => hearts_card_parts($card)['suit'] === $leadSuit));
        $eligible = $follow !== [] ? $follow : $hand;
        if ((int)$state['trickNumber'] === 0 && $follow === []) {
            $nonPoints = array_values(array_filter($eligible, static fn(string $card): bool => hearts_point_value($card, (int)$state['playerCount']) === 0));
            if ($nonPoints !== []) $eligible = $nonPoints;
        }
        return $eligible;
    }
    if ((int)$state['trickNumber'] === 0) {
        $clubs = array_values(array_filter($hand, static fn(string $card): bool => hearts_card_parts($card)['suit'] === 'C'));
        usort($clubs, static fn(string $a, string $b): int => hearts_card_parts($a)['rank'] <=> hearts_card_parts($b)['rank']);
        return $clubs === [] ? [] : [$clubs[0]];
    }
    if (empty($state['heartsBroken'])) {
        $nonHearts = array_values(array_filter($hand, static fn(string $card): bool => hearts_card_parts($card)['suit'] !== 'H'));
        if ($nonHearts !== []) return $nonHearts;
    }
    return $hand;
}

function hearts_project_state(array $state, int $viewerUserId, array $context): array
{
    $projection = $state;
    $projection['legalCards'] = hearts_legal_cards($state, $viewerUserId);
    foreach ((array)($projection['hands'] ?? []) as $userId => $hand) {
        if ((int)$userId === $viewerUserId) continue;
        $projection['hands'][$userId] = ['private' => true, 'count' => count((array)$hand)];
    }
    foreach ((array)($projection['pendingPasses'] ?? []) as $userId => $cards) {
        if ((int)$userId !== $viewerUserId) $projection['pendingPasses'][$userId] = ['private' => true, 'count' => count((array)$cards)];
    }
    $projection['widow'] = ['private' => true, 'count' => count((array)($state['widow'] ?? []))];
    foreach ((array)($projection['captured'] ?? []) as $userId => $cards) {
        $projection['captured'][$userId] = ['private' => true, 'count' => count((array)$cards), 'points' => array_sum(array_map(static fn(string $card): int => hearts_point_value($card, (int)$state['playerCount']), (array)$cards))];
    }
    return $projection;
}

function hearts_pass_target_index(int $source, int $count, string $direction): int
{
    return match ($direction) {
        'left' => ($source + 1) % $count,
        'right' => ($source - 1 + $count) % $count,
        'across' => ($source + intdiv($count, 2)) % $count,
        default => $source,
    };
}

function hearts_apply_passes(array &$state): void
{
    $players = array_values(array_map('intval', $state['turnOrder']));
    $incoming = array_fill_keys(array_map('strval', $players), []);
    foreach ($players as $index => $userId) {
        $cards = array_values(array_map('strval', (array)$state['pendingPasses'][(string)$userId]));
        $state['hands'][(string)$userId] = array_values(array_diff($state['hands'][(string)$userId], $cards));
        $target = $players[hearts_pass_target_index($index, count($players), (string)$state['passDirection'])];
        $incoming[(string)$target] = array_merge($incoming[(string)$target], $cards);
    }
    foreach ($players as $userId) $state['hands'][(string)$userId] = hearts_sort_hand(array_merge($state['hands'][(string)$userId], $incoming[(string)$userId]));
    $state['pendingPasses'] = [];
    hearts_begin_play($state);
}

function hearts_trick_winner(array $trick): int
{
    $leadSuit = hearts_card_parts((string)$trick[0]['card'])['suit'];
    $winner = (int)$trick[0]['userId']; $rank = hearts_card_parts((string)$trick[0]['card'])['rank'];
    foreach ($trick as $play) {
        $parts = hearts_card_parts((string)$play['card']);
        if ($parts['suit'] === $leadSuit && $parts['rank'] > $rank) { $rank = $parts['rank']; $winner = (int)$play['userId']; }
    }
    return $winner;
}

function hearts_score_hand(array &$state): void
{
    $players = array_values(array_map('intval', $state['turnOrder']));
    $points = [];
    foreach ($players as $userId) {
        $points[(string)$userId] = array_sum(array_map(static fn(string $card): int => hearts_point_value($card, (int)$state['playerCount']), (array)$state['captured'][(string)$userId]));
    }
    $moonValue = (int)$state['playerCount'] === 2 ? 14 : 26;
    $widowPoints = array_sum(array_map(static fn(string $card): int => hearts_point_value($card, (int)$state['playerCount']), (array)$state['widow']));
    $shooter = null;
    if (!empty($state['settings']['shootTheMoon']) && ((int)$state['playerCount'] === 4 || $widowPoints === 0)) {
        foreach ($players as $userId) if ($points[(string)$userId] === $moonValue) $shooter = $userId;
    }
    if ($shooter !== null) {
        foreach ($players as $userId) if ($userId !== $shooter) $state['scores'][(string)$userId] = (int)$state['scores'][(string)$userId] + $moonValue;
    } else {
        foreach ($players as $userId) $state['scores'][(string)$userId] = (int)$state['scores'][(string)$userId] + $points[(string)$userId];
    }
    $state['lastHandResult'] = ['handNumber' => (int)$state['handNumber'], 'pointsByUser' => $points, 'moonShooterUserId' => $shooter, 'widowPoints' => $widowPoints];
    $state['history'][] = ['handNumber' => (int)$state['handNumber'], 'pointsByUser' => $points, 'scores' => $state['scores'], 'moonShooterUserId' => $shooter];
}

function hearts_terminal(array &$state): ?array
{
    $target = (int)$state['settings']['targetScore'];
    $scores = array_map('intval', $state['scores']);
    if (max($scores) < $target) return null;
    $minimum = min($scores);
    $winners = array_keys(array_filter($scores, static fn(int $score): bool => $score === $minimum));
    if (count($winners) !== 1) return null;
    $winner = (int)$winners[0];
    $resultScores = [];
    foreach ($state['turnOrder'] as $userId) $resultScores[(string)(int)$userId] = (int)$userId === $winner ? 1 : 0;
    $state['completed'] = true; $state['phase'] = 'completed'; $state['winnerUserId'] = $winner; $state['terminalReason'] = 'lowest-score-at-target';
    return ['state' => $state, 'turnUserId' => null, 'terminal' => true, 'result' => ocx_game_result_from_scores($resultScores)];
}

function hearts_apply_action(array $state, int $actorUserId, string $action, array $payload, array $context): array
{
    if ((int)($state['schemaVersion'] ?? 0) !== HEARTS_STATE_SCHEMA_VERSION || !empty($state['completed'])) {
        throw new MultiplayerGameException('The Hearts state is unavailable.', 'HEARTS_STATE_INVALID', 409);
    }
    if (!in_array($actorUserId, array_map('intval', $state['turnOrder']), true)) {
        throw new MultiplayerGameException('Only a player may act.', 'HEARTS_PLAYER_INVALID', 403);
    }
    if ($action === 'resign') {
        $scores = []; foreach ($state['turnOrder'] as $userId) $scores[(string)(int)$userId] = (int)$userId === $actorUserId ? 0 : 1;
        $state['completed'] = true; $state['phase'] = 'completed'; $state['terminalReason'] = 'resignation';
        return ['state' => $state, 'turnUserId' => null, 'terminal' => true, 'result' => ocx_game_result_from_scores($scores)];
    }
    if ($action === 'deal') {
        if ((string)$state['phase'] !== 'deal') throw new MultiplayerGameException('A deal is not available now.', 'HEARTS_DEAL_INVALID', 409);
        ocx_game_assert_turn($state, $actorUserId);
        hearts_begin_hand($state, (array)($context['authoritativeRandomness']['deck'] ?? []));
        return ['state' => $state, 'turnUserId' => (int)$state['turnOrder'][(int)$state['turnIndex']]];
    }
    if ($action === 'pass') {
        if ((string)$state['phase'] !== 'passing') throw new MultiplayerGameException('Card passing is not active.', 'HEARTS_PASS_INVALID', 409);
        ocx_game_assert_turn($state, $actorUserId);
        $cards = array_values(array_unique(array_map('strval', (array)($payload['cards'] ?? []))));
        if (count($cards) !== (int)$state['passCount']) throw new MultiplayerGameException('Choose the required number of cards to pass.', 'HEARTS_PASS_INVALID', 422);
        foreach ($cards as $card) if (!in_array($card, $state['hands'][(string)$actorUserId], true)) throw new MultiplayerGameException('A selected pass card is unavailable.', 'HEARTS_PASS_INVALID', 422);
        $state['pendingPasses'][(string)$actorUserId] = $cards;
        if (count($state['pendingPasses']) === count($state['turnOrder'])) hearts_apply_passes($state);
        else $state['turnIndex'] = ((int)$state['turnIndex'] + 1) % count($state['turnOrder']);
        return ['state' => $state, 'turnUserId' => (int)$state['turnOrder'][(int)$state['turnIndex']]];
    }
    if ($action === 'settle-trick') {
        if ((string)$state['phase'] !== 'settling' || !is_array($state['settlement'])) throw new MultiplayerGameException('There is no completed trick to settle.', 'HEARTS_SETTLEMENT_INVALID', 409);
        ocx_game_assert_turn($state, $actorUserId);
        $now = (int)($context['nowUnixMs'] ?? floor(microtime(true) * 1000));
        if ($now < (int)$state['settlement']['settleAfterUnixMs']) throw new MultiplayerGameException('The completed trick is still being shown.', 'HEARTS_SETTLEMENT_EARLY', 409, ['retryAfterMs' => (int)$state['settlement']['settleAfterUnixMs'] - $now]);
        $state['currentTrick'] = []; $state['settlement'] = null;
        if (array_sum(array_map('count', $state['hands'])) === 0) {
            hearts_score_hand($state);
            $terminal = hearts_terminal($state);
            if ($terminal !== null) return $terminal;
            $state['phase'] = 'deal'; $state['turnIndex'] = ((int)$state['handNumber']) % count($state['turnOrder']);
        } else $state['phase'] = 'playing';
        return ['state' => $state, 'turnUserId' => (int)$state['turnOrder'][(int)$state['turnIndex']]];
    }
    if ($action !== 'play') throw new MultiplayerGameException('This Hearts action is not supported.', 'HEARTS_ACTION_INVALID', 422);
    if ((string)$state['phase'] !== 'playing') throw new MultiplayerGameException('Card play is not active.', 'HEARTS_PLAY_INVALID', 409);
    ocx_game_assert_turn($state, $actorUserId);
    $card = strtoupper(trim((string)($payload['card'] ?? '')));
    hearts_card_parts($card);
    if (!in_array($card, hearts_legal_cards($state, $actorUserId), true)) throw new MultiplayerGameException('That card is not a legal play.', 'HEARTS_PLAY_INVALID', 422);
    $leadSuit = $state['currentTrick'] === [] ? null : hearts_card_parts((string)$state['currentTrick'][0]['card'])['suit'];
    $state['hands'][(string)$actorUserId] = array_values(array_diff($state['hands'][(string)$actorUserId], [$card]));
    $state['currentTrick'][] = ['userId' => $actorUserId, 'card' => $card];
    $state['playSequence'] = (int)$state['playSequence'] + 1;
    if (hearts_card_parts($card)['suit'] === 'H' && $leadSuit !== 'H') $state['heartsBroken'] = true;
    if (count($state['currentTrick']) < count($state['turnOrder'])) {
        $state['turnIndex'] = ((int)$state['turnIndex'] + 1) % count($state['turnOrder']);
        return ['state' => $state, 'turnUserId' => (int)$state['turnOrder'][(int)$state['turnIndex']]];
    }
    $winner = hearts_trick_winner($state['currentTrick']);
    foreach ($state['currentTrick'] as $play) $state['captured'][(string)$winner][] = (string)$play['card'];
    $state['tricksWon'][(string)$winner] = (int)$state['tricksWon'][(string)$winner] + 1;
    $state['trickNumber'] = (int)$state['trickNumber'] + 1;
    $state['lastCompletedTrick'] = ['cards' => $state['currentTrick'], 'winnerUserId' => $winner, 'trick' => (int)$state['trickNumber']];
    hearts_set_turn($state, $winner);
    $now = (int)($context['nowUnixMs'] ?? floor(microtime(true) * 1000));
    $state['phase'] = 'settling'; $state['settlement'] = ['winnerUserId' => $winner, 'settleAfterUnixMs' => $now + 900];
    return ['state' => $state, 'turnUserId' => $winner];
}
