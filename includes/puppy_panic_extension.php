<?php
declare(strict_types=1);

require_once __DIR__ . '/ocx_game_extension_support.php';
require_once __DIR__ . '/puppy_panic_bot_support.php';

const PUPPY_PANIC_EXTENSION_ID = 'puppy-panic';
const PUPPY_PANIC_STATE_SCHEMA_VERSION = 1;
const PUPPY_PANIC_COUNTER_WINDOW_MS = 2600;

function puppy_panic_extension_adapter(): array
{
    return [
        'id' => PUPPY_PANIC_EXTENSION_ID,
        'recordingAdapter'=>'puppy_panic_recording_adapter',
        'projectVirtualMembers'=>'puppy_panic_project_virtual_members',
        'initialState' => 'puppy_panic_initial_state',
        'applyAction' => 'puppy_panic_apply_action',
        'validateSettings' => 'puppy_panic_validate_settings',
        'settingsProjection' => 'puppy_panic_settings_projection',
        'rulesProjection' => 'puppy_panic_rules_projection',
        'projectState' => 'puppy_panic_project_state',
        'randomnessPurposes' => [
            'deal' => 'puppy-panic-deal',
            'bot-deal'=>'puppy-panic-deal',
            'bot-settle-random'=>'puppy-panic-random-effect',
            'settle-random' => 'puppy-panic-random-effect',
        ],
        'deriveRandomness' => 'puppy_panic_derive_randomness',
        'presentationStatus' => 'puppy_panic_presentation_status',
        'openingProcedure' => 'verified-random-starting-player',
        'rematchSeatRotation' => false,
    ];
}

function puppy_panic_presentation_status(PDO $pdo, ?string $requestedPack = null): array
{
    return [
        'requestedPack' => 'built-in',
        'effectivePack' => 'built-in',
        'classicAvailable' => false,
        'fallbackApplied' => false,
        'presentationOnly' => true,
        'mediaPack' => ['repositoryOwned' => true, 'remoteMedia' => false],
    ];
}

function puppy_panic_validate_settings(array $settings, string $mode, array $definition = []): array
{
    $allowed=['mischiefPack'];for($seat=1;$seat<=5;$seat++)$allowed[]='botSeat'.$seat.'Difficulty';
    if (array_diff(array_keys($settings), $allowed)) {
        throw new MultiplayerGameException('A game option is not supported.', 'PUPPY_PANIC_SETTINGS_INVALID', 422);
    }
    $pack = strtolower(trim((string)($settings['mischiefPack'] ?? 'core')));
    if (!in_array($pack, ['core', 'mischief'], true)) {
        throw new MultiplayerGameException('Choose the Core deck or Mischief Pack.', 'PUPPY_PANIC_SETTINGS_INVALID', 422);
    }
    $out=['mischiefPack'=>$pack];
    for($seat=1;$seat<=5;$seat++){
        $key='botSeat'.$seat.'Difficulty';$level=$settings[$key]??'none';
        if(!is_string($level)||!in_array($level,['none','easy','normal','expert'],true))throw new MultiplayerGameException('Choose a listed bot difficulty.','PUPPY_PANIC_SETTINGS_INVALID',422);
        if($mode!=='practice'&&$level!=='none')throw new MultiplayerGameException('Games with bots are Practice only.','MULTIPLAYER_GAME_BOTS_PRACTICE_ONLY',422);
        if($mode==='practice')$out[$key]=$level;
    }
    return $out;
}

function puppy_panic_settings_projection(array $settings, string $mode, array $definition = []): array
{
    $settings = puppy_panic_validate_settings($settings, $mode, $definition);
    return [
        'label' => 'Game Options',
        'description' => 'Two through five players, including optional Practice bots. Last puppy wrangler still in the game wins.',
        'classificationLabel' => 'Accepted deck options',
        'controls' => [[
            'key' => 'mischiefPack',
            'type' => 'button-choice',
            'value' => $settings['mischiefPack'],
            'defaultValue' => 'core',
            'label' => 'Deck',
            'description' => 'The match-wide deck choice is locked after acceptance.',
            'options' => [
                ['value' => 'core', 'label' => 'Core deck - Default', 'description' => 'The original 52-card deck.'],
                ['value' => 'mischief', 'label' => 'Mischief Pack', 'description' => 'Adds Under the Couch, Trainer\'s Plan, Fetch This!, and Toy Basket Flip.'],
            ],
        ]],
    ];
}

function puppy_panic_rules_projection(array $settings, string $mode, array $definition = []): array
{
    $settings = puppy_panic_validate_settings($settings, $mode, $definition);
    return [
        'label' => 'Game rules',
        'description' => 'Play any number of cards, then draw. Survive every Chaos Puppy and be the last active player.',
        'sections' => [
            ['label' => 'Setup', 'text' => 'Each player receives seven ordinary cards and one Calm Down. The draw pile receives one fewer Chaos Puppy than the player count. ' . ($settings['mischiefPack'] === 'mischief' ? 'The accepted Mischief Pack is included.' : 'The Core deck is active.')],
            ['label' => 'A turn', 'text' => 'Play zero or more cards, then draw once. Puppy Pile-On transfers the current turn debt and adds two. Nap Time removes one required turn.'],
            ['label' => 'Chaos Puppy', 'text' => 'A drawn Chaos Puppy must be calmed with one Calm Down and secretly returned anywhere in the draw pile. Without Calm Down, that player is out.'],
            ['label' => 'Actions and counters', 'text' => 'Puppy Cam privately views the next three cards. Squirrel! shuffles. Puppy Eyes requests a card chosen privately by its owner. Not Today! counters an action; each additional counter reverses the result again.'],
            ['label' => 'Matching cards', 'text' => 'Two matching family cards steal one random card. Three matching family cards request one named card. Five different titles retrieve one eligible public discard.'],
            ['label' => 'Privacy', 'text' => 'Hands, future-card views, reordering, requested-card choices, draw order, and reinsertion positions are visible only to the authorized player.'],
        ],
    ];
}

