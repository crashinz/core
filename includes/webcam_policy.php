<?php
declare(strict_types=1);

function webcam_policy_setting_defaults(): array {
    return [
        'allow_webcam_use' => '1',
        'webcam_policy_revision' => '1',
    ];
}

function webcam_capability(PDO $pdo): array {
    return [
        'allowWebcamUse' => app_setting($pdo, 'allow_webcam_use', '1') === '1',
        'revision' => max(1, (int)app_setting($pdo, 'webcam_policy_revision', '1')),
    ];
}

function webcam_viewer_preferences_from_row(array $row): array {
    return [
        'showWebcams' => !array_key_exists('webcam_show_preference', $row)
            || (int)$row['webcam_show_preference'] === 1,
        'receiveWebcams' => !array_key_exists('webcam_receive_preference', $row)
            || (int)$row['webcam_receive_preference'] === 1,
        'version' => max(1, (int)($row['webcam_preferences_version'] ?? 1)),
    ];
}

function webcam_viewer_preferences(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare(
        'SELECT webcam_show_preference, webcam_receive_preference, webcam_preferences_version
           FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return webcam_viewer_preferences_from_row($row ?: []);
}

function webcam_viewer_preferences_update(
    PDO $pdo,
    int $userId,
    mixed $expectedVersion,
    mixed $showWebcams,
    mixed $receiveWebcams
): array {
    $parsedVersion = filter_var($expectedVersion, FILTER_VALIDATE_INT);
    if ($parsedVersion === false || (int)$parsedVersion < 1) {
        return [
            'ok' => false,
            'code' => 'WEBCAM_PREFERENCES_VERSION_REQUIRED',
            'error' => 'Webcam preferences changed. Refresh and try again.',
            'http_status' => 409,
        ];
    }
    foreach (['showWebcams' => $showWebcams, 'receiveWebcams' => $receiveWebcams] as $field => $value) {
        if (!in_array($value, [true, false, 0, 1, '0', '1'], true)) {
            return [
                'ok' => false,
                'code' => 'WEBCAM_PREFERENCE_INVALID',
                'error' => "{$field} must be enabled or disabled.",
                'http_status' => 400,
            ];
        }
    }

    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) {
            if (db_uses_mysql_syntax($pdo)) $pdo->beginTransaction();
            else $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
        }
        $sql = 'SELECT webcam_show_preference, webcam_receive_preference, webcam_preferences_version
                  FROM users WHERE id = ? LIMIT 1';
        if (db_uses_mysql_syntax($pdo)) $sql .= ' FOR UPDATE';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $current = $stmt->fetch();
        if (!$current) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            return [
                'ok' => false,
                'code' => 'WEBCAM_PREFERENCES_USER_NOT_FOUND',
                'error' => 'Webcam preferences are unavailable.',
                'http_status' => 404,
            ];
        }
        $preferences = webcam_viewer_preferences_from_row($current);
        if ((int)$preferences['version'] !== (int)$parsedVersion) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            return [
                'ok' => false,
                'code' => 'WEBCAM_PREFERENCES_STALE',
                'error' => 'Webcam preferences changed. Refresh and try again.',
                'preferences' => $preferences,
                'http_status' => 409,
            ];
        }

        $nextShow = (bool)$showWebcams;
        $nextReceive = (bool)$receiveWebcams;
        $changed = $preferences['showWebcams'] !== $nextShow
            || $preferences['receiveWebcams'] !== $nextReceive;
        $nextVersion = (int)$preferences['version'] + ($changed ? 1 : 0);
        if ($changed) {
            $pdo->prepare(
                'UPDATE users
                    SET webcam_show_preference = ?, webcam_receive_preference = ?, webcam_preferences_version = ?
                  WHERE id = ?'
            )->execute([$nextShow ? 1 : 0, $nextReceive ? 1 : 0, $nextVersion, $userId]);
        }
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->commit();
        return [
            'ok' => true,
            'idempotent' => !$changed,
            'preferences' => [
                'showWebcams' => $nextShow,
                'receiveWebcams' => $nextReceive,
                'version' => $nextVersion,
            ],
        ];
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function webcam_capability_update(PDO $pdo, bool $allowWebcamUse): array {
    $before = webcam_capability($pdo);
    if ($before['allowWebcamUse'] === $allowWebcamUse) {
        return ['ok' => true, 'idempotent' => true, 'capability' => $before, 'stoppedParticipantCount' => 0];
    }

    $affected = [];
    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) {
            if (db_uses_mysql_syntax($pdo)) $pdo->beginTransaction();
            else $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
        }
        if (!$allowWebcamUse) {
            $affected = $pdo->query(
                'SELECT id, session_id, avatar_path FROM participants
                  WHERE webcam_enabled = 1 OR webcam_path IS NOT NULL'
            )->fetchAll();
            $pdo->exec('UPDATE participants SET webcam_enabled = 0, webcam_path = NULL');
            $pdo->exec("DELETE FROM media_signals WHERE media = 'webcam'");
        }
        set_app_setting($pdo, 'allow_webcam_use', $allowWebcamUse ? '1' : '0');
        set_app_setting($pdo, 'webcam_policy_revision', (string)((int)$before['revision'] + 1));
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->commit();
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    $capability = webcam_capability($pdo);
    $sessionIds = array_map('intval', $pdo->query('SELECT id FROM room_sessions')->fetchAll(PDO::FETCH_COLUMN));
    foreach ($sessionIds as $sessionId) emit_event($pdo, $sessionId, 'webcam_capability', $capability);
    foreach ($affected as $participant) {
        emit_event($pdo, (int)$participant['session_id'], 'webcam', [
            'participant_id' => (int)$participant['id'],
            'webcam_path' => null,
            'webcam_enabled' => false,
            'avatar_path' => $participant['avatar_path'],
            'avatar_url' => resolve_avatar($participant['avatar_path']),
        ]);
    }
    return [
        'ok' => true,
        'idempotent' => false,
        'capability' => $capability,
        'stoppedParticipantCount' => count($affected),
    ];
}

function webcam_signal_sdp_sends_video(array $data): bool {
    $description = $data['description'] ?? $data;
    $sdp = is_array($description) ? (string)($description['sdp'] ?? '') : '';
    if ($sdp === '') return false;
    $inVideo = false;
    $videoFound = false;
    $direction = null;
    foreach (preg_split('/\r\n|\n|\r/', $sdp) ?: [] as $line) {
        if (str_starts_with($line, 'm=')) {
            $inVideo = str_starts_with($line, 'm=video ');
            if ($inVideo) $videoFound = true;
            continue;
        }
        if ($inVideo && in_array($line, ['a=sendrecv', 'a=sendonly', 'a=recvonly', 'a=inactive'], true)) {
            $direction = substr($line, 2);
        }
    }
    if (!$videoFound) return false;
    return $direction === null || in_array($direction, ['sendrecv', 'sendonly'], true);
}

function webcam_signal_requests_video(array $body, ?array $normalizedData = null): bool {
    if ((string)($body['media'] ?? '') === 'webcam') return true;
    $data = $normalizedData ?? (is_array($body['data'] ?? null) ? $body['data'] : []);
    if (($data['chatspace_media'] ?? '') === 'video') return true;
    if (!empty($data['webcam_operation']) || ($data['media_reason'] ?? '') === 'webcam') return true;
    return in_array((string)($body['type'] ?? ''), ['offer', 'answer'], true)
        && webcam_signal_sdp_sends_video($data);
}
