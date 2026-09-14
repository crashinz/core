<?php
declare(strict_types=1);

const AVATAR_UPLOAD_MIN_DIMENSION_PX = 42;

function avatar_size_policy_setting_defaults(): array {
    return [
        'avatar_display_max_px' => '200',
        'webcam_display_max_width_px' => '200',
        'webcam_display_max_height_px' => '200',
        'avatar_upload_max_width_px' => '250',
        'avatar_upload_max_height_px' => '250',
        'avatar_size_policy_revision' => '1',
    ];
}

function avatar_size_policy_bounds(): array {
    return [
        'avatar_display_max_px' => [42, 1000],
        'webcam_display_max_width_px' => [42, 2048],
        'webcam_display_max_height_px' => [42, 2048],
        'avatar_upload_max_width_px' => [42, 4096],
        'avatar_upload_max_height_px' => [42, 4096],
    ];
}

function avatar_size_policy_setting_map(): array {
    return [
        'avatar_display_max_px' => 'avatarDisplayMaxPx',
        'webcam_display_max_width_px' => 'webcamDisplayMaxWidthPx',
        'webcam_display_max_height_px' => 'webcamDisplayMaxHeightPx',
        'avatar_upload_max_width_px' => 'avatarUploadMaxWidthPx',
        'avatar_upload_max_height_px' => 'avatarUploadMaxHeightPx',
    ];
}

function avatar_size_policy_bounded_int(mixed $value, int $min, int $max, int $fallback): int {
    $parsed = filter_var($value, FILTER_VALIDATE_INT);
    if ($parsed === false) return $fallback;
    return max($min, min($max, (int)$parsed));
}

function avatar_size_policy(PDO $pdo): array {
    $defaults = avatar_size_policy_setting_defaults();
    $bounds = avatar_size_policy_bounds();
    $avatarMaxMb = corechat_limit_value($pdo, 'avatar_max_size_mb', 5.0);
    $policy = [
        'revision' => max(1, (int)app_setting($pdo, 'avatar_size_policy_revision', '1')),
        'avatarMaxSizeMb' => $avatarMaxMb,
        'avatarMaxBytes' => $avatarMaxMb === null ? null : (int)round($avatarMaxMb * 1024 * 1024),
        'avatarMinDimensionPx' => AVATAR_UPLOAD_MIN_DIMENSION_PX,
        'limitEnforcement' => [
            'avatar_max_size_mb' => $avatarMaxMb !== null,
        ],
    ];
    foreach (avatar_size_policy_setting_map() as $setting => $publicKey) {
        [$min, $max] = $bounds[$setting];
        $fallback = (int)$defaults[$setting];
        $configured = corechat_limit_value($pdo, $setting, $fallback);
        $policy['limitEnforcement'][$setting] = $configured !== null;
        $policy[$publicKey] = $configured === null
            ? $max
            : avatar_size_policy_bounded_int($configured, $min, $max, $fallback);
    }
    return $policy;
}

function avatar_size_policy_validate_settings(array $input): array {
    $values = [];
    foreach (avatar_size_policy_setting_map() as $setting => $publicKey) {
        if (!array_key_exists($setting, $input)) {
            return [
                'ok' => false,
                'code' => 'AVATAR_SIZE_POLICY_SETTING_REQUIRED',
                'error' => 'Every avatar and webcam size limit is required.',
                'http_status' => 400,
            ];
        }
        $parsed = filter_var($input[$setting], FILTER_VALIDATE_INT);
        [$min, $max] = avatar_size_policy_bounds()[$setting];
        if ($parsed === false || (int)$parsed < $min || (int)$parsed > $max) {
            return [
                'ok' => false,
                'code' => 'AVATAR_SIZE_POLICY_SETTING_INVALID',
                'setting' => $setting,
                'error' => "{$setting} must be a whole number from {$min} to {$max} pixels.",
                'http_status' => 400,
            ];
        }
        $values[$setting] = (int)$parsed;
    }
    return ['ok' => true, 'values' => $values];
}

function avatar_size_policy_emit(PDO $pdo, array $policy): void {
    $sessionIds = $pdo->query('SELECT id FROM room_sessions')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($sessionIds as $sessionId) {
        emit_event($pdo, (int)$sessionId, 'avatar_size_policy', $policy);
    }
}

