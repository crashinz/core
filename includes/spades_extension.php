<?php
declare(strict_types=1);

require_once __DIR__ . '/ocx_game_extension_support.php';
require_once __DIR__ . '/spades_bot_support.php';

const SPADES_EXTENSION_ID = 'spades';
const SPADES_STATE_SCHEMA_VERSION = 2;
const SPADES_SETTINGS_SCHEMA_VERSION = 7;

function spades_recording_adapter(): array
{
    return ['schemaVersion' => 1,
        'stateKeys' => ['bids', 'blindSelections', 'bots', 'completed', 'currentTrick', 'dealerIndex', 'dealerUserId', 'handNumber', 'handViewed', 'hands', 'history', 'initialDealerPending', 'lastCompletedTrick', 'leaderIndex', 'meaningfulPlay', 'partnerPasses', 'partnershipCommitments', 'pendingPartnershipCommitment', 'phase', 'playSequence', 'resignedUserId', 'roundNumber', 'schemaVersion', 'settings', 'settlement', 'spadesBroken', 'starterIndex', 'starterReason', 'starterUserId', 'teamBags', 'teamBidHints', 'teamBids', 'teamScores', 'terminalReason', 'tricksWon', 'turnIndex', 'turnOrder', 'winningTeam'],
        'payloadKeys' => ['accept', 'amount', 'card', 'cards', 'kind', 'returnCards']];
}

function spades_extension_adapter(): array
{
    return [
        'recordingAdapter' => 'spades_recording_adapter',
        'id' => SPADES_EXTENSION_ID,
        'initialState' => 'spades_initial_state',
        'applyAction' => 'spades_apply_action',
        'validateSettings' => 'spades_validate_settings',
        'settingsProjection' => 'spades_settings_projection',
        'rulesProjection' => 'spades_rules_projection',
        'projectState' => 'spades_project_state',
        'projectVirtualMembers' => 'spades_project_virtual_members',
        'randomnessPurposes' => ['deal' => 'spades-deal'],
        'deriveRandomness' => 'spades_derive_randomness',
        'presentationStatus' => 'spades_presentation_status',
        'openingProcedure' => 'verified-initial-dealer-then-clockwise-hand-and-rematch-rotation',
        'rematchSeatRotation' => true,
    ];
}

function spades_presentation_status(PDO $pdo, ?string $requestedPack = null): array
{
    return ocx_game_presentation_status($pdo, SPADES_EXTENSION_ID, $requestedPack);
}

function spades_validate_settings(array $settings, string $mode, array $definition = []): array
{
    $allowed = [
        'winningScore', 'nilBidScore', 'blindNilScore', 'doubleNilScore',
        'doubleBlindNilScore', 'nilTricksCountTowardTeamBid',
        'blindNilEligibilityDifference', 'scoringSchemaVersion',
        'regularNilOneCardExchange', 'exchangeMode',
        'minimumTeamBid', 'deckVariant', 'tenForTwoHundred', 'bostonBonus',
        'bagRule', 'legacyBackDoorEnding',
        'botSeat2Difficulty', 'botSeat3Difficulty', 'botSeat4Difficulty',
    ];
    if (array_diff(array_keys($settings), $allowed)) throw new MultiplayerGameException('A Spades setting is not supported.', 'SPADES_SETTINGS_INVALID', 422);
    $legacy = $settings !== []
        && !array_key_exists('scoringSchemaVersion', $settings)
        && !array_key_exists('nilBidScore', $settings)
        && !array_key_exists('blindNilScore', $settings)
        && !array_key_exists('doubleNilScore', $settings)
        && !array_key_exists('doubleBlindNilScore', $settings);
    $winning = (int)($settings['winningScore'] ?? 500);
    if ($winning < 100 || $winning > 2000 || $winning % 50 !== 0) throw new MultiplayerGameException('Winning score must be from 100 to 2000 in steps of 50.', 'SPADES_WINNING_SCORE_INVALID', 422);
    $minimumTeamBid = (int)($settings['minimumTeamBid'] ?? 0);
    if (!in_array($minimumTeamBid, [0, 2, 3, 4, 5], true)) throw new MultiplayerGameException('Minimum team bid must be None, 2, 3, 4, or 5.', 'SPADES_MINIMUM_TEAM_BID_INVALID', 422);
    $deckVariant = strtolower(trim((string)($settings['deckVariant'] ?? 'ace-high')));
    if (!in_array($deckVariant, ['ace-high', 'joker-joker-ace'], true)) throw new MultiplayerGameException('Choose Ace-High or Joker-Joker-Ace.', 'SPADES_DECK_VARIANT_INVALID', 422);
    $tenForTwoHundred = $settings['tenForTwoHundred'] ?? false;
    if (!is_bool($tenForTwoHundred) && !in_array($tenForTwoHundred, [0, 1, '0', '1'], true)) throw new MultiplayerGameException('Choose whether Ten for 200 is enabled.', 'SPADES_TEN_FOR_TWO_HUNDRED_INVALID', 422);
    $bostonBonus = $settings['bostonBonus'] ?? false;
    if (!is_bool($bostonBonus) && !in_array($bostonBonus, [0, 1, '0', '1'], true)) throw new MultiplayerGameException('Choose whether the Boston bonus is enabled.', 'SPADES_BOSTON_BONUS_INVALID', 422);
    $bagRule = strtolower(trim((string)($settings['bagRule'] ?? 'ten-minus-100')));
    if (!in_array($bagRule, ['ten-minus-100', 'five-minus-50', 'minus-10-each', 'no-penalty', 'no-bag-scoring'], true)) throw new MultiplayerGameException('Choose a supported overtrick and bag rule.', 'SPADES_BAG_RULE_INVALID', 422);
    $legacyBackDoorEnding = $settings['legacyBackDoorEnding'] ?? false;
    if (!is_bool($legacyBackDoorEnding) && !in_array($legacyBackDoorEnding, [0, 1, '0', '1'], true)) throw new MultiplayerGameException('Choose whether the Legacy OCX back-door ending is enabled.', 'SPADES_BACK_DOOR_ENDING_INVALID', 422);
    $nilCounts = $settings['nilTricksCountTowardTeamBid'] ?? false;
    if (!is_bool($nilCounts) && !in_array($nilCounts, [0, 1, '0', '1'], true)) throw new MultiplayerGameException('Choose whether Nil tricks count toward the team bid.', 'SPADES_NIL_SETTING_INVALID', 422);
    $regularNilExchange = $settings['regularNilOneCardExchange'] ?? false;
    if (!is_bool($regularNilExchange) && !in_array($regularNilExchange, [0, 1, '0', '1'], true)) throw new MultiplayerGameException('Choose whether regular Nil uses a one-card exchange.', 'SPADES_REGULAR_NIL_EXCHANGE_INVALID', 422);
    $nil = (int)($settings['nilBidScore'] ?? ($legacy ? 100 : 50));
    $blind = (int)($settings['blindNilScore'] ?? ($legacy ? 200 : 100));
    $double = (int)($settings['doubleNilScore'] ?? 200);
    $doubleBlind = (int)($settings['doubleBlindNilScore'] ?? 400);
    if (!in_array($nil, [50, 100], true)) throw new MultiplayerGameException('Nil Bid score must be 50 or 100.', 'SPADES_NIL_SCORE_INVALID', 422);
    if (!in_array($blind, [100, 200], true)) throw new MultiplayerGameException('Blind Nil score must be 100 or 200.', 'SPADES_BLIND_NIL_SCORE_INVALID', 422);
    if (!in_array($double, [100, 200], true)) throw new MultiplayerGameException('Double Nil score must be 100 or 200.', 'SPADES_DOUBLE_NIL_SCORE_INVALID', 422);
    if (!in_array($doubleBlind, [200, 400], true)) throw new MultiplayerGameException('Double Blind Nil score must be 200 or 400.', 'SPADES_DOUBLE_BLIND_NIL_SCORE_INVALID', 422);
    $eligibility = (int)($settings['blindNilEligibilityDifference'] ?? 100);
    if ($eligibility !== 100) throw new MultiplayerGameException('Blind Nil is offered at a 100-point score difference.', 'SPADES_BLIND_NIL_THRESHOLD_INVALID', 422);
    $hasExplicitExchangeMode = array_key_exists('exchangeMode', $settings);
    $hasExplicitRegularNilExchange = array_key_exists('regularNilOneCardExchange', $settings);
    $exchangeMode = strtolower(trim((string)($settings['exchangeMode'] ?? '')));
    if (!$hasExplicitExchangeMode) {
        // Existing persisted settings predate the exchange choice and must
        // retain the then-current Modern accept/decline behavior. A genuinely
        // fresh or current-schema settings package receives the Legacy default.
        $exchangeMode = $settings === [] || $hasExplicitRegularNilExchange ? 'legacy' : 'modern';
    }
    if (!in_array($exchangeMode, ['legacy', 'modern'], true)) {
        throw new MultiplayerGameException('Choose a supported two-card exchange mode.', 'SPADES_EXCHANGE_MODE_INVALID', 422);
    }
    $hasCurrentVariantSetting = array_intersect([
        'minimumTeamBid', 'deckVariant', 'tenForTwoHundred', 'bostonBonus',
        'bagRule', 'legacyBackDoorEnding',
    ], array_keys($settings)) !== [];
    $usesCurrentSettings = $hasExplicitExchangeMode || $hasExplicitRegularNilExchange || $hasCurrentVariantSetting || $settings === [];
    $schema = (int)($settings['scoringSchemaVersion'] ?? ($usesCurrentSettings ? SPADES_SETTINGS_SCHEMA_VERSION : ($legacy ? 1 : 2)));
    if (!in_array($schema, range(1, SPADES_SETTINGS_SCHEMA_VERSION), true)) throw new MultiplayerGameException('The Spades scoring settings version is unavailable.', 'SPADES_SETTINGS_SCHEMA_INVALID', 422);
    $botDifficulties = [];
    if ($mode === 'practice') {
        foreach ([2, 3, 4] as $seat) {
            $key = 'botSeat' . $seat . 'Difficulty';
            $difficulty = strtolower(trim((string)($settings[$key] ?? 'normal')));
            if (!in_array($difficulty, ['none', 'normal', 'expert'], true)) throw new MultiplayerGameException('Choose None, Normal, or Expert for each Practice bot seat.', 'SPADES_BOT_DIFFICULTY_INVALID', 422);
            $botDifficulties[$key] = $difficulty;
        }
    }
    return array_merge([
        'winningScore' => $winning,
        'nilBidScore' => $nil,
        'blindNilScore' => $blind,
        'doubleNilScore' => $double,
        'doubleBlindNilScore' => $doubleBlind,
        'nilTricksCountTowardTeamBid' => filter_var($nilCounts, FILTER_VALIDATE_BOOLEAN),
        'blindNilEligibilityDifference' => 100,
        'scoringSchemaVersion' => $usesCurrentSettings ? SPADES_SETTINGS_SCHEMA_VERSION : $schema,
        'regularNilOneCardExchange' => filter_var($regularNilExchange, FILTER_VALIDATE_BOOLEAN),
        'exchangeMode' => $exchangeMode,
        'minimumTeamBid' => $minimumTeamBid,
        'deckVariant' => $deckVariant,
        'tenForTwoHundred' => filter_var($tenForTwoHundred, FILTER_VALIDATE_BOOLEAN),
        'bostonBonus' => filter_var($bostonBonus, FILTER_VALIDATE_BOOLEAN),
        'bagRule' => $bagRule,
        'legacyBackDoorEnding' => filter_var($legacyBackDoorEnding, FILTER_VALIDATE_BOOLEAN),
    ], $botDifficulties);
}

