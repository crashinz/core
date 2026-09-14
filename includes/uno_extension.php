<?php
declare(strict_types=1);

require_once __DIR__ . '/ocx_game_extension_support.php';
require_once __DIR__ . '/uno_bot_support.php';

const UNO_EXTENSION_ID = 'uno';
const UNO_STATE_SCHEMA_VERSION = 1;

function uno_recording_adapter(): array
{
    return ['schemaVersion' => 1,
        'stateKeys' => ['bots', 'playerCount', 'completed', 'currentColor', 'dealerIndex', 'dealerUserId', 'direction', 'discardPile', 'drawPile', 'drawnCardId', 'handNumber', 'hands', 'history', 'lastAction', 'lastRoundResult', 'pendingChallenge', 'phase', 'playSequence', 'reshuffleCount', 'reshuffleSeed', 'resignedUserId', 'roundNumber', 'schemaVersion', 'scores', 'settings', 'starterIndex', 'starterReason', 'starterUserId', 'targetScore', 'terminalReason', 'turnIndex', 'turnOrder', 'unoCatchUserId', 'unoDeclared', 'winnerUserId'],
        'payloadKeys' => ['card', 'color']];
}

function uno_extension_adapter(): array
{
    return [
        'recordingAdapter' => 'uno_recording_adapter',
        'id' => UNO_EXTENSION_ID,
        'initialState' => 'uno_initial_state',
        'applyAction' => 'uno_apply_action',
        'validateSettings' => 'uno_validate_settings',
        'settingsProjection' => 'uno_settings_projection',
        'rulesProjection' => 'uno_rules_projection',
        'projectState' => 'uno_project_state',
        'projectVirtualMembers' => 'uno_project_virtual_members',
        'randomnessPurposes' => ['deal' => 'uno-deal', 'bot-deal' => 'uno-deal'],
        'deriveRandomness' => 'uno_derive_randomness',
        'presentationStatus' => 'uno_presentation_status',
        'openingProcedure' => 'server-shuffled-seven-card-clockwise-deal',
        'rematchSeatRotation' => true,
    ];
}

function uno_presentation_status(PDO $pdo, ?string $requestedPack = null): array
{
    $requested = $requestedPack ?? 'built-in';
    return [
        'requestedPack' => $requested,
        'effectivePack' => 'built-in',
        'classicAvailable' => false,
        'fallbackApplied' => $requested !== 'built-in',
        'presentationOnly' => true,
        'mediaPack' => [
            'extensionId' => UNO_EXTENSION_ID,
            'installedCount' => 0,
            'requiredCount' => 0,
            'classicComplete' => false,
        ],
    ];
}

function uno_validate_settings(array $settings, string $mode, array $definition = []): array
{
    $allowed = [];
    for ($seat = 1; $seat <= 10; $seat++) $allowed[] = 'botSeat'.$seat.'Difficulty';
    if (array_diff(array_keys($settings), $allowed)) throw new MultiplayerGameException('UNO does not have configurable match rules.', 'UNO_SETTINGS_INVALID', 422);
    $out = [];
    foreach ($allowed as $key) {
        $value = $settings[$key] ?? 'none';
        if (!is_string($value) || !in_array($value, ['none','easy','normal','expert'], true)) throw new MultiplayerGameException('Choose a listed UNO bot difficulty.', 'UNO_SETTINGS_INVALID', 422);
        if ($mode !== 'practice' && $value !== 'none') throw new MultiplayerGameException('Games with bots are Practice only.', 'MULTIPLAYER_GAME_BOTS_PRACTICE_ONLY', 422);
        if ($mode === 'practice') $out[$key] = $value;
    }
    return uno_bot_lobby_settings($out);
}

function uno_settings_projection(array $settings, string $mode, array $definition = []): array
{
    uno_validate_settings($settings, $mode, $definition);
    return [
        'label' => 'Game Options',
        'description' => 'Standard 108-card play for two through ten players. Add optional Practice bots to empty seats in the waiting lobby. The first player to 500 points wins.',
        'classificationLabel' => 'Accepted UNO options',
        'controls' => [],
    ];
}

