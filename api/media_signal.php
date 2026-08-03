<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_exception_handler.php';
api_install_exception_handler('media-signal', 'MEDIA_SIGNAL_FAILED', 'Media signaling is temporarily unavailable.');
require_once __DIR__ . '/../includes/base.php';
require_once __DIR__ . '/../includes/media_signal_contract.php';

$pdo = db();

function media_auth(PDO $pdo, int $sessionId, int $participantId, ?string $token): array {
    $participant = auth_participant($pdo, $sessionId, $token ?: '');
    if ($participantId > 0 && (int)$participant['id'] !== $participantId) json_out(['error' => 'Unauthorized'], 403);
    return $participant;
}

function media_voice_context(PDO $pdo, int $sessionId, array $participant, bool $requireJoined = false): array {
    $stmt = $pdo->prepare('SELECT context_type, context_public_id FROM voice_sessions WHERE participant_id = ? AND session_id = ? LIMIT 1');
    $stmt->execute([(int)$participant['id'], $sessionId]);
    $row = $stmt->fetch();
    if (!$row) {
        if ($requireJoined) json_out(['error' => 'Join voice before signaling.', 'code' => 'VOICE_SESSION_REQUIRED'], 409);
        return ['type' => 'room', 'publicId' => null];
    }
    $type = (string)($row['context_type'] ?? 'room');
    $publicId = trim((string)($row['context_public_id'] ?? ''));
    if ($type !== 'private-voice') return ['type' => 'room', 'publicId' => null];
    try {
        return private_voice_media_context($pdo, $sessionId, (int)$participant['user_id'], $publicId);
    } catch (PrivateVoiceException $error) {
        $pdo->prepare('DELETE FROM voice_sessions WHERE participant_id = ? AND session_id = ?')
            ->execute([(int)$participant['id'], $sessionId]);
        json_out(['error' => $error->getMessage(), 'code' => $error->errorCode], $error->httpStatus);
    }
}

function media_voice_target_in_context(PDO $pdo, int $sessionId, int $targetParticipantId, array $context): bool {
    if ((string)($context['type'] ?? 'room') === 'room') {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM participants WHERE id = ? AND session_id = ? LIMIT 1'
        );
        $stmt->execute([$targetParticipantId, $sessionId]);
        return (bool)$stmt->fetchColumn();
    }
    $stmt = $pdo->prepare(
        "SELECT 1 FROM voice_sessions
          WHERE participant_id = ? AND session_id = ? AND context_type = ?
            AND COALESCE(context_public_id, '') = ? LIMIT 1"
    );
    $stmt->execute([
        $targetParticipantId,
        $sessionId,
        (string)$context['type'],
        (string)($context['publicId'] ?? ''),
    ]);
    return (bool)$stmt->fetchColumn();
}

function media_voice_participants(PDO $pdo, int $sessionId, array $viewer, array $context): array {
    $stmt = $pdo->prepare(
        "SELECT v.participant_id, v.muted, v.deafened, v.speaking,
                p.user_id, p.display_name, p.avatar_path, p.webcam_path,
                u.role, r.owner_id
           FROM voice_sessions v
           JOIN participants p ON p.id = v.participant_id
           JOIN users u ON u.id = p.user_id
           JOIN room_sessions rs ON rs.id = v.session_id
           JOIN rooms r ON r.id = rs.room_id
          WHERE v.session_id = ? AND v.context_type = ?
            AND COALESCE(v.context_public_id, '') = ?
          ORDER BY v.joined_at ASC"
    );
    $stmt->execute([$sessionId, (string)$context['type'], (string)($context['publicId'] ?? '')]);
    return array_map(function(array $row) use ($pdo, $sessionId, $viewer): array {
        $projected = avatar_visibility_project_payload($pdo, (int)$viewer['user_id'], [
            'id' => (int)$row['participant_id'],
            'user_id' => (int)$row['user_id'],
            'display_name' => $row['display_name'],
            'role' => $row['role'] ?: 'user',
            'is_owner' => (int)$row['user_id'] === (int)$row['owner_id'],
            'avatar_path' => $row['avatar_path'],
            'avatar_url' => resolve_avatar($row['avatar_path']),
            'webcam_path' => $row['webcam_path'],
            'muted' => (bool)$row['muted'],
            'deafened' => (bool)$row['deafened'],
            'speaking' => (bool)$row['speaking'],
        ]);
        return p2p_avatar_project_participant($pdo, $sessionId, $viewer, $projected);
    }, $stmt->fetchAll());
}