function spades_settings_projection(array $settings, string $mode, array $definition = []): array
{
    $settings = spades_validate_settings($settings, $mode, $definition);
    $controls = [
        ['key' => 'winningScore', 'type' => 'stepper', 'value' => $settings['winningScore'], 'defaultValue' => 500, 'label' => 'Winning Score', 'description' => 'Choose 100 through 2000 in steps of 50. New games default to 500.', 'minimum' => 100, 'maximum' => 2000, 'step' => 50, 'shortcuts' => [['value' => 250, 'label' => '250'], ['value' => 500, 'label' => '500']]],
        ['key' => 'minimumTeamBid', 'type' => 'select', 'value' => $settings['minimumTeamBid'], 'defaultValue' => 0, 'label' => 'Minimum team bid (Board)', 'description' => 'None keeps the current rules. Board 2 through Board 5 require the partners\' combined standard bid to reach that amount. A matching Double Nil or Double Blind Nil partnership is exempt.', 'options' => [['value' => 0, 'label' => 'None'], ['value' => 2, 'label' => 'Board 2'], ['value' => 3, 'label' => 'Board 3'], ['value' => 4, 'label' => 'Board 4'], ['value' => 5, 'label' => 'Board 5']]],
        ['key' => 'deckVariant', 'type' => 'select', 'value' => $settings['deckVariant'], 'defaultValue' => 'ace-high', 'label' => 'Deck', 'description' => 'Ace-High uses the standard 52-card deck. Joker-Joker-Ace adds illustrated Big and Little Jokers above the Ace of Spades and removes the 2 of Clubs and 2 of Hearts to keep 52 cards.', 'options' => [['value' => 'ace-high', 'label' => 'Ace-High (standard)'], ['value' => 'joker-joker-ace', 'label' => 'Joker-Joker-Ace']]],
        ['key' => 'nilBidScore', 'type' => 'select', 'value' => $settings['nilBidScore'], 'defaultValue' => 50, 'label' => 'Nil Bid Score', 'description' => 'Choose 50 or 100. New games default to 50.', 'options' => [['value' => 50, 'label' => '50'], ['value' => 100, 'label' => '100']]],
        ['key' => 'blindNilScore', 'type' => 'select', 'value' => $settings['blindNilScore'], 'defaultValue' => 100, 'label' => 'Blind Nil Score', 'description' => 'Choose 100 or 200. Blind Nil is offered only when the team trails by at least 100.', 'options' => [['value' => 100, 'label' => '100'], ['value' => 200, 'label' => '200']]],
        ['key' => 'doubleNilScore', 'type' => 'select', 'value' => $settings['doubleNilScore'], 'defaultValue' => 200, 'label' => 'Double Nil Score', 'description' => 'Both partners commit to Nil. Choose 100 or 200; new games default to 200.', 'options' => [['value' => 100, 'label' => '100'], ['value' => 200, 'label' => '200']]],
        ['key' => 'doubleBlindNilScore', 'type' => 'select', 'value' => $settings['doubleBlindNilScore'], 'defaultValue' => 400, 'label' => 'Double Blind Nil Score', 'description' => 'Both partners commit before either sees a hand. Choose 200 or 400; new games default to 400.', 'options' => [['value' => 200, 'label' => '200'], ['value' => 400, 'label' => '400']]],
        ['key' => 'nilTricksCountTowardTeamBid', 'type' => 'select', 'value' => $settings['nilTricksCountTowardTeamBid'] ? 1 : 0, 'defaultValue' => 0, 'label' => 'Nil tricks count toward the team bid', 'description' => 'Choose Yes or No with rectangular buttons. When No, Nil tricks do not satisfy the team contract but remain part of accurate overtrick and bag accounting.', 'options' => [['value' => 1, 'label' => 'Yes'], ['value' => 0, 'label' => 'No']]],
        ['key' => 'regularNilOneCardExchange', 'type' => 'select', 'value' => $settings['regularNilOneCardExchange'] ? 1 : 0, 'defaultValue' => 0, 'label' => 'Regular Nil one-card exchange', 'description' => 'Off by default. When enabled, a regular Nil bidder and partner must privately exchange exactly one card each way before play. A Blind Nil exchange takes priority and never stacks with this exchange.', 'options' => [['value' => 0, 'label' => 'Disabled'], ['value' => 1, 'label' => 'Enabled']]],
        ['key' => 'exchangeMode', 'type' => 'select', 'value' => $settings['exchangeMode'], 'defaultValue' => 'legacy', 'label' => 'Blind Nil two-card exchange', 'description' => 'Applies only when a player bids Blind Nil. Legacy requires exactly two cards each way; Modern lets the Blind Nil bidder accept with two return cards or decline. Existing games created before this choice retain Modern behavior.', 'options' => [['value' => 'legacy', 'label' => 'Legacy mandatory two-way exchange'], ['value' => 'modern', 'label' => 'Modern accept/decline exchange']]],
        ['key' => 'tenForTwoHundred', 'type' => 'select', 'value' => $settings['tenForTwoHundred'] ? 1 : 0, 'defaultValue' => 0, 'label' => 'Ten for 200', 'description' => 'Off by default. When enabled, an exact combined team contract of 10 scores +200 if made or -200 if set. Extra tricks still follow the selected bag rule.', 'options' => [['value' => 0, 'label' => 'Disabled'], ['value' => 1, 'label' => 'Enabled (+200 / -200)']]],
        ['key' => 'bostonBonus', 'type' => 'select', 'value' => $settings['bostonBonus'] ? 1 : 0, 'defaultValue' => 0, 'label' => 'Boston bonus', 'description' => 'Off by default. When enabled, taking all 13 tricks adds 200 points. It is not an automatic win and can stack with Ten for 200.', 'options' => [['value' => 0, 'label' => 'Disabled'], ['value' => 1, 'label' => 'Enabled (+200)']]],
        ['key' => 'bagRule', 'type' => 'select', 'value' => $settings['bagRule'], 'defaultValue' => 'ten-minus-100', 'label' => 'Overtrick and bag rule', 'description' => 'The standard rule remains the default. Choose a five-bag threshold, a flat -10 per overtrick, overtrick points without a threshold penalty, or no bag scoring.', 'options' => [['value' => 'ten-minus-100', 'label' => '10 bags: -100 (standard)'], ['value' => 'five-minus-50', 'label' => '5 bags: -50'], ['value' => 'minus-10-each', 'label' => '-10 per overtrick'], ['value' => 'no-penalty', 'label' => 'No penalty (+1 each)'], ['value' => 'no-bag-scoring', 'label' => 'No bag scoring']]],
        ['key' => 'legacyBackDoorEnding', 'type' => 'select', 'value' => $settings['legacyBackDoorEnding'] ? 1 : 0, 'defaultValue' => 0, 'label' => 'Back-door ending', 'description' => 'Off by default. The Legacy OCX option ends the game when a team falls strictly below the negative winning-score threshold; the other team wins. If both teams are tied below it, the game is a draw.', 'options' => [['value' => 0, 'label' => 'Disabled'], ['value' => 1, 'label' => 'Enabled (Legacy OCX)']]],
    ];
    if ($mode === 'practice') {
        foreach ([2, 3, 4] as $seat) {
            $key = 'botSeat' . $seat . 'Difficulty';
            $controls[] = ['key' => $key, 'type' => 'select', 'value' => $settings[$key], 'defaultValue' => 'normal', 'label' => 'Empty seat ' . $seat . ' bot', 'description' => 'None keeps the seat open for a person after the host accepts. Normal is an intermediate human-like player; Expert adds public-history inference and expected-trick analysis.', 'options' => [['value' => 'none', 'label' => 'None'], ['value' => 'normal', 'label' => 'Normal'], ['value' => 'expert', 'label' => 'Expert']]];
        }
    }
    return [
        'label' => 'Game Options',
        'description' => $mode === 'practice'
            ? 'The host accepts this complete scoring and play configuration once. Other Practice players join directly, and these values lock when play begins.'
            : 'Every player must accept this complete scoring and play configuration before cards are dealt. These values lock when play begins.',
        'classificationLabel' => 'Accepted Spades options',
        'controls' => $controls,
    ];
}