function uno_rules_projection(array $settings, string $mode, array $definition = []): array
{
    uno_validate_settings($settings, $mode, $definition);
    return [
        'label' => 'UNO rules',
        'description' => 'Match the discard by color, number, or symbol and be the first player to empty your hand.',
        'sections' => [
            ['label' => 'Players and deck', 'text' => 'Two through ten players use one standard 108-card four-color deck. Each hand begins with seven cards per player and one face-up discard. Practice can include Easy or Normal bots with at least one human. Bot games never affect ranked records. Draw penalties do not stack.'],
            ['label' => 'Your turn', 'text' => 'Play one card matching the current color, number, or action symbol, or play a Wild. You may choose to draw even when a card in your hand is playable. After drawing, only that drawn card may be played; otherwise keep it and end the turn.'],
            ['label' => 'Special-card guide', 'text' => 'These are the non-number cards used in this game.', 'items' => [
                ['card' => 'R:S:1', 'label' => 'Skip', 'text' => 'The next player loses that turn.'],
                ['card' => 'G:R:1', 'label' => 'Reverse', 'text' => 'Play changes direction. With two players, the other player is skipped and you play again.'],
                ['card' => 'Y:D2:1', 'label' => 'Draw Two', 'text' => 'The next player draws two cards and loses that turn. Draw penalties do not stack.'],
                ['card' => 'W:W:1', 'label' => 'Wild', 'text' => 'Play it on any card, then choose the color that continues.'],
                ['card' => 'W:D4:1', 'label' => 'Wild Draw Four', 'text' => 'Choose the continuing color. The next player draws four unless they challenge the play.'],
            ]],
            ['label' => 'Wild Draw Four', 'text' => 'Wild Draw Four may be played only when the player has no card matching the current color. The affected player may accept four cards or challenge. A successful challenge makes the offender draw four; an unsuccessful challenge makes the challenger draw six.'],
            ['label' => 'Calling UNO', 'text' => 'Use the UNO button before playing from two cards down to one. If it was not called, another player may catch the omission before the next turn begins, making the player draw two cards.'],
            ['label' => 'Scoring and victory', 'text' => 'The hand winner scores every card left in the other hands: number cards at face value, action cards at 20, and Wild cards at 50. The first player to reach 500 points wins the match.'],
            ['label' => 'Unofficial implementation', 'text' => 'UNO is a registered trademark of Mattel, Inc. This unofficial game is not affiliated with, sponsored by, or endorsed by Mattel.'],
        ],
    ];
}

function uno_deck(): array
{
    $cards = [];
    foreach (['R', 'Y', 'G', 'B'] as $color) {
        $cards[] = $color . ':0:1';
        for ($number = 1; $number <= 9; $number++) {
            $cards[] = $color . ':' . $number . ':1';
            $cards[] = $color . ':' . $number . ':2';
        }
        foreach (['S', 'R', 'D2'] as $symbol) {
            $cards[] = $color . ':' . $symbol . ':1';
            $cards[] = $color . ':' . $symbol . ':2';
        }
    }
    for ($copy = 1; $copy <= 4; $copy++) {
        $cards[] = 'W:W:' . $copy;
        $cards[] = 'W:D4:' . $copy;
    }
    return $cards;
}

function uno_derive_randomness(string $canonicalReveal, string $actionType, array $payload, array $context): array
{
    return [
        'deck' => ocx_game_random_permutation($canonicalReveal, uno_deck(), 'uno-deal'),
        'reshuffleSeed' => hash('sha256', $canonicalReveal . '|uno-reshuffle'),
    ];
}

function uno_initial_state(array $playerUserIds, array $context = []): array
{
    $players = array_values(array_unique(array_map('intval', $playerUserIds)));
    if (count($players) < 1 || count($players) > 10 || min($players) < 1) {
        throw new MultiplayerGameException('UNO requires two through ten authenticated players.', 'UNO_PLAYER_SET_INVALID', 422);
    }
    $mode = (string)($context['mode'] ?? 'practice');
    $settings = uno_validate_settings((array)($context['settings'] ?? []), $mode);
    [$players, $bots] = uno_bot_fill_seats($players, $settings, $mode, (array)($context['humanSeats'] ?? []));
    if (count($players) < 2 || count($players) > 10) throw new MultiplayerGameException('UNO needs two through ten people or Practice bots.', 'MULTIPLAYER_GAME_MINIMUM_PLAYERS', 409);
    $dealerIndex = 0;
    return [
        'schemaVersion' => UNO_STATE_SCHEMA_VERSION,
        'playerCount' => count($players),
        'bots' => $bots,
        'turnOrder' => $players,
        'turnIndex' => 1 % count($players),
        'dealerIndex' => $dealerIndex,
        'dealerUserId' => $players[$dealerIndex],
        'direction' => 1,
        'phase' => 'deal',
        'handNumber' => 0,
        'targetScore' => 500,
        'hands' => array_fill_keys(array_map('strval', $players), []),
        'scores' => array_fill_keys(array_map('strval', $players), 0),
        'drawPile' => [],
        'discardPile' => [],
        'currentColor' => null,
        'reshuffleSeed' => '',
        'reshuffleCount' => 0,
        'drawnCardId' => null,
        'pendingChallenge' => null,
        'unoDeclared' => array_fill_keys(array_map('strval', $players), false),
        'unoCatchUserId' => null,
        'playSequence' => 0,
        'lastAction' => null,
        'lastRoundResult' => null,
        'history' => [],
        'starterUserId' => $players[1 % count($players)],
        'starterReason' => 'The player to the dealer\'s left begins after the verified deal.',
        'completed' => false,
    ];
}