function puppy_panic_card_specs(bool $includeMischief = true): array
{
    $specs = [
        ['midnight-zoomies', 'Midnight Zoomies', 'chaos', 'chaos', 1],
        ['mudroom-stampede', 'Mudroom Stampede', 'chaos', 'chaos', 1],
        ['shoe-shredder', 'Shoe Shredder', 'chaos', 'chaos', 1],
        ['couch-cushion-catastrophe', 'Couch-Cushion Catastrophe', 'chaos', 'chaos', 1],
        ['squeaky-toy', 'Squeaky Toy', 'calm', 'calm', 1],
        ['peanut-butter-puzzle', 'Peanut Butter Puzzle', 'calm', 'calm', 1],
        ['belly-rub', 'Belly Rub', 'calm', 'calm', 1],
        ['treat-trail', 'Treat Trail', 'calm', 'calm', 1],
        ['cozy-blanket', 'Cozy Blanket', 'calm', 'calm', 1],
        ['good-pup', 'Who\'s a Good Pup?', 'calm', 'calm', 1],
        ['puppy-pile-on', 'Puppy Pile-On', 'action', 'pile-on', 4],
        ['nap-time', 'Nap Time', 'action', 'nap', 4],
        ['puppy-cam', 'Puppy Cam', 'action', 'peek', 5],
        ['squirrel', 'Squirrel!', 'action', 'shuffle', 4],
        ['puppy-eyes', 'Puppy Eyes', 'action', 'favor', 4],
        ['not-today', 'Not Today!', 'action', 'counter', 5],
        ['sock-bandit', 'Sock Bandit', 'matching', 'matching', 4],
        ['doorbell-detective', 'Doorbell Detective', 'matching', 'matching', 4],
        ['bubble-beard-bath-pup', 'Bubble-Beard Bath Pup', 'matching', 'matching', 4],
        ['vacuum-nemesis', 'Vacuum Nemesis', 'matching', 'matching', 4],
    ];
    if ($includeMischief) {
        $specs = array_merge($specs, [
            ['under-the-couch', 'Under the Couch', 'mischief', 'bottom-draw', 4],
            ['trainers-plan', 'Trainer\'s Plan', 'mischief', 'reorder', 4],
            ['fetch-this', 'Fetch This!', 'mischief', 'target-attack', 3],
            ['toy-basket-flip', 'Toy Basket Flip', 'mischief', 'flip', 3],
        ]);
    }
    return $specs;
}

function puppy_panic_catalog(bool $includeMischief = true): array
{
    static $cache=[];
    if(isset($cache[(int)$includeMischief]))return $cache[(int)$includeMischief];
    $catalog = [];
    foreach (puppy_panic_card_specs($includeMischief) as [$slug, $title, $kind, $effect, $count]) {
        for ($copy = 1; $copy <= $count; $copy++) {
            $id = $slug . ':' . $copy;
            $catalog[$id] = compact('id', 'slug', 'title', 'kind', 'effect');
        }
    }
    return $cache[(int)$includeMischief]=$catalog;
}

function puppy_panic_card(array $state, string $cardId): array
{
    $card = puppy_panic_catalog(($state['settings']['mischiefPack'] ?? 'core') === 'mischief')[$cardId] ?? null;
    if (!is_array($card)) throw new MultiplayerGameException('Choose a valid card.', 'PUPPY_PANIC_CARD_INVALID', 422);
    return $card;
}

function puppy_panic_sort_hand(array $state, array $cards): array
{
    $order = ['calm' => 0, 'action' => 1, 'matching' => 2, 'mischief' => 3, 'chaos' => 4];
    usort($cards, static function (string $left, string $right) use ($state, $order): int {
        $a = puppy_panic_card($state, $left); $b = puppy_panic_card($state, $right);
        $ap = $a['slug'] === 'squeaky-toy' ? -1 : ($order[$a['kind']] ?? 9);
        $bp = $b['slug'] === 'squeaky-toy' ? -1 : ($order[$b['kind']] ?? 9);
        return [$ap, $a['title'], $a['id']] <=> [$bp, $b['title'], $b['id']];
    });
    return array_values($cards);
}

function puppy_panic_derive_randomness(string $canonicalReveal, string $actionType, array $payload, array $context): array
{
    $settings = puppy_panic_validate_settings(
        array_intersect_key((array)($context['settings'] ?? []), ['mischiefPack' => true]),
        (string)($context['mode'] ?? 'practice')
    );
    $actionType=match($actionType){'bot-deal'=>'deal','bot-settle-random'=>'settle-random',default=>$actionType};
    $seed = hash('sha256', $canonicalReveal . '|puppy-panic|' . $actionType);
    if ($actionType === 'deal') {
        $deck = array_keys(puppy_panic_catalog($settings['mischiefPack'] === 'mischief'));
        $players = array_values(array_filter((array)($context['members'] ?? []), static fn(array $member): bool => in_array((string)($member['role'] ?? ''), ['master', 'player'], true) && (string)($member['membershipStatus'] ?? '') === 'active'));
        $ids=array_map(static fn($m)=>(int)$m['userId'],$players);$seats=[];foreach($players as$m)$seats[(int)$m['seat']]=(int)$m['userId'];
        [$allPlayers]=puppy_panic_bot_fill_seats($ids,(array)($context['settings']??[]),(string)($context['mode']??'practice'),$seats);
        return [
            'deck' => ocx_game_random_permutation($seed, $deck, 'puppy-panic-deal'),
            'readySeed' => hash('sha256', $seed . '|ready'),
            'starterOffset' => $allPlayers ? hexdec(substr($seed, 0, 8)) % count($allPlayers) : 0,
        ];
    }
    return ['seed' => $seed];
}