function avatar_size_policy_update(PDO $pdo, array $input, bool $reset = false): array {
    $defaults = avatar_size_policy_setting_defaults();
    $validation = avatar_size_policy_validate_settings(
        $reset ? array_intersect_key($defaults, avatar_size_policy_setting_map()) : $input
    );
    if (empty($validation['ok'])) return $validation;

    $before = avatar_size_policy($pdo);
    $transaction = database_transaction_begin($pdo, false);
    $ownsTransaction = !empty($transaction['owned']);
    try {
        if ($ownsTransaction && !db_uses_mysql_syntax($pdo)) {
            $pdo->prepare('UPDATE app_settings SET value=value WHERE setting_key=?')->execute(['avatar_size_policy_revision']);
        }
        foreach ($validation['values'] as $setting => $value) {
            set_app_setting($pdo, $setting, (string)$value);
        }
        $candidate = avatar_size_policy($pdo);
        $changed = false;
        foreach (array_values(avatar_size_policy_setting_map()) as $publicKey) {
            if ((int)$before[$publicKey] !== (int)$candidate[$publicKey]) {
                $changed = true;
                break;
            }
        }
        $revision = (int)$before['revision'] + ($changed ? 1 : 0);
        if ($changed && (int)$before['avatarDisplayMaxPx'] !== (int)$candidate['avatarDisplayMaxPx']) {
            avatar_relationship_cancel_active_dances(
                $pdo,
                null,
                'installation-avatar-display-cap-change'
            );
        }
        set_app_setting($pdo, 'avatar_size_policy_revision', (string)$revision);
        $policy = avatar_size_policy($pdo);
        if ($changed) avatar_size_policy_emit($pdo, $policy);
        if ($ownsTransaction) database_transaction_commit($pdo, $transaction);
        return [
            'ok' => true,
            'idempotent' => !$changed,
            'policy' => $policy,
        ];
    } catch (Throwable $error) {
        if ($ownsTransaction) database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
}

function avatar_size_nullable_int(mixed $value): ?int {
    if ($value === null || $value === '') return null;
    $parsed = filter_var($value, FILTER_VALIDATE_INT);
    return $parsed === false ? -1 : (int)$parsed;
}

function avatar_size_preferences_from_row(array $row): array {
    return [
        'avatarDisplayPreferencePx' => isset($row['avatar_display_size_px'])
            ? (int)$row['avatar_display_size_px']
            : null,
        'webcamDisplayWidthPreferencePx' => isset($row['webcam_display_width_px'])
            ? (int)$row['webcam_display_width_px']
            : null,
        'avatarDisplayWidthPreferencePx' => isset($row['avatar_display_width_px']) ? (int)$row['avatar_display_width_px'] : null,
        'avatarDisplayHeightPreferencePx' => isset($row['avatar_display_height_px']) ? (int)$row['avatar_display_height_px'] : null,
        'webcamDisplayHeightPreferencePx' => isset($row['webcam_display_height_px'])
            ? (int)$row['webcam_display_height_px']
            : null,
        'displayPreferenceVersion' => max(1, (int)($row['avatar_size_version'] ?? 1)),
    ];
}

function avatar_size_preferences_public(PDO $pdo, array $row): array {
    $policy = avatar_size_policy($pdo);
    $preferences = avatar_size_preferences_from_row($row);
    $avatar = $preferences['avatarDisplayPreferencePx'];
    $webcamWidth = $preferences['webcamDisplayWidthPreferencePx'];
    $webcamHeight = $preferences['webcamDisplayHeightPreferencePx'];
    return $preferences + [
        'effectiveAvatarDisplayMaxPx' => min(
            $policy['avatarDisplayMaxPx'],
            $avatar ?? $policy['avatarDisplayMaxPx']
        ),
        'effectiveWebcamDisplayWidthPx' => min(
            $policy['webcamDisplayMaxWidthPx'],
            $webcamWidth ?? $policy['webcamDisplayMaxWidthPx']
        ),
        'effectiveWebcamDisplayHeightPx' => min(
            $policy['webcamDisplayMaxHeightPx'],
            $webcamHeight ?? $policy['webcamDisplayMaxHeightPx']
        ),
    ];
}

function avatar_size_preferences_update(
    PDO $pdo,
    int $userId,
    mixed $expectedVersion,
    array $changes
): array {
    $parsedVersion = filter_var($expectedVersion, FILTER_VALIDATE_INT);
    if ($parsedVersion === false || (int)$parsedVersion < 1) {
        return [
            'ok' => false,
            'code' => 'AVATAR_SIZE_VERSION_INVALID',
            'error' => 'Avatar display settings are out of date. Refresh and try again.',
            'http_status' => 400,
        ];
    }

    $fieldMap = [
        'avatar_display_size_px' => ['avatarDisplayMaxPx', 'avatar display size'],
        'avatar_display_width_px' => ['avatarDisplayMaxPx', 'avatar display width'],
        'avatar_display_height_px' => ['avatarDisplayMaxPx', 'avatar display height'],
        'webcam_display_width_px' => ['webcamDisplayMaxWidthPx', 'webcam display width'],
        'webcam_display_height_px' => ['webcamDisplayMaxHeightPx', 'webcam display height'],
    ];
    $policy = avatar_size_policy($pdo);
    $normalized = [];
    if (array_key_exists('avatar_display_width_px', $changes) !== array_key_exists('avatar_display_height_px', $changes)) {
        return ['ok' => false, 'code' => 'AVATAR_SIZE_PAIR_REQUIRED', 'error' => 'Provide both avatar width and height.', 'http_status' => 400];
    }
    if (array_key_exists('avatar_display_size_px', $changes) && !array_key_exists('avatar_display_width_px', $changes)) {
        $changes['avatar_display_width_px'] = null;
        $changes['avatar_display_height_px'] = null;
    }
    foreach ($fieldMap as $field => [$capKey, $label]) {
        if (!array_key_exists($field, $changes)) continue;
        $value = avatar_size_nullable_int($changes[$field]);
        if ($value !== null && ($value < AVATAR_UPLOAD_MIN_DIMENSION_PX || $value > (int)$policy[$capKey])) {
            if ($value > (int)$policy[$capKey]) {
                $settingId = match ($field) {
                    'avatar_display_size_px' => 'avatar_display_max_px',
                    'avatar_display_width_px', 'avatar_display_height_px' => 'avatar_display_max_px',
                    'webcam_display_width_px' => 'webcam_display_max_width_px',
                    'webcam_display_height_px' => 'webcam_display_max_height_px',
                    default => '',
                };
                if ($settingId !== '') limit_event_record_reached($pdo, $settingId, 'member', 'user:' . $userId, 'rejected', ['requestedPixels' => $value]);
            }
            return [
                'ok' => false,
                'code' => 'AVATAR_SIZE_PREFERENCE_INVALID',
                'field' => $field,
                'error' => ucfirst($label) . ' must be a whole number from '
                    . AVATAR_UPLOAD_MIN_DIMENSION_PX . ' to ' . (int)$policy[$capKey] . ' pixels.',
                'http_status' => 400,
            ];
        }
        $normalized[$field] = $value;
    }
    if (array_key_exists('avatar_display_width_px', $normalized)
        && (($normalized['avatar_display_width_px'] === null) !== ($normalized['avatar_display_height_px'] === null))) {
        return ['ok' => false, 'code' => 'AVATAR_SIZE_PAIR_REQUIRED', 'error' => 'Provide both avatar dimensions, or clear both to use proportional sizing.', 'http_status' => 400];
    }
    if (!$normalized) {
        return [
            'ok' => false,
            'code' => 'AVATAR_SIZE_PREFERENCE_REQUIRED',
            'error' => 'Choose an avatar or webcam display size.',
            'http_status' => 400,
        ];
    }

    $transaction = database_transaction_begin($pdo, false);
    $ownsTransaction = !empty($transaction['owned']);
    try {
        if ($ownsTransaction && !db_uses_mysql_syntax($pdo)) {
            $pdo->prepare('UPDATE users SET avatar_size_version=avatar_size_version WHERE id=?')->execute([$userId]);
        }
        $sql = 'SELECT avatar_display_size_px, avatar_display_width_px, avatar_display_height_px, webcam_display_width_px, webcam_display_height_px, avatar_size_version FROM users WHERE id = ? LIMIT 1';
        if (db_uses_mysql_syntax($pdo)) $sql .= ' FOR UPDATE';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) {
            if ($ownsTransaction) database_transaction_rollback($pdo, $transaction);
            return [
                'ok' => false,
                'code' => 'AVATAR_SIZE_USER_NOT_FOUND',
                'error' => 'Avatar display settings are unavailable.',
                'http_status' => 404,
            ];
        }
        $currentVersion = max(1, (int)($user['avatar_size_version'] ?? 1));
        if ($currentVersion !== (int)$parsedVersion) {
            if ($ownsTransaction) database_transaction_rollback($pdo, $transaction);
            return [
                'ok' => false,
                'code' => 'AVATAR_SIZE_PREFERENCE_STALE',
                'error' => 'Avatar display settings changed. Refresh and try again.',
                'preferences' => avatar_size_preferences_public($pdo, $user),
                'http_status' => 409,
            ];
        }

        $next = $user;
        $changed = false;
        foreach ($normalized as $field => $value) {
            $current = isset($user[$field]) ? (int)$user[$field] : null;
            if ($current !== $value) $changed = true;
            $next[$field] = $value;
        }
        $nextVersion = $currentVersion + ($changed ? 1 : 0);
        if ($changed) {
            $avatarDisplayChanged = (isset($user['avatar_display_size_px']) ? (int)$user['avatar_display_size_px'] : null)
                !== $next['avatar_display_size_px']
                || $user['avatar_display_width_px'] !== $next['avatar_display_width_px']
                || $user['avatar_display_height_px'] !== $next['avatar_display_height_px'];
            $pdo->prepare(
                'UPDATE users SET avatar_display_size_px = ?, avatar_display_width_px = ?, avatar_display_height_px = ?, webcam_display_width_px = ?, webcam_display_height_px = ?, avatar_size_version = ? WHERE id = ?'
            )->execute([
                $next['avatar_display_size_px'],
                $next['avatar_display_width_px'],
                $next['avatar_display_height_px'],
                $next['webcam_display_width_px'],
                $next['webcam_display_height_px'],
                $nextVersion,
                $userId,
            ]);
            $pdo->prepare(
                'UPDATE participants SET avatar_display_size_px = ?, avatar_display_width_px = ?, avatar_display_height_px = ?, webcam_display_width_px = ?, webcam_display_height_px = ?, avatar_size_version = ? WHERE user_id = ?'
            )->execute([
                $next['avatar_display_size_px'],
                $next['avatar_display_width_px'],
                $next['avatar_display_height_px'],
                $next['webcam_display_width_px'],
                $next['webcam_display_height_px'],
                $nextVersion,
                $userId,
            ]);
            if ($avatarDisplayChanged) {
                avatar_relationship_cancel_active_dances(
                    $pdo,
                    $userId,
                    'participant-avatar-display-size-change'
                );
            }
        }
        $next['avatar_size_version'] = $nextVersion;
        if ($ownsTransaction) database_transaction_commit($pdo, $transaction);
        return [
            'ok' => true,
            'idempotent' => !$changed,
            'preferences' => avatar_size_preferences_public($pdo, $next),
        ];
    } catch (Throwable $error) {
        if ($ownsTransaction) database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
}

// Called only after membership removal, inside the relationship's write transaction.
// Keep this live behavior separate from historical sizing/migration function bodies.
function avatar_size_restore_detached_members_locked(PDO $pdo, array $members): void {
    $ids = array_values(array_unique(array_filter(array_map(
        static fn(array $member): int => (int)($member['participant_id'] ?? 0),
        $members
    ), static fn(int $id): bool => $id > 0)));
    if (!$ids) return;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $lockSuffix = db_uses_mysql_syntax($pdo) ? ' FOR UPDATE' : '';
    $statement = $pdo->prepare(
        "SELECT p.* FROM participants p WHERE p.id IN ($placeholders)
          AND NOT EXISTS (
              SELECT 1 FROM avatar_relationship_members arm
              JOIN avatar_relationships ar ON ar.id = arm.relationship_id
              WHERE arm.participant_id = p.id AND arm.membership_status = 'active' AND ar.status = 'active'
          ) ORDER BY p.user_id, p.id" . $lockSuffix
    );
    $statement->execute($ids);
    $byUser = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $participant) {
        $userId = (int)$participant['user_id'];
        if ($userId > 0) $byUser[$userId][] = $participant;
    }
    ksort($byUser, SORT_NUMERIC);
    foreach ($byUser as $userId => $participants) {
        $userStatement = $pdo->prepare(
            'SELECT avatar_path, avatar_source_width_px, avatar_source_height_px, avatar_display_size_px,
                    avatar_display_width_px, avatar_display_height_px, avatar_size_version
               FROM users WHERE id = ? LIMIT 1' . $lockSuffix
        );
        $userStatement->execute([$userId]);
        $user = $userStatement->fetch(PDO::FETCH_ASSOC);
        if (!$user) continue;
        $width = (int)($user['avatar_source_width_px'] ?? 0);
        $height = (int)($user['avatar_source_height_px'] ?? 0);
        $sourceFile = avatar_source_file((string)$user['avatar_path']);
        $sourcePath = $sourceFile !== null ? realpath($sourceFile) : false;
        $assetRoot = realpath(dirname(__DIR__) . '/assets');
        if ($sourcePath && $assetRoot && str_starts_with($sourcePath, $assetRoot . DIRECTORY_SEPARATOR)) {
            $dimensions = @getimagesize($sourcePath);
            if (is_array($dimensions) && (int)$dimensions[0] > 0 && (int)$dimensions[1] > 0) {
                $width = (int)$dimensions[0];
                $height = (int)$dimensions[1];
            }
        }
        // Missing source dimensions mean natural/proportional mode, never invented original dimensions.
        if ($width <= 0 || $height <= 0) { $width = null; $height = null; }
        $differs = static fn(array $row): bool => $row['avatar_display_size_px'] !== null
            || (isset($row['avatar_display_width_px']) ? (int)$row['avatar_display_width_px'] : null) !== $width
            || (isset($row['avatar_display_height_px']) ? (int)$row['avatar_display_height_px'] : null) !== $height;
        $changed = $differs($user);
        $currentVersion = max(1, (int)$user['avatar_size_version']);
        foreach ($participants as $participant) {
            $changed = $changed || $differs($participant);
            $currentVersion = max($currentVersion, (int)$participant['avatar_size_version']);
        }
        if (!$changed) continue;
        $nextVersion = $currentVersion + 1;
        $pdo->prepare(
            'UPDATE users SET avatar_display_size_px = NULL, avatar_display_width_px = ?,
                              avatar_display_height_px = ?, avatar_size_version = ? WHERE id = ?'
        )->execute([$width, $height, $nextVersion, $userId]);
        $updateParticipant = $pdo->prepare(
            'UPDATE participants SET avatar_display_size_px = NULL, avatar_display_width_px = ?,
                                     avatar_display_height_px = ?, avatar_size_version = ? WHERE id = ? AND user_id = ?'
        );
        foreach ($participants as $participant) {
            $updateParticipant->execute([$width, $height, $nextVersion, (int)$participant['id'], $userId]);
            $participant['avatar_display_size_px'] = null;
            $participant['avatar_display_width_px'] = $width;
            $participant['avatar_display_height_px'] = $height;
            $participant['avatar_size_version'] = $nextVersion;
            emit_event($pdo, (int)$participant['session_id'], 'avatar', array_merge([
                'participant_id' => (int)$participant['id'],
                'avatar_path' => (string)$participant['avatar_path'],
                'avatar_url' => resolve_avatar((string)$participant['avatar_path']),
                'avatar_identity' => (string)($participant['avatar_identity'] ?? ''),
                'avatar_orientation' => avatar_orientation_normalize($participant['avatar_orientation'] ?? null),
                'avatar_orientation_version' => max(1, (int)($participant['avatar_orientation_version'] ?? 1)),
                'webcam_path' => $participant['webcam_path'] ?? null,
                'webcam_enabled' => !empty($participant['webcam_enabled']),
            ], avatar_size_participant_event_fields($pdo, $participant)));
        }
    }
}