function uno_card_parts(string $card): array
{
    if (!preg_match('/^([RYGBW]):(0|1|2|3|4|5|6|7|8|9|S|R|D2|W|D4):([1-4])$/', $card, $match)) {
        throw new MultiplayerGameException('Choose a valid card.', 'UNO_CARD_INVALID', 422);
    }
    $color = $match[1];
    $symbol = $match[2];
    if (($color === 'W') !== in_array($symbol, ['W', 'D4'], true)) {
        throw new MultiplayerGameException('Choose a valid card.', 'UNO_CARD_INVALID', 422);
    }
    return ['color' => $color, 'symbol' => $symbol, 'copy' => (int)$match[3]];
}

function uno_sort_hand(array $cards): array
{
    $colorOrder = ['R' => 0, 'Y' => 1, 'G' => 2, 'B' => 3, 'W' => 4];
    $symbolOrder = ['0' => 0, '1' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6, '7' => 7, '8' => 8, '9' => 9, 'S' => 10, 'R' => 11, 'D2' => 12, 'W' => 13, 'D4' => 14];
    usort($cards, static function (string $left, string $right) use ($colorOrder, $symbolOrder): int {
        $a = uno_card_parts($left); $b = uno_card_parts($right);
        return [$colorOrder[$a['color']], $symbolOrder[$a['symbol']], $a['copy']] <=> [$colorOrder[$b['color']], $symbolOrder[$b['symbol']], $b['copy']];
    });
    return array_values($cards);
}

function uno_turn_user(array $state): int
{
    $turnOrder = array_values(array_map('intval', (array)($state['turnOrder'] ?? [])));
    if ($turnOrder === []) return 0;
    $turnIndex = (int)($state['turnIndex'] ?? 0);
    return (int)($turnOrder[$turnIndex] ?? $turnOrder[0]);
}

function uno_advance_index(array $state, int $fromIndex, int $steps = 1): int
{
    $count = count((array)$state['turnOrder']);
    $direction = (int)($state['direction'] ?? 1) < 0 ? -1 : 1;
    return (($fromIndex + ($steps * $direction)) % $count + $count) % $count;
}

function uno_draw_one(array &$state): string
{
    if ((array)$state['drawPile'] === []) {
        $discard = array_values(array_map('strval', (array)$state['discardPile']));
        if (count($discard) <= 1) {
            throw new MultiplayerGameException('The UNO draw pile cannot be restored.', 'UNO_DRAW_PILE_INVALID', 409);
        }
        $top = array_pop($discard);
        $state['discardPile'] = [$top];
        $state['reshuffleCount'] = (int)$state['reshuffleCount'] + 1;
        $seed = hash('sha256', (string)$state['reshuffleSeed'] . '|' . (int)$state['reshuffleCount']);
        $state['drawPile'] = ocx_game_random_permutation($seed, $discard, 'uno-reshuffle');
    }
    $card = array_pop($state['drawPile']);
    if (!is_string($card)) throw new MultiplayerGameException('The next UNO card is unavailable.', 'UNO_DRAW_PILE_INVALID', 409);
    return $card;
}

function uno_draw_cards(array &$state, int $userId, int $count): array
{
    $drawn = [];
    for ($index = 0; $index < $count; $index++) $drawn[] = uno_draw_one($state);
    $key = (string)$userId;
    $state['hands'][$key] = uno_sort_hand(array_merge((array)$state['hands'][$key], $drawn));
    return $drawn;
}

function uno_card_playable(string $card, array $state): bool
{
    $parts = uno_card_parts($card);
    if ($parts['color'] === 'W') return true;
    if ($parts['color'] === (string)($state['currentColor'] ?? '')) return true;
    $top = (string)(array_slice((array)$state['discardPile'], -1)[0] ?? '');
    if ($top === '') return false;
    return $parts['symbol'] === uno_card_parts($top)['symbol'];
}

function uno_card_points(string $card): int
{
    $symbol = uno_card_parts($card)['symbol'];
    if (ctype_digit($symbol)) return (int)$symbol;
    return in_array($symbol, ['W', 'D4'], true) ? 50 : 20;
}