function puppy_panic_initial_state(array $playerUserIds, array $context = []): array
{
    $players = array_values(array_unique(array_map('intval', $playerUserIds)));
    if (count($players) < 1 || count($players) > 5 || min($players) < 1) throw new MultiplayerGameException('This game requires two through five authenticated players.', 'PUPPY_PANIC_PLAYER_SET_INVALID', 422);
    $settings = puppy_panic_validate_settings((array)($context['settings'] ?? []), (string)($context['mode'] ?? 'practice'));
    [$players,$bots]=puppy_panic_bot_fill_seats($players,$settings,(string)($context['mode']??'practice'),(array)($context['humanSeats']??[]));
    if(count($players)<2||count($players)>5)throw new MultiplayerGameException('Puppy Panic needs two through five players or Practice bots.','PUPPY_PANIC_PLAYER_SET_INVALID',422);
    return [
        'schemaVersion' => PUPPY_PANIC_STATE_SCHEMA_VERSION,
        'bots'=>$bots,'botSequence'=>0,
        'settings' => $settings,
        'turnOrder' => $players,
        'turnIndex' => 0,
        'phase' => 'deal',
        'hands' => array_fill_keys(array_map('strval', $players), []),
        'drawPile' => [],
        'discardPile' => [],
        'eliminated' => array_fill_keys(array_map('strval', $players), false),
        'owedTurns' => 1,
        'pendingAction' => null,
        'pendingChoice' => null,
        'privatePeek' => null,
        'actionSequence' => 0,
        'history' => [],
        'starterUserId' => $players[0],
        'starterReason' => 'Verified randomness chooses the starting player.',
        'completed' => false,
        'winnerUserId' => null,
        'terminalReason' => null,
    ];
}

function puppy_panic_turn_user(array $state): int
{
    return (int)($state['turnOrder'][(int)($state['turnIndex'] ?? 0)] ?? 0);
}

function puppy_panic_active_users(array $state): array
{
    return array_values(array_filter(array_map('intval', (array)($state['turnOrder'] ?? [])), static fn(int $userId): bool => empty($state['eliminated'][(string)$userId])));
}

function puppy_panic_next_index(array $state, int $fromIndex): int
{
    $count = count((array)$state['turnOrder']);
    for ($offset = 1; $offset <= $count; $offset++) {
        $index = ($fromIndex + $offset) % $count;
        if (empty($state['eliminated'][(string)$state['turnOrder'][$index]])) return $index;
    }
    return $fromIndex;
}

function puppy_panic_add_history(array &$state, string $type, int $userId, string $summary, array $extra = []): void
{
    $state['actionSequence'] = (int)$state['actionSequence'] + 1;
    $state['lastAction'] = array_merge(['sequence' => $state['actionSequence'], 'type' => $type, 'userId' => $userId, 'summary' => $summary], $extra);
    $state['history'][] = $state['lastAction'];
    if (count($state['history']) > 80) $state['history'] = array_slice($state['history'], -80);
}

function puppy_panic_remove_cards(array &$state, int $userId, array $cardIds): void
{
    $hand = array_values((array)($state['hands'][(string)$userId] ?? []));
    foreach ($cardIds as $cardId) {
        $index = array_search((string)$cardId, $hand, true);
        if ($index === false) throw new MultiplayerGameException('That card is no longer in your hand.', 'PUPPY_PANIC_CARD_MISSING', 409);
        array_splice($hand, (int)$index, 1);
    }
    $state['hands'][(string)$userId] = puppy_panic_sort_hand($state, $hand);
}

function puppy_panic_discard(array &$state, array $cardIds): void
{
    foreach ($cardIds as $cardId) $state['discardPile'][] = (string)$cardId;
}

function puppy_panic_result(array $state, ?int $winnerUserId): array
{
    $scores = [];
    foreach ((array)$state['turnOrder'] as $userId) $scores[(string)(int)$userId] = $winnerUserId !== null && (int)$userId === $winnerUserId ? 1 : 0;
    return ocx_game_result_from_scores($scores);
}

function puppy_panic_finish(array $state, ?int $winnerUserId, string $reason): array
{
    $state['completed'] = true; $state['phase'] = 'completed'; $state['winnerUserId'] = $winnerUserId; $state['terminalReason'] = $reason;
    return ['state' => $state, 'turnUserId' => null, 'terminal' => true, 'result' => puppy_panic_result($state, $winnerUserId)];
}

function puppy_panic_advance(array &$state, int $owedTurns = 1, ?int $targetUserId = null): int
{
    if ($targetUserId !== null) {
        $index = array_search($targetUserId, array_map('intval', $state['turnOrder']), true);
        if ($index === false || !empty($state['eliminated'][(string)$targetUserId])) throw new MultiplayerGameException('Choose an active player.', 'PUPPY_PANIC_TARGET_INVALID', 422);
        $state['turnIndex'] = (int)$index;
    } else {
        $state['turnIndex'] = puppy_panic_next_index($state, (int)$state['turnIndex']);
    }
    $state['owedTurns'] = max(1, $owedTurns);
    $state['phase'] = 'playing'; $state['pendingAction'] = null; $state['pendingChoice'] = null; $state['privatePeek'] = null;
    return puppy_panic_turn_user($state);
}