function avatar_size_participant_event_fields(PDO $pdo, array $participant): array {
    $preferences = avatar_size_preferences_public($pdo, $participant);
    return [
        'avatar_source_width_px' => isset($participant['avatar_source_width_px'])
            ? (int)$participant['avatar_source_width_px']
            : null,
        'avatar_source_height_px' => isset($participant['avatar_source_height_px'])
            ? (int)$participant['avatar_source_height_px']
            : null,
        'avatar_display_size_px' => $preferences['avatarDisplayPreferencePx'],
        'avatar_display_width_px' => $preferences['avatarDisplayWidthPreferencePx'],
        'avatar_display_height_px' => $preferences['avatarDisplayHeightPreferencePx'],
        'webcam_display_width_px' => $preferences['webcamDisplayWidthPreferencePx'],
        'webcam_display_height_px' => $preferences['webcamDisplayHeightPreferencePx'],
        'avatar_size_version' => $preferences['displayPreferenceVersion'],
        'effective_avatar_display_max_px' => $preferences['effectiveAvatarDisplayMaxPx'],
        'effective_webcam_display_width_px' => $preferences['effectiveWebcamDisplayWidthPx'],
        'effective_webcam_display_height_px' => $preferences['effectiveWebcamDisplayHeightPx'],
    ];
}