function spades_rules_projection(array $settings, string $mode, array $definition = []): array
{
    $settings = spades_validate_settings($settings, $mode, $definition);
    $boardRule = (int)$settings['minimumTeamBid'] === 0
        ? 'There is no minimum team contract.'
        : 'Board ' . $settings['minimumTeamBid'] . ' requires each combined non-Nil team contract to be at least ' . $settings['minimumTeamBid'] . '; matching Double Nil and Double Blind Nil are exempt.';
    $deckRule = $settings['deckVariant'] === 'joker-joker-ace'
        ? 'Joker-Joker-Ace is active: Big Joker, Little Joker, Ace of Spades, then the remaining Spades are trump; the 2 of Clubs and 2 of Hearts are removed.'
        : 'The standard Ace-High 52-card deck is active.';
    $bagRule = match ($settings['bagRule']) {
        'five-minus-50' => 'Each made-contract overtrick scores one point; every five accumulated bags costs 50 points.',
        'minus-10-each' => 'Every overtrick costs 10 points immediately and no bag counter accumulates.',
        'no-penalty' => 'Each made-contract overtrick scores one point with no accumulated penalty.',
        'no-bag-scoring' => 'Overtricks add no points and no bag counter or penalty applies.',
        default => 'Each made-contract overtrick scores one point; every 10 accumulated bags costs 100 points.',
    };
    $bonusRule = ($settings['tenForTwoHundred'] ? 'Ten for 200 is enabled: an exact team contract of 10 scores +200 if made or -200 if set. ' : 'Ten for 200 is disabled. ')
        . ($settings['bostonBonus'] ? 'A Boston adds 200 points for taking all 13 tricks.' : 'The Boston bonus is disabled.');
    $endingRule = $settings['legacyBackDoorEnding']
        ? 'The Legacy OCX back-door ending is enabled: falling strictly below the negative target ends the game; a tie below that threshold is a draw.'
        : 'The Legacy OCX back-door ending is disabled.';
    return [
        'label' => 'Spades rules',
        'description' => 'Four players form two fixed teams. Follow the led suit when possible. No Spade or Joker may be played on the first trick. After that, trump may not be led until broken unless a hand contains only trump. The accepted winning score is ' . $settings['winningScore'] . '. Nil scores ' . $settings['nilBidScore'] . ', Blind Nil ' . $settings['blindNilScore'] . ', partnership Double Nil ' . $settings['doubleNilScore'] . ', and partnership Double Blind Nil ' . $settings['doubleBlindNilScore'] . '. Blind Nil is available when a team trails by at least ' . $settings['blindNilEligibilityDifference'] . ' and must be chosen before that bidder sees the hand. ' . $deckRule . ' ' . $bagRule,
        'sections' => [
            ['label' => 'Team bids', 'text' => ($settings['nilTricksCountTowardTeamBid'] ? 'The accepted option counts a Nil bidder’s tricks toward the team contract.' : 'By default, tricks taken by a Nil bidder do not satisfy the team contract; they still count accurately as overtricks for bag accounting.') . ' Each partner makes an individual bid. The first standard bid remains private to that partnership until the partner bids; then both individual bids and the summed team contract become public. Nil and Blind Nil remain explicit individual choices. Matching independent Nil choices derive Double Nil or Double Blind Nil. ' . $boardRule],
            ['label' => 'Deck and trump', 'text' => $deckRule . ' Jokers count as Spades for following suit, breaking trump, Nil, and lead restrictions.'],
            ['label' => 'Contract bonuses and bags', 'text' => $bonusRule . ' ' . $bagRule],
            ['label' => 'Ending rule', 'text' => $endingRule],
            ['label' => 'Nil card exchanges', 'text' => ($settings['regularNilOneCardExchange'] ? 'The accepted regular Nil option requires the Nil bidder and partner to exchange exactly one card in both directions.' : 'Regular Nil does not exchange cards unless its separate one-card option is enabled.') . ' An eligible Blind Nil choice is made before the bidder sees the hand. ' . ($settings['exchangeMode'] === 'legacy' ? 'The accepted Blind Nil Legacy option requires exactly two cards in both directions with no decline.' : 'The accepted Blind Nil Modern option lets the bidder accept with exactly two return cards or decline without changing either hand.') . ' Blind Nil takes priority, so a team never performs both exchanges in one hand.'],
            ['label' => 'Mode', 'text' => $mode === 'recorded' ? 'Recorded deals require four authenticated humans, use server-authoritative randomness, and update Spades-only records.' : 'Practice deals may start with one through four humans. Each empty seat may stay open with None or use the accepted Normal or Expert bot. Play begins when the accepted combination supplies all four seats; committed browser randomness is used, and Recorded records are never updated.'],
            ['label' => 'Dealer and turns', 'text' => 'A new four-player set receives a fair verified initial dealer. The dealer then rotates between hands, and an accepted rematch with the same four players continues that rotation. Bid and play only on your turn. Spectators may inspect public bids, tricks, history, scores, and rules but never private hands or randomness material.'],
        ],
    ];
}

function spades_deck(array $settings = []): array
{
    $cards = [];
    $jokerVariant = (string)($settings['deckVariant'] ?? 'ace-high') === 'joker-joker-ace';
    foreach (['C', 'D', 'H', 'S'] as $suit) {
        foreach (range(2, 14) as $rank) {
            if ($jokerVariant && $rank === 2 && in_array($suit, ['C', 'H'], true)) continue;
            $cards[] = $suit . $rank;
        }
    }
    if ($jokerVariant) array_push($cards, 'JL', 'JB');
    return $cards;
}

function spades_derive_randomness(string $canonicalReveal, string $actionType, array $payload, array $context): array
{
    $dealerBytes = ocx_game_random_bytes($canonicalReveal, 'spades-initial-dealer', 1);
    return [
        'deck' => ocx_game_random_permutation($canonicalReveal, spades_deck((array)($context['settings'] ?? [])), 'spades-deal'),
        'initialDealerIndex' => (int)$dealerBytes[0] % 4,
    ];
}

function spades_initial_state(array $playerUserIds, array $context = []): array
{
    $humanPlayers = array_values(array_unique(array_map('intval', $playerUserIds)));
    $mode = (string)($context['mode'] ?? 'practice');
    if ($humanPlayers === [] || min($humanPlayers) < 1 || count($humanPlayers) > 4) throw new MultiplayerGameException('Spades requires one through four authenticated players.', 'SPADES_PLAYER_SET_INVALID', 422);
    if ($mode === 'recorded' && count($humanPlayers) !== 4) throw new MultiplayerGameException('Recorded Spades requires exactly four authenticated human players.', 'SPADES_RECORDED_PLAYER_SET_INVALID', 422);
    $settings = spades_validate_settings((array)($context['settings'] ?? []), $mode);
    [$players, $bots] = spades_bot_fill_seats($humanPlayers, $settings, $mode);
    if (count($players) !== 4) throw new MultiplayerGameException('More players must join before Spades can start with bot seats set to None.', 'MULTIPLAYER_GAME_MINIMUM_PLAYERS', 409);
    $roundContext = (array)($context['roundContext'] ?? []);
    $previousState = (array)($roundContext['previousState'] ?? []);
    // Spades dealer/round continuity belongs only to the one successor created
    // by unanimous rematch consent. A later unrelated game with the same four
    // accounts is a fresh set and must draw a new initial dealer.
    $rematchContinues = !empty($roundContext['rematchContinues']);
    $advance = $rematchContinues && !empty($roundContext['advancesSeries']);
    $roundNumber = $rematchContinues
        ? max(1, (int)($roundContext['previousRoundNumber'] ?? 1) + ($advance ? 1 : 0))
        : 1;
    $continuesDealer = $rematchContinues
        && array_key_exists('dealerIndex', $previousState)
        && $previousState['dealerIndex'] !== null;
    $dealerIndex = $continuesDealer ? (int)$previousState['dealerIndex'] % 4 : null;
    $dealCoordinatorIndex = $dealerIndex === null ? 0 : ($dealerIndex + 1) % 4;
    if ((int)$players[$dealCoordinatorIndex] < 1) $dealCoordinatorIndex = (int)array_search($humanPlayers[0], $players, true);
    return [
        'schemaVersion' => SPADES_STATE_SCHEMA_VERSION,
        'turnOrder' => $players,
        'turnIndex' => $dealCoordinatorIndex,
        'roundNumber' => $roundNumber,
        'dealerIndex' => $dealerIndex,
        'dealerUserId' => $dealerIndex === null ? null : $players[$dealerIndex],
        'starterUserId' => null,
        'starterReason' => $dealerIndex === null
            ? 'A fair verified draw will select the initial dealer for this player set.'
            : 'Dealer rotation continues from the preceding accepted round with the same four players.',
        'initialDealerPending' => $dealerIndex === null,
        'leaderIndex' => null,
        'phase' => 'deal',
        'settings' => $settings,
        'bots' => $bots,
        'hands' => array_fill_keys(array_map('strval', $players), []),
        'bids' => [],
        'teamBidHints' => [],
        'teamBids' => [],
        'handViewed' => array_fill_keys(array_map('strval', $players), false),
        'blindSelections' => [],
        'partnershipCommitments' => [],
        'pendingPartnershipCommitment' => null,
        'partnerPasses' => [],
        'currentTrick' => [],
        'lastCompletedTrick' => null,
        'settlement' => null,
        'playSequence' => 0,
        'tricksWon' => array_fill_keys(array_map('strval', $players), 0),
        'spadesBroken' => false,
        'teamScores' => ['0' => 0, '1' => 0],
        'teamBags' => ['0' => 0, '1' => 0],
        'handNumber' => 0,
        'history' => [],
        'meaningfulPlay' => false,
        'completed' => false,
    ];
}