function media_from_signal_data(array $body): string {
    $media = (string)($body['media'] ?? '');
    if (in_array($media, ['voice', 'webcam', 'avatar'], true)) return $media;
    $data = $body['data'] ?? null;
    if (is_array($data) && ($data['chatspace_media'] ?? '') === 'video') return 'webcam';
    return 'voice';
}

function media_signal_poll_payload(PDO $pdo, array $query): array {
    $sessionId = resolve_session_id($pdo, $query['session_id'] ?? '');
    $participantId = (int)($query['participant_id'] ?? 0);
    $after = (int)($query['after'] ?? 0);
    $viewer = media_auth($pdo, $sessionId, $participantId, $query['join_token'] ?? '');
    $clientEpoch = media_client_epoch($query['client_epoch'] ?? '');
    $client = media_signal_register_client($pdo, $sessionId, $participantId, $clientEpoch);
    if (!hash_equals((string)$client['epoch'], $clientEpoch)) {
        json_out([
            'error' => 'Media client epoch was superseded.',
            'code' => 'MEDIA_CLIENT_EPOCH_SUPERSEDED',
            'recoverable' => true,
            'client_epoch' => (string)$client['epoch'],
        ], 409);
    }
    $media = (string)($query['media'] ?? 'all');
    $webcamAllowed = webcam_capability($pdo)['allowWebcamUse'];
    $context = media_voice_context($pdo, $sessionId, $viewer);
    $contextFilter = " AND context_type = ? AND COALESCE(context_public_id, '') = ?";
    $contextArgs = [(string)$context['type'], (string)($context['publicId'] ?? '')];

    $delivery = '((to_participant_id = ? AND (recipient_epoch = ? OR (recipient_epoch IS NULL AND created_at >= ?))) OR (to_participant_id = 0 AND created_at >= ?))';
    $deliveryArgs = [$participantId, $clientEpoch, $client['started_at'], $client['started_at']];

    if ($media === 'avatar' && !p2p_avatar_policy($pdo)['effectiveEnabled']) {
        $stmt = null;
        $rows = [];
    } elseif ($media === 'webcam' && !$webcamAllowed) {
        $stmt = null;
        $rows = [];
    } elseif (in_array($media, ['voice', 'webcam'], true)) {
        $stmt = $pdo->prepare("SELECT id, media, from_participant_id, sender_epoch, type, data FROM media_signals WHERE session_id = ?{$contextFilter} AND media = ? AND {$delivery} AND id > ? AND (expires_at IS NULL OR expires_at >= ?) ORDER BY id ASC LIMIT 80");
        $stmt->execute([$sessionId, ...$contextArgs, $media, ...$deliveryArgs, $after, media_signal_now()]);
        $rows = $stmt->fetchAll();
    } elseif ($media === 'avatar') {
        $stmt = $pdo->prepare("SELECT id, media, from_participant_id, sender_epoch, context_type, context_public_id, type, data FROM media_signals WHERE session_id = ? AND media = 'avatar' AND context_type = 'room' AND {$delivery} AND id > ? AND (expires_at IS NULL OR expires_at >= ?) ORDER BY id ASC LIMIT 80");
        $stmt->execute([$sessionId, ...$deliveryArgs, $after, media_signal_now()]);
        $rows = $stmt->fetchAll();
    } else {
        $webcamFilter = $webcamAllowed ? '' : " AND media <> 'webcam'";
        $stmt = $pdo->prepare("SELECT id, media, from_participant_id, sender_epoch, context_type, context_public_id, type, data FROM media_signals WHERE session_id = ?{$webcamFilter} AND ((media = 'avatar' AND context_type = 'room') OR (media <> 'avatar'{$contextFilter})) AND {$delivery} AND id > ? AND (expires_at IS NULL OR expires_at >= ?) ORDER BY id ASC LIMIT 80");
        $stmt->execute([$sessionId, ...$contextArgs, ...$deliveryArgs, $after, media_signal_now()]);
        $rows = $stmt->fetchAll();
    }
    $signals = [];
    $signalErrors = [];
    $lastSignalId = $after;

    foreach ($rows as $row) {
        $lastSignalId = max($lastSignalId, (int)$row['id']);
        $decoded = json_decode($row['data'], true);
        $normalized = media_signal_normalize_payload((string)$row['type'], $decoded);

        if (!$normalized['ok']) {
            $signalErrors[] = [
                'id' => (int)$row['id'],
                'media' => $row['media'],
                'from_participant_id' => (int)$row['from_participant_id'],
                'type' => $row['type'],
                'error' => $normalized['error'],
                'diagnostics' => $normalized['diagnostics'] ?? [],
            ];
            continue;
        }

        if ((string)$row['media'] === 'avatar') {
            $authorization = (string)($normalized['data']['avatar_authorization'] ?? '');
            if (!p2p_avatar_signal_authorized(
                $pdo,
                $sessionId,
                (int)$row['from_participant_id'],
                (int)$viewer['id'],
                $authorization
            ) || !p2p_avatar_signal_payload_allowed(
                $pdo,
                (string)$row['type'],
                (array)$normalized['data']
            )) continue;
        } elseif (isset($row['context_type'])
            && ((string)$row['context_type'] !== (string)$context['type']
                || (string)($row['context_public_id'] ?? '') !== (string)($context['publicId'] ?? ''))) {
            continue;
        }

        if (!$webcamAllowed && webcam_signal_requests_video([
            'media' => $row['media'],
            'type' => $row['type'],
        ], $normalized['data'])) {
            continue;
        }

        if ((string)$row['media'] === 'webcam'
            && !webcam_audience_recipient_allowed(
                $pdo,
                $sessionId,
                (int)$row['from_participant_id'],
                (int)$viewer['id'],
                (string)($row['sender_epoch'] ?? '')
            )) {
            continue;
        }

        $signals[] = [
            'id' => (int)$row['id'],
            'media' => $row['media'],
            'from_participant_id' => (int)$row['from_participant_id'],
            'sender_epoch' => $row['sender_epoch'],
            'type' => $row['type'],
            'data' => $normalized['data'],
        ];
    }

    return [
        'signals' => $signals,
        'signal_errors' => $signalErrors,
        'last_signal_id' => $lastSignalId,
        'client_epoch' => (string)$client['epoch'],
        'voice_context' => ['type' => $context['type'], 'public_id' => $context['publicId']],
        'voice_participants' => media_voice_participants($pdo, $sessionId, $viewer, $context),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $payload = db_with_sqlite_lock_retry(
        $pdo,
        static fn(): array => media_signal_poll_payload($pdo, $_GET),
        'media-signal-poll'
    );
    json_out($payload);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);

$body = input_json();
$action = (string)($body['action'] ?? '');
$sessionId = resolve_session_id($pdo, $body['session_id'] ?? '');
$participantId = (int)($body['participant_id'] ?? 0);
$participant = media_auth($pdo, $sessionId, $participantId, $body['join_token'] ?? '');
$clientEpoch = null;
if (in_array($action, ['join', 'leave', 'status', 'signal', 'webcam_audience_confirm', 'webcam_on', 'webcam_off'], true)) {
    $clientEpoch = media_client_epoch($body['client_epoch'] ?? '');
    $client = media_signal_register_client($pdo, $sessionId, (int)$participant['id'], $clientEpoch);
    if (!hash_equals((string)$client['epoch'], $clientEpoch)) {
        json_out([
            'error' => 'Media client epoch was superseded.',
            'code' => 'MEDIA_CLIENT_EPOCH_SUPERSEDED',
            'recoverable' => true,
            'client_epoch' => (string)$client['epoch'],
        ], 409);
    }
}

if ($action === 'webcam_audience_confirm') {
    try {
        $audience = webcam_audience_confirm(
            $pdo,
            $sessionId,
            $participant,
            (string)$clientEpoch,
            (string)($body['audience_mode'] ?? ''),
            is_array($body['recipient_user_ids'] ?? null) ? $body['recipient_user_ids'] : []
        );
    } catch (PrivateVoiceException $error) {
        json_out(['error' => $error->getMessage(), 'code' => $error->errorCode], $error->httpStatus);
    }
    json_out(['ok' => true, 'audience' => $audience]);
}

if ($action === 'webcam_on' || $action === 'webcam_off') {
    $enabled = $action === 'webcam_on';
    if ($enabled && !webcam_capability($pdo)['allowWebcamUse']) {
        json_out([
            'error' => 'Webcam use is disabled for this installation.',
            'code' => 'WEBCAM_USE_DISABLED',
        ], 403);
    }
    if ($enabled && optional_core_voice_webcam_policy($pdo)['selectiveWebcamAudience']['enabled']) {
        $audience = webcam_audience_projection($pdo, (int)$participant['id'], (string)$clientEpoch);
        if (empty($audience['confirmed'])) {
            json_out([
                'error' => 'Confirm who may receive the webcam before turning it on.',
                'code' => 'WEBCAM_AUDIENCE_CONFIRMATION_REQUIRED',
            ], 409);
        }
    }
    avatar_relationship_transaction($pdo, static function() use ($pdo, $participant, $enabled): array {
        if ($enabled) {
            avatar_relationship_cancel_active_dances(
                $pdo,
                (int)$participant['user_id'],
                'participant-webcam-enabled'
            );
        }
        $pdo->prepare('UPDATE participants SET webcam_path = NULL, webcam_enabled = ? WHERE id = ?')
            ->execute([$enabled ? 1 : 0, (int)$participant['id']]);
        return ['ok' => true];
    });
    $payload = [
        'participant_id' => (int)$participant['id'],
        'webcam_path' => null,
        'webcam_enabled' => $enabled,
        'avatar_path' => $participant['avatar_path'],
        'avatar_url' => resolve_avatar($participant['avatar_path']),
    ];
    emit_event($pdo, $sessionId, 'webcam', $payload);
    if (!$enabled) webcam_audience_clear($pdo, (int)$participant['id']);
    json_out(['ok' => true] + $payload);
}

if ($action === 'join' || $action === 'leave') {
    $context = $action === 'leave'
        ? media_voice_context($pdo, $sessionId, $participant)
        : ['type' => 'room', 'publicId' => null];
    if ($action === 'join') {
        $requestedContext = trim((string)($body['voice_context'] ?? 'room'));
        if ($requestedContext === 'private-voice') {
            try {
                $context = private_voice_media_context(
                    $pdo,
                    $sessionId,
                    (int)$participant['user_id'],
                    trim((string)($body['private_voice_chat_id'] ?? ''))
                );
            } catch (PrivateVoiceException $error) {
                json_out(['error' => $error->getMessage(), 'code' => $error->errorCode], $error->httpStatus);
            }
        } elseif ($requestedContext !== 'room') {
            json_out(['error' => 'Voice context is invalid.', 'code' => 'VOICE_CONTEXT_INVALID'], 400);
        }
        $pdo->prepare(db_uses_mysql_syntax($pdo)
            ? 'INSERT INTO voice_sessions (participant_id, session_id, muted, deafened, speaking, context_type, context_public_id, joined_at) VALUES (?,?,?,?,?,?,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE session_id = VALUES(session_id), muted = VALUES(muted), deafened = VALUES(deafened), speaking = VALUES(speaking), context_type = VALUES(context_type), context_public_id = VALUES(context_public_id), joined_at = CURRENT_TIMESTAMP'
            : 'INSERT OR REPLACE INTO voice_sessions (participant_id, session_id, muted, deafened, speaking, context_type, context_public_id, joined_at) VALUES (?,?,?,?,?,?,?,CURRENT_TIMESTAMP)'
        )->execute([(int)$participant['id'], $sessionId, 0, 0, 0, $context['type'], $context['publicId']]);
    } else {
        $pdo->prepare('DELETE FROM voice_sessions WHERE participant_id = ?')->execute([(int)$participant['id']]);
    }
    media_signal_insert(
        $pdo,
        $sessionId,
        'voice',
        (int)$participant['id'],
        0,
        $action,
        ['participant_id' => (int)$participant['id']],
        $clientEpoch,
        (string)$context['type'],
        $context['publicId']
    );
    json_out(['ok' => true]);
}

if ($action === 'status') {
    media_voice_context($pdo, $sessionId, $participant, true);
    $pdo->prepare('UPDATE voice_sessions SET muted = ?, deafened = ?, speaking = ? WHERE participant_id = ? AND session_id = ?')
        ->execute([
            !empty($body['muted']) ? 1 : 0,
            !empty($body['deafened']) ? 1 : 0,
            !empty($body['speaking']) ? 1 : 0,
            (int)$participant['id'],
            $sessionId,
        ]);
    json_out(['ok' => true]);
}

if ($action === 'signal') {
    $to = (int)($body['to_id'] ?? 0);
    $type = (string)($body['type'] ?? '');
    if ($to <= 0 || $type === '') json_out(['error' => 'Missing signal fields'], 400);
    $normalized = media_signal_normalize_payload($type, $body['data'] ?? null);
    if (!$normalized['ok']) {
        json_out([
            'error' => 'Malformed media signal',
            'detail' => $normalized['error'],
            'diagnostics' => $normalized['diagnostics'] ?? [],
        ], 400);
    }
    $signalMedia = media_from_signal_data($body);
    if ($signalMedia === 'avatar') {
        $context = ['type' => 'room', 'publicId' => null];
        $targetStmt = $pdo->prepare('SELECT 1 FROM participants WHERE id=? AND session_id=? LIMIT 1');
        $targetStmt->execute([$to, $sessionId]);
        if (!$targetStmt->fetchColumn() || !p2p_avatar_signal_authorized(
            $pdo,
            $sessionId,
            (int)$participant['id'],
            $to,
            (string)($normalized['data']['avatar_authorization'] ?? '')
        ) || !p2p_avatar_signal_payload_allowed($pdo, $type, (array)$normalized['data'])) {
            json_out(['error' => 'Avatar synchronization is unavailable.', 'code' => 'P2P_AVATAR_SIGNAL_DENIED'], 403);
        }
    } else {
        // Ordinary room webcam signaling remains independent of joining voice.
        // Private-voice signaling still uses its established active context.
        $context = media_voice_context($pdo, $sessionId, $participant);
        if (!media_voice_target_in_context($pdo, $sessionId, $to, $context)) {
            json_out(['error' => 'Signal recipient is outside the active voice context.', 'code' => 'VOICE_CONTEXT_RECIPIENT_DENIED'], 403);
        }
    }
    if (!webcam_capability($pdo)['allowWebcamUse']
        && webcam_signal_requests_video($body, $normalized['data'])) {
        json_out([
            'error' => 'Webcam use is disabled for this installation.',
            'code' => 'WEBCAM_USE_DISABLED',
        ], 403);
    }
    if ($signalMedia === 'webcam'
        && !webcam_audience_recipient_allowed(
            $pdo,
            $sessionId,
            (int)$participant['id'],
            $to,
            (string)$clientEpoch
        )) {
        json_out([
            'error' => 'That participant is outside the confirmed webcam audience.',
            'code' => 'WEBCAM_AUDIENCE_RECIPIENT_DENIED',
        ], 403);
    }
    $persisted = media_signal_insert(
        $pdo,
        $sessionId,
        $signalMedia,
        (int)$participant['id'],
        $to,
        $type,
        $normalized['data'],
        $clientEpoch,
        (string)$context['type'],
        $context['publicId']
    );
    json_out(['ok' => true] + $persisted);
}

json_out(['error' => 'Bad request'], 400);
