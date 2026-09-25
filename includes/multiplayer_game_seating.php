<?php
declare(strict_types=1);

function multiplayer_game_has_seat_choices(array $definition): bool
{
    return in_array((string)($definition['extensionId'] ?? ''), ['spades', 'hearts', 'uno', 'dominos'], true);
}

/** Existing bot implementations; new adapters opt in here when supported. */
function multiplayer_game_bot_slots(array $definition): array
{
    return match ((string)($definition['extensionId'] ?? '')) {
        'spades', 'hearts', 'five-dice', 'dominos' => [1, 2, 3, 4],
        'uno' => range(1, 10),
        'blackjack', 'puppy-panic' => range(1,5),
        'chinese-checkers' => range(1,6),
        'battleship', 'chess', 'checkers', 'backgammon-first-party', 'nested-four', 'acey-deucy', 'eight-ball', 'tetris-versus' => [2],
        default => [],
    };
}

function multiplayer_game_bot_choices(array $definition): array
{
    if (($definition['extensionId'] ?? '') === 'eight-ball') { require_once __DIR__.'/pool_bot_support.php'; return pool_bot_choices(); }
    if (($definition['extensionId'] ?? '') === 'tetris-versus') return [['value'=>'none','label'=>'None'],['value'=>'easy','label'=>'Easy'],['value'=>'normal','label'=>'Normal'],['value'=>'expert','label'=>'Expert']];
    if (($definition['extensionId'] ?? '') === 'dominos') { require_once __DIR__.'/dominos_bot_support.php'; return dominos_bot_choices(); }
    if (($definition['extensionId'] ?? '') === 'five-dice') { require_once __DIR__ . '/five_dice_bot_support.php'; return five_dice_bot_choices(); }
    if (($definition['extensionId'] ?? '') === 'puppy-panic') { require_once __DIR__ . '/puppy_panic_bot_support.php'; return puppy_panic_bot_choices(); }
    if (($definition['extensionId'] ?? '') === 'blackjack') { require_once __DIR__ . '/blackjack_bot_support.php'; return blackjack_bot_choices(); }
    if (($definition['extensionId'] ?? '') === 'acey-deucy') { require_once __DIR__ . '/acey_deucy_bot_support.php'; return acey_deucy_bot_choices(); }
    if (($definition['extensionId'] ?? '') === 'nested-four') {
        require_once __DIR__ . '/nested_four_bot_support.php';
        return nested_four_bot_choices();
    }
    if (($definition['extensionId'] ?? '') === 'chinese-checkers') {
        require_once __DIR__ . '/chinese_checkers_bot_support.php';
        return chinese_checkers_bot_choices();
    }
    if (($definition['extensionId'] ?? '') === 'hearts') {
        require_once __DIR__ . '/hearts_bot_support.php';
        return hearts_bot_choices();
    }
    if (($definition['extensionId'] ?? '') === 'uno') {
        require_once __DIR__ . '/uno_bot_support.php';
        return uno_bot_choices();
    }
    if (($definition['extensionId'] ?? '') === 'backgammon-first-party') {
        require_once __DIR__ . '/backgammon_bot_support.php';
        return backgammon_bot_choices();
    }
    if (($definition['extensionId'] ?? '') === 'checkers') {
        require_once __DIR__ . '/checkers_bot_support.php';
        return checkers_bot_choices();
    }
    if (($definition['extensionId'] ?? '') === 'chess') {
        require_once __DIR__ . '/chess_bot_support.php';
        return chess_bot_choices();
    }
    return [['value' => 'none', 'label' => 'None'], ['value' => 'normal', 'label' => 'Normal'], ['value' => 'expert', 'label' => 'Expert']];
}

function multiplayer_game_requires_host_start(array $definition): bool
{
    return multiplayer_game_has_seat_choices($definition) || multiplayer_game_bot_slots($definition) !== [];
}