function puppy_panic_complete_required_turn(array &$state): int
{
    if ((int)$state['owedTurns'] > 1) {
        $state['owedTurns'] = (int)$state['owedTurns'] - 1;
        $state['phase'] = 'playing'; $state['pendingAction'] = null; $state['pendingChoice'] = null; $state['privatePeek'] = null;
        return puppy_panic_turn_user($state);
    }
    return puppy_panic_advance($state);
}

function puppy_panic_begin_deal(array &$state, array $verified): int
{
    $full = array_keys(puppy_panic_catalog($state['settings']['mischiefPack'] === 'mischief'));
    $deck = array_values(array_map('strval', (array)($verified['deck'] ?? [])));
    if (count($deck) !== count($full) || count(array_unique($deck)) !== count($full) || array_diff($deck, $full) || array_diff($full, $deck)) throw new MultiplayerGameException('The verified deal is unavailable.', 'PUPPY_PANIC_RANDOMNESS_INVALID', 409);
    $ordinary = []; $calm = []; $chaos = [];
    foreach ($deck as $cardId) {
        $kind = puppy_panic_card($state, $cardId)['kind'];
        if ($kind === 'chaos') $chaos[] = $cardId; elseif ($kind === 'calm') $calm[] = $cardId; else $ordinary[] = $cardId;
    }
    $players = array_map('intval', $state['turnOrder']);
    $state['hands'] = array_fill_keys(array_map('strval', $players), []);
    for ($round = 0; $round < 7; $round++) foreach ($players as $userId) $state['hands'][(string)$userId][] = array_shift($ordinary);
    foreach ($players as $userId) $state['hands'][(string)$userId][] = array_shift($calm);
    $calmToReturn = count($players) === 2 ? 2 : count($calm);
    $ready = array_merge($ordinary, array_slice($calm, 0, $calmToReturn), array_slice($chaos, 0, count($players) - 1));
    $seed = (string)($verified['readySeed'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $seed)) throw new MultiplayerGameException('The verified draw pile is unavailable.', 'PUPPY_PANIC_RANDOMNESS_INVALID', 409);
    $state['drawPile'] = ocx_game_random_permutation($seed, $ready, 'puppy-panic-ready');
    foreach ($players as $userId) $state['hands'][(string)$userId] = puppy_panic_sort_hand($state, $state['hands'][(string)$userId]);
    $state['turnIndex'] = max(0, min(count($players) - 1, (int)($verified['starterOffset'] ?? 0)));
    $state['starterUserId'] = puppy_panic_turn_user($state); $state['phase'] = 'playing'; $state['owedTurns'] = 1;
    puppy_panic_add_history($state, 'deal', 0, 'The verified deck was dealt and a starting player was chosen.');
    return puppy_panic_turn_user($state);
}

function puppy_panic_draw(array &$state, int $actorUserId, bool $fromBottom): array
{
    if ($state['drawPile'] === []) throw new MultiplayerGameException('The draw pile is empty.', 'PUPPY_PANIC_DRAW_EMPTY', 409);
    $cardId = $fromBottom ? array_shift($state['drawPile']) : array_pop($state['drawPile']);
    $card = puppy_panic_card($state, (string)$cardId); $state['privatePeek'] = null;
    if ($card['kind'] === 'chaos') {
        $calmCards = array_values(array_filter((array)$state['hands'][(string)$actorUserId], fn(string $held): bool => puppy_panic_card($state, $held)['kind'] === 'calm'));
        $state['phase'] = 'chaos'; $state['pendingChoice'] = ['kind' => 'chaos', 'actorUserId' => $actorUserId, 'chaosCardId' => $cardId, 'calmCards' => $calmCards];
        puppy_panic_add_history($state, 'chaos', $actorUserId, 'A Chaos Puppy was drawn.');
        return ['state' => $state, 'turnUserId' => $actorUserId];
    }
    $state['hands'][(string)$actorUserId][] = $cardId;
    $state['hands'][(string)$actorUserId] = puppy_panic_sort_hand($state, $state['hands'][(string)$actorUserId]);
    puppy_panic_add_history($state, $fromBottom ? 'bottom-draw' : 'draw', $actorUserId, $fromBottom ? 'A card was drawn from the bottom.' : 'A card was drawn.');
    $turnUserId = puppy_panic_complete_required_turn($state);
    return ['state' => $state, 'turnUserId' => $turnUserId];
}

function puppy_panic_target(array $state, int $actorUserId, mixed $value): int
{
    $target = filter_var($value, FILTER_VALIDATE_INT);
    if ($target === false || (int)$target === $actorUserId || !in_array((int)$target, puppy_panic_active_users($state), true)) throw new MultiplayerGameException('Choose another active player.', 'PUPPY_PANIC_TARGET_INVALID', 422);
    return (int)$target;
}

