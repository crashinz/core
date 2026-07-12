<?php
require_once __DIR__ . '/../includes/base.php';
require_once __DIR__ . '/../includes/media_signal_contract.php';

$pdo = db();

function media_auth(PDO $pdo, int $sessionId, int $participantId, ?string $token): array {
    $participant = auth_participant($pdo, $sessionId, $token ?: '');
    if ($participantId > 0 && (int)$participant['id'] !== $participantId) json_out(['error' => 'Unauthorized'], 403);
    return $participant;
}

function media_voice_participants(PDO $pdo, int $sessionId): array {
    $stmt = $pdo->prepare(
        'SELECT v.participant_id, v.muted, v.deafened, v.speaking,
                p.user_id, p.display_name, p.avatar_path, p.webcam_path,
                u.role, r.owner_id
           FROM voice_sessions v
           JOIN participants p ON p.id = v.participant_id
           JOIN users u ON u.id = p.user_id
           JOIN room_sessions rs ON rs.id = v.session_id
           JOIN rooms r ON r.id = rs.room_id
          WHERE v.session_id = ?
          ORDER BY v.joined_at ASC'
    );
    $stmt->execute([$sessionId]);
    return array_map(fn(array $row): array => [
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
    ], $stmt->fetchAll());
}

function media_client_epoch(mixed $value): string {
    $epoch = trim((string)$value);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,95}$/', $epoch)) {
        json_out(['error' => 'Invalid media client epoch'], 400);
    }
    return $epoch;
}

function media_signal_now(): string {
    return gmdate('Y-m-d H:i:s');
}

function media_signal_expiry(): string {
    return gmdate('Y-m-d H:i:s', time() + 600);
}

function media_signal_register_client(PDO $pdo, int $sessionId, int $participantId, string $clientEpoch): array {
    $now = media_signal_now();
    $stmt = $pdo->prepare('SELECT client_epoch, started_at FROM media_signal_clients WHERE participant_id = ? AND session_id = ? LIMIT 1');
    $stmt->execute([$participantId, $sessionId]);
    $current = $stmt->fetch();
    $startedAt = $current && hash_equals((string)$current['client_epoch'], $clientEpoch)
        ? (string)$current['started_at']
        : $now;

    if ($current) {
        $pdo->prepare('UPDATE media_signal_clients SET client_epoch = ?, started_at = ?, updated_at = ? WHERE participant_id = ? AND session_id = ?')
            ->execute([$clientEpoch, $startedAt, $now, $participantId, $sessionId]);
    } else {
        try {
            $pdo->prepare('INSERT INTO media_signal_clients (participant_id, session_id, client_epoch, started_at, updated_at) VALUES (?,?,?,?,?)')
                ->execute([$participantId, $sessionId, $clientEpoch, $startedAt, $now]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() !== '23000') throw $e;
            $retry = $pdo->prepare('UPDATE media_signal_clients SET client_epoch = ?, started_at = ?, updated_at = ? WHERE participant_id = ? AND session_id = ?');
            $retry->execute([$clientEpoch, $startedAt, $now, $participantId, $sessionId]);
        }
    }

    $pdo->prepare('DELETE FROM media_signals WHERE expires_at IS NOT NULL AND expires_at < ?')->execute([$now]);
    $pdo->prepare('DELETE FROM media_signal_clients WHERE updated_at < ?')->execute([gmdate('Y-m-d H:i:s', time() - 86400)]);

    return ['epoch' => $clientEpoch, 'started_at' => $startedAt];
}

function media_signal_recipient_epoch(PDO $pdo, int $sessionId, int $participantId): ?string {
    if ($participantId <= 0) return null;
    $stmt = $pdo->prepare('SELECT client_epoch FROM media_signal_clients WHERE participant_id = ? AND session_id = ? LIMIT 1');
    $stmt->execute([$participantId, $sessionId]);
    $value = $stmt->fetchColumn();
    return is_string($value) && $value !== '' ? $value : null;
}