function multiplayer_game_bot_lobby_projection(array $definition, array $session, array $members, int $viewer, string $playerSetSha): array
{
    $slots = multiplayer_game_bot_slots($definition);
    if ($slots === [] || $session['status'] !== 'lobby') return [];
    $settings = json_decode((string)$session['settings_json'], true) ?: [];
    if (($definition['extensionId'] ?? '') === 'uno') {
        require_once __DIR__ . '/uno_bot_support.php';
        $settings = uno_bot_lobby_settings($settings);
    }
    $humans = [];
    $hostAccepted = false;
    $allAccepted = true;
    foreach ($members as $member) {
        if (!in_array($member['role'] ?? '', ['master', 'player'], true) || ($member['membershipStatus'] ?? '') !== 'active' || (int)$member['userId'] <= 0) continue;
        $humans[(int)$member['seat']] = $member;
        $allAccepted = $allAccepted && !empty($member['accepted']);
        if (($member['role'] ?? '') === 'master') $hostAccepted = !empty($member['accepted']);
    }
    $isHost = (int)$session['master_user_id'] === $viewer;
    $options = [];
    $readyCount = count($humans);
    foreach ($slots as $seat) {
        $occupied = isset($humans[$seat]);
        $difficulty = $occupied ? 'none' : ($settings['botSeat' . $seat . 'Difficulty'] ?? 'none');
        if (!$occupied && ($definition['extensionId'] ?? '') === 'tetris-versus') $difficulty = in_array($settings['opponent'] ?? 'human', ['easy','normal','expert'], true) ? $settings['opponent'] : 'none';
        if (!$occupied && $session['mode'] === 'practice' && $difficulty !== 'none') $readyCount++;
        $options[] = ['seat' => $seat, 'occupantName' => $humans[$seat]['displayName'] ?? '',
            'difficulty' => $difficulty, 'editable' => $isHost && !$occupied];
    }
    $practiceTable = in_array($definition['extensionId'] ?? '', ['eight-ball','tetris-versus'], true);
    $tableReady = count($humans) >= multiplayer_game_minimum_players($definition, (string)$session['mode'], $settings);
    return ['choices' => multiplayer_game_bot_choices($definition),
        'strengthNote' => match ($definition['extensionId'] ?? '') { 'five-dice'=>'Easy keeps matching dice. Normal compares one reroll; Expert plans both remaining rerolls. Both consider the scorecard and bonuses. Practice only.', 'puppy-panic'=>'Practice bots use their own hand and permitted private views. Easy draws simply; Normal uses survival cards and counters; Expert manages danger and turn debt more carefully. Both decks supported.', 'blackjack' => 'Easy uses simple hit/stand choices. Normal uses basic strategy. Expert remembers exposed cards and adjusts bets to the standings and rounds left. Bots use only visible cards. Practice only.', 'acey-deucy' => 'Practice bots follow the selected Acey Deucy rules. Easy is forgiving; Normal plans dice sequences; Expert also considers replies. These are relative levels, not ratings.', 'nested-four' => 'Practice bots remember observed pieces. Normal plans replies; Expert searches farther. Difficulty names are relative, not ratings.', 'chinese-checkers' => 'Practice bots use classical JumpStar-derived evaluation. Difficulty names are relative levels, not Elo ratings.', 'hearts' => 'Easy uses simple legal play; Normal considers passing, played cards and shooting the moon; Expert adds a short lookahead. Practice only.', 'uno' => 'Easy uses simple legal play; Normal manages colors and action cards. Practice only.', 'chess' => chess_bot_strength_note(), 'checkers' => checkers_bot_strength_note(), 'backgammon-first-party' => backgammon_bot_strength_note(), default => '' },
        'options' => $options, 'isHost' => $isHost, 'mode' => $session['mode'],
        'settingsSha256' => $session['settings_sha256'], 'playerSetSha256' => $playerSetSha,
        'showStart' => $isHost && !multiplayer_game_has_seat_choices($definition),
        'canStart' => $isHost && ($practiceTable ? $tableReady : (($definition['extensionId'] ?? '') === 'five-dice' ? $readyCount >= (int)($session['mode']==='practice'?1:2) && $readyCount<=4 : (($definition['extensionId'] ?? '') === 'uno' ? $readyCount >= 2 && $readyCount <= 10 : (($definition['extensionId'] ?? '') === 'hearts' ? in_array($readyCount, [2,4], true) : (in_array($definition['extensionId'] ?? '', ['chinese-checkers','blackjack','puppy-panic'], true) ? $readyCount>=2 && $readyCount<=(int)$definition['maxPlayers'] : $readyCount === (int)$definition['maxPlayers']))) )) && ($session['mode'] === 'practice' ? $hostAccepted : $allAccepted)];
}

