<?php
declare(strict_types=1);

require_once __DIR__ . '/five_dice_identity.php';
require_once __DIR__ . '/five_dice_bot_support.php';

/**
 * Independently authored Five Dice rules adapter.
 *
 * The opaque key is durable identity only. It must never be used as ordinary
 * user-facing text. Build 000056 remains authoritative for sessions,
 * membership, randomness receipts, persistence, results, and cleanup.
 */

const FIVE_DICE_STATE_SCHEMA_VERSION = 1;

function five_dice_extension_adapter(): array
{
    return [
        'id' => FIVE_DICE_EXTENSION_ID,
        'initialState' => 'five_dice_initial_state',
        'applyAction' => 'five_dice_apply_action',
        'projectState' => 'five_dice_project_state',
        'projectMemberMetadata' => 'five_dice_project_member_metadata',
        'randomnessPurpose' => 'five-dice-roll',
        'randomnessPurposes' => ['roll'=>'five-dice-roll','bot-roll'=>'five-dice-roll'],
        'projectVirtualMembers' => 'five_dice_project_virtual_members',
        'recordingAdapter' => 'five_dice_recording_adapter',
        'presentationStatus' => 'five_dice_presentation_status',
        'validateSettings' => 'five_dice_validate_settings',
        'rulesProjection' => 'five_dice_rules_projection',
        'openingProcedure' => 'fixed-seat-one-owner-approved-no-meaningful-first-player-advantage',
        'rematchSeatRotation' => false,
    ];
}

function five_dice_project_state(array $state, int $viewerUserId, array $context): array
{
    $projection = $state;
    $projection['botTask'] = five_dice_bot_task($state, $viewerUserId, $context);
    $projection['personalBests'] = is_array($context['memberMetadata']['personalBests'] ?? null)
        ? $context['memberMetadata']['personalBests']
        : [];
    foreach ($projection['personalBests'] as $userId => $best) {
        if (isset($projection['players'][(string)$userId]) && is_array($best)) {
            $projection['players'][(string)$userId]['personalBest'] = $best;
        }
    }
    $projection['scorePreviews'] = [];
    if (!empty($state['completed'])
        || (int)($state['rollsThisTurn'] ?? 0) < 1
        || !in_array((string)($context['viewerRole'] ?? ''), ['master', 'player'], true)) {
        return $projection;
    }
    $turnOrder = array_values(array_map('intval', (array)($state['turnOrder'] ?? [])));
    $turnUserId = (int)($turnOrder[(int)($state['turnIndex'] ?? -1)] ?? 0);
    $player = $state['players'][(string)$viewerUserId] ?? null;
    if ($viewerUserId < 1 || $turnUserId !== $viewerUserId || !is_array($player)) return $projection;
    $scorecard = is_array($player['scorecard'] ?? null) ? $player['scorecard'] : [];
    $dice = array_values(array_map('intval', (array)($state['dice'] ?? [])));
    foreach (five_dice_allowed_score_categories($scorecard, $dice) as $category) {
        $projection['scorePreviews'][$category] = five_dice_score_with_joker($category, $scorecard, $dice);
    }
    return $projection;
}