function uno_begin_hand(array &$state, array $verified): void
{
    $deck = array_values(array_map('strval', (array)($verified['deck'] ?? [])));
    if (count($deck) !== 108 || count(array_unique($deck)) !== 108 || array_diff($deck, uno_deck()) !== [] || array_diff(uno_deck(), $deck) !== []) {
        throw new MultiplayerGameException('The verified UNO deal is unavailable.', 'UNO_RANDOMNESS_INVALID', 409);
    }
    $seed = trim((string)($verified['reshuffleSeed'] ?? ''));
    if (!preg_match('/^[a-f0-9]{64}$/', $seed)) throw new MultiplayerGameException('The verified UNO reshuffle owner is unavailable.', 'UNO_RANDOMNESS_INVALID', 409);
    $players = array_values(array_map('intval', $state['turnOrder']));
    $count = count($players);
    $state['hands'] = array_fill_keys(array_map('strval', $players), []);
    $state['drawPile'] = $deck;
    $state['discardPile'] = [];
    $state['direction'] = 1;
    $state['reshuffleSeed'] = $seed;
    $state['reshuffleCount'] = 0;
    $state['drawnCardId'] = null;
    $state['pendingChallenge'] = null;
    $state['unoDeclared'] = array_fill_keys(array_map('strval', $players), false);
    $state['unoCatchUserId'] = null;
    $state['lastRoundResult'] = null;
    $state['handNumber'] = (int)$state['handNumber'] + 1;
    $firstIndex = (($state['dealerIndex'] + 1) % $count);
    for ($round = 0; $round < 7; $round++) {
        for ($offset = 0; $offset < $count; $offset++) {
            $index = ($firstIndex + $offset) % $count;
            $state['hands'][(string)$players[$index]][] = uno_draw_one($state);
        }
    }
    foreach ($players as $userId) $state['hands'][(string)$userId] = uno_sort_hand((array)$state['hands'][(string)$userId]);
    do {
        $top = uno_draw_one($state);
        if (uno_card_parts($top)['symbol'] === 'D4') array_unshift($state['drawPile'], $top);
    } while (uno_card_parts($top)['symbol'] === 'D4');
    $state['discardPile'][] = $top;
    $parts = uno_card_parts($top);
    $state['currentColor'] = $parts['color'] === 'W' ? null : $parts['color'];
    $state['phase'] = 'playing';
    $state['turnIndex'] = $firstIndex;
    if ($parts['symbol'] === 'W') {
        $state['phase'] = 'opening-color';
    } elseif ($parts['symbol'] === 'S') {
        $state['turnIndex'] = uno_advance_index($state, $firstIndex, 1);
    } elseif ($parts['symbol'] === 'R') {
        $state['direction'] = -1;
        $state['turnIndex'] = (int)$state['dealerIndex'];
    } elseif ($parts['symbol'] === 'D2') {
        uno_draw_cards($state, $players[$firstIndex], 2);
        $state['turnIndex'] = uno_advance_index($state, $firstIndex, 1);
    }
    $state['starterUserId'] = uno_turn_user($state);
    $state['starterReason'] = $parts['symbol'] === 'R'
        ? 'The opening Reverse makes the dealer begin in the opposite direction.'
        : 'The verified opening discard and its action determine the first turn.';
    $state['playSequence'] = (int)$state['playSequence'] + 1;
    $state['lastAction'] = ['sequence' => $state['playSequence'], 'type' => 'deal', 'userId' => null, 'card' => $top, 'drawCount' => 0];
}

function uno_legal_cards(array $state, int $actorUserId): array
{
    if (!in_array((string)($state['phase'] ?? ''), ['playing', 'drawn'], true) || uno_turn_user($state) !== $actorUserId) return [];
    $hand = array_values(array_map('strval', (array)$state['hands'][(string)$actorUserId]));
    if ((string)$state['phase'] === 'drawn') {
        $drawn = (string)($state['drawnCardId'] ?? '');
        return $drawn !== '' && in_array($drawn, $hand, true) && uno_card_playable($drawn, $state) ? [$drawn] : [];
    }
    return array_values(array_filter($hand, static fn(string $card): bool => uno_card_playable($card, $state)));
}