/** Update one empty slot atomically with mode/acceptance; never start or displace a person. */
function multiplayer_game_set_lobby_bot(PDO $pdo, string $publicId, int $userId, int $seat, string $difficulty, string $expectedSettings, string $expectedPlayers): array
{
    $transaction = database_transaction_begin($pdo, true);
    try {
        multiplayer_game_lock_session($pdo, $publicId);
        $session = multiplayer_game_require_member($pdo, $publicId, $userId, ['master']);
        if ($session['status'] !== 'lobby') throw new MultiplayerGameException('Bot seats are locked after play begins.', 'MULTIPLAYER_GAME_SETTINGS_LOCKED', 409);
        $definition = multiplayer_game_definition($pdo, (string)$session['game_key']);
        if (!in_array($seat, multiplayer_game_bot_slots($definition), true) || !in_array($difficulty, array_column(multiplayer_game_bot_choices($definition), 'value'), true)) {
            throw new MultiplayerGameException('Choose an available bot seat and a listed strength.', 'MULTIPLAYER_GAME_BOT_CHOICE_INVALID', 422);
        }
        $playerSet = multiplayer_game_player_set($pdo, (int)$session['id']);
        if (!hash_equals((string)$session['settings_sha256'], $expectedSettings) || !hash_equals($playerSet['sha256'], $expectedPlayers)) {
            throw new MultiplayerGameException('Players or options changed. Refresh the game before changing a bot.', 'MULTIPLAYER_GAME_BOT_CHOICE_STALE', 409);
        }
        $occupied = $pdo->prepare("SELECT COUNT(*) FROM multiplayer_game_members WHERE game_session_id=? AND seat_number=? AND role IN ('master','player') AND membership_status='active'");
        $occupied->execute([(int)$session['id'], $seat]);
        if ((int)$occupied->fetchColumn() > 0) throw new MultiplayerGameException('That seat belongs to a player. Bots can only fill empty seats.', 'MULTIPLAYER_GAME_BOT_SEAT_OCCUPIED', 409);
        $settings = json_decode((string)$session['settings_json'], true) ?: [];
        if (($definition['extensionId'] ?? '') === 'tetris-versus') {
            $settings['opponent'] = $difficulty === 'none' ? 'human' : $difficulty;
        } else {
            $settings['botSeat' . $seat . 'Difficulty'] = $difficulty;
            if (($definition['extensionId'] ?? '') === 'eight-ball' && $difficulty !== 'none') $settings['tableMode'] = 'match';
        }
        $mode = $difficulty === 'none' ? (string)$session['mode'] : 'practice';
        $result = multiplayer_game_update_settings($pdo, $publicId, $userId, $settings, $mode);
        database_transaction_commit($pdo, $transaction);
        return $result;
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
}

function multiplayer_game_valid_seat_requests(array $requests, array $seatsByUser): array
{
    return array_filter($requests, static fn(array $request): bool =>
        ($seatsByUser[(int)($request['byUserId'] ?? 0)] ?? 0) === (int)($request['fromSeat'] ?? -1)
        && ($seatsByUser[(int)($request['toUserId'] ?? 0)] ?? 0) === (int)($request['toSeat'] ?? -1));
}

/** Only public membership facts are returned. Hearts has individual scoring. */
function multiplayer_game_seating_projection(array $definition, array $session, array $members, array $state, int $viewer): array
{
    if (!multiplayer_game_has_seat_choices($definition)) return [];
    $bySeat = [];
    $seatsByUser = [];
    $mySeat = null;
    foreach ($members as $member) {
        if (!in_array($member['role'] ?? '', ['master', 'player'], true) || ($member['membershipStatus'] ?? '') !== 'active') continue;
        $bySeat[(int)$member['seat']] = $member;
        $seatsByUser[(int)$member['userId']] = (int)$member['seat'];
        if ((int)$member['userId'] === $viewer) $mySeat = (int)$member['seat'];
    }
    $dominos = ($definition['extensionId'] ?? '') === 'dominos';
    $dominosSettings = json_decode((string)$session['settings_json'], true) ?: [];
    $spades = ($definition['extensionId'] ?? '') === 'spades' || ($dominos && ($dominosSettings['tableMode'] ?? '') === 'teams');
    $uno = ($definition['extensionId'] ?? '') === 'uno';
    $twoHearts = false;
    $capacity = $uno ? min(10, (int)$definition['maxPlayers']) : 4;
    $options = [];
    $settings = json_decode((string)$session['settings_json'], true) ?: [];
    if ($uno && $session['status'] === 'lobby') {
        require_once __DIR__ . '/uno_bot_support.php';
        $settings = uno_bot_lobby_settings($settings);
    }
    $plannedCount = count($bySeat);
    if ($session['mode'] === 'practice') for ($seat = 1; $seat <= $capacity; $seat++) if (!isset($bySeat[$seat]) && ($settings['botSeat'.$seat.'Difficulty'] ?? 'none') !== 'none') $plannedCount++;
    $twoHearts = !$spades && !$uno && !$dominos && $plannedCount === 2;
    $nameAt = static function(int $seat) use ($bySeat, $spades, $uno, $session, $settings): string {
        if (isset($bySeat[$seat])) return (string)$bySeat[$seat]['displayName'];
        $level = $settings['botSeat' . $seat . 'Difficulty'] ?? 'none';
        if ($session['mode'] === 'practice' && $level !== 'none') return ucfirst($level) . ' Bot ' . $seat;
        return 'Empty seat';
    };
    for ($seat = 1; $seat <= $capacity; $seat++) {
        if ($twoHearts && !isset($bySeat[$seat]) && $nameAt($seat) === 'Empty seat') continue;
        $opposite = (($seat + 1) % 4) + 1;
        // Preview the arrangement after the requested move/swap, not before it.
        $oppositeName = $nameAt($opposite);
        if ($opposite === $mySeat && $seat !== $mySeat) {
            $oppositeName = isset($bySeat[$seat]) ? (string)$bySeat[$seat]['displayName'] : ($spades && $session['mode'] === 'practice' && ($settings['botSeat' . $mySeat . 'Difficulty'] ?? 'none') !== 'none'
                ? (($settings['botSeat' . $mySeat . 'Difficulty'] ?? 'none') === 'expert' ? 'Expert' : 'Normal') . ' Bot ' . $mySeat : 'Empty seat');
        }
        $occupant = $bySeat[$seat] ?? null;
        $relationship = ($spades ? 'Your partner: ' : 'Opposite you: ') . $oppositeName;
        if ($twoHearts) {
            for ($other = 1; $other <= $capacity; $other++) if ($other !== $mySeat && $nameAt($other) !== 'Empty seat') $relationship = 'Your opponent: ' . $nameAt($other);
        } elseif ($uno) {
            $afterMove = $bySeat;
            if ($mySeat !== null && $seat !== $mySeat) {
                unset($afterMove[$mySeat]);
                if ($occupant !== null) $afterMove[$mySeat] = $occupant;
                $afterMove[$seat] = $bySeat[$mySeat];
            }
            if ($session['mode'] === 'practice') for ($botSeat = 1; $botSeat <= $capacity; $botSeat++) {
                $level = $settings['botSeat'.$botSeat.'Difficulty'] ?? 'none';
                if (!isset($afterMove[$botSeat]) && $level !== 'none') $afterMove[$botSeat] = ['displayName'=>ucfirst($level).' Bot '.$botSeat];
            }
            ksort($afterMove);
            $nextSeats = array_merge(array_filter(array_keys($afterMove), static fn(int $s): bool => $s > $seat), array_filter(array_keys($afterMove), static fn(int $s): bool => $s < $seat));
            $nextSeat = $nextSeats[0] ?? null;
            $relationship = $nextSeat === null ? 'Waiting for another player' : 'Clockwise neighbor: ' . $afterMove[$nextSeat]['displayName'];
        }
        $options[] = ['seat' => $seat, 'occupantUserId' => (int)($occupant['userId'] ?? 0),
            'current' => $seat === $mySeat,
            'occupantName' => $occupant['displayName'] ?? ($nameAt($seat) === 'Empty seat' ? 'Open seat' : $nameAt($seat)),
            'available' => $occupant === null, 'partnerSeat' => $spades ? $opposite : null,
            'label' => 'Seat ' . $seat . ' — ' . ($seat === $mySeat ? 'You' : $nameAt($seat)) . ' · ' . $relationship];
    }
    $requests = [];
    $pending = multiplayer_game_valid_seat_requests((array)($state['seatChangeRequests'] ?? []), $seatsByUser);
    foreach ($pending as $request) {
        if ((int)($request['toUserId'] ?? 0) !== $viewer && (int)($request['byUserId'] ?? 0) !== $viewer) continue;
        $request['requesterName'] = (string)($bySeat[(int)$request['fromSeat']]['displayName'] ?? 'Player');
        $requests[] = $request;
    }
    $viewerMember = $mySeat === null ? [] : $bySeat[$mySeat];
    $isHost = ($viewerMember['role'] ?? '') === 'master';
    $practice = $session['mode'] === 'practice';
    $hostAccepted = false;
    $allAccepted = true;
    foreach ($bySeat as $member) {
        if (($member['role'] ?? '') === 'master') $hostAccepted = !empty($member['accepted']);
        $allAccepted = $allAccepted && !empty($member['accepted']);
    }
    $readySeats = count($bySeat);
    if ($practice) for ($seat = 1; $seat <= $capacity; $seat++) {
        if (!isset($bySeat[$seat]) && ($settings['botSeat' . $seat . 'Difficulty'] ?? 'none') !== 'none') $readySeats++;
    }
    $readyCount = $dominos ? ($spades ? $readySeats === 4 : $readySeats >= 2 && $readySeats <= 4) : ($spades ? $readySeats === 4 : ($uno ? $readySeats >= 2 && $readySeats <= $capacity : in_array($readySeats, [2, 4], true)));
    return ['canChoose' => $session['status'] === 'lobby' && $mySeat !== null,
        'gameSessionId' => (string)$session['public_id'],
        'currentSeat' => $mySeat, 'options' => $options, 'requests' => $requests,
        'gameName' => $dominos ? 'Dominos' : ($spades ? 'Spades' : ($uno ? 'UNO' : 'Hearts')),
        'tableKind' => $uno ? 'ten' : ($twoHearts ? 'two' : 'four'),
        'isHost' => $isHost, 'mode' => $session['mode'], 'settingsSha256' => $session['settings_sha256'],
        'canAccept' => $mySeat !== null && ($isHost || !$practice) && empty($viewerMember['accepted']),
        'canStart' => $isHost && $readyCount && $pending === [] && ($practice ? $hostAccepted : $allAccepted),
        'startStatus' => !$readyCount ? ($dominos ? ($spades ? 'Teams require all four seats.' : 'Waiting for at least two players or bots.') : ($spades ? 'Waiting for four players or accepted Practice bot seats.' : ($uno ? 'UNO can start with two through ten players.' : 'Hearts can start with exactly two or four people or Practice bots.')))
            : ($pending !== [] ? 'Resolve pending seat swaps before starting.' : (!($practice ? $hostAccepted : $allAccepted) ? 'Waiting for acceptance of the current seating and options.' : 'Ready for the host to start.')),
        'description' => $dominos ? ($spades ? 'Teams: seats 1 and 3 versus seats 2 and 4. Choose a seat or request a swap.' : 'All Fives for two to four players. Choose a seat or request a swap.') : ($spades ? 'Seats 1 and 3 are partners; seats 2 and 4 are partners. Occupied seats require an approved swap.'
            : ($uno ? 'UNO is individual play. Seats run clockwise; Reverse changes the direction of play. Choose an open seat or request a swap.' : ($twoHearts ? 'Two-player Hearts: you sit opposite your opponent. Both players score individually.' : 'Hearts scores each player individually. Seats 1 and 3 sit opposite, as do 2 and 4. Choose an open seat or request a swap.')))];
}

function multiplayer_game_choose_seat(PDO $pdo, string $publicId, int $userId, int $seat, string $decision = '', string $requestId = ''): array
{
    $transaction = database_transaction_begin($pdo, true);
    try {
        multiplayer_game_lock_session($pdo, $publicId);
        $session = multiplayer_game_require_member($pdo, $publicId, $userId, ['master', 'player']);
        $definition = multiplayer_game_definition($pdo, (string)$session['game_key']);
        if (!multiplayer_game_has_seat_choices($definition) || $session['status'] !== 'lobby') throw new MultiplayerGameException('Seats may be chosen in a waiting Hearts, Spades, or UNO game.', 'MULTIPLAYER_GAME_SEAT_CHOICE_LOCKED', 409);
        $query = $pdo->prepare("SELECT user_id,seat_number FROM multiplayer_game_members WHERE game_session_id=? AND role IN ('master','player') AND membership_status='active'");
        $query->execute([(int)$session['id']]);
        $seats = [];
        foreach ($query->fetchAll() as $row) $seats[(int)$row['user_id']] = (int)$row['seat_number'];
        $state = json_decode((string)$session['state_json'], true) ?: [];
        $requests = multiplayer_game_valid_seat_requests((array)($state['seatChangeRequests'] ?? []), $seats);
        $mover = $userId;
        $swapUser = null;
        $change = false;
        $settingsHash = (string)$session['settings_sha256'];
        if ($decision !== '') {
            if (!in_array($decision, ['approve', 'decline', 'cancel'], true)) throw new MultiplayerGameException('Choose approve, decline, or cancel.', 'MULTIPLAYER_GAME_SEAT_DECISION_INVALID', 422);
            $request = $requests[$requestId] ?? null;
            if (!is_array($request) || ($decision === 'cancel' ? (int)$request['byUserId'] !== $userId : (int)$request['toUserId'] !== $userId)) throw new MultiplayerGameException('That seat request is unavailable.', 'MULTIPLAYER_GAME_SEAT_SWAP_STALE', 409);
            unset($requests[$requestId]);
            if ($decision === 'approve') {
                $mover = (int)$request['byUserId'];
                $seat = (int)$request['toSeat'];
                if (($seats[$mover] ?? 0) !== (int)$request['fromSeat'] || ($seats[$userId] ?? 0) !== $seat) throw new MultiplayerGameException('The seating changed. Request a new swap.', 'MULTIPLAYER_GAME_SEAT_SWAP_STALE', 409);
                $swapUser = $userId;
                $change = true;
            }
        } else {
            $capacity = ($definition['extensionId'] ?? '') === 'uno' ? min(10, (int)$definition['maxPlayers']) : 4;
            if ($seat < 1 || $seat > $capacity) throw new MultiplayerGameException('Choose seat 1 through ' . $capacity . '.', 'MULTIPLAYER_GAME_SEAT_INVALID', 422);
            $occupant = array_search($seat, $seats, true);
            if ($occupant !== false && $occupant !== $userId) {
                foreach ($requests as $key => $request) if ((int)$request['byUserId'] === $userId) unset($requests[$key]);
                $id = uuid_v4();
                $requests[$id] = ['id' => $id, 'byUserId' => $userId, 'toUserId' => $occupant, 'fromSeat' => $seats[$userId], 'toSeat' => $seat];
            } elseif ($occupant === false) $change = true;
        }
        if ($change) {
            $from = $seats[$mover];
            // NULL temporarily releases the unique seat inside this locked transaction.
            $pdo->prepare('UPDATE multiplayer_game_members SET seat_number=NULL WHERE game_session_id=? AND user_id=?')->execute([(int)$session['id'], $mover]);
            if ($swapUser !== null) $pdo->prepare('UPDATE multiplayer_game_members SET seat_number=? WHERE game_session_id=? AND user_id=?')->execute([$from, (int)$session['id'], $swapUser]);
            $pdo->prepare('UPDATE multiplayer_game_members SET seat_number=? WHERE game_session_id=? AND user_id=?')->execute([$seat, (int)$session['id'], $mover]);
            $pdo->prepare('DELETE FROM multiplayer_game_acceptances WHERE game_session_id=?')->execute([(int)$session['id']]);
            // Bind acceptance to this arrangement so an old browser cannot silently
            // reaccept its previous partner after an intervening seat change.
            $settingsHash = strtoupper(hash('sha256', $settingsHash . ':seats:' . $mover . ':' . $seat . ':' . (int)$session['state_version']));
            $requests = [];
        }
        $state['seatChangeRequests'] = $requests;
        $pdo->prepare('UPDATE multiplayer_game_sessions SET state_json=?,settings_sha256=?,state_version=state_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([multiplayer_game_canonical_json($state), $settingsHash, (int)$session['id']]);
        $result = multiplayer_game_project_session($pdo, $publicId, $userId);
        database_transaction_commit($pdo, $transaction);
        return $result;
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
}
