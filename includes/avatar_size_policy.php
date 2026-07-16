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
    $policy = [
        'revision' => max(1, (int)app_setting($pdo, 'avatar_size_policy_revision', '1')),
    ];
    foreach (avatar_size_policy_setting_map() as $setting => $publicKey) {
        [$min, $max] = $bounds[$setting];
        $fallback = (int)$defaults[$setting];
        $policy[$publicKey] = avatar_size_policy_bounded_int(
            app_setting($pdo, $setting, (string)$fallback),
            $min,
            $max,
            $fallback
        );
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
    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) {
            if (db_uses_mysql_syntax($pdo)) $pdo->beginTransaction();
            else $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
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
        set_app_setting($pdo, 'avatar_size_policy_revision', (string)$revision);
        $policy = avatar_size_policy($pdo);
        if ($changed) avatar_size_policy_emit($pdo, $policy);
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->commit();
        return [
            'ok' => true,
            'idempotent' => !$changed,
            'policy' => $policy,
        ];
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
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
        'webcam_display_width_px' => ['webcamDisplayMaxWidthPx', 'webcam display width'],
        'webcam_display_height_px' => ['webcamDisplayMaxHeightPx', 'webcam display height'],
    ];
    $policy = avatar_size_policy($pdo);
    $normalized = [];
    foreach ($fieldMap as $field => [$capKey, $label]) {
        if (!array_key_exists($field, $changes)) continue;
        $value = avatar_size_nullable_int($changes[$field]);
        if ($value !== null && ($value < AVATAR_UPLOAD_MIN_DIMENSION_PX || $value > (int)$policy[$capKey])) {
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
    if (!$normalized) {
        return [
            'ok' => false,
            'code' => 'AVATAR_SIZE_PREFERENCE_REQUIRED',
            'error' => 'Choose an avatar or webcam display size.',
            'http_status' => 400,
        ];
    }

    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) {
            if (db_uses_mysql_syntax($pdo)) $pdo->beginTransaction();
            else $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
        }
        $sql = 'SELECT avatar_display_size_px, webcam_display_width_px, webcam_display_height_px, avatar_size_version FROM users WHERE id = ? LIMIT 1';
        if (db_uses_mysql_syntax($pdo)) $sql .= ' FOR UPDATE';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            return [
                'ok' => false,
                'code' => 'AVATAR_SIZE_USER_NOT_FOUND',
                'error' => 'Avatar display settings are unavailable.',
                'http_status' => 404,
            ];
        }
        $currentVersion = max(1, (int)($user['avatar_size_version'] ?? 1));
        if ($currentVersion !== (int)$parsedVersion) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
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
            $pdo->prepare(
                'UPDATE users SET avatar_display_size_px = ?, webcam_display_width_px = ?, webcam_display_height_px = ?, avatar_size_version = ? WHERE id = ?'
            )->execute([
                $next['avatar_display_size_px'],
                $next['webcam_display_width_px'],
                $next['webcam_display_height_px'],
                $nextVersion,
                $userId,
            ]);
            $pdo->prepare(
                'UPDATE participants SET avatar_display_size_px = ?, webcam_display_width_px = ?, webcam_display_height_px = ?, avatar_size_version = ? WHERE user_id = ?'
            )->execute([
                $next['avatar_display_size_px'],
                $next['webcam_display_width_px'],
                $next['webcam_display_height_px'],
                $nextVersion,
                $userId,
            ]);
        }
        $next['avatar_size_version'] = $nextVersion;
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->commit();
        return [
            'ok' => true,
            'idempotent' => !$changed,
            'preferences' => avatar_size_preferences_public($pdo, $next),
        ];
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function avatar_size_participant_event_fields(PDO $pdo, array $participant): array {
    $preferences = avatar_size_preferences_public($pdo, $participant);
    return [
        'avatar_display_size_px' => $preferences['avatarDisplayPreferencePx'],
        'webcam_display_width_px' => $preferences['webcamDisplayWidthPreferencePx'],
        'webcam_display_height_px' => $preferences['webcamDisplayHeightPreferencePx'],
        'avatar_size_version' => $preferences['displayPreferenceVersion'],
        'effective_avatar_display_max_px' => $preferences['effectiveAvatarDisplayMaxPx'],
        'effective_webcam_display_width_px' => $preferences['effectiveWebcamDisplayWidthPx'],
        'effective_webcam_display_height_px' => $preferences['effectiveWebcamDisplayHeightPx'],
    ];
}