function puppy_panic_begin_pending(array &$state, int $actorUserId, string $effect, array $cardIds, array $payload, array $context): array
{
    puppy_panic_remove_cards($state, $actorUserId, $cardIds); puppy_panic_discard($state, $cardIds);
    $state['phase'] = 'pending-action';
    $state['pendingAction'] = [
        'actorUserId' => $actorUserId,
        'effect' => $effect,
        'cardIds' => array_values($cardIds),
        'payload' => $payload,
        'counterDepth' => 0,
        'settleAfterUnixMs' => (int)($context['nowUnixMs'] ?? 0) + PUPPY_PANIC_COUNTER_WINDOW_MS,
        'requiresRandomness' => in_array($effect, ['shuffle', 'pair-steal'], true),
    ];
    puppy_panic_add_history($state, 'action-pending', $actorUserId, 'An action was played and may be countered.', ['effect' => $effect]);
    return ['state' => $state, 'turnUserId' => $actorUserId];
}

function puppy_panic_eliminate(array $state, int $userId, string $reason): array
{
    $state['eliminated'][(string)$userId] = true; $state['pendingChoice'] = null; $state['pendingAction'] = null;
    puppy_panic_add_history($state, 'eliminated', $userId, 'A player is out.', ['reason' => $reason]);
    $active = puppy_panic_active_users($state);
    if (count($active) <= 1) return puppy_panic_finish($state, $active[0] ?? null, 'last-player-standing');
    $turnUserId = puppy_panic_advance($state);
    return ['state' => $state, 'turnUserId' => $turnUserId];
}

function puppy_panic_settle_pending(array $state, int $actorUserId, string $action, array $context): array
{
    $pending = (array)($state['pendingAction'] ?? []);
    if ((string)$state['phase'] !== 'pending-action' || $pending === []) throw new MultiplayerGameException('There is no action to settle.', 'PUPPY_PANIC_SETTLE_INVALID', 409);
    $owner = (int)$pending['actorUserId'];
    if ($actorUserId !== $owner) throw new MultiplayerGameException('Only the action owner may settle it.', 'PUPPY_PANIC_SETTLE_INVALID', 403);
    if ((int)($context['nowUnixMs'] ?? 0) < (int)($pending['settleAfterUnixMs'] ?? 0)) throw new MultiplayerGameException('The counter window is still open.', 'PUPPY_PANIC_COUNTER_OPEN', 409);
    $requiresRandomness = !empty($pending['requiresRandomness']);
    if (($action === 'settle-random') !== $requiresRandomness) throw new MultiplayerGameException('The action settlement is missing its required authority.', 'PUPPY_PANIC_RANDOMNESS_REQUIRED', 409);
    $effect = (string)$pending['effect']; $payload = (array)$pending['payload']; $state['pendingAction'] = null; $state['phase'] = 'playing';
    if ((int)$pending['counterDepth'] % 2 === 1) { puppy_panic_add_history($state, 'action-cancelled', $owner, 'The action was canceled by Not Today!', ['effect' => $effect]); return ['state' => $state, 'turnUserId' => $owner]; }
    if ($effect === 'pile-on') { $owed = max(1, (int)$state['owedTurns']) + 2; puppy_panic_add_history($state, 'pile-on', $owner, 'The next player owes extra turns.'); $turnUserId = puppy_panic_advance($state, $owed); return ['state' => $state, 'turnUserId' => $turnUserId]; }
    if ($effect === 'nap') { puppy_panic_add_history($state, 'nap', $owner, 'One required turn ended without drawing.'); $turnUserId = puppy_panic_complete_required_turn($state); return ['state' => $state, 'turnUserId' => $turnUserId]; }
    if ($effect === 'peek') { $state['privatePeek'] = ['userId' => $owner, 'cards' => array_reverse(array_slice($state['drawPile'], -3))]; puppy_panic_add_history($state, 'peek', $owner, 'The next three cards were viewed privately.'); return ['state' => $state, 'turnUserId' => $owner]; }
    if ($effect === 'shuffle') {
        $seed = (string)($context['authoritativeRandomness']['seed'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $seed)) throw new MultiplayerGameException('Verified shuffle authority is unavailable.', 'PUPPY_PANIC_RANDOMNESS_INVALID', 409);
        $state['privatePeek']=null; $state['drawPile'] = ocx_game_random_permutation($seed, $state['drawPile'], 'puppy-panic-shuffle'); puppy_panic_add_history($state, 'shuffle', $owner, 'The draw pile was shuffled.'); return ['state' => $state, 'turnUserId' => $owner];
    }
    if ($effect === 'favor') { $target = (int)$payload['targetUserId']; if(empty($state['hands'][(string)$target])) { puppy_panic_add_history($state,'favor-complete',$target,'No card was available to give.',['targetUserId'=>$owner]); return ['state'=>$state,'turnUserId'=>$owner]; } $state['phase'] = 'favor'; $state['pendingChoice'] = ['kind' => 'favor', 'actorUserId' => $owner, 'targetUserId' => $target]; return ['state' => $state, 'turnUserId' => $target]; }
    if ($effect === 'reorder') { $state['phase'] = 'reorder'; $state['pendingChoice'] = ['kind' => 'reorder', 'actorUserId' => $owner, 'cards' => array_reverse(array_slice($state['drawPile'], -3))]; return ['state' => $state, 'turnUserId' => $owner]; }
    if ($effect === 'target-attack') { $target = (int)$payload['targetUserId']; $owed = max(1, (int)$state['owedTurns']) + 2; puppy_panic_add_history($state, 'fetch-this', $owner, 'A chosen player now owes extra turns.', ['targetUserId' => $target]); $turnUserId = puppy_panic_advance($state, $owed, $target); return ['state' => $state, 'turnUserId' => $turnUserId]; }
    if ($effect === 'flip') { $state['privatePeek']=null; if (count($state['drawPile']) > 1) { $bottom = array_shift($state['drawPile']); $top = array_pop($state['drawPile']); array_unshift($state['drawPile'], $top); $state['drawPile'][] = $bottom; } puppy_panic_add_history($state, 'flip', $owner, 'The top and bottom cards traded places.'); return ['state' => $state, 'turnUserId' => $owner]; }
    if ($effect === 'bottom-draw') return puppy_panic_draw($state, $owner, true);
    if ($effect === 'pair-steal') {
        $target = (int)$payload['targetUserId']; $hand = array_values((array)$state['hands'][(string)$target]);
        if ($hand !== []) { $seed = (string)($context['authoritativeRandomness']['seed'] ?? ''); $index = hexdec(substr($seed, 0, 8)) % count($hand); $cardId = $hand[$index]; array_splice($hand, $index, 1); $state['hands'][(string)$target] = $hand; $state['hands'][(string)$owner][] = $cardId; $state['hands'][(string)$owner] = puppy_panic_sort_hand($state, $state['hands'][(string)$owner]); }
        puppy_panic_add_history($state, 'pair-steal', $owner, 'A random card was stolen.', ['targetUserId' => $target]); return ['state' => $state, 'turnUserId' => $owner];
    }
    if ($effect === 'trio-request') {
        $target = (int)$payload['targetUserId']; $title = (string)$payload['requestedTitle']; $targetHand = array_values((array)$state['hands'][(string)$target]); $found = false;
        foreach ($targetHand as $index => $cardId) if (puppy_panic_card($state, $cardId)['title'] === $title) { array_splice($targetHand, $index, 1); $state['hands'][(string)$owner][] = $cardId; $found = true; break; }
        $state['hands'][(string)$target] = $targetHand; $state['hands'][(string)$owner] = puppy_panic_sort_hand($state, $state['hands'][(string)$owner]);
        puppy_panic_add_history($state, 'trio-request', $owner, $found ? 'The requested card was transferred.' : 'The requested card was not held.', ['targetUserId' => $target, 'requestedTitle' => $title]); return ['state' => $state, 'turnUserId' => $owner];
    }
    if ($effect === 'discard-retrieve') {
        $cardId = (string)$payload['retrieveCardId']; $index = array_search($cardId, $state['discardPile'], true);
        if ($index !== false) { array_splice($state['discardPile'], (int)$index, 1); $state['hands'][(string)$owner][] = $cardId; $state['hands'][(string)$owner] = puppy_panic_sort_hand($state, $state['hands'][(string)$owner]); }
        puppy_panic_add_history($state, 'discard-retrieve', $owner, 'An eligible discarded card returned to a hand.'); return ['state' => $state, 'turnUserId' => $owner];
    }
    throw new MultiplayerGameException('The pending action is unsupported.', 'PUPPY_PANIC_ACTION_INVALID', 422);
}