function five_dice_project_member_metadata(PDO $pdo, array $state, array $userIds, array $context): array
{
    $mode = strtolower(trim((string)($context['mode'] ?? '')));
    if (!in_array($mode, ['practice', 'recorded'], true)) return ['personalBests' => []];
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
    $activePlayerIds = array_values(array_map('intval', array_keys((array)($state['players'] ?? []))));
    $userIds = array_values(array_intersect($userIds, $activePlayerIds));
    if ($userIds === []) return ['personalBests' => []];
    $best = array_fill_keys(array_map('strval', $userIds), null);
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));

    if ($mode === 'recorded') {
        $stmt = $pdo->prepare(
            "SELECT r.id,r.result_json,member.user_id,member.score_value
               FROM multiplayer_game_results r
               JOIN multiplayer_game_result_members member ON member.result_id=r.id
              WHERE r.game_key=? AND r.mode='recorded' AND member.user_id IN ({$placeholders})
              ORDER BY r.recorded_at ASC,r.id ASC"
        );
        $stmt->execute(array_merge([FIVE_DICE_GAME_KEY], $userIds));
        $correction = $pdo->prepare(
            'SELECT replacement_json FROM multiplayer_game_result_corrections WHERE result_id=? ORDER BY created_at DESC,id DESC LIMIT 1'
        );
        foreach ($stmt->fetchAll() as $row) {
            $userId = (int)$row['user_id'];
            $score = is_numeric($row['score_value']) ? (float)$row['score_value'] : null;
            $correction->execute([(int)$row['id']]);
            $replacement = $correction->fetchColumn();
            if (is_string($replacement) && $replacement !== '') {
                $decoded = json_decode($replacement, true);
                $candidate = is_array($decoded) ? ($decoded['members'][(string)$userId]['score'] ?? null) : null;
                if (is_numeric($candidate) && is_finite((float)$candidate)) $score = (float)$candidate;
            }
            if ($score !== null && ($best[(string)$userId] === null || $score > $best[(string)$userId])) {
                $best[(string)$userId] = $score;
            }
        }
    } else {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT session.id,session.state_json
               FROM multiplayer_game_sessions session
               JOIN multiplayer_game_members member ON member.game_session_id=session.id
              WHERE session.game_key=? AND session.mode='practice' AND session.status='completed'
                AND member.user_id IN ({$placeholders})
              ORDER BY session.id ASC"
        );
        $stmt->execute(array_merge([FIVE_DICE_GAME_KEY], $userIds));
        foreach ($stmt->fetchAll() as $row) {
            $completed = json_decode((string)$row['state_json'], true);
            if (!is_array($completed) || empty($completed['completed'])) continue;
            foreach ($userIds as $userId) {
                $candidate = $completed['players'][(string)$userId]['total'] ?? null;
                if (!is_numeric($candidate) || !is_finite((float)$candidate)) continue;
                $score = (float)$candidate;
                if ($best[(string)$userId] === null || $score > $best[(string)$userId]) $best[(string)$userId] = $score;
            }
        }
    }

    return ['personalBests' => array_map(static fn(?float $score): array => [
        'mode' => $mode,
        'score' => $score === null ? null : (int)$score,
        'retentionOwner' => $mode === 'recorded' ? 'immutable-result-and-latest-correction' : 'completed-practice-session',
    ], $best)];
}

function five_dice_validate_settings(array $settings, string $mode, array $definition = []): array
{
    if (!in_array($mode, ['practice', 'recorded'], true)) {
        throw new MultiplayerGameException('Choose Practice or Recorded Play.', 'MULTIPLAYER_GAME_MODE_INVALID', 422);
    }
    $out=[];$allowed=[];
    for($seat=1;$seat<=4;$seat++){
        $key='botSeat'.$seat.'Difficulty';$allowed[]=$key;$level=$settings[$key]??'none';
        if(!is_string($level)||!in_array($level,array_column(five_dice_bot_choices(),'value'),true))throw new MultiplayerGameException('Choose a listed bot difficulty.','FIVE_DICE_SETTINGS_INVALID',422);
        if($mode!=='practice'&&$level!=='none')throw new MultiplayerGameException('Games with bots are Practice only.','MULTIPLAYER_GAME_BOTS_PRACTICE_ONLY',422);
        if($mode==='practice')$out[$key]=$level;
    }
    if(array_diff(array_keys($settings),$allowed))throw new MultiplayerGameException('A Five Dice setting is not supported.','FIVE_DICE_SETTINGS_INVALID',422);
    return $out;
}

function five_dice_rules_projection(array $settings, string $mode, array $definition = []): array
{
    $recordEffect = $mode === 'recorded'
        ? 'Completed Recorded games update Five Dice opponent and lifetime records.'
        : 'Practice games do not change Recorded records.';
    return [
        'label' => 'Five Dice rules',
        'description' => 'Each player may roll at most three times and then scores one unused category. After rolling, select a die to keep it. Hold or release choices may change only after roll one before roll two and after roll two before roll three. After roll three, all hold controls are disabled, there is no fourth roll, all five dice are scored, and holds clear after scoring. Every player completes all thirteen categories. A first Yahtzee scores 50. A later Yahtzee can earn a separate 100-point bonus and follows the Joker placement rule described below. If the combined Ones, Twos, Threes, Fours, Fives, and Sixes score is at least 63 points, that player receives a 35-point upper-section bonus. ' . $recordEffect,
        'sections' => [
            ['label' => 'Mode', 'text' => $mode === 'recorded' ? 'Ranked or Recorded Play uses server-authoritative randomness and updates records after a valid completion.' : 'Practice Mode uses committed browser randomness and never updates Recorded records.'],
            ['label' => 'Scoring', 'text' => 'Score one unused category after rolling. A first Yahtzee scores 50. Every later Yahtzee awards a separate 100-point bonus only when the Yahtzee category already contains 50. If that category contains zero, no repeat bonus is awarded.'],
            ['label' => 'Joker rule', 'text' => 'For a later Yahtzee, first score the matching open Upper Section category. If it is already filled, choose an open Lower Section category: Full House scores 25, Small Straight 30, Large Straight 40, and Three of a Kind, Four of a Kind, or Chance scores the total of all dice. If every Lower Section category is filled, an open Upper Section category is filled with zero. Any earned 100-point repeat bonus remains.'],
            ['label' => 'Upper bonus', 'text' => 'If Ones, Twos, Threes, Fours, Fives, and Sixes total at least 63 points, add a 35-point bonus.'],
            ['label' => 'Turns', 'text' => 'Only the current player can roll, hold or release dice, or select a score. Hold and release controls are available only between rolls one and two or between rolls two and three. After roll three, every die and the roll control remain disabled until the current player scores all five dice.'],
        ],
    ];
}