function spades_team_index(array $state, int $userId): int
{
    static $teamMaps = [];
    $turnOrder = array_values(array_map('intval', $state['turnOrder']));
    $signature = implode(':', $turnOrder);
    if (!isset($teamMaps[$signature])) {
        $teamMaps[$signature] = [];
        foreach ($turnOrder as $seat => $playerUserId) $teamMaps[$signature][(string)$playerUserId] = (int)$seat % 2;
    }
    if (!array_key_exists((string)$userId, $teamMaps[$signature])) {
        throw new MultiplayerGameException('Only a player may act.', 'SPADES_PLAYER_INVALID', 403);
    }
    return (int)$teamMaps[$signature][(string)$userId];
}

function spades_card_parts(string $card): array
{
    static $cache = [];
    $card = strtoupper($card);
    if (isset($cache[$card])) return $cache[$card];
    if ($card === 'JL') return $cache[$card] = ['suit' => 'S', 'rank' => 15, 'joker' => 'little'];
    if ($card === 'JB') return $cache[$card] = ['suit' => 'S', 'rank' => 16, 'joker' => 'big'];
    if (!preg_match('/^([CDHS])(2|3|4|5|6|7|8|9|10|11|12|13|14)$/', $card, $match)) throw new MultiplayerGameException('Choose a valid card.', 'SPADES_CARD_INVALID', 422);
    return $cache[$card] = ['suit' => $match[1], 'rank' => (int)$match[2], 'joker' => null];
}

function spades_sort_hand(array $cards): array
{
    usort($cards, static function (string $left, string $right): int {
        $suits = ['C' => 0, 'D' => 1, 'H' => 2, 'S' => 3];
        $a = spades_card_parts($left); $b = spades_card_parts($right);
        return [$suits[$a['suit']], $a['rank']] <=> [$suits[$b['suit']], $b['rank']];
    });
    return $cards;
}

function spades_legal_cards(array $state, int $actorUserId): array
{
    if ((string)($state['phase'] ?? '') !== 'playing') return [];
    $turnOrder = array_values(array_map('intval', (array)($state['turnOrder'] ?? [])));
    $turnIndex = (int)($state['turnIndex'] ?? -1);
    if (!isset($turnOrder[$turnIndex]) || $turnOrder[$turnIndex] !== $actorUserId) return [];
    $hand = array_values(array_map('strval', (array)($state['hands'][(string)$actorUserId] ?? [])));
    if ($hand === []) return [];
    $tricksPlayed = array_sum(array_map('intval', (array)($state['tricksWon'] ?? [])));
    $eligible = array_values(array_filter($hand, static function(string $card) use ($tricksPlayed): bool {
        $parts = spades_card_parts($card);
        return $tricksPlayed > 0 || $parts['suit'] !== 'S';
    }));
    $currentTrick = array_values((array)($state['currentTrick'] ?? []));
    if ($currentTrick !== []) {
        $leadSuit = spades_card_parts((string)$currentTrick[0]['card'])['suit'];
        $following = array_values(array_filter(
            $eligible,
            static fn(string $card): bool => spades_card_parts($card)['suit'] === $leadSuit
        ));
        return $following !== [] ? $following : $eligible;
    }
    if (empty($state['spadesBroken'])) {
        $nonSpades = array_values(array_filter(
            $eligible,
            static fn(string $card): bool => spades_card_parts($card)['suit'] !== 'S'
        ));
        if ($nonSpades !== []) return $nonSpades;
    }
    return $eligible;
}

function spades_project_state(array $state, int $viewerUserId, array $context): array
{
    $projection = spades_upgrade_state_settings($state);
    $projection['legalCards'] = spades_legal_cards($projection, $viewerUserId);
    $isPlayer = in_array($viewerUserId, array_map('intval', (array)($projection['turnOrder'] ?? [])), true);
    $viewerTeam = $isPlayer ? spades_team_index($projection, $viewerUserId) : -1;
    if (!is_array($projection['hands'] ?? null)) return $projection;
    foreach ((array)$projection['hands'] as $userId => $hand) {
        if ((int)$userId === $viewerUserId && ((string)($state['phase'] ?? '') !== 'bidding' || !empty($state['handViewed'][(string)$viewerUserId]))) continue;
        $projection['hands'][$userId] = ['private' => true, 'count' => count((array)$hand)];
        if ((int)$userId === $viewerUserId) $projection['hands'][$userId]['notViewed'] = true;
    }
    if ((string)($state['phase'] ?? '') === 'bidding') {
        foreach ((array)$projection['blindSelections'] as $userId => $_value) if ((int)$userId !== $viewerUserId) $projection['blindSelections'][$userId] = 'private';
        $handViewed = !empty($projection['handViewed'][(string)$viewerUserId]);
        $blind = $isPlayer && spades_blind_available($projection, $viewerUserId) && !$handViewed;
        $role = $isPlayer ? spades_bid_role($projection, $viewerUserId) : 'none';
        $hint = $viewerTeam >= 0 ? (array)($projection['teamBidHints'][(string)$viewerTeam] ?? []) : [];
        $partnerBid = [];
        if ($isPlayer && $role === 'team') {
            $partnerUserId = spades_partner_user($projection, $viewerUserId);
            $partnerChoice = (array)($projection['bids'][(string)$partnerUserId] ?? []);
            if ($partnerChoice !== []) {
                $partnerBid = [
                    'fromUserId' => $partnerUserId,
                    'kind' => (string)($partnerChoice['kind'] ?? 'standard'),
                    'amount' => (int)($partnerChoice['amount'] ?? 0),
                ];
            }
        }
        $projection['bidEligibility'] = [
            'standard' => $isPlayer && $handViewed,
            'nil' => $isPlayer && $handViewed && spades_team_bid_candidate_meets_minimum($projection, $viewerUserId, 'nil', 0),
            'blindNil' => $blind && spades_team_bid_candidate_meets_minimum($projection, $viewerUserId, 'blind-nil', 0),
            'role' => $role,
            'blindOffer' => $blind && spades_team_bid_candidate_meets_minimum($projection, $viewerUserId, 'blind-nil', 0),
            'minimumTeamBid' => (int)$projection['settings']['minimumTeamBid'],
            'minimumStandardBid' => $isPlayer ? spades_minimum_standard_bid_for_actor($projection, $viewerUserId) : 1,
            'partnerBid' => $partnerBid !== [] ? $partnerBid : null,
            'partnerHint' => $role === 'team' && $hint !== [] ? [
                'fromUserId' => (int)($hint['fromUserId'] ?? 0),
                'amount' => (int)($hint['amount'] ?? 0),
            ] : null,
        ];
    }
    // The first individual standard bid is private to that partnership until
    // the second partner bids. Once the partnership contract is complete,
    // both individual bids and the summed team contract become public.
    foreach ((array)$projection['teamBidHints'] as $teamKey => $_hint) {
        if ((int)$teamKey !== $viewerTeam) unset($projection['teamBidHints'][$teamKey]);
    }
    foreach ((array)$projection['bids'] as $userId => $bid) {
        if ((string)($bid['role'] ?? '') !== 'hint' || (string)($bid['kind'] ?? '') !== 'standard') continue;
        $bidTeam = spades_team_index($projection, (int)$userId);
        $teamComplete = isset($projection['teamBids'][(string)$bidTeam]);
        if ($bidTeam !== $viewerTeam && !$teamComplete) $projection['bids'][$userId] = ['private' => true];
    }
    foreach ((array)($projection['partnerPasses'] ?? []) as $key => $pass) {
        $visible = [
            'fromUserId' => (int)($pass['fromUserId'] ?? 0),
            'toUserId' => (int)($pass['toUserId'] ?? 0),
            'status' => (string)($pass['status'] ?? 'unknown'),
            'count' => count((array)($pass['cards'] ?? [])),
            'cardCount' => (int)($pass['cardCount'] ?? 2),
            'trigger' => (string)($pass['trigger'] ?? 'blind-nil'),
            'exchangeMode' => (string)($pass['exchangeMode'] ?? $projection['settings']['exchangeMode'] ?? 'modern'),
            'allowDecline' => (bool)($pass['allowDecline'] ?? ((string)($pass['exchangeMode'] ?? 'modern') === 'modern')),
        ];
        if (in_array($viewerUserId, [$visible['fromUserId'], $visible['toUserId']], true)) {
            $visible['cards'] = array_values(array_map('strval', (array)($pass['cards'] ?? [])));
        }
        $projection['partnerPasses'][$key] = $visible;
    }
    return $projection;
}

