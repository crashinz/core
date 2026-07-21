<?php
declare(strict_types=1);

const AVATAR_DANCE_CAPABILITY_SETTING = 'avatar_dance_capabilities';
const AVATAR_DANCE_CAPABILITY_REVISION_SETTING = 'avatar_dance_capabilities_revision';

function avatar_dance_capability_registry(): array {
    static $registry = null;
    if ($registry !== null) return $registry;
    $registry = [
        [
            'id' => 'synchronized-sway',
            'label' => 'Synchronized Sway',
            'description' => 'Moves the complete relationship together in a synchronized side-to-side sway.',
            'kind' => 'relationship',
            'defaultEnabled' => true,
            'order' => 10,
        ],
        [
            'id' => 'synchronized-bounce',
            'label' => 'Synchronized Bounce',
            'description' => 'Moves the complete relationship together in a synchronized vertical bounce.',
            'kind' => 'relationship',
            'defaultEnabled' => true,
            'order' => 20,
        ],
        [
            'id' => 'lap_dance',
            'label' => 'Lap Dance',
            'description' => 'Allows one eligible lap occupant to use the certified rotational Lap Dance.',
            'kind' => 'lap',
            'defaultEnabled' => true,
            'order' => 30,
        ],
        [
            'id' => 'lap_bounce',
            'label' => 'Lap Bounce',
            'description' => 'Allows one eligible lap occupant to use the certified upward Lap Bounce.',
            'kind' => 'lap',
            'defaultEnabled' => true,
            'order' => 40,
        ],
    ];
    return $registry;
}

function avatar_dance_capability_ids(): array {
    return array_column(avatar_dance_capability_registry(), 'id');
}

function avatar_dance_capability_default_values(): array {
    $values = [];
    foreach (avatar_dance_capability_registry() as $definition) {
        $values[(string)$definition['id']] = !empty($definition['defaultEnabled']);
    }
    return $values;
}

function avatar_dance_capability_encode(array $enabled): string {
    $ordered = [];
    foreach (avatar_dance_capability_ids() as $danceId) {
        $ordered[$danceId] = !empty($enabled[$danceId]);
    }
    $encoded = json_encode($ordered, JSON_UNESCAPED_SLASHES);
    if ($encoded === false) throw new RuntimeException('Dance capability settings could not be encoded.');
    return $encoded;
}

function avatar_dance_capability_setting_defaults(): array {
    return [
        AVATAR_DANCE_CAPABILITY_SETTING => avatar_dance_capability_encode(
            avatar_dance_capability_default_values()
        ),
        AVATAR_DANCE_CAPABILITY_REVISION_SETTING => '1',
    ];
}

function avatar_dance_capability_normalize_values(mixed $stored): array {
    if (is_string($stored)) {
        $stored = json_decode($stored, true);
    }
    if (!is_array($stored)) $stored = [];
    $values = [];
    foreach (avatar_dance_capability_registry() as $definition) {
        $danceId = (string)$definition['id'];
        $value = $stored[$danceId] ?? $definition['defaultEnabled'];
        $values[$danceId] = in_array($value, [true, 1, '1'], true);
    }
    return $values;
}

function avatar_dance_capability_policy(PDO $pdo): array {
    $enabled = avatar_dance_capability_normalize_values(app_setting(
        $pdo,
        AVATAR_DANCE_CAPABILITY_SETTING,
        avatar_dance_capability_encode(avatar_dance_capability_default_values())
    ));
    $dances = [];
    foreach (avatar_dance_capability_registry() as $definition) {
        $danceId = (string)$definition['id'];
        $dances[] = $definition + ['enabled' => !empty($enabled[$danceId])];
    }
    $enabledCount = count(array_filter($enabled));
    return [
        'settingKey' => AVATAR_DANCE_CAPABILITY_SETTING,
        'revision' => max(1, (int)app_setting($pdo, AVATAR_DANCE_CAPABILITY_REVISION_SETTING, '1')),
        'categoryId' => 'avatar-interactions',
        'sectionId' => 'dances',
        'categoryLabel' => 'Avatar Interactions',
        'sectionLabel' => 'Dances',
        'description' => 'Choose which optional avatar dances members may start in this community.',
        'enabled' => $enabled,
        'dances' => $dances,
        'enabledCount' => $enabledCount,
        'totalCount' => count($dances),
        'allEnabled' => $enabledCount === count($dances),
        'allDisabled' => $enabledCount === 0,
    ];
}