function five_dice_categories(): array
{
    return [
        'ones', 'twos', 'threes', 'fours', 'fives', 'sixes',
        'three-kind', 'four-kind', 'full-house', 'small-straight',
        'large-straight', 'chance', 'yahtzee',
    ];
}

function five_dice_empty_scorecard(): array
{
    return array_fill_keys(five_dice_categories(), null);
}

function five_dice_initial_state(array $playerUserIds, array $context = []): array
{
    $playerUserIds = array_values(array_unique(array_map('intval', $playerUserIds)));
    if ($playerUserIds === [] || count($playerUserIds) > 4 || min($playerUserIds) < 1) {
        throw new MultiplayerGameException(
            'This game requires one to four authenticated players.',
            'FIVE_DICE_PLAYER_SET_INVALID',
            422
        );
    }
    $settings=five_dice_validate_settings((array)($context['settings']??[]),(string)($context['mode']??'practice'));
    [$playerUserIds,$bots]=five_dice_bot_fill_seats($playerUserIds,$settings,(string)($context['mode']??'practice'),(array)($context['humanSeats']??[]));
    $players = [];
    foreach ($playerUserIds as $userId) {
        $players[(string)$userId] = [
            'scorecard' => five_dice_empty_scorecard(),
            'upperSubtotal' => 0,
            'upperBonus' => 0,
            'yahtzeeBonus' => 0,
            'total' => 0,
        ];
    }
    return [
        'schemaVersion' => FIVE_DICE_STATE_SCHEMA_VERSION,
        'bots'=>$bots, 'botSequence'=>0, 'botRollPending'=>false, 'lastBotAction'=>null,
        'turnOrder' => $playerUserIds,
        'turnIndex' => 0,
        'dice' => [1, 1, 1, 1, 1],
        'held' => [false, false, false, false, false],
        'rollsThisTurn' => 0,
        'players' => $players,
        'usedRandomnessRequestIds' => [],
        'completed' => false,
    ];
}

function five_dice_validate_state(array $state): void
{
    $turnOrder = $state['turnOrder'] ?? null;
    $players = $state['players'] ?? null;
    $dice = $state['dice'] ?? null;
    $held = $state['held'] ?? null;
    if ((int)($state['schemaVersion'] ?? 0) !== FIVE_DICE_STATE_SCHEMA_VERSION
        || !is_array($turnOrder) || $turnOrder === [] || count($turnOrder) > 4
        || !is_array($players) || count($players) !== count($turnOrder)
        || !is_array($dice) || count($dice) !== 5
        || !is_array($held) || count($held) !== 5
        || (int)($state['turnIndex'] ?? -1) < 0
        || (int)($state['turnIndex'] ?? -1) >= count($turnOrder)
        || (int)($state['rollsThisTurn'] ?? -1) < 0
        || (int)($state['rollsThisTurn'] ?? -1) > 3) {
        throw new MultiplayerGameException('The game state is invalid.', 'FIVE_DICE_STATE_INVALID', 409);
    }
    foreach ($dice as $value) {
        if (!is_int($value) || $value < 1 || $value > 6) {
            throw new MultiplayerGameException('The dice values are invalid.', 'FIVE_DICE_VALUE_INVALID', 409);
        }
    }
    foreach ($held as $value) {
        if (!is_bool($value)) {
            throw new MultiplayerGameException('The held-dice state is invalid.', 'FIVE_DICE_HOLD_STATE_INVALID', 409);
        }
    }
    foreach ($turnOrder as $userId) {
        $userId = (int)$userId;
        $player = $players[(string)$userId] ?? null;
        $scorecardKeys = is_array($player['scorecard'] ?? null) ? array_keys($player['scorecard']) : [];
        $expectedKeys = five_dice_categories();
        sort($scorecardKeys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);
        if (($userId < 1 && (!isset($state['bots'][(string)$userId]) || (int)$state['bots'][(string)$userId]['userId']!==$userId)) || !is_array($player) || !is_array($player['scorecard'] ?? null)
            || $scorecardKeys !== $expectedKeys) {
            throw new MultiplayerGameException('The player scorecard state is invalid.', 'FIVE_DICE_PLAYER_STATE_INVALID', 409);
        }
        $yahtzeeBonus = (int)($player['yahtzeeBonus'] ?? 0);
        if ($yahtzeeBonus < 0 || $yahtzeeBonus > 1200 || $yahtzeeBonus % 100 !== 0) {
            throw new MultiplayerGameException('The repeat-Yahtzee bonus state is invalid.', 'FIVE_DICE_PLAYER_STATE_INVALID', 409);
        }
    }
}

