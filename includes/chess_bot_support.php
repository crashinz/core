<?php
declare(strict_types=1);

const CHESS_BOT_ID = -6402;
const CHESS_BOT_ENGINE = 'stockfish.js-18.0.0-lite-single';

function chess_bot_choices(): array
{
    return [
        ['value' => 'none', 'label' => 'None'],
        ['value' => 'elo-1320', 'label' => '1320 · Casual'],
        ['value' => 'elo-1600', 'label' => '1600 · Intermediate'],
        ['value' => 'elo-1900', 'label' => '1900 · Advanced'],
        ['value' => 'elo-2200', 'label' => '2200 · Master level'],
        ['value' => 'elo-2500', 'label' => '2500 · Grandmaster level'],
    ];
}

function chess_bot_strength_note(): string
{
    return 'Higher ratings mean a harder opponent. Ratings and level names are approximate bot strengths, not certified player ratings or titles.';
}

function chess_project_virtual_members(PDO $pdo, array $state, array $context): array
{
    return ($context['mode'] ?? '') === 'practice' ? array_values((array)($state['bots'] ?? [])) : [];
}

/** Standard FEN uses rank eight first, matching the authoritative board rows. */
function chess_bot_fen(array $state): string
{
    $ranks = [];
    foreach ($state['board'] as $row) {
        $rank = ''; $empty = 0;
        foreach ($row as $piece) {
            if ($piece === null) { $empty++; continue; }
            if ($empty) { $rank .= $empty; $empty = 0; }
            $rank .= $piece[0] === 'w' ? $piece[1] : strtolower($piece[1]);
        }
        if ($empty) $rank .= $empty;
        $ranks[] = $rank;
    }
    $rights = '';
    foreach (['wK' => 'K', 'wQ' => 'Q', 'bK' => 'k', 'bQ' => 'q'] as $key => $letter) if (!empty($state['castling'][$key])) $rights .= $letter;
    $ep = $state['enPassant'] ?? null;
    return implode('/', $ranks) . ' ' . chess_color_for_user($state, (int)$state['turnOrder'][(int)$state['turnIndex']])
        . ' ' . ($rights ?: '-') . ' ' . (is_array($ep) ? chess_bot_square_name($ep) : '-')
        . ' ' . (int)$state['halfmoveClock'] . ' ' . (int)$state['fullmoveNumber'];
}

function chess_bot_square_name(array $square): string
{
    return chr(97 + (int)$square[1]) . (8 - (int)$square[0]);
}

function chess_bot_uci(array $move): string
{
    return chess_bot_square_name($move['from']) . chess_bot_square_name($move['to']) . strtolower((string)($move['promotion'] ?? ''));
}

function chess_bot_position_key(array $state): string
{
    return hash('sha256', json_encode([chess_bot_fen($state), $state['botPosition'] ?? null, $state['drawOfferBy'] ?? null], JSON_THROW_ON_ERROR));
}

function chess_bot_track_move(array &$state, array $move): void
{
    if (empty($state['bots'])) return;
    if (empty($state['botPosition']) || (int)$state['halfmoveClock'] === 0) {
        $state['botPosition'] = ['fen' => chess_bot_fen($state), 'moves' => []];
    } else {
        $state['botPosition']['moves'][] = chess_bot_uci($move);
    }
}