function avatar_dance_capability_lock(PDO $pdo): array {
    $sql = 'SELECT setting_key, value FROM app_settings WHERE setting_key IN (?,?) ORDER BY setting_key';
    if (db_uses_mysql_syntax($pdo)) $sql .= ' FOR UPDATE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        AVATAR_DANCE_CAPABILITY_SETTING,
        AVATAR_DANCE_CAPABILITY_REVISION_SETTING,
    ]);
    $stmt->fetchAll();
    return avatar_dance_capability_policy($pdo);
}

function avatar_dance_capability_enabled(array $policy, string $danceId): bool {
    return in_array($danceId, avatar_dance_capability_ids(), true)
        && !empty($policy['enabled'][$danceId]);
}

function avatar_dance_capability_start_error(array $policy, string $danceId, bool $lap): array {
    $definition = null;
    foreach ($policy['dances'] ?? [] as $candidate) {
        if ((string)($candidate['id'] ?? '') === $danceId) {
            $definition = $candidate;
            break;
        }
    }
    $label = (string)($definition['label'] ?? 'That dance');
    return avatar_relationship_operation_error(
        $lap ? 'RELATIONSHIP_LAP_ANIMATION_DISABLED' : 'RELATIONSHIP_DANCE_DISABLED',
        "{$label} is disabled by the community dance settings.",
        'dance-capability-disabled',
        409
    ) + [
        'dance_id' => $danceId,
        'dance_capability_revision' => max(1, (int)($policy['revision'] ?? 1)),
    ];
}

function avatar_dance_capability_validate_boolean(mixed $value): ?bool {
    if (in_array($value, [true, 1, '1'], true)) return true;
    if (in_array($value, [false, 0, '0'], true)) return false;
    return null;
}

function avatar_dance_capability_target_values(array $before, array $request): array {
    $operation = (string)($request['operation'] ?? '');
    $current = avatar_dance_capability_normalize_values($before['enabled'] ?? []);
    if ($operation === 'enable_all' || $operation === 'disable_all') {
        return ['ok' => true, 'operation' => $operation, 'values' => array_fill_keys(
            avatar_dance_capability_ids(),
            $operation === 'enable_all'
        )];
    }
    if ($operation === 'set') {
        $danceId = trim((string)($request['dance_id'] ?? ''));
        $enabled = avatar_dance_capability_validate_boolean($request['enabled'] ?? null);
        if (!in_array($danceId, avatar_dance_capability_ids(), true) || $enabled === null) {
            return [
                'ok' => false,
                'code' => 'DANCE_CAPABILITY_SETTING_INVALID',
                'error' => 'Choose a registered dance and an enabled or disabled state.',
                'http_status' => 400,
            ];
        }
        $current[$danceId] = $enabled;
        return ['ok' => true, 'operation' => $operation, 'dance_id' => $danceId, 'values' => $current];
    }
    if ($operation === 'replace') {
        $provided = $request['enabled'] ?? null;
        if (!is_array($provided) || array_diff(array_keys($provided), avatar_dance_capability_ids())) {
            return [
                'ok' => false,
                'code' => 'DANCE_CAPABILITY_SETTING_INVALID',
                'error' => 'The complete registered dance policy is required.',
                'http_status' => 400,
            ];
        }
        $next = [];
        foreach (avatar_dance_capability_ids() as $danceId) {
            if (!array_key_exists($danceId, $provided)) {
                return [
                    'ok' => false,
                    'code' => 'DANCE_CAPABILITY_SETTING_INVALID',
                    'error' => 'The complete registered dance policy is required.',
                    'http_status' => 400,
                ];
            }
            $enabled = avatar_dance_capability_validate_boolean($provided[$danceId]);
            if ($enabled === null) {
                return [
                    'ok' => false,
                    'code' => 'DANCE_CAPABILITY_SETTING_INVALID',
                    'error' => 'Every registered dance must be enabled or disabled.',
                    'http_status' => 400,
                ];
            }
            $next[$danceId] = $enabled;
        }
        return ['ok' => true, 'operation' => $operation, 'values' => $next];
    }
    return [
        'ok' => false,
        'code' => 'DANCE_CAPABILITY_OPERATION_INVALID',
        'error' => 'Choose an individual, Enable All, or Disable All dance operation.',
        'http_status' => 400,
    ];
}