function five_dice_score(string $category, array $dice): int
{
    if (!in_array($category, five_dice_categories(), true) || count($dice) !== 5) {
        throw new MultiplayerGameException('Choose an available score category.', 'FIVE_DICE_CATEGORY_INVALID', 422);
    }
    $counts = array_fill(1, 6, 0);
    $sum = 0;
    foreach ($dice as $value) {
        $value = (int)$value;
        if ($value < 1 || $value > 6) {
            throw new MultiplayerGameException('The dice values are invalid.', 'FIVE_DICE_VALUE_INVALID', 422);
        }
        $counts[$value]++;
        $sum += $value;
    }
    $numberCategories = ['ones' => 1, 'twos' => 2, 'threes' => 3, 'fours' => 4, 'fives' => 5, 'sixes' => 6];
    if (isset($numberCategories[$category])) {
        $face = $numberCategories[$category];
        return $counts[$face] * $face;
    }
    $multiplicities = array_values(array_filter($counts));
    rsort($multiplicities, SORT_NUMERIC);
    $unique = array_keys(array_filter($counts));
    sort($unique, SORT_NUMERIC);
    return match ($category) {
        'three-kind' => max($multiplicities) >= 3 ? $sum : 0,
        'four-kind' => max($multiplicities) >= 4 ? $sum : 0,
        'full-house' => $multiplicities === [3, 2] ? 25 : 0,
        'small-straight' => five_dice_has_small_straight($unique) ? 30 : 0,
        'large-straight' => $unique === [1,2,3,4,5] || $unique === [2,3,4,5,6] ? 40 : 0,
        'chance' => $sum,
        'yahtzee' => max($multiplicities) === 5 ? 50 : 0,
        default => 0,
    };
}

function five_dice_has_small_straight(array $unique): bool
{
    foreach ([[1,2,3,4], [2,3,4,5], [3,4,5,6]] as $straight) {
        if (array_diff($straight, $unique) === []) return true;
    }
    return false;
}

function five_dice_upper_categories(): array
{
    return ['ones' => 1, 'twos' => 2, 'threes' => 3, 'fours' => 4, 'fives' => 5, 'sixes' => 6];
}

function five_dice_lower_joker_categories(): array
{
    return ['three-kind', 'four-kind', 'full-house', 'small-straight', 'large-straight', 'chance'];
}

function five_dice_is_yahtzee(array $dice): bool
{
    return count($dice) === 5 && count(array_unique(array_map('intval', $dice))) === 1;
}

/**
 * Return the only categories that may receive the current roll.
 *
 * An already-filled Yahtzee box activates the owner-approved Joker placement
 * order even when that box contains zero. The separate repeat bonus is earned
 * only when the box contains 50.
 */