function spades_begin_hand(array &$state, array $deck, ?int $initialDealerIndex = null): void
{
    if (count($deck) !== 52 || count(array_unique($deck)) !== 52) throw new MultiplayerGameException('The verified deck is invalid.', 'SPADES_DECK_INVALID', 500);
    foreach ($deck as $card) spades_card_parts((string)$card);
    foreach ($state['turnOrder'] as $userId) $state['hands'][(string)$userId] = [];
    foreach ($deck as $index => $card) {
        $userId = (int)$state['turnOrder'][$index % 4];
        $state['hands'][(string)$userId][] = (string)$card;
    }
    foreach ($state['hands'] as &$hand) $hand = spades_sort_hand($hand);
    unset($hand);
    if (!empty($state['initialDealerPending'])) {
        if ($initialDealerIndex === null || $initialDealerIndex < 0 || $initialDealerIndex > 3) {
            throw new MultiplayerGameException('A verified initial dealer is required.', 'SPADES_INITIAL_DEALER_INVALID', 409);
        }
        $state['dealerIndex'] = ($initialDealerIndex + 3) % 4;
        $state['initialDealerPending'] = false;
    }
    $state['dealerIndex'] = ((int)$state['dealerIndex'] + 1) % 4;
    $state['dealerUserId'] = (int)$state['turnOrder'][(int)$state['dealerIndex']];
    $state['turnIndex'] = ((int)$state['dealerIndex'] + 1) % 4;
    $state['leaderIndex'] = (int)$state['turnIndex'];
    $state['starterUserId'] = (int)$state['turnOrder'][(int)$state['leaderIndex']];
    $state['starterReason'] = 'The player after the dealer leads; the dealer rotates for each hand and continues across an accepted rematch.';
    $state['phase'] = 'bidding';
    $state['bids'] = [];
    $state['teamBidHints'] = [];
    $state['teamBids'] = [];
    $state['handViewed'] = [];
    foreach ($state['turnOrder'] as $userId) {
        // Only an actually eligible Blind Nil bidder begins with a concealed
        // hand and the source-backed Show Cards / Bid Blind Nil choice.
        $state['handViewed'][(string)(int)$userId] = !spades_blind_available($state, (int)$userId);
    }
    $state['blindSelections'] = [];
    $state['partnershipCommitments'] = [];
    $state['pendingPartnershipCommitment'] = null;
    $state['partnerPasses'] = [];
    $state['currentTrick'] = [];
    $state['lastCompletedTrick'] = null;
    $state['settlement'] = null;
    $state['tricksWon'] = array_fill_keys(array_map('strval', $state['turnOrder']), 0);
    $state['spadesBroken'] = false;
    $state['handNumber'] = (int)$state['handNumber'] + 1;
    $state['meaningfulPlay'] = true;
}

function spades_blind_available(array $state, int $userId): bool
{
    $team = spades_team_index($state, $userId);
    $other = 1 - $team;
    $threshold = (int)($state['settings']['blindNilEligibilityDifference'] ?? 100);
    return (int)$state['teamScores'][(string)$other] - (int)$state['teamScores'][(string)$team] >= $threshold;
}

function spades_bid_value(string $kind, array $settings): int
{
    return match ($kind) {
        'nil' => (int)$settings['nilBidScore'],
        'blind-nil' => (int)$settings['blindNilScore'],
        'double-nil' => (int)$settings['doubleNilScore'],
        'double-blind-nil' => (int)$settings['doubleBlindNilScore'],
        default => 0,
    };
}

function spades_upgrade_state_settings(array $state): array
{
    $state['settings'] = spades_validate_settings((array)($state['settings'] ?? []), 'practice');
    $state['bids'] = is_array($state['bids'] ?? null) ? $state['bids'] : [];
    $state['teamBidHints'] = is_array($state['teamBidHints'] ?? null) ? $state['teamBidHints'] : [];
    $state['teamBids'] = is_array($state['teamBids'] ?? null) ? $state['teamBids'] : [];
    $state['settlement'] = is_array($state['settlement'] ?? null) ? $state['settlement'] : null;
    $state['playSequence'] = max(0, (int)($state['playSequence'] ?? 0));
    $state['partnerPasses'] = is_array($state['partnerPasses'] ?? null) ? $state['partnerPasses'] : [];
    foreach ($state['partnerPasses'] as $key => $pass) {
        if (!is_array($pass)) {
            unset($state['partnerPasses'][$key]);
            continue;
        }
        $cardCount = (int)($pass['cardCount'] ?? 2);
        $pass['cardCount'] = in_array($cardCount, [1, 2], true) ? $cardCount : 2;
        $pass['trigger'] = (string)($pass['trigger'] ?? 'blind-nil');
        $pass['exchangeMode'] = (string)($pass['exchangeMode'] ?? $state['settings']['exchangeMode'] ?? 'modern');
        $pass['allowDecline'] = array_key_exists('allowDecline', $pass)
            ? filter_var($pass['allowDecline'], FILTER_VALIDATE_BOOLEAN)
            : $pass['trigger'] === 'blind-nil' && $pass['exchangeMode'] === 'modern';
        $state['partnerPasses'][$key] = $pass;
    }
    if ((int)($state['schemaVersion'] ?? 1) < SPADES_STATE_SCHEMA_VERSION) {
        // Convert any pre-release proposal-shaped double bid into two
        // independent Nil choices. A pending proposal becomes the proposer’s
        // committed independent choice; the partner still chooses normally.
        $pending = (array)($state['pendingPartnershipCommitment'] ?? []);
        if ($pending !== []) {
            $proposer = (int)($pending['proposerUserId'] ?? 0);
            $kind = (string)($pending['kind'] ?? '') === 'double-blind-nil' ? 'blind-nil' : 'nil';
            if ($proposer > 0 && !isset($state['bids'][(string)$proposer])) {
                $state['bids'][(string)$proposer] = ['kind' => $kind, 'amount' => 0, 'role' => 'hint'];
                $state['blindSelections'][(string)$proposer] = $kind === 'blind-nil' ? 'blind-nil' : 'none';
            }
        }
        foreach ((array)($state['partnershipCommitments'] ?? []) as $team => $commitment) {
            if ((string)($commitment['status'] ?? '') !== 'accepted') continue;
            $kind = (string)($commitment['kind'] ?? '') === 'double-blind-nil' ? 'blind-nil' : 'nil';
            foreach ((array)($commitment['userIds'] ?? []) as $userId) {
                $state['bids'][(string)(int)$userId] = ['kind' => $kind, 'amount' => 0, 'role' => 'team'];
                $state['blindSelections'][(string)(int)$userId] = $kind === 'blind-nil' ? 'blind-nil' : 'none';
            }
            $state['teamBids'][(string)(int)$team] = [
                'amount' => 0,
                'nilClassification' => $kind === 'blind-nil' ? 'double-blind-nil' : 'double-nil',
                'submittedByUserId' => (int)($commitment['userIds'][1] ?? 0),
            ];
        }
        $state['pendingPartnershipCommitment'] = null;
        $state['partnershipCommitments'] = [];
        $state['schemaVersion'] = SPADES_STATE_SCHEMA_VERSION;
    }
    return $state;
}

function spades_team_users(array $state, int $team): array
{
    return [(int)$state['turnOrder'][$team], (int)$state['turnOrder'][$team + 2]];
}

function spades_bid_role(array $state, int $userId): string
{
    $team = spades_team_index($state, $userId);
    foreach (spades_team_users($state, $team) as $teamUserId) {
        if ($teamUserId !== $userId && isset($state['bids'][(string)$teamUserId])) return 'team';
    }
    return 'hint';
}

function spades_team_bid_candidate_meets_minimum(array $state, int $actorUserId, string $kind, int $amount): bool
{
    $minimum = (int)($state['settings']['minimumTeamBid'] ?? 0);
    if ($minimum === 0) return true;
    $team = spades_team_index($state, $actorUserId);
    $choices = [];
    foreach (spades_team_users($state, $team) as $userId) {
        if ($userId === $actorUserId) {
            $choices[] = ['kind' => $kind, 'amount' => $amount];
            continue;
        }
        if (!isset($state['bids'][(string)$userId])) return true;
        $choices[] = (array)$state['bids'][(string)$userId];
    }
    $kinds = array_map(static fn(array $bid): string => (string)($bid['kind'] ?? ''), $choices);
    if ($kinds === ['nil', 'nil'] || $kinds === ['blind-nil', 'blind-nil']) return true;
    $official = 0;
    foreach ($choices as $bid) if ((string)($bid['kind'] ?? '') === 'standard') $official += (int)($bid['amount'] ?? 0);
    return $official >= $minimum;
}

function spades_minimum_standard_bid_for_actor(array $state, int $actorUserId): int
{
    $minimum = (int)($state['settings']['minimumTeamBid'] ?? 0);
    if ($minimum === 0) return 1;
    $partnerUserId = spades_partner_user($state, $actorUserId);
    $partnerBid = (array)($state['bids'][(string)$partnerUserId] ?? []);
    if ($partnerBid === []) return 1;
    $partnerAmount = (string)($partnerBid['kind'] ?? '') === 'standard' ? (int)($partnerBid['amount'] ?? 0) : 0;
    return max(1, min(13, $minimum - $partnerAmount));
}