function uno_complete_round(array &$state, int $winnerUserId): ?array
{
    $points = 0;
    foreach ((array)$state['hands'] as $cards) foreach ((array)$cards as $card) $points += uno_card_points((string)$card);
    $winnerKey = (string)$winnerUserId;
    $state['scores'][$winnerKey] = (int)$state['scores'][$winnerKey] + $points;
    $state['lastRoundResult'] = [
        'handNumber' => (int)$state['handNumber'],
        'winnerUserId' => $winnerUserId,
        'points' => $points,
        'scores' => $state['scores'],
    ];
    $state['history'][] = $state['lastRoundResult'];
    if (count($state['history']) > 20) $state['history'] = array_slice($state['history'], -20);
    $state['turnIndex'] = (int)array_search($winnerUserId, array_map('intval', $state['turnOrder']), true);
    if ((int)$state['scores'][$winnerKey] < (int)$state['targetScore']) {
        $state['phase'] = 'round-complete';
        return null;
    }
    $state['completed'] = true;
    $state['phase'] = 'completed';
    $state['winnerUserId'] = $winnerUserId;
    $state['terminalReason'] = 'first-to-500';
    $resultScores = [];
    foreach ($state['turnOrder'] as $userId) $resultScores[(string)(int)$userId] = (int)$userId === $winnerUserId ? 1 : 0;
    return ocx_game_result_from_scores($resultScores);
}

function uno_project_state(array $state, int $viewerUserId, array $context): array
{
    $turnOrder = array_values(array_unique(array_filter(
        array_map('intval', (array)($state['turnOrder'] ?? [])),
        static fn(int $userId): bool => $userId > 0 || isset($state['bots'][(string)$userId])
    )));
    if ($turnOrder === []) {
        $turnOrder = array_values(array_unique(array_filter(array_map(
            static fn(array $member): int => in_array((string)($member['role'] ?? ''), ['master', 'player'], true)
                && (string)($member['membershipStatus'] ?? '') === 'active'
                    ? (int)($member['userId'] ?? 0)
                    : 0,
            (array)($context['members'] ?? [])
        ))));
    }
    if ($turnOrder === [] && $viewerUserId > 0) $turnOrder[] = $viewerUserId;
    $playerKeys = array_map('strval', $turnOrder);
    $state = array_replace([
        'schemaVersion' => UNO_STATE_SCHEMA_VERSION,
        'playerCount' => count($turnOrder),
        'turnOrder' => $turnOrder,
        'turnIndex' => 0,
        'dealerIndex' => 0,
        'dealerUserId' => (int)($turnOrder[0] ?? 0),
        'direction' => 1,
        'phase' => 'lobby',
        'handNumber' => 0,
        'targetScore' => 500,
        'hands' => array_fill_keys($playerKeys, []),
        'scores' => array_fill_keys($playerKeys, 0),
        'drawPile' => [],
        'discardPile' => [],
        'currentColor' => null,
        'reshuffleSeed' => '',
        'reshuffleCount' => 0,
        'drawnCardId' => null,
        'pendingChallenge' => null,
        'unoDeclared' => array_fill_keys($playerKeys, false),
        'unoCatchUserId' => null,
        'playSequence' => 0,
        'lastAction' => null,
        'lastRoundResult' => null,
        'history' => [],
        'starterUserId' => (int)($turnOrder[0] ?? 0),
        'starterReason' => 'Waiting for two through ten players and final acceptance.',
        'completed' => false,
        'winnerUserId' => null,
        'terminalReason' => null,
    ], $state);
    $projection = $state;
    $projection['legalCards'] = uno_legal_cards($state, $viewerUserId);
    $projection['legalActions'] = [];
    $viewerTurn = uno_turn_user($state) === $viewerUserId;
    $phase = (string)$state['phase'];
    if ($viewerTurn && empty($state['completed'])) {
        if ($phase === 'deal') $projection['legalActions'][] = 'deal';
        if ($phase === 'opening-color') $projection['legalActions'][] = 'choose-color';
        if ($phase === 'playing') {
            $projection['legalActions'][] = 'draw';
            if ($projection['legalCards'] !== []) $projection['legalActions'][] = 'play';
            if (count((array)$state['hands'][(string)$viewerUserId]) === 2 && $projection['legalCards'] !== []) $projection['legalActions'][] = 'call-uno';
        }
        if ($phase === 'drawn') {
            if (count((array)$state['hands'][(string)$viewerUserId]) === 2 && $projection['legalCards'] !== []) $projection['legalActions'][] = 'call-uno';
            $projection['legalActions'][] = 'pass';
            if ($projection['legalCards'] !== []) $projection['legalActions'][] = 'play';
        }
        if ($phase === 'challenge' && (int)($state['pendingChallenge']['targetUserId'] ?? 0) === $viewerUserId) {
            $projection['legalActions'][] = 'accept-draw-four';
            $projection['legalActions'][] = 'challenge-draw-four';
        }
        if ($phase === 'round-complete') $projection['legalActions'][] = 'next-hand';
    }
    if ((int)($state['unoCatchUserId'] ?? 0) !== 0 && (int)$state['unoCatchUserId'] !== $viewerUserId && empty($state['completed'])) {
        $projection['legalActions'][] = 'catch-uno';
    }
    foreach ((array)$projection['hands'] as $userId => $hand) {
        if ((int)$userId === $viewerUserId) continue;
        $projection['hands'][$userId] = ['private' => true, 'count' => count((array)$hand)];
    }
    $projection['cardCounts'] = [];
    foreach ((array)$state['hands'] as $userId => $hand) $projection['cardCounts'][$userId] = count((array)$hand);
    $projection['drawPile'] = ['private' => true, 'count' => count((array)$state['drawPile'])];
    $projection['topDiscard'] = (string)(array_slice((array)$state['discardPile'], -1)[0] ?? '');
    $projection['discardCount'] = count((array)$state['discardPile']);
    $projection['pendingChallenge'] = is_array($state['pendingChallenge']) ? [
        'offenderUserId' => (int)$state['pendingChallenge']['offenderUserId'],
        'targetUserId' => (int)$state['pendingChallenge']['targetUserId'],
    ] : null;
    $projection['drawnCardId'] = uno_turn_user($state) === $viewerUserId ? $state['drawnCardId'] : null;
    $projection['botTask'] = uno_bot_task($state, $viewerUserId, $context);
    unset($projection['reshuffleSeed']);
    return $projection;
}