function puppy_panic_project_state_core(array $state, int $viewerUserId, array $context): array
{
    $hands = (array)($state['hands'] ?? []);
    $drawPile = (array)($state['drawPile'] ?? []);
    $discardPile = (array)($state['discardPile'] ?? []);
    $pendingChoice = $state['pendingChoice'] ?? null;
    $pendingAction = $state['pendingAction'] ?? null;
    $projection = $state; $projection['cardCounts'] = [];
    foreach ($hands as $userId => $hand) $projection['cardCounts'][$userId] = count((array)$hand);
    $projection['hand'] = puppy_panic_sort_hand($state, (array)($hands[(string)$viewerUserId] ?? [])); unset($projection['hands']);
    $projection['drawPile'] = ['private' => true, 'count' => count($drawPile)]; $projection['discardCount'] = count($discardPile); $projection['topDiscard'] = (string)(array_slice($discardPile, -1)[0] ?? '');
    $projection['legalActions'] = []; $phase = (string)($state['phase'] ?? ''); $turnUser = (int)($context['turnUserId'] ?? puppy_panic_turn_user($state)); $active = in_array($viewerUserId, puppy_panic_active_users($state), true);
    if ($active && empty($state['completed'])) {
        $projection['legalActions'][] = 'resign';
        if ($phase === 'deal' && $turnUser === $viewerUserId) $projection['legalActions'][] = 'deal';
        if ($phase === 'playing' && $turnUser === $viewerUserId) $projection['legalActions'] = array_merge($projection['legalActions'], ['play', 'combo', 'draw']);
        if ($phase === 'pending-action') {
            $hasCounter = false; foreach ((array)($hands[(string)$viewerUserId] ?? []) as $cardId) if (puppy_panic_card($state, $cardId)['effect'] === 'counter') { $hasCounter = true; break; }
            if ($hasCounter) $projection['legalActions'][] = 'counter';
            if ((int)($state['pendingAction']['actorUserId'] ?? 0) === $viewerUserId && (int)($context['nowUnixMs'] ?? 0) >= (int)($state['pendingAction']['settleAfterUnixMs'] ?? PHP_INT_MAX)) $projection['legalActions'][] = !empty($state['pendingAction']['requiresRandomness']) ? 'settle-random' : 'settle-action';
        }
        if ($phase === 'chaos' && (int)($state['pendingChoice']['actorUserId'] ?? 0) === $viewerUserId) $projection['legalActions'] = array_merge($projection['legalActions'], ['calm', 'eliminate']);
        if ($phase === 'favor' && (int)($state['pendingChoice']['targetUserId'] ?? 0) === $viewerUserId) $projection['legalActions'][] = 'give-card';
        if ($phase === 'reorder' && (int)($state['pendingChoice']['actorUserId'] ?? 0) === $viewerUserId) $projection['legalActions'][] = 'reorder';
    }
    if ((int)($state['privatePeek']['userId'] ?? 0) !== $viewerUserId) $projection['privatePeek'] = null;
    if (is_array($pendingChoice)) {
        $choice = $pendingChoice; $projection['pendingChoice'] = ['kind' => (string)$choice['kind'], 'actorUserId' => (int)($choice['actorUserId'] ?? 0), 'targetUserId' => (int)($choice['targetUserId'] ?? 0)];
        if (((string)$choice['kind'] === 'chaos' || (string)$choice['kind'] === 'reorder') && (int)$choice['actorUserId'] === $viewerUserId) $projection['pendingChoice'] = $choice;
    }
    if (is_array($pendingAction)) $projection['pendingAction'] = array_diff_key($pendingAction, ['payload' => true, 'cardIds' => true]) + ['targetUserId'=>(int)($pendingAction['payload']['targetUserId']??0)];
    return $projection;
}