function avatar_dance_capability_reconcile_locked(
    PDO $pdo,
    array $disabledDanceIds,
    int $policyRevision
): array {
    if (!$disabledDanceIds) {
        return ['stoppedStateCount' => 0, 'affectedRelationshipCount' => 0, 'sessionIds' => []];
    }
    $disabled = array_fill_keys($disabledDanceIds, true);
    $relationshipIds = $pdo->query(
        "SELECT id FROM avatar_relationships WHERE status = 'active' ORDER BY id"
    )->fetchAll(PDO::FETCH_COLUMN);
    $relationshipSql = 'SELECT id, session_id, relationship_public_id, version, status, options_json
                          FROM avatar_relationships WHERE id = ? LIMIT 1';
    if (db_uses_mysql_syntax($pdo)) $relationshipSql .= ' FOR UPDATE';
    $relationshipStmt = $pdo->prepare($relationshipSql);
    $stoppedStateCount = 0;
    $affectedRelationshipCount = 0;
    $sessionIds = [];
    foreach ($relationshipIds as $relationshipId) {
        $relationshipStmt->execute([(int)$relationshipId]);
        $relationship = $relationshipStmt->fetch() ?: null;
        if (!$relationship || (string)$relationship['status'] !== 'active') continue;
        $storedOptions = [];
        if (!empty($relationship['options_json'])) {
            $decodedOptions = json_decode((string)$relationship['options_json'], true);
            if (!is_array($decodedOptions) || json_last_error() !== JSON_ERROR_NONE) continue;
            $storedOptions = $decodedOptions;
        }
        $members = avatar_relationship_locked_members($pdo, (int)$relationship['id']);
        $playback = avatar_relationship_dance_playback($storedOptions['dancePlayback'] ?? null);
        $lapAnimations = avatar_relationship_lap_animation_states(
            $storedOptions['lapAnimations'] ?? null,
            $relationship,
            $members
        );
        $stopGroup = $playback['state'] === 'playing' && isset($disabled[(string)$playback['danceId']]);
        $stoppedLap = array_values(array_filter(
            $lapAnimations,
            static fn(array $state): bool => isset($disabled[(string)($state['mode'] ?? '')])
        ));
        if (!$stopGroup && !$stoppedLap) continue;

        $nextVersion = max(1, (int)$relationship['version']) + 1;
        $generation = 'dance-policy-' . $policyRevision . '-' . (int)$relationship['id'];
        if ($stopGroup) {
            $playback = avatar_relationship_dance_playback([
                'state' => 'stopped',
                'generation' => $generation,
            ]);
            $storedOptions['dancePlayback'] = $playback;
            $stoppedStateCount++;
        }
        $nextLapAnimations = [];
        foreach ($lapAnimations as $state) {
            if (isset($disabled[(string)($state['mode'] ?? '')])) continue;
            $state['relationshipVersion'] = $nextVersion;
            $nextLapAnimations[] = $state;
        }
        if ($stoppedLap) {
            $storedOptions['lapAnimations'] = $nextLapAnimations;
            $stoppedStateCount += count($stoppedLap);
        }
        $pdo->prepare(
            'UPDATE avatar_relationships
                SET options_json = ?, version = ?, updated_at = CURRENT_TIMESTAMP
              WHERE id = ?'
        )->execute([
            json_encode($storedOptions, JSON_UNESCAPED_SLASHES),
            $nextVersion,
            (int)$relationship['id'],
        ]);
        $eventRelationship = avatar_relationship_public_event_snapshot($pdo, (int)$relationship['id']);
        avatar_relationship_emit_lifecycle_event(
            $pdo,
            (int)$relationship['session_id'],
            'dance-capability-shutdown',
            $eventRelationship,
            [
                'operation_id' => $generation,
                'actor_participant_id' => 0,
                'dancePlayback' => $playback,
                'lapAnimation' => null,
                'lapAnimations' => avatar_relationship_lap_animation_states(
                    $nextLapAnimations,
                    $eventRelationship,
                    $members
                ),
                'disabled_dance_ids' => $disabledDanceIds,
                'resolution_reason' => 'dance-capability-disabled',
            ]
        );
        $affectedRelationshipCount++;
        $sessionIds[(int)$relationship['session_id']] = true;
    }
    return [
        'stoppedStateCount' => $stoppedStateCount,
        'affectedRelationshipCount' => $affectedRelationshipCount,
        'sessionIds' => array_map('intval', array_keys($sessionIds)),
    ];
}

function avatar_dance_capability_emit(PDO $pdo, array $policy): void {
    $sessionIds = $pdo->query('SELECT id FROM room_sessions ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($sessionIds as $sessionId) {
        emit_event($pdo, (int)$sessionId, 'dance_capability', $policy);
    }
}