function uno_action_result(array $state): array
{
    return ['state' => $state, 'turnUserId' => uno_turn_user($state)];
}

function uno_apply_action_core(array $state, int $actorUserId, string $action, array $payload, array $context): array
{
    if ((int)($state['schemaVersion'] ?? 0) !== UNO_STATE_SCHEMA_VERSION || !empty($state['completed'])) {
        throw new MultiplayerGameException('The UNO state is unavailable.', 'UNO_STATE_INVALID', 409);
    }
    if (!in_array($actorUserId, array_map('intval', $state['turnOrder']), true)) {
        throw new MultiplayerGameException('Only a player may act.', 'UNO_PLAYER_INVALID', 403);
    }
    if ($action === 'resign') {
        $scores = [];
        foreach ($state['turnOrder'] as $userId) $scores[(string)(int)$userId] = (int)$userId === $actorUserId ? 0 : 1;
        $state['completed'] = true; $state['phase'] = 'completed'; $state['terminalReason'] = 'resignation';
        return ['state' => $state, 'turnUserId' => null, 'terminal' => true, 'result' => ocx_game_result_from_scores($scores)];
    }
    if ($action === 'catch-uno') {
        $target = (int)($state['unoCatchUserId'] ?? 0);
        if ($target === 0 || $target === $actorUserId) throw new MultiplayerGameException('There is no UNO omission to catch.', 'UNO_CATCH_INVALID', 409);
        uno_draw_cards($state, $target, 2);
        $state['unoCatchUserId'] = null;
        $state['playSequence'] = (int)$state['playSequence'] + 1;
        $state['lastAction'] = ['sequence' => $state['playSequence'], 'type' => 'catch-uno', 'userId' => $actorUserId, 'targetUserId' => $target, 'drawCount' => 2];
        return uno_action_result($state);
    }
    if ($action === 'deal') {
        if ((string)$state['phase'] !== 'deal') throw new MultiplayerGameException('A deal is not available now.', 'UNO_DEAL_INVALID', 409);
        ocx_game_assert_turn($state, $actorUserId);
        uno_begin_hand($state, (array)($context['authoritativeRandomness'] ?? []));
        return uno_action_result($state);
    }
    if ($action === 'next-hand') {
        if ((string)$state['phase'] !== 'round-complete') throw new MultiplayerGameException('The next hand is not available yet.', 'UNO_NEXT_HAND_INVALID', 409);
        ocx_game_assert_turn($state, $actorUserId);
        $state['dealerIndex'] = ((int)$state['dealerIndex'] + 1) % count($state['turnOrder']);
        $state['dealerUserId'] = (int)$state['turnOrder'][(int)$state['dealerIndex']];
        $state['direction'] = 1;
        $state['turnIndex'] = ((int)$state['dealerIndex'] + 1) % count($state['turnOrder']);
        $state['phase'] = 'deal';
        return uno_action_result($state);
    }
    if ($action === 'choose-color') {
        if ((string)$state['phase'] !== 'opening-color') throw new MultiplayerGameException('An opening color is not being selected.', 'UNO_COLOR_INVALID', 409);
        ocx_game_assert_turn($state, $actorUserId);
        $color = strtoupper(trim((string)($payload['color'] ?? '')));
        if (!in_array($color, ['R', 'Y', 'G', 'B'], true)) throw new MultiplayerGameException('Choose red, yellow, green, or blue.', 'UNO_COLOR_INVALID', 422);
        $state['currentColor'] = $color; $state['phase'] = 'playing';
        $state['playSequence'] = (int)$state['playSequence'] + 1;
        $state['lastAction'] = ['sequence' => $state['playSequence'], 'type' => 'choose-color', 'userId' => $actorUserId, 'color' => $color, 'drawCount' => 0];
        return uno_action_result($state);
    }
    if ($action === 'call-uno') {
        if (!in_array((string)$state['phase'], ['playing','drawn'], true)) throw new MultiplayerGameException('UNO cannot be called now.', 'UNO_DECLARE_INVALID', 409);
        ocx_game_assert_turn($state, $actorUserId);
        if (count((array)$state['hands'][(string)$actorUserId]) !== 2 || uno_legal_cards($state, $actorUserId) === []) throw new MultiplayerGameException('Call UNO when exactly two cards remain and one can be played.', 'UNO_DECLARE_INVALID', 422);
        $state['unoDeclared'][(string)$actorUserId] = true;
        $state['playSequence'] = (int)$state['playSequence'] + 1;
        $state['lastAction'] = ['sequence' => $state['playSequence'], 'type' => 'call-uno', 'userId' => $actorUserId, 'drawCount' => 0];
        return uno_action_result($state);
    }
    if (in_array($action, ['accept-draw-four', 'challenge-draw-four'], true)) {
        if ((string)$state['phase'] !== 'challenge' || !is_array($state['pendingChallenge'])) throw new MultiplayerGameException('There is no Wild Draw Four decision.', 'UNO_CHALLENGE_INVALID', 409);
        ocx_game_assert_turn($state, $actorUserId);
        $challenge = $state['pendingChallenge'];
        if ((int)$challenge['targetUserId'] !== $actorUserId) throw new MultiplayerGameException('Only the affected player may decide.', 'UNO_CHALLENGE_INVALID', 403);
        $state['unoCatchUserId'] = null;
        $offender = (int)$challenge['offenderUserId'];
        $successful = $action === 'challenge-draw-four' && !empty($challenge['illegal']);
        $drawTarget = $successful ? $offender : $actorUserId;
        $drawCount = $successful ? 4 : ($action === 'challenge-draw-four' ? 6 : 4);
        uno_draw_cards($state, $drawTarget, $drawCount);
        $state['pendingChallenge'] = null;
        $state['phase'] = 'playing';
        if ($successful) {
            $state['turnIndex'] = (int)array_search($actorUserId, array_map('intval', $state['turnOrder']), true);
        } else {
            $state['turnIndex'] = uno_advance_index($state, (int)array_search($actorUserId, array_map('intval', $state['turnOrder']), true), 1);
        }
        $state['playSequence'] = (int)$state['playSequence'] + 1;
        $state['lastAction'] = ['sequence' => $state['playSequence'], 'type' => $action, 'userId' => $actorUserId, 'targetUserId' => $drawTarget, 'drawCount' => $drawCount, 'successful' => $successful];
        $roundWinner = (int)($challenge['roundWinnerUserId'] ?? 0);
        if ($roundWinner !== 0) {
            $result = uno_complete_round($state, $roundWinner);
            if ($result !== null) return ['state' => $state, 'turnUserId' => null, 'terminal' => true, 'result' => $result];
            return ['state' => $state, 'turnUserId' => $roundWinner];
        }
        return uno_action_result($state);
    }
    if (!in_array($action, ['draw', 'pass', 'play'], true)) throw new MultiplayerGameException('This UNO action is not supported.', 'UNO_ACTION_INVALID', 422);
    ocx_game_assert_turn($state, $actorUserId);
    if ((int)($state['unoCatchUserId'] ?? 0) !== 0) $state['unoCatchUserId'] = null;
    if ($action === 'draw') {
        if ((string)$state['phase'] !== 'playing') throw new MultiplayerGameException('A card cannot be drawn now.', 'UNO_DRAW_INVALID', 409);
        $state['unoDeclared'][(string)$actorUserId] = false;
        $drawn = uno_draw_cards($state, $actorUserId, 1)[0];
        $state['playSequence'] = (int)$state['playSequence'] + 1;
        $state['lastAction'] = ['sequence' => $state['playSequence'], 'type' => 'draw', 'userId' => $actorUserId, 'drawCount' => 1];
        if (uno_card_playable($drawn, $state)) {
            $state['phase'] = 'drawn'; $state['drawnCardId'] = $drawn;
        } else {
            $state['turnIndex'] = uno_advance_index($state, (int)$state['turnIndex'], 1);
        }
        return uno_action_result($state);
    }
    if ($action === 'pass') {
        if ((string)$state['phase'] !== 'drawn') throw new MultiplayerGameException('There is no drawn card to keep.', 'UNO_PASS_INVALID', 409);
        $state['drawnCardId'] = null; $state['phase'] = 'playing';
        $state['turnIndex'] = uno_advance_index($state, (int)$state['turnIndex'], 1);
        $state['playSequence'] = (int)$state['playSequence'] + 1;
        $state['lastAction'] = ['sequence' => $state['playSequence'], 'type' => 'pass', 'userId' => $actorUserId, 'drawCount' => 0];
        return uno_action_result($state);
    }
    if (!in_array((string)$state['phase'], ['playing', 'drawn'], true)) throw new MultiplayerGameException('A card cannot be played now.', 'UNO_PLAY_INVALID', 409);
    $card = trim((string)($payload['card'] ?? ''));
    uno_card_parts($card);
    if (!in_array($card, uno_legal_cards($state, $actorUserId), true)) throw new MultiplayerGameException('That card is not a legal play.', 'UNO_PLAY_INVALID', 422);
    $parts = uno_card_parts($card);
    $chosenColor = strtoupper(trim((string)($payload['color'] ?? '')));
    if ($parts['color'] === 'W' && !in_array($chosenColor, ['R', 'Y', 'G', 'B'], true)) throw new MultiplayerGameException('Choose the continuing color for the Wild card.', 'UNO_COLOR_INVALID', 422);
    $currentColor = (string)($state['currentColor'] ?? '');
    $hadMatchingColor = false;
    if ($parts['symbol'] === 'D4' && $currentColor !== '') {
        foreach ((array)$state['hands'][(string)$actorUserId] as $held) {
            if ((string)$held === $card) continue;
            if (uno_card_parts((string)$held)['color'] === $currentColor) { $hadMatchingColor = true; break; }
        }
    }
    $hand = array_values((array)$state['hands'][(string)$actorUserId]);
    $removeIndex = array_search($card, $hand, true);
    array_splice($hand, (int)$removeIndex, 1);
    $state['hands'][(string)$actorUserId] = $hand;
    $state['discardPile'][] = $card;
    $state['currentColor'] = $parts['color'] === 'W' ? $chosenColor : $parts['color'];
    $state['drawnCardId'] = null; $state['phase'] = 'playing';
    $remaining = count($hand);
    if ($remaining === 1) {
        $state['unoCatchUserId'] = !empty($state['unoDeclared'][(string)$actorUserId]) ? null : $actorUserId;
    }
    $state['unoDeclared'][(string)$actorUserId] = false;
    $actorIndex = (int)$state['turnIndex'];
    $drawCount = 0;
    if ($parts['symbol'] === 'R') {
        $state['direction'] = (int)$state['direction'] * -1;
        $state['turnIndex'] = uno_advance_index($state, $actorIndex, count($state['turnOrder']) === 2 ? 2 : 1);
    } elseif ($parts['symbol'] === 'S') {
        $state['turnIndex'] = uno_advance_index($state, $actorIndex, 2);
    } elseif ($parts['symbol'] === 'D2') {
        $targetIndex = uno_advance_index($state, $actorIndex, 1);
        uno_draw_cards($state, (int)$state['turnOrder'][$targetIndex], 2);
        $drawCount = 2;
        $state['turnIndex'] = uno_advance_index($state, $actorIndex, 2);
    } elseif ($parts['symbol'] === 'D4') {
        $targetIndex = uno_advance_index($state, $actorIndex, 1);
        $state['pendingChallenge'] = [
            'offenderUserId' => $actorUserId,
            'targetUserId' => (int)$state['turnOrder'][$targetIndex],
            'illegal' => $hadMatchingColor,
            'roundWinnerUserId' => $remaining === 0 ? $actorUserId : null,
        ];
        $state['phase'] = 'challenge';
        $state['turnIndex'] = $targetIndex;
    } else {
        $state['turnIndex'] = uno_advance_index($state, $actorIndex, 1);
    }
    $state['playSequence'] = (int)$state['playSequence'] + 1;
    $state['lastAction'] = ['sequence' => $state['playSequence'], 'type' => 'play', 'userId' => $actorUserId, 'card' => $card, 'color' => $state['currentColor'], 'drawCount' => $drawCount];
    if ($remaining === 0 && $parts['symbol'] !== 'D4') {
        $result = uno_complete_round($state, $actorUserId);
        if ($result !== null) return ['state' => $state, 'turnUserId' => null, 'terminal' => true, 'result' => $result];
        return ['state' => $state, 'turnUserId' => $actorUserId];
    }
    return uno_action_result($state);
}