function puppy_panic_apply_action_rules(array $state, int $actorUserId, string $action, array $payload, array $context): array
{
    if ((int)($state['schemaVersion'] ?? 0) !== PUPPY_PANIC_STATE_SCHEMA_VERSION || !empty($state['completed'])) throw new MultiplayerGameException('The game state is unavailable.', 'PUPPY_PANIC_STATE_INVALID', 409);
    if (!in_array($actorUserId, array_map('intval', $state['turnOrder']), true) || !empty($state['eliminated'][(string)$actorUserId])) throw new MultiplayerGameException('Only an active player may act.', 'PUPPY_PANIC_PLAYER_INVALID', 403);
    if ($action === 'resign') return puppy_panic_eliminate($state, $actorUserId, 'resignation');
    if ($action === 'deal') { if ((string)$state['phase'] !== 'deal') throw new MultiplayerGameException('The deal is not available.', 'PUPPY_PANIC_DEAL_INVALID', 409); ocx_game_assert_turn($state, $actorUserId); $turnUserId = puppy_panic_begin_deal($state, (array)($context['authoritativeRandomness'] ?? [])); return ['state' => $state, 'turnUserId' => $turnUserId]; }
    if ($action === 'counter') {
        if ((string)$state['phase'] !== 'pending-action') throw new MultiplayerGameException('There is no action to counter.', 'PUPPY_PANIC_COUNTER_INVALID', 409);
        $cardId = trim((string)($payload['card'] ?? '')); if (puppy_panic_card($state, $cardId)['effect'] !== 'counter') throw new MultiplayerGameException('Choose Not Today! to counter.', 'PUPPY_PANIC_COUNTER_INVALID', 422);
        puppy_panic_remove_cards($state, $actorUserId, [$cardId]); puppy_panic_discard($state, [$cardId]); $state['pendingAction']['counterDepth'] = (int)$state['pendingAction']['counterDepth'] + 1; $state['pendingAction']['settleAfterUnixMs'] = (int)($context['nowUnixMs'] ?? 0) + PUPPY_PANIC_COUNTER_WINDOW_MS;
        puppy_panic_add_history($state, 'counter', $actorUserId, 'Not Today! changed whether the pending action will resolve.'); return ['state' => $state, 'turnUserId' => (int)$state['pendingAction']['actorUserId']];
    }
    if (in_array($action, ['settle-action', 'settle-random'], true)) return puppy_panic_settle_pending($state, $actorUserId, $action, $context);
    if ($action === 'calm') {
        $choice = (array)($state['pendingChoice'] ?? []); if ((string)$state['phase'] !== 'chaos' || (int)($choice['actorUserId'] ?? 0) !== $actorUserId) throw new MultiplayerGameException('There is no Chaos Puppy to calm.', 'PUPPY_PANIC_CALM_INVALID', 409);
        $cardId = trim((string)($payload['card'] ?? '')); if (!in_array($cardId, (array)$choice['calmCards'], true)) throw new MultiplayerGameException('Choose a Calm Down card from your hand.', 'PUPPY_PANIC_CALM_INVALID', 422);
        puppy_panic_remove_cards($state, $actorUserId, [$cardId]); puppy_panic_discard($state, [$cardId]); $count = count($state['drawPile']); $raw = $payload['position'] ?? 'middle';
        $position = is_numeric($raw) ? (int)$raw : match (strtolower(trim((string)$raw))) { 'top' => 0, 'near top' => (int)round($count * .2), 'middle' => (int)round($count * .5), 'near bottom' => (int)round($count * .8), 'bottom' => $count, default => -1 };
        if ($position < 0 || $position > $count) throw new MultiplayerGameException('Choose a valid private reinsertion position.', 'PUPPY_PANIC_POSITION_INVALID', 422);
        array_splice($state['drawPile'], $count - $position, 0, [(string)$choice['chaosCardId']]); $state['pendingChoice'] = null; $state['phase'] = 'playing'; puppy_panic_add_history($state, 'calm', $actorUserId, 'A Chaos Puppy was calmed and secretly returned.'); $turnUserId = puppy_panic_complete_required_turn($state); return ['state' => $state, 'turnUserId' => $turnUserId];
    }
    if ($action === 'eliminate') { $choice = (array)($state['pendingChoice'] ?? []); if ((string)$state['phase'] !== 'chaos' || (int)($choice['actorUserId'] ?? 0) !== $actorUserId || (array)($choice['calmCards'] ?? []) !== []) throw new MultiplayerGameException('Use an available Calm Down card.', 'PUPPY_PANIC_ELIMINATION_INVALID', 409); return puppy_panic_eliminate($state, $actorUserId, 'chaos-puppy'); }
    if ($action === 'give-card') {
        $choice = (array)($state['pendingChoice'] ?? []); if ((string)$state['phase'] !== 'favor' || (int)($choice['targetUserId'] ?? 0) !== $actorUserId) throw new MultiplayerGameException('There is no card request for you.', 'PUPPY_PANIC_FAVOR_INVALID', 409);
        $cardId = trim((string)($payload['card'] ?? '')); $requester = (int)$choice['actorUserId']; puppy_panic_remove_cards($state, $actorUserId, [$cardId]); $state['hands'][(string)$requester][] = $cardId; $state['hands'][(string)$requester] = puppy_panic_sort_hand($state, $state['hands'][(string)$requester]); $state['phase'] = 'playing'; $state['pendingChoice'] = null; puppy_panic_add_history($state, 'favor-complete', $actorUserId, 'A privately chosen card was given.', ['targetUserId' => $requester]); return ['state' => $state, 'turnUserId' => $requester];
    }
    if ($action === 'reorder') {
        $choice = (array)($state['pendingChoice'] ?? []); if ((string)$state['phase'] !== 'reorder' || (int)($choice['actorUserId'] ?? 0) !== $actorUserId) throw new MultiplayerGameException('There are no cards to reorder.', 'PUPPY_PANIC_REORDER_INVALID', 409);
        $order = array_values(array_map('strval', (array)($payload['cards'] ?? []))); $cards = array_values(array_map('strval', (array)$choice['cards'])); $a = $order; $b = $cards; sort($a); sort($b); if ($a !== $b || count($order) !== count($cards)) throw new MultiplayerGameException('Return every viewed card exactly once.', 'PUPPY_PANIC_REORDER_INVALID', 422);
        for ($i = 0; $i < count($cards); $i++) array_pop($state['drawPile']); foreach (array_reverse($order) as $cardId) $state['drawPile'][] = $cardId; $state['phase'] = 'playing'; $state['pendingChoice'] = null; $state['privatePeek']=['userId'=>$actorUserId,'cards'=>$order]; puppy_panic_add_history($state, 'reorder', $actorUserId, 'The next cards were reordered privately.'); return ['state' => $state, 'turnUserId' => $actorUserId];
    }
    if ((string)$state['phase'] !== 'playing') throw new MultiplayerGameException('Finish the current private decision first.', 'PUPPY_PANIC_ACTION_BLOCKED', 409);
    ocx_game_assert_turn($state, $actorUserId);
    if ($action === 'draw') return puppy_panic_draw($state, $actorUserId, false);
    if ($action === 'play') {
        $cardId = trim((string)($payload['card'] ?? '')); $card = puppy_panic_card($state, $cardId);
        if (in_array($card['kind'], ['calm', 'chaos', 'matching'], true) || $card['effect'] === 'counter') throw new MultiplayerGameException('That card is not played by itself now.', 'PUPPY_PANIC_PLAY_INVALID', 422);
        $clean = []; if (in_array($card['effect'], ['favor', 'target-attack'], true)) $clean['targetUserId'] = puppy_panic_target($state, $actorUserId, $payload['targetUserId'] ?? null);
        return puppy_panic_begin_pending($state, $actorUserId, $card['effect'], [$cardId], $clean, $context);
    }
    if ($action === 'combo') {
        $cardIds = array_values(array_unique(array_map('strval', (array)($payload['cards'] ?? [])))); foreach ($cardIds as $cardId) puppy_panic_card($state, $cardId);
        $cards = array_map(static fn(string $id): array => puppy_panic_card($state, $id), $cardIds); $titles = array_values(array_unique(array_column($cards, 'title'))); $effect = ''; $clean = [];
        if (count($cardIds) === 2 && count($titles) === 1 && $cards[0]['kind'] === 'matching') { $effect = 'pair-steal'; $clean['targetUserId'] = puppy_panic_target($state, $actorUserId, $payload['targetUserId'] ?? null); }
        elseif (count($cardIds) === 3 && count($titles) === 1 && $cards[0]['kind'] === 'matching') { $effect = 'trio-request'; $clean['targetUserId'] = puppy_panic_target($state, $actorUserId, $payload['targetUserId'] ?? null); $clean['requestedTitle'] = trim((string)($payload['requestedTitle'] ?? '')); if ($clean['requestedTitle'] === '') throw new MultiplayerGameException('Choose the card title to request.', 'PUPPY_PANIC_COMBO_INVALID', 422); }
        elseif (count($cardIds) === 5 && count($titles) === 5) { $effect = 'discard-retrieve'; $clean['retrieveCardId'] = trim((string)($payload['retrieveCardId'] ?? '')); $candidate = puppy_panic_card($state, $clean['retrieveCardId']); if (!in_array($clean['retrieveCardId'], $state['discardPile'], true) || in_array($candidate['kind'], ['chaos', 'calm'], true) || in_array($clean['retrieveCardId'], $cardIds, true)) throw new MultiplayerGameException('Choose an eligible earlier discard.', 'PUPPY_PANIC_COMBO_INVALID', 422); }
        else throw new MultiplayerGameException('Choose a matching pair, matching trio, or five different titles.', 'PUPPY_PANIC_COMBO_INVALID', 422);
        return puppy_panic_begin_pending($state, $actorUserId, $effect, $cardIds, $clean, $context);
    }
    throw new MultiplayerGameException('This game action is not supported.', 'PUPPY_PANIC_ACTION_INVALID', 422);
}