function media_signal_insert(PDO $pdo, int $sessionId, string $media, int $from, int $to, string $type, mixed $data, ?string $senderEpoch = null): array {
    $recipientEpoch = media_signal_recipient_epoch($pdo, $sessionId, $to);
    $pdo->prepare('INSERT INTO media_signals (session_id, media, from_participant_id, to_participant_id, sender_epoch, recipient_epoch, type, data, expires_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$sessionId, $media, $from, $to, $senderEpoch, $recipientEpoch, $type, json_encode($data, JSON_UNESCAPED_SLASHES), media_signal_expiry()]);
    return [
        'signal_id' => (int)$pdo->lastInsertId(),
        'recipient_epoch' => $recipientEpoch,
    ];
}

function media_from_signal_data(array $body): string {
    $media = (string)($body['media'] ?? '');
    if (in_array($media, ['voice', 'webcam'], true)) return $media;
    $data = $body['data'] ?? null;
    if (is_array($data) && ($data['chatspace_media'] ?? '') === 'video') return 'webcam';
    return 'voice';
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sessionId = resolve_session_id($pdo, $_GET['session_id'] ?? '');
    $participantId = (int)($_GET['participant_id'] ?? 0);
    $after = (int)($_GET['after'] ?? 0);
    media_auth($pdo, $sessionId, $participantId, $_GET['join_token'] ?? '');
    $clientEpoch = media_client_epoch($_GET['client_epoch'] ?? '');
    $client = media_signal_register_client($pdo, $sessionId, $participantId, $clientEpoch);
    $media = (string)($_GET['media'] ?? 'all');

    $delivery = '((to_participant_id = ? AND (recipient_epoch = ? OR (recipient_epoch IS NULL AND created_at >= ?))) OR (to_participant_id = 0 AND created_at >= ?))';
    $deliveryArgs = [$participantId, $clientEpoch, $client['started_at'], $client['started_at']];

    if (in_array($media, ['voice', 'webcam'], true)) {
        $stmt = $pdo->prepare("SELECT id, media, from_participant_id, sender_epoch, type, data FROM media_signals WHERE session_id = ? AND media = ? AND {$delivery} AND id > ? AND (expires_at IS NULL OR expires_at >= ?) ORDER BY id ASC LIMIT 80");
        $stmt->execute([$sessionId, $media, ...$deliveryArgs, $after, media_signal_now()]);
    } else {
        $stmt = $pdo->prepare("SELECT id, media, from_participant_id, sender_epoch, type, data FROM media_signals WHERE session_id = ? AND {$delivery} AND id > ? AND (expires_at IS NULL OR expires_at >= ?) ORDER BY id ASC LIMIT 80");
        $stmt->execute([$sessionId, ...$deliveryArgs, $after, media_signal_now()]);
    }
    $signals = [];
    $signalErrors = [];
    $lastSignalId = $after;

    foreach ($stmt->fetchAll() as $row) {
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

        $signals[] = [
            'id' => (int)$row['id'],
            'media' => $row['media'],
            'from_participant_id' => (int)$row['from_participant_id'],
            'sender_epoch' => $row['sender_epoch'],
            'type' => $row['type'],
            'data' => $normalized['data'],
        ];
    }

    json_out([
        'signals' => $signals,
        'signal_errors' => $signalErrors,
        'last_signal_id' => $lastSignalId,
        'client_epoch' => $clientEpoch,
        'voice_participants' => media_voice_participants($pdo, $sessionId),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);

$body = input_json();
$action = (string)($body['action'] ?? '');
$sessionId = resolve_session_id($pdo, $body['session_id'] ?? '');
$participantId = (int)($body['participant_id'] ?? 0);
$participant = media_auth($pdo, $sessionId, $participantId, $body['join_token'] ?? '');
$clientEpoch = null;
if (in_array($action, ['join', 'leave', 'status', 'signal'], true)) {
    $clientEpoch = media_client_epoch($body['client_epoch'] ?? '');
    media_signal_register_client($pdo, $sessionId, (int)$participant['id'], $clientEpoch);
}

if ($action === 'webcam_on' || $action === 'webcam_off') {
    $enabled = $action === 'webcam_on';
    $pdo->prepare('UPDATE participants SET webcam_path = NULL, webcam_enabled = ? WHERE id = ?')
        ->execute([$enabled ? 1 : 0, (int)$participant['id']]);
    $payload = [
        'participant_id' => (int)$participant['id'],
        'webcam_path' => null,
        'webcam_enabled' => $enabled,
        'avatar_path' => $participant['avatar_path'],
        'avatar_url' => resolve_avatar($participant['avatar_path']),
    ];
    emit_event($pdo, $sessionId, 'webcam', $payload);
    json_out(['ok' => true] + $payload);
}

if ($action === 'join' || $action === 'leave') {
    if ($action === 'join') {
        $pdo->prepare(db_uses_mysql_syntax($pdo)
            ? 'INSERT INTO voice_sessions (participant_id, session_id, muted, deafened, speaking, joined_at) VALUES (?,?,?,?,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE session_id = VALUES(session_id), muted = VALUES(muted), deafened = VALUES(deafened), speaking = VALUES(speaking), joined_at = CURRENT_TIMESTAMP'
            : 'INSERT OR REPLACE INTO voice_sessions (participant_id, session_id, muted, deafened, speaking, joined_at) VALUES (?,?,?,?,?,CURRENT_TIMESTAMP)'
        )->execute([(int)$participant['id'], $sessionId, 0, 0, 0]);
    } else {
        $pdo->prepare('DELETE FROM voice_sessions WHERE participant_id = ?')->execute([(int)$participant['id']]);
    }
    media_signal_insert($pdo, $sessionId, 'voice', (int)$participant['id'], 0, $action, ['participant_id' => (int)$participant['id']], $clientEpoch);
    json_out(['ok' => true]);
}

if ($action === 'status') {
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
    $persisted = media_signal_insert($pdo, $sessionId, media_from_signal_data($body), (int)$participant['id'], $to, $type, $normalized['data'], $clientEpoch);
    json_out(['ok' => true] + $persisted);
}

json_out(['error' => 'Bad request'], 400);