function five_dice_allowed_score_categories(array $scorecard, array $dice): array
{
    $open = array_values(array_filter(
        five_dice_categories(),
        static fn(string $category): bool => array_key_exists($category, $scorecard) && $scorecard[$category] === null
    ));
    if (!five_dice_is_yahtzee($dice) || ($scorecard['yahtzee'] ?? null) === null) {
        return $open;
    }

    $face = (int)$dice[0];
    $matchingUpper = array_search($face, five_dice_upper_categories(), true);
    if (is_string($matchingUpper) && ($scorecard[$matchingUpper] ?? null) === null) {
        return [$matchingUpper];
    }

    $openLower = array_values(array_filter(
        five_dice_lower_joker_categories(),
        static fn(string $category): bool => ($scorecard[$category] ?? null) === null
    ));
    if ($openLower !== []) return $openLower;

    return array_values(array_filter(
        array_keys(five_dice_upper_categories()),
        static fn(string $category): bool => ($scorecard[$category] ?? null) === null
    ));
}

function five_dice_score_with_joker(string $category, array $scorecard, array $dice): array
{
    $allowed = five_dice_allowed_score_categories($scorecard, $dice);
    if (!in_array($category, $allowed, true)) {
        throw new MultiplayerGameException(
            'Choose a category allowed by the repeat-Yahtzee Joker rule.',
            'FIVE_DICE_JOKER_PLACEMENT_REQUIRED',
            409
        );
    }

    $repeat = five_dice_is_yahtzee($dice) && ($scorecard['yahtzee'] ?? null) !== null;
    if (!$repeat) return ['score' => five_dice_score($category, $dice), 'repeatBonus' => 0];

    $matchingUpper = array_search((int)$dice[0], five_dice_upper_categories(), true);
    if (is_string($matchingUpper) && $category === $matchingUpper) {
        $score = five_dice_score($category, $dice);
    } elseif (in_array($category, five_dice_lower_joker_categories(), true)) {
        $score = match ($category) {
            'full-house' => 25,
            'small-straight' => 30,
            'large-straight' => 40,
            default => array_sum(array_map('intval', $dice)),
        };
    } else {
        $score = 0;
    }

    return [
        'score' => $score,
        'repeatBonus' => (int)($scorecard['yahtzee'] ?? 0) === 50 ? 100 : 0,
    ];
}

function five_dice_recalculate_player(array $scorecard, int $yahtzeeBonus = 0): array
{
    $upper = 0;
    foreach (array_slice(five_dice_categories(), 0, 6) as $category) {
        $upper += (int)($scorecard[$category] ?? 0);
    }
    $bonus = $upper >= 63 ? 35 : 0;
    return [
        'upperSubtotal' => $upper,
        'upperBonus' => $bonus,
        'yahtzeeBonus' => $yahtzeeBonus,
        'total' => array_sum(array_map(static fn(mixed $value): int => (int)($value ?? 0), $scorecard)) + $bonus + $yahtzeeBonus,
    ];
}

function five_dice_terminal_result(array $state, ?int $resignedUserId = null): array
{
    $scores = [];
    foreach ((array)($state['players'] ?? []) as $userId => $player) {
        $scores[(string)(int)$userId] = (int)($player['total'] ?? 0);
    }
    if ($resignedUserId !== null && array_key_exists((string)$resignedUserId, $scores)) {
        $remaining = array_diff_key($scores, [(string)$resignedUserId => true]);
        $scores[(string)$resignedUserId] = $remaining === [] ? 0 : min($remaining) - 1;
    }
    $result = ocx_game_result_from_scores($scores);
    if ($scores !== []) {
        $highest = max($scores);
        $leaders = array_keys(array_filter($scores, static fn(int $score): bool => $score === $highest));
        if (count($leaders) > 1) {
            foreach ($leaders as $userId) $result['members'][(string)$userId]['outcome'] = 'draw';
        }
    }
    return $result;
}