function spades_finalize_team_bid(array &$state, int $team): void
{
    $teamUsers = spades_team_users($state, $team);
    $choices = [];
    foreach ($teamUsers as $userId) {
        if (!isset($state['bids'][(string)$userId])) return;
        $choices[] = (array)$state['bids'][(string)$userId];
    }
    $official = 0;
    $submittedBy = 0;
    foreach ($choices as $index => $bid) {
        if ((string)($bid['kind'] ?? '') !== 'standard') continue;
        $official += (int)($bid['amount'] ?? 0);
        if ((string)($bid['role'] ?? '') === 'team' || $submittedBy === 0) $submittedBy = $teamUsers[$index];
    }
    $kinds = array_map(static fn(array $bid): string => (string)($bid['kind'] ?? ''), $choices);
    $nilClassification = null;
    if ($kinds === ['nil', 'nil']) $nilClassification = 'double-nil';
    if ($kinds === ['blind-nil', 'blind-nil']) $nilClassification = 'double-blind-nil';
    $state['teamBids'][(string)$team] = [
        'amount' => $official,
        'submittedByUserId' => $submittedBy,
        'nilClassification' => $nilClassification,
        'individualAmounts' => [
            (string)$teamUsers[0] => (int)($choices[0]['amount'] ?? 0),
            (string)$teamUsers[1] => (int)($choices[1]['amount'] ?? 0),
        ],
        'hintAmount' => isset($state['teamBidHints'][(string)$team]['amount'])
            ? (int)$state['teamBidHints'][(string)$team]['amount'] : null,
    ];
}

function spades_nil_set_by_trick(array $state, int $winnerUserId): bool
{
    $bid = (array)($state['bids'][(string)$winnerUserId] ?? []);
    return in_array((string)($bid['kind'] ?? ''), ['nil', 'blind-nil'], true)
        && (int)($state['tricksWon'][(string)$winnerUserId] ?? 0) === 0;
}

function spades_partner_user(array $state, int $userId): int
{
    $seat = array_search($userId, array_map('intval', $state['turnOrder']), true);
    if ($seat === false) throw new MultiplayerGameException('Only a player may act.', 'SPADES_PLAYER_INVALID', 403);
    return (int)$state['turnOrder'][((int)$seat + 2) % 4];
}

function spades_next_unbid(array &$state, int $fromIndex): ?int
{
    for ($offset = 1; $offset <= 4; $offset++) {
        $index = ($fromIndex + $offset) % 4;
        $userId = (int)$state['turnOrder'][$index];
        if (!isset($state['bids'][(string)$userId])) {
            $state['turnIndex'] = $index;
            return $userId;
        }
    }
    return null;
}

function spades_prepare_partner_passes(array &$state): ?int
{
    $state['partnerPasses'] = [];
    foreach ([0, 1] as $team) {
        $nilUserId = 0;
        $trigger = '';
        foreach (spades_team_users($state, $team) as $userId) {
            $kind = (string)($state['bids'][(string)$userId]['kind'] ?? '');
            if ($kind === 'blind-nil') {
                $nilUserId = $userId;
                $trigger = 'blind-nil';
                break;
            }
            if ($nilUserId === 0 && !empty($state['settings']['regularNilOneCardExchange']) && $kind === 'nil') {
                $nilUserId = $userId;
                $trigger = 'regular-nil';
            }
        }
        if ($nilUserId === 0) continue;
        $partnerId = spades_partner_user($state, $nilUserId);
        $key = (string)$nilUserId;
        $isBlind = $trigger === 'blind-nil';
        $exchangeMode = $isBlind ? (string)$state['settings']['exchangeMode'] : 'legacy';
        $state['partnerPasses'][$key] = [
            'fromUserId' => $partnerId,
            'toUserId' => $nilUserId,
            'status' => 'awaiting-offer',
            'cards' => [],
            'cardCount' => $isBlind ? 2 : 1,
            'trigger' => $trigger,
            'exchangeMode' => $exchangeMode,
            'allowDecline' => $isBlind && $exchangeMode === 'modern',
        ];
    }
    if ($state['partnerPasses'] === []) {
        $state['phase'] = 'playing';
        $state['turnIndex'] = (int)$state['leaderIndex'];
        return (int)$state['turnOrder'][(int)$state['turnIndex']];
    }
    $state['phase'] = 'partner-pass';
    $first = reset($state['partnerPasses']);
    $userId = (int)$first['fromUserId'];
    $state['turnIndex'] = (int)array_search($userId, array_map('intval', $state['turnOrder']), true);
    return $userId;
}

function spades_advance_partner_pass(array &$state): int
{
    foreach ($state['partnerPasses'] as $pass) {
        if ((string)$pass['status'] === 'awaiting-offer') {
            $userId = (int)$pass['fromUserId'];
            $state['turnIndex'] = (int)array_search($userId, array_map('intval', $state['turnOrder']), true);
            return $userId;
        }
        if ((string)$pass['status'] === 'awaiting-response') {
            $userId = (int)$pass['toUserId'];
            $state['turnIndex'] = (int)array_search($userId, array_map('intval', $state['turnOrder']), true);
            return $userId;
        }
    }
    $state['phase'] = 'playing';
    $state['turnIndex'] = (int)$state['leaderIndex'];
    return (int)$state['turnOrder'][(int)$state['turnIndex']];
}

function spades_trick_winner(array $trick): int
{
    $lead = spades_card_parts((string)$trick[0]['card'])['suit'];
    $winner = $trick[0];
    foreach (array_slice($trick, 1) as $play) {
        $card = spades_card_parts((string)$play['card']);
        $best = spades_card_parts((string)$winner['card']);
        if (($card['suit'] === 'S' && $best['suit'] !== 'S')
            || ($card['suit'] === $best['suit'] && $card['rank'] > $best['rank'])
            || ($best['suit'] !== 'S' && $card['suit'] === $lead && $best['suit'] !== $lead)) $winner = $play;
    }
    return (int)$winner['userId'];
}

function spades_contract_score(int $teamBid, bool $madeContract, array $settings): int
{
    if (!empty($settings['tenForTwoHundred']) && $teamBid === 10) return $madeContract ? 200 : -200;
    return $madeContract ? $teamBid * 10 : -$teamBid * 10;
}

function spades_bag_score(int $priorBags, int $overtricks, bool $madeContract, array $settings): array
{
    $rule = (string)($settings['bagRule'] ?? 'ten-minus-100');
    if ($rule === 'minus-10-each') {
        return ['bagPoints' => 0, 'bagPenalty' => -10 * $overtricks, 'bags' => 0];
    }
    if ($rule === 'no-penalty') {
        return ['bagPoints' => $madeContract ? $overtricks : 0, 'bagPenalty' => 0, 'bags' => 0];
    }
    if ($rule === 'no-bag-scoring') {
        return ['bagPoints' => 0, 'bagPenalty' => 0, 'bags' => 0];
    }
    $threshold = $rule === 'five-minus-50' ? 5 : 10;
    $penaltyValue = $rule === 'five-minus-50' ? 50 : 100;
    $bags = $priorBags + $overtricks;
    $penalty = 0;
    while ($bags >= $threshold) {
        $bags -= $threshold;
        $penalty -= $penaltyValue;
    }
    return [
        'bagPoints' => $madeContract ? $overtricks : 0,
        'bagPenalty' => $penalty,
        'bags' => $bags,
    ];
}

function spades_score_hand(array &$state): void
{
    foreach ([0, 1] as $team) {
        $teamUsers = spades_team_users($state, $team);
        $teamBid = (int)($state['teamBids'][(string)$team]['amount'] ?? 0);
        $contractTricks = 0;
        $totalTeamTricks = 0;
        $nilTricks = 0;
        $nilAdjustment = 0;
        $nilChoices = [];
        foreach ($teamUsers as $userId) {
            $bid = (array)($state['bids'][(string)$userId] ?? []);
            $tricks = (int)$state['tricksWon'][(string)$userId];
            $totalTeamTricks += $tricks;
            $kind = (string)($bid['kind'] ?? 'standard');
            if ($kind === 'standard') {
                $contractTricks += $tricks;
            } elseif (in_array($kind, ['nil', 'blind-nil'], true)) {
                $nilChoices[] = ['kind' => $kind, 'tricks' => $tricks];
                $nilTricks += $tricks;
            }
        }
        $derived = (string)($state['teamBids'][(string)$team]['nilClassification'] ?? '');
        if (in_array($derived, ['double-nil', 'double-blind-nil'], true)) {
            $success = array_sum(array_column($nilChoices, 'tricks')) === 0;
            $value = spades_bid_value($derived, $state['settings']);
            $nilAdjustment += $success ? $value : -$value;
        } else {
            foreach ($nilChoices as $choice) {
                $value = spades_bid_value((string)$choice['kind'], $state['settings']);
                $nilAdjustment += (int)$choice['tricks'] === 0 ? $value : -$value;
            }
        }
        if (!empty($state['settings']['nilTricksCountTowardTeamBid'])) $contractTricks += $nilTricks;
        $madeContract = $contractTricks >= $teamBid;
        $bags = max(0, $totalTeamTricks - $teamBid);
        $contractScore = spades_contract_score($teamBid, $madeContract, $state['settings']);
        $bagScore = spades_bag_score((int)$state['teamBags'][(string)$team], $bags, $madeContract, $state['settings']);
        $bagPoints = (int)$bagScore['bagPoints'];
        $bagPenalty = (int)$bagScore['bagPenalty'];
        $state['teamBags'][(string)$team] = (int)$bagScore['bags'];
        $bostonBonus = !empty($state['settings']['bostonBonus']) && $totalTeamTricks === 13 ? 200 : 0;
        $handScore = $contractScore + $bagPoints + $bagPenalty + $bostonBonus;
        $state['teamScores'][(string)$team] = (int)$state['teamScores'][(string)$team] + $handScore + $nilAdjustment;
        $state['history'][] = [
            'hand' => (int)$state['handNumber'],
            'team' => $team,
            'bid' => $teamBid,
            'officialTeamBid' => $teamBid,
            'nilClassification' => $derived !== '' ? $derived : null,
            'contractTricks' => $contractTricks,
            'totalTricks' => $totalTeamTricks,
            'madeContract' => $madeContract,
            'contractScore' => $contractScore,
            'bagsEarned' => $bags,
            'bagPoints' => $bagPoints,
            'bagPenalty' => $bagPenalty,
            'bagRule' => (string)$state['settings']['bagRule'],
            'bostonBonus' => $bostonBonus,
            'nilAdjustment' => $nilAdjustment,
            'scoreChange' => $handScore + $nilAdjustment,
            'bags' => (int)$state['teamBags'][(string)$team],
        ];
    }
}