function chess_bot_task(array $state, int $viewer, array $context): ?array
{
    if (($context['mode'] ?? '') !== 'practice' || ($context['status'] ?? '') !== 'active'
        || !in_array($context['viewerRole'] ?? '', ['master', 'player'], true)
        || $viewer <= 0 || !in_array($viewer, $state['turnOrder'] ?? [], true)
        || empty($state['bots'][(string)CHESS_BOT_ID]) || !empty($state['completed'])) return null;
    $turn = (int)($state['turnOrder'][(int)($state['turnIndex'] ?? 0)] ?? 0);
    $offer = (int)($state['drawOfferBy'] ?? 0);
    if ($turn !== CHESS_BOT_ID && $offer !== $viewer) return null;
    $difficulty = (string)$state['bots'][(string)CHESS_BOT_ID]['difficulty'];
    $budget = 1200;
    if (($state['clock']['kind'] ?? 'none') !== 'none') {
        $clock = $state['clocks'][(string)CHESS_BOT_ID];
        $remaining = (int)$clock['remainingSeconds'];
        if (!empty($clock['turnStartedAt'])) $remaining -= max(0, time() - (int)strtotime($clock['turnStartedAt']));
        $budget = max(50, min($budget, (int)($remaining * 100)));
    }
    $position = $state['botPosition'] ?? ['fen' => chess_bot_fen($state), 'moves' => []];
    return ['positionKey' => chess_bot_position_key($state), 'engine' => CHESS_BOT_ENGINE,
        'rating' => (int)substr($difficulty, 4), 'moveTimeMs' => $budget,
        'position' => 'position fen ' . $position['fen'] . (empty($position['moves']) ? '' : ' moves ' . implode(' ', $position['moves'])),
        'respondToDraw' => $offer === $viewer];
}

/** The browser's engine proposes a move; only the existing PHP reducer may apply it. */
function chess_apply_action(array $state, int $actorUserId, string $action, array $payload, array $context): array
{
    if ($actorUserId <= 0 || !in_array($actorUserId, $state['turnOrder'] ?? [], true)) {
        throw new MultiplayerGameException('Only an authenticated Chess participant may act.', 'CHESS_PLAYER_INVALID', 403);
    }
    if (!empty($state['bots']) && ($context['mode'] ?? 'practice') !== 'practice') {
        throw new MultiplayerGameException('Games with bots are Practice only.', 'MULTIPLAYER_GAME_BOTS_PRACTICE_ONLY', 422);
    }
    $trace = null;
    if ($action === 'bot-step') {
        if (($context['mode'] ?? '') !== 'practice' || empty($state['bots'][(string)CHESS_BOT_ID])) {
            throw new MultiplayerGameException('A Practice Chess bot is not available.', 'CHESS_BOT_UNAVAILABLE', 409);
        }
        if (!hash_equals(chess_bot_position_key($state), (string)($payload['positionKey'] ?? ''))) {
            throw new MultiplayerGameException('The Chess position changed. Refresh before retrying.', 'CHESS_BOT_POSITION_STALE', 409);
        }
        $actorUserId = CHESS_BOT_ID;
        $trace = ['reason' => 'browser-uci-proposal-server-validated', 'difficulty' => $state['bots'][(string)CHESS_BOT_ID]['difficulty'],
            'engine' => CHESS_BOT_ENGINE, 'elapsedMs' => max(0, min(60000, (int)($payload['elapsedMs'] ?? 0))),
            'publicObservation' => ['fen' => chess_bot_fen($state), 'positionKey' => chess_bot_position_key($state)]];
        if ((int)($state['drawOfferBy'] ?? 0) > 0) {
            $action = 'decline-draw'; $payload = [];
        } else {
            ocx_game_assert_turn($state, CHESS_BOT_ID);
            $claims = chess_draw_claim_eligibility($state, CHESS_BOT_ID);
            if (!empty($claims['threefold']) || !empty($claims['fiftyMove'])) {
                $action = 'claim-draw'; $payload = ['claim' => !empty($claims['threefold']) ? 'threefold' : 'fifty-move'];
            } else {
                $uci = (string)($payload['move'] ?? '');
                if (!preg_match('/^[a-h][1-8][a-h][1-8][qrbn]?$/D', $uci)) throw new MultiplayerGameException('The Chess engine returned an invalid move.', 'CHESS_BOT_MOVE_INVALID', 422);
                $action = 'move'; $payload = ['from' => [8 - (int)$uci[1], ord($uci[0]) - 97],
                    'to' => [8 - (int)$uci[3], ord($uci[2]) - 97], 'promotion' => strtoupper($uci[4] ?? '')];
                $trace['selected'] = $uci;
            }
        }
    }
    $applied = chess_apply_action_core($state, $actorUserId, $action, $payload, $context);
    if (function_exists('game_recording_observe')) game_recording_observe($context, 'chess', $state, $actorUserId, $action, $payload, $applied['state'], $trace);
    return $applied;
}