function five_dice_apply_action_rules(array $state, int $actorUserId, string $action, array $payload, array $context): array
{
    five_dice_validate_state($state);
    if (!empty($state['completed'])) {
        throw new MultiplayerGameException('This game is complete.', 'FIVE_DICE_COMPLETE', 409);
    }
    $turnOrder = array_map('intval', $state['turnOrder']);
    if (!in_array($actorUserId, $turnOrder, true)) {
        throw new MultiplayerGameException('Wait for your turn.', 'FIVE_DICE_NOT_YOUR_TURN', 409);
    }
    if ($action === 'resign') {
        $state['completed'] = true;
        $state['terminalReason'] = 'resignation';
        $state['resignedUserId'] = $actorUserId;
        return [
            'state' => $state,
            'turnUserId' => null,
            'terminal' => true,
            'result' => five_dice_terminal_result($state, $actorUserId),
        ];
    }
    $turnUserId = $turnOrder[(int)$state['turnIndex']];
    if ($actorUserId !== $turnUserId) {
        throw new MultiplayerGameException('Wait for your turn.', 'FIVE_DICE_NOT_YOUR_TURN', 409);
    }
    if ($action === 'roll') {
        if ((int)$state['rollsThisTurn'] >= 3) {
            throw new MultiplayerGameException('Score this turn before rolling again.', 'FIVE_DICE_ROLL_LIMIT', 409);
        }
        $requestId = (string)($context['randomnessRequestId'] ?? '');
        $dice = $context['authoritativeDice'] ?? null;
        if (!preg_match('/^[A-Za-z0-9._:-]{8,96}$/', $requestId)
            || !is_array($dice) || count($dice) !== 5
            || in_array($requestId, (array)$state['usedRandomnessRequestIds'], true)) {
            throw new MultiplayerGameException('A fresh verified randomness receipt is required.', 'FIVE_DICE_RANDOMNESS_REQUIRED', 409);
        }
        foreach ($dice as $index => $value) {
            $value = (int)$value;
            if ($value < 1 || $value > 6) {
                throw new MultiplayerGameException('The verified dice are invalid.', 'FIVE_DICE_RANDOMNESS_INVALID', 409);
            }
            if (empty($state['held'][$index])) $state['dice'][$index] = $value;
        }
        $state['rollsThisTurn']++;
        $state['usedRandomnessRequestIds'][] = $requestId;
    } elseif ($action === 'set-holds') {
        $held=$payload['held']??null;
        if(!isset($state['bots'][(string)$actorUserId])||!is_array($held)||!array_is_list($held)||count($held)!==5||count(array_filter($held,'is_bool'))!==5||!in_array(false,$held,true)||(int)$state['rollsThisTurn']<1||(int)$state['rollsThisTurn']>=3)throw new MultiplayerGameException('These holds are unavailable.','FIVE_DICE_HOLD_INVALID',422);
        $state['held']=$held;
    } elseif ($action === 'toggle-hold') {
        $index = filter_var($payload['index'] ?? null, FILTER_VALIDATE_INT);
        if ($index === false || $index < 0 || $index > 4
            || (int)$state['rollsThisTurn'] < 1 || (int)$state['rollsThisTurn'] >= 3) {
            throw new MultiplayerGameException('That die cannot be held now.', 'FIVE_DICE_HOLD_INVALID', 422);
        }
        $state['held'][$index] = !$state['held'][$index];
    } elseif ($action === 'score') {
        if ((int)$state['rollsThisTurn'] < 1) {
            throw new MultiplayerGameException('Roll before choosing a score.', 'FIVE_DICE_SCORE_REQUIRES_ROLL', 409);
        }
        $category = strtolower(trim((string)($payload['category'] ?? '')));
        $player = $state['players'][(string)$actorUserId];
        if (!array_key_exists($category, $player['scorecard']) || $player['scorecard'][$category] !== null) {
            throw new MultiplayerGameException('Choose an unused score category.', 'FIVE_DICE_CATEGORY_UNAVAILABLE', 409);
        }
        $placement = five_dice_score_with_joker($category, $player['scorecard'], $state['dice']);
        $player['scorecard'][$category] = (int)$placement['score'];
        $yahtzeeBonus = (int)($player['yahtzeeBonus'] ?? 0) + (int)$placement['repeatBonus'];
        $player = array_replace($player, five_dice_recalculate_player($player['scorecard'], $yahtzeeBonus));
        $state['players'][(string)$actorUserId] = $player;
        $complete = true;
        foreach ($state['players'] as $candidate) {
            if (in_array(null, $candidate['scorecard'], true)) {
                $complete = false;
                break;
            }
        }
        $state['completed'] = $complete;
        $state['dice'] = [1,1,1,1,1];
        $state['held'] = [false,false,false,false,false];
        $state['rollsThisTurn'] = 0;
        if (!$complete) $state['turnIndex'] = ((int)$state['turnIndex'] + 1) % count($turnOrder);
    } else {
        throw new MultiplayerGameException('That game action is unavailable.', 'FIVE_DICE_ACTION_INVALID', 422);
    }

    five_dice_validate_state($state);
    return [
        'state' => $state,
        'turnUserId' => !empty($state['completed']) ? null : $turnOrder[(int)$state['turnIndex']],
        'terminal' => (bool)$state['completed'],
        'result' => five_dice_terminal_result($state),
    ];
}