function spades_result_from_winning_team(array $state, int $winningTeam): array
{
    $members = [];
    foreach ((array)($state['turnOrder'] ?? []) as $index => $userId) {
        $userId = (int)$userId;
        if ($userId < 1) continue;
        $won = $index % 2 === $winningTeam;
        $members[(string)$userId] = [
            'score' => $won ? 1.0 : 0.0,
            'outcome' => $won ? 'win' : 'loss',
        ];
    }
    return ['members' => $members];
}

function spades_draw_result(array $state): array
{
    $members = [];
    foreach ((array)($state['turnOrder'] ?? []) as $userId) {
        $userId = (int)$userId;
        if ($userId > 0) $members[(string)$userId] = ['score' => 0.5, 'outcome' => 'draw'];
    }
    return ['members' => $members];
}

function spades_terminal(array &$state): ?array
{
    $winning = (int)$state['settings']['winningScore'];
    $scores = array_map('intval', $state['teamScores']);
    $positiveEnding = max($scores) >= $winning && $scores[0] !== $scores[1];
    $backDoorEnding = !empty($state['settings']['legacyBackDoorEnding'])
        && min($scores) < -$winning;
    if (!$positiveEnding && !$backDoorEnding) return null;
    $state['completed'] = true;
    $state['phase'] = 'completed';
    if ($backDoorEnding && $scores[0] === $scores[1]) {
        $state['winningTeam'] = null;
        $state['terminalReason'] = 'legacy-back-door-tie';
        return ['state' => $state, 'turnUserId' => null, 'terminal' => true, 'result' => spades_draw_result($state)];
    }
    $winningTeam = $scores[0] > $scores[1] ? 0 : 1;
    $state['winningTeam'] = $winningTeam;
    $state['terminalReason'] = $backDoorEnding ? 'legacy-back-door' : 'score-target';
    return ['state' => $state, 'turnUserId' => null, 'terminal' => true, 'result' => spades_result_from_winning_team($state, $winningTeam)];
}