function avatar_dance_capability_update(
    PDO $pdo,
    array $request,
    mixed $expectedRevision,
    int $actorUserId,
    string $source = 'admin'
): array {
    $revision = filter_var($expectedRevision, FILTER_VALIDATE_INT);
    if ($revision === false || (int)$revision < 1) {
        return [
            'ok' => false,
            'code' => 'DANCE_CAPABILITY_REVISION_REQUIRED',
            'error' => 'A current dance capability revision is required.',
            'http_status' => 400,
        ];
    }
    if (($request['operation'] ?? '') === 'disable_all' && empty($request['confirmed'])) {
        return [
            'ok' => false,
            'code' => 'DANCE_CAPABILITY_CONFIRMATION_REQUIRED',
            'error' => 'Confirm Disable All Dances before continuing.',
            'http_status' => 409,
        ];
    }

    $ownsTransaction = !$pdo->inTransaction();
    $changed = false;
    $shutdown = ['stoppedStateCount' => 0, 'affectedRelationshipCount' => 0, 'sessionIds' => []];
    try {
        if ($ownsTransaction) {
            if (db_uses_mysql_syntax($pdo)) {
                // Reconciliation locks rows explicitly; current reads let a concurrent
                // lifecycle commit be re-evaluated instead of invalidating the scan.
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                $pdo->beginTransaction();
            } else {
                $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
            }
        }
        $before = avatar_dance_capability_lock($pdo);
        $target = avatar_dance_capability_target_values($before, $request);
        if (empty($target['ok'])) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            return $target;
        }
        $currentValues = avatar_dance_capability_normalize_values($before['enabled'] ?? []);
        $nextValues = avatar_dance_capability_normalize_values($target['values']);
        $changed = $currentValues !== $nextValues;
        if (!$changed && (int)$revision !== (int)$before['revision']) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->commit();
            return [
                'ok' => true,
                'idempotent' => true,
                'policy' => $before,
                'stoppedStateCount' => 0,
                'affectedRelationshipCount' => 0,
            ];
        }
        if ((int)$revision !== (int)$before['revision']) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            return [
                'ok' => false,
                'code' => 'DANCE_CAPABILITY_STALE',
                'error' => 'Dance capability settings changed. Refresh and try again.',
                'revision' => (int)$before['revision'],
                'policy' => $before,
                'http_status' => 409,
            ];
        }
        $nextRevision = (int)$before['revision'] + ($changed ? 1 : 0);
        if ($changed) {
            set_app_setting($pdo, AVATAR_DANCE_CAPABILITY_SETTING, avatar_dance_capability_encode($nextValues));
            set_app_setting($pdo, AVATAR_DANCE_CAPABILITY_REVISION_SETTING, (string)$nextRevision);
        }
        $disabledDanceIds = array_keys(array_filter(
            $nextValues,
            static fn(bool $enabled): bool => !$enabled
        ));
        $shutdown = avatar_dance_capability_reconcile_locked($pdo, $disabledDanceIds, $nextRevision);
        if ($changed || $shutdown['stoppedStateCount'] > 0) {
            $changedIds = [];
            foreach (avatar_dance_capability_ids() as $danceId) {
                if ($currentValues[$danceId] !== $nextValues[$danceId]) $changedIds[] = $danceId;
            }
            log_tool(
                $pdo,
                $actorUserId > 0 ? $actorUserId : null,
                $source === 'setup' ? 'setup_dance_capability_update' : 'admin_dance_capability_update',
                null,
                null,
                'Dance capabilities revision ' . (int)$before['revision'] . ' to ' . $nextRevision
                    . '; operation ' . (string)$target['operation']
                    . '; changed ' . ($changedIds ? implode(',', $changedIds) : 'none')
                    . '; stopped ' . (int)$shutdown['stoppedStateCount'] . ' active state(s).'
            );
        }
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->commit();
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    $policy = avatar_dance_capability_policy($pdo);
    if ($changed || $shutdown['stoppedStateCount'] > 0) avatar_dance_capability_emit($pdo, $policy);
    return [
        'ok' => true,
        'idempotent' => !$changed && $shutdown['stoppedStateCount'] === 0,
        'policy' => $policy,
        'stoppedStateCount' => (int)$shutdown['stoppedStateCount'],
        'affectedRelationshipCount' => (int)$shutdown['affectedRelationshipCount'],
    ];
}