function spades_apply_action_core(array $state, int $actorUserId, string $action, array $payload, array $context): array
{
    $state = spades_upgrade_state_settings($state);
    if ((int)($state['schemaVersion'] ?? 0) !== SPADES_STATE_SCHEMA_VERSION || !empty($state['completed'])) throw new MultiplayerGameException('The Spades state is unavailable.', 'SPADES_STATE_INVALID', 409);
    if (!in_array($actorUserId, array_map('intval', $state['turnOrder']), true)) throw new MultiplayerGameException('Only a player may act.', 'SPADES_PLAYER_INVALID', 403);
    $state['handViewed'] ??= array_fill_keys(array_map('strval', $state['turnOrder']), true);
    $state['partnershipCommitments'] ??= [];
    $state['pendingPartnershipCommitment'] ??= null;
    if ($action === 'resign') {
        $losingTeam = spades_team_index($state, $actorUserId); $winningTeam = 1 - $losingTeam;
        $state['completed'] = true; $state['phase'] = 'completed'; $state['winningTeam'] = $winningTeam; $state['terminalReason'] = 'resignation';
        return ['state' => $state, 'turnUserId' => null, 'terminal' => true, 'result' => spades_result_from_winning_team($state, $winningTeam)];
    }
    if ($action === 'deal') {
        if ((string)$state['phase'] !== 'deal') throw new MultiplayerGameException('A deal is not available now.', 'SPADES_DEAL_INVALID', 409);
        ocx_game_assert_turn($state, $actorUserId);
        spades_begin_hand(
            $state,
            (array)($context['authoritativeRandomness']['deck'] ?? []),
            isset($context['authoritativeRandomness']['initialDealerIndex'])
                ? (int)$context['authoritativeRandomness']['initialDealerIndex']
                : null
        );
        return ['state' => $state, 'turnUserId' => (int)$state['turnOrder'][(int)$state['turnIndex']]];
    }
    if ($action === 'view-hand') {
        if ((string)$state['phase'] !== 'bidding') throw new MultiplayerGameException('The hand-view choice is not available now.', 'SPADES_HAND_VIEW_INVALID', 409);
        ocx_game_assert_turn($state, $actorUserId);
        if (isset($state['bids'][(string)$actorUserId])) throw new MultiplayerGameException('This bid is already committed.', 'SPADES_BID_ALREADY_COMMITTED', 409);
        $state['handViewed'][(string)$actorUserId] = true;
        return ['state' => $state, 'turnUserId' => $actorUserId];
    }
    if ($action === 'bid') {
        if ((string)$state['phase'] !== 'bidding') throw new MultiplayerGameException('Bidding is not active.', 'SPADES_BID_INVALID', 409);
        ocx_game_assert_turn($state, $actorUserId);
        if (isset($state['bids'][(string)$actorUserId])) throw new MultiplayerGameException('This bid is already committed.', 'SPADES_BID_ALREADY_COMMITTED', 409);
        $kind = strtolower(trim((string)($payload['kind'] ?? 'standard')));
        $amount = (int)($payload['amount'] ?? 0);
        if (!in_array($kind, ['standard', 'nil', 'blind-nil'], true)
            || ($kind === 'standard' && ($amount < 1 || $amount > 13))
            || ($kind !== 'standard' && $amount !== 0)) throw new MultiplayerGameException('Choose a valid Spades bid.', 'SPADES_BID_INVALID', 422);
        $isBlind = $kind === 'blind-nil';
        if ($isBlind && (!spades_blind_available($state, $actorUserId) || !empty($state['handViewed'][(string)$actorUserId]))) throw new MultiplayerGameException('Blind Nil is available only before viewing the hand and when your team trails by at least 100.', 'SPADES_BLIND_NIL_UNAVAILABLE', 422);
        if (!$isBlind && empty($state['handViewed'][(string)$actorUserId])) throw new MultiplayerGameException('View the hand before making this bid.', 'SPADES_HAND_NOT_VIEWED', 409);
        $role = spades_bid_role($state, $actorUserId);
        if (!spades_team_bid_candidate_meets_minimum($state, $actorUserId, $kind, $amount)) {
            throw new MultiplayerGameException(
                'Board ' . (int)$state['settings']['minimumTeamBid'] . ' requires this partnership to make a combined standard bid of at least ' . (int)$state['settings']['minimumTeamBid'] . ', unless both partners choose matching Nil bids.',
                'SPADES_TEAM_BID_MINIMUM',
                422
            );
        }
        $state['bids'][(string)$actorUserId] = ['kind' => $kind, 'amount' => $amount, 'role' => $role];
        $state['blindSelections'][(string)$actorUserId] = $isBlind ? 'blind-nil' : 'none';
        $team = spades_team_index($state, $actorUserId);
        if ($role === 'hint' && $kind === 'standard') {
            $state['teamBidHints'][(string)$team] = ['fromUserId' => $actorUserId, 'amount' => $amount];
        }
        spades_finalize_team_bid($state, $team);
        $currentIndex = (int)array_search($actorUserId, array_map('intval', $state['turnOrder']), true);
        $next = spades_next_unbid($state, $currentIndex);
        if ($next === null) $next = spades_prepare_partner_passes($state);
        return ['state' => $state, 'turnUserId' => $next];
    }
    if ($action === 'offer-partner-pass') {
        if ((string)$state['phase'] !== 'partner-pass') throw new MultiplayerGameException('Partner passing is not available now.', 'SPADES_PASS_INVALID', 409);
        ocx_game_assert_turn($state, $actorUserId);
        $passKey = null;
        foreach ($state['partnerPasses'] as $key => $pass) if ((int)$pass['fromUserId'] === $actorUserId && (string)$pass['status'] === 'awaiting-offer') $passKey = (string)$key;
        if ($passKey === null) throw new MultiplayerGameException('This partner has no eligible pass to offer.', 'SPADES_PASS_INVALID', 409);
        $cardCount = (int)($state['partnerPasses'][$passKey]['cardCount'] ?? 2);
        if (!in_array($cardCount, [1, 2], true)) throw new MultiplayerGameException('The partner exchange size is invalid.', 'SPADES_STATE_INVALID', 409);
        $cards = array_values(array_map('strval', (array)($payload['cards'] ?? [])));
        if (count($cards) !== $cardCount || count(array_unique($cards)) !== $cardCount) throw new MultiplayerGameException('Choose exactly ' . ($cardCount === 1 ? 'one card' : 'two cards') . ' to pass to your partner.', 'SPADES_PASS_INVALID', 422);
        foreach ($cards as $card) if (!in_array($card, $state['hands'][(string)$actorUserId], true)) throw new MultiplayerGameException('A selected pass card is unavailable.', 'SPADES_PASS_INVALID', 422);
        $state['partnerPasses'][$passKey]['cards'] = $cards;
        $state['partnerPasses'][$passKey]['status'] = 'awaiting-response';
        $targetId = (int)$state['partnerPasses'][$passKey]['toUserId'];
        $state['turnIndex'] = (int)array_search($targetId, array_map('intval', $state['turnOrder']), true);
        return ['state' => $state, 'turnUserId' => $targetId];
    }
    if ($action === 'respond-partner-pass') {
        if ((string)$state['phase'] !== 'partner-pass') throw new MultiplayerGameException('Partner passing is not available now.', 'SPADES_PASS_INVALID', 409);
        ocx_game_assert_turn($state, $actorUserId);
        $passKey = (string)$actorUserId;
        $pass = (array)($state['partnerPasses'][$passKey] ?? []);
        if ($pass === [] || (string)($pass['status'] ?? '') !== 'awaiting-response') throw new MultiplayerGameException('There is no partner pass to answer.', 'SPADES_PASS_INVALID', 409);
        $accept = filter_var($payload['accept'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $exchangeMode = (string)($pass['exchangeMode'] ?? $state['settings']['exchangeMode'] ?? 'modern');
        $cardCount = (int)($pass['cardCount'] ?? 2);
        if (!in_array($cardCount, [1, 2], true)) throw new MultiplayerGameException('The partner exchange size is invalid.', 'SPADES_STATE_INVALID', 409);
        $allowDecline = array_key_exists('allowDecline', $pass)
            ? filter_var($pass['allowDecline'], FILTER_VALIDATE_BOOLEAN)
            : $exchangeMode === 'modern';
        if (!$accept && !$allowDecline) {
            $regularNil = (string)($pass['trigger'] ?? 'blind-nil') === 'regular-nil';
            throw new MultiplayerGameException(
                $regularNil ? 'The accepted regular Nil option requires exactly one return card.' : 'The accepted Legacy Blind Nil exchange requires exactly two return cards.',
                $regularNil ? 'SPADES_REGULAR_NIL_EXCHANGE_REQUIRED' : 'SPADES_LEGACY_EXCHANGE_REQUIRED',
                409
            );
        }
        if ($accept) {
            $returnCards = array_values(array_map('strval', (array)($payload['returnCards'] ?? [])));
            if (count($returnCards) !== $cardCount || count(array_unique($returnCards)) !== $cardCount) throw new MultiplayerGameException('Choose exactly ' . ($cardCount === 1 ? 'one card' : 'two cards') . ' to return to your partner.', 'SPADES_PASS_INVALID', 422);
            $sourceId = (int)$pass['fromUserId'];
            $offered = array_values(array_map('strval', (array)$pass['cards']));
            if (count($offered) !== $cardCount || count(array_unique($offered)) !== $cardCount) throw new MultiplayerGameException('The offered pass is stale.', 'SPADES_PASS_STALE', 409);
            foreach ($returnCards as $card) if (!in_array($card, $state['hands'][(string)$actorUserId], true)) throw new MultiplayerGameException('A selected return card is unavailable.', 'SPADES_PASS_INVALID', 422);
            foreach ($offered as $card) if (!in_array($card, $state['hands'][(string)$sourceId], true)) throw new MultiplayerGameException('The offered pass is stale.', 'SPADES_PASS_STALE', 409);
            $state['hands'][(string)$sourceId] = array_values(array_diff($state['hands'][(string)$sourceId], $offered));
            $state['hands'][(string)$actorUserId] = array_values(array_diff($state['hands'][(string)$actorUserId], $returnCards));
            $state['hands'][(string)$sourceId] = spades_sort_hand(array_merge($state['hands'][(string)$sourceId], $returnCards));
            $state['hands'][(string)$actorUserId] = spades_sort_hand(array_merge($state['hands'][(string)$actorUserId], $offered));
            $state['partnerPasses'][$passKey]['status'] = 'accepted';
        } else $state['partnerPasses'][$passKey]['status'] = 'declined';
        $state['partnerPasses'][$passKey]['cards'] = [];
        $nextUserId = spades_advance_partner_pass($state);
        return ['state' => $state, 'turnUserId' => $nextUserId];
    }
    if ($action === 'settle-trick') {
        if ((string)$state['phase'] !== 'settling' || !is_array($state['settlement'])) {
            throw new MultiplayerGameException('There is no completed trick to settle.', 'SPADES_SETTLEMENT_INVALID', 409);
        }
        // Settling makes no player choice. The player check above still blocks
        // spectators, while allowing a connected human to advance a bot-won
        // trick after the authoritative display deadline.
        $nowUnixMs = (int)($context['nowUnixMs'] ?? floor(microtime(true) * 1000));
        $settleAfterUnixMs = (int)($state['settlement']['settleAfterUnixMs'] ?? 0);
        if ($settleAfterUnixMs < 1 || $nowUnixMs < $settleAfterUnixMs) {
            throw new MultiplayerGameException('The complete trick is still being shown.', 'SPADES_SETTLEMENT_EARLY', 409, [
                'retryAfterMs' => max(1, $settleAfterUnixMs - $nowUnixMs),
            ]);
        }
        $state['currentTrick'] = [];
        $state['settlement'] = null;
        if (array_sum(array_map('count', $state['hands'])) === 0) {
            spades_score_hand($state);
            $terminal = spades_terminal($state);
            if ($terminal !== null) return $terminal;
            $state['phase'] = 'deal';
        } else {
            $state['phase'] = 'playing';
        }
        return ['state' => $state, 'turnUserId' => (int)$state['turnOrder'][(int)$state['turnIndex']]];
    }
    if ($action !== 'play') throw new MultiplayerGameException('This Spades action is not supported.', 'SPADES_ACTION_INVALID', 422);
    if ((string)$state['phase'] !== 'playing') throw new MultiplayerGameException('Card play is not active.', 'SPADES_PLAY_INVALID', 409);
    ocx_game_assert_turn($state, $actorUserId);
    $card = strtoupper(trim((string)($payload['card'] ?? '')));
    $parts = spades_card_parts($card);
    $hand =& $state['hands'][(string)$actorUserId];
    if (!in_array($card, $hand, true)) throw new MultiplayerGameException('That card is not in your hand.', 'SPADES_CARD_UNAVAILABLE', 422);
    $legalCards = spades_legal_cards($state, $actorUserId);
    if (!in_array($card, $legalCards, true)) {
        if (array_sum(array_map('intval', $state['tricksWon'])) === 0 && $parts['suit'] === 'S') throw new MultiplayerGameException('A Spade cannot be played on the first trick.', 'SPADES_FIRST_TRICK_TRUMP', 422);
        $leadSuit = $state['currentTrick'] === [] ? null : spades_card_parts((string)$state['currentTrick'][0]['card'])['suit'];
        if ($leadSuit !== null && $parts['suit'] !== $leadSuit) throw new MultiplayerGameException('You must follow the led suit.', 'SPADES_MUST_FOLLOW_SUIT', 422);
        throw new MultiplayerGameException('Spades cannot be led until broken.', 'SPADES_NOT_BROKEN', 422);
    }
    if (array_sum(array_map('intval', $state['tricksWon'])) === 0 && $parts['suit'] === 'S') throw new MultiplayerGameException('A Spade cannot be played on the first trick.', 'SPADES_FIRST_TRICK_TRUMP', 422);
    $leadSuit = $state['currentTrick'] === [] ? null : spades_card_parts((string)$state['currentTrick'][0]['card'])['suit'];
    if ($leadSuit !== null && $parts['suit'] !== $leadSuit) {
        foreach ($hand as $heldCard) if (spades_card_parts((string)$heldCard)['suit'] === $leadSuit) throw new MultiplayerGameException('You must follow the led suit.', 'SPADES_MUST_FOLLOW_SUIT', 422);
    }
    if ($leadSuit === null && $parts['suit'] === 'S' && empty($state['spadesBroken'])) {
        $hasOtherSuit = false; foreach ($hand as $heldCard) if (spades_card_parts((string)$heldCard)['suit'] !== 'S') $hasOtherSuit = true;
        if ($hasOtherSuit) throw new MultiplayerGameException('Spades cannot be led until broken.', 'SPADES_NOT_BROKEN', 422);
    }
    $hand = array_values(array_diff($hand, [$card]));
    if ($parts['suit'] === 'S' && $leadSuit !== 'S') $state['spadesBroken'] = true;
    $state['lastCompletedTrick'] = null;
    $state['currentTrick'][] = ['userId' => $actorUserId, 'card' => $card];
    $state['playSequence'] = (int)$state['playSequence'] + 1;
    if (count($state['currentTrick']) < 4) {
        $next = ocx_game_advance_turn($state);
        return ['state' => $state, 'turnUserId' => $next];
    }
    $completedTrick = $state['currentTrick'];
    $winner = spades_trick_winner($completedTrick);
    $nilSet = spades_nil_set_by_trick($state, $winner);
    $state['tricksWon'][(string)$winner] = (int)$state['tricksWon'][(string)$winner] + 1;
    $trickNumber = array_sum(array_map('intval', $state['tricksWon']));
    $state['lastCompletedTrick'] = [
        'cards' => $completedTrick,
        'winnerUserId' => $winner,
        'trick' => $trickNumber,
        'nilSet' => $nilSet,
    ];
    $state['history'][] = ['hand' => (int)$state['handNumber'], 'trick' => $trickNumber, 'winnerUserId' => $winner, 'cards' => $completedTrick, 'nilSet' => $nilSet];
    $state['turnIndex'] = (int)array_search($winner, array_map('intval', $state['turnOrder']), true);
    $state['leaderIndex'] = (int)$state['turnIndex'];
    $durationMs = $nilSet ? 2000 : 1040;
    $state['phase'] = 'settling';
    $state['settlement'] = [
        'kind' => $nilSet ? 'nil-set' : 'ordinary',
        'durationMs' => $durationMs,
        'settleAfterUnixMs' => (int)($context['nowUnixMs'] ?? floor(microtime(true) * 1000)) + $durationMs,
        'winnerUserId' => $winner,
        'trick' => $trickNumber,
    ];
    return ['state' => $state, 'turnUserId' => (int)$state['turnOrder'][(int)$state['turnIndex']]];
}
